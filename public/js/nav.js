/**
 * Updates the dynamic parts of the navbar (offcanvas auth area, theme
 * toggle label) - the static structure (brand, links, offcanvas shell)
 * lives in templates/app.php itself.
 */
function renderNav() {
    const authArea = document.getElementById('navAuthArea');
    if (!authArea) {
        return;
    }

    if (currentUser) {
        // /admin is a separate server-rendered page (its own AdminLTE
        // shell), not part of this SPA - a plain href leaving the SPA,
        // same as login.js's forgot-password link.
        const adminLinkHtml = currentUser.is_admin
            ? '<a href="' + window.KOCHBUCH_API_BASE.replace(/\/api\/v1$/, '') + '/admin" class="btn btn-outline-secondary btn-sm"><i class="bi bi-gear"></i> ' + escapeHtml(t('nav.admin')) + '</a>'
            : '';

        authArea.innerHTML =
            '<div class="small text-muted mb-1"><i class="bi bi-person-circle"></i> ' + escapeHtml(currentUser.username) + '</div>' +
            '<a href="#/recipes?mine=1" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="offcanvas">' + escapeHtml(t('recipe.my_recipes')) + '</a>' +
            // todo.md "Changing your own user details" - a dedicated routed
            // page (public/js/views/profile.js) rather than an inline
            // drawer form, since it also covers firstname/lastname/username/
            // email now, not just the password.
            '<a href="#/profile" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="offcanvas">' + escapeHtml(t('nav.profile')) + '</a>' +
            adminLinkHtml +
            '<button type="button" class="btn btn-outline-secondary btn-sm" id="logoutBtn">' + escapeHtml(t('nav.logout')) + '</button>';

        document.getElementById('logoutBtn').addEventListener('click', () => {
            Kochbuch.setToken(null);
            currentUser = null;
            renderNav();
            Router.navigate('/recipes');
        });
    } else {
        authArea.innerHTML =
            '<a href="#/login" class="btn btn-primary btn-sm" data-bs-dismiss="offcanvas">' + escapeHtml(t('nav.login')) + '</a>';
    }

    updateThemeToggleLabel();
}

function updateThemeToggleLabel() {
    const label = document.getElementById('themeToggleLabel');
    const icon = document.getElementById('themeToggleIcon');
    if (!label) {
        return;
    }
    const isDark = loadSettings().theme === 'dark';
    label.textContent = isDark ? t('theme.light') : t('theme.dark');
    if (icon) {
        icon.className = 'bi ' + (isDark ? 'bi-sun' : 'bi-moon-stars');
    }
}

function wireThemeToggle() {
    const btn = document.getElementById('themeToggleBtn');
    if (!btn) {
        return;
    }
    btn.addEventListener('click', () => {
        const next = loadSettings().theme === 'dark' ? 'light' : 'dark';
        setTheme(next);
        updateThemeToggleLabel();
    });
}

function updateFontScaleLabels() {
    const desktopRange = document.getElementById('fontScaleDesktopRange');
    const mobileRange = document.getElementById('fontScaleMobileRange');
    const desktopValue = document.getElementById('fontScaleDesktopValue');
    const mobileValue = document.getElementById('fontScaleMobileValue');
    if (desktopValue && desktopRange) {
        desktopValue.textContent = desktopRange.value + '%';
    }
    if (mobileValue && mobileRange) {
        mobileValue.textContent = mobileRange.value + '%';
    }
}

function wireFontScaleControls() {
    const desktopRange = document.getElementById('fontScaleDesktopRange');
    const mobileRange = document.getElementById('fontScaleMobileRange');
    if (!desktopRange || !mobileRange) {
        return;
    }

    const settings = loadSettings();
    desktopRange.value = settings.fontScaleDesktop;
    mobileRange.value = settings.fontScaleMobile;
    updateFontScaleLabels();

    desktopRange.addEventListener('input', () => {
        setFontScale('desktop', parseInt(desktopRange.value, 10));
        updateFontScaleLabels();
    });
    mobileRange.addEventListener('input', () => {
        setFontScale('mobile', parseInt(mobileRange.value, 10));
        updateFontScaleLabels();
    });
}

/**
 * todo.md "Disabling the Screen Lock on Smartphones" - just persists the
 * preference and, if currently on the recipe detail view, applies it
 * immediately (acquireWakeLockIfEnabled()/releaseWakeLock() live in
 * recipe-detail.js, the only place that actually requests the wake lock).
 */
