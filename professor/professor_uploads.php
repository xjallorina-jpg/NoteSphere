<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include('../includes/db_config.php');
/** @var PDO $conn */

// Security check: Siguraduhing professor ang nakalogin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'professor') {
    header("Location: ../auth/login.php"); 
    exit(); 
}

$message = "";

// Process the upload
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $user_id = (int) $_SESSION['user_id'];

    // Files may arrive either as a normal $_FILES upload (local XAMPP) or as
    // an already-uploaded Cloudinary URL (Vercel).
    $cloudFile = ns_cloud_upload_from_request('note_file');
    $cloudThumb = ns_cloud_upload_from_request('thumbnail');
    $single = $_FILES['note_file'] ?? null;
    $thumb = $_FILES['thumbnail'] ?? null;

    $hasCloud = $cloudFile !== null;
    $hasLocal = is_array($single) && (int)($single['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

    if ($title === '') {
        $message = "❌ Please enter a title.";
    } elseif (!$hasCloud && !$hasLocal) {
        $message = "❌ Please choose a file to upload.";
    } else {
        // Limit: 1 GB per file
        $maxBytes = 1024 * 1024 * 1024;
        $fileSize = $hasCloud ? (int)$cloudFile['size'] : (int)($single['size'] ?? 0);

        if ($fileSize > $maxBytes) {
            $message = "❌ File too large. Max 1 GB per file.";
        } else {
            try {
                $storedFile = $hasCloud
                    ? $cloudFile['path']
                    : ns_store_uploaded_file($single, 'notes')['path'];

                $storedThumb = '';
                if ($cloudThumb !== null) {
                    $storedThumb = $cloudThumb['path'];
                } elseif (is_array($thumb) && (int)($thumb['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $storedThumb = ns_store_uploaded_file($thumb, 'notes/thumbnails')['path'];
                }

                // Deretso approved na agad dahil professor ang nag-upload
                $sql = "INSERT INTO notes (user_id, title, description, file_path, thumbnail, status) VALUES (?, ?, ?, ?, ?, 'approved')";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$user_id, $title, $description, $storedFile, $storedThumb]);

                $message = "✅ Success! Resource published successfully.";
            } catch (Throwable $e) {
                error_log('NoteSphere professor upload failed: ' . $e->getMessage());
                $message = "❌ " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Resource | NoteSphere Pro</title>
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#0f172a">
    <style>
        :root { --bg: #030303; --card: #0a0a0a; --gold: #FFD700; --text: #ffffff; --border: #1a1a1a; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background: var(--bg); color: var(--text); display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
        
        .container { width: 100%; max-width: 480px; padding: 40px; background: var(--card); border: 1px solid var(--border); border-radius: 20px; box-shadow: 0 30px 60px rgba(0,0,0,0.5); }
        h2 { font-size: 1.2rem; text-transform: uppercase; letter-spacing: 2px; color: var(--gold); margin-bottom: 30px; }
        
        .form-group { margin-bottom: 20px; }
        label { display: block; color: #666; font-size: 0.7rem; text-transform: uppercase; margin-bottom: 8px; }
        input, textarea { width: 100%; background: #050505; border: 1px solid var(--border); padding: 14px; border-radius: 8px; color: #fff; font-size: 0.9rem; transition: 0.3s; }
        input:focus, textarea:focus { border-color: var(--gold); outline: none; }
        
        button { width: 100%; padding: 15px; background: var(--gold); border: none; border-radius: 8px; color: #000; font-weight: 800; cursor: pointer; transition: 0.3s; margin-top: 10px; }
        button:hover { filter: brightness(1.2); }
        
        .status-msg { font-size: 0.8rem; padding: 15px; margin-bottom: 20px; border-left: 3px solid var(--gold); background: #111; border-radius: 4px; }
        .cancel-btn { display: block; text-align: center; margin-top: 20px; color: #444; text-decoration: none; font-size: 0.7rem; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Upload Resource</h2>
        <?php if($message): ?><div class="status-msg"><?= $message ?></div><?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label>Resource Title</label>
                <input type="text" name="title" required>
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" rows="3" required></textarea>
            </div>
            <div class="form-group">
                <label>File Attachment</label>
                <input type="file" name="note_file" accept=".pdf,.doc,.docx,.ppt,.pptx" required>
            </div>
            <div class="form-group">
                <label>Thumbnail <span style="color: var(--gold); text-transform: none;">(Optional)</span></label>
                <input type="file" name="thumbnail" accept="image/*">
            </div>
            <button type="submit">SUBMIT TO SYSTEM</button>
        </form>
        <a href="professor_dashboard.php" class="cancel-btn">CANCEL OPERATION</a>
    </div>
<script src="../assets/cloudinary_upload.js"></script>
</body>
</html>