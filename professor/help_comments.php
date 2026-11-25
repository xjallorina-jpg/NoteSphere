<?php
session_start();
require_once '../includes/db_config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$post_id = $_GET['id'] ?? null;

if (!$post_id) {
    header("Location: help_board.php");
    exit();
}

if (isset($_GET['mark_read'])) {
    $notif_id = $_GET['mark_read'];
    $mark_stmt = $conn->prepare("UPDATE help_notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $mark_stmt->execute([$notif_id, $user_id]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_comment'])) {
    $comment_text = trim($_POST['comment_text']);
    if (!empty($comment_text)) {
        $columns_query = $conn->query("SHOW COLUMNS FROM help_comments");
        $table_columns = $columns_query->fetchAll(PDO::FETCH_COLUMN);

        $possible_names = ['comment', 'message', 'content', 'body', 'reply', 'text', 'comment_text', 'comments', 'answer'];
        $found_column = null;

        foreach ($possible_names as $name) {
            if (in_array($name, $table_columns)) {
                $found_column = $name;
                break;
            }
        }

        if (!$found_column) {
            foreach ($table_columns as $col) {
                if (!in_array($col, ['id', 'question_id', 'student_id', 'user_id', 'created_at', 'updated_at'])) {
                    $found_column = $col;
                    break;
                }
            }
        }

        if ($found_column) {
            $c_stmt = $conn->prepare("INSERT INTO help_comments (question_id, user_id, `$found_column`, created_at) VALUES (?, ?, ?, NOW())");
            $c_stmt->execute([$post_id, $user_id, $comment_text]);

            $comment_id = (int) $conn->lastInsertId();
        } else {
            die("Error: Walang makitang text column para sa komento sa help_comments table.");
        }

        $q_owner_stmt = $conn->prepare("SELECT student_id FROM help_questions WHERE id = ?");
        $q_owner_stmt->execute([$post_id]);
        $owner = $q_owner_stmt->fetch(PDO::FETCH_ASSOC);

        if ($owner && $owner['student_id'] != $user_id) {
            $notif_insert = $conn->prepare("INSERT INTO help_notifications (user_id, actor_id, question_id, is_read, created_at) VALUES (?, ?, ?, 0, NOW())");
            $notif_insert->execute([$owner['student_id'], $user_id, $post_id]);

            // Also feed the shared notification center used by Student Notifications.
            try {
                $actor_name = (string) ($_SESSION['full_name'] ?? 'A professor');
                $title_stmt = $conn->prepare("SELECT title FROM help_questions WHERE id = ? LIMIT 1");
                $title_stmt->execute([$post_id]);
                $question_title = (string) $title_stmt->fetchColumn();

                $shared_insert = $conn->prepare("
                    INSERT INTO notifications
                    (user_id, type, title, message, question_id, comment_id)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $shared_insert->execute([
                    $owner['student_id'],
                    'help_comment',
                    'Professor replied to your post',
                    $actor_name . ' replied to "' . $question_title . '".',
                    $post_id,
                    $comment_id ?? null
                ]);
            } catch (PDOException $notificationError) {
                error_log('NoteSphere help notification error: ' . $notificationError->getMessage());
            }
        }

        header("Location: view_post.php?id=" . $post_id);
        exit();
    }
}

$post_stmt = $conn->prepare("
    SELECT q.*, u.full_name, u.role, u.profile_pic, u.email 
    FROM help_questions q 
    JOIN users u ON q.student_id = u.user_id 
    WHERE q.id = ?
");
$post_stmt->execute([$post_id]);
$post = $post_stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    header("Location: help_board.php");
    exit();
}

$comm_stmt = $conn->prepare("
    SELECT c.*, u.full_name, u.role, u.profile_pic, u.email 
    FROM help_comments c 
    JOIN users u ON c.user_id = u.user_id 
    WHERE c.question_id = ? 
    ORDER BY c.created_at ASC
");
$comm_stmt->execute([$post_id]);
$comments = $comm_stmt->fetchAll(PDO::FETCH_ASSOC);

$user_stmt = $conn->prepare("SELECT full_name, email, profile_pic FROM users WHERE user_id = ?");
$user_stmt->execute([$user_id]);
$current_user = $user_stmt->fetch(PDO::FETCH_ASSOC);
$profile_pic = !empty($current_user['profile_pic']) ? $current_user['profile_pic'] : 'https://api.dicebear.com/7.x/avataaars/svg?seed=' . urlencode($current_user['email'] ?? 'user');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Post - NoteSphere</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { 
            --neon: #39FF14; 
            --neon-glow: rgba(57, 255, 20, 0.15);
            --bg: #050505; 
            --card-bg: #0a0a0a; 
            --text: #f8fafc; 
            --sidebar-bg: #000;
            --border-color: #1a1a1a; 
            --sub-text: #888;
            --hover-bg: rgba(255, 255, 20, 0.05);
            --input-bg: #030303;
        }

        body.light-mode {
            --bg: #f4f6f9;
            --card-bg: #ffffff;
            --text: #000000;
            --sidebar-bg: #ffffff;
            --border-color: #e2e8f0;
            --sub-text: #64748b;
            --neon: #2ecc71;
            --neon-glow: rgba(46, 204, 113, 0.1);
            --hover-bg: #f2f2f2;
            --input-bg: #f8fafc;
            background: linear-gradient(135deg,rgba(5,7,13,.55),rgba(5,7,13,.25)),repeating-linear-gradient(0deg,rgba(57,255,20,.04) 0 1px,transparent 1px 34px),repeating-linear-gradient(90deg,rgba(57,255,20,.025) 0 1px,transparent 1px 34px) no-repeat center fixed !important;
            background-size: cover !important;
        }

        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
            font-family: 'Plus Jakarta Sans', sans-serif; 
        }

        body { 
            background: linear-gradient(rgba(11, 11, 11, 0.85), rgba(11, 11, 11, 0.92)), linear-gradient(135deg,rgba(5,7,13,.55),rgba(5,7,13,.25)),repeating-linear-gradient(0deg,rgba(57,255,20,.04) 0 1px,transparent 1px 34px),repeating-linear-gradient(90deg,rgba(57,255,20,.025) 0 1px,transparent 1px 34px) no-repeat center fixed !important; 
            background-size: cover !important;
            color: var(--text);
            font-family: 'Plus Jakarta Sans', sans-serif; 
            display: flex; 
            flex-direction: column;
            margin: 0;
            min-height: 100vh;
            padding-bottom: 40px;
            transition: background 0.3s ease, color 0.3s ease;
        }
        
        .fb-header { background-color: var(--card-bg); border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; padding: 0 16px; height: 56px; position: sticky; top: 0; z-index: 100; box-shadow: 0 1px 2px rgba(0,0,0,0.1); }
        .header-left h2 { color: var(--neon); font-size: 1.3rem; font-weight: 800; }
        .header-right-group { display: flex; align-items: center; gap: 6px; }
        
        .nav-icon-btn { width: 38px; height: 38px; border-radius: 50%; background: var(--hover-bg); border: none; display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--sub-text); text-decoration: none; font-size: 1rem; transition: background 0.2s; }
        .nav-icon-btn:hover { background: var(--border-color); color: var(--neon); }
        .nav-profile-pic { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-color); }

        .fb-container { width: 100%; max-width: 680px; margin: 20px auto; padding: 0 15px; }
        .post-card, .comment-card, .comment-box-card { background: var(--card-bg); border-radius: 12px; border: 1px solid var(--border-color); padding: 16px; margin-bottom: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.15); transition: background-color 0.5s ease, border-color 0.5s ease; }
        
        .post-header, .comment-header { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
        .avatar { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-color); }
        .author-info h4 { font-size: 0.95rem; font-weight: 700; color: var(--text); }
        .author-info span { font-size: 0.75rem; color: var(--sub-text); }
        
        .post-content p, .comment-body p { font-size: 0.95rem; line-height: 1.4; margin-bottom: 12px; word-break: break-word; color: var(--text); }
        .comments-section-title { font-size: 1rem; font-weight: 700; margin: 20px 0 10px 0; color: var(--text); }
        
        .comment-card:target {
            background-color: rgba(250, 204, 21, 0.15) !important;
            border: 2px solid #facc15 !important;
            box-shadow: 0 0 12px rgba(250, 204, 21, 0.4);
        }
        body.light-mode .comment-card:target {
            background-color: #fff9db !important;
            border: 2px solid #eab308 !important;
        }
        
        .comment-form textarea { width: 100%; padding: 12px; border-radius: 8px; border: 1px solid var(--border-color); background: var(--input-bg); color: var(--text); resize: vertical; min-height: 70px; font-size: 0.95rem; margin-bottom: 10px; outline: none; }
        .comment-form textarea:focus { border-color: var(--neon); }
        .submit-btn { background: var(--neon); color: #000; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 800; cursor: pointer; font-size: 0.9rem; float: right; transition: opacity 0.2s; }
        .submit-btn:hover { opacity: 0.9; }
        .clearfix::after { content: ""; clear: both; display: table; }
    </style>
</head>
<body>
    <header class="fb-header">
        <div class="header-left">
            <h2>NoteSphere</h2>
        </div>
        <div class="header-right-group">
            <a href="help_board.php" class="nav-icon-btn" title="Help Board Feed">🏠</a>
            <a href="help_history.php" class="nav-icon-btn" title="My Post History">📜</a>
            <a href="help_notifications.php" class="nav-icon-btn" title="Notifications">🔔</a>
            <a href="profile.php"><img src="<?php echo htmlspecialchars($profile_pic); ?>" alt="Profile" class="nav-profile-pic"></a>
            <a href="student_dashboard.php" class="nav-icon-btn" title="Back to Dashboard">🚪</a>
        </div>
    </header>

    <div class="fb-container">
        <!-- Main Post -->
        <div class="post-card">
            <div class="post-header">
                <img src="<?php echo !empty($post['profile_pic']) ? $post['profile_pic'] : 'https://api.dicebear.com/7.x/avataaars/svg?seed=' . urlencode($post['email']); ?>" class="avatar" alt="Avatar">
                <div class="author-info">
                    <h4><?php echo htmlspecialchars($post['full_name']); ?></h4>
                    <span><?php echo date('M d, Y • h:i A', strtotime($post['created_at'])); ?></span>
                </div>
            </div>
            <div class="post-content">
                <?php if (!empty($post['title'])): ?>
                    <h3 style="margin-bottom: 8px; font-size: 1.1rem; color: var(--text);"><?php echo htmlspecialchars($post['title']); ?></h3>
                <?php endif; ?>
                <p><?php echo nl2br(htmlspecialchars($post['question'] ?? $post['content'] ?? '')); ?></p>
            </div>
        </div>

        <div class="comments-section-title">💬 Comments (<?php echo count($comments); ?>)</div>

        <!-- Comments List -->
        <div class="comments-list">
            <?php if (!empty($comments)): ?>
                <?php foreach ($comments as $c): ?>
                    <?php 
                        $comment_body = '';
                        foreach ($c as $key => $val) {
                            if (!in_array($key, ['id', 'question_id', 'student_id', 'user_id', 'full_name', 'role', 'profile_pic', 'email', 'created_at', 'updated_at'])) {
                                $comment_body = $val;
                                break;
                            }
                        }
                    ?>
                    <div class="comment-card" id="comment-<?php echo $c['id']; ?>">
                        <div class="comment-header">
                            <img src="<?php echo !empty($c['profile_pic']) ? $c['profile_pic'] : 'https://api.dicebear.com/7.x/avataaars/svg?seed=' . urlencode($c['email'] ?? 'user'); ?>" class="avatar" alt="Avatar">
                            <div class="author-info">
                                <h4><?php echo htmlspecialchars($c['full_name'] ?? 'User'); ?> <span style="font-size: 0.7rem; background: var(--border-color); color: var(--neon); padding: 2px 6px; border-radius: 4px;"><?php echo htmlspecialchars($c['role'] ?? 'Student'); ?></span></h4>
                                <span><?php echo date('M d, Y • h:i A', strtotime($c['created_at'])); ?></span>
                            </div>
                        </div>
                        <div class="comment-body">
                            <p><?php echo nl2br(htmlspecialchars($comment_body)); ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="color: var(--sub-text); text-align: center; padding: 15px 0;">No comments yet. Be the first to reply!</p>
            <?php endif; ?>
        </div>

        <!-- Comment Form Box -->
        <div class="comment-box-card" style="margin-top: 15px;">
            <form action="view_post.php?id=<?php echo $post_id; ?>" method="POST" class="comment-form clearfix">
                <textarea name="comment_text" placeholder="Write a comment..." required></textarea>
                <button type="submit" name="submit_comment" class="submit-btn">Post Comment</button>
            </form>
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

            if (window.location.hash) {
                const targetElement = document.querySelector(window.location.hash);
                if (targetElement) {
                    setTimeout(() => {
                        targetElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }, 200);
                }
            }
        });

        window.addEventListener('storage', (event) => {
            if (event.key === 'notesphere_theme' || event.key === 'theme') {
                if (event.newValue) applyTheme(event.newValue);
            }
        });
    </script>
</body>
</html>