/**
 * FitPal Restaurant Products List — client behaviour.
 *
 * Owns the interactive surface of restaurant/pages/products.php:
 *
 *   - Filter tabs (All / Active / Inactive) narrow the grid by
 *     toggling visibility on .product-card[data-active].
 *   - Edit button opens the edit confirmation modal, then
 *     navigates to product-edit.php?id=<n>.
 *   - Delete button opens the delete confirmation modal, then
 *     POSTs to the handler and removes the card from the grid on
 *     success.
 *   - Notification modal shows the response message.
 *
 * ---------------------------------------------------------------------
 * DOM CONTRACT
 * ---------------------------------------------------------------------
 * Reads from products.php:
 *
 *   #productsPage         root; carries data-csrf-token and
 *                         data-handler-url
 *   #filterTabs           .filter-tab[data-filter="all|active|inactive"]
 *   #productsGrid         .product-card[data-active="0|1"]
 *   #productCount         text node updated on filter change
 *   #editModal            .modal opens via .is-open
 *   #editProductName      text node inside the modal
 *   #confirmEditBtn       anchor that navigates to product-edit.php
 *   #deleteModal          .modal opens via .is-open
 *   #deleteProductName    text node inside the modal
 *   #confirmDeleteBtn     button that POSTs the delete
 *   #notificationModal    .modal opens via .is-open
 *   #notificationMessage  text node
 *
 * Every element is optional. The script degrades to a no-op if the
 * root element is missing.
 *
 * ---------------------------------------------------------------------
 * FILTER TABS AND THE ADD CARD
 * ---------------------------------------------------------------------
 * The add-product card carries no data-active attribute and is
 * never hidden by a filter. The filter loop only inspects
 * .product-card[data-active]. The add card is identified by the
 * presence of the .add-product-card modifier, which has no
 * data-active, so the loop's querySelectorAll skips it.
 *
 * ---------------------------------------------------------------------
 * SCROLLBAR-WIDTH COMPENSATION
 * ---------------------------------------------------------------------
 * When a modal opens, the page beneath it must not scroll. Setting
 * body overflow:hidden on a browser with a classic scrollbar
 * removes the scrollbar and shifts the page to the right by the
 * scrollbar's width. The helper below measures that width once at
 * open time and applies it as padding-right on the body, so the
 * page beneath the modal does not move. The padding is removed at
 * close. The measured width is cached for the page's lifetime.
 *
 * ---------------------------------------------------------------------
 * NO INLINE HANDLERS
 * ---------------------------------------------------------------------
 * Every click is bound here. The page carries a JSON config on
 * window.FITPAL_PRODUCTS_CONFIG for the handler URL, the CSRF
 * token, and the asset base. Those values are read once and cached
 * at boot.
 *
 * @package FitPal
 * @version 2.0 — Edit confirmation modal. Scrollbar-width
 *                compensation on modal open and close. Add card is
 *                never hidden by filters.
 *
 *                (1.0: initial version.)
 */

