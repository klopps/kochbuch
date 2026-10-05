/**
 * Android app shell extras (all no-ops in the browser/PWA):
 *
 *  - showVersion(): the installed app's version (AppInfoPlugin.java,
 *    versionName from build.gradle) after the Kochbuch version at the bottom
 *    of the navigation drawer - "v<Kochbuch> / <App>". The web code always
 *    comes live from the server, so only the app shell knows its version.
 *  - checkForUpdate(): in-app update (todo.md "Update-Funktion für die App").
 *    Compares the installed versionCode with public/app/version.json
 *    (written by bin\publish-app.bat next to the APK); if the server has a
 *    newer one, a banner and a drawer entry offer "Jetzt aktualisieren",
 *    which lets AppUpdatePlugin download the APK and open Android's
 *    installer. An APK from before AppUpdatePlugin just gets the download
 *    link instead. Checked silently on every app start, and on demand via
 *    "Nach Updates suchen" in the drawer.
 */
const NativeApp = (() => {
    let latest = null;     // version.json once a newer version was found
    let installed = null;  // AppInfo.getInfo() result

    function plugin(name) {
        const cap = window.Capacitor;
        if (!cap || typeof cap.isNativePlatform !== 'function' || !cap.isNativePlatform()) {
            return null;
        }

        return (cap.Plugins && cap.Plugins[name]) || null;
    }

    async function installedInfo() {
        if (installed === null) {
            const appInfo = plugin('AppInfo');
            installed = appInfo ? await appInfo.getInfo() : false;
        }

        return installed || null;
    }

    async function showVersion() {
        const target = document.querySelector('.nav-version-text');
        if (!target) {
            return;
        }
        try {
            const info = await installedInfo();
            if (info && info.versionName) {
                target.textContent = target.textContent.trim() + ' / ' + info.versionName;
            }
        } catch (e) {
            // Version unreadable - keep the Kochbuch version alone.
        }
        renderNavEntry();
    }

    function downloadUrl(version) {
        return new URL(version.url || '/app/kochbuch.apk', location.origin + siteBaseUrl() + '/').href;
    }

    /**
     * @param {{silent?: boolean}} options silent: only speak up when there
     *        is an update (app start); otherwise also report "up to date" /
     *        errors (the drawer's "Nach Updates suchen").
     */
    async function checkForUpdate(options) {
        const silent = !!(options && options.silent);
        let info;
        try {
            info = await installedInfo();
        } catch (e) {
            info = null;
        }
        if (!info) {
            return; // not the app, or an APK too old to know its version
        }

        let version;
        try {
            const response = await fetch(siteBaseUrl() + '/app/version.json', { cache: 'no-store' });
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            version = await response.json();
        } catch (e) {
            if (!silent) {
                showToast(t('app_update.check_failed'), 'danger');
            }

            return;
        }

        if (Number(version.versionCode) > Number(info.versionCode)) {
            latest = version;
            renderNavEntry();
            showBanner();
        } else {
            latest = null;
            renderNavEntry();
            if (!silent) {
                showToast(t('app_update.up_to_date', { version: info.versionName }));
            }
        }
    }

    function renderNavEntry() {
        const box = document.getElementById('navAppUpdate');
        if (!box || !plugin('AppInfo')) {
            return;
        }
        box.classList.remove('d-none');
        if (latest) {
            box.innerHTML = '<button type="button" class="btn btn-sm btn-primary app-update-now">' +
                '<i class="bi bi-download"></i> ' + escapeHtml(t('app_update.available', { version: latest.versionName })) + '</button>';
        } else {
            box.innerHTML = '<a href="#" class="link-secondary small app-update-check">' + escapeHtml(t('app_update.check')) + '</a>';
        }
        box.querySelectorAll('.app-update-now').forEach((btn) => btn.addEventListener('click', () => installUpdate(btn)));
        box.querySelectorAll('.app-update-check').forEach((link) => link.addEventListener('click', (e) => {
            e.preventDefault();
            checkForUpdate({ silent: false });
        }));
    }

    function showBanner() {
        let banner = document.getElementById('appUpdateBanner');
        if (!banner) {
            // Outside #app (which every route re-renders), fixed at the bottom.
            banner = document.createElement('div');
            banner.id = 'appUpdateBanner';
            banner.className = 'app-update-banner alert alert-info d-flex flex-wrap align-items-center gap-2 mb-0 shadow';
            document.body.appendChild(banner);
        }
        banner.innerHTML =
            '<i class="bi bi-arrow-up-circle"></i>' +
            '<span class="me-auto">' + escapeHtml(t('app_update.available', { version: latest.versionName })) + '</span>' +
            '<button type="button" class="btn btn-sm btn-primary app-update-now">' + escapeHtml(t('app_update.install')) + '</button>' +
            '<button type="button" class="btn-close" aria-label="' + escapeHtml(t('common.close')) + '"></button>';
        banner.querySelector('.app-update-now').addEventListener('click', (e) => installUpdate(e.currentTarget));
        banner.querySelector('.btn-close').addEventListener('click', () => banner.remove());
    }

    async function installUpdate(button) {
        if (!latest) {
            return;
        }
        const url = downloadUrl(latest);
        const updater = plugin('AppUpdate');
        if (!updater) {
            // APK from before AppUpdatePlugin: it can't install itself.
            showToast(t('app_update.manual', { url }), 'info');

            return;
        }

        const original = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' + escapeHtml(t('app_update.downloading'));
        const listener = await updater.addListener('downloadProgress', (event) => {
            button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' +
                escapeHtml(t('app_update.downloading')) + ' ' + event.percent + ' %';
        });
        try {
            await updater.downloadAndInstall({ url });
            // Android's installer is open now; it replaces the app on confirm.
        } catch (e) {
            showToast(t('app_update.failed') + (e && e.message ? ' (' + e.message + ')' : ''), 'danger');
        } finally {
            listener.remove();
            button.disabled = false;
            button.innerHTML = original;
        }
    }

    return { showVersion, checkForUpdate };
})();
