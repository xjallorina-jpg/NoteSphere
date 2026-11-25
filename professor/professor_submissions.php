<?php
session_start();
include('../includes/db_config.php'); 
/** @var PDO $conn */

// 1. I-check kung naka-login at kung professor ba ang user
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'professor') {
    header("Location: ../auth/login.php");
    exit();
}

$prof_id = $_SESSION['user_id'];

// 2. Kung nag-submit si prof ng grade at feedback
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_grade'])) {
    $submission_id = $_POST['submission_id'];
    $grade = trim($_POST['grade']);
    $feedback = trim($_POST['feedback']);

    $update_sql = "UPDATE announcement_submissions SET grade = ?, feedback = ?, status = 'Checked' WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    
    if ($update_stmt->execute([$grade, $feedback, $submission_id])) {
        echo "
        <!DOCTYPE html>
        <html lang='en'>
        <head>
            <meta charset='UTF-8'>
            <title>Success | NoteSphere</title>
            <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        </head>
        <body style='background: #050505;'>
            <script>
                Swal.fire({
                    title: 'TAGUMPAY!',
                    text: 'Na-save na ang grado at feedback para sa estudyante.',
                    icon: 'success',
                    confirmButtonColor: '#FFD700',
                    background: '#0a0a0a',
                    color: '#f0f0f0'
                }).then(() => {
                    window.location.href = 'professor_submissions.php';
                });
            </script>
</body>
        </html>";
        exit();
    }
}

// 3. Kunin ang submissions at i-base ang section sa mismong ANNOUNCEMENT table columns (course, year_level, section)
$sql = "SELECT s.*, a.title as ann_title, a.target_course as ann_course, a.year_level as ann_year, a.section as ann_section, 
               u.full_name as student_name, u.course as stud_course, u.year_level as stud_year, u.section as stud_section 
        FROM announcement_submissions s 
        JOIN announcements a ON s.announcement_id = a.id 
        JOIN users u ON s.student_id = u.user_id 
        WHERE a.professor_id = ? 
        ORDER BY a.target_course, a.year_level, a.section, s.submitted_at DESC";

$stmt = $conn->prepare($sql);
$stmt->execute([$prof_id]);
$all_submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// I-group ang mga submissions ayon sa target section ng ANNOUNCEMENT
$grouped_submissions = [];
$total_submissions = count($all_submissions);
$checked_count = 0;

