/**
 * Create/edit recipe form (routes "/recipes/new" and "/recipes/:id/edit").
 * The ingredient and step lists both need drag-reorder and an edit/display
 * toggle with at most one row editable at a time, so they can't follow this
 * file's usual plain-DOM pattern (no separate JS array, submit reads the
 * DOM straight from querySelectorAll()) - wireEditableItemList() is the
 * shared engine behind both (see its own comment further down), fed by a
 * thin per-domain adapter (wireIngredientEditor()/wireStepEditor()).
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
        steps: [{ instruction: '', is_heading: false }],
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
            recipe.steps = [{ instruction: '', is_heading: false }];
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
        '<div id="ingredientRows" class="mb-2"></div>' +
        '<div class="d-flex flex-wrap gap-2 mb-4">' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" id="addIngredientBtn"><i class="bi bi-plus-lg"></i> ' + escapeHtml(t('recipe.add_ingredient')) + '</button>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" id="addSectionBtn"><i class="bi bi-plus-lg"></i> ' + escapeHtml(t('recipe.add_section')) + '</button>' +
        '</div>' +

        '<h2 class="h5 mb-2">' + escapeHtml(t('recipe.steps')) + '</h2>' +
        '<div id="stepRows" class="mb-2"></div>' +
        '<div class="d-flex flex-wrap gap-2 mb-4">' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" id="addStepBtn"><i class="bi bi-plus-lg"></i> ' + escapeHtml(t('recipe.add_step')) + '</button>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" id="addStepSectionBtn"><i class="bi bi-plus-lg"></i> ' + escapeHtml(t('recipe.add_section')) + '</button>' +
        '</div>' +

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
 * Ingredient-row rendering, either a real ingredient or a section heading.
 * Not-being-edited rows render as plain text (amount+unit bold, name
 * after, note on its own muted line below - order matches the PDF/detail
 * view's amount-unit-name-(note) convention); the currently-edited row (at
 * most one at a time, see wireEditableItemList()) renders its input fields
 * instead, amount/unit/name in that sequence per the "Anzahl, Einheit,
 * Zutatenbeschreibung hintereinander" requirement, then note.
 */
function ingredientDisplayHtml(item) {
    const amountNumber = item.amount !== '' && item.amount !== null && item.amount !== undefined ? parseFloat(item.amount) : null;
    const amountUnit = [amountNumber !== null && !isNaN(amountNumber) ? formatAmount(amountNumber) : '', item.unit].filter(Boolean).join(' ');
    let html = '<div class="editable-item-text">';
    html += amountUnit ? '<strong>' + escapeHtml(amountUnit) + '</strong> ' : '';
    html += escapeHtml(item.name || '');
    html += '</div>';
    if (item.note) {
        html += '<div class="text-muted small">' + escapeHtml(item.note) + '</div>';
    }

    return html;
}

function ingredientHeadingDisplayHtml(item) {
    return '<div class="editable-item-text fw-semibold">' + escapeHtml(item.name || '') + '</div>';
}

function ingredientEditHtml(item) {
    return (
        '<div class="d-flex flex-wrap gap-2">' +
        '<input type="number" step="any" class="form-control ing-amount" style="width:5.5rem" placeholder="' + escapeHtml(t('recipe.ingredient_amount')) + '" value="' + (item.amount ?? '') + '">' +
        '<input type="text" class="form-control ing-unit" style="width:5.5rem" placeholder="' + escapeHtml(t('recipe.ingredient_unit')) + '" value="' + escapeHtml(item.unit || '') + '">' +
        '<input type="text" class="form-control ing-name flex-grow-1" style="min-width:9rem" placeholder="' + escapeHtml(t('recipe.ingredient_name')) + '" value="' + escapeHtml(item.name || '') + '">' +
        '<input type="text" class="form-control ing-note flex-grow-1" style="min-width:7rem" placeholder="' + escapeHtml(t('recipe.ingredient_note')) + '" value="' + escapeHtml(item.note || '') + '">' +
        '</div>'
    );
}

function ingredientHeadingEditHtml(item) {
    return '<input type="text" class="form-control ing-heading-name" placeholder="' + escapeHtml(t('recipe.section_placeholder')) + '" value="' + escapeHtml(item.name || '') + '">';
}

/*
 * Step-row rendering, either a real step or a section heading - same
 * display/edit split as the ingredient rows above, just a single text
 * field instead of four.
 */
function stepDisplayHtml(item) {
    return '<div class="editable-item-text">' + escapeHtml(item.text || '').replace(/\n/g, '<br>') + '</div>';
}

function stepHeadingDisplayHtml(item) {
    return '<div class="editable-item-text fw-semibold">' + escapeHtml(item.text || '') + '</div>';
}

function stepEditHtml(item) {
    return '<textarea class="form-control step-text-input" rows="2" placeholder="' + escapeHtml(t('recipe.step_placeholder')) + '">' + escapeHtml(item.text || '') + '</textarea>';
}

