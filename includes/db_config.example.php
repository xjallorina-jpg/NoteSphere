<?php
/**
 * NoteSphere Database Configuration
 * Database: 1notesphere_db
 *
 * Copy this file to db_config.php and update values for your environment.
 */

if (!defined('NOTESPHERE_DB_CONFIG_LOADED')) {
    define('NOTESPHERE_DB_CONFIG_LOADED', true);

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $host = 'localhost';
    $dbname = '1notesphere_db';
    $username = 'root';
    $password = ''; // Default XAMPP password is often empty.

    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";

    try {
        $conn = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log(
            'NoteSphere database connection failed: '
            . $e->getMessage()
        );

        http_response_code(500);
        exit(
            'Unable to connect to the NoteSphere database. '
            . 'Please check the database configuration.'
        );
    }
}
