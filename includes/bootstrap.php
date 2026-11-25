<?php
/**
 * NoteSphere - shared bootstrap
 *
 * Loaded by the Vercel front controller before the requested page is included.
 * Starting the session here guarantees that every page uses the database
 * session handler even when the page calls session_start() itself later.
 */

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/db_config.php';

if (ns_env_bool('APP_DEBUG', false)) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);
    ini_set('display_errors', '0');
}
