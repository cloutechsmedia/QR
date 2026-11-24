<?php
/**
 * Techzo Kochi Scratch & Win - Instagram Auto-Verify Webhook
 * POST /api/ig_webhook.php
 *
 * Called by ManyChat (External Request) or your Meta Messaging Webhook ONLY AFTER
 * Instagram confirms `is_user_follow_business == true` for @techzo__official.
 *
 * Request (JSON or form-encoded):
 *   Header: X-Webhook-Secret: <ig_webhook_secret>  (or body field "secret")
 *   Body:   { "instagram_username": "arjun_kochi", "ig_scoped_id": "178414..." }
 *
 * Response (JSON):
 *   { "ok": true, "token": "...", "unlock_url": "https://yourdomain.com/?token=..." }
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/settings.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$settings = get_settings();
$expectedSecret = (string) ($settings['ig_webhook_secret'] ?? '');

// Support Meta Webhook GET challenge verification if connected directly to Meta App
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['hub_mode'], $_GET['hub_verify_token'], $_GET['hub_challenge'])) {
    if ($_GET['hub_mode'] === 'subscribe' && $expectedSecret !== '' && hash_equals($expectedSecret, (string) $_GET['hub_verify_token'])) {
        header('Content-Type: text/plain');
        echo (string) $_GET['hub_challenge'];
        exit;
    }
    http_response_code(403);
    exit('Forbidden');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$raw  = file_get_contents('php://input');
$data = [];
if (is_string($raw) && trim($raw) !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}
if (empty($data)) {
    $data = $_POST;
}

// 1. Authenticate webhook caller via shared secret
$providedSecret = (string) ($_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? $data['secret'] ?? '');
if ($expectedSecret === '' || $providedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
    json_response(['ok' => false, 'error' => 'Unauthorized webhook secret.'], 401);
}

// 2. Validate follower's Instagram username
$igRaw     = (string) ($data['instagram_username'] ?? $data['ig_username'] ?? $data['username'] ?? '');
$igUser    = normalize_instagram($igRaw);
$igScoped  = trim((string) ($data['ig_scoped_id'] ?? $data['subscriber_id'] ?? ''));

if (!is_valid_instagram($igUser)) {
    json_response(['ok' => false, 'error' => 'Invalid or missing instagram_username.'], 422);
}

$pdo = db();

// 3. Check if this Instagram user already participated
$playedStmt = $pdo->prepare('SELECT code FROM participants WHERE LOWER(instagram_username) = :ig LIMIT 1');
$playedStmt->execute([':ig' => $igUser]);
$alreadyCode = $playedStmt->fetchColumn();

if ($alreadyCode !== false) {
    json_response([
        'ok'             => false,
        'already_played' => true,
        'message'        => 'You have already played the Techzo Kochi Scratch & Win!',
    ], 200);
}

// 4. Reuse existing valid unused token if one exists, otherwise generate a new one (valid 24 hours)
$existingStmt = $pdo->prepare(
    'SELECT token FROM ig_verify_tokens
     WHERE LOWER(instagram_username) = :ig AND used = 0 AND expires_at > NOW()
     ORDER BY id DESC LIMIT 1'
);
$existingStmt->execute([':ig' => $igUser]);
$token = $existingStmt->fetchColumn();

if ($token === false) {
    $token = bin2hex(random_bytes(20)); // 40-char cryptographically secure hex token
    $ins   = $pdo->prepare(
        'INSERT INTO ig_verify_tokens (token, instagram_username, ig_scoped_id, used, expires_at)
         VALUES (:token, :ig, :sid, 0, DATE_ADD(NOW(), INTERVAL 24 HOUR))'
    );
    $ins->execute([
        ':token' => $token,
        ':ig'    => $igUser,
        ':sid'   => $igScoped !== '' ? substr($igScoped, 0, 64) : null,
    ]);
}

$unlockUrl = get_base_url() . '/?token=' . rawurlencode((string) $token);

json_response([
    'ok'                 => true,
    'instagram_username' => $igUser,
    'token'              => (string) $token,
    'unlock_url'         => $unlockUrl,
    'message'            => "Verified! Open your personal scratch card link: {$unlockUrl}",
], 200);
