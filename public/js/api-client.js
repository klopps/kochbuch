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
            const error = new Error((data.error && data.error.message) || response.statusText);
            error.status = response.status;
            error.data = data.error || {};
            throw error;
        }

        return data.data;
    }

    async function request(method, path, body) {
        const response = await fetch(BASE_URL + path, {
            method,
            headers: Object.assign({ 'Content-Type': 'application/json' }, authHeaders()),
            body: body !== undefined ? JSON.stringify(body) : undefined,
        });

        return unwrap(response);
    }

    /**
     * multipart/form-data upload (recipe images) - don't set Content-Type
     * manually, the browser needs to add its own boundary parameter.
     */
    async function upload(path, formData) {
        const response = await fetch(BASE_URL + path, {
            method: 'POST',
            headers: authHeaders(),
            body: formData,
        });

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
        get: (path) => request('GET', path),
        post: (path, body) => request('POST', path, body),
        put: (path, body) => request('PUT', path, body),
        del: (path) => request('DELETE', path),
        upload,
        download,
        imageUrl: (path) => BASE_URL + path,
        fetchImageObjectUrl,
        getToken,
        setToken,
        isLoggedIn,
    };
})();
