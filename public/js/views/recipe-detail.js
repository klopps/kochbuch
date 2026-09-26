/**
 * Recipe detail screen (route "/recipes/:id"). Portion scaling is purely
 * client-side: the API always returns ingredient amounts for the recipe's
 * own base `servings`, and changing the stepper just re-renders the
 * ingredient list scaled by servings/base - no API round trip.
 */
async function renderRecipeDetail(params) {
    const app = document.getElementById('app');
    app.innerHTML = '<div class="text-center text-muted py-5"><div class="spinner-border" role="status"></div></div>';

    let recipe;
    try {
        recipe = await Kochbuch.get('/recipes/' + params.id);
    } catch (e) {
        app.innerHTML = '<div class="alert alert-danger">' + escapeHtml(translateApiError(e.data) || e.message) + '</div>';

        return;
    }

    let servings = recipe.servings;
    app.innerHTML = recipeDetailHtml(recipe, servings);
    wireRecipeDetail(recipe, () => servings, (v) => { servings = v; });
    hydrateAuthImages(app);
}

function recipeDetailHtml(recipe, servings) {
    const imagePath = recipeImagePath(recipe);
    const owner = isOwner(recipe);

    return (
        '<div class="mb-3"><a href="' + escapeHtml(lastRecipesListUrl) + '" class="link-secondary text-decoration-none"><i class="bi bi-arrow-left"></i> ' + escapeHtml(t('recipe.back_to_list')) + '</a></div>' +
        '<div class="recipe-hero">' + (imagePath ? '<img id="heroImage" data-recipe-image="' + escapeHtml(imagePath) + '" alt="">' : '<i class="bi bi-egg-fried"></i>') + '</div>' +
        '<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">' +
        '<h1 class="h3 mb-0">' + escapeHtml(recipe.name) + '</h1>' +
        '<div class="d-flex gap-2 flex-wrap">' +
        shareButtonHtml() +
        exportMenuHtml(recipe.id) +
        (owner ? '<a href="#/recipes/' + recipe.id + '/edit" class="btn btn-outline-secondary"><i class="bi bi-pencil"></i></a>' : '') +
        (owner ? '<button type="button" class="btn btn-outline-danger" id="deleteRecipeBtn"><i class="bi bi-trash"></i></button>' : '') +
        '</div></div>' +
        '<div class="d-flex flex-wrap gap-2 mb-3">' +
        difficultyBadgeHtml(recipe.difficulty) + dietBadgesHtml(recipe) +
        (VISIBILITY_META[recipe.visibility]
            ? '<span class="badge text-bg-secondary"><i class="bi ' + VISIBILITY_META[recipe.visibility].icon + '"></i> ' + escapeHtml(t(VISIBILITY_META[recipe.visibility].labelKey)) + '</span>'
            : '') +
        '</div>' +
        (recipe.description ? '<p class="mb-4">' + escapeHtml(recipe.description).replace(/\n/g, '<br>') + '</p>' : '') +

        '<div class="row g-4">' +
        '<div class="col-lg-5">' +
        '<div class="d-flex justify-content-between align-items-center mb-2">' +
        '<h2 class="h5 mb-0">' + escapeHtml(t('recipe.ingredients')) + '</h2>' +
        '<div class="portion-stepper">' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" id="portionMinus">-</button>' +
        '<span class="portion-value" id="portionValue">' + servings + '</span>' +
        '<span class="text-muted small">' + escapeHtml(t('recipe.servings_label')) + '</span>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" id="portionPlus">+</button>' +
        '</div></div>' +
        '<ul class="ingredient-list" id="ingredientList"></ul>' +
        metaInfoHtml(recipe) +
        categoryPanelHtml(recipe) +
        '</div>' +
        '<div class="col-lg-7">' +
        '<h2 class="h5 mb-2">' + escapeHtml(t('recipe.steps')) + '</h2>' +
        '<ol class="step-list">' + recipe.steps.map((s) => (
            s.is_heading
                ? '<li class="step-heading">' + escapeHtml(s.instruction) + '</li>'
                : '<li>' + escapeHtml(s.instruction).replace(/\n/g, '<br>') + '</li>'
        )).join('') + '</ol>' +
        (recipe.notes ? '<h2 class="h5 mt-4 mb-2">' + escapeHtml(t('recipe.notes')) + '</h2><p>' + escapeHtml(recipe.notes).replace(/\n/g, '<br>') + '</p>' : '') +
        imageGalleryHtml(recipe, owner) +
        '</div>' +
        '</div>'
    );
}

function shareButtonHtml() {
    return '<button type="button" class="btn btn-outline-secondary" id="shareRecipeBtn"><i class="bi bi-share"></i></button>';
}

