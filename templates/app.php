<!DOCTYPE html>
<html>
<head>
    <title><?= htmlspecialchars($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8"/>

    <script>window.KOCHBUCH_API_BASE = "<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>/api/v1";</script>
    <script>window.KOCHBUCH_LOCALE = "<?= htmlspecialchars($translator->locale(), ENT_QUOTES) ?>";</script>
    <script>window.KOCHBUCH_TRANSLATIONS = <?= json_encode($translator->all(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
    <script>window.KOCHBUCH_SETTINGS = <?= json_encode([
        'recipe_page_sizes' => $recipePageSizes,
        'recipe_default_page_size' => $recipeDefaultPageSize,
        'default_font_scale_desktop' => $defaultFontScaleDesktop,
        'default_font_scale_mobile' => $defaultFontScaleMobile,
    ], JSON_UNESCAPED_UNICODE) ?>;</script>

    <link rel="stylesheet" href="./lib/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="./lib/bootstrap-icons/font/bootstrap-icons.min.css">
    <link rel="stylesheet" type="text/css" href="./css/style.css?v=<?= \Kochbuch\App::assetVersion($rootDir, '/css/style.css') ?>" />

    <link rel="manifest" href="<?= $baseUrl ?>/site.webmanifest">
    <link rel="icon" type="image/png" href="./favicon.png">
    <link rel="apple-touch-icon" href="./icon-192.png">
    <meta name="theme-color" content="#b5651d">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($appName) ?>">

    <script src="./js/config.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/config.js') ?>"></script>
    <script src="./js/i18n.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/i18n.js') ?>"></script>
    <script src="./js/api-client.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/api-client.js') ?>"></script>
    <script src="./js/settings.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/settings.js') ?>"></script>
    <script>applyStoredTheme();</script>
</head>
<body>
    <nav class="navbar app-navbar sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="#/recipes"><img class="brand-logo" src="./images/logo.svg" alt="">&nbsp;&nbsp;<?= htmlspecialchars($appName) ?></a>
            <!-- todo.md "PWA/Offline Capability" - hidden by default, toggled by nav.js's wireOfflineIndicator() based on navigator.onLine/the online/offline events (title/aria-label set there too, same reason the rest of this file uses data-i18n/JS instead of server-side translation - see the data-i18n loop below). -->
            <span id="offlineIndicator" class="offline-indicator text-warning d-none"><i class="bi bi-wifi-off"></i></span>
            <button class="navbar-toggler" type="button" data-bs-toggle="offcanvas" data-bs-target="#navOffcanvas" aria-controls="navOffcanvas">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="offcanvas offcanvas-end" tabindex="-1" id="navOffcanvas">
                <div class="offcanvas-header">
                    <h5 class="offcanvas-title"><?= htmlspecialchars($appName) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
                </div>
                <!-- No navbar-expand-* on the <nav>: the burger + offcanvas
                     drawer is used at every width, desktop included, so
                     settings/auth/legal links never sit in the top bar. -->
                <div class="offcanvas-body d-flex flex-column">
                    <div id="navAuthArea" class="d-flex flex-column gap-2 mb-3"></div>
                    <div class="btn-group mb-3" role="group" aria-label="Language">
                        <button type="button" class="btn btn-outline-secondary lang-btn" data-lang="de">DE</button>
                        <button type="button" class="btn btn-outline-secondary lang-btn" data-lang="en">EN</button>
                    </div>
                    <button id="themeToggleBtn" type="button" class="btn btn-outline-secondary mb-3">
                        <i id="themeToggleIcon" class="bi bi-moon-stars"></i> <span id="themeToggleLabel"></span>
                    </button>
                    <div class="mb-2">
                        <label class="form-label small mb-1" for="fontScaleDesktopRange" data-i18n="settings.font_scale_desktop"></label>
                        <div class="d-flex align-items-center gap-2">
                            <input type="range" class="form-range" id="fontScaleDesktopRange" min="50" max="150" step="5">
                            <span id="fontScaleDesktopValue" class="small text-muted text-end" style="min-width:3.5ch"></span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small mb-1" for="fontScaleMobileRange" data-i18n="settings.font_scale_mobile"></label>
                        <div class="d-flex align-items-center gap-2">
                            <input type="range" class="form-range" id="fontScaleMobileRange" min="50" max="150" step="5">
                            <span id="fontScaleMobileValue" class="small text-muted text-end" style="min-width:3.5ch"></span>
                        </div>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="keepScreenAwakeToggle">
                        <label class="form-check-label small" for="keepScreenAwakeToggle" data-i18n="settings.keep_screen_awake"></label>
                    </div>
                    <div class="mb-3">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="offlineSyncBtn">
                            <i class="bi bi-cloud-arrow-down"></i> <span data-i18n="settings.offline_sync_button"></span>
                        </button>
                        <div id="offlineSyncStatus" class="form-text"></div>
                    </div>
                    <hr class="w-100">
                    <div class="small d-flex flex-row justify-content-evenly">
                        <a class="link-secondary" href="<?= $baseUrl ?>/imprint" data-i18n="legal.imprint"></a>
                        <a class="link-secondary" href="<?= $baseUrl ?>/privacy" data-i18n="legal.privacy"></a>
                    </div>
                    <!-- mt-auto: the offcanvas-body is a flex column, so this
                         pushes version + logo to the very bottom of the drawer. -->
                    <div class="nav-version-text mt-auto pt-3 text-center">v<?= htmlspecialchars($appVersion) ?></div>
                    <img id="navLogo" class="nav-logo" src="./images/logo.svg" alt="<?= htmlspecialchars($appName) ?>">
                </div>
            </div>
        </div>
    </nav>

    <main id="app" class="container py-4"></main>
    <!--<hr class="w-100">
    <div id="legal" class="container text-center">
        <a class="link-secondary px-2" href="<?= $baseUrl ?>/imprint" data-i18n="legal.imprint"></a>
        <a class="link-secondary px-2" href="<?= $baseUrl ?>/privacy" data-i18n="legal.privacy"></a>
    </div>-->
    <div id="toastHost"></div>

    <script src="./lib/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="./js/helper.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/helper.js') ?>"></script>
    <script src="./js/toast.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/toast.js') ?>"></script>
    <script src="./js/confirm-dialog.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/confirm-dialog.js') ?>"></script>
    <script src="./js/error-dialog.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/error-dialog.js') ?>"></script>
    <script src="./js/ocr-draft-store.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/ocr-draft-store.js') ?>"></script>
    <script src="./js/offline-store.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/offline-store.js') ?>"></script>
    <script src="./js/router.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/router.js') ?>"></script>
    <script src="./js/nav.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/nav.js') ?>"></script>
    <script src="./js/views/recipes-list.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/views/recipes-list.js') ?>"></script>
    <script src="./js/views/recipe-detail.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/views/recipe-detail.js') ?>"></script>
    <script src="./js/views/recipe-form.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/views/recipe-form.js') ?>"></script>
    <script src="./js/views/recipe-import-photo.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/views/recipe-import-photo.js') ?>"></script>
    <script src="./js/views/recipe-import-json.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/views/recipe-import-json.js') ?>"></script>
    <script src="./js/views/categories.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/views/categories.js') ?>"></script>
    <script src="./js/views/login.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/views/login.js') ?>"></script>
    <script src="./js/views/profile.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/views/profile.js') ?>"></script>
    <script src="./js/views/not-found.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/views/not-found.js') ?>"></script>
    <script src="./js/app.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/app.js') ?>"></script>

    <script>
        document.querySelectorAll('[data-i18n]').forEach(function (el) {
            el.textContent = t(el.getAttribute('data-i18n'));
        });
    </script>

    <!-- todo.md "PWA/Offline Capability" - relative path (not "/sw.js"), same
         subdirectory-safety as every other same-origin reference on this
         page; a service worker's scope defaults to its own registering
         script's directory, so this resolves correctly either way. No
         "?v=..." cache-busting query here on purpose: the browser's own
         service-worker update check (a periodic byte-diff of this exact URL)
         is how a changed sw.js gets picked up - a changing query string would
         make every deploy look like registering a brand new, unrelated
         worker instead of letting that update mechanism do its job. -->
    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('./sw.js');
        }
    </script>
</body>
</html>
