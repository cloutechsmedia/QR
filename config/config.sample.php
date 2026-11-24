<?php
/**
 * Techzo Kochi Scratch & Win - Configuration Sample
 * Copy this file to config/config.php and update with your MySQL credentials.
 */

declare(strict_types=1);

return [
    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'techzo_kochi_campaign',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name'          => 'Techzo Kochi Scratch & Win',
        'base_url'      => 'https://yourdomain.com', // No trailing slash
        'timezone'      => 'Asia/Kolkata',
        'force_https'   => true, // Set false ONLY for local HTTP dev
        'debug'         => false,
    ],
    'rate_limit' => [
        'play_max_attempts'   => 8,    // Max API play requests per IP per window
        'play_window_seconds' => 600,  // 10 minutes
        'login_max_attempts'  => 6,    // Max admin login attempts per IP per window
        'login_window_seconds'=> 900,  // 15 minutes
    ],
];
