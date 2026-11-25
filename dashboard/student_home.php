<?php
// =========================================================
// NoteSphere - Student Home Dashboard
// Existing UI preserved; announcement badge counts are cleared
// once the student marks an announcement as read or submits work.
// =========================================================
session_start();
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    header('Location: ../auth/login.php');
    exit();
}

require_once __DIR__ . '/../includes/db_config.php';
require_once __DIR__ . '/../includes/notification_counts.php';
/** @var PDO $conn */
$pdo = $conn;
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$user_id = (int) $_SESSION['user_id'];

// Combined unread count shown on the sidebar Help Board button.
$ns_badge_counts = ns_notification_counts($conn, $user_id);

// One-time Help Board restriction notice returned from help_board.php.
$helpBoardBannedMessage = $_SESSION['help_board_banned_message'] ?? '';
unset($_SESSION['help_board_banned_message']);

// HTML escaping helper.
if (!function_exists('ns_home_escape')) {
    function ns_home_escape($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

// Fetch student information using the current NoteSphere user_id column.
try {
    $stmt_user = $pdo->prepare('SELECT full_name, course, year_level, section FROM users WHERE user_id = ? LIMIT 1');
    $stmt_user->execute([$user_id]);
    $user_data = $stmt_user->fetch();
    $fullname = $user_data['full_name'] ?? $_SESSION['full_name'] ?? $_SESSION['fullname'] ?? 'Student';
} catch (Throwable $e) {
    $fullname = $_SESSION['full_name'] ?? $_SESSION['fullname'] ?? 'Student';
}

// Fetch the active semester and academic year from current system settings.
try {
    $stmt_settings = $pdo->query('SELECT current_semester, current_academic_year FROM system_settings LIMIT 1');
    $current_settings = $stmt_settings->fetch() ?: [];
    $active_semester = trim((string)($current_settings['current_semester'] ?? '1st Semester'));
    $active_year = trim((string)($current_settings['current_academic_year'] ?? '2026-2027'));
    if ($active_semester === '') $active_semester = '1st Semester';
    if ($active_year === '') $active_year = '2026-2027';
} catch (Throwable $e) {
    $active_semester = '1st Semester';
    $active_year = '2026-2027';
}

// Fetch professors and announcement counts. A card's red badge counts only
// announcements that have NOT been marked as read AND have no submission/read
// record yet. The existing professor announcement page records both actions in
// announcement_reads and/or announcement_submissions, so the count drops after
// Mark as Read or Submit/Add Work.
$professors = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            u.user_id AS id,
            u.full_name,
            (
                SELECT COUNT(*)
                FROM announcements a
                WHERE a.professor_id = u.user_id
                  AND a.status = 'active'
                  AND TRIM(COALESCE(a.semester, '')) = TRIM(?)
                  AND TRIM(COALESCE(a.academic_year, '')) = TRIM(?)
            ) AS total_announcements,
            (
                SELECT COUNT(*)
                FROM announcements a
                WHERE a.professor_id = u.user_id
                  AND a.status = 'active'
                  AND TRIM(COALESCE(a.semester, '')) = TRIM(?)
                  AND TRIM(COALESCE(a.academic_year, '')) = TRIM(?)
                  AND NOT EXISTS (
                      SELECT 1
                      FROM announcement_reads ar
                      WHERE ar.announcement_id = a.id
                        AND ar.student_id = ?
                  )
                  AND NOT EXISTS (
                      SELECT 1
                      FROM announcement_submissions s
                      WHERE s.announcement_id = a.id
                        AND s.student_id = ?
                  )
            ) AS unread_announcements
        FROM users u
        WHERE u.role = 'professor'
          AND EXISTS (
              SELECT 1
              FROM announcements current_a
              WHERE current_a.professor_id = u.user_id
                AND current_a.status = 'active'
                AND TRIM(COALESCE(current_a.semester, '')) = TRIM(?)
                AND TRIM(COALESCE(current_a.academic_year, '')) = TRIM(?)
          )
        ORDER BY u.full_name ASC
    ");
    $stmt->execute([
        $active_semester,
        $active_year,
        $active_semester,
        $active_year,
        $user_id,
        $user_id,
        $active_semester,
        $active_year
    ]);
    $professors = $stmt->fetchAll();
} catch (Throwable $e) {
    // Compatibility fallback if either tracking table is not installed yet.
    // The dashboard still loads; it will show a zero badge until the tables exist.
    try {
        $stmt_fallback = $pdo->prepare("
            SELECT
                u.user_id AS id,
                u.full_name,
                (
                    SELECT COUNT(*)
                    FROM announcements a
                    WHERE a.professor_id = u.user_id
                      AND a.status = 'active'
                      AND TRIM(COALESCE(a.semester, '')) = TRIM(?)
                      AND TRIM(COALESCE(a.academic_year, '')) = TRIM(?)
                ) AS total_announcements,
                0 AS unread_announcements
            FROM users u
            WHERE u.role = 'professor'
              AND EXISTS (
                  SELECT 1
                  FROM announcements current_a
                  WHERE current_a.professor_id = u.user_id
                    AND current_a.status = 'active'
                    AND TRIM(COALESCE(current_a.semester, '')) = TRIM(?)
                    AND TRIM(COALESCE(current_a.academic_year, '')) = TRIM(?)
              )
            ORDER BY u.full_name ASC
        ");
        $stmt_fallback->execute([
            $active_semester,
            $active_year,
            $active_semester,
            $active_year
        ]);
        $professors = $stmt_fallback->fetchAll();
    } catch (Throwable $ex) {
        $professors = [];
    }
}

$total_unread = 0;
foreach ($professors as $professor_row) {
    $total_unread += (int)($professor_row['unread_announcements'] ?? 0);
}

// =========================================================
// STUDENT STORAGE DISPLAY
// Use the central Admin-configured storage helper when available so the
// dashboard uses the same quota and usage calculation as upload validation.
// =========================================================
$student_storage_limit_bytes = 0;
$student_storage_used_bytes = 0;
$student_storage_remaining_bytes = 0;
$student_storage_percent = 0;
$student_storage_available = false;

if (!function_exists('ns_home_resolve_storage_file')) {
    function ns_home_resolve_storage_file(string $path, string $root): ?string {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '' || strpos($path, '://') !== false) return null;
        $path = ltrim($path, '/');
        if (strpos($path, '../') !== false) return null;
        $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $realRoot = realpath($root);
        $realFile = realpath($full);
        if ($realRoot === false || $realFile === false || !is_file($realFile)) return null;
        $prefix = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return strpos($realFile, $prefix) === 0 ? $realFile : null;
    }
}

if (!function_exists('ns_home_format_storage_bytes')) {
    function ns_home_format_storage_bytes($bytes): string {
        $bytes = max(0, (float)$bytes);
        if ($bytes >= 1099511627776) return number_format($bytes / 1099511627776, 2) . ' TB';
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
        return number_format($bytes, 0) . ' bytes';
    }
}

try {
    $project_root = dirname(__DIR__);
    $storage_helper = $project_root . DIRECTORY_SEPARATOR . 'storage_helper.php';
    if (is_file($storage_helper)) require_once $storage_helper;

    // Prefer the shared helper, which reads the quota configured by Admin.
    if (function_exists('ns_get_user_storage')) {
        $storage_data = ns_get_user_storage($pdo, $user_id, 'student');
        $student_storage_limit_bytes = (int)($storage_data['quota_bytes'] ?? $storage_data['quota'] ?? 0);
        $student_storage_used_bytes = (int)($storage_data['used_bytes'] ?? $storage_data['used'] ?? 0);
    }

    // Resolve note attachments separately because the shared helper currently
    // does not include the note_attachments table. Count each physical file once.
    $seen_storage_files = [];
    $attachment_columns = [];
    try {
        $column_stmt = $pdo->query('SHOW COLUMNS FROM `note_attachments`');
        foreach ($column_stmt->fetchAll(PDO::FETCH_ASSOC) as $column_row) {
            if (isset($column_row['Field'])) $attachment_columns[] = (string)$column_row['Field'];
        }
    } catch (Throwable $ignored) {
        $attachment_columns = [];
    }

    if ($attachment_columns) {
        $owner_column = null;
        foreach (['user_id', 'student_id', 'owner_id', 'uploaded_by'] as $candidate) {
            if (in_array($candidate, $attachment_columns, true)) { $owner_column = $candidate; break; }
        }
        $path_column = null;
        foreach (['file_path', 'attachment_path', 'path', 'file_url'] as $candidate) {
            if (in_array($candidate, $attachment_columns, true)) { $path_column = $candidate; break; }
        }

        if ($owner_column !== null && $path_column !== null) {
            $attachment_sql = 'SELECT `' . str_replace('`', '', $path_column) . '` AS storage_path FROM `note_attachments` WHERE `' . str_replace('`', '', $owner_column) . '` = ?';
            $attachment_stmt = $pdo->prepare($attachment_sql);
            $attachment_stmt->execute([$user_id]);
            while ($attachment_row = $attachment_stmt->fetch(PDO::FETCH_ASSOC)) {
                $full_path = ns_home_resolve_storage_file((string)($attachment_row['storage_path'] ?? ''), $project_root);
                if ($full_path === null || isset($seen_storage_files[$full_path])) continue;
                $seen_storage_files[$full_path] = true;
                clearstatcache(true, $full_path);
                $file_size = filesize($full_path);
                if ($file_size !== false) $student_storage_used_bytes += (int)$file_size;
            }
        }
    }

    // Fallback for installations that do not yet have storage_helper.php.
    // Dynamically detect actual column names instead of assuming one schema.
    if (!function_exists('ns_get_user_storage')) {
        $settings_stmt = $pdo->query('SELECT student_storage_gb FROM system_storage ORDER BY id ASC LIMIT 1');
        $settings_row = $settings_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$settings_row || !isset($settings_row['student_storage_gb'])) {
            throw new RuntimeException('Student storage settings were not found.');
        }
        $storage_gb = (float)$settings_row['student_storage_gb'];
        if (!is_finite($storage_gb) || $storage_gb < 0) throw new RuntimeException('Student storage limit is invalid.');
        $student_storage_limit_bytes = (int)round($storage_gb * 1024 * 1024 * 1024);
        $student_storage_used_bytes = 0;

        $tables_to_scan = [
            ['table' => 'notes', 'owners' => ['user_id','student_id','owner_id','uploaded_by'], 'paths' => ['file_path','thumbnail','thumbnail_path','preview_path']],
            ['table' => 'note_attachments', 'owners' => ['user_id','student_id','owner_id','uploaded_by'], 'paths' => ['file_path','attachment_path','path','file_url']],
            ['table' => 'announcement_submissions', 'owners' => ['student_id','user_id'], 'paths' => ['file_path','attachment_path']]
        ];
        foreach ($tables_to_scan as $table_info) {
            try {
                $cols = [];
                foreach ($pdo->query('SHOW COLUMNS FROM `' . $table_info['table'] . '`')->fetchAll(PDO::FETCH_ASSOC) as $cr) {
                    if (isset($cr['Field'])) $cols[] = (string)$cr['Field'];
                }
                $owner = null; $path_fields = [];
                foreach ($table_info['owners'] as $candidate) if (in_array($candidate, $cols, true)) { $owner = $candidate; break; }
                foreach ($table_info['paths'] as $candidate) if (in_array($candidate, $cols, true)) $path_fields[] = $candidate;
                if ($owner === null || !$path_fields) continue;
                $select_fields = implode(', ', array_map(static function($field) { return '`' . str_replace('`', '', $field) . '`'; }, $path_fields));
                $q = $pdo->prepare('SELECT ' . $select_fields . ' FROM `' . $table_info['table'] . '` WHERE `' . str_replace('`', '', $owner) . '` = ?');
                $q->execute([$user_id]);
                while ($row = $q->fetch(PDO::FETCH_ASSOC)) foreach ($path_fields as $field) {
                    $full_path = ns_home_resolve_storage_file((string)($row[$field] ?? ''), $project_root);
                    if ($full_path === null || isset($seen_storage_files[$full_path])) continue;
                    $seen_storage_files[$full_path] = true;
                    clearstatcache(true, $full_path);
                    $file_size = filesize($full_path);
                    if ($file_size !== false) $student_storage_used_bytes += (int)$file_size;
                }
            } catch (Throwable $ignored) {
                // Missing optional tables/columns should not break the dashboard.
            }
        }
    }

    $student_storage_limit_bytes = max(0, $student_storage_limit_bytes);
    $student_storage_used_bytes = max(0, $student_storage_used_bytes);
    $student_storage_remaining_bytes = max(0, $student_storage_limit_bytes - $student_storage_used_bytes);
    $student_storage_percent = $student_storage_limit_bytes > 0
        ? min(100, max(0, ($student_storage_used_bytes / $student_storage_limit_bytes) * 100))
        : ($student_storage_used_bytes > 0 ? 100 : 0);
    $student_storage_available = $student_storage_limit_bytes > 0;
} catch (Throwable $storage_error) {
    error_log('NoteSphere student dashboard storage display: ' . $storage_error->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>NoteSphere - Student Dashboard</title>
    <link rel="stylesheet" href="../assets/notification_badges.css">
    <script src="../assets/notification_badges.js" data-csrf="<?php echo ns_home_escape(ns_notification_csrf_token()); ?>" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --neon: #39FF14;
            --neon-glow: rgba(57, 255, 20, 0.12);
            --card-bg: rgba(12, 12, 12, 0.88);
            --text: #f8fafc;
            --sub-text: #94a3b8;
            --border: rgba(148, 163, 184, 0.18);
            --sidebar-width: 260px;
            --bg: #080808;
            --sidebar-bg: rgba(12, 12, 12, 0.96);
        }
        body.light-mode {
            --bg: #ffffff;
            --card-bg: rgba(255, 255, 255, 0.93);
            --text: #000000;
            --sidebar-bg: rgba(255, 255, 255, 0.96);
            --border: rgba(15, 23, 42, 0.13);
            --sub-text: #334155;
            --neon: #087f3e;
            --neon-glow: rgba(8, 127, 62, 0.12);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
        
        html, body {
            width: 100%;
            max-width: 100vw;
            overflow-x: hidden;
        }

        body { 
            position: relative;
            color: var(--text); 
            background: var(--bg);
            display: flex; 
            min-height: 100vh; 
            transition: color 0.3s ease, background 0.3s ease; 
        }

        body::before {
            content: none;
        }

        /* Sidebar Styles */
        .sidebar { width: var(--sidebar-width); height: 100vh; background: var(--sidebar-bg); border-right: 1px solid var(--border); padding: 35px 20px; position: fixed; top: 0; left: 0; display: flex; flex-direction: column; justify-content: space-between; z-index: 1002; transition: transform 0.3s ease, background 0.3s ease, border-color 0.3s ease; overflow-y: auto; backdrop-filter: blur(10px); }
        .sidebar-top { display: flex; flex-direction: column; }
        .sidebar-brand { display: flex; align-items: center; gap: 12px; margin-bottom: 30px; padding-left: 5px; }
        .sidebar-brand h2 { font-weight: 800; color: var(--neon); font-size: 1.4rem; }
        .sidebar-brand img { width: 35px; height: auto; }
        
        .menu { display: flex; flex-direction: column; gap: 6px; }
        .menu a { display: flex; align-items: center; gap: 14px; padding: 12px 16px; text-decoration: none; color: var(--sub-text); font-weight: 600; font-size: 0.92rem; border-radius: 12px; transition: all 0.3s ease; }
        .menu a svg { width: 20px; height: 20px; stroke: currentColor; stroke-width: 2; fill: none; flex-shrink: 0; }
        .menu a:hover, .menu a.active { background: var(--neon-glow); color: var(--neon); }
        .menu a.active { border: 1px solid rgba(57, 255, 20, 0.2); }

        /* More Menu Styles */
        .more-toggle-btn { width: 100%; display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: transparent; border: none; color: var(--sub-text); font-weight: 600; font-size: 0.92rem; border-radius: 12px; cursor: pointer; transition: all 0.3s ease; }
        .more-toggle-left { display: flex; align-items: center; gap: 14px; }
        .more-toggle-left svg, .more-chevron { width: 20px; height: 20px; stroke: currentColor; stroke-width: 2; fill: none; flex-shrink: 0; }
        .more-chevron { transition: transform 0.3s ease; }
        .more-toggle-btn:hover { background: var(--neon-glow); color: var(--neon); }
        .submenu-container { display: flex; flex-direction: column; gap: 4px; padding-left: 15px; margin-top: 4px; }
        .submenu-container a { padding: 10px 14px; font-size: 0.88rem; }

        .sidebar-footer { display: flex; flex-direction: column; gap: 10px; margin-top: 20px; }

        .theme-toggle-btn { width: 100%; padding: 12px 16px; background: var(--card-bg); border: 1px solid var(--border); color: var(--text); border-radius: 12px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 12px; transition: 0.3s; font-size: 0.9rem; text-decoration: none; }
        .theme-toggle-btn:hover { border-color: var(--neon); color: var(--neon); }

        /* Mobile Header */
        .mobile-header { display: none; align-items: center; justify-content: space-between; padding: 15px 20px; background: var(--sidebar-bg); border-bottom: 1px solid var(--border); position: fixed; top: 0; left: 0; width: 100%; z-index: 1000; box-sizing: border-box; backdrop-filter: blur(10px); }
        .sidebar-toggle-btn { position: relative; background: var(--card-bg); border: 1px solid var(--border); cursor: pointer; display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 10px; color: var(--text); }
        .sidebar-toggle-btn svg { width: 20px; height: 20px; stroke: currentColor; stroke-width: 2; fill: none; }

        /* Sidebar Overlay */
        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0, 0, 0, 0.6); z-index: 1001; backdrop-filter: blur(2px); }

        /* Main Content */
        main { 
            margin-left: var(--sidebar-width); 
            flex: 1; 
            padding: 40px; 
            max-width: calc(100vw - var(--sidebar-width)); 
            width: 100%; 
            box-sizing: border-box;
            overflow-x: hidden;
        }
        
        .welcome-box { background: linear-gradient(135deg, var(--card-bg), rgba(57, 255, 20, 0.03)); border: 1px solid var(--border); border-radius: 20px; padding: 30px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; transition: background 0.3s ease, border-color 0.3s ease; box-sizing: border-box; }
        .welcome-box h1 { font-size: 1.8rem; font-weight: 800; margin-bottom: 6px; }
        .welcome-box p { color: var(--sub-text); font-size: 0.95rem; }
        .semester-badge { background: var(--neon-glow); color: var(--neon); padding: 6px 14px; border-radius: 20px; font-size: 0.85rem; font-weight: 700; border: 1px solid var(--neon); opacity: 1 !important; visibility: visible !important; display: inline-block !important; }
        body.light-mode .semester-badge { background: var(--neon-glow); color: var(--neon); border-color: var(--neon); }

        .section-header { margin-bottom: 20px; margin-top: 10px; }
        .section-header h2 { font-size: 1.3rem; font-weight: 700; color: var(--text); opacity: 1 !important; visibility: visible !important; }
        .section-header p { color: var(--sub-text); font-size: 0.88rem; margin-top: 4px; opacity: 1 !important; visibility: visible !important; }
        body.light-mode .section-header h2, body.light-mode .section-header p { color: #000000 !important; }

        .prof-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; width: 100%; }
        .prof-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 16px; padding: 24px; text-decoration: none; color: var(--text); transition: all 0.2s ease; display: flex; flex-direction: column; justify-content: space-between; box-sizing: border-box; position: relative; opacity: 1 !important; visibility: visible !important; }
        .prof-card:hover { border-color: var(--neon); transform: translateY(-3px); box-shadow: 0 10px 25px var(--neon-glow); }
        .prof-card h3 { font-size: 1.15rem; font-weight: 700; margin-bottom: 6px; color: var(--text); word-break: break-word; }
        .prof-card p { font-size: 0.85rem; color: var(--sub-text); margin-bottom: 20px; }
        
        /* Notification Badge sa Taas */
        .notif-badge {
            position: absolute;
            top: 20px;
            right: 20px;
            background: #ff4d4d;
            color: #ffffff;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            gap: 4px;
            box-shadow: 0 4px 10px rgba(255, 77, 77, 0.3);
            animation: pulse-badge 2s infinite;
        }
        @keyframes pulse-badge {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .prof-footer { display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border); padding-top: 14px; gap: 10px; }
        .badge { background: var(--neon-glow); color: var(--neon); padding: 5px 12px; font-size: 0.78rem; font-weight: 700; border-radius: 8px; }

        @media (max-width: 768px) {
            .mobile-header { display: flex; padding-left: calc(20px + env(safe-area-inset-left, 0px)); padding-right: calc(20px + env(safe-area-inset-right, 0px)); }
            main { margin-left: 0; padding: calc(90px + env(safe-area-inset-top, 0px)) calc(16px + env(safe-area-inset-right, 0px)) calc(20px + env(safe-area-inset-bottom, 0px)) calc(16px + env(safe-area-inset-left, 0px)); max-width: 100vw; }
            .sidebar { transform: translateX(-100%); }
            body.sidebar-open .sidebar { transform: translateX(0); }
            body.sidebar-open .sidebar-overlay { display: block; }
            .sidebar { padding-bottom: calc(90px + env(safe-area-inset-bottom, 0px)); overflow-y: auto; }
        }
        @supports (-webkit-touch-callout: none) {
            input, select, textarea { font-size: 16px; }
        }
    </style>
