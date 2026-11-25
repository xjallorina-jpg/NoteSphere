<?php
/**
 * NoteSphere Database Configuration - example
 *
 * The real includes/db_config.php now reads environment variables, so this
 * file is only documentation. Copy .env.example to .env for local development,
 * or add the same variables in the Vercel project settings.
 *
 * Required variables:
 *
 *   DB_HOST=notesphere-xjallorina-16dc.g.aivencloud.com
 *   DB_PORT=27295
 *   DB_NAME=defaultdb
 *   DB_USER=avnadmin
 *   DB_PASS=your-aiven-password
 *
 * Optional variables:
 *
 *   DB_SSL=1
 *   DB_SSL_CA=/absolute/path/to/ca.pem
 *   DB_SSL_CA_PEM="-----BEGIN CERTIFICATE-----..."
 *   DB_SSL_VERIFY=1
 *   DB_SESSIONS=1
 *   APP_TIMEZONE=Asia/Manila
 */

if (!defined('NOTESPHERE_DB_CONFIG_LOADED')) {
    define('NOTESPHERE_DB_CONFIG_LOADED', true);

    require_once __DIR__ . '/db_config.php';
}
