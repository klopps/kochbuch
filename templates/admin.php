<?php
$pageTitle = $t('admin.nav.dashboard');
$adminActiveNav = 'dashboard';
$pageScripts = ['/js/admin-dashboard.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';
?>
<div class="row g-3" id="adminDashboardStats"></div>
<?php
require $rootDir . '/templates/partials/admin-shell-footer.php';
