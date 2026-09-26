/**
 * Whether the inline "change password" form (see changePasswordFormHtml())
 * is currently expanded - a module-level flag rather than component state,
 * since renderNav() rebuilds #navAuthArea's innerHTML from scratch on
 * every call (login/logout, theme label refresh, ...) and would otherwise
 * silently collapse an open form on any unrelated re-render.
 */
let changePasswordFormOpen = false;

/**
 * Updates the dynamic parts of the navbar (offcanvas auth area, theme
 * toggle label) - the static structure (brand, links, offcanvas shell)
 * lives in templates/app.php itself.
 */
function renderNav() {
    const authArea = document.getElementById('navAuthArea');
    if (!authArea) {
        return;
    }

    if (currentUser) {
        // /admin is a separate server-rendered page (its own AdminLTE
        // shell), not part of this SPA - a plain href leaving the SPA,
        // same as login.js's forgot-password link.
        const adminLinkHtml = currentUser.is_admin
            ? '<a href="' + window.KOCHBUCH_API_BASE.replace(/\/api\/v1$/, '') + '/admin" class="btn btn-outline-secondary btn-sm"><i class="bi bi-gear"></i> ' + escapeHtml(t('nav.admin')) + '</a>'
            : '';

        authArea.innerHTML =
            '<div class="small text-muted mb-1"><i class="bi bi-person-circle"></i> ' + escapeHtml(currentUser.username) + '</div>' +
            '<a href="#/recipes?mine=1" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="offcanvas">' + escapeHtml(t('recipe.my_recipes')) + '</a>' +
            '<button type="button" class="btn btn-outline-secondary btn-sm" id="changePasswordToggleBtn">' + escapeHtml(t('nav.change_password')) + '</button>' +
            (changePasswordFormOpen ? changePasswordFormHtml() : '') +
            adminLinkHtml +
            '<button type="button" class="btn btn-outline-secondary btn-sm" id="logoutBtn">' + escapeHtml(t('nav.logout')) + '</button>';

        document.getElementById('logoutBtn').addEventListener('click', () => {
            Kochbuch.setToken(null);
            currentUser = null;
            renderNav();
            Router.navigate('/recipes');
        });
        wireChangePasswordForm();
    } else {
        changePasswordFormOpen = false;
        authArea.innerHTML =
            '<a href="#/login" class="btn btn-primary btn-sm" data-bs-dismiss="offcanvas">' + escapeHtml(t('nav.login')) + '</a>';
    }

    updateThemeToggleLabel();
}

/**
 * Self-service password change (todo.md: users need this without going
 * through the forgot-password flow) - an inline form in the drawer rather
 * than a separate page, since that's the only place a logged-in user's own
 * account actions already live (mirrors YTAN's inline nav "change
 * password" expand, minus the dedicated profile page Kochbuch doesn't have).
 */
function changePasswordFormHtml() {
    return (
        '<div class="border rounded p-2 mb-2">' +
        '<div class="mb-2"><label class="form-label small mb-1">' + escapeHtml(t('auth.current_password')) + '</label>' +
        passwordInputHtml('changePasswordCurrent', ' autocomplete="current-password"') + '</div>' +
        '<div class="mb-2"><label class="form-label small mb-1">' + escapeHtml(t('auth.new_password')) + '</label>' +
        passwordInputHtml('changePasswordNew', ' autocomplete="new-password"') + '</div>' +
        '<div class="mb-2"><label class="form-label small mb-1">' + escapeHtml(t('auth.confirm_password')) + '</label>' +
        passwordInputHtml('changePasswordConfirm', ' autocomplete="new-password"') + '</div>' +
        '<div id="changePasswordError" class="alert alert-danger py-1 px-2 small d-none mb-2"></div>' +
        '<div class="d-flex gap-2">' +
        '<button type="button" class="btn btn-primary btn-sm" id="changePasswordSubmitBtn">' + escapeHtml(t('auth.change_password_submit')) + '</button>' +
        '<button type="button" class="btn btn-outline-secondary btn-sm" id="changePasswordCancelBtn">' + escapeHtml(t('common.cancel')) + '</button>' +
        '</div>' +
        '</div>'
    );
}

