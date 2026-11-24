<?php
/**
 * Common Helpers: Normalization, Validation, Code Generation, Dates, JSON & CSV
 */

declare(strict_types=1);

function json_response(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Normalize WhatsApp number: keep digits only; if 10-digit Indian mobile, prefix 91.
 */
function normalize_whatsapp(string $input): string
{
    $digits = preg_replace('/\D+/', '', $input) ?? '';
    $digits = ltrim($digits, '0');
    if (strlen($digits) === 10 && preg_match('/^[6-9]\d{9}$/', $digits)) {
        return '91' . $digits;
    }
    return $digits;
}

function is_valid_whatsapp(string $normalized): bool
{
    // Accept 10-15 digits (standard E.164 without '+')
    return (bool) preg_match('/^\d{10,15}$/', $normalized);
}

/**
 * Normalize Instagram username: trim, strip leading @, lowercase.
 */
function normalize_instagram(string $input): string
{
    $handle = trim($input);
    // If user pasted a full instagram URL, extract username segment
    if (preg_match('#instagram\.com/([A-Za-z0-9._]+)#i', $handle, $m)) {
        $handle = $m[1];
    }
    $handle = ltrim($handle, '@');
    return strtolower($handle);
}

function is_valid_instagram(string $normalized): bool
{
    // Instagram rules: 1-30 chars, letters/numbers/periods/underscores, no consecutive dots or leading/trailing dot
    if ($normalized === '' || strlen($normalized) > 30) {
        return false;
    }
    if (!preg_match('/^[a-z0-9._]+$/', $normalized)) {
        return false;
    }
    if (str_starts_with($normalized, '.') || str_ends_with($normalized, '.') || str_contains($normalized, '..')) {
        return false;
    }
    return true;
}

function sanitize_name(string $input): string
{
    $clean = trim(preg_replace('/\s+/', ' ', $input) ?? '');
    return mb_substr($clean, 0, 120, 'UTF-8');
}

function is_valid_name(string $name): bool
{
    $len = mb_strlen($name, 'UTF-8');
    return $len >= 2 && $len <= 120;
}

/**
 * Generate a collision-free unique coupon/gift code.
 * Example: GIFT-A7K9M2 or TZ1000-X4P8Q2
 */
function generate_unique_code(PDO $pdo, string $prefix): string
{
    // Omit confusing characters (0, O, 1, I, L)
    $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    $maxIdx   = strlen($alphabet) - 1;

    for ($attempt = 0; $attempt < 25; $attempt++) {
        $suffix = '';
        for ($i = 0; $i < 6; $i++) {
            $suffix .= $alphabet[random_int(0, $maxIdx)];
        }
        $code = strtoupper($prefix) . '-' . $suffix;

        $check = $pdo->prepare('SELECT 1 FROM participants WHERE code = :code LIMIT 1');
        $check->execute([':code' => $code]);
        if (!$check->fetchColumn()) {
            return $code;
        }
    }

    throw new RuntimeException('Unable to generate unique reward code.');
}

/**
 * Format date range like "30 Nov - 5 Dec" or "30 Nov 2026 - 5 Dec 2026"
 */
function format_validity_range(string $from, string $to, bool $includeYear = false): string
{
    $fmt = $includeYear ? 'j M Y' : 'j M';
    $d1  = DateTimeImmutable::createFromFormat('Y-m-d', $from);
    $d2  = DateTimeImmutable::createFromFormat('Y-m-d', $to);
    if (!$d1 || !$d2) {
        return $from . ' - ' . $to;
    }
    return $d1->format($fmt) . ' - ' . $d2->format($fmt);
}

/**
 * Evaluate discount coupon validity against today's date (Asia/Kolkata).
 * Returns: 'valid' | 'not_yet_valid' | 'expired'
 */
function evaluate_coupon_date_status(string $validFrom, string $validTo): string
{
    $today = date('Y-m-d');
    if ($today < $validFrom) {
        return 'not_yet_valid';
    }
    if ($today > $validTo) {
        return 'expired';
    }
    return 'valid';
}

/**
 * Check whether the campaign is currently open for participation.
 * Returns ['open' => bool, 'reason' => string]
 */
function check_campaign_open(array $settings): array
{
    if (($settings['campaign_active'] ?? '0') !== '1') {
        return ['open' => false, 'reason' => 'This Kochi campaign is currently paused. Please check back soon!'];
    }

    $now   = time();
    $start = !empty($settings['campaign_start']) ? strtotime($settings['campaign_start']) : false;
    $end   = !empty($settings['campaign_end']) ? strtotime($settings['campaign_end']) : false;

    if ($start !== false && $now < $start) {
        return ['open' => false, 'reason' => 'The campaign has not started yet. Stay tuned!'];
    }
    if ($end !== false && $now > $end) {
        return ['open' => false, 'reason' => 'This campaign has ended. Thank you for the love, Kochi!'];
    }

    return ['open' => true, 'reason' => ''];
}

/**
 * Prevent CSV formula injection when exporting to Excel/Sheets.
 */
function csv_safe(string $value): string
{
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $value;
    }
    return $value;
}

function get_base_url(): string
{
    $cfg = app_config()['app'];
    if (!empty($cfg['base_url'])) {
        return $cfg['base_url'];
    }
    $scheme = is_https_request() ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    // Strip /admin or /api if called from subdirectory
    $dir = preg_replace('#/(admin|api)$#', '', $dir) ?? '';
    return $scheme . '://' . $host . $dir;
}
