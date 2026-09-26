/**
 * Admin user management (/admin/users, todo.md "Benutzerverwaltung ...
 * Gestaltung übernommen") - structure (list/form single-view toggle inside
 * one card, search-with-icon, collapsible filter-by-right panel, rows-
 * per-page + pagination, rights shown as badges) ported from YTAN's
 * admin-user.js. Two deliberate deviations from YTAN, both kept
 * intentionally rather than copied verbatim:
 *
 * - USER_RIGHT_FIELDS has only 'is_admin' - Kochbuch has none of YTAN's
 *   tour_* rights, and no new Kochbuch-specific rights were requested, so
 *   only the *architecture* (filter panel/badges/per-right description) is
 *   adopted, ready to extend once real additional rights exist.
 * - The action column is a single "⋮" dropdown, not four inline icon
 *   buttons - YTAN's own row (username/email/name/rights/4 icon buttons)
 *   is wider than a 390px mobile viewport, which silently pushes the
 *   buttons off-screen behind a horizontal table scroll (the exact bug
 *   already fixed once in this file's history - see done.md). Kochbuch's
 *   own "Mobile First" requirement (todo.md "Allgemein") takes priority
 *   over pixel-for-pixel fidelity here.
 *
 * Creation is invite-only - there's no password field in the create form
 * at all (see UserController::create()). Unlike YTAN (which only emails
 * the invite/reset link), Kochbuch always shows the generated link inline
 * too, since this dev environment has no guaranteed SMTP setup.
 */
const USER_RIGHT_FIELDS = ['is_admin'];
const USER_RIGHT_LABELS = { is_admin: t('admin.users.is_admin') };
const USER_RIGHT_DESCRIPTIONS = { is_admin: t('admin.users.is_admin_desc') };

let adminUserCurrentUser = null;
let adminUserLastList = [];
let adminUserFilters = {}; // { is_admin: 1 } - AND'ed together
let adminUserFilterPanelOpen = false;
let adminUserSearchQuery = '';
let adminUserPageSize = ADMIN_LIST_DEFAULT_PAGE_SIZE;
let adminUserPage = 1; // 1-indexed, over the filtered result set
let adminUserLastActionMessage = null; // {label, link} shown after invite/send-reset

function setUserAdminHeader(title, backOnClick, actionHtml) {
    document.getElementById('userAdminMenuTitle').textContent = title;
    const backBtn = document.getElementById('userAdminMenuBack');
    backBtn.style.display = backOnClick ? '' : 'none';
    backBtn.onclick = backOnClick || null;
    document.getElementById('userAdminMenuAction').innerHTML = actionHtml || '';
    if (actionHtml) {
        const createBtn = document.getElementById('userCreateBtn');
        if (createBtn) {
            createBtn.addEventListener('click', showUserCreateForm);
        }
    }
}

function showUserList() {
    document.getElementById('userAdminList').hidden = false;
    document.getElementById('userAdminForm').hidden = true;
}

function showUserFormView() {
    document.getElementById('userAdminList').hidden = true;
    document.getElementById('userAdminForm').hidden = false;
}

async function loadUserList() {
    try {
        adminUserLastList = await Kochbuch.get('/users');
        renderUserTable();
    } catch (err) {
        showToast(translateApiError(err.data) || err.message, 'danger');
    }
}

function toggleUserFilter(field) {
    if (Object.prototype.hasOwnProperty.call(adminUserFilters, field)) {
        delete adminUserFilters[field];
    } else {
        adminUserFilters[field] = 1;
    }
    adminUserPage = 1;
    // Full local re-render (not just renderFilteredUserRows()) so the
    // toggle button's active-count badge picks up the change too - cheap
    // since filtering is client-side already, unlike YTAN's server-side
    // per-field query params, which need an actual refetch.
    renderUserTable();
}

function toggleUserFilterPanel() {
    adminUserFilterPanelOpen = !adminUserFilterPanelOpen;
    renderUserTable();
}

function userFilterPanelHtml() {
    const activeCount = Object.keys(adminUserFilters).length;

    let html = '<button type="button" class="btn btn-outline-secondary btn-sm mb-2 d-flex align-items-center gap-2" id="userFilterToggleBtn">' +
        '<i class="bi bi-sliders"></i>' +
        '<span>' + escapeHtml(t('admin.users.filter_by_right')) + (activeCount > 0 ? ' (' + activeCount + ')' : '') + '</span>' +
        '<i class="bi ' + (adminUserFilterPanelOpen ? 'bi-chevron-up' : 'bi-chevron-down') + '"></i>' +
        '</button>';

    if (adminUserFilterPanelOpen) {
        html += '<div class="card card-body mb-3">';
        USER_RIGHT_FIELDS.forEach((field) => {
            const checked = Object.prototype.hasOwnProperty.call(adminUserFilters, field);
            html += '<div class="form-check">' +
                '<input class="form-check-input user-filter-checkbox" type="checkbox" id="userFilter_' + field + '" data-field="' + field + '"' + (checked ? ' checked' : '') + '>' +
                '<label class="form-check-label" for="userFilter_' + field + '">' + escapeHtml(USER_RIGHT_LABELS[field]) + '</label>' +
                '</div>';
        });
        html += '</div>';
    }

    return html;
}

