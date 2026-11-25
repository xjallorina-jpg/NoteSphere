
<?php

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

$prof_id = (int) $_SESSION['user_id'];


// =========================================================
// GET ANNOUNCEMENT ID
// =========================================================

$announcement_id = isset($_GET['announcement_id'])
    ? (int) $_GET['announcement_id']
    : 0;

if ($announcement_id <= 0) {
    header("Location: add_announcement.php");
    exit();
}


// =========================================================
// FETCH ANNOUNCEMENT
// =========================================================

$stmt_ann = $conn->prepare("
    SELECT *
    FROM announcements
    WHERE id = ?
      AND professor_id = ?
    LIMIT 1
");

$stmt_ann->execute([
    $announcement_id,
    $prof_id
]);

$announcement = $stmt_ann->fetch(PDO::FETCH_ASSOC);

if (!$announcement) {
    header("Location: add_announcement.php");
    exit();
}


// =========================================================
// HANDLE GRADE / FEEDBACK
// =========================================================

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['save_grade'])
) {
    $submission_id = isset($_POST['submission_id'])
        ? (int) $_POST['submission_id']
        : 0;

    $grade = trim($_POST['grade'] ?? '');
    $feedback = trim($_POST['feedback'] ?? '');

    if ($submission_id <= 0) {
        $_SESSION['grade_error'] = "Invalid submission.";

        header(
            "Location: view_submissions.php?announcement_id=" .
            $announcement_id
        );
        exit();
    }

    if ($grade === '' && $feedback === '') {
        $_SESSION['grade_error'] =
            "Please enter a grade or feedback before saving.";

        header(
            "Location: view_submissions.php?announcement_id=" .
            $announcement_id
        );
        exit();
    }

    try {
        // Verify that the submission belongs to this announcement.
        // The announcement itself was already verified as owned
        // by the currently logged-in professor above.

        $check_submission = $conn->prepare("
            SELECT id, student_id
            FROM announcement_submissions
            WHERE id = ?
              AND announcement_id = ?
            LIMIT 1
        ");

        $check_submission->execute([
            $submission_id,
            $announcement_id
        ]);

        $submission = $check_submission->fetch(PDO::FETCH_ASSOC);

        if (!$submission) {
            $_SESSION['grade_error'] =
                "The selected submission does not belong to this announcement.";

            header(
                "Location: view_submissions.php?announcement_id=" .
                $announcement_id
            );
            exit();
        }

        $stmt_grade = $conn->prepare("
            UPDATE announcement_submissions
            SET grade = ?, feedback = ?
            WHERE id = ?
              AND announcement_id = ?
        ");

        $stmt_grade->execute([
            $grade === '' ? null : $grade,
            $feedback === '' ? null : $feedback,
            $submission_id,
            $announcement_id
        ]);

        $_SESSION['grade_success'] =
            "Grade and feedback successfully updated.";

        header(
            "Location: view_submissions.php?announcement_id=" .
            $announcement_id
        );
        exit();

    } catch (PDOException $e) {
        error_log(
            "NoteSphere grade update error: " . $e->getMessage()
        );

        $_SESSION['grade_error'] =
            "Unable to update the grade and feedback. Please try again.";

        header(
            "Location: view_submissions.php?announcement_id=" .
            $announcement_id
        );
        exit();
    }
}


// =========================================================
// FLASH MESSAGES
// =========================================================

$success_msg = $_SESSION['grade_success'] ?? '';
$error_msg = $_SESSION['grade_error'] ?? '';

unset($_SESSION['grade_success']);
unset($_SESSION['grade_error']);


// =========================================================
// FETCH STUDENT SUBMISSIONS
// =========================================================

$stmt_subs = $conn->prepare("
    SELECT
        s.*,
        u.full_name,
        u.section,
        u.email
    FROM announcement_submissions s
    INNER JOIN users u
        ON s.student_id = u.user_id
    WHERE s.announcement_id = ?
      AND (
            s.submission_text IS NOT NULL
            OR s.file_path IS NOT NULL
          )
    ORDER BY s.submitted_at DESC
");

$stmt_subs->execute([$announcement_id]);

$submissions = $stmt_subs->fetchAll(PDO::FETCH_ASSOC);


// =========================================================
// ESCAPE HELPER
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
// CHECK IF REVIEWED
// =========================================================

function hasGradeOrFeedback($submission)
{
    $has_grade =
        isset($submission['grade']) &&
        $submission['grade'] !== null &&
        trim((string) $submission['grade']) !== '';

    $has_feedback =
        isset($submission['feedback']) &&
        $submission['feedback'] !== null &&
        trim((string) $submission['feedback']) !== '';

    return $has_grade || $has_feedback;
}


// =========================================================
// BUILD SUBMISSION FILE URL
// =========================================================

function getSubmissionFileUrl($filePath)
{
    $filePath = trim((string) $filePath);

    if ($filePath === '') {
        return '';
    }

    // Only accept a relative path stored by the application.
    // Do not allow arbitrary external URLs or path traversal.
    $normalized = str_replace('\\', '/', $filePath);
    $normalized = ltrim($normalized, '/');

    if (
        preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $normalized) ||
        strpos($normalized, "\0") !== false
    ) {
        return '';
    }

    $segments = explode('/', $normalized);

    foreach ($segments as $segment) {
        if ($segment === '..') {
            return '';
        }
    }

    if (strpos($normalized, 'uploads/') !== 0) {
        $normalized = 'uploads/' . $normalized;
    }

    $segments = explode('/', $normalized);

    $encodedSegments = array_map(
        'rawurlencode',
        $segments
    );

    return '../' . implode('/', $encodedSegments);
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

    <title>View Submissions | NoteSphere</title>

    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap"
        rel="stylesheet"
    >

    <link
        rel="manifest"
        href="../manifest.json"
    >

    <meta name="theme-color" content="#0f172a">

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>

        /* =====================================================
           THEME VARIABLES
        ===================================================== */

        :root {
            --neon: #39FF14;
            --neon-glow: rgba(57, 255, 20, 0.12);
            --card-bg: #0c0c0c;
            --text: #f8fafc;
            --sub-text: #94a3b8;
            --border: #1f293d;
            --page-bg: #0b0b0b;
            --bg-image: url('../assets/Untitled design.png');
        }

        body.light-mode {
            --card-bg: #ffffff;
            --text: #000000;
            --border: #e2e8f0;
            --sub-text: #555555;
            --neon: #10b981;
            --neon-glow: rgba(16, 185, 129, 0.12);
            --page-bg: #f5f7fb;
        }

        /* =====================================================
           GLOBAL
        ===================================================== */

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        html,
        body {
            width: 100%;
            min-height: 100vh;
            overflow-x: hidden;
        }

        body {
            position: relative;
            color: var(--text);
            background-color: #0b0b0b;
            background-image:
                linear-gradient(rgba(11,11,11,.85), rgba(11,11,11,.92)),
                var(--bg-image);
            background-position: center center;
            background-repeat: no-repeat;
            background-size: cover;
            background-attachment: fixed;
            display: flex;
            flex-direction: column;
            align-items: center;
            transition:
                color 0.3s ease,
                background-color 0.3s ease;
        }

        body::after {
            content: "";
            position: fixed;
            inset: 0;
            z-index: -1;
            pointer-events: none;
            background: radial-gradient(
                circle at top center,
                rgba(57,255,20,.035),
                transparent 45%
            );
        }

        body.light-mode {
            --bg-image: url('../assets/Untitled design.png');
        }

        /* =====================================================
           MAIN CONTAINER
        ===================================================== */

        .container {
            width: 100%;
            max-width: 1100px;
            padding: 40px 20px;
            box-sizing: border-box;
            position: relative;
        }

        /* =====================================================
           TOP BAR
        ===================================================== */

        .top-bar {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            margin-bottom: 25px;
        }

        .close-btn {
            background: var(--card-bg);
            border: 1px solid var(--border);
            color: var(--text);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 1.2rem;
            font-weight: 800;
            transition: 0.2s;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .close-btn:hover {
            border-color: var(--neon);
            color: var(--neon);
            transform: scale(1.05);
        }

        /* =====================================================
           CARDS
        ===================================================== */

        .card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            box-sizing: border-box;
            transition:
                background-color 0.3s ease,
                border-color 0.3s ease;
            margin-bottom: 25px;
        }

        /* =====================================================
           ALERTS
        ===================================================== */

        .alert-success {
            background: rgba(46, 204, 113, 0.1);
            border: 1px solid #2ecc71;
            color: #2ecc71;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .alert-error {
            background: rgba(255, 77, 77, 0.1);
            border: 1px solid #ff4d4d;
            color: #ff4d4d;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }

        /* =====================================================
           SUBMISSION CARD
        ===================================================== */

        .submission-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            padding: 20px;
            border-radius: 14px;
            margin-bottom: 15px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            min-width: 0;
        }

        .sub-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 10px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 10px;
        }

        .student-name {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--text);
            overflow-wrap: anywhere;
        }

        .student-meta {
            font-size: 0.8rem;
            color: var(--sub-text);
            line-height: 1.7;
        }

        .sub-body {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr);
            gap: 20px;
            align-items: start;
        }

        .sub-body > div {
            min-width: 0;
        }

        /* =====================================================
           INLINE FILE PREVIEW
        ===================================================== */

        .submission-preview {
            width: 100%;
            margin-top: 15px;
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            background: var(--card-bg);
        }

        .preview-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            padding: 12px 14px;
            color: var(--text);
            border-bottom: 1px solid var(--border);
            font-size: 0.82rem;
        }

        .preview-heading span {
            color: var(--neon);
            font-size: 0.7rem;
            font-weight: 800;
        }

        .file-preview-frame {
            display: block;
            width: 100%;
            height: 520px;
            border: none;
            background: #ffffff;
        }

        .text-preview-frame {
            height: 300px;
        }

        .image-preview-link {
            display: block;
            padding: 12px;
            text-align: center;
            background: rgba(0, 0, 0, 0.04);
        }

        .file-preview-image {
            display: block;
            max-width: 100%;
            max-height: 600px;
            width: auto;
            height: auto;
            margin: 0 auto;
            object-fit: contain;
            border-radius: 6px;
        }

        .unsupported-preview {
            padding: 28px 18px;
            text-align: center;
            color: var(--sub-text);
        }

        .unsupported-icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .unsupported-preview strong {
            display: block;
            color: var(--text);
            overflow-wrap: anywhere;
            margin-bottom: 8px;
        }

        .unsupported-preview p {
            font-size: 0.8rem;
            line-height: 1.6;
        }

        /* =====================================================
           GRADE FORM
        ===================================================== */

        .grade-form {
            display: flex;
            flex-direction: column;
            gap: 10px;
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border);
            padding: 14px;
            border-radius: 10px;
        }

        .form-row {
            display: flex;
            gap: 10px;
        }

        .form-row input,
        .form-row textarea {
            width: 100%;
            min-width: 0;
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--card-bg);
            color: var(--text);
            outline: none;
            font-size: 0.85rem;
            resize: vertical;
        }

        .form-row input:focus,
        .form-row textarea:focus {
            border-color: var(--neon);
        }

        .btn-grade {
            background: var(--neon);
            color: #0c0c0c;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 800;
            cursor: pointer;
            transition: 0.2s;
            font-size: 0.85rem;
            align-self: flex-end;
        }

        .btn-grade:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        /* =====================================================
           MOBILE
        ===================================================== */

        @media (max-width: 768px) {
            .sub-body {
                grid-template-columns: minmax(0, 1fr);
            }

            .container {
                padding: 20px 12px;
            }

            .card {
                padding: 20px;
            }

            .sub-header {
                flex-direction: column;
            }

            .btn-grade {
                width: 100%;
                align-self: stretch;
            }

            .file-preview-frame {
                height: 400px;
            }

            .text-preview-frame {
                height: 260px;
            }

            .file-preview-image {
                max-height: 400px;
            }
        }

    </style>

