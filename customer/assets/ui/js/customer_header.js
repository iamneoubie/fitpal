/**
 * FitPal Customer Header JavaScript
 * 
 * This file handles customer-specific header functionality:
 * - User dropdown toggle
 * - Cart and notification badge updates
 * - Customer-specific navigation interactions
 */

(function() {
    'use strict';

    /**
     * Initialize customer header functionality
     */
    document.addEventListener('DOMContentLoaded', function() {
        initUserDropdown();
        initBadgeUpdateListener();
        initCustomerNavInteractions();
        initSearchToggle();
    });

    /**
     * Initialize user dropdown toggle
     */
    function initUserDropdown() {
        const dropdownToggle = document.getElementById('userDropdownToggle');
        const dropdownMenu = document.querySelector('.user-dropdown-menu');

        if (!dropdownToggle || !dropdownMenu) {
            return;
        }

        let isDropdownOpen = false;

        /**
         * Open user dropdown
         */
        function openDropdown() {
            dropdownMenu.classList.add('active');
            dropdownToggle.classList.add('active');
            dropdownToggle.setAttribute('aria-expanded', 'true');
            isDropdownOpen = true;
        }

        /**
         * Close user dropdown
         */
        function closeDropdown() {
            dropdownMenu.classList.remove('active');
            dropdownToggle.classList.remove('active');
            dropdownToggle.setAttribute('aria-expanded', 'false');
            isDropdownOpen = false;
        }

        /**
         * Toggle user dropdown
         */
        function toggleDropdown() {
            if (isDropdownOpen) {
                closeDropdown();
            } else {
                openDropdown();
            }
        }

        // Toggle dropdown on button click
        dropdownToggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            toggleDropdown();
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (isDropdownOpen && 
                !dropdownToggle.contains(e.target) && 
                !dropdownMenu.contains(e.target)) {
                closeDropdown();
            }
        });

        // Close dropdown on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && isDropdownOpen) {
                closeDropdown();
                dropdownToggle.focus();
            }
        });

        // Close dropdown when clicking a link inside it
        const dropdownLinks = dropdownMenu.querySelectorAll('a');
        dropdownLinks.forEach(function(link) {
            link.addEventListener('click', function() {
                setTimeout(closeDropdown, 200);
            });
        });

        console.log('Customer user dropdown initialized');
    }

    /**
     * Initialize badge update listener
     * Listens for cart and notification updates from other scripts
     */
    function initBadgeUpdateListener() {
        // Listen for custom events to update badges
        document.addEventListener('cartUpdated', function(e) {
            const count = e.detail?.count || 0;
            updateCartBadge(count);
        });

        document.addEventListener('notificationUpdated', function(e) {
            const count = e.detail?.count || 0;
            updateNotificationBadge(count);
        });

        console.log('Badge update listeners initialized');
    }

    /**
     * Update cart badge count
     * @param {number} count - The new cart count
     */
    function updateCartBadge(count) {
        // Update desktop badge
        const cartBadge = document.getElementById('cartBadge');
        if (cartBadge) {
            if (count > 0) {
                cartBadge.textContent = count;
                cartBadge.style.display = 'flex';
            } else {
                cartBadge.style.display = 'none';
            }
        }

        // Update mobile badge
        const mobileBadge = document.querySelector('.mobile-cart-badge');
        if (mobileBadge) {
            if (count > 0) {
                mobileBadge.textContent = count;
                mobileBadge.style.display = 'flex';
            } else {
                mobileBadge.style.display = 'none';
            }
        }

        // Store in session for persistence
        if (typeof sessionStorage !== 'undefined') {
            sessionStorage.setItem('cart_count', count);
        }

        console.log('Cart badge updated to:', count);
    }

    /**
     * Update notification badge count
     * @param {number} count - The new notification count
     */
    function updateNotificationBadge(count) {
        // Update desktop badge
        const notificationBadge = document.getElementById('notificationBadge');
        if (notificationBadge) {
            if (count > 0) {
                notificationBadge.textContent = count;
                notificationBadge.style.display = 'flex';
            } else {
                notificationBadge.style.display = 'none';
            }
        }

        // Update mobile badge
        const mobileBadge = document.querySelector('.mobile-notification-badge');
        if (mobileBadge) {
            if (count > 0) {
                mobileBadge.textContent = count;
                mobileBadge.style.display = 'flex';
            } else {
                mobileBadge.style.display = 'none';
            }
        }

        // Store in session for persistence
        if (typeof sessionStorage !== 'undefined') {
            sessionStorage.setItem('notification_count', count);
        }

        console.log('Notification badge updated to:', count);
    }

    /**
     * Initialize customer navigation interactions
     */
    function initCustomerNavInteractions() {
        // Add active state to nav items on click
        const navLinks = document.querySelectorAll('.nav-link, .mobile-nav-link');
        
        navLinks.forEach(function(link) {
            link.addEventListener('click', function() {
                // Don't remove active if it's a logout or dropdown toggle
                if (this.classList.contains('logout-link') || 
                    this.classList.contains('user-dropdown-toggle')) {
                    return;
                }

                // Remove active from all links in the same container
                const container = this.closest('.nav-list') || this.closest('.mobile-nav-list');
                if (container) {
                    container.querySelectorAll('.nav-link, .mobile-nav-link').forEach(function(l) {
                        l.classList.remove('active');
                    });
                }

                // Add active to clicked link (unless it's a cart/notification link)
                if (!this.classList.contains('cart-link') && 
                    !this.classList.contains('notification-link')) {
                    this.classList.add('active');
                }
            });
        });

        console.log('Customer navigation interactions initialized');
    }

    /**
     * Initialize search toggle for mobile
     */
    function initSearchToggle() {
        const searchToggle = document.querySelector('.search-toggle');
        const searchBar = document.querySelector('.search-bar');

        if (!searchToggle || !searchBar) {
            return;
        }

        let isSearchOpen = false;

        searchToggle.addEventListener('click', function(e) {
            e.preventDefault();
            isSearchOpen = !isSearchOpen;
            
            if (isSearchOpen) {
                searchBar.classList.add('active');
                searchBar.querySelector('input')?.focus();
            } else {
                searchBar.classList.remove('active');
            }
        });

        // Close search on Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && isSearchOpen) {
                searchBar.classList.remove('active');
                isSearchOpen = false;
            }
        });

        console.log('Search toggle initialized');
    }

    /**
     * Initialize cart count from session on page load
     */
    function initCartCountFromSession() {
        if (typeof sessionStorage !== 'undefined') {
            const savedCartCount = sessionStorage.getItem('cart_count');
            if (savedCartCount !== null) {
                updateCartBadge(parseInt(savedCartCount, 10));
            }

            const savedNotificationCount = sessionStorage.getItem('notification_count');
            if (savedNotificationCount !== null) {
                updateNotificationBadge(parseInt(savedNotificationCount, 10));
            }
        }
    }

    // Load saved badge counts
    document.addEventListener('DOMContentLoaded', function() {
        initCartCountFromSession();
    });

    // Expose badge update functions globally
    window.updateCartBadge = updateCartBadge;
    window.updateNotificationBadge = updateNotificationBadge;

    console.log('Customer header.js loaded successfully');

})();