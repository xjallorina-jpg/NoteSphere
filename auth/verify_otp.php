<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../includes/db_config.php';

/** @var PDO $conn */

$error = '';

/* =========================================================
   HELPERS
========================================================= */

function otpLog(string $message): void
{
    error_log('[NoteSphere OTP Verification] ' . $message);
}

function otpSafeOutput(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function otpValidSubjectCode(string $code): bool
{
    return strlen($code) <= 30 &&
        (bool) preg_match(
            '/^[A-Z][A-Z0-9]*(?:[ -][A-Z0-9]+)*$/',
            $code
        );
}

/* =========================================================
   CHECK TEMPORARY REGISTRATION SESSION
========================================================= */

if (
    !isset($_SESSION['verify_user']) ||
    $_SESSION['verify_user'] !== true ||
    !isset($_SESSION['temp_user']) ||
    !is_array($_SESSION['temp_user'])
) {
    header('Location: register.php');
    exit;
}

/* =========================================================
   VERIFY OTP
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $enteredOtp = trim((string) ($_POST['otp_code'] ?? ''));

    $userData = $_SESSION['temp_user'];

    $sessionOtp = trim(
        (string) ($userData['otp_code'] ?? '')
    );

    $otpCreatedAt = (int) (
        $userData['otp_created_at'] ?? 0
    );

    $otpLifetime = 600; // 10 minutes
    $maxAttempts = 5;

    $attempts = (int) (
        $_SESSION['otp_attempts'] ?? 0
    );

    /* -----------------------------------------
       1. OTP EXPIRATION
    ----------------------------------------- */

    if (
        $otpCreatedAt <= 0 ||
        (time() - $otpCreatedAt) > $otpLifetime
    ) {
        unset(
            $_SESSION['verify_user'],
            $_SESSION['temp_user'],
            $_SESSION['verify_email'],
            $_SESSION['otp_attempts']
        );

        $error =
            'Your verification code has expired. Please register again to receive a new code.';

    /* -----------------------------------------
       2. OTP FORMAT
    ----------------------------------------- */

    } elseif (!preg_match('/^[0-9]{6}$/', $enteredOtp)) {
        $error = 'Please enter the complete 6-digit verification code.';

    /* -----------------------------------------
       3. LIMIT OTP ATTEMPTS
    ----------------------------------------- */

    } elseif ($attempts >= $maxAttempts) {
        unset(
            $_SESSION['verify_user'],
            $_SESSION['temp_user'],
            $_SESSION['verify_email'],
            $_SESSION['otp_attempts']
        );

        $error =
            'You have exceeded the maximum number of verification attempts. Please register again.';

    /* -----------------------------------------
       4. COMPARE OTP
    ----------------------------------------- */

    } elseif (
        $sessionOtp === '' ||
        !hash_equals($sessionOtp, $enteredOtp)
    ) {
        $attempts++;
        $_SESSION['otp_attempts'] = $attempts;

        $remainingAttempts = max(
            0,
            $maxAttempts - $attempts
        );

        if ($remainingAttempts === 0) {
            unset(
                $_SESSION['verify_user'],
                $_SESSION['temp_user'],
                $_SESSION['verify_email'],
                $_SESSION['otp_attempts']
            );

            $error =
                'You have exceeded the maximum number of verification attempts. Please register again.';
        } else {
            $error =
                'Invalid verification code. You have ' .
                $remainingAttempts .
                ' attempt(s) remaining.';
        }

    } else {
        /* =====================================
           OTP IS CORRECT
        ===================================== */

        $fullName = trim(
            (string) ($userData['full_name'] ?? '')
        );

        $email = strtolower(
            trim((string) ($userData['email'] ?? ''))
        );

        $course = trim(
            (string) ($userData['course'] ?? '')
        );

        $studentStatus = trim(
            (string) ($userData['student_status'] ?? '')
        );

        /*
         * IMPORTANT:
         * The registration form stores year level as strings
         * such as "1st Year", not integers.
         */
        $yearLevel = trim(
            (string) ($userData['year_level'] ?? '')
        );

        $section = strtoupper(
            trim((string) ($userData['section'] ?? ''))
        );

        $enrolledSubjects = strtoupper(
            trim((string) ($userData['enrolled_subjects'] ?? ''))
        );

        // The registration page already hashes the password.
        $passwordHash = (string) (
            $userData['password'] ?? ''
        );

        $role = 'student';

        /* -----------------------------------------
           5. VALIDATE TEMPORARY REGISTRATION DATA
        ----------------------------------------- */

        $allowedCourses = [
            'BSIT',
            'BSCpE',
            'BSBA',
            'BSE',
            'BECE',
            'BEE',
            'BTLE'
        ];

        $allowedYears = [
            '1st Year',
            '2nd Year',
            '3rd Year',
            '4th Year'
        ];

        $validData =
            $fullName !== '' &&
            strlen($fullName) <= 150 &&
            filter_var($email, FILTER_VALIDATE_EMAIL) &&
            strlen($email) <= 255 &&
            in_array($course, $allowedCourses, true) &&
            in_array(
                $studentStatus,
                ['Regular', 'Irregular'],
                true
            ) &&
            in_array($yearLevel, $allowedYears, true) &&
            $passwordHash !== '' &&
            strlen($passwordHash) >= 20 &&
            $enrolledSubjects !== '' &&
            $section !== '' &&
            strlen($section) <= 50;

        if (
            $studentStatus === 'Regular' &&
            !in_array(
                $section,
                ['A', 'B', 'C', 'D', 'E'],
                true
            )
        ) {
            $validData = false;
        }

        if (!$validData) {
            unset(
                $_SESSION['verify_user'],
                $_SESSION['temp_user'],
                $_SESSION['verify_email'],
                $_SESSION['otp_attempts']
            );

            $error =
                'Your temporary registration information is invalid or incomplete. Please register again.';

        } else {
            /* -----------------------------------------
               6. VALIDATE SUBJECT CODES AGAIN
            ----------------------------------------- */

            $rawSubjectCodes = explode(',', $enrolledSubjects);
            $subjectCodes = [];

            foreach ($rawSubjectCodes as $rawCode) {
                $code = strtoupper(trim($rawCode));

                // Ignore empty parts, matching registration normalization.
                if ($code === '') {
                    continue;
                }

                if (!otpValidSubjectCode($code)) {
                    $validData = false;
                    break;
                }

                // Remove duplicate subject codes.
                if (!in_array($code, $subjectCodes, true)) {
                    $subjectCodes[] = $code;
                }
            }

            if (count($subjectCodes) === 0) {
                $validData = false;
            }

            if (!$validData) {
                unset(
                    $_SESSION['verify_user'],
                    $_SESSION['temp_user'],
                    $_SESSION['verify_email'],
                    $_SESSION['otp_attempts']
                );

                $error =
                    'The subject-code information is invalid. Please register again.';

            } else {
                $enrolledSubjects = implode(', ', $subjectCodes);

                /* -----------------------------------------
                   7. CREATE ACCOUNT
                ----------------------------------------- */

                try {
                    if ($conn->inTransaction()) {
                        throw new RuntimeException(
                            'An unexpected database transaction is already active.'
                        );
                    }

                    $conn->beginTransaction();

                    /*
                     * Lock the system-limit record so concurrent
                     * registrations serialize their limit checks.
                     */
                    $limitStmt = $conn->query(
                        'SELECT max_users
                         FROM system_limits
                         ORDER BY id ASC
                         LIMIT 1
                         FOR UPDATE'
                    );

                    $limitRow = $limitStmt->fetch(
                        PDO::FETCH_ASSOC
                    );

                    if (
                        !$limitRow ||
                        !isset($limitRow['max_users']) ||
                        !is_numeric($limitRow['max_users'])
                    ) {
                        throw new RuntimeException(
                            'The registration limit is missing or invalid.'
                        );
                    }

                    $maxUsers = (int) $limitRow['max_users'];

                    if ($maxUsers <= 0) {
                        throw new RuntimeException(
                            'The registration limit must be greater than zero.'
                        );
                    }

                    /* Check whether email already exists. */

                    $emailStmt = $conn->prepare(
                        'SELECT user_id
                         FROM users
                         WHERE email = :email
                         LIMIT 1'
                    );

                    $emailStmt->execute([
                        ':email' => $email
                    ]);

                    if ($emailStmt->fetch(PDO::FETCH_ASSOC)) {
                        $conn->rollBack();

                        $error =
                            'This email address is already registered. Please log in or use another email address.';

                    } else {
                        /* Count all registered accounts. */

                        $countStmt = $conn->query(
                            'SELECT COUNT(*) FROM users'
                        );

                        $registeredUsers = (int) (
                            $countStmt->fetchColumn()
                        );

                        if ($registeredUsers >= $maxUsers) {
                            $conn->rollBack();

                            $error =
                                'The maximum number of user accounts has been reached. Please contact the administrator.';

                        } else {
                            /*
                             * Insert account only after successful
                             * OTP verification and validation.
                             */
                            $insertStmt = $conn->prepare(
                                'INSERT INTO users (
                                    full_name,
                                    email,
                                    course,
                                    student_status,
                                    year_level,
                                    section,
                                    enrolled_subjects,
                                    password,
                                    role
                                ) VALUES (
                                    :full_name,
                                    :email,
                                    :course,
                                    :student_status,
                                    :year_level,
                                    :section,
                                    :enrolled_subjects,
                                    :password,
                                    :role
                                )'
                            );

                            $insertStmt->execute([
                                ':full_name' => $fullName,
                                ':email' => $email,
                                ':course' => $course,
                                ':student_status' => $studentStatus,
                                ':year_level' => $yearLevel,
                                ':section' => $section,
                                ':enrolled_subjects' => $enrolledSubjects,
                                ':password' => $passwordHash,
                                ':role' => $role
                            ]);

                            $conn->commit();

                            /*
                             * Send a welcome email informing the user
                             * that their account has been created.
                             */
                            try {
                                require_once __DIR__ . '/../includes/PHPMailer-master/PHPMailer-master/src/Exception.php';
                                require_once __DIR__ . '/../includes/PHPMailer-master/PHPMailer-master/src/PHPMailer.php';
                                require_once __DIR__ . '/../includes/PHPMailer-master/PHPMailer-master/src/SMTP.php';

                                $mailConfigPath = __DIR__ . '/../includes/mail_config.php';

                                if (is_file($mailConfigPath)) {
                                    $mailConfig = require $mailConfigPath;

                                    if (is_array($mailConfig)) {
                                        $mailUsername = trim(
                                            (string) ($mailConfig['username'] ?? '')
                                        );

                                        $appPassword = preg_replace(
                                            '/\s+/',
                                            '',
                                            trim((string) ($mailConfig['app_password'] ?? ''))
                                        );

                                        if (
                                            $mailUsername !== '' &&
                                            $appPassword !== '' &&
                                            filter_var($mailUsername, FILTER_VALIDATE_EMAIL)
                                        ) {
                                            $loginScheme = !empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
                                                ? strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO'])
                                                : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
                                            $loginBasePath = rtrim(dirname(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
                                            $loginUrl = $loginScheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $loginBasePath . '/auth/login.php';

                                            $mail = new PHPMailer\PHPMailer\PHPMailer(true);

                                            $mail->isSMTP();
                                            $mail->Host = 'smtp.gmail.com';
                                            $mail->SMTPAuth = true;
                                            $mail->Username = $mailUsername;
                                            $mail->Password = $appPassword;
                                            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                                            $mail->Port = 587;
                                            $mail->CharSet = 'UTF-8';

                                            $mail->setFrom($mailUsername, 'NoteSphere');
                                            $mail->addAddress($email, $fullName);

                                            $mail->isHTML(true);
                                            $mail->Subject = 'Welcome to NoteSphere';

                                            $safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');

                                            $mail->Body = '
                                                <div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:24px;color:#222;">
                                                    <h2 style="color:#10b981;">Welcome to NoteSphere!</h2>
                                                    <p>Dear ' . $safeName . ',</p>
                                                    <p>
                                                        Greetings! Since you are enrolled at CDM, you have been
                                                        added to the NoteSphere system. Your account has been
                                                        successfully created and is now ready for use.
                                                    </p>
                                                    <p>
                                                        You may access the system by clicking the link below:
                                                    </p>
                                                    <p>
                                                        <a href="' . $loginUrl . '" style="color:#10b981;font-weight:bold;">
                                                            Access NoteSphere
                                                        </a>
                                                    </p>
                                                    <p>
                                                        If you did not expect this email, please disregard it.
                                                    </p>
                                                    <p>
                                                        Sincerely,<br>
                                                        <strong>The NoteSphere Team</strong>
                                                    </p>
                                                </div>
                                            ';

                                            $mail->AltBody =
                                                "Dear {$fullName},\n\n" .
                                                "Greetings! Since you are enrolled at CDM, you have been added to the NoteSphere system. " .
                                                "Your account has been successfully created and is now ready for use.\n\n" .
                                                "Access the system here: {$loginUrl}\n\n" .
                                                "Sincerely,\nThe NoteSphere Team";

                                            $mail->send();
                                        }
                                    }
                                }
                            } catch (Throwable $mailError) {
                                otpLog('Welcome email error: ' . $mailError->getMessage());
                            }

                            /*
                             * Clear registration data and regenerate
                             * the session ID after account creation.
                             */
                            unset(
                                $_SESSION['verify_user'],
                                $_SESSION['temp_user'],
                                $_SESSION['verify_email'],
                                $_SESSION['otp_attempts']
                            );

                            session_regenerate_id(true);

                            /*
                             * Redirect to a small success page.
                             * No second database insert occurs here.
                             */
                            ?>
                            <!DOCTYPE html>
                            <html lang="en">
                            <head>
                                <meta charset="UTF-8">
                                <meta
                                    name="viewport"
                                    content="width=device-width, initial-scale=1.0"
                                >
                                <title>Email Verified | NoteSphere</title>

                                <link
                                    href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap"
                                    rel="stylesheet"
                                >

                                <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

                                <style>
                                    * {
                                        box-sizing: border-box;
                                    }

                                    body {
                                        margin: 0;
                                        min-height: 100vh;
                                        display: flex;
                                        align-items: center;
                                        justify-content: center;
                                        padding: 20px;
                                        font-family: 'Plus Jakarta Sans', sans-serif;
                                        color: #f4f7f5;
                                        background:
                                            linear-gradient(
                                                rgba(3, 7, 5, .78),
                                                rgba(3, 7, 5, .92)
                                            ),
                                            url('../assets/login.jpg')
                                            center / cover no-repeat,
                                            #070b0a;
                                    }

                                    .success-card {
                                        width: 100%;
                                        max-width: 420px;
                                        padding: 32px 24px;
                                        text-align: center;
                                        border: 1px solid rgba(57, 255, 20, .22);
                                        border-radius: 20px;
                                        background: #111815;
                                    }

                                    h1 {
                                        margin: 0 0 12px;
                                        color: #39ff14;
                                        font-size: 24px;
                                    }

                                    p {
                                        color: #b6c0b9;
                                        font-size: 14px;
                                        line-height: 1.7;
                                    }
                                </style>
                            </head>
                            <body>
                                <main class="success-card">
                                    <h1>Email Verified!</h1>
                                    <p>
                                        Your NoteSphere account has been
                                        created successfully. You can now log in.
                                    </p>
                                </main>

                                <script>
                                    Swal.fire({
                                        title: 'Email Verified!',
                                        text: 'Your account has been created successfully. You can now log in.',
                                        icon: 'success',
                                        background: '#111815',
                                        color: '#f4f7f5',
                                        confirmButtonColor: '#39FF14',
                                        confirmButtonText: 'Go to Login',
                                        allowOutsideClick: false,
                                        allowEscapeKey: false
                                    }).then(function () {
                                        window.location.replace('login.php');
                                    });
                                </script>
</body>
                            </html>
                            <?php
                            exit;
                        }
                    }

                } catch (PDOException $e) {
                    if ($conn->inTransaction()) {
                        $conn->rollBack();
                    }

                    $sqlState = $e->errorInfo[0] ?? (string) $e->getCode();
                    $driverCode = (int) ($e->errorInfo[1] ?? 0);

                    if (
                        $sqlState === '23000' &&
                        $driverCode === 1062
                    ) {
                        $error =
                            'This email address is already registered. Please log in instead.';
                    } else {
                        otpLog(
                            'Database error: ' . $e->getMessage() .
                            ' | SQLSTATE: ' . $sqlState
                        );

                        $error =
                            'We could not create your account because of a database error. Please check the server error log.';
                    }

                } catch (Throwable $e) {
                    if ($conn->inTransaction()) {
                        $conn->rollBack();
                    }

                    otpLog(
                        'System error: ' . get_class($e) .
                        ' - ' . $e->getMessage() .
                        ' | File: ' . $e->getFile() .
                        ' | Line: ' . $e->getLine()
                    );

                    $error =
                        'A system error occurred while creating your account. Please check the server error log.';
                }
            }
        }
    }
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

    <title>Email Verification | NoteSphere</title>

    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            min-height: 100%;
        }

        body {
            min-height: 100vh;
            min-height: 100dvh;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: #f4f7f5;
            background:
                linear-gradient(
                    rgba(3, 7, 5, .72),
                    rgba(3, 7, 5, .88)
                ),
                url('../assets/login.jpg') center / cover no-repeat fixed,
                #070b0a;
        }

        .card {
            width: 100%;
            max-width: 420px;
            padding: 40px 30px;
            border: 1px solid rgba(57, 255, 20, .2);
            border-radius: 22px;
            background: rgba(17, 24, 21, .97);
            box-shadow: 0 20px 50px rgba(0, 0, 0, .5);
            text-align: center;
        }

        .brand {
            margin-bottom: 9px;
            color: #39ff14;
            font-size: 14px;
            font-weight: 800;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        h1 {
            margin-bottom: 10px;
            color: #f4f7f5;
            font-size: 25px;
            font-weight: 800;
            letter-spacing: -.5px;
        }

        .subtitle {
            margin-bottom: 25px;
            color: #a0aaa4;
            font-size: 13px;
            line-height: 1.8;
            overflow-wrap: anywhere;
        }

        .subtitle strong {
            color: #d9e5dc;
            font-weight: 700;
        }

        form {
            display: flex;
            flex-direction: column;
            width: 100%;
        }

        .input-group {
            margin-bottom: 17px;
            width: 100%;
        }

        input {
            width: 100%;
            min-height: 58px;
            padding: 14px 12px;
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 12px;
            outline: none;
            background: #090e0b;
            color: #f4f7f5;
            font-family: inherit;
            font-size: 25px;
            font-weight: 700;
            text-align: center;
            letter-spacing: 10px;
            transition: border-color .2s, box-shadow .2s;
        }

        input::placeholder {
            color: #4b574f;
            opacity: 1;
        }

        input:focus {
            border-color: #39ff14;
            box-shadow: 0 0 0 4px rgba(57, 255, 20, .09);
        }

        .hint {
            margin: 0 0 20px;
            color: #849087;
            font-size: 11px;
            line-height: 1.7;
        }

        .submit-btn {
            width: 100%;
            min-height: 49px;
            padding: 13px 16px;
            border: 1px solid transparent;
            border-radius: 11px;
            background: #39ff14;
            color: #061006;
            cursor: pointer;
            font-family: inherit;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: .5px;
            transition: background .2s, transform .2s, box-shadow .2s;
        }

        .submit-btn:hover {
            background: #32e010;
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(57, 255, 20, .14);
        }

        .submit-btn:disabled {
            cursor: wait;
            opacity: .65;
            transform: none;
            box-shadow: none;
        }

        .alert {
            width: 100%;
            margin-bottom: 18px;
            padding: 12px 13px;
            border: 1px solid rgba(251, 113, 133, .24);
            border-radius: 10px;
            background: rgba(251, 113, 133, .08);
            color: #fda4af;
            font-size: 12px;
            line-height: 1.7;
            overflow-wrap: anywhere;
        }

        .back-link {
            display: inline-block;
            margin-top: 22px;
            color: #a0aaa4;
            font-size: 12px;
            text-decoration: none;
            transition: color .2s;
        }

        .back-link:hover {
            color: #39ff14;
        }

        @media (max-width: 480px) {
            body {
                padding: 14px;
                background-attachment: scroll;
            }

            .card {
                padding: 32px 20px;
                border-radius: 18px;
            }

            h1 {
                font-size: 23px;
            }

            .subtitle {
                font-size: 12px;
            }

            input {
                min-height: 55px;
                font-size: 23px;
                letter-spacing: 8px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                transition-duration: .01ms !important;
            }
        }
    </style>
</head>

<body>
    <main class="card">
        <div class="brand">NoteSphere</div>

        <h1>Verify Your Email</h1>

        <p class="subtitle">
            Enter the six-digit verification code sent to
            <?php
            $verificationEmail = (string) (
                $_SESSION['verify_email'] ?? ''
            );
            ?>
            <strong><?= otpSafeOutput($verificationEmail) ?></strong>
        </p>

        <?php if ($error !== ''): ?>
            <div class="alert" role="alert">
                <?= otpSafeOutput($error) ?>
            </div>
        <?php endif; ?>

        <form
            method="POST"
            id="otpForm"
            autocomplete="on"
        >
            <div class="input-group">
                <input
                    type="text"
                    id="otp_code"
                    name="otp_code"
                    maxlength="6"
                    minlength="6"
                    pattern="[0-9]{6}"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    placeholder="------"
                    aria-label="Six-digit verification code"
                    aria-describedby="otpHint"
                    required
                    autofocus
                >
            </div>

            <p class="hint" id="otpHint">
                The code is valid for 10 minutes. You have up to
                five verification attempts.
            </p>

            <button
                type="submit"
                class="submit-btn"
                id="verifyBtn"
            >
                VERIFY EMAIL
            </button>
        </form>

        <a class="back-link" href="register.php">
            Back to Registration
        </a>
    </main>

    <script>
        const otpInput = document.getElementById('otp_code');
        const otpForm = document.getElementById('otpForm');
        const verifyBtn = document.getElementById('verifyBtn');

        // Keep only digits and limit the value to six characters.
        otpInput.addEventListener('input', function () {
            this.value = this.value
                .replace(/[^0-9]/g, '')
                .slice(0, 6);
        });

        otpForm.addEventListener('submit', function (event) {
            const value = otpInput.value.trim();

            if (!/^[0-9]{6}$/.test(value)) {
                event.preventDefault();

                Swal.fire({
                    icon: 'warning',
                    title: 'Invalid OTP Format',
                    text: 'Please enter all six digits of your verification code.',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#39FF14',
                    background: '#111815',
                    color: '#f4f7f5'
                });

                otpInput.focus();
                return;
            }

            verifyBtn.disabled = true;
            verifyBtn.textContent = 'VERIFYING...';
        });

        <?php if ($error !== ''): ?>
        Swal.fire({
            icon: 'error',
            title: 'Verification Failed',
            text: <?= json_encode(
                $error,
                JSON_HEX_TAG |
                JSON_HEX_APOS |
                JSON_HEX_QUOT |
                JSON_HEX_AMP
            ) ?>,
            confirmButtonText: 'OK',
            confirmButtonColor: '#39FF14',
            background: '#111815',
            color: '#f4f7f5'
        });
        <?php endif; ?>
    </script>
</body>
</html>