</head>

<body>

<div class="container">

    <!-- CLOSE BUTTON -->

    <div class="top-bar">
        <a
            href="add_announcement.php"
            class="close-btn"
            title="Close / Back"
            aria-label="Back to announcements"
        >✕</a>
    </div>


    <!-- ANNOUNCEMENT INFORMATION -->

    <div class="card">

        <span
            style="
                font-size: 0.75rem;
                color: var(--neon);
                font-weight: 700;
            "
        >
            [
            <?= e($announcement['category'] ?? 'Announcement') ?>
            Submissions
            ]
        </span>

        <h1
            style="
                font-size: 1.5rem;
                font-weight: 800;
                margin: 4px 0 8px 0;
                overflow-wrap: anywhere;
            "
        >
            <?= e($announcement['title'] ?? '') ?>
        </h1>

        <p
            style="
                font-size: 0.85rem;
                color: var(--sub-text);
                line-height: 1.8;
            "
        >
            Subject:
            <strong><?= e($announcement['subject'] ?? '') ?></strong>
            |
            Target:
            <strong>
                <?= e($announcement['year_level'] ?? '') ?>
                -
                <?= e($announcement['section'] ?? '') ?>
            </strong>
        </p>

    </div>


    <!-- STUDENT SUBMISSIONS -->

    <div class="card">

        <h3
            style="
                margin-bottom: 20px;
                font-size: 1.15rem;
                color: var(--neon);
            "
        >
            Student Submissions (<?= count($submissions) ?>)
        </h3>

        <?php if (empty($submissions)): ?>

            <p
                style="
                    color: var(--sub-text);
                    text-align: center;
                    padding: 40px 10px;
                "
            >
                No student submissions found for this task yet.
            </p>

        <?php else: ?>

            <?php foreach ($submissions as $sub): ?>

                <?php
                $is_reviewed = hasGradeOrFeedback($sub);
                ?>

                <div class="submission-card">

                    <!-- STUDENT INFORMATION -->

                    <div class="sub-header">

                        <div>

                            <div class="student-name">
                                <?= e(
                                    $sub['full_name'] ?? 'Unknown Student'
                                ) ?>
                            </div>

                            <div class="student-meta">
                                Section:
                                <?= e($sub['section'] ?? 'N/A') ?>
                                <br>
                                Submitted:
                                <?= e($sub['submitted_at'] ?? 'N/A') ?>
                                <?php if (!empty($sub['email'])): ?>
                                    <br>
                                    Email:
                                    <?= e($sub['email']) ?>
                                <?php endif; ?>
                            </div>

                        </div>

                        <div>

                            <?php if ($is_reviewed): ?>

                                <span
                                    style="
                                        display: inline-block;
                                        background: rgba(46, 204, 113, 0.15);
                                        color: #2ecc71;
                                        border: 1px solid rgba(46, 204, 113, 0.3);
                                        padding: 4px 10px;
                                        border-radius: 6px;
                                        font-size: 0.8rem;
                                        font-weight: 700;
                                    "
                                >Reviewed</span>

                            <?php else: ?>

                                <span
                                    style="
                                        display: inline-block;
                                        background: rgba(241, 196, 15, 0.15);
                                        color: #f1c40f;
                                        border: 1px solid rgba(241, 196, 15, 0.3);
                                        padding: 4px 10px;
                                        border-radius: 6px;
                                        font-size: 0.8rem;
                                        font-weight: 700;
                                    "
                                >Pending Grade</span>

                            <?php endif; ?>

                        </div>

                    </div>


                    <!-- SUBMISSION CONTENT -->

                    <div class="sub-body">

                        <!-- STUDENT ANSWER / FILE -->

                        <div>

                            <div
                                style="
                                    font-size: 0.85rem;
                                    font-weight: 600;
                                    margin-bottom: 4px;
                                "
                            >
                                Student Answer / Notes:
                            </div>

                            <div
                                style="
                                    font-size: 0.85rem;
                                    color: var(--sub-text);
                                    background: rgba(0, 0, 0, 0.1);
                                    padding: 10px;
                                    border-radius: 8px;
                                    margin-bottom: 8px;
                                    min-height: 50px;
                                    overflow-wrap: anywhere;
                                    line-height: 1.7;
                                "
                            >

                                <?php
                                $answer_content =
                                    $sub['submission_text'] ?? null;
                                ?>

                                <?php if (trim((string) $answer_content) !== ''): ?>

                                    <?= nl2br(e($answer_content)) ?>

                                <?php else: ?>

                                    <span
                                        style="
                                            color: var(--sub-text);
                                            font-style: italic;
                                        "
                                    >No text provided.</span>

                                <?php endif; ?>

                            </div>


                            <!-- INLINE FILE PREVIEW -->

                            <?php
                            $file_path = $sub['file_path'] ?? '';
                            $file_url = getSubmissionFileUrl($file_path);

                            $file_extension = strtolower(
                                pathinfo(
                                    str_replace('\\', '/', (string) $file_path),
                                    PATHINFO_EXTENSION
                                )
                            );

                            $previewable_extensions = [
                                'pdf',
                                'jpg',
                                'jpeg',
                                'png',
                                'gif',
                                'webp',
                                'txt'
                            ];
                            ?>

                            <?php if ($file_path !== '' && $file_url !== ''): ?>

                                <div class="submission-preview">

                                    <div class="preview-heading">

                                        <strong>Submitted File Preview</strong>

                                        <span>
                                            <?= e(strtoupper($file_extension)) ?>
                                        </span>

                                    </div>


                                    <?php if ($file_extension === 'pdf'): ?>

                                        <iframe
                                            src="<?= e($file_url) ?>#toolbar=0&navpanes=0"
                                            class="file-preview-frame"
                                            title="Student PDF Submission"
                                            loading="lazy"
                                        ></iframe>

                                        <div
                                            style="
                                                padding: 10px 14px;
                                                font-size: 0.78rem;
                                                color: var(--sub-text);
                                            "
                                        >
                                            If the PDF preview does not appear,
                                            check the file path or open the file
                                            in a new browser tab.

                                            <a
                                                href="<?= e($file_url) ?>"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                style="
                                                    color: var(--neon);
                                                    font-weight: 700;
                                                "
                                            >Open PDF</a>
                                        </div>


                                    <?php elseif (
                                        in_array(
                                            $file_extension,
                                            [
                                                'jpg',
                                                'jpeg',
                                                'png',
                                                'gif',
                                                'webp'
                                            ],
                                            true
                                        )
                                    ): ?>

                                        <a
                                            href="<?= e($file_url) ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="image-preview-link"
                                            title="Open image in a new tab"
                                        >

                                            <img
                                                src="<?= e($file_url) ?>"
                                                alt="Student's submitted image"
                                                class="file-preview-image"
                                                loading="lazy"
                                            >

                                        </a>


                                    <?php elseif ($file_extension === 'txt'): ?>

                                        <iframe
                                            src="<?= e($file_url) ?>"
                                            class="file-preview-frame text-preview-frame"
                                            title="Student Text Submission"
                                            loading="lazy"
                                        ></iframe>


                                    <?php else: ?>

                                        <div class="unsupported-preview">

                                            <div class="unsupported-icon">📄</div>

                                            <strong>
                                                <?= e(
                                                    basename(
                                                        str_replace(
                                                            '\\',
                                                            '/',
                                                            (string) $file_path
                                                        )
                                                    )
                                                ) ?>
                                            </strong>

                                            <p>
                                                This file type cannot be previewed
                                                directly by most browsers.
                                                PDF, image, and TXT files can be
                                                displayed inline when supported.
                                            </p>

                                            <a
                                                href="view_submission_file.php?submission_id=<?= (int) $sub['id'] ?>"
                                            >Open file in new tab</a>

                                        </div>

                                    <?php endif; ?>

                                </div>

                            <?php elseif ($file_path !== ''): ?>

                                <p
                                    style="
                                        color: #ff6b6b;
                                        font-size: 0.82rem;
                                        margin-top: 10px;
                                    "
                                >
                                    The saved file path is invalid.
                                    Please verify the stored submission path.
                                </p>

                            <?php endif; ?>

                        </div>


                        <!-- GRADE / FEEDBACK -->

                        <div>

                            <form method="POST" class="grade-form">

                                <input
                                    type="hidden"
                                    name="submission_id"
                                    value="<?= (int) $sub['id'] ?>"
                                >

                                <div
                                    style="
                                        font-size: 0.82rem;
                                        font-weight: 600;
                                        color: var(--text);
                                    "
                                >
                                    Provide Grade &amp; Feedback
                                </div>

                                <div class="form-row">

                                    <input
                                        type="text"
                                        name="grade"
                                        placeholder="Grade (e.g. 1.25 or 95)"
                                        value="<?= e($sub['grade'] ?? '') ?>"
                                        maxlength="20"
                                        aria-label="Grade"
                                    >

                                </div>

                                <div class="form-row">

                                    <textarea
                                        name="feedback"
                                        rows="4"
                                        placeholder="Add feedback remarks..."
                                        aria-label="Feedback remarks"
                                    ><?= e($sub['feedback'] ?? '') ?></textarea>

                                </div>

                                <button
                                    type="submit"
                                    name="save_grade"
                                    class="btn-grade"
                                >
                                    Save Grade &amp; Feedback
                                </button>

                            </form>

                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>

