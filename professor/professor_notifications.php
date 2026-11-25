<?php
session_start();
require_once '../includes/db_config.php';
/** @var PDO $conn */

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch the latest user details from the database
$user_stmt = $conn->prepare("SELECT full_name, email, profile_pic FROM users WHERE user_id = ?");
$user_stmt->execute([$user_id]);
$current_user = $user_stmt->fetch(PDO::FETCH_ASSOC);

$fullname = $current_user['full_name'] ?? 'User';
$email = $current_user['email'] ?? '';

// Helper function para sa profile picture path
function get_profile_picture($pic, $email) {
    if (!empty($pic)) {
        if (strpos($pic, 'http') === 0) {
            return $pic;
        } else {
            $clean_path = str_replace('../', '', $pic);
            return '../' . ltrim($clean_path, '/');
        }
    }
    return 'https://api.dicebear.com/7.x/avataaars/svg?seed=' . urlencode($email);
}

$profile_pic = get_profile_picture($current_user['profile_pic'] ?? '', $email);
$_SESSION['profile_pic'] = $profile_pic;

// Handle single notification deletion
if (isset($_GET['delete'])) {
    $del_id = $_GET['delete'];
    $del_stmt = $conn->prepare("DELETE FROM help_notifications WHERE id = ? AND user_id = ?");
    $del_stmt->execute([$del_id, $user_id]);
    header("Location: help_notifications.php?msg=deleted");
    exit();
}

// Fetch unread notification count for badge counter kung kailangan sa ibang parte
$unread_count_stmt = $conn->prepare("SELECT COUNT(*) FROM help_notifications WHERE user_id = ? AND is_read = 0");
$unread_count_stmt->execute([$user_id]);
$unread_count = $unread_count_stmt->fetchColumn();

