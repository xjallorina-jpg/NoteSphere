<?php
require_once __DIR__ . '/../includes/db_config.php';

/** @var PDO $pdo */

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$upload_success = '';
$upload_error = '';

// Handle Form Submissions (Quick Upload if needed)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'quick_upload') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $institute = trim($_POST['institute'] ?? 'General');
        $submission_type = $_POST['submission_type'] ?? 'upload';
        
        if (empty($title)) {
            $upload_error = "Note title is required.";
        } else {
            $file_path = '';
            $thumbnail_name = '';

            $cloudFile = ns_cloud_upload_from_request('note_file');
            $cloudThumb = ns_cloud_upload_from_request('thumbnail');

            if ($cloudThumb !== null) {
                $thumbnail_name = $cloudThumb['path'];
            } elseif (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
                $thumb_ext = strtolower(pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION));
                $allowed_img = ['jpg', 'jpeg', 'png', 'webp'];

                if (in_array($thumb_ext, $allowed_img)) {
                    $thumbnail_name = ns_store_uploaded_file($_FILES['thumbnail'], 'notes/thumbnails')['path'];
                }
            }

            if ($submission_type === 'upload') {
                $allowed_files = ['pdf', 'docx', 'doc', 'ppt', 'pptx', 'txt', 'zip'];

                if ($cloudFile !== null) {
                    $file_ext = strtolower(pathinfo($cloudFile['name'], PATHINFO_EXTENSION));

                    if (in_array($file_ext, $allowed_files)) {
                        $file_path = $cloudFile['path'];
                    } else {
                        $upload_error = "Invalid file format. Allowed extensions: PDF, DOCX, PPT, TXT, ZIP.";
                    }
                } elseif (isset($_FILES['note_file']) && $_FILES['note_file']['error'] === UPLOAD_ERR_OK) {
                    $file_name = $_FILES['note_file']['name'];
                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                    if (in_array($file_ext, $allowed_files)) {
                        try {
                            $file_path = ns_store_uploaded_file($_FILES['note_file'], 'notes')['path'];
                        } catch (Throwable $e) {
                            $upload_error = $e->getMessage();
                        }
                    } else {
                        $upload_error = "Invalid file format. Allowed extensions: PDF, DOCX, PPT, TXT, ZIP.";
                    }
                } else {
                    $upload_error = "Please select a file to upload.";
                }
            } else {
                $note_content = $_POST['note_content'] ?? '';
                if (!empty($note_content)) {
                    try {
                        $file_path = ns_store_text_file(
                            $note_content,
                            'written_' . uniqid() . '.txt',
                            'notes'
                        );
                    } catch (Throwable $e) {
                        $upload_error = $e->getMessage();
                    }
                } else {
                    $upload_error = "Note content cannot be empty.";
                }
            }

            if (empty($upload_error)) {
                $status = ($_SESSION['role'] === 'professor') ? 'approved' : 'pending';
                $stmt = $pdo->prepare("INSERT INTO notes (user_id, title, description, institute, file_path, thumbnail, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                if ($stmt->execute([$user_id, $title, $description, $institute, $file_path, $thumbnail_name, $status])) {
                    $upload_success = "Reference material successfully published!";
                } else {
                    $upload_error = "Database error occurred while saving your material.";
                }
            }
        }
    }
}

// Fetch Search & Filter Parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$selected_institute = isset($_GET['institute']) ? trim($_GET['institute']) : '';

$notes = [];

$sql = "SELECT n.*, u.full_name AS uploader, 
     COALESCE(AVG(r.rating), 0) as avg_rating, 
     COUNT(r.rating) as total_ratings 
     FROM notes n 
     JOIN users u ON n.user_id = u.user_id 
     LEFT JOIN note_ratings r ON n.id = r.note_id 
     WHERE n.status = 'approved'";
$params = [];

if (!empty($search)) {
    $sql .= " AND (n.title LIKE ? OR n.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($selected_institute)) {
    $sql .= " AND (n.institute = ? OR n.institute LIKE ?)";
    $params[] = $selected_institute;
    $params[] = "%$selected_institute%";
}

$sql .= " GROUP BY n.id ORDER BY n.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$notes = $stmt->fetchAll();

