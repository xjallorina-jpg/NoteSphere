<?php

// =========================================================
// PROFESSOR DASHBOARD
// =========================================================

session_start();

require_once __DIR__ . '/../includes/db_config.php';
require_once __DIR__ . '/../includes/notification_counts.php';
require_once __DIR__ . '/../storage_helper.php';

/** @var PDO $conn */

// =========================================================
// SECURITY CHECK
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

// Combined unread count shown on the sidebar Help Board button.
$ns_badge_counts = ns_notification_counts($conn, $user_id);

// =========================================================
// FETCH PROFESSOR INFORMATION
// =========================================================

try {

    $stmt_user = $conn->prepare("
        SELECT full_name
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");

    $stmt_user->execute([$user_id]);

    $user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);

    $fullname =
        $user_data['full_name']
        ?? $_SESSION['fullname']
        ?? 'Professor';

} catch (Exception $e) {

    $fullname =
        $_SESSION['fullname']
        ?? 'Professor';
}

// =========================================================
// FETCH ACTIVE ACADEMIC YEAR AND SEMESTER
// CONTROLLED BY ADMIN
//
// DATABASE COLUMNS:
// current_academic_year = School Year
// current_semester      = Semester
// =========================================================

$active_semester = '1st Semester';
$active_year = '2026-2027';

