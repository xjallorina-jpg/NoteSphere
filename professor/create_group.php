<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'professor') { header("Location: ../auth/login.php"); exit(); }
require_once __DIR__ . '/../includes/db_config.php';
$pdo = $conn;
$pid = (int)$_SESSION['user_id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    if ($name !== '') {
        $ins = $pdo->prepare("INSERT INTO chat_groups (name, created_by) VALUES (?,?)");
        $ins->execute([$name, $pid]);
        $gid = $pdo->lastInsertId();
        // add creator as member
        $pdo->prepare("INSERT INTO chat_group_members (group_id, user_id) VALUES (?,?)")->execute([$gid, $pid]);
        // add selected students
        foreach ($_POST['students'] ?? [] as $sid) {
            $sid = (int)$sid;
            if ($sid > 0) {
                $pdo->prepare("INSERT IGNORE INTO chat_group_members (group_id, user_id) VALUES (?,?)")->execute([$gid, $sid]);
            }
        }
        header("Location: group_chat.php"); exit();
    }
}
$students = $pdo->query("SELECT user_id, full_name FROM users WHERE role='student' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Create Group</title></head><body>
<h1>Create Group Chat</h1>
<form method="POST">
  <input type="text" name="name" placeholder="Group name" required><br>
  <select name="students[]" multiple>
    <?php foreach ($students as $s): ?>
      <option value="<?= (int)$s['user_id'] ?>"><?= htmlspecialchars($s['full_name']) ?></option>
    <?php endforeach; ?>
  </select><br>
  <button type="submit">Create</button>
</form>
<a href="group_chat.php">Back</a>
</body></html>
