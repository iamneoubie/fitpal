/**
 * FitPal Customer Cart Page JavaScript
 * Version 1.9 — Scroll lock applied before the modal is shown, and
 *                released after it is hidden. This prevents the page
 *                from visibly reflowing when the body scrollbar
 *                disappears and reappears around the modal fade.
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
 * @package FitPal
 * @version 1.9
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        const cartSubtotalEl     = document.getElementById('cartSubtotal');
        const cartItemsContainer = document.getElementById('cartItems');

        const removeModal        = document.getElementById('cartRemoveModal');
        const removeModalName    = document.getElementById('cartModalItemName');
        const removeModalCancel  = document.getElementById('cartModalCancel');
        const removeModalConfirm = document.getElementById('cartModalConfirm');

        const pushToQueueForm    = document.getElementById('cartPushToQueueForm');
        const addToOrderBtn      = document.getElementById('cartAddToOrderBtn');
        const selectedIdsBox     = document.getElementById('cartSelectedIds');
        const selectAllPage      = document.getElementById('cartSelectAllPage');
        const selectionCountEl   = document.getElementById('cartSelectionCount');

        const csrfToken = window.FITPAL_CSRF_TOKEN || '';

        let pendingRemoveCartId = null;
        let pendingRemoveEl     = null;

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
                csrf_token: csrfToken
            });

            return fetch('../backend/handlers/cart-handler.php', {
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
                csrf_token: csrfToken
            });

            return fetch('../backend/handlers/cart-handler.php', {
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
        // off-screen — the page reflows to fill the space the scrollbar
        // was using, but nobody sees it because the modal isn't visible
        // yet. Then show the modal on an already-stable page.
        //
        // On close, hide the modal first, and release the scroll lock
        // only AFTER the fade-out completes. Releasing it earlier would
        // reflow the page behind the fading modal, which the user would
        // see as a jump.
        // ============================================

        function openRemoveModal(cartId, productName, itemEl) {
            if (!removeModal) { performRemove(cartId, itemEl); return; }

            pendingRemoveCartId = cartId;
            pendingRemoveEl     = itemEl || null;

            if (removeModalName) removeModalName.textContent = productName;

            // 1. Lock scroll while the modal is hidden.
            document.body.style.overflow = 'hidden';

            // 2. Show the modal on the now-stable page.
            removeModal.style.display = 'flex';
            void removeModal.offsetWidth;
            removeModal.classList.add('active');
        }

        function closeRemoveModal() {
            if (!removeModal) return;

            // 1. Start the fade-out. Page stays locked.
            removeModal.classList.remove('active');

            // 2. After the fade completes, hide and release the lock.
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

                const formAction =
                    pushToQueueForm.getAttribute('action') ||
                    '../backend/handlers/cart-handler.php';

                fetch(formAction, {
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