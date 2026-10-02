/**
 * FitPal Product Detail
 * Version 11.0 — Adds the thumbnail swap behaviour on the main
 *                preview box.
 *
 * Routing:
 *   Main view:
 *     "Add to Cart"   → AJAX POST → cart-handler.php   (action=add) → stays on page
 *     "Add to Order"  → AJAX POST → queue-handler.php  (action=add) → redirect to menu.php
 *
 *   Customize view:
 *     "Add to Cart"       → AJAX POST → cart-handler.php   (action=add) → stays on page
 *     "Apply and Add"     → AJAX POST → queue-handler.php  (action=add) → redirect to menu.php
 *
 * Reviews section
 * ---------------
 * The first 5 reviews are server-rendered by product-detail.php.
 * The remaining reviews are embedded as JSON on the list wrapper's
 * data-reviews-more attribute. This file reads that payload,
 * appends 5 more cards per click of #reviewsLoadMoreBtn, and
 * removes the button once the payload is exhausted.
 *
 * Thumbnail swap
 * --------------
 * product-detail.php renders the main preview box (#mainPreviewBox,
 * #previewImage, #previewPlaceholder) and, when the product folder
 * has more than one image-*.{ext} file, a thumbnail strip
 * (#thumbnailNavigation) carrying one
 * .thumbnail-image-btn[data-index] per image.
 *
 * This file binds:
 *   - mouseenter on a thumbnail → preview shows that image
 *   - click on a thumbnail      → that index is locked in
 *   - mouseleave on a thumbnail → preview reverts to the locked index
 *
 * The strip is optional. When it is absent (single-image products,
 * or a folder with zero images), the whole block exits at its first
 * line and the preview box keeps whatever the server rendered.
 *
 * The preview box swaps its background-image inline. Every visual
 * rule (sizing, ring, active/hover/broken states) lives in
 * product-detail.css. This file only toggles class names and
 * writes one inline style property.
 *
 * @package FitPal
 * @version 11.0 — Adds the thumbnail swap behaviour. Every other
 *                behaviour is byte-identical to v10.0.
 *
 *                (10.0: reviews Load More. 9.0: single-button
 *                loading state. 8.0: reviews section.)
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ------------------------------------------------------
        // THUMBNAIL SWAP
        //
        // Self-contained. Exits immediately when #thumbnailNavigation
        // is absent. Has no reference to any node the wizard owns,
        // and the wizard below has no reference to any node this
        // block owns.
        // ------------------------------------------------------
        (function initThumbnailSwap() {
            const previewImage       = document.getElementById('previewImage');
            const previewPlaceholder = document.getElementById('previewPlaceholder');
            const thumbnailNav       = document.getElementById('thumbnailNavigation');

            if (!previewImage || !previewPlaceholder) return;
            if (!thumbnailNav) return;

            const buttons = Array.from(
                thumbnailNav.querySelectorAll('.thumbnail-image-btn')
            );
            if (buttons.length === 0) return;

            let lockedIndex  = 0;
            let hoveredIndex = -1;

            // Read every button's background-image URL once at bind
            // time. Product-detail.php writes them inline as
            // url('...') on each button.
            const imageUrls = buttons.map(function (btn) {
                const bg = btn.style.backgroundImage;
                if (!bg || bg === 'none') return '';
                const match = bg.match(/url\((['"]?)(.*?)\1\)/);
                return match && match[2] ? match[2] : '';
            });

            // Probe each URL once so a broken file can be marked
            // before the customer ever hovers it.
            imageUrls.forEach(function (url, index) {
                if (!url) {
                    buttons[index].classList.add('is-broken');
                    buttons[index].setAttribute('aria-disabled', 'true');
                    return;
                }
                const probe = new Image();
                probe.onerror = function () {
                    buttons[index].classList.add('is-broken');
                    buttons[index].setAttribute('aria-disabled', 'true');
                    console.warn('[product-detail] Thumbnail failed to load:', url);
                };
                probe.src = url;
            });

            function isUsableIndex(index) {
                if (index < 0 || index >= imageUrls.length) return false;
                if (imageUrls[index] === '') return false;
                if (buttons[index].classList.contains('is-broken')) return false;
                return true;
            }

            function applyPreviewAtIndex(index) {
                if (!isUsableIndex(index)) {
                    previewImage.style.display = 'none';
                    previewPlaceholder.style.display = 'flex';
                    return;
                }
                previewImage.style.backgroundImage = "url('" + imageUrls[index] + "')";
                previewImage.style.display = 'block';
                previewPlaceholder.style.display = 'none';
            }

            function setActiveButton(index) {
                buttons.forEach(function (btn, i) {
                    btn.classList.toggle('active', i === index);
                });
            }

            function setHoverButton(index) {
                buttons.forEach(function (btn, i) {
                    btn.classList.toggle('hover', i === index && i !== lockedIndex);
                });
            }

            // The server rendered index 0 as active. Normalise in case
            // index 0 is broken — fall through to the first usable one.
            let initialIndex = 0;
            if (!isUsableIndex(initialIndex)) {
                initialIndex = imageUrls.findIndex(function (_, i) {
                    return isUsableIndex(i);
                });
            }
            if (initialIndex >= 0) {
                lockedIndex = initialIndex;
                applyPreviewAtIndex(lockedIndex);
                setActiveButton(lockedIndex);
            }

            buttons.forEach(function (btn, index) {
                btn.addEventListener('mouseenter', function () {
                    if (!isUsableIndex(index)) return;
                    hoveredIndex = index;
                    applyPreviewAtIndex(index);
                    setHoverButton(index);
                });

                btn.addEventListener('mouseleave', function () {
                    if (hoveredIndex === -1) return;
                    hoveredIndex = -1;
                    applyPreviewAtIndex(lockedIndex);
                    setActiveButton(lockedIndex);
                    buttons.forEach(function (b) {
                        b.classList.remove('hover');
                    });
                });

                btn.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    if (!isUsableIndex(index)) return;
                    lockedIndex = index;
                    hoveredIndex = -1;
                    applyPreviewAtIndex(lockedIndex);
                    setActiveButton(lockedIndex);
                    buttons.forEach(function (b) {
                        b.classList.remove('hover');
                    });
                });

                // Keyboard parity: Enter and Space act like a click.
                btn.addEventListener('keydown', function (event) {
                    if (event.key !== 'Enter' && event.key !== ' ') return;
                    event.preventDefault();
                    btn.click();
                });
            });
        })();

        // ------------------------------------------------------
        // DOM
        // ------------------------------------------------------
        const stepMain               = document.getElementById('stepMain');
        const stepCustomize          = document.getElementById('stepCustomize');
        const customizeBtn           = document.getElementById('customizeBtn');
        const backToMainBtn          = document.getElementById('backToMainBtn');
        const cancelCustomizeBtn     = document.getElementById('cancelCustomizeBtn');
        const applyCustomizeBtn      = document.getElementById('applyCustomizeBtn');
        const customizeAddToCartBtn  = document.getElementById('customizeAddToCartBtn');
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
        const form                   = document.getElementById('actionControlForm');
        const pageRoot               = document.getElementById('productDetailPage');

        if (!pageRoot) {
            console.error('[product-detail] #productDetailPage not found.');
            return;
        }
        if (!form) {
            console.error('[product-detail] #actionControlForm not found.');
            return;
        }

        // ------------------------------------------------------
        // ENDPOINTS
        // ------------------------------------------------------
        const CART_URL  = form.dataset.cartUrl
            || '../backend/handlers/cart-handler.php';
        const QUEUE_URL = form.dataset.queueUrl
            || '../backend/handlers/queue-handler.php';

        // ------------------------------------------------------
        // BASE
        // ------------------------------------------------------
        const BASE_PRICE    = parseFloat(pageRoot.dataset.basePrice)     || 0;
        const BASE_CALORIES = parseInt(pageRoot.dataset.baseCalories, 10) || 0;

        // ------------------------------------------------------
        // HELPERS
        // ------------------------------------------------------
        function findDefaultRadio(groupEl) {
            return groupEl.querySelector('input[type="radio"][data-is-default="1"]');
        }
        function findCheckedRadio(groupEl) {
            return groupEl.querySelector('input[type="radio"]:checked');
        }
        function syncRadioLabels(groupEl, chosenInput) {
            groupEl.querySelectorAll('.radio-option').forEach(l => l.classList.remove('selected'));
            if (chosenInput) {
                const lbl = chosenInput.closest('.radio-option');
                if (lbl) lbl.classList.add('selected');
            }
        }

        // ------------------------------------------------------
        // SYNC DOM FROM DEFAULTS
        // ------------------------------------------------------
        function syncDomFromDefaults() {
            document.querySelectorAll('.customization-group[data-component-kind="choice"]').forEach(group => {
                const fieldset = group.querySelector('.customization-radio-group');
                if (!fieldset) return;
                const chosen = findDefaultRadio(fieldset)
                            || findCheckedRadio(fieldset)
                            || fieldset.querySelector('input[type="radio"]');
                fieldset.querySelectorAll('input[type="radio"]').forEach(r => r.checked = false);
                if (chosen) chosen.checked = true;
                syncRadioLabels(fieldset, chosen);
            });

            document.querySelectorAll('.customization-group[data-component-kind="multi"]').forEach(group => {
                group.querySelectorAll('.checkbox-option').forEach(lbl => {
                    const cb = lbl.querySelector('input[type="checkbox"]');
                    if (!cb) return;
                    const isDefault = cb.dataset.isDefault === '1';
                    cb.checked = isDefault;
                    lbl.classList.toggle('selected', isDefault);
                });
            });

            document.querySelectorAll('.customization-group[data-component-kind="modifier"]').forEach(group => {
                const option = group.querySelector('.modifier-option');
                if (!option) return;
                const minQty     = parseInt(option.dataset.minQty, 10) || 0;
                const maxQty     = parseInt(option.dataset.maxQty, 10) || 1;
                const defaultQty = parseInt(option.dataset.defaultQuantity, 10) || 0;
                const startQty   = Math.min(Math.max(defaultQty, minQty), maxQty);

                const qtySpan  = option.querySelector('.modifier-quantity');
                const minusBtn = option.querySelector('.modifier-minus');
                const plusBtn  = option.querySelector('.modifier-plus');

                if (qtySpan)  qtySpan.textContent = String(startQty);
                if (minusBtn) minusBtn.disabled = startQty <= minQty;
                if (plusBtn)  plusBtn.disabled  = startQty >= maxQty;
                option.classList.toggle('selected', startQty > 0);
            });
        }

        // ------------------------------------------------------
        // TOTALS
        // ------------------------------------------------------
        function calculateTotals() {
            let price    = BASE_PRICE;
            let calories = BASE_CALORIES;

            document.querySelectorAll('.customization-group[data-component-kind="choice"]').forEach(group => {
                const fieldset = group.querySelector('.customization-radio-group');
                if (!fieldset) return;
                const def = findDefaultRadio(fieldset);
                const sel = findCheckedRadio(fieldset);
                const dp = def ? (parseFloat(def.dataset.priceModifier) || 0) : 0;
                const dc = def ? (parseInt(def.dataset.calories, 10) || 0)    : 0;
                const sp = sel ? (parseFloat(sel.dataset.priceModifier) || 0) : 0;
                const sc = sel ? (parseInt(sel.dataset.calories, 10) || 0)    : 0;
                price    += sp - dp;
                calories += sc - dc;
            });

            document.querySelectorAll('.customization-group[data-component-kind="multi"]').forEach(group => {
                group.querySelectorAll('.checkbox-option').forEach(lbl => {
                    const cb = lbl.querySelector('input[type="checkbox"]');
                    if (!cb) return;
                    const isDefault = cb.dataset.isDefault === '1';
                    if (cb.checked === isDefault) return;
                    const p = parseFloat(cb.dataset.priceModifier) || 0;
                    const c = parseInt(cb.dataset.calories, 10) || 0;
                    if (cb.checked) { price += p; calories += c; }
                    else            { price -= p; calories -= c; }
                });
            });

            document.querySelectorAll('.customization-group[data-component-kind="modifier"]').forEach(group => {
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

            return {
                price:    Math.max(0, price),
                calories: Math.max(0, calories)
            };
        }

        function getQuantity() {
            return parseInt(quantityInput ? quantityInput.value : '1', 10) || 1;
        }

        function updateMainTotals() {
            const qty = getQuantity();
            if (mainTotalPrice)    mainTotalPrice.textContent    = '₱' + (BASE_PRICE * qty).toFixed(2);
            if (mainCaloriesBadge) mainCaloriesBadge.textContent = (BASE_CALORIES * qty) + ' kcal';
        }

        function updateCustomizeTotals() {
            const t   = calculateTotals();
            const qty = getQuantity();
            const fp  = t.price * qty;
            const fc  = t.calories * qty;
            if (customizeTotalPrice)    customizeTotalPrice.textContent    = '₱' + fp.toFixed(2);
            if (customizeTotalCalories) customizeTotalCalories.textContent = fc + ' kcal';
            if (totalPriceInput)        totalPriceInput.value              = fp.toFixed(2);
            if (totalCaloriesInput)     totalCaloriesInput.value           = String(fc);
        }

        // ------------------------------------------------------
        // STEP NAV
        // ------------------------------------------------------
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

        if (customizeBtn)       customizeBtn.addEventListener('click', e => { e.preventDefault(); showCustomizeStep(); });
        if (backToMainBtn)      backToMainBtn.addEventListener('click', e => { e.preventDefault(); showMainStep(); });
        if (cancelCustomizeBtn) cancelCustomizeBtn.addEventListener('click', e => {
            e.preventDefault();
            syncDomFromDefaults();
            updateCustomizeTotals();
            showMainStep();
        });

        // ------------------------------------------------------
        // QUANTITY
        // ------------------------------------------------------
        const minusBtn = document.querySelector('.qty-minus');
        const plusBtn  = document.querySelector('.qty-plus');

        if (minusBtn && plusBtn && quantityInput) {
            function bump(delta) {
                const cur = parseInt(quantityInput.value, 10) || 1;
                const max = parseInt(quantityInput.max, 10) || 999;
                let next = cur + delta;
                if (next < 1)   next = 1;
                if (next > max) next = max;
                quantityInput.value = next;
                updateMainTotals();
                updateCustomizeTotals();
            }
            minusBtn.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); bump(-1); });
            plusBtn .addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); bump(1);  });

            quantityInput.addEventListener('change', function () {
                const val = parseInt(this.value, 10) || 1;
                const max = parseInt(this.max, 10) || 999;
                this.value = Math.min(Math.max(val, 1), max);
                updateMainTotals();
                updateCustomizeTotals();
            });
            quantityInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); this.blur(); }
            });
        }

        // ------------------------------------------------------
        // CUSTOMIZATION EVENTS
        // ------------------------------------------------------
        document.querySelectorAll('.customization-radio-group input[type="radio"]').forEach(radio => {
            radio.addEventListener('change', function () {
                const fieldset = this.closest('.customization-radio-group');
                if (fieldset) syncRadioLabels(fieldset, this);
                updateCustomizeTotals();
            });
        });

        document.querySelectorAll('.checkbox-option input[type="checkbox"]').forEach(cb => {
            cb.addEventListener('change', function () {
                const lbl = this.closest('.checkbox-option');
                if (lbl) lbl.classList.toggle('selected', this.checked);
                updateCustomizeTotals();
            });
        });

        document.querySelectorAll('.modifier-option').forEach(option => {
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

            if (minus) minus.addEventListener('click', e => {
                e.preventDefault(); e.stopPropagation();
                setQty((parseInt(qtySpan.textContent, 10) || 0) - 1);
            });
            if (plus) plus.addEventListener('click', e => {
                e.preventDefault(); e.stopPropagation();
                setQty((parseInt(qtySpan.textContent, 10) || 0) + 1);
            });
        });

        // ------------------------------------------------------
        // PAYLOAD
        // ------------------------------------------------------
        function buildCustomizationsData() {
            const out = [];

            document.querySelectorAll('.static-ingredient input[type="hidden"]').forEach(h => {
                out.push({
                    ingredient_id:   parseInt(h.value, 10) || 0,
                    selected_option: 'selected',
                    quantity:        parseInt(h.dataset.defaultQuantity, 10) || 1,
                    price_modifier:  parseFloat(h.dataset.priceModifier) || 0,
                    calories:        parseInt(h.dataset.calories, 10) || 0
                });
            });

            document.querySelectorAll('.customization-group[data-component-kind="choice"]').forEach(group => {
                const fieldset = group.querySelector('.customization-radio-group');
                if (!fieldset) return;
                const checked = fieldset.querySelector('input[type="radio"]:checked');
                if (!checked) return;

                if (!checked.value) {
                    out.push({
                        component_id:    group.dataset.componentId || null,
                        ingredient_id:   0,
                        selected_option: 'remove',
                        quantity:        0,
                        price_modifier:  0,
                        calories:        0
                    });
                    return;
                }
                out.push({
                    component_id:    group.dataset.componentId || null,
                    ingredient_id:   parseInt(checked.value, 10) || 0,
                    selected_option: 'selected',
                    quantity:        1,
                    price_modifier:  parseFloat(checked.dataset.priceModifier) || 0,
                    calories:        parseInt(checked.dataset.calories, 10) || 0
                });
            });

            document.querySelectorAll('.customization-group[data-component-kind="multi"]').forEach(group => {
                group.querySelectorAll('.checkbox-option input[type="checkbox"]').forEach(cb => {
                    out.push({
                        component_id:    group.dataset.componentId || null,
                        ingredient_id:   parseInt(cb.value, 10) || 0,
                        selected_option: cb.checked ? 'selected' : 'remove',
                        quantity:        cb.checked ? 1 : 0,
                        price_modifier:  parseFloat(cb.dataset.priceModifier) || 0,
                        calories:        parseInt(cb.dataset.calories, 10) || 0
                    });
                });
            });

            document.querySelectorAll('.customization-group[data-component-kind="modifier"]').forEach(group => {
                const option = group.querySelector('.modifier-option');
                if (!option) return;
                const qtySpan = option.querySelector('.modifier-quantity');
                const qty     = parseInt(qtySpan ? qtySpan.textContent : '0', 10) || 0;

                out.push({
                    component_id:    group.dataset.componentId || null,
                    ingredient_id:   parseInt(option.dataset.ingredientId, 10) || 0,
                    selected_option: qty > 0 ? 'add' : 'remove',
                    quantity:        qty,
                    price_modifier:  parseFloat(option.dataset.priceModifier) || 0,
                    calories:        parseInt(option.dataset.calories, 10) || 0
                });
            });

            const notes = document.getElementById('globalNotes');
            if (notes && notes.value.trim()) {
                out.push({ type: 'notes', notes: notes.value.trim() });
            }
            return out;
        }

        function prepareSubmission() {
            if (customizationsData) {
                customizationsData.value = JSON.stringify(buildCustomizationsData());
            }
            const t   = calculateTotals();
            const qty = getQuantity();
            if (totalPriceInput)    totalPriceInput.value    = (t.price * qty).toFixed(2);
            if (totalCaloriesInput) totalCaloriesInput.value = String(t.calories * qty);
        }

        // ------------------------------------------------------
        // SUBMIT HELPERS
        // ------------------------------------------------------
        function setButtonLoading(button, label) {
            button.disabled = true;
            button.classList.add('loading');
            button.dataset.originalLabel = button.innerHTML;
            button.innerHTML = '<span>' + label + '</span>';
        }
        function restoreButton(button) {
            button.disabled = false;
            button.classList.remove('loading');
            if (button.dataset.originalLabel) {
                button.innerHTML = button.dataset.originalLabel;
                delete button.dataset.originalLabel;
            }
        }

        async function postForm(url, formEl, extraFields) {
            const formData = new FormData(formEl);
            if (extraFields) {
                Object.keys(extraFields).forEach(k => formData.set(k, extraFields[k]));
            }

            const res = await fetch(url, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            });

            let data;
            try {
                data = await res.json();
            } catch {
                data = { status: 'error', message: 'Unexpected server response (' + res.status + ').' };
            }
            return data;
        }

        // ------------------------------------------------------
        // HANDLERS — Cart / Order
        // ------------------------------------------------------

        if (addToCartBtn) {
            addToCartBtn.addEventListener('click', async e => {
                e.preventDefault();
                prepareSubmission();
                setButtonLoading(addToCartBtn, 'Adding...');

                try {
                    const data = await postForm(CART_URL, form, { action: 'add' });
                    if (data && data.status === 'success') {
                        showToast(data.message || 'Added to cart', 'success');
                    } else {
                        showToast((data && data.message) || 'Could not add to cart', 'error');
                    }
                } catch (err) {
                    console.warn('Add to cart failed', err);
                    showToast('Network error. Please try again.', 'error');
                } finally {
                    restoreButton(addToCartBtn);
                }
            });
        }

        if (customizeAddToCartBtn) {
            customizeAddToCartBtn.addEventListener('click', async e => {
                e.preventDefault();
                prepareSubmission();
                setButtonLoading(customizeAddToCartBtn, 'Adding...');

                try {
                    const data = await postForm(CART_URL, form, { action: 'add' });
                    if (data && data.status === 'success') {
                        showToast(data.message || 'Added to cart', 'success');
                    } else {
                        showToast((data && data.message) || 'Could not add to cart', 'error');
                    }
                } catch (err) {
                    console.warn('Add to cart failed', err);
                    showToast('Network error. Please try again.', 'error');
                } finally {
                    restoreButton(customizeAddToCartBtn);
                }
            });
        }

        if (addToOrderBtn) {
            addToOrderBtn.addEventListener('click', async e => {
                e.preventDefault();
                prepareSubmission();
                setButtonLoading(addToOrderBtn, 'Adding...');

                try {
                    const data = await postForm(QUEUE_URL, form, { action: 'add' });
                    if (data && data.status === 'success') {
                        showToast(data.message || 'Added to order', 'success');
                        setTimeout(() => { window.location.href = 'menu.php'; }, 400);
                    } else {
                        showToast((data && data.message) || 'Could not add to order', 'error');
                        restoreButton(addToOrderBtn);
                    }
                } catch (err) {
                    console.warn('Add to order failed', err);
                    showToast('Network error. Please try again.', 'error');
                    restoreButton(addToOrderBtn);
                }
            });
        }

        if (applyCustomizeBtn) {
            applyCustomizeBtn.addEventListener('click', async e => {
                e.preventDefault();
                prepareSubmission();
                setButtonLoading(applyCustomizeBtn, 'Adding...');

                try {
                    const data = await postForm(QUEUE_URL, form, { action: 'add' });
                    if (data && data.status === 'success') {
                        showToast(data.message || 'Added to order', 'success');
                        setTimeout(() => { window.location.href = 'menu.php'; }, 400);
                    } else {
                        showToast((data && data.message) || 'Could not add to order', 'error');
                        restoreButton(applyCustomizeBtn);
                    }
                } catch (err) {
                    console.warn('Apply customize failed', err);
                    showToast('Network error. Please try again.', 'error');
                    restoreButton(applyCustomizeBtn);
                }
            });
        }

        // ------------------------------------------------------
        // REVIEWS — LOAD MORE
        // ------------------------------------------------------
        function initReviewsLoadMore() {
            const list = document.getElementById('reviewsList');
            const btn  = document.getElementById('reviewsLoadMoreBtn');
            if (!list || !btn) return;

            const section = list.closest('.product-reviews-section');
            const perPage = section
                ? (parseInt(section.getAttribute('data-reviews-per-page'), 10) || 5)
                : 5;

            let more = [];
            try {
                const raw = list.getAttribute('data-reviews-more') || '[]';
                const parsed = JSON.parse(raw);
                if (Array.isArray(parsed)) {
                    more = parsed;
                }
            } catch (err) {
                console.warn('[product-detail] Could not parse reviews payload:', err);
                more = [];
            }

            if (more.length === 0) {
                btn.remove();
                return;
            }

            let nextIndex = 0;

            btn.addEventListener('click', function () {
                const slice = more.slice(nextIndex, nextIndex + perPage);
                nextIndex += slice.length;

                slice.forEach(review => {
                    list.appendChild(buildReviewCard(review));
                });

                if (nextIndex >= more.length) {
                    btn.remove();
                }
            });
        }

        /**
         * Build a review card node from a payload entry. Uses
         * document.createElement and textContent only.
         */
        function buildReviewCard(review) {
            const firstName = String(review.first_name || '');
            const lastName  = String(review.last_name  || '');

            let displayName = (firstName + ' ' + lastName).trim();
            if (displayName === '') displayName = 'Customer';

            let initial = 'U';
            if (firstName !== '') {
                initial = firstName.charAt(0).toUpperCase();
            } else if (lastName !== '') {
                initial = lastName.charAt(0).toUpperCase();
            }

            const picture = String(review.profile_picture || '');
            const score   = parseInt(review.score, 10) || 0;
            const comment = String(review.comment || '');
            const dateRaw = String(review.date_posted || '');

            const dateOut = formatReviewDate(dateRaw);

            const card = document.createElement('article');
            card.className = 'review-item';

            const avatar = document.createElement('div');
            avatar.className = 'review-avatar';
            avatar.setAttribute('aria-hidden', 'true');

            if (picture !== '') {
                const assetBase = resolveAssetBase();
                const url = buildAvatarUrl(picture, assetBase);
                const img = document.createElement('img');
                img.className = 'review-avatar-image';
                img.alt = '';
                img.src = url;
                img.addEventListener('error', function () {
                    img.style.display = 'none';
                    initialSpan.style.display = 'flex';
                });
                const initialSpan = document.createElement('span');
                initialSpan.className = 'review-avatar-initial';
                initialSpan.style.display = 'none';
                initialSpan.textContent = initial;
                avatar.appendChild(img);
                avatar.appendChild(initialSpan);
            } else {
                const initialSpan = document.createElement('span');
                initialSpan.className = 'review-avatar-initial';
                initialSpan.textContent = initial;
                avatar.appendChild(initialSpan);
            }

            const body = document.createElement('div');
            body.className = 'review-body';

            const header = document.createElement('header');
            header.className = 'review-header';

            const author = document.createElement('span');
            author.className = 'review-author';
            author.textContent = displayName;

            const date = document.createElement('span');
            date.className = 'review-date';
            date.textContent = dateOut;

            header.appendChild(author);
            header.appendChild(date);

            const rating = document.createElement('div');
            rating.className = 'review-rating';
            rating.setAttribute('aria-label', score + ' out of 5 stars');

            for (let i = 1; i <= 5; i++) {
                const star = document.createElement('span');
                star.className = 'star' + (i <= score ? ' filled' : '');
                star.textContent = '★';
                rating.appendChild(star);
            }

            body.appendChild(header);
            body.appendChild(rating);

            if (comment !== '') {
                const p = document.createElement('p');
                p.className = 'review-comment';
                const lines = comment.split(/\r?\n/);
                lines.forEach((line, idx) => {
                    if (idx > 0) {
                        p.appendChild(document.createElement('br'));
                    }
                    p.appendChild(document.createTextNode(line));
                });
                body.appendChild(p);
            }

            card.appendChild(avatar);
            card.appendChild(body);

            return card;
        }

        /**
         * Format a MySQL DATETIME string into "Mmm dd, yyyy".
         * Falls back to the raw string when the value cannot be
         * parsed, matching the server-rendered path.
         */
        function formatReviewDate(dateRaw) {
            if (dateRaw === '') return '';

            const m = dateRaw.match(/^(\d{4})-(\d{2})-(\d{2})/);
            if (!m) return dateRaw;

            const yyyy = m[1];
            const mm   = parseInt(m[2], 10);
            const dd   = m[3];

            const monthNames = [
                'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'
            ];
            const monthName = monthNames[mm - 1] || m[2];

            return monthName + ' ' + dd + ', ' + yyyy;
        }

        /**
         * Build a browser-loadable URL for a stored profile_picture
         * path. Mirrors buildReviewAvatarUrl() in the PHP.
         */
        function buildAvatarUrl(storedPath, assetBase) {
            if (storedPath === '') return '';

            let base = assetBase;
            if (base === '') {
                base = '../../shared/';
            }

            const projectRoot = base.replace(/shared\/$/, '');
            return projectRoot + storedPath;
        }

        /**
         * Read the page's asset base from window.FITPAL_ASSET_BASE,
         * falling back to the standard ../../shared/ used by every
         * customer page.
         */
        function resolveAssetBase() {
            if (typeof window.FITPAL_ASSET_BASE === 'string' && window.FITPAL_ASSET_BASE !== '') {
                return window.FITPAL_ASSET_BASE;
            }
            return '../../shared/';
        }

        // ------------------------------------------------------
        // TOAST
        // ------------------------------------------------------
        function showToast(message, type) {
            let toast = document.getElementById('pdToast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'pdToast';
                toast.style.cssText = [
                    'position: fixed',
                    'top: 24px',
                    'right: 24px',
                    'z-index: 9999',
                    'padding: 12px 20px',
                    'border-radius: 8px',
                    'font-size: 14px',
                    'font-weight: 500',
                    'max-width: 320px',
                    'box-shadow: 0 4px 16px rgba(0,0,0,0.15)',
                    'transform: translateX(120%)',
                    'transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1)'
                ].join(';');
                document.body.appendChild(toast);
            }

            const palette = {
                success: { bg: '#d1fae5', fg: '#065f46' },
                error:   { bg: '#fee2e2', fg: '#991b1b' }
            };
            const c = palette[type] || palette.success;

            toast.style.background = c.bg;
            toast.style.color      = c.fg;
            toast.textContent      = message;

            void toast.offsetWidth;
            toast.style.transform = 'translateX(0)';

            clearTimeout(toast._timer);
            toast._timer = setTimeout(() => {
                toast.style.transform = 'translateX(120%)';
            }, 2600);
        }

        // ------------------------------------------------------
        // KEYBOARD
        // ------------------------------------------------------
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && stepCustomize && stepCustomize.style.display !== 'none') {
                showMainStep();
            }
        });

        // ------------------------------------------------------
        // INIT
        // ------------------------------------------------------
        syncDomFromDefaults();
        updateMainTotals();
        updateCustomizeTotals();
        initReviewsLoadMore();

        console.log('Product Detail JS v11.0 initialized');
    });
})();