foreach ($all_submissions as $sub) {
    if ($sub['status'] === 'Checked') {
        $checked_count++;
    }
    
    $course = $sub['ann_course'] ?? 'N/A';
    $year = $sub['ann_year'] ?? 'N/A';
    $section = $sub['ann_section'] ?? 'N/A';
    
    $section_key = "{$course}_{$year}_{$section}"; 
    $section_label = "{$course} — Year {$year} — Section {$section}";
    
    $grouped_submissions[$section_key]['label'] = $section_label;
    $grouped_submissions[$section_key]['items'][] = $sub;
}
$total_sections = count($grouped_submissions);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Submissions | NoteSphere</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
   <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#0f172a">
   <style>
        :root { 
            --bg: #050505; 
            --panel: #0a0a0a; 
            --gold: #FFD700; 
            --text: #f0f0f0; 
            --danger: #ff3e3e; 
            --sub-text: #888;
            --border-color: #1a1a1a;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background: var(--bg); color: var(--text); display: flex; min-height: 100vh; }
        
        /* Sidebar Styling */
        .sidebar { width: 280px; background: #000; padding: 40px; border-right: 1px solid var(--border-color); display: flex; flex-direction: column; position: fixed; height: 100vh; z-index: 10; }
        .logo-area { display: flex; align-items: center; gap: 15px; margin-bottom: 60px; }
        .logo-area img { width: 45px; height: 45px; border-radius: 10px; }
        
        .btn-main { background: linear-gradient(135deg, #FFD700, #b89c00); color: #000; padding: 15px; border-radius: 12px; font-weight: 800; text-decoration: none; text-align: center; margin-bottom: 20px; transition: 0.3s; display: block; font-size: 0.85rem; }
        .btn-main:hover { transform: scale(1.02); filter: brightness(1.1); }
        .btn-logout { background: transparent; border: 1px solid #333; color: var(--sub-text); padding: 12px; border-radius: 12px; text-decoration: none; text-align: center; font-size: 0.8rem; transition: 0.3s; display: block; }
        .btn-logout:hover { border-color: var(--danger); color: var(--danger); }
        
        /* Main Workspace */
        .main { flex: 1; padding: 50px; margin-left: 280px; width: calc(100% - 280px); }
        
        .header-flex { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 30px; }
        h1 { font-size: 1.8rem; margin-bottom: 6px; font-weight: 800; letter-spacing: -0.5px; }
        .subtitle { color: var(--sub-text); font-size: 0.9rem; }

        /* Mini Stats Overview */
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: var(--panel); border: 1px solid var(--border-color); border-radius: 16px; padding: 20px 25px; }
        .stat-title { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1.5px; color: var(--sub-text); margin-bottom: 8px; font-weight: 700; }
        .stat-value { font-size: 1.5rem; font-weight: 800; color: var(--gold); }

        /* Professional Two-Column Workspace */
        .workspace {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 30px;
            align-items: start;
        }

        /* Section Selector Pane (Left) */
        .section-nav-pane {
            background: var(--panel);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            position: sticky;
            top: 40px;
        }
        .section-nav-title {
            font-size: 0.75rem;
            letter-spacing: 2px;
            color: var(--gold);
            text-transform: uppercase;
            margin-bottom: 15px;
            padding-left: 10px;
            font-weight: 700;
        }
        .section-card-btn {
            background: #0d0d0d;
            border: 1px solid var(--border-color);
            padding: 15px 18px;
            border-radius: 14px;
            width: 100%;
            text-align: left;
            color: var(--text);
            cursor: pointer;
            transition: 0.3s;
            margin-bottom: 12px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .section-card-btn:hover { border-color: #444; background: #121212; }
        .section-card-btn.active {
            border-color: var(--gold);
            background: rgba(255, 215, 0, 0.05);
            box-shadow: 0 4px 20px rgba(255, 215, 0, 0.08);
        }
        .sec-name { font-weight: 700; font-size: 0.85rem; color: var(--text); line-height: 1.4; }
        .sec-count { font-size: 0.75rem; color: var(--sub-text); font-weight: 500; }

        /* Content Display Pane (Right) */
        .content-pane {
            background: var(--panel);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 35px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            min-height: 550px;
        }
        .section-content-panel { display: none; }
        .section-content-panel.active { display: block; }

        .pane-header {
            font-size: 1.15rem;
            color: var(--gold);
            font-weight: 800;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .search-filter-box {
            width: 100%;
            padding: 12px 18px;
            background: #111;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            color: var(--text);
            font-size: 0.85rem;
            margin-bottom: 25px;
            outline: none;
            transition: 0.3s;
        }
        .search-filter-box:focus { border-color: var(--gold); }

        /* Student Item Card */
        .student-item-card { 
            background: #0d0d0d; 
            border: 1px solid var(--border-color); 
            border-radius: 16px; 
            padding: 25px; 
            margin-bottom: 20px; 
            transition: 0.3s;
        }
        .student-item-card:hover { border-color: #333; }

        .grade-form input, .grade-form textarea {
            width: 100%;
            padding: 12px 15px;
            background: #141414;
            border: 1px solid var(--border-color);
            color: var(--text);
            border-radius: 10px;
            margin-top: 6px;
            margin-bottom: 15px;
            font-size: 0.9rem;
            transition: 0.3s;
        }
        .grade-form input:focus, .grade-form textarea:focus { border-color: var(--gold); outline: none; }

        .grade-btn {
            background: var(--gold);
            color: #000;
            border: none;
            padding: 12px 22px;
            font-weight: 800;
            border-radius: 10px;
            cursor: pointer;
            transition: 0.3s;
            font-size: 0.85rem;
        }
        .grade-btn:hover { filter: brightness(1.1); transform: translateY(-1px); }

        .badge-checked { background: rgba(73, 184, 0, 0.1); color: #49b800; border: 1px solid rgba(73, 184, 0, 0.2); padding: 5px 12px; border-radius: 20px; font-size: 0.7rem; font-weight: 700; }
        .badge-pending { background: rgba(255, 193, 7, 0.1); color: #ffc107; border: 1px solid rgba(255, 193, 7, 0.2); padding: 5px 12px; border-radius: 20px; font-size: 0.7rem; font-weight: 700; }
        
        .download-btn {
            background: #141414;
            color: var(--gold);
            border: 1px solid #333;
            padding: 10px 16px;
            border-radius: 10px;
            font-size: 0.8rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            transition: 0.3s;
        }
        .download-btn:hover { border-color: var(--gold); background: rgba(255, 215, 0, 0.05); }

        .placeholder-text {
            color: var(--sub-text);
            text-align: center;
            padding: 100px 20px;
            font-size: 0.9rem;
        }

        @media (max-width: 900px) {
            body { display: block; }
            .sidebar { position: static; width: 100%; height: auto; padding: 20px; border-right: 0; border-bottom: 1px solid var(--border-color); }
            .logo-area { margin-bottom: 20px; }
            .main { margin-left: 0; width: 100%; padding: 24px 16px; }
            .stats-grid { grid-template-columns: 1fr; }
            .workspace { grid-template-columns: 1fr; gap: 20px; }
            .section-nav-pane { position: static; }
            .header-flex { flex-direction: column; align-items: flex-start; gap: 12px; }
            .content-pane { padding: 20px; min-height: 0; }
        }
    </style>
</head>
<body>
    <nav class="sidebar">
        <div class="logo-area">
            <img src="../assets/cdmlogo.png" alt="CDM">
            <h2 style="color:var(--gold); font-size: 1.2rem;">NOTESPHERE</h2>
        </div>
        <a href="professor_dashboard.php" class="btn-main" style="background: #111; color: #fff; border: 1px solid #333;">DASHBOARD</a>
        <a href="professor_uploads.php" class="btn-main" style="background: #111; color: #fff; border: 1px solid #333;">+ UPLOAD MATERIAL</a>
        <a href="add_announcement.php" class="btn-main" style="background: #111; color: #fff; border: 1px solid #333;">+ ADD ANNOUNCEMENT</a>
        <a href="professor_manage_notes.php" class="btn-main" style="background: #111; color: #fff; border: 1px solid #333;">MANAGE NOTES</a>
        <a href="professor_submissions.php" class="btn-main">SUBMISSIONS</a>
        <a href="../auth/logout.php" class="btn-logout" style="margin-top: auto;">LOGOUT</a>
    </nav>

    <main class="main">
        <div class="header-flex">
            <div>
                <h1>Student Submissions</h1>
                <p class="subtitle">Pamahalaan, suriin, at bigyan ng marka ang mga ipinasang gawain base sa target na seksyon ng announcement.</p>
            </div>
        </div>

        <!-- Mini Stats Bar -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-title">Kabuuang Pagpasa</div>
                <div class="stat-value"><?= $total_submissions; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-title">Target na Seksyon</div>
                <div class="stat-value"><?= $total_sections; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-title">Nasuri / Checked</div>
                <div class="stat-value" style="color: #49b800;"><?= $checked_count; ?> / <?= $total_submissions; ?></div>
            </div>
        </div>

        <?php if (!empty($grouped_submissions)): ?>
            <div class="workspace">
                <!-- Left Pane: Section Selection Switcher -->
                <div class="section-nav-pane">
                    <div class="section-nav-title">Mga Seksyon (Announcement)</div>
                    <?php 
                    $first = true;
                    foreach ($grouped_submissions as $key => $group): 
                    ?>
                        <button class="section-card-btn <?= $first ? 'active' : ''; ?>" onclick="switchSection('<?= $key; ?>', this)">
                            <span class="sec-name">📁 <?= htmlspecialchars($group['label']); ?></span>
                            <span class="sec-count"><?= count($group['items']); ?> ang nagpasa</span>
                        </button>
                    <?php 
                        $first = false;
                    endforeach; 
                    ?>
                </div>

                <!-- Right Pane: Submissions Display Content -->
                <div class="content-pane">
                    <?php 
                    $first_panel = true;
                    foreach ($grouped_submissions as $key => $group): 
                    ?>
                        <div id="section-<?= $key; ?>" class="section-content-panel <?= $first_panel ? 'active' : ''; ?>">
                            <div class="pane-header">
                                <span>Target: <?= htmlspecialchars($group['label']); ?></span>
                                <span style="font-size: 0.75rem; background: rgba(255, 215, 0, 0.1); color: var(--gold); padding: 4px 12px; border-radius: 20px; border: 1px solid rgba(255, 215, 0, 0.2);">
                                    <?= count($group['items']); ?> Submissions
                                </span>
                            </div>

                            <!-- Search Filter para sa mga estudyante sa seksyong ito -->
                            <input type="text" class="search-filter-box" placeholder="🔍 Maghanap ng pangalan ng estudyante o task..." onkeyup="filterStudents(this, 'section-<?= $key; ?>')">

                            <div class="student-list-container">
                                <?php foreach ($group['items'] as $sub): ?>
                                    <div class="student-item-card" data-search-target="<?= strtolower($sub['student_name'] . ' ' . $sub['ann_title']); ?>">
                                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px;">
                                            <div>
                                                <h3 style="color: var(--text); font-size: 1.05rem; margin-bottom: 4px; font-weight: 700;"><?= htmlspecialchars($sub['student_name']); ?></h3>
                                                <p style="font-size: 0.8rem; color: var(--sub-text); margin-bottom: 4px;">Estudyante galing sa: <span style="color: #fff;"><?= htmlspecialchars($sub['stud_course'] . ' ' . $sub['stud_year'] . $sub['stud_section']); ?></span></p>
                                                <p style="font-size: 0.85rem; color: var(--sub-text);">Task: <strong style="color: var(--gold);"><?= htmlspecialchars($sub['ann_title']); ?></strong></p>
                                            </div>
                                            <div>
                                                <?php if ($sub['status'] === 'Checked'): ?>
                                                    <span class="badge-checked">Checked</span>
                                                <?php else: ?>
                                                    <span class="badge-pending">Pending</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <p style="font-size: 0.75rem; color: #666; margin-bottom: 15px;">
                                            Petsa ng Pagpasa: <?= date('M d, Y h:i A', strtotime($sub['submitted_at'])); ?>
                                        </p>

                                        <div style="margin-bottom: 20px;">
                                            <a href="<?= htmlspecialchars(ns_media_url($sub['file_path'])); ?>" target="_blank" class="download-btn">
                                                📥 I-download ang File ng Estudyante
                                            </a>
                                        </div>

                                        <?php
                                         if (!empty($sub['student_note'])): ?>
                                            <p style="font-size: 0.85rem; background: #121212; padding: 15px; border-radius: 12px; border: 1px solid var(--border-color); margin-bottom: 20px; color: #ccc;">
                                                <em style="color: var(--gold);">Mensahe ng estudyante:</em> "<?= htmlspecialchars($sub['student_note']); ?>"
                                            </p>
                                        <?php endif; ?>

                                        <!-- Grading Form -->
                                        <form action="professor_submissions.php" method="POST" class="grade-form" style="background: #121212; padding: 22px; border-radius: 14px; border: 1px solid var(--border-color);">
                                            <input type="hidden" name="submission_id" value="<?= $sub['id']; ?>">
                                            
                                            <label style="font-size: 0.8rem; font-weight: 600; color: var(--gold);">Grade / Score:</label>
                                            <input type="text" name="grade" value="<?= htmlspecialchars($sub['grade'] !== 'Pending' ? $sub['grade'] : ''); ?>" placeholder="Halimbawa: 1.25, 95, o Passed" required>

                                            <label style="font-size: 0.8rem; font-weight: 600; color: var(--gold);">Feedback / Komento:</label>
                                            <textarea name="feedback" rows="3" placeholder="Mag-iwan ng komento para sa estudyante..."><?= htmlspecialchars($sub['feedback'] ?? ''); ?></textarea>

                                            <button type="submit" name="update_grade" class="grade-btn">I-save ang Grado</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                       

                    <?php 
                        $first_panel = false;
                    endforeach; 
                    ?>
                </div>
            </div>
        <?php else: ?>
            <div class="content-pane">
                <div class="placeholder-text">Wala pang naipapasang gawa ang mga estudyante sa ngayon.</div>
            </div>
        <?php endif; ?>
    </main>

    <script>
        function switchSection(sectionKey, buttonElement) {
            const panels = document.querySelectorAll('.section-content-panel');
            panels.forEach(panel => panel.classList.remove('active'));

            const buttons = document.querySelectorAll('.section-card-btn');
            buttons.forEach(btn => btn.classList.remove('active'));

            document.getElementById('section-' + sectionKey).classList.add('active');
            buttonElement.classList.add('active');
        }

        function filterStudents(inputElement, panelId) {
            const query = inputElement.value.toLowerCase();
            const panel = document.getElementById(panelId);
            const cards = panel.querySelectorAll('.student-item-card');

            cards.forEach(card => {
                const targetText = card.getAttribute('data-search-target');
                if (targetText.includes(query)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>