</head>
<body>

<!-- Mobile Header with Hamburger -->
<header class="mobile-header">
    <div style="display: flex; align-items: center; gap: 10px;">

        <img src="../assets/icon-192.png" alt="NoteSphere" style="width: 30px; height: auto;">
        <h2 style="font-size: 1.2rem; color: var(--neon);">NoteSphere</h2>
    </div>
    <button class="sidebar-toggle-btn" onclick="toggleMobileSidebar()" aria-label="Toggle Sidebar">
        <svg viewBox="0 0 24 24"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
        <span class="ns-count-badge" data-ns-count-group="help messages" <?php echo (int)$ns_badge_counts['total'] > 0 ? '' : 'hidden'; ?>><?php echo (int)$ns_badge_counts['total'] > 99 ? '99+' : (int)$ns_badge_counts['total']; ?></span>
    </button>
</header>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" onclick="toggleMobileSidebar()"></div>

<nav class="sidebar" id="sidebar">
    <div class="sidebar-top">
        <div class="sidebar-brand">
            <img src="../assets/icon-192.png" alt="NoteSphere" style="width: 35px; height: auto;">
            <h2>NoteSphere</h2>
        </div>

        <div class="menu">
            <a href="student_home.php" class="active">
                <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                <span>Dashboard</span>
            </a>
            
            <a href="view_notes.php">
                <svg viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                <span>View Notes</span>
            </a>

            <a href="help_board.php" class="ns-nav-link">
                <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path><circle cx="12" cy="10" r="1"></circle></svg>
                <span>Help Board (Q&A)</span>
                <span class="ns-count-badge ns-inline" data-ns-count-group="help messages" <?php echo (int)$ns_badge_counts['total'] > 0 ? '' : 'hidden'; ?>><?php echo (int)$ns_badge_counts['total'] > 99 ? '99+' : (int)$ns_badge_counts['total']; ?></span>
            </a>

            <div class="menu-item-group" style="margin-top: 5px;">
                <button type="button" onclick="toggleMoreMenu(event)" class="more-toggle-btn" id="moreToggleBtn">
                    <div class="more-toggle-left">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="1"></circle><circle cx="19" cy="12" r="1"></circle><circle cx="5" cy="12" r="1"></circle></svg>
                        <span>More</span>
                    </div>
                    <svg class="more-chevron" id="moreChevron" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </button>

                <div id="moreSubmenu" class="submenu-container" style="display: none;">
                    <a href="archive_sy.php">
                        <svg viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                        <span>Archive SY</span>
                    </a>

                    <a href="enrollment_update.php">
                        <svg viewBox="0 0 24 24"><path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c3 3 9 3 12 0v-5"></path></svg>
                        <span>Academic Info Update</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="sidebar-footer">
        <button type="button" onclick="enableStudentNotifications()" class="theme-toggle-btn" id="studentNotificationBtn">
            <span id="studentNotificationIcon">🔔</span> <span id="studentNotificationText">Enable Notifications</span>
        </button>
        <button onclick="toggleGlobalTheme()" class="theme-toggle-btn" id="globalThemeBtn">
            <span id="themeIcon">🌞</span> <span id="themeText">Light Mode</span>
        </button>
        <a href="javascript:void(0)" onclick="confirmLogout()" class="theme-toggle-btn" style="color: #ff4d4d; border-color: rgba(255, 77, 77, 0.3);">
            <svg viewBox="0 0 24 24" style="width: 18px; height: 18px; stroke: currentColor; stroke-width: 2; fill: none;"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline></svg>
            <span>Logout</span>
        </a>
    </div>
