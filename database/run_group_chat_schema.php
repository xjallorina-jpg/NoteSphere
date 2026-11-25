<?php
require_once __DIR__ . '/../includes/db_config.php';
$pdo = $conn;
$sql = file_get_contents(__DIR__ . '/../database/group_chat_schema.sql');
$pdo->exec($sql);
echo "Tables created.";
