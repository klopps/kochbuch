function parsePageSizesInput(value) {
    return value.split(',')
        .map((s) => parseInt(s.trim(), 10))
        .filter((n) => Number.isInteger(n) && n > 0);
}

function rebuildDefaultPageSizeOptions(pageSizes, selected) {
    const select = document.getElementById('settingDefaultPageSize');
    select.innerHTML = pageSizes
        .map((size) => '<option value="' + size + '"' + (size === selected ? ' selected' : '') + '>' + size + '</option>')
        .join('');
}

async function loadSettings() {
    const settings = await Kochbuch.get('/admin/settings');
    document.getElementById('settingAppName').value = settings.app_name;
    document.getElementById('settingDefaultLocale').value = settings.default_locale;
    document.getElementById('settingPageSizes').value = settings.recipe_page_sizes.join(',');
    rebuildDefaultPageSizeOptions(settings.recipe_page_sizes, settings.recipe_default_page_size);
    document.getElementById('settingFontScaleDesktop').value = settings.default_font_scale_desktop;
    document.getElementById('settingFontScaleMobile').value = settings.default_font_scale_mobile;

    document.getElementById('settingPageSizes').addEventListener('input', () => {
        const sizes = parsePageSizesInput(document.getElementById('settingPageSizes').value);
        const currentDefault = parseInt(document.getElementById('settingDefaultPageSize').value, 10) || sizes[0];
        rebuildDefaultPageSizeOptions(sizes, sizes.includes(currentDefault) ? currentDefault : sizes[0]);
    });
}

async function initSettingsPage() {
    await loadSettings();

    document.getElementById('settingsForm').addEventListener('submit', async (e) => {
        e.preventDefault();

        // todo.md "Loading Indicator During Longer Processes"
        await withBusyButton(e.submitter, async () => {
            await Kochbuch.put('/admin/settings', {
                app_name: document.getElementById('settingAppName').value,
                default_locale: document.getElementById('settingDefaultLocale').value,
                recipe_page_sizes: parsePageSizesInput(document.getElementById('settingPageSizes').value),
                recipe_default_page_size: parseInt(document.getElementById('settingDefaultPageSize').value, 10),
                default_font_scale_desktop: parseInt(document.getElementById('settingFontScaleDesktop').value, 10),
                default_font_scale_mobile: parseInt(document.getElementById('settingFontScaleMobile').value, 10),
            });
            showToast(t('admin.settings.saved'));
        });
    });
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: initSettingsPage });
