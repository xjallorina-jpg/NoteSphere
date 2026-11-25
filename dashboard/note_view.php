<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../auth/login.php'); exit; }
require_once __DIR__ . '/../includes/db_config.php';
$pdo = $conn;
$note_id = isset($_GET['note_id']) ? (int)$_GET['note_id'] : 0;
if ($note_id <= 0) { echo 'Invalid note.'; exit; }
$stmt = $pdo->prepare("SELECT n.id, n.title, n.description, n.file_path, n.institute, n.status, u.full_name AS uploader FROM notes n LEFT JOIN users u ON n.user_id = u.user_id WHERE n.id = ? LIMIT 1");
$stmt->execute([$note_id]);
$note = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$note) { echo 'Note not found.'; exit; }
$attStmt = $pdo->prepare("SELECT file_path, original_name FROM note_attachments WHERE note_id = ? ORDER BY id ASC");
$attStmt->execute([$note_id]);
$attachments = $attStmt->fetchAll(PDO::FETCH_ASSOC);
$images = [];
foreach ($attachments as $a) {
    $ext = strtolower(pathinfo($a['file_path'], PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) $images[] = $a;
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title><?=htmlspecialchars($note['title'])?></title>
<style>
body{font-family:sans-serif;padding:20px;background:#111;color:#fff;}
.gallery{position:relative;max-width:600px;margin:20px auto;}
.gallery img{width:100%;border-radius:12px;}
.nav{position:absolute;top:50%;transform:translateY(-50%);background:rgba(0,0,0,.6);color:#fff;border:none;padding:10px 14px;font-size:24px;cursor:pointer;border-radius:8px;}
.prev{left:10px;} .next{right:10px;}
.thumbs{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;}
.thumbs img{width:60px;height:60px;object-fit:cover;border-radius:6px;cursor:pointer;border:2px solid transparent;}
.thumbs img.active{border-color:#39FF14;}
</style>
</head>
<body style="position:relative;">
<a href="view_notes_institutes.php" style="position:fixed;top:20px;right:20px;width:44px;height:44px;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.1);color:#fff;border-radius:12px;text-decoration:none;font-size:26px;z-index:1000;">&times;</a>
<h1><?=htmlspecialchars($note['title'])?></h1>
<p><?=htmlspecialchars($note['description'] ?? '')?></p>
<p>Institute: <?=htmlspecialchars($note['institute'] ?? '')?></p>
<p>Uploader: <?=htmlspecialchars($note['uploader'] ?? '')?></p>
<?php
$ext = strtolower(pathinfo($note['file_path'] ?? '', PATHINFO_EXTENSION));
$isImageFile = in_array($ext, ['jpg','jpeg','png','gif','webp']);
if (!empty($note['file_path']) && !$isImageFile): ?>
<p><a href="<?=htmlspecialchars(ns_media_url($note['file_path']))?>" target="_blank" style="color:#39FF14;">Open File</a></p>
<?php endif; ?>

<?php if (count($images) > 0): ?>
<div class="gallery">
  <img id="mainImg" src="<?=htmlspecialchars(ns_media_url($images[0]['file_path']))?>" alt="photo">
  <?php if (count($images) > 1): ?>
  <button class="nav prev" onclick="prev()">&#10094;</button>
  <button class="nav next" onclick="next()">&#10095;</button>
  <?php endif; ?>
</div>
<div class="thumbs" id="thumbs">
  <?php foreach ($images as $i => $img): ?>
  <img src="<?=htmlspecialchars(ns_media_url($img['file_path']))?>" data-idx="<?=$i?>" onclick="show(<?=$i?>)" class="<?=$i===0?'active':''?>">
  <?php endforeach; ?>
</div>
<script>
const imgs = <?=json_encode(array_map(function($a){return ns_media_url($a['file_path']);}, $images))?>;
let idx = 0;
function show(i){ idx=i; document.getElementById('mainImg').src=imgs[idx]; document.querySelectorAll('#thumbs img').forEach((t,j)=>t.classList.toggle('active',j===idx)); }
function next(){ show((idx+1)%imgs.length); }
function prev(){ show((idx-1+imgs.length)%imgs.length); }
</script>
<?php else: ?>
<p>No attached photos.</p>
<?php endif; ?>

</body>
</html>
