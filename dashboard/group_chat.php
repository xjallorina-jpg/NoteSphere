<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }
require_once __DIR__ . '/../includes/db_config.php';
$pdo = $conn;
$uid = (int)$_SESSION['user_id'];
// List groups
$groups = $pdo->prepare("SELECT g.* FROM chat_groups g JOIN chat_group_members m ON m.group_id=g.id WHERE m.user_id=?");
$groups->execute([$uid]);
$groups = $groups->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Group Chat</title></head><body>
<h1>Group Chat</h1>
<?php foreach ($groups as $g): ?>
  <div><a href="group_chat_view.php?group_id=<?= (int)$g['id'] ?>"><?= htmlspecialchars($g['name']) ?></a></div>
<?php endforeach; ?>
</body></html>