try {

    $stmt_settings = $conn->prepare("
        SELECT
            current_academic_year,
            current_semester
        FROM system_settings
        WHERE id = 1
        LIMIT 1
    ");

    $stmt_settings->execute();

    $current_settings =
        $stmt_settings->fetch(PDO::FETCH_ASSOC);

    if ($current_settings) {

        // =================================================
        // GET CURRENT SEMESTER FROM ADMIN SETTINGS
        // =================================================

        if (
            isset($current_settings['current_semester']) &&
            trim($current_settings['current_semester']) !== ''
        ) {

            $active_semester =
                trim($current_settings['current_semester']);
        }

        // =================================================
        // GET CURRENT SCHOOL YEAR FROM ADMIN SETTINGS
        // =================================================

        if (
            isset($current_settings['current_academic_year']) &&
            trim($current_settings['current_academic_year']) !== ''
        ) {

            $active_year =
                trim($current_settings['current_academic_year']);
        }
    }

} catch (Exception $e) {

    // Keep default values if settings cannot be loaded.

}


// =========================================================
// PROFESSOR PERSONAL STORAGE
// Dynamic quota based on Admin Dashboard settings
// =========================================================

$professor_storage = [];

try {

    // Get professor storage quota configured by the admin
    $storage_stmt = $conn->prepare("
        SELECT professor_storage_gb
        FROM system_storage
        WHERE id = 1
        LIMIT 1
    ");

    $storage_stmt->execute();

    $admin_storage = $storage_stmt->fetch(PDO::FETCH_ASSOC);

    // Convert admin-configured GB to bytes
    $admin_quota_gb = isset($admin_storage['professor_storage_gb'])
        ? (float) $admin_storage['professor_storage_gb']
        : 1000.00;

    $admin_quota_bytes = max(
        1,
        $admin_quota_gb * 1024 * 1024 * 1024
    );

    // Get current professor storage usage
    $professor_storage = ns_get_user_storage(
        $conn,
        $user_id,
        'professor'
    );

    // Always use the quota currently configured by the admin
    $professor_storage['quota'] = $admin_quota_bytes;

    // Support either used or used_bytes from the helper
    $storage_used_bytes = max(
        0,
        (float) (
            $professor_storage['used']
            ?? $professor_storage['used_bytes']
            ?? 0
        )
    );

    // Recalculate remaining storage and percentage
    $storage_remaining_bytes = max(
        0,
        $admin_quota_bytes - $storage_used_bytes
    );

    $storage_percentage = $admin_quota_bytes > 0
        ? min(
            100,
            max(
                0,
                ($storage_used_bytes / $admin_quota_bytes) * 100
            )
        )
        : 100;

    // Format storage values
    $format_storage = function ($bytes) {
        $bytes = max(0, (float) $bytes);

        if ($bytes >= 1024 * 1024 * 1024 * 1024) {
            return number_format(
                $bytes / (1024 * 1024 * 1024 * 1024),
                2
            ) . ' TB';
        }

        if ($bytes >= 1024 * 1024 * 1024) {
            return number_format(
                $bytes / (1024 * 1024 * 1024),
                2
            ) . ' GB';
        }

        if ($bytes >= 1024 * 1024) {
            return number_format(
                $bytes / (1024 * 1024),
                2
            ) . ' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }

        return number_format($bytes, 0) . ' B';
    };

    // Store consistent values for the dashboard
    $professor_storage['used'] = $storage_used_bytes;
    $professor_storage['used_bytes'] = $storage_used_bytes;
    $professor_storage['quota'] = $admin_quota_bytes;
    $professor_storage['quota_bytes'] = $admin_quota_bytes;
    $professor_storage['remaining'] = $storage_remaining_bytes;
    $professor_storage['remaining_bytes'] = $storage_remaining_bytes;
    $professor_storage['percentage'] = $storage_percentage;

    $professor_storage['used_text'] =
        $format_storage($storage_used_bytes);

    $professor_storage['quota_text'] =
        $format_storage($admin_quota_bytes);

    $professor_storage['remaining_text'] =
        $format_storage($storage_remaining_bytes);

} catch (Throwable $e) {

    error_log(
        'Professor storage error: ' . $e->getMessage()
    );

    // Fallback quota also uses the admin setting when available
    $fallback_quota_gb = 1000.00;

    try {
        $fallback_stmt = $conn->query("
            SELECT professor_storage_gb
            FROM system_storage
            WHERE id = 1
            LIMIT 1
        ");

        $fallback_row = $fallback_stmt
            ? $fallback_stmt->fetch(PDO::FETCH_ASSOC)
            : false;

        if (
            $fallback_row &&
            isset($fallback_row['professor_storage_gb'])
        ) {
            $fallback_quota_gb = max(
                0.000001,
                (float) $fallback_row['professor_storage_gb']
            );
        }
    } catch (Throwable $fallback_error) {
        error_log(
            'Professor storage fallback error: '
            . $fallback_error->getMessage()
        );
    }

    $fallback_quota_bytes = max(
        1,
        $fallback_quota_gb * 1024 * 1024 * 1024
    );

    $professor_storage = [
        'used' => 0,
        'used_bytes' => 0,
        'quota' => $fallback_quota_bytes,
        'quota_bytes' => $fallback_quota_bytes,
        'remaining' => $fallback_quota_bytes,
        'remaining_bytes' => $fallback_quota_bytes,
        'percentage' => 0,
        'used_text' => '0 B',
        'quota_text' => number_format($fallback_quota_gb, 2) . ' GB',
        'remaining_text' => number_format($fallback_quota_gb, 2) . ' GB'
    ];

    $storage_percentage = 0;
}

// =========================================================
// STORAGE VALUES
// =========================================================

$storage_percentage = min(
    100,
    max(
        0,
        (float) (
            $professor_storage['percentage'] ?? 0
        )
    )
);

$storage_used_text =
    $professor_storage['used_text'] ?? '0 B';

$storage_quota_text =
    $professor_storage['quota_text'] ?? '1000 GB';

$storage_remaining_text =
    $professor_storage['remaining_text'] ?? '1000 GB';



// =========================================================
// FETCH RECENT PRIVATE CONSULTATIONS
// =========================================================

$recent_messages = [];

try {

    $stmt_msg = $conn->prepare("
        SELECT
            m.*,
            u.user_id AS student_id,
            u.full_name AS student_name,
            u.course,
            u.year_level,
            u.section
        FROM messages m
        JOIN users u
            ON m.sender_id = u.user_id
        WHERE
            m.receiver_id = ?
            AND u.role = 'student'
            AND m.id = (
                SELECT
                    m2.id
                FROM messages m2
                WHERE
                    (
                        m2.sender_id = u.user_id
                        AND
                        m2.receiver_id = ?
                    )
                    OR
                    (
                        m2.sender_id = ?
                        AND
                        m2.receiver_id = u.user_id
                    )
                ORDER BY
                    m2.created_at DESC,
                    m2.id DESC
                LIMIT 1
            )
        ORDER BY
            m.created_at DESC
        LIMIT 5
    ");

    $stmt_msg->execute([
        $user_id,
        $user_id,
        $user_id
    ]);

    $recent_messages =
        $stmt_msg->fetchAll(
            PDO::FETCH_ASSOC
        );

} catch (Exception $e) {

    $recent_messages = [];

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

    <title>
        NoteSphere - Faculty Dashboard
    </title>

    <link rel="stylesheet" href="../assets/notification_badges.css">
    <script src="../assets/notification_badges.js" data-csrf="<?php echo htmlspecialchars(ns_notification_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" defer></script>

    <!-- =====================================================
         GOOGLE FONT
    ====================================================== -->

    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <!-- =====================================================
         SWEETALERT2
    ====================================================== -->

    <script
        src="https://cdn.jsdelivr.net/npm/sweetalert2@11"
    ></script>

    <style>

        /* =====================================================
           ROOT
        ====================================================== */

        :root {

            --neon: #39FF14;

            --neon-glow:
                rgba(57, 255, 20, 0.12);

            --card-bg:
                #0c0c0c;

            --bg:
                #080808;

            --text:
                #f8fafc;

            --sub-text:
                #94a3b8;

            --border:
                #1f293d;

            --sidebar-width:
                260px;

            --danger:
                #ff4d4d;
        }

        /* =====================================================
           LIGHT MODE
        ====================================================== */

        body.light-mode {

            --card-bg:
                #f5efe6;

            --bg:
                #f0e6d8;

            --text:
                #3b2f2f;

            --border:
                #d9c9b4;

            --sub-text:
                #6b5a4a;

            --neon:
                #8b6f47;

            --neon-glow:
                rgba(139, 111, 71, 0.15);
        }

        /* =====================================================
           GLOBAL
        ====================================================== */

        * {

            margin: 0;

            padding: 0;

            box-sizing: border-box;

            font-family:
                'Plus Jakarta Sans',
                sans-serif;
        }

        html,
        body {

            width: 100%;

            max-width: 100%;

            min-height: 100%;

            overflow-x: hidden;
        }

        body {

            position: relative;

            color:
                var(--text);

            background:
                var(--bg);

            display: flex;

            min-height: 100vh;

            transition:
                color 0.3s ease,
                background 0.3s ease;
        }

        /* =====================================================
           BACKGROUND
        ====================================================== */

        body::before {
            content: none;
        }

        body.light-mode::before {

            background:
                
                url('../assets/Untitled design.png')
                no-repeat
                center
                center;

            background-size: cover;
        }

        /* =====================================================
           SIDEBAR
        ====================================================== */

        .sidebar {

            width:
                var(--sidebar-width);

            height: 100vh;

            background:
                var(--card-bg);

            border-right:
                1px solid
                var(--border);

            padding:
                28px 18px;

            position: fixed;

            top: 0;

            left: 0;

            display: flex;

            flex-direction: column;

            justify-content: space-between;

            z-index: 1002;

            transition:
                transform 0.3s ease,
                background 0.3s ease,
                border-color 0.3s ease;

            overflow-y: auto;
        }

        .sidebar-top {

            display: flex;

            flex-direction: column;
        }

        /* =====================================================
           BRAND
        ====================================================== */

        .sidebar-brand {

            display: flex;

            align-items: center;

            gap: 12px;

            margin-bottom: 30px;

            padding-left: 5px;
        }

        .sidebar-brand h2 {

            font-weight: 800;

            color:
                #2ecc71;

            font-size:
                1.4rem;
        }

        .sidebar-brand img {

            width: 35px;

            height: auto;
        }

        /* =====================================================
           MENU
        ====================================================== */

        .menu {

            display: flex;

            flex-direction: column;

            gap: 6px;
        }

        .menu a {

            display: flex;

            align-items: center;

            gap: 14px;

            padding:
                12px 16px;

            text-decoration: none;

            color:
                var(--sub-text);

            font-weight: 600;

            font-size:
                0.92rem;

            border-radius:
                12px;

            transition:
                all 0.3s ease;
        }

        .menu a svg {

            width: 20px;

            height: 20px;

            stroke:
                currentColor;

            stroke-width: 2;

            fill: none;

            flex-shrink: 0;
        }

        .menu a:hover,
        .menu a.active {

            background:
                var(--neon-glow);

            color:
                var(--neon);
        }

        .menu a.active {

            border:
                1px solid
                rgba(57, 255, 20, 0.2);
        }







        /* =====================================================
           SIDEBAR FOOTER
        ====================================================== */

        .sidebar-footer {

            display: flex;

            flex-direction: column;

            gap: 10px;

            margin-top: 20px;
        }

        .theme-toggle-btn {

            width: 100%;

            padding:
                12px 16px;

            background:
                var(--card-bg);

            border:
                1px solid
                var(--border);

            color:
                var(--text);

            border-radius:
                12px;

            font-weight:
                600;

            cursor:
                pointer;

            display: flex;

            align-items:
                center;

            gap: 12px;

            transition:
                0.3s;

            font-size:
                0.9rem;

            text-decoration:
                none;
        }

        .theme-toggle-btn:hover {

            border-color:
                var(--neon);

            color:
                var(--neon);
        }

        /* =====================================================
           MOBILE HEADER
        ====================================================== */

        .mobile-header {

            display: none;

            align-items:
                center;

            justify-content:
                space-between;

            padding:
                12px 16px;

            background:
                var(--card-bg);

            border-bottom:
                1px solid
                var(--border);

            position:
                fixed;

            top: 0;

            left: 0;

            width: 100%;

            z-index: 1000;
        }

        .mobile-brand {

            display: flex;

            align-items:
                center;

            gap: 9px;
        }

        .mobile-brand img {

            width:
                30px;

            height:
                auto;
        }

        .mobile-brand h2 {

            font-size:
                1.15rem;

            color:
                #2ecc71;
        }

        .sidebar-toggle-btn {

            background:
                var(--card-bg);

            border:
                1px solid
                var(--border);

            cursor:
                pointer;

            display:
                flex;

            align-items:
                center;

            justify-content:
                center;

            width:
                42px;

            height:
                42px;

            border-radius:
                10px;

            color:
                var(--text);
        }

        .sidebar-toggle-btn svg {

            width:
                20px;

            height:
                20px;

            stroke:
                currentColor;

            stroke-width:
                2;

            fill:
                none;
        }

        /* =====================================================
           SIDEBAR OVERLAY
        ====================================================== */

        .sidebar-overlay {

            display:
                none;

            position:
                fixed;

            top: 0;

            left: 0;

            width:
                100vw;

            height:
                100vh;

            background:
                rgba(0, 0, 0, 0.65);

            z-index:
                1001;

            backdrop-filter:
                blur(2px);
        }

        /* =====================================================
           MAIN CONTENT
        ====================================================== */

        main {

            margin-left:
                var(--sidebar-width);

            flex: 1;

            padding:
                40px;

            width:
                calc(
                    100% -
                    var(--sidebar-width)
                );

            max-width:
                calc(
                    100% -
                    var(--sidebar-width)
                );

            overflow-x:
                hidden;
        }

        /* =====================================================
           WELCOME BOX
        ====================================================== */

        .welcome-box {

            background:
                linear-gradient(
                    135deg,
                    var(--card-bg),
                    rgba(
                        57,
                        255,
                        20,
                        0.03
                    )
                );

            border:
                1px solid
                #2ecc71;

            border-radius:
                20px;

            padding:
                30px;

            margin-bottom:
                30px;

            display:
                flex;

            justify-content:
                space-between;

            align-items:
                center;

            flex-wrap:
                wrap;

            gap:
                20px;
        }

        .welcome-box h1 {

            font-size:
                1.8rem;

            font-weight:
                800;

            margin-bottom:
                6px;

            line-height:
                1.3;
        }

        .welcome-box p {

            color:
                var(--sub-text);

            font-size:
                0.95rem;
        }

        .semester-badge {

            background:
                var(--neon-glow);

            color:
                var(--neon);

            padding:
                8px 15px;

            border-radius:
                20px;

            font-size:
                0.82rem;

            font-weight:
                700;

            border:
                1px solid
                var(--neon);

            white-space:
                nowrap;
        }

        /* =====================================================
           SECTION HEADER
        ====================================================== */

        .section-header {

            display:
                flex;

            justify-content:
                space-between;

            align-items:
                center;

            margin-bottom:
                20px;

            margin-top:
                10px;

            flex-wrap:
                wrap;

            gap:
                15px;
        }

        .section-header h2 {

            font-size:
                1.3rem;

            font-weight:
                700;
        }

        .section-header p {

            color:
                var(--sub-text);

            font-size:
                0.88rem;

            margin-top:
                4px;

            line-height:
                1.5;
        }

        /* =====================================================
           MESSAGE BUTTON
        ====================================================== */

        .icon-action-btn {

            display:
                flex;

            align-items:
                center;

            justify-content:
                center;

            width:
                42px;

            height:
                42px;

            background:
                var(--neon-glow);

            border:
                1px solid
                var(--neon);

            border-radius:
                12px;

            color:
                var(--neon);

            text-decoration:
                none;

            transition:
                all 0.3s ease;

            flex-shrink:
                0;
        }

        .icon-action-btn:hover {

            background:
                var(--neon);

            color:
                #0c0c0c;

            transform:
                translateY(-2px);
        }

        .icon-action-btn svg {

            width:
                20px;

            height:
                20px;

            stroke:
                currentColor;

            stroke-width:
                2.2;

            fill:
                none;
        }

        /* =====================================================
           CONSULTATION CARD
        ====================================================== */

        .consultation-card {

            background:
                var(--card-bg);

            border:
                1px solid
                var(--border);

            border-radius:
                16px;

            padding:
                24px;

            margin-bottom:
                25px;

            transition:
                background 0.3s ease,
                border-color 0.3s ease;
        }

        .consultation-header {

            display:
                flex;

            justify-content:
                space-between;

            align-items:
                center;

            gap:
                10px;

            flex-wrap:
                wrap;

            margin-bottom:
                5px;
        }

        .consultation-header h3 {

            font-size:
                1.1rem;

            font-weight:
                700;

            color:
                var(--text);
        }

        .consultation-title-wrap {

            display:
                flex;

            align-items:
                center;

            gap:
                8px;

            min-width:
                0;
        }

        .live-badge {

            font-size:
                0.76rem;

            background:
                var(--neon-glow);

            color:
                var(--neon);

            padding:
                5px 10px;

            border-radius:
                8px;

            font-weight:
                700;

            white-space:
                nowrap;
        }

        .consultation-description {

            color:
                var(--sub-text);

            font-size:
                0.85rem;

            margin-bottom:
                10px;

            line-height:
                1.5;
        }

        /* =====================================================
           MESSAGE LIST
        ====================================================== */

        .msg-list {

            display:
                flex;

            flex-direction:
                column;

            gap:
                12px;

            margin-top:
                15px;
        }

        .msg-item {

            display:
                flex;

            justify-content:
                space-between;

            align-items:
                center;

            gap:
                15px;

            padding:
                14px 18px;

            background:
                var(--card-bg);

            border:
                1px solid
                #2ecc71;

            border-radius:
                12px;

            text-decoration:
                none;

            color:
                var(--text);

            transition:
                all 0.2s ease;

            min-width:
                0;
        }

        .msg-item:hover {

            border-color:
                var(--neon);

            background:
                var(--neon-glow);

            transform:
                translateY(-2px);
        }

        .msg-info {

            min-width:
                0;

            flex:
                1;
        }

        .msg-info h4 {

            font-size:
                0.98rem;

            font-weight:
                700;

            color:
                var(--text);

            margin-bottom:
                4px;

            word-break:
                break-word;
        }

        .student-info {

            font-size:
                0.74rem;

            color:
                var(--neon);

            font-weight:
                500;
        }

        .msg-info p {

            font-size:
                0.85rem;

            color:
                var(--sub-text);

            white-space:
                nowrap;

            overflow:
                hidden;

            text-overflow:
                ellipsis;

            max-width:
                600px;
        }

        .msg-time {

            font-size:
                0.76rem;

            color:
                var(--sub-text);

            font-weight:
                600;

            white-space:
                nowrap;

            flex-shrink:
                0;
        }

        /* =====================================================
           EMPTY MESSAGE
        ====================================================== */

        .empty-message {

            text-align:
                center;

            padding:
                35px 20px;

            color:
                var(--sub-text);

            font-size:
                0.88rem;

            background:
                var(--card-bg);

            border-radius:
                12px;

            margin-top:
                15px;

            border:
                1px dashed
                var(--border);

            line-height:
                1.5;
        }

        /* =====================================================
           TABLET
        ====================================================== */

        @media (max-width: 900px) {

            :root {

                --sidebar-width:
                    230px;
            }

            main {

                padding:
                    28px;
            }

            .sidebar {

                padding:
                    25px 15px;
            }

            .menu a {

                padding:
                    11px 13px;

                font-size:
                    0.87rem;
            }

            .welcome-box h1 {

                font-size:
                    1.55rem;
            }
        }

        /* =====================================================
           MOBILE
        ====================================================== */

        @media (max-width: 768px) {

            .mobile-header {

                display:
                    flex;
            }

            .sidebar {

                width:
                    270px;

                max-width:
                    85vw;

                transform:
                    translateX(-100%);

                box-shadow:
                    8px 0 30px
                    rgba(0, 0, 0, 0.25);

                padding-bottom:
                    calc(90px + env(safe-area-inset-bottom, 0px));
                overflow-y:
                    auto;
            }

            body.sidebar-open
            .sidebar {

                transform:
                    translateX(0);
            }

            body.sidebar-open
            .sidebar-overlay {

                display:
                    block;
            }

            main {

                margin-left:
                    0;

                width:
                    100%;

                max-width:
                    100%;

                padding:
                    82px 16px 25px;
            }

            .welcome-box {

                padding:
                    22px;

                margin-bottom:
                    22px;

                align-items:
                    flex-start;
            }

            .welcome-box h1 {

                font-size:
                    1.35rem;

                word-break:
                    break-word;
            }

            .welcome-box p {

                font-size:
                    0.84rem;

                line-height:
                    1.5;
            }

            .semester-badge {

                font-size:
                    0.76rem;

                padding:
                    7px 11px;

                white-space:
                    normal;
            }

            .section-header {

                align-items:
                    flex-start;
            }

            .section-header > div:first-child {

                flex:
                    1;

                min-width:
                    0;
            }

            .section-header h2 {

                font-size:
                    1.15rem;
            }

            .section-header p {

                font-size:
                    0.8rem;
            }

            .consultation-card {

                padding:
                    18px;

                border-radius:
                    14px;
            }

            .msg-item {

                flex-direction:
                    column;

                align-items:
                    flex-start;

                gap:
                    9px;

                padding:
                    14px;
            }

            .msg-info {

                width:
                    100%;
            }

            .msg-info p {

                max-width:
                    100%;
            }

            .msg-time {

                font-size:
                    0.72rem;
            }
        }

        /* =====================================================
           SMALL MOBILE
        ====================================================== */

        @media (max-width: 480px) {

            .mobile-header {

                padding:
                    10px 12px;
            }

            .mobile-brand h2 {

                font-size:
                    1.05rem;
            }

            .mobile-brand img {

                width:
                    27px;
            }

            .sidebar-toggle-btn {

                width:
                    40px;

                height:
                    40px;
            }

            main {

                padding:
                    76px 12px 20px;
            }

            .welcome-box {

                padding:
                    18px;

                gap:
                    14px;
            }

            .welcome-box h1 {

                font-size:
                    1.18rem;
            }

            .welcome-box p {

                font-size:
                    0.78rem;
            }

            .semester-badge {

                width:
                    100%;

                text-align:
                    center;
            }

            .section-header {

                gap:
                    10px;
            }

            .icon-action-btn {

                width:
                    40px;

                height:
                    40px;
            }

            .consultation-card {

                padding:
                    15px;
            }

            .consultation-header h3 {

                font-size:
                    1rem;
            }

            .consultation-description {

                font-size:
                    0.78rem;
            }

            .student-info {

                display:
                    block;

                margin-top:
                    3px;
            }

            .msg-info h4 {

                font-size:
                    0.9rem;
            }

            .msg-info p {

                font-size:
                    0.78rem;
            }
        }

    </style>

</head>

<body>

<!-- =====================================================
     MOBILE HEADER
====================================================== -->

<header class="mobile-header">

    <div class="mobile-brand">

        <img
            src="../assets/icon-192.png"
            alt="Colegio de Montalban Logo"
        >

        <h2>
            NoteSphere
        </h2>

    </div>

    <button
        class="sidebar-toggle-btn"
        onclick="toggleMobileSidebar()"
        aria-label="Toggle Sidebar"
        type="button"
    >

        <svg viewBox="0 0 24 24">

            <line
                x1="3"
                y1="12"
                x2="21"
                y2="12"
            ></line>

            <line
                x1="3"
                y1="6"
                x2="21"
                y2="6"
            ></line>

            <line
                x1="3"
                y1="18"
                x2="21"
                y2="18"
            ></line>

        </svg>

    </button>

</header>

<!-- =====================================================
     SIDEBAR OVERLAY
====================================================== -->

<div
    class="sidebar-overlay"
    onclick="toggleMobileSidebar()"
></div>

<!-- =====================================================
     SIDEBAR
====================================================== -->

<nav
    class="sidebar"
    id="sidebar"
>

    <div class="sidebar-top">

        <!-- BRAND -->

        <div class="sidebar-brand">

            <img
                src="../assets/icon-192.png"
                alt="Colegio de Montalban Logo"
            >

            <h2>
                NoteSphere
            </h2>

        </div>

        <!-- MENU -->

        <div class="menu">

            <!-- DASHBOARD -->

            <a
                href="professor_dashboard.php"
                class="active"
            >

                <svg viewBox="0 0 24 24">

                    <path
                        d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"
                    ></path>

                    <polyline
                        points="9 22 9 12 15 12 15 22"
                    ></polyline>

                </svg>

                <span>
                    Dashboard
                </span>

            </a>

                 <!-- ADD ANNOUNCEMENT -->

            <a
                href="add_announcement.php"
            >

                <svg viewBox="0 0 24 24">

                    <path
                        d="M12 5v14M5 12h14"
                    ></path>

                </svg>

                <span>
                    Add Announcement
                </span>

            </a>          
        </div>



    </div>

    <!-- =====================================================
         SIDEBAR FOOTER
    ====================================================== -->

    <div class="sidebar-footer">

        <!-- DEVICE NOTIFICATIONS -->

        <button
            type="button"
            onclick="enableProfessorNotifications()"
            class="theme-toggle-btn"
            id="professorNotificationBtn"
        >
            <span id="professorNotificationIcon">
                🔔
            </span>

            <span id="professorNotificationText">
                Enable Notifications
            </span>
        </button>

        <!-- THEME -->

        <button
            onclick="toggleGlobalTheme()"
            class="theme-toggle-btn"
            id="globalThemeBtn"
            type="button"
        >

            <span id="themeIcon">
                🌞
            </span>

            <span id="themeText">
                Light Mode
            </span>

        </button>

        <!-- LOGOUT -->

        <a
            href="../auth/logout.php"
            class="theme-toggle-btn logout-link"
            style="
                color:#ff4d4d;
                border-color:
                rgba(255,77,77,0.3);
            "
        >

            <svg
                viewBox="0 0 24 24"
                style="
                    width:18px;
                    height:18px;
                    stroke:currentColor;
                    stroke-width:2;
                    fill:none;
                "
            >

                <path
                    d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"
                ></path>

                <polyline
                    points="16 17 21 12 16 7"
                ></polyline>

            </svg>

            <span>
                Logout
            </span>

        </a>

    </div>

</nav>

<!-- =====================================================
     MAIN CONTENT
====================================================== -->

<main>

    <!-- =================================================
         WELCOME
    ================================================== -->

    <div class="welcome-box">

        <div>

            <h1>

                Hello, Prof.

                <?php
                echo htmlspecialchars(
                    $fullname
                );
                ?>!

                👋

            </h1>

            <p>
                Welcome back to your Faculty Dashboard.
            </p>

        </div>

        <!-- CURRENT ACADEMIC PERIOD -->

        <div class="semester-badge">

            <?php
            echo htmlspecialchars(
                $active_semester
                . ' | '
                . $active_year
            );
            ?>

        </div>

    </div>

    <!-- =================================================
         DASHBOARD OVERVIEW
    ================================================== -->

    <div class="section-header">

        <div>

            <h2>
                Dashboard Overview
            </h2>

            <p>
                Your main control panel and recent student consultations for the current academic period.
            </p>

        </div>

        <!-- MESSAGES -->

        <a
            href="professor_messages.php"
            class="icon-action-btn ns-nav-link"
            title="View All Messages"
        >
            <svg viewBox="0 0 24 24">

                <path
                    d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"
                ></path>

            </svg>

        </a>

    </div>

    <!-- =================================================
         CONSULTATIONS
    ================================================== -->

    <div class="consultation-card">

        <div class="consultation-header">

            <div class="consultation-title-wrap">
                <h3>
                    Recent Private Consultations
                </h3>

                <?php echo ns_notification_badge_html(
                    (int)($ns_badge_counts['direct_messages'] ?? 0),
                    'direct_messages',
                    true
                ); ?>
            </div>

            <span class="live-badge">
                Live Updates
            </span>

        </div>

        <p class="consultation-description">
            Check quick inquiries and messages sent by your students.
        </p>

        <!-- =================================================
             MESSAGES
        ================================================== -->

        <?php if (!empty($recent_messages)): ?>

            <div class="msg-list">

                <?php foreach ($recent_messages as $msg): ?>

                    <a
                        href="professor_messages.php?student_id=<?php echo (int) $msg['student_id']; ?>"
                        class="msg-item"
                    >

                        <div class="msg-info">

                            <h4>

                                <?php
                                echo htmlspecialchars(
                                    $msg['student_name']
                                );
                                ?>

                                <span class="student-info">

                                    (

                                    <?php
                                    echo htmlspecialchars(
                                        $msg['course']
                                        . ' '
                                        . $msg['year_level']
                                        . '-'
                                        . $msg['section']
                                    );
                                    ?>

                                    )

                                </span>

                            </h4>

                            <p>

                                <?php
                                echo htmlspecialchars(
                                    $msg['message']
                                    ??
                                    'Sent a consultation message...'
                                );
                                ?>

                            </p>

                        </div>

                        <span class="msg-time">

                            <?php

                            if (
                                !empty(
                                    $msg['created_at']
                                )
                            ) {

                                echo date(
                                    'M d, h:i A',
                                    strtotime(
                                        $msg['created_at']
                                    )
                                );

                            } else {

                                echo 'Recently';

                            }

                            ?>

                        </span>

                    </a>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="empty-message">

                No pending messages or consultations requiring your response at the moment.

            </div>

        <?php endif; ?>

    </div>

</main>

<!-- =====================================================
     JAVASCRIPT
====================================================== -->

<script>

    // =====================================================
    // MOBILE SIDEBAR
    // =====================================================

    function toggleMobileSidebar() {

        document.body.classList.toggle(
            'sidebar-open'
        );

    }

    // Close sidebar when clicking a menu link

    document.querySelectorAll(
        '.sidebar .menu a'
    ).forEach(function(link) {

        link.addEventListener(
            'click',
            function() {

                if (
                    window.innerWidth <= 768
                ) {

                    document.body.classList.remove(
                        'sidebar-open'
                    );

                }

            }
        );

    });

    // =====================================================
    // THEME
    // =====================================================

    const currentUserId = <?php echo (int)$user_id; ?>;

    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        for (let i = 0; i < rawData.length; i++) outputArray[i] = rawData.charCodeAt(i);
        return outputArray;
    }

    function updateProfessorNotificationButton() {
        const text = document.getElementById('professorNotificationText');
        const button = document.getElementById('professorNotificationBtn');
        if (!text || !button) return;
        if (!('Notification' in window)) {
            text.textContent = 'Notifications Not Supported';
            button.disabled = true;
            return;
        }
        if (Notification.permission === 'granted') {
            text.textContent = 'Notifications Enabled';
        } else if (Notification.permission === 'denied') {
            text.textContent = 'Notifications Blocked';
        } else {
            text.textContent = 'Enable Notifications';
        }
    }
    async function enableProfessorNotifications() {
        if (!('Notification' in window)) {
            if (window.Swal) Swal.fire({icon:'info', title:'Not Supported', text:'This browser does not support device notifications.'});
            return;
        }
        try {
            const permission = await Notification.requestPermission();
            updateProfessorNotificationButton();
            if (permission === 'granted') {
                try {
                    const registration = await navigator.serviceWorker.ready;
                    const vapidResponse = await fetch('../api/push_proxy.php?endpoint=vapid-public', { cache: 'no-store' });
                    const vapid = await vapidResponse.json();
                    if (vapid && vapid.publicKey) {
                        const subscription = await registration.pushManager.subscribe({
                            userVisibleOnly: true,
                            applicationServerKey: urlBase64ToUint8Array(vapid.publicKey)
                        });
                        await fetch('../api/push_proxy.php?endpoint=subscribe', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                            body: JSON.stringify({ subscription: subscription, user: currentUserId })
                        });
                    }
                } catch (pushError) {
                    console.warn('Push subscription could not be completed.', pushError);
                }
                if (window.Swal) Swal.fire({icon:'success', title:'Notifications Enabled', text:'You will receive NoteSphere notifications on this device.'});
            } else if (permission === 'denied') {
                if (window.Swal) Swal.fire({icon:'warning', title:'Permission Blocked', text:'Allow notifications for NoteSphere in your browser/device settings.'});
            }
        } catch (error) {
            console.error('Unable to request notification permission:', error);
        }
    }
    updateProfessorNotificationButton();

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
            isLight
                ? 'light'
                : 'dark'
        );

        updateThemeButton(
            isLight
        );

    }

    // =====================================================
    // UPDATE THEME BUTTON
    // =====================================================

    function updateThemeButton(
        isLight
    ) {

        const themeIcon =
            document.getElementById(
                'themeIcon'
            );

        const themeText =
            document.getElementById(
                'themeText'
            );

        if (themeIcon) {

            themeIcon.textContent =
                isLight
                    ? '🌙'
                    : '🌞';

        }

        if (themeText) {

            themeText.textContent =
                isLight
                    ? 'Dark Mode'
                    : 'Light Mode';

        }

    }

    // =====================================================
    // LOAD SAVED THEME
    // =====================================================

    if (
        localStorage.getItem(
            'notesphere_theme'
        ) === 'light'
    ) {

        document.body.classList.add(
            'light-mode'
        );

        updateThemeButton(
            true
        );

    } else {

        updateThemeButton(
            false
        );

    }

    // =====================================================
    // LOGOUT CONFIRMATION
    // =====================================================

    document.querySelectorAll(
        '.logout-link'
    ).forEach(function(logoutLink) {

        logoutLink.addEventListener(
            'click',
            function(event) {

                event.preventDefault();

                const logoutUrl =
                    this.href;

                const isLight =
                    document.body.classList.contains(
                        'light-mode'
                    );

                Swal.fire({

                    title:
                        'Logout?',

                    text:
                        'Are you sure you want to log out of your account?',

                    icon:
                        'warning',

                    showCancelButton:
                        true,

                    confirmButtonText:
                        'Yes, Logout',

                    cancelButtonText:
                        'Cancel',

                    reverseButtons:
                        true,

                    background:
                        isLight
                            ? '#ffffff'
                            : '#0c0c0c',

                    color:
                        isLight
                            ? '#111827'
                            : '#f8fafc',

                    confirmButtonColor:
                        '#ff4d4d',

                    cancelButtonColor:
                        '#64748b'

                }).then(function(result) {

                    if (
                        result.isConfirmed
                    ) {

                        window.location.href =
                            logoutUrl;

                    }

                });

            }
        );

    });



</script>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('../sw.js', { scope: '../' }).catch(function (error) {
            console.warn('Service worker registration failed:', error);
        });
    });
}
</script>
</body>
</html>