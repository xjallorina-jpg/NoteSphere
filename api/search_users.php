<?php
require_once __DIR__ . '/../includes/db_config.php';
session_start();
if (empty($_SESSION['user_id'])) { http_response_code(401); exit; }
$q = trim($_GET['q'] ?? '');
header('Content-Type: application/json');
if ($q === '') { echo json_encode(['results'=>[], 'note'=>'Type a name to search.']); exit; }
try {
    $stmt = $conn->prepare("SELECT id, full_name, email FROM users WHERE full_name LIKE :q OR email LIKE :q LIMIT 20");
    $stmt->execute([':q' => '%' . $q . '%']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        echo json_encode(['results'=>[], 'note'=>'No registered user found with that name.']);
    } else {
        echo json_encode(['results'=>$rows, 'note'=>'']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['results'=>[], 'note'=>'Search error.']);
}