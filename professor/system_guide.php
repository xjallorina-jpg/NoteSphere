<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../auth/login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>System Guide | NoteSphere</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#0f172a">
    <style>
        :root { 
            --neon: #39FF14; 
            --neon-glow: rgba(57, 255, 20, 0.15);
            --bg: #050505; 
            --card-bg: #0a0a0a; 
            --text: #f8fafc; 
            --border-color: #1a1a1a; 
            --sub-text: #888;
        }

        body.light-mode {
            --bg: #f4f6f9;
            --card-bg: #ffffff;
            --text: #000000;
            --border-color: #e2e8f0;
            --sub-text: #64748b;
            --neon: #2ecc71;
            --neon-glow: rgba(46, 204, 113, 0.1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
        
        html, body {
            width: 100%;
            min-height: 100vh;
            overflow-x: hidden;
            background: linear-gradient(rgba(11, 11, 11, 0.85), rgba(11, 11, 11, 0.92)), linear-gradient(135deg,rgba(5,7,13,.55),rgba(5,7,13,.25)),repeating-linear-gradient(0deg,rgba(57,255,20,.04) 0 1px,transparent 1px 34px),repeating-linear-gradient(90deg,rgba(57,255,20,.025) 0 1px,transparent 1px 34px) no-repeat center fixed;
            background-size: cover;
            color: var(--text);
        }

        body.light-mode {
            background: linear-gradient(rgba(244, 246, 249, 0.85), rgba(244, 246, 249, 0.92)), linear-gradient(135deg,rgba(5,7,13,.55),rgba(5,7,13,.25)),repeating-linear-gradient(0deg,rgba(57,255,20,.04) 0 1px,transparent 1px 34px),repeating-linear-gradient(90deg,rgba(57,255,20,.025) 0 1px,transparent 1px 34px) no-repeat center fixed;
            background-size: cover;
        }

        .container-wrapper {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
            padding: 30px 15px;
        }

        .card-guide { 
            background: var(--card-bg); 
            border: 1px solid var(--border-color); 
            border-radius: 20px; 
            padding: 40px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.6);
        }

        .text-neon { 
            color: var(--neon) !important; 
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--sub-text);
            text-decoration: none;
            font-size: 0.9rem;
            margin-bottom: 20px;
            transition: 0.3s;
        }

        .btn-back:hover {
            color: var(--neon);
            transform: translateX(-3px);
        }

        .guide-section {
            margin-top: 30px;
        }

        .guide-section h5 {
            color: var(--neon);
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: 10px;
        }

        .guide-section p {
            color: var(--sub-text);
            font-size: 0.95rem;
            line-height: 1.6;
        }

        hr {
            border-color: var(--border-color);
        }

        @media (max-width: 576px) {
            .container-wrapper {
                padding: 15px 10px;
            }
            .card-guide {
                padding: 20px;
                border-radius: 15px;
            }
        }
    </style>
</head>
<body>

    <div class="container-wrapper">
        <a href="student_dashboard.php" class="btn-back">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
            Back to Dashboard
        </a>

        <div class="card-guide">
            <h2 class="text-neon fw-bold mb-2">NoteSphere System Guide</h2>
            <p class="text-muted small">A brief student guide on how to navigate and utilize the NoteSphere platform effectively.</p>
            
            <hr class="my-4">

            <div class="guide-section">
                <h5>1. Dashboard & Announcements</h5>
                <p>Here you can view important announcements, assignments, or project guidelines posted by your professors. You can submit your outputs by attaching a file (up to 25MB) and clicking the submit button. Note that submissions are limited to <b>one attempt</b> per activity.</p>
            </div>

            <div class="guide-section">
                <h5>2. View Notes & Share a Note</h5>
                <p>You can share your reviewers, lecture notes, or documents using <b>Share a Note</b> to help your peers. All uploaded notes become visible and downloadable for everyone under the <b>View Notes</b> section.</p>
            </div>

            <div class="guide-section">
                <h5>3. My Submissions & My Uploads</h5>
                <p>Use <b>My Submissions</b> to monitor your activity output history. Meanwhile, <b>My Uploads</b> lists all the individual study notes and resources you have personally contributed to the system.</p>
            </div>

            <div class="guide-section">
                <h5>4. Enrollment Update (New Semester / Academic Year)</h5>
                <p>When a new semester begins or when permitted by the administrator, go to <b>Enrollment Update</b> to modify your Year Level, Section, and Enrolled Subjects. This action automatically archives your previous semester details and clears past dashboard logs for a fresh academic start, while keeping public study notes intact.</p>
            </div>
        </div>
    </div>

    <script>
        // Automatically check and apply theme on page load
        if (localStorage.getItem('notesphere_theme') === 'light') {
            document.body.classList.add('light-mode');
        }
    </script>
</body>
</html>