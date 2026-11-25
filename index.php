<?php
/**
 * Compatibility entry point.
 *
 * Older installed PWA shortcuts use /NoteSphere/index.php as their start URL.
 * Redirect them to the real portal while keeping the app path-agnostic
 * (works on localhost, a subfolder, or an ngrok/tunnel URL).
 */
$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: portal.php' . ($query !== '' ? '?' . $query : ''), true, 302);
exit;
