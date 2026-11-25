<?php
// =========================================================
// NoteSphere - Manage Users
// Complete Account Management Upgrade
// =========================================================

session_start();

require_once __DIR__ . '/../includes/db_config.php';

/** @var PDO $conn */

// =========================================================
// ADMIN SECURITY
// =========================================================

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    header('Location: ../auth/login.php');
    exit();
}

$current_admin_id = (int) $_SESSION['user_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

// =========================================================
// HELPERS
// =========================================================

function e($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function formatRole($role): string
{
    return [
        'admin' => 'Administrator',
        'professor' => 'Professor',
        'student' => 'Student'
    ][$role] ?? ucfirst((string)$role);
}

function getRoleClass($role): string
{
    return [
        'admin' => 'role-admin',
        'professor' => 'role-professor',
        'student' => 'role-student'
    ][$role] ?? 'role-default';
}

function getInitials($name): string
{
    $parts = preg_split('/\s+/', trim((string)$name));

    if (!$parts || !$parts[0]) {
        return 'U';
    }

    if (count($parts) === 1) {
        return strtoupper(substr($parts[0], 0, 1));
    }

    return strtoupper(
        substr($parts[0], 0, 1) .
        substr($parts[count($parts) - 1], 0, 1)
    );
}

function getUserDetails(array $user): string
{
    $role = $user['role'] ?? '';

    if ($role === 'student') {
        $details = [];

        if (!empty($user['institute'])) {
            $details[] = 'Institute: ' . $user['institute'];
        }

        if (!empty($user['student_status'])) {
            $details[] = $user['student_status'];
        }

        if (
            isset($user['year_level']) &&
            $user['year_level'] !== ''
        ) {
            $details[] = 'Year ' . $user['year_level'];
        }

        if (!empty($user['section'])) {
            $details[] = 'Section ' . $user['section'];
        }

        return $details
            ? implode(' • ', $details)
            : 'Student Account';
    }

    if ($role === 'professor') {
        return !empty($user['course'])
            ? 'Professor • ' . $user['course']
            : 'Professor Account';
    }

    return $role === 'admin'
        ? 'Administrator Account'
        : 'System Account';
}

function redirectWithMessage(
    string $icon,
    string $title,
    string $message
): void {
    $_SESSION['swal'] = [
        'icon' => $icon,
        'title' => $title,
        'text' => $message
    ];

    header('Location: manage_users.php');
    exit();
}

function getUsersColumns(PDO $conn): array
{
    $columns = [];

    $stmt = $conn->query('SHOW COLUMNS FROM users');

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[] = $column['Field'];
    }

    return $columns;
}

// =========================================================
// GET CURRENT ADMIN
// =========================================================

$admin_name = 'Administrator';

try {
    $stmt = $conn->prepare(
        'SELECT full_name FROM users WHERE user_id = ? LIMIT 1'
    );

    $stmt->execute([$current_admin_id]);

    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!empty($admin['full_name'])) {
        $admin_name = $admin['full_name'];
    }
} catch (PDOException $ex) {
    error_log('Manage Users - Admin: ' . $ex->getMessage());
}

