/**
 * Bridge to the native Android app's share receiver (android/ ...
 * ShareReceiverPlugin.java; todo.md "Share to Kochbuch"). Only active inside
 * the Capacitor app shell - in a normal browser or the installed PWA every
 * function here is a no-op and the PWA share target (sw.js) is used instead.
 *
 * Why a native app at all: Chrome refuses to hand its own "share image"
 * files to an installed web app, so sharing an image out of Chrome only
 * works when a real app is the share target.
 */
const NativeShare = (() => {
    function plugin() {
        const cap = window.Capacitor;
        if (!cap || typeof cap.isNativePlatform !== 'function' || !cap.isNativePlatform()) {
            return null;
        }

        return (cap.Plugins && cap.Plugins.ShareReceiver) || null;
    }

    /**
     * Whenever Android hands the app a share (also one that arrived before
     * this page finished loading - the plugin retains the event), open the
     * import view. The timestamp makes the hash differ every time, so a
     * second share while the import view is already open re-renders it.
     */
    function init() {
        const p = plugin();
        if (!p) {
            return;
        }
        p.addListener('shareReceived', () => {
            Router.navigate('/recipes/import-photo?shared=native&t=' + Date.now());
        });
    }

    /**
     * Takes (and clears) the pending share from the native side.
     *
     * @returns {Promise<{files: File[], title: string, text: string, skipped: number}|null>}
     */
    async function take() {
        const p = plugin();
        if (!p) {
            return null;
        }
        const share = await p.getPendingShare();
        if (!share || !share.hasShare) {
            return null;
        }

        return {
            files: (share.files || []).map(fileFromBase64),
            title: share.title || '',
            text: share.text || '',
            skipped: share.skipped || 0,
        };
    }

    return { init, take, isAvailable: () => plugin() !== null };
})();