function wireKeepScreenAwakeToggle() {
    const toggle = document.getElementById('keepScreenAwakeToggle');
    if (!toggle) {
        return;
    }
    toggle.checked = loadSettings().keepScreenAwake;
    toggle.addEventListener('change', () => {
        setKeepScreenAwake(toggle.checked);
        if (toggle.checked) {
            acquireWakeLockIfEnabled();
        } else {
            releaseWakeLock();
        }
    });
}

/**
 * todo.md "PWA/Offline Capability" - renders the "last synced" status line
 * under the sync button, both on initial page load and right after a sync
 * finishes.
 */
function updateOfflineSyncStatus() {
    const statusEl = document.getElementById('offlineSyncStatus');
    if (!statusEl) {
        return;
    }
    const meta = OfflineStore.getSyncMeta();
    if (!meta) {
        statusEl.textContent = t('settings.offline_sync_none_yet');

        return;
    }
    statusEl.textContent = t('settings.offline_sync_status', {
        count: meta.recipeCount,
        date: new Date(meta.syncedAt).toLocaleString(window.KOCHBUCH_LOCALE),
    });
}

/**
 * todo.md "PWA/Offline Capability" - pages through every recipe visible to
 * the current user (GET /recipes, the same unfiltered endpoint/visibility
 * rule the recipe list itself uses - no recipe is reachable only through a
 * specific filter a plain unfiltered page-through would miss), stores each
 * one's full detail via OfflineStore, then does the same for the user's own
 * categories and their recipe membership (needed for the category filter to
 * work offline, see recipes-list.js's filterRecipesOffline()). Fetches
 * recipe details in small concurrent batches rather than one at a time -
 * this now runs automatically on every boot (see autoSyncForOffline()), not
 * just on an occasional manual click, so it needs to be reasonably fast.
 *
 * Shared by the manual "sync now" button and the automatic boot-time sync
 * below - `syncPromise` makes a second caller (e.g. the user clicking the
 * button while the automatic boot sync is still running) just await the
 * same in-flight run instead of starting a duplicate one.
 */
let syncPromise = null;

async function performFullSync(onProgress) {
    if (syncPromise) {
        return syncPromise;
    }

    syncPromise = (async () => {
        const pageSizes = (window.KOCHBUCH_SETTINGS && window.KOCHBUCH_SETTINGS.recipe_page_sizes) || [10];
        const perPage = Math.max(...pageSizes);
        const BATCH_SIZE = 5;

        const ids = [];
        let page = 1;
        while (true) {
            const result = await Kochbuch.get('/recipes?per_page=' + perPage + '&page=' + page);
            result.items.forEach((item) => ids.push(item.id));
            if (result.items.length === 0 || ids.length >= result.total) {
                break;
            }
            page++;
        }

        let synced = 0;
        for (let i = 0; i < ids.length; i += BATCH_SIZE) {
            const batch = ids.slice(i, i + BATCH_SIZE);
            await Promise.all(batch.map(async (id) => {
                try {
                    const recipe = await Kochbuch.get('/recipes/' + id);
                    await OfflineStore.saveRecipe(recipe);
                    synced++;
                } catch (e) {
                    // One recipe failing (e.g. deleted mid-sync) shouldn't abort the rest.
                }
            }));
            if (onProgress) {
                onProgress(synced, ids.length);
            }
        }

        let categoryCount = 0;
        if (Kochbuch.isLoggedIn()) {
            try {
                const categories = await Kochbuch.get('/categories');
                const withRecipeIds = await Promise.all(categories.map(async (category) => {
                    const recipes = await Kochbuch.get('/categories/' + category.id + '/recipes');

                    return { id: category.id, name: category.name, recipeIds: recipes.map((r) => r.id) };
                }));
                await OfflineStore.saveCategories(withRecipeIds);
                categoryCount = withRecipeIds.length;
            } catch (e) {
                // Categories just stay whatever was synced last time - not fatal.
            }
        }

        OfflineStore.setSyncMeta({ syncedAt: new Date().toISOString(), recipeCount: synced, categoryCount });

        return synced;
    })();

    try {
        return await syncPromise;
    } finally {
        syncPromise = null;
    }
}

