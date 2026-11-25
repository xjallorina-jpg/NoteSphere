
<?php
// =========================================================
// NoteSphere - NoteHub Institute Selection
// =========================================================

session_start();

// =========================================================
// SECURITY CHECK
// =========================================================

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'student'
) {
    header("Location: ../auth/login.php");
    exit();
}

// =========================================================
// HELPER
// =========================================================

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// =========================================================
// INSTITUTES
// =========================================================

$institutes = [
    'ICS' => [
        'name' => 'Institute of Computing Studies',
        'short_name' => 'Computing Studies',
        'description' => 'Explore programming, computing, and information technology learning materials.',
        'transition_description' => 'Preparing programming, computing, and IT learning materials.',
        'icon' => 'code',
    ],
    'IBE' => [
        'name' => 'Institute of Business Education',
        'short_name' => 'Business Education',
        'description' => 'Discover business, management, and entrepreneurship learning resources.',
        'transition_description' => 'Preparing business, management, and entrepreneurship resources.',
        'icon' => 'business',
    ],
    'ITE' => [
        'name' => 'Institute of Technical Education',
        'short_name' => 'Technical Education',
        'description' => 'Browse technical subjects, practical lessons, and educational materials.',
        'transition_description' => 'Preparing technical subjects, practical lessons, and study materials.',
        'icon' => 'book',
    ],
];

// =========================================================
// INSTITUTE PARAMETER VALIDATION
// =========================================================

$selectedInstitute = strtoupper(trim($_GET['institute'] ?? ''));

