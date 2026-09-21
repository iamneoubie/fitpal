/**
 * FitPal Header – Direct class toggling, no input locks
 * All animations via CSS; minimal JS overhead.
 */
(function() {
    'use strict';

    const menuToggle = document.getElementById('menuToggle');
    const mobileNav = document.getElementById('mobileNav');
    const mobileOverlay = document.getElementById('mobileOverlay');
    const loginBtn = document.getElementById('loginDropdown');
    const dropdownMenu = document.getElementById('dropdownMenu');
    const header = document.querySelector('.header');

    let isMenuOpen = false;
    let isDropdownOpen = false;

    // ─── Menu Toggle ───
    function toggleMenu(e) {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
        isMenuOpen = !isMenuOpen;

        // Toggle classes directly on elements
        mobileNav.classList.toggle('open', isMenuOpen);
        menuToggle.classList.toggle('active', isMenuOpen);
        menuToggle.setAttribute('aria-expanded', String(isMenuOpen));
        if (mobileOverlay) {
            mobileOverlay.classList.toggle('active', isMenuOpen);
        }
        // Optional body class if you want to lock scroll – but we avoid it for performance
        // document.body.classList.toggle('menu-open', isMenuOpen);
    }

    // ─── Dropdown Toggle ───
    function toggleDropdown(e) {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
        isDropdownOpen = !isDropdownOpen;
        dropdownMenu.classList.toggle('active', isDropdownOpen);
        loginBtn.setAttribute('aria-expanded', String(isDropdownOpen));
    }

    // ─── Close dropdown on outside click ───
    function handleOutsideClick(e) {
        if (isDropdownOpen &&
            !loginBtn.contains(e.target) &&
            !dropdownMenu.contains(e.target)) {
            isDropdownOpen = false;
            dropdownMenu.classList.remove('active');
            loginBtn.setAttribute('aria-expanded', 'false');
        }
    }

    // ─── Sticky header (rAF-throttled) ───
    function handleScroll() {
        header.classList.toggle('header-scrolled', window.pageYOffset > 10);
    }

    // ─── Initialise ───

    if (menuToggle && mobileNav) {
        menuToggle.addEventListener('click', toggleMenu);

        if (mobileOverlay) {
            mobileOverlay.addEventListener('click', () => {
                if (isMenuOpen) toggleMenu();
            });
        }

        // Close menu on link click inside nav
        mobileNav.addEventListener('click', (e) => {
            if (e.target.closest('a') && isMenuOpen) {
                toggleMenu();
            }
        });

        // Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && isMenuOpen) {
                toggleMenu();
                menuToggle.focus();
            }
        });

        // Close on resize to desktop (debounced)
        let resizeTimer;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => {
                if (window.innerWidth > 992 && isMenuOpen) {
                    toggleMenu();
                }
            }, 100);
        });
    }

    if (loginBtn && dropdownMenu) {
        loginBtn.addEventListener('click', toggleDropdown);
        document.addEventListener('click', handleOutsideClick);

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && isDropdownOpen) {
                isDropdownOpen = false;
                dropdownMenu.classList.remove('active');
                loginBtn.setAttribute('aria-expanded', 'false');
                loginBtn.focus();
            }
        });
    }

    // Sticky header
    if (header) {
        let ticking = false;
        window.addEventListener('scroll', () => {
            if (!ticking) {
                requestAnimationFrame(() => {
                    handleScroll();
                    ticking = false;
                });
                ticking = true;
            }
        }, { passive: true });
    }

    // Active link highlight
    (function highlightActive() {
        const currentPage = window.location.pathname.split('/').pop() || 'index.php';
        document.querySelectorAll('.nav-link, .mobile-nav-link').forEach((link) => {
            const href = link.getAttribute('href');
            if (!href) return;
            const hrefFile = href.split('/').pop() || '';
            if (hrefFile === currentPage ||
                (currentPage === 'index.php' && hrefFile === '') ||
                (currentPage === '' && hrefFile === 'index.php')) {
                link.classList.add('active');
            }
        });
    })();

    console.log('Header initialized – direct toggling, no locks');
})();