<?php
/**
 * NoteSphere Login
 * Save as: C:\\xampp_new\\htdocs\\NoteSphere\\auth\\login.php
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $isHttps,
        'samesite' => 'Lax',
        'path' => '/'
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

require_once __DIR__ . '/../includes/db_config.php';
/** @var PDO $conn */

$error = '';
$errorType = 'Login Failed';
$securityAlert = false;
$userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
$ipAddress = substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
$isMobileDevice = (bool)preg_match('/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Mobile|Tablet/i', $userAgent);

$requestedRole = strtolower(trim((string)($_GET['role'] ?? '')));
$loginRoleLabels = [
    'student' => 'Student',
    'professor' => 'Professor',
    'admin' => 'Administrator'
];
$loginRoleLabel = $loginRoleLabels[$requestedRole] ?? '';

if (empty($_SESSION['login_csrf_token'])) {
    $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));
}
$loginCsrfToken = (string)$_SESSION['login_csrf_token'];

// -----------------------------------------------------------------------------
// Security table setup
// -----------------------------------------------------------------------------
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS login_security_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        email_attempted VARCHAR(255) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        user_agent VARCHAR(500) NULL,
        attempt_status VARCHAR(50) NOT NULL,
        details VARCHAR(500) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_login_security_created_at (created_at),
        INDEX idx_login_security_email (email_attempted),
        INDEX idx_login_security_status (attempt_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->exec("CREATE TABLE IF NOT EXISTS login_security_control (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        login_locked TINYINT(1) NOT NULL DEFAULT 0,
        lock_reason VARCHAR(500) NULL,
        updated_by INT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->exec("INSERT INTO login_security_control (id, login_locked, lock_reason)
                 VALUES (1, 0, NULL)
                 ON DUPLICATE KEY UPDATE id = id");

    $conn->exec("CREATE TABLE IF NOT EXISTS login_security_blocklist (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        blocked_ip VARCHAR(45) NOT NULL,
        email_attempted VARCHAR(255) NULL,
        alert_id BIGINT UNSIGNED NULL,
        blocked_by INT NULL,
        blocked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_login_security_blocked_ip (blocked_ip)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    error_log('NoteSphere security table setup error: ' . $e->getMessage());
}

$loginLocked = false;
$loginLockReason = 'The login page has been temporarily locked by the administrator because of repeated suspicious sign-in attempts. Please try again later.';
$clientIpBlocked = false;
try {
    // Re-read the control row on every page request so admin changes take effect on refresh.
    $lockStmt = $conn->query('SELECT login_locked, lock_reason FROM login_security_control WHERE id = 1 LIMIT 1');
    $lockRow = $lockStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $loginLocked = ((int)($lockRow['login_locked'] ?? 0) === 1);
    if (trim((string)($lockRow['lock_reason'] ?? '')) !== '') {
        $loginLockReason = (string)$lockRow['lock_reason'];
    }

    $blockStmt = $conn->prepare('SELECT 1 FROM login_security_blocklist WHERE blocked_ip = ? LIMIT 1');
    $blockStmt->execute([$ipAddress]);
    $clientIpBlocked = (bool)$blockStmt->fetchColumn();
} catch (Throwable $e) {
    error_log('NoteSphere security control read error: ' . $e->getMessage());
}

function logUnknownAccountAttempt(PDO $conn, string $email, string $ipAddress, string $userAgent): bool
{
    try {
        $countStmt = $conn->prepare("SELECT COUNT(*) FROM login_security_logs
            WHERE email_attempted = ? AND ip_address = ?
              AND attempt_status IN ('unknown_account', 'unknown_account_alert')
              AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $countStmt->execute([$email, $ipAddress]);
        $previousAttempts = (int)$countStmt->fetchColumn();
        $isThirdAttempt = ($previousAttempts === 2);
        $status = $isThirdAttempt ? 'unknown_account_alert' : 'unknown_account';
        $details = $isThirdAttempt
            ? 'Security alert: third failed login attempt using an unregistered email within 15 minutes.'
            : 'Login attempted using an email not registered in the users table.';

        $insertStmt = $conn->prepare('INSERT INTO login_security_logs
            (email_attempted, ip_address, user_agent, attempt_status, details)
            VALUES (?, ?, ?, ?, ?)');
        $insertStmt->execute([
            $email,
            substr($ipAddress, 0, 45),
            substr($userAgent, 0, 500),
            $status,
            $details
        ]);
        return $isThirdAttempt;
    } catch (Throwable $e) {
        error_log('NoteSphere login attempt logging error: ' . $e->getMessage());
        return false;
    }
}

// -----------------------------------------------------------------------------
// Process login
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $postedToken = (string)($_POST['login_csrf_token'] ?? '');

    if (!hash_equals($loginCsrfToken, $postedToken)) {
        $error = 'Your login form expired. Refresh the page and try again.';
        $errorType = 'Session Expired';
    } elseif ($email === '') {
        $error = 'Please enter your email.';
        $errorType = 'Email Required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        $error = 'Please enter a valid email address.';
        $errorType = 'Invalid Email';
    } elseif ($password === '') {
        $error = 'Please enter your password.';
        $errorType = 'Password Required';
    } else {
        try {
            $stmt = $conn->prepare("SELECT user_id, full_name, email, course, student_status,
                year_level, section, enrolled_subjects, password, role, warning_count, account_status
                FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // A valid admin credential can pass the global lock/IP gate to manage the system.
            // The existing desktop-only admin-login rule is retained below.
            $validAdminDuringLock = $user
                && password_verify($password, (string)($user['password'] ?? ''))
                && (($user['role'] ?? '') === 'admin');

            if (($loginLocked || $clientIpBlocked) && !$validAdminDuringLock) {
                $error = $clientIpBlocked
                    ? 'Sign-in from this network has been blocked by the administrator after suspicious activity.'
                    : $loginLockReason;
                $errorType = $clientIpBlocked ? 'Access Temporarily Blocked' : 'Login Temporarily Locked';
            } elseif (!$user) {
                $securityAlert = logUnknownAccountAttempt($conn, $email, $ipAddress, $userAgent);
                if ($securityAlert && function_exists('curl_init')) {
                    $pushPayload = json_encode([
                        'title' => 'NoteSphere Security Alert',
                        'body' => 'Third failed login attempt with unregistered email: ' . $email,
                        'tag' => 'notesphere-security-login',
                        'url' => '/NoteSphere/admin/admin_dashboard.php'
                    ]);
                    $ch = curl_init(ns_push_server_url('notify'));
                    if ($ch !== false) {
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $pushPayload);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                        curl_exec($ch);
                        curl_close($ch);
                    }
                }
                $error = 'Incorrect email or password.';
                $errorType = 'Login Failed';
            } elseif (!password_verify($password, (string)$user['password'])) {
                $error = 'Incorrect email or password.';
                $errorType = 'Login Failed';
            } elseif (strtolower(trim((string)($user['account_status'] ?? 'active'))) === 'banned') {
                $error = 'Your account has been banned. Please contact the administrator.';
                $errorType = 'Account Banned';
            } elseif (!in_array((string)$user['role'], ['student', 'professor', 'admin'], true)) {
                $error = 'Your account role is not valid.';
                $errorType = 'Invalid Account Role';
            } elseif ($user['role'] === 'admin' && $isMobileDevice) {
                $error = 'Admin login is only available on a desktop or laptop. Please use a computer to access the Admin Dashboard.';
                $errorType = 'Desktop Access Required';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['user_id'];
                $_SESSION['role'] = (string)$user['role'];
                $_SESSION['full_name'] = (string)$user['full_name'];
                $_SESSION['email'] = (string)$user['email'];
                $_SESSION['course'] = (string)($user['course'] ?? '');
                $_SESSION['student_status'] = (string)($user['student_status'] ?? '');
                $_SESSION['year_level'] = (string)($user['year_level'] ?? '');
                $_SESSION['section'] = (string)($user['section'] ?? '');
                $_SESSION['enrolled_subjects'] = (string)($user['enrolled_subjects'] ?? '');
                $_SESSION['warning_count'] = (int)($user['warning_count'] ?? 0);
                $_SESSION['account_status'] = (string)($user['account_status'] ?? 'active');
                $_SESSION['login_device'] = $isMobileDevice ? 'mobile' : 'desktop';
                unset($_SESSION['login_csrf_token']);

                if ($user['role'] === 'student') {
                    header('Location: ../dashboard/student_home.php');
                    exit;
                }
                if ($user['role'] === 'professor') {
                    header('Location: ../professor/professor_dashboard.php');
                    exit;
                }
                if ($user['role'] === 'admin') {
                    header('Location: ../admin/admin_landing.php');
                    exit;
                }
            }
        } catch (PDOException $e) {
            error_log('NoteSphere login database error: ' . $e->getMessage());
            $error = 'Something went wrong with the database. Please try again.';
            $errorType = 'System Error';
        } catch (Throwable $e) {
            error_log('NoteSphere login error: ' . get_class($e) . ' - ' . $e->getMessage());
            $error = 'An unexpected error occurred. Please try again later.';
            $errorType = 'System Error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#05070d">
    <meta name="application-name" content="NoteSphere">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="NoteSphere">
    <meta name="apple-mobile-web-app-status-bar-style" content="black">
    <link rel="apple-touch-icon" sizes="180x180" href="../assets/apple-touch-icon.png">
    <title>Login | NoteSphere</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { width: 100%; min-height: 100%; }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(rgba(0,0,0,.28), rgba(0,0,0,.45)), url('../assets/login.jpg') no-repeat center center / cover fixed;
            color: #fff; display: flex; justify-content: center; align-items: center;
            min-height: 100vh; min-height: 100dvh;
            padding: calc(20px + env(safe-area-inset-top, 0px)) calc(20px + env(safe-area-inset-right, 0px)) calc(20px + env(safe-area-inset-bottom, 0px)) calc(20px + env(safe-area-inset-left, 0px));
            overflow-y: auto; -webkit-tap-highlight-color: transparent;
        }
        .login-security-banner { margin: 0 0 16px; padding: 13px 14px; border: 1px solid rgba(220,38,38,.55); border-radius: 12px; background: rgba(127,29,29,.28); color: #fff; text-align: left; }
        .login-security-banner strong { display:block; color:#fecaca; font-size:13px; margin-bottom:5px; }
        .login-security-banner span { display:block; color:#f3f4f6; font-size:11px; line-height:1.5; }
        .btn-back-fixed { position: fixed; top: calc(25px + env(safe-area-inset-top, 0px)); left: calc(25px + env(safe-area-inset-left, 0px)); display:flex; align-items:center; text-decoration:none; color:#888; z-index:100; }
        .back-circle { width:42px; height:42px; border:1px solid rgba(255,255,255,.15); border-radius:50%; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,.4); backdrop-filter:blur(5px); -webkit-backdrop-filter:blur(5px); transition:.3s; }
        .btn-back-fixed:hover .back-circle { border-color:#39FF14; color:#39FF14; transform:translateX(-3px); }
        .btn-back-fixed:focus-visible, button:focus-visible, input:focus-visible, a:focus-visible { outline:2px solid #39FF14; outline-offset:3px; }
        .card { width:100%; max-width:440px; padding:40px 30px; border:1px solid #1a1a1a; border-radius:24px; background:#0a0a0a; box-shadow:0 20px 40px rgba(0,0,0,.8); }
        h2 { font-size:1.75rem; font-weight:800; margin-bottom:8px; text-align:center; }
        .desc { font-size:.85rem; color:#888; text-align:center; margin-bottom:30px; line-height:1.6; }
        .login-role-badge { display:flex; align-items:center; justify-content:center; gap:7px; width:fit-content; margin:0 auto 13px; padding:6px 11px; border:1px solid rgba(57,255,20,.28); border-radius:999px; background:rgba(57,255,20,.08); color:#39FF14; font-size:.62rem; font-weight:800; letter-spacing:.1em; text-transform:uppercase; }
        .login-role-badge::before { content:""; width:6px; height:6px; border-radius:50%; background:#39FF14; box-shadow:0 0 8px rgba(57,255,20,.7); }
        .back-to-site { display:block; margin-top:20px; color:#888; font-size:.72rem; font-weight:600; text-align:center; text-decoration:none; transition:color .2s; }
        .back-to-site:hover { color:#39FF14; }
        form { display:flex; flex-direction:column; width:100%; }
        .input-group { margin-bottom:15px; }
        input { width:100%; padding:14px 16px; border-radius:12px; border:1px solid #222; background:#111; color:#fff; font-family:inherit; font-size:1rem; outline:none; transition:.3s; -webkit-appearance:none; appearance:none; }
        input::placeholder { color:#777; }
        input:focus { border-color:#39FF14; background:#141414; box-shadow:0 0 0 4px rgba(57,255,20,.08); }
        .password-box { position:relative; }
        .password-box input { padding-right:70px; }
        .show-password { position:absolute; right:12px; top:50%; transform:translateY(-50%); background:transparent; border:none; color:#888; cursor:pointer; font-family:inherit; font-size:.75rem; font-weight:700; transition:color .3s; }
        .show-password:hover { color:#39FF14; }
        .forgot { text-align:right; margin-top:-5px; margin-bottom:20px; }
        .forgot a { color:#39FF14; text-decoration:none; font-size:.8rem; }
        .forgot a:hover { text-decoration:underline; }
        button[type="submit"] { width:100%; padding:14px; border-radius:12px; border:none; background:#39FF14; color:#000; font-family:'Plus Jakarta Sans',sans-serif; font-weight:800; font-size:.95rem; cursor:pointer; transition:.3s; }
        button[type="submit"]:hover { background:#32e010; transform:translateY(-2px); box-shadow:0 5px 20px rgba(57,255,20,.3); }
        button[type="submit"]:disabled { opacity:.65; cursor:wait; transform:none; box-shadow:none; }
        @media (max-width:480px) { body { padding: calc(75px + env(safe-area-inset-top, 0px)) calc(15px + env(safe-area-inset-right, 0px)) calc(20px + env(safe-area-inset-bottom, 0px)) calc(15px + env(safe-area-inset-left, 0px)); background-attachment:scroll; } .btn-back-fixed { top:calc(15px + env(safe-area-inset-top, 0px)); left:calc(15px + env(safe-area-inset-left, 0px)); } .card { padding:30px 20px; border-radius:20px; } h2 { font-size:1.55rem; } }
        @media (max-height:520px) { body { align-items:flex-start; } }
        @supports (-webkit-touch-callout: none) { input, select, textarea { font-size:16px; } }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { transition-duration:.01ms !important; } }
    </style>
</head>
<body>
    <a href="../portal.php" class="btn-back-fixed" aria-label="Back to portal" title="Back to portal">
        <div class="back-circle"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg></div>
    </a>
    <main class="card">
        <?php if ($loginRoleLabel !== ''): ?>
            <div class="login-role-badge"><?= htmlspecialchars($loginRoleLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> access</div>
        <?php endif; ?>
        <h2>Welcome Back</h2>
        <p class="desc">Login to your NoteSphere account.</p>
        <?php if ($loginLocked || $clientIpBlocked): ?>
            <div class="login-security-banner" role="alert">
                <strong><?= $loginLocked ? 'Login Temporarily Locked' : 'Access Temporarily Blocked' ?></strong>
                <span><?= htmlspecialchars($clientIpBlocked ? 'Sign-in from this network has been blocked by the administrator after suspicious activity.' : $loginLockReason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>
        <form method="POST" id="loginForm" action="" novalidate>
            <input type="hidden" name="login_csrf_token" value="<?= htmlspecialchars($loginCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <div class="input-group">
                <input type="email" name="email" id="email" placeholder="Enter your email" value="<?= htmlspecialchars((string)($_POST['email'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="255" autocomplete="username" aria-label="Email address" required>
            </div>
            <div class="input-group password-box">
                <input type="password" name="password" id="password" placeholder="Enter your password" autocomplete="current-password" aria-label="Password" required>
                <button type="button" class="show-password" id="showPassword" aria-label="Show password" aria-pressed="false">Show</button>
            </div>
            <div class="forgot"><a href="forgot_password.php">Forgot Password?</a></div>
            <button type="submit" id="loginButton">Login</button>
        </form>
        <a class="back-to-site" href="../website/index.php">Back to the NoteSphere website</a>
    </main>
    <script>
        const form = document.getElementById('loginForm');
        const email = document.getElementById('email');
        const password = document.getElementById('password');
        const showPassword = document.getElementById('showPassword');
        const loginButton = document.getElementById('loginButton');
        const alertTheme = { confirmButtonColor:'#39FF14', background:'#0a0a0a', color:'#fff' };

        showPassword.addEventListener('click', function () {
            const isHidden = password.type === 'password';
            password.type = isHidden ? 'text' : 'password';
            showPassword.textContent = isHidden ? 'Hide' : 'Show';
            showPassword.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            showPassword.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            const emailValue = email.value.trim();
            if (emailValue === '') {
                Swal.fire({...alertTheme, icon:'warning', title:'Email Required', text:'Please enter your email.'}).then(() => email.focus());
                return;
            }
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailValue) || emailValue.length > 255) {
                Swal.fire({...alertTheme, icon:'warning', title:'Invalid Email', text:'Please enter a valid email address.'}).then(() => email.focus());
                return;
            }
            if (password.value === '') {
                Swal.fire({...alertTheme, icon:'warning', title:'Password Required', text:'Please enter your password.'}).then(() => password.focus());
                return;
            }
            loginButton.disabled = true;
            loginButton.textContent = 'LOGGING IN...';
            HTMLFormElement.prototype.submit.call(form);
        });
    </script>
    <?php if ($securityAlert): ?>
        <script>Swal.fire({icon:'warning',title:'Security Warning',text:'Multiple unsuccessful login attempts have been detected. This attempt has been recorded for security review.',confirmButtonText:'Understood',confirmButtonColor:'#39FF14',background:'#0a0a0a',color:'#fff',allowOutsideClick:false});</script>
    <?php elseif (($loginLocked || $clientIpBlocked) && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
        <script>Swal.fire({icon:'warning',title:<?= json_encode($clientIpBlocked ? 'Access Temporarily Blocked' : 'Login Temporarily Locked', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,text:<?= json_encode($clientIpBlocked ? 'Sign-in from this network has been blocked by the administrator after suspicious activity.' : $loginLockReason, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,confirmButtonText:'I Understand',confirmButtonColor:'#dc2626',background:'#0a0a0a',color:'#fff',allowOutsideClick:false});</script>
    <?php elseif ($error !== ''): ?>
        <script>Swal.fire({icon:'error',title:<?= json_encode($errorType, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,text:<?= json_encode($error, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,confirmButtonColor:'#39FF14',background:'#0a0a0a',color:'#fff'});</script>
    <?php endif; ?>
</body>
</html>

