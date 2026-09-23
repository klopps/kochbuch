function renderNotFound() {
    document.getElementById('app').innerHTML =
        '<div class="empty-state"><i class="bi bi-question-circle"></i><p class="mb-2">' + escapeHtml(t('error.not_found')) + '</p>' +
        '<a href="#/recipes" class="btn btn-outline-secondary btn-sm">' + escapeHtml(t('nav.recipes')) + '</a></div>';
}