// Fetch notifications safely
$stmt = $conn->prepare("
    SELECT * 
    FROM help_notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC
");
$stmt->execute([$user_id]);
$raw_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

$notifications = [];
foreach ($raw_notifications as $n) {
    $actor_name = "A student";
    $actor_role = "Student";
    $actor_pic = 'https://api.dicebear.com/7.x/avataaars/svg?seed=user';
    $comment_text = "";
    $comment_id = null;

    $lookup_id = $n['actor_id'] ?? null;
    
    if (isset($n['question_id'])) {
        $c_stmt = $conn->prepare("SELECT * FROM help_comments WHERE question_id = ? ORDER BY created_at DESC LIMIT 1");
        $c_stmt->execute([$n['question_id']]);
        $commenter = $c_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($commenter) {
            if (!$lookup_id) {
                $lookup_id = $commenter['student_id'] ?? $commenter['user_id'] ?? null;
            }
            $comment_id = $commenter['id'] ?? null;
            $comment_text = $commenter['comment_text'] ?? $commenter['comment'] ?? $commenter['content'] ?? $commenter['message'] ?? $commenter['body'] ?? '';
        }
    }

    if ($lookup_id) {
        $u_stmt = $conn->prepare("SELECT full_name, role, profile_pic, email FROM users WHERE user_id = ?");
        $u_stmt->execute([$lookup_id]);
        $u_data = $u_stmt->fetch(PDO::FETCH_ASSOC);
        if ($u_data) {
            $actor_name = $u_data['full_name'];
            $actor_role = $u_data['role'] ?? 'Student';
            $actor_pic = get_profile_picture($u_data['profile_pic'] ?? '', $u_data['email'] ?? '');
        }
    }

    $n['actor_name'] = $actor_name;
    $n['actor_role'] = $actor_role;
    $n['actor_pic'] = $actor_pic;
    $n['comment_id'] = $comment_id;
    $n['comment_text'] = $comment_text;
    $notifications[] = $n;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - NoteSphere</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { 
            --neon: #39FF14; 
            --neon-glow: rgba(57, 255, 20, 0.15);
            --bg: #030303; 
            --card-bg: #09090b; 
            --text: #f8fafc; 
            --border-color: #1f1f23; 
            --sub-text: #94a3b8;
            --hover-bg: #18181b;
            --danger-red: #ff4d4d;
            --unread-bg: rgba(57, 255, 20, 0.08);
        }

        body.light-mode {
            --card-bg: #ffffff;
            --text: #000000;
            --border-color: #e2e8f0;
            --sub-text: #64748b;
            --neon: #10b981;
            --neon-glow: rgba(16, 185, 129, 0.1);
            --hover-bg: #f1f5f9;
            --unread-bg: #ecfdf5;
        }

        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
            font-family: 'Plus Jakarta Sans', sans-serif; 
        }

        /* Fixed Background Frame */
        .page-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            z-index: -999;
            background: linear-gradient(rgba(11, 11, 11, 0.85), rgba(11, 11, 11, 0.92)), linear-gradient(135deg,rgba(5,7,13,.55),rgba(5,7,13,.25)),repeating-linear-gradient(0deg,rgba(57,255,20,.04) 0 1px,transparent 1px 34px),repeating-linear-gradient(90deg,rgba(57,255,20,.025) 0 1px,transparent 1px 34px) no-repeat center center;
            background-size: cover;
            background-attachment: fixed;
            pointer-events: none;
        }

        body.light-mode .page-bg {
            background: linear-gradient(135deg,rgba(5,7,13,.55),rgba(5,7,13,.25)),repeating-linear-gradient(0deg,rgba(57,255,20,.04) 0 1px,transparent 1px 34px),repeating-linear-gradient(90deg,rgba(57,255,20,.025) 0 1px,transparent 1px 34px) no-repeat center center;
            background-size: cover;
            background-attachment: fixed;
        }

        body { 
            position: relative;
            color: var(--text);
            display: flex; 
            flex-direction: column;
            min-height: 100vh;
            padding-bottom: 40px;
            transition: color 0.3s ease;
        }
        
        /* Minimalist Header with Close (Ekis) Button */
        .history-header {
            width: 100%;
            max-width: 680px;
            margin: 25px auto 10px auto;
            padding: 0 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .history-title-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .history-title-wrap h2 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text);
        }

        .close-btn {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--sub-text);
            text-decoration: none;
            font-size: 1.2rem;
            transition: all 0.2s ease;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .close-btn:hover {
            background: var(--hover-bg);
            color: var(--danger-red);
            border-color: var(--danger-red);
        }

        .fb-container { 
            width: 100%; 
            max-width: 680px; 
            margin: 0 auto; 
            padding: 0 15px; 
        }

        .toast-msg { 
            background: #10b981; 
            color: #000; 
            padding: 12px 16px; 
            border-radius: 12px; 
            margin-bottom: 16px; 
            font-size: 0.9rem; 
            font-weight: 600; 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.1); 
            animation: fadeIn 0.3s ease; 
        }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } }

        .notif-list { 
            background: var(--card-bg); 
            border-radius: 16px; 
            box-shadow: 0 8px 30px rgba(0,0,0,0.12); 
            border: 1px solid var(--border-color); 
            overflow: hidden; 
            transition: background-color 0.3s ease, border-color 0.3s ease; 
        }
        .notif-item-wrapper { display: flex; align-items: center; border-bottom: 1px solid var(--border-color); position: relative; transition: background 0.2s; border-left: 4px solid transparent; }
        .notif-item-wrapper:last-child { border-bottom: none; }
        .notif-item-wrapper:hover { background: var(--hover-bg); }
        .notif-item-wrapper.unread { background: var(--unread-bg); border-left-color: var(--neon); }

        .notif-link { display: flex; align-items: center; gap: 14px; padding: 16px; text-decoration: none; color: var(--text); flex: 1; cursor: pointer; }
        
        .notif-avatar { width: 46px; height: 46px; border-radius: 50%; object-fit: cover; flex-shrink: 0; border: 2px solid var(--neon); background: var(--border-color); }
        .notif-content { flex: 1; font-size: 0.95rem; line-height: 1.4; color: var(--text); }
        .notif-content strong { color: var(--text); font-weight: 700; }
        .notif-role-badge { display: inline-block; font-size: 0.7rem; background: var(--hover-bg); color: var(--sub-text); padding: 2px 6px; border-radius: 6px; margin-left: 5px; font-weight: 600; vertical-align: middle; text-transform: uppercase; border: 1px solid var(--border-color); }
        
        .notif-comment-preview { color: var(--sub-text); font-style: italic; margin-top: 4px; font-size: 0.9rem; }
        .notif-time { font-size: 0.75rem; color: var(--sub-text); margin-top: 4px; }
        
        .notif-right-actions { display: flex; align-items: center; gap: 10px; padding-right: 16px; }
        .notif-dot { width: 10px; height: 10px; background-color: var(--neon); border-radius: 50%; flex-shrink: 0; }
        
        .delete-btn { background: none; border: none; color: var(--sub-text); font-size: 1.2rem; cursor: pointer; padding: 8px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: background 0.2s, color 0.2s; }
        .delete-btn:hover { background: var(--hover-bg); color: var(--danger-red); }

        .custom-modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.7); z-index: 1000; align-items: center; justify-content: center; animation: fadeInModal 0.2s ease; }
        @keyframes fadeInModal { from { opacity: 0; } to { opacity: 1; } }
        .custom-modal-card { background: var(--card-bg); border: 1px solid var(--border-color); width: 90%; max-width: 380px; border-radius: 16px; padding: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); text-align: center; }
        .custom-modal-title { font-size: 1.2rem; font-weight: 700; margin-bottom: 10px; color: var(--text); }
        .custom-modal-body { font-size: 0.95rem; color: var(--sub-text); margin-bottom: 24px; line-height: 1.5; }
        .custom-modal-actions { display: flex; gap: 12px; justify-content: flex-end; }
        .modal-btn { padding: 10px 18px; border-radius: 10px; font-weight: 700; font-size: 0.9rem; cursor: pointer; border: none; transition: opacity 0.2s; }
        .modal-btn-cancel { background: var(--hover-bg); color: var(--text); border: 1px solid var(--border-color); }
        .modal-btn-cancel:hover { background: var(--border-color); }
        .modal-btn-danger { background: var(--danger-red); color: white; }
        .modal-btn-danger:hover { opacity: 0.9; }
    </style>
