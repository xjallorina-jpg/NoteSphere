<?php
session_start();
require_once('../includes/db_config.php');
/** @var PDO $conn */

// Ensure professor is logged in
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'professor') {
    header("Location: ../auth/login.php"); 
    exit();
}

$prof_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 1;
$current_page = basename($_SERVER['PHP_SELF']);

$success_msg = ''; 
$error_msg = '';

// Fetch active semester and academic year from system settings
$active_sem = '1st Semester';
$active_year = '2026-2027';
try {
    $stmt_settings = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('active_semester', 'active_academic_year')");
    while ($row = $stmt_settings->fetch(PDO::FETCH_ASSOC)) {
        if ($row['setting_key'] === 'active_semester') {
            $active_sem = $row['setting_value'];
        }
        if ($row['setting_key'] === 'active_academic_year') {
            $active_year = $row['setting_value'];
        }
    }
} catch (Exception $e) {
    // Fallback to default
}

// Handle Add / Edit Announcement
if (isset($_POST['save_announcement'])) {
    $edit_id = $_POST['edit_id'] ?? '';
    $title = trim($_POST['title']);
    $subject = trim($_POST['subject']);
    $year_level = trim($_POST['year_level']);
    $section = trim($_POST['section']);
    $content = trim($_POST['content']);
    $category = trim($_POST['category']);
    $meet_link = trim($_POST['meet_link']);
    $quiz_link = trim($_POST['quiz_link']);
    $deadline = !empty($_POST['deadline']) ? date('Y-m-d H:i:s', strtotime($_POST['deadline'])) : null;

    if (!empty($title) && !empty($content)) {
        try {
            if (!empty($edit_id)) {
                // Update existing
                $stmt = $conn->prepare("UPDATE announcements SET title = ?, subject = ?, year_level = ?, section = ?, message = ?, category = ?, meet_link = ?, quiz_link = ?, deadline = ? WHERE id = ? AND professor_id = ?");
                $stmt->execute([$title, $subject, $year_level, $section, $content, $category, $meet_link, $quiz_link, $deadline, $edit_id, $prof_id]);
                $success_msg = "Successfully updated post!";
            } else {
                // Insert new
                $stmt = $conn->prepare("INSERT INTO announcements (professor_id, title, subject, year_level, section, message, category, meet_link, quiz_link, deadline, semester, academic_year, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())");
                $stmt->execute([$prof_id, $title, $subject, $year_level, $section, $content, $category, $meet_link, $quiz_link, $deadline, $active_sem, $active_year]);
                $success_msg = "Successfully posted $category for $active_sem ($active_year)!";
            }
        } catch (Exception $e) {
            $error_msg = "Error: " . $e->getMessage();
        }
    } else {
        $error_msg = "Please fill in both the Title and Message fields!";
    }
}

// Delete announcement
if (isset($_GET['delete'])) {
    $conn->prepare("DELETE FROM announcements WHERE id = ? AND professor_id = ?")->execute([$_GET['delete'], $prof_id]);
    header("Location: " . $current_page); 
    exit();
}

// Fetch posts made by this professor
$my_announcements = $conn->prepare("SELECT * FROM announcements WHERE professor_id = ? ORDER BY created_at DESC");
$my_announcements->execute([$prof_id]);
$announcements = $my_announcements->fetchAll(PDO::FETCH_ASSOC);

