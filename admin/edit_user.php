
<?php

// =========================================================
// NoteSphere - Edit User
// =========================================================

session_start();

require_once __DIR__ . '/../includes/db_config.php';

/** @var PDO $conn */

// =========================================================
// ADMIN SECURITY CHECK
// =========================================================

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    header("Location: ../auth/login.php");
    exit();
}

// =========================================================
// CSRF TOKEN
// =========================================================

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

// =========================================================
// HELPER FUNCTIONS
// =========================================================

function e($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function redirectWithMessage(
    string $message,
    string $type,
    int $user_id = 0
): void {
    $_SESSION['admin_message'] = $message;
    $_SESSION['admin_message_type'] = $type;

    if ($user_id > 0) {
        header('Location: edit_user.php?user_id=' . $user_id);
    } else {
        header('Location: manage_users.php');
    }

    exit();
}

// =========================================================
// GET USER ID
// =========================================================

$user_id = filter_input(
    INPUT_GET,
    'user_id',
    FILTER_VALIDATE_INT
);

$user_id = $user_id ? (int) $user_id : 0;

if ($user_id <= 0) {
    redirectWithMessage('Invalid user ID.', 'error');
}

// =========================================================
// UPDATE USER
// =========================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submitted_token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submitted_token) ||
        $submitted_token === '' ||
        !hash_equals($csrf_token, $submitted_token)
    ) {
        redirectWithMessage(
            'Invalid security token. Please try again.',
            'error',
            $user_id
        );
    }

    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $course = trim($_POST['course'] ?? '');
    $student_status = trim($_POST['student_status'] ?? '');
    $year_level_raw = trim((string) ($_POST['year_level'] ?? ''));
    $section = trim($_POST['section'] ?? '');
    $enrolled_subjects = trim($_POST['enrolled_subjects'] ?? '');
    $role = trim($_POST['role'] ?? '');

    // -----------------------------------------------------
    // VALIDATION
    // -----------------------------------------------------

    if ($full_name === '' || mb_strlen($full_name) > 150) {
        redirectWithMessage(
            'Full name is required and must not exceed 150 characters.',
            'error',
            $user_id
        );
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        redirectWithMessage(
            'Please enter a valid email address.',
            'error',
            $user_id
        );
    }

    if (strlen($email) > 255) {
        redirectWithMessage(
            'Email address must not exceed 255 characters.',
            'error',
            $user_id
        );
    }

    if (strlen($course) > 50) {
        redirectWithMessage(
            'Course must not exceed 50 characters.',
            'error',
            $user_id
        );
    }

    if (strlen($student_status) > 30) {
        redirectWithMessage(
            'Student status must not exceed 30 characters.',
            'error',
            $user_id
        );
    }

    if (strlen($section) > 255) {
        redirectWithMessage(
            'Section must not exceed 255 characters.',
            'error',
            $user_id
        );
    }

    // Year level is stored as an integer in the database.
    $year_level = null;

    if ($year_level_raw !== '') {
        if (!in_array($year_level_raw, ['1', '2', '3', '4'], true)) {
            redirectWithMessage(
                'Please select a valid year level.',
                'error',
                $user_id
            );
        }

        $year_level = (int) $year_level_raw;
    }

    // -----------------------------------------------------
    // ALLOWED ROLES
    // -----------------------------------------------------

    $allowed_roles = ['student', 'professor', 'admin'];

    if (!in_array($role, $allowed_roles, true)) {
        redirectWithMessage(
            'Invalid user role.',
            'error',
            $user_id
        );
    }

    // -----------------------------------------------------
    // STUDENT STATUS VALIDATION
    // -----------------------------------------------------

    $allowed_student_statuses = [
        '',
        'Regular',
        'Irregular'
    ];

    if (!in_array($student_status, $allowed_student_statuses, true)) {
        redirectWithMessage(
            'Please select a valid student status.',
            'error',
            $user_id
        );
    }

    try {

        // -------------------------------------------------
        // CONFIRM USER EXISTS
        // -------------------------------------------------

        $existing_stmt = $conn->prepare("
            SELECT user_id, role
            FROM users
            WHERE user_id = ?
            LIMIT 1
        ");

        $existing_stmt->execute([$user_id]);
        $existing_user = $existing_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing_user) {
            redirectWithMessage('User not found.', 'error');
        }

        // -------------------------------------------------
        // PREVENT ADMIN FROM DEMOTING THEIR OWN ACCOUNT
        // -------------------------------------------------

        if (
            $user_id === (int) $_SESSION['user_id'] &&
            $role !== 'admin'
        ) {
            redirectWithMessage(
                'You cannot change your own account role from Administrator.',
                'error',
                $user_id
            );
        }

        // -------------------------------------------------
        // CHECK DUPLICATE EMAIL
        // -------------------------------------------------

        $email_check = $conn->prepare("
            SELECT user_id
            FROM users
            WHERE email = ?
              AND user_id != ?
            LIMIT 1
        ");

        $email_check->execute([$email, $user_id]);

        if ($email_check->fetch(PDO::FETCH_ASSOC)) {
            redirectWithMessage(
                'That email address is already being used by another account.',
                'error',
                $user_id
            );
        }

        // -------------------------------------------------
        // UPDATE USER
        // Uses only columns present in the current schema.
        // Does not reference created_at or profile_pic.
        // -------------------------------------------------

        $update = $conn->prepare("
            UPDATE users
            SET
                full_name = ?,
                email = ?,
                course = ?,
                student_status = ?,
                year_level = ?,
                section = ?,
                enrolled_subjects = ?,
                role = ?
            WHERE user_id = ?
            LIMIT 1
        ");

        $update->execute([
            $full_name,
            $email,
            $course !== '' ? $course : null,
            $student_status !== '' ? $student_status : null,
            $year_level,
            $section !== '' ? $section : null,
            $enrolled_subjects !== '' ? $enrolled_subjects : null,
            $role,
            $user_id
        ]);

        try {
            $n = $conn->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?,?,?,?)");
            $n->execute([$user_id, 'account', 'Account Updated', 'Your account information has been updated by the administrator.']);
        } catch (Throwable $e) {}

        // Send update email
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
            $mail->Subject = 'Account Updated - NoteSphere';
            $mail->Body = 'Your account information has been updated by the administrator.';
            $mail->send();
        } catch (Throwable $e) {
            error_log('Update email failed: ' . $e->getMessage());
        }

        redirectWithMessage(
            'User account updated successfully.',
            'success'
        );

    } catch (PDOException $e) {

        error_log('NoteSphere edit_user.php update error: ' . $e->getMessage());

        redirectWithMessage(
            'Unable to update the user account. Please check the database configuration and try again.',
            'error',
            $user_id
        );
    }
}

