function statCardHtml(label, value, icon) {
    return (
        '<div class="col-12 col-sm-6 col-lg-4">' +
        '<div class="card">' +
        '<div class="card-body d-flex align-items-center gap-3">' +
        '<i class="bi ' + icon + ' fs-1 text-primary"></i>' +
        '<div><div class="fs-3 fw-bold">' + escapeHtml(value) + '</div><div class="text-muted">' + escapeHtml(label) + '</div></div>' +
        '</div></div></div>'
    );
}

async function loadDashboardStats() {
    const container = document.getElementById('adminDashboardStats');
    try {
        const stats = await Kochbuch.get('/admin/dashboard-stats');
        container.innerHTML =
            statCardHtml(t('admin.dashboard.recipes'), stats.recipe_count, 'bi-egg-fried') +
            statCardHtml(t('admin.dashboard.users'), stats.user_count, 'bi-people') +
            statCardHtml(t('admin.dashboard.categories'), stats.category_count, 'bi-folder');
    } catch (e) {
        showToast(translateApiError(e.data) || e.message, 'danger');
    }
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: loadDashboardStats });
