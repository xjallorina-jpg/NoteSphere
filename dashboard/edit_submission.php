<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../auth/login.php"); exit();
}
require_once __DIR__ . '/../includes/db_config.php';
$student_id = (int)$_SESSION['user_id'];
$sid = isset($_GET['submission_id']) ? (int)$_GET['submission_id'] : 0;
if ($sid <= 0) { header("Location: student_dashboard.php"); exit(); }

$stmt = $conn->prepare("SELECT s.*, a.deadline FROM announcement_submissions s JOIN announcements a ON s.announcement_id=a.id WHERE s.id=? AND s.student_id=?");
$stmt->execute([$sid, $student_id]);
$sub = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sub) { header("Location: student_dashboard.php"); exit(); }

// deadline check
$can_edit = true;
if (!empty($sub['deadline'])) { $can_edit = strtotime($sub['deadline']) > time(); }
if (!$can_edit) { header("Location: submission_history.php?prof_id=0"); exit(); }

// handle post
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $text = trim($_POST['submission_text'] ?? '');
    $note = trim($_POST['student_note'] ?? '');
    $upd = $conn->prepare("UPDATE announcement_submissions SET submission_text=?, student_note=? WHERE id=? AND student_id=?");
    $upd->execute([$text === '' ? null : $text, $note === '' ? null : $note, $sid, $student_id]);
    header("Location: submission_history.php?prof_id=0"); exit();
}
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Edit Submission</title></head><body>
<form method="POST">
  <textarea name="submission_text"><?= htmlspecialchars($sub['submission_text'] ?? '') ?></textarea>
  <textarea name="student_note"><?= htmlspecialchars($sub['student_note'] ?? '') ?></textarea>
  <button type="submit">Save</button>
</form>
</body></html>

