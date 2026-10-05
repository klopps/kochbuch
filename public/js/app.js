/**
 * App entry point - registers routes (order matters: a specific literal
 * route like "/recipes/new" must be registered before the parameterized
 * "/recipes/:id" it would otherwise be swallowed by) and boots the router
 * once the current user (if any) has been resolved, so the very first
 * render already knows the login state.
 */
const Views = {
    notFound: renderNotFound,
};

Router.on('/', renderRecipesList);
Router.on('/recipes', renderRecipesList);
Router.on('/recipes/new', renderRecipeForm);
Router.on('/recipes/import-photo', renderRecipeImportPhoto);
Router.on('/recipes/import-json', renderRecipeImportJson);
Router.on('/recipes/:id/edit', renderRecipeForm);
Router.on('/recipes/:id', renderRecipeDetail);
Router.on('/categories', renderCategories);
Router.on('/categories/:id', renderCategoryDetail);
Router.on('/login', renderLogin);
Router.on('/profile', renderProfile);

document.addEventListener('DOMContentLoaded', async () => {
    wireThemeToggle();
    wireFontScaleControls();
    wireKeepScreenAwakeToggle();
    wireOfflineSyncButton();
    wireOfflineIndicator();
    wireLanguageSwitcher();
    wireLogoReload();
    await loadCurrentUser();
    renderNav();
    // todo.md "PWA/Offline Capability" - fire-and-forget: never await this,
    // the first render must feel exactly as fast online as before this
    // existed. See nav.js's autoSyncForOffline()/performFullSync().
    autoSyncForOffline();
    Router.start();
    // Android app only (no-op in the browser/PWA): open the import view
    // for images/text shared into the app - see native-share.js.
    NativeShare.init();
    // Android app only: "v<Kochbuch> / <App>" in the navigation drawer.
    NativeApp.showVersion();
});
