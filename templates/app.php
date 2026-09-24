<!DOCTYPE html>
<html>
<head>
    <title><?= htmlspecialchars($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8"/>

    <script>window.KOCHBUCH_API_BASE = "<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>/api/v1";</script>
    <script>window.KOCHBUCH_LOCALE = "<?= htmlspecialchars($translator->locale(), ENT_QUOTES) ?>";</script>
    <script>window.KOCHBUCH_TRANSLATIONS = <?= json_encode($translator->all(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>

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
            <a class="navbar-brand" href="#/recipes"><i class="bi bi-egg-fried"></i> <?= htmlspecialchars($appName) ?></a>
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
                    <hr class="w-100">
                    <div class="small d-flex flex-column gap-1">
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

    <div id="toastHost"></div>

    <script src="./lib/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="./js/helper.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/helper.js') ?>"></script>
    <script src="./js/toast.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/toast.js') ?>"></script>
    <script src="./js/confirm-dialog.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/confirm-dialog.js') ?>"></script>
    <script src="./js/router.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/router.js') ?>"></script>
    <script src="./js/nav.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/nav.js') ?>"></script>
    <script src="./js/views/recipes-list.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/views/recipes-list.js') ?>"></script>
    <script src="./js/views/recipe-detail.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/views/recipe-detail.js') ?>"></script>
    <script src="./js/views/recipe-form.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/views/recipe-form.js') ?>"></script>
    <script src="./js/views/categories.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/views/categories.js') ?>"></script>
    <script src="./js/views/login.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/views/login.js') ?>"></script>
    <script src="./js/views/not-found.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/views/not-found.js') ?>"></script>
    <script src="./js/app.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/app.js') ?>"></script>

    <script>
        document.querySelectorAll('[data-i18n]').forEach(function (el) {
            el.textContent = t(el.getAttribute('data-i18n'));
        });
    </script>
</body>
</html>
