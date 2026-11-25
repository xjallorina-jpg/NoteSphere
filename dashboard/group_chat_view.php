<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }
require_once __DIR__ . '/../includes/db_config.php';
$pdo = $conn;
$uid = (int)$_SESSION['user_id'];
$gid = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
if ($gid <= 0) { header("Location: group_chat.php"); exit(); }
// verify membership
$chk = $pdo->prepare("SELECT 1 FROM chat_group_members WHERE group_id=? AND user_id=?");
$chk->execute([$gid, $uid]);
if (!$chk->fetch()) { header("Location: group_chat.php"); exit(); }
// post message
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $msg = trim($_POST['message'] ?? '');
    if ($msg !== '') {
        $ins = $pdo->prepare("INSERT INTO chat_group_messages (group_id, sender_id, message) VALUES (?,?,?)");
        $ins->execute([$gid, $uid, $msg]);
    }
    header("Location: group_chat_view.php?group_id=$gid"); exit();
}
// fetch messages
$stmt = $pdo->prepare("SELECT m.*, u.full_name FROM chat_group_messages m JOIN users u ON u.user_id=m.sender_id WHERE m.group_id=? ORDER BY m.sent_at ASC");
$stmt->execute([$gid]);
$msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Group Chat</title></head><body>
<h1>Group Chat</h1>
<div>
<?php foreach ($msgs as $m): ?>
  <p><strong><?= htmlspecialchars($m['full_name']) ?>:</strong> <?= htmlspecialchars($m['message']) ?> <small><?= $m['sent_at'] ?></small></p>
<?php endforeach; ?>
</div>
<form method="POST">
  <input type="text" name="message" required>
  <button type="submit">Send</button>
</form>
<a href="group_chat.php">Back</a>
</body></html>

