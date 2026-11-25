<?php

session_start();

include('../includes/db_config.php');

/** @var PDO $conn */

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$success_msg = '';
$error_msg = '';


// =========================================================
// UPDATE PROFILE
// =========================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');

    // Fetch old profile picture
    // IMPORTANT: users table uses user_id, NOT id
    $stmt_old = $conn->prepare("
        SELECT profile_pic
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");

    $stmt_old->execute([$user_id]);

    $current_db_pic = $stmt_old->fetchColumn();

    $profile_pic_db = null;


    // =====================================================
    // PROFILE PICTURE UPLOAD
    // =====================================================

    if (
        isset($_FILES['profile_pic']) &&
        $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK
    ) {

        $file_tmp = $_FILES['profile_pic']['tmp_name'];
        $file_name = $_FILES['profile_pic']['name'];

        $file_ext = strtolower(
            pathinfo($file_name, PATHINFO_EXTENSION)
        );

        // Allowed image extensions
        $allowed_extensions = [
            'jpg',
            'jpeg',
            'png',
            'webp',
            'gif',
            'bmp',
            'jfif',
            'heic'
        ];


        if (in_array($file_ext, $allowed_extensions, true)) {

            $upload_dir = '../uploads/';

            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }


            $new_file_name =
                'user_' .
                $user_id .
                '_' .
                time() .
                '_' .
                uniqid() .
                '.' .
                $file_ext;

            $destination =
                $upload_dir .
                $new_file_name;


            if (move_uploaded_file($file_tmp, $destination)) {

                // Path saved in database
                $profile_pic_db =
                    'uploads/' .
                    $new_file_name;


                // =================================================
                // DELETE OLD PROFILE PICTURE
                // =================================================

                if (
                    !empty($current_db_pic) &&
                    strpos($current_db_pic, 'http') !== 0 &&
                    $current_db_pic !== 'profile'
                ) {

                    $old_file_path =
                        '../' .
                        ltrim($current_db_pic, '/');

                    if (file_exists($old_file_path)) {
                        @unlink($old_file_path);
                    }
                }

            } else {

                $error_msg =
                    "There was a problem uploading the image. Please try again.";
            }

        } else {

            $error_msg =
                "Please select a valid image file (PNG, JPG, JPEG, WEBP, Screenshot, etc.).";
        }
    }


    // =========================================================
    // SAVE PROFILE INFORMATION
    // =========================================================

    if (empty($error_msg)) {

        if ($profile_pic_db !== null) {

            $update_stmt = $conn->prepare("
                UPDATE users
                SET
                    full_name = ?,
                    email = ?,
                    profile_pic = ?
                WHERE user_id = ?
            ");

            $update_stmt->execute([
                $full_name,
                $email,
                $profile_pic_db,
                $user_id
            ]);

        } else {

            $update_stmt = $conn->prepare("
                UPDATE users
                SET
                    full_name = ?,
                    email = ?
                WHERE user_id = ?
            ");

            $update_stmt->execute([
                $full_name,
                $email,
                $user_id
            ]);
        }


        $_SESSION['success_msg'] =
            "Your profile has been successfully updated!";

        header("Location: profile.php");
        exit();
    }
}


// =========================================================
// FETCH CURRENT USER
// =========================================================

$stmt = $conn->prepare("
    SELECT *
    FROM users
    WHERE user_id = ?
    LIMIT 1
");

$stmt->execute([$user_id]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);


// =========================================================
// PROFILE PICTURE PATH
// =========================================================

if (
    !empty($user['profile_pic']) &&
    $user['profile_pic'] !== 'profile'
) {

    if (strpos($user['profile_pic'], 'http') === 0) {

        $profile_pic = $user['profile_pic'];

    } else {

        $profile_pic =
            '../' .
            ltrim($user['profile_pic'], '/');
    }

} else {

    $profile_pic = '';
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

    <title>Edit Profile | NoteSphere</title>

    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <style>

        :root {
            --neon: #10b981;
            --neon-glow: rgba(16, 185, 129, 0.15);

            --bg-overlay:
                linear-gradient(
                    rgba(244, 246, 249, 0.45),
                    rgba(244, 246, 249, 0.65)
                );

            --card-bg: rgba(255, 255, 255, 0.92);

            --text: #0f172a;
            --border-color: #e2e8f0;
            --sub-text: #64748b;

            --input-bg: #f8fafc;
            --hover-bg: #f1f5f9;
        }


        body.dark-mode {

            --neon: #39FF14;

            --neon-glow:
                rgba(57, 255, 20, 0.15);

            --bg-overlay:
                linear-gradient(
                    rgba(11, 11, 11, 0.75),
                    rgba(11, 11, 11, 0.85)
                );

            --card-bg:
                rgba(18, 18, 20, 0.92);

            --text: #f8fafc;

            --border-color: #27272a;

            --sub-text: #94a3b8;

            --input-bg: #09090b;

            --hover-bg: #1e1e24;
        }


        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }


        html {

            background:
                var(--bg-overlay),
                linear-gradient(135deg,rgba(5,7,13,.55),rgba(5,7,13,.25)),repeating-linear-gradient(0deg,rgba(57,255,20,.04) 0 1px,transparent 1px 34px),repeating-linear-gradient(90deg,rgba(57,255,20,.025) 0 1px,transparent 1px 34px)
                no-repeat
                center
                center
                fixed;

            background-size: cover;

            min-height: 100%;
        }


        body {

            background: transparent;

            color: var(--text);

            display: flex;

            justify-content: center;

            align-items: center;

            min-height: 100vh;

            padding: 20px;

            transition: color 0.3s ease;
        }


        .container {

            width: 100%;

            max-width: 500px;

            margin: auto;
        }


        .card {

            background: var(--card-bg);

            backdrop-filter: blur(8px);

            -webkit-backdrop-filter: blur(8px);

            border: 1px solid var(--border-color);

            border-radius: 20px;

            padding: 30px;

            box-shadow:
                0 12px 32px rgba(0,0,0,0.1);

            transition:
                background-color 0.3s ease,
                border-color 0.3s ease;
        }


        .header {

            display: flex;

            align-items: center;

            justify-content: space-between;

            margin-bottom: 25px;
        }


        .header h2 {

            font-size: 1.15rem;

            font-weight: 700;

            color: var(--text);

            display: flex;

            align-items: center;

            gap: 8px;
        }


        .close-btn-card {

            width: 34px;

            height: 34px;

            border-radius: 50%;

            background: var(--hover-bg);

            border: 1px solid var(--border-color);

            display: flex;

            align-items: center;

            justify-content: center;

            cursor: pointer;

            color: var(--sub-text);

            text-decoration: none;

            transition: all 0.2s ease;

            flex-shrink: 0;
        }


        .close-btn-card svg {

            width: 16px;

            height: 16px;

            stroke: currentColor;

            stroke-width: 2.2;

            fill: none;

            stroke-linecap: round;

            stroke-linejoin: round;
        }


        .close-btn-card:hover {

            background: var(--border-color);

            color: var(--neon);

            border-color: var(--neon);
        }


        .avatar-preview {

            display: flex;

            flex-direction: column;

            align-items: center;

            margin-bottom: 20px;
        }


        .avatar-preview img {

            width: 90px;

            height: 90px;

            border-radius: 50%;

            object-fit: cover;

            border: 2px solid var(--neon);

            margin-bottom: 12px;

            background: var(--border-color);
        }


        .avatar-initials {

            width: 90px;

            height: 90px;

            border-radius: 50%;

            background: var(--neon-glow);

            border: 2px solid var(--neon);

            color: var(--neon);

            display: flex;

            align-items: center;

            justify-content: center;

            font-weight: 800;

            font-size: 2.2rem;

            margin-bottom: 12px;
        }


        .form-group {

            margin-bottom: 15px;
        }


        .form-group label {

            display: block;

            font-size: 0.85rem;

            font-weight: 600;

            color: var(--sub-text);

            margin-bottom: 6px;
        }


        .form-control {

            width: 100%;

            padding: 12px 14px;

            background: var(--input-bg);

            border: 1px solid var(--border-color);

            color: var(--text);

            border-radius: 12px;

            font-size: 0.95rem;

            outline: none;

            transition:
                border-color 0.2s,
                background-color 0.3s,
                color 0.3s;
        }


        .form-control:focus {

            border-color: var(--neon);
        }


        .btn-save {

            background: var(--neon);

            color: #000;

            border: none;

            padding: 12px;

            font-weight: 800;

            border-radius: 12px;

            cursor: pointer;

            width: 100%;

            font-size: 0.95rem;

            margin-top: 10px;

            transition:
                opacity 0.2s,
                transform 0.2s;
        }


        .btn-save:hover {

            opacity: 0.9;

            transform: scale(1.01);
        }


        .alert-success {

            background: rgba(16, 185, 129, 0.1);

            border: 1px solid var(--neon);

            color: var(--neon);

            padding: 10px;

            border-radius: 10px;

            margin-bottom: 15px;

            font-size: 0.85rem;

            text-align: center;
        }


        .alert-error {

            background: rgba(255, 77, 77, 0.1);

            border: 1px solid #ff4d4d;

            color: #ff4d4d;

            padding: 10px;

            border-radius: 10px;

            margin-bottom: 15px;

            font-size: 0.85rem;

            text-align: center;
        }

    </style>

</head>


<body>

<div class="container">

    <div class="card">

        <div class="header">

            <h2>⚙️ Edit Profile</h2>

            <a
                href="help_board.php"
                class="close-btn-card"
                title="Back"
            >

                <svg viewBox="0 0 24 24">

                    <line
                        x1="18"
                        y1="6"
                        x2="6"
                        y2="18"
                    ></line>

                    <line
                        x1="6"
                        y1="6"
                        x2="18"
                        y2="18"
                    ></line>

                </svg>

            </a>

        </div>


        <?php if (isset($_SESSION['success_msg'])): ?>

            <div class="alert-success">

                <?php
                echo htmlspecialchars(
                    $_SESSION['success_msg']
                );

                unset($_SESSION['success_msg']);
                ?>

            </div>

        <?php endif; ?>


        <?php if (!empty($error_msg)): ?>

            <div class="alert-error">

                <?php
                echo htmlspecialchars($error_msg);
                ?>

            </div>

        <?php endif; ?>


        <form
            action="profile.php"
            method="POST"
            enctype="multipart/form-data"
        >

      
            <div class="form-group">

                <label>Full Name</label>

                <input
                    type="text"
                    name="full_name"
                    class="form-control"
                    value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>"
                    required
                >

            </div>


            <div class="form-group">

                <label>Email Address</label>

                <input
                    type="email"
                    name="email"
                    class="form-control"
                    value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>"
                    required
                >

            </div>


            <button
                type="submit"
                class="btn-save"
            >
                Save Changes
            </button>

        </form>

    </div>

</div>


<script>

    function applyTheme(theme) {

        if (theme === 'dark') {

            document.body.classList.add('dark-mode');

        } else {

            document.body.classList.remove('dark-mode');
        }
    }


    document.addEventListener(
        "DOMContentLoaded",
        () => {

            const savedTheme =
                localStorage.getItem('notesphere_theme') ||
                localStorage.getItem('theme') ||
                'dark';

            applyTheme(savedTheme);
        }
    );


    window.addEventListener(
        'storage',
        (event) => {

            if (
                event.key === 'notesphere_theme' ||
                event.key === 'theme'
            ) {

                if (event.newValue) {

                    applyTheme(event.newValue);
                }
            }
        }
    );

</script>
</body>

</html>