</nav>

<main>
    <div class="welcome-box">
        <div>
            <h1>Hello, <?php echo ns_home_escape($fullname); ?>! 👋</h1>
            <p>Select a professor below to view their announcements, quizzes, and modules.</p>
        </div>
        <div class="semester-badge">
            <?php echo ns_home_escape($active_semester . ' | ' . $active_year); ?>
        </div>
    </div>

    <div class="section-header">
        <h2>Professors<?php if ($total_unread > 0): ?>
            <span style="display:inline-flex;align-items:center;gap:4px;margin-left:8px;color:#ff4d4d;font-size:0.75rem;vertical-align:middle;">🔔 <?php echo $total_unread; ?> unread</span>
        <?php endif; ?></h2>
        <p>Tap a professor's name to view current announcements, assignments, modules, and updates.</p>
    </div>

    <?php if (!empty($professors)): ?>
        <div class="prof-grid">
            <?php foreach ($professors as $prof): ?>
                <div class="prof-card">
                    <?php if ($prof['unread_announcements'] > 0): ?>
                        <div class="notif-badge">
                            <svg viewBox="0 0 24 24" style="width: 12px; height: 12px; stroke: currentColor; stroke-width: 3; fill: none;"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                            <span><?php echo (int)$prof['unread_announcements']; ?> New</span>
                        </div>
                    <?php endif; ?>

                    <a href="professor_announcements.php?prof_id=<?php echo (int)$prof['id']; ?>" style="text-decoration: none; color: inherit; flex: 1; display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <h3 style="padding-right: <?php echo ($prof['unread_announcements'] > 0) ? '65px' : '0'; ?>;">Prof. <?php echo ns_home_escape($prof['full_name']); ?></h3>
                            <p>Click to open updates, assignments, and files from this professor.</p>
                        </div>
                    </a>

                    <div class="prof-footer">
                        <span class="badge"><?php echo (int)$prof['total_announcements']; ?> Announcements</span>
                        <a href="professor_announcements.php?prof_id=<?php echo (int)$prof['id']; ?>" style="font-size: 0.85rem; color: var(--neon); font-weight: 750; text-decoration: none; padding: 6px 10px;"></a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div style="text-align: center; padding: 40px; background: var(--card-bg); border: 1px solid var(--border); border-radius: 16px; color: var(--sub-text);">
            No professors registered in the system yet.
        </div>
    <?php endif; ?>
