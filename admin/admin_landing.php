
<?php
// =========================================================
// NoteSphere - Admin Landing Page
// =========================================================

$logoFile = dirname(__DIR__) . '/assets/cdmlogo.png';
$backgroundFile = dirname(__DIR__) . '/assets/bg.jpg';

$logoPath = '../assets/cdmlogo.png';
$backgroundPath = '../assets/bg.jpg';
$dashboardPath = 'admin_dashboard.php';

$logoExists = file_exists($logoFile);
$backgroundExists = file_exists($backgroundFile);

if ($backgroundExists) {
    $bodyStyle = "background-image: linear-gradient(115deg, rgba(12,25,42,.90), rgba(18,42,54,.70)), url('../assets/bg.jpg');";
} else {
    $bodyStyle = "background-image: radial-gradient(circle at 80% 25%, rgba(145,190,170,.18), transparent 30%), linear-gradient(135deg, #101e31, #1a3043 60%, #24444c);";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#152b3a">
    <title>Admin Portal | NoteSphere</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --navy: #152b3a;
            --navy-light: #213f4c;
            --green: #8fc7a6;
            --green-dark: #317653;
            --white: #ffffff;
            --text: #edf5f1;
            --muted: #c3d2d0;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--navy);
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
            color: var(--text);
        }

        .page {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .navbar {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding: 24px 7%;
            border-bottom: 1px solid rgba(255,255,255,.12);
            background: rgba(12, 27, 38, .24);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-mark {
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 13px;
            background: rgba(143,199,166,.16);
            border: 1px solid rgba(143,199,166,.35);
            color: var(--green);
            font-size: 23px;
            font-weight: 800;
        }

        .brand-name {
            font-size: 21px;
            font-weight: 800;
            letter-spacing: -.7px;
        }

        .brand-subtitle {
            margin-top: 3px;
            color: var(--muted);
            font-size: 10px;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .portal-label {
            padding: 10px 16px;
            border: 1px solid rgba(255,255,255,.18);
            border-radius: 30px;
            color: #e2f1e8;
            background: rgba(255,255,255,.06);
            font-size: 12px;
            font-weight: 700;
        }

        .main {
            flex: 1;
            width: 100%;
            max-width: 1400px;
            margin: auto;
            padding: 65px 7%;
            display: grid;
            grid-template-columns: 1.15fr .85fr;
            align-items: center;
            gap: 65px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            margin-bottom: 24px;
            color: var(--green);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 2.3px;
            text-transform: uppercase;
        }

        .eyebrow::before {
            content: "";
            width: 28px;
            height: 2px;
            background: var(--green);
        }

        h1 {
            max-width: 650px;
            margin-bottom: 24px;
            color: var(--white);
            font-size: clamp(38px, 5vw, 66px);
            font-weight: 800;
            line-height: 1.12;
            letter-spacing: -2.5px;
        }

        h1 span {
            color: var(--green);
        }

        .description {
            max-width: 560px;
            color: var(--muted);
            font-size: 15px;
            line-height: 1.9;
        }

        .features {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 27px;
        }

        .feature {
            padding: 9px 13px;
            border-radius: 8px;
            border: 1px solid rgba(255,255,255,.14);
            background: rgba(255,255,255,.06);
            color: #e0ebe6;
            font-size: 11px;
            font-weight: 600;
        }

        .logo-panel {
            width: 100%;
            max-width: 420px;
            justify-self: end;
            padding: 38px 32px;
            text-align: center;
          
            border-radius: 26px;
            
            color: var(--navy);
        }

        .logo-frame {
            width: 190px;
            height: 190px;
            margin: 0 auto 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logo-frame img {
            display: block;
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .logo-fallback {
            width: 160px;
            height: 160px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border: 3px solid #b8d5c2;
            border-radius: 50%;
            color: var(--green-dark);
        }

        .logo-fallback strong {
            font-size: 34px;
            letter-spacing: 2px;
        }

        .logo-fallback span {
            margin-top: 6px;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: 1px;
        }

        .school-name {
            margin-bottom: 9px;
            font-size: 17px;
            font-weight: 800;
        }

        .school-description {
            margin-bottom: 26px;
            color: #62756d;
            font-size: 12px;
            line-height: 1.7;
        }

        .dashboard-button {
            width: 100%;
            min-height: 51px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 14px 20px;
            border: none;
            border-radius: 12px;
            background: var(--green-dark);
            color: var(--white);
            text-decoration: none;
            font-family: inherit;
            font-size: 13px;
            font-weight: 800;
            transition: background .2s ease, transform .2s ease;
        }

        .dashboard-button:hover {
            background: #245e40;
            transform: translateY(-2px);
        }

        .dashboard-button:focus-visible {
            outline: 3px solid #8fc7a6;
            outline-offset: 4px;
        }

        .footer {
            padding: 20px 7%;
            border-top: 1px solid rgba(255,255,255,.12);
            color: #b7c8c5;
            text-align: center;
            font-size: 11px;
        }

        @media (max-width: 850px) {
            .main {
                grid-template-columns: 1fr;
                gap: 42px;
                padding-top: 50px;
                padding-bottom: 50px;
            }

            .logo-panel {
                justify-self: center;
            }

            h1 {
                letter-spacing: -1.5px;
            }
        }

        @media (max-width: 520px) {
            .navbar {
                padding: 18px 5%;
            }

            .brand-name {
                font-size: 18px;
            }

            .brand-subtitle {
                font-size: 8px;
                letter-spacing: 1.3px;
            }

            .portal-label {
                padding: 8px 10px;
                font-size: 10px;
            }

            .main {
                padding: 42px 6%;
            }

            h1 {
                font-size: 39px;
            }

            .description {
                font-size: 13px;
            }

            .logo-panel {
                padding: 30px 22px;
            }

            .logo-frame {
                width: 155px;
                height: 155px;
            }
        }
    </style>
</head>
<body style="<?= htmlspecialchars($bodyStyle, ENT_QUOTES, 'UTF-8') ?>">
<div class="page">
    <header class="navbar">
        <div class="brand">
            
            <div>
                <div class="brand-name">NoteSphere</div>
                <div class="brand-subtitle">Academic Sharing Platform</div>
            </div>
        </div>
        <div class="portal-label">Administrator Portal</div>
    </header>

    <main class="main">
        <section class="hero">
           
            <h1>Welcome to <span>NoteSphere</span></h1>
            <p class="description">
                Your centralized platform for academic notes, announcements,
                and learning resources. Manage and support the NoteSphere
                community through the administrator dashboard.
            </p>

            <div class="features">
                <span class="feature">Note Management</span>
                <span class="feature">Announcements</span>
                <span class="feature">User Administration</span>
            </div>
        </section>

        <section class="logo-panel">
            <div class="logo-frame">
                <?php if ($logoExists): ?>
                    <img
                        src="<?= htmlspecialchars($logoPath, ENT_QUOTES, 'UTF-8') ?>"
                        alt="Colegio de Montalban Logo"
                    >
                <?php else: ?>
                    <div class="logo-fallback">
                        <strong>CDM</strong>
                        <span>COLEGIO DE MONTALBAN</span>
                    </div>
                <?php endif; ?>
            </div>

         

            <a class="dashboard-button"
               href="<?= htmlspecialchars($dashboardPath, ENT_QUOTES, 'UTF-8') ?>">
                Open Admin Dashboard <span aria-hidden="true">→</span>
            </a>
        </section>
    </main>

    <footer class="footer">
        &copy; <?= date('Y') ?> NoteSphere | Colegio de Montalban
    </footer>
</div>
</body>
</html>
