
const CACHE_NAME = 'notesphere-v2';

// Derive the base from where sw.js is served so ngrok/tunnel subpaths work too.
const BASE = self.location.pathname.replace(/sw\.js$/, '') || '/';

const STATIC_ASSETS = [
    BASE + 'assets/logo.png'
];

// Install service worker
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(STATIC_ASSETS))
            .catch((error) => {
                console.error('NoteSphere cache installation failed:', error);
            })
    );

    self.skipWaiting();
});

// Activate service worker and remove old caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((cacheNames) =>
                Promise.all(
                    cacheNames
                        .filter((name) => name !== CACHE_NAME)
                        .map((name) => caches.delete(name))
                )
            )
            .then(() => self.clients.claim())
    );
});

// Handle requests without caching private PHP pages
self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (
        request.method !== 'GET' ||
        url.origin !== self.location.origin
    ) {
        return;
    }

    // Never cache login, dashboards, messages, or other dynamic PHP pages.
    if (url.pathname.endsWith('.php')) {
        return;
    }

    // Cache-first for the explicitly cached static assets only.
    if (STATIC_ASSETS.includes(url.pathname) || url.pathname.endsWith('/assets/logo.png')) {
        event.respondWith(
            caches.match(request).then((cachedResponse) => {
                return cachedResponse || fetch(request);
            })
        );
    }
});

// Receive push notifications from the server
self.addEventListener('push', (event) => {
    let data = {};

    if (event.data) {
        try {
            data = event.data.json();
        } catch (error) {
            data = {
                body: event.data.text()
            };
        }
    }

    const title = data.title || 'NoteSphere';
    const options = {
        body: data.body || 'You have a new notification.',
        icon: BASE + 'assets/logo.png',
        badge: BASE + 'assets/logo.png',
        tag: data.tag || 'notesphere-notification',
        data: {
            url: data.url || BASE + 'auth/login.php'
        }
    };

    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

// Open the relevant NoteSphere page when a notification is clicked
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    event.waitUntil(
        (async () => {
            const targetUrl = new URL(
                event.notification.data?.url || BASE + 'auth/login.php',
                self.location.origin
            );

            // Only allow navigation within NoteSphere's origin and directory.
            if (
                targetUrl.origin !== self.location.origin ||
                !targetUrl.pathname.startsWith(BASE)
            ) {
                return;
            }

            const windows = await self.clients.matchAll({
                type: 'window',
                includeUncontrolled: true
            });

            for (const client of windows) {
                if ('focus' in client) {
                    await client.focus();

                    if ('navigate' in client) {
                        await client.navigate(targetUrl.href);
                    }

                    return;
                }
            }

            if (self.clients.openWindow) {
                await self.clients.openWindow(targetUrl.href);
            }
        })()
    );
});
