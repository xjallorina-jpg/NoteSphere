<?php
/**
 * NoteSphere - Student Notifications
 * Upgrade: show the commenter and comment preview, and open the related
 * Help Board comment when a matching comment anchor exists.
 */
session_start();
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../includes/db_config.php';
require_once __DIR__ . '/../includes/notification_counts.php';
/** @var PDO $conn */

if (!isset($_SESSION['user_id'], $_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];

if (!function_exists('h')) {
    function h(mixed $value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$student_name = 'Student';
try {
    $stmt = $conn->prepare('SELECT full_name FROM users WHERE user_id = ? LIMIT 1');
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $student_name = $user['full_name'] ?? 'Student';
} catch (Throwable $e) {
    // Keep the page available if the profile lookup fails.
}

// Open only a notification owned by the signed-in student and mark it read.
if (isset($_GET['open']) && ctype_digit((string)$_GET['open'])) {
    $notification_id = (int) $_GET['open'];
    try {
        $stmt = $conn->prepare('SELECT question_id, comment_id, announcement_id FROM notifications WHERE notification_id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$notification_id, $user_id]);
        $notification = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($notification) {
            $mark = $conn->prepare('UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?');
            $mark->execute([$notification_id, $user_id]);

            $question_id = (int)($notification['question_id'] ?? 0);
            $comment_id = (int)($notification['comment_id'] ?? 0);

            if ($question_id > 0) {
                // The Help Board should use id="comment-{comment_id}" on each comment.
                $anchor = $comment_id > 0 ? 'comment-' . $comment_id : 'post-' . $question_id;
                header('Location: help_board.php#' . rawurlencode($anchor));
                exit();
            }
        }
    } catch (Throwable $e) {
        // Redirect safely if a notification cannot be opened.
    }
    header('Location: student_notifications.php');
    exit();
}

if (isset($_GET['mark_all']) && $_GET['mark_all'] === '1') {
    try {
        $stmt = $conn->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?');
        $stmt->execute([$user_id]);
    } catch (Throwable $e) {
        // Keep the page usable if marking all read fails.
    }
    header('Location: student_notifications.php');
    exit();
}

$notifications = [];
$notifications_load_error = false;
try {
    $stmt = $conn->prepare("
        SELECT
            n.notification_id, n.type, n.title, n.message, n.announcement_id, n.question_id, n.comment_id,
            n.is_read, n.created_at,
            c.comment AS comment_text,
            q.title AS question_title,
            commenter.full_name AS sender_name
        FROM notifications n
        LEFT JOIN help_comments c ON c.id = n.comment_id
        LEFT JOIN help_questions q ON q.id = n.question_id
        LEFT JOIN users commenter ON commenter.user_id = c.user_id
        WHERE n.user_id = ?
        ORDER BY n.created_at DESC, n.notification_id DESC
    ");
    $stmt->execute([$user_id]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $notifications = [];
    $notifications_load_error = true;
    error_log('[NoteSphere Student Notifications] SELECT failed: ' . $e->getMessage());
}

// Opening the notification centre counts as reading it. The rows above keep
// their original is_read value for this render (unread styling), while the
// database flags and the nav badges are cleared immediately.
try {
    $conn->prepare('UPDATE `notifications` SET `is_read` = 1 WHERE `user_id` = ?')->execute([$user_id]);
    $conn->prepare('UPDATE `help_notifications` SET `is_read` = 1 WHERE `user_id` = ?')->execute([$user_id]);
} catch (Throwable $e) {
    // The legacy table may not exist; shared notifications are already updated.
}

$ns_badge_counts = ns_notification_counts($conn, $user_id);
$unread_count = 0;

$profile_initial = 'S';
if (trim($student_name) !== '') {
    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        $profile_initial = mb_strtoupper(mb_substr(trim($student_name), 0, 1, 'UTF-8'), 'UTF-8');
    } else {
        $profile_initial = strtoupper(substr(trim($student_name), 0, 1));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notifications | NoteSphere</title>
<link rel="stylesheet" href="../assets/notification_badges.css">
<script src="../assets/notification_badges.js" data-csrf="<?php echo h(ns_notification_csrf_token()); ?>" defer></script>
<script>
(function () {
    const savedTheme = localStorage.getItem('notesphere_theme') || localStorage.getItem('theme') || 'dark';
    document.documentElement.setAttribute('data-theme', savedTheme);
})();
</script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#080808;--topbar:rgba(8,8,8,.96);--card:#0d0d0d;--card-hover:#151515;--border:#252525;--border-hover:#383838;--text:#f8fafc;--sub-text:#bdbdbd;--muted:#777;--icon-bg:#182414;--green:#39ff14;--green-soft:#0e170c;--button:#171717;--button-hover:#2b2b2b;--shadow:rgba(0,0,0,.35)}
html[data-theme="light"]{--bg:#ffffff;--topbar:rgba(255,255,255,.96);--card:#fff;--card-hover:#f1f5f2;--border:#dfe6e1;--border-hover:#cbd6cf;--text:#000000;--sub-text:#000000;--muted:#000000;--icon-bg:#e8f6e5;--green:#24a812;--green-soft:#edf9ea;--button:#edf1ee;--button-hover:#e1e7e3;--shadow:rgba(0,0,0,.08)}
html,body{min-height:100%;background:var(--bg)}
body{font-family:Arial,Helvetica,sans-serif;color:var(--text);min-height:100vh;background:var(--bg);transition:background .25s ease,color .25s ease}
.topbar{position:sticky;top:0;z-index:1000;height:70px;background:var(--topbar);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 25px;backdrop-filter:blur(12px);transition:background .25s ease,border-color .25s ease}
.top-left{width:180px;display:flex;align-items:center}.back-btn{width:42px;height:42px;border:0;border-radius:50%;background:var(--button);color:var(--text);font-size:22px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:.2s}.back-btn:hover{background:var(--button-hover);transform:scale(1.04)}.brand{margin-left:12px;font-size:19px;font-weight:700;color:var(--green)}
.top-nav{display:flex;align-items:center;justify-content:center;gap:8px;flex:1}.nav-item{width:115px;height:58px;border-radius:10px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-decoration:none;color:var(--muted);position:relative;transition:.2s}.nav-item:hover{background:var(--card-hover);color:var(--text)}.nav-item.active{color:var(--green)}.nav-item.active:after{content:"";position:absolute;bottom:0;left:20px;right:20px;height:3px;background:var(--green);border-radius:10px}.nav-item svg{width:22px;height:22px;margin-bottom:3px}.nav-item span{font-size:11px}.notification-icon{position:relative}.notification-count{position:absolute;top:5px;right:25px;min-width:17px;height:17px;padding:0 4px;background:#ff3b30;color:#fff;border-radius:20px;font-size:9px;font-weight:800;display:flex;align-items:center;justify-content:center;border:2px solid var(--topbar)}
.top-right{width:220px;display:flex;justify-content:flex-end;align-items:center;gap:7px}.profile-btn{display:flex;align-items:center;gap:8px;text-decoration:none;color:var(--text);padding:8px 10px;border-radius:10px;transition:.2s}.profile-btn:hover{background:var(--card-hover)}.profile-circle{width:36px;height:36px;border-radius:50%;background:var(--green);color:#050505;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0}.profile-name{max-width:105px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:13px;color:var(--text)}
.page{width:100%;max-width:750px;margin:auto;padding:28px 15px 60px}.heading{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;gap:10px}.heading h1{font-size:27px;color:var(--text)}.mark-read{color:var(--green);text-decoration:none;font-size:12px;font-weight:700;padding:7px 10px;border-radius:7px;white-space:nowrap}.mark-read:hover{background:var(--green-soft)}
.notification{display:flex;gap:13px;padding:16px;background:var(--card);border:1px solid var(--border);border-radius:14px;margin-bottom:10px;text-decoration:none;color:var(--text);transition:.2s;position:relative;box-shadow:0 4px 14px var(--shadow)}.notification:hover{background:var(--card-hover);border-color:var(--border-hover);transform:translateY(-1px)}.notification.unread{background:var(--green-soft);border-left:4px solid var(--green)}.notification-icon-box{width:45px;height:45px;border-radius:50%;background:var(--icon-bg);color:var(--green);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:20px}.notification-content{flex:1;min-width:0}.notification-title{font-weight:700;font-size:14px;margin-bottom:4px;color:var(--text);padding-right:20px;overflow-wrap:anywhere}.notification-message{color:var(--sub-text);font-size:13px;line-height:1.45;overflow-wrap:anywhere}.notification-post{margin-top:8px;padding:8px 10px;border-radius:8px;background:var(--button);color:var(--sub-text);font-size:11px;line-height:1.45;overflow-wrap:anywhere}.notification-post strong{color:var(--text)}.comment-preview{margin-top:7px;padding:9px 10px;border-left:2px solid var(--green);background:var(--button);border-radius:7px;color:var(--sub-text);font-size:12px;line-height:1.45;overflow-wrap:anywhere}.comment-label{display:block;color:var(--green);font-size:10px;font-weight:700;margin-bottom:3px;text-transform:uppercase}.commenter-name{color:var(--text);font-weight:700}.notification-time{color:var(--muted);font-size:10px;margin-top:7px}.unread-dot{width:9px;height:9px;border-radius:50%;background:var(--green);position:absolute;right:15px;top:19px}
.empty{background:var(--card);border:1px solid var(--border);border-radius:15px;padding:55px 20px;text-align:center;color:var(--muted);box-shadow:0 4px 14px var(--shadow)}.empty-icon{width:70px;height:70px;margin:0 auto 15px;border-radius:50%;background:var(--icon-bg);display:flex;align-items:center;justify-content:center;font-size:32px}.empty h3{color:var(--text);margin-bottom:7px;font-size:16px}.empty p{color:var(--sub-text);font-size:13px;line-height:1.5;max-width:420px;margin:auto}
@media(max-width:850px){.topbar{padding:0 15px}.top-left{width:150px}.top-right{width:150px}}
@media(max-width:750px){.topbar{padding:0 10px}.top-left,.top-right{width:auto}.brand{display:none}.profile-name{display:none}.nav-item{width:70px}.nav-item span{font-size:9px}}
@media(max-width:480px){.topbar{height:62px}.back-btn{width:38px;height:38px}.profile-circle{width:34px;height:34px}.top-nav{gap:2px}.nav-item{width:58px;height:54px}.nav-item svg{width:19px;height:19px}.nav-item span{font-size:8px}.notification-count{right:11px}.page{padding:20px 10px 50px}.heading h1{font-size:23px}.heading{align-items:flex-start}.mark-read{font-size:11px}.notification{padding:13px;gap:10px}.notification-icon-box{width:40px;height:40px;font-size:18px}.notification-title{font-size:13px}.notification-message{font-size:12px}.notification-post{font-size:10px}.comment-preview{font-size:11px}}
@media(max-width:350px){.topbar{padding:0 6px}.nav-item{width:50px}.profile-btn{gap:5px;padding:4px}.profile-circle{width:30px;height:30px}}
        /* iOS: keep form fields at 16px so Safari does not zoom on focus. */
        @supports (-webkit-touch-callout: none) {
            input,
            select,
            textarea {
                font-size: 16px;
            }
        }
</style>
</head>
<body>
<header class="topbar">
    <div class="top-left">
        <button type="button" class="back-btn" onclick="window.location.href='student_home.php';" title="Back" aria-label="Back to dashboard">×</button>
        <div class="brand">NoteSphere</div>
    </div>
    <nav class="top-nav">
        <a href="help_board.php" class="nav-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 10.5L12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9 21v-7h6v7"/></svg><span>Home</span>
        </a>
        <!-- MESSAGES -->
<a
    href="student_messages.php"
    class="nav-item message-nav ns-nav-link"
    title="Messages"
    aria-label="Open messages"
>
    <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
    >
        <path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8
                 8.5 8.5 0 0 1-7.6 4.7
                 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7
                 a8.4 8.4 0 0 1-.9-3.8
                 8.5 8.5 0 0 1 4.7-7.6
                 8.4 8.4 0 0 1 3.8-.9h.5
                 a8.5 8.5 0 0 1 8 8v.5z"
        />
    </svg>
    <?php echo ns_notification_badge_html((int)($ns_badge_counts['messages'] ?? 0), 'messages'); ?>

    <span>Messages</span>
</a>
        <a href="student_history.php" class="nav-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><span>History</span>
        </a>

        <a href="student_notifications.php" class="nav-item active notification-icon ns-nav-link" aria-current="page">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
            <?php echo ns_notification_badge_html((int)($ns_badge_counts['help'] ?? 0), 'help'); ?>
            <span>Notifications</span>
        </a>
    </nav>
    <div class="top-right">
        <a href="student_profile.php" class="profile-btn" title="<?php echo h($student_name); ?>">
            <div class="profile-circle"><?php echo h($profile_initial); ?></div><span class="profile-name"><?php echo h($student_name); ?></span>
        </a>
    </div>
</header>
<main class="page">
    <div class="heading">
        <h1>Notifications</h1>
        <?php if ($unread_count > 0): ?><a href="student_notifications.php?mark_all=1" class="mark-read" onclick="return confirm('Mark all notifications as read?');">Mark all as read</a><?php endif; ?>
    </div>

    <?php if ($notifications_load_error): ?>
        <div class="empty"><div class="empty-icon">⚠️</div><h3>Unable to load notifications</h3><p>The notification query failed. Check <strong>C:\xampp\apache\logs\error.log</strong> for the database error. Your announcements and comments have not been deleted.</p></div>
    <?php elseif (!empty($notifications)): ?>
        <?php foreach ($notifications as $notification):
            $is_comment = strtolower((string)($notification['type'] ?? '')) === 'comment' || !empty($notification['comment_id']);
            $sender_name = trim((string)($notification['sender_name'] ?? ''));
            $comment_text = trim((string)($notification['comment_text'] ?? ''));
            $question_title = trim((string)($notification['question_title'] ?? ''));
            $display_title = trim((string)($notification['title'] ?? ''));
            if ($is_comment && $sender_name !== '') {
                $display_title = $sender_name . ' commented on your post';
            } elseif ($display_title === '') {
                $display_title = $is_comment ? 'New comment on your post' : 'New notification';
            }
            $timestamp = !empty($notification['created_at']) ? strtotime($notification['created_at']) : false;
        ?>
            <a href="student_notifications.php?open=<?php echo (int)$notification['notification_id']; ?>" class="notification <?php echo empty($notification['is_read']) ? 'unread' : ''; ?>">
                <div class="notification-icon-box"><?php echo $is_comment ? '💬' : '🔔'; ?></div>
                <div class="notification-content">
                    <div class="notification-title"><?php echo h($display_title); ?></div>
                    <div class="notification-message">
                        <?php
                        $message = trim((string)($notification['message'] ?? ''));
                        if ($is_comment && $sender_name !== '') {
                            // Avoid repeating the sender's name if the stored message already includes it.
                            echo h($message !== '' ? $message : 'Someone commented on your Help Board post.');
                        } else {
                            echo h($message);
                        }
                        ?>
                    </div>
                    <?php if ($question_title !== ''): ?>
                        <div class="notification-post"><strong>Your post:</strong> <?php echo h($question_title); ?></div>
                    <?php endif; ?>
                    <?php if ($comment_text !== ''): ?>
                        <div class="comment-preview"><span class="comment-label">Comment by <?php echo h($sender_name !== '' ? $sender_name : 'another user'); ?></span><?php echo h($comment_text); ?></div>
                    <?php elseif ($is_comment && $sender_name !== ''): ?>
                        <div class="comment-preview"><span class="comment-label">Commenter</span><?php echo h($sender_name); ?> commented on this post.</div>
                    <?php endif; ?>
                    <div class="notification-time"><?php echo $timestamp ? h(date('M d, Y · h:i A', $timestamp)) : ''; ?> · Tap to view</div>
                </div>
                <?php if (empty($notification['is_read'])): ?><div class="unread-dot" aria-label="Unread notification"></div><?php endif; ?>
            </a>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty"><div class="empty-icon">🔔</div><h3>No notifications yet</h3><p>You will see your Help Board comment notifications and other available notifications here.</p></div>
    <?php endif; ?>
</main>
<script>
function syncStudentTheme(){
    const savedTheme = localStorage.getItem('notesphere_theme') || localStorage.getItem('theme') || 'dark';
    document.documentElement.setAttribute('data-theme', savedTheme);
}
syncStudentTheme();
window.addEventListener('storage', function(event){
    if(event.key === 'notesphere_theme' || event.key === 'theme') syncStudentTheme();
});
</script>
</body>
</html>

