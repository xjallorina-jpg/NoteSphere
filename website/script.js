/* ==========================================================================
   NoteSphere public website
   ========================================================================== */

(() => {
    'use strict';

    const header = document.getElementById('siteHeader');
    const navToggle = document.getElementById('navToggle');
    const navLinks = document.getElementById('navLinks');
    const installButton = document.getElementById('installAppBtn');
    const installStatus = document.getElementById('installStatus');
    const installBtnLabel = document.getElementById('installBtnLabel');
    const themeToggle = document.getElementById('themeToggle');
    const themeToggleLabel = themeToggle?.querySelector('.theme-toggle-label');
    const themeColorMeta = document.querySelector('meta[name="theme-color"]');

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const isIOS =
        /iPad|iPhone|iPod/.test(window.navigator.userAgent || '') ||
        (window.navigator.platform === 'MacIntel' && window.navigator.maxTouchPoints > 1);
    const isStandalone =
        window.matchMedia('(display-mode: standalone)').matches ||
        window.matchMedia('(display-mode: fullscreen)').matches ||
        window.navigator.standalone === true;

    /* ----------------------------------------------------------------------
       Theme (dark by default, light mode available)
       ---------------------------------------------------------------------- */

    function applyTheme(theme, persist = false) {
        const isLight = theme === 'light';
        const nextTheme = isLight ? 'light' : 'dark';

        document.documentElement.setAttribute('data-theme', nextTheme);

        if (themeToggle) {
            const action = isLight ? 'Switch to dark mode' : 'Switch to light mode';
            themeToggle.setAttribute('aria-label', action);
            themeToggle.setAttribute('title', action);
        }

        if (themeToggleLabel) {
            themeToggleLabel.textContent = isLight ? 'Dark mode' : 'Light mode';
        }

        if (themeColorMeta) {
            themeColorMeta.setAttribute('content', isLight ? '#f4f7f2' : '#05070d');
        }

        if (persist) {
            try {
                localStorage.setItem('notesphere-theme', nextTheme);
            } catch (error) {
                // The theme still applies for this page even when storage is unavailable.
            }
        }
    }

    applyTheme(document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark');

    themeToggle?.addEventListener('click', () => {
        const currentTheme = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
        applyTheme(currentTheme === 'light' ? 'dark' : 'light', true);
    });

    /* ----------------------------------------------------------------------
       Header state
       ---------------------------------------------------------------------- */

    function updateHeader() {
        if (!header) {
            return;
        }

        header.classList.toggle('is-scrolled', window.scrollY > 8);
    }

    updateHeader();
    window.addEventListener('scroll', updateHeader, { passive: true });

    /* ----------------------------------------------------------------------
       Mobile navigation
       ---------------------------------------------------------------------- */

    function setNavOpen(isOpen) {
        if (!navToggle || !navLinks) {
            return;
        }

        navToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        navToggle.setAttribute('aria-label', isOpen ? 'Close navigation menu' : 'Open navigation menu');
        navLinks.classList.toggle('is-open', isOpen);
    }

    if (navToggle && navLinks) {
        navToggle.addEventListener('click', () => {
            setNavOpen(navToggle.getAttribute('aria-expanded') !== 'true');
        });

        navLinks.addEventListener('click', (event) => {
            if (event.target.closest('a')) {
                setNavOpen(false);
            }
        });

        document.addEventListener('click', (event) => {
            if (
                navToggle.getAttribute('aria-expanded') === 'true' &&
                !header.contains(event.target)
            ) {
                setNavOpen(false);
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                setNavOpen(false);
            }
        });

        window.addEventListener('resize', () => {
            if (window.innerWidth > 940) {
                setNavOpen(false);
            }
        });
    }

    /* ----------------------------------------------------------------------
       Scroll reveal
       ---------------------------------------------------------------------- */

    const revealItems = document.querySelectorAll('.reveal');

    if (prefersReducedMotion || !('IntersectionObserver' in window)) {
        revealItems.forEach((item) => item.classList.add('is-visible'));
    } else {
        const revealObserver = new IntersectionObserver(
            (entries, observer) => {
                entries.forEach((entry) => {
                    if (!entry.isIntersecting) {
                        return;
                    }

                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                });
            },
            {
                rootMargin: '0px 0px -8% 0px',
                threshold: 0.08
            }
        );

        revealItems.forEach((item) => revealObserver.observe(item));
    }

    /* ----------------------------------------------------------------------
       PWA installation
       ---------------------------------------------------------------------- */

    let deferredPrompt = null;

    function setInstallStatus(message, isReady = false) {
        if (!installStatus) {
            return;
        }

        installStatus.textContent = message;
        installStatus.classList.toggle('is-ready', isReady);
    }

    function setInstallButtonLabel(message) {
        if (installBtnLabel) {
            installBtnLabel.textContent = message;
        } else if (installButton) {
            installButton.textContent = message;
        }
    }

    function showIosInstallHelp() {
        setInstallStatus('In Safari, tap the Share button, scroll down, then choose Add to Home Screen.', true);

        const iosCard = document.getElementById('iosInstallCard');
        if (!iosCard) {
            return;
        }

        iosCard.classList.add('is-highlighted');
        iosCard.scrollIntoView({
            behavior: prefersReducedMotion ? 'auto' : 'smooth',
            block: 'center'
        });

        window.setTimeout(() => {
            iosCard.classList.remove('is-highlighted');
        }, 4500);
    }

    function updateInstalledState() {
        if (!installButton) {
            return;
        }

        if (isStandalone) {
            setInstallButtonLabel('NoteSphere is installed');
            installButton.disabled = true;
            setInstallStatus('You are already using the installed NoteSphere app.', true);
            return;
        }

        if (isIOS) {
            setInstallButtonLabel('Add to Home Screen');
            setInstallStatus('On iPhone or iPad, open this page in Safari, tap Share, then choose Add to Home Screen.');
        }
    }

    function fallbackInstallMessage() {
        if (isIOS) {
            setInstallStatus('In Safari, tap the Share button, scroll down, then choose Add to Home Screen.');
            return;
        }

        if (/Android/i.test(window.navigator.userAgent || '')) {
            setInstallStatus('In Chrome, open the browser menu, then choose Install app or Add to Home screen.');
            return;
        }

        setInstallStatus('Use your browser menu or the install icon in the address bar, then choose Install NoteSphere.');
    }

    updateInstalledState();

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredPrompt = event;

        if (isStandalone) {
            return;
        }

        setInstallStatus('NoteSphere is ready to install on this device.', true);
    });

    window.addEventListener('appinstalled', () => {
        deferredPrompt = null;

        if (installButton) {
            setInstallButtonLabel('NoteSphere is installed');
            installButton.disabled = true;
        }

        setInstallStatus('NoteSphere was installed successfully.', true);
    });

    if (installButton) {
        installButton.addEventListener('click', async () => {
            if (isStandalone) {
                setInstallStatus('NoteSphere is already installed on this device.', true);
                return;
            }

            if (isIOS) {
                showIosInstallHelp();
                return;
            }

            if (!deferredPrompt) {
                fallbackInstallMessage();
                document.getElementById('install-steps')?.scrollIntoView({
                    behavior: prefersReducedMotion ? 'auto' : 'smooth',
                    block: 'center'
                });
                return;
            }

            installButton.disabled = true;

            try {
                deferredPrompt.prompt();
                const choice = await deferredPrompt.userChoice;

                if (choice && choice.outcome === 'accepted') {
                    setInstallStatus('Installing NoteSphere…', true);
                } else {
                    setInstallStatus('Installation was dismissed. You can try again anytime.');
                }

                deferredPrompt = null;
            } catch (error) {
                console.error('NoteSphere install prompt failed:', error);
                fallbackInstallMessage();
            } finally {
                if (!isStandalone) {
                    installButton.disabled = false;
                }
            }
        });
    }

    /* ----------------------------------------------------------------------
       Service worker registration (enhances installability where supported)
       ---------------------------------------------------------------------- */

    if ('serviceWorker' in navigator && window.isSecureContext) {
        window.addEventListener('load', async () => {
            try {
                await navigator.serviceWorker.register('/NoteSphere/sw.js', {
                    scope: '/NoteSphere/'
                });
            } catch (error) {
                console.info('NoteSphere service worker was not registered:', error);
            }
        });
    }
})();
 
 