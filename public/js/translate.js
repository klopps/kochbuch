/**
 * Admin translation editor (/admin/translate, todo.md "Admin-Oberfläche" ->
 * Übersetzungen), mirroring YTAN's translate.js. Keys are grouped by
 * namespace (the part of the key before the first ".") in collapsible
 * <details>, each key showing DE/EN as two stacked textareas (a Bootstrap
 * `col-md-6` pair - two columns is nowhere near as prone to mobile
 * clipping as the ingredient-row bug CLAUDE.md documents, but they still
 * stack to full width below md).
 *
 * Edits are tracked in `dirty` (not written back into `en`/`de` until
 * saveAll()) and applied to individual <textarea> elements directly on
 * "input" rather than through a full re-render, so typing doesn't lose
 * cursor position/focus; renderTranslateList() itself is only called on
 * load and on search-filter changes.
 */
const translateState = {
    en: {},
    de: {},
    usage: {},
    dirty: {},
    filterText: '',
};

function cssEscapeKey(value) {
    return window.CSS && CSS.escape ? CSS.escape(value) : value.replace(/[^a-zA-Z0-9_-]/g, '\\$&');
}

function groupKeysByNamespace(keys) {
    const groups = {};
    keys.forEach((key) => {
        const ns = key.split('.')[0];
        (groups[ns] = groups[ns] || []).push(key);
    });

    return groups;
}

function currentValue(key, locale) {
    if (translateState.dirty[key]) {
        return translateState.dirty[key][locale];
    }

    return (locale === 'en' ? translateState.en : translateState.de)[key] || '';
}

function keyMatchesFilter(key) {
    const q = translateState.filterText.trim().toLowerCase();
    if (!q) {
        return true;
    }

    return key.toLowerCase().includes(q)
        || currentValue(key, 'en').toLowerCase().includes(q)
        || currentValue(key, 'de').toLowerCase().includes(q);
}

function keyRowHtml(key) {
    const used = translateState.usage[key] && translateState.usage[key].length > 0;

    return (
        '<div class="border-bottom py-2" data-key-row="' + escapeHtml(key) + '">' +
        '<div class="d-flex align-items-center gap-2 mb-1">' +
        '<code class="small">' + escapeHtml(key) + '</code>' +
        (used ? '' : '<span class="badge text-bg-secondary">' + escapeHtml(t('admin.translate.unused')) + '</span>') +
        '</div>' +
        '<div class="row g-2">' +
        '<div class="col-12 col-md-6">' +
        '<label class="form-label small text-muted">DE</label>' +
        '<textarea class="form-control form-control-sm translate-field" data-key="' + escapeHtml(key) + '" data-locale="de" rows="2">' + escapeHtml(currentValue(key, 'de')) + '</textarea>' +
        '</div>' +
        '<div class="col-12 col-md-6">' +
        '<label class="form-label small text-muted">EN</label>' +
        '<textarea class="form-control form-control-sm translate-field" data-key="' + escapeHtml(key) + '" data-locale="en" rows="2">' + escapeHtml(currentValue(key, 'en')) + '</textarea>' +
        '</div>' +
        '</div>' +
        '</div>'
    );
}

function namespaceGroupHtml(namespace, keys) {
    const visibleKeys = keys.filter(keyMatchesFilter);
    if (visibleKeys.length === 0) {
        return '';
    }

    return (
        '<details class="mb-2" open>' +
        '<summary class="fw-bold py-1">' + escapeHtml(namespace) + ' <span class="text-muted small">(' + visibleKeys.length + ')</span></summary>' +
        visibleKeys.map(keyRowHtml).join('') +
        '</details>'
    );
}

function updateDirtyCount() {
    const count = Object.keys(translateState.dirty).length;
    document.getElementById('translateDirtyCount').textContent = count > 0 ? t('admin.translate.dirty_count', { count: count }) : '';
}

function renderTranslateList() {
    const allKeys = Object.keys(Object.assign({}, translateState.en, translateState.de)).sort();
    const groups = groupKeysByNamespace(allKeys);
    const namespaces = Object.keys(groups).sort();
    const html = namespaces.map((ns) => namespaceGroupHtml(ns, groups[ns])).join('');

    document.getElementById('translateKeyList').innerHTML = html || '<p class="text-muted">' + escapeHtml(t('admin.translate.no_matches')) + '</p>';

    document.querySelectorAll('[data-key-row]').forEach((row) => {
        if (translateState.dirty[row.dataset.keyRow]) {
            row.classList.add('bg-warning-subtle');
        }
    });

    document.querySelectorAll('.translate-field').forEach((field) => {
        field.addEventListener('input', () => {
            const key = field.dataset.key;
            if (!translateState.dirty[key]) {
                translateState.dirty[key] = { en: translateState.en[key] || '', de: translateState.de[key] || '' };
            }
            translateState.dirty[key][field.dataset.locale] = field.value;
            const row = document.querySelector('[data-key-row="' + cssEscapeKey(key) + '"]');
            if (row) {
                row.classList.add('bg-warning-subtle');
            }
            updateDirtyCount();
        });
    });

    updateDirtyCount();
}

async function loadTranslations() {
    const result = await Kochbuch.get('/translations');
    translateState.en = result.en;
    translateState.de = result.de;
    translateState.usage = result.usage;
    translateState.dirty = {};
    renderTranslateList();
}

async function saveAll(force) {
    const en = Object.assign({}, translateState.en);
    const de = Object.assign({}, translateState.de);
    Object.keys(translateState.dirty).forEach((key) => {
        en[key] = translateState.dirty[key].en;
        de[key] = translateState.dirty[key].de;
    });

    try {
        await Kochbuch.put('/translations', { en: en, de: de, force: !!force });
        showToast(t('admin.translate.saved'));
        await loadTranslations();
    } catch (err) {
        if (err.data && err.data.code === 'translation.key_mismatch') {
            const onlyEn = (err.data.only_in_en || []).join(', ') || t('admin.translate.none');
            const onlyDe = (err.data.only_in_de || []).join(', ') || t('admin.translate.none');
            if (window.confirm(t('admin.translate.key_mismatch_message', { only_en: onlyEn, only_de: onlyDe }))) {
                await saveAll(true);
            }
        } else {
            showToast(t('admin.translate.save_failed', { error: translateApiError(err.data) || err.message }), 'danger');
        }
    }
}

async function initTranslatePage() {
    document.getElementById('translateSearchInput').placeholder = t('admin.translate.search_placeholder');
    await loadTranslations();

    document.getElementById('translateSearchInput').addEventListener('input', (e) => {
        translateState.filterText = e.target.value;
        renderTranslateList();
    });
    document.getElementById('translateSaveAllBtn').addEventListener('click', () => saveAll(false));
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: initTranslatePage });
