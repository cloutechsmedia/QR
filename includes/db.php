<?php
/**
 * Database Connection (PDO) & Config Loader
 */

declare(strict_types=1);

function app_config(): array
{
    static $config = null;
    if ($config === null) {
        $configPath = dirname(__DIR__) . '/config/config.php';
        if (!file_exists($configPath)) {
            throw new RuntimeException('Missing config/config.php. Copy config/config.sample.php to config/config.php.');
        }
        $config = require $configPath;
        date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Kolkata');
    }
    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $cfg = app_config()['db'];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            (int) ($cfg['port'] ?? 3306),
            $cfg['name'],
            $cfg['charset'] ?? 'utf8mb4'
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        if (!empty($cfg['ssl']) && defined('PDO::MYSQL_ATTR_SSL_CA')) {
            $caPath = (string) ($cfg['ssl_ca'] ?? '/etc/ssl/certs/ca-certificates.crt');
            if ($caPath !== '' && file_exists($caPath)) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
            }
            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
            }
        }

        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $options);

        // Ensure IST session time zone in MySQL
        $pdo->exec("SET time_zone = '+05:30'");
    }
    return $pdo;
}
