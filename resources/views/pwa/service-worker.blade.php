const CACHE_PREFIX = "digikash-pwa";
const CACHE_VERSION = @json($cacheVersion);
const STATIC_CACHE = `${CACHE_PREFIX}-static-${CACHE_VERSION}`;
const RUNTIME_CACHE = `${CACHE_PREFIX}-runtime-${CACHE_VERSION}`;
const OFFLINE_URL = {!! json_encode($offlineUrl, JSON_UNESCAPED_SLASHES) !!};
const PRECACHE_URLS = {!! json_encode($precacheUrls, JSON_UNESCAPED_SLASHES) !!};
const SAFE_STATIC_PATH_PREFIXES = {!! json_encode($staticPathPrefixes, JSON_UNESCAPED_SLASHES) !!};
const SENSITIVE_PATH_PREFIXES = {!! json_encode($sensitivePrefixes, JSON_UNESCAPED_SLASHES) !!};
const NAVIGATION_SCOPE = {!! json_encode($navigationScope ?? '/user/', JSON_UNESCAPED_SLASHES) !!};
const NOTIFICATION_FALLBACK_TITLE = {!! json_encode($notificationTitle ?? 'DigiKash', JSON_UNESCAPED_SLASHES) !!};
const NOTIFICATION_ICON = {!! json_encode($notificationIcon ?? '', JSON_UNESCAPED_SLASHES) !!};
const NOTIFICATION_BADGE = {!! json_encode($notificationBadge ?? '', JSON_UNESCAPED_SLASHES) !!};
const EXPECTED_CACHES = [STATIC_CACHE, RUNTIME_CACHE];

self.addEventListener("install", function (event) {
    event.waitUntil((async function () {
        const cache = await caches.open(STATIC_CACHE);
        await Promise.all(PRECACHE_URLS.map(function (url) {
            return cache.add(url).catch(function () {});
        }));
        await self.skipWaiting();
    })());
});

self.addEventListener("activate", function (event) {
    event.waitUntil((async function () {
        const cacheNames = await caches.keys();

        await Promise.all(cacheNames.map(function (cacheName) {
            if (cacheName.startsWith(CACHE_PREFIX) && !EXPECTED_CACHES.includes(cacheName)) {
                return caches.delete(cacheName);
            }

            return Promise.resolve();
        }));

        // Navigation preload was deliberately not enabled. The browser would
        // otherwise start the navigation fetch BEFORE our fetch handler runs,
        // which races badly with auth-changing redirects (admin → "Login as
        // User" → /user/dashboard) — the preload sends cookies from the
        // original tab while the redirect's Set-Cookie is still being applied.

        await self.clients.claim();
    })());
});

self.addEventListener("message", function (event) {
    if (event.data && event.data.type === "SKIP_WAITING") {
        self.skipWaiting();
    }
});

self.addEventListener("fetch", function (event) {
    const request = event.request;

    if (request.method !== "GET") {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (isSensitiveRequest(url, request)) {
        return;
    }

    if (request.mode === "navigate") {
        // When the navigation was triggered FROM /admin (e.g. the admin
        // "Login as User" flow redirects /admin/user/login/{id} →
        // /user/dashboard), let the browser handle it natively. The SW
        // interfering on the redirect target while a Set-Cookie was just
        // applied caused the impersonation session to land on the wrong
        // user's dashboard. Sensitive prefixes already cover the /admin URL
        // itself; this Referer check covers the post-redirect landing.
        const referer = request.referrer || "";
        if (referer.indexOf(self.location.origin + "/admin") === 0) {
            return;
        }

        // Respond to ALL same-origin, non-sensitive navigations. Chrome's
        // PWA installability check probes start_url through the SW and
        // requires event.respondWith() to be invoked.
        event.respondWith(networkFirstNavigation(event));

        return;
    }

    if (isSafeStaticAsset(url)) {
        event.respondWith(staleWhileRevalidate(request));
    }
});

async function networkFirstNavigation(event) {
    const request = event.request;

    try {
        return await fetch(request);
    } catch (error) {
        const cachedOfflinePage = await caches.match(OFFLINE_URL);

        return cachedOfflinePage || new Response("", { status: 504, statusText: "Offline" });
    }
}

async function staleWhileRevalidate(request) {
    const cache = await caches.open(STATIC_CACHE);
    const cachedResponse = await cache.match(request);
    const networkPromise = fetch(request)
        .then(function (response) {
            if (isCacheableResponse(response)) {
                cache.put(request, response.clone()).catch(function () {});
            }

            return response;
        })
        .catch(function () {
            return cachedResponse;
        });

    return cachedResponse || networkPromise;
}

function isSafeStaticAsset(url) {
    return SAFE_STATIC_PATH_PREFIXES.some(function (prefix) {
        return url.pathname.startsWith(prefix);
    });
}

function isSensitiveRequest(url, request) {
    const acceptsJson = (request.headers.get("accept") || "").includes("application/json");

    if (acceptsJson) {
        return true;
    }

    return SENSITIVE_PATH_PREFIXES.some(function (prefix) {
        return url.pathname === prefix || url.pathname.startsWith(`${prefix}/`);
    });
}

function isCacheableResponse(response) {
    return response && response.status === 200 && (response.type === "basic" || response.type === "default");
}

/* =============================================================
 | Web Push
 |
 | These two handlers are what make a notification reachable when
 | the app is closed: `push` wakes the worker with no page open,
 | and `notificationclick` focuses an existing client instead of
 | stacking up duplicate tabs.
 ============================================================= */

self.addEventListener("push", function (event) {
    if (!event.data) {
        return;
    }

    let payload;

    try {
        payload = event.data.json();
    } catch (error) {
        payload = { title: "", body: event.data.text() };
    }

    const title = payload.title || NOTIFICATION_FALLBACK_TITLE;
    const options = {
        body: payload.body || "",
        icon: payload.icon || NOTIFICATION_ICON,
        badge: payload.badge || NOTIFICATION_BADGE,
        tag: payload.tag || "digikash-notification",
        renotify: Boolean(payload.tag),
        data: payload.data || {},
        timestamp: Date.now()
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener("notificationclick", function (event) {
    event.notification.close();

    const data = event.notification.data || {};
    const target = resolveNotificationUrl(data.action_link);

    event.waitUntil((async function () {
        const clientList = await self.clients.matchAll({ type: "window", includeUncontrolled: true });

        for (const client of clientList) {
            if ("focus" in client) {
                if ("navigate" in client && client.url !== target) {
                    try {
                        await client.navigate(target);
                    } catch (error) {}
                }

                return client.focus();
            }
        }

        if (self.clients.openWindow) {
            return self.clients.openWindow(target);
        }
    })());
});

/**
 * Push payloads are attacker-influenced only via our own server, but an
 * absolute URL to another origin would still be a redirect we do not want to
 * perform, so anything off-origin falls back to the app scope.
 */
function resolveNotificationUrl(link) {
    const fallback = new URL(NAVIGATION_SCOPE, self.location.origin).href;

    if (!link) {
        return fallback;
    }

    try {
        const url = new URL(link, self.location.origin);

        return url.origin === self.location.origin ? url.href : fallback;
    } catch (error) {
        return fallback;
    }
}
