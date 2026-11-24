<?php
/**
 * Techzo Kochi Scratch & Win - Play API Endpoint
 * POST /api/play.php
 *
 * Server-side winner assignment with DB transaction + row locking.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/settings.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

init_secure_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

// Parse JSON or form-encoded payload
$raw  = file_get_contents('php://input');
$data = [];
if (is_string($raw) && trim($raw) !== '' && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
} else {
    $data = $_POST;
}

// 1. CSRF Verification
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$csrfBody   = is_string($data['csrf_token'] ?? null) ? $data['csrf_token'] : '';
if (!verify_csrf_token($csrfHeader !== '' ? $csrfHeader : $csrfBody)) {
    json_response([
        'ok'    => false,
        'error' => 'Security token expired. Please refresh the page and try again.',
    ], 403);
}

// 2. IP Rate Limiting
$rlCfg = app_config()['rate_limit'];
$rl    = check_rate_limit(
    'play',
    (int) ($rlCfg['play_max_attempts'] ?? 8),
    (int) ($rlCfg['play_window_seconds'] ?? 600)
);
if (!$rl['allowed']) {
    json_response([
        'ok'    => false,
        'error' => 'Too many requests from your connection. Please wait a couple of minutes and try again.',
    ], 429);
}

// 3. Session-based participation lock
if (!empty($_SESSION['has_played'])) {
    json_response([
        'ok'             => false,
        'already_played' => true,
        'error'          => 'You have already unlocked your scratch card on this device! One entry per participant.',
    ], 409);
}

// 4. Load campaign settings & check schedule
$settings   = get_settings();
$openStatus = check_campaign_open($settings);
if (!$openStatus['open']) {
    json_response([
        'ok'    => false,
        'error' => $openStatus['reason'],
    ], 403);
}

// 5. Server-side Input Validation & Sanitization
$requireIgToken = (($settings['require_ig_token'] ?? '0') === '1');
$igTokenInput   = trim((string) ($data['ig_token'] ?? ''));

$name      = sanitize_name((string) ($data['name'] ?? ''));
$whatsapp  = normalize_whatsapp((string) ($data['whatsapp'] ?? ''));
$instagram = normalize_instagram((string) ($data['instagram_username'] ?? ''));

$fieldErrors = [];
if (!is_valid_name($name)) {
    $fieldErrors['name'] = 'Please enter your full name (at least 2 characters).';
}
if (!is_valid_whatsapp($whatsapp)) {
    $fieldErrors['whatsapp'] = 'Please enter a valid 10-digit WhatsApp number.';
}
if (!$requireIgToken && !is_valid_instagram($instagram)) {
    $fieldErrors['instagram_username'] = 'Please enter a valid Instagram username (letters, numbers, dots, underscores).';
}

if (!empty($fieldErrors)) {
    json_response([
        'ok'     => false,
        'error'  => reset($fieldErrors),
        'fields' => $fieldErrors,
    ], 422);
}

if ($requireIgToken && $igTokenInput === '') {
    json_response([
        'ok'    => false,
        'error' => 'Instagram follow verification is required. Please follow @' . ($settings['instagram_handle'] ?? 'techzo__official') . ' and DM "' . ($settings['ig_dm_keyword'] ?? 'KOCHI') . '" to get your verified unlock link.',
    ], 403);
}

// 6. Transaction + Row Locking for Duplicate Check & Gift Slot Assignment
$pdo        = db();
$totalGifts = max(1, (int) ($settings['total_gifts'] ?? 3));
$poolSize   = max($totalGifts, (int) ($settings['pool_size'] ?? 100));
$discount   = max(1, (int) ($settings['discount_amount'] ?? 1000));
$giftName   = (string) ($settings['gift_name'] ?? 'Bluetooth Speaker');
$validFrom  = (string) ($settings['valid_from'] ?? '2026-11-30');
$validTo    = (string) ($settings['valid_to'] ?? '2026-12-05');
$minBill    = (int) ($settings['min_bill_amount'] ?? 0);
$igHandle   = (string) ($settings['instagram_handle'] ?? 'techzo__official');
$claimWa    = preg_replace('/\D+/', '', (string) ($settings['whatsapp_claim_number'] ?? '')) ?? '';

try {
    $pdo->beginTransaction();

    // Ensure entry_counter row exists and lock it FOR UPDATE so all concurrent plays serialize cleanly
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('entry_counter', '0')");
    $counterStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'entry_counter' FOR UPDATE");
    $counterStmt->execute();
    $currentCounter = (int) $counterStmt->fetchColumn();

    // Ensure gift slots are initialized once
    ensure_gift_slots_initialized($pdo, $totalGifts, $poolSize);

    // Validate & lock Instagram auto-verify token if strict mode is active (or token provided)
    $tokenRowId     = null;
    $autoIgVerified = 0;
    if ($requireIgToken || $igTokenInput !== '') {
        $tokStmt = $pdo->prepare(
            'SELECT id, instagram_username, used, (expires_at > NOW()) AS not_expired
             FROM ig_verify_tokens
             WHERE token = :token
             LIMIT 1 FOR UPDATE'
        );
        $tokStmt->execute([':token' => $igTokenInput]);
        $tokRow = $tokStmt->fetch();

        if (!$tokRow || (int) $tokRow['used'] === 1 || (int) $tokRow['not_expired'] !== 1) {
            $pdo->rollBack();
            json_response([
                'ok'    => false,
                'error' => 'Your Instagram verification link is invalid or already used. Please DM "' . ($settings['ig_dm_keyword'] ?? 'KOCHI') . '" to @' . $igHandle . ' on Instagram for a fresh verified link.',
            ], 403);
        }

        // Enforce the verified Instagram username from the token (cannot be tampered with)
        $instagram      = normalize_instagram((string) $tokRow['instagram_username']);
        $tokenRowId     = (int) $tokRow['id'];
        $autoIgVerified = 1;
    }

    // Check duplicates by WhatsApp OR case-insensitive Instagram username
    $dupStmt = $pdo->prepare(
        'SELECT id, whatsapp, instagram_username FROM participants
         WHERE whatsapp = :wa OR LOWER(instagram_username) = :ig
         LIMIT 1 FOR UPDATE'
    );
    $dupStmt->execute([
        ':wa' => $whatsapp,
        ':ig' => $instagram,
    ]);
    $existing = $dupStmt->fetch();

    if ($existing) {
        $pdo->rollBack();
        $reason = ($existing['whatsapp'] === $whatsapp)
            ? 'This WhatsApp number has already been used to play. One scratch card per participant!'
            : 'This Instagram username (@' . $instagram . ') has already participated. One scratch card per account!';

        json_response([
            'ok'             => false,
            'already_played' => true,
            'error'          => $reason,
        ], 409);
    }

    // Increment entry number atomically
    $entryNo = $currentCounter + 1;
    $updCounter = $pdo->prepare("UPDATE settings SET setting_value = :val WHERE setting_key = 'entry_counter'");
    $updCounter->execute([':val' => (string) $entryNo]);

    // Check if this entry_no matches an unawarded gift slot AND active awarded gifts < total_gifts
    $awardedGiftsCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM participants WHERE result_type = 'gift' AND status != 'revoked' FOR UPDATE"
    )->fetchColumn();

    $slotStmt = $pdo->prepare(
        'SELECT id FROM gift_slots WHERE entry_no = :entry_no AND awarded = 0 LIMIT 1 FOR UPDATE'
    );
    $slotStmt->execute([':entry_no' => $entryNo]);
    $matchedSlotId = $slotStmt->fetchColumn();

    $isGiftWinner = ($matchedSlotId !== false) && ($awardedGiftsCount < $totalGifts);

    if ($isGiftWinner) {
        $resultType = 'gift';
        $code       = generate_unique_code($pdo, 'GIFT');
    } else {
        $resultType = 'discount';
        $code       = generate_unique_code($pdo, 'TZ' . $discount);
    }

    // Insert participant
    $ins = $pdo->prepare(
        'INSERT INTO participants (entry_no, name, whatsapp, instagram_username, ip, result_type, code, status, ig_verified)
         VALUES (:entry_no, :name, :whatsapp, :instagram, :ip, :result_type, :code, :status, :ig_verified)'
    );
    $ins->execute([
        ':entry_no'    => $entryNo,
        ':name'        => $name,
        ':whatsapp'    => $whatsapp,
        ':instagram'   => $instagram,
        ':ip'          => get_client_ip(),
        ':result_type' => $resultType,
        ':code'        => $code,
        ':status'      => $autoIgVerified ? 'verified' : 'pending',
        ':ig_verified' => $autoIgVerified,
    ]);
    $participantId = (int) $pdo->lastInsertId();

    // Consume one-time Instagram verification token
    if ($tokenRowId !== null) {
        $useTok = $pdo->prepare('UPDATE ig_verify_tokens SET used = 1 WHERE id = :id');
        $useTok->execute([':id' => $tokenRowId]);
    }

    // Mark gift slot awarded if winner
    if ($isGiftWinner) {
        $markSlot = $pdo->prepare(
            'UPDATE gift_slots SET awarded = 1, participant_id = :pid, awarded_at = NOW() WHERE id = :slot_id'
        );
        $markSlot->execute([
            ':pid'     => $participantId,
            ':slot_id' => (int) $matchedSlotId,
        ]);
    }

    $pdo->commit();

    // Lock session so same browser session cannot replay
    $_SESSION['has_played'] = true;

    $validityShort = format_validity_range($validFrom, $validTo, false);
    $validityFull  = format_validity_range($validFrom, $validTo, true);
    $baseUrl       = get_base_url();

    if ($isGiftWinner) {
        $headline     = "Congratulations! You won a {$giftName}!";
        $instructions = "Take a screenshot of this code and DM @{$igHandle} on Instagram or message us on WhatsApp to verify your follow and claim your {$giftName}.";
        $shareText    = "I just won a {$giftName} in the Techzo Kochi Scratch & Win! Try your luck here: {$baseUrl}";
        $validityText = 'Claim after Instagram follow verification';
    } else {
        $headline     = "You won Rs. {$discount} off on our services!";
        $minBillNote  = $minBill > 0 ? " (Min. bill Rs. {$minBill})" : '';
        $instructions = "Show this code at Techzo between {$validityFull}{$minBillNote}. Follow @{$igHandle} on Instagram to keep it valid.";
        $shareText    = "I just won a Rs. {$discount} discount coupon in the Techzo Kochi Scratch & Win! Everyone wins — try yours here: {$baseUrl}";
        $validityText = "Valid {$validityShort} only";
    }

    json_response([
        'ok'     => true,
        'reward' => [
            'type'         => $resultType,
            'name'         => $name,
            'headline'     => $headline,
            'code'         => $code,
            'validity'     => $validityText,
            'instructions' => $instructions,
            'gift_name'    => $giftName,
            'discount'     => $discount,
            'min_bill'     => $minBill,
            'ig_handle'    => $igHandle,
            'claim_wa'     => $claimWa,
            'share_text'   => $shareText,
        ],
    ], 200);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Handle unique constraint collision (SQLSTATE 23000) gracefully
    if ($e->getCode() === '23000') {
        json_response([
            'ok'             => false,
            'already_played' => true,
            'error'          => 'It looks like this WhatsApp number or Instagram username has already played!',
        ], 409);
    }

    $debug = !empty(app_config()['app']['debug']);
    json_response([
        'ok'    => false,
        'error' => $debug ? ('Database error: ' . $e->getMessage()) : 'Something went wrong while unlocking your card. Please try again.',
    ], 500);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $debug = !empty(app_config()['app']['debug']);
    json_response([
        'ok'    => false,
        'error' => $debug ? $e->getMessage() : 'Unexpected server error. Please try again.',
    ], 500);
}
