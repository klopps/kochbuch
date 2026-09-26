/**
 * Populates the admin shell's navbar once initAdminAuth() confirms the
 * current user is an admin, and wires the logout button - shared by every
 * /admin/* page's own JS file (mirrors YTAN's admin-common.js).
 */
function adminOnAuthenticated(user) {
    const nameEl = document.getElementById('adminUserName');
    if (nameEl) {
        nameEl.textContent = user.username;
    }

    document.getElementById('adminLogoutBtn').addEventListener('click', () => {
        Kochbuch.setToken(null);
        window.location.reload();
    });
}