// =========================================================
// CREATE ACCOUNT
// Supports student, professor, and administrator.
//
// Optional academic fields are inserted only if their
// columns exist in the current users table.
// =========================================================

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['create_account'])
) {
    $posted_token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($posted_token) ||
        !hash_equals($csrf_token, $posted_token)
    ) {
        redirectWithMessage(
            'error',
            'Security Error',
            'Invalid security token. Please try again.'
        );
    }

    $name = trim($_POST['account_name'] ?? '');
    $email = trim($_POST['account_email'] ?? '');
    $role = $_POST['account_role'] ?? '';
    $password = $_POST['temporary_password'] ?? '';

    $allowed_roles = ['student', 'professor', 'admin'];

    if ($name === '' || mb_strlen($name) > 150) {
        redirectWithMessage(
            'warning',
            'Invalid Name',
            'Enter a name containing no more than 150 characters.'
        );
    }

    if (
        $email === '' ||
        strlen($email) > 255 ||
        !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {
        redirectWithMessage(
            'warning',
            'Invalid Email',
            'Enter a valid email address.'
        );
    }

    if (!in_array($role, $allowed_roles, true)) {
        redirectWithMessage(
            'warning',
            'Invalid Role',
            'Select a valid account role.'
        );
    }

    if (
        !is_string($password) ||
        strlen($password) < 8 ||
        strlen($password) > 72
    ) {
        redirectWithMessage(
            'warning',
            'Invalid Password',
            'Use a temporary password between 8 and 72 bytes.'
        );
    }

    $course = trim($_POST['course'] ?? '');
    $year_level = trim($_POST['year_level'] ?? '');
    $section = trim($_POST['section'] ?? '');

    if (
        $year_level !== '' &&
        !in_array($year_level, ['1', '2', '3', '4', '5'], true)
    ) {
        redirectWithMessage(
            'warning',
            'Invalid Year Level',
            'Select a valid year level.'
        );
    }

    try {
        $conn->beginTransaction();

        // Check email before inserting.
        $emailCheck = $conn->prepare(
            'SELECT user_id FROM users WHERE email = ? LIMIT 1'
        );

        $emailCheck->execute([$email]);

        if ($emailCheck->fetch(PDO::FETCH_ASSOC)) {
            $conn->rollBack();

            redirectWithMessage(
                'warning',
                'Email Already Registered',
                'This email is already associated with an account.'
            );
        }

        $available_columns = getUsersColumns($conn);

        // These columns are required by this account-creation flow.
        $required_columns = [
            'full_name',
            'email',
            'password',
            'role'
        ];

        foreach ($required_columns as $required_column) {
            if (!in_array(
                $required_column,
                $available_columns,
                true
            )) {
                throw new RuntimeException(
                    'Missing required users column: ' . $required_column
                );
            }
        }

        $fields = [
            'full_name' => $name,
            'email' => $email,
            'password' => password_hash(
                $password,
                PASSWORD_DEFAULT
            ),
            'role' => $role
        ];

        // Keep the existing account status convention.
        if (in_array('account_status', $available_columns, true)) {
            $fields['account_status'] = 'active';
        }

        // Add optional academic details only if supported by the DB.
        if (
            $role === 'student' &&
            in_array('course', $available_columns, true)
        ) {
            $fields['course'] = $course !== '' ? $course : null;
        }

        if (
            $role === 'student' &&
            in_array('year_level', $available_columns, true)
        ) {
            $fields['year_level'] = $year_level !== ''
                ? $year_level
                : null;
        }

        if (
            $role === 'student' &&
            in_array('section', $available_columns, true)
        ) {
            $fields['section'] = $section !== ''
                ? $section
                : null;
        }

        // Optional student status, if the column exists.
        if (
            $role === 'student' &&
            in_array('student_status', $available_columns, true)
        ) {
            $fields['student_status'] = 'active';
        }

        $insert_columns = array_keys($fields);
        $quoted_columns = array_map(
            static fn($column) => '`' . $column . '`',
            $insert_columns
        );

        $placeholders = implode(
            ', ',
            array_fill(0, count($insert_columns), '?')
        );

        $sql = 'INSERT INTO users (' .
            implode(', ', $quoted_columns) .
            ') VALUES (' . $placeholders . ')';

        $insert = $conn->prepare($sql);
        $insert->execute(array_values($fields));
        $newUserId = (int)$conn->lastInsertId();

        // Notify the new account
        try {
            $n = $conn->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?,?,?,?)");
            $n->execute([$newUserId, 'account', 'Welcome to NoteSphere', 'Your account has been created.']);
        } catch (Throwable $e) {}

        // Send welcome email
        try {
            require_once __DIR__ . '/../includes/PHPMailer-master/PHPMailer-master/src/Exception.php';
            require_once __DIR__ . '/../includes/PHPMailer-master/PHPMailer-master/src/PHPMailer.php';
            require_once __DIR__ . '/../includes/PHPMailer-master/PHPMailer-master/src/SMTP.php';
            $mc = require __DIR__ . '/../includes/mail_config.php';
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->SMTPDebug = 2;
            $mail->Debugoutput = 'error_log';
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
            error_log('Welcome email sent to: ' . $email);
        } catch (Throwable $e) {
            error_log('Email send failed: ' . $e->getMessage());
            $_SESSION['email_error'] = $e->getMessage();
        }

        $conn->commit();

        $emailNote = empty($_SESSION['email_error'])
            ? ' Welcome email sent.'
            : ' Email FAILED: ' . $_SESSION['email_error'];
        unset($_SESSION['email_error']);
        redirectWithMessage(
            'success',
            'Account Created',
            $name . ' has been added as ' . formatRole($role) . '.' . $emailNote
        );

    } catch (Throwable $ex) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        error_log(
            'Manage Users - Create Account: ' . $ex->getMessage()
        );

        redirectWithMessage(
            'error',
            'Account Creation Failed',
            'The account could not be created. Check the database schema and required fields.'
        );
    }
}

// =========================================================
// DELETE USER
// =========================================================

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['delete_user'])
) {
    $posted_token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($posted_token) ||
        !hash_equals($csrf_token, $posted_token)
    ) {
        redirectWithMessage(
            'error',
            'Security Error',
            'Invalid security token. Please try again.'
        );
    }

    $delete_user_id = filter_var(
        $_POST['user_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (!$delete_user_id || $delete_user_id <= 0) {
        redirectWithMessage(
            'error',
            'Invalid User',
            'The selected user is invalid.'
        );
    }

    if ((int)$delete_user_id === $current_admin_id) {
        redirectWithMessage(
            'warning',
            'Action Not Allowed',
            'You cannot delete your own administrator account.'
        );
    }

    try {
        $check = $conn->prepare(
            'SELECT user_id, full_name, role
             FROM users WHERE user_id = ? LIMIT 1'
        );

        $check->execute([(int)$delete_user_id]);

        $target = $check->fetch(PDO::FETCH_ASSOC);

        if (!$target) {
            redirectWithMessage(
                'error',
                'User Not Found',
                'The selected account no longer exists.'
            );
        }

        $delete = $conn->prepare(
            'DELETE FROM users WHERE user_id = ? LIMIT 1'
        );

        $delete->execute([(int)$delete_user_id]);

        if ($delete->rowCount() > 0) {
            redirectWithMessage(
                'success',
                'User Deleted',
                $target['full_name'] . ' has been removed.'
            );
        }

        redirectWithMessage(
            'error',
            'Delete Failed',
            'The account could not be deleted.'
        );

    } catch (PDOException $ex) {
        error_log(
            'Manage Users - Delete: ' . $ex->getMessage()
        );

        redirectWithMessage(
            'error',
            'Delete Failed',
            'The account may be linked to existing system records.'
        );
    }
}

