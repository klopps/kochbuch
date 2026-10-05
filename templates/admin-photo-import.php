<?php
$pageTitle = $t('admin.photo_import.title');
$adminActiveNav = 'photo-import';
$pageScripts = ['/js/admin-photo-import.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';
?>
<div class="card">
    <div class="card-header">
        <h2 class="h5 mb-0"><?= htmlspecialchars($t('admin.photo_import.title')) ?></h2>
    </div>
    <div class="card-body">
        <p class="text-muted small"><?= htmlspecialchars($t('admin.photo_import.intro')) ?></p>
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label" for="photoImportOwner"><?= htmlspecialchars($t('admin.photo_import.owner')) ?></label>
                <select class="form-select" id="photoImportOwner"></select>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="photoImportVisibility"><?= htmlspecialchars($t('admin.photo_import.visibility')) ?></label>
                <select class="form-select" id="photoImportVisibility">
                    <option value="private"><?= htmlspecialchars($t('recipe.visibility_private')) ?></option>
                    <option value="internal" selected><?= htmlspecialchars($t('recipe.visibility_internal')) ?></option>
                    <option value="public"><?= htmlspecialchars($t('recipe.visibility_public')) ?></option>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label" for="photoImportFiles"><?= htmlspecialchars($t('admin.photo_import.files')) ?></label>
                <input class="form-control" type="file" id="photoImportFiles" accept="image/jpeg,image/png,image/webp" multiple>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <button type="button" class="btn btn-primary" id="photoImportStartBtn"><i class="bi bi-play-fill"></i> <?= htmlspecialchars($t('admin.photo_import.start')) ?></button>
            <button type="button" class="btn btn-outline-secondary d-none" id="photoImportStopBtn"><i class="bi bi-stop-fill"></i> <?= htmlspecialchars($t('admin.photo_import.stop')) ?></button>
        </div>
        <div id="photoImportSummary" class="mb-2"></div>
        <div id="photoImportList"></div>
    </div>
</div>
<?php
require $rootDir . '/templates/partials/admin-shell-footer.php';
