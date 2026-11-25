<?php
// =========================================================
// NoteSphere - Create Account (Student / Professor)
// =========================================================

session_start();
require_once __DIR__ . '/../includes/db_config.php';
/** @var PDO $conn */

// =========================================================
// ADMIN SECURITY
// =========================================================
if (!isset($_SESSION['user_id'], $_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// =========================================================
// HELPERS
// =========================================================
function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function getInstitutes(): array
{
    return [
        'ICS' => [
            'name' => 'Institute of Computer Studies',
            'courses' => [
                'BSIT' => 'Bachelor of Science in Information Technology',
                'BSCPE' => 'Bachelor of Science in Computer Engineering',
            ],
        ],
        'IBE' => [
            'name' => 'Institute of Business',
            'courses' => [
                'BSBA-HRM' => 'Bachelor of Science in Business Administration Major in Human Resource Management',
                'BSE' => 'Bachelor of Science in Entrepreneurship',
            ],
        ],
        'ITE' => [
            'name' => 'Institute of Education',
            'courses' => [
                'BSEd-SCI' => 'Bachelor of Secondary Education major in Science',
                'BEEd-GEN' => 'Bachelor of Elementary Education - Generalist',
                'BEEd-ECED' => 'Bachelor of Early Childhood Education',
                'BTLEd-ICT' => 'Bachelor of Technology and Livelihood Education major in Information and Communication Technology',
                'TCP' => 'Teacher Certificate Program (18 Units-TCP)',
            ],
        ],
    ];
}

$institutes = getInstitutes();

$error = '';
$success = '';

$defaults = [
    'role' => 'student',
    'full_name' => '',
    'email' => '',
    'institute' => '',
    'course' => '',
    'student_status' => 'Regular',
    'section' => '',
    'subject_codes' => '',
    'year_level' => '',
];
$old = $defaults;

// =========================================================
// CREATE ACCOUNT
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role = (string)($_POST['role'] ?? '');
    $full_name = trim((string)($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $confirm_password = (string)($_POST['confirm_password'] ?? '');
    $institute = strtoupper(trim((string)($_POST['institute'] ?? '')));
    $course = trim((string)($_POST['course'] ?? ''));
    $student_status = trim((string)($_POST['student_status'] ?? 'Regular'));
    $regular_section = strtoupper(trim((string)($_POST['regular_section'] ?? '')));
    $irregular_section = strtoupper(trim((string)($_POST['irregular_section'] ?? '')));
    $subject_codes_input = strtoupper(trim((string)($_POST['subject_codes'] ?? '')));
    $year_level = trim((string)($_POST['year_level'] ?? ''));

    $section = $student_status === 'Irregular' ? $irregular_section : $regular_section;

    $old = [
        'role' => $role,
        'full_name' => $full_name,
        'email' => $email,
        'institute' => $institute,
        'course' => $course,
        'student_status' => $student_status,
        'section' => $section,
        'subject_codes' => $subject_codes_input,
        'year_level' => $year_level,
    ];

    $posted_token = $_POST['csrf_token'] ?? '';
    $subject_codes = '';

    if (!is_string($posted_token) || !hash_equals($csrf_token, $posted_token)) {
        $error = 'Invalid security token. Please refresh the page and try again.';
    } elseif (!in_array($role, ['student', 'professor'], true)) {
        $error = 'Select a valid account role.';
    } elseif ($full_name === '' || mb_strlen($full_name) > 150) {
        $error = 'Enter a full name containing no more than 150 characters.';
    } elseif ($email === '' || strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (strlen($password) < 8 || strlen($password) > 72) {
        $error = 'Use a temporary password between 8 and 72 characters.';
    } elseif ($role === 'student' && $password !== $confirm_password) {
        $error = 'The temporary passwords you entered do not match.';
    } elseif ($role === 'student') {
        if (!isset($institutes[$institute])) {
            $error = 'Select a valid institute.';
        } elseif (!isset($institutes[$institute]['courses'][$course])) {
            $error = 'Select a valid course for the chosen institute.';
        } elseif (!in_array($student_status, ['Regular', 'Irregular'], true)) {
            $error = 'Select Regular or Irregular as the student status.';
        } elseif ($student_status === 'Regular' && !in_array($section, ['A', 'B', 'C', 'D', 'E'], true)) {
            $error = 'Select a valid regular section.';
        } elseif ($student_status === 'Irregular' && ($section === '' || mb_strlen($section) > 50)) {
            $error = 'Enter the irregular student section (maximum 50 characters).';
        } elseif (!in_array($year_level, ['1', '2', '3', '4'], true)) {
            $error = 'Select a valid year level.';
        } elseif ($subject_codes_input === '') {
            $error = 'Enter the subject codes taken by the student.';
        } elseif (mb_strlen($subject_codes_input) > 1000) {
            $error = 'Subject codes must not exceed 1,000 characters.';
        } else {
            $parts = preg_split('/[,;\r\n]+/', $subject_codes_input) ?: [];
            $parts = array_values(array_unique(array_filter(
                array_map(static fn($value) => strtoupper(trim($value)), $parts),
                static fn($value) => $value !== ''
            )));

            foreach ($parts as $code) {
                if (strlen($code) > 30 || !preg_match('/^[A-Z][A-Z0-9]*(?:[ -][A-Z0-9]+)*$/', $code)) {
                    $error = 'The subject code "' . $code . '" is invalid. Use letters and numbers separated by commas (example: IT101, MATH101, GE 1).';
                    break;
                }
            }

            if ($error === '') {
                if (!$parts) {
                    $error = 'Enter at least one valid subject code.';
                } else {
                    $subject_codes = implode(', ', $parts);
                }
            }
        }
    }

    if ($error === '') {
        try {
            $check_stmt = $conn->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');
            $check_stmt->execute([$email]);

            if ($check_stmt->fetch(PDO::FETCH_ASSOC)) {
                $error = 'An account with this email address already exists.';
            } else {
                $fields = [
                    'full_name' => $full_name,
                    'email' => $email,
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => $role,
                    'account_status' => 'active',
                ];

                if ($role === 'student') {
                    $fields['institute'] = $institute;
                    $fields['course'] = $course;
                    $fields['student_status'] = $student_status;
                    $fields['year_level'] = $year_level;
                    $fields['section'] = $section;
                    $fields['enrolled_subjects'] = $subject_codes;
                }

                $columns = array_keys($fields);
                $quoted = array_map(static fn($column) => '`' . $column . '`', $columns);
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                $insert_stmt = $conn->prepare(
                    'INSERT INTO users (' . implode(', ', $quoted) . ') VALUES (' . $placeholders . ')'
                );
                $insert_stmt->execute(array_values($fields));

                // Send welcome email with system link
                try {
                    require_once __DIR__ . '/../includes/PHPMailer-master/PHPMailer-master/src/Exception.php';
                    require_once __DIR__ . '/../includes/PHPMailer-master/PHPMailer-master/src/PHPMailer.php';
                    require_once __DIR__ . '/../includes/PHPMailer-master/PHPMailer-master/src/SMTP.php';
                    $mc = require __DIR__ . '/../includes/mail_config.php';
                    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host = 'smtp.gmail.com';
                    $mail->SMTPAuth = true;
                    $mail->Username = $mc['username'];
                    $mail->Password = preg_replace('/\s+/', '', $mc['app_password']);
                    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = 587;
                    $mail->setFrom($mc['username'], 'NoteSphere');
                    $mail->addAddress($email);
                    $mail->Subject = 'Welcome to NoteSphere';
                    $systemScheme = !empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
                        ? strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO'])
                        : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
                    $systemBasePath = rtrim(dirname(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
                    $systemLink = $systemScheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $systemBasePath . '/website/index.php';
                    $mail->Body = "Your account has been created. You can now log in to NoteSphere.\n\nAccess the system here: $systemLink\n\nIf you need a temporary password, request one from the portal.";
                    $mail->send();
                } catch (Throwable $e) {
                    error_log('Welcome email failed: ' . $e->getMessage());
                }

                $success = ($role === 'student' ? 'Student' : 'Professor') . ' account for '
                    . $full_name . ' was created successfully.';
                $old = $defaults;
            }
        } catch (PDOException $ex) {
            error_log('[NoteSphere Create Account] ' . $ex->getMessage());
            $error = 'The account could not be saved. Please check the database and try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Create Account | NoteSphere</title>

<style>
:root {
    --primary:#233961;
    --primary-light:#3D8BFF;
    --bg:#f5f7fb;
    --card:#fff;
    --text:#1e293b;
    --muted:#64748b;
    --border:#e2e8f0;
    --success:#16a34a;
    --danger:#dc2626;
    --shadow:0 8px 25px rgba(15,23,42,.06);
    --radius:16px;
}

body.dark {
    --bg:#0f172a;
    --card:#1e293b;
    --text:#f1f5f9;
    --muted:#94a3b8;
    --border:#334155;
    --shadow:0 8px 25px rgba(0,0,0,.25);
}

* { box-sizing:border-box; margin:0; padding:0; }

body {
    font-family:Arial,Helvetica,sans-serif;
    background:var(--bg);
    background-image:url('../assets/Untitled design.png');
    background-size:cover;
    background-attachment:fixed;
    color:var(--text);
    min-height:100vh;
    transition:background .25s,color .25s;
}

button,input,select { font-family:inherit; }
a { color:inherit; text-decoration:none; }

/* =====================================================
   BACK BUTTON
===================================================== */

.back-btn {
    position:fixed;
    top:18px;
    left:20px;
    width:42px;
    height:42px;
    border:1px solid var(--border);
    border-radius:11px;
    background:var(--card);
    color:var(--text);
    display:flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    z-index:2000;
    box-shadow:0 5px 18px rgba(15,23,42,.08);
}

.back-btn:hover { color:var(--danger); }

.back-btn svg,
.form-card-icon svg,
.section-heading svg,
.password-toggle svg {
    width:20px;
    height:20px;
    stroke:currentColor;
}

/* =====================================================
   LAYOUT
===================================================== */

.main { min-height:100vh; }

.topbar {
    height:76px;
    background:var(--card);
    border-bottom:1px solid var(--border);
    display:flex;
    align-items:center;
    padding:0 30px 0 82px;
    position:sticky;
    top:0;
    z-index:100;
}

.page-heading h1 { font-size:20px; font-weight:800; }
.page-heading p { font-size:12px; color:var(--muted); margin-top:4px; }

.content { padding:30px; }

/* =====================================================
   FORM CARD
===================================================== */

.form-card {
    width:100%;
    max-width:900px;
    margin:0 auto;
    background:var(--card);
    border:1px solid var(--border);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    overflow:hidden;
}

.form-card-head {
    padding:22px 26px;
    border-bottom:1px solid var(--border);
    display:flex;
    align-items:center;
    gap:14px;
}

.form-card-icon {
    width:46px;
    height:46px;
    border-radius:13px;
    background:rgba(61,139,255,.12);
    color:var(--primary-light);
    display:flex;
    align-items:center;
    justify-content:center;
    flex-shrink:0;
}

.form-card-head h2 { font-size:18px; font-weight:800; }
.form-card-head p { font-size:11px; color:var(--muted); margin-top:3px; }

.form-body { padding:26px; }

/* =====================================================
   SECTIONS
===================================================== */

.form-section + .form-section {
    margin-top:26px;
    padding-top:24px;
    border-top:1px solid var(--border);
}

.section-heading {
    display:flex;
    align-items:center;
    gap:9px;
    margin-bottom:16px;
}

.section-heading svg {
    width:17px;
    height:17px;
    color:var(--primary-light);
}

.section-heading h3 {
    font-size:12px;
    font-weight:800;
    letter-spacing:.07em;
    text-transform:uppercase;
    color:var(--primary);
}

body.dark .section-heading h3 { color:var(--primary-light); }

/* =====================================================
   FORM FIELDS
===================================================== */

.form-grid {
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:18px;
}

.form-group { min-width:0; }
.form-group.full { grid-column:1 / -1; }

.form-label {
    display:block;
    font-size:12px;
    font-weight:800;
    margin-bottom:7px;
}

.form-input {
    width:100%;
    height:45px;
    border:1px solid var(--border);
    border-radius:10px;
    background:var(--card);
    color:var(--text);
    padding:0 13px;
    outline:none;
    font-size:13px;
    transition:border-color .2s,box-shadow .2s;
}

.form-input:focus {
    border-color:var(--primary-light);
    box-shadow:0 0 0 3px rgba(61,139,255,.13);
}

.form-input:disabled {
    opacity:.55;
    cursor:not-allowed;
}

.form-input.upper { text-transform:uppercase; }

select.form-input { cursor:pointer; }
select.form-input option { background:var(--card); color:var(--text); }

.form-help {
    color:var(--muted);
    font-size:10px;
    margin-top:5px;
    line-height:1.5;
}

/* =====================================================
   PASSWORD
===================================================== */

.form-input-wrap { position:relative; }

.password-input { padding-right:48px; }

.password-toggle {
    position:absolute;
    right:7px;
    top:50%;
    transform:translateY(-50%);
    width:34px;
    height:34px;
    border:none;
    background:transparent;
    color:var(--muted);
    cursor:pointer;
    border-radius:8px;
    display:flex;
    align-items:center;
    justify-content:center;
}

.password-toggle:hover { color:var(--primary-light); }
.password-toggle svg { width:18px; height:18px; }

/* =====================================================
   SUBJECT STATUS
===================================================== */

.subject-status {
    display:block;
    min-height:15px;
    font-size:10px;
    line-height:1.5;
    margin-top:5px;
}

.subject-status.valid { color:var(--success); }
.subject-status.invalid { color:var(--danger); }

/* =====================================================
   ALERTS
===================================================== */

.alert {
    display:flex;
    align-items:flex-start;
    gap:10px;
    padding:13px 16px;
    border-radius:11px;
    font-size:12px;
    font-weight:600;
    line-height:1.5;
    margin-bottom:20px;
}

.alert svg {
    width:18px;
    height:18px;
    stroke:currentColor;
    flex-shrink:0;
    margin-top:1px;
}

.alert-error {
    background:rgba(220,38,38,.08);
    color:var(--danger);
    border:1px solid rgba(220,38,38,.2);
}

.alert-success {
    background:rgba(22,163,74,.08);
    color:var(--success);
    border:1px solid rgba(22,163,74,.2);
}

/* =====================================================
   FOOTER ACTIONS
===================================================== */

.form-actions {
    padding:18px 26px;
    border-top:1px solid var(--border);
    display:flex;
    justify-content:flex-end;
    gap:10px;
    background:var(--card);
}

.btn {
    min-height:42px;
    padding:0 18px;
    border-radius:10px;
    font-size:12px;
    font-weight:800;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    border:none;
    transition:transform .2s,background .2s;
}

.btn-cancel {
    border:1px solid var(--border);
    background:var(--card);
    color:var(--text);
}

.btn-cancel:hover { transform:translateY(-1px); }

.btn-save {
    background:linear-gradient(135deg,#233961,#3D8BFF);
    color:#fff;
    box-shadow:0 7px 18px rgba(35,57,97,.18);
}

.btn-save:hover { transform:translateY(-1px); }

.btn-save:disabled {
    opacity:.65;
    cursor:wait;
    transform:none;
    box-shadow:none;
}

/* =====================================================
   RESPONSIVE
===================================================== */

@media(max-width:800px) {
    .topbar { padding:0 18px 0 72px; }
    .content { padding:20px 15px; }
}

@media(max-width:640px) {
    .form-grid { grid-template-columns:1fr; }
    .form-group.full { grid-column:auto; }
    .form-body { padding:20px; }
    .form-actions { padding:16px 20px; flex-direction:column-reverse; }
    .btn { width:100%; }
}

@media(max-width:520px) {
    .back-btn { top:15px; left:15px; width:38px; height:38px; }
    .topbar { height:70px; padding:0 14px 0 65px; }
    .form-card-head { padding:18px 20px; }
}
</style>
</head>

<body>

<button type="button" class="back-btn"
        onclick="goBack()"
        title="Back to Manage Users"
        aria-label="Back to Manage Users">
    <svg viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round">
        <path d="M18 6L6 18"></path>
        <path d="M6 6l12 12"></path>
    </svg>
</button>

<main class="main">

<header class="topbar">
    <div class="page-heading">
        <h1>Create Account</h1>
        <p>Add a new student or professor account to NoteSphere.</p>
    </div>
</header>

<section class="content">

    <div class="form-card">

        <div class="form-card-head">
            <div class="form-card-icon">
                <svg viewBox="0 0 24 24" fill="none"
                     stroke-width="2" stroke-linecap="round"
                     stroke-linejoin="round">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M19 8v6"></path>
                    <path d="M22 11h-6"></path>
                </svg>
            </div>
            <div>
                <h2>Account Details</h2>
                <p>Choose the role first, then complete the required information.</p>
            </div>
        </div>

        <div class="form-body">

            <?php if ($error !== ''): ?>
                <div class="alert alert-error" role="alert">
                    <svg viewBox="0 0 24 24" fill="none"
                         stroke-width="2" stroke-linecap="round"
                         stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <path d="M12 8v4"></path>
                        <path d="M12 16h.01"></path>
                    </svg>
                    <span><?= h($error) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="alert alert-success" role="status">
                    <svg viewBox="0 0 24 24" fill="none"
                         stroke-width="2" stroke-linecap="round"
                         stroke-linejoin="round">
                        <path d="M22 11.1V12a10 10 0 1 1-5.9-9.1"></path>
                        <path d="M22 4L12 14l-3-3"></path>
                    </svg>
                    <span><?= h($success) ?></span>
                </div>
            <?php endif; ?>

            <form action="add_professor.php" method="POST" autocomplete="off" id="createAccountForm">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">

                <!-- ==============================
                     ROLE + PERSONAL INFORMATION
                =============================== -->

                <section class="form-section">

                    <div class="section-heading">
                        <svg viewBox="0 0 24 24" fill="none"
                             stroke-width="2" stroke-linecap="round"
                             stroke-linejoin="round">
                            <circle cx="12" cy="8" r="4"></circle>
                            <path d="M4 21a8 8 0 0 1 16 0"></path>
                        </svg>
                        <h3>Account Role &amp; Personal Information</h3>
                    </div>

                    <div class="form-grid">

                        <div class="form-group">
                            <label for="role" class="form-label">Account Role</label>
                            <select id="role" name="role" class="form-input" required>
                                <option value="student" <?= $old['role'] === 'student' ? 'selected' : '' ?>>Student</option>
                                <option value="professor" <?= $old['role'] === 'professor' ? 'selected' : '' ?>>Professor</option>
                            </select>
                            <div class="form-help">Roles are limited to Student and Professor.</div>
                        </div>

                        <div class="form-group">
                            <label for="full_name" class="form-label">Full Name</label>
                            <input type="text" id="full_name" name="full_name"
                                   class="form-input" placeholder="Enter full name"
                                   maxlength="150" value="<?= h($old['full_name']) ?>" required>
                        </div>

                        <div class="form-group full">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" id="email" name="email"
                                   class="form-input" placeholder="Enter email address"
                                   maxlength="255" value="<?= h($old['email']) ?>" required>
                            <div class="form-help">This email will be used for login and account notifications.</div>
                        </div>

                    </div>

                </section>

                <!-- ==============================
                     STUDENT ACADEMIC INFORMATION
                =============================== -->

                <section
                    class="form-section"
                    id="studentFields"
                    <?= $old['role'] === 'professor' ? 'hidden' : '' ?>
                >

                    <div class="section-heading">
                        <svg viewBox="0 0 24 24" fill="none"
                             stroke-width="2" stroke-linecap="round"
                             stroke-linejoin="round">
                            <path d="M2 10l10-5 10 5-10 5L2 10z"></path>
                            <path d="M6 12.5V17c2 2 10 2 12 0v-4.5"></path>
                            <path d="M22 10v6"></path>
                        </svg>
                        <h3>Academic Information</h3>
                    </div>

                    <div class="form-grid">

                        <div class="form-group">
                            <label for="institute" class="form-label">Institute</label>
                            <select id="institute" name="institute" class="form-input">
                                <option value="">Select Institute</option>
                                <?php foreach ($institutes as $institute_code => $institute_data): ?>
                                    <option value="<?= h($institute_code) ?>"
                                        <?= $old['institute'] === $institute_code ? 'selected' : '' ?>>
                                        <?= h($institute_code . ' - ' . $institute_data['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-help">Choose the institute first; the course list updates automatically.</div>
                        </div>

                        <div class="form-group">
                            <label for="course" class="form-label">Course</label>
                            <select id="course" name="course" class="form-input">
                                <?php
                                $selected_courses = [];
                                if ($old['institute'] !== '' && isset($institutes[$old['institute']])) {
                                    $selected_courses = $institutes[$old['institute']]['courses'];
                                }
                                ?>
                                <?php if ($selected_courses): ?>
                                    <option value="">Select Course</option>
                                    <?php foreach ($selected_courses as $course_code => $course_name): ?>
                                        <option value="<?= h($course_code) ?>"
                                            <?= $old['course'] === $course_code ? 'selected' : '' ?>>
                                            <?= h($course_name . ' (' . $course_code . ')') ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="">Select Institute first</option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="student_status" class="form-label">Student Status</label>
                            <select id="student_status" name="student_status" class="form-input">
                                <option value="Regular" <?= $old['student_status'] === 'Regular' ? 'selected' : '' ?>>Regular</option>
                                <option value="Irregular" <?= $old['student_status'] === 'Irregular' ? 'selected' : '' ?>>Irregular</option>
                            </select>
                        </div>

                        <div class="form-group" id="yearLevelGroup">
                            <label for="year_level" class="form-label">Year Level</label>
                            <select id="year_level" name="year_level" class="form-input">
                                <option value="">Select Year Level</option>
                                <?php foreach (['1' => '1st Year', '2' => '2nd Year', '3' => '3rd Year', '4' => '4th Year'] as $year_value => $year_label): ?>
                                    <option value="<?= h($year_value) ?>"
                                        <?= $old['year_level'] === $year_value ? 'selected' : '' ?>>
                                        <?= h($year_label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div
                            class="form-group"
                            id="regularSectionGroup"
                            <?= $old['student_status'] === 'Irregular' ? 'hidden' : '' ?>
                        >
                            <label for="regular_section" class="form-label">Section</label>
                            <select id="regular_section" name="regular_section" class="form-input">
                                <option value="">Select Section</option>
                                <?php foreach (['A', 'B', 'C', 'D', 'E'] as $section_code): ?>
                                    <option value="<?= h($section_code) ?>"
                                        <?= $old['student_status'] === 'Regular' && $old['section'] === $section_code ? 'selected' : '' ?>>
                                        <?= h($section_code) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-help">Regular students choose a block section from A to E.</div>
                        </div>

                        <div
                            class="form-group"
                            id="irregularSectionGroup"
                            <?= $old['student_status'] === 'Regular' ? 'hidden' : '' ?>
                        >
                            <label for="irregular_section" class="form-label">Section / Block</label>
                            <input type="text" id="irregular_section" name="irregular_section"
                                   class="form-input upper" placeholder="Enter section or block"
                                   maxlength="50"
                                   value="<?= $old['student_status'] === 'Irregular' ? h($old['section']) : '' ?>"
                                   oninput="forceUpperCase(this)">
                            <div class="form-help">Irregular students type their assigned or other section.</div>
                        </div>

                        <div class="form-group full">
                            <label for="subject_codes" class="form-label">Enrolled Subject Codes</label>
                            <input type="text" id="subject_codes" name="subject_codes"
                                   class="form-input upper" maxlength="1000"
                                   placeholder="Example: IT101, MATH101, GE 1"
                                   value="<?= h($old['subject_codes']) ?>"
                                   oninput="forceUpperCase(this)"
                                   aria-describedby="subjectHint subjectStatus">
                            <div class="form-help" id="subjectHint">
                                Separate each subject code with a comma. Example: IT101, MATH101, GE 1.
                            </div>
                            <span id="subjectStatus" class="subject-status" aria-live="polite"></span>
                        </div>

                    </div>

                </section>

                <!-- ==============================
                     ACCOUNT SECURITY
                =============================== -->

                <section class="form-section">

                    <div class="section-heading">
                        <svg viewBox="0 0 24 24" fill="none"
                             stroke-width="2" stroke-linecap="round"
                             stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                        <h3>Account Security</h3>
                    </div>

                    <div class="form-grid">

                        <div class="form-group">
                            <label for="password" class="form-label">Temporary Password</label>
                            <div class="form-input-wrap">
                                <input type="password" id="password" name="password"
                                       class="form-input password-input"
                                       placeholder="At least 8 characters"
                                       minlength="8" maxlength="72"
                                       autocomplete="new-password" required>
                                <button type="button" class="password-toggle"
                                        data-target="password"
                                        title="Show password"
                                        aria-label="Show password">
                                    <svg viewBox="0 0 24 24" fill="none"
                                         stroke-width="2" stroke-linecap="round"
                                         stroke-linejoin="round">
                                        <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"></path>
                                        <circle cx="12" cy="12" r="3"></circle>
                                    </svg>
                                </button>
                            </div>
                            <div class="form-help">Share this temporary password securely with the account owner.</div>
                        </div>

                        <div
                            class="form-group"
                            id="confirmPasswordGroup"
                            <?= $old['role'] === 'professor' ? 'hidden' : '' ?>
                        >
                            <label for="confirm_password" class="form-label">Confirm Temporary Password</label>
                            <div class="form-input-wrap">
                                <input type="password" id="confirm_password" name="confirm_password"
                                       class="form-input password-input"
                                       placeholder="Re-enter temporary password"
                                       minlength="8" maxlength="72"
                                       autocomplete="new-password">
                                <button type="button" class="password-toggle"
                                        data-target="confirm_password"
                                        title="Show password"
                                        aria-label="Show password">
                                    <svg viewBox="0 0 24 24" fill="none"
                                         stroke-width="2" stroke-linecap="round"
                                         stroke-linejoin="round">
                                        <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"></path>
                                        <circle cx="12" cy="12" r="3"></circle>
                                    </svg>
                                </button>
                            </div>
                            <div class="form-help">Required for student accounts only.</div>
                        </div>

                    </div>

                </section>

                <div class="form-actions">
                    <a href="manage_users.php" class="btn btn-cancel">Cancel</a>
                    <button type="submit" class="btn btn-save" id="submitBtn">
                        Create Account
                    </button>
                </div>

            </form>

        </div>

    </div>

</section>

</main>

<script>
/* =====================================================
   THEME SYNC (same key as the admin pages)
===================================================== */
(function applyNoteSphereTheme() {
    const savedTheme = localStorage.getItem('notesphere_admin_theme');
    document.body.classList.toggle('dark', savedTheme === 'dark');
})();

window.addEventListener('storage', function (event) {
    if (event.key === 'notesphere_admin_theme') {
        document.body.classList.toggle('dark', event.newValue === 'dark');
    }
});

/* =====================================================
   BACK
===================================================== */
function goBack() {
    window.location.href = 'manage_users.php';
}

/* =====================================================
   ELEMENTS
===================================================== */
const INSTITUTES = <?= json_encode($institutes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

const form = document.getElementById('createAccountForm');
const submitBtn = document.getElementById('submitBtn');
const roleSelect = document.getElementById('role');
const studentFields = document.getElementById('studentFields');
const instituteSelect = document.getElementById('institute');
const courseSelect = document.getElementById('course');
const statusSelect = document.getElementById('student_status');
const yearLevelSelect = document.getElementById('year_level');
const regularSectionGroup = document.getElementById('regularSectionGroup');
const irregularSectionGroup = document.getElementById('irregularSectionGroup');
const regularSection = document.getElementById('regular_section');
const irregularSection = document.getElementById('irregular_section');
const subjectInput = document.getElementById('subject_codes');
const subjectStatus = document.getElementById('subjectStatus');
const confirmPasswordGroup = document.getElementById('confirmPasswordGroup');
const confirmPassword = document.getElementById('confirm_password');

/* =====================================================
   UPPERCASE INPUTS
===================================================== */
function forceUpperCase(input) {
    const start = input.selectionStart;
    const end = input.selectionEnd;
    input.value = input.value.toUpperCase();
    if (start !== null && end !== null) {
        input.setSelectionRange(start, end);
    }
}

/* =====================================================
   COURSE OPTIONS
===================================================== */
function updateCourseOptions() {
    const institute = instituteSelect.value;
    const previous = courseSelect.value;
    courseSelect.innerHTML = '';

    if (!institute || !INSTITUTES[institute]) {
        courseSelect.add(new Option('Select Institute first', ''));
        return;
    }

    courseSelect.add(new Option('Select Course', ''));
    Object.keys(INSTITUTES[institute].courses).forEach(function (code) {
        courseSelect.add(
            new Option(INSTITUTES[institute].courses[code] + ' (' + code + ')', code)
        );
    });

    const hasPrevious = Array.from(courseSelect.options).some(function (option) {
        return option.value === previous;
    });
    if (previous && hasPrevious) courseSelect.value = previous;
}

/* =====================================================
   REGULAR / IRREGULAR SECTION
===================================================== */
function updateSectionFields() {
    const isRegular = statusSelect.value === 'Regular';
    const isIrregular = statusSelect.value === 'Irregular';

    regularSectionGroup.hidden = !isRegular;
    irregularSectionGroup.hidden = !isIrregular;

    regularSection.disabled = !isRegular;
    regularSection.required = isRegular;

    irregularSection.disabled = !isIrregular;
    irregularSection.required = isIrregular;

    if (!isRegular) regularSection.value = '';
    if (!isIrregular) irregularSection.value = '';
}

/* =====================================================
   ROLE FIELDS
===================================================== */
function updateRoleFields() {
    const isStudent = roleSelect.value === 'student';

    studentFields.hidden = !isStudent;

    ['institute', 'course', 'student_status', 'subject_codes', 'year_level'].forEach(function (id) {
        const field = document.getElementById(id);
        field.disabled = !isStudent;
        field.required = isStudent;
    });

    confirmPasswordGroup.hidden = !isStudent;
    confirmPassword.disabled = !isStudent;
    confirmPassword.required = isStudent;

    if (isStudent) {
        updateCourseOptions();
        updateSectionFields();
        checkSubjectCodes(false);
    } else {
        regularSection.disabled = true;
        regularSection.required = false;
        irregularSection.disabled = true;
        irregularSection.required = false;
    }
}

/* =====================================================
   SUBJECT-CODE VALIDATION
===================================================== */
function normalizeSubjectCodes(value) {
    return value
        .split(',')
        .map(function (code) { return code.trim().toUpperCase(); })
        .filter(function (code) { return code.length > 0; })
        .filter(function (code, index, array) { return array.indexOf(code) === index; });
}

function isValidSubjectCode(code) {
    return code.length <= 30 &&
        /^[A-Z][A-Z0-9]*(?:[ -][A-Z0-9]+)*$/.test(code);
}

function checkSubjectCodes(showEmptyMessage) {
    const rawValue = subjectInput.value.trim();

    if (rawValue === '') {
        subjectStatus.textContent = showEmptyMessage
            ? 'Please enter at least one subject code.'
            : '';
        subjectStatus.className = showEmptyMessage
            ? 'subject-status invalid'
            : 'subject-status';
        return false;
    }

    const codes = normalizeSubjectCodes(rawValue);

    if (codes.length === 0) {
        subjectStatus.textContent = 'Please enter at least one subject code.';
        subjectStatus.className = 'subject-status invalid';
        return false;
    }

    const invalidCodes = codes.filter(function (code) {
        return !isValidSubjectCode(code);
    });

    if (invalidCodes.length > 0) {
        subjectStatus.textContent = 'Check the format of: ' + invalidCodes.join(', ');
        subjectStatus.className = 'subject-status invalid';
        return false;
    }

    subjectStatus.textContent = codes.length +
        ' subject code(s) detected. Format looks valid.';
    subjectStatus.className = 'subject-status valid';
    return true;
}

/* =====================================================
   PASSWORD SHOW / HIDE
===================================================== */
const eyeOpen =
    '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"></path>' +
    '<circle cx="12" cy="12" r="3"></circle>';

const eyeClosed =
    '<path d="M3 3l18 18"></path>' +
    '<path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"></path>' +
    '<path d="M9.9 5.1A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a17.4 17.4 0 0 1-3.1 3.9"></path>' +
    '<path d="M6.6 6.6C3.7 8.4 2 12 2 12s3.5 7 10 7c1.7 0 3.2-.4 4.5-1"></path>';

document.querySelectorAll('.password-toggle').forEach(function (button) {
    button.addEventListener('click', function () {
        const target = document.getElementById(this.dataset.target);
        const icon = this.querySelector('svg');
        const showing = target.type === 'text';

        target.type = showing ? 'password' : 'text';
        icon.innerHTML = showing ? eyeOpen : eyeClosed;
        this.title = showing ? 'Show password' : 'Hide password';
        this.setAttribute('aria-label', this.title);
    });
});

/* =====================================================
   EVENTS
===================================================== */
roleSelect.addEventListener('change', updateRoleFields);
instituteSelect.addEventListener('change', updateCourseOptions);
statusSelect.addEventListener('change', updateSectionFields);

subjectInput.addEventListener('input', function () {
    checkSubjectCodes(false);
});

subjectInput.addEventListener('blur', function () {
    if (subjectInput.value.trim() !== '') checkSubjectCodes(false);
});

form.addEventListener('submit', function () {
    if (roleSelect.value === 'student' && subjectInput.value.trim() !== '') {
        subjectInput.value = normalizeSubjectCodes(subjectInput.value).join(', ');
    }

    submitBtn.disabled = true;
    submitBtn.textContent = 'Creating Account...';
});

/* =====================================================
   INITIALIZE
===================================================== */
updateRoleFields();

if (subjectInput.value.trim() !== '') {
    checkSubjectCodes(false);
}
</script>
</body>
</html>