function wireChangePasswordForm() {
    document.getElementById('changePasswordToggleBtn').addEventListener('click', () => {
        changePasswordFormOpen = !changePasswordFormOpen;
        renderNav();
    });

    if (!changePasswordFormOpen) {
        return;
    }

    document.getElementById('changePasswordCancelBtn').addEventListener('click', () => {
        changePasswordFormOpen = false;
        renderNav();
    });

    document.getElementById('changePasswordSubmitBtn').addEventListener('click', async () => {
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
            changePasswordFormOpen = false;
            renderNav();
            showToast(t('auth.password_changed_success'));
        } catch (err) {
            errorBox.textContent = translateApiError(err.data) || err.message;
            errorBox.classList.remove('d-none');
        }
    });
}

function updateThemeToggleLabel() {
    const label = document.getElementById('themeToggleLabel');
    const icon = document.getElementById('themeToggleIcon');
    if (!label) {
        return;
    }
    const isDark = loadSettings().theme === 'dark';
    label.textContent = isDark ? t('theme.light') : t('theme.dark');
    if (icon) {
        icon.className = 'bi ' + (isDark ? 'bi-sun' : 'bi-moon-stars');
    }
}

function wireThemeToggle() {
    const btn = document.getElementById('themeToggleBtn');
    if (!btn) {
        return;
    }
    btn.addEventListener('click', () => {
        const next = loadSettings().theme === 'dark' ? 'light' : 'dark';
        setTheme(next);
        updateThemeToggleLabel();
    });
}

function updateFontScaleLabels() {
    const desktopRange = document.getElementById('fontScaleDesktopRange');
    const mobileRange = document.getElementById('fontScaleMobileRange');
    const desktopValue = document.getElementById('fontScaleDesktopValue');
    const mobileValue = document.getElementById('fontScaleMobileValue');
    if (desktopValue && desktopRange) {
        desktopValue.textContent = desktopRange.value + '%';
    }
    if (mobileValue && mobileRange) {
        mobileValue.textContent = mobileRange.value + '%';
    }
}

function wireFontScaleControls() {
    const desktopRange = document.getElementById('fontScaleDesktopRange');
    const mobileRange = document.getElementById('fontScaleMobileRange');
    if (!desktopRange || !mobileRange) {
        return;
    }

    const settings = loadSettings();
    desktopRange.value = settings.fontScaleDesktop;
    mobileRange.value = settings.fontScaleMobile;
    updateFontScaleLabels();

    desktopRange.addEventListener('input', () => {
        setFontScale('desktop', parseInt(desktopRange.value, 10));
        updateFontScaleLabels();
    });
    mobileRange.addEventListener('input', () => {
        setFontScale('mobile', parseInt(mobileRange.value, 10));
        updateFontScaleLabels();
    });
}

function wireLanguageSwitcher() {
    const buttons = document.querySelectorAll('.lang-btn');
    if (!buttons.length) {
        return;
    }
    const active = window.KOCHBUCH_LOCALE;
    buttons.forEach((btn) => {
        btn.classList.toggle('active', btn.dataset.lang === active);
        btn.addEventListener('click', () => setLanguage(btn.dataset.lang));
    });
}

/**
 * A hidden "hard refresh" gesture on the drawer logo: four taps/clicks in a
 * row force a real full-page reload rather than a SPA hash navigation -
 * useful when a stale service worker/cache needs to be shaken loose
 * without knowing a keyboard shortcut. Counted by hand with a reset timer
 * rather than via MouseEvent.detail - detail is desktop-mouse-only native
 * click counting; a tap on a touchscreen fires a synthesized click with
 * detail always 1, so mobile (this app is mobile-first) would never reach
 * 4 with the native counter alone.
 */
function wireLogoReload() {
    const logo = document.getElementById('navLogo');
    if (!logo) {
        return;
    }
    let clickCount = 0;
    let resetTimer = null;
    logo.addEventListener('click', () => {
        clickCount++;
        clearTimeout(resetTimer);
        if (clickCount >= 4) {
            clickCount = 0;
            window.location.reload();

            return;
        }
        resetTimer = setTimeout(() => {
            clickCount = 0;
        }, 600);
    });
}
