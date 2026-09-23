/**
 * Create/edit recipe form (routes "/recipes/new" and "/recipes/:id/edit").
 * Ingredient/step rows are plain DOM rows inside a container (no separate
 * JS array to keep in sync) - "add row" appends markup, "remove row"
 * removes the element, and submit reads the current DOM state straight
 * from querySelectorAll().
 */
async function renderRecipeForm(params) {
    const app = document.getElementById('app');
    const editing = !!params.id;

    if (!Kochbuch.isLoggedIn()) {
        Router.navigate('/login');

        return;
    }

    let recipe = {
        name: '', description: '', servings: 4, difficulty: 'normal', visibility: 'internal',
        prep_time_minutes: null, rest_time_minutes: null, cook_time_minutes: null,
        calories: null, allergen_info: '', notes: '', source: '', source_url: '',
        is_vegan: false, is_vegetarian: false, is_pescetarian: false,
        ingredients: [{ name: '', amount: '', unit: '', note: '' }],
        steps: [''],
        tags: [],
    };

    if (editing) {
        app.innerHTML = '<div class="text-center text-muted py-5"><div class="spinner-border" role="status"></div></div>';
        try {
            recipe = await Kochbuch.get('/recipes/' + params.id);
        } catch (e) {
            app.innerHTML = '<div class="alert alert-danger">' + escapeHtml(translateApiError(e.data) || e.message) + '</div>';

            return;
        }
        if (!isOwner(recipe)) {
            app.innerHTML = '<div class="alert alert-danger">' + escapeHtml(t('recipe.not_allowed')) + '</div>';

            return;
        }
        if (recipe.ingredients.length === 0) {
            recipe.ingredients = [{ name: '', amount: '', unit: '', note: '' }];
        }
        if (recipe.steps.length === 0) {
            recipe.steps = [''];
        }
    }

    app.innerHTML = recipeFormHtml(recipe, editing, params.id);
    wireRecipeForm(recipe, editing, params.id);
}

