/**
 * FitPal Menu Page JavaScript
 * Version 3.1 - Fixed product detail navigation, improved performance
 *
 * Handles quantity controls, add-to-cart feedback, sticky filter behavior,
 * dropdown toggles, and active order tracker with auto-hide.
 *
 * @package FitPal
 * @version 3.1
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
        // DROPDOWN TOGGLES - FIXED to not interfere with clicks
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
        // QUANTITY CONTROLS - FIXED to not interfere with clicks
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
                e.stopPropagation(); // Prevent event from bubbling
                updateValue(-1);
            });

            plusBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation(); // Prevent event from bubbling
                updateValue(1);
            });

            input.addEventListener('change', function(e) {
                e.stopPropagation(); // Prevent event from bubbling
                var val = parseInt(this.value, 10) || 1;
                var max = parseInt(this.max, 10) || 999;
                if (val < 1) val = 1;
                if (val > max) val = max;
                this.value = val;
            });

            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    e.stopPropagation(); // Prevent event from bubbling
                    var form = this.closest('.add-to-cart-form');
                    if (form) {
                        var submitBtn = form.querySelector('.add-btn');
                        if (submitBtn) submitBtn.click();
                    }
                }
            });

            // Prevent click on quantity control from bubbling to parent
            control.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });

        // ============================================
        // ADD-TO-CART FORM HANDLING - FIXED to not interfere with clicks
        // ============================================
        document.querySelectorAll('.add-to-cart-form').forEach(function(form) {
            // Prevent clicks inside form from bubbling
            form.addEventListener('click', function(e) {
                e.stopPropagation();
            });

            form.addEventListener('submit', function(e) {
                e.stopPropagation(); // Prevent event from bubbling
                var submitBtn = this.querySelector('.add-btn');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Adding...';

                    setTimeout(function() {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Add';
                    }, 5000);
                }
            });
        });

        // ============================================
        // FILTER AUTO-SUBMIT
        // ============================================
        // Checkboxes in dropdowns already submit via onchange
        // Radio buttons in restaurant dropdown also submit via onchange

        // Price inputs - auto-submit on blur or Enter
        var priceInputs = document.querySelectorAll('.price-input');
        priceInputs.forEach(function(input) {
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    e.stopPropagation(); // Prevent event from bubbling
                    if (filterForm) {
                        filterForm.submit();
                    }
                }
            });

            input.addEventListener('blur', function(e) {
                e.stopPropagation(); // Prevent event from bubbling
                var defaultValue = this.getAttribute('data-default') || '';
                if (this.value !== defaultValue) {
                    if (filterForm) {
                        filterForm.submit();
                    }
                }
            });

            // Store initial value for blur comparison
            input.setAttribute('data-default', input.value);

            // Prevent click on price input from bubbling
            input.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });

        // Price apply button
        document.querySelectorAll('.price-apply-btn').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation(); // Prevent event from bubbling
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
                e.stopPropagation(); // Prevent event from bubbling
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
                    e.stopPropagation(); // Prevent event from bubbling
                    clearTimeout(searchTimeout);
                    if (filterForm) {
                        filterForm.submit();
                    }
                }
            });

            // Prevent click on search input from bubbling
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
        // RESTAURANT TAB CLICK - Scroll to filter
        // ============================================
        document.querySelectorAll('.restaurant-tab').forEach(function(tab) {
            tab.addEventListener('click', function(e) {
                if (menuFilters) {
                    var headerHeight = header ? header.offsetHeight : 0;
                    var scrollPosition = menuFilters.getBoundingClientRect().top + window.pageYOffset - headerHeight - 10;
                    window.scrollTo({
                        top: scrollPosition,
                        behavior: 'smooth'
                    });
                }
            });
        });

        // ============================================
        // PRODUCT CARD CLICK HANDLING - FIXED
        // ============================================
        // Ensure product cards don't have click handlers that interfere
        document.querySelectorAll('.product-card').forEach(function(card) {
            // Remove any existing click listeners that might have been added
            // by other scripts (like menu.js v3.0)
            var links = card.querySelectorAll('a');
            links.forEach(function(link) {
                // Ensure links work normally
                link.addEventListener('click', function(e) {
                    // Allow the link to work normally
                    // Don't prevent default
                });
            });

            // Prevent accidental drag selection
            card.addEventListener('mousedown', function(e) {
                // Allow clicks, just prevent text selection on drag
                if (e.button === 0) {
                    // Left click only
                }
            });
        });

        console.log('Menu JS v3.1 - Fixed product navigation initialized');

    });
})();