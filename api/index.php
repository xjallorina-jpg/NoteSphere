<?php
/**
 * NoteSphere - Vercel front controller
 *
 * Vercel Functions need a single entry point. vercel.json rewrites every .php
 * request here with the original path in ?ns_path=...; this file validates the
 * path and includes the real page. Relative links, redirects and __DIR__-based
 * includes keep working because the original URL is preserved in the browser.
 */
declare(strict_types=1);

$root = dirname(__DIR__);

$requested = $_GET['ns_path'] ?? '';

if (is_array($requested)) {
    http_response_code(400);
    exit('Invalid request path.');
}

$requested = rawurldecode((string) $requested);

if ($requested === '') {
    $requested = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $requested = rawurldecode($requested);
}

$requested = str_replace('\\', '/', $requested);
$requested = ltrim($requested, '/');

if ($requested === '' || substr($requested, -1) === '/') {
    $requested = 'portal.php';
}

// Block source folders that should never be executed directly.
$blockedPrefixes = [
    'includes/',
    'database/',
    'sql/',
    'push-server/',
    'tools/',
    '.git/',
    'vendor/',
];

foreach ($blockedPrefixes as $prefix) {
    if (stripos($requested, $prefix) === 0) {
        http_response_code(404);
        exit('Not found.');
    }
}

if ($requested === 'api/index.php') {
    header('Location: /', true, 302);
    exit;
}

// Only PHP pages are handled here; everything else is served statically.
if (strtolower(substr($requested, -4)) !== '.php') {
    $requested .= '.php';
}

if (strpos($requested, '..') !== false) {
    http_response_code(400);
    exit('Invalid request path.');
}

$fullPath = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $requested));
$rootReal = realpath($root);

if (
    $fullPath === false
    || $rootReal === false
    || strpos($fullPath, rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0
    || !is_file($fullPath)
    || strtolower(pathinfo($fullPath, PATHINFO_EXTENSION)) !== 'php'
) {
    http_response_code(404);
    exit('Page not found.');
}

// Rebuild the original query string (Vercel preserves the caller's params).
$query = $_GET;
unset($query['ns_path']);
$queryString = http_build_query($query);

$originalUri = '/' . ltrim($requested, '/');
$originalUri .= $queryString !== '' ? '?' . $queryString : '';

// Make PHP_SELF / SCRIPT_NAME / REQUEST_URI look like the real page so the
// existing redirect and link-building code keeps working.
$_SERVER['SCRIPT_FILENAME'] = $fullPath;
$_SERVER['SCRIPT_NAME'] = '/' . ltrim($requested, '/');
$_SERVER['PHP_SELF'] = '/' . ltrim($requested, '/');
$_SERVER['REQUEST_URI'] = $originalUri;
$_SERVER['QUERY_STRING'] = $queryString;
$_SERVER['DOCUMENT_ROOT'] = $rootReal;
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
$_SERVER['SERVER_PORT'] = $_SERVER['SERVER_PORT'] ?? '443';
$_SERVER['HTTPS'] = 'on';

// Connect to Aiven and start the database-backed session before the page runs.
require_once $root . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

chdir(dirname($fullPath));

require $fullPath;
