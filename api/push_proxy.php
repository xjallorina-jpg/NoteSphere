<?php
/**
 * NoteSphere - same-origin proxy for the local push notification server.
 *
 * This lets phones using an ngrok/tunnel URL reach the push server without a
 * separate tunnel or browser CORS problems. The push server itself still runs
 * at PUSH_SERVER_URL (default http://127.0.0.1:4000 locally). On Vercel the
 * push server must be deployed separately and its public URL configured.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/env.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$allowedEndpoints = ['vapid-public', 'status', 'subscribe', 'unsubscribe', 'notify'];
$endpoint = (string)($_GET['endpoint'] ?? '');
if (!in_array($endpoint, $allowedEndpoints, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unsupported push endpoint.']);
    exit;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET' && $method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Unsupported request method.']);
    exit;
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) $rawBody = '';

$pushBase = ns_push_server_url();
if ($pushBase === '') {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'The push server is not configured.']);
    exit;
}

$ch = curl_init($pushBase . '/' . $endpoint);
if ($ch === false) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'The push server is not available.']);
    exit;
}

curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json']);
if ($method === 'POST') {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
}

$response = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false || $curlError !== '') {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'The push server is not running. Start it with: cd push-server && node index.js']);
    exit;
}

http_response_code($httpCode > 0 ? $httpCode : 200);
echo $response;