// =========================================================
// FILTERS
// =========================================================

$search = trim($_GET['search'] ?? '');
$role_filter = $_GET['role'] ?? 'all';

$allowed_filters = [
    'all',
    'student',
    'professor',
    'admin'
];

if (!in_array($role_filter, $allowed_filters, true)) {
    $role_filter = 'all';
}

// =========================================================
// COUNTS AND USER LIST
// =========================================================

$total_users = 0;
$total_students = 0;
$total_professors = 0;
$total_admins = 0;

$users = [];
$load_error = false;

try {
    $countStmt = $conn->query(
        "SELECT
            COUNT(*) AS total_users,
            COALESCE(SUM(role = 'student'), 0) AS total_students,
            COALESCE(SUM(role = 'professor'), 0) AS total_professors,
            COALESCE(SUM(role = 'admin'), 0) AS total_admins
         FROM users"
    );

    $counts = $countStmt->fetch(PDO::FETCH_ASSOC);

    if ($counts) {
        $total_users = (int)$counts['total_users'];
        $total_students = (int)$counts['total_students'];
        $total_professors = (int)$counts['total_professors'];
        $total_admins = (int)$counts['total_admins'];
    }

    $available_columns = getUsersColumns($conn);

    $display_columns = [
        'user_id',
        'full_name',
        'email',
        'institute',
        'course',
        'student_status',
        'year_level',
        'section',
        'enrolled_subjects',
        'role',
        'warning_count',
        'account_status',
        'created_at'
    ];

    $select_columns = [];

    foreach ($display_columns as $column) {
        if (in_array($column, $available_columns, true)) {
            $select_columns[] = '`' . $column . '`';
        }
    }

    if (
        !in_array('user_id', $available_columns, true) ||
        !in_array('full_name', $available_columns, true) ||
        !in_array('email', $available_columns, true) ||
        !in_array('role', $available_columns, true)
    ) {
        throw new RuntimeException(
            'The users table is missing required display columns.'
        );
    }

    $sql = 'SELECT ' . implode(', ', $select_columns) .
        ' FROM users WHERE 1=1';

    $params = [];

    if ($role_filter !== 'all') {
        $sql .= ' AND role = ?';
        $params[] = $role_filter;
    }

    if ($search !== '') {
        $searchable_columns = [
            'full_name',
            'email',
            'institute',
            'course',
            'student_status',
            'year_level',
            'section',
            'enrolled_subjects',
            'role',
            'account_status'
        ];

        $conditions = [];

        foreach ($searchable_columns as $column) {
            if (in_array($column, $available_columns, true)) {
                $conditions[] = '`' . $column . '` LIKE ?';
                $params[] = '%' . $search . '%';
            }
        }

        if ($conditions) {
            $sql .= ' AND (' . implode(' OR ', $conditions) . ')';
        }
    }

    $sql .= "
        ORDER BY
            CASE
                WHEN role = 'admin' THEN 1
                WHEN role = 'professor' THEN 2
                WHEN role = 'student' THEN 3
                ELSE 4
            END,
            full_name ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $ex) {
    $load_error = true;

    error_log(
        'Manage Users - Load: ' . $ex->getMessage()
    );
}

// =========================================================
// SWEETALERT MESSAGE
// =========================================================