if (
    $selectedInstitute !== '' &&
    !array_key_exists($selectedInstitute, $institutes)
) {
    http_response_code(400);
    exit('Invalid institute selection.');
}
?>
<!DOCTYPE html>
<html lang="en" class="dark-mode">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0b0b0b" id="themeColor">

    <title>Choose Institute | NoteSphere</title>

    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <script>
    (function () {
        try {
            const savedTheme = localStorage.getItem('notesphere_theme');
            const isLight = savedTheme === 'light';

            document.documentElement.classList.toggle('light-mode', isLight);
            document.documentElement.classList.toggle('dark-mode', !isLight);
        } catch (error) {
            document.documentElement.classList.add('dark-mode');
        }
    })();
    </script>

    <style>
    /* =====================================================
       VARIABLES
    ====================================================== */

    :root {
        --neon: #39FF14;
        --neon-glow: rgba(57, 255, 20, 0.12);

        --text: #f8fafc;
        --sub-text: #94a3b8;

        --border: rgba(148, 163, 184, 0.18);
        --card-bg: rgba(12, 12, 12, 0.88);

        --close-bg: rgba(12, 12, 12, 0.88);
        --close-text: #f8fafc;

        --section-bg: rgba(15, 15, 15, 0.45);
        --card-shadow: 0 15px 40px rgba(0, 0, 0, 0.20);
    }

    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }

    html {
        min-height: 100%;
        scroll-behavior: smooth;
    }

    body {
        min-height: 100vh;
        font-family: 'Plus Jakarta Sans', sans-serif;
        color: var(--text);
        background: transparent;
        overflow-x: hidden;
        transition: color 0.3s ease, background-color 0.3s ease;
    }

    /* =====================================================
       DARK MODE
    ====================================================== */

    html.dark-mode,
    body.dark-mode {
        color-scheme: dark;

        --neon: #39FF14;
        --neon-glow: rgba(57, 255, 20, 0.12);

        --text: #eff3f8;
        --sub-text: #b8c2d0;

        --border: rgba(148, 163, 184, 0.18);
        --card-bg: rgba(12, 12, 12, 0.88);

        --close-bg: rgba(12, 12, 12, 0.88);
        --close-text: #f8fafc;

        --section-bg: rgba(15, 15, 15, 0.45);
        --card-shadow: 0 15px 40px rgba(0, 0, 0, 0.20);
    }

    html.dark-mode {
        background-color: #0b0b0b;
    }

    html.dark-mode body::before,
    body.dark-mode::before {
        background:
            linear-gradient(
                rgba(11, 11, 11, 0.84),
                rgba(11, 11, 11, 0.91)
            ),
            none no-repeat center center;
        background-size: cover;
    }

    /* =====================================================
       LIGHT MODE
    ====================================================== */

    html.light-mode,
    body.light-mode {
        color-scheme: light;

        --neon: #087f3e;
        --neon-glow: rgb(0, 0, 0);

        --text: #000000;
        --sub-text: #000000;

        --border: rgba(15, 23, 42, 0.13);
        --card-bg: rgba(255, 255, 255, 0.93);

        --close-bg: rgba(255, 255, 255, 0.94);
        --close-text: #000000;

        --section-bg: rgba(255, 255, 255, 0.72);
        --card-shadow: 0 15px 40px rgb(0, 0, 0);
    }

    html.light-mode {
        background-color: #ffffff;
    }

    html.light-mode body::before,
    body.light-mode::before {
        background: none no-repeat center center;
        background-size: cover;
    }

    /* =====================================================
       BACKGROUND
    ====================================================== */

    body::before {
        content: "";
        position: fixed;
        inset: 0;
        z-index: -1;
        pointer-events: none;
    }

    a {
        color: inherit;
    }

    /* =====================================================
       CLOSE BUTTON
    ====================================================== */

    .close-button {
        position: fixed;
        top: 22px;
        right: 25px;
        z-index: 1000;

        width: 44px;
        height: 44px;

        display: flex;
        align-items: center;
        justify-content: center;

        background: var(--close-bg);
        color: var(--close-text);

        border: 1px solid var(--border);
        border-radius: 12px;

        font-family: Arial, sans-serif;
        font-size: 30px;
        line-height: 1;

        text-decoration: none;
        cursor: pointer;

        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);

        transition:
            color 0.25s ease,
            background 0.25s ease,
            border-color 0.25s ease,
            transform 0.25s ease;
    }

    .close-button:hover {
        color: var(--neon);
        border-color: var(--neon);
        background: var(--neon-glow);
        transform: scale(1.05);
    }

    .close-button:focus-visible,
    .institute-card:focus-visible {
        outline: 2px solid var(--neon);
        outline-offset: 4px;
    }

    /* =====================================================
       MAIN CONTENT
    ====================================================== */

    .main-content {
        width: min(1120px, calc(100% - 48px));
        margin: 0 auto;
        padding: 90px 0 38px;
    }

    /* =====================================================
       HERO
    ====================================================== */

    .hero {
        max-width: 760px;
        margin: 0 auto 48px;
        text-align: center;
    }

    .eyebrow {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 10px;

        margin-bottom: 22px;
        padding: 9px 15px;

        color: var(--neon);
        background: var(--neon-glow);
        border: 1px solid var(--border);
        border-radius: 999px;

        font-size: 12px;
        font-weight: 800;
        letter-spacing: 1.2px;
        text-transform: uppercase;
    }

    .eyebrow-dot {
        width: 8px;
        height: 8px;
        flex-shrink: 0;
        border-radius: 50%;
        background: var(--neon);
        box-shadow: 0 0 12px var(--neon-glow);
    }

    .hero h1 {
        color: var(--text);
        font-size: clamp(38px, 6vw, 64px);
        font-weight: 800;
        letter-spacing: -2.8px;
        line-height: 1.12;
        margin-bottom: 22px;
    }

    .hero h1 span {
        color: var(--neon);
    }

    .hero > p {
        max-width: 620px;
        margin: 0 auto;
        color: var(--sub-text);
        font-size: 15px;
        line-height: 1.9;
    }

    /* =====================================================
       SECTION HEADING
    ====================================================== */

    .institute-section {
        width: 100%;
    }

    .section-heading {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 20px;
        margin-bottom: 24px;
    }

    .section-heading h2 {
        color: var(--text);
        font-size: clamp(21px, 3vw, 28px);
        font-weight: 800;
        letter-spacing: -0.8px;
        margin-bottom: 8px;
    }

    .section-heading p {
        color: var(--sub-text);
        font-size: 13px;
        line-height: 1.7;
    }

    /* =====================================================
       INSTITUTE GRID
    ====================================================== */

    .institute-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 22px;
    }

    /* =====================================================
       INSTITUTE CARDS
    ====================================================== */

    .institute-card {
        position: relative;
        display: flex;
        flex-direction: column;
        min-width: 0;
        min-height: 300px;

        padding: 27px 24px 23px;

        color: var(--text);
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: 20px;

        text-decoration: none;
        box-shadow: var(--card-shadow);

        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);

        overflow: hidden;
        cursor: pointer;
        -webkit-tap-highlight-color: transparent;

        transition:
            transform 0.28s ease,
            border-color 0.28s ease,
            box-shadow 0.28s ease,
            background-color 0.28s ease;
    }

    .institute-card::before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;

        background: var(--neon);
        opacity: 0;
        transform: scaleX(0.45);
        transform-origin: center;

        transition: opacity 0.28s ease, transform 0.28s ease;
    }

    .institute-card:hover {
        transform: translateY(-7px);
        border-color: var(--neon);
        box-shadow:
            0 18px 45px rgba(0, 0, 0, 0.12),
            0 0 22px var(--neon-glow);
    }

    .institute-card:hover::before {
        opacity: 1;
        transform: scaleX(1);
    }

    .institute-card:active {
        transform: scale(0.98);
    }

    .institute-card.is-opening {
        pointer-events: none;
        border-color: var(--neon);
        box-shadow: 0 0 30px var(--neon-glow);
        transform: scale(0.97);
    }

    .institute-card.is-opening::before {
        opacity: 1;
        transform: scaleX(1);
    }

    .institute-icon {
        display: flex;
        align-items: center;
        justify-content: center;

        width: 54px;
        height: 54px;
        margin-bottom: 23px;

        color: var(--neon);
        background: var(--neon-glow);
        border: 1px solid var(--border);
        border-radius: 15px;

        transition:
            color 0.28s ease,
            background 0.28s ease,
            transform 0.28s ease,
            box-shadow 0.28s ease;
    }

    .institute-card:hover .institute-icon {
        transform: translateY(-2px);
    }

    .institute-card.is-opening .institute-icon {
        box-shadow: 0 0 25px var(--neon-glow);
    }

    .institute-icon svg {
        width: 27px;
        height: 27px;
        fill: none;
        stroke: currentColor;
        stroke-width: 1.8;
        stroke-linecap: round;
        stroke-linejoin: round;
    }

    .institute-code {
        color: var(--neon);
        font-size: 12px;
        font-weight: 800;
        letter-spacing: 2px;
        margin-bottom: 9px;
    }

    .institute-name {
        color: var(--text);
        font-size: 17px;
        font-weight: 800;
        line-height: 1.55;
        margin-bottom: 12px;
    }

    .institute-description {
        color: var(--sub-text);
        font-size: 12.5px;
        line-height: 1.85;
        margin-bottom: 24px;
    }

    .card-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;

        margin-top: auto;
        padding-top: 16px;

        border-top: 1px solid var(--border);
    }

    .explore-label {
        color: var(--text);
        font-size: 12px;
        font-weight: 800;
        transition: color 0.25s ease;
    }

    .arrow {
        display: flex;
        align-items: center;
        justify-content: center;

        width: 31px;
        height: 31px;
        flex-shrink: 0;

        color: var(--neon);
        background: var(--neon-glow);
        border-radius: 10px;

        font-size: 19px;
        line-height: 1;

        transition: transform 0.25s ease, background 0.25s ease, color 0.25s ease;
    }

    .institute-card:hover .explore-label {
        color: var(--neon);
    }

    .institute-card:hover .arrow {
        transform: translateX(3px);
    }

    /* =====================================================
       BOTTOM NOTE
    ====================================================== */

    .bottom-note {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;

        max-width: 780px;
        margin: 30px auto 0;
        padding: 16px 20px;

        color: var(--sub-text);
        background: var(--section-bg);
        border: 1px solid var(--border);
        border-radius: 14px;

        font-size: 11.5px;
        line-height: 1.8;
        text-align: center;
    }

    .bottom-note svg {
        width: 19px;
        height: 19px;
        flex-shrink: 0;

        fill: none;
        stroke: var(--neon);
        stroke-width: 1.7;
        stroke-linecap: round;
        stroke-linejoin: round;
    }

    /* =====================================================
       CINEMATIC TRANSITION SCREEN
    ====================================================== */

    .transition-screen {
        position: fixed;
        inset: 0;
        z-index: 2000;

        display: flex;
        align-items: center;
        justify-content: center;

        width: 100%;
        min-height: 100vh;
        min-height: 100dvh;
        padding: 24px;

        background: rgba(5, 7, 5, 0.96);
        backdrop-filter: blur(18px);
        -webkit-backdrop-filter: blur(18px);

        opacity: 0;
        visibility: hidden;
        pointer-events: none;

        transition: opacity 0.35s ease, visibility 0.35s ease;
    }

    .transition-screen.active {
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
    }

    .transition-content {
        width: min(100%, 360px);
        text-align: center;

        opacity: 0;
        transform: translateY(18px) scale(0.97);

        transition: opacity 0.4s ease, transform 0.4s ease;
    }

    .transition-screen.active .transition-content {
        opacity: 1;
        transform: translateY(0) scale(1);
    }

    .transition-logo {
        display: flex;
        align-items: center;
        justify-content: center;

        width: 82px;
        height: 82px;
        margin: 0 auto 25px;

        color: #39FF14;
        background: rgba(57, 255, 20, 0.09);
        border: 1px solid rgba(57, 255, 20, 0.45);
        border-radius: 24px;

        box-shadow: 0 0 40px rgba(57, 255, 20, 0.12);
        animation: transitionPulse 1.6s ease-in-out infinite;
    }

    .transition-logo svg {
        width: 39px;
        height: 39px;
        fill: none;
        stroke: currentColor;
        stroke-width: 1.8;
        stroke-linecap: round;
        stroke-linejoin: round;
    }

    .transition-brand {
        color: #f8fafc;
        font-size: 12px;
        font-weight: 800;
        letter-spacing: 4px;
        margin-bottom: 16px;
    }

    .transition-title {
        color: #f8fafc;
        font-size: clamp(23px, 5vw, 30px);
        font-weight: 800;
        line-height: 1.35;
        overflow-wrap: anywhere;
        margin-bottom: 10px;
    }

    .transition-description {
        color: #a1a1aa;
        font-size: 13px;
        line-height: 1.8;
        margin-bottom: 28px;
    }

    .transition-loader {
        width: 100%;
        height: 4px;
        overflow: hidden;
        background: rgba(255, 255, 255, 0.12);
        border-radius: 999px;
    }

    .transition-loader span {
        display: block;
        width: 38%;
        height: 100%;
        background: #39FF14;
        border-radius: inherit;
        box-shadow: 0 0 14px rgba(57, 255, 20, 0.6);
        animation: transitionLoading 1.1s ease-in-out infinite;
    }

    .transition-status {
        color: #a1a1aa;
        font-size: 11px;
        letter-spacing: 1.5px;
        text-transform: uppercase;
        margin-top: 16px;
    }

    @keyframes transitionPulse {
        0%, 100% {
            transform: scale(1);
            box-shadow: 0 0 25px rgba(57, 255, 20, 0.10);
        }
        50% {
            transform: scale(1.05);
            box-shadow: 0 0 40px rgba(57, 255, 20, 0.22);
        }
    }

    @keyframes transitionLoading {
        0% {
            transform: translateX(-110%);
        }
        100% {
            transform: translateX(270%);
        }
    }

    /* =====================================================
       TABLET
    ====================================================== */

    @media (max-width: 900px) {
        .main-content {
            width: min(720px, calc(100% - 40px));
            padding-top: 90px;
        }

        .institute-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .institute-card {
            min-height: 285px;
        }

        .hero {
            margin-bottom: 40px;
        }
    }

    /* =====================================================
       MOBILE
    ====================================================== */

    @media (max-width: 600px) {
        .close-button {
            top: 14px;
            right: 14px;
            width: 40px;
            height: 40px;
            border-radius: 11px;
        }

        .main-content {
            width: calc(100% - 32px);
            padding-top: 85px;
            padding-bottom: 24px;
        }

        .hero {
            margin-bottom: 34px;
        }

        .eyebrow {
            margin-bottom: 17px;
            font-size: 10px;
            letter-spacing: 0.9px;
        }

        .hero h1 {
            font-size: clamp(36px, 10vw, 48px);
            letter-spacing: -1.8px;
            margin-bottom: 17px;
        }

        .hero > p {
            font-size: 13px;
            line-height: 1.8;
        }

        .section-heading {
            margin-bottom: 18px;
        }

        .section-heading h2 {
            font-size: 22px;
        }

        .section-heading p {
            font-size: 12px;
        }

        .institute-grid {
            grid-template-columns: minmax(0, 1fr);
            gap: 15px;
        }

        .institute-card {
            min-height: auto;
            padding: 22px 20px 19px;
            border-radius: 17px;
        }

        .institute-icon {
            width: 48px;
            height: 48px;
            margin-bottom: 17px;
        }

        .institute-name {
            font-size: 16px;
        }

        .institute-description {
            margin-bottom: 20px;
        }

        .bottom-note {
            align-items: flex-start;
            padding: 14px;
            margin-top: 22px;
            font-size: 10.5px;
            text-align: left;
        }

        .transition-screen {
            padding: 20px;
        }
    }

    /* =====================================================
       REDUCED MOTION
    ====================================================== */

    @media (prefers-reduced-motion: reduce) {
        *,
        *::before,
        *::after {
            scroll-behavior: auto !important;
            transition-duration: 0.01ms !important;
            animation-duration: 0.01ms !important;
            animation-iteration-count: 1 !important;
        }
    }
            /* iOS: keep form fields at 16px so Safari does not zoom on focus. */
        @supports (-webkit-touch-callout: none) {
            input,
            select,
            textarea {
                font-size: 16px;
            }
        }
