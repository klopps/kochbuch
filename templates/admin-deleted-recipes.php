<?php
$pageTitle = $t('admin.deleted_recipes.title');
$adminActiveNav = 'deleted-recipes';
$pageScripts = ['/js/admin-deleted-recipes.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';
?>
<div class="card">
    <div class="card-header">
        <h2 class="h5 mb-0"><?= htmlspecialchars($t('admin.deleted_recipes.title')) ?></h2>
    </div>
    <div class="card-body">
        <p class="text-muted small"><?= htmlspecialchars($t('admin.deleted_recipes.intro')) ?></p>
        <div id="deletedRecipesList"></div>
    </div>
</div>
<?php
require $rootDir . '/templates/partials/admin-shell-footer.php';
