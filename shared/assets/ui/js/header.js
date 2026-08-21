/**
 * FitPal Header JavaScript
 * 
 * This file handles all interactive functionality for the header component:
 * - Mobile menu toggle
 * - Login dropdown
 * - Active link highlighting
 * - Sticky header behavior
 * - Responsive adjustments
 */

(function() {
    'use strict';

    /**
     * Initialize all header functionality when DOM is ready
     */
    document.addEventListener('DOMContentLoaded', function() {
        initMobileMenu();
        initLoginDropdown();
        initStickyHeader();
        highlightActiveLink();
        handleResponsiveAdjustments();
    });

    /**
     * Initialize mobile menu toggle functionality
     */
    function initMobileMenu() {
        const menuToggle = document.getElementById('menuToggle');
        const mobileNav = document.getElementById('mobileNav');
        const mobileOverlay = document.getElementById('mobileOverlay');

        if (!menuToggle || !mobileNav) {
            return;
        }

        /**
         * Toggle mobile menu open/closed state
         */
        function toggleMobileMenu() {
            const isOpen = mobileNav.classList.contains('open');
            
            if (isOpen) {
                closeMobileMenu();
            } else {
                openMobileMenu();
            }
        }

        /**
         * Open mobile menu
         */
        function openMobileMenu() {
            mobileNav.classList.add('open');
            menuToggle.classList.add('active');
            menuToggle.setAttribute('aria-expanded', 'true');
            
            if (mobileOverlay) {
                mobileOverlay.classList.add('active');
            }
            
            document.body.style.overflow = 'hidden';
        }

        /**
         * Close mobile menu
         */
        function closeMobileMenu() {
            mobileNav.classList.remove('open');
            menuToggle.classList.remove('active');
            menuToggle.setAttribute('aria-expanded', 'false');
            
            if (mobileOverlay) {
                mobileOverlay.classList.remove('active');
            }
            
            document.body.style.overflow = '';
        }

        // Toggle menu on button click
        menuToggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            toggleMobileMenu();
        });

        // Close menu when clicking overlay
        if (mobileOverlay) {
            mobileOverlay.addEventListener('click', function(e) {
                e.preventDefault();
                closeMobileMenu();
            });
        }

        // Close menu on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && mobileNav.classList.contains('open')) {
                closeMobileMenu();
            }
        });

        // Close menu when clicking a link inside mobile nav
        const mobileLinks = mobileNav.querySelectorAll('a');
        mobileLinks.forEach(function(link) {
            link.addEventListener('click', function() {
                // Don't close if it's a logout link (it has its own confirm dialog)
                if (!this.classList.contains('mobile-logout')) {
                    setTimeout(closeMobileMenu, 300);
                }
            });
        });

        // Close menu on window resize (if screen becomes large)
        window.addEventListener('resize', function() {
            if (window.innerWidth > 992 && mobileNav.classList.contains('open')) {
                closeMobileMenu();
            }
        });

        // Close menu on route change (for SPA-like behavior)
        window.addEventListener('beforeunload', function() {
            if (mobileNav.classList.contains('open')) {
                closeMobileMenu();
            }
        });

        // Log initialization
        console.log('Mobile menu initialized');
    }

    /**
     * Initialize login dropdown functionality
     */
    function initLoginDropdown() {
        const dropdownToggle = document.getElementById('loginDropdown');
        const dropdownMenu = document.querySelector('.dropdown-menu');

        if (!dropdownToggle || !dropdownMenu) {
            return;
        }

        let isDropdownOpen = false;

        /**
         * Open dropdown
         */
        function openDropdown() {
            dropdownMenu.classList.add('active');
            dropdownToggle.setAttribute('aria-expanded', 'true');
            isDropdownOpen = true;
        }

        /**
         * Close dropdown
         */
        function closeDropdown() {
            dropdownMenu.classList.remove('active');
            dropdownToggle.setAttribute('aria-expanded', 'false');
            isDropdownOpen = false;
        }

        /**
         * Toggle dropdown
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
            if (isDropdownOpen && !dropdownToggle.contains(e.target) && !dropdownMenu.contains(e.target)) {
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

        // Log initialization
        console.log('Login dropdown initialized');
    }

    /**
     * Initialize sticky header behavior
     * Adds a shadow effect when scrolling past the header
     */
    function initStickyHeader() {
        const header = document.querySelector('.header');
        
        if (!header) {
            return;
        }

        let lastScrollY = 0;
        let isSticky = false;

        /**
         * Handle scroll events for sticky header
         */
        function handleScroll() {
            const currentScrollY = window.scrollY;
            
            // Add shadow when scrolled past header height
            if (currentScrollY > 10) {
                if (!isSticky) {
                    header.classList.add('header-scrolled');
                    isSticky = true;
                }
            } else {
                if (isSticky) {
                    header.classList.remove('header-scrolled');
                    isSticky = false;
                }
            }
            
            lastScrollY = currentScrollY;
        }

        // Use requestAnimationFrame for smooth performance
        let ticking = false;
        window.addEventListener('scroll', function() {
            if (!ticking) {
                window.requestAnimationFrame(function() {
                    handleScroll();
                    ticking = false;
                });
                ticking = true;
            }
        });

        // Also handle on load (in case page loads scrolled down)
        window.addEventListener('load', function() {
            handleScroll();
        });

        // Log initialization
        console.log('Sticky header initialized');
    }

    /**
     * Highlight the active navigation link based on current page
     */
    function highlightActiveLink() {
        const navLinks = document.querySelectorAll('.nav-link, .mobile-nav-link');
        const currentPath = window.location.pathname;
        const currentPage = currentPath.split('/').pop() || 'index.php';

        if (!navLinks.length) {
            return;
        }

        // Remove all active classes first
        navLinks.forEach(function(link) {
            link.classList.remove('active');
        });

        // Find and highlight matching links
        navLinks.forEach(function(link) {
            const href = link.getAttribute('href');
            if (!href) {
                return;
            }

            // Get the filename from href
            const hrefParts = href.split('/');
            const hrefFile = hrefParts[hrefParts.length - 1] || '';

            // Check if the href matches the current page
            if (hrefFile === currentPage) {
                link.classList.add('active');
            }

            // Special case for index.php (home)
            if (currentPage === '' || currentPage === 'index.php') {
                if (hrefFile === 'index.php' || hrefFile === '') {
                    link.classList.add('active');
                }
            }

            // Check if the current path contains the href (for nested pages)
            if (currentPath.includes(hrefFile) && hrefFile !== '') {
                // Don't override exact matches
                if (!link.classList.contains('active')) {
                    link.classList.add('active');
                }
            }
        });

        // Log initialization
        console.log('Active link highlighting initialized');
    }

    /**
     * Handle responsive adjustments and utility functions
     */
    function handleResponsiveAdjustments() {
        // Fix for iOS Safari viewport height (100vh issue)
        function setVH() {
            let vh = window.innerHeight * 0.01;
            document.documentElement.style.setProperty('--vh', vh + 'px');
        }

        setVH();
        window.addEventListener('resize', setVH);
        window.addEventListener('orientationchange', function() {
            setTimeout(setVH, 300);
        });

        // Handle touch events for better mobile interaction
        handleTouchInteractions();

        // Log initialization
        console.log('Responsive adjustments initialized');
    }

    /**
     * Handle touch interactions for better mobile experience
     */
    function handleTouchInteractions() {
        // Add touch feedback to buttons
        const interactiveElements = document.querySelectorAll(
            'button, .btn, .nav-link, .mobile-nav-link, .dropdown-toggle'
        );

        interactiveElements.forEach(function(el) {
            el.addEventListener('touchstart', function() {
                // Small visual feedback for touch
                this.style.opacity = '0.7';
            }, { passive: true });

            el.addEventListener('touchend', function() {
                this.style.opacity = '1';
            }, { passive: true });

            el.addEventListener('touchcancel', function() {
                this.style.opacity = '1';
            }, { passive: true });
        });

        // Prevent zoom on double tap for mobile
        let lastTouchEnd = 0;
        document.addEventListener('touchend', function(e) {
            const now = Date.now();
            if (now - lastTouchEnd <= 300) {
                e.preventDefault();
            }
            lastTouchEnd = now;
        }, { passive: false });
    }

    /**
     * Utility: Close all dropdowns and menus
     * Exposed globally for use from other scripts
     */
    window.closeAllHeaderMenus = function() {
        const mobileNav = document.getElementById('mobileNav');
        const menuToggle = document.getElementById('menuToggle');
        const dropdownMenu = document.querySelector('.dropdown-menu');
        const dropdownToggle = document.getElementById('loginDropdown');

        // Close mobile menu
        if (mobileNav && mobileNav.classList.contains('open')) {
            mobileNav.classList.remove('open');
            if (menuToggle) {
                menuToggle.classList.remove('active');
                menuToggle.setAttribute('aria-expanded', 'false');
            }
            document.body.style.overflow = '';
        }

        // Close dropdown
        if (dropdownMenu && dropdownMenu.classList.contains('active')) {
            dropdownMenu.classList.remove('active');
            if (dropdownToggle) {
                dropdownToggle.setAttribute('aria-expanded', 'false');
            }
        }

        // Remove overlay
        const mobileOverlay = document.getElementById('mobileOverlay');
        if (mobileOverlay) {
            mobileOverlay.classList.remove('active');
        }
    };

    /**
     * Utility: Refresh header state
     * Useful after dynamic content changes (e.g., login/logout via AJAX)
     */
    window.refreshHeader = function() {
        highlightActiveLink();
        // Any other refresh logic
        console.log('Header refreshed');
    };

    // Log that the header JS is fully loaded
    console.log('Header.js loaded successfully');

})();