</style>
</head>

<body>

    <script>
    (function () {
        try {
            const savedTheme = localStorage.getItem('notesphere_theme');
            const isLight = savedTheme === 'light';

            document.body.classList.toggle('light-mode', isLight);
            document.body.classList.toggle('dark-mode', !isLight);

            const themeColor = document.getElementById('themeColor');

            if (themeColor) {
                themeColor.setAttribute(
                    'content',
                    isLight ? '#ffffff' : '#0b0b0b'
                );
            }
        } catch (error) {
            document.body.classList.add('dark-mode');
        }
    })();
    </script>

    <!-- =====================================================
         BACK TO STUDENT DASHBOARD
    ====================================================== -->

    <a
        class="close-button"
        href="student_home.php"
        aria-label="Back to Student Dashboard"
        title="Back to Dashboard"
    >&times;</a>

    <!-- =====================================================
         MAIN CONTENT
    ====================================================== -->

    <main class="main-content">

        <section class="hero">
            <div class="eyebrow">
                <span class="eyebrow-dot"></span>
                Your Academic Library
            </div>

            <h1>
                Find Notes.<br>
                Learn <span>Better.</span>
            </h1>

            <p>
                Welcome to NoteHub, your gateway to shared academic
                notes and learning materials. Choose your institute
                to get started.
            </p>
        </section>

        <section class="institute-section" aria-labelledby="instituteHeading">

            <div class="section-heading">
                <div>
                    <h2 id="instituteHeading">Choose Your Institute</h2>
                    <p>Select an institute to explore its available notes.</p>
                </div>
            </div>

            <div class="institute-grid">

                <!-- ICS -->
                <a
                    class="institute-card"
                   href="view_notes_institutes.php?institute=ICS"
                    data-institute="ICS"
                    aria-label="Explore notes for Institute of Computing Studies"
                >
                    <div class="institute-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <polyline points="8 17 3 12 8 7"></polyline>
                            <polyline points="16 7 21 12 16 17"></polyline>
                            <line x1="14" y1="4" x2="10" y2="20"></line>
                        </svg>
                    </div>

                    <div class="institute-code">ICS</div>

                    <div class="institute-name">
                        Institute of Computing Studies
                    </div>

                    <p class="institute-description">
                        Explore programming, computing, and information
                        technology learning materials.
                    </p>

                    <div class="card-footer">
                        <span class="explore-label">Explore Notes</span>
                        <span class="arrow" aria-hidden="true">→</span>
                    </div>
                </a>

                <!-- IBE -->
                <a
                    class="institute-card"
                   href="view_notes_institutes.php?institute=IBE"
                    data-institute="IBE"
                    aria-label="Explore notes for Institute of Business Education"
                >
                    <div class="institute-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <rect x="3" y="7" width="18" height="13" rx="2"></rect>
                            <path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                            <path d="M3 12h18"></path>
                            <path d="M10 12v2h4v-2"></path>
                        </svg>
                    </div>

                    <div class="institute-code">IBE</div>

                    <div class="institute-name">
                        Institute of Business Education
                    </div>

                    <p class="institute-description">
                        Discover business, management, and entrepreneurship
                        learning resources.
                    </p>

                    <div class="card-footer">
                        <span class="explore-label">Explore Notes</span>
                        <span class="arrow" aria-hidden="true">→</span>
                    </div>
                </a>

                <!-- ITE -->
                <a
                    class="institute-card"
                  href="view_notes_institutes.php?institute=ITE"
                    data-institute="ITE"
                    aria-label="Explore notes for Institute of Technical Education"
                >
                    <div class="institute-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                            <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                            <path d="M8 7h8"></path>
                            <path d="M8 11h8"></path>
                        </svg>
                    </div>

                    <div class="institute-code">ITE</div>

                    <div class="institute-name">
                        Institute of Technical Education
                    </div>

                    <p class="institute-description">
                        Browse technical subjects, practical lessons,
                        and educational materials.
                    </p>

                    <div class="card-footer">
                        <span class="explore-label">Explore Notes</span>
                        <span class="arrow" aria-hidden="true">→</span>
                    </div>
                </a>

            </div>
        </section>

        <div class="bottom-note">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11z"></path>
                <polyline points="9 12 11 14 15 10"></polyline>
            </svg>

            <span>
                Choose your institute to access the corresponding academic materials.
            </span>
        </div>

    </main>

    <!-- =====================================================
         CINEMATIC LOADING SCREEN
    ====================================================== -->

    <div
        class="transition-screen"
        id="instituteTransition"
        role="status"
        aria-live="polite"
        aria-hidden="true"
    >
        <div class="transition-content">

            <div class="transition-logo" id="transitionIcon">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <polyline points="8 17 3 12 8 7"></polyline>
                    <polyline points="16 7 21 12 16 17"></polyline>
                    <line x1="14" y1="4" x2="10" y2="20"></line>
                </svg>
            </div>

            <div class="transition-brand">NOTESPHERE</div>

            <h2 class="transition-title" id="transitionTitle">
                Preparing your institute
            </h2>

            <p class="transition-description" id="transitionDescription">
                Getting your academic learning materials ready.
            </p>

            <div class="transition-loader" aria-hidden="true">
                <span></span>
            </div>

            <p class="transition-status">Entering Institute</p>

        </div>
    </div>

    <!-- =====================================================
         INSTITUTE CARD TRANSITION
    ====================================================== -->

  