function recipeFormHtml(recipe, editing, recipeId) {
    return (
        '<h1 class="h3 mb-4">' + escapeHtml(t(editing ? 'recipe.edit_title' : 'recipe.create_title')) + '</h1>' +
        '<form id="recipeForm" novalidate>' +

        '<div class="row g-3 mb-4">' +
        '<div class="col-12"><label class="form-label">' + escapeHtml(t('recipe.name')) + '</label>' +
        '<input type="text" class="form-control" id="fName" required value="' + escapeHtml(recipe.name) + '"></div>' +
        '<div class="col-12"><label class="form-label">' + escapeHtml(t('recipe.description')) + '</label>' +
        '<textarea class="form-control" id="fDescription" rows="2">' + escapeHtml(recipe.description || '') + '</textarea></div>' +
        '</div>' +

        '<div class="row g-3 mb-4">' +
        '<div class="col-6 col-md-2"><label class="form-label">' + escapeHtml(t('recipe.servings')) + '</label>' +
        '<input type="number" min="1" class="form-control" id="fServings" value="' + recipe.servings + '"></div>' +
        '<div class="col-6 col-md-3"><label class="form-label">' + escapeHtml(t('recipe.difficulty')) + '</label>' +
        '<select class="form-select" id="fDifficulty">' +
        ['easy', 'normal', 'hard', 'challenging'].map((d) => '<option value="' + d + '"' + (recipe.difficulty === d ? ' selected' : '') + '>' + escapeHtml(t(DIFFICULTY_LABEL_KEY[d])) + '</option>').join('') +
        '</select></div>' +
        '<div class="col-12 col-md-3"><label class="form-label">' + escapeHtml(t('recipe.visibility')) + '</label>' +
        '<select class="form-select" id="fVisibility">' +
        '<option value="private"' + (recipe.visibility === 'private' ? ' selected' : '') + '>' + escapeHtml(t('recipe.visibility_private')) + '</option>' +
        '<option value="internal"' + (recipe.visibility === 'internal' ? ' selected' : '') + '>' + escapeHtml(t('recipe.visibility_internal')) + '</option>' +
        '<option value="public"' + (recipe.visibility === 'public' ? ' selected' : '') + '>' + escapeHtml(t('recipe.visibility_public')) + '</option>' +
        '</select></div>' +
        '<div class="col-12 col-md-4"><label class="form-label">' + escapeHtml(t('recipe.calories')) + '</label>' +
        '<input type="number" min="0" class="form-control" id="fCalories" value="' + (recipe.calories ?? '') + '"></div>' +
        '</div>' +

        '<div class="row g-3 mb-4">' +
        '<div class="col-4"><label class="form-label">' + escapeHtml(t('recipe.prep_time')) + '</label>' +
        '<input type="number" min="0" class="form-control" id="fPrep" value="' + (recipe.prep_time_minutes ?? '') + '"></div>' +
        '<div class="col-4"><label class="form-label">' + escapeHtml(t('recipe.rest_time')) + '</label>' +
        '<input type="number" min="0" class="form-control" id="fRest" value="' + (recipe.rest_time_minutes ?? '') + '"></div>' +
        '<div class="col-4"><label class="form-label">' + escapeHtml(t('recipe.cook_time')) + '</label>' +
        '<input type="number" min="0" class="form-control" id="fCook" value="' + (recipe.cook_time_minutes ?? '') + '"></div>' +
        '</div>' +

        '<div class="mb-4 d-flex flex-wrap gap-3">' +
        dietCheckbox('fVegan', 'diet.vegan', recipe.is_vegan) +
        dietCheckbox('fVegetarian', 'diet.vegetarian', recipe.is_vegetarian) +
        dietCheckbox('fPescetarian', 'diet.pescetarian', recipe.is_pescetarian) +
        '</div>' +

        '<h2 class="h5 mb-2">' + escapeHtml(t('recipe.ingredients')) + '</h2>' +
        '<div id="ingredientRows" class="mb-2">' + recipe.ingredients.map(ingredientRowHtml).join('') + '</div>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary mb-4" id="addIngredientBtn"><i class="bi bi-plus-lg"></i> ' + escapeHtml(t('recipe.add_ingredient')) + '</button>' +

        '<h2 class="h5 mb-2">' + escapeHtml(t('recipe.steps')) + '</h2>' +
        '<div id="stepRows" class="mb-2">' + recipe.steps.map(stepRowHtml).join('') + '</div>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary mb-4" id="addStepBtn"><i class="bi bi-plus-lg"></i> ' + escapeHtml(t('recipe.add_step')) + '</button>' +

        '<div class="row g-3 mb-4">' +
        '<div class="col-12"><label class="form-label">' + escapeHtml(t('recipe.tags')) + '</label>' +
        '<input type="text" class="form-control" id="fTags" value="' + escapeHtml((recipe.tags || []).join(', ')) + '">' +
        '<div class="form-text">' + escapeHtml(t('recipe.tags_hint')) + '</div></div>' +
        '<div class="col-12"><label class="form-label">' + escapeHtml(t('recipe.notes')) + '</label>' +
        '<textarea class="form-control" id="fNotes" rows="2">' + escapeHtml(recipe.notes || '') + '</textarea></div>' +
        '<div class="col-12"><label class="form-label">' + escapeHtml(t('recipe.allergen_info')) + '</label>' +
        '<textarea class="form-control" id="fAllergenInfo" rows="2">' + escapeHtml(recipe.allergen_info || '') + '</textarea></div>' +
        '<div class="col-12 col-md-6"><label class="form-label">' + escapeHtml(t('recipe.source')) + '</label>' +
        '<input type="text" class="form-control" id="fSource" value="' + escapeHtml(recipe.source || '') + '"></div>' +
        '<div class="col-12 col-md-6"><label class="form-label">' + escapeHtml(t('recipe.source_url')) + '</label>' +
        '<input type="url" class="form-control" id="fSourceUrl" value="' + escapeHtml(recipe.source_url || '') + '"></div>' +
        '</div>' +

        '<div id="formError" class="alert alert-danger d-none"></div>' +
        '<div class="d-flex gap-2">' +
        '<button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> ' + escapeHtml(t('recipe.save')) + '</button>' +
        '<a href="#/recipes' + (editing ? '/' + recipeId : '') + '" class="btn btn-outline-secondary">' + escapeHtml(t('recipe.cancel')) + '</a>' +
        '</div>' +
        '</form>'
    );
}

function dietCheckbox(id, labelKey, checked) {
    return (
        '<div class="form-check">' +
        '<input class="form-check-input" type="checkbox" id="' + id + '"' + (checked ? ' checked' : '') + '>' +
        '<label class="form-check-label" for="' + id + '">' + escapeHtml(t(labelKey)) + '</label></div>'
    );
}

/*
 * Flex layout rather than Bootstrap grid columns: a 5-across col-N row
 * (name/amount/unit/note/remove) squeezes every field down to a handful of
 * characters at a 390px mobile width, clipping placeholders and the remove
 * button - flex-wrap lets amount/unit/remove stay compact and wrap onto
 * their own line under the full-width name field instead.
 */