</div>


<!-- =========================================================
     THEME
========================================================== -->

<script>

function applyTheme(theme) {
    if (theme === 'light') {
        document.body.classList.add('light-mode');
    } else {
        document.body.classList.remove('light-mode');
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const savedTheme =
        localStorage.getItem('notesphere_theme') ||
        localStorage.getItem('theme') ||
        'dark';

    applyTheme(savedTheme);
});

window.addEventListener('storage', function (event) {
    if (
        event.key === 'notesphere_theme' ||
        event.key === 'theme'
    ) {
        if (event.newValue) {
            applyTheme(event.newValue);
        }
    }
});

</script>


<!-- =========================================================
     SWEETALERT MESSAGES
========================================================== -->

<?php if (!empty($success_msg)): ?>

<script>
Swal.fire({
    icon: 'success',
    title: 'Saved Successfully',
    text: <?= json_encode($success_msg) ?>,
    confirmButtonText: 'OK',
    confirmButtonColor: '#10b981',
    background: document.body.classList.contains('light-mode')
        ? '#ffffff'
        : '#0c0c0c',
    color: document.body.classList.contains('light-mode')
        ? '#111827'
        : '#f8fafc'
});
</script>

<?php endif; ?>


<?php if (!empty($error_msg)): ?>

<script>
Swal.fire({
    icon: 'error',
    title: 'Update Failed',
    text: <?= json_encode($error_msg) ?>,
    confirmButtonText: 'OK',
    confirmButtonColor: '#10b981',
    background: document.body.classList.contains('light-mode')
        ? '#ffffff'
        : '#0c0c0c',
    color: document.body.classList.contains('light-mode')
        ? '#111827'
        : '#f8fafc'
});
</script>

<?php endif; ?>
</body>
</html>
