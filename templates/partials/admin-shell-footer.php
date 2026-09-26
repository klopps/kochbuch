                </div>
            </div>
        </main>
    </div>

<div id="toastHost"></div>

<script src="<?= $baseUrl ?>/js/config.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/config.js') ?>"></script>
<script src="<?= $baseUrl ?>/js/i18n.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/i18n.js') ?>"></script>
<script src="<?= $baseUrl ?>/js/helper.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/helper.js') ?>"></script>
<script src="<?= $baseUrl ?>/js/api-client.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/api-client.js') ?>"></script>
<script src="<?= $baseUrl ?>/lib/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="<?= $baseUrl ?>/js/toast.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/toast.js') ?>"></script>
<script src="<?= $baseUrl ?>/js/admin-auth.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/admin-auth.js') ?>"></script>
<script src="<?= $baseUrl ?>/js/admin-common.js?v=<?= \Kochbuch\App::assetVersion($rootDir, '/js/admin-common.js') ?>"></script>
<script src="<?= $baseUrl ?>/lib/adminlte/adminlte.min.js"></script>
<?php foreach ($pageScripts ?? [] as $script): ?>
<script src="<?= $baseUrl . $script ?>?v=<?= \Kochbuch\App::assetVersion($rootDir, $script) ?>"></script>
<?php endforeach; ?>

<script>
    document.querySelectorAll('[data-i18n]').forEach(function (el) {
        el.textContent = t(el.getAttribute('data-i18n'));
    });
</script>
</body>
</html>
