<?php
$pageTitle = $t('admin.chefkoch_import.title');
$adminActiveNav = 'chefkoch-import';
$pageScripts = ['/js/admin-chefkoch-import.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';
?>
<div class="card">
    <div class="card-body">
        <div id="chefkochImportLoginForm"></div>
        <div id="chefkochImportResults" class="mt-3"></div>
    </div>
</div>
<?php
require $rootDir . '/templates/partials/admin-shell-footer.php';
