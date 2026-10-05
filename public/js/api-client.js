/**
 * Kochbuch API client
 *
 * Thin fetch() wrapper around the /api/v1 REST API. Handles the JWT bearer
 * token (issued by POST /api/v1/auth/login) so feature code doesn't have
 * to - don't fetch() the API directly, go through this.
 */
const Kochbuch = (() => {
    // Injected by templates/app.php from the server's own base path, so
    // this keeps working whether the app is served at the domain root or
    // from a subdirectory (e.g. https://example.com/kochbuch/public/).
    const BASE_URL = (typeof window.KOCHBUCH_API_BASE !== 'undefined') ? window.KOCHBUCH_API_BASE : '/api/v1';
    const TOKEN_KEY = 'kochbuch_token';

    // todo.md "Loading Indicator During Longer Processes" - a fixed,
    // centrally-defined cutoff (not an admin setting) so a hung request
    // (dead backend, lost connection) eventually surfaces as a clear error
    // instead of leaving a "Save" button spinning forever. Uploads get a
    // longer allowance since they transfer a file, not just JSON.
    const DEFAULT_TIMEOUT_MS = 15000;
    const UPLOAD_TIMEOUT_MS = 60000;

    /**
     * @return {signal: AbortSignal, clear: () => void}
     */
    function withTimeout(ms) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), ms);

        return { signal: controller.signal, clear: () => clearTimeout(timer) };
    }

    /**
     * A plain Error with the same {status, data: {code, message}} shape
     * unwrap() gives a normal failed-request error, so callers (and
     * translateApiError()) don't need a separate code path for a timeout -
     * error.data.code = 'request_timeout' resolves via the usual
     * "error.<code>" i18n key.
     */
    function timeoutError() {
        const message = 'The request took too long and was cancelled.';
        const error = new Error(message);
        error.status = 0;
        error.data = { code: 'request_timeout', message };

        return error;
    }

    /**
     * todo.md "PWA/Offline Capability" - the navbar's offline indicator
     * (nav.js's wireOfflineIndicator()) starts out driven only by
     * navigator.onLine, which only reliably catches a confident "definitely
     * offline" (airplane mode/no signal) - it stays falsely "online" when
     * connected to a network that can't actually reach this server (the
     * real case that prompted this: a phone on the same WiFi as the dev
     * machine, which simply isn't reachable). A fetch() that never gets a
     * response at all (not even an error one) is strong, direct proof the
     * opposite way - dispatched as a DOM event rather than importing nav.js
     * here, so this module stays a self-contained API client with no UI
     * dependency. Deliberately NOT wired into fetchImageObjectUrl(): every
     * recipe photo fetch is *expected* to fail while offline (photos are
     * never cached, see helper.js's hydrateAuthImages()), so treating that
     * as a connectivity signal would make the indicator flicker on by
     * design on every single offline page load, independent of whether the
     * server is actually reachable.
     */
    function notifyConnectivity(online) {
        window.dispatchEvent(new CustomEvent('kochbuch:connectivity', { detail: { online } }));
    }

    function getToken() {
        return localStorage.getItem(TOKEN_KEY);
    }

    function setToken(token) {
        if (token) {
            localStorage.setItem(TOKEN_KEY, token);
        } else {
            localStorage.removeItem(TOKEN_KEY);
        }
    }

    function isLoggedIn() {
        return !!getToken();
    }

    function authHeaders() {
        const token = getToken();

        return token ? { Authorization: 'Bearer ' + token } : {};
    }

    async function unwrap(response) {
        const contentType = response.headers.get('Content-Type') || '';
        const data = contentType.includes('application/json') ? await response.json().catch(() => ({})) : {};

        if (!response.ok) {
            // A non-JSON error (e.g. a PHP fatal error page, a proxy's 502/504)
            // has no message - and over HTTP/2 not even a statusText - so
            // fall back to a translated text with the status, never an empty
            // error box.
            const fallback = t('error.http_status', { status: response.status });
            const error = new Error((data.error && data.error.message) || fallback);
            error.status = response.status;
            error.data = data.error || {};
            throw error;
        }

        if (response.status !== 204 && !contentType.includes('application/json')) {
            // 200 but no JSON - e.g. PHP printed a fatal error page with
            // display_errors on: a broken answer, not a success. (204 No
            // Content is the deliberate empty answer of several DELETEs.)
            const error = new Error(t('error.http_status', { status: response.status }));
            error.status = response.status;
            error.data = {};
            throw error;
        }

        return data.data;
    }

    async function request(method, path, body, timeoutMs) {
        const { signal, clear } = withTimeout(timeoutMs || DEFAULT_TIMEOUT_MS);
        let response;
        try {
            response = await fetch(BASE_URL + path, {
                method,
                headers: Object.assign({ 'Content-Type': 'application/json' }, authHeaders()),
                body: body !== undefined ? JSON.stringify(body) : undefined,
                signal,
            });
        } catch (e) {
            notifyConnectivity(false);
            throw e.name === 'AbortError' ? timeoutError() : e;
        } finally {
            clear();
        }

        // Any actual HTTP response - even an error one (404/500/...) - is
        // proof the server is reachable, independent of whether unwrap()
        // itself goes on to throw for a non-2xx status.
        notifyConnectivity(true);

        return unwrap(response);
    }

    /**
     * multipart/form-data upload (recipe images, placeholder images) -
     * don't set Content-Type manually, the browser needs to add its own
     * boundary parameter. Always POST, even for an "update" (see
     * PlaceholderImageController) - PHP only populates $_FILES/$_POST for
     * actual POST requests, so a PUT with a multipart body would silently
     * see no fields/files at all.
     */
    async function upload(path, formData, timeoutMs) {
        const { signal, clear } = withTimeout(timeoutMs || UPLOAD_TIMEOUT_MS);
        let response;
        try {
            response = await fetch(BASE_URL + path, {
                method: 'POST',
                headers: authHeaders(),
                body: formData,
                signal,
            });
        } catch (e) {
            notifyConnectivity(false);
            throw e.name === 'AbortError' ? timeoutError() : e;
        } finally {
            clear();
        }

        notifyConnectivity(true);

        return unwrap(response);
    }

    /**
     * Fetches a binary response (export endpoints) with the auth header
     * attached and triggers a browser download - a plain <a href> can't
     * send an Authorization header, which a private recipe's export needs.
     */
    async function download(path) {
        const response = await fetch(BASE_URL + path, { headers: authHeaders() });
        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            const error = new Error((data.error && data.error.message) || response.statusText);
            error.status = response.status;
            error.data = data.error || {};
            throw error;
        }

        const blob = await response.blob();
        const disposition = response.headers.get('Content-Disposition') || '';
        const match = disposition.match(/filename="([^"]+)"/);
        const filename = match ? match[1] : 'download';

        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    }

    /**
     * Fetches an image with the auth header attached and returns an
     * object URL for it - a plain <img src="..."> can't send an
     * Authorization header, so a private recipe's image 403s if pointed
     * at the API URL directly (a public recipe's image happens to work
     * either way, since that endpoint allows anonymous reads - but relying
     * on that per-recipe would mean two different rendering paths for
     * what should be the same <img>). Callers must URL.revokeObjectURL()
     * the result once the <img> no longer needs it (see
     * helper.js's hydrateAuthImages(), which owns the whole lifecycle).
     */
    async function fetchImageObjectUrl(path) {
        const response = await fetch(BASE_URL + path, { headers: authHeaders() });
        if (!response.ok) {
            throw new Error('image fetch failed: ' + response.status);
        }

        return URL.createObjectURL(await response.blob());
    }

    return {
        // timeoutMs is optional on every verb - overrides DEFAULT_TIMEOUT_MS
        // for a specific call known to legitimately take longer (e.g. the
        // Chefkoch importer's bulk list/import endpoints, which make
        // several sequential upstream requests server-side before
        // responding - see admin-chefkoch-import.js).
        get: (path, timeoutMs) => request('GET', path, undefined, timeoutMs),
        post: (path, body, timeoutMs) => request('POST', path, body, timeoutMs),
        put: (path, body, timeoutMs) => request('PUT', path, body, timeoutMs),
        del: (path, timeoutMs) => request('DELETE', path, undefined, timeoutMs),
        upload,
        download,
        imageUrl: (path) => BASE_URL + path,
        fetchImageObjectUrl,
        getToken,
        setToken,
        isLoggedIn,
    };
})();
