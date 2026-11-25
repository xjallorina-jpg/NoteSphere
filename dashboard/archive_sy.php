
<?php

// archive_sy.php

session_start();
date_default_timezone_set('Asia/Manila');

// ===============================
// SECURITY CHECK
// ===============================
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../auth/login.php");
    exit();
}

// ===============================
// DATABASE CONNECTION
// ===============================
$host = 'localhost';
$dbname = '1notesphere_db';
$username = 'root';
$password = '';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}

$student_id = $_SESSION['user_id'];

// ======================================================
// HANDLE PERMANENT DELETE
// ======================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    $action = $_POST['action'];
    $ann_id = isset($_POST['ann_id']) ? (int)$_POST['ann_id'] : 0;

    if ($action === 'delete') {

        if ($ann_id <= 0) {
            header("Location: archive_sy.php?status=invalid");
            exit();
        }

        try {

            $pdo->beginTransaction();

            // Remove read records first
            $stmt_reads = $pdo->prepare("
                DELETE FROM announcement_reads
                WHERE announcement_id = ?
            ");

            $stmt_reads->execute([$ann_id]);

            // Delete announcement
            $stmt_delete = $pdo->prepare("
                DELETE FROM announcements
                WHERE id = ?
            ");

            $stmt_delete->execute([$ann_id]);

            if ($stmt_delete->rowCount() > 0) {

                $pdo->commit();

                header("Location: archive_sy.php?status=deleted");
                exit();

            } else {

                $pdo->rollBack();

                header("Location: archive_sy.php?status=not_found");
                exit();
            }

        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            header("Location: archive_sy.php?status=error");
            exit();
        }
    }
}

// ======================================================
// GET CURRENT SEMESTER AND ACADEMIC YEAR
// ======================================================
$current_semester = '';
$current_academic_year = '';

try {

    $stmt_settings = $pdo->query("
        SELECT current_semester, current_academic_year
        FROM system_settings
        LIMIT 1
    ");

    $settings = $stmt_settings->fetch();

    if ($settings) {

        $current_semester =
            $settings['current_semester'] ?? '';

        $current_academic_year =
            $settings['current_academic_year'] ?? '';
    }

} catch (PDOException $e) {

    $current_semester = '';
    $current_academic_year = '';
}

// ======================================================
// FETCH OLD / ARCHIVED ANNOUNCEMENTS
// ======================================================
$archived_posts = [];

try {

    /*
     * Archive shows announcements that are:
     *
     * - active
     * - NOT from the current semester
     * - NOT from the current academic year
     */

    if (
        $current_semester !== '' &&
        $current_academic_year !== ''
    ) {

        $stmt_arch = $pdo->prepare("
            SELECT a.*
            FROM announcements a
            WHERE a.status = 'active'
              AND NOT (
                    a.semester = ?
                    AND a.academic_year = ?
              )
            ORDER BY
                a.academic_year DESC,
                a.semester DESC,
                a.created_at DESC
        ");

        $stmt_arch->execute([
            $current_semester,
            $current_academic_year
        ]);

    } else {

        // Fallback if system settings are empty
        $stmt_arch = $pdo->query("
            SELECT a.*
            FROM announcements a
            WHERE a.status = 'active'
            ORDER BY a.created_at DESC
        ");
    }

    $archived_posts = $stmt_arch->fetchAll();

} catch (PDOException $e) {

    $archived_posts = [];
}

// ======================================================
// HELPER FUNCTIONS
// ======================================================
function getProfessorLabel(int $professor_id)
{
    global $pdo;

    if (!$professor_id) {
        return 'Professor';
    }

    try {
        $stmt = $pdo->prepare("SELECT full_name FROM users WHERE user_id = ? LIMIT 1");
        $stmt->execute([$professor_id]);
        $row = $stmt->fetch();

        if ($row && !empty($row['full_name'])) {
            return $row['full_name'];
        }
    } catch (PDOException $e) {
    }

    return 'Professor #' . $professor_id;
}

function getInitial(int $professor_id)
{
    return 'P';
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Archive SY | NoteSphere</title>

    <!-- FONT -->
    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <!-- SWEET ALERT 2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>

        /* ==================================================
           ROOT
        ================================================== */

        :root {
            --neon: #39FF14;
            --neon-glow: rgba(57, 255, 20, 0.12);

            --card-bg: #0c0c0c;
            --text: #f8fafc;
            --sub-text: #94a3b8;
            --border: #1f293d;

            --sidebar-width: 260px;
            --bg: #080808;
        }


        /* ==================================================
           LIGHT MODE
        ================================================== */

        body.light-mode {

            --card-bg: #ffffff;
            --text: #000000;
            --sub-text: #334155;
            --border: #e2e8f0;
            --bg: #ffffff;

            --neon: #087f3e;
            --neon-glow: rgba(8, 127, 62, 0.10);
        }


        /* ==================================================
           GLOBAL
        ================================================== */

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;

            font-family: 'Plus Jakarta Sans', sans-serif;

            word-break: break-word;
        }

        body {

            background: var(--bg);

            color: var(--text);

            display: flex;

            min-height: 100vh;

            overflow-x: hidden;

            transition: background 0.3s ease;
        }


        /* ==================================================
           SIDEBAR
        ================================================== */

        .sidebar {

            width: var(--sidebar-width);

            height: 100vh;

            background: var(--card-bg);

            border-right: 1px solid var(--border);

            padding: 30px 20px;

            position: fixed;

            top: 0;
            left: 0;

            display: flex;

            flex-direction: column;

            justify-content: space-between;

            z-index: 1002;

            transition: transform 0.3s ease;

            overflow-y: auto;
        }

        .sidebar-top {

            display: flex;

            flex-direction: column;
        }

        .sidebar-brand {

            display: flex;

            align-items: center;

            gap: 12px;

            margin-bottom: 28px;

            padding-left: 5px;
        }

        .sidebar-brand img {

            width: 35px;

            height: auto;

            object-fit: contain;
        }

        .sidebar-brand h2 {

            font-weight: 800;

            color: var(--neon);

            font-size: 1.4rem;
        }


        /* ==================================================
           MENU
        ================================================== */

        .menu {

            display: flex;

            flex-direction: column;

            gap: 5px;
        }

        .menu a {

            display: flex;

            align-items: center;

            gap: 14px;

            padding: 11px 15px;

            text-decoration: none;

            color: var(--sub-text);

            font-weight: 600;

            font-size: 0.90rem;

            border-radius: 11px;

            transition: all 0.3s ease;
        }

        .menu a svg {

            width: 20px;
            height: 20px;

            stroke: currentColor;

            stroke-width: 2;

            fill: none;

            flex-shrink: 0;
        }

        .menu a:hover,
        .menu a.active {

            background: var(--neon-glow);

            color: var(--neon);
        }

        .menu a.active {

            border: 1px solid rgba(57, 255, 20, 0.20);
        }


        /* ==================================================
           MORE MENU
        ================================================== */

        .more-toggle-btn {

            width: 100%;

            display: flex;

            align-items: center;

            justify-content: space-between;

            padding: 11px 15px;

            background: transparent;

            border: none;

            color: var(--sub-text);

            font-weight: 600;

            font-size: 0.90rem;

            border-radius: 11px;

            cursor: pointer;

            transition: all 0.3s ease;
        }

        .more-toggle-left {

            display: flex;

            align-items: center;

            gap: 14px;
        }

        .more-toggle-left svg,
        .more-chevron {

            width: 20px;
            height: 20px;

            stroke: currentColor;

            stroke-width: 2;

            fill: none;

            flex-shrink: 0;
        }

        .more-chevron {

            transition: transform 0.3s ease;
        }

        .more-toggle-btn:hover {

            background: var(--neon-glow);

            color: var(--neon);
        }

        .submenu-container {

            display: flex;

            flex-direction: column;

            gap: 3px;

            padding-left: 15px;

            margin-top: 3px;
        }

        .submenu-container a {

            padding: 9px 13px;

            font-size: 0.86rem;
        }


        /* ==================================================
           SIDEBAR FOOTER
        ================================================== */

        .sidebar-footer {

            display: flex;

            flex-direction: column;

            gap: 9px;

            margin-top: 20px;
        }

        .theme-toggle-btn {

            width: 100%;

            padding: 11px 15px;

            background: var(--card-bg);

            border: 1px solid var(--border);

            color: var(--text);

            border-radius: 11px;

            font-weight: 600;

            cursor: pointer;

            display: flex;

            align-items: center;

            gap: 12px;

            transition: 0.3s;

            font-size: 0.88rem;

            text-decoration: none;
        }

        .theme-toggle-btn:hover {

            border-color: var(--neon);

            color: var(--neon);
        }


        /* ==================================================
           MOBILE HEADER
        ================================================== */

        .mobile-header {

            display: none;

            align-items: center;

            justify-content: space-between;

            padding: 13px 18px;

            background: var(--card-bg);

            border-bottom: 1px solid var(--border);

            position: fixed;

            top: 0;
            left: 0;

            width: 100%;

            z-index: 1000;
        }

        .sidebar-toggle-btn {

            background: var(--card-bg);

            border: 1px solid var(--border);

            cursor: pointer;

            display: flex;

            align-items: center;

            justify-content: center;

            width: 40px;
            height: 40px;

            border-radius: 10px;

            color: var(--text);
        }

        .sidebar-toggle-btn svg {

            width: 20px;
            height: 20px;

            stroke: currentColor;

            stroke-width: 2;

            fill: none;
        }


        /* ==================================================
           SIDEBAR OVERLAY
        ================================================== */

        .sidebar-overlay {

            display: none;

            position: fixed;

            top: 0;
            left: 0;

            width: 100vw;
            height: 100vh;

            background: rgba(0, 0, 0, 0.60);

            z-index: 1001;

            backdrop-filter: blur(2px);
        }


        /* ==================================================
           MAIN
        ================================================== */

        main {

            flex: 1;

            padding: 35px 40px;

            max-width: 100vw;

            width: 100%;
        }


        /* ==================================================
           HEADER / X CLOSE BUTTON
        ================================================== */

        .header-nav {

            display: flex;

            align-items: center;

            justify-content: flex-end;

            margin-bottom: 17px;
        }

        .back-circle-btn {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            width: 42px;
            height: 42px;

            text-decoration: none;

            color: var(--text);

            background: var(--card-bg);

            border: 1px solid var(--border);

            border-radius: 10px;

            font-size: 29px;

            font-weight: 400;

            line-height: 1;

            transition: all 0.2s ease;
        }

        .back-circle-btn:hover {

            color: var(--neon);

            border-color: var(--neon);

            transform: scale(1.05);
        }

        .back-circle-btn:focus-visible {

            outline: 2px solid var(--neon);

            outline-offset: 3px;
        }


        /* ==================================================
           BANNER
        ================================================== */

        .class-banner {

            background: var(--card-bg);

            border: 1px solid var(--border);

            border-radius: 16px;

            padding: 19px 21px;

            color: var(--text);

            margin-bottom: 18px;

            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
        }

        .class-banner h1 {

            font-size: 1.35rem;

            font-weight: 800;

            margin-bottom: 5px;

            color: var(--neon);
        }

        .class-banner p {

            font-size: 0.82rem;

            color: var(--sub-text);

            line-height: 1.5;
        }


        /* ==================================================
           FEED
        ================================================== */

        .feed-list {

            display: flex;

            flex-direction: column;

            gap: 12px;
        }

        .g-card {

            background: var(--card-bg);

            border: 1px solid var(--border);

            border-radius: 15px;

            padding: 15px 17px;

            transition: all 0.25s ease;

            box-shadow: 0 3px 15px rgba(0, 0, 0, 0.15);
        }

        .g-card:hover {

            border-color: var(--neon);

            box-shadow: 0 7px 22px var(--neon-glow);
        }


        /* ==================================================
           CARD HEADER
        ================================================== */

        .g-card-header {

            display: flex;

            align-items: flex-start;

            justify-content: space-between;

            margin-bottom: 9px;

            gap: 8px;
        }

        .g-avatar-title {

            display: flex;

            align-items: center;

            gap: 9px;
        }

        .g-icon-circle {

            width: 35px;
            height: 35px;

            border-radius: 50%;

            background: var(--neon-glow);

            color: var(--neon);

            display: flex;

            align-items: center;

            justify-content: center;

            font-weight: 800;

            font-size: 0.85rem;

            flex-shrink: 0;

            border: 1px solid rgba(57, 255, 20, 0.20);
        }

        .g-title-meta h3 {

            font-size: 0.87rem;

            font-weight: 700;

            color: var(--text);

            margin-bottom: 2px;
        }

        .g-title-meta span {

            font-size: 0.70rem;

            color: var(--sub-text);

            line-height: 1.4;
        }


        /* ==================================================
           ANNOUNCEMENT CONTENT
        ================================================== */

        .ann-content {

            font-size: 0.83rem;

            color: var(--text);

            line-height: 1.5;

            margin-bottom: 10px;

            white-space: pre-wrap;

            word-break: break-word;
        }


        /* ==================================================
           DETAILS
        ================================================== */

        .announcement-details {

            display: flex;

            flex-wrap: wrap;

            gap: 5px;

            margin-bottom: 11px;
        }

        .detail-tag {

            padding: 4px 8px;

            border: 1px solid var(--border);

            border-radius: 7px;

            font-size: 0.66rem;

            color: var(--sub-text);

            background: transparent;
        }


        /* ==================================================
           ACTIONS
        ================================================== */

        .archive-actions {

            display: flex;

            justify-content: flex-end;

            align-items: center;

            gap: 7px;

            border-top: 1px solid var(--border);

            padding-top: 10px;
        }

        .outline-btn {

            background: transparent;

            border: 1px solid var(--border);

            color: var(--text);

            padding: 6px 10px;

            border-radius: 8px;

            font-weight: 700;

            cursor: pointer;

            font-size: 0.70rem;

            transition: all 0.2s;

            display: inline-flex;

            align-items: center;

            justify-content: center;

            gap: 5px;

            text-decoration: none;
        }

        .outline-btn:hover {

            background: var(--neon-glow);

            color: var(--neon);

            border-color: var(--neon);
        }

        .delete-btn {

            color: #ff4d4d;

            border-color: rgba(255, 77, 77, 0.35);
        }

        .delete-btn:hover {

            color: #ff4d4d;

            border-color: #ff4d4d;

            background: rgba(255, 77, 77, 0.08);
        }


        /* ==================================================
           EMPTY STATE
        ================================================== */

        .empty-state {

            text-align: center;

            padding: 40px 20px;

            background: var(--card-bg);

            border: 1px solid var(--border);

            border-radius: 16px;

            color: var(--sub-text);

            font-size: 0.85rem;

            line-height: 1.5;

            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
        }

        .empty-state p {

            font-weight: 700;

            color: var(--text);

            margin-bottom: 4px;
        }


        /* ==================================================
           MOBILE
        ================================================== */

        @media (max-width: 768px) {

            .mobile-header {
                display: flex;
            }

            main {

                margin-left: 0;

                padding:
                    82px
                    14px
                    18px
                    14px;

                max-width: 100vw;
            }

            .sidebar {

                transform: translateX(-100%);
            }

            body.sidebar-open .sidebar {

                transform: translateX(0);
            }

            body.sidebar-open .sidebar-overlay {

                display: block;
            }

            .class-banner {

                padding: 16px;

                border-radius: 14px;

                margin-bottom: 14px;
            }

            .class-banner h1 {

                font-size: 1.08rem;
            }

            .class-banner p {

                font-size: 0.76rem;
            }

            .feed-list {

                gap: 10px;
            }

            .g-card {

                padding: 13px 14px;

                border-radius: 14px;
            }

            .g-card-header {

                flex-direction: column;

                margin-bottom: 8px;
            }

            .g-icon-circle {

                width: 33px;
                height: 33px;

                font-size: 0.80rem;
            }

            .g-title-meta h3 {

                font-size: 0.82rem;
            }

            .g-title-meta span {

                font-size: 0.65rem;
            }

            .ann-content {

                font-size: 0.78rem;

                line-height: 1.45;

                margin-bottom: 9px;
            }

            .detail-tag {

                font-size: 0.62rem;

                padding: 4px 7px;
            }

            .archive-actions {

                flex-direction: column;

                align-items: stretch !important;

                padding-top: 9px;
            }

            .archive-actions form {

                width: 100%;
            }

            .archive-actions button {

                width: 100%;

                justify-content: center;

                padding: 7px 9px;

                font-size: 0.69rem;
            }

            .empty-state {

                padding: 35px 15px;

                font-size: 0.78rem;
            }

            .header-nav {

                margin-bottom: 14px;
            }

            .back-circle-btn {

                width: 38px;
                height: 38px;

                font-size: 27px;
            }
        }


        /* ==================================================
           SMALL MOBILE
        ================================================== */

        @media (max-width: 420px) {

            main {

                padding-left: 11px;

                padding-right: 11px;
            }

            .mobile-header {

                padding-left: 13px;

                padding-right: 13px;
            }

            .mobile-header h2 {

                font-size: 1.05rem !important;
            }

            .class-banner h1 {

                font-size: 1rem;
            }

            .g-card {

                padding: 12px;
            }

            .g-avatar-title {

                gap: 8px;
            }
        }

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


<!-- =========================================
     MOBILE HEADER
========================================= -->

<header class="mobile-header">

    <div style="display:flex;align-items:center;gap:9px;">

        <img
            src="../assets/cdmlogo.png"
            alt="Logo"
            style="width:30px;height:auto;"
        >

        <h2 style="
            font-size:1.15rem;
            color:var(--neon);
        ">
            NoteSphere
        </h2>

    </div>



</header>




<!-- =========================================
     MAIN CONTENT
========================================= -->

<main>


    <!-- X / CLOSE BUTTON -->

    <div class="header-nav">

        <a
            href="student_home.php"
            class="back-circle-btn"
            title="Back to Dashboard"
            aria-label="Back to Dashboard"
        >&times;</a>

    </div>


    <!-- BANNER -->

    <div class="class-banner">

        <h1>
            Archive Semester & Academic Year
        </h1>

        <p>
            Review past class announcements, previous semester posts,
            and manage your archived records.
        </p>

    </div>


    <!-- Group archived posts by professor -->
    <?php
        $grouped = [];
        foreach ($archived_posts as $p) {
            $pid = $p['professor_id'] ?? 0;
            if (!isset($grouped[$pid])) $grouped[$pid] = [];
            $grouped[$pid][] = $p;
        }
    ?>

    <!-- =========================================
         NO ARCHIVE
    ========================================= -->

    <?php if (empty($archived_posts)): ?>

        <div class="empty-state">

            <p>
                No archived posts found
            </p>

            There are no announcements from previous
            semesters or academic years.

        </div>


    <?php else: ?>


        <!-- =========================================
             ARCHIVED POSTS
        ========================================= -->

        <div class="feed-list">

            <?php foreach ($grouped as $pid => $posts): ?>

                <?php
                    $professor_label = getProfessorLabel($pid);
                    $initial = getInitial($pid);
                ?>

                <details class="g-card" style="padding:0;overflow:hidden;">

                    <summary style="cursor:pointer;padding:15px 17px;display:flex;align-items:center;gap:9px;list-style:none;">

                        <div class="g-icon-circle"><?= htmlspecialchars($initial) ?></div>

                        <div>
                            <div style="font-weight:700;color:var(--text);"><?= htmlspecialchars($professor_label) ?></div>
                            <div style="font-size:0.72rem;color:var(--sub-text);"><?= count($posts) ?> archived announcement(s)</div>
                        </div>

                    </summary>

                    <div style="padding:0 17px 17px;">

                        <?php foreach ($posts as $post): ?>

                <?php

                    $professor_label =
                        getProfessorLabel(
                            $post['professor_id'] ?? 0
                        );

                    $initial =
                        getInitial(
                            $post['professor_id'] ?? 0
                        );

                ?>


                <!-- CARD -->

                <div class="g-card">


                    <!-- HEADER -->

                    <div class="g-card-header">

                        <div class="g-avatar-title">


                            <!-- AVATAR -->

                            <div class="g-icon-circle">

                                <?= htmlspecialchars($initial) ?>

                            </div>


                            <!-- META -->

                            <div class="g-title-meta">

                                <h3>

                                    <?= htmlspecialchars(
                                        $professor_label
                                    ) ?>

                                    <?php if (!empty($post['subject'])): ?>

                                        (
                                        <?= htmlspecialchars(
                                            $post['subject']
                                        ) ?>
                                        )

                                    <?php endif; ?>

                                </h3>


                                <span>

                                    <?=
                                        date(
                                            'M d, Y - h:i A',
                                            strtotime(
                                                $post['created_at']
                                            )
                                        )
                                    ?>

                                    &nbsp;|&nbsp;

                                    Semester:

                                    <?= htmlspecialchars(
                                        $post['semester'] ?? 'N/A'
                                    ) ?>

                                    &nbsp;(

                                    <?= htmlspecialchars(
                                        $post['academic_year'] ?? 'N/A'
                                    ) ?>

                                    )

                                </span>

                            </div>

                        </div>

                    </div>


                    <!-- TITLE -->

                    <?php if (!empty($post['title'])): ?>

                        <div
                            style="
                                font-size:0.92rem;
                                font-weight:800;
                                color:var(--neon);
                                margin-bottom:6px;
                            "
                        >

                            <?= htmlspecialchars(
                                $post['title']
                            ) ?>

                        </div>

                    <?php endif; ?>


                    <!-- MESSAGE -->

                    <div class="ann-content">

                        <?= nl2br(
                            htmlspecialchars(
                                $post['message'] ?? ''
                            )
                        ) ?>

                    </div>


                    <!-- DETAILS -->

                    <div class="announcement-details">


                        <?php if (!empty($post['category'])): ?>

                            <span class="detail-tag">

                                Category:

                                <?= htmlspecialchars(
                                    $post['category']
                                ) ?>

                            </span>

                        <?php endif; ?>


                        <?php if (!empty($post['year_level'])): ?>

                            <span class="detail-tag">

                                Year:

                                <?= htmlspecialchars(
                                    $post['year_level']
                                ) ?>

                            </span>

                        <?php endif; ?>


                        <?php if (!empty($post['section'])): ?>

                            <span class="detail-tag">

                                Section:

                                <?= htmlspecialchars(
                                    $post['section']
                                ) ?>

                            </span>

                        <?php endif; ?>


                    </div>


                    <!-- ACTION -->

                    <div class="archive-actions">


                        <!-- DELETE -->

                        <form
                            method="POST"
                            class="delete-form"
                            style="margin:0;"
                        >

                            <input
                                type="hidden"
                                name="action"
                                value="delete"
                            >

                            <input
                                type="hidden"
                                name="ann_id"
                                value="<?= (int)$post['id'] ?>"
                            >


                            <button
                                type="submit"
                                class="outline-btn delete-btn"
                            >

                                🗑 Delete Permanently

                            </button>

                        </form>

                    </div>

                </div>

                        <?php endforeach; ?>

                    </div>

                </details>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>


</main>


<script>


/* ==================================================
   MOBILE SIDEBAR
================================================== */

function toggleMobileSidebar() {

    document.body.classList.toggle(
        'sidebar-open'
    );
}


/* ==================================================
   MORE MENU
================================================== */

function toggleMoreMenu(event) {

    event.preventDefault();

    const submenu =
        document.getElementById(
            'moreSubmenu'
        );

    const chevron =
        document.getElementById(
            'moreChevron'
        );


    if (
        submenu.style.display === 'none' ||
        submenu.style.display === ''
    ) {

        submenu.style.display = 'flex';

        chevron.style.transform =
            'rotate(180deg)';

    } else {

        submenu.style.display = 'none';

        chevron.style.transform =
            'rotate(0deg)';
    }
}


/* ==================================================
   THEME
================================================== */

function toggleGlobalTheme() {

    document.body.classList.toggle(
        'light-mode'
    );

    const isLight =
        document.body.classList.contains(
            'light-mode'
        );


    localStorage.setItem(
        'notesphere_theme',
        isLight ? 'light' : 'dark'
    );


    updateThemeButton(isLight);
}


function updateThemeButton(isLight) {

    document.getElementById(
        'themeIcon'
    ).textContent =
        isLight ? '🌙' : '🌞';


    document.getElementById(
        'themeText'
    ).textContent =
        isLight ? 'Dark Mode' : 'Light Mode';
}


/* ==================================================
   LOAD SAVED THEME
================================================== */

if (
    localStorage.getItem(
        'notesphere_theme'
    ) === 'light'
) {

    document.body.classList.add(
        'light-mode'
    );

    updateThemeButton(true);
}


/* ==================================================
   SWEET ALERT DELETE CONFIRMATION
================================================== */

document
    .querySelectorAll('.delete-form')
    .forEach(function(form) {

        form.addEventListener(
            'submit',
            function(event) {

                event.preventDefault();


                Swal.fire({

                    title:
                        'Delete permanently?',

                    text:
                        'This announcement will be permanently deleted and cannot be recovered.',

                    icon:
                        'warning',

                    showCancelButton:
                        true,

                    confirmButtonText:
                        'Yes, delete it',

                    cancelButtonText:
                        'Cancel',

                    confirmButtonColor:
                        '#ff4d4d',

                    cancelButtonColor:
                        '#6c757d',

                    reverseButtons:
                        true,

                    theme:
                        document.body.classList.contains(
                            'light-mode'
                        )
                            ? 'light'
                            : 'dark'

                }).then(function(result) {

                    if (
                        result.isConfirmed
                    ) {

                        form.submit();
                    }

                });

            }
        );

    });


/* ==================================================
   SWEET ALERT STATUS
================================================== */

const urlParams =
    new URLSearchParams(
        window.location.search
    );

const status =
    urlParams.get('status');


/* ==================================================
   DELETED
================================================== */

if (status === 'deleted') {

    Swal.fire({

        icon:
            'success',

        title:
            'Deleted!',

        text:
            'The announcement has been permanently deleted.',

        confirmButtonText:
            'OK',

        confirmButtonColor:
            '#2ecc71',

        theme:
            document.body.classList.contains(
                'light-mode'
            )
                ? 'light'
                : 'dark'

    }).then(function() {

        window.history.replaceState(
            {},
            document.title,
            'archive_sy.php'
        );

    });
}


/* ==================================================
   NOT FOUND
================================================== */

else if (status === 'not_found') {

    Swal.fire({

        icon:
            'info',

        title:
            'Not Found',

        text:
            'The announcement was already deleted or no longer exists.',

        confirmButtonText:
            'OK',

        confirmButtonColor:
            '#2ecc71',

        theme:
            document.body.classList.contains(
                'light-mode'
            )
                ? 'light'
                : 'dark'

    }).then(function() {

        window.history.replaceState(
            {},
            document.title,
            'archive_sy.php'
        );

    });
}


/* ==================================================
   INVALID
================================================== */

else if (status === 'invalid') {

    Swal.fire({

        icon:
            'warning',

        title:
            'Invalid Request',

        text:
            'The selected announcement is invalid.',

        confirmButtonText:
            'OK',

        confirmButtonColor:
            '#2ecc71',

        theme:
            document.body.classList.contains(
                'light-mode'
            )
                ? 'light'
                : 'dark'

    }).then(function() {

        window.history.replaceState(
            {},
            document.title,
            'archive_sy.php'
        );

    });
}


/* ==================================================
   ERROR
================================================== */

else if (status === 'error') {

    Swal.fire({

        icon:
            'error',

        title:
            'Something went wrong',

        text:
            'The announcement could not be deleted. Please try again.',

        confirmButtonText:
            'OK',

        confirmButtonColor:
            '#ff4d4d',

        theme:
            document.body.classList.contains(
                'light-mode'
            )
                ? 'light'
                : 'dark'

    }).then(function() {

        window.history.replaceState(
            {},
            document.title,
            'archive_sy.php'
        );

    });
}

</script>
</body>
</html>

