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
const CACHE_NAME = 'kochbuch-shell-v3';

// Holds one pending share (see the share-target handler below) between the
// service worker receiving it and the import view picking it up. Separate
// from CACHE_NAME so activate() must not delete it.
const SHARE_CACHE = 'kochbuch-share-v1';

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
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE_NAME && key !== SHARE_CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

/**
 * PWA Web Share Target (site.webmanifest's "share_target"): sharing images
 * and/or text from another app (gallery, screenshot, an Instagram caption)
 * to "Kochbuch" POSTs multipart form data to ./share-target. A share with
 * files can only arrive as a POST, which a hash-routed SPA can't read, so
 * the worker stashes it in SHARE_CACHE and answers with a 303 redirect to
 * the photo-import view, which then picks it up (recipe-import-photo.js,
 * consumeSharedContent()). Without an active worker the POST falls through
 * to the server, which just redirects to the same view (see App.php).
 */
function guessImageType(name) {
    const ext = String(name || '').toLowerCase().split('.').pop();

    return { png: 'image/png', webp: 'image/webp', jpg: 'image/jpeg', jpeg: 'image/jpeg' }[ext] || 'image/jpeg';
}

async function handleShareTarget(request) {
    // Diagnostic copy of the raw request, only read when the parsed form
    // turns out empty (see `diagnostic` below).
    const rawCopy = request.clone();
    const contentType = request.headers.get('content-type') || '';
    let form;
    try {
        form = await request.formData();
    } catch (e) {
        form = new FormData();
        var parseError = String(e && e.message || e);
    }
    const cache = await caches.open(SHARE_CACHE);
    for (const key of await cache.keys()) {
        await cache.delete(key);
    }

    const meta = {};
    ['title', 'text', 'url'].forEach((field) => {
        const value = form.get(field);
        if (typeof value === 'string' && value.trim() !== '') {
            meta[field] = value;
        }
    });

    // Every file in the share counts, whatever the form field is called:
    // Android often hands over content:// files with an empty MIME type,
    // which a strict `type.startsWith('image/')` check silently dropped.
    // The type is guessed from the file name when missing; the server
    // validates the real bytes anyway (RecipeController::ocr()).
    // `received` is a small diagnostic so the import view can say what
    // actually arrived when nothing usable did.
    const received = [];
    let index = 0;
    for (const [field, value] of form.entries()) {
        if (typeof value === 'string') {
            received.push(field + ': text(' + value.length + ')');
            continue;
        }
        received.push(field + ': file(' + (value.type || '?') + ', ' + value.size + ' B)');
        if (value.size === 0) {
            continue;
        }
        const type = value.type && value.type !== 'application/octet-stream' ? value.type : guessImageType(value.name);
        await cache.put('shared/image-' + (index++), new Response(value, { headers: { 'Content-Type': type, 'X-Filename': encodeURIComponent(value.name || 'shared.jpg') } }));
    }
    meta.received = received;
    if (received.length === 0) {
        // Nothing parsed out of the share - record what the request really
        // looked like so the import view can report it.
        let bodyBytes = -1;
        try {
            bodyBytes = (await rawCopy.arrayBuffer()).byteLength;
        } catch (e) {
            // body already consumed
        }
        meta.diagnostic = 'content-type=' + (contentType || '?') + ', body=' + bodyBytes + ' B' + (typeof parseError === 'string' ? ', parse error: ' + parseError : '');
    }
    await cache.put('shared/meta', new Response(JSON.stringify(meta), { headers: { 'Content-Type': 'application/json' } }));

    return Response.redirect(new URL('./#/recipes/import-photo?shared=1', self.registration.scope).href, 303);
}

async function handleShareTargetGet(url) {
    const meta = {};
    ['title', 'text', 'url'].forEach((field) => {
        const value = url.searchParams.get(field);
        if (value && value.trim() !== '') {
            meta[field] = value;
        }
    });
    try {
        const cache = await caches.open(SHARE_CACHE);
        for (const key of await cache.keys()) {
            await cache.delete(key);
        }
        await cache.put('shared/meta', new Response(JSON.stringify(meta), { headers: { 'Content-Type': 'application/json' } }));
    } catch (e) {
        // Fall through to the redirect - the import view just opens empty.
    }

    return Response.redirect(new URL('./#/recipes/import-photo?shared=1', self.registration.scope).href, 303);
}

self.addEventListener('fetch', (event) => {
    const request = event.request;

    // GET variant of the share target (site.webmanifest declares GET: the
    // text-only form Chrome's WebAPK builder reliably accepts) - the shared
    // title/text/url arrive as query parameters.
    if (request.method === 'GET' && new URL(request.url).pathname.endsWith('/share-target')) {
        event.respondWith(handleShareTargetGet(new URL(request.url)));

        return;
    }

    if (request.method === 'POST' && new URL(request.url).pathname.endsWith('/share-target')) {
        event.respondWith(handleShareTarget(request).catch(() => Response.redirect(new URL('./#/recipes/import-photo', self.registration.scope).href, 303)));

        return;
    }

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
    // cache: 'no-cache' = always revalidate with the server (cheap 304s),
    // never trust a stale entry in the browser's HTTP cache: an old copy of
    // site.webmanifest cached before Cache-Control: no-cache existed was
    // served to the installing phone for days, so the installed PWA got
    // built without its share_target.
    event.respondWith(
        fetch(request, { cache: 'no-cache' })
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
