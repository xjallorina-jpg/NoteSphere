
<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../includes/db_config.php';

require_once __DIR__ . '/../includes/PHPMailer-master/PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../includes/PHPMailer-master/PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../includes/PHPMailer-master/PHPMailer-master/src/SMTP.php';

/** @var PDO $conn */

$error = '';

$fullName = '';
$email = '';
$course = '';
$studentStatus = '';
$yearLevel = '';
$section = '';
$subjects = '';

/* =========================================================
   HELPERS
========================================================= */

function registerLog(string $message): void
{
    error_log('[NoteSphere Registration] ' . $message);
}

function postValue(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

function safeOutput(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function textLength(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}

/**
 * Checks subject-code format only.
 * It does not confirm whether a subject is officially offered.
 */
function isValidSubjectCode(string $code): bool
{
    if ($code === '' || textLength($code) > 30) {
        return false;
    }

    return (bool) preg_match(
        '/^[A-Z][A-Z0-9]*(?:[ -][A-Z0-9]+)*$/',
        $code
    );
}

/* =========================================================
   REGISTRATION PROCESS
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $fullName = postValue('full_name');
    $email = strtolower(postValue('email'));
    $course = postValue('course');
    $studentStatus = postValue('student_status');
    $yearLevel = postValue('year_level');

    $section = strtoupper(
        postValue(
            $studentStatus === 'Irregular'
                ? 'irregular_section'
                : 'regular_section'
        )
    );

    $subjects = strtoupper(postValue('enrolled_subjects'));

    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    try {

        /* 1. REQUIRED FIELDS */

        if (
            $fullName === '' ||
            $email === '' ||
            $course === '' ||
            $studentStatus === '' ||
            $yearLevel === '' ||
            $section === '' ||
            $subjects === '' ||
            $password === '' ||
            $confirmPassword === ''
        ) {
            throw new RuntimeException(
                'Please complete all required fields.'
            );
        }

        /* 2. FULL NAME */

        if (textLength($fullName) > 150) {
            throw new RuntimeException(
                'Full name must not exceed 150 characters.'
            );
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $fullName)) {
            throw new RuntimeException(
                'Please enter a valid full name.'
            );
        }

        /* 3. EMAIL */

        if (
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            strlen($email) > 255
        ) {
            throw new RuntimeException(
                'Please enter a valid email address.'
            );
        }

        /* 4. COURSE */

        $allowedCourses = [
            'BSIT',
            'BSCpE',
            'BSBA',
            'BSE',
            'BECE',
            'BEE',
            'BTLE'
        ];

        if (!in_array($course, $allowedCourses, true)) {
            throw new RuntimeException(
                'Please select a valid course.'
            );
        }

        /* 5. STUDENT STATUS */

        if (!in_array(
            $studentStatus,
            ['Regular', 'Irregular'],
            true
        )) {
            throw new RuntimeException(
                'Please select a valid student status.'
            );
        }

        /* 6. YEAR LEVEL */

        $allowedYears = [
            '1st Year',
            '2nd Year',
            '3rd Year',
            '4th Year'
        ];

        if (!in_array($yearLevel, $allowedYears, true)) {
            throw new RuntimeException(
                'Please select a valid year level.'
            );
        }

        /* 7. SECTION */

        if ($section === '' || textLength($section) > 50) {
            throw new RuntimeException(
                'Please enter a valid section.'
            );
        }

        if (
            $studentStatus === 'Regular' &&
            !in_array($section, ['A', 'B', 'C', 'D', 'E'], true)
        ) {
            throw new RuntimeException(
                'Please select a valid regular section.'
            );
        }

        /* 8. PASSWORD */

        if (strlen($password) < 8) {
            throw new RuntimeException(
                'Password must be at least 8 characters long.'
            );
        }

        if ($password !== $confirmPassword) {
            throw new RuntimeException(
                'Passwords do not match.'
            );
        }

        /* 9. SUBJECT-CODE VALIDATION */

        $rawSubjectCodes = explode(',', $subjects);
        $subjectList = [];

        foreach ($rawSubjectCodes as $rawCode) {

            $code = strtoupper(trim($rawCode));

            if ($code === '') {
                continue;
            }

            // Remove duplicate subject codes.
            if (in_array($code, $subjectList, true)) {
                continue;
            }

            if (!isValidSubjectCode($code)) {
                throw new RuntimeException(
                    'The subject code "' . $code .
                    '" has an invalid format. Use letters/numbers ' .
                    'and separate multiple codes with commas. ' .
                    'Example: IT101, MATH101, GE 1.'
                );
            }

            $subjectList[] = $code;
        }

        if (count($subjectList) === 0) {
            throw new RuntimeException(
                'Please enter at least one valid subject code.'
            );
        }

        $subjects = implode(', ', $subjectList);

        if (strlen($subjects) > 65535) {
            throw new RuntimeException(
                'The subject list is too long.'
            );
        }

        /* 10. ADMIN-CONFIGURED USER LIMIT */

        $limitQuery = $conn->query(
            'SELECT max_users
             FROM system_limits
             ORDER BY id DESC
             LIMIT 1'
        );

        $limitRow = $limitQuery->fetch(PDO::FETCH_ASSOC);

        if (
            !$limitRow ||
            !isset($limitRow['max_users'])
        ) {
            throw new RuntimeException(
                'The registration limit is not configured. ' .
                'Please contact the administrator.'
            );
        }

        $maxUsers = (int) $limitRow['max_users'];

        if ($maxUsers < 1) {
            throw new RuntimeException(
                'Student registration is currently unavailable. ' .
                'Please contact the administrator.'
            );
        }

        $countQuery = $conn->query(
            'SELECT COUNT(*) FROM users'
        );

        $currentUsers = (int) $countQuery->fetchColumn();

        if ($currentUsers >= $maxUsers) {
            throw new RuntimeException(
                'The system has reached its maximum registered-user ' .
                'limit. Please contact the administrator.'
            );
        }

        /* 11. CHECK EXISTING EMAIL */

        $checkEmail = $conn->prepare(
            'SELECT user_id
             FROM users
             WHERE email = :email
             LIMIT 1'
        );

        $checkEmail->execute([
            ':email' => $email
        ]);

        if ($checkEmail->fetch(PDO::FETCH_ASSOC)) {
            throw new RuntimeException(
                'This email address is already registered. ' .
                'Please use another email or log in.'
            );
        }

        /* 12. LOAD EMAIL CONFIGURATION */

        $mailConfigPath = __DIR__ . '/../includes/mail_config.php';

        if (!is_file($mailConfigPath)) {
            throw new RuntimeException(
                'Mail configuration is missing. ' .
                'Please check includes/mail_config.php.'
            );
        }

        $mailConfig = require $mailConfigPath;

        if (!is_array($mailConfig)) {
            throw new RuntimeException(
                'The mail configuration is invalid.'
            );
        }

        $mailUsername = trim(
            (string) ($mailConfig['username'] ?? '')
        );

        $appPassword = preg_replace(
            '/\s+/',
            '',
            trim((string) ($mailConfig['app_password'] ?? ''))
        );

        if (
            $mailUsername === '' ||
            $appPassword === '' ||
            $appPassword === 'ILAGAY_DITO_ANG_BAGO_MONG_GMAIL_APP_PASSWORD'
        ) {
            throw new RuntimeException(
                'Gmail credentials are missing. ' .
                'Please configure includes/mail_config.php.'
            );
        }

        if (!filter_var($mailUsername, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException(
                'The Gmail username in mail_config.php is invalid.'
            );
        }

        /* 13. GENERATE OTP */

        $otp = (string) random_int(100000, 999999);

        $mail = new PHPMailer(true);

        try {

            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = $mailUsername;
            $mail->Password = $appPassword;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            $mail->SMTPKeepAlive = false;
            $mail->Timeout = 20;
            $mail->CharSet = 'UTF-8';

            $mail->setFrom($mailUsername, 'NoteSphere');
            $mail->addAddress($email, $fullName);

            $mail->isHTML(true);
            $mail->Subject = 'NoteSphere - Email Verification Code';

            $safeName = safeOutput($fullName);
            $safeOtp = safeOutput($otp);

            $mail->Body = '
                <div style="
                    font-family:Arial,sans-serif;
                    max-width:600px;
                    margin:auto;
                    padding:24px;
                    color:#222;
                ">
                    <h2 style="color:#10b981;">
                        Welcome to NoteSphere!
                    </h2>

                    <p>Hello ' . $safeName . ',</p>

                    <p>
                        Use the verification code below to complete
                        your student registration.
                    </p>

                    <div style="
                        padding:18px;
                        text-align:center;
                        background:#f3f4f6;
                        border-radius:10px;
                    ">
                        <span style="
                            font-size:32px;
                            font-weight:bold;
                            letter-spacing:8px;
                            color:#111827;
                        ">' . $safeOtp . '</span>
                    </div>

                    <p>
                        Do not share this verification code with anyone.
                    </p>

                    <p>
                        If you did not request this code, you can
                        ignore this email.
                    </p>

                    <p>
                        Thank you,<br>
                        <strong>NoteSphere Team</strong>
                    </p>
                </div>
            ';

            $mail->AltBody =
                "Hello {$fullName},\n\n" .
                "Your NoteSphere verification code is: {$otp}\n\n" .
                "Enter this code on the verification page to " .
                "complete registration.";

            $mail->send();

        } catch (MailException $mailError) {

            registerLog(
                'PHPMailer error: ' . $mailError->getMessage() .
                ' | SMTP: ' . (string) $mail->ErrorInfo
            );

            throw new RuntimeException(
                'We could not send the verification email. ' .
                'Please check the Gmail configuration and try again.'
            );
        }

        /* 14. SAVE TEMPORARY REGISTRATION */

        $_SESSION['temp_user'] = [
            'full_name' => $fullName,
            'email' => $email,
            'course' => $course,
            'student_status' => $studentStatus,
            'year_level' => $yearLevel,
            'section' => $section,
            'enrolled_subjects' => $subjects,
            'password' => password_hash(
                $password,
                PASSWORD_DEFAULT
            ),
            'otp_code' => $otp,
            'otp_created_at' => time()
        ];

        $_SESSION['verify_email'] = $email;
        $_SESSION['verify_user'] = true;

        session_write_close();

        header('Location: verify_otp.php');
        exit;

    } catch (PDOException $e) {

        registerLog(
            'Database error: ' . $e->getMessage() .
            ' | SQLSTATE: ' . $e->getCode()
        );

        if ($e->getCode() === '23000') {
            $error =
                'This email address may already be registered. ' .
                'Please check your details.';
        } else {
            $error =
                'A database error occurred. Please check the ' .
                'database configuration and try again.';
        }

    } catch (RuntimeException $e) {

        $error = $e->getMessage();

        registerLog(
            'Registration rejected: ' . $e->getMessage()
        );

    } catch (Throwable $e) {

        registerLog(
            'Unexpected error: ' . get_class($e) .
            ' - ' . $e->getMessage() .
            ' | File: ' . $e->getFile() .
            ' | Line: ' . $e->getLine()
        );

        $error =
            'A system error occurred during registration. ' .
            'Please try again later.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0, viewport-fit=cover"
    >

    <meta name="theme-color" content="#070b0a">
    <meta name="application-name" content="NoteSphere">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="NoteSphere">
    <meta name="apple-mobile-web-app-status-bar-style" content="black">
    <link rel="apple-touch-icon" sizes="180x180" href="../assets/apple-touch-icon.png">

    <title>Student Registration | NoteSphere</title>

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>

        :root {
            --green: #39FF14;
            --green-dark: #10b981;
            --background: #070b0a;
            --card: rgba(12, 18, 15, 0.96);
            --text: #f4f7f5;
            --muted: #a0aaa4;
            --border: rgba(255, 255, 255, .12);
            --input: #111815;
            --danger: #fb7185;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            min-height: 100dvh;
            padding: calc(35px + env(safe-area-inset-top, 0px))
                     calc(16px + env(safe-area-inset-right, 0px))
                     calc(35px + env(safe-area-inset-bottom, 0px))
                     calc(16px + env(safe-area-inset-left, 0px));

            display: flex;
            align-items: center;
            justify-content: center;

            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--text);

            background:
                linear-gradient(
                    rgba(3, 7, 5, .78),
                    rgba(3, 7, 5, .91)
                ),
                url('../assets/login.jpg') center / cover fixed no-repeat,
                var(--background);
        }

        /* =====================================================
           REGISTER CARD
        ===================================================== */

        .register-card {
            position: relative;
            width: 100%;
            max-width: 680px;
            padding: 35px;

            border: 1px solid rgba(57, 255, 20, .22);
            border-radius: 22px;

            background: var(--card);

            box-shadow: 0 20px 65px rgba(0, 0, 0, .48);

            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
        }

        /* =====================================================
           BACK BUTTON
        ===================================================== */

        .back-btn {
            position: absolute;
            top: 20px;
            left: 20px;

            width: 40px;
            height: 40px;

            display: inline-flex;
            align-items: center;
            justify-content: center;

            border: 1px solid rgba(57, 255, 20, .35);
            border-radius: 50%;

            background: rgba(57, 255, 20, .08);
            color: var(--green);

            font-size: 22px;
            text-decoration: none;
            cursor: pointer;

            transition: background .3s, color .3s, transform .3s;
        }

        .back-btn:hover {
            background: var(--green);
            color: #061006;
            transform: translateX(-3px);
        }

        .back-btn:focus-visible,
        button:focus-visible,
        input:focus-visible,
        select:focus-visible {
            outline: 2px solid var(--green);
            outline-offset: 3px;
        }

        /* =====================================================
           HEADER
        ===================================================== */

        .brand {
            margin: 0 0 8px;

            color: var(--green);

            font-size: 30px;
            font-weight: 800;
            letter-spacing: -.8px;

            text-align: center;
        }

        .subtitle {
            margin: 0 0 28px;

            color: var(--muted);

            font-size: 13px;
            line-height: 1.7;

            text-align: center;
        }

        /* =====================================================
           SECTION TITLES
        ===================================================== */

        .section-title {
            margin: 25px 0 15px;
            padding-bottom: 10px;

            border-bottom: 1px solid var(--border);

            color: var(--green);

            font-size: 13px;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        /* =====================================================
           FORM GRID
        ===================================================== */

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 17px;
        }

        .field {
            min-width: 0;

            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        label {
            color: #e3e9e5;

            font-size: 12px;
            font-weight: 700;
        }

        /* =====================================================
           INPUTS
        ===================================================== */

        input,
        select {
            width: 100%;
            min-height: 46px;
            padding: 12px 13px;

            border: 1px solid var(--border);
            border-radius: 10px;

            outline: none;

            background: var(--input);
            color: var(--text);

            font-family: inherit;
            font-size: 16px;

            transition: border-color .3s, box-shadow .3s;
            -webkit-appearance: none;
            appearance: none;
        }

        input:focus,
        select:focus {
            border-color: var(--green);

            box-shadow: 0 0 0 4px rgba(57, 255, 20, .08);
        }

        input::placeholder {
            color: #6f7a73;
        }

        /* =====================================================
           PASSWORD
        ===================================================== */

        .password-wrap {
            position: relative;
        }

        .password-wrap input {
            padding-right: 72px;
        }

        .toggle-password {
            position: absolute;
            top: 50%;
            right: 12px;

            transform: translateY(-50%);

            border: none;
            background: transparent;

            color: var(--green);

            cursor: pointer;

            font-family: inherit;
            font-size: 11px;
            font-weight: 800;
        }

        .toggle-password:hover {
            color: #32e010;
        }

        /* =====================================================
           HELPER TEXT
        ===================================================== */

        .hint {
            margin: 0;

            color: var(--muted);

            font-size: 11px;
            line-height: 1.6;
        }

        .subject-status {
            display: block;
            min-height: 16px;

            font-size: 11px;
            line-height: 1.5;
        }

        .subject-status.valid {
            color: #6ee7b7;
        }

        .subject-status.invalid {
            color: var(--danger);
        }

        /* =====================================================
           CREATE ACCOUNT BUTTON
           MATCHED TO LOGIN BUTTON
        ===================================================== */

        .submit-btn {
            width: 100%;
            padding: 14px;
            margin-top: 25px;

            border: none;
            border-radius: 12px;

            background: #39FF14;
            color: #000;

            font-family: 'Plus Jakarta Sans', sans-serif;
            font-weight: 800;
            font-size: 0.95rem;

            cursor: pointer;

            transition: 0.3s;
        }

        .submit-btn:hover {
            background: #32e010;

            transform: translateY(-2px);

            box-shadow: 0 5px 20px rgba(57, 255, 20, 0.3);
        }

        .submit-btn:disabled {
            opacity: 0.65;
            cursor: wait;
            transform: none;
            box-shadow: none;
        }

        /* =====================================================
           LOGIN LINK
        ===================================================== */

        .login-link {
            margin: 20px 0 0;

            color: var(--muted);

            font-size: 12px;
            text-align: center;
        }

        .login-link a {
            color: var(--green);

            font-weight: 800;
            text-decoration: none;
        }

        .login-link a:hover {
            text-decoration: underline;
        }

        /* =====================================================
           MOBILE
        ===================================================== */

        @media (max-width: 560px) {

            body {
                padding: calc(18px + env(safe-area-inset-top, 0px))
                        calc(10px + env(safe-area-inset-right, 0px))
                        calc(18px + env(safe-area-inset-bottom, 0px))
                        calc(10px + env(safe-area-inset-left, 0px));
                align-items: flex-start;
                background-attachment: scroll;
            }

            .register-card {
                margin: 8px 0;
                padding: 65px 18px 24px;
                border-radius: 17px;
            }

            .back-btn {
                top: 15px;
                left: 15px;

                width: 37px;
                height: 37px;

                font-size: 21px;
            }

            .brand {
                font-size: 26px;
            }

            .subtitle {
                font-size: 12px;
                margin-bottom: 23px;
            }

            .form-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .field.full {
                grid-column: auto;
            }

            .section-title {
                margin-top: 23px;
            }

            .submit-btn {
                padding: 14px;
            }
        }

        @supports (-webkit-touch-callout: none) {

            body {
                background-attachment: scroll;
            }

            input,
            select,
            textarea {
                font-size: 16px;
            }
        }

        @media (prefers-reduced-motion: reduce) {

            *,
            *::before,
            *::after {
                transition-duration: .01ms !important;
                animation-duration: .01ms !important;
            }
        }

    </style>

</head>

<body>

<main class="register-card">

    <!-- BACK BUTTON -->

    <button
        type="button"
        class="back-btn"
        id="backButton"
        aria-label="Go back"
        title="Go back"
    >&#8592;</button>

    <!-- HEADER -->

    <div class="brand">NoteSphere</div>

    <p class="subtitle">
        Create your student account to access and share academic learning materials.
    </p>

    <form
        id="registerForm"
        method="POST"
        action=""
        autocomplete="on"
    >

        <!-- PERSONAL INFORMATION -->

        <h2 class="section-title">Personal Information</h2>

        <div class="form-grid">

            <div class="field full">

                <label for="full_name">Full Name</label>

                <input
                    type="text"
                    id="full_name"
                    name="full_name"
                    maxlength="150"
                    placeholder="Enter your complete name"
                    value="<?= safeOutput($fullName) ?>"
                    autocomplete="name"
                    required
                >

            </div>

            <div class="field full">

                <label for="email">School Email Address</label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    maxlength="255"
                    placeholder="Enter your email address"
                    value="<?= safeOutput($email) ?>"
                    autocomplete="email"
                    required
                >

                <p class="hint">
                    A six-digit verification code will be sent to this email.
                </p>

            </div>

        </div>

        <!-- ACADEMIC INFORMATION -->

        <h2 class="section-title">Academic Information</h2>

        <div class="form-grid">

            <div class="field">

                <label for="course">Course</label>

                <select id="course" name="course" required>

                    <option value="">Select Course</option>

                    <?php foreach (
                        ['BSIT', 'BSCpE', 'BSBA', 'BSE', 'BECE', 'BEE', 'BTLE']
                        as $item
                    ): ?>

                        <option
                            value="<?= safeOutput($item) ?>"
                            <?= $course === $item ? 'selected' : '' ?>
                        ><?= safeOutput($item) ?></option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="field">

                <label for="student_status">Student Status</label>

                <select id="student_status" name="student_status" required>

                    <option value="">Select Status</option>

                    <option
                        value="Regular"
                        <?= $studentStatus === 'Regular' ? 'selected' : '' ?>
                    >Regular</option>

                    <option
                        value="Irregular"
                        <?= $studentStatus === 'Irregular' ? 'selected' : '' ?>
                    >Irregular</option>

                </select>

            </div>

            <div class="field">

                <label for="year_level">Year Level</label>

                <select id="year_level" name="year_level" required>

                    <option value="">Select Year</option>

                    <?php foreach (
                        ['1st Year', '2nd Year', '3rd Year', '4th Year']
                        as $item
                    ): ?>

                        <option
                            value="<?= safeOutput($item) ?>"
                            <?= $yearLevel === $item ? 'selected' : '' ?>
                        ><?= safeOutput($item) ?></option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="field" id="regularSectionField">

                <label for="regular_section">Section</label>

                <select
                    id="regular_section"
                    name="regular_section"
                >

                    <option value="">Select Section</option>

                    <?php foreach (['A', 'B', 'C', 'D', 'E'] as $item): ?>

                        <option
                            value="<?= $item ?>"
                            <?= $studentStatus === 'Regular' && $section === $item
                                ? 'selected'
                                : '' ?>
                        ><?= $item ?></option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div
                class="field"
                id="irregularSectionField"
                style="display:none;"
            >

                <label for="irregular_section">Section / Block</label>

                <input
                    type="text"
                    id="irregular_section"
                    name="irregular_section"
                    maxlength="50"
                    placeholder="Enter your section or block"
                    value="<?= $studentStatus === 'Irregular'
                        ? safeOutput($section)
                        : '' ?>"
                >

            </div>

            <div class="field full">

                <label for="enrolled_subjects">Enrolled Subject Codes</label>

                <input
                    type="text"
                    id="enrolled_subjects"
                    name="enrolled_subjects"
                    maxlength="1000"
                    placeholder="Example: IT101, MATH101, GE 1"
                    value="<?= safeOutput($subjects) ?>"
                    aria-describedby="subjectHint subjectStatus"
                    required
                >

                <p class="hint" id="subjectHint">
                    Separate each subject code with a comma.
                    Example: IT101, MATH101, GE 1.
                </p>

                <span
                    id="subjectStatus"
                    class="subject-status"
                    aria-live="polite"
                ></span>

            </div>

        </div>

        <!-- ACCOUNT SECURITY -->

        <h2 class="section-title">Account Security</h2>

        <div class="form-grid">

            <div class="field">

                <label for="password">Password</label>

                <div class="password-wrap">

                    <input
                        type="password"
                        id="password"
                        name="password"
                        minlength="8"
                        placeholder="At least 8 characters"
                        autocomplete="new-password"
                        required
                    >

                    <button
                        class="toggle-password"
                        type="button"
                        data-target="password"
                        aria-label="Show password"
                    >Show</button>

                </div>

            </div>

            <div class="field">

                <label for="confirm_password">Confirm Password</label>

                <div class="password-wrap">

                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        minlength="8"
                        placeholder="Re-enter password"
                        autocomplete="new-password"
                        required
                    >

                    <button
                        class="toggle-password"
                        type="button"
                        data-target="confirm_password"
                        aria-label="Show password"
                    >Show</button>

                </div>

            </div>

        </div>

        <!-- CREATE ACCOUNT -->

        <button
            type="submit"
            class="submit-btn"
            id="submitBtn"
        >CREATE ACCOUNT</button>

        <!-- LOGIN LINK -->

        <p class="login-link">
            Already have an account?
            <a href="login.php">Log in</a>
        </p>

    </form>

</main>

<script>

    const studentStatusSelect =
        document.getElementById('student_status');

    const regularSectionField =
        document.getElementById('regularSectionField');

    const irregularSectionField =
        document.getElementById('irregularSectionField');

    const regularSection =
        document.getElementById('regular_section');

    const irregularSection =
        document.getElementById('irregular_section');

    const form =
        document.getElementById('registerForm');

    const submitBtn =
        document.getElementById('submitBtn');

    const subjectInput =
        document.getElementById('enrolled_subjects');

    const subjectStatus =
        document.getElementById('subjectStatus');

    /* =====================================================
       SWEETALERT THEME
    ===================================================== */

    const alertTheme = {
        confirmButtonColor: '#39FF14',
        cancelButtonColor: '#4b5563',
        background: '#0a0a0a',
        color: '#fff'
    };

    /* =====================================================
       BACK BUTTON
    ===================================================== */

    document.getElementById('backButton').addEventListener(
        'click',
        function () {

            if (window.history.length > 1) {
                window.history.back();
            } else {
                window.location.href = 'login.php';
            }

        }
    );

    /* =====================================================
       REGULAR / IRREGULAR SECTION
    ===================================================== */

    function updateSection() {

        const isRegular =
            studentStatusSelect.value === 'Regular';

        const isIrregular =
            studentStatusSelect.value === 'Irregular';

        regularSectionField.style.display =
            isRegular ? 'flex' : 'none';

        irregularSectionField.style.display =
            isIrregular ? 'flex' : 'none';

        regularSection.disabled = !isRegular;
        regularSection.required = isRegular;

        irregularSection.disabled = !isIrregular;
        irregularSection.required = isIrregular;

        if (!isRegular) {
            regularSection.value = '';
        }

        if (!isIrregular) {
            irregularSection.value = '';
        }
    }

    studentStatusSelect.addEventListener(
        'change',
        updateSection
    );

    /* =====================================================
       SUBJECT-CODE VALIDATION
    ===================================================== */

    function normalizeSubjectCodes(value) {

        return value
            .split(',')
            .map(code => code.trim().toUpperCase())
            .filter(code => code.length > 0)
            .filter(
                (code, index, array) =>
                    array.indexOf(code) === index
            );
    }

    function isValidSubjectCode(code) {

        return code.length <= 30 &&
            /^[A-Z][A-Z0-9]*(?:[ -][A-Z0-9]+)*$/.test(code);
    }

    function checkSubjectCodes(showEmptyMessage = false) {

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

            subjectStatus.textContent =
                'Please enter at least one subject code.';

            subjectStatus.className =
                'subject-status invalid';

            return false;
        }

        const invalidCodes =
            codes.filter(code => !isValidSubjectCode(code));

        if (invalidCodes.length > 0) {

            subjectStatus.textContent =
                'Check the format of: ' + invalidCodes.join(', ');

            subjectStatus.className =
                'subject-status invalid';

            return false;
        }

        subjectStatus.textContent =
            codes.length +
            ' subject code(s) detected. Format looks valid.';

        subjectStatus.className =
            'subject-status valid';

        return true;
    }

    subjectInput.addEventListener('input', function () {
        checkSubjectCodes(false);
    });

    subjectInput.addEventListener('blur', function () {

        if (subjectInput.value.trim() !== '') {
            checkSubjectCodes(false);
        }

    });

    /* =====================================================
       PASSWORD SHOW / HIDE
    ===================================================== */

    document.querySelectorAll('.toggle-password').forEach(
        button => {

            button.addEventListener('click', function () {

                const target = document.getElementById(
                    this.dataset.target
                );

                const showing = target.type === 'text';

                target.type = showing ? 'password' : 'text';

                this.textContent = showing ? 'Show' : 'Hide';

                this.setAttribute(
                    'aria-label',
                    showing ? 'Show password' : 'Hide password'
                );

            });

        }
    );

    /* =====================================================
       REGISTRATION VALIDATION + CONFIRMATION
    ===================================================== */

    form.addEventListener('submit', function (event) {

        event.preventDefault();

        const password =
            document.getElementById('password').value;

        const confirmPassword =
            document.getElementById('confirm_password').value;

        const selectedSection =
            studentStatusSelect.value === 'Regular'
                ? regularSection.value
                : irregularSection.value.trim();

        if (!selectedSection) {

            Swal.fire({
                ...alertTheme,
                icon: 'warning',
                title: 'Section Required',
                text: 'Please select or enter your section.'
            });

            return;
        }

        if (
            studentStatusSelect.value === 'Irregular' &&
            selectedSection.length > 50
        ) {

            Swal.fire({
                ...alertTheme,
                icon: 'warning',
                title: 'Section Too Long',
                text: 'Your section must not exceed 50 characters.'
            });

            return;
        }

        if (password.length < 8) {

            Swal.fire({
                ...alertTheme,
                icon: 'warning',
                title: 'Password Too Short',
                text: 'Your password must contain at least 8 characters.'
            });

            return;
        }

        if (password !== confirmPassword) {

            Swal.fire({
                ...alertTheme,
                icon: 'error',
                title: 'Password Mismatch',
                text: 'The passwords you entered do not match.'
            });

            return;
        }

        if (!checkSubjectCodes(true)) {

            Swal.fire({
                ...alertTheme,
                icon: 'warning',
                title: 'Check Subject Codes',
                text: 'Please correct the subject-code format before continuing.',
                confirmButtonText: 'Review Subjects'
            }).then(() => {
                subjectInput.focus();
            });

            return;
        }

        // Normalize subject codes before submitting.
        subjectInput.value =
            normalizeSubjectCodes(subjectInput.value).join(', ');

        Swal.fire({
            ...alertTheme,
            title: 'Confirm Registration',
            text: 'Please check your details. A verification code will be sent to your email.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Continue',
            cancelButtonText: 'Review Details',
            reverseButtons: true,
            focusCancel: true
        }).then(result => {

            if (result.isConfirmed) {

                submitBtn.disabled = true;

                submitBtn.textContent =
                    'SENDING VERIFICATION CODE...';

                // Submit through the native form method.
                HTMLFormElement.prototype.submit.call(form);
            }

        });

    });

    /* =====================================================
       INITIALIZE
    ===================================================== */

    updateSection();

    if (subjectInput.value.trim() !== '') {
        checkSubjectCodes(false);
    }

    /* =====================================================
       SERVER-SIDE ERROR MESSAGE
    ===================================================== */

    <?php if ($error !== ''): ?>

    Swal.fire({
        ...alertTheme,
        icon: 'error',
        title: 'Registration Failed',
        text: <?= json_encode(
            $error,
            JSON_HEX_TAG |
            JSON_HEX_APOS |
            JSON_HEX_QUOT |
            JSON_HEX_AMP
        ) ?>,
        confirmButtonText: 'OK'
    });

    <?php endif; ?>

</script>
</body>
</html>

