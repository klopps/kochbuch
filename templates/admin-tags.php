<?php
$pageTitle = $t('admin.tags.title');
$adminActiveNav = 'tags';
$pageScripts = ['/js/admin-tags.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';
?>
<div class="card">
    <div class="card-body">
        <div id="adminTagsFilters" class="mb-3"></div>
        <div id="adminTagsResults"></div>
        <nav id="adminTagsPagination" class="mt-3"></nav>
    </div>
</div>
<?php
require $rootDir . '/templates/partials/admin-shell-footer.php';
