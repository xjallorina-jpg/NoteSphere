<?php
/**
 * NoteSphere Database Configuration
 *
 * Works in three environments:
 *   1. Vercel          - reads DB_* variables from the project environment.
 *   2. Local XAMPP     - reads .env if present, otherwise localhost defaults.
 *   3. Any PHP host    - reads DB_* variables from the process environment.
 *
 * Required environment variables:
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 *
 * Optional:
 *   DB_SSL=1|0            Force TLS on/off (default: on for remote hosts)
 *   DB_SSL_CA=/path/ca.pem
 *   DB_SSL_CA_PEM="-----BEGIN CERTIFICATE-----..."
 *   DB_SSL_VERIFY=1|0     Verify the server certificate (default: on when a CA exists)
 *   DB_SESSIONS=1|0       Store PHP sessions in MySQL (default: on for Vercel)
 */

require_once __DIR__ . '/env.php';

if (!defined('NOTESPHERE_DB_CONFIG_LOADED')) {
    define('NOTESPHERE_DB_CONFIG_LOADED', true);

    date_default_timezone_set(ns_env('APP_TIMEZONE', 'Asia/Manila'));

    $host = (string) ns_env('DB_HOST', 'localhost');
    $port = (string) ns_env('DB_PORT', '3306');
    $dbname = (string) ns_env('DB_NAME', '1notesphere_db');
    $username = (string) ns_env('DB_USER', ns_env('DB_USERNAME', 'root'));
    $password = (string) ns_env('DB_PASS', ns_env('DB_PASSWORD', ''));
    $socket = (string) ns_env('DB_SOCKET', '');
    $charset = 'utf8mb4';

    if ($socket !== '') {
        $dsn = "mysql:unix_socket={$socket};dbname={$dbname};charset={$charset}";
    } else {
        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_TIMEOUT => ns_env_int('DB_TIMEOUT', 15),
    ];

    // Aiven (and most managed MySQL providers) require TLS for remote hosts.
    $isLocalHost = in_array(
        strtolower($host),
        ['localhost', '127.0.0.1', '::1'],
        true
    );

    $useSsl = ns_env_bool('DB_SSL', !$isLocalHost);

    if ($useSsl) {
        $caPath = (string) ns_env(
            'DB_SSL_CA',
            __DIR__ . DIRECTORY_SEPARATOR . 'certs' . DIRECTORY_SEPARATOR . 'aiven-ca.pem'
        );

        // Allow the CA to be supplied inline (handy on Vercel, where files in
        // the deployment are read-only but /tmp is writable).
        $caPem = (string) ns_env('DB_SSL_CA_PEM', '');

        if ($caPem !== '') {
            $tmpCa = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . 'notesphere-db-ca.pem';

            if (@file_put_contents($tmpCa, $caPem) !== false) {
                $caPath = $tmpCa;
            }
        }

        if (defined('PDO::MYSQL_ATTR_SSL_CA')) {
            if ($caPath !== '' && is_file($caPath)) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = ns_env_bool(
                    'DB_SSL_VERIFY',
                    true
                );
            } else {
                // Encrypt the connection even when no CA file is bundled.
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            }
        }
    }

    try {
        $conn = new PDO($dsn, $username, $password, $options);

        // Keep MySQL and PHP in the same timezone (the original XAMPP dump
        // uses +08:00).
        try {
            $conn->exec("SET time_zone = '+08:00'");
        } catch (Throwable $timezoneError) {
            // Some managed providers restrict session settings; ignore it.
            error_log('NoteSphere timezone setup skipped: ' . $timezoneError->getMessage());
        }

        // Backwards-compatible alias used by many older pages.
        $pdo = $conn;
    } catch (PDOException $e) {
        error_log('NoteSphere database connection failed: ' . $e->getMessage());

        http_response_code(500);

        if (ns_env_bool('APP_DEBUG', false)) {
            exit('Database connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        exit(
            'Unable to connect to the NoteSphere database. '
            . 'Please check the database configuration.'
        );
    }

    // Database-backed sessions are required on Vercel (no shared writable
    // filesystem between function instances).
    require_once __DIR__ . '/session.php';
    ns_start_session($conn);

    // Shared upload/storage helpers (Cloudinary + local fallback).
    require_once __DIR__ . '/cloudinary.php';
}
