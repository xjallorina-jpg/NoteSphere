<?php
require_once __DIR__ . '/../includes/db_config.php';
$pdo = $conn;
$cols = $pdo->query("SHOW COLUMNS FROM messages")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) echo $c['Field'] . "\n";