(function () {
    'use strict';

    function boot() {
        var page = document.getElementById('productsPage');
        if (!page) return;

        var config      = window.FITPAL_PRODUCTS_CONFIG || {};
        var CSRF_TOKEN  = page.dataset.csrfToken  || config.csrfToken  || '';
        var HANDLER_URL = page.dataset.handlerUrl || config.handlerUrl || '../backend/handlers/product-handler.php';

        var filterTabs          = document.getElementById('filterTabs');
        var productsGrid        = document.getElementById('productsGrid');
        var productCount        = document.getElementById('productCount');

        var editModal           = document.getElementById('editModal');
        var editProductName     = document.getElementById('editProductName');
        var confirmEditBtn      = document.getElementById('confirmEditBtn');

        var deleteModal         = document.getElementById('deleteModal');
        var deleteProductName   = document.getElementById('deleteProductName');
        var confirmDeleteBtn    = document.getElementById('confirmDeleteBtn');

        var notificationModal   = document.getElementById('notificationModal');
        var notificationMessage = document.getElementById('notificationMessage');

        var pendingDeleteId     = 0;
        var pendingDeleteCard   = null;
        var isProcessing        = false;

        /* ============================================
           SCROLLBAR-WIDTH HELPERS
           ============================================ */

        var cachedScrollbarWidth = null;

        function getScrollbarWidth() {
            if (cachedScrollbarWidth !== null) {
                return cachedScrollbarWidth;
            }

            // Measure by creating two off-screen divs, one with a
            // scrollbar and one without, and comparing their widths.
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

            // Save the current padding so it can be restored exactly.
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
            // force reflow so the transition runs from opacity: 0
            void modal.offsetWidth;
            modal.classList.add('is-open');
        }

        function closeModal(modal) {
            if (!modal) return;
            modal.classList.remove('is-open');
            setTimeout(function () {
                if (!modal.classList.contains('is-open')) {
                    modal.style.display = 'none';
                    // Only release the scroll lock if no other modal
                    // is still open.
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
                if (which === 'edit-modal')         closeModal(editModal);
                if (which === 'delete-modal')       closeModal(deleteModal);
                if (which === 'notification-modal') closeModal(notificationModal);
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;

            if (editModal && editModal.classList.contains('is-open')) {
                closeModal(editModal);
            }
            if (deleteModal && deleteModal.classList.contains('is-open')) {
                closeModal(deleteModal);
                pendingDeleteId = 0;
                pendingDeleteCard = null;
            }
            if (notificationModal && notificationModal.classList.contains('is-open')) {
                closeModal(notificationModal);
            }
        });

        function showNotification(message) {
            if (notificationMessage) {
                notificationMessage.textContent = message;
            }
            openModal(notificationModal);
        }

        /* ============================================
           FILTER TABS
           ============================================ */

        function updateProductCount(filter) {
            if (!productCount || !productsGrid) return;

            var cards = productsGrid.querySelectorAll('.product-card[data-active]');
            var visible = 0;

            cards.forEach(function (card) {
                if (card.style.display !== 'none') visible++;
            });

            var names = {
                'all':      'all products',
                'active':   'active products',
                'inactive': 'inactive products'
            };
            var name = names[filter] || 'products';

            productCount.textContent =
                'Showing ' + name + ' (' + visible + ')';
        }

        function applyFilter(filter) {
            if (!productsGrid) return;

            // Only product cards carry data-active. The add card has
            // no data-active and is therefore never hidden by a
            // filter change.
            var cards = productsGrid.querySelectorAll('.product-card[data-active]');

            cards.forEach(function (card) {
                var isActive = card.dataset.active === '1';

                if (filter === 'all') {
                    card.style.display = '';
                    return;
                }
                if (filter === 'active') {
                    card.style.display = isActive ? '' : 'none';
                    return;
                }
                if (filter === 'inactive') {
                    card.style.display = isActive ? 'none' : '';
                }
            });

            updateProductCount(filter);
        }

        if (filterTabs) {
            filterTabs.addEventListener('click', function (e) {
                var tab = e.target.closest('.filter-tab');
                if (!tab) return;

                filterTabs.querySelectorAll('.filter-tab').forEach(function (t) {
                    t.classList.toggle('active', t === tab);
                });

                applyFilter(tab.dataset.filter || 'all');
            });
        }

        /* ============================================
           EDIT FLOW
           ============================================ */

        if (productsGrid) {
            productsGrid.addEventListener('click', function (e) {
                var btn = e.target.closest('.edit-btn');
                if (!btn) return;

                e.preventDefault();
                e.stopPropagation();

                var productId = parseInt(btn.dataset.productId, 10) || 0;
                if (productId <= 0) return;

                var name = btn.dataset.productName || 'this product';
                if (editProductName) {
                    editProductName.textContent = name;
                }
                if (confirmEditBtn) {
                    confirmEditBtn.setAttribute(
                        'href',
                        'product-edit.php?id=' + productId
                    );
                }

                openModal(editModal);
            });
        }

        /* ============================================
           DELETE FLOW
           ============================================ */

        if (productsGrid) {
            productsGrid.addEventListener('click', function (e) {
                var btn = e.target.closest('.delete-btn');
                if (!btn) return;

                e.preventDefault();
                e.stopPropagation();

                pendingDeleteId = parseInt(btn.dataset.productId, 10) || 0;
                pendingDeleteCard = btn.closest('.product-card');

                var name = btn.dataset.productName || 'this product';
                if (deleteProductName) {
                    deleteProductName.textContent = name;
                }

                openModal(deleteModal);
            });
        }

        if (confirmDeleteBtn) {
            confirmDeleteBtn.addEventListener('click', function () {
                if (pendingDeleteId <= 0 || isProcessing) return;

                isProcessing = true;
                var originalLabel = confirmDeleteBtn.textContent;
                confirmDeleteBtn.disabled = true;
                confirmDeleteBtn.textContent = 'Deleting…';

                var body = new URLSearchParams();
                body.append('action', 'delete_product');
                body.append('csrf_token', CSRF_TOKEN);
                body.append('product_id', String(pendingDeleteId));

                fetch(HANDLER_URL, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: body.toString(),
                    credentials: 'same-origin'
                })
                .then(function (res) {
                    return res.text().then(function (text) {
                        try { return JSON.parse(text); }
                        catch (err) {
                            console.error('[products.js] Non-JSON response:', res.status, text.slice(0, 200));
                            return {
                                status: 'error',
                                message: 'Server returned an unexpected response.'
                            };
                        }
                    });
                })
                .then(function (data) {
                    closeModal(deleteModal);

                    if (data && data.status === 'success') {
                        if (pendingDeleteCard && pendingDeleteCard.parentNode) {
                            pendingDeleteCard.parentNode.removeChild(pendingDeleteCard);
                        }
                        showNotification(data.message || 'Product deleted.');

                        // Recompute the count under the currently
                        // active filter now that a card is gone.
                        var activeTab = filterTabs
                            ? filterTabs.querySelector('.filter-tab.active')
                            : null;
                        var currentFilter = activeTab
                            ? (activeTab.dataset.filter || 'all')
                            : 'all';
                        updateProductCount(currentFilter);
                    } else {
                        showNotification((data && data.message) || 'Could not delete the product.');
                    }

                    pendingDeleteId = 0;
                    pendingDeleteCard = null;
                })
                .catch(function (err) {
                    console.error('[products.js] Delete failed:', err);
                    closeModal(deleteModal);
                    showNotification('Network error. Please try again.');
                    pendingDeleteId = 0;
                    pendingDeleteCard = null;
                })
                .finally(function () {
                    isProcessing = false;
                    confirmDeleteBtn.disabled = false;
                    confirmDeleteBtn.textContent = originalLabel;
                });
            });
        }

        // Initial count for the default "All" tab.
        updateProductCount('all');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();