</main>

<script>
    function toggleMobileSidebar() {
        document.body.classList.toggle('sidebar-open');
    }

    function toggleMoreMenu(event) {
        event.preventDefault();
        const submenu = document.getElementById('moreSubmenu');
        const chevron = document.getElementById('moreChevron');
        if (submenu.style.display === 'none' || submenu.style.display === '') {
            submenu.style.display = 'flex';
            chevron.style.transform = 'rotate(180deg)';
        } else {
            submenu.style.display = 'none';
            chevron.style.transform = 'rotate(0deg)';
        }
    }

    const currentUserId = <?php echo (int)$user_id; ?>;

    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        for (let i = 0; i < rawData.length; i++) outputArray[i] = rawData.charCodeAt(i);
        return outputArray;
    }

    function updateStudentNotificationButton() {
        const button = document.getElementById('studentNotificationBtn');
        const text = document.getElementById('studentNotificationText');
        if (!button || !text) return;
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

    async function enableStudentNotifications() {
        if (!('Notification' in window)) {
            if (window.Swal) Swal.fire({icon:'info',title:'Not Supported',text:'This browser does not support device notifications.'});
            return;
        }
        try {
            const permission = await Notification.requestPermission();
            updateStudentNotificationButton();
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
                if (window.Swal) Swal.fire({icon:'success',title:'Notifications Enabled',text:'You will receive NoteSphere notifications on this device.'});
            } else if (permission === 'denied') {
                if (window.Swal) Swal.fire({icon:'warning',title:'Permission Blocked',text:'Allow notifications for NoteSphere in your browser/device settings.'});
            }
        } catch (error) {
            console.error('Unable to request notification permission:', error);
        }
    }
    updateStudentNotificationButton();

    function toggleGlobalTheme() {
        document.body.classList.toggle('light-mode');
        const isLight = document.body.classList.contains('light-mode');
        localStorage.setItem('notesphere_theme', isLight ? 'light' : 'dark');
        updateThemeButton(isLight);
    }
    
    function updateThemeButton(isLight) {
        document.getElementById('themeIcon').textContent = isLight ? '🌙' : '🌞';
        document.getElementById('themeText').textContent = isLight ? 'Dark Mode' : 'Light Mode';
    }

    if (localStorage.getItem('notesphere_theme') === 'light') {
        document.body.classList.add('light-mode');
        updateThemeButton(true);
    }

    function confirmLogout() {
        Swal.fire({
            title: 'Logout?',
            text: 'Are you sure you want to log out?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#ff4d4d',
            cancelButtonColor: '#39FF14',
            confirmButtonText: 'Yes, logout'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = '../auth/logout.php';
            }
        });
    }

    <?php if ($helpBoardBannedMessage !== ''): ?>
    const helpBoardBanMessage = <?php echo json_encode($helpBoardBannedMessage, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    const helpBoardBanIsLight = document.body.classList.contains('light-mode');
    Swal.fire({
        icon: 'warning',
        title: 'Help Board Access Disabled',
        text: helpBoardBanMessage,
        confirmButtonText: 'OK',
        allowOutsideClick: false,
        allowEscapeKey: false,
        background: helpBoardBanIsLight ? '#ffffff' : '#111111',
        color: helpBoardBanIsLight ? '#17211b' : '#ffffff',
        confirmButtonColor: helpBoardBanIsLight ? '#24a812' : '#39ff14'
    });
    <?php endif; ?>
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