async function syncRecipesForOffline(button) {
    const statusEl = document.getElementById('offlineSyncStatus');

    await withBusyButton(button, async () => {
        const synced = await performFullSync((done, total) => {
            if (statusEl) {
                statusEl.textContent = t('settings.offline_sync_progress', { done, total });
            }
        });
        updateOfflineSyncStatus();
        showToast(t('settings.offline_sync_summary', { count: synced }));
    });
}

/**
 * todo.md "PWA/Offline Capability" - runs performFullSync() automatically on
 * every app boot (see app.js), not just when the user remembers to press
 * the manual button - fire-and-forget (never awaited by the caller) so it
 * never delays the first render, and any failure (most commonly: actually
 * offline at boot) is swallowed rather than surfaced, unlike the manual
 * button's own toast/error handling.
 */
async function autoSyncForOffline() {
    try {
        await performFullSync();
        updateOfflineSyncStatus();
    } catch (e) {
        // Silent - the manual button remains available to retry/inspect.
    }
}

function wireOfflineSyncButton() {
    const btn = document.getElementById('offlineSyncBtn');
    if (!btn) {
        return;
    }
    updateOfflineSyncStatus();
    btn.addEventListener('click', () => syncRecipesForOffline(btn));
}

/**
 * todo.md "PWA/Offline Capability" - a small persistent icon in the navbar
 * (between the app name and the burger button) whenever the app is
 * offline, not just a one-off toast - offline browsing/search can go on for
 * a while (see recipes-list.js's filterRecipesOffline()/homeFeedOffline()),
 * so there should be an always-visible reminder of why results might be
 * stale or incomplete.
 *
 * Two independent signals, either of which can show/hide it:
 * - The browser's own online/offline events (navigator.onLine) - reliably
 *   catches the confident case (airplane mode/no signal), immediately,
 *   without waiting for any request to actually fail.
 * - api-client.js's 'kochbuch:connectivity' event, fired from every real
 *   request's actual outcome - catches the case navigator.onLine can't:
 *   reported "online" by the OS/browser, but this specific server isn't
 *   actually reachable (e.g. connected to a WiFi network the target host
 *   isn't on). Whichever signal fired most recently wins - a real request
 *   succeeding is treated as proof of connectivity even if a stale
 *   'offline' browser event is still the last navigator.onLine state, and
 *   vice versa.
 */
function wireOfflineIndicator() {
    const indicator = document.getElementById('offlineIndicator');
    if (!indicator) {
        return;
    }
    indicator.title = t('nav.offline_indicator');
    indicator.setAttribute('aria-label', t('nav.offline_indicator'));

    const setOffline = (offline) => indicator.classList.toggle('d-none', !offline);
    setOffline(!navigator.onLine);
    window.addEventListener('online', () => setOffline(false));
    window.addEventListener('offline', () => setOffline(true));
    window.addEventListener('kochbuch:connectivity', (e) => setOffline(!e.detail.online));
}

function wireLanguageSwitcher() {
    const buttons = document.querySelectorAll('.lang-btn');
    if (!buttons.length) {
        return;
    }
    const active = window.KOCHBUCH_LOCALE;
    buttons.forEach((btn) => {
        btn.classList.toggle('active', btn.dataset.lang === active);
        btn.addEventListener('click', () => setLanguage(btn.dataset.lang));
    });
}

/**
 * A hidden "hard refresh" gesture on the drawer logo: four taps/clicks in a
 * row force a real full-page reload rather than a SPA hash navigation -
 * useful when a stale service worker/cache needs to be shaken loose
 * without knowing a keyboard shortcut. Counted by hand with a reset timer
 * rather than via MouseEvent.detail - detail is desktop-mouse-only native
 * click counting; a tap on a touchscreen fires a synthesized click with
 * detail always 1, so mobile (this app is mobile-first) would never reach
 * 4 with the native counter alone.
 */
function wireLogoReload() {
    const logo = document.getElementById('navLogo');
    if (!logo) {
        return;
    }
    let clickCount = 0;
    let resetTimer = null;
    logo.addEventListener('click', () => {
        clickCount++;
        clearTimeout(resetTimer);
        if (clickCount >= 4) {
            clickCount = 0;
            window.location.reload();

            return;
        }
        resetTimer = setTimeout(() => {
            clickCount = 0;
        }, 600);
    });
}