function ingredientRowHtml(ingredient) {
    return (
        '<div class="d-flex flex-wrap gap-2 mb-2 ingredient-row">' +
        '<input type="text" class="form-control ing-name flex-grow-1" style="min-width:9rem" placeholder="' + escapeHtml(t('recipe.ingredient_name')) + '" value="' + escapeHtml(ingredient.name || '') + '">' +
        '<input type="number" step="any" class="form-control ing-amount" style="width:5.5rem" placeholder="' + escapeHtml(t('recipe.ingredient_amount')) + '" value="' + (ingredient.amount ?? '') + '">' +
        '<input type="text" class="form-control ing-unit" style="width:5.5rem" placeholder="' + escapeHtml(t('recipe.ingredient_unit')) + '" value="' + escapeHtml(ingredient.unit || '') + '">' +
        '<input type="text" class="form-control ing-note flex-grow-1" style="min-width:7rem" placeholder="' + escapeHtml(t('recipe.ingredient_note')) + '" value="' + escapeHtml(ingredient.note || '') + '">' +
        '<button type="button" class="btn btn-outline-danger remove-row-btn" aria-label="' + escapeHtml(t('recipe.remove_row')) + '"><i class="bi bi-x-lg"></i></button>' +
        '</div>'
    );
}

function stepRowHtml(step) {
    return (
        '<div class="d-flex gap-2 mb-2 step-row">' +
        '<textarea class="form-control step-text" rows="2" placeholder="' + escapeHtml(t('recipe.step_placeholder')) + '">' + escapeHtml(step || '') + '</textarea>' +
        '<button type="button" class="btn btn-sm btn-outline-danger remove-row-btn align-self-start"><i class="bi bi-x-lg"></i></button>' +
        '</div>'
    );
}

function wireRecipeForm(recipe, editing, recipeId) {
    document.getElementById('addIngredientBtn').addEventListener('click', () => {
        document.getElementById('ingredientRows').insertAdjacentHTML('beforeend', ingredientRowHtml({}));
        wireRemoveButtons();
    });
    document.getElementById('addStepBtn').addEventListener('click', () => {
        document.getElementById('stepRows').insertAdjacentHTML('beforeend', stepRowHtml(''));
        wireRemoveButtons();
    });
    wireRemoveButtons();

    document.getElementById('recipeForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const errorBox = document.getElementById('formError');
        errorBox.classList.add('d-none');

        const payload = {
            name: document.getElementById('fName').value.trim(),
            description: document.getElementById('fDescription').value,
            servings: parseInt(document.getElementById('fServings').value, 10) || 1,
            difficulty: document.getElementById('fDifficulty').value,
            visibility: document.getElementById('fVisibility').value,
            calories: document.getElementById('fCalories').value || null,
            prep_time_minutes: document.getElementById('fPrep').value || null,
            rest_time_minutes: document.getElementById('fRest').value || null,
            cook_time_minutes: document.getElementById('fCook').value || null,
            is_vegan: document.getElementById('fVegan').checked,
            is_vegetarian: document.getElementById('fVegetarian').checked,
            is_pescetarian: document.getElementById('fPescetarian').checked,
            notes: document.getElementById('fNotes').value,
            allergen_info: document.getElementById('fAllergenInfo').value,
            source: document.getElementById('fSource').value,
            source_url: document.getElementById('fSourceUrl').value,
            tags: document.getElementById('fTags').value.split(',').map((s) => s.trim()).filter(Boolean),
            ingredients: Array.from(document.querySelectorAll('.ingredient-row')).map((row) => ({
                name: row.querySelector('.ing-name').value.trim(),
                amount: row.querySelector('.ing-amount').value || null,
                unit: row.querySelector('.ing-unit').value.trim(),
                note: row.querySelector('.ing-note').value.trim(),
            })).filter((i) => i.name),
            steps: Array.from(document.querySelectorAll('.step-text')).map((el) => el.value.trim()).filter(Boolean),
        };

        try {
            const saved = editing
                ? await Kochbuch.put('/recipes/' + recipeId, payload)
                : await Kochbuch.post('/recipes', payload);
            showToast(t('recipe.saved'));
            Router.navigate('/recipes/' + saved.id);
        } catch (err) {
            errorBox.textContent = translateApiError(err.data) || err.message;
            errorBox.classList.remove('d-none');
        }
    });
}

function wireRemoveButtons() {
    document.querySelectorAll('.remove-row-btn').forEach((btn) => {
        btn.onclick = () => {
            const row = btn.closest('.ingredient-row, .step-row');
            const container = row.parentElement;
            if (container.children.length > 1) {
                row.remove();
            }
        };
    });
}
