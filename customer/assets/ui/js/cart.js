/**
 * FitPal Customer Cart Page JavaScript
 * Version 1.0
 *
 * Handles:
 *  - Quantity stepper (+ / −) with min/max clamping
 *  - Quantity input changes (debounced sync with server)
 *  - Remove-item confirmation modal
 *  - Live subtotal recalculation
 *  - Empty-state transition when the last item is removed
 *
 * @package FitPal
 * @version 1.0 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ============================================
        // DOM REFERENCES
        // ============================================
        const cartContainer = document.querySelector('.cart-container');
        const cartSubtotalEl = document.getElementById('cartSubtotal');
        const cartItemsContainer = document.getElementById('cartItems');

        const removeModal       = document.getElementById('cartRemoveModal');
        const removeModalName   = document.getElementById('cartModalItemName');
        const removeModalCancel = document.getElementById('cartModalCancel');
        const removeModalConfirm= document.getElementById('cartModalConfirm');

        const csrfToken = window.FITPAL_CSRF_TOKEN || '';
        const assetBase = window.FITPAL_ASSET_BASE || '../../shared/';

        let pendingRemoveCartId = null;
        let pendingRemoveEl     = null;

        // ============================================
        // HELPERS
        // ============================================

        /**
         * Format a number as Philippine peso.
         */
        function formatPeso(amount) {
            const n = Number(amount) || 0;
            return '₱' + n.toFixed(2);
        }

        /**
         * Recompute the subtotal from all available cart items on the page
         * and update the summary element.
         */
        function recalculateSubtotal() {
            if (!cartItemsContainer) return;

            const items = cartItemsContainer.querySelectorAll('.cart-item');
            let total = 0;

            items.forEach(function (item) {
                const price    = parseFloat(item.dataset.price) || 0;
                const qtyInput = item.querySelector('.qty-input');
                const qty      = parseInt(qtyInput ? qtyInput.value : '0', 10) || 0;
                total += price * qty;
            });

            if (cartSubtotalEl) {
                cartSubtotalEl.textContent = formatPeso(total);
            }
        }

        /**
         * Update the subtotal display for one row.
         */
        function updateRowSubtotal(itemEl) {
            if (!itemEl) return;

            const price    = parseFloat(itemEl.dataset.price) || 0;
            const qtyInput = itemEl.querySelector('.qty-input');
            const qty      = parseInt(qtyInput ? qtyInput.value : '0', 10) || 0;

            const rowTotalEl = itemEl.querySelector('.cart-item-subtotal-amount');
            if (rowTotalEl) {
                rowTotalEl.textContent = formatPeso(price * qty);
            }
        }

        /**
         * Show the empty state when the last item is removed.
         * Reloads the page instead — simpler and guarantees a fresh state.
         */
        function refreshPage() {
            window.location.reload();
        }

        // ============================================
        // SERVER SYNC
        // ============================================

        /**
         * Persist a quantity change to the server.
         *
         * @param {number} cartId
         * @param {number} quantity
         * @returns {Promise<boolean>}
         */
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
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 'success') {
                    throw new Error((data && data.message) || 'Failed to update quantity');
                }
                return true;
            });
        }

        /**
         * Persist a removal to the server.
         *
         * @param {number} cartId
         * @returns {Promise<boolean>}
         */
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
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 'success') {
                    throw new Error((data && data.message) || 'Failed to remove item');
                }
                return true;
            });
        }

        // ============================================
        // QUANTITY CONTROLS (event delegation)
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

                    if (minusBtn) {
                        qty -= 1;
                        if (qty < 1) qty = 1;
                    } else {
                        qty += 1;
                        if (qty > stock) qty = stock;
                    }

                    qtyInput.value = qty;
                    updateRowSubtotal(itemEl);
                    recalculateSubtotal();

                    // Persist (fire and forget — UI already updated)
                    syncQuantity(cartId, qty).catch(function (err) {
                        console.error('Quantity sync failed:', err);
                        // Roll back the UI on failure
                        window.location.reload();
                    });
                }

                // Remove button
                const removeBtn = e.target.closest('.cart-item-remove');
                if (removeBtn) {
                    e.preventDefault();
                    e.stopPropagation();

                    const cartId = parseInt(removeBtn.dataset.cartId, 10) || 0;
                    const name   = removeBtn.dataset.productName || 'this item';
                    if (cartId <= 0) return;

                    openRemoveModal(cartId, name, removeBtn.closest('.cart-item'));
                }
            });

            // Quantity input changes
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

            // Prevent wheel-scroll from changing quantity accidentally
            cartItemsContainer.addEventListener('wheel', function (e) {
                if (e.target.closest('.qty-input')) {
                    e.target.blur();
                }
            }, { passive: true });
        }

        // ============================================
        // REMOVE MODAL
        // ============================================

        function openRemoveModal(cartId, productName, itemEl) {
            if (!removeModal) {
                // No modal in DOM — remove directly
                performRemove(cartId, itemEl);
                return;
            }

            pendingRemoveCartId = cartId;
            pendingRemoveEl     = itemEl || null;

            if (removeModalName) {
                removeModalName.textContent = productName;
            }

            removeModal.style.display = 'flex';
            // Force reflow so the transition can play
            void removeModal.offsetWidth;
            removeModal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeRemoveModal() {
            if (!removeModal) return;

            removeModal.classList.remove('active');
            document.body.style.overflow = '';

            setTimeout(function () {
                if (!removeModal.classList.contains('active')) {
                    removeModal.style.display = 'none';
                }
            }, 200);

            pendingRemoveCartId = null;
            pendingRemoveEl     = null;
        }

        function performRemove(cartId, itemEl) {
            if (cartId <= 0) return;

            syncRemove(cartId)
                .then(function () {
                    // Count remaining available items
                    const remaining = cartItemsContainer
                        ? cartItemsContainer.querySelectorAll('.cart-item').length
                        : 0;

                    if (itemEl) {
                        itemEl.remove();
                    }

                    // If we just removed the last available item, reload so the
                    // page flips into the empty state cleanly.
                    if (remaining <= 1) {
                        window.location.reload();
                        return;
                    }

                    recalculateSubtotal();

                    // If the summary checkout button should be disabled because
                    // there are no more items, the reload above handles it.
                    // Otherwise nothing else to do.
                })
                .catch(function (err) {
                    console.error('Remove failed:', err);
                    window.location.reload();
                });
        }

        if (removeModalCancel) {
            removeModalCancel.addEventListener('click', function (e) {
                e.preventDefault();
                closeRemoveModal();
            });
        }

        if (removeModalConfirm) {
            removeModalConfirm.addEventListener('click', function (e) {
                e.preventDefault();
                const cartId = pendingRemoveCartId;
                const itemEl = pendingRemoveEl;

                if (cartId == null) {
                    closeRemoveModal();
                    return;
                }

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

        // Escape key closes the modal
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && removeModal && removeModal.classList.contains('active')) {
                closeRemoveModal();
            }
        });

        // ============================================
        // INIT
        // ============================================
        recalculateSubtotal();
    });
})();