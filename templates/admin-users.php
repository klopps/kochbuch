<?php
$pageTitle = $t('admin.users.title');
$adminActiveNav = 'users';
$pageScripts = ['/js/admin-user.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';
?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center" id="userAdminMenuHeader">
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="userAdminMenuBack" style="display:none;"><i class="bi bi-arrow-left"></i></button>
            <h2 class="h5 mb-0" id="userAdminMenuTitle"></h2>
        </div>
        <div id="userAdminMenuAction"></div>
    </div>
    <div class="card-body">
        <div id="userAdminForm"></div>
        <div id="userAdminList"></div>
    </div>
</div>
<?php
require $rootDir . '/templates/partials/admin-shell-footer.php';
