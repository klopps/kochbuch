<?php
$pageTitle = $t('admin.settings.title');
$adminActiveNav = 'settings';
$pageScripts = ['/js/admin-settings.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';
?>
<div class="card" style="max-width:32rem">
    <div class="card-body">
        <form id="settingsForm">
            <div class="mb-3">
                <label class="form-label" data-i18n="admin.settings.app_name"></label>
                <input id="settingAppName" type="text" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label" data-i18n="admin.settings.default_locale"></label>
                <select id="settingDefaultLocale" class="form-select">
                    <option value="de">Deutsch</option>
                    <option value="en">English</option>
                </select>
            </div>
            <div class="mb-3 form-check form-switch">
                <input id="settingTranslateToolEnabled" type="checkbox" class="form-check-input" role="switch">
                <label class="form-check-label" for="settingTranslateToolEnabled" data-i18n="admin.settings.translate_tool_enabled"></label>
            </div>
            <div class="mb-3">
                <label class="form-label" data-i18n="admin.settings.page_sizes"></label>
                <input id="settingPageSizes" type="text" class="form-control" placeholder="10,20,100" required>
                <div class="form-text" data-i18n="admin.settings.page_sizes_hint"></div>
            </div>
            <div class="mb-3">
                <label class="form-label" data-i18n="admin.settings.default_page_size"></label>
                <select id="settingDefaultPageSize" class="form-select"></select>
            </div>
            <div class="mb-3">
                <label class="form-label" data-i18n="admin.settings.default_font_scale_desktop"></label>
                <input id="settingFontScaleDesktop" type="number" class="form-control" min="50" max="150" step="5" required>
            </div>
            <div class="mb-3">
                <label class="form-label" data-i18n="admin.settings.default_font_scale_mobile"></label>
                <input id="settingFontScaleMobile" type="number" class="form-control" min="50" max="150" step="5" required>
            </div>
            <div id="settingsError" class="alert alert-danger d-none"></div>
            <button type="submit" class="btn btn-primary" data-i18n="admin.settings.save"></button>
        </form>
    </div>
</div>
<?php
require $rootDir . '/templates/partials/admin-shell-footer.php';
