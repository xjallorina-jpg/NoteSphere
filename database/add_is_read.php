<?php
require_once __DIR__ . '/../includes/db_config.php';
$pdo = $conn;
try { $pdo->exec("ALTER TABLE messages ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
echo "Done";
