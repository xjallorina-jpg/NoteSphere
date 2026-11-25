<?php
require_once __DIR__ . '/../includes/db_config.php';
$pdo = $conn;
$ss = $pdo->query("SELECT current_academic_year, current_semester FROM system_settings WHERE id=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$ay = $ss['current_academic_year'] ?? '2026-2027';
$sem = $ss['current_semester'] ?? '1st Semester';
$stmt = $pdo->prepare("UPDATE announcements SET academic_year=?, semester=? WHERE academic_year IS NULL OR academic_year=''");
$stmt->execute([$ay, $sem]);
echo "Done: set to $ay / $sem";