// =========================================================
// GET USER DATA
// =========================================================

try {

    $stmt = $conn->prepare("
        SELECT
            user_id,
            full_name,
            email,
            course,
            student_status,
            year_level,
            section,
            enrolled_subjects,
            role,
            warning_count,
            account_status
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");

    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        redirectWithMessage('User not found.', 'error');
    }

} catch (PDOException $e) {

    error_log('NoteSphere edit_user.php fetch error: ' . $e->getMessage());

    http_response_code(500);
    exit('Unable to load user information. Please check the PHP error log.');
}

// =========================================================
// ADMIN NAME
// =========================================================

$admin_name = 'Administrator';

try {

    $admin_stmt = $conn->prepare("
        SELECT full_name
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");

    $admin_stmt->execute([(int) $_SESSION['user_id']]);
    $admin = $admin_stmt->fetch(PDO::FETCH_ASSOC);

    if ($admin && !empty($admin['full_name'])) {
        $admin_name = $admin['full_name'];
    }

} catch (PDOException $e) {
    // Keep the default administrator label.
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

    <title>Edit User - NoteSphere</title>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root {
            --bg: #f4f6fa;
            --card: #ffffff;
            --text: #202938;
            --muted: #7b8494;
            --border: #e4e8ef;
            --input-bg: #ffffff;
            --primary: #3157a4;
            --primary-hover: #274789;
            --soft: #f5f7fb;
            --close-bg: #f1f3f6;
            --close-hover: #e5e8ed;
            --shadow: 0 12px 35px rgba(31, 41, 55, 0.08);
        }

        body.dark-mode {
            --bg: #121722;
            --card: #1b2230;
            --text: #f2f4f8;
            --muted: #9ba5b5;
            --border: #303949;
            --input-bg: #151b26;
            --primary: #5d86dc;
            --primary-hover: #7298e6;
            --soft: #202837;
            --close-bg: #252d3a;
            --close-hover: #313b4b;
            --shadow: 0 15px 40px rgba(0, 0, 0, 0.30);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html,
        body {
            min-height: 100%;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: var(--bg);
            color: var(--text);
            transition: background 0.25s ease, color 0.25s ease;
        }

        .page {
            min-height: 100vh;
            padding: 40px 25px;
        }

        .container {
            width: 100%;
            max-width: 920px;
            margin: 0 auto;
        }

        .card {
            position: relative;
            width: 100%;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 36px;
            box-shadow: var(--shadow);
            transition: background 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease;
        }

        .close-button {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 50%;
            background: var(--close-bg);
            color: var(--muted);
            font-size: 24px;
            line-height: 40px;
            text-align: center;
            text-decoration: none;
            cursor: pointer;
            transition: 0.2s ease;
        }

        .close-button:hover {
            background: var(--close-hover);
            color: var(--text);
            transform: rotate(90deg);
        }

        .header {
            padding-right: 55px;
            margin-bottom: 28px;
        }

        .header h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 8px;
        }

        .header p {
            font-size: 14px;
            line-height: 1.6;
            color: var(--muted);
        }

        .account-info {
            margin-bottom: 28px;
            padding: 17px 18px;
            background: var(--soft);
            border: 1px solid var(--border);
            border-radius: 13px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.8;
        }

        .account-info strong {
            color: var(--text);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        label {
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 700;
            color: var(--text);
        }

        input,
        select,
        textarea {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 11px;
            padding: 12px 14px;
            background: var(--input-bg);
            color: var(--text);
            font-family: inherit;
            font-size: 14px;
            outline: none;
            transition: 0.2s ease;
        }

        input::placeholder,
        textarea::placeholder {
            color: var(--muted);
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(49, 87, 164, 0.12);
        }

        textarea {
            min-height: 110px;
            resize: vertical;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 30px;
            padding-top: 24px;
            border-top: 1px solid var(--border);
        }

        .btn {
            border: none;
            border-radius: 10px;
            padding: 12px 22px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: 0.2s ease;
        }

        .btn-cancel {
            background: var(--soft);
            color: var(--text);
            border: 1px solid var(--border);
        }

        .btn-cancel:hover {
            transform: translateY(-1px);
        }

        .btn-save {
            background: var(--primary);
            color: #ffffff;
            min-width: 140px;
        }

        .btn-save:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
        }

        @media (max-width: 700px) {
            .page {
                padding: 20px 12px;
            }

            .card {
                padding: 25px 20px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-group.full {
                grid-column: auto;
            }

            .header {
                padding-right: 45px;
            }

            .header h1 {
                font-size: 23px;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }
    </style>
</head>

<body>

<div class="page">
    <div class="container">
        <div class="card">

            <a
                href="manage_users.php"
                class="close-button"
                title="Back to Manage Users"
                aria-label="Back to Manage Users"
            >×</a>

            <div class="header">
                <h1>Edit User</h1>
                <p>Update the information of this NoteSphere account.</p>
            </div>

            <div class="account-info">
                Editing account:
                <strong><?= e($user['full_name']) ?></strong>
                <br>
                User ID:
                <strong>#<?= e($user['user_id']) ?></strong>
                <br>
                Account Status:
                <strong><?= e($user['account_status'] ?? 'active') ?></strong>
                <br>
                Warning Count:
                <strong><?= e($user['warning_count'] ?? 0) ?></strong>
            </div>

            <form
                method="POST"
                action="edit_user.php?user_id=<?= (int) $user_id ?>"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrf_token) ?>"
                >

                <div class="form-grid">

                    <div class="form-group">
                        <label for="full_name">Full Name</label>
                        <input
                            type="text"
                            id="full_name"
                            name="full_name"
                            maxlength="150"
                            value="<?= e($user['full_name']) ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            maxlength="255"
                            value="<?= e($user['email']) ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="course">Course</label>
                        <input
                            type="text"
                            id="course"
                            name="course"
                            maxlength="50"
                            value="<?= e($user['course']) ?>"
                            placeholder="Example: BSIT"
                        >
                    </div>

                    <div class="form-group">
                        <label for="student_status">Student Status</label>
                        <select id="student_status" name="student_status">
                            <option
                                value=""
                                <?= ($user['student_status'] ?? '') === '' ? 'selected' : '' ?>
                            >Select Status</option>

                            <option
                                value="Regular"
                                <?= ($user['student_status'] ?? '') === 'Regular' ? 'selected' : '' ?>
                            >Regular</option>

                            <option
                                value="Irregular"
                                <?= ($user['student_status'] ?? '') === 'Irregular' ? 'selected' : '' ?>
                            >Irregular</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="year_level">Year Level</label>
                        <select id="year_level" name="year_level">
                            <option
                                value=""
                                <?= empty($user['year_level']) ? 'selected' : '' ?>
                            >Select Year Level</option>

                            <option
                                value="1"
                                <?= (string) ($user['year_level'] ?? '') === '1' ? 'selected' : '' ?>
                            >1st Year</option>

                            <option
                                value="2"
                                <?= (string) ($user['year_level'] ?? '') === '2' ? 'selected' : '' ?>
                            >2nd Year</option>

                            <option
                                value="3"
                                <?= (string) ($user['year_level'] ?? '') === '3' ? 'selected' : '' ?>
                            >3rd Year</option>

                            <option
                                value="4"
                                <?= (string) ($user['year_level'] ?? '') === '4' ? 'selected' : '' ?>
                            >4th Year</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="section">Section</label>
                        <input
                            type="text"
                            id="section"
                            name="section"
                            maxlength="255"
                            value="<?= e($user['section']) ?>"
                            placeholder="Example: A"
                        >
                    </div>

                    <div class="form-group full">
                        <label for="enrolled_subjects">Enrolled Subjects</label>
                        <textarea
                            id="enrolled_subjects"
                            name="enrolled_subjects"
                            placeholder="Enter subject codes separated by commas"
                        ><?= e($user['enrolled_subjects']) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="role">Account Role</label>
                        <select id="role" name="role" required>
                            <option
                                value="student"
                                <?= $user['role'] === 'student' ? 'selected' : '' ?>
                            >Student</option>

                            <option
                                value="professor"
                                <?= $user['role'] === 'professor' ? 'selected' : '' ?>
                            >Professor</option>

                            <option
                                value="admin"
                                <?= $user['role'] === 'admin' ? 'selected' : '' ?>
                            >Administrator</option>
                        </select>
                    </div>

                </div>

                <div class="form-actions">
                    <a href="manage_users.php" class="btn btn-cancel">
                        Cancel
                    </a>

                    <button type="submit" class="btn btn-save">
                        Save Changes
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>

<script>
(function () {
    try {
        const savedTheme = localStorage.getItem('notesphere_admin_theme');

        if (savedTheme === 'dark' || savedTheme === 'dark-mode') {
            document.body.classList.add('dark-mode');
        } else {
            document.body.classList.remove('dark-mode');
        }
    } catch (error) {
        // Theme preference is optional.
    }
})();
</script>

<?php
if (
    isset($_SESSION['admin_message']) &&
    isset($_SESSION['admin_message_type'])
):
    $message = $_SESSION['admin_message'];
    $message_type = $_SESSION['admin_message_type'];

    unset($_SESSION['admin_message'], $_SESSION['admin_message_type']);

    $alert_icon = in_array(
        $message_type,
        ['success', 'error', 'warning', 'info', 'question'],
        true
    ) ? $message_type : 'info';

    $alert_title = $alert_icon === 'success' ? 'Success' : (
        $alert_icon === 'error' ? 'Error' : 'Notice'
    );
?>
<script>
Swal.fire({
    icon: <?= json_encode($alert_icon) ?>,
    title: <?= json_encode($alert_title) ?>,
    text: <?= json_encode($message) ?>,
    confirmButtonColor: '#3157a4'
});
</script>
<?php endif; ?>
</body>
</html>