function actionMessageHtml() {
    const msg = adminUserLastActionMessage;
    if (!msg) {
        return '';
    }

    return (
        '<div class="alert alert-info d-flex flex-wrap align-items-center gap-2">' +
        '<span>' + escapeHtml(msg.label) + ':</span>' +
        '<code class="text-break flex-grow-1">' + escapeHtml(msg.link) + '</code>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" id="copyActionLinkBtn">' + escapeHtml(t('admin.users.copy_link')) + '</button>' +
        '</div>'
    );
}

function renderUserTable() {
    setUserAdminHeader(
        t('admin.users.title'),
        null,
        '<button type="button" class="btn btn-primary btn-sm" id="userCreateBtn"><i class="bi bi-plus-lg"></i> ' + escapeHtml(t('admin.users.create')) + '</button>'
    );

    const sizeOptions = ADMIN_LIST_PAGE_SIZES
        .map((size) => '<option value="' + size + '"' + (size === adminUserPageSize ? ' selected' : '') + '>' + size + '</option>')
        .join('');

    let html = actionMessageHtml();
    html += '<div class="input-group mb-3">' +
        '<span class="input-group-text"><i class="bi bi-search"></i></span>' +
        '<input type="search" class="form-control" id="userSearchInput" placeholder="' + escapeHtml(t('admin.users.search_placeholder')) + '" value="' + escapeHtml(adminUserSearchQuery) + '">' +
        '</div>';
    html += userFilterPanelHtml();
    html += '<div class="d-flex align-items-center gap-2 mb-3">' +
        '<label for="userPageSize" class="form-label mb-0 small">' + escapeHtml(t('admin.users.rows_per_page')) + '</label>' +
        '<select id="userPageSize" class="form-select form-select-sm w-auto">' + sizeOptions + '</select>' +
        '</div>';
    html += '<div id="userTableResults"></div>';

    document.getElementById('userAdminList').innerHTML = html;
    showUserList();
    wireUserTableControls();
    renderFilteredUserRows();
}

function wireUserTableControls() {
    const copyBtn = document.getElementById('copyActionLinkBtn');
    if (copyBtn) {
        copyBtn.addEventListener('click', () => {
            navigator.clipboard.writeText(adminUserLastActionMessage.link);
            showToast(t('admin.users.link_copied'));
        });
    }

    document.getElementById('userSearchInput').addEventListener('input', (e) => {
        adminUserSearchQuery = e.target.value;
        adminUserPage = 1;
        renderFilteredUserRows();
    });
    document.getElementById('userFilterToggleBtn').addEventListener('click', toggleUserFilterPanel);
    document.querySelectorAll('.user-filter-checkbox').forEach((checkbox) => {
        checkbox.addEventListener('change', () => toggleUserFilter(checkbox.dataset.field));
    });
    document.getElementById('userPageSize').addEventListener('change', (e) => {
        adminUserPageSize = parseInt(e.target.value, 10);
        adminUserPage = 1;
        renderFilteredUserRows();
    });
}

function userRightBadgesHtml(user) {
    return USER_RIGHT_FIELDS
        .filter((field) => user[field])
        .map((field) => '<span class="badge text-bg-primary me-1">' + escapeHtml(USER_RIGHT_LABELS[field]) + '</span>')
        .join('');
}

function userRowHtml(user) {
    const isSelf = adminUserCurrentUser && adminUserCurrentUser.id === user.id;

    return (
        '<tr>' +
        '<td>' +
        '<div>' + escapeHtml(user.username) + '</div>' +
        '<div class="small text-muted">' + escapeHtml(user.email) + '</div>' +
        '</td>' +
        '<td>' + (userRightBadgesHtml(user) || '&ndash;') + '</td>' +
        '<td class="text-end">' +
        '<div class="dropdown">' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-three-dots-vertical"></i></button>' +
        '<ul class="dropdown-menu dropdown-menu-end">' +
        '<li><button type="button" class="dropdown-item user-edit-btn" data-id="' + user.id + '"><i class="bi bi-pencil"></i> ' + escapeHtml(t('admin.users.edit')) + '</button></li>' +
        '<li><button type="button" class="dropdown-item user-setpw-btn" data-id="' + user.id + '"><i class="bi bi-key"></i> ' + escapeHtml(t('admin.users.set_password')) + '</button></li>' +
        '<li><button type="button" class="dropdown-item user-reset-btn" data-id="' + user.id + '"><i class="bi bi-envelope"></i> ' + escapeHtml(t('admin.users.send_reset')) + '</button></li>' +
        (isSelf ? '' : '<li><button type="button" class="dropdown-item text-danger user-delete-btn" data-id="' + user.id + '"><i class="bi bi-trash"></i> ' + escapeHtml(t('admin.users.delete')) + '</button></li>') +
        '</ul>' +
        '</div>' +
        '</td></tr>'
    );
}

