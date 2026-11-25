
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/db_config.php';

// Redirect authenticated users to their respective dashboards.
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? 'student';

    if ($role === 'admin') {
        header('Location: admin/admin_landing.php');
    } elseif ($role === 'professor') {
        header('Location: professor/professor_dashboard.php');
    } else {
        header('Location: dashboard/student_home.php');
    }

    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">

    <title>CDM Portal | NoteSphere</title>
    <meta name="description" content="NoteSphere LMS - A web-based academic note sharing, rating, messaging, and announcement platform for Colegio de Montalban.">
    <meta name="theme-color" content="#0f172a">
    <meta name="application-name" content="NoteSphere">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="NoteSphere">
    <meta name="apple-mobile-web-app-status-bar-style" content="black">
    <meta name="format-detection" content="telephone=no">

    <link rel="manifest" href="manifest.json">
    <link rel="icon" type="image/png" href="assets/logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/apple-touch-icon.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --ns-green: #39FF14;
            --ns-bg: #0f172a;
            --ns-panel: #0a0a0a;
            --ns-white: #ffffff;
            --ns-muted: #aaaaaa;
        }

        * {
            box-sizing: border-box;
        }

        html {
            width: 100%;
            min-height: 100%;
            -webkit-text-size-adjust: 100%;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #080808 url('assets/login.jpg') no-repeat center center / cover;
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--ns-white);
            margin: 0;
            padding: calc(20px + env(safe-area-inset-top, 0px))
                     calc(20px + env(safe-area-inset-right, 0px))
                     calc(20px + env(safe-area-inset-bottom, 0px))
                     calc(20px + env(safe-area-inset-left, 0px));
            overflow-y: auto;
            -webkit-tap-highlight-color: transparent;
            touch-action: manipulation;
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.30);
            pointer-events: none;
            z-index: 0;
        }

        .main-container {
            position: relative;
            z-index: 1;
            text-align: center;
            width: 100%;
            max-width: 450px;
            padding: 15px;
        }

        .cdmlogo-img {
            width: 110px;
            height: auto;
            max-width: 100%;
            object-fit: contain;
            margin-bottom: 5px;
        }

        h1 {
            font-size: clamp(2.5rem, 10vw, 3.5rem);
            font-weight: 800;
            line-height: 0.95;
            margin: 8px 0 20px;
            letter-spacing: -2px;
        }

        .subtitle {
            color: var(--ns-green);
            font-size: 10px;
            font-weight: 800;
            margin: 15px 0 40px;
            letter-spacing: 3px;
            text-transform: uppercase;
        }

        .button-group {
            display: flex;
            flex-direction: column;
            gap: 12px;
            width: 100%;
            max-width: 320px;
            margin: 0 auto;
        }

        .btn {
            padding: 16px 20px;
            text-decoration: none;
            border-radius: 16px;
            font-family: inherit;
            font-weight: 700;
            transition: background 0.2s, border-color 0.2s,
                        color 0.2s, transform 0.2s, opacity 0.2s;
            border: 2px solid transparent;
            display: block;
            width: 100%;
            text-align: center;
            font-size: 14px;
            cursor: pointer;
            -webkit-tap-highlight-color: transparent;
        }

        .btn:focus-visible,
        button:focus-visible {
            outline: 2px solid var(--ns-green);
            outline-offset: 4px;
        }

        .btn-login {
            background: rgba(10, 10, 10, 0.92);
            color: var(--ns-white);
            border: 1px solid rgba(57, 255, 20, 0.65);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.22);
        }

        .btn-login:hover {
            background: #151515;
            border-color: var(--ns-green);
            color: var(--ns-green);
            transform: translateY(-1px);
        }

        .btn-request {
            background: var(--ns-green);
            color: #000;
            box-shadow: 0 4px 15px rgba(57, 255, 20, 0.22);
        }

        .btn-request:hover {
            transform: translateY(-1px);
            opacity: 0.95;
        }

        .btn-guide {
            background: rgba(255, 255, 255, 0.08);
            color: var(--ns-white);
            border: 1px solid rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
        }

        .btn-guide:hover {
            background: rgba(255, 255, 255, 0.15);
            border-color: var(--ns-green);
            color: var(--ns-green);
            transform: translateY(-1px);
        }

        /* Modals */
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            width: 100%;
            height: 100%;
            height: 100dvh;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
            z-index: 10000;
            justify-content: center;
            align-items: center;
            padding: calc(20px + env(safe-area-inset-top, 0px))
                     calc(20px + env(safe-area-inset-right, 0px))
                     calc(20px + env(safe-area-inset-bottom, 0px))
                     calc(20px + env(safe-area-inset-left, 0px));
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: var(--ns-panel);
            padding: 30px 20px;
            border-radius: 20px;
            border: 1px solid #333;
            width: 100%;
            max-width: 450px;
            text-align: left;
            max-height: 85vh;
            max-height: 85dvh;
            overflow-y: auto;
            overscroll-behavior: contain;
            box-shadow: 0 15px 45px rgba(0, 0, 0, 0.45);
            animation: modalIn 0.22s ease-out;
        }

        .modal-content::-webkit-scrollbar {
            width: 6px;
        }

        .modal-content::-webkit-scrollbar-thumb {
            background: #333;
            border-radius: 4px;
        }

        .modal-content h2 {
            color: var(--ns-green);
            margin: 0 0 15px;
            font-size: 1.2rem;
            text-align: center;
        }

        .modal-content p {
            font-size: 0.9rem;
            color: #ccc;
            line-height: 1.6;
        }

        .guide-details {
            font-size: 0.85rem;
            color: #aaa;
            line-height: 1.65;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .guide-details strong {
            color: #fff;
        }

        .guide-details .highlight {
            color: var(--ns-green);
        }

        .close-btn {
            margin: 22px auto 0;
            padding: 10px 25px;
            background: transparent;
            border: 1px solid var(--ns-green);
            border-radius: 8px;
            font-family: inherit;
            font-weight: 700;
            cursor: pointer;
            color: var(--ns-green);
            transition: 0.2s;
            display: block;
            -webkit-tap-highlight-color: transparent;
        }

        .close-btn:hover {
            background: var(--ns-green);
            color: #000;
        }

        /* PWA install card */
        .pwa-floating-card {
            position: fixed;
            bottom: calc(20px + env(safe-area-inset-bottom, 0px));
            right: calc(20px + env(safe-area-inset-right, 0px));
            width: 300px;
            background: rgba(10, 10, 10, 0.96);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            padding: 15px;
            border-radius: 14px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.4);
            display: none;
            flex-direction: column;
            gap: 12px;
            z-index: 9999;
            border: 1px solid #333;
            animation: slideUp 0.3s ease-out;
        }

        .pwa-card-content {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .pwa-card-icon img {
            width: 40px;
            height: 40px;
            object-fit: contain;
            border-radius: 6px;
        }

        .pwa-card-text {
            min-width: 0;
        }

        .pwa-card-text h4 {
            margin: 0 0 4px;
            font-size: 14px;
            color: #fff;
        }

        .pwa-card-text p {
            margin: 0;
            font-size: 11px;
            color: #aaa;
            line-height: 1.5;
        }

        .pwa-card-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .btn-pwa-install,
        .btn-pwa-close {
            font-family: inherit;
            padding: 8px 13px;
            border-radius: 7px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s;
        }

        .btn-pwa-install {
            background: var(--ns-green);
            color: #000;
            border: 1px solid var(--ns-green);
        }

        .btn-pwa-install:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .btn-pwa-close {
            background: transparent;
            color: #aaa;
            border: 1px solid #444;
        }

        .btn-pwa-close:hover {
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
        }

        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @keyframes modalIn {
            from { transform: translateY(12px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @media (max-width: 768px) {
            .pwa-floating-card {
                top: calc(15px + env(safe-area-inset-top, 0px));
                left: calc(15px + env(safe-area-inset-left, 0px));
                right: calc(15px + env(safe-area-inset-right, 0px));
                bottom: auto;
                width: auto;
                max-width: 450px;
                margin: 0 auto;
                animation: slideDown 0.3s ease-out;
            }
        }

        @keyframes slideDown {
            from { transform: translateY(-15px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @media (max-height: 650px) {
            body {
                align-items: flex-start;
            }

            .main-container {
                margin: auto;
            }

            .subtitle {
                margin-bottom: 22px;
            }

            .cdmlogo-img {
                width: 80px;
            }

            h1 {
                margin-bottom: 12px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                transition-duration: 0.01ms !important;
                scroll-behavior: auto !important;
            }
        }
    </style>
</head>

<body>
    <a href="website/index.php" aria-label="Back to website" title="Back to website" style="position:fixed;top:calc(25px + env(safe-area-inset-top,0px));left:calc(25px + env(safe-area-inset-left,0px));display:flex;align-items:center;justify-content:center;width:42px;height:42px;border:1px solid rgba(255,255,255,.15);border-radius:50%;background:rgba(0,0,0,.4);backdrop-filter:blur(5px);color:#888;z-index:100;text-decoration:none;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
    </a>
    <main class="main-container">
        <img
            src="assets/logo.png"
            alt="NoteSphere Logo"
            class="cdmlogo-img"
            width="110"
        >

        <h1>NoteSphere</h1>

        <div class="subtitle">Colegio de Montalban</div>

        <div class="button-group">
            <a href="auth/login.php" class="btn btn-login" id="loginLink">
                Login to NoteSphere
            </a>

            <a href="auth/request_pass.php" id="requestPasswordLink" class="btn btn-request">
                Request Temporary Password
            </a>

        </div>
    </main>

    <!-- PWA Installation Card -->
    <aside
        id="pwaInstallCard"
        class="pwa-floating-card"
        aria-label="Install NoteSphere"
        aria-live="polite"
    >
        <div class="pwa-card-content">
            <div class="pwa-card-icon">
                <img src="assets/logo.png" alt="" width="40" height="40">
            </div>

            <div class="pwa-card-text">
                <h4>Install NoteSphere</h4>
                <p>Add NoteSphere to your home screen for quick and easy access.</p>
            </div>
        </div>

        <div class="pwa-card-actions">
            <button type="button" id="pwaInstallBtn" class="btn-pwa-install">
                Install
            </button>

            <button
                type="button"
                id="pwaCloseBtn"
                class="btn-pwa-close"
                aria-label="Dismiss install suggestion"
            >
                ✕
            </button>
        </div>
    </aside>

    <!-- PWA Installation Guide -->
    <div
        id="pwaGuideModal"
        class="modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="pwaGuideTitle"
        aria-hidden="true"
    >
        <div class="modal-content">
            <h2 id="pwaGuideTitle">How to Install NoteSphere</h2>

            <p>
                If your browser does not show the automatic installation option,
                follow the steps below.
            </p>

            <div class="guide-details">
                <div>
                    <strong>iPhone / iPad (Safari):</strong><br>
                    1. Open NoteSphere using <strong>Safari</strong>.<br>
                    2. Tap the <strong>Share</strong> button.<br>
                    3. Scroll down and tap <strong>Add to Home Screen</strong>.<br>
                    4. Tap <strong>Add</strong> to finish.
                </div>

                <div>
                    <strong>Android phones:</strong><br>
                    1. Open NoteSphere using Chrome.<br>
                    2. Tap the browser menu (⋮).<br>
                    3. Select <strong>Add to Home screen</strong> or
                    <strong>Install app</strong>, if available.
                </div>

                <div>
                    <strong>Windows / desktop:</strong><br>
                    1. Open NoteSphere in a supported browser.<br>
                    2. Look for the install icon near the address bar, or open
                    the browser menu.<br>
                    3. Select <strong>Install NoteSphere</strong>, if available.
                </div>

                <div>
                    <strong>Important:</strong><br>
                    Installation availability depends on the browser and website
                    configuration. A secure HTTPS connection is generally required
                    outside localhost.
                </div>
            </div>

            <button type="button" class="close-btn" id="closePwaGuideBtn">
                Got it!
            </button>
        </div>
    </div>


    <script>
    (() => {
        'use strict';

        // Derive the app base from the current URL so ngrok/tunnel subpaths work too.
        const BASE_PATH = window.location.pathname.replace(/portal\.php$/, '') || '/';
        const INSTALL_DISMISSED_KEY = 'notespherePwaInstallDismissed';
        const isIOS =
            /iPad|iPhone|iPod/.test(window.navigator.userAgent || '') ||
            (window.navigator.platform === 'MacIntel' && window.navigator.maxTouchPoints > 1);

        const installCard = document.getElementById('pwaInstallCard');
        const installBtn = document.getElementById('pwaInstallBtn');
        const installCloseBtn = document.getElementById('pwaCloseBtn');

        const pwaGuideModal = document.getElementById('pwaGuideModal');

        const closePwaGuideBtn = document.getElementById('closePwaGuideBtn');

        let deferredPrompt = null;
        let previouslyFocusedElement = null;

        // Determine whether NoteSphere is already installed as a PWA.
        function isAppInstalled() {
            return window.matchMedia('(display-mode: standalone)').matches ||
                window.matchMedia('(display-mode: fullscreen)').matches ||
                window.navigator.standalone === true;
        }

        function isInstallDismissed() {
            try {
                return sessionStorage.getItem(INSTALL_DISMISSED_KEY) === 'true';
            } catch (error) {
                return false;
            }
        }

        function showInstallCard() {
            if (isAppInstalled() || isInstallDismissed()) {
                installCard.style.display = 'none';
                return;
            }

            installCard.style.display = 'flex';
        }

        function hideInstallCard(rememberChoice = false) {
            installCard.style.display = 'none';

            if (rememberChoice) {
                try {
                    sessionStorage.setItem(INSTALL_DISMISSED_KEY, 'true');
                } catch (error) {
                    // The card can still be hidden when storage is unavailable.
                }
            }
        }

        function openModal(modal) {
            previouslyFocusedElement = document.activeElement;

            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';

            const firstButton = modal.querySelector('button');

            if (firstButton) {
                firstButton.focus();
            }
        }

        function closeModal(modal) {
            modal.classList.remove('show');
            modal.setAttribute('aria-hidden', 'true');

            if (
                !pwaGuideModal.classList.contains('show')
            ) {
                document.body.style.overflow = '';
            }

            if (previouslyFocusedElement &&
                typeof previouslyFocusedElement.focus === 'function') {
                previouslyFocusedElement.focus();
            }
        }

        function showPwaGuide() {
            openModal(pwaGuideModal);
        }

        function hidePwaGuide() {
            closeModal(pwaGuideModal);
            hideInstallCard(false);
        }

        // Keep these functions available to any existing inline event handlers.
        window.showPwaGuide = showPwaGuide;
        window.hidePwaGuide = hidePwaGuide;

        closePwaGuideBtn.addEventListener('click', hidePwaGuide);

        // Close a modal when the user clicks its backdrop.
        [pwaGuideModal].forEach((modal) => {
            modal.addEventListener('click', (event) => {
                if (event.target !== modal) {
                    return;
                }
                hidePwaGuide();
            });
        });

        // Allow Escape to close open modals.
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') {
                return;
            }

            if (pwaGuideModal.classList.contains('show')) {
                hidePwaGuide();
            }
        });

        // Browser-controlled PWA installation prompt (where supported).
        window.addEventListener('beforeinstallprompt', (event) => {
            event.preventDefault();
            deferredPrompt = event;
            showInstallCard();
        });

        window.addEventListener('appinstalled', () => {
            deferredPrompt = null;
            hideInstallCard(false);
        });

        installBtn.addEventListener('click', async () => {
            if (isAppInstalled()) {
                hideInstallCard(false);
                return;
            }

            if (!deferredPrompt) {
                showPwaGuide();
                return;
            }

            installBtn.disabled = true;

            try {
                deferredPrompt.prompt();

                const choice = await deferredPrompt.userChoice;

                if (choice && choice.outcome === 'accepted') {
                    hideInstallCard(false);
                }

                // The browser prompt can only be used once.
                deferredPrompt = null;

                if (!isAppInstalled() && !isInstallDismissed()) {
                    installBtn.disabled = false;
                }
            } catch (error) {
                console.error('NoteSphere installation prompt failed:', error);
                deferredPrompt = null;
                showPwaGuide();
            } finally {
                installBtn.disabled = false;
            }
        });

        installCloseBtn.addEventListener('click', () => {
            hideInstallCard(true);
        });

        // Hide the card when launched as an installed app.
        const displayMode = window.matchMedia('(display-mode: standalone)');

        if (typeof displayMode.addEventListener === 'function') {
            displayMode.addEventListener('change', () => {
                if (isAppInstalled()) {
                    hideInstallCard(false);
                }
            });
        }

        // Show a fallback installation suggestion on browsers that do not
        // dispatch beforeinstallprompt, unless already installed or dismissed.
        window.addEventListener('load', () => {
            if (isIOS && !isAppInstalled()) {
                const cardText = installCard.querySelector('.pwa-card-text p');

                if (cardText) {
                    cardText.textContent = 'In Safari, tap Share, then choose Add to Home Screen.';
                }

                installBtn.textContent = 'How to install';
            }

            if (!isAppInstalled() && !isInstallDismissed()) {
                window.setTimeout(showInstallCard, 1500);
            }
        });

        // Register the NoteSphere service worker.
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', async () => {
                try {
                    const registration = await navigator.serviceWorker.register(
                        BASE_PATH + 'sw.js',
                        { scope: BASE_PATH }
                    );

                    console.info(
                        'NoteSphere service worker registered successfully.',
                        registration.scope
                    );
                } catch (error) {
                    console.error(
                        'NoteSphere service worker registration failed:',
                        error
                    );
                }
            });
        }
    })();
    </script>
</body>
</html>
