<?php
/**
 * Techzo Kochi Scratch & Win - Active Configuration
 * Update these values for your environment (or use environment variables).
 */

declare(strict_types=1);

return [
    'db' => [
        'host'    => getenv('DB_HOST') ?: 'localhost',
        'port'    => (int) (getenv('DB_PORT') ?: 3306),
        'name'    => getenv('DB_NAME') ?: 'techzo_kochi_campaign',
        'user'    => getenv('DB_USER') ?: 'root',
        'pass'    => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
        'ssl'     => (getenv('DB_SSL') === 'true'),
        'ssl_ca'  => getenv('DB_SSL_CA') ?: '/etc/ssl/certs/ca-certificates.crt',
    ],
    'app' => [
        'name'          => 'Techzo Kochi Scratch & Win',
        'base_url'      => rtrim(getenv('APP_URL') ?: '', '/'),
        'timezone'      => 'Asia/Kolkata',
        // Automatically enforce HTTPS cookies on HTTPS connections; set true in production on cPanel
        'force_https'   => (getenv('FORCE_HTTPS') === 'true'),
        'debug'         => (getenv('APP_DEBUG') === 'true'),
    ],
    'rate_limit' => [
        'play_max_attempts'    => 8,
        'play_window_seconds'  => 600,
        'login_max_attempts'   => 6,
        'login_window_seconds' => 900,
    ],
];