$swal = $_SESSION['swal'] ?? null;
unset($_SESSION['swal']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Manage Users | NoteSphere</title>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
:root {
    --primary:#233961;
    --primary-light:#3D8BFF;
    --bg:#f5f7fb;
    --card:#fff;
    --text:#000000;
    --muted:#000000;
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
.stat-icon svg,
.search-icon svg,
.action-btn svg,
.modal-icon svg,
.modal-close svg,
.password-toggle svg,
.save-professor-btn svg,
.plus-icon svg {
    width:20px;
    height:20px;
    stroke:currentColor;
}

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

.content-header {
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:20px;
    margin-bottom:24px;
}

.content-title h2 { font-size:25px; font-weight:800; }
.content-title p { color:var(--muted); font-size:13px; margin-top:6px; }

.add-professor-btn {
    border:none;
    background:linear-gradient(135deg,#233961,#3D8BFF);
    color:#fff;
    padding:12px 18px;
    border-radius:11px;
    font-size:13px;
    font-weight:800;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    box-shadow:0 7px 18px rgba(35,57,97,.18);
}

.add-professor-btn:hover { transform:translateY(-1px); }

.plus-icon {
    width:21px;
    height:21px;
    border-radius:50%;
    background:rgba(255,255,255,.2);
    display:flex;
    align-items:center;
    justify-content:center;
}

.plus-icon svg { width:14px; height:14px; }

.stats {
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:18px;
    margin-bottom:22px;
}

.stat-card {
    background:var(--card);
    border:1px solid var(--border);
    border-radius:var(--radius);
    padding:19px;
    box-shadow:var(--shadow);
    display:flex;
    align-items:center;
    gap:14px;
}

.stat-icon {
    width:48px;
    height:48px;
    border-radius:13px;
    background:rgba(61,139,255,.12);
    color:var(--primary-light);
    display:flex;
    align-items:center;
    justify-content:center;
    flex-shrink:0;
}

.stat-number { font-size:23px; font-weight:800; line-height:1; }
.stat-label { color:var(--muted); font-size:12px; margin-top:6px; }

.users-card {
    background:var(--card);
    border:1px solid var(--border);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    overflow:hidden;
}

.toolbar {
    padding:20px;
    border-bottom:1px solid var(--border);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
    flex-wrap:wrap;
}

.search-form {
    display:flex;
    gap:9px;
    flex:1;
    min-width:260px;
    max-width:540px;
}

.search-box { position:relative; flex:1; }

.search-box input {
    width:100%;
    height:43px;
    border:1px solid var(--border);
    border-radius:10px;
    padding:0 14px 0 40px;
    outline:none;
    background:var(--card);
    color:var(--text);
    font-size:13px;
}

.search-box input:focus,
.form-input:focus {
    border-color:var(--primary-light);
    box-shadow:0 0 0 3px rgba(61,139,255,.1);
}

.search-icon {
    position:absolute;
    left:14px;
    top:50%;
    transform:translateY(-50%);
    color:var(--muted);
    display:flex;
}

.search-icon svg { width:17px; height:17px; }

.search-btn,.clear-btn {
    height:43px;
    padding:0 16px;
    border-radius:10px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:13px;
    font-weight:700;
    cursor:pointer;
}

.search-btn { border:none; background:var(--primary); color:#fff; }
.clear-btn { border:1px solid var(--border); background:var(--card); color:var(--text); }

.role-tabs { display:flex; gap:7px; flex-wrap:wrap; }

.role-tab {
    padding:9px 13px;
    border:1px solid var(--border);
    border-radius:9px;
    color:var(--muted);
    font-size:12px;
    font-weight:700;
    background:var(--card);
}

.role-tab:hover { color:var(--primary-light); border-color:var(--primary-light); }
.role-tab.active { background:var(--primary); border-color:var(--primary); color:#fff; }

.table-wrap { width:100%; overflow-x:auto; }

table { width:100%; border-collapse:collapse; min-width:900px; }
thead { background:rgba(148,163,184,.07); }

th {
    text-align:left;
    padding:14px 18px;
    color:var(--muted);
    font-size:10px;
    text-transform:uppercase;
    letter-spacing:.6px;
    font-weight:800;
    white-space:nowrap;
    border-bottom:1px solid var(--border);
}

td {
    padding:15px 18px;
    border-bottom:1px solid var(--border);
    vertical-align:middle;
    font-size:13px;
}

tbody tr:hover { background:rgba(61,139,255,.035); }
tbody tr:last-child td { border-bottom:none; }

.user-cell { display:flex; align-items:center; gap:12px; min-width:230px; }

.user-avatar {
    width:43px;
    height:43px;
    border-radius:50%;
    background:linear-gradient(135deg,#233961,#3D8BFF);
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:13px;
    font-weight:800;
    flex-shrink:0;
}

.user-name { font-weight:800; line-height:1.3; }
.user-id { color:var(--muted); font-size:10px; margin-top:3px; }
.email { color:var(--muted); font-size:12px; word-break:break-word; max-width:220px; }
.course { font-weight:700; font-size:12px; }
.details,.subjects { color:var(--muted); font-size:10px; margin-top:4px; line-height:1.5; }
.subjects { max-width:220px; }

.role-badge {
    display:inline-flex;
    align-items:center;
    padding:6px 9px;
    border-radius:7px;
    font-size:10px;
    font-weight:800;
    white-space:nowrap;
}

.role-admin { color:#7c3aed; background:rgba(124,58,237,.1); }
.role-professor { color:#0369a1; background:rgba(3,105,161,.1); }
.role-student { color:#15803d; background:rgba(21,128,61,.1); }
.role-default { color:var(--muted); background:rgba(100,116,139,.1); }

.status-badge {
    display:inline-flex;
    padding:5px 8px;
    border-radius:7px;
    font-size:10px;
    font-weight:800;
    text-transform:capitalize;
}

.status-active { color:#15803d; background:rgba(21,128,61,.1); }
.status-inactive { color:#b91c1c; background:rgba(220,38,38,.1); }
.date { color:var(--muted); font-size:11px; white-space:nowrap; }

.actions { display:flex; align-items:center; gap:7px; white-space:nowrap; }

.action-btn {
    width:35px;
    height:35px;
    border-radius:9px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border:1px solid var(--border);
    background:var(--card);
    cursor:pointer;
}

.action-btn svg { width:16px; height:16px; }
.edit-btn { color:var(--primary-light); }
.delete-btn { color:var(--danger); }
.edit-btn:hover { background:rgba(61,139,255,.1); }
.delete-btn:hover { background:rgba(220,38,38,.08); }

.empty { padding:65px 20px; text-align:center; }

.empty-icon {
    width:64px;
    height:64px;
    margin:0 auto 14px;
    border-radius:18px;
    background:rgba(61,139,255,.1);
    color:var(--primary-light);
    display:flex;
    align-items:center;
    justify-content:center;
}

.empty-icon svg { width:30px; height:30px; }
.empty h3 { font-size:16px; font-weight:800; }
.empty p { color:var(--muted); font-size:12px; margin-top:6px; }

.load-error {
    margin:20px;
    padding:14px 16px;
    border-radius:10px;
    color:#b91c1c;
    background:rgba(220,38,38,.08);
    border:1px solid rgba(220,38,38,.2);
    font-size:13px;
}

.modal-overlay {
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.58);
    backdrop-filter:blur(5px);
    display:none;
    align-items:center;
    justify-content:center;
    padding:20px;
    z-index:5000;
    overflow-y:auto;
}

.modal-overlay.show { display:flex; }

.professor-modal {
    width:100%;
    max-width:500px;
    max-height:calc(100vh - 40px);
    overflow-y:auto;
    background:var(--card);
    border:1px solid var(--border);
    border-radius:20px;
    box-shadow:0 25px 70px rgba(0,0,0,.25);
}

.modal-header {
    padding:22px 24px;
    border-bottom:1px solid var(--border);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
}

.modal-header-left { display:flex; align-items:center; gap:13px; }

.modal-icon {
    width:45px;
    height:45px;
    border-radius:13px;
    background:rgba(61,139,255,.12);
    color:var(--primary-light);
    display:flex;
    align-items:center;
    justify-content:center;
}

.modal-title { font-size:18px; font-weight:800; }
.modal-subtitle { font-size:11px; color:var(--muted); margin-top:3px; }

.modal-close {
    width:35px;
    height:35px;
    border:none;
    border-radius:9px;
    background:rgba(100,116,139,.08);
    color:var(--muted);
    cursor:pointer;
    display:flex;
    align-items:center;
    justify-content:center;
}

.modal-body { padding:24px; }
.form-group { margin-bottom:17px; }
.form-group:last-child { margin-bottom:0; }

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
}

select.form-input { cursor:pointer; }
select.form-input option { background:var(--card); color:var(--text); }
.password-input { padding-right:48px; }

.form-input-wrap { position:relative; }

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
}

.password-toggle svg { width:18px; height:18px; }

.form-help {
    color:var(--muted);
    font-size:10px;
    margin-top:5px;
    line-height:1.5;
}

.modal-footer {
    padding:18px 24px;
    border-top:1px solid var(--border);
    display:flex;
    justify-content:flex-end;
    gap:9px;
}

.modal-btn {
    min-height:42px;
    padding:0 17px;
    border-radius:10px;
    font-size:12px;
    font-weight:800;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    justify-content:center;
}

.cancel-modal-btn {
    border:1px solid var(--border);
    background:var(--card);
    color:var(--text);
}

.save-professor-btn {
    border:none;
    background:var(--primary);
    color:#fff;
    gap:5px;
}

.save-professor-btn svg { width:15px; height:15px; }

@media(max-width:1100px) {
    .stats { grid-template-columns:repeat(2,minmax(0,1fr)); }
}

@media(max-width:800px) {
    .topbar { padding:0 18px 0 72px; }
    .content { padding:20px 15px; }
    .stats { grid-template-columns:1fr; }
    .content-header { flex-direction:column; }
    .add-professor-btn { width:100%; }
}

@media(max-width:520px) {
    .back-btn { top:15px; left:15px; width:38px; height:38px; }
    .topbar { height:70px; padding:0 14px 0 65px; }
    .search-form { min-width:100%; max-width:none; flex-wrap:wrap; }
    .search-box { min-width:100%; }
    .search-btn,.clear-btn { flex:1; }
    .role-tabs { width:100%; }
    .role-tab { flex:1; text-align:center; }
    .modal-body { padding:20px; }
    .modal-footer { padding:16px 20px; flex-direction:column-reverse; }
    .modal-btn { width:100%; }
}
</style>
</head>

<body>

<button type="button" class="back-btn"
        onclick="goBack()"
        title="Back to Admin Dashboard"
        aria-label="Back to Admin Dashboard">
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
        <h1>Manage Users</h1>
        <p>Manage student, professor, and administrator accounts.</p>
    </div>
</header>

<section class="content">

    <div class="content-header">
        <div class="content-title">
            <h2>User Accounts</h2>
            <p>Create, view, search, edit, and remove user accounts.</p>
        </div>

        <button type="button" class="add-professor-btn"
                onclick="window.location.href='add_professor.php'">
            <span class="plus-icon">
                <svg viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5"
                     stroke-linecap="round">
                    <path d="M12 5v14"></path>
                    <path d="M5 12h14"></path>
                </svg>
            </span>
            Create Account
        </button>
    </div>

    <div class="stats">

        <div class="stat-card">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none"
                     stroke-width="2" stroke-linecap="round"
                     stroke-linejoin="round">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
            </div>
            <div>
                <div class="stat-number"><?= number_format($total_users); ?></div>
                <div class="stat-label">Total Users</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none"
                     stroke-width="2" stroke-linecap="round"
                     stroke-linejoin="round">
                    <path d="M2 10l10-5 10 5-10 5L2 10z"></path>
                    <path d="M6 12.5V17c2 2 10 2 12 0v-4.5"></path>
                    <path d="M22 10v6"></path>
                </svg>
            </div>
            <div>
                <div class="stat-number"><?= number_format($total_students); ?></div>
                <div class="stat-label">Students</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none"
                     stroke-width="2" stroke-linecap="round"
                     stroke-linejoin="round">
                    <circle cx="12" cy="7" r="4"></circle>
                    <path d="M4 21a8 8 0 0 1 16 0"></path>
                </svg>
            </div>
            <div>
                <div class="stat-number"><?= number_format($total_professors); ?></div>
                <div class="stat-label">Professors</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24" fill="none"
                     stroke-width="2" stroke-linecap="round"
                     stroke-linejoin="round">
                    <path d="M12 3l7 4v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V7l7-4z"></path>
                    <path d="M9 12l2 2 4-4"></path>
                </svg>
            </div>
            <div>
                <div class="stat-number"><?= number_format($total_admins); ?></div>
                <div class="stat-label">Administrators</div>
            </div>
        </div>

    </div>

    <div class="users-card">

        <div class="toolbar">

            <form method="GET" action="manage_users.php"
                  class="search-form">

                <input type="hidden" name="role"
                       value="<?= e($role_filter); ?>">

                <div class="search-box">
                    <span class="search-icon">
                        <svg viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2"
                             stroke-linecap="round"
                             stroke-linejoin="round">
                            <circle cx="11" cy="11" r="7"></circle>
                            <path d="M20 20l-4-4"></path>
                        </svg>
                    </span>

                    <input type="text" name="search"
                           placeholder="Search name, email, course, section..."
                           value="<?= e($search); ?>"
                           autocomplete="off">
                </div>

                <button type="submit" class="search-btn">Search</button>

                <?php if ($search !== '' || $role_filter !== 'all'): ?>
                    <a href="manage_users.php" class="clear-btn">Clear</a>
                <?php endif; ?>

            </form>

            <div class="role-tabs">
                <?php
                $roles = [
                    'all' => 'All',
                    'student' => 'Students',
                    'professor' => 'Professors',
                    'admin' => 'Admins'
                ];

                foreach ($roles as $roleKey => $roleLabel):
                    $url = 'manage_users.php?role=' . urlencode($roleKey);

                    if ($search !== '') {
                        $url .= '&search=' . urlencode($search);
                    }
                ?>
                    <a href="<?= e($url); ?>"
                       class="role-tab <?= $role_filter === $roleKey ? 'active' : ''; ?>">
                        <?= e($roleLabel); ?>
                    </a>
                <?php endforeach; ?>
            </div>

        </div>

        <?php if ($load_error): ?>

            <div class="load-error">
                <strong>Unable to load user accounts.</strong>
                Check the PHP error log and confirm the database schema.
            </div>

        <?php elseif (!empty($users)): ?>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Email</th>
                            <th>Academic Information</th>
                            <th>Role</th>
                            <th>Account Status</th>
                            <th>Registered</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($users as $user): ?>

                        <?php
                        $user_id = (int)$user['user_id'];

                        $full_name = trim($user['full_name'] ?? '');

                        if ($full_name === '') {
                            $full_name = 'Unnamed User';
                        }

                        $role = $user['role'] ?? '';

                        $details = getUserDetails($user);

                        $account_status = $user['account_status'] ?? 'unknown';

                        $status_class =
                            strtolower((string)$account_status) === 'active'
                            ? 'status-active'
                            : 'status-inactive';
                        ?>

                        <tr>

                            <td>
                                <div class="user-cell">
                                    <div class="user-avatar">
                                        <?= e(getInitials($full_name)); ?>
                                    </div>

                                    <div>
                                        <div class="user-name">
                                            <?= e($full_name); ?>
                                        </div>
                                        <div class="user-id">
                                            User ID: <?= $user_id; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <td>
                                <div class="email">
                                    <?= e($user['email'] ?? '—'); ?>
                                </div>
                            </td>

                            <td>
                                <?php if (!empty($user['course'])): ?>
                                    <div class="course">
                                        <?= e($user['course']); ?>
                                    </div>
                                <?php endif; ?>

                                <div class="details"><?= e($details); ?></div>

                                <?php if (!empty($user['enrolled_subjects'])): ?>
                                    <div class="subjects">
                                        Subjects: <?= e($user['enrolled_subjects']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="role-badge <?= e(getRoleClass($role)); ?>">
                                    <?= e(formatRole($role)); ?>
                                </span>
                            </td>

                            <td>
                                <span class="status-badge <?= e($status_class); ?>">
                                    <?= e($account_status); ?>
                                </span>
                            </td>

                            <td>
                                <?php
                                $created_at = $user['created_at'] ?? '';
                                $registered = 'Not recorded';

                                if ($created_at !== '') {
                                    $timestamp = strtotime((string)$created_at);

                                    if ($timestamp !== false) {
                                        $registered = date('M j, Y', $timestamp);
                                    }
                                }
                                ?>
                                <div class="date"><?= e($registered); ?></div>
                            </td>

                            <td>
                                <div class="actions">

                                    <button type="button"
                                            class="action-btn edit-btn"
                                            title="Edit User"
                                            onclick='confirmEdit(
                                                <?= $user_id; ?>,
                                                <?= json_encode(
                                                    $full_name,
                                                    JSON_HEX_TAG |
                                                    JSON_HEX_APOS |
                                                    JSON_HEX_AMP |
                                                    JSON_HEX_QUOT
                                                ); ?>
                                            )'>
                                        <svg viewBox="0 0 24 24" fill="none"
                                             stroke="currentColor" stroke-width="2"
                                             stroke-linecap="round"
                                             stroke-linejoin="round">
                                            <path d="M12 20h9"></path>
                                            <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4L16.5 3.5z"></path>
                                        </svg>
                                    </button>

                                    <button type="button"
                                            class="action-btn delete-btn"
                                            title="<?= $user_id === $current_admin_id ? 'You cannot delete your own account' : 'Delete User'; ?>"
                                            onclick='confirmDelete(
                                                    <?= $user_id; ?>,
                                                    <?= json_encode(
                                                        $full_name,
                                                        JSON_HEX_TAG |
                                                        JSON_HEX_APOS |
                                                        JSON_HEX_AMP |
                                                        JSON_HEX_QUOT
                                                    ); ?>
                                                )'>
                                            <svg viewBox="0 0 24 24" fill="none"
                                                 stroke="currentColor" stroke-width="2"
                                                 stroke-linecap="round"
                                                 stroke-linejoin="round">
                                                <path d="M3 6h18"></path>
                                                <path d="M8 6V4h8v2"></path>
                                                <path d="M19 6l-1 15H6L5 6"></path>
                                                <path d="M10 11v6"></path>
                                                <path d="M14 11v6"></path>
                                            </svg>
                                        </button>

                                </div>
                            </td>

                        </tr>

                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>

            <div class="empty">
                <div class="empty-icon">
                    <svg viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                    </svg>
                </div>

                <h3>No Users Found</h3>

                <p>
                    <?php if ($search !== ''): ?>
                        No user matched your search:
                        <strong><?= e($search); ?></strong>
                    <?php elseif ($role_filter !== 'all'): ?>
                        There are no <?= e(formatRole($role_filter)); ?>
                        accounts yet.
                    <?php else: ?>
                        No user accounts are available.
                    <?php endif; ?>
                </p>
            </div>

        <?php endif; ?>

    </div>

</section>
</main>

<!-- CREATE ACCOUNT MODAL -->

<div class="modal-overlay" id="professorModal">

    <div class="professor-modal" onclick="event.stopPropagation()">

        <div class="modal-header">

            <div class="modal-header-left">
                <div class="modal-icon">
                    <svg viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M3 21a6 6 0 0 1 12 0"></path>
                        <path d="M19 8v6"></path>
                        <path d="M16 11h6"></path>
                    </svg>
                </div>

                <div>
                    <div class="modal-title">Create Account</div>
                    <div class="modal-subtitle">
                        Add a new NoteSphere user.
                    </div>
                </div>
            </div>

            <button type="button" class="modal-close"
                    onclick="closeProfessorModal()"
                    title="Close" aria-label="Close">
                <svg viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2"
                     stroke-linecap="round">
                    <path d="M6 6l12 12"></path>
                    <path d="M18 6L6 18"></path>
                </svg>
            </button>

        </div>

        <form method="POST" action="manage_users.php"
              id="addProfessorForm" autocomplete="off">

            <input type="hidden" name="csrf_token"
                   value="<?= e($csrf_token); ?>">

            <input type="hidden" name="create_account" value="1">

            <div class="modal-body">

                <div class="form-group">
                    <label for="account_name" class="form-label">
                        Full Name
                    </label>

                    <input type="text" id="account_name"
                           name="account_name" class="form-input"
                           placeholder="Enter full name"
                           maxlength="150" required autocomplete="off">
                </div>

                <div class="form-group">
                    <label for="account_email" class="form-label">
                        Email Address
                    </label>

                    <input type="email" id="account_email"
                           name="account_email" class="form-input"
                           placeholder="Enter email address"
                           maxlength="255" required autocomplete="off">
                </div>

                <div class="form-group">
                    <label for="account_role" class="form-label">
                        Account Role
                    </label>

                    <select id="account_role" name="account_role"
                            class="form-input" required
                            onchange="updateAcademicFields()">
                        <option value="student">Student</option>
                        <option value="professor">Professor</option>
                        <option value="admin">Administrator</option>
                    </select>

                    <div class="form-help">
                        Assign administrator access only to trusted personnel.
                    </div>
                </div>

                <div id="academicFields">

                    <div class="form-group">
                        <label for="course" class="form-label">
                            Course / Program
                        </label>

                        <input type="text" id="course" name="course"
                               class="form-input"
                               placeholder="Enter course or program"
                               maxlength="150">
                    </div>

                    <div class="form-group">
                        <label for="year_level" class="form-label">
                            Year Level
                        </label>

                        <select id="year_level" name="year_level"
                                class="form-input">
                            <option value="">Select year level</option>
                            <option value="1">1st Year</option>
                            <option value="2">2nd Year</option>
                            <option value="3">3rd Year</option>
                            <option value="4">4th Year</option>
                            <option value="5">5th Year</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="section" class="form-label">
                            Section
                        </label>

                        <input type="text" id="section" name="section"
                               class="form-input"
                               placeholder="Enter section"
                               maxlength="100">
                    </div>

                </div>

                <div class="form-group">
                    <label for="temporary_password" class="form-label">
                        Temporary Password
                    </label>

                    <div class="form-input-wrap">
                        <input type="password"
                               id="temporary_password"
                               name="temporary_password"
                               class="form-input password-input"
                               placeholder="Enter temporary password"
                               minlength="8" maxlength="72"
                               required autocomplete="new-password">

                        <button type="button" class="password-toggle"
                                id="passwordToggle"
                                onclick="toggleProfessorPassword()"
                                title="Show password">

                            <svg id="passwordEyeIcon"
                                 viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2"
                                 stroke-linecap="round" stroke-linejoin="round">
                                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>

                        </button>
                    </div>

                    <div class="form-help">
                        Share the temporary password securely with the account owner.
                        This form does not send an email automatically.
                    </div>
                </div>

            </div>

            <div class="modal-footer">

                <button type="button"
                        class="modal-btn cancel-modal-btn"
                        onclick="closeProfessorModal()">
                    Cancel
                </button>

                <button type="submit"
                        class="modal-btn save-professor-btn">
                    <svg viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.5"
                         stroke-linecap="round">
                        <path d="M12 5v14"></path>
                        <path d="M5 12h14"></path>
                    </svg>
                    Create Account
                </button>

            </div>

        </form>
    </div>
</div>

<!-- DELETE FORM -->

<form method="POST" action="manage_users.php"
      id="deleteForm" style="display:none;">

    <input type="hidden" name="csrf_token"
           value="<?= e($csrf_token); ?>">

    <input type="hidden" name="user_id" id="deleteUserId" value="">

    <input type="hidden" name="delete_user" value="1">

</form>

<script>
// Theme synchronization
(function applyNoteSphereTheme() {
    const savedTheme = localStorage.getItem('notesphere_admin_theme');
    document.body.classList.toggle('dark', savedTheme === 'dark');
})();

window.addEventListener('storage', function(event) {
    if (event.key === 'notesphere_admin_theme') {
        document.body.classList.toggle('dark', event.newValue === 'dark');
    }
});

// Back button
function goBack() {
    window.location.href = 'admin_dashboard.php';
}

// Create account modal
const professorModal = document.getElementById('professorModal');

function openProfessorModal() {
    professorModal.classList.add('show');
    document.body.style.overflow = 'hidden';

    updateAcademicFields();

    setTimeout(function() {
        document.getElementById('account_name').focus();
    }, 100);
}

function closeProfessorModal() {
    professorModal.classList.remove('show');
    document.body.style.overflow = '';

    document.getElementById('addProfessorForm').reset();

    const passwordInput = document.getElementById('temporary_password');
    passwordInput.type = 'password';

    document.getElementById('passwordEyeIcon').innerHTML =
        '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"></path>' +
        '<circle cx="12" cy="12" r="3"></circle>';

    document.getElementById('passwordToggle').title = 'Show password';

    updateAcademicFields();
}

professorModal.addEventListener('click', function(event) {
    if (event.target === professorModal) {
        closeProfessorModal();
    }
});

document.addEventListener('keydown', function(event) {
    if (
        event.key === 'Escape' &&
        professorModal.classList.contains('show')
    ) {
        closeProfessorModal();
    }
});

// Show academic fields only for students.
function updateAcademicFields() {
    const role = document.getElementById('account_role').value;
    const academicFields = document.getElementById('academicFields');

    academicFields.style.display = role === 'student' ? 'block' : 'none';

    document.getElementById('course').disabled = role !== 'student';
    document.getElementById('year_level').disabled = role !== 'student';
    document.getElementById('section').disabled = role !== 'student';
}

// Show / hide password
function toggleProfessorPassword() {
    const input = document.getElementById('temporary_password');
    const icon = document.getElementById('passwordEyeIcon');
    const toggle = document.getElementById('passwordToggle');

    if (input.type === 'password') {
        input.type = 'text';

        icon.innerHTML =
            '<path d="M3 3l18 18"></path>' +
            '<path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"></path>' +
            '<path d="M9.9 5.1A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a17.4 17.4 0 0 1-3.1 3.9"></path>' +
            '<path d="M6.6 6.6C3.7 8.4 2 12 2 12s3.5 7 10 7c1.7 0 3.2-.4 4.5-1"></path>';

        toggle.title = 'Hide password';
    } else {
        input.type = 'password';

        icon.innerHTML =
            '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"></path>' +
            '<circle cx="12" cy="12" r="3"></circle>';

        toggle.title = 'Show password';
    }
}

// Edit confirmation
function confirmEdit(userId, userName) {
    Swal.fire({
        title: 'Edit User?',
        html: 'You are about to edit the account of<br><strong>' +
            escapeHtml(userName) + '</strong>.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Edit User',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#233961',
        cancelButtonColor: '#64748b',
        reverseButtons: true
    }).then(function(result) {
        if (result.isConfirmed) {
            window.location.href =
                'edit_user.php?user_id=' + encodeURIComponent(userId);
        }
    });
}

// Delete confirmation
function confirmDelete(userId, userName) {
    if (Number(userId) === <?= (int)$current_admin_id; ?>) {
        Swal.fire({
            icon: 'info',
            title: 'Account Protected',
            html: 'You cannot delete the account you are currently signed in with.',
            confirmButtonColor: '#233961'
        });
        return;
    }

    Swal.fire({
        title: 'Delete User?',
        html: 'You are about to permanently delete<br><strong>' +
            escapeHtml(userName) +
            '</strong>.<br><br>' +
            '<span style="color:#dc2626;font-size:13px;">' +
            'This action cannot be undone.</span>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Delete',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#64748b',
        reverseButtons: true
    }).then(function(result) {
        if (result.isConfirmed) {
            document.getElementById('deleteUserId').value = userId;
            document.getElementById('deleteForm').submit();
        }
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Initial state
updateAcademicFields();
</script>

<?php if ($swal): ?>
<script>
Swal.fire({
    icon: <?= json_encode($swal['icon'] ?? 'info'); ?>,
    title: <?= json_encode($swal['title'] ?? 'Notice'); ?>,
    text: <?= json_encode($swal['text'] ?? ''); ?>,
    confirmButtonColor: '#233961'
});
</script>
<?php endif; ?>
</body>
</html>