// Fetch current user's upload history WITH ratings count and average score
$history_stmt = $pdo->prepare("SELECT n.*, 
     COALESCE(AVG(r.rating), 0) as avg_rating, 
     COUNT(r.rating) as total_ratings 
     FROM notes n 
     LEFT JOIN note_ratings r ON n.id = r.note_id 
     WHERE n.user_id = ? 
     GROUP BY n.id 
     ORDER BY n.created_at DESC");
$history_stmt->execute([$user_id]);
$my_uploads = $history_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NoteSphere - View Notes Hub</title>
    
    <script>
        (function() {
            const savedTheme = localStorage.getItem('notesphere_theme');
            if (savedTheme === 'light') {
                document.documentElement.classList.add('light-mode');
            } else {
                document.documentElement.classList.remove('light-mode');
            }
        })();
    </script>

    <style>
        :root {
            --card-bg: #000000;
            --text: #f8fafc;
            --sub-text: #ffffff;
            --sidebar-bg: #050505;
            --border: rgba(255, 255, 255, 0.08);
            --neon: #39ff14;
            --neon-glow: rgba(57, 255, 20, 0.15);
            --sidebar-width: 260px;
        }

        html.light-mode {
            --card-bg: #ffffff;
            --text: #000000;
            --sub-text: #64748b;
            --sidebar-bg: #ffffff;
            --border: rgba(0, 0, 0, 0.1);
            --neon: #16a34a;
            --neon-glow: rgba(22, 163, 74, 0.1);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;
        }

        body {
            background: linear-gradient(rgba(0, 0, 0, 0.5), rgba(0, 0, 0, 0.5)), linear-gradient(135deg,rgba(5,7,13,.55),rgba(5,7,13,.25)),repeating-linear-gradient(0deg,rgba(57,255,20,.04) 0 1px,transparent 1px 34px),repeating-linear-gradient(90deg,rgba(57,255,20,.025) 0 1px,transparent 1px 34px) no-repeat center center fixed;
            background-size: cover;
            color: var(--text);
            display: flex;
            min-height: 100vh;
        }

        .sidebar { 
            width: var(--sidebar-width); 
            min-width: var(--sidebar-width); 
            max-width: var(--sidebar-width); 
            height: 100vh; 
            background: var(--sidebar-bg); 
            border-right: 1px solid var(--border); 
            padding: 35px 20px; 
            position: fixed; 
            top: 0; 
            left: 0; 
            display: flex; 
            flex-direction: column; 
            justify-content: space-between; 
            z-index: 1002; 
            overflow-y: auto; 
        }
        
        .sidebar-top { display: flex; flex-direction: column; }
        .sidebar-brand { display: flex; align-items: center; gap: 12px; margin-bottom: 30px; padding-left: 5px; }
        .sidebar-brand h2 { font-weight: 800; color: var(--neon); font-size: 1.4rem; }
        .sidebar-brand img { width: 35px; height: auto; }
        
        .menu { display: flex; flex-direction: column; gap: 6px; }
        .menu a { display: flex; align-items: center; gap: 14px; padding: 12px 16px; text-decoration: none; color: var(--sub-text); font-weight: 600; font-size: 0.92rem; border-radius: 12px; }
        .menu a svg { width: 20px; height: 20px; stroke: currentColor; stroke-width: 2; fill: none; flex-shrink: 0; }
        .menu a:hover, .menu a.active { background: var(--neon-glow); color: var(--neon); }
        .menu a.active { border: 1px solid var(--neon-glow); }

        .sidebar-footer { display: flex; flex-direction: column; gap: 10px; margin-top: 20px; }
        .theme-toggle-btn { width: 100%; padding: 12px 16px; background: var(--card-bg); border: 1px solid var(--border); color: var(--text); border-radius: 12px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 12px; font-size: 0.9rem; text-decoration: none; }
        .theme-toggle-btn:hover { border-color: var(--neon); color: var(--neon); }

        .mobile-header { display: none; align-items: center; justify-content: space-between; padding: 15px 20px; background: var(--sidebar-bg); border-bottom: 1px solid var(--border); position: fixed; top: 0; left: 0; width: 100%; z-index: 1000; box-sizing: border-box; }
        .sidebar-toggle-btn { background: var(--card-bg); border: 1px solid var(--border); cursor: pointer; display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 10px; color: var(--text); }
        .sidebar-toggle-btn svg { width: 20px; height: 20px; stroke: currentColor; stroke-width: 2; fill: none; }

        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0, 0, 0, 0.6); z-index: 1001; backdrop-filter: blur(2px); }

        main {
            margin-left: var(--sidebar-width);
            flex-grow: 1;
            padding: 30px;
            width: calc(100% - var(--sidebar-width));
            min-height: 100vh;
        }

        .action-buttons-wrapper {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .history-btn, .upload-trigger-btn {
            padding: 10px 18px;
            border-radius: 10px;
            font-size: 0.88rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid var(--border);
            text-decoration: none;
        }

        .history-btn { background: var(--card-bg); color: var(--text); }
        .history-btn:hover { background: var(--neon-glow); border-color: var(--neon); color: var(--neon); }

        .upload-trigger-btn { background: var(--neon); color: #fff; border: none; }
        html.light-mode .upload-trigger-btn { color: #fff; }
        .upload-trigger-btn:hover { opacity: 0.9; }

        .alert-success { background: var(--neon-glow); border: 1px solid var(--neon); color: var(--neon); padding: 12px 15px; border-radius: 10px; margin-bottom: 20px; font-size: 0.9rem; font-weight: 600; }
        .alert-error { background: rgba(255, 77, 77, 0.1); border: 1px solid #ff4d4d; color: #ff4d4d; padding: 12px 15px; border-radius: 10px; margin-bottom: 20px; font-size: 0.9rem; font-weight: 600; }

        .google-hub-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 60vh;
            text-align: center;
        }

        .hub-logo { font-size: 3rem; font-weight: 900; margin-bottom: 25px; letter-spacing: -1px; color: var(--text); }
        .hub-logo span { color: var(--neon); }

        .google-search-bar {
            width: 100%;
            max-width: 650px;
            display: flex;
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 30px;
            padding: 6px 8px 6px 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            margin-bottom: 30px;
            align-items: center;
            gap: 10px;
        }

        .google-search-input { flex-grow: 1; background: transparent; border: none; color: var(--text); font-size: 1rem; outline: none; }
        .google-search-btn { background: var(--neon); color: #fff; border: none; padding: 10px 22px; border-radius: 20px; font-weight: 700; cursor: pointer; font-size: 0.9rem; }
        .google-search-btn:hover { opacity: 0.9; }

        .institute-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            width: 100%;
            max-width: 750px;
        }

        .institute-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px 15px;
            text-align: center;
            text-decoration: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }
        .institute-card:hover, .institute-card.active { border-color: var(--neon); background: var(--neon-glow); transform: translateY(-2px); }
        .institute-icon { font-size: 2.2rem; }
        .institute-name { font-size: 0.85rem; font-weight: 700; color: var(--text); }

        .results-section { display: flex; flex-direction: column; gap: 20px; }
        .results-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 15px; flex-wrap: wrap; gap: 10px; }
        .back-hub-btn { color: var(--neon); text-decoration: none; font-size: 0.9rem; font-weight: 700; }
        .back-hub-btn:hover { text-decoration: underline; }

        .grid-container { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
        .card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 16px; padding: 18px; display: flex; flex-direction: column; justify-content: space-between; gap: 15px; }

        .preview-box { width: 100%; height: 140px; background: rgba(0,0,0,0.05); border-radius: 10px; position: relative; overflow: hidden; display: flex; align-items: center; justify-content: center; margin-bottom: 12px; }
        .file-badge { position: absolute; top: 8px; right: 8px; background: rgba(0,0,0,0.7); color: #fff; padding: 3px 8px; border-radius: 6px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; }
        .card h3 { font-size: 1rem; font-weight: 700; color: var(--text); margin-bottom: 4px; }
        
        .star-rating-box { display: flex; align-items: center; justify-content: space-between; background: var(--card-bg); border: 1px solid var(--border); padding: 8px 10px; border-radius: 8px; margin-top: 10px; }
        .download-btn { display: block; width: 100%; text-align: center; background: var(--neon-glow); color: var(--neon); border: 1px solid var(--neon); padding: 10px; border-radius: 8px; text-decoration: none; font-size: 0.85rem; font-weight: 700; margin-top: 10px; }
        .download-btn:hover { background: var(--neon); color: #fff; }

        /* Modal Styles */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0, 0, 0, 0.7); z-index: 2000; backdrop-filter: blur(3px); align-items: center; justify-content: center; padding: 20px; }
        .modal-container { background: var(--card-bg); border: 1px solid var(--border); border-radius: 20px; width: 100%; max-width: 650px; max-height: 80vh; overflow-y: auto; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 15px; margin-bottom: 20px; }
        .modal-header h3 { color: var(--text); font-size: 1.2rem; }
        .close-modal-btn { background: transparent; border: none; color: var(--sub-text); font-size: 1.4rem; cursor: pointer; }
        .close-modal-btn:hover { color: var(--neon); }

        .history-item { background: rgba(255,255,255,0.02); border: 1px solid var(--border); border-radius: 12px; padding: 15px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; gap: 15px; flex-wrap: wrap; }
        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; border: 1px solid currentColor; display: inline-block; margin-top: 6px; }
        .status-approved { color: var(--neon); background: var(--neon-glow); }
        .status-pending { color: #f1c40f; background: rgba(241, 196, 15, 0.1); }
        .status-rejected { color: #ff4d4d; background: rgba(255, 77, 77, 0.1); }

        @media (max-width: 768px) {
            .mobile-header { display: flex; }
            main { margin-left: 0; padding: 90px 20px 20px 20px; width: 100%; }
            .sidebar { transform: translateX(-100%); transition: transform 0.3s ease; }
            body.sidebar-open .sidebar { transform: translateX(0); }
            body.sidebar-open .sidebar-overlay { display: block; }
            .google-hub-container { min-height: 50vh; padding: 5px; }
            .hub-logo { font-size: 2.2rem; margin-bottom: 15px; }
            .institute-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
        }
    </style>
</head>
<body>

<!-- Mobile Header -->
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
            
            <a href="view_notes.php" class="active">
                <svg viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                <span>View Notes</span>
            </a>

            <a href="help_board.php">
                <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path><circle cx="12" cy="10" r="1"></circle></svg>
                <span>Help Board (Q&A)</span>
            </a>
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
    <div class="action-buttons-wrapper">
        <button type="button" onclick="openHistoryModal()" class="history-btn">
            <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" fill="none" stroke-width="2"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            <span>History</span>
        </button>
        <a href="upload_material.php" class="upload-trigger-btn">
            <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" fill="none" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
            <span>Upload Material</span>
        </a>
    </div>

    <?php if (!empty($upload_success)): ?>
        <div class="alert-success"><?php echo $upload_success; ?></div>
    <?php endif; ?>
    <?php if (!empty($upload_error)): ?>
        <div class="alert-error"><?php echo $upload_error; ?></div>
    <?php endif; ?>

    <?php if (empty($search) && empty($selected_institute)): ?>
        <div class="google-hub-container">
            <h1 class="hub-logo">NoteSphere <span>Hub</span></h1>
            
            <form method="GET" action="" class="google-search-bar">
                <input type="text" name="search" class="google-search-input" placeholder="Search institutional notes, subjects, or modules..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="google-search-btn">Search</button>
            </form>

            <p style="color: var(--sub-text); margin-bottom: 20px; font-weight: 600; font-size: 0.9rem;">Select Institute Repository:</p>
            
            <div class="institute-grid">
                <a href="?institute=ITE" class="institute-card <?php echo ($selected_institute === 'ITE') ? 'active' : ''; ?>">
                    <span class="institute-icon">💻</span>
                    <span class="institute-name">ITE (IT & CS)</span>
                </a>
                <a href="?institute=Education" class="institute-card <?php echo ($selected_institute === 'Education') ? 'active' : ''; ?>">
                    <span class="institute-icon">📚</span>
                    <span class="institute-name">Education</span>
                </a>
                <a href="?institute=Business" class="institute-card <?php echo ($selected_institute === 'Business') ? 'active' : ''; ?>">
                    <span class="institute-icon">📊</span>
                    <span class="institute-name">Business</span>
                </a>
                <a href="?institute=Criminal Justice" class="institute-card <?php echo ($selected_institute === 'Criminal Justice') ? 'active' : ''; ?>">
                    <span class="institute-icon">⚖️</span>
                    <span class="institute-name">Criminal Justice</span>
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="results-section">
            <div class="results-header">
                <div>
                    <h2>Results for: <span style="color: var(--neon);"><?php echo htmlspecialchars(!empty($selected_institute) ? $selected_institute : $search); ?></span></h2>
                </div>
                <a href="view_notes.php" class="back-hub-btn">← Back to Hub</a>
            </div>

            <?php if (count($notes) > 0): ?>
                <div class="grid-container">
                    <?php foreach($notes as $row): 
                        $file_path = htmlspecialchars(ns_media_url($row['file_path']));
                        $ext = strtoupper(pathinfo($row['file_path'], PATHINFO_EXTENSION));
                        $thumbPath = (!empty($row['thumbnail'])) ? ns_media_url($row['thumbnail']) : '../assets/default_thumb.png';
                        $avg_rating = number_format($row['avg_rating'], 1);
                        $total_ratings = $row['total_ratings'];
                        $post_date = date('M d, Y • h:i A', strtotime($row['created_at']));
                    ?>
                    <div class="card">
                        <div>
                            <div class="preview-box">
                                <span class="file-badge"><?php echo $ext; ?></span>
                                <img src="<?= htmlspecialchars($thumbPath) ?>" style="width:100%; height:100%; object-fit:cover;" alt="Thumbnail">
                            </div>
                            <h3><?php echo htmlspecialchars($row['title']); ?></h3>
                            <p style="color:var(--sub-text); font-size: 0.85rem; margin: 10px 0;"><?php echo htmlspecialchars($row['description'] ?? ''); ?></p>
                            <small style="color: var(--neon); display: block; font-weight: 700; margin-bottom: 3px;">Institute: <?php echo htmlspecialchars($row['institute'] ?? 'General'); ?></small>
                            <small style="color: var(--sub-text); display: block; margin-bottom: 3px;">By: <?php echo htmlspecialchars($row['uploader']); ?></small>
                            <small style="color: var(--sub-text); display: block; font-size: 0.78rem; margin-bottom: 8px;">📅 <?php echo $post_date; ?></small>
                            
                            <div class="star-rating-box">
                                <span style="color: #f1c40f; font-weight: 700; font-size: 0.9rem;">⭐ <?php echo $avg_rating; ?> / 5</span>
                                <span style="color: var(--sub-text); font-size: 0.8rem; font-weight: 600;"><?php echo $total_ratings; ?> rating(s)</span>
                            </div>
                        </div>
                        <a href="<?php echo $file_path; ?>" class="download-btn" target="_blank">Download / View Material</a>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="color: var(--sub-text);">No materials found matching your query.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>

<!-- History Modal with Ratings Count -->
<div class="modal-overlay" id="historyModal">
    <div class="modal-container">
        <div class="modal-header">
            <h3>My Upload History</h3>
            <button class="close-modal-btn" onclick="closeHistoryModal()">&times;</button>
        </div>
        <div>
            <?php if (count($my_uploads) > 0): ?>
                <?php foreach ($my_uploads as $item): 
                    $status_class = 'status-pending';
                    $status_text = strtoupper($item['status']);
                    if ($item['status'] === 'approved') $status_class = 'status-approved';
                    if ($item['status'] === 'rejected') $status_class = 'status-rejected';
                    
                    $my_avg_rating = number_format($item['avg_rating'], 1);
                    $my_total_ratings = $item['total_ratings'];
                ?>
                    <div class="history-item">
                        <div style="flex-grow: 1; min-width: 200px;">
                            <strong style="color: var(--text); font-size: 0.95rem; display: block; margin-bottom: 3px;"><?php echo htmlspecialchars($item['title']); ?></strong>
                            <small style="color: var(--sub-text); font-size: 0.78rem; display: block; margin-bottom: 4px;">Institute: <?php echo htmlspecialchars($item['institute']); ?> | 📅 <?php echo date('M d, Y', strtotime($item['created_at'])); ?></small>
                            
                            <!-- Ratings info displayed inside history item -->
                            <div style="display: flex; align-items: center; gap: 10px; margin-top: 6px;">
                                <span style="color: #f1c40f; font-size: 0.8rem; font-weight: 700;">⭐ <?php echo $my_avg_rating; ?> / 5</span>
                                <span style="color: var(--sub-text); font-size: 0.78rem; font-weight: 600;"><?php echo $my_total_ratings; ?> rating(s)</span>
                            </div>
                        </div>
                        <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 6px;">
                            <span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                            <?php if (!empty($item['file_path'])): ?>
                                <a href="<?php echo htmlspecialchars(ns_media_url($item['file_path'])); ?>" target="_blank" style="color: var(--neon); text-decoration: none; font-size: 0.8rem; font-weight: 700;">View File →</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="color: var(--sub-text); text-align: center; padding: 20px;">You haven't uploaded any materials yet.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    function toggleMobileSidebar() {
        document.body.classList.toggle('sidebar-open');
    }

    function toggleGlobalTheme() {
        document.documentElement.classList.toggle('light-mode');
        const isLight = document.documentElement.classList.contains('light-mode');
        localStorage.setItem('notesphere_theme', isLight ? 'light' : 'dark');
    }

    function openHistoryModal() {
        document.getElementById('historyModal').style.display = 'flex';
    }

    function closeHistoryModal() {
        document.getElementById('historyModal').style.display = 'none';
    }

    window.onclick = function(event) {
        const modal = document.getElementById('historyModal');
        if (event.target === modal) {
            closeHistoryModal();
        }
    }
</script>
</body>
</html>