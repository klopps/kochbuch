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
Router.on('/recipes/:id/edit', renderRecipeForm);
Router.on('/recipes/:id', renderRecipeDetail);
Router.on('/categories', renderCategories);
Router.on('/categories/:id', renderCategoryDetail);
Router.on('/login', renderLogin);

document.addEventListener('DOMContentLoaded', async () => {
    wireThemeToggle();
    wireLanguageSwitcher();
    wireLogoReload();
    await loadCurrentUser();
    renderNav();
    Router.start();
});