function exportMenuHtml(id) {
    return (
        '<div class="dropdown">' +
        '<button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown"><i class="bi bi-download"></i></button>' +
        '<ul class="dropdown-menu dropdown-menu-end">' +
        ['json', 'pdf', 'xml'].map((f) => '<li><a class="dropdown-item recipe-export-link" href="#" data-format="' + f + '">' + f.toUpperCase() + '</a></li>').join('') +
        '</ul></div>'
    );
}

function metaInfoHtml(recipe) {
    const rows = [];
    const time = timeLabel(totalTimeMinutes(recipe));
    if (time) rows.push([t('recipe.total_time'), time]);
    if (recipe.calories) rows.push([t('recipe.calories'), recipe.calories + ' kcal']);
    if (recipe.allergen_info) rows.push([t('recipe.allergen_info'), recipe.allergen_info]);
    if (recipe.source || recipe.source_url) {
        const sourceHtml = recipe.source_url
            ? '<a href="' + escapeHtml(recipe.source_url) + '" target="_blank" rel="noopener">' + escapeHtml(recipe.source || recipe.source_url) + '</a>'
            : escapeHtml(recipe.source);
        rows.push([t('recipe.source'), sourceHtml]);
    }
    if (!rows.length && !(recipe.tags || []).length) {
        return '';
    }

    return (
        '<div class="mt-4 small">' +
        rows.map(([label, value]) => '<div class="d-flex justify-content-between border-bottom py-1"><span class="text-muted">' + escapeHtml(label) + '</span><span>' + value + '</span></div>').join('') +
        (recipe.tags.length ? '<div class="d-flex flex-wrap gap-1 mt-2">' + recipe.tags.map((tg) => '<span class="tag-chip">' + escapeHtml(tg) + '</span>').join('') + '</div>' : '') +
        '</div>'
    );
}

function categoryPanelHtml(recipe) {
    if (!Kochbuch.isLoggedIn()) {
        return '';
    }

    return (
        '<div class="mt-4">' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" id="categoryToggleBtn"><i class="bi bi-collection"></i> ' + escapeHtml(t('category.add_to')) + '</button>' +
        '<div id="categoryChecklist" class="mt-2 d-none"></div>' +
        '</div>'
    );
}

function imageGalleryHtml(recipe, owner) {
    if (!owner && recipe.images.length <= 1) {
        return '';
    }

    return (
        '<h2 class="h5 mt-4 mb-2">' + escapeHtml(t('recipe.images')) + '</h2>' +
        '<div class="image-thumb-grid" id="imageThumbGrid">' +
        recipe.images.map((id) => imageThumbHtml(recipe, id, owner)).join('') +
        (owner ? '<label class="btn btn-outline-secondary image-thumb d-flex align-items-center justify-content-center" style="cursor:pointer">' +
            '<i class="bi bi-plus-lg"></i><input type="file" id="imageUploadInput" accept="image/jpeg,image/png,image/webp" class="d-none"></label>' : '') +
        '</div>'
    );
}

function imageThumbHtml(recipe, imageId, owner) {
    const path = '/recipes/' + recipe.id + '/images/' + imageId;
    const isPrimary = recipe.primary_image_id === imageId;

    return (
        '<div class="image-thumb' + (isPrimary ? ' is-primary' : '') + '" data-image-id="' + imageId + '">' +
        '<img data-recipe-image="' + escapeHtml(path) + '" alt="">' +
        (owner ? '<button type="button" class="image-thumb-remove" data-remove-image="' + imageId + '">&times;</button>' : '') +
        '</div>'
    );
}

function wireRecipeDetail(recipe, getServings, setServings) {
    renderIngredients(recipe, getServings());

    document.getElementById('portionMinus').addEventListener('click', () => {
        const next = Math.max(1, getServings() - 1);
        setServings(next);
        document.getElementById('portionValue').textContent = next;
        renderIngredients(recipe, next);
    });
    document.getElementById('portionPlus').addEventListener('click', () => {
        const next = getServings() + 1;
        setServings(next);
        document.getElementById('portionValue').textContent = next;
        renderIngredients(recipe, next);
    });

    document.getElementById('shareRecipeBtn').addEventListener('click', async () => {
        const url = location.origin + location.pathname + '#/recipes/' + recipe.id;
        if (navigator.share) {
            try {
                await navigator.share({ title: recipe.name, url });
            } catch (e) { /* user cancelled */ }

            return;
        }
        await navigator.clipboard.writeText(url);
        showToast(t('recipe.share_copied'));
    });

    document.querySelectorAll('.recipe-export-link').forEach((link) => {
        link.addEventListener('click', async (e) => {
            e.preventDefault();
            try {
                await Kochbuch.download('/recipes/' + recipe.id + '/export/' + link.dataset.format);
            } catch (err) {
                showToast(translateApiError(err.data) || err.message, 'danger');
            }
        });
    });

    const deleteBtn = document.getElementById('deleteRecipeBtn');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', async () => {
            if (!await showConfirmDialog(t('recipe.delete_confirm'), { type: 'danger' })) {
                return;
            }
            try {
                await Kochbuch.del('/recipes/' + recipe.id);
                showToast(t('recipe.deleted'));
                Router.navigate('/recipes');
            } catch (err) {
                showToast(translateApiError(err.data) || err.message, 'danger');
            }
        });
    }

    wireCategoryPanel(recipe);
    wireImageGallery(recipe);
}

