<?php
/**
 * Shared shell for every /admin/* page (todo.md "Admin-Oberfläche"),
 * mirroring YTAN's templates/partials/admin-shell-header.php / -footer.php
 * (see CLAUDE.md). A page using this shell sets a few variables and
 * requires this file, then requires the matching -footer.php after its own
 * body markup, e.g.:
 *
 *     $pageTitle = $t('admin.nav.dashboard');
 *     $adminActiveNav = 'dashboard';
 *     $pageScripts = ['/js/admin-dashboard.js'];
 *     require $rootDir . '/templates/partials/admin-shell-header.php';
 *     // ... page body ...
 *     require $rootDir . '/templates/partials/admin-shell-footer.php';
 *
 * There is no server-side auth check here - every /admin/* route in
 * App.php is a plain unauthenticated route, same as the main SPA shell.
 * The real gate is server-side on the API via BaseController::requireAdmin();
 * client-side, public/js/admin-auth.js's initAdminAuth() toggles #loginBox
 * vs #adminAppWrapper based on GET /auth/me, and every admin page's own JS
 * file calls it at the bottom of admin-shell-footer.php's script stack.
 *
 * Registering a new admin page: add a route in App.php requiring a new
 * thin template following the pattern above, plus one entry in
 * $adminNavItems below.
 */
$adminNavItems = [
    ['key' => 'dashboard', 'icon' => 'bi-speedometer2', 'label' => $t('admin.nav.dashboard'), 'href' => $baseUrl . '/admin'],
    ['key' => 'users', 'icon' => 'bi-people', 'label' => $t('admin.nav.users'), 'href' => $baseUrl . '/admin/users'],
    ['key' => 'translate', 'icon' => 'bi-translate', 'label' => $t('admin.nav.translate'), 'href' => $baseUrl . '/admin/translate', 'visible' => $translateToolEnabled ?? false],
    ['key' => 'settings', 'icon' => 'bi-gear', 'label' => $t('admin.nav.settings'), 'href' => $baseUrl . '/admin/settings'],
];
?>
<!DOCTYPE html>
<html>
<head>
    <title><?= htmlspecialchars($appName) ?> | <?= htmlspecialchars($pageTitle ?? 'Admin') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8" />

    <script>window.KOCHBUCH_API_BASE = "<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>/api/v1";</script>
    <script>window.KOCHBUCH_TRANSLATIONS = <?= json_encode($translator->all(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>

    <link rel="stylesheet" href="<?= $baseUrl ?>/lib/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= $baseUrl ?>/lib/bootstrap-icons/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= $baseUrl ?>/lib/adminlte/adminlte.min.css">
    <link rel="stylesheet" type="text/css" href="<?= $baseUrl ?>/css/style.css?v=<?= \Kochbuch\App::assetVersion($rootDir, '/css/style.css') ?>" />
    <link rel="icon" type="image/png" href="<?= $baseUrl ?>/favicon.png">
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <div id="loginBox" class="d-flex align-items-center justify-content-center p-3" style="min-height:100vh">
        <div class="card shadow-sm" style="width:100%;max-width:24rem">
            <div class="card-body">
                <h1 class="h4 mb-3 text-center">&nbsp;<?= htmlspecialchars($appName) ?> Admin</h1>
                <form id="adminLoginForm">
                    <div class="mb-3">
                        <label class="form-label"><?= htmlspecialchars($t('auth.username')) ?></label>
                        <input id="loginUsername" type="text" class="form-control" autocomplete="username" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= htmlspecialchars($t('auth.password')) ?></label>
                        <div class="input-group">
                            <input id="loginPassword" type="password" class="form-control" autocomplete="current-password" required>
                            <button type="button" class="btn btn-outline-secondary" tabindex="-1" onclick="togglePasswordVisibility('loginPassword', this);" aria-label="<?= htmlspecialchars($t('common.show_password'), ENT_QUOTES) ?>"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <div id="loginError" class="alert alert-danger d-none"></div>
                    <button type="submit" class="btn btn-primary w-100"><?= htmlspecialchars($t('nav.login')) ?></button>
                </form>
            </div>
        </div>
    </div>

    <div id="adminAppWrapper" class="app-wrapper d-none">
        <nav class="app-header navbar navbar-expand bg-body">
            <div class="container-fluid">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <button class="nav-link" data-lte-toggle="sidebar" type="button"><i class="bi bi-list"></i></button>
                    </li>
                </ul>
                <ul class="navbar-nav ms-auto align-items-center gap-2">
                    <li class="nav-item d-flex align-items-center"><span id="adminUserName" class="text-muted small"></span></li>
                    <li class="nav-item">
                        <a href="<?= $baseUrl ?>/" class="nav-link" title="<?= htmlspecialchars($appName) ?>"><i class="bi bi-box-arrow-up-left"></i></a>
                    </li>
                    <li class="nav-item">
                        <button id="adminLogoutBtn" type="button" class="btn btn-sm btn-outline-secondary"><?= htmlspecialchars($t('nav.logout')) ?></button>
                    </li>
                </ul>
            </div>
        </nav>

        <aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">
            <div class="sidebar-brand">
                <a href="<?= $baseUrl ?>/admin" class="brand-link">
                    <img src="<?= $baseUrl ?>/images/logo.svg" class="brand-image opacity-75 shadow" alt="">
                    &nbsp;
                    <span class="brand-text fw-light"><?= htmlspecialchars($appName) ?></span>
                </a>
            </div>
            <div class="sidebar-wrapper">
                <nav class="mt-2">
                    <ul class="nav sidebar-menu flex-column" role="menu">
                        <?php foreach ($adminNavItems as $item): ?>
                            <?php if (($item['visible'] ?? true) === false) { continue; } ?>
                            <li class="nav-item">
                                <a href="<?= htmlspecialchars($item['href']) ?>" class="nav-link<?= $item['key'] === ($adminActiveNav ?? '') ? ' active' : '' ?>">
                                    <i class="nav-icon bi <?= htmlspecialchars($item['icon']) ?>"></i>
                                    <p><?= htmlspecialchars($item['label']) ?></p>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </nav>
            </div>
        </aside>

        <main class="app-main">
            <div class="app-content-header">
                <div class="container-fluid">
                    <h3 class="mb-0"><?= htmlspecialchars($pageTitle ?? '') ?></h3>
                </div>
            </div>
            <div class="app-content">
                <div class="container-fluid">
