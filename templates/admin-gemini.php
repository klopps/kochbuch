<?php
$pageTitle = $t('admin.gemini.title');
$adminActiveNav = 'gemini';
$pageScripts = ['/js/admin-gemini.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';
?>
<div class="card">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <h2 class="h5 mb-0 me-auto"><?= htmlspecialchars($t('admin.gemini.title')) ?></h2>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="geminiReloadBtn"><i class="bi bi-arrow-clockwise"></i> <?= htmlspecialchars($t('admin.gemini.reload')) ?></button>
        <button type="button" class="btn btn-sm btn-outline-danger" id="geminiResetBtn"><i class="bi bi-eraser"></i> <?= htmlspecialchars($t('admin.gemini.reset')) ?></button>
    </div>
    <div class="card-body">
        <p class="text-muted small"><?= htmlspecialchars($t('admin.gemini.intro')) ?></p>
        <div id="geminiQuota"></div>
    </div>
</div>
<?php
require $rootDir . '/templates/partials/admin-shell-footer.php';
