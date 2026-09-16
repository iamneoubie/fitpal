/**
 * FitPal Menu Page
 * Version 8.0
 *
 * Handles:
 *   - Sticky filter bar + scroll shadow
 *   - Dropdown toggles (dietary / allergen / restaurant)
 *   - Quantity controls on product cards
 *   - Add-to-queue via delegated CLICK on .add-btn
 *   - Price input auto-submit
 *   - Search debounce + Enter submit
 *   - Active order tracker auto-hide on mobile
 *
 * Notes:
 *   - The add button is type="button", so there is no native form
 *     submit to intercept. This file binds a delegated `click`
 *     listener on document that catches every .add-btn and calls
 *     window.addToQueue() — which is provided by queue-panel.js.
 *   - window.addToQueue talks to queue-handler.php. This file
 *     NEVER talks to the queue endpoint directly.
 *
 * @package FitPal
 * @version 8.0
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ============================================
        // DOM REFERENCES
        // ============================================
        const menuFilters = document.getElementById('menuFilters');
        const header      = document.querySelector('.header');
        const searchInput = document.getElementById('menuSearch');
        const filterForm  = document.getElementById('filterForm');

        // ============================================
        // STICKY FILTER OFFSET
        // ============================================
        function updateStickyOffset() {
            if (menuFilters && header) {
                menuFilters.style.top = header.offsetHeight + 'px';
            }
        }

        updateStickyOffset();
        window.addEventListener('resize', updateStickyOffset);

        // ============================================
        // STICKY FILTER SCROLL SHADOW
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
        // ADD-TO-QUEUE — delegated click on .add-btn
        //
        // The button is type="button", so this is the ONLY
        // place that reacts to it. We read everything from
        // the enclosing .product-card's data-* attributes.
        // ============================================
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.add-btn');
            if (!btn) return;
            if (btn.disabled) return;

            const form = btn.closest('.add-to-cart-form');
            if (!form) return;

            const productCard = form.closest('.product-card');
            if (!productCard) {
                console.error('[menu.js] .add-btn clicked outside a .product-card. Ignoring.');
                return;
            }

            e.preventDefault();
            e.stopPropagation();

            const quantityInput = form.querySelector('input[name="quantity"]');
            const stock         = parseInt(productCard.dataset.productStock, 10) || 999;
            let quantity        = parseInt(quantityInput ? quantityInput.value : '1', 10) || 1;

            if (quantity < 1)     quantity = 1;
            if (quantity > stock) quantity = stock;

            if (typeof window.addToQueue !== 'function') {
                console.error(
                    '[menu.js] window.addToQueue is undefined. ' +
                    'queue-panel.js failed to initialize or was not loaded.'
                );
                window.alert('The order panel could not be loaded. Please refresh the page.');
                return;
            }

            window.addToQueue(
                parseInt(productCard.dataset.productId, 10),
                productCard.dataset.productName || 'Product',
                parseFloat(productCard.dataset.productPrice) || 0,
                quantity,
                productCard.dataset.productImage || '',
                stock,
                productCard.dataset.restaurantName || '',
                productCard.dataset.branchName || ''
            );

            // Visual feedback
            btn.classList.add('added');
            btn.disabled = true;
            setTimeout(function () {
                btn.classList.remove('added');
                btn.disabled = false;
            }, 1500);
        }, true);

        // ============================================
        // ENTER KEY in quantity input triggers add
        // (since the button is type="button", Enter doesn't submit)
        // ============================================
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;

            const input = e.target;
            if (!input.classList || !input.classList.contains('qty-input')) return;

            const form = input.closest('.add-to-cart-form');
            if (!form) return;

            e.preventDefault();
            e.stopPropagation();

            const btn = form.querySelector('.add-btn');
            if (btn) btn.click();
        }, true);

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
                             - 120;

                setTimeout(function () {
                    window.scrollTo({ top: offset, behavior: 'smooth' });
                }, 300);
            }
        }

        // ============================================
        // ORDER TRACKER — auto-hide on mobile
        // ============================================
        (function setupOrderTracker() {
            const orderTracker = document.getElementById('orderTracker');
            if (!orderTracker) return;

            if (window.innerWidth > 768) return;

            let dismissed = false;
            let hideTimer = null;

            function hideTracker() {
                orderTracker.style.transition = 'transform 0.4s ease, opacity 0.4s ease';
                orderTracker.style.transform  = 'translateY(100%)';
                orderTracker.style.opacity    = '0';
                dismissed = true;
            }

            function showTracker() {
                orderTracker.style.transform = 'translateY(0)';
                orderTracker.style.opacity   = '1';
                dismissed = false;
                clearTimeout(hideTimer);
                hideTimer = setTimeout(hideTracker, 8000);
            }

            hideTimer = setTimeout(hideTracker, 8000);

            let lastScrollY = window.pageYOffset;
            window.addEventListener('scroll', function () {
                const currentY = window.pageYOffset;
                if (currentY < lastScrollY && dismissed) {
                    showTracker();
                }
                lastScrollY = currentY;
            }, { passive: true });
        })();

        console.log('Menu JS v8.0 initialized');
    });
})();