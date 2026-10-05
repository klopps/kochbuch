/**
 * "Merkliste" (todo.md "Watchlist", route #/watchlist): the logged-in user's
 * own ordered list of recipes to cook next. Recipes get here via the
 * bookmark button on the recipe page (recipe-detail.js, appended to the
 * end). Here the list can be
 *  - reordered by dragging the handle on the left (SortableJS, vendored in
 *    public/lib/sortablejs - works with touch, unlike native drag & drop),
 *  - shortened with the "x" on the right (after a confirmation),
 *  - used to open a recipe by its name.
 * Backend: WatchlistController (/api/v1/watchlist).
 */
let watchlistSortable = null;

async function renderWatchlist() {
    const app = document.getElementById('app');

    if (!Kochbuch.isLoggedIn()) {
        Router.navigate('/login');

        return;
    }

    app.innerHTML =
        '<h1 class="h3 mb-3"><i class="bi bi-bookmark-heart"></i> ' + escapeHtml(t('watchlist.title')) + '</h1>' +
        '<div id="watchlistBody"><div class="text-center text-muted py-5"><div class="spinner-border" role="status"></div></div></div>';

    let recipes;
    try {
        recipes = await Kochbuch.get('/watchlist');
    } catch (err) {
        document.getElementById('watchlistBody').innerHTML = '<div class="alert alert-danger">' + escapeHtml(translateApiError(err.data) || err.message) + '</div>';

        return;
    }

    renderWatchlistItems(recipes);
}

function renderWatchlistItems(recipes) {
    const body = document.getElementById('watchlistBody');
    if (!body) {
        return;
    }
    if (watchlistSortable) {
        watchlistSortable.destroy();
        watchlistSortable = null;
    }

    if (recipes.length === 0) {
        body.innerHTML =
            '<div class="empty-state"><i class="bi bi-bookmark"></i>' +
            '<p class="mb-0">' + escapeHtml(t('watchlist.empty')) + '</p></div>';

        return;
    }

    body.innerHTML =
        '<ul class="list-group watchlist" id="watchlistItems">' +
        recipes.map((recipe) => (
            '<li class="list-group-item d-flex align-items-center gap-2 watchlist-item" data-id="' + recipe.id + '">' +
            '<span class="watchlist-handle text-muted" title="' + escapeHtml(t('watchlist.drag')) + '" aria-label="' + escapeHtml(t('watchlist.drag')) + '"><i class="bi bi-grip-vertical"></i></span>' +
            '<a href="#/recipes/' + recipe.id + '" class="flex-grow-1 text-reset text-decoration-none watchlist-name">' + escapeHtml(recipe.name) + '</a>' +
            '<button type="button" class="btn btn-sm btn-link text-muted watchlist-remove" data-id="' + recipe.id + '" data-name="' + escapeHtml(recipe.name) + '"' +
            ' title="' + escapeHtml(t('watchlist.remove')) + '" aria-label="' + escapeHtml(t('watchlist.remove')) + '"><i class="bi bi-x-lg"></i></button>' +
            '</li>'
        )).join('') +
        '</ul>' +
        '<p class="form-text mt-2">' + escapeHtml(t('watchlist.hint')) + '</p>';

    const list = document.getElementById('watchlistItems');
    watchlistSortable = Sortable.create(list, {
        handle: '.watchlist-handle',
        animation: 150,
        onEnd: async (event) => {
            if (event.oldIndex === event.newIndex) {
                return;
            }
            const ids = Array.from(list.querySelectorAll('.watchlist-item')).map((li) => Number(li.dataset.id));
            try {
                await Kochbuch.put('/watchlist/order', { recipe_ids: ids });
            } catch (err) {
                showToast(translateApiError(err.data) || err.message, 'danger');
                renderWatchlist();
            }
        },
    });

    list.querySelectorAll('.watchlist-remove').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const confirmed = await showConfirmDialog(t('watchlist.remove_confirm', { name: btn.dataset.name }), { type: 'danger', confirmLabel: t('watchlist.remove') });
            if (!confirmed) {
                return;
            }
            try {
                await Kochbuch.del('/watchlist/' + btn.dataset.id);
                const item = btn.closest('.watchlist-item');
                item.remove();
                if (!list.querySelector('.watchlist-item')) {
                    renderWatchlistItems([]);
                }
                showToast(t('watchlist.removed'));
            } catch (err) {
                showToast(translateApiError(err.data) || err.message, 'danger');
            }
        });
    });
}