function adminUserPaginationHtml(totalMatches, totalPages) {
    return (
        '<div class="d-flex align-items-center justify-content-center gap-3 mt-2">' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" id="userPagePrevBtn"' + (adminUserPage <= 1 ? ' disabled' : '') + '><i class="bi bi-chevron-left"></i></button>' +
        '<span class="small text-muted">' + (totalMatches === 0 ? escapeHtml(t('admin.users.zero_users')) : escapeHtml(t('admin.users.page_of', { page: adminUserPage, total: totalPages }))) + '</span>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" id="userPageNextBtn"' + (adminUserPage >= totalPages ? ' disabled' : '') + '><i class="bi bi-chevron-right"></i></button>' +
        '</div>'
    );
}

/**
 * Filters (search text + active right filters, AND'ed) and paginates the
 * already-loaded adminUserLastList client-side, then renders just the
 * results table into #userTableResults - kept separate from
 * renderUserTable() so typing in the search box never rebuilds the search
 * input itself (which would steal focus/cursor position).
 */
function renderFilteredUserRows() {
    const query = foldSearchText(adminUserSearchQuery.trim());
    const activeFilterFields = Object.keys(adminUserFilters);

    const allMatches = adminUserLastList.filter((u) => {
        if (activeFilterFields.some((field) => !u[field])) {
            return false;
        }
        if (query === '') {
            return true;
        }

        return foldSearchText(u.username).includes(query) || foldSearchText(u.email || '').includes(query);
    });

    const totalPages = Math.max(1, Math.ceil(allMatches.length / adminUserPageSize));
    adminUserPage = Math.min(Math.max(1, adminUserPage), totalPages);
    const pageStart = (adminUserPage - 1) * adminUserPageSize;
    const users = allMatches.slice(pageStart, pageStart + adminUserPageSize);

    let html;
    if (users.length === 0) {
        html = '<div class="empty-state"><i class="bi bi-people"></i><p class="mb-0">' + escapeHtml(t('admin.users.no_users')) + '</p></div>';
    } else {
        html = '<table class="table align-middle">' +
            '<thead><tr><th>' + escapeHtml(t('admin.users.username')) + '</th><th>' + escapeHtml(t('admin.users.col_rights')) + '</th><th>' + escapeHtml(t('admin.users.col_actions')) + '</th></tr></thead>' +
            '<tbody>' + users.map(userRowHtml).join('') + '</tbody></table>';
    }
    html += adminUserPaginationHtml(allMatches.length, totalPages);

    const results = document.getElementById('userTableResults');
    results.innerHTML = html;

    document.getElementById('userPagePrevBtn').addEventListener('click', () => { adminUserPage -= 1; renderFilteredUserRows(); });
    document.getElementById('userPageNextBtn').addEventListener('click', () => { adminUserPage += 1; renderFilteredUserRows(); });
    results.querySelectorAll('.user-edit-btn').forEach((btn) => btn.addEventListener('click', () => {
        const user = adminUserLastList.find((u) => u.id === parseInt(btn.dataset.id, 10));
        showUserFormFor(user);
    }));
    results.querySelectorAll('.user-setpw-btn').forEach((btn) => btn.addEventListener('click', () => promptSetPassword(parseInt(btn.dataset.id, 10))));
    results.querySelectorAll('.user-reset-btn').forEach((btn) => btn.addEventListener('click', () => sendResetEmail(parseInt(btn.dataset.id, 10))));
    results.querySelectorAll('.user-delete-btn').forEach((btn) => btn.addEventListener('click', () => deleteUser(parseInt(btn.dataset.id, 10))));
}

function formCheckRow(id, checked, label, description) {
    return (
        '<div class="form-check form-switch mb-2">' +
        '<input class="form-check-input" type="checkbox" role="switch" id="' + id + '"' + (checked ? ' checked' : '') + '>' +
        '<label class="form-check-label" for="' + id + '">' + escapeHtml(label) + '</label>' +
        (description ? '<div class="form-text mt-0">' + escapeHtml(description) + '</div>' : '') +
        '</div>'
    );
}

