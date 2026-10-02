/**
 * todo.md "PWA/Offline Capability" - caches the app shell (the SPA's own
 * HTML/CSS/JS/vendored-lib files, icons, manifest, and the static
 * placeholder-image files) so the app keeps working with no connection.
 *
 * Deliberately does NOT intercept or cache anything under /api/v1/ - recipe
 * data is cached separately in IndexedDB (see offline-store.js), which is a
 * much better fit for "give me recipe #42" or "list everything available
 * offline" than Cache Storage's exact-request-URL keying would be for a
 * paginated/filtered API. Recipe photos in particular
 * (/api/v1/recipes/{id}/images/{imageId}) are explicitly never cached
 * anywhere, per the todo's own requirement - that fetch simply fails
 * through to the network when offline, and the app substitutes a
 * placeholder (see helper.js's hydrateAuthImages()).
 *
 * No build step/bundler, matching the rest of this project - plain vanilla
 * JS, no Workbox.
 */

// Bump this string whenever a deploy needs every previously-cached shell
// entry thrown away - activate() below deletes any cache that doesn't match
// the current name. There's no automated versioning here (no build step to
// hook into), same "a human remembers to do this" spirit as this project's
// hand-numbered database migrations.
const CACHE_NAME = 'kochbuch-shell-v1';

self.addEventListener('install', () => {
    // Take over immediately rather than waiting for every open tab to close
    // first - the fetch handler below is network-first, so a newly-activated
    // worker never serves stale content it doesn't already know is stale;
    // there's no "old content under a new worker version" risk to avoid here.
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);
    if (url.origin !== self.location.origin || url.pathname.includes('/api/v1/')) {
        return; // Cross-origin or API traffic - never intercepted/cached here.
    }

    // Network-first, falling back to whatever was cached from a previous
    // successful load - this is what makes the Cache-Control: no-store the
    // server deliberately sends on every HTML route (see App.php) harmless
    // here: that header only governs the browser's own HTTP cache, not this
    // separate, explicitly-managed Cache Storage.
    event.respondWith(
        fetch(request)
            .then((response) => {
                if (response && response.ok) {
                    const copy = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
                }

                return response;
            })
            .catch(() => caches.match(request).then((cached) => cached || Response.error()))
    );
});
