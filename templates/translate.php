<?php
$pageTitle = $t('admin.translate.title');
$adminActiveNav = 'translate';
$pageScripts = ['/js/translate.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';
?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
    <input id="translateSearchInput" type="search" class="form-control" style="max-width:20rem">
    <span id="translateDirtyCount" class="text-muted small"></span>
    <button id="translateSaveAllBtn" type="button" class="btn btn-primary ms-auto">
        <i class="bi bi-save"></i> <span data-i18n="admin.translate.save_all"></span>
    </button>
</div>
<div id="translateKeyList"></div>
<?php
require $rootDir . '/templates/partials/admin-shell-footer.php';
