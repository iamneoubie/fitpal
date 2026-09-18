/**
 * FitPal Menu Page
 * Version 9.1
 *
 * Handles:
 *   - Fixed filter bar + scroll shadow
 *   - Dropdown toggles (dietary / allergen / restaurant)
 *   - Quantity controls on product cards
 *   - Add to Cart  (persistent) → cart-handler.php
 *   - Add to Order (session)    → window.addToQueue() from queue-panel.js
 *   - Price input auto-submit
 *   - Search debounce + Enter submit
 *
 * Add-to-Cart vs Add-to-Order:
 *   Each product card exposes TWO buttons:
 *     .add-to-cart-btn  → persistent cart (survives sessions)
 *     .add-to-order-btn → session queue (checkout immediately)
 *   Both read the same quantity input within the same .action-row.
 *
 * @package FitPal
 * @version 9.1 — Removed order tracker; filter bar is fixed in CSS.
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ============================================
        // DOM REFERENCES
        // ============================================
        const menuFilters = document.getElementById('menuFilters');
        const searchInput = document.getElementById('menuSearch');
        const filterForm  = document.getElementById('filterForm');
        const csrfToken   = window.FITPAL_CSRF_TOKEN || '';

        // ============================================
        // FILTER BAR SCROLL SHADOW
        // (bar is fixed in CSS; this just toggles the shadow class)
        // ============================================
        let scrollTicking = false;

        function handleFilterScroll() {
            if (!menuFilters) return;
            const top = menuFilters.getBoundingClientRect().top;
            menuFilters.classList.toggle('scrolled', top <= 0);
        }

        window.addEventListener('scroll', function () {
            if (scrollTicking) return;
            scrollTicking = true;
            requestAnimationFrame(function () {
                handleFilterScroll();
                scrollTicking = false;
            });
        }, { passive: true });

        // ============================================
        // DROPDOWN TOGGLES
        // ============================================
        const dropdowns = document.querySelectorAll('.filter-dropdown');

        function closeAllDropdowns(except) {
            document.querySelectorAll('.filter-dropdown-menu.open').forEach(function (menu) {
                if (menu === except) return;
                menu.classList.remove('open');
                const toggle = menu.closest('.filter-dropdown')?.querySelector('.filter-dropdown-toggle');
                if (toggle) toggle.setAttribute('aria-expanded', 'false');
            });
        }

        dropdowns.forEach(function (dropdown) {
            const toggle   = dropdown.querySelector('.filter-dropdown-toggle');
            const menu     = dropdown.querySelector('.filter-dropdown-menu');
            const closeBtn = dropdown.querySelector('.filter-dropdown-close');

            if (!toggle || !menu) return;

            toggle.addEventListener('click', function (e) {
                e.stopPropagation();
                const isOpen = menu.classList.contains('open');

                closeAllDropdowns(menu);

                if (isOpen) {
                    menu.classList.remove('open');
                    toggle.setAttribute('aria-expanded', 'false');
                } else {
                    menu.classList.add('open');
                    toggle.setAttribute('aria-expanded', 'true');
                }
            });

            if (closeBtn) {
                closeBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    menu.classList.remove('open');
                    toggle.setAttribute('aria-expanded', 'false');
                });
            }

            document.addEventListener('click', function (e) {
                if (!dropdown.contains(e.target) && menu.classList.contains('open')) {
                    menu.classList.remove('open');
                    toggle.setAttribute('aria-expanded', 'false');
                }
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && menu.classList.contains('open')) {
                    menu.classList.remove('open');
                    toggle.setAttribute('aria-expanded', 'false');
                    toggle.focus();
                }
            });
        });

        // ============================================
        // QUANTITY CONTROLS (product cards)
        // ============================================
        document.querySelectorAll('.quantity-control').forEach(function (control) {
            const minusBtn = control.querySelector('.qty-minus');
            const plusBtn  = control.querySelector('.qty-plus');
            const input    = control.querySelector('.qty-input');

            if (!minusBtn || !plusBtn || !input) return;

            function clamp(value) {
                const max = parseInt(input.max, 10) || 999;
                if (value < 1)   value = 1;
                if (value > max) value = max;
                return value;
            }

            function bump(delta) {
                const current = parseInt(input.value, 10) || 1;
                input.value = clamp(current + delta);
            }

            minusBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                bump(-1);
            });

            plusBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                bump(1);
            });

            input.addEventListener('change', function (e) {
                e.stopPropagation();
                this.value = clamp(parseInt(this.value, 10) || 1);
            });

            control.addEventListener('click', function (e) {
                e.stopPropagation();
            });
        });

        // ============================================
        // SHARED HELPERS FOR BOTH ACTIONS
        // ============================================

        /**
         * Read and clamp the quantity from an .action-row.
         */
        function readQuantity(row, stock) {
            const input = row.querySelector('.qty-input');
            let quantity = parseInt(input ? input.value : '1', 10) || 1;
            if (quantity < 1)     quantity = 1;
            if (quantity > stock) quantity = stock;
            return quantity;
        }

        /**
         * Flash visual feedback on a button and re-enable it after a short delay.
         */
        function flashButton(btn, duration) {
            duration = duration || 1500;
            btn.classList.add('added');
            btn.disabled = true;
            setTimeout(function () {
                btn.classList.remove('added');
                btn.disabled = false;
            }, duration);
        }

        /**
         * Resolve the product-card that owns the given action-row.
         */
        function findProductCard(row) {
            return row ? row.closest('.product-card') : null;
        }

        /**
         * Read all product data-* attributes from a product card.
         */
        function readProductData(card) {
            return {
                productId:      parseInt(card.dataset.productId, 10) || 0,
                productName:    card.dataset.productName || 'Product',
                productPrice:   parseFloat(card.dataset.productPrice) || 0,
                productImage:   card.dataset.productImage || '',
                stock:          parseInt(card.dataset.productStock, 10) || 999,
                restaurantName: card.dataset.restaurantName || '',
                branchName:     card.dataset.branchName || ''
            };
        }

        // ============================================
        // ADD-TO-CART — POSTs to cart-handler.php
        // ============================================
        function addToCart(row, btn) {
            const card = findProductCard(row);
            if (!card) return;

            const data = readProductData(card);
            const quantity = readQuantity(row, data.stock);

            if (data.productId <= 0) {
                showMenuToast('Could not add to cart: missing product.', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'add');
            formData.append('csrf_token', csrfToken);
            formData.append('product_id', String(data.productId));
            formData.append('quantity', String(quantity));

            flashButton(btn);

            const cartUrl = row.dataset.cartUrl || '../backend/handlers/cart-handler.php';

            fetch(cartUrl, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: formData
            })
            .then(function (r) {
                return r.text().then(function (text) {
                    try { return JSON.parse(text); }
                    catch (e) {
                        console.error('[menu.js] Cart non-JSON response:', r.status, text.slice(0, 200));
                        return { status: 'error', message: 'Server returned ' + r.status };
                    }
                });
            })
            .then(function (response) {
                if (response && response.status === 'success') {
                    showMenuToast(data.productName + ' added to cart', 'success');
                } else {
                    showMenuToast((response && response.message) || 'Could not add to cart', 'error');
                }
            })
            .catch(function () {
                showMenuToast('Network error. Please try again.', 'error');
            });
        }

        // ============================================
        // ADD-TO-ORDER — delegates to queue-panel.js
        // ============================================
        function addToOrder(row, btn) {
            const card = findProductCard(row);
            if (!card) return;

            const data = readProductData(card);
            const quantity = readQuantity(row, data.stock);

            if (typeof window.addToQueue !== 'function') {
                console.error(
                    '[menu.js] window.addToQueue is undefined. ' +
                    'queue-panel.js failed to initialize or was not loaded.'
                );
                showMenuToast('The order panel could not be loaded. Please refresh.', 'error');
                return;
            }

            window.addToQueue(
                data.productId,
                data.productName,
                data.productPrice,
                quantity,
                data.productImage,
                data.stock,
                data.restaurantName,
                data.branchName
            );

            flashButton(btn);
        }

        // ============================================
        // DELEGATED CLICK — routes to the right action
        // ============================================
        document.addEventListener('click', function (e) {
            const cartBtn = e.target.closest('.add-to-cart-btn');
            if (cartBtn && !cartBtn.disabled) {
                const row = cartBtn.closest('.action-row');
                if (row) {
                    e.preventDefault();
                    e.stopPropagation();
                    addToCart(row, cartBtn);
                }
                return;
            }

            const orderBtn = e.target.closest('.add-to-order-btn');
            if (orderBtn && !orderBtn.disabled) {
                const row = orderBtn.closest('.action-row');
                if (row) {
                    e.preventDefault();
                    e.stopPropagation();
                    addToOrder(row, orderBtn);
                }
                return;
            }
        }, true);

        // ============================================
        // ENTER KEY in quantity input triggers Add to Order
        // (the primary action — queue for immediate checkout)
        // ============================================
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;

            const input = e.target;
            if (!input.classList || !input.classList.contains('qty-input')) return;

            const row = input.closest('.action-row');
            if (!row) return;

            e.preventDefault();
            e.stopPropagation();

            const btn = row.querySelector('.add-to-order-btn');
            if (btn) btn.click();
        }, true);

        // ============================================
        // MENU TOAST (small, local to menu.js)
        // ============================================
        function showMenuToast(message, type) {
            let toast = document.getElementById('menuToast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'menuToast';
                toast.style.cssText = [
                    'position:fixed', 'top:80px', 'right:20px',
                    'padding:12px 20px', 'border-radius:8px',
                    'font-size:14px', 'font-weight:500', 'z-index:9999',
                    'transform:translateX(120%)',
                    'transition:transform .3s cubic-bezier(.4,0,.2,1)',
                    'max-width:360px', 'box-shadow:0 4px 16px rgba(0,0,0,.15)'
                ].join(';');
                document.body.appendChild(toast);
            }

            var palette = {
                success: ['#d1fae5', '#065f46'],
                error:   ['#fee2e2', '#991b1b'],
                info:    ['#dbeafe', '#1e40af']
            };
            var colors = palette[type] || palette.info;
            toast.style.background = colors[0];
            toast.style.color      = colors[1];
            toast.textContent      = message;

            void toast.offsetWidth;
            toast.style.transform = 'translateX(0)';

            clearTimeout(toast._timer);
            toast._timer = setTimeout(function () {
                toast.style.transform = 'translateX(120%)';
            }, 2600);
        }

        // ============================================
        // FILTER AUTO-SUBMIT — price inputs
        // ============================================
        document.querySelectorAll('.price-input').forEach(function (input) {
            input.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;
                e.preventDefault();
                e.stopPropagation();
                if (filterForm) filterForm.submit();
            });

            input.addEventListener('blur', function (e) {
                e.stopPropagation();
                const previous = this.getAttribute('data-default') || '';
                if (this.value !== previous && filterForm) {
                    filterForm.submit();
                }
            });

            input.setAttribute('data-default', input.value);

            input.addEventListener('click', function (e) {
                e.stopPropagation();
            });
        });

        document.querySelectorAll('.price-apply-btn').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (filterForm) filterForm.submit();
            });
        });

        // ============================================
        // SEARCH DEBOUNCE
        // ============================================
        let searchTimeout    = null;
        let searchSubmitting = false;

        function submitFilters() {
            if (!filterForm || searchSubmitting) return;
            searchSubmitting = true;
            filterForm.submit();
        }

        if (searchInput) {
            searchInput.addEventListener('input', function (e) {
                e.stopPropagation();
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(submitFilters, 500);
            });

            searchInput.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;
                e.preventDefault();
                e.stopPropagation();
                clearTimeout(searchTimeout);
                submitFilters();
            });

            searchInput.addEventListener('click', function (e) {
                e.stopPropagation();
            });
        }

        // ============================================
        // SMOOTH SCROLL AFTER FILTER
        // ============================================
        const urlParams  = new URLSearchParams(window.location.search);
        const hasFilters =
            urlParams.has('search') ||
            urlParams.has('tags') ||
            urlParams.has('allergens') ||
            urlParams.has('restaurant_id') ||
            urlParams.has('min_price') ||
            urlParams.has('max_price');

        if (hasFilters) {
            const restaurantList = document.querySelector('.restaurant-list');
            if (restaurantList) {
                const offset = restaurantList.getBoundingClientRect().top
                             + window.pageYOffset
                             - 140;   // header (70) + filter bar (~64) + small gap

                setTimeout(function () {
                    window.scrollTo({ top: offset, behavior: 'smooth' });
                }, 300);
            }
        }

        console.log('Menu JS v9.1 initialized');
    });
})();