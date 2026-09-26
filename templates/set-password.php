<!DOCTYPE html>
<html class="standalone-page">
<head>
    <title><?= htmlspecialchars($appName) ?> | <?= htmlspecialchars($translator->t('auth.set_password_title')) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8" />

    <script>window.KOCHBUCH_API_BASE = "<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>/api/v1";</script>
    <script>window.KOCHBUCH_TRANSLATIONS = <?= json_encode($translator->all(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>

    <script src="<?= $baseUrl ?>/js/settings.js"></script>
    <script>applyStoredTheme();</script>
    <link rel="stylesheet" href="<?= $baseUrl ?>/lib/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= $baseUrl ?>/lib/bootstrap-icons/font/bootstrap-icons.min.css">
    <link rel="stylesheet" type="text/css" href="<?= $baseUrl ?>/css/style.css?v=<?= \Kochbuch\App::assetVersion($rootDir, '/css/style.css') ?>" />
</head>
<body>
    <div class="container py-4" style="max-width:26rem">
        <h1 class="h3 mb-4 text-center" data-i18n="auth.set_password_title"></h1>
        <form id="setPasswordForm">
            <div class="mb-3">
                <label class="form-label" data-i18n="auth.new_password"></label>
                <div id="spPasswordField"></div>
            </div>
            <div class="mb-3">
                <label class="form-label" data-i18n="auth.confirm_password"></label>
                <div id="spConfirmField"></div>
            </div>
            <div id="spError" class="alert alert-danger d-none"></div>
            <button type="submit" class="btn btn-primary w-100" data-i18n="auth.set_password_submit"></button>
        </form>
    </div>

    <script src="<?= $baseUrl ?>/js/i18n.js"></script>
    <script src="<?= $baseUrl ?>/js/helper.js"></script>
    <script src="<?= $baseUrl ?>/js/api-client.js"></script>
    <script>
        document.querySelectorAll('[data-i18n]').forEach(function (el) {
            el.textContent = t(el.getAttribute('data-i18n'));
        });
        document.getElementById('spPasswordField').innerHTML = passwordInputHtml('spPassword', ' autocomplete="new-password" required');
        document.getElementById('spConfirmField').innerHTML = passwordInputHtml('spConfirmPassword', ' autocomplete="new-password" required');

        var token = <?= json_encode($_GET['token'] ?? '') ?>;

        document.getElementById('setPasswordForm').addEventListener('submit', async function (e) {
            e.preventDefault();
            var errorBox = document.getElementById('spError');
            errorBox.classList.add('d-none');

            var password = document.getElementById('spPassword').value;
            var confirmPassword = document.getElementById('spConfirmPassword').value;
            if (password !== confirmPassword) {
                errorBox.textContent = t('auth.passwords_do_not_match');
                errorBox.classList.remove('d-none');
                return;
            }

            try {
                var result = await Kochbuch.post('/auth/set-password', { token: token, password: password });
                Kochbuch.setToken(result.token);
                window.location.href = '<?= $baseUrl ?>/';
            } catch (err) {
                errorBox.textContent = translateApiError(err.data) || err.message;
                errorBox.classList.remove('d-none');
            }
        });
    </script>
</body>
</html>