function stepHeadingEditHtml(item) {
    return '<input type="text" class="form-control step-heading-input" placeholder="' + escapeHtml(t('recipe.section_placeholder')) + '" value="' + escapeHtml(item.text || '') + '">';
}

function wireRecipeForm(recipe, editing, recipeId) {
    const ingredientEditor = wireIngredientEditor(recipe.ingredients);
    const stepEditor = wireStepEditor(recipe.steps);

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
            ingredients: ingredientEditor.getIngredients(),
            steps: stepEditor.getSteps(),
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

/**
 * Shared engine behind the ingredient and step editors: an explicit
 * uid-keyed JS list (uid-keyed so reordering or committing an edit never
 * has to trust array indices that a concurrent drag might have shifted)
 * with drag-reorder and an edit/display toggle where at most one row is
 * editable at a time. A caller supplies how a single loaded item is turned
 * into its internal shape, how a blank new one looks, how it renders in
 * its two states, and how it's read back out of its edit-mode DOM; the row
 * wrapper (remove/edit/drag buttons) and all interaction wiring
 * (add/remove/edit-toggle/drag, Pointer Events reorder) live here once
 * rather than duplicated per domain.
 *
 * Drag-to-reorder is the same Pointer Events pattern as YTAN's
 * tour-admin.js route reordering: Pointer Events unify mouse/touch/pen (a
 * native HTML5 draggable wouldn't fire on touch, and this app is
 * mobile-first), and a floating ghost clone appended to <body> follows the
 * pointer and keeps capturing move/up events while the real list
 * re-renders underneath it on every reorder.
 *
 * Returns { getData(mapItem) }: commits any open edit, then maps every
 * item through the caller's mapItem(item) (return null to drop a blank
 * one) for the submit handler to read the final result from.
 */
function wireEditableItemList(config) {
    const { containerId, initialData, itemFromData, blankItem, isBlankNewItem, renderDisplay, renderEdit, commitFromRow, addButtons } = config;

    let nextUid = 1;
    let items = initialData.map((data) => ({ uid: nextUid++, ...itemFromData(data) }));
    // A brand new recipe's single empty item starts in edit mode, so
    // there's immediately something to type into.
    let editingUid = items.length === 1 && isBlankNewItem(items[0]) ? items[0].uid : null;

    const container = document.getElementById(containerId);
    let dragState = null; // { pointerId, uid, ghost, offsetY }

    function commitEditingItem() {
        if (editingUid === null) {
            return;
        }
        const row = container.querySelector('[data-uid="' + editingUid + '"]');
        const item = items.find((i) => i.uid === editingUid);
        if (row && item) {
            commitFromRow(item, row);
        }
        editingUid = null;
    }

    function itemHtml(item, isEditing) {
        const isHeading = item.type === 'heading';
        const body = isEditing ? renderEdit(item) : renderDisplay(item);

        return (
            '<div class="d-flex align-items-start gap-2 mb-2 editable-item' + (isHeading ? ' editable-item-heading' : '') + '" data-uid="' + item.uid + '">' +
            '<button type="button" class="btn btn-link text-danger p-0 remove-item-btn" aria-label="' + escapeHtml(t('recipe.remove_row')) + '"><i class="bi bi-x-lg"></i></button>' +
            '<div class="flex-grow-1 editable-item-body">' + body + '</div>' +
            '<button type="button" class="btn btn-link p-0 edit-item-btn" aria-label="' + escapeHtml(t(isEditing ? 'recipe.finish_edit_row' : 'recipe.edit_row')) + '"><i class="bi ' + (isEditing ? 'bi-check-lg' : 'bi-pencil') + '"></i></button>' +
            '<span class="drag-handle" role="button" aria-label="' + escapeHtml(t('recipe.reorder_row')) + '"><i class="bi bi-list"></i></span>' +
            '</div>'
        );
    }

    function render() {
        container.innerHTML = items.map((item) => itemHtml(item, item.uid === editingUid)).join('');
        container.querySelectorAll('.editable-item').forEach((row) => {
            const uid = parseInt(row.dataset.uid, 10);

            row.querySelector('.remove-item-btn').addEventListener('click', () => {
                if (editingUid === uid) {
                    editingUid = null;
                }
                items = items.filter((i) => i.uid !== uid);
                render();
            });

            row.querySelector('.edit-item-btn').addEventListener('click', () => {
                const wasEditing = editingUid === uid;
                commitEditingItem();
                editingUid = wasEditing ? null : uid;
                render();
            });

            row.querySelector('.drag-handle').addEventListener('pointerdown', (e) => startDrag(e, uid));
        });
    }

    function addItem(type) {
        commitEditingItem();
        const item = { uid: nextUid++, type, ...blankItem(type) };
        items.push(item);
        editingUid = item.uid;
        render();
    }

    function startDrag(event, uid) {
        if (event.pointerType === 'mouse' && event.button !== 0) {
            return;
        }
        commitEditingItem();
        render();

        const row = container.querySelector('[data-uid="' + uid + '"]');
        if (!row) {
            return;
        }
        const rect = row.getBoundingClientRect();
        const ghost = row.cloneNode(true);
        ghost.classList.add('editable-item-ghost');
        ghost.style.width = rect.width + 'px';
        ghost.style.left = rect.left + 'px';
        ghost.style.top = rect.top + 'px';
        document.body.appendChild(ghost);

        dragState = { pointerId: event.pointerId, uid, ghost, offsetY: event.clientY - rect.top };
        ghost.setPointerCapture(event.pointerId);
        ghost.addEventListener('pointermove', onDragMove);
        ghost.addEventListener('pointerup', endDrag);
        ghost.addEventListener('pointercancel', endDrag);
        event.preventDefault();
    }

    function onDragMove(event) {
        if (!dragState || event.pointerId !== dragState.pointerId) {
            return;
        }
        dragState.ghost.style.top = (event.clientY - dragState.offsetY) + 'px';

        const rows = Array.from(container.querySelectorAll('.editable-item'));
        const fromIndex = items.findIndex((i) => i.uid === dragState.uid);
        if (fromIndex === -1) {
            return;
        }
        let targetIndex = rows.length - 1;
        for (let i = 0; i < rows.length; i++) {
            const r = rows[i].getBoundingClientRect();
            if (event.clientY < r.top + r.height / 2) {
                targetIndex = i;
                break;
            }
        }
        if (targetIndex !== fromIndex) {
            const [moved] = items.splice(fromIndex, 1);
            items.splice(targetIndex, 0, moved);
            render();
        }
    }

    function endDrag(event) {
        if (!dragState || event.pointerId !== dragState.pointerId) {
            return;
        }
        dragState.ghost.removeEventListener('pointermove', onDragMove);
        dragState.ghost.removeEventListener('pointerup', endDrag);
        dragState.ghost.removeEventListener('pointercancel', endDrag);
        dragState.ghost.remove();
        dragState = null;
    }

    addButtons.forEach(({ id, type }) => {
        document.getElementById(id).addEventListener('click', () => addItem(type));
    });

    render();

    return {
        getData: (mapItem) => {
            commitEditingItem();

            return items.map(mapItem).filter((i) => i !== null);
        },
    };
}

function wireIngredientEditor(initialIngredients) {
    const list = wireEditableItemList({
        containerId: 'ingredientRows',
        initialData: initialIngredients,
        itemFromData: (i) => ({
            type: i.is_heading ? 'heading' : 'ingredient',
            name: i.name || '',
            amount: i.amount ?? '',
            unit: i.unit || '',
            note: i.note || '',
        }),
        blankItem: () => ({ name: '', amount: '', unit: '', note: '' }),
        isBlankNewItem: (item) => !item.name,
        renderDisplay: (item) => (item.type === 'heading' ? ingredientHeadingDisplayHtml(item) : ingredientDisplayHtml(item)),
        renderEdit: (item) => (item.type === 'heading' ? ingredientHeadingEditHtml(item) : ingredientEditHtml(item)),
        commitFromRow: (item, row) => {
            if (item.type === 'heading') {
                item.name = row.querySelector('.ing-heading-name').value.trim();
            } else {
                item.name = row.querySelector('.ing-name').value.trim();
                item.amount = row.querySelector('.ing-amount').value;
                item.unit = row.querySelector('.ing-unit').value.trim();
                item.note = row.querySelector('.ing-note').value.trim();
            }
        },
        addButtons: [{ id: 'addIngredientBtn', type: 'ingredient' }, { id: 'addSectionBtn', type: 'heading' }],
    });

    return {
        getIngredients: () => list.getData((i) => {
            const name = i.name.trim();
            if (!name) {
                return null;
            }

            return {
                name,
                is_heading: i.type === 'heading',
                amount: i.type === 'heading' || i.amount === '' || i.amount === null || i.amount === undefined ? null : i.amount,
                unit: i.type === 'heading' ? null : i.unit.trim(),
                note: i.type === 'heading' ? null : i.note.trim(),
            };
        }),
    };
}

function wireStepEditor(initialSteps) {
    const list = wireEditableItemList({
        containerId: 'stepRows',
        initialData: initialSteps,
        itemFromData: (s) => ({ type: s.is_heading ? 'heading' : 'step', text: s.instruction || '' }),
        blankItem: () => ({ text: '' }),
        isBlankNewItem: (item) => !item.text,
        renderDisplay: (item) => (item.type === 'heading' ? stepHeadingDisplayHtml(item) : stepDisplayHtml(item)),
        renderEdit: (item) => (item.type === 'heading' ? stepHeadingEditHtml(item) : stepEditHtml(item)),
        commitFromRow: (item, row) => {
            const input = row.querySelector(item.type === 'heading' ? '.step-heading-input' : '.step-text-input');
            item.text = input.value.trim();
        },
        addButtons: [{ id: 'addStepBtn', type: 'step' }, { id: 'addStepSectionBtn', type: 'heading' }],
    });

    return {
        getSteps: () => list.getData((s) => {
            const text = s.text.trim();
            if (!text) {
                return null;
            }

            return { instruction: text, is_heading: s.type === 'heading' };
        }),
    };
}
