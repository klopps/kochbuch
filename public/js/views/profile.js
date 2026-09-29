/**
 * Self-service "Profile" screen (route "/profile", todo.md "Changing your
 * own user details") - lets a logged-in user edit their own firstname/
 * lastname/username/email and change their password, mirroring YTAN's
 * profile drawer screen (AuthController::updateProfile()/changePassword()).
 * Unlike YTAN's nav-drawer-stack UI, this is a regular routed view, same
 * pattern as every other public/js/views/*.js screen.
 */
async function renderProfile() {
    const app = document.getElementById('app');

    if (!Kochbuch.isLoggedIn()) {
        Router.navigate('/login');

        return;
    }

    app.innerHTML = '<div class="text-center text-muted py-5"><div class="spinner-border" role="status"></div></div>';

    await loadCurrentUser();
    if (!currentUser) {
        Router.navigate('/login');

        return;
    }

    renderProfileForm(currentUser);
}

function renderProfileForm(user) {
    const app = document.getElementById('app');

    const pendingEmailNotice = user.pending_email
        ? '<div class="alert alert-warning d-flex justify-content-between align-items-center gap-2">' +
          '<span>' + escapeHtml(t('profile.pending_email_notice', { email: user.pending_email })) + '</span>' +
          '<button type="button" class="btn btn-sm btn-outline-secondary" id="cancelPendingEmailBtn">' + escapeHtml(t('common.cancel')) + '</button>' +
          '</div>'
        : '';

    app.innerHTML =
        '<div class="mx-auto" style="max-width:28rem">' +
        '<div class="d-flex justify-content-between align-items-center mb-4">' +
        '<h1 class="h3 mb-0">' + escapeHtml(t('profile.title')) + '</h1>' +
        '<button type="button" class="btn-close" id="closeProfileBtn" aria-label="' + escapeHtml(t('common.close')) + '"></button>' +
        '</div>' +

        pendingEmailNotice +

        '<h2 class="h5 mb-3">' + escapeHtml(t('profile.edit_heading')) + '</h2>' +
        '<form id="editProfileForm" class="mb-5">' +
        '<div class="mb-3"><label class="form-label">' + escapeHtml(t('profile.firstname')) + '</label>' +
        '<input type="text" class="form-control" id="profileFirstname" value="' + escapeHtml(user.firstname || '') + '" required></div>' +
        '<div class="mb-3"><label class="form-label">' + escapeHtml(t('profile.lastname')) + '</label>' +
        '<input type="text" class="form-control" id="profileLastname" value="' + escapeHtml(user.lastname || '') + '" required></div>' +
        '<div class="mb-3"><label class="form-label">' + escapeHtml(t('auth.username')) + '</label>' +
        '<input type="text" class="form-control" id="profileUsername" value="' + escapeHtml(user.username || '') + '" required></div>' +
        '<div class="mb-3"><label class="form-label">' + escapeHtml(t('profile.email')) + '</label>' +
        '<input type="email" class="form-control" id="profileEmail" value="' + escapeHtml(user.email || '') + '" required></div>' +
        '<div class="mb-3"><label class="form-label">' + escapeHtml(t('auth.current_password')) + '</label>' +
        passwordInputHtml('profileCurrentPassword', ' autocomplete="current-password" required') + '</div>' +
        '<div id="editProfileError" class="alert alert-danger d-none"></div>' +
        '<button type="submit" class="btn btn-primary">' + escapeHtml(t('profile.save_button')) + '</button>' +
        '</form>' +

        '<h2 class="h5 mb-3">' + escapeHtml(t('profile.change_password_heading')) + '</h2>' +
        '<form id="changePasswordForm">' +
        '<div class="mb-3"><label class="form-label">' + escapeHtml(t('auth.current_password')) + '</label>' +
        passwordInputHtml('changePasswordCurrent', ' autocomplete="current-password" required') + '</div>' +
        '<div class="mb-3"><label class="form-label">' + escapeHtml(t('auth.new_password')) + '</label>' +
        passwordInputHtml('changePasswordNew', ' autocomplete="new-password" required') + '</div>' +
        '<div class="mb-3"><label class="form-label">' + escapeHtml(t('auth.confirm_password')) + '</label>' +
        passwordInputHtml('changePasswordConfirm', ' autocomplete="new-password" required') + '</div>' +
        '<div id="changePasswordError" class="alert alert-danger d-none"></div>' +
        '<button type="submit" class="btn btn-primary">' + escapeHtml(t('auth.change_password_submit')) + '</button>' +
        '</form>' +
        '</div>';

    wireEditProfileForm();
    wireProfileChangePasswordForm();

    document.getElementById('closeProfileBtn').addEventListener('click', () => {
        Router.navigate('/recipes');
    });

    const cancelBtn = document.getElementById('cancelPendingEmailBtn');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', async () => {
            try {
                await Kochbuch.del('/auth/email-change');
                showToast(t('profile.pending_email_change_canceled'));
                renderProfile();
            } catch (err) {
                showToast(translateApiError(err.data) || err.message, 'danger');
            }
        });
    }
}

function wireEditProfileForm() {
    document.getElementById('editProfileForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const errorBox = document.getElementById('editProfileError');
        errorBox.classList.add('d-none');

        const payload = {
            firstname: document.getElementById('profileFirstname').value.trim(),
            lastname: document.getElementById('profileLastname').value.trim(),
            username: document.getElementById('profileUsername').value.trim(),
            email: document.getElementById('profileEmail').value.trim(),
            current_password: document.getElementById('profileCurrentPassword').value,
        };

        try {
            const result = await Kochbuch.put('/auth/profile', payload);
            Kochbuch.setToken(result.token);
            await loadCurrentUser();
            renderNav();

            showToast(
                result.email_change_pending
                    ? t('profile.pending_email_notice', { email: result.pending_email })
                    : t('profile.updated_success')
            );

            renderProfileForm(currentUser);
        } catch (err) {
            errorBox.textContent = translateApiError(err.data) || err.message;
            errorBox.classList.remove('d-none');
        }
    });
}

function wireProfileChangePasswordForm() {
    document.getElementById('changePasswordForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const errorBox = document.getElementById('changePasswordError');
        errorBox.classList.add('d-none');

        const currentPassword = document.getElementById('changePasswordCurrent').value;
        const newPassword = document.getElementById('changePasswordNew').value;
        const confirmPassword = document.getElementById('changePasswordConfirm').value;

        if (newPassword !== confirmPassword) {
            errorBox.textContent = t('auth.passwords_do_not_match');
            errorBox.classList.remove('d-none');

            return;
        }

        try {
            await Kochbuch.put('/auth/password', { current_password: currentPassword, new_password: newPassword });
            showToast(t('auth.password_changed_success'));
            document.getElementById('changePasswordForm').reset();
        } catch (err) {
            errorBox.textContent = translateApiError(err.data) || err.message;
            errorBox.classList.remove('d-none');
        }
    });
}
