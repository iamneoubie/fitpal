/**
 * FitPal Menu Page JavaScript
 * Version 5.0 - Consolidated all menu-related logic including add-to-queue
 *
 * Handles:
 * - Quantity controls
 * - Add-to-cart/queue form submission
 * - Sticky filter behavior
 * - Dropdown toggles
 * - Search debounce
 * - Price input auto-submit
 * - Active order tracker with auto-hide
 * - Add to queue integration
 *
 * @package FitPal
 * @version 5.0
 */

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {

        // ============================================
        // DOM REFERENCES
        // ============================================
        var menuFilters = document.getElementById('menuFilters');
        var header = document.querySelector('.header');
        var searchInput = document.getElementById('menuSearch');
        var filterForm = document.getElementById('filterForm');

        // ============================================
        // STICKY FILTER OFFSET
        // ============================================
        function updateStickyOffset() {
            if (menuFilters && header) {
                var headerHeight = header.offsetHeight;
                menuFilters.style.top = headerHeight + 'px';
            }
        }

        updateStickyOffset();
        window.addEventListener('resize', function() {
            updateStickyOffset();
        });

        // ============================================
        // STICKY FILTER SCROLL SHADOW
        // ============================================
        function handleFilterScroll() {
            if (menuFilters) {
                var filterTop = menuFilters.getBoundingClientRect().top;
                if (filterTop <= 0) {
                    menuFilters.classList.add('scrolled');
                } else {
                    menuFilters.classList.remove('scrolled');
                }
            }
        }

        var scrollTicking = false;
        window.addEventListener('scroll', function() {
            if (!scrollTicking) {
                requestAnimationFrame(function() {
                    handleFilterScroll();
                    scrollTicking = false;
                });
                scrollTicking = true;
            }
        }, { passive: true });

        // ============================================
        // DROPDOWN TOGGLES
        // ============================================
        var dropdowns = document.querySelectorAll('.filter-dropdown');

        dropdowns.forEach(function(dropdown) {
            var toggle = dropdown.querySelector('.filter-dropdown-toggle');
            var menu = dropdown.querySelector('.filter-dropdown-menu');
            var closeBtn = dropdown.querySelector('.filter-dropdown-close');

            if (!toggle || !menu) return;

            toggle.addEventListener('click', function(e) {
                e.stopPropagation();
                var isOpen = menu.classList.contains('open');

                // Close all other dropdowns
                document.querySelectorAll('.filter-dropdown-menu.open').forEach(function(other) {
                    if (other !== menu) {
                        other.classList.remove('open');
                        var otherToggle = other.closest('.filter-dropdown').querySelector('.filter-dropdown-toggle');
                        if (otherToggle) {
                            otherToggle.setAttribute('aria-expanded', 'false');
                        }
                    }
                });

                if (isOpen) {
                    menu.classList.remove('open');
                    toggle.setAttribute('aria-expanded', 'false');
                } else {
                    menu.classList.add('open');
                    toggle.setAttribute('aria-expanded', 'true');
                }
            });

            // Close button (mobile)
            if (closeBtn) {
                closeBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    menu.classList.remove('open');
                    toggle.setAttribute('aria-expanded', 'false');
                });
            }

            // Close dropdown on outside click
            document.addEventListener('click', function(e) {
                if (!dropdown.contains(e.target) && menu.classList.contains('open')) {
                    menu.classList.remove('open');
                    toggle.setAttribute('aria-expanded', 'false');
                }
            });

            // Close on Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && menu.classList.contains('open')) {
                    menu.classList.remove('open');
                    toggle.setAttribute('aria-expanded', 'false');
                    toggle.focus();
                }
            });
        });

        // ============================================
        // QUANTITY CONTROLS
        // ============================================
        document.querySelectorAll('.quantity-control').forEach(function(control) {
            var minusBtn = control.querySelector('.qty-minus');
            var plusBtn = control.querySelector('.qty-plus');
            var input = control.querySelector('.qty-input');

            if (!minusBtn || !plusBtn || !input) return;

            function updateValue(delta) {
                var val = parseInt(input.value, 10) || 1;
                val += delta;
                var max = parseInt(input.max, 10) || 999;
                if (val < 1) val = 1;
                if (val > max) val = max;
                input.value = val;
            }

            minusBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                updateValue(-1);
            });

            plusBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                updateValue(1);
            });

            input.addEventListener('change', function(e) {
                e.stopPropagation();
                var val = parseInt(this.value, 10) || 1;
                var max = parseInt(this.max, 10) || 999;
                if (val < 1) val = 1;
                if (val > max) val = max;
                this.value = val;
            });

            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    e.stopPropagation();
                    var form = this.closest('.add-to-cart-form');
                    if (form) {
                        var submitBtn = form.querySelector('.add-btn');
                        if (submitBtn) submitBtn.click();
                    }
                }
            });

            control.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });

        // ============================================
        // ADD-TO-CART FORM HANDLING
        // Integrated with queue panel
        // ============================================
        document.querySelectorAll('.add-to-cart-form').forEach(function(form) {
            form.addEventListener('click', function(e) {
                e.stopPropagation();
            });

            form.addEventListener('submit', function(e) {
                e.preventDefault();
                e.stopPropagation();

                var productCard = this.closest('.product-card');
                if (!productCard) {
                    console.warn('Product card not found');
                    return;
                }

                var productId = productCard.dataset.productId;
                var name = productCard.dataset.productName || 'Product';
                var price = parseFloat(productCard.dataset.productPrice) || 0;
                var stock = parseInt(productCard.dataset.productStock) || 999;
                var image = productCard.dataset.productImage || '';
                var restaurantName = productCard.dataset.restaurantName || '';
                var branchName = productCard.dataset.branchName || '';
                var quantityInput = this.querySelector('input[name="quantity"]');
                var quantity = parseInt(quantityInput ? quantityInput.value : 1, 10) || 1;

                if (quantity < 1) quantity = 1;
                if (quantity > stock) quantity = stock;

                // Use the queue panel API if available
                if (typeof window.addToQueue === 'function') {
                    window.addToQueue(
                        parseInt(productId, 10),
                        name,
                        price,
                        quantity,
                        image,
                        stock,
                        restaurantName,
                        branchName
                    );

                    // Visual feedback
                    var btn = this.querySelector('.add-btn');
                    if (btn) {
                        btn.classList.add('added');
                        btn.disabled = true;

                        setTimeout(function() {
                            btn.classList.remove('added');
                            btn.disabled = false;
                        }, 1500);
                    }
                } else {
                    // Fallback: submit the form normally
                    this.submit();
                }
            });
        });

        // ============================================
        // FILTER AUTO-SUBMIT
        // ============================================

        // Price inputs - auto-submit on blur or Enter
        var priceInputs = document.querySelectorAll('.price-input');
        priceInputs.forEach(function(input) {
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    e.stopPropagation();
                    if (filterForm) {
                        filterForm.submit();
                    }
                }
            });

            input.addEventListener('blur', function(e) {
                e.stopPropagation();
                var defaultValue = this.getAttribute('data-default') || '';
                if (this.value !== defaultValue) {
                    if (filterForm) {
                        filterForm.submit();
                    }
                }
            });

            input.setAttribute('data-default', input.value);

            input.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });

        // Price apply button
        document.querySelectorAll('.price-apply-btn').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                if (filterForm) {
                    filterForm.submit();
                }
            });
        });

        // ============================================
        // SEARCH DEBOUNCE
        // ============================================
        var searchTimeout = null;
        if (searchInput) {
            searchInput.addEventListener('input', function(e) {
                e.stopPropagation();
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    if (filterForm) {
                        filterForm.submit();
                    }
                }, 500);
            });

            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    e.stopPropagation();
                    clearTimeout(searchTimeout);
                    if (filterForm) {
                        filterForm.submit();
                    }
                }
            });

            searchInput.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        }

        // ============================================
        // SMOOTH SCROLL AFTER FILTER
        // ============================================
        var urlParams = new URLSearchParams(window.location.search);
        var hasFilters = urlParams.has('search') ||
                         urlParams.has('tags') ||
                         urlParams.has('allergens') ||
                         urlParams.has('restaurant_id') ||
                         urlParams.has('min_price') ||
                         urlParams.has('max_price');

        if (hasFilters) {
            var restaurantList = document.querySelector('.restaurant-list');
            if (restaurantList) {
                var headerOffset = 120;
                var elementPosition = restaurantList.getBoundingClientRect().top;
                var offsetPosition = elementPosition + window.pageYOffset - headerOffset;

                setTimeout(function() {
                    window.scrollTo({
                        top: offsetPosition,
                        behavior: 'smooth'
                    });
                }, 300);
            }
        }

        // ============================================
        // ORDER TRACKER - AUTO-HIDE ON MOBILE
        // ============================================
        var orderTracker = document.getElementById('orderTracker');
        var trackerDismissed = false;

        if (orderTracker) {
            var menuPageEl = document.querySelector('.menu-page');
            if (menuPageEl) {
                menuPageEl.classList.add('has-order-tracker');
            }

            var isMobile = window.innerWidth <= 768;

            if (isMobile) {
                var trackerTimer = setTimeout(function() {
                    if (!trackerDismissed) {
                        orderTracker.style.transition = 'transform 0.4s ease, opacity 0.4s ease';
                        orderTracker.style.transform = 'translateY(100%)';
                        orderTracker.style.opacity = '0';
                        trackerDismissed = true;
                    }
                }, 8000);

                var lastScrollY = window.pageYOffset;
                window.addEventListener('scroll', function() {
                    var currentScrollY = window.pageYOffset;
                    if (currentScrollY < lastScrollY && trackerDismissed) {
                        orderTracker.style.transform = 'translateY(0)';
                        orderTracker.style.opacity = '1';
                        trackerDismissed = false;

                        clearTimeout(trackerTimer);
                        trackerTimer = setTimeout(function() {
                            if (!trackerDismissed) {
                                orderTracker.style.transform = 'translateY(100%)';
                                orderTracker.style.opacity = '0';
                                trackerDismissed = true;
                            }
                        }, 8000);
                    }
                    lastScrollY = currentScrollY;
                }, { passive: true });
            }
        }

        // ============================================
        // PRODUCT CARD CLICK HANDLING
        // ============================================
        document.querySelectorAll('.product-card').forEach(function(card) {
            var links = card.querySelectorAll('a');
            links.forEach(function(link) {
                link.addEventListener('click', function(e) {
                    // Allow the link to work normally
                });
            });
        });

        console.log('Menu JS v5.0 - Consolidated with queue panel integration');

    });
})();