</head>
<body>
    <!-- Dedicated Fixed Background Layer -->
    <div class="page-bg"></div>

    <!-- Header na may Close / Ekis Button pabalik sa Help Board -->
    <div class="history-header">
        <div class="history-title-wrap">
            <h2>🔔 Notifications</h2>
        </div>
        <a href="help_board.php" class="close-btn" title="Close & Return">
            &#10005;
        </a>
    </div>

    <div class="fb-container">
        <?php if (isset($_GET['msg']) && $_GET['msg'] == 'deleted'): ?>
            <div id="toast" class="toast-msg">
                <span>🗑️ Notification successfully deleted.</span>
                <span style="cursor:pointer; font-weight:bold;" onclick="this.parentElement.style.display='none';">&times;</span>
            </div>
        <?php endif; ?>

        <div class="notif-list">
            <?php if (!empty($notifications)): ?>
                <?php foreach ($notifications as $n): ?>
                    <?php 
                        $is_unread = isset($n['is_read']) && $n['is_read'] == 0;
                        $actor_name = htmlspecialchars($n['actor_name']);
                        $actor_role = htmlspecialchars($n['actor_role']);
                        $actor_pic = htmlspecialchars($n['actor_pic']);
                        $comment_preview = htmlspecialchars($n['comment_text']);
                        
                        $target_link = "view_post.php?id=" . $n['question_id'] . "&mark_read=" . $n['id'];
                        if (!empty($n['comment_id'])) {
                            $target_link .= "#comment-" . $n['comment_id'];
                        }
                    ?>
                    <div class="notif-item-wrapper <?php echo $is_unread ? 'unread' : ''; ?>">
                        <a href="<?php echo $target_link; ?>" class="notif-link">
                            <img src="<?php echo $actor_pic; ?>" alt="<?php echo $actor_name; ?>" class="notif-avatar">
                            <div class="notif-content">
                                <div>
                                    <strong><?php echo $actor_name; ?></strong> 
                                    <span class="notif-role-badge"><?php echo $actor_role; ?></span> 
                                    commented on your question.
                                </div>
                                <?php if (!empty($comment_preview)): ?>
                                    <div class="notif-comment-preview">"<?php echo $comment_preview; ?>"</div>
                                <?php endif; ?>
                                <div class="notif-time"><?php echo date('M d, Y • h:i A', strtotime($n['created_at'])); ?></div>
                            </div>
                        </a>
                        <div class="notif-right-actions">
                            <?php if ($is_unread): ?>
                                <div class="notif-dot"></div>
                            <?php endif; ?>
                            <button type="button" class="delete-btn" title="Delete this notification" onclick="openDeleteModal(<?php echo $n['id']; ?>)">
                                &#8942;
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="text-align: center; color: var(--sub-text); padding: 50px;">
                    <p style="font-size: 1.05rem;">You have no notifications yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Custom In-System Delete Confirmation Modal -->
    <div id="deleteModal" class="custom-modal-overlay">
        <div class="custom-modal-card">
            <div class="custom-modal-title">Delete Confirmation</div>
            <div class="custom-modal-body">Are you sure you want to delete this notification from your list?</div>
            <div class="custom-modal-actions">
                <button type="button" class="modal-btn modal-btn-cancel" onclick="closeDeleteModal()">Cancel</button>
                <button type="button" class="modal-btn modal-btn-danger" id="confirmDeleteBtn">Delete</button>
            </div>
        </div>
    </div>

    <script>
        function applyTheme(theme) {
            if (theme === 'light') {
                document.body.classList.add('light-mode');
            } else {
                document.body.classList.remove('light-mode');
            }
        }

        document.addEventListener("DOMContentLoaded", () => {
            const savedTheme = localStorage.getItem('notesphere_theme') || localStorage.getItem('theme') || 'dark';
            applyTheme(savedTheme);
        });

        window.addEventListener('storage', (event) => {
            if (event.key === 'notesphere_theme' || event.key === 'theme') {
                if (event.newValue) {
                    applyTheme(event.newValue);
                }
            }
        });

        let targetDeleteId = null;

        function openDeleteModal(notifId) {
            targetDeleteId = notifId;
            document.getElementById('deleteModal').style.display = 'flex';
        }

        function closeDeleteModal() {
            targetDeleteId = null;
            document.getElementById('deleteModal').style.display = 'none';
        }

        document.getElementById('confirmDeleteBtn').addEventListener('click', () => {
            if (targetDeleteId !== null) {
                window.location.href = "help_notifications.php?delete=" + targetDeleteId;
            }
        });

        window.addEventListener('click', (e) => {
            const modal = document.getElementById('deleteModal');
            if (e.target === modal) {
                closeDeleteModal();
            }
        });
    </script>
</body>
</html>