<?php
$pageTitle = $t('admin.placeholder_images.title');
$adminActiveNav = 'placeholder-images';
$pageScripts = ['/js/admin-placeholder-images.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';
?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center" id="placeholderImageMenuHeader">
        <h2 class="h5 mb-0"><?= htmlspecialchars($t('admin.placeholder_images.title')) ?></h2>
        <div id="placeholderImageMenuAction"></div>
    </div>
    <div class="card-body">
        <p class="text-muted small"><?= htmlspecialchars($t('admin.placeholder_images.intro')) ?></p>
        <div id="placeholderImageForm"></div>
        <div id="placeholderImageList"></div>
    </div>
</div>
<?php
require $rootDir . '/templates/partials/admin-shell-footer.php';
