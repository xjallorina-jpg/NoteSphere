<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') { header('Location: ../auth/login.php'); exit(); }
require_once __DIR__ . '/../includes/db_config.php';
$rows = []; $avg = 0; $count = 0;
try {
  $stmt = $conn->query("SELECT s.*, u.full_name FROM user_satisfaction s LEFT JOIN users u ON u.user_id=s.user_id ORDER BY s.created_at DESC");
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $r) { $avg += (float)($r['rating'] ?? 0); $count++; }
  if ($count > 0) $avg = round($avg/$count, 2);
} catch (Throwable $e) { $rows = []; }
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Satisfaction Results</title>
<style>
body{font-family:sans-serif;background:#0b0d0b;color:#f4f7f4;padding:20px;}
.card{background:#121512;border:1px solid #2a332b;border-radius:12px;padding:20px;max-width:700px;margin:0 auto;}
.summary{text-align:center;margin-bottom:20px;}
.rating{font-size:48px;font-weight:800;color:#39ff14;}
.scroll{max-height:500px;overflow:auto;border:1px solid #2a332b;border-radius:8px;padding:10px;}
.item{border-bottom:1px solid #2a332b;padding:12px 0;}
.stars{color:#f59e0b;}
</style></head>
<body>
<div class="card">
  <div class="summary">
    <div class="rating"><?= $avg ?></div>
    <div>Excellent</div>
    <div><?= $count ?> respondent(s)</div>
  </div>
  <div class="scroll">
    <?php foreach ($rows as $r): ?>
      <div class="item">
        <strong><?= htmlspecialchars($r['full_name'] ?? 'User') ?></strong>
        <span class="stars"><?= str_repeat('★', (int)($r['rating'] ?? 0)) ?></span>
        <p><?= htmlspecialchars($r['comment'] ?? '') ?></p>
        <small><?= htmlspecialchars($r['created_at'] ?? '') ?></small>
      </div>
    <?php endforeach; ?>
  </div>
</div>
</body></html>
