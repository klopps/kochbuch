<?php
$pageTitle = $t('admin.quickeditor.title');
$adminActiveNav = 'quickeditor';
$pageScripts = ['/js/admin-quickeditor.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';
?>
<div class="card">
    <div class="card-body">
        <div id="adminQuickEditorFilters" class="mb-3"></div>
        <div id="adminQuickEditorResults"></div>
        <nav id="adminQuickEditorPagination" class="mt-3"></nav>
    </div>
</div>
<?php
require $rootDir . '/templates/partials/admin-shell-footer.php';
