<?php
session_start();
require_once('../includes/db_config.php');
/** @var PDO $conn */

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'professor') {
    header("Location: ../auth/login.php");
    exit();
}

$announcement_id = $_GET['id'] ?? null;

if (!$announcement_id) {
    header("Location: professor_dashboard.php");
    exit();
}

$message_status = "";

// Kung pinindot ang submit para i-update ang deadline
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $new_deadline = !empty($_POST['deadline']) ? $_POST['deadline'] : null;

    $update_sql = "UPDATE announcements SET deadline = ? WHERE id = ? AND professor_id = ?";
    $update_stmt = $conn->prepare($update_sql);
    
    if ($update_stmt->execute([$new_deadline, $announcement_id, $_SESSION['user_id']])) {
        echo "
        <!DOCTYPE html>
        <html lang='en'>
        <head>
            <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        </head>
        <body style='background: #050505;'>
            <script>
                Swal.fire({
                    title: 'Na-update na!',
                    text: 'Matagumpay na nabago ang deadline ng announcement na ito.',
                    icon: 'success',
                    confirmButtonColor: '#FFD700',
                    background: '#0a0a0a',
                    color: '#ffffff'
                }).then(() => {
                    window.location.href = 'professor_dashboard.php';
                });
            </script>
</body>
        </html>";
        exit();
    } else {
        $message_status = "May error sa pag-update ng deadline.";
    }
}

// Kunin ang kasalukuyang detalye ng announcement
$stmt = $conn->prepare("SELECT * FROM announcements WHERE id = ? AND professor_id = ?");
$stmt->execute([$announcement_id, $_SESSION['user_id']]);
$announcement = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$announcement) {
    header("Location: professor_dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Deadline | NoteSphere</title>
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#0f172a">
    <style>
        :root { --bg: #050505; --panel: #0a0a0a; --gold: #FFD700; --text: #f0f0f0; }
        body { background: var(--bg); color: var(--text); padding: 50px; font-family: sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .card { background: var(--panel); border: 1px solid #1a1a1a; border-radius: 20px; padding: 40px; max-width: 500px; width: 100%; box-sizing: border-box; }
        input { width: 100%; padding: 12px; margin: 15px 0; background: #000; border: 1px solid #333; color: white; border-radius: 8px; box-sizing: border-box; }
        button { background: var(--gold); color: #000; padding: 15px; border: none; border-radius: 8px; width: 100%; font-weight: 800; cursor: pointer; transition: 0.3s; }

        @media (max-width: 600px) {
            body { padding: 20px 14px; }
            .card { padding: 24px 18px; }
        }
        button:hover { background: #e5c100; }
        .back-btn { display: block; text-align: center; color: #666; margin-top: 20px; text-decoration: none; }
        .back-btn:hover { color: #aaa; }
    </style>
</head>
<body>
    <div class="card">
        <h2 style="color: var(--gold); margin-bottom: 10px;">Edit / Extend Deadline</h2>
        <p style="color: #aaa; font-size: 0.9rem; margin-bottom: 20px;"><b>Announcement:</b> <?= htmlspecialchars($announcement['title']) ?></p>
        
        <?php if($message_status) echo "<p style='color:red; margin-bottom:15px;'>$message_status</p>"; ?>
        
        <form method="POST">
            <label style="font-size: 0.9rem; color: #ccc;">Bagong Deadline (Petsa at Oras):</label>
            <input type="datetime-local" name="deadline" value="<?= !empty($announcement['deadline']) ? date('Y-m-d\TH:i', strtotime($announcement['deadline'])) : '' ?>">
            <small style="color: #666; display: block; margin-top: -10px; margin-bottom: 15px;">*Iwanang blanko kung gusto mong tanggalin ang deadline para muling makapagpasa ang lahat.</small>
            
            <button type="submit">I-UPDATE ANG DEADLINE</button>
            <a href="professor_dashboard.php" class="back-btn">Cancel</a>
        </form>
    </div>
</body>
</html>