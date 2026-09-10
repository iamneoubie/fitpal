/**
 * FitPal Product Detail JavaScript
 * Version 7.0
 *
 * Base price and base calories are read once from the page root.
 * Every subsequent change is a delta against the default state.
 *
 * @package FitPal
 * @version 7.0
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ----------------------------------------------------------
        // DOM
        // ----------------------------------------------------------
        const stepMain               = document.getElementById('stepMain');
        const stepCustomize          = document.getElementById('stepCustomize');
        const customizeBtn           = document.getElementById('customizeBtn');
        const backToMainBtn          = document.getElementById('backToMainBtn');
        const cancelCustomizeBtn     = document.getElementById('cancelCustomizeBtn');
        const applyCustomizeBtn      = document.getElementById('applyCustomizeBtn');
        const addToOrderBtn          = document.getElementById('addToOrderBtn');
        const addToCartBtn           = document.getElementById('addToCartBtn');
        const quantityInput          = document.getElementById('productQuantity');
        const mainTotalPrice         = document.getElementById('mainTotalPrice');
        const mainCaloriesBadge      = document.getElementById('mainCaloriesBadge');
        const customizeTotalPrice    = document.getElementById('customizeTotalPrice');
        const customizeTotalCalories = document.getElementById('customizeTotalCalories');
        const customizationsData     = document.getElementById('customizationsData');
        const totalPriceInput        = document.getElementById('totalPriceInput');
        const totalCaloriesInput     = document.getElementById('totalCaloriesInput');
        const redirectInput          = document.querySelector('input[name="redirect"]');
        const form                   = document.getElementById('actionControlForm');
        const pageRoot               = document.getElementById('productDetailPage');

        // ----------------------------------------------------------
        // BASE (from PHP, derived from defaults)
        // ----------------------------------------------------------
        const BASE_PRICE    = parseFloat(pageRoot.dataset.basePrice) || 0;
        const BASE_CALORIES = parseInt(pageRoot.dataset.baseCalories, 10) || 0;

        // ----------------------------------------------------------
        // HELPERS
        // ----------------------------------------------------------
        function radiosInGroup(groupEl) {
            return Array.from(groupEl.querySelectorAll('input[type="radio"]'));
        }

        function findDefaultRadio(groupEl) {
            return groupEl.querySelector('input[type="radio"][data-is-default="1"]');
        }

        function findCheckedRadio(groupEl) {
            return groupEl.querySelector('input[type="radio"]:checked');
        }

        function syncRadioLabels(groupEl, chosenInput) {
            groupEl.querySelectorAll('.radio-option').forEach(function (lbl) {
                lbl.classList.remove('selected');
            });
            if (chosenInput) {
                const lbl = chosenInput.closest('.radio-option');
                if (lbl) lbl.classList.add('selected');
            }
        }

        // ----------------------------------------------------------
        // SYNC DOM FROM DEFAULTS
        // ----------------------------------------------------------
        function syncDomFromDefaults() {

            // ---- Radios ----
            document.querySelectorAll('.customization-group[data-component-kind="choice"]').forEach(function (group) {
                const fieldset = group.querySelector('.customization-radio-group');
                if (!fieldset) return;

                const defaultRadio = findDefaultRadio(fieldset);
                let chosen = defaultRadio;

                if (!chosen) {
                    chosen = findCheckedRadio(fieldset);
                }
                if (!chosen) {
                    const first = fieldset.querySelector('input[type="radio"]');
                    if (first) chosen = first;
                }

                radiosInGroup(fieldset).forEach(function (r) { r.checked = false; });
                if (chosen) chosen.checked = true;

                syncRadioLabels(fieldset, chosen);
            });

            // ---- Checkboxes ----
            document.querySelectorAll('.customization-group[data-component-kind="multi"]').forEach(function (group) {
                group.querySelectorAll('.checkbox-option').forEach(function (lbl) {
                    const cb = lbl.querySelector('input[type="checkbox"]');
                    if (!cb) return;
                    const isDefault = cb.dataset.isDefault === '1';
                    cb.checked = isDefault;
                    lbl.classList.toggle('selected', isDefault);
                });
            });

            // ---- Modifiers ----
            document.querySelectorAll('.customization-group[data-component-kind="modifier"]').forEach(function (group) {
                const option = group.querySelector('.modifier-option');
                if (!option) return;

                const minQty     = parseInt(option.dataset.minQty, 10) || 0;
                const maxQty     = parseInt(option.dataset.maxQty, 10) || 1;
                const defaultQty = parseInt(option.dataset.defaultQuantity, 10) || 0;

                // Clamp into range — this is what PHP already did when rendering,
                // so the DOM will not visually shift.
                let startQty = defaultQty;
                if (startQty < minQty) startQty = minQty;
                if (startQty > maxQty) startQty = maxQty;

                const qtySpan  = option.querySelector('.modifier-quantity');
                const minusBtn = option.querySelector('.modifier-minus');
                const plusBtn  = option.querySelector('.modifier-plus');

                if (qtySpan)  qtySpan.textContent = String(startQty);
                if (minusBtn) minusBtn.disabled = startQty <= minQty;
                if (plusBtn)  plusBtn.disabled  = startQty >= maxQty;
                option.classList.toggle('selected', startQty > 0);
            });

            // ---- Static ----
            // Nothing to sync; their hidden input always has data-is-default="1".
        }

        // ----------------------------------------------------------
        // CALCULATE TOTALS
        // ----------------------------------------------------------
        function calculateTotals() {
            let price    = BASE_PRICE;
            let calories = BASE_CALORIES;

            // Choice groups: delta between selected and default.
            document.querySelectorAll('.customization-group[data-component-kind="choice"]').forEach(function (group) {
                const fieldset = group.querySelector('.customization-radio-group');
                if (!fieldset) return;

                const defaultRadio  = findDefaultRadio(fieldset);
                const selectedRadio = findCheckedRadio(fieldset);

                const dp = defaultRadio  ? (parseFloat(defaultRadio.dataset.priceModifier)  || 0) : 0;
                const dc = defaultRadio  ? (parseInt(defaultRadio.dataset.calories,  10) || 0) : 0;

                const sp = selectedRadio ? (parseFloat(selectedRadio.dataset.priceModifier)  || 0) : 0;
                const sc = selectedRadio ? (parseInt(selectedRadio.dataset.calories,  10) || 0) : 0;

                price    += sp - dp;
                calories += sc - dc;
            });

            // Multi groups: only flips count.
            document.querySelectorAll('.customization-group[data-component-kind="multi"]').forEach(function (group) {
                group.querySelectorAll('.checkbox-option').forEach(function (lbl) {
                    const cb = lbl.querySelector('input[type="checkbox"]');
                    if (!cb) return;

                    const isDefault = cb.dataset.isDefault === '1';
                    const isChecked = cb.checked;
                    if (isChecked === isDefault) return;

                    const p = parseFloat(cb.dataset.priceModifier) || 0;
                    const c = parseInt(cb.dataset.calories, 10) || 0;

                    if (isChecked) {
                        price    += p;
                        calories += c;
                    } else {
                        price    -= p;
                        calories -= c;
                    }
                });
            });

            // Modifiers: delta in units × per-unit values.
            document.querySelectorAll('.customization-group[data-component-kind="modifier"]').forEach(function (group) {
                const option = group.querySelector('.modifier-option');
                if (!option) return;

                const qtySpan    = option.querySelector('.modifier-quantity');
                const currentQty = parseInt(qtySpan ? qtySpan.textContent : '0', 10) || 0;
                const defaultQty = parseInt(option.dataset.defaultQuantity, 10) || 0;
                if (currentQty === defaultQty) return;

                const p = parseFloat(option.dataset.priceModifier) || 0;
                const c = parseInt(option.dataset.calories, 10) || 0;

                const delta = currentQty - defaultQty;
                price    += p * delta;
                calories += c * delta;
            });

            if (price    < 0) price    = 0;
            if (calories < 0) calories = 0;

            return { price: price, calories: calories };
        }

        // ----------------------------------------------------------
        // UI UPDATERS
        // ----------------------------------------------------------
        function getQuantity() {
            return parseInt(quantityInput ? quantityInput.value : '1', 10) || 1;
        }

        function updateMainTotals() {
            const qty = getQuantity();
            if (mainTotalPrice)    mainTotalPrice.textContent    = '₱' + (BASE_PRICE    * qty).toFixed(2);
            if (mainCaloriesBadge) mainCaloriesBadge.textContent = (BASE_CALORIES * qty) + ' kcal';
        }

        function updateCustomizeTotals() {
            const t = calculateTotals();
            const qty = getQuantity();
            const finalPrice = t.price * qty;
            const finalCal   = t.calories * qty;

            if (customizeTotalPrice)    customizeTotalPrice.textContent    = '₱' + finalPrice.toFixed(2);
            if (customizeTotalCalories) customizeTotalCalories.textContent = finalCal + ' kcal';
            if (totalPriceInput)        totalPriceInput.value              = finalPrice.toFixed(2);
            if (totalCaloriesInput)     totalCaloriesInput.value           = String(finalCal);
        }

        // ----------------------------------------------------------
        // STEP NAVIGATION
        // ----------------------------------------------------------
        function showCustomizeStep() {
            if (stepMain) stepMain.style.display = 'none';
            if (stepCustomize) {
                stepCustomize.style.display = 'block';
                stepCustomize.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            updateCustomizeTotals();
        }

        function showMainStep() {
            if (stepCustomize) stepCustomize.style.display = 'none';
            if (stepMain)      stepMain.style.display      = 'block';
            updateMainTotals();
        }

        if (customizeBtn) {
            customizeBtn.addEventListener('click', function (e) {
                e.preventDefault();
                showCustomizeStep();
            });
        }
        if (backToMainBtn) {
            backToMainBtn.addEventListener('click', function (e) {
                e.preventDefault();
                showMainStep();
            });
        }
        if (cancelCustomizeBtn) {
            cancelCustomizeBtn.addEventListener('click', function (e) {
                e.preventDefault();
                syncDomFromDefaults();
                updateCustomizeTotals();
                showMainStep();
            });
        }

        // ----------------------------------------------------------
        // QUANTITY CONTROLS (main view)
        // ----------------------------------------------------------
        const minusBtn = document.querySelector('.qty-minus');
        const plusBtn  = document.querySelector('.qty-plus');

        if (minusBtn && plusBtn && quantityInput) {
            function bump(delta) {
                const cur = parseInt(quantityInput.value, 10) || 1;
                const max = parseInt(quantityInput.max, 10) || 999;
                let next = cur + delta;
                if (next < 1) next = 1;
                if (next > max) next = max;
                quantityInput.value = next;
                updateMainTotals();
                updateCustomizeTotals();
            }
            minusBtn.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); bump(-1); });
            plusBtn .addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); bump(1);  });
            quantityInput.addEventListener('change', function () {
                const val = parseInt(this.value, 10) || 1;
                const max = parseInt(this.max, 10) || 999;
                const clamped = Math.min(Math.max(val, 1), max);
                this.value = clamped;
                updateMainTotals();
                updateCustomizeTotals();
            });
            quantityInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); this.blur(); }
            });
        }

        // ----------------------------------------------------------
        // CUSTOMIZATION EVENTS
        // ----------------------------------------------------------
        document.querySelectorAll('.customization-radio-group input[type="radio"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                const fieldset = this.closest('.customization-radio-group');
                if (fieldset) syncRadioLabels(fieldset, this);
                updateCustomizeTotals();
            });
        });

        document.querySelectorAll('.checkbox-option input[type="checkbox"]').forEach(function (cb) {
            cb.addEventListener('change', function () {
                const lbl = this.closest('.checkbox-option');
                if (lbl) lbl.classList.toggle('selected', this.checked);
                updateCustomizeTotals();
            });
        });

        document.querySelectorAll('.modifier-option').forEach(function (option) {
            const qtySpan = option.querySelector('.modifier-quantity');
            const minus   = option.querySelector('.modifier-minus');
            const plus    = option.querySelector('.modifier-plus');
            const minQty  = parseInt(option.dataset.minQty, 10) || 0;
            const maxQty  = parseInt(option.dataset.maxQty, 10) || 1;

            function setQty(next) {
                if (next < minQty) next = minQty;
                if (next > maxQty) next = maxQty;
                if (qtySpan) qtySpan.textContent = String(next);
                if (minus) minus.disabled = next <= minQty;
                if (plus)  plus.disabled  = next >= maxQty;
                option.classList.toggle('selected', next > 0);
                updateCustomizeTotals();
            }

            if (minus) minus.addEventListener('click', function (e) {
                e.preventDefault(); e.stopPropagation();
                const cur = parseInt(qtySpan.textContent, 10) || 0;
                setQty(cur - 1);
            });
            if (plus) plus.addEventListener('click', function (e) {
                e.preventDefault(); e.stopPropagation();
                const cur = parseInt(qtySpan.textContent, 10) || 0;
                setQty(cur + 1);
            });
        });

        // ----------------------------------------------------------
        // PAYLOAD
        // ----------------------------------------------------------
        function buildCustomizationsData() {
            const out = [];

            // Static
            document.querySelectorAll('.static-ingredient input[type="hidden"]').forEach(function (h) {
                out.push({
                    ingredient_id: parseInt(h.value, 10) || 0,
                    selected_option: 'selected',
                    quantity: parseInt(h.dataset.defaultQuantity, 10) || 1,
                    price_modifier: parseFloat(h.dataset.priceModifier) || 0,
                    calories: parseInt(h.dataset.calories, 10) || 0
                });
            });

            // Choice
            document.querySelectorAll('.customization-group[data-component-kind="choice"]').forEach(function (group) {
                const fieldset = group.querySelector('.customization-radio-group');
                if (!fieldset) return;
                const checked = fieldset.querySelector('input[type="radio"]:checked');
                if (!checked) return;

                if (!checked.value) {
                    out.push({
                        component_id: group.dataset.componentId || null,
                        ingredient_id: 0,
                        selected_option: 'remove',
                        quantity: 0,
                        price_modifier: 0,
                        calories: 0
                    });
                    return;
                }
                out.push({
                    component_id: group.dataset.componentId || null,
                    ingredient_id: parseInt(checked.value, 10) || 0,
                    selected_option: 'selected',
                    quantity: 1,
                    price_modifier: parseFloat(checked.dataset.priceModifier) || 0,
                    calories: parseInt(checked.dataset.calories, 10) || 0
                });
            });

            // Multi
            document.querySelectorAll('.customization-group[data-component-kind="multi"]').forEach(function (group) {
                group.querySelectorAll('.checkbox-option input[type="checkbox"]').forEach(function (cb) {
                    out.push({
                        component_id: group.dataset.componentId || null,
                        ingredient_id: parseInt(cb.value, 10) || 0,
                        selected_option: cb.checked ? 'selected' : 'remove',
                        quantity: cb.checked ? 1 : 0,
                        price_modifier: parseFloat(cb.dataset.priceModifier) || 0,
                        calories: parseInt(cb.dataset.calories, 10) || 0
                    });
                });
            });

            // Modifier
            document.querySelectorAll('.customization-group[data-component-kind="modifier"]').forEach(function (group) {
                const option = group.querySelector('.modifier-option');
                if (!option) return;
                const qtySpan = option.querySelector('.modifier-quantity');
                const qty = parseInt(qtySpan ? qtySpan.textContent : '0', 10) || 0;

                out.push({
                    component_id: group.dataset.componentId || null,
                    ingredient_id: parseInt(option.dataset.ingredientId, 10) || 0,
                    selected_option: qty > 0 ? 'add' : 'remove',
                    quantity: qty,
                    price_modifier: parseFloat(option.dataset.priceModifier) || 0,
                    calories: parseInt(option.dataset.calories, 10) || 0
                });
            });

            // Notes
            const notes = document.getElementById('globalNotes');
            if (notes && notes.value.trim()) {
                out.push({ type: 'notes', notes: notes.value.trim() });
            }
            return out;
        }

        function prepareSubmission() {
            if (customizationsData) customizationsData.value = JSON.stringify(buildCustomizationsData());
            const t = calculateTotals();
            const qty = getQuantity();
            if (totalPriceInput)    totalPriceInput.value    = (t.price * qty).toFixed(2);
            if (totalCaloriesInput) totalCaloriesInput.value = String(t.calories * qty);
            if (redirectInput)      redirectInput.value      = 'menu.php';
        }

        if (applyCustomizeBtn) {
            applyCustomizeBtn.addEventListener('click', function (e) {
                e.preventDefault();
                prepareSubmission();
                this.disabled = true;
                this.classList.add('loading');
                if (form) form.submit();
            });
        }

        function handleMainSubmit(button) {
            return function (e) {
                e.preventDefault();
                prepareSubmission();
                button.disabled = true;
                button.classList.add('loading');
                setTimeout(function () { if (form) form.submit(); }, 200);
            };
        }

        if (addToOrderBtn && form) addToOrderBtn.addEventListener('click', handleMainSubmit(addToOrderBtn));
        if (addToCartBtn  && form) addToCartBtn .addEventListener('click', handleMainSubmit(addToCartBtn));

        // ----------------------------------------------------------
        // KEYBOARD
        // ----------------------------------------------------------
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && stepCustomize && stepCustomize.style.display !== 'none') {
                showMainStep();
            }
        });

        // ----------------------------------------------------------
        // INIT
        // ----------------------------------------------------------
        syncDomFromDefaults();
        updateMainTotals();
        updateCustomizeTotals();

        // One re-sync after paint, in case any other deferred script
        // momentarily touched the DOM. Same code path, idempotent.
        requestAnimationFrame(function () {
            syncDomFromDefaults();
            updateMainTotals();
            updateCustomizeTotals();
        });

        console.log('Product Detail JS v7.0 initialized');
    });
})();