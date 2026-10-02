/**
 * todo.md "PWA/Offline Capability" - thin IndexedDB wrapper storing the full
 * detail payload of every recipe the user has ever viewed (or explicitly
 * synced), keyed by id. Deliberately IndexedDB rather than the service
 * worker's Cache Storage (see sw.js's own doc-comment): Cache Storage is
 * keyed by exact request URL, a poor fit for "give me recipe #42 regardless
 * of how I got there" or for listing everything currently available
 * offline - IndexedDB's own keyPath/getAll() cover both naturally.
 *
 * Recipe images are never stored here (todo.md is explicit about this) -
 * only the plain JSON a `GET /recipes/{id}` response already is, same shape
 * recipe-detail.js/recipes-list.js already render from when online.
 */
// todo.md "PWA/Offline Capability" - used by recipes-list.js/recipe-detail.js
// as the timeout passed to Kochbuch.get() for their main read request,
// instead of api-client.js's much longer 15s default. Those calls back off
// to cached/IndexedDB data on failure, so they're interactive reads where
// a "poor/stalled connection" (as opposed to a clean, instant offline
// failure) should give up and fall back quickly rather than leaving the
// search box/detail view looking frozen for the full default timeout.
const OFFLINE_FALLBACK_TIMEOUT_MS = 5000;

const OfflineStore = (() => {
    const DB_NAME = 'kochbuch-offline';
    const DB_VERSION = 1;
    const STORE_NAME = 'recipes';
    const SYNC_META_KEY = 'kochbuch_offline_sync_meta';

    let dbPromise = null;

    function openDb() {
        if (!dbPromise) {
            dbPromise = new Promise((resolve, reject) => {
                if (!('indexedDB' in window)) {
                    reject(new Error('IndexedDB not supported'));

                    return;
                }
                const request = indexedDB.open(DB_NAME, DB_VERSION);
                request.onupgradeneeded = () => {
                    if (!request.result.objectStoreNames.contains(STORE_NAME)) {
                        request.result.createObjectStore(STORE_NAME, { keyPath: 'id' });
                    }
                };
                request.onsuccess = () => resolve(request.result);
                request.onerror = () => reject(request.error);
            });
        }

        return dbPromise;
    }

    async function withStore(mode, callback) {
        const db = await openDb();

        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, mode);
            const store = tx.objectStore(STORE_NAME);
            const result = callback(store);
            tx.oncomplete = () => resolve(result);
            tx.onerror = () => reject(tx.error);
        });
    }

    /**
     * Fire-and-forget by design (see recipe-detail.js's opportunistic call
     * on every successful view) - a storage failure here must never block
     * or break the normal online rendering path, so callers aren't required
     * to await/catch this.
     */
    async function saveRecipe(recipe) {
        try {
            await withStore('readwrite', (store) => store.put(recipe));
        } catch (e) {
            // IndexedDB unavailable/full/blocked - offline support just
            // silently stays unavailable for this recipe, nothing else to do.
        }
    }

    async function getRecipe(id) {
        try {
            return await withStore('readonly', (store) => {
                return new Promise((resolve, reject) => {
                    const req = store.get(Number(id));
                    req.onsuccess = () => resolve(req.result || null);
                    req.onerror = () => reject(req.error);
                });
            });
        } catch (e) {
            return null;
        }
    }

    async function listRecipes() {
        try {
            return await withStore('readonly', (store) => {
                return new Promise((resolve, reject) => {
                    const req = store.getAll();
                    req.onsuccess = () => resolve(req.result || []);
                    req.onerror = () => reject(req.error);
                });
            });
        } catch (e) {
            return [];
        }
    }

    async function clear() {
        try {
            await withStore('readwrite', (store) => store.clear());
        } catch (e) {
            // Nothing to clean up if IndexedDB isn't available in the first place.
        }
        localStorage.removeItem(SYNC_META_KEY);
    }

    function getSyncMeta() {
        try {
            return JSON.parse(localStorage.getItem(SYNC_META_KEY));
        } catch (e) {
            return null;
        }
    }

    function setSyncMeta(meta) {
        localStorage.setItem(SYNC_META_KEY, JSON.stringify(meta));
    }

    return { saveRecipe, getRecipe, listRecipes, clear, getSyncMeta, setSyncMeta };
})();
