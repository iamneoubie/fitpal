/**
 * FitPal Customer Header JavaScript
 * 
 * Direct class toggling, no input locks.
 * All animations via CSS; minimal JS overhead.
 */
(function() {
    'use strict';

    var menuToggle = document.getElementById('menuToggle');
    var mobileNav = document.getElementById('mobileNav');
    var mobileOverlay = document.getElementById('mobileOverlay');
    var header = document.querySelector('.customer-header');

    var isMenuOpen = false;

    // ─── Menu Toggle ───
    function toggleMenu(e) {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
        isMenuOpen = !isMenuOpen;

        mobileNav.classList.toggle('open', isMenuOpen);
        menuToggle.classList.toggle('active', isMenuOpen);
        menuToggle.setAttribute('aria-expanded', String(isMenuOpen));
        if (mobileOverlay) {
            mobileOverlay.classList.toggle('active', isMenuOpen);
        }
    }

    // ─── Sticky header ───
    function handleScroll() {
        if (header) {
            header.classList.toggle('header-scrolled', window.pageYOffset > 10);
        }
    }

    // ─── Initialise ───

    if (menuToggle && mobileNav) {
        menuToggle.addEventListener('click', toggleMenu);

        if (mobileOverlay) {
            mobileOverlay.addEventListener('click', function() {
                if (isMenuOpen) toggleMenu();
            });
        }

        mobileNav.addEventListener('click', function(e) {
            if (e.target.closest('a') && isMenuOpen) {
                toggleMenu();
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && isMenuOpen) {
                toggleMenu();
                menuToggle.focus();
            }
        });

        var resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                if (window.innerWidth > 992 && isMenuOpen) {
                    toggleMenu();
                }
            }, 100);
        });
    }

    // ─── Sticky header ───
    if (header) {
        var ticking = false;
        window.addEventListener('scroll', function() {
            if (!ticking) {
                requestAnimationFrame(function() {
                    handleScroll();
                    ticking = false;
                });
                ticking = true;
            }
        }, { passive: true });
    }

    // ─── Active link highlight ───
    (function highlightActive() {
        var currentPage = window.location.pathname.split('/').pop() || 'index.php';
        var links = document.querySelectorAll('.nav-link, .mobile-nav-link');
        for (var i = 0; i < links.length; i++) {
            var link = links[i];
            var href = link.getAttribute('href');
            if (!href) continue;
            var hrefFile = href.split('/').pop() || '';
            if (hrefFile === currentPage ||
                (currentPage === 'index.php' && hrefFile === '') ||
                (currentPage === '' && hrefFile === 'index.php')) {
                link.classList.add('active');
            }
        }
    })();

})();