// If editing, fetch specific post data to load into form
$edit_data = null;
if (isset($_GET['edit'])) {
    $stmt_edit = $conn->prepare("SELECT * FROM announcements WHERE id = ? AND professor_id = ?");
    $stmt_edit->execute([$_GET['edit'], $prof_id]);
    $edit_data = $stmt_edit->fetch(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Post Announcement & LMS Tools | NoteSphere</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#0f172a">
    
    <style>
        :root {
            --neon: #39FF14;
            --neon-glow: rgba(57, 255, 20, 0.12);
            --card-bg: #0c0c0c;
            --text: #f8fafc;
            --sub-text: #94a3b8;
            --border: #1f293d;
            --sidebar-width: 260px;
        }
        body.light-mode {
            --card-bg: #ffffff;
            --text: #000000;
            --sidebar-bg: #ffffff;
            --border: #e2e8f0;
            --sub-text: #555555;
            --neon: #2ecc71;
            --neon-glow: rgba(46, 204, 113, 0.12);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
        
        html {
            scroll-behavior: smooth;
        }

        html, body {
            width: 100%;
            max-width: 100vw;
            overflow-x: hidden;
        }

        body { 
            position: relative;
            color: var(--text); 
            display: flex; 
            min-height: 100vh; 
            transition: color 0.3s ease; 
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            z-index: -1;
            background: linear-gradient(rgba(11, 11, 11, 0.85), rgba(11, 11, 11, 0.92)), linear-gradient(135deg,rgba(5,7,13,.55),rgba(5,7,13,.25)),repeating-linear-gradient(0deg,rgba(57,255,20,.04) 0 1px,transparent 1px 34px),repeating-linear-gradient(90deg,rgba(57,255,20,.025) 0 1px,transparent 1px 34px) no-repeat center center;
            background-size: cover;
            pointer-events: none;
        }

        body.light-mode::before {
            background: linear-gradient(135deg,rgba(5,7,13,.55),rgba(5,7,13,.25)),repeating-linear-gradient(0deg,rgba(57,255,20,.04) 0 1px,transparent 1px 34px),repeating-linear-gradient(90deg,rgba(57,255,20,.025) 0 1px,transparent 1px 34px) no-repeat center center;
            background-size: cover;
        }

        /* Sidebar Styles */
        .sidebar { width: var(--sidebar-width); height: 100vh; background: var(--card-bg); border-right: 1px solid var(--border); padding: 35px 20px; position: fixed; top: 0; left: 0; display: flex; flex-direction: column; justify-content: space-between; z-index: 1002; transition: transform 0.3s ease, background 0.3s ease, border-color 0.3s ease; overflow-y: auto; }
        .sidebar-top { display: flex; flex-direction: column; }
        .sidebar-brand { display: flex; align-items: center; gap: 12px; margin-bottom: 30px; padding: 0 5px; background: transparent !important; border: none !important; box-shadow: none !important; }
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
        .mobile-header { display: none; align-items: center; justify-content: space-between; padding: 15px 20px; background: var(--card-bg); border-bottom: 1px solid var(--border); position: fixed; top: 0; left: 0; width: 100%; z-index: 1000; box-sizing: border-box; }
        .sidebar-toggle-btn { background: var(--card-bg); border: 1px solid var(--border); cursor: pointer; display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 10px; color: var(--text); }
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

        .grid-layout { display: grid; grid-template-columns: 1.2fr 1fr; gap: 30px; }
        .card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 20px; padding: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); box-sizing: border-box; transition: background-color 0.3s ease, border-color 0.3s ease; }
        
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; color: var(--text); font-weight: 600; margin-bottom: 6px; font-size: 0.85rem; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 12px 15px; border-radius: 12px; border: 1px solid var(--border); background: var(--card-bg); color: var(--text); outline: none; font-size: 0.88rem; }
        .form-group textarea { resize: vertical; height: 110px; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: var(--neon); box-shadow: 0 0 10px var(--neon-glow); }
        
        .btn-submit { width: 100%; background: var(--neon); color: #0c0c0c; border: none; padding: 14px; border-radius: 12px; font-weight: 800; cursor: pointer; transition: 0.3s; margin-top: 5px; }
        .btn-submit:hover { opacity: 0.9; transform: translateY(-1px); box-shadow: 0 4px 12px var(--neon-glow); }
        
        .btn-cancel-edit { display: inline-block; text-align: center; width: 100%; background: transparent; color: var(--sub-text); border: 1px solid var(--border); padding: 10px; border-radius: 12px; font-weight: 600; cursor: pointer; margin-top: 8px; text-decoration: none; font-size: 0.85rem; }
        .btn-cancel-edit:hover { border-color: var(--text); color: var(--text); }

        .alert-success { background: rgba(46, 204, 113, 0.1); border: 1px solid #2ecc71; color: #2ecc71; padding: 12px; border-radius: 10px; margin-bottom: 20px; font-size: 0.9rem; font-weight: 600; }
        .alert-error { background: rgba(255, 77, 77, 0.1); border: 1px solid #ff4d4d; color: #ff4d4d; padding: 12px; border-radius: 10px; margin-bottom: 20px; font-size: 0.9rem; font-weight: 600; }

        .ann-list-item { background: var(--card-bg); border: 1px solid var(--border); padding: 18px; border-radius: 14px; margin-bottom: 15px; }
        .ann-title { font-weight: 700; font-size: 1.05rem; color: var(--text); margin-bottom: 4px; }
        .ann-meta { font-size: 0.78rem; color: var(--neon); font-weight: 600; margin-bottom: 8px; }
        .lms-badge { display: inline-block; background: rgba(52, 152, 219, 0.15); color: #3498db; border: 1px solid rgba(52, 152, 219, 0.3); padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; margin-top: 6px; margin-right: 5px; text-decoration: none; }

        /* Custom Confirmation Modal Card */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0, 0, 0, 0.7); z-index: 2000; backdrop-filter: blur(4px); align-items: center; justify-content: center; }
        .confirm-card { background: var(--card-bg); border: 1px solid var(--border); width: 100%; max-width: 400px; padding: 30px; border-radius: 20px; text-align: center; box-shadow: 0 15px 40px rgba(0,0,0,0.5); }
        .confirm-card h3 { font-size: 1.25rem; font-weight: 800; color: var(--text); margin-bottom: 10px; }
        .confirm-card p { font-size: 0.9rem; color: var(--sub-text); margin-bottom: 25px; line-height: 1.4; }
        .confirm-actions { display: flex; gap: 12px; justify-content: center; }
        .btn-confirm-yes { flex: 1; background: #2ecc71; color: #fff; border: none; padding: 12px; border-radius: 12px; font-weight: 700; cursor: pointer; font-size: 0.9rem; display: flex; align-items: center; justify-content: center; gap: 6px; transition: 0.2s; }
        .btn-confirm-yes:hover { opacity: 0.9; }
        .btn-confirm-no { flex: 1; background: #ff4d4d; color: #fff; border: none; padding: 12px; border-radius: 12px; font-weight: 700; cursor: pointer; font-size: 0.9rem; display: flex; align-items: center; justify-content: center; gap: 6px; transition: 0.2s; }
        .btn-confirm-no:hover { opacity: 0.9; }

        @media (max-width: 900px) {
            .mobile-header { display: flex; }
            main { margin-left: 0; padding: 90px 16px 20px 16px; max-width: 100vw; }
            .sidebar { transform: translateX(-100%); }
            body.sidebar-open .sidebar { transform: translateX(0); }
            body.sidebar-open .sidebar-overlay { display: block; }
            .grid-layout { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<header class="mobile-header">
    <div style="display: flex; align-items: center; gap: 10px;">
        <img src="../assets/cdmlogo.png" alt="Logo" style="width: 30px; height: auto;">
        <h2 style="font-size: 1.2rem; color: var(--neon);">NoteSphere</h2>
    </div>
    <button class="sidebar-toggle-btn" onclick="toggleMobileSidebar()" aria-label="Toggle Sidebar">
        <svg viewBox="0 0 24 24"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
    </button>
</header>

<div class="sidebar-overlay" onclick="toggleMobileSidebar()"></div>

<!-- Custom Confirmation Modal Card -->
<div class="modal-overlay" id="confirmModal">
    <div class="confirm-card">
        <h3 id="modalTitle">Confirm Action</h3>
        <p id="modalMessage">Are you sure you want to proceed with this action?</p>
        <div class="confirm-actions">
            <button type="button" class="btn-confirm-yes" id="modalYesBtn">
                <svg viewBox="0 0 24 24" style="width: 18px; height: 18px; stroke: currentColor; stroke-width: 3; fill: none;"><polyline points="20 6 9 17 4 12"></polyline></svg>
                Okay
            </button>
            <button type="button" class="btn-confirm-no" id="modalNoBtn">
                <svg viewBox="0 0 24 24" style="width: 18px; height: 18px; stroke: currentColor; stroke-width: 3; fill: none;"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                Cancel
            </button>
        </div>
    </div>
</div>

<nav class="sidebar" id="sidebar">
    <div class="sidebar-top">
        <div class="sidebar-brand">
            <img src="../assets/cdmlogo.png" alt="Logo">
            <h2>NoteSphere</h2>
        </div>

        <div class="menu">
            <a href="professor_dashboard.php">
                <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                <span>Dashboard</span>
            </a>
            
            <a href="professor_uploads.php">
                <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>
                <span>Upload Material</span>
            </a>

            <a href="professor_announcements.php" class="active">
                <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                <span>Add Announcement</span>
            </a>

            <a href="professor_manage_notes.php">
                <svg viewBox="0 0 24 24"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-2.5-2.5Z"/><path d="M6 6h10M6 10h10"/></svg>
                <span>Manage Notes</span>
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
                    <a href="help_board.php">
                        <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path><circle cx="12" cy="10" r="1"></circle></svg>
                        <span>Help Board (Q&A)</span>
                    </a>
                    <a href="professor_messages.php">
                        <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                        <span>Consultation</span>
                    </a>
                    <a href="professor_submissions.php">
                        <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/></svg>
                        <span>Submissions</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="sidebar-footer">
        <button onclick="toggleGlobalTheme()" class="theme-toggle-btn" id="globalThemeBtn">
            <span id="themeIcon">🌞</span> <span id="themeText">Light Mode</span>
        </button>
        <a href="../auth/logout.php" class="theme-toggle-btn" style="color: #ff4d4d; border-color: rgba(255, 77, 77, 0.3);">
            <svg viewBox="0 0 24 24" style="width: 18px; height: 18px; stroke: currentColor; stroke-width: 2; fill: none;"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline></svg>
            <span>Logout</span>
        </a>
    </div>
</nav>

<main>
    <h1 style="font-size: 1.8rem; font-weight: 800; margin-bottom: 25px; letter-spacing: -0.5px;">Broadcast Announcement & LMS Tools</h1>

    <?php if(!empty($success_msg)): ?><div class="alert-success"><?= $success_msg ?></div><?php endif; ?>
    <?php if(!empty($error_msg)): ?><div class="alert-error"><?= $error_msg ?></div><?php endif; ?>

    <div class="grid-layout">
        <!-- Form Card (Supports Add & Edit) -->
        <div class="card" id="editFormCard">
            <h3 style="margin-bottom: 10px; font-size: 1.15rem; color: var(--neon);">
                <?= $edit_data ? '✏️ Edit Post / Task' : 'Create Post / Task' ?>
            </h3>
            <p style="font-size: 0.8rem; color: var(--sub-text); margin-bottom: 18px;">Automatically saved to: <strong style="color: var(--text);"><?= htmlspecialchars($active_sem) ?> (<?= htmlspecialchars($active_year) ?>)</strong></p>
            
            <form method="POST" action="<?= htmlspecialchars($current_page) ?>" id="announcementForm">
                <input type="hidden" name="edit_id" value="<?= $edit_data['id'] ?? '' ?>">

                <div class="form-group">
                    <label>Post Category</label>
                    <select name="category" id="categorySelect" onchange="toggleFields()" required>
                        <option value="Announcement" <?= ($edit_data && $edit_data['category'] == 'Announcement') ? 'selected' : '' ?>>📢 General Announcement</option>
                        <option value="Activity" <?= ($edit_data && $edit_data['category'] == 'Activity') ? 'selected' : '' ?>>📁 Activity / Assignment</option>
                        <option value="Quiz" <?= ($edit_data && $edit_data['category'] == 'Quiz') ? 'selected' : '' ?>>📝 Quiz (Google Form)</option>
                        <option value="Exam" <?= ($edit_data && $edit_data['category'] == 'Exam') ? 'selected' : '' ?>>📋 Exam (Google Form)</option>
                        <option value="Online Class" <?= ($edit_data && $edit_data['category'] == 'Online Class') ? 'selected' : '' ?>>📹 Online Class (Google Meet)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Title</label>
                    <input type="text" name="title" placeholder="e.g. Midterm Examination for IT 311" value="<?= htmlspecialchars($edit_data['title'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label>Subject / Course Code</label>
                    <input type="text" name="subject" placeholder="e.g. IT 311" value="<?= htmlspecialchars($edit_data['subject'] ?? '') ?>">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label>Target Year Level</label>
                        <select name="year_level">
                            <?php $yl = $edit_data['year_level'] ?? 'All Years'; ?>
                            <option value="All Years" <?= $yl == 'All Years' ? 'selected' : '' ?>>All Years</option>
                            <option value="1st Year" <?= $yl == '1st Year' ? 'selected' : '' ?>>1st Year</option>
                            <option value="2nd Year" <?= $yl == '2nd Year' ? 'selected' : '' ?>>2nd Year</option>
                            <option value="3rd Year" <?= $yl == '3rd Year' ? 'selected' : '' ?>>3rd Year</option>
                            <option value="4th Year" <?= $yl == '4th Year' ? 'selected' : '' ?>>4th Year</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Target Section</label>
                        <input type="text" name="section" placeholder="e.g. 3A or All" value="<?= htmlspecialchars($edit_data['section'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Message / Instructions</label>
                    <textarea name="content" placeholder="Type instructions or details here..." required><?= htmlspecialchars($edit_data['message'] ?? '') ?></textarea>
                </div>

                <!-- Conditional Fields -->
                <div class="form-group" id="meetField" style="display: none;">
                    <label>📹 Google Meet Link</label>
                    <input type="url" name="meet_link" placeholder="https://meet.google.com/xxx-xxxx-xxx" value="<?= htmlspecialchars($edit_data['meet_link'] ?? '') ?>">
                </div>

                <div class="form-group" id="quizField" style="display: none;">
                    <label>📝 Google Form Quiz/Exam Link</label>
                    <input type="url" name="quiz_link" placeholder="https://forms.gle/xxxxxxxx" value="<?= htmlspecialchars($edit_data['quiz_link'] ?? '') ?>">
                </div>

                <div class="form-group" id="deadlineField" style="display: none;">
                    <label>⏰ Submission Deadline</label>
                    <?php 
                        $dl_val = '';
                        if(!empty($edit_data['deadline'])) {
                            $dl_val = date('Y-m-d\TH:i', strtotime($edit_data['deadline']));
                        }
                    ?>
                    <input type="datetime-local" name="deadline" value="<?= $dl_val ?>">
                </div>

                <button type="submit" name="save_announcement" class="btn-submit"><?= $edit_data ? 'Save Changes' : 'Publish Post' ?></button>
                <?php if($edit_data): ?>
                    <a href="<?= htmlspecialchars($current_page) ?>" class="btn-cancel-edit">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- History Card -->
        <div class="card">
            <h3 style="margin-bottom: 18px; font-size: 1.15rem; color: var(--neon);">Your Post History</h3>
            <div style="max-height: 650px; overflow-y: auto; padding-right: 5px;">
                <?php if(empty($announcements)): ?>
                    <p style="color: var(--sub-text); text-align: center; padding: 40px;">No posts created yet.</p>
                <?php else: ?>
                    <?php foreach($announcements as $a): ?>
                        <div class="ann-list-item">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                <span style="font-size: 0.75rem; color: var(--neon); font-weight: 700;">[<?= htmlspecialchars($a['category']) ?>]</span>
                                <?php if(!empty($a['semester']) && !empty($a['academic_year'])): ?>
                                    <span style="font-size: 0.68rem; background: var(--neon-glow); color: var(--neon); padding: 2px 6px; border-radius: 4px;"><?= htmlspecialchars($a['semester']) ?> (<?= htmlspecialchars($a['academic_year']) ?>)</span>
                                <?php endif; ?>
                            </div>
                            <div class="ann-title"><?= htmlspecialchars($a['title']) ?></div>
                            <div class="ann-meta">📚 <?= htmlspecialchars($a['subject']) ?> | 🎯 <?= htmlspecialchars($a['year_level']) ?>-<?= htmlspecialchars($a['section']) ?></div>
                            <div style="font-size: 0.85rem; color: var(--sub-text); margin-bottom: 8px;"><?= nl2br(htmlspecialchars($a['message'])) ?></div>
                            
                            <?php if(!empty($a['meet_link'])): ?>
                                <a href="<?= htmlspecialchars($a['meet_link']) ?>" target="_blank" class="lms-badge">📹 Join Meet</a>
                            <?php endif; ?>
                            <?php if(!empty($a['quiz_link'])): ?>
                                <a href="<?= htmlspecialchars($a['quiz_link']) ?>" target="_blank" class="lms-badge">📝 Open Form</a>
                            <?php endif; ?>
                            <?php if(!empty($a['deadline'])): ?>
                                <div style="font-size: 0.72rem; color: #ff4d4d; margin-top: 6px; font-weight: 700;">⏳ Deadline: <?= $a['deadline'] ?></div>
                            <?php endif; ?>

                            <div style="margin-top: 10px; display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border); padding-top: 8px;">
                                <span style="font-size: 0.7rem; color: var(--sub-text);"><?= $a['created_at'] ?></span>
                                <div style="display: flex; gap: 12px;">
                                    <a href="<?= htmlspecialchars($current_page) ?>?edit=<?= $a['id'] ?>" style="color: #3498db; text-decoration: none; font-weight: 700; font-size: 0.75rem;">EDIT</a>
                                    <button type="button" onclick="confirmDelete(<?= $a['id'] ?>, '<?= htmlspecialchars(addslashes($a['title'])) ?>')" style="background: none; border: none; color: #ff4d4d; font-weight: 700; font-size: 0.75rem; cursor: pointer;">DELETE</button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
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

    function toggleFields() {
        let cat = document.getElementById('categorySelect').value;
        let meetField = document.getElementById('meetField');
        let quizField = document.getElementById('quizField');
        let deadlineField = document.getElementById('deadlineField');

        meetField.style.display = 'none';
        quizField.style.display = 'none';
        deadlineField.style.display = 'none';

        if (cat === 'Online Class') {
            meetField.style.display = 'block';
        } else if (cat === 'Quiz' || cat === 'Exam' || cat === 'Activity') {
            quizField.style.display = 'block';
            deadlineField.style.display = 'block';
        }
    }
    
    toggleFields();

    // Auto scroll to form when editing
    <?php if($edit_data): ?>
    window.addEventListener('DOMContentLoaded', () => {
        const formCard = document.getElementById('editFormCard');
        if(formCard) {
            formCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
    <?php endif; ?>

    // Custom Confirmation Modal Logic (Check / X Buttons)
    function confirmDelete(id, title) {
        const modal = document.getElementById('confirmModal');
        const modalTitle = document.getElementById('modalTitle');
        const modalMsg = document.getElementById('modalMessage');
        const yesBtn = document.getElementById('modalYesBtn');
        const noBtn = document.getElementById('modalNoBtn');

        modalTitle.textContent = "Delete Post?";
        modalMsg.innerHTML = `Are you sure you want to delete <strong style="color:var(--text);">&ldquo;${title}&rdquo;</strong>? This action cannot be undone.`;
        modal.style.display = 'flex';

        yesBtn.onclick = function() {
            window.location.href = `<?= htmlspecialchars($current_page) ?>?delete=${id}`;
        };

        noBtn.onclick = function() {
            modal.style.display = 'none';
        };

        window.onclick = function(event) {
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        };
    }
</script>
</body>
</html>