/**
 * FitPal Restaurant Product Edit / Create — client behaviour.
 *
 * Owns the interactive surface of restaurant/pages/product-edit.php.
 *
 * ---------------------------------------------------------------------
 * WHAT THIS FILE OWNS
 * ---------------------------------------------------------------------
 *   - Tab switching (Details / Images / Customization).
 *   - Customizable toggle: shows or hides the base price field and
 *     the composition editor.
 *   - Composition editor: one row per ingredient with min / max /
 *     default / required / price / order controls, plus a custom
 *     searchable ingredient picker.
 *   - Ingredient master list: fetched once via get_ingredients and
 *     cached for the page's lifetime.
 *   - Five-slot image grid.
 *   - Live form validation on blur.
 *   - Submit: multipart POST to the handler.
 *   - Unsaved-changes guard on Back and Cancel.
 *
 * ---------------------------------------------------------------------
 * CUSTOM INGREDIENT PICKER
 * ---------------------------------------------------------------------
 * The ingredient field is not a native <select>. The replacement is:
 *
 *   .ingredient-picker                 wrapper
 *     input.row-ingredient[type=hidden]  carries the chosen id
 *     .ingredient-picker-field           the closed surface
 *       input.ingredient-picker-search     the text input
 *       span.ingredient-picker-chevron     drawn in CSS
 *       span.ingredient-picker-chip        shows the chosen name
 *     .ingredient-picker-panel           the open list
 *       button.ingredient-picker-option    one row per ingredient
 *
 * The picker:
 *   - opens on focus or click of the field
 *   - filters the master list as the user types
 *   - highlights the focused row in the brand colour
 *   - selects on click or on Enter
 *   - closes on Escape, on outside click, or on selection
 *
 * Only one picker can be open at a time.
 *
 * ---------------------------------------------------------------------
 * AUTOCLOSE BUG — WHY IT HAPPENED AND HOW IT IS FIXED
 * ---------------------------------------------------------------------
 * A document-level click listener closes the active picker so that
 * clicking anywhere outside it dismisses it. That listener is
 * correct in principle. The bug was that it fired for clicks that
 * originated INSIDE the picker as well, because the picker's own
 * handlers did not stop the click from bubbling. The sequence was:
 *
 *   1. User clicks an option inside the panel.
 *   2. The option's mousedown fires, calls choose(), closes the
 *      picker, and stops the mousedown from bubbling.
 *   3. The browser then dispatches a click event for the same
 *      gesture. Because nothing stopped it, it bubbles to the
 *      document.
 *   4. The document's click listener runs, sees no open picker is
 *      "safe" to keep, and closes whichever picker is currently
 *      registered.
 *
 * The fix is two-part:
 *
 *   a. Every listener inside the picker stops BOTH mousedown and
 *      click from propagating.
 *   b. The document-level listener ignores any click whose target
 *      is inside an .ingredient-picker.
 *
 * Either guard alone would be sufficient; both are applied so a
 * future change to one path cannot reintroduce the bug.
 *
 * @package FitPal
 * @version 2.5 — Autoclose fix. Document click listener ignores
 *                clicks originating inside .ingredient-picker, and
 *                every picker-internal listener stops both mousedown
 *                and click from bubbling.
 *
 *                (2.4: partial autoclose fix. 2.3: custom picker.
 *                2.2: server-rendered slot previews. 2.1:
 *                scrollbar-width compensation. 2.0: tab layout and
 *                5-slot grid. 1.0: single upload area.)
 */