function renderIngredients(recipe, servings) {
    const factor = servings / recipe.servings;
    document.getElementById('ingredientList').innerHTML = recipe.ingredients.map((i) => {
        if (i.is_heading) {
            return '<li class="ingredient-heading">' + escapeHtml(i.name) + '</li>';
        }

        const amount = i.amount !== null ? formatAmount(i.amount * factor) : '';
        const amountUnit = [amount, i.unit].filter(Boolean).join(' ');

        // Left to right: amount, unit, ingredient, (note).
        return (
            '<li><span class="ingredient-amount text-nowrap fw-semibold">' + escapeHtml(amountUnit) + '</span>' +
            '<span>' + escapeHtml(i.name) + (i.note ? ' <span class="text-muted small">(' + escapeHtml(i.note) + ')</span>' : '') + '</span></li>'
        );
    }).join('');
}

async function wireCategoryPanel(recipe) {
    const toggleBtn = document.getElementById('categoryToggleBtn');
    if (!toggleBtn) {
        return;
    }
    const panel = document.getElementById('categoryChecklist');

    toggleBtn.addEventListener('click', async () => {
        panel.classList.toggle('d-none');
        if (panel.classList.contains('d-none') || panel.dataset.loaded) {
            return;
        }
        panel.dataset.loaded = '1';
        try {
            const categories = await Kochbuch.get('/categories?recipe_id=' + recipe.id);
            panel.innerHTML = categories.length
                ? categories.map((c) =>
                    '<div class="form-check">' +
                    '<input class="form-check-input category-toggle" type="checkbox" id="cat' + c.id + '" data-category-id="' + c.id + '"' + (c.contains_recipe ? ' checked' : '') + '>' +
                    '<label class="form-check-label" for="cat' + c.id + '">' + escapeHtml(c.name) + '</label></div>'
                ).join('')
                : '<p class="small text-muted mb-0">' + escapeHtml(t('category.none_yet')) + ' <a href="#/categories">' + escapeHtml(t('category.manage')) + '</a></p>';

            panel.querySelectorAll('.category-toggle').forEach((cb) => {
                cb.addEventListener('change', async () => {
                    const categoryId = cb.dataset.categoryId;
                    try {
                        if (cb.checked) {
                            await Kochbuch.put('/categories/' + categoryId + '/recipes/' + recipe.id);
                        } else {
                            await Kochbuch.del('/categories/' + categoryId + '/recipes/' + recipe.id);
                        }
                    } catch (err) {
                        cb.checked = !cb.checked;
                        showToast(translateApiError(err.data) || err.message, 'danger');
                    }
                });
            });
        } catch (err) {
            panel.innerHTML = '<p class="text-danger small">' + escapeHtml(translateApiError(err.data) || err.message) + '</p>';
        }
    });
}

function wireImageGallery(recipe) {
    const grid = document.getElementById('imageThumbGrid');
    if (!grid) {
        return;
    }

    grid.querySelectorAll('.image-thumb img').forEach((img) => {
        img.addEventListener('click', () => {
            const hero = document.getElementById('heroImage');
            if (hero) {
                hero.src = img.src;
            }
        });
    });

    grid.querySelectorAll('[data-remove-image]').forEach((btn) => {
        btn.addEventListener('click', async (e) => {
            e.stopPropagation();
            try {
                await Kochbuch.del('/recipes/' + recipe.id + '/images/' + btn.dataset.removeImage);
                renderRecipeDetail({ id: recipe.id });
            } catch (err) {
                showToast(translateApiError(err.data) || err.message, 'danger');
            }
        });
    });

    const uploadInput = document.getElementById('imageUploadInput');
    if (uploadInput) {
        uploadInput.addEventListener('change', async () => {
            const file = uploadInput.files[0];
            if (!file) {
                return;
            }
            const formData = new FormData();
            formData.append('image', file);
            try {
                await Kochbuch.upload('/recipes/' + recipe.id + '/images', formData);
                renderRecipeDetail({ id: recipe.id });
            } catch (err) {
                showToast(translateApiError(err.data) || err.message, 'danger');
            }
        });
    }
}
