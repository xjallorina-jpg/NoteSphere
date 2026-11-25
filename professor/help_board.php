<?php
/**
 * =========================================================
 * NoteSphere - Professor Help Board
 * =========================================================
 * Professors can:
 * - View student questions
 * - Reply to student questions
 * - Receive their own replies in the comment thread
 * - Hide posts from their personal Help Board view
 * - Search student questions
 *
 * Shared tables:
 * help_questions, help_comments, help_hidden_posts,
 * notifications, users
 * =========================================================
 */

session_start();

require_once __DIR__ . '/../includes/db_config.php';
require_once __DIR__ . '/../includes/notification_counts.php';

/** @var PDO $conn */

// =========================================================
// PROFESSOR SECURITY CHECK
// =========================================================

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'professor'
) {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];

// Shared unread counters for the Messages / Notifications nav badges.
$ns_badge_counts = ns_notification_counts($conn, $user_id);

// =========================================================
// CSRF TOKEN
// =========================================================

if (empty($_SESSION['help_board_csrf'])) {
    $_SESSION['help_board_csrf'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['help_board_csrf'];

// =========================================================
// HELPER FUNCTIONS
// =========================================================

function h(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function help_board_initial($value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '?';
    }

    if (function_exists('mb_substr')) {
        return strtoupper(mb_substr($value, 0, 1, 'UTF-8'));
    }

    return strtoupper(substr($value, 0, 1));
}

function help_board_redirect(): void
{
    $search = trim((string) ($_GET['search'] ?? ''));

    $url = 'help_board.php';

    if ($search !== '') {
        $url .= '?search=' . rawurlencode($search);
    }

    header('Location: ' . $url);
    exit();
}

function help_board_flash(string $message, string $type = 'success'): void
{
    $_SESSION['help_board_message'] = $message;
    $_SESSION['help_board_message_type'] = $type;
}

// =========================================================
// FLASH MESSAGE
// =========================================================

$flash_message = (string) ($_SESSION['help_board_message'] ?? '');
$flash_type = (string) ($_SESSION['help_board_message_type'] ?? 'success');

unset(
    $_SESSION['help_board_message'],
    $_SESSION['help_board_message_type']
);

// =========================================================
// PROFESSOR INFORMATION
// =========================================================

$professor_name = 'Professor';

try {
    $stmt = $conn->prepare("
        SELECT full_name
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");

    $stmt->execute([$user_id]);

    $professor = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($professor && !empty($professor['full_name'])) {
        $professor_name = $professor['full_name'];
    }
} catch (PDOException $e) {
    error_log('Professor Help Board profile error: ' . $e->getMessage());
}

// =========================================================
// POST ACTIONS
// =========================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $posted_token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($posted_token) ||
        !hash_equals($csrf_token, $posted_token)
    ) {
        help_board_flash(
            'Invalid request. Please refresh the page and try again.',
            'error'
        );

        help_board_redirect();
    }

    $action = $_POST['action'] ?? '';

    // =====================================================
    // ADD PROFESSOR COMMENT / REPLY
    // =====================================================

    if ($action === 'add_comment') {

        $question_id = filter_var(
            $_POST['question_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $comment = trim((string) ($_POST['comment'] ?? ''));

        $comment_length = function_exists('mb_strlen')
            ? mb_strlen($comment, 'UTF-8')
            : strlen($comment);

        if (!$question_id || $question_id <= 0) {
            help_board_flash('Invalid student question.', 'error');
            help_board_redirect();
        }

        if ($comment === '') {
            help_board_flash('Please enter a reply.', 'error');
            help_board_redirect();
        }

        if ($comment_length > 2000) {
            help_board_flash(
                'Your reply must not exceed 2,000 characters.',
                'error'
            );

            help_board_redirect();
        }

        try {
            $conn->beginTransaction();

            // Verify that the question still exists.
            $check = $conn->prepare("
                SELECT id, student_id, title
                FROM help_questions
                WHERE id = ?
                LIMIT 1
            ");

            $check->execute([$question_id]);

            $question = $check->fetch(PDO::FETCH_ASSOC);

            if (!$question) {
                $conn->rollBack();

                help_board_flash(
                    'This student question no longer exists.',
                    'error'
                );

                help_board_redirect();
            }

            $post_owner_id = (int) $question['student_id'];

            // Insert the professor's reply.
            $insert = $conn->prepare("
                INSERT INTO help_comments
                    (question_id, user_id, comment)
                VALUES
                    (?, ?, ?)
            ");

            $insert->execute([
                $question_id,
                $user_id,
                $comment
            ]);

            $comment_id = (int) $conn->lastInsertId();

            $conn->commit();

            // Notify the student who created the question.
            // A notification failure must not undo the reply.
            if ($post_owner_id > 0 && $post_owner_id !== $user_id) {
                try {
                    $notification_title = 'Professor replied to your post';

                    $notification_message =
                        $professor_name .
                        ' replied to "' .
                        (string) $question['title'] .
                        '".';

                    $notification = $conn->prepare("
                        INSERT INTO notifications
                            (
                                user_id,
                                type,
                                title,
                                message,
                                question_id,
                                comment_id
                            )
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");

                    $notification->execute([
                        $post_owner_id,
                        'comment',
                        $notification_title,
                        $notification_message,
                        $question_id,
                        $comment_id
                    ]);

                    if (function_exists('curl_init')) {
                        $pushPayload = json_encode([
                            'title' => $notification_title,
                            'body' => $notification_message,
                            'tag' => 'notesphere-comment-' . $comment_id,
                            'url' => '/NoteSphere/dashboard/help_board.php#comment-' . $comment_id,
                            'user_ids' => [$post_owner_id]
                        ]);
                        $ch = curl_init(ns_push_server_url('notify'));
                        if ($ch !== false) {
                            curl_setopt($ch, CURLOPT_POST, true);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $pushPayload);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                            curl_exec($ch);
                            curl_close($ch);
                        }
                    }
                } catch (PDOException $notification_error) {
                    error_log(
                        'Professor Help Board notification error: ' .
                        $notification_error->getMessage()
                    );
                }
            }

            help_board_flash(
                'Your reply was added successfully.',
                'success'
            );

        } catch (PDOException $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            error_log('Professor Help Board reply error: ' . $e->getMessage());

            help_board_flash(
                'Unable to add your reply. Please try again.',
                'error'
            );
        }

        help_board_redirect();
    }

    // =====================================================
    // HIDE POST FROM THIS PROFESSOR'S VIEW
    // =====================================================

    if ($action === 'hide_post') {

        $question_id = filter_var(
            $_POST['question_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        if (!$question_id || $question_id <= 0) {
            help_board_flash('Invalid student post.', 'error');
            help_board_redirect();
        }

        try {
            // Check whether the question exists.
            $check = $conn->prepare("
                SELECT id
                FROM help_questions
                WHERE id = ?
                LIMIT 1
            ");

            $check->execute([$question_id]);

            if (!$check->fetch(PDO::FETCH_ASSOC)) {
                help_board_flash(
                    'This student post no longer exists.',
                    'error'
                );

                help_board_redirect();
            }

            // Check whether this professor already hid it.
            $existing = $conn->prepare("
                SELECT id
                FROM help_hidden_posts
                WHERE question_id = ?
                  AND user_id = ?
                LIMIT 1
            ");

            $existing->execute([
                $question_id,
                $user_id
            ]);

            if (!$existing->fetch(PDO::FETCH_ASSOC)) {
                $hide = $conn->prepare("
                    INSERT INTO help_hidden_posts
                        (question_id, user_id)
                    VALUES
                        (?, ?)
                ");

                $hide->execute([
                    $question_id,
                    $user_id
                ]);
            }

            help_board_flash(
                'Post hidden from your Help Board.',
                'success'
            );

        } catch (PDOException $e) {
            error_log('Professor Help Board hide error: ' . $e->getMessage());

            help_board_flash(
                'Unable to hide this post. Please try again.',
                'error'
            );
        }

        help_board_redirect();
    }

    // Unknown POST action.
    help_board_flash('Invalid action.', 'error');
    help_board_redirect();
}

// =========================================================
// SEARCH
// =========================================================

$search = trim((string) ($_GET['search'] ?? ''));

// =========================================================
// GET STUDENT QUESTIONS
// =========================================================

$questions = [];
$questions_error = false;

try {
    $sql = "
        SELECT
            q.id,
            q.student_id,
            q.title,
            q.details,
            q.is_anonymous,
            q.created_at,
            u.full_name
        FROM help_questions q
        LEFT JOIN users u
            ON u.user_id = q.student_id
        LEFT JOIN help_hidden_posts hp
            ON hp.question_id = q.id
           AND hp.user_id = ?
        WHERE hp.id IS NULL
    ";

    $params = [$user_id];

    if ($search !== '') {
        $sql .= "
            AND (
                q.title LIKE ?
                OR q.details LIKE ?
                OR (
                    COALESCE(q.is_anonymous, 0) = 0
                    AND u.full_name LIKE ?
                )
            )
        ";

        $search_value = '%' . $search . '%';

        $params[] = $search_value;
        $params[] = $search_value;
        $params[] = $search_value;
    }

    $sql .= " ORDER BY q.created_at DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Professor Help Board questions error: ' . $e->getMessage());
    $questions_error = true;
}

// =========================================================
// GET COMMENTS FOR DISPLAYED QUESTIONS
// =========================================================

$comments_by_question = [];
$comments_error = false;

if (!empty($questions)) {
    $question_ids = array_map(
        'intval',
        array_column($questions, 'id')
    );

    $placeholders = implode(
        ',',
        array_fill(0, count($question_ids), '?')
    );

    try {
        $comment_stmt = $conn->prepare("
            SELECT
                c.id,
                c.question_id,
                c.user_id,
                c.comment,
                c.created_at,
                u.full_name,
                u.role
            FROM help_comments c
            LEFT JOIN users u
                ON u.user_id = c.user_id
            WHERE c.question_id IN ($placeholders)
            ORDER BY c.created_at ASC, c.id ASC
        ");

        $comment_stmt->execute($question_ids);

        $all_comments = $comment_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($all_comments as $comment) {
            $qid = (int) $comment['question_id'];

            if (!isset($comments_by_question[$qid])) {
                $comments_by_question[$qid] = [];
            }

            $comments_by_question[$qid][] = $comment;
        }

    } catch (PDOException $e) {
        error_log('Professor Help Board comments error: ' . $e->getMessage());
        $comments_error = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Help Board | NoteSphere</title>

    <link rel="stylesheet" href="../assets/notification_badges.css">
    <script src="../assets/notification_badges.js" data-csrf="<?php echo h(ns_notification_csrf_token()); ?>" defer></script>

    <script>
    (function () {
        try {
            const savedTheme =
                localStorage.getItem('notesphere_theme') ||
                localStorage.getItem('theme') ||
                'dark';

            document.documentElement.setAttribute(
                'data-theme',
                savedTheme === 'light' ? 'light' : 'dark'
            );
        } catch (e) {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    })();
    </script>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

    <style>
    /* =====================================================
       NOTESPHERE PROFESSOR HELP BOARD
    ===================================================== */

    :root {
        --topbar: rgba(8, 8, 8, .94);
        --card: #0d0d0d;
        --card-hover: #151515;
        --border: #252525;
        --border-hover: #383838;
        --text: #f8fafc;
        --sub-text: #bdbdbd;
        --muted: #777;
        --input-bg: #151515;
        --input-border: #303030;
        --button-bg: #171717;
        --button-hover: #2b2b2b;
        --green: #39ff14;
        --green-text: #050505;
        --green-soft: #0e170c;
        --shadow: rgba(0,0,0,.35);
    }

    html[data-theme="light"] {
        --topbar: rgba(255,255,255,.94);
        --card: #fff;
        --card-hover: #f3f6f4;
        --border: #dfe6e1;
        --border-hover: #cbd6cf;
        --text: #17211b;
        --sub-text: #59635d;
        --muted: #7a847e;
        --input-bg: #f8fafc;
        --input-border: #dfe6e1;
        --button-bg: #edf1ee;
        --button-hover: #e1e7e3;
        --green: #24a812;
        --green-text: #fff;
        --green-soft: #edf9ea;
        --shadow: rgba(0,0,0,.10);
    }

    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }

    html {
        min-height: 100%;
        background: #0b0b0b;
        overflow-x: hidden;
    }

    html[data-theme="light"] {
        background: #ffffff;
    }

    /* =========================================================
       BACKGROUND - SAME AS VIEW NOTES
    ========================================================= */

    body {
        position: relative;
        min-height: 100vh;
        font-family: Arial, Helvetica, sans-serif;
        color: var(--text);
        background: transparent;
        overflow-x: hidden;
        transition: color .25s ease, background-color .25s ease;
    }

    body::before {
        content: "";
        position: fixed;
        inset: 0;
        z-index: -1;
        pointer-events: none;
        background:
            linear-gradient(
                rgba(11, 11, 11, 0.84),
                rgba(11, 11, 11, 0.91)
            ),
            url('../assets/bginmess.png') no-repeat center center;
        background-size: cover;
    }

    html[data-theme="light"] body::before {
        background:
            url('../assets/bginmess.png') no-repeat center center;
        background-size: cover;
    }

    /* TOP BAR */

    .topbar {
        position: sticky;
        top: 0;
        z-index: 1000;
        width: 100%;
        min-height: 70px;
        background: var(--topbar);
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 22px;
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
    }

    .top-left {
        display: flex;
        align-items: center;
        flex: 0 0 180px;
        min-width: 0;
    }

    .back-btn {
        width: 42px;
        height: 42px;
        flex-shrink: 0;
        border: none;
        border-radius: 50%;
        background: var(--button-bg);
        color: var(--text);
        font-size: 17px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background .2s ease, transform .2s ease;
    }

    .back-btn:hover {
        background: var(--button-hover);
        transform: scale(1.06);
    }

    .brand {
        margin-left: 12px;
        font-size: 19px;
        font-weight: 700;
        color: var(--green);
        white-space: nowrap;
    }

    .top-nav {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        flex: 1;
        min-width: 0;
    }

    .nav-item {
        width: 105px;
        height: 58px;
        flex-shrink: 1;
        border-radius: 10px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        color: var(--muted);
        transition: background .2s ease, color .2s ease;
        position: relative;
        min-width: 0;
    }

    .nav-item i {
        font-size: 20px;
        margin-bottom: 4px;
    }

    .nav-item span {
        font-size: 11px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 100%;
    }

    .nav-item:hover {
        background: var(--card-hover);
        color: var(--text);
    }

    .nav-item.active {
        color: var(--green);
    }

    .nav-item.active::after {
        content: "";
        position: absolute;
        bottom: 0;
        left: 20px;
        right: 20px;
        height: 3px;
        border-radius: 10px;
        background: var(--green);
    }

    .top-right {
        flex: 0 0 180px;
        min-width: 0;
        display: flex;
        justify-content: flex-end;
    }

    .profile-btn {
        display: flex;
        align-items: center;
        gap: 8px;
        max-width: 100%;
        text-decoration: none;
        color: var(--text);
        padding: 7px 10px;
        border-radius: 10px;
    }

    .profile-btn:hover {
        background: var(--button-bg);
    }

    .profile-circle,
    .professor-avatar {
        width: 42px;
        height: 42px;
        flex-shrink: 0;
        border-radius: 50%;
        background: var(--green);
        color: var(--green-text);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        font-size: 15px;
    }

    .profile-circle {
        width: 36px;
        height: 36px;
    }

    .profile-name {
        max-width: 105px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        font-size: 13px;
    }

    /* MAIN */

    .page {
        width: 100%;
        max-width: 800px;
        margin: 0 auto;
        padding: 28px 15px 70px;
    }

    .page-heading {
        margin-bottom: 20px;
    }

    .page-heading h1 {
        font-size: 27px;
        line-height: 1.2;
        margin-bottom: 6px;
    }

    .page-heading p {
        color: var(--muted);
        font-size: 14px;
        line-height: 1.5;
    }

    /* SEARCH */

    .search-box {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 14px;
        padding: 12px;
        margin-bottom: 18px;
        box-shadow: 0 8px 25px var(--shadow);
    }

    .search-form {
        display: flex;
        gap: 8px;
        width: 100%;
    }

    .search-input {
        flex: 1;
        min-width: 0;
        width: 100%;
        background: var(--input-bg);
        border: 1px solid var(--input-border);
        border-radius: 10px;
        padding: 12px 14px;
        color: var(--text);
        outline: none;
        font-size: 14px;
    }

    .search-input::placeholder {
        color: var(--muted);
    }

    .search-input:focus {
        border-color: var(--green);
    }

    .search-btn {
        flex-shrink: 0;
        border: none;
        background: var(--green);
        color: var(--green-text);
        font-weight: 700;
        border-radius: 10px;
        padding: 0 18px;
        min-height: 42px;
        cursor: pointer;
    }

    .search-btn:hover {
        filter: brightness(.92);
        transform: translateY(-1px);
    }

    /* PROFESSOR INFO */

    .professor-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 15px;
        padding: 17px 18px;
        margin-bottom: 18px;
        box-shadow: 0 8px 25px var(--shadow);
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .professor-name {
        font-size: 14px;
        font-weight: 700;
    }

    .professor-sub {
        color: var(--muted);
        font-size: 12px;
        margin-top: 3px;
        line-height: 1.4;
    }

    .feed-title {
        color: var(--sub-text);
        font-size: 13px;
        font-weight: 700;
        margin: 20px 3px 10px;
        text-transform: uppercase;
        letter-spacing: .5px;
    }

    /* QUESTION CARD */

    .post-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 15px;
        margin-bottom: 15px;
        overflow: visible;
        box-shadow: 0 8px 25px var(--shadow);
    }

    .post-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 17px 18px 10px;
    }

    .post-user {
        display: flex;
        align-items: center;
        gap: 11px;
        min-width: 0;
    }

    .post-user > div:last-child {
        min-width: 0;
    }

    .avatar {
        width: 42px;
        height: 42px;
        flex-shrink: 0;
        border-radius: 50%;
        background: var(--green);
        color: var(--green-text);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
    }

    .post-user-name {
        font-size: 14px;
        font-weight: 700;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .post-time {
        color: var(--muted);
        font-size: 11px;
        margin-top: 3px;
    }

    /* POST MENU */

    .post-menu {
        position: relative;
        flex-shrink: 0;
    }

    .menu-btn {
        width: 34px;
        height: 34px;
        border: none;
        border-radius: 50%;
        background: transparent;
        color: var(--muted);
        cursor: pointer;
        font-size: 19px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .menu-btn:hover {
        background: var(--button-bg);
        color: var(--text);
    }

    .menu-form {
        position: absolute;
        top: 38px;
        right: 0;
        display: none;
        background: var(--card);
        border: 1px solid var(--border-hover);
        border-radius: 10px;
        overflow: hidden;
        min-width: 145px;
        z-index: 100;
        box-shadow: 0 8px 25px var(--shadow);
    }

    .menu-form.show {
        display: block;
    }

    .hide-btn {
        width: 100%;
        border: none;
        background: transparent;
        color: var(--text);
        text-align: left;
        padding: 11px 13px;
        cursor: pointer;
        font-family: inherit;
        font-size: 13px;
    }

    .hide-btn:hover {
        background: var(--button-hover);
    }

    /* QUESTION CONTENT */

    .post-content {
        padding: 3px 18px 17px;
    }

    .post-title {
        font-size: 19px;
        line-height: 1.35;
        font-weight: 700;
        margin-bottom: 8px;
        color: var(--text);
        overflow-wrap: anywhere;
    }

    .post-details {
        color: var(--sub-text);
        font-size: 14px;
        line-height: 1.6;
        white-space: pre-wrap;
        word-break: break-word;
        overflow-wrap: anywhere;
    }

    .post-footer {
        border-top: 1px solid var(--border);
        padding: 10px 18px;
        color: var(--muted);
        font-size: 12px;
    }

    /* COMMENTS */

    .comments {
        border-top: 1px solid var(--border);
        padding: 12px 18px 16px;
    }

    .comment {
        display: flex;
        gap: 9px;
        margin-bottom: 10px;
        min-width: 0;
    }

    .comment-avatar {
        width: 30px;
        height: 30px;
        flex-shrink: 0;
        border-radius: 50%;
        background: var(--button-hover);
        color: var(--text);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 11px;
        font-weight: 700;
    }

    .comment-body {
        background: var(--card-hover);
        border-radius: 12px;
        padding: 8px 11px;
        flex: 1;
        min-width: 0;
    }

    .comment-name {
        font-weight: 700;
        font-size: 12px;
        margin-bottom: 3px;
        overflow-wrap: anywhere;
    }

    .comment-text {
        color: var(--sub-text);
        font-size: 13px;
        line-height: 1.45;
        white-space: pre-wrap;
        word-break: break-word;
        overflow-wrap: anywhere;
    }

    .comment-time {
        color: var(--muted);
        font-size: 10px;
        margin-top: 4px;
    }

    /* REPLY FORM */

    .comment-form {
        display: flex;
        gap: 8px;
        margin-top: 8px;
        width: 100%;
    }

    .comment-input {
        flex: 1;
        min-width: 0;
        width: 100%;
        border: 1px solid var(--input-border);
        background: var(--input-bg);
        color: var(--text);
        border-radius: 18px;
        padding: 9px 13px;
        outline: none;
        font-family: inherit;
        font-size: 13px;
    }

    .comment-input::placeholder {
        color: var(--muted);
    }

    .comment-input:focus {
        border-color: var(--green);
    }

    .comment-btn {
        width: 38px;
        height: 38px;
        flex-shrink: 0;
        border: none;
        border-radius: 50%;
        background: var(--green);
        color: var(--green-text);
        cursor: pointer;
        font-size: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .comment-btn:hover {
        filter: brightness(.92);
    }

    /* EMPTY STATE / ERROR */

    .empty-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 15px;
        text-align: center;
        padding: 45px 20px;
        color: var(--muted);
        box-shadow: 0 8px 25px var(--shadow);
    }

    .empty-icon {
        font-size: 42px;
        margin-bottom: 12px;
        color: var(--green);
    }

    .empty-card h3 {
        color: var(--text);
        margin-bottom: 6px;
    }

    /* RESPONSIVE */

    @media (max-width: 900px) {
        .top-left,
        .top-right {
            flex-basis: 130px;
        }

        .nav-item {
            width: 82px;
        }

        .profile-name {
            max-width: 75px;
        }
    }

    @media (max-width: 750px) {
        .topbar {
            min-height: 64px;
            height: auto;
            padding: 0 8px;
            gap: 4px;
        }

        .top-left,
        .top-right {
            flex: 0 0 auto;
        }

        .brand {
            display: none;
        }

        .back-btn {
            width: 38px;
            height: 38px;
            font-size: 16px;
        }

        .top-nav {
            flex: 1 1 auto;
            gap: 2px;
            justify-content: center;
            min-width: 0;
        }

        .nav-item {
            width: auto;
            flex: 1 1 0;
            max-width: 70px;
            height: 54px;
            border-radius: 9px;
        }

        .nav-item i {
            font-size: 18px;
            margin-bottom: 3px;
        }

        .nav-item span {
            font-size: 9px;
        }

        .nav-item.active::after {
            left: 15px;
            right: 15px;
            height: 2px;
        }

        .profile-name {
            display: none;
        }

        .profile-btn {
            padding: 4px;
        }

        .page {
            width: 100%;
            padding: 20px 10px 50px;
        }

        .page-heading {
            margin-bottom: 16px;
        }

        .page-heading h1 {
            font-size: 24px;
        }

        .page-heading p {
            font-size: 13px;
        }

        .professor-card {
            padding: 14px;
            border-radius: 13px;
        }

        .post-card {
            border-radius: 13px;
        }

        .post-header {
            padding: 14px 14px 9px;
        }

        .post-content {
            padding: 3px 14px 15px;
        }

        .comments {
            padding: 11px 14px 14px;
        }

        .post-title {
            font-size: 17px;
        }

        .post-details {
            font-size: 13px;
            line-height: 1.55;
        }
    }

    @media (max-width: 480px) {
        .topbar {
            min-height: 60px;
            padding: 0 7px;
        }

        .back-btn {
            width: 34px;
            height: 34px;
            font-size: 14px;
        }

        .top-nav {
            gap: 0;
        }

        .nav-item {
            max-width: 58px;
            height: 50px;
        }

        .nav-item i {
            font-size: 17px;
            margin-bottom: 2px;
        }

        .nav-item span {
            font-size: 8px;
        }

        .profile-circle {
            width: 34px;
            height: 34px;
            font-size: 14px;
        }

        .page {
            padding: 17px 8px 45px;
        }

        .page-heading h1 {
            font-size: 22px;
        }

        .page-heading p {
            font-size: 12px;
        }

        .search-form {
            flex-direction: column;
            gap: 7px;
        }

        .search-input {
            min-height: 42px;
        }

        .search-btn {
            width: 100%;
            height: 42px;
            padding: 0;
        }

        .professor-card {
            padding: 13px;
        }

        .professor-avatar {
            width: 38px;
            height: 38px;
        }

        .professor-name {
            font-size: 13px;
        }

        .professor-sub {
            font-size: 11px;
        }

        .post-header {
            padding: 12px 12px 8px;
        }

        .post-content {
            padding: 3px 12px 14px;
        }

        .post-title {
            font-size: 16px;
            line-height: 1.4;
        }

        .post-details {
            font-size: 13px;
        }

        .post-footer {
            padding: 9px 12px;
        }

        .comments {
            padding: 10px 12px 13px;
        }

        .comment {
            gap: 7px;
        }

        .comment-avatar {
            width: 28px;
            height: 28px;
        }

        .comment-body {
            padding: 7px 9px;
        }

        .comment-text {
            font-size: 12px;
        }

        .comment-form {
            gap: 6px;
        }

        .comment-input {
            font-size: 12px;
            padding: 9px 11px;
        }

        .comment-btn {
            width: 36px;
            height: 36px;
        }

        .empty-card {
            padding: 38px 15px;
        }
    }

    @media (max-width: 350px) {
        .topbar {
            padding: 0 5px;
        }

        .back-btn {
            width: 32px;
            height: 32px;
        }

        .nav-item {
            max-width: 52px;
        }

        .nav-item span {
            font-size: 7px;
        }

        .profile-circle {
            width: 32px;
            height: 32px;
        }

        .page {
            padding-left: 6px;
            padding-right: 6px;
        }
    }

    /* ACCESSIBILITY */

    button,
    input {
        -webkit-tap-highlight-color: transparent;
    }

    button:focus-visible,
    a:focus-visible,
    input:focus-visible {
        outline: 2px solid var(--green);
        outline-offset: 2px;
    }
    </style>
</head>

<body>

<!-- =====================================================
     TOP NAVIGATION
====================================================== -->

<header class="topbar">

    <div class="top-left">
        <button
            type="button"
            class="back-btn"
            onclick="window.location.href='professor_dashboard.php';"
            title="Back to Dashboard"
            aria-label="Back to Dashboard"
        >
            <i class="fa-solid fa-arrow-left"></i>
        </button>

        <div class="brand">NoteSphere</div>
    </div>

    <nav class="top-nav" aria-label="Professor navigation">

        <a
            href="help_board.php"
            class="nav-item active"
            title="Help Board"
            aria-label="Help Board"
            aria-current="page"
        >
            <i class="fa-solid fa-house"></i>
            <span>Home</span>
        </a>

        <a
            href="help_history.php"
            class="nav-item"
            title="History"
            aria-label="History"
        >
            <i class="fa-solid fa-clock-rotate-left"></i>
            <span>History</span>
        </a>

        <a
            href="professor_messages.php"
            class="nav-item ns-nav-link"
            title="Messages"
            aria-label="Open messages"
        >
            <i class="fa-solid fa-envelope"></i>
            <?php echo ns_notification_badge_html((int)($ns_badge_counts['messages'] ?? 0), 'messages'); ?>
            <span>Messages</span>
        </a>

        <a
            href="help_notifications.php"
            class="nav-item ns-nav-link"
            title="Notifications"
            aria-label="Notifications"
        >
            <i class="fa-solid fa-bell"></i>
            <?php echo ns_notification_badge_html((int)($ns_badge_counts['help'] ?? 0), 'help'); ?>
            <span>Notifications</span>
        </a>

    </nav>

    <div class="top-right">
        <a
            href="profile.php"
            class="profile-btn"
            title="Professor Profile"
        >
            <div class="profile-circle">
                <?php echo h(help_board_initial($professor_name)); ?>
            </div>

            <span class="profile-name">
                <?php echo h($professor_name); ?>
            </span>
        </a>
    </div>

</header>

<!-- =====================================================
     MAIN CONTENT
====================================================== -->

<main class="page">

    <div class="page-heading">
        <h1>Help Board</h1>
        <p>
            View student questions and provide helpful answers and guidance.
        </p>
    </div>

    <!-- PROFESSOR INFORMATION -->

    <div class="professor-card">
        <div class="professor-avatar">
            <?php echo h(help_board_initial($professor_name)); ?>
        </div>

        <div>
            <div class="professor-name">
                <?php echo h($professor_name); ?>
            </div>

            <div class="professor-sub">
                Professor · You can reply to student questions.
            </div>
        </div>
    </div>

    <!-- SEARCH -->

    <div class="search-box">
        <form method="GET" action="help_board.php" class="search-form">

            <input
                type="search"
                name="search"
                class="search-input"
                placeholder="Search student questions..."
                value="<?php echo h($search); ?>"
                maxlength="200"
                aria-label="Search student questions"
            >

            <button type="submit" class="search-btn">
                <i class="fa-solid fa-magnifying-glass"></i>
                &nbsp; Search
            </button>

        </form>
    </div>

    <!-- FEED TITLE -->

    <div class="feed-title">
        <?php echo $search !== '' ? 'Search Results' : 'Student Questions'; ?>
    </div>

    <?php if ($questions_error): ?>

        <div class="empty-card">
            <div class="empty-icon">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>

            <h3>Unable to load student questions</h3>

            <p>
                Please try refreshing the page. If the problem continues,
                check the database connection and Help Board table structure.
            </p>
        </div>

    <?php elseif (!empty($questions)): ?>

        <?php foreach ($questions as $question): ?>

            <?php
            $question_id = (int) $question['id'];

            $is_anonymous = !empty($question['is_anonymous']);

            $display_name = $is_anonymous
                ? 'Anonymous Student'
                : (
                    trim((string) ($question['full_name'] ?? '')) !== ''
                        ? $question['full_name']
                        : 'Student'
                );

            $post_comments = $comments_by_question[$question_id] ?? [];

            $created_timestamp = !empty($question['created_at'])
                ? strtotime($question['created_at'])
                : false;
            ?>

            <article class="post-card" id="post-<?php echo (int) $question_id; ?>">

                <!-- POST HEADER -->

                <div class="post-header">

                    <div class="post-user">

                        <div class="avatar">
                            <?php echo h(help_board_initial($display_name)); ?>
                        </div>

                        <div>

                            <div class="post-user-name">
                                <?php echo h($display_name); ?>
                            </div>

                            <div class="post-time">
                                <?php
                                echo $created_timestamp
                                    ? h(date('M d, Y · h:i A', $created_timestamp))
                                    : 'Date unavailable';
                                ?>
                            </div>

                        </div>

                    </div>

                    <!-- POST MENU -->

                    <div class="post-menu">

                        <button
                            type="button"
                            class="menu-btn"
                            onclick="toggleMenu(<?php echo $question_id; ?>)"
                            title="Post options"
                            aria-label="Post options"
                            aria-expanded="false"
                            aria-controls="menu-<?php echo $question_id; ?>"
                        >
                            <i class="fa-solid fa-ellipsis"></i>
                        </button>

                        <div
                            class="menu-form"
                            id="menu-<?php echo $question_id; ?>"
                        >

                            <form
                                method="POST"
                                action="help_board.php<?php echo $search !== '' ? '?search=' . rawurlencode($search) : ''; ?>"
                                onsubmit="return confirmHidePost(event, this);"
                            >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?php echo h($csrf_token); ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="hide_post"
                                >

                                <input
                                    type="hidden"
                                    name="question_id"
                                    value="<?php echo $question_id; ?>"
                                >

                                <button type="submit" class="hide-btn">
                                    <i class="fa-regular fa-eye-slash"></i>
                                    &nbsp; Hide post
                                </button>

                            </form>

                        </div>

                    </div>

                </div>

                <!-- POST CONTENT -->

                <div class="post-content">

                    <div class="post-title">
                        <?php echo h($question['title'] ?? 'Untitled question'); ?>
                    </div>

                    <div class="post-details"><?php
                        echo h($question['details'] ?? '');
                    ?></div>

                </div>

                <!-- POST FOOTER -->

                <div class="post-footer">
                    <i class="fa-regular fa-comment"></i>
                    &nbsp;
                    <?php echo count($post_comments); ?>
                    <?php echo count($post_comments) === 1 ? 'comment' : 'comments'; ?>
                </div>

                <!-- COMMENTS -->

                <div class="comments">

                    <?php if ($comments_error): ?>

                        <p style="color:var(--muted);font-size:12px;margin-bottom:10px;">
                            Comments could not be loaded. Please refresh the page.
                        </p>

                    <?php elseif (!empty($post_comments)): ?>

                        <?php foreach ($post_comments as $comment): ?>

                            <?php
                            $comment_user_id = (int) ($comment['user_id'] ?? 0);

                            $comment_name = trim(
                                (string) ($comment['full_name'] ?? '')
                            );

                            if ($comment_name === '') {
                                $comment_name = 'User';
                            }

                            $comment_timestamp = !empty($comment['created_at'])
                                ? strtotime($comment['created_at'])
                                : false;

                            $comment_role = strtolower(
                                (string) ($comment['role'] ?? '')
                            );

                            $is_professor_comment = $comment_role === 'professor';
                            ?>

                            <div class="comment" id="comment-<?php echo (int) $comment['id']; ?>">

                                <div class="comment-avatar">
                                    <?php echo h(help_board_initial($comment_name)); ?>
                                </div>

                                <div class="comment-body">

                                    <div class="comment-name">

                                        <?php echo h($comment_name); ?>

                                        <?php if ($comment_user_id === $user_id): ?>
                                            <span style="color:var(--green);font-size:10px;margin-left:5px;">
                                                You
                                            </span>
                                        <?php endif; ?>

                                        <?php if ($is_professor_comment): ?>
                                            <span style="color:var(--green);font-size:10px;margin-left:5px;">
                                                Professor
                                            </span>
                                        <?php endif; ?>

                                    </div>

                                    <div class="comment-text"><?php
                                        echo h($comment['comment'] ?? '');
                                    ?></div>

                                    <div class="comment-time">
                                        <?php
                                        echo $comment_timestamp
                                            ? h(date('M d, Y · h:i A', $comment_timestamp))
                                            : 'Date unavailable';
                                        ?>
                                    </div>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    <!-- PROFESSOR REPLY FORM -->

                    <form
                        method="POST"
                        action="help_board.php<?php echo $search !== '' ? '?search=' . rawurlencode($search) : ''; ?>"
                        class="comment-form"
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?php echo h($csrf_token); ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="add_comment"
                        >

                        <input
                            type="hidden"
                            name="question_id"
                            value="<?php echo $question_id; ?>"
                        >

                        <input
                            type="text"
                            name="comment"
                            class="comment-input"
                            placeholder="Write a reply to this student..."
                            maxlength="2000"
                            autocomplete="off"
                            aria-label="Write a reply"
                            required
                        >

                        <button
                            type="submit"
                            class="comment-btn"
                            title="Reply to student"
                            aria-label="Send reply"
                        >
                            <i class="fa-solid fa-paper-plane"></i>
                        </button>

                    </form>

                </div>

            </article>

        <?php endforeach; ?>

    <?php else: ?>

        <!-- EMPTY STATE -->

        <div class="empty-card">

            <div class="empty-icon">
                <i class="fa-regular fa-comments"></i>
            </div>

            <h3>
                <?php
                echo $search !== ''
                    ? 'No matching student questions'
                    : 'No student questions found';
                ?>
            </h3>

            <p>
                <?php
                echo $search !== ''
                    ? 'Try another search keyword.'
                    : 'Student questions will appear here when they are posted.';
                ?>
            </p>

        </div>

    <?php endif; ?>

</main>

<script>
// =========================================================
// POST MENU
// =========================================================

function toggleMenu(id) {
    const menu = document.getElementById('menu-' + id);

    if (!menu) {
        return;
    }

    const isCurrentlyOpen = menu.classList.contains('show');

    document.querySelectorAll('.menu-form').forEach(function (item) {
        item.classList.remove('show');

        const parent = item.closest('.post-menu');
        const button = parent ? parent.querySelector('.menu-btn') : null;

        if (button) {
            button.setAttribute('aria-expanded', 'false');
        }
    });

    if (!isCurrentlyOpen) {
        menu.classList.add('show');

        const parent = menu.closest('.post-menu');
        const button = parent ? parent.querySelector('.menu-btn') : null;

        if (button) {
            button.setAttribute('aria-expanded', 'true');
        }
    }
}

// =========================================================
// CLOSE MENU WHEN CLICKING OUTSIDE
// =========================================================

document.addEventListener('click', function (event) {
    if (!event.target.closest('.post-menu')) {
        document.querySelectorAll('.menu-form').forEach(function (menu) {
            menu.classList.remove('show');

            const parent = menu.closest('.post-menu');
            const button = parent ? parent.querySelector('.menu-btn') : null;

            if (button) {
                button.setAttribute('aria-expanded', 'false');
            }
        });
    }
});

// =========================================================
// HIDE POST CONFIRMATION - SWEETALERT2
// =========================================================

function confirmHidePost(event, form) {
    event.preventDefault();

    const currentTheme =
        document.documentElement.getAttribute('data-theme') || 'dark';

    const isLightTheme = currentTheme === 'light';

    Swal.fire({
        title: 'Hide this post?',
        text: 'This student question will be hidden from your Help Board only. It will not be deleted.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, hide it',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        background: isLightTheme ? '#ffffff' : '#111111',
        color: isLightTheme ? '#17211b' : '#ffffff',
        confirmButtonColor: isLightTheme ? '#24a812' : '#39ff14',
        cancelButtonColor: isLightTheme ? '#6b7280' : '#444444'
    }).then(function (result) {
        if (result.isConfirmed) {
            form.submit();
        }
    });

    return false;
}

// =========================================================
// FLASH MESSAGES - SWEETALERT2
// =========================================================

<?php if ($flash_message !== ''): ?>

(function () {
    const currentTheme =
        document.documentElement.getAttribute('data-theme') || 'dark';

    const isLightTheme = currentTheme === 'light';

    const flashIcon = <?php
        echo json_encode(
            $flash_type === 'error' ? 'error' : 'success',
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        );
    ?>;

    const flashTitle = <?php
        echo json_encode(
            $flash_type === 'error' ? 'Something went wrong' : 'Success',
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        );
    ?>;

    const flashText = <?php
        echo json_encode(
            $flash_message,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        );
    ?>;

    Swal.fire({
        icon: flashIcon,
        title: flashTitle,
        text: flashText,
        background: isLightTheme ? '#ffffff' : '#111111',
        color: isLightTheme ? '#17211b' : '#ffffff',
        confirmButtonColor: isLightTheme ? '#24a812' : '#39ff14',
        confirmButtonText: 'OK'
    });
})();

<?php endif; ?>

// Open a notified post/comment and clear its unread badge.
(function () {
    const hash = window.location.hash || '';
    const match = hash.match(/^#(comment|post)-(\d+)$/);
    if (!match) return;

    const target = document.getElementById(hash.slice(1));
    if (target) target.scrollIntoView({behavior: 'smooth', block: 'center'});

    if (window.NSMarkCommentRead) {
        window.NSMarkCommentRead(
            match[1] === 'post' ? Number(match[2]) : 0,
            match[1] === 'comment' ? Number(match[2]) : 0
        );
    }
})();
</script>
</body>
</html>