/**
 * Android app shell extras (no-op in the browser/PWA): shows the installed
 * app's version (android/ ... AppInfoPlugin.java, versionName from
 * build.gradle) after the Kochbuch version at the bottom of the navigation
 * drawer - "v<Kochbuch> / <App>". The web code always comes live from the
 * server, so only the app shell knows which APK is installed. An older APK
 * without the AppInfo plugin simply keeps showing the Kochbuch version.
 */
const NativeApp = (() => {
    function appInfoPlugin() {
        const cap = window.Capacitor;
        if (!cap || typeof cap.isNativePlatform !== 'function' || !cap.isNativePlatform()) {
            return null;
        }

        return (cap.Plugins && cap.Plugins.AppInfo) || null;
    }

    async function showVersion() {
        const plugin = appInfoPlugin();
        const target = document.querySelector('.nav-version-text');
        if (!plugin || !target) {
            return;
        }
        try {
            const info = await plugin.getInfo();
            if (info && info.versionName) {
                target.textContent = target.textContent.trim() + ' / ' + info.versionName;
            }
        } catch (e) {
            // Version unreadable - keep the Kochbuch version alone.
        }
    }

    return { showVersion };
})();
