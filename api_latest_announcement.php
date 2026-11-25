<?php
require_once __DIR__ . '/../includes/db_config.php';
header('Content-Type: application/json');
$last = (int)($_GET['last'] ?? 0);
$stmt = $conn->prepare("SELECT id, title FROM announcements ORDER BY id DESC LIMIT 1");
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$latest = $row ? (int)$row['id'] : 0;
$new = $latest > $last;
echo json_encode(['latest' => $latest, 'new' => $new, 'title' => $row['title'] ?? '']);
