<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// I-load ang database configuration file mula sa includes folder
require_once('../includes/db_config.php');

// Tiyakin na nag-eexist ang $conn mula sa db_config.php
if (!isset($conn)) {
    die("Database connection error: \$conn variable is not initialized in db_config.php");
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Tamang path para sa nested PHPMailer-master folders sa loob ng includes/
require_once('../includes/PHPMailer-master/PHPMailer-master/src/Exception.php');
require_once('../includes/PHPMailer-master/PHPMailer-master/src/PHPMailer.php');
require_once('../includes/PHPMailer-master/PHPMailer-master/src/SMTP.php');

$swl_script = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_temp_pass'])) {
    $email = trim($_POST['email']);
    
    if (!isset($_SESSION['resend_count'])) {
        $_SESSION['resend_count'] = 0;
    }

    if ($_SESSION['resend_count'] >= 3) {
        $swl_script = "Swal.fire({ 
            icon: 'warning', 
            title: 'Maximum Attempts Reached', 
            text: 'You have requested a temporary password multiple times. Please check your email or make sure your account is registered in the system.', 
            confirmButtonColor: '#39FF14' 
        });";
    } else {
        // Gamitin ang $conn para sa secure PDO prepared statement
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $temp_pass = 'Temp' . rand(1000, 9999) . '!';
            $hashed_pass = password_hash($temp_pass, PASSWORD_DEFAULT);

            $update = $conn->prepare("UPDATE users SET password = ?, is_first_login = 1 WHERE email = ?");
            $update->execute([$hashed_pass, $email]);

            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'notesphere.system@gmail.com'; // Palitan ng iyong system Gmail
                $mail->Password   = 'fesn khzz ucxc nuid';                // Palitan ng iyong Gmail App Password
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;

                $mail->setFrom('your_school_system_email@gmail.com', 'NoteSphere Portal');
                $mail->addAddress($email, $user['full_name']);

                $mail->isHTML(true);
                $mail->Subject = 'NoteSphere Access - Your Temporary Password';
                $mail->Body    = "
                    <div style='font-family: Arial, sans-serif; padding: 20px; background: #f4f4f4;'>
                        <div style='max-width: 500px; background: #fff; padding: 20px; border-radius: 10px; margin: auto;'>
                            <h2 style='color: #0d6efd;'>NoteSphere Temporary Password</h2>
                            <p>Hello <b>{$user['full_name']}</b>,</p>
                            <p>Use the temporary password below to log into your account:</p>
                            <div style='background: #e9ecef; padding: 15px; text-align: center; font-size: 20px; font-weight: bold; letter-spacing: 2px; border-radius: 5px;'>
                                {$temp_pass}
                            </div>
                            <p style='color: #666; font-size: 12px; margin-top: 15px;'>Please change your password immediately after logging in.</p>
                        </div>
                    </div>";

                $mail->send();
                $_SESSION['resend_count']++;
                $remaining = 3 - $_SESSION['resend_count'];

                $swl_script = "Swal.fire({
                    icon: 'success',
                    title: 'Temporary Password Sent!',
                    text: 'Your temporary password has been sent to your email. (Remaining attempts: {$remaining})',
                    confirmButtonColor: '#39FF14'
                }).then(() => { window.location.href = 'login.php?email=" . urlencode($email) . "'; });";

            } catch (Exception $e) {
                $swl_script = "Swal.fire({ 
                    icon: 'error', 
                    title: 'Email Error', 
                    text: 'Failed to send email. Mailer Error: {$mail->ErrorInfo}', 
                    confirmButtonColor: '#39FF14' 
                });";
            }
        } else {
            $swl_script = "Swal.fire({ 
                icon: 'error', 
                title: 'Account Not Found', 
                text: 'The email you entered is not registered in the school database.', 
                confirmButtonColor: '#39FF14' 
            });";
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
    <title>Request Access | NoteSphere</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        html { 
            width: 100%; 
            min-height: 100%; 
            -webkit-text-size-adjust: 100%;
        }
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background: url('../assets/login.jpg') no-repeat center/cover; 
            width: 100%;
            min-height: 100vh; 
            min-height: 100dvh; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            color: #fff; 
            margin: 0; 
            padding: calc(15px + env(safe-area-inset-top, 0px))
                     calc(15px + env(safe-area-inset-right, 0px))
                     calc(15px + env(safe-area-inset-bottom, 0px))
                     calc(15px + env(safe-area-inset-left, 0px)); 
            box-sizing: border-box; 
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            -webkit-tap-highlight-color: transparent;
            touch-action: manipulation; 
        }
        .card { 
            background: rgba(10, 10, 10, 0.9); 
            backdrop-filter: blur(10px); 
            -webkit-backdrop-filter: blur(10px);
            padding: 30px 20px; 
            border-radius: 20px; 
            border: 1px solid #333; 
            width: 100%; 
            max-width: 400px; 
            text-align: center; 
            box-sizing: border-box; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        }
        .cdmlogo-img { 
            width: 90px; 
            max-width: 100%; 
            margin-bottom: 10px; 
        }
        h2 { 
            margin-bottom: 5px; 
            font-weight: 800; 
            color: #fff; 
            font-size: 1.5rem;
        }
        p.subtitle { 
            font-size: 12px; 
            color: #aaa; 
            margin-bottom: 25px; 
            line-height: 1.4;
        }
        .form-group { 
            margin-bottom: 15px; 
            text-align: left; 
        }
        .form-group label { 
            font-size: 12px; 
            font-weight: 700; 
            color: #ccc; 
            display: block; 
            margin-bottom: 5px; 
        }
        .form-control { 
            width: 100%; 
            padding: 12px 15px; 
            background: #1a1a1a; 
            border: 1px solid #444; 
            border-radius: 10px; 
            color: #fff; 
            font-size: 16px; 
            box-sizing: border-box; 
            -webkit-appearance: none;
            appearance: none;
        }
        .form-control:focus { 
            outline: none; 
            border-color: #39FF14; 
        }
        .btn { 
            width: 100%; 
            padding: 14px; 
            border-radius: 12px; 
            font-weight: 700; 
            cursor: pointer; 
            transition: 0.3s; 
            border: none; 
            font-size: 14px; 
            margin-top: 5px; 
            text-decoration: none; 
            display: block; 
            box-sizing: border-box; 
            text-align: center;
            -webkit-tap-highlight-color: transparent;
        }
        .btn-submit { 
            background: #39FF14; 
            color: #000; 
            box-shadow: 0 4px 15px rgba(57, 255, 20, 0.3);
        }
        .btn-submit:hover { 
            opacity: 0.95; 
            transform: scale(1.02); 
        }
        .btn-cancel { 
            background: transparent; 
            color: #aaa; 
            border: 1px solid #444; 
            margin-top: 10px; 
        }
        .btn-cancel:hover { 
            background: rgba(255,255,255,0.05); 
            color: #fff; 
        }
        @media (max-height: 520px) {
            body { align-items: flex-start; }
        }
        @supports (-webkit-touch-callout: none) {
            body { background-attachment: scroll; }
            input, select, textarea { font-size: 16px; }
        }
    </style>
</head>
<body>
    <div class="card">
        <h2>NoteSphere Access</h2>
        <p class="subtitle">Enter your official school email to receive your temporary password.</p>
        <form action="" method="POST">
            <div class="form-group">
                <label>School Email Address</label>
                <input type="email" name="email" class="form-control" placeholder="name@cdm.edu.ph" required>
            </div>
            <button type="submit" name="send_temp_pass" class="btn btn-submit">Send Temporary Password</button>
            <a href="../portal.php" class="btn btn-cancel">Cancel</a>
        </form>
    </div>
    <script>
        <?php echo $swl_script; ?>
    </script>
</body>
</html>
