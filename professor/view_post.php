<?php

// =========================================================
// NoteSphere - Professor View Post
// =========================================================

session_start();

require_once __DIR__ . '/../includes/db_config.php';

/** @var PDO $conn */

// =========================================================
// PROFESSOR SECURITY CHECK
// =========================================================

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'professor'
) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];


// =========================================================
// POST ID
// =========================================================

$post_id = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;

if ($post_id <= 0) {
    header("Location: help_board.php");
    exit();
}


// =========================================================
// CSRF TOKEN
// =========================================================

if (empty($_SESSION['help_board_csrf'])) {
    $_SESSION['help_board_csrf'] =
        bin2hex(random_bytes(32));
}

$csrf_token =
    $_SESSION['help_board_csrf'];


// =========================================================
// HELPER
// =========================================================

function e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


// =========================================================
// CURRENT PROFESSOR
// =========================================================

$user_stmt = $conn->prepare("
    SELECT
        full_name,
        email,
        profile_pic,
        role
    FROM users
    WHERE user_id = ?
    LIMIT 1
");

$user_stmt->execute([
    $user_id
]);

$current_user =
    $user_stmt->fetch(PDO::FETCH_ASSOC);


if (!$current_user) {

    session_destroy();

    header("Location: ../auth/login.php");
    exit();
}


$professor_name =
    $current_user['full_name']
    ?? 'Professor';

$professor_email =
    $current_user['email']
    ?? '';


// =========================================================
// PROFILE IMAGE HELPER
// =========================================================

function getProfileImage($profile_pic, $email = 'user')
{
    if (!empty($profile_pic)) {

        if (
            filter_var(
                $profile_pic,
                FILTER_VALIDATE_URL
            ) ||
            str_starts_with(
                $profile_pic,
                'data:image'
            )
        ) {
            return $profile_pic;
        }

        if (
            str_starts_with(
                $profile_pic,
                '/'
            )
        ) {
            return $profile_pic;
        }

        return '../' .
            ltrim(
                $profile_pic,
                './'
            );
    }

    return 'https://api.dicebear.com/7.x/avataaars/svg?seed=' .
        urlencode($email ?: 'user');
}


$profile_pic = getProfileImage(
    $current_user['profile_pic'] ?? '',
    $professor_email
);


// =========================================================
// FLASH MESSAGE
// =========================================================

$flash_message =
    $_SESSION['help_board_message']
    ?? '';

$flash_type =
    $_SESSION['help_board_message_type']
    ?? 'success';

unset(
    $_SESSION['help_board_message']
);

unset(
    $_SESSION['help_board_message_type']
);


// =========================================================
// MARK NOTIFICATION AS READ
// =========================================================

if (isset($_GET['mark_read'])) {

    $notification_id =
        (int) $_GET['mark_read'];

    if ($notification_id > 0) {

        try {

            /*
             * Current NoteSphere notification table
             * uses user_id and notification id.
             */

            $mark_stmt = $conn->prepare("
                UPDATE notifications
                SET is_read = 1
                WHERE notification_id = ?
                AND user_id = ?
            ");

            $mark_stmt->execute([
                $notification_id,
                $user_id
            ]);

        } catch (PDOException $e) {

            // Do not stop the page if marking fails.
        }
    }
}


// =========================================================
// ADD COMMENT
// =========================================================

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['submit_comment'])
) {

    $posted_token =
        $_POST['csrf_token']
        ?? '';

    if (
        empty($posted_token) ||
        !hash_equals(
            $csrf_token,
            $posted_token
        )
    ) {

        $_SESSION['help_board_message'] =
            'Invalid request. Please try again.';

        $_SESSION['help_board_message_type'] =
            'error';

        header(
            "Location: view_post.php?id=" .
            $post_id
        );

        exit();
    }


    $comment_text =
        trim(
            $_POST['comment_text']
            ?? ''
        );


    if ($comment_text === '') {

        $_SESSION['help_board_message'] =
            'Please enter a comment.';

        $_SESSION['help_board_message_type'] =
            'error';

        header(
            "Location: view_post.php?id=" .
            $post_id
        );

        exit();
    }


    if (mb_strlen($comment_text) > 2000) {

        $_SESSION['help_board_message'] =
            'Your comment is too long.';

        $_SESSION['help_board_message_type'] =
            'error';

        header(
            "Location: view_post.php?id=" .
            $post_id
        );

        exit();
    }


    try {

        // =====================================================
        // CHECK POST
        // =====================================================

        $q_stmt = $conn->prepare("
            SELECT
                id,
                student_id,
                title
            FROM help_questions
            WHERE id = ?
            LIMIT 1
        ");

        $q_stmt->execute([
            $post_id
        ]);

        $question =
            $q_stmt->fetch(PDO::FETCH_ASSOC);


        if (!$question) {

            $_SESSION['help_board_message'] =
                'The post could not be found.';

            $_SESSION['help_board_message_type'] =
                'error';

            header(
                "Location: help_board.php"
            );

            exit();
        }


        // =====================================================
        // INSERT COMMENT
        // =====================================================

        /*
         * IMPORTANT:
         *
         * help_comments uses:
         * question_id
         * user_id
         * comment
         * created_at
         */

        $comment_stmt = $conn->prepare("
            INSERT INTO help_comments
            (
                question_id,
                user_id,
                comment
            )
            VALUES (?, ?, ?)
        ");

        $comment_stmt->execute([
            $post_id,
            $user_id,
            $comment_text
        ]);


        $comment_id =
            (int) $conn->lastInsertId();


        // =====================================================
        // NOTIFY POST OWNER
        // =====================================================

        $owner_id =
            (int) $question['student_id'];


        /*
         * Do not notify yourself.
         */

        if (
            $owner_id > 0 &&
            $owner_id !== $user_id
        ) {

            try {

                $notif_stmt = $conn->prepare("
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

                $notif_stmt->execute([
                    $owner_id,
                    'help_comment',
                    'New comment on your post',
                    $professor_name .
                    ' commented on your post: "' .
                    $question['title'] .
                    '"',
                    $post_id,
                    $comment_id
                ]);

            } catch (PDOException $e) {

                /*
                 * The comment was already saved.
                 * Notification failure should not
                 * remove the comment.
                 */
            }
        }


        $_SESSION['help_board_message'] =
            'Comment added successfully.';

        $_SESSION['help_board_message_type'] =
            'success';


    } catch (PDOException $e) {

        $_SESSION['help_board_message'] =
            'Unable to add your comment right now.';

        $_SESSION['help_board_message_type'] =
            'error';
    }


    header(
        "Location: view_post.php?id=" .
        $post_id
    );

    exit();
}


// =========================================================
// GET MAIN POST
// =========================================================

try {

    $post_stmt = $conn->prepare("
        SELECT
            q.id,
            q.student_id,
            q.title,
            q.details,
            q.is_anonymous,
            q.created_at,
            u.full_name,
            u.role,
            u.profile_pic,
            u.email

        FROM help_questions q

        JOIN users u
            ON q.student_id = u.user_id

        WHERE q.id = ?

        LIMIT 1
    ");

    $post_stmt->execute([
        $post_id
    ]);

    $post =
        $post_stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    $post = false;
}


if (!$post) {

    header(
        "Location: help_board.php"
    );

    exit();
}


// =========================================================
// GET COMMENTS
// =========================================================

try {

    $comm_stmt = $conn->prepare("
        SELECT
            c.id,
            c.question_id,
            c.user_id,
            c.comment,
            c.created_at,
            u.full_name,
            u.role,
            u.profile_pic,
            u.email

        FROM help_comments c

        JOIN users u
            ON c.user_id = u.user_id

        WHERE c.question_id = ?

        ORDER BY c.created_at ASC
    ");

    $comm_stmt->execute([
        $post_id
    ]);

    $comments =
        $comm_stmt->fetchAll(
            PDO::FETCH_ASSOC
        );

} catch (PDOException $e) {

    $comments = [];
}


// =========================================================
// POST AUTHOR
// =========================================================

$is_anonymous =
    (int) ($post['is_anonymous'] ?? 0) === 1;


if ($is_anonymous) {

    $post_author_name =
        'Anonymous Student';

    $post_author_email =
        'anonymous';

    $post_author_image =
        'https://api.dicebear.com/7.x/avataaars/svg?seed=anonymous';

} else {

    $post_author_name =
        $post['full_name']
        ?? 'User';

    $post_author_email =
        $post['email']
        ?? 'user';

    $post_author_image =
        getProfileImage(
            $post['profile_pic'] ?? '',
            $post_author_email
        );
}


// =========================================================
// ROLE LABEL
// =========================================================

$post_role =
    strtolower(
        $post['role'] ?? 'student'
    );

$post_role_label =
    $post_role === 'professor'
        ? 'Professor'
        : 'Student';


// =========================================================
// INITIALS
// =========================================================

$professor_initial =
    strtoupper(
        substr(
            $professor_name,
            0,
            1
        )
    );


// =========================================================
// COMMENT COUNT
// =========================================================

$comment_count =
    count($comments);

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        View Post - NoteSphere
    </title>


    <!-- =====================================================
         FONT
    ====================================================== -->

    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >


    <!-- =====================================================
         FONT AWESOME
    ====================================================== -->

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >


    <!-- =====================================================
         SWEETALERT
    ====================================================== -->

    <script
        src="https://cdn.jsdelivr.net/npm/sweetalert2@11">
    </script>


    <style>

        /* =====================================================
           ROOT
        ====================================================== */

        :root {

            --primary: #233961;

            --primary-light: #3D8BFF;

            --background: #f5f7fb;

            --card: #ffffff;

            --text: #1f2937;

            --muted: #6b7280;

            --border: #e5e7eb;

            --input: #f8fafc;

            --success: #16a34a;

            --danger: #dc2626;

            --shadow:
                0 8px 25px
                rgba(
                    35,
                    57,
                    97,
                    0.08
                );
        }


        /* =====================================================
           DARK MODE
        ====================================================== */

        html.dark-mode {

            --background: #111827;

            --card: #1f2937;

            --text: #f9fafb;

            --muted: #9ca3af;

            --border: #374151;

            --input: #111827;

            --shadow:
                0 8px 25px
                rgba(
                    0,
                    0,
                    0,
                    0.25
                );
        }


        /* =====================================================
           RESET
        ====================================================== */

        * {

            margin: 0;

            padding: 0;

            box-sizing: border-box;

            font-family:
                'Plus Jakarta Sans',
                Arial,
                Helvetica,
                sans-serif;
        }


        html,
        body {

            min-height: 100%;
        }


        body {

            background:
                var(--background);

            color:
                var(--text);

            min-height: 100vh;

            padding-bottom: 40px;

            transition:
                background 0.3s ease,
                color 0.3s ease;
        }


        /* =====================================================
           TOPBAR
        ====================================================== */

        .fb-header {

            height: 72px;

            background:
                var(--card);

            border-bottom:
                1px solid var(--border);

            display: flex;

            align-items: center;

            position: sticky;

            top: 0;

            z-index: 100;

            padding:
                0 28px;

            box-shadow:
                0 2px 12px
                rgba(
                    0,
                    0,
                    0,
                    0.04
                );
        }


        /* =====================================================
           HEADER LEFT
        ====================================================== */

        .header-left {

            min-width: 230px;

            display: flex;

            align-items: center;

            gap: 14px;
        }


        .back-btn {

            width: 40px;

            height: 40px;

            border-radius: 50%;

            border:
                1px solid var(--border);

            background:
                transparent;

            color:
                var(--text);

            display: flex;

            align-items: center;

            justify-content: center;

            text-decoration: none;

            font-size: 25px;

            line-height: 1;

            transition:
                background 0.2s ease,
                color 0.2s ease;
        }


        .back-btn:hover {

            background:
                var(--primary);

            color:
                #ffffff;

            border-color:
                var(--primary);
        }


        .header-left h2 {

            color:
                var(--primary);

            font-size: 21px;

            font-weight: 800;
        }


        html.dark-mode
        .header-left h2 {

            color:
                #ffffff;
        }


        .header-left h2 span {

            color:
                var(--primary-light);
        }


        /* =====================================================
           NAV
        ====================================================== */

        .header-right-group {

            flex: 1;

            display: flex;

            align-items: center;

            justify-content: center;

            gap: 8px;
        }


        .nav-icon-btn {

            width: 42px;

            height: 42px;

            border-radius: 50%;

            background:
                transparent;

            border: none;

            display: flex;

            align-items: center;

            justify-content: center;

            cursor: pointer;

            color:
                var(--muted);

            text-decoration: none;

            font-size: 17px;

            transition:
                background 0.2s ease,
                color 0.2s ease,
                transform 0.2s ease;
        }


        .nav-icon-btn:hover {

            background:
                rgba(
                    61,
                    139,
                    255,
                    0.12
                );

            color:
                var(--primary-light);

            transform:
                translateY(-1px);
        }


        .nav-icon-btn.active {

            background:
                rgba(
                    61,
                    139,
                    255,
                    0.15
                );

            color:
                var(--primary-light);
        }


        /* =====================================================
           PROFILE AREA
        ====================================================== */

        .profile-area {

            min-width: 230px;

            display: flex;

            align-items: center;

            justify-content: flex-end;

            gap: 10px;
        }


        .profile-info {

            text-align: right;
        }


        .profile-name {

            font-size: 13px;

            font-weight: 700;

            color:
                var(--text);
        }


        .profile-role {

            font-size: 10px;

            font-weight: 700;

            color:
                var(--primary-light);
        }


        .profile-link {

            display: flex;

            text-decoration: none;
        }


        .nav-profile-pic {

            width: 42px;

            height: 42px;

            border-radius: 50%;

            object-fit: cover;

            border:
                2px solid
                var(--primary-light);

            background:
                linear-gradient(
                    135deg,
                    var(--primary),
                    var(--primary-light)
                );
        }


        /* =====================================================
           MAIN CONTAINER
        ====================================================== */

        .fb-container {

            width: 100%;

            max-width: 760px;

            margin:
                25px auto;

            padding:
                0 15px;
        }


        /* =====================================================
           POST CARD
        ====================================================== */

        .post-card,
        .comment-card,
        .comment-box-card {

            background:
                var(--card);

            border:
                1px solid var(--border);

            border-radius: 15px;

            padding: 18px;

            margin-bottom: 16px;

            box-shadow:
                var(--shadow);

            transition:
                background 0.3s ease,
                border-color 0.3s ease;
        }


        /* =====================================================
           POST HEADER
        ====================================================== */

        .post-header,
        .comment-header {

            display: flex;

            align-items: center;

            gap: 11px;

            margin-bottom: 15px;
        }


        .avatar {

            width: 43px;

            height: 43px;

            border-radius: 50%;

            object-fit: cover;

            border:
                2px solid var(--border);

            flex-shrink: 0;

            background:
                linear-gradient(
                    135deg,
                    var(--primary),
                    var(--primary-light)
                );
        }


        .author-info {

            min-width: 0;

            flex: 1;
        }


        .author-info h4 {

            font-size: 14px;

            font-weight: 700;

            color:
                var(--text);

            margin-bottom: 3px;
        }


        .author-info span {

            font-size: 11px;

            color:
                var(--muted);
        }


        .role-badge {

            display: inline-block;

            font-size: 9px;

            font-weight: 700;

            color:
                var(--primary-light);

            background:
                rgba(
                    61,
                    139,
                    255,
                    0.10
                );

            padding:
                3px 7px;

            border-radius: 5px;

            margin-left: 5px;

            vertical-align: middle;
        }


        /* =====================================================
           POST CONTENT
        ====================================================== */

        .post-content h3 {

            font-size: 18px;

            line-height: 1.4;

            color:
                var(--text);

            margin-bottom: 9px;
        }


        .post-content p {

            font-size: 14px;

            line-height: 1.65;

            color:
                var(--text);

            word-break: break-word;

            white-space: normal;
        }


        /* =====================================================
           COMMENTS TITLE
        ====================================================== */

        .comments-section-title {

            font-size: 16px;

            font-weight: 700;

            margin:
                22px 2px 12px;

            color:
                var(--text);
        }


        /* =====================================================
           COMMENT CARD
        ====================================================== */

        .comment-card {

            margin-bottom: 10px;

            padding: 14px;
        }


        .comment-header {

            margin-bottom: 9px;
        }


        .comment-body {

            padding-left: 54px;
        }


        .comment-body p {

            font-size: 13px;

            line-height: 1.55;

            color:
                var(--text);

            word-break: break-word;

            white-space: pre-wrap;
        }


        .comment-time {

            font-size: 10px;

            color:
                var(--muted);

            margin-top: 4px;
        }


        /* =====================================================
           COMMENT FORM
        ====================================================== */

        .comment-form textarea {

            width: 100%;

            padding: 13px;

            border-radius: 10px;

            border:
                1px solid var(--border);

            background:
                var(--input);

            color:
                var(--text);

            resize: vertical;

            min-height: 85px;

            font-size: 13px;

            outline: none;

            transition:
                border-color 0.2s ease,
                box-shadow 0.2s ease;
        }


        .comment-form textarea:focus {

            border-color:
                var(--primary-light);

            box-shadow:
                0 0 0 3px
                rgba(
                    61,
                    139,
                    255,
                    0.10
                );
        }


        .comment-actions {

            display: flex;

            align-items: center;

            justify-content: flex-end;

            margin-top: 10px;
        }


        .submit-btn {

            background:
                var(--primary);

            color:
                #ffffff;

            border: none;

            padding:
                10px 19px;

            border-radius: 9px;

            font-weight: 700;

            cursor: pointer;

            font-size: 13px;

            display: flex;

            align-items: center;

            gap: 7px;

            transition:
                background 0.2s ease,
                transform 0.2s ease;
        }


        .submit-btn:hover {

            background:
                var(--primary-light);

            transform:
                translateY(-1px);
        }


        /* =====================================================
           EMPTY COMMENTS
        ====================================================== */

        .no-comments {

            text-align: center;

            padding:
                25px 10px;

            color:
                var(--muted);

            font-size: 13px;
        }


        .no-comments i {

            display: block;

            font-size: 27px;

            margin-bottom: 8px;

            opacity: 0.7;
        }


        /* =====================================================
           HIGHLIGHT TARGET COMMENT
        ====================================================== */

        .comment-card:target {

            background:
                rgba(
                    61,
                    139,
                    255,
                    0.08
                );

            border:
                2px solid
                var(--primary-light);

            box-shadow:
                0 0 15px
                rgba(
                    61,
                    139,
                    255,
                    0.20
                );
        }


        /* =====================================================
           RESPONSIVE
        ====================================================== */

        @media (max-width: 850px) {

            .fb-header {

                padding:
                    0 15px;
            }


            .header-left {

                min-width:
                    auto;
            }


            .profile-area {

                min-width:
                    auto;
            }


            .profile-info {

                display:
                    none;
            }
        }


        @media (max-width: 650px) {

            .header-left h2 {

                display:
                    none;
            }


            .header-left {

                min-width:
                    auto;
            }


            .header-right-group {

                gap:
                    2px;
            }


            .nav-icon-btn {

                width:
                    38px;

                height:
                    38px;

                font-size:
                    16px;
            }


            .fb-container {

                margin-top:
                    18px;

                padding:
                    0 10px;
            }


            .post-card,
            .comment-card,
            .comment-box-card {

                border-radius:
                    13px;

                padding:
                    15px;
            }


            .comment-body {

                padding-left:
                    0;
            }


            .comment-actions {

                justify-content:
                    stretch;
            }


            .submit-btn {

                width:
                    100%;

                justify-content:
                    center;
            }
        }

    </style>


    <!-- =====================================================
         APPLY THEME BEFORE PAGE LOAD
    ====================================================== -->

    <script>

        (function () {

            const savedTheme =
                localStorage.getItem(
                    'notesphere_theme'
                ) ||
                localStorage.getItem(
                    'theme'
                ) ||
                'dark';


            if (
                savedTheme === 'light'
            ) {

                document.documentElement
                    .classList
                    .remove('dark-mode');

            } else {

                document.documentElement
                    .classList
                    .add('dark-mode');
            }

        })();

    </script>

</head>


<body>


<!-- =========================================================
     HEADER
========================================================== -->

<header class="fb-header">


    <!-- LEFT -->

    <div class="header-left">


        <a
            href="help_board.php"
            class="back-btn"
            title="Back to Help Board"
        >

            ×

        </a>


        <h2>

            Note<span>Sphere</span>

        </h2>

    </div>


    <!-- NAV -->

    <div class="header-right-group">


        <!-- HOME / HELP BOARD -->

        <a
            href="help_board.php"
            class="nav-icon-btn active"
            title="Help Board"
        >

            <i class="fa-solid fa-house"></i>

        </a>


        <!-- HISTORY -->

        <a
            href="help_history.php"
            class="nav-icon-btn"
            title="History"
        >

            <i class="fa-solid fa-clock-rotate-left"></i>

        </a>


        <!-- NOTIFICATIONS -->

        <a
            href="help_notifications.php"
            class="nav-icon-btn"
            title="Notifications"
        >

            <i class="fa-solid fa-bell"></i>

        </a>


        <!-- PROFILE -->

        <a
            href="profile.php"
            class="profile-link"
            title="Profile"
        >

            <img
                src="<?= e($profile_pic) ?>"
                alt="Profile"
                class="nav-profile-pic"
            >

        </a>


        <!-- BACK TO PROFESSOR DASHBOARD -->

        <a
            href="professor_dashboard.php"
            class="nav-icon-btn"
            title="Professor Dashboard"
        >

            <i class="fa-solid fa-xmark"></i>

        </a>

    </div>

</header>


<!-- =========================================================
     MAIN
========================================================== -->

<div class="fb-container">


    <!-- =====================================================
         MAIN POST
    ====================================================== -->

    <div class="post-card">


        <div class="post-header">


            <img
                src="<?= e($post_author_image) ?>"
                class="avatar"
                alt="Avatar"
            >


            <div class="author-info">


                <h4>

                    <?= e(
                        $post_author_name
                    ) ?>


                    <?php if (!$is_anonymous): ?>

                        <span class="role-badge">

                            <?= e(
                                $post_role_label
                            ) ?>

                        </span>

                    <?php endif; ?>

                </h4>


                <span>

                    <?= e(
                        date(
                            'M d, Y • h:i A',
                            strtotime(
                                $post['created_at']
                            )
                        )
                    ) ?>

                </span>

            </div>

        </div>


        <!-- POST CONTENT -->

        <div class="post-content">


            <?php if (
                !empty(
                    $post['title']
                )
            ): ?>

                <h3>

                    <?= e(
                        $post['title']
                    ) ?>

                </h3>

            <?php endif; ?>


            <p>

                <?= nl2br(
                    e(
                        $post['details']
                        ?? ''
                    )
                ) ?>

            </p>

        </div>

    </div>


    <!-- =====================================================
         COMMENTS TITLE
    ====================================================== -->

    <div class="comments-section-title">

        <i class="fa-regular fa-comments"></i>

        Comments
        (<?= $comment_count ?>)

    </div>


    <!-- =====================================================
         COMMENTS LIST
    ====================================================== -->

    <div class="comments-list">


        <?php if (!empty($comments)): ?>


            <?php foreach (
                $comments
                as $comment
            ): ?>


                <?php

                $comment_name =
                    $comment['full_name']
                    ?? 'User';

                $comment_email =
                    $comment['email']
                    ?? 'user';

                $comment_image =
                    getProfileImage(
                        $comment['profile_pic']
                        ?? '',
                        $comment_email
                    );

                $comment_role =
                    strtolower(
                        $comment['role']
                        ?? 'student'
                    );

                $comment_role_label =
                    $comment_role === 'professor'
                        ? 'Professor'
                        : 'Student';

                ?>


                <div
                    class="comment-card"
                    id="comment-<?= (int) $comment['id'] ?>"
                >


                    <div class="comment-header">


                        <img
                            src="<?= e($comment_image) ?>"
                            class="avatar"
                            alt="Avatar"
                        >


                        <div class="author-info">


                            <h4>

                                <?= e(
                                    $comment_name
                                ) ?>


                                <span class="role-badge">

                                    <?= e(
                                        $comment_role_label
                                    ) ?>

                                </span>

                            </h4>


                            <span>

                                <?= e(
                                    date(
                                        'M d, Y • h:i A',
                                        strtotime(
                                            $comment['created_at']
                                        )
                                    )
                                ) ?>

                            </span>

                        </div>

                    </div>


                    <div class="comment-body">

                        <p>

                            <?= nl2br(
                                e(
                                    $comment['comment']
                                    ?? ''
                                )
                            ) ?>

                        </p>

                    </div>

                </div>


            <?php endforeach; ?>


        <?php else: ?>


            <div class="comment-card no-comments">

                <i
                    class="fa-regular fa-comment-dots"
                ></i>

                No comments yet.
                Be the first to reply!

            </div>


        <?php endif; ?>


    </div>


    <!-- =====================================================
         COMMENT FORM
    ====================================================== -->

    <div
        class="comment-box-card"
        style="margin-top: 15px;"
    >


        <form
            action="view_post.php?id=<?= $post_id ?>"
            method="POST"
            class="comment-form"
        >


            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($csrf_token) ?>"
            >


            <textarea
                name="comment_text"
                placeholder="Write a comment..."
                maxlength="2000"
                required
            ></textarea>


            <div class="comment-actions">

                <button
                    type="submit"
                    name="submit_comment"
                    class="submit-btn"
                >

                    <i
                        class="fa-solid fa-paper-plane"
                    ></i>

                    Post Comment

                </button>

            </div>

        </form>

    </div>


</div>


<!-- =========================================================
     JAVASCRIPT
========================================================== -->

<script>


    // =====================================================
    // FLASH MESSAGE
    // =====================================================

    <?php if (!empty($flash_message)): ?>

        Swal.fire({

            icon:
                <?= json_encode(
                    $flash_type === 'error'
                        ? 'error'
                        : 'success'
                ) ?>,

            title:
                <?= json_encode(
                    $flash_type === 'error'
                        ? 'Something went wrong'
                        : 'Success'
                ) ?>,

            text:
                <?= json_encode(
                    $flash_message
                ) ?>,

            confirmButtonColor:
                '#233961'

        });

    <?php endif; ?>


    // =====================================================
    // THEME
    // =====================================================

    function applyTheme(theme) {

        if (theme === 'light') {

            document.documentElement
                .classList
                .remove('dark-mode');

        } else {

            document.documentElement
                .classList
                .add('dark-mode');
        }
    }


    // =====================================================
    // PAGE LOAD
    // =====================================================

    document.addEventListener(
        'DOMContentLoaded',
        function () {

            const savedTheme =
                localStorage.getItem(
                    'notesphere_theme'
                ) ||
                localStorage.getItem(
                    'theme'
                ) ||
                'dark';


            applyTheme(
                savedTheme
            );


            // ---------------------------------------------
            // SCROLL TO COMMENT FROM NOTIFICATION
            // ---------------------------------------------

            if (
                window.location.hash
            ) {

                const targetElement =
                    document.querySelector(
                        window.location.hash
                    );


                if (targetElement) {

                    setTimeout(
                        function () {

                            targetElement.scrollIntoView({
                                behavior: 'smooth',
                                block: 'center'
                            });

                        },
                        250
                    );
                }
            }

        }
    );


    // =====================================================
    // LIVE THEME CHANGE
    // =====================================================

    window.addEventListener(
        'storage',
        function (event) {

            if (
                event.key ===
                    'notesphere_theme' ||
                event.key ===
                    'theme'
            ) {

                if (
                    event.newValue
                ) {

                    applyTheme(
                        event.newValue
                    );
                }
            }

        }
    );

</script>
</body>
</html>