function userFormHtml(user) {
    const isNew = !user;
    const u = user || { id: null, username: '', email: '', is_admin: false };
    const hint = isNew ? '<p class="text-muted small">' + escapeHtml(t('admin.users.invite_hint')) + '</p>' : '';

    return (
        '<div class="mx-auto" style="max-width:26rem">' +
        hint +
        '<div class="mb-3">' +
        '<label for="userFormUsername" class="form-label">' + escapeHtml(t('admin.users.username')) + '</label>' +
        '<input id="userFormUsername" type="text" class="form-control" value="' + escapeHtml(u.username) + '">' +
        '</div>' +
        '<div class="mb-3">' +
        '<label for="userFormEmail" class="form-label">' + escapeHtml(t('admin.users.email')) + '</label>' +
        '<input id="userFormEmail" type="email" class="form-control" value="' + escapeHtml(u.email || '') + '">' +
        '</div>' +
        formCheckRow('userFormIsAdmin', u.is_admin, t('admin.users.is_admin'), USER_RIGHT_DESCRIPTIONS.is_admin) +
        '<div id="userFormError" class="alert alert-danger d-none mt-3"></div>' +
        '<div class="mt-3">' +
        '<button id="userFormSaveBtn" class="btn btn-primary" type="button">' + escapeHtml(t('admin.users.save')) + '</button>&nbsp;' +
        '<button class="btn btn-outline-secondary" type="button" id="userFormCancelBtn">' + escapeHtml(t('admin.users.cancel')) + '</button>' +
        '</div>' +
        '</div>'
    );
}

function showUserCreateForm() {
    setUserAdminHeader(t('admin.users.create'), closeUserForm, '');
    document.getElementById('userAdminForm').innerHTML = userFormHtml(null);
    showUserFormView();
    document.getElementById('userFormSaveBtn').addEventListener('click', () => submitUserForm(null));
    document.getElementById('userFormCancelBtn').addEventListener('click', closeUserForm);
}

function showUserFormFor(user) {
    setUserAdminHeader(t('admin.users.edit'), closeUserForm, '');
    document.getElementById('userAdminForm').innerHTML = userFormHtml(user);
    showUserFormView();
    document.getElementById('userFormSaveBtn').addEventListener('click', () => submitUserForm(user.id));
    document.getElementById('userFormCancelBtn').addEventListener('click', closeUserForm);
}

function closeUserForm() {
    document.getElementById('userAdminForm').innerHTML = '';
    renderUserTable();
}

async function submitUserForm(id) {
    const errorBox = document.getElementById('userFormError');
    errorBox.classList.add('d-none');

    const payload = {
        username: document.getElementById('userFormUsername').value,
        email: document.getElementById('userFormEmail').value,
        is_admin: document.getElementById('userFormIsAdmin').checked,
    };

    try {
        if (id) {
            await Kochbuch.put('/users/' + id, payload);
            showToast(t('admin.users.updated'));
            adminUserLastActionMessage = null;
        } else {
            const result = await Kochbuch.post('/users', payload);
            showToast(t('admin.users.created'));
            adminUserLastActionMessage = { label: t('admin.users.invite_link_label'), link: result.invite_link };
        }
        await loadUserList();
    } catch (err) {
        errorBox.textContent = translateApiError(err.data) || err.message;
        errorBox.classList.remove('d-none');
    }
}

async function promptSetPassword(userId) {
    const password = window.prompt(t('admin.users.new_password'));
    if (!password) {
        return;
    }
    try {
        await Kochbuch.put('/users/' + userId + '/password', { password: password });
        showToast(t('admin.users.password_set'));
    } catch (err) {
        showToast(translateApiError(err.data) || err.message, 'danger');
    }
}

async function sendResetEmail(userId) {
    if (!window.confirm(t('admin.users.send_reset_confirm'))) {
        return;
    }
    try {
        const result = await Kochbuch.post('/users/' + userId + '/send-reset', {});
        adminUserLastActionMessage = { label: t('admin.users.reset_link_label'), link: result.reset_link };
        renderUserTable();
    } catch (err) {
        showToast(translateApiError(err.data) || err.message, 'danger');
    }
}

async function deleteUser(userId) {
    if (!window.confirm(t('admin.users.delete_confirm'))) {
        return;
    }
    try {
        await Kochbuch.del('/users/' + userId);
        showToast(t('admin.users.deleted'));
        await loadUserList();
    } catch (err) {
        showToast(translateApiError(err.data) || err.message, 'danger');
    }
}

function initUserAdmin(user) {
    adminUserCurrentUser = user;
    adminUserFilters = {};
    adminUserFilterPanelOpen = false;
    adminUserSearchQuery = '';
    adminUserPageSize = ADMIN_LIST_DEFAULT_PAGE_SIZE;
    adminUserPage = 1;
    adminUserLastActionMessage = null;
    loadUserList();
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: initUserAdmin });