(function () {
    'use strict';

    function boot() {
        var page = document.getElementById('productEditPage');
        if (!page) return;

        var form = document.getElementById('productForm');
        if (!form) return;

        var config      = window.FITPAL_PRODUCT_EDIT_CONFIG || {};
        var CSRF_TOKEN  = page.dataset.csrfToken  || config.csrfToken  || '';
        var HANDLER_URL = page.dataset.handlerUrl || config.handlerUrl || '../backend/handlers/product-handler.php';
        var ASSET_BASE  = page.dataset.assetBase  || config.assetBase  || '../../shared/';
        var IS_EDIT     = (page.dataset.editMode === '1');

        /* ============================================
           ELEMENTS
           ============================================ */

        var tabButtons = page.querySelectorAll('.product-tab');
        var tabPanels  = page.querySelectorAll('.product-tab-panel');

        var customizableToggle = document.getElementById('is_customizable');
        var basePriceGroup     = document.getElementById('basePriceGroup');
        var compositionEditor  = document.getElementById('compositionEditor');
        var compositionRows    = document.getElementById('compositionRows');
        var addIngredientBtn   = document.getElementById('addIngredientBtn');
        var compositionError   = document.getElementById('compositionError');

        var imageSlotGrid = document.getElementById('imageSlotGrid');
        var imagesError   = document.getElementById('imagesError');

        var cancelBtn      = document.getElementById('cancelBtn');
        var backBtn        = document.getElementById('backBtn');
        var submitBtn      = document.getElementById('submitBtn');
        var submitBtnLabel = document.getElementById('submitBtnLabel');

        var validationModal   = document.getElementById('validationModal');
        var validationMessage = document.getElementById('validationMessage');
        var successModal      = document.getElementById('successModal');
        var successMessage    = document.getElementById('successMessage');
        var successOkBtn      = document.getElementById('successOkBtn');
        var errorModal        = document.getElementById('errorModal');
        var errorMessage      = document.getElementById('errorMessage');
        var leaveModal        = document.getElementById('leaveModal');
        var confirmLeaveBtn   = document.getElementById('confirmLeaveBtn');

        /* ============================================
           STATE
           ============================================ */

        var ingredientMaster = null;
        var ingredientFetch  = null;
        var formChanged      = false;
        var isSubmitting     = false;

        var slotState = {};

        // The picker that is currently open, or null.
        var activePicker = null;

        /* ============================================
           SCROLLBAR-WIDTH HELPERS
           ============================================ */

        var cachedScrollbarWidth = null;

        function getScrollbarWidth() {
            if (cachedScrollbarWidth !== null) {
                return cachedScrollbarWidth;
            }

            var outer = document.createElement('div');
            outer.style.visibility = 'hidden';
            outer.style.overflow = 'scroll';
            outer.style.msOverflowStyle = 'scrollbar';
            outer.style.position = 'absolute';
            outer.style.top = '-9999px';
            outer.style.width = '100px';
            outer.style.height = '100px';
            document.body.appendChild(outer);

            var inner = document.createElement('div');
            inner.style.width = '100%';
            outer.appendChild(inner);

            cachedScrollbarWidth = outer.offsetWidth - inner.offsetWidth;

            outer.parentNode.removeChild(outer);
            return cachedScrollbarWidth;
        }

        function lockBodyScroll() {
            var scrollbarWidth = getScrollbarWidth();

            if (!document.body.dataset.originalPaddingRight) {
                document.body.dataset.originalPaddingRight =
                    document.body.style.paddingRight || '';
            }

            if (scrollbarWidth > 0) {
                document.body.style.paddingRight = scrollbarWidth + 'px';
            }

            document.body.style.overflow = 'hidden';
        }

        function unlockBodyScroll() {
            var originalPadding = document.body.dataset.originalPaddingRight || '';
            document.body.style.paddingRight = originalPadding;
            document.body.style.overflow = '';
        }

        /* ============================================
           MODAL HELPERS
           ============================================ */

        function openModal(modal) {
            if (!modal) return;
            lockBodyScroll();
            modal.style.display = 'flex';
            void modal.offsetWidth;
            modal.classList.add('is-open');
        }

        function closeModal(modal) {
            if (!modal) return;
            modal.classList.remove('is-open');
            setTimeout(function () {
                if (!modal.classList.contains('is-open')) {
                    modal.style.display = 'none';
                    var stillOpen = document.querySelector('.modal.is-open');
                    if (!stillOpen) {
                        unlockBodyScroll();
                    }
                }
            }, 200);
        }

        page.querySelectorAll('[data-close-modal]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                var which = this.dataset.closeModal;
                if (which === 'validation-modal') closeModal(validationModal);
                if (which === 'error-modal')      closeModal(errorModal);
                if (which === 'leave-modal')      closeModal(leaveModal);
            });
        });

        function showValidation(message) {
            if (validationMessage) validationMessage.textContent = message;
            openModal(validationModal);
        }

        function showError(message) {
            if (errorMessage) errorMessage.textContent = message;
            openModal(errorModal);
        }

        function showSuccess(message, redirect) {
            if (successMessage) successMessage.textContent = message;
            openModal(successModal);
            successOkBtn.addEventListener('click', function () {
                window.location.href = redirect || 'products.php';
            }, { once: true });
        }

        /* ============================================
           TABS
           ============================================ */

        function activateTab(tabKey) {
            tabButtons.forEach(function (btn) {
                var isActive = btn.dataset.tab === tabKey;
                btn.classList.toggle('active', isActive);
                btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });

            tabPanels.forEach(function (panel) {
                var matches = panel.id === 'tab-' + tabKey;
                panel.classList.toggle('active', matches);
                if (matches) {
                    panel.removeAttribute('hidden');
                } else {
                    panel.setAttribute('hidden', '');
                }
            });

            closeActivePicker();
        }

        tabButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                activateTab(this.dataset.tab || 'details');
            });
        });

        function focusTab(tabKey) {
            activateTab(tabKey);
            var panel = document.getElementById('tab-' + tabKey);
            if (panel && typeof panel.scrollIntoView === 'function') {
                panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        /* ============================================
           CUSTOMIZABLE TOGGLE
           ============================================ */

        function syncCustomizableVisibility() {
            if (!customizableToggle) return;

            var on = customizableToggle.checked;

            if (basePriceGroup) {
                basePriceGroup.style.display = on ? '' : 'none';
            }
            if (compositionEditor) {
                compositionEditor.style.display = on ? '' : 'none';
            }

            if (on && ingredientMaster === null) {
                fetchIngredients();
            }
        }

        if (customizableToggle) {
            customizableToggle.addEventListener('change', function () {
                formChanged = true;
                syncCustomizableVisibility();
            });
        }

        /* ============================================
           INGREDIENT MASTER
           ============================================ */

        function fetchIngredients() {
            if (ingredientMaster !== null) {
                return Promise.resolve(ingredientMaster);
            }
            if (ingredientFetch !== null) {
                return ingredientFetch;
            }

            var body = new URLSearchParams();
            body.append('action', 'get_ingredients');
            body.append('csrf_token', CSRF_TOKEN);

            ingredientFetch = fetch(HANDLER_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString(),
                credentials: 'same-origin'
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.status === 'success' && Array.isArray(data.ingredients)) {
                    ingredientMaster = data.ingredients;
                    return ingredientMaster;
                }
                throw new Error((data && data.message) || 'Could not load ingredients.');
            })
            .catch(function (err) {
                console.error('[product-edit.js] get_ingredients failed:', err);
                ingredientMaster = [];
                showError('Could not load the ingredient list. Please refresh and try again.');
                return [];
            })
            .finally(function () {
                ingredientFetch = null;
            });

            return ingredientFetch;
        }

        function findIngredientById(id) {
            if (!Array.isArray(ingredientMaster)) return null;
            var target = parseInt(id, 10) || 0;
            if (target <= 0) return null;

            for (var i = 0; i < ingredientMaster.length; i++) {
                if (parseInt(ingredientMaster[i].ingredient_id, 10) === target) {
                    return ingredientMaster[i];
                }
            }
            return null;
        }

        /* ============================================
           CUSTOM INGREDIENT PICKER
           ============================================ */

        function closeActivePicker() {
            if (!activePicker) return;
            var toClose = activePicker;
            activePicker = null;
            toClose.close();
        }

        // ---- Document-level outside-click close ----
        //
        // Closes the active picker when the user clicks anywhere
        // that is NOT inside the picker. The early return is the
        // primary guard against the autoclose bug: a click that
        // originated inside .ingredient-picker must never trigger
        // this handler, no matter what state the picker is in.
        document.addEventListener('click', function (e) {
            if (!activePicker) return;
            if (e.target.closest('.ingredient-picker')) return;
            closeActivePicker();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && activePicker) {
                closeActivePicker();
            }
        });

        function createIngredientPicker(rowData) {
            rowData = rowData || {};

            var wrapper = document.createElement('div');
            wrapper.className = 'ingredient-picker';

            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.className = 'row-ingredient';
            hidden.value = rowData.ingredient_id ? String(rowData.ingredient_id) : '';
            wrapper.appendChild(hidden);

            var field = document.createElement('div');
            field.className = 'ingredient-picker-field';
            field.setAttribute('tabindex', '-1');

            var search = document.createElement('input');
            search.type = 'text';
            search.className = 'ingredient-picker-search';
            search.autocomplete = 'off';
            search.placeholder = 'Search ingredients…';
            field.appendChild(search);

            var chip = document.createElement('span');
            chip.className = 'ingredient-picker-chip';
            chip.hidden = true;
            field.appendChild(chip);

            var chevron = document.createElement('span');
            chevron.className = 'ingredient-picker-chevron';
            chevron.setAttribute('aria-hidden', 'true');
            field.appendChild(chevron);

            wrapper.appendChild(field);

            var panel = document.createElement('div');
            panel.className = 'ingredient-picker-panel';
            panel.hidden = true;
            wrapper.appendChild(panel);

            var filtered = [];
            var highlight = -1;
            var isOpen = false;

            function updateChipAndSearch() {
                var id = parseInt(hidden.value, 10) || 0;
                var ing = id > 0 ? findIngredientById(id) : null;

                if (ing) {
                    chip.textContent = ing.name;
                    chip.hidden = false;
                    search.value = '';
                    search.placeholder = '';
                } else {
                    chip.hidden = true;
                    search.placeholder = 'Search ingredients…';
                }
            }

            function matchesFilter(ing, q) {
                if (q === '') return true;
                var hay = (ing.name || '').toLowerCase();
                var tags = (ing.dietary_tags || '').toLowerCase();
                return hay.indexOf(q) !== -1 || tags.indexOf(q) !== -1;
            }

            function rebuildFiltered(q) {
                var lower = q.toLowerCase().trim();
                filtered = (ingredientMaster || []).filter(function (ing) {
                    return matchesFilter(ing, lower);
                });
                highlight = filtered.length > 0 ? 0 : -1;
            }

            function renderPanel() {
                panel.innerHTML = '';

                if (filtered.length === 0) {
                    var empty = document.createElement('div');
                    empty.className = 'ingredient-picker-empty';
                    empty.textContent = (ingredientMaster && ingredientMaster.length > 0)
                        ? 'No ingredients match your search.'
                        : 'No ingredients available.';
                    panel.appendChild(empty);
                    return;
                }

                filtered.forEach(function (ing, index) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'ingredient-picker-option';
                    if (index === highlight) {
                        btn.classList.add('is-highlighted');
                    }

                    var nameSpan = document.createElement('span');
                    nameSpan.className = 'ingredient-picker-option-name';
                    nameSpan.textContent = ing.name || '';
                    btn.appendChild(nameSpan);

                    var metaParts = [];
                    var price = parseFloat(ing.unit_price) || 0;
                    if (price > 0) {
                        metaParts.push('₱' + price.toFixed(2));
                    }
                    var cals = parseInt(ing.calories, 10) || 0;
                    if (cals > 0) {
                        metaParts.push(cals + ' kcal');
                    }

                    if (metaParts.length > 0) {
                        var metaSpan = document.createElement('span');
                        metaSpan.className = 'ingredient-picker-option-meta';
                        metaSpan.textContent = metaParts.join(' · ');
                        btn.appendChild(metaSpan);
                    }

                    // The option's mousedown runs first and selects.
                    // Both mousedown and click are stopped so the
                    // document-level outside-click handler never
                    // sees them.
                    btn.addEventListener('mousedown', function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        choose(ing);
                    });

                    btn.addEventListener('click', function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                    });

                    panel.appendChild(btn);
                });
            }

            function setHighlight(index) {
                var options = panel.querySelectorAll('.ingredient-picker-option');
                if (options.length === 0) {
                    highlight = -1;
                    return;
                }

                if (index < 0) index = 0;
                if (index >= options.length) index = options.length - 1;

                highlight = index;
                options.forEach(function (el, i) {
                    el.classList.toggle('is-highlighted', i === highlight);
                });

                var el = options[highlight];
                if (el && typeof el.scrollIntoView === 'function') {
                    el.scrollIntoView({ block: 'nearest' });
                }
            }

            function open() {
                if (isOpen) return;
                if (ingredientMaster === null) {
                    fetchIngredients().then(function () {
                        open();
                    });
                    return;
                }

                closeActivePicker();

                isOpen = true;
                activePicker = { close: close };

                rebuildFiltered(search.value);
                renderPanel();
                panel.hidden = false;
                wrapper.classList.add('is-open');

                setTimeout(function () { search.focus(); }, 0);
            }

            function close() {
                if (!isOpen) return;
                isOpen = false;
                if (activePicker && activePicker.close === close) {
                    activePicker = null;
                }
                panel.hidden = true;
                wrapper.classList.remove('is-open');
                search.value = '';
            }

            function choose(ing) {
                hidden.value = String(ing.ingredient_id);
                updateChipAndSearch();
                formChanged = true;
                close();
                if (typeof rowData.onChange === 'function') {
                    rowData.onChange();
                }
            }

            // ---- Field events ----
            // mousedown opens the picker. click is swallowed so the
            // document handler does not fire and close it again.
            field.addEventListener('mousedown', function (e) {
                e.stopPropagation();
                if (isOpen) {
                    search.focus();
                    return;
                }
                open();
            });

            field.addEventListener('click', function (e) {
                e.stopPropagation();
            });

            search.addEventListener('input', function () {
                if (!isOpen) {
                    open();
                    return;
                }
                rebuildFiltered(search.value);
                renderPanel();
                setHighlight(0);
            });

            search.addEventListener('keydown', function (e) {
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (!isOpen) { open(); return; }
                    setHighlight(highlight + 1);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (!isOpen) return;
                    setHighlight(highlight - 1);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (!isOpen) { open(); return; }
                    if (highlight >= 0 && filtered[highlight]) {
                        choose(filtered[highlight]);
                    }
                } else if (e.key === 'Escape') {
                    if (isOpen) {
                        e.stopPropagation();
                        close();
                        search.blur();
                    }
                } else if (e.key === 'Tab') {
                    if (isOpen) close();
                }
            });

            // ---- Panel events ----
            // Both mousedown and click are stopped so neither the
            // document outside-click handler nor any other listener
            // sees a click that originated inside the panel.
            panel.addEventListener('mousedown', function (e) {
                e.stopPropagation();
            });

            panel.addEventListener('click', function (e) {
                e.stopPropagation();
            });

            updateChipAndSearch();

            return wrapper;
        }

        /* ============================================
           COMPOSITION ROWS
           ============================================ */

        function createRow(rowData) {
            rowData = rowData || {};

            var row = document.createElement('div');
            row.className = 'composition-row';

            var ingredientField = document.createElement('div');
            ingredientField.className = 'composition-field composition-field-ingredient';
            ingredientField.appendChild(createIngredientPicker({
                ingredient_id: rowData.ingredient_id,
                onChange: function () { formChanged = true; }
            }));
            row.appendChild(ingredientField);

            var minField = document.createElement('div');
            minField.className = 'composition-field composition-field-min';
            var minLabel = document.createElement('span');
            minLabel.className = 'composition-field-label';
            minLabel.textContent = 'Min';
            minField.appendChild(minLabel);
            var minInput = document.createElement('input');
            minInput.type = 'number';
            minInput.min = '0';
            minInput.step = '1';
            minInput.className = 'row-min';
            minInput.value = rowData.min_quantity !== undefined ? rowData.min_quantity : 0;
            minField.appendChild(minInput);
            row.appendChild(minField);

            var maxField = document.createElement('div');
            maxField.className = 'composition-field composition-field-max';
            var maxLabel = document.createElement('span');
            maxLabel.className = 'composition-field-label';
            maxLabel.textContent = 'Max';
            maxField.appendChild(maxLabel);
            var maxInput = document.createElement('input');
            maxInput.type = 'number';
            maxInput.min = '0';
            maxInput.step = '1';
            maxInput.className = 'row-max';
            maxInput.value = rowData.max_quantity !== undefined ? rowData.max_quantity : 1;
            maxField.appendChild(maxInput);
            row.appendChild(maxField);

            var defField = document.createElement('div');
            defField.className = 'composition-field composition-field-default';
            var defLabel = document.createElement('span');
            defLabel.className = 'composition-field-label';
            defLabel.textContent = 'Default';
            defField.appendChild(defLabel);
            var defToggle = document.createElement('label');
            defToggle.className = 'flag-toggle flag-default' + (rowData.is_default ? ' is-on' : '');
            var defCheckbox = document.createElement('input');
            defCheckbox.type = 'checkbox';
            defCheckbox.className = 'row-is-default';
            defCheckbox.checked = !!rowData.is_default;
            defToggle.appendChild(defCheckbox);
            defToggle.appendChild(document.createTextNode('Default'));
            defField.appendChild(defToggle);
            row.appendChild(defField);

            var defQtyField = document.createElement('div');
            defQtyField.className = 'composition-field composition-field-default-qty';
            var defQtyLabel = document.createElement('span');
            defQtyLabel.className = 'composition-field-label';
            defQtyLabel.textContent = 'Default qty';
            defQtyField.appendChild(defQtyLabel);
            var defQtyInput = document.createElement('input');
            defQtyInput.type = 'number';
            defQtyInput.min = '0';
            defQtyInput.step = '1';
            defQtyInput.className = 'row-default-qty';
            defQtyInput.value = rowData.default_quantity !== undefined ? rowData.default_quantity : 0;
            defQtyField.appendChild(defQtyInput);
            row.appendChild(defQtyField);

            var priceField = document.createElement('div');
            priceField.className = 'composition-field composition-field-price';
            var priceLabel = document.createElement('span');
            priceLabel.className = 'composition-field-label';
            priceLabel.textContent = 'Price ±';
            priceField.appendChild(priceLabel);
            var priceInput = document.createElement('input');
            priceInput.type = 'number';
            priceInput.step = '0.01';
            priceInput.className = 'row-price';
            priceInput.value = rowData.price_modifier !== undefined ? rowData.price_modifier : 0;
            priceField.appendChild(priceInput);
            row.appendChild(priceField);

            var orderField = document.createElement('div');
            orderField.className = 'composition-field composition-field-order';
            var orderLabel = document.createElement('span');
            orderLabel.className = 'composition-field-label';
            orderLabel.textContent = 'Order';
            orderField.appendChild(orderLabel);
            var orderInput = document.createElement('input');
            orderInput.type = 'number';
            orderInput.min = '0';
            orderInput.step = '1';
            orderInput.className = 'row-order';
            orderInput.value = rowData.display_order !== undefined ? rowData.display_order : 0;
            orderField.appendChild(orderInput);
            row.appendChild(orderField);

            var flagsField = document.createElement('div');
            flagsField.className = 'composition-field composition-field-flags';
            var flagsLabel = document.createElement('span');
            flagsLabel.className = 'composition-field-label';
            flagsLabel.textContent = 'Required';
            flagsField.appendChild(flagsLabel);
            var requiredToggle = document.createElement('label');
            requiredToggle.className = 'flag-toggle flag-required' + (rowData.is_required ? ' is-on' : '');
            var requiredCheckbox = document.createElement('input');
            requiredCheckbox.type = 'checkbox';
            requiredCheckbox.className = 'row-is-required';
            requiredCheckbox.checked = !!rowData.is_required;
            requiredToggle.appendChild(requiredCheckbox);
            requiredToggle.appendChild(document.createTextNode('Required'));
            flagsField.appendChild(requiredToggle);
            row.appendChild(flagsField);

            var actionsField = document.createElement('div');
            actionsField.className = 'composition-field composition-field-actions';
            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'remove-row-btn';
            removeBtn.setAttribute('aria-label', 'Remove ingredient');

            var removeIcon = document.createElement('img');
            removeIcon.src = ASSET_BASE + 'assets/images/icons/trash.svg';
            removeIcon.alt = '';
            removeIcon.width = 14;
            removeIcon.height = 14;
            removeIcon.onerror = function () {
                this.onerror = null;
                this.src = ASSET_BASE + 'assets/images/icons/remove-line.svg';
            };
            removeBtn.appendChild(removeIcon);
            actionsField.appendChild(removeBtn);
            row.appendChild(actionsField);

            function markChanged() { formChanged = true; }

            [minInput, maxInput, defQtyInput, priceInput, orderInput].forEach(function (input) {
                input.addEventListener('input', markChanged);
            });
            defCheckbox.addEventListener('change', function () {
                defToggle.classList.toggle('is-on', this.checked);
                markChanged();
            });
            requiredCheckbox.addEventListener('change', function () {
                requiredToggle.classList.toggle('is-on', this.checked);
                markChanged();
            });
            removeBtn.addEventListener('click', function () {
                formChanged = true;
                row.parentNode.removeChild(row);
            });

            return row;
        }

        function addRow(rowData) {
            if (!compositionRows) return;
            compositionRows.appendChild(createRow(rowData));
        }

        if (addIngredientBtn) {
            addIngredientBtn.addEventListener('click', function () {
                if (ingredientMaster === null) {
                    fetchIngredients().then(function () { addRow({}); });
                    return;
                }
                addRow({});
            });
        }

        function loadInitialComposition() {
            if (!compositionRows) return;

            var raw = page.dataset.initialComposition || '[]';
            var rows;
            try { rows = JSON.parse(raw); }
            catch (err) { rows = []; }

            if (!Array.isArray(rows) || rows.length === 0) return;

            fetchIngredients().then(function () {
                rows.forEach(function (row) { addRow(row); });
            });
        }

        /* ============================================
           IMAGE SLOTS
           ============================================ */

        function revokePreviewUrl(slotIndex) {
            var st = slotState[slotIndex];
            if (!st || !st.previewUrl) return;
            try { URL.revokeObjectURL(st.previewUrl); }
            catch (e) { /* noop */ }
            st.previewUrl = '';
        }

        function initImageSlots() {
            if (!imageSlotGrid) return;

            var slots = imageSlotGrid.querySelectorAll('.image-slot');

            slots.forEach(function (slotEl) {
                var slotIndex = parseInt(slotEl.dataset.slot, 10);
                if (slotIndex < 1 || slotIndex > 5) return;

                var hasExisting = slotEl.dataset.hasExisting === '1';
                var existingUrl = slotEl.dataset.existingUrl || '';

                slotState[slotIndex] = {
                    mode: 'keep',
                    url: hasExisting ? existingUrl : '',
                    hadExisting: hasExisting,
                    previewUrl: ''
                };

                var input     = slotEl.querySelector('.image-slot-input');
                var opInput   = slotEl.querySelector('input[name^="image_op"]');
                var removeBtn = slotEl.querySelector('.image-slot-remove');
                var newBadge  = slotEl.querySelector('.image-slot-badge-new');
                var preview   = slotEl.querySelector('.image-slot-preview');

                if (!input || !opInput || !preview) return;

                function syncSlotUi() {
                    var state     = slotState[slotIndex];
                    var hasFile   = state.mode === 'replace';
                    var hasServer = state.mode === 'keep' && state.hadExisting;
                    var isEmpty   = !hasFile && !hasServer;

                    slotEl.classList.toggle('has-image', hasServer);
                    slotEl.classList.toggle('has-new-image', hasFile);
                    slotEl.classList.toggle('is-empty', isEmpty);

                    if (hasFile && state.url) {
                        preview.src = state.url;
                        preview.style.display = '';
                    } else if (hasServer && existingUrl) {
                        preview.src = existingUrl;
                        preview.style.display = '';
                    } else {
                        preview.src = '';
                        preview.style.display = 'none';
                    }

                    if (newBadge) {
                        newBadge.hidden = !hasFile;
                    }
                    if (removeBtn) {
                        removeBtn.hidden = !(hasServer || hasFile);
                    }

                    opInput.value = state.mode;
                }

                input.addEventListener('change', function () {
                    var file = input.files && input.files[0];
                    if (!file) return;

                    revokePreviewUrl(slotIndex);

                    var url = URL.createObjectURL(file);

                    slotState[slotIndex] = {
                        mode: 'replace',
                        url: url,
                        hadExisting: hasExisting,
                        previewUrl: url
                    };

                    formChanged = true;
                    syncSlotUi();
                });

                if (removeBtn) {
                    removeBtn.addEventListener('click', function (e) {
                        e.preventDefault();
                        e.stopPropagation();

                        var state = slotState[slotIndex];

                        if (state.mode === 'replace') {
                            revokePreviewUrl(slotIndex);
                            input.value = '';

                            slotState[slotIndex] = {
                                mode: 'keep',
                                url: hasExisting ? existingUrl : '',
                                hadExisting: hasExisting,
                                previewUrl: ''
                            };
                        } else if (state.hadExisting) {
                            slotState[slotIndex] = {
                                mode: 'remove',
                                url: '',
                                hadExisting: true,
                                previewUrl: ''
                            };
                        } else {
                            return;
                        }

                        formChanged = true;
                        syncSlotUi();
                    });
                }

                syncSlotUi();
            });
        }

        /* ============================================
           LIVE VALIDATION
           ============================================ */

        function markError(input, errorEl, message) {
            if (input) input.classList.add('error');
            if (errorEl) errorEl.textContent = message;
        }

        function clearError(input, errorEl) {
            if (input) input.classList.remove('error');
            if (errorEl) errorEl.textContent = '';
        }

        function fieldError(id) {
            return document.getElementById(id + 'Error');
        }

        function wireBlurValidation(inputId, validator) {
            var input = document.getElementById(inputId);
            if (!input) return;
            var errorEl = fieldError(inputId);

            input.addEventListener('blur', function () {
                var result = validator(this.value);
                if (result) {
                    markError(this, errorEl, result);
                } else {
                    clearError(this, errorEl);
                }
            });

            input.addEventListener('input', function () {
                if (this.classList.contains('error')) {
                    clearError(this, errorEl);
                }
                formChanged = true;
            });
        }

        wireBlurValidation('name', function (v) {
            v = (v || '').trim();
            if (v === '') return 'Product name is required.';
            if (v.length < 2) return 'Product name must be at least 2 characters.';
            return '';
        });

        wireBlurValidation('price', function (v) {
            if (v === '') return 'Price is required.';
            var n = parseFloat(v);
            if (isNaN(n)) return 'Price must be a number.';
            if (n < 0)    return 'Price cannot be negative.';
            return '';
        });

        wireBlurValidation('stock', function (v) {
            if (v === '') return 'Stock is required.';
            if (!/^\d+$/.test(v)) return 'Stock must be a whole number.';
            return '';
        });

        wireBlurValidation('base_price', function (v) {
            if (!customizableToggle || !customizableToggle.checked) return '';
            if (v === '') return 'Base price is required for customizable products.';
            var n = parseFloat(v);
            if (isNaN(n)) return 'Base price must be a number.';
            if (n < 0)    return 'Base price cannot be negative.';
            return '';
        });

        /* ============================================
           PAYLOAD BUILDER
           ============================================ */

        function collectComposition() {
            if (!customizableToggle || !customizableToggle.checked) {
                return [];
            }
            if (!compositionRows) return [];

            var out = [];
            var rowEls = compositionRows.querySelectorAll('.composition-row');

            rowEls.forEach(function (row) {
                var hidden = row.querySelector('.row-ingredient');
                var ingredientId = parseInt(hidden ? hidden.value : '0', 10) || 0;
                if (ingredientId <= 0) return;

                var minQty = parseInt(row.querySelector('.row-min').value, 10) || 0;
                var maxQty = parseInt(row.querySelector('.row-max').value, 10) || 0;
                var defQty = parseInt(row.querySelector('.row-default-qty').value, 10) || 0;

                if (maxQty < minQty) maxQty = minQty;
                if (defQty < minQty) defQty = minQty;
                if (defQty > maxQty) defQty = maxQty;

                out.push({
                    ingredient_id:    ingredientId,
                    is_default:       row.querySelector('.row-is-default').checked ? 1 : 0,
                    default_quantity: defQty,
                    min_quantity:     minQty,
                    max_quantity:     maxQty,
                    price_modifier:   parseFloat(row.querySelector('.row-price').value) || 0,
                    display_order:    parseInt(row.querySelector('.row-order').value, 10) || 0,
                    is_required:      row.querySelector('.row-is-required').checked ? 1 : 0
                });
            });

            return out;
        }

        /* ============================================
           CLIENT VALIDATION BEFORE SUBMIT
           ============================================ */

        function validateBeforeSubmit() {
            var firstError = null;

            function fail(id, message, tabKey) {
                if (firstError) return;
                firstError = { id: id, message: message, tab: tabKey || 'details' };
            }

            var nameVal  = (document.getElementById('name').value || '').trim();
            var priceVal = (document.getElementById('price').value || '').trim();
            var stockVal = (document.getElementById('stock').value || '').trim();

            if (nameVal === '')   fail('name',  'Product name is required.', 'details');
            if (priceVal === '')  fail('price', 'Price is required.', 'details');
            else if (isNaN(parseFloat(priceVal))) fail('price', 'Price must be a number.', 'details');
            if (stockVal === '')  fail('stock', 'Stock is required.', 'details');
            else if (!/^\d+$/.test(stockVal)) fail('stock', 'Stock must be a whole number.', 'details');

            if (customizableToggle && customizableToggle.checked) {
                var basePriceVal = (document.getElementById('base_price').value || '').trim();
                if (basePriceVal === '') {
                    fail('base_price', 'Base price is required for customizable products.', 'customization');
                } else if (isNaN(parseFloat(basePriceVal))) {
                    fail('base_price', 'Base price must be a number.', 'customization');
                }

                var rows = collectComposition();
                if (rows.length === 0) {
                    fail('composition', 'Add at least one ingredient to a customizable product.', 'customization');
                }
            }

            var slotHasImage = false;
            Object.keys(slotState).forEach(function (k) {
                var st = slotState[k];
                if (st.mode === 'replace') { slotHasImage = true; return; }
                if (st.mode === 'keep' && st.hadExisting) { slotHasImage = true; }
            });

            if (!slotHasImage) {
                fail('images', 'Please add at least one product image.', 'images');
            }

            return firstError;
        }

        /* ============================================
           SUBMIT
           ============================================ */

        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();

                if (isSubmitting) return;

                closeActivePicker();

                var err = validateBeforeSubmit();
                if (err) {
                    if (err.tab) focusTab(err.tab);

                    var el    = document.getElementById(err.id);
                    var msgEl = fieldError(err.id);
                    if (el)    el.classList.add('error');
                    if (msgEl) msgEl.textContent = err.message;
                    if (err.id === 'images' && imagesError) {
                        imagesError.textContent = err.message;
                    }
                    if (err.id === 'composition' && compositionError) {
                        compositionError.textContent = err.message;
                    }

                    showValidation(err.message);
                    return;
                }

                isSubmitting = true;
                if (submitBtn) submitBtn.disabled = true;
                if (submitBtnLabel) submitBtnLabel.textContent = 'Saving…';

                var formData = new FormData(form);

                formData.set('action', IS_EDIT ? 'update_product' : 'create_product');
                formData.set('csrf_token', CSRF_TOKEN);

                if (IS_EDIT) {
                    formData.set('product_id', String(config.productId || 0));
                }

                formData.set('composition', JSON.stringify(collectComposition()));
                formData.set('is_customizable', customizableToggle && customizableToggle.checked ? '1' : '0');
                formData.set('is_active', document.getElementById('is_active').checked ? '1' : '0');

                fetch(HANDLER_URL, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(function (res) {
                    return res.text().then(function (text) {
                        try { return JSON.parse(text); }
                        catch (parseErr) {
                            console.error('[product-edit.js] Non-JSON response:', res.status, text.slice(0, 200));
                            return {
                                status: 'error',
                                message: 'Server returned an unexpected response.'
                            };
                        }
                    });
                })
                .then(function (data) {
                    if (data && data.status === 'success') {
                        formChanged = false;
                        showSuccess(data.message || 'Product saved.', data.redirect || 'products.php');
                        return;
                    }

                    isSubmitting = false;
                    if (submitBtn) submitBtn.disabled = false;
                    if (submitBtnLabel) submitBtnLabel.textContent = IS_EDIT ? 'Save Changes' : 'Create Product';

                    if (data && data.field) {
                        var f = String(data.field);
                        var input = document.getElementById(f);
                        var errorEl = fieldError(f);
                        if (input)   input.classList.add('error');
                        if (errorEl) errorEl.textContent = data.message || 'Please check this field.';

                        if (f === 'images' && imagesError) {
                            imagesError.textContent = data.message || 'Please check your images.';
                            focusTab('images');
                        }
                        if (f === 'composition' && compositionError) {
                            compositionError.textContent = data.message || 'Please check the composition.';
                            focusTab('customization');
                        }
                        if (f === 'base_price') {
                            focusTab('customization');
                        }
                    }

                    showValidation((data && data.message) || 'Could not save the product.');
                })
                .catch(function (err2) {
                    console.error('[product-edit.js] Submit failed:', err2);
                    isSubmitting = false;
                    if (submitBtn) submitBtn.disabled = false;
                    if (submitBtnLabel) submitBtnLabel.textContent = IS_EDIT ? 'Save Changes' : 'Create Product';
                    showError('Network error. Please try again.');
                });
            });
        }

        /* ============================================
           UNSAVED-CHANGES GUARD
           ============================================ */

        function tryLeave(targetUrl) {
            if (!formChanged) {
                window.location.href = targetUrl;
                return;
            }
            openModal(leaveModal);
            confirmLeaveBtn.addEventListener('click', function () {
                formChanged = false;
                window.location.href = targetUrl;
            }, { once: true });
        }

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function (e) {
                e.preventDefault();
                tryLeave('products.php');
            });
        }

        if (backBtn) {
            backBtn.addEventListener('click', function (e) {
                e.preventDefault();
                tryLeave('products.php');
            });
        }

        window.addEventListener('beforeunload', function (e) {
            if (!formChanged || isSubmitting) return;
            e.preventDefault();
            e.returnValue = '';
        });

        /* ============================================
           MARK FORM AS DIRTY ON EVERY EDITABLE INPUT
           ============================================ */

        form.querySelectorAll('input, textarea, select').forEach(function (input) {
            if (input.type === 'hidden') return;
            if (input.classList.contains('image-slot-input')) return;
            if (input.classList.contains('ingredient-picker-search')) return;
            input.addEventListener('input',  function () { formChanged = true; });
            input.addEventListener('change', function () { formChanged = true; });
        });

        /* ============================================
           INIT
           ============================================ */

        activateTab('details');
        syncCustomizableVisibility();
        initImageSlots();

        if (IS_EDIT && customizableToggle && customizableToggle.checked) {
            loadInitialComposition();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();