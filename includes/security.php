<?php
/**
 * Security Helpers: Secure Sessions, CSRF, Sanitization, IP Rate Limiting, Admin Auth
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function is_https_request(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    return false;
}

function init_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $cfg = app_config()['app'];
    $secureCookie = !empty($cfg['force_https']) || is_https_request();

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    if ($secureCookie) {
        ini_set('session.cookie_secure', '1');
    }

    session_name('tz_kochi_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    if (empty($_SESSION['_created_at'])) {
        $_SESSION['_created_at'] = time();
    }
}

function csrf_token(): string
{
    init_secure_session();
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

function verify_csrf_token(?string $token): bool
{
    init_secure_session();
    if (empty($token) || empty($_SESSION['_csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['_csrf_token'], $token);
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function get_client_ip(): string
{
    // On cPanel / Apache behind Cloudflare or reverse proxy, validate candidate IP
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ];

    foreach ($candidates as $ip) {
        if (is_string($ip) && filter_var(trim($ip), FILTER_VALIDATE_IP)) {
            return trim($ip);
        }
    }
    return '0.0.0.0';
}

/**
 * DB-backed sliding-window IP rate limiter.
 * Returns ['allowed' => bool, 'retry_after' => int]
 */
function check_rate_limit(string $action, int $maxAttempts, int $windowSeconds): array
{
    $pdo = db();
    $ip  = get_client_ip();

    // Opportunistic cleanup of stale rows (1 in 20 calls)
    if (random_int(1, 20) === 1) {
        $cleanup = $pdo->prepare('DELETE FROM rate_limits WHERE window_start < (NOW() - INTERVAL 1 DAY)');
        $cleanup->execute();
    }

    $stmt = $pdo->prepare(
        'SELECT id, attempts, UNIX_TIMESTAMP(window_start) AS ws FROM rate_limits WHERE ip = :ip AND action = :action LIMIT 1'
    );
    $stmt->execute([':ip' => $ip, ':action' => $action]);
    $row = $stmt->fetch();

    $now = time();

    if (!$row) {
        $ins = $pdo->prepare(
            'INSERT INTO rate_limits (ip, action, attempts, window_start) VALUES (:ip, :action, 1, NOW())
             ON DUPLICATE KEY UPDATE attempts = attempts + 1'
        );
        $ins->execute([':ip' => $ip, ':action' => $action]);
        return ['allowed' => true, 'retry_after' => 0];
    }

    $elapsed = $now - (int) $row['ws'];
    if ($elapsed >= $windowSeconds) {
        $upd = $pdo->prepare(
            'UPDATE rate_limits SET attempts = 1, window_start = NOW() WHERE id = :id'
        );
        $upd->execute([':id' => $row['id']]);
        return ['allowed' => true, 'retry_after' => 0];
    }

    if ((int) $row['attempts'] >= $maxAttempts) {
        return ['allowed' => false, 'retry_after' => max(1, $windowSeconds - $elapsed)];
    }

    $inc = $pdo->prepare('UPDATE rate_limits SET attempts = attempts + 1 WHERE id = :id');
    $inc->execute([':id' => $row['id']]);
    return ['allowed' => true, 'retry_after' => 0];
}

function is_admin_logged_in(): bool
{
    init_secure_session();
    return !empty($_SESSION['admin_id']) && !empty($_SESSION['admin_username']);
}

function require_admin_auth(): void
{
    if (!is_admin_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function set_flash(string $type, string $message): void
{
    init_secure_session();
    $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    init_secure_session();
    if (empty($_SESSION['_flash'])) {
        return null;
    }
    $flash = $_SESSION['_flash'];
    unset($_SESSION['_flash']);
    return $flash;
}
