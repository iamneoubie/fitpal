/**
 * FitPal Header JavaScript
 * Handles mobile menu toggle and login dropdown
 */

(function() {
    'use strict';

    // Wait for DOM to be ready
    document.addEventListener('DOMContentLoaded', function() {
        
        // ============================================
        // MOBILE MENU TOGGLE
        // ============================================
        const menuToggle = document.getElementById('menuToggle');
        const mobileNav = document.getElementById('mobileNav');
        const mobileOverlay = document.getElementById('mobileOverlay');
        
        if (menuToggle && mobileNav) {
            // Toggle menu
            menuToggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                const isOpen = mobileNav.classList.contains('open');
                
                if (isOpen) {
                    // Close menu
                    mobileNav.classList.remove('open');
                    menuToggle.classList.remove('active');
                    menuToggle.setAttribute('aria-expanded', 'false');
                    if (mobileOverlay) {
                        mobileOverlay.classList.remove('active');
                    }
                    document.body.classList.remove('menu-open');
                } else {
                    // Open menu
                    mobileNav.classList.add('open');
                    menuToggle.classList.add('active');
                    menuToggle.setAttribute('aria-expanded', 'true');
                    if (mobileOverlay) {
                        mobileOverlay.classList.add('active');
                    }
                    document.body.classList.add('menu-open');
                }
            });
            
            // Close on overlay click
            if (mobileOverlay) {
                mobileOverlay.addEventListener('click', function() {
                    mobileNav.classList.remove('open');
                    menuToggle.classList.remove('active');
                    menuToggle.setAttribute('aria-expanded', 'false');
                    mobileOverlay.classList.remove('active');
                    document.body.classList.remove('menu-open');
                });
            }
            
            // Close on escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && mobileNav.classList.contains('open')) {
                    mobileNav.classList.remove('open');
                    menuToggle.classList.remove('active');
                    menuToggle.setAttribute('aria-expanded', 'false');
                    if (mobileOverlay) {
                        mobileOverlay.classList.remove('active');
                    }
                    document.body.classList.remove('menu-open');
                    menuToggle.focus();
                }
            });
            
            // Close on window resize (desktop)
            window.addEventListener('resize', function() {
                if (window.innerWidth > 992 && mobileNav.classList.contains('open')) {
                    mobileNav.classList.remove('open');
                    menuToggle.classList.remove('active');
                    menuToggle.setAttribute('aria-expanded', 'false');
                    if (mobileOverlay) {
                        mobileOverlay.classList.remove('active');
                    }
                    document.body.classList.remove('menu-open');
                }
            });
            
            // Close mobile menu when a link is clicked
            mobileNav.querySelectorAll('a').forEach(function(link) {
                link.addEventListener('click', function() {
                    setTimeout(function() {
                        mobileNav.classList.remove('open');
                        menuToggle.classList.remove('active');
                        menuToggle.setAttribute('aria-expanded', 'false');
                        if (mobileOverlay) {
                            mobileOverlay.classList.remove('active');
                        }
                        document.body.classList.remove('menu-open');
                    }, 300);
                });
            });
        }
        
        // ============================================
        // LOGIN DROPDOWN - SIMPLE & RELIABLE
        // ============================================
        const loginBtn = document.getElementById('loginDropdown');
        const dropdownMenu = document.getElementById('dropdownMenu');
        
        if (loginBtn && dropdownMenu) {
            // Toggle dropdown on button click
            loginBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                const isOpen = dropdownMenu.classList.contains('active');
                
                if (isOpen) {
                    dropdownMenu.classList.remove('active');
                    loginBtn.setAttribute('aria-expanded', 'false');
                } else {
                    dropdownMenu.classList.add('active');
                    loginBtn.setAttribute('aria-expanded', 'true');
                }
            });
            
            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!loginBtn.contains(e.target) && !dropdownMenu.contains(e.target)) {
                    dropdownMenu.classList.remove('active');
                    loginBtn.setAttribute('aria-expanded', 'false');
                }
            });
            
            // Close dropdown on escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && dropdownMenu.classList.contains('active')) {
                    dropdownMenu.classList.remove('active');
                    loginBtn.setAttribute('aria-expanded', 'false');
                    loginBtn.focus();
                }
            });
            
            // Close dropdown when a link is clicked
            dropdownMenu.querySelectorAll('a').forEach(function(link) {
                link.addEventListener('click', function() {
                    setTimeout(function() {
                        dropdownMenu.classList.remove('active');
                        loginBtn.setAttribute('aria-expanded', 'false');
                    }, 150);
                });
            });
        }
        
        // ============================================
        // STICKY HEADER
        // ============================================
        const header = document.querySelector('.header');
        if (header) {
            let lastScroll = 0;
            
            window.addEventListener('scroll', function() {
                const currentScroll = window.pageYOffset || document.documentElement.scrollTop;
                
                if (currentScroll > 10) {
                    header.classList.add('header-scrolled');
                } else {
                    header.classList.remove('header-scrolled');
                }
                
                lastScroll = currentScroll;
            }, { passive: true });
        }
        
        // ============================================
        // ACTIVE LINK HIGHLIGHTING
        // ============================================
        const currentPath = window.location.pathname;
        const currentPage = currentPath.split('/').pop() || 'index.php';
        
        document.querySelectorAll('.nav-link, .mobile-nav-link').forEach(function(link) {
            const href = link.getAttribute('href');
            if (!href) return;
            
            const hrefFile = href.split('/').pop() || '';
            
            // Check if this link matches current page
            if (hrefFile === currentPage || 
                (currentPage === 'index.php' && hrefFile === '') ||
                (currentPage === '' && hrefFile === 'index.php')) {
                link.classList.add('active');
            }
        });
        
        console.log('Header initialized successfully');
    });
})();