<script>
(function () {
    'use strict';

    const transition = document.getElementById('instituteTransition');
    const title = document.getElementById('transitionTitle');
    const description = document.getElementById('transitionDescription');
    const iconContainer = document.getElementById('transitionIcon');
    const cards = document.querySelectorAll('.institute-card');

    if (!transition || !title || !description || !iconContainer || !cards.length) {
        return;
    }

    const instituteDetails = {
        ICS: {
            title: 'Computing Studies',
            description: 'Preparing programming, computing, and IT learning materials.',
            icon: `
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <polyline points="8 17 3 12 8 7"></polyline>
                    <polyline points="16 7 21 12 16 17"></polyline>
                    <line x1="14" y1="4" x2="10" y2="20"></line>
                </svg>
            `
        },

        IBE: {
            title: 'Business Education',
            description: 'Preparing business, management, and entrepreneurship resources.',
            icon: `
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <rect x="3" y="7" width="18" height="13" rx="2"></rect>
                    <path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                    <path d="M3 12h18"></path>
                    <path d="M10 12v2h4v-2"></path>
                </svg>
            `
        },

        ITE: {
            title: 'Technical Education',
            description: 'Preparing technical subjects, practical lessons, and study materials.',
            icon: `
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                    <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                    <path d="M8 7h8"></path>
                    <path d="M8 11h8"></path>
                </svg>
            `
        }
    };

    let isOpening = false;

    cards.forEach(function (card) {
        card.addEventListener('click', function (event) {

            // Allow opening the link in a new tab or window.
            if (
                event.button !== 0 ||
                event.ctrlKey ||
                event.metaKey ||
                event.shiftKey ||
                event.altKey
            ) {
                return;
            }

            // Prevent accidental double-click navigation.
            if (isOpening) {
                event.preventDefault();
                return;
            }

            const destination = new URL(card.href, window.location.href);
            const institute = destination.searchParams.get('institute');
            const details = instituteDetails[institute];

            // If the institute is invalid, let normal link navigation handle it.
            if (!details) {
                return;
            }

            event.preventDefault();
            isOpening = true;

            card.classList.add('is-opening');

            title.textContent = details.title;
            description.textContent = details.description;
            iconContainer.innerHTML = details.icon;

            transition.classList.add('active');
            transition.setAttribute('aria-hidden', 'false');

            document.documentElement.style.overflow = 'hidden';

            // Navigate directly to view_notes.php after the short animation.
            window.setTimeout(function () {
                window.location.assign(destination.href);
            }, 350);
        });
    });
})();
</script>

</body>
</html>
