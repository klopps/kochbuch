/**
 * Admin "KI-Kontingent" page (/admin/gemini; todo.md "Gemini-Ratenlimits
 * erkennen"): shows GeminiQuotaState's remembered state per configured
 * model - available, or exhausted by its per-minute / per-day quota and
 * until when - plus today's request counters (a "Gemini day" runs from
 * midnight to midnight Pacific time, when the daily quotas reset).
 * "Zurücksetzen" forgets the remembered state (confirm first, same
 * window.confirm() convention as admin-deleted-recipes.js).
 */
async function initAdminGemini() {
    document.getElementById('geminiReloadBtn').addEventListener('click', loadGeminiQuota);
    document.getElementById('geminiResetBtn').addEventListener('click', resetGeminiQuota);
    loadGeminiQuota();
}

async function loadGeminiQuota() {
    const box = document.getElementById('geminiQuota');
    box.innerHTML = '<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm"></div></div>';

    try {
        renderGeminiQuota(await Kochbuch.get('/admin/gemini-quota'));
    } catch (err) {
        box.innerHTML = '<div class="alert alert-danger">' + escapeHtml(translateApiError(err.data) || err.message) + '</div>';
    }
}

function geminiFormatTime(iso) {
    return iso ? new Date(iso).toLocaleString(window.KOCHBUCH_LOCALE, { dateStyle: 'short', timeStyle: 'short' }) : '–';
}

function geminiStatusHtml(row) {
    if (row.status === 'day') {
        return '<span class="badge text-bg-danger">' + escapeHtml(t('admin.gemini.status_day')) + '</span>' +
            '<div class="small text-muted">' + escapeHtml(t('admin.gemini.until', { time: geminiFormatTime(row.exhausted_until) })) + '</div>';
    }
    if (row.status === 'minute') {
        return '<span class="badge text-bg-warning">' + escapeHtml(t('admin.gemini.status_minute')) + '</span>' +
            '<div class="small text-muted">' + escapeHtml(t('admin.gemini.until', { time: geminiFormatTime(row.exhausted_until) })) + '</div>';
    }

    return '<span class="badge text-bg-success">' + escapeHtml(t('admin.gemini.status_available')) + '</span>';
}

function renderGeminiQuota(data) {
    const box = document.getElementById('geminiQuota');
    const warning = data.key_configured
        ? ''
        : '<div class="alert alert-warning">' + escapeHtml(t('admin.gemini.no_key')) + '</div>';

    box.innerHTML = warning +
        '<p class="small mb-3">' + escapeHtml(t('admin.gemini.next_reset', { time: geminiFormatTime(data.next_daily_reset) })) + '</p>' +
        '<div class="table-responsive"><table class="table align-middle">' +
        '<thead><tr>' +
        '<th>' + escapeHtml(t('admin.gemini.col_model')) + '</th>' +
        '<th>' + escapeHtml(t('admin.gemini.col_status')) + '</th>' +
        '<th class="text-end">' + escapeHtml(t('admin.gemini.col_today')) + '</th>' +
        '<th>' + escapeHtml(t('admin.gemini.col_last_success')) + '</th>' +
        '<th>' + escapeHtml(t('admin.gemini.col_last_limit')) + '</th>' +
        '</tr></thead><tbody>' +
        data.models.map((row) => (
            '<tr' + (row.configured ? '' : ' class="text-muted"') + '>' +
            '<td><code>' + escapeHtml(row.model) + '</code>' + (row.configured ? '' : '<div class="small">' + escapeHtml(t('admin.gemini.not_configured')) + '</div>') + '</td>' +
            '<td>' + geminiStatusHtml(row) + '</td>' +
            '<td class="text-end">' + escapeHtml(t('admin.gemini.today_counts', { requests: row.requests_today, successes: row.successes_today })) + '</td>' +
            '<td>' + escapeHtml(geminiFormatTime(row.last_success_at)) + '</td>' +
            '<td>' + escapeHtml(geminiFormatTime(row.last_limit_at)) +
            (row.last_limit_kind ? ' <span class="small text-muted">(' + escapeHtml(t(row.last_limit_kind === 'day' ? 'admin.gemini.status_day' : 'admin.gemini.status_minute')) + ')</span>' : '') +
            '</td>' +
            '</tr>'
        )).join('') +
        '</tbody></table></div>';
}

async function resetGeminiQuota() {
    if (!window.confirm(t('admin.gemini.reset_confirm'))) {
        return;
    }
    try {
        renderGeminiQuota(await Kochbuch.del('/admin/gemini-quota'));
        showToast(t('admin.gemini.reset_done'));
    } catch (err) {
        showToast(translateApiError(err.data) || err.message, 'danger');
    }
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: initAdminGemini });
