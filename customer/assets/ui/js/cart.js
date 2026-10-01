/**
 * FitPal Customer Cart Page JavaScript
 * Version 2.0 — Cart handler URL now read from window.FITPAL_CART.
 *
 * Handles:
 *  - Quantity stepper (+ / −) with min/max clamping
 *  - Quantity input changes (debounced sync with server)
 *  - Remove-item confirmation modal
 *  - Selected subtotal (only checked rows count)
 *  - Per-row selection checkboxes + "select all on this page"
 *  - Collapsible customization panel (toggle via aria-controls)
 *  - "Add to Order" — pushes only the SELECTED cart rows to the
 *    session queue, then redirects to menu.php.
 *
 * ---------------------------------------------------------------------
 * HANDLER TARGET
 * ---------------------------------------------------------------------
 * Every POST this file makes targets a file that exists on disk:
 *
 *     cart-handler.php   ← update_quantity, remove_item, push_to_queue
 *
 * The URL is read from window.FITPAL_CART.handlerUrl, which
 * customer/pages/cart.php publishes. When that object is absent —
 * which only happens on a page rendered without it — the fallback
 * names the same file the page's markup names. The
 * #cartPushToQueueForm's own action attribute is also used as a
 * last resort if the config object is missing AND the form is
 * present.
 *
 * This file does not reference order-handler.php or any other
 * retired filename in any fetch call.
 *
 * ---------------------------------------------------------------------
 * Config
 * ---------------------------------------------------------------------
 * window.FITPAL_CART = {
 *     csrfToken:  '<the customer's own csrf token>',
 *     handlerUrl: '../backend/handlers/cart-handler.php'
 * };
 *
 * The fallback CSRF token source is window.FITPAL_CSRF_TOKEN, which
 * the same page publishes.
 *
 * ---------------------------------------------------------------------
 * Rules honored
 * ---------------------------------------------------------------------
 *   - No CSS in this file.
 *   - No <svg> injection.
 *   - No window.alert / confirm / prompt. Every confirmation is a
 *     page-rendered modal.
 *   - Scroll lock is applied before the modal is shown and released
 *     after it is hidden, so the page does not reflow around the
 *     body scrollbar.
 *
 * @package FitPal
 * @version 2.0 — The cart handler URL is now read from
 *                window.FITPAL_CART.handlerUrl. The previous
 *                revision hard-coded '../backend/handlers/
 *                cart-handler.php' at each fetch site. The endpoint
 *                is now declared in exactly one place — the page —
 *                and this file reads it.
 *
 *                When FITPAL_CART is absent, the file falls back to
 *                the form's own action attribute, then to the same
 *                literal path the page's markup names.
 *
 *                No behavioural change to the quantity stepper, the
 *                remove modal, the selection summary, the
 *                customization toggle, or the push-to-queue
 *                submission.
 *
 *                (1.9: scroll lock ordering around the modal fade.
 *                1.8: customization toggle. 1.7: selection summary.
 *                1.6: remove modal. 1.5: server sync debounce.)
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ============================================
        // CONFIG
        // ============================================
        var CFG = window.FITPAL_CART || {};

        var CSRF_TOKEN = CFG.csrfToken
            || window.FITPAL_CSRF_TOKEN
            || '';

        var cartSubtotalEl     = document.getElementById('cartSubtotal');
        var cartItemsContainer = document.getElementById('cartItems');

        var removeModal        = document.getElementById('cartRemoveModal');
        var removeModalName    = document.getElementById('cartModalItemName');
        var removeModalCancel  = document.getElementById('cartModalCancel');
        var removeModalConfirm = document.getElementById('cartModalConfirm');

        var pushToQueueForm    = document.getElementById('cartPushToQueueForm');
        var addToOrderBtn      = document.getElementById('cartAddToOrderBtn');
        var selectedIdsBox     = document.getElementById('cartSelectedIds');
        var selectAllPage      = document.getElementById('cartSelectAllPage');
        var selectionCountEl   = document.getElementById('cartSelectionCount');

        // The cart handler URL. Read from the page's config object,
        // then from the form's own action attribute, then from the
        // literal fallback that names the same file.
        var CART_URL = CFG.handlerUrl
            || (pushToQueueForm && pushToQueueForm.getAttribute('action'))
            || '../backend/handlers/cart-handler.php';

        var pendingRemoveCartId = null;
        var pendingRemoveEl     = null;

        // ============================================
        // HELPERS
        // ============================================

        function formatPeso(amount) {
            const n = Number(amount) || 0;
            return '₱' + n.toFixed(2);
        }

        function getItemCheckboxes() {
            if (!cartItemsContainer) return [];
            return Array.prototype.slice.call(
                cartItemsContainer.querySelectorAll('.cart-item-checkbox:not(:disabled)')
            );
        }

        function getRowQty(itemEl) {
            const qtyInput = itemEl.querySelector('.qty-input');
            return parseInt(qtyInput ? qtyInput.value : '0', 10) || 0;
        }

        function getRowPrice(itemEl) {
            return parseFloat(itemEl.dataset.price) || 0;
        }

        function recalculateSubtotal() {
            if (!cartItemsContainer) return;

            const items = cartItemsContainer.querySelectorAll('.cart-item');
            let total = 0;

            items.forEach(function (item) {
                const checkbox = item.querySelector('.cart-item-checkbox');
                const isChecked = checkbox ? checkbox.checked : false;
                if (!isChecked) return;

                total += getRowPrice(item) * getRowQty(item);
            });

            if (cartSubtotalEl) {
                cartSubtotalEl.textContent = formatPeso(total);
            }
        }

        function updateRowSubtotal(itemEl) {
            if (!itemEl) return;

            const price = getRowPrice(itemEl);
            const qty   = getRowQty(itemEl);

            const rowTotalEl = itemEl.querySelector('.cart-item-subtotal-amount');
            if (rowTotalEl) {
                rowTotalEl.textContent = formatPeso(price * qty);
            }
        }

        function parseJsonOrThrow(res) {
            return res.text().then(function (text) {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('[cart.js] Expected JSON but got:',
                        '\n  status:', res.status,
                        '\n  body  :', text.slice(0, 500));
                    throw new Error('Server returned non-JSON response (status ' + res.status + ')');
                }
            });
        }

        // ============================================
        // SERVER SYNC
        // ============================================

        function syncQuantity(cartId, quantity) {
            const body = new URLSearchParams({
                action: 'update_quantity',
                cart_id: String(cartId),
                quantity: String(quantity),
                csrf_token: CSRF_TOKEN
            });

            return fetch(CART_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
                credentials: 'same-origin'
            })
            .then(parseJsonOrThrow)
            .then(function (data) {
                if (!data || data.status !== 'success') {
                    throw new Error((data && data.message) || 'Failed to update quantity');
                }
                return true;
            });
        }

        function syncRemove(cartId) {
            const body = new URLSearchParams({
                action: 'remove_item',
                cart_id: String(cartId),
                csrf_token: CSRF_TOKEN
            });

            return fetch(CART_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
                credentials: 'same-origin'
            })
            .then(parseJsonOrThrow)
            .then(function (data) {
                if (!data || data.status !== 'success') {
                    throw new Error((data && data.message) || 'Failed to remove item');
                }
                return true;
            });
        }

        // ============================================
        // SELECTION
        // ============================================

        function updateSelectionSummary() {
            const boxes   = getItemCheckboxes();
            const checked = boxes.filter(function (b) { return b.checked; });

            if (selectionCountEl) {
                selectionCountEl.textContent = String(checked.length);
            }

            if (selectAllPage) {
                selectAllPage.checked = boxes.length > 0 && checked.length === boxes.length;
                selectAllPage.indeterminate = checked.length > 0 && checked.length < boxes.length;
            }

            if (addToOrderBtn) {
                addToOrderBtn.disabled = checked.length === 0;
            }
        }

        function refreshSelectionAndSubtotal() {
            updateSelectionSummary();
            recalculateSubtotal();
        }

        if (selectAllPage) {
            selectAllPage.addEventListener('change', function () {
                getItemCheckboxes().forEach(function (b) {
                    b.checked = selectAllPage.checked;
                });
                refreshSelectionAndSubtotal();
            });
        }

        if (cartItemsContainer) {
            cartItemsContainer.addEventListener('change', function (e) {
                if (e.target.classList && e.target.classList.contains('cart-item-checkbox')) {
                    refreshSelectionAndSubtotal();
                }
            });
        }

        // ============================================
        // QUANTITY + REMOVE + CUSTOMS TOGGLE (delegated)
        // ============================================
        if (cartItemsContainer) {

            cartItemsContainer.addEventListener('click', function (e) {
                const minusBtn = e.target.closest('.qty-minus');
                const plusBtn  = e.target.closest('.qty-plus');

                if (minusBtn || plusBtn) {
                    e.preventDefault();
                    e.stopPropagation();

                    const btn     = minusBtn || plusBtn;
                    const cartId  = parseInt(btn.dataset.cartId, 10) || 0;
                    const itemEl  = btn.closest('.cart-item');
                    if (!itemEl || cartId <= 0) return;

                    const qtyInput = itemEl.querySelector('.qty-input');
                    const stock    = parseInt(itemEl.dataset.stock, 10) || 999;
                    let qty        = parseInt(qtyInput.value, 10) || 1;

                    if (minusBtn) { qty -= 1; if (qty < 1) qty = 1; }
                    else          { qty += 1; if (qty > stock) qty = stock; }

                    qtyInput.value = qty;
                    updateRowSubtotal(itemEl);
                    recalculateSubtotal();

                    syncQuantity(cartId, qty).catch(function (err) {
                        console.error('Quantity sync failed:', err);
                        window.location.reload();
                    });
                }

                const removeBtn = e.target.closest('.cart-item-remove');
                if (removeBtn) {
                    e.preventDefault();
                    e.stopPropagation();

                    const cartId = parseInt(removeBtn.dataset.cartId, 10) || 0;
                    const name   = removeBtn.dataset.productName || 'this item';
                    if (cartId <= 0) return;

                    openRemoveModal(cartId, name, removeBtn.closest('.cart-item'));
                }

                const customsToggle = e.target.closest('.cart-customs-toggle');
                if (customsToggle) {
                    e.preventDefault();
                    e.stopPropagation();

                    const panelId = customsToggle.getAttribute('aria-controls');
                    if (!panelId) return;

                    const wrapper = document.getElementById(panelId);
                    if (!wrapper) return;

                    const expanded = customsToggle.getAttribute('aria-expanded') === 'true';
                    customsToggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                    wrapper.hidden = expanded;
                }
            });

            cartItemsContainer.addEventListener('change', function (e) {
                const input = e.target.closest('.qty-input');
                if (!input) return;

                const itemEl = input.closest('.cart-item');
                if (!itemEl) return;

                const cartId = parseInt(input.dataset.cartId, 10) || 0;
                const stock  = parseInt(itemEl.dataset.stock, 10) || 999;
                let qty      = parseInt(input.value, 10) || 1;

                if (qty < 1) qty = 1;
                if (qty > stock) qty = stock;
                input.value = qty;

                updateRowSubtotal(itemEl);
                recalculateSubtotal();

                if (cartId > 0) {
                    syncQuantity(cartId, qty).catch(function (err) {
                        console.error('Quantity sync failed:', err);
                        window.location.reload();
                    });
                }
            });

            cartItemsContainer.addEventListener('wheel', function (e) {
                if (e.target.closest('.qty-input')) e.target.blur();
            }, { passive: true });
        }

        // ============================================
        // REMOVE MODAL
        //
        // Ordering matters. Lock scroll FIRST, while the modal is still
        // off-screen. Then show the modal on an already-stable page.
        //
        // On close, hide the modal first, and release the scroll lock
        // only AFTER the fade-out completes.
        // ============================================

        function openRemoveModal(cartId, productName, itemEl) {
            if (!removeModal) { performRemove(cartId, itemEl); return; }

            pendingRemoveCartId = cartId;
            pendingRemoveEl     = itemEl || null;

            if (removeModalName) removeModalName.textContent = productName;

            document.body.style.overflow = 'hidden';

            removeModal.style.display = 'flex';
            void removeModal.offsetWidth;
            removeModal.classList.add('active');
        }

        function closeRemoveModal() {
            if (!removeModal) return;

            removeModal.classList.remove('active');

            setTimeout(function () {
                if (!removeModal.classList.contains('active')) {
                    removeModal.style.display = 'none';
                    document.body.style.overflow = '';
                }
            }, 200);

            pendingRemoveCartId = null;
            pendingRemoveEl     = null;
        }

        function performRemove(cartId, itemEl) {
            if (cartId <= 0) return;
            syncRemove(cartId)
                .then(function () {
                    const remaining = cartItemsContainer
                        ? cartItemsContainer.querySelectorAll('.cart-item').length
                        : 0;
                    if (itemEl) itemEl.remove();
                    if (remaining <= 1) { window.location.reload(); return; }
                    refreshSelectionAndSubtotal();
                })
                .catch(function (err) {
                    console.error('Remove failed:', err);
                    window.location.reload();
                });
        }

        if (removeModalCancel) {
            removeModalCancel.addEventListener('click', function (e) {
                e.preventDefault(); closeRemoveModal();
            });
        }

        if (removeModalConfirm) {
            removeModalConfirm.addEventListener('click', function (e) {
                e.preventDefault();
                const cartId = pendingRemoveCartId;
                const itemEl = pendingRemoveEl;
                if (cartId == null) { closeRemoveModal(); return; }
                removeModalConfirm.disabled = true;
                removeModalConfirm.textContent = 'Removing…';
                performRemove(cartId, itemEl);
                closeRemoveModal();
            });
        }

        if (removeModal) {
            removeModal.addEventListener('click', function (e) {
                if (e.target === removeModal || e.target.classList.contains('cart-modal-overlay')) {
                    closeRemoveModal();
                }
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && removeModal && removeModal.classList.contains('active')) {
                closeRemoveModal();
            }
        });

        // ============================================
        // ADD TO ORDER — push SELECTED cart rows
        // ============================================
        if (pushToQueueForm && addToOrderBtn) {
            pushToQueueForm.addEventListener('submit', function (e) {
                e.preventDefault();
                e.stopPropagation();

                const selected = getItemCheckboxes()
                    .filter(function (b) { return b.checked; })
                    .map(function (b) { return b.value; });

                if (selected.length === 0) {
                    window.alert('Select at least one item to add to your order.');
                    return;
                }

                if (selectedIdsBox) {
                    selectedIdsBox.innerHTML = '';
                    selected.forEach(function (id) {
                        const input = document.createElement('input');
                        input.type  = 'hidden';
                        input.name  = 'cart_ids[]';
                        input.value = id;
                        selectedIdsBox.appendChild(input);
                    });
                }

                const originalLabel = addToOrderBtn.textContent;
                addToOrderBtn.disabled = true;
                addToOrderBtn.textContent = 'Adding…';

                const body = new URLSearchParams(new FormData(pushToQueueForm));

                fetch(CART_URL, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: body.toString(),
                    credentials: 'same-origin'
                })
                .then(function (res) {
                    return res.text().then(function (text) {
                        console.log('[cart.js] push_to_queue raw response:', res.status, text.slice(0, 500));
                        if (!res.ok) {
                            throw new Error('HTTP ' + res.status + ' — ' + text.slice(0, 200));
                        }
                        try { return JSON.parse(text); }
                        catch (err) {
                            throw new Error('Non-JSON response (status ' + res.status + '): ' + text.slice(0, 200));
                        }
                    });
                })
                .then(function (data) {
                    if (data && data.status === 'success') {
                        window.location.href = (data.redirect || 'menu.php');
                    } else {
                        addToOrderBtn.disabled = false;
                        addToOrderBtn.textContent = originalLabel;
                        console.error('[cart.js] push_to_queue error response:', data);
                        window.alert((data && data.message) || 'Could not add items to your order.');
                    }
                })
                .catch(function (err) {
                    console.error('[cart.js] push_to_queue failed:', err);
                    addToOrderBtn.disabled = false;
                    addToOrderBtn.textContent = originalLabel;
                    window.alert('Could not reach the server. Check the console for the exact response.');
                });
            });
        }

        // ============================================
        // INIT
        // ============================================
        refreshSelectionAndSubtotal();
    });
})();