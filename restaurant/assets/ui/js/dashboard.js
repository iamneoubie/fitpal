/**
 * FitPal Restaurant Dashboard JavaScript
 *
 * Owns the interactive surface of restaurant/pages/dashboard.php:
 *
 *   1. Period combobox.
 *        - Button toggles a popover menu of three options.
 *        - Choosing an option updates the button label and
 *          navigates to ?period=<value>, so the selection survives
 *          a reload and the URL is shareable.
 *        - Closes on outside click, on Escape, on option select,
 *          and on focus moving outside the combobox.
 *
 *   2. Tab switching between the four analytics panels.
 *        - Click activates the matching panel and sets the
 *          corresponding tab.
 *        - Left / Right arrows on a focused tab move the selection
 *          (WAI-ARIA tab pattern).
 *        - The active tab is persisted in the URL hash so a reload
 *          returns to the same tab.
 *
 * ---------------------------------------------------------------------
 * WHAT THIS FILE DOES NOT OWN
 * ---------------------------------------------------------------------
 *   - The bar chart, weekday chart, or category share bars. Their
 *     heights and widths are rendered server-side as inline style
 *     attributes because the values come from PHP. This file never
 *     touches them.
 *   - The re-apply banner. It is a plain anchor.
 *   - The stat cards. They are plain markup.
 *
 * ---------------------------------------------------------------------
 * DOM CONTRACT — PERIOD COMBOBOX
 * ---------------------------------------------------------------------
 *   #periodCombo                    wrapper, carries data-active
 *     #periodComboButton            the button
 *       #periodComboLabel           the visible label
 *     #periodComboMenu              the <ul> popover, hidden by default
 *       .period-combo-option        one <button> per option
 *         data-value                the period token
 *         data-label                the human label
 *         .is-selected              on the active option
 *
 * The <form> that previously wrapped the native <select> is gone;
 * this combobox is not inside a form. Navigation happens through
 * window.location.assign().
 *
 * ---------------------------------------------------------------------
 * DOM CONTRACT — TABS
 * ---------------------------------------------------------------------
 *   .restaurant-tab                 four tab buttons
 *     data-tab="revenue|trending|days|categories"
 *     aria-selected="true|false"
 *
 *   .restaurant-tab-panel           four panels
 *     data-panel="revenue|trending|days|categories"
 *     .active on exactly one
 *     hidden attribute set on the three inactive panels
 *
 * The `revenue` tab and its panel are `.active` in the initial
 * markup. That is the default when no hash is present.
 *
 * ---------------------------------------------------------------------
 * HASH CONTRACT
 * ---------------------------------------------------------------------
 * The hash is `#revenue`, `#trending`, `#days`, or `#categories`.
 *
 *   On load        If the hash names a valid tab, that tab is
 *                  activated before first paint. The script runs
 *                  with `defer`, after the DOM is parsed but
 *                  before `load`.
 *
 *   On tab click   The panel is shown and the hash is written with
 *                  history.replaceState() so the browser does not
 *                  add a history entry per tab.
 *
 *   On hashchange  A manual hash edit or a link with a different
 *                  hash switches the tab. An unknown hash is
 *                  ignored.
 *
 * ---------------------------------------------------------------------
 * BOOTSTRAP
 * ---------------------------------------------------------------------
 * The script is loaded with `defer`, so a DOMContentLoaded
 * listener attached here would never fire. The readyState guard
 * below is correct whether the script is deferred, async, or
 * plain.
 *
 * @package FitPal
 * @version 3.0 — Owns the period combobox in addition to the tab
 *                switching. The page no longer carries an inline
 *                <script> block, so the file-separation matrix is
 *                honored end to end.
 *
 *                (2.0: tab switching + hash. 1.0: placeholder.)
 */

(function () {
    'use strict';

    var DEFAULT_TAB = 'revenue';
    var VALID_TABS  = ['revenue', 'trending', 'days', 'categories'];

    var VALID_PERIODS = ['7d', '30d', '12m'];

    function isValidTab(value) {
        return VALID_TABS.indexOf(value) !== -1;
    }

    function isValidPeriod(value) {
        return VALID_PERIODS.indexOf(value) !== -1;
    }

    /* ============================================================
       PERIOD COMBOBOX
       ============================================================ */

    function initPeriodCombo() {
        var combo = document.getElementById('periodCombo');
        var button = document.getElementById('periodComboButton');
        var menu = document.getElementById('periodComboMenu');

        if (!combo || !button || !menu) {
            return;
        }

        var options = menu.querySelectorAll('.period-combo-option');
        if (!options.length) {
            return;
        }

        var isOpen = false;

        function openMenu() {
            if (isOpen) return;
            isOpen = true;

            menu.hidden = false;
            combo.classList.add('is-open');
            button.setAttribute('aria-expanded', 'true');

            document.addEventListener('mousedown', onDocumentMouseDown);
            document.addEventListener('keydown', onDocumentKeyDown, true);
            window.addEventListener('resize', closeMenu);
            window.addEventListener('scroll', closeMenu, { passive: true });
        }

        function closeMenu() {
            if (!isOpen) return;
            isOpen = false;

            menu.hidden = true;
            combo.classList.remove('is-open');
            button.setAttribute('aria-expanded', 'false');

            document.removeEventListener('mousedown', onDocumentMouseDown);
            document.removeEventListener('keydown', onDocumentKeyDown, true);
            window.removeEventListener('resize', closeMenu);
            window.removeEventListener('scroll', closeMenu);
        }

        function onDocumentMouseDown(event) {
            if (combo.contains(event.target)) {
                return;
            }
            closeMenu();
        }

        function onDocumentKeyDown(event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeMenu();
                button.focus();
            }
        }

        function chooseOption(option) {
            var value = option.dataset.value || '';
            var label = option.dataset.label || '';

            if (!isValidPeriod(value)) {
                closeMenu();
                return;
            }

            // Optimistic UI: update the visible label and the
            // selected state before navigation, so the click feels
            // instant even on a slow connection.
            var labelEl = document.getElementById('periodComboLabel');
            if (labelEl && label !== '') {
                labelEl.textContent = label;
            }

            options.forEach(function (opt) {
                var isSelected = (opt === option);
                opt.classList.toggle('is-selected', isSelected);
                opt.setAttribute('aria-selected', isSelected ? 'true' : 'false');
            });

            closeMenu();

            // Navigate to the new period. The page reloads with
            // the new token in the URL, so the selection survives
            // a refresh and is shareable.
            var baseUrl = window.location.pathname;
            var url = baseUrl + '?period=' + encodeURIComponent(value);

            window.location.assign(url);
        }

        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            if (isOpen) {
                closeMenu();
            } else {
                openMenu();
            }
        });

        options.forEach(function (option) {
            option.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                chooseOption(option);
            });
        });

        // Keyboard navigation inside the open menu. Down / Up
        // move between options, Enter chooses the highlighted one,
        // Escape closes.
        menu.addEventListener('keydown', function (event) {
            var key = event.key;

            if (key !== 'ArrowDown' && key !== 'ArrowUp' && key !== 'Enter') {
                return;
            }

            var optionList = Array.prototype.slice.call(options);
            var current = optionList.indexOf(document.activeElement);

            if (key === 'ArrowDown') {
                event.preventDefault();
                var next = current < 0 ? 0 : (current + 1) % optionList.length;
                optionList[next].focus();
                return;
            }

            if (key === 'ArrowUp') {
                event.preventDefault();
                var prev = current <= 0 ? optionList.length - 1 : current - 1;
                optionList[prev].focus();
                return;
            }

            if (key === 'Enter' && current >= 0) {
                event.preventDefault();
                chooseOption(optionList[current]);
            }
        });

        // When the button opens the menu, focus the currently
        // selected option so the keyboard user lands somewhere
        // useful.
        button.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
                if (!isOpen) {
                    event.preventDefault();
                    openMenu();
                }
                var selected = menu.querySelector('.period-combo-option.is-selected');
                if (selected) {
                    selected.focus();
                }
            }
        });
    }

    /* ============================================================
       TAB SWITCHING
       ============================================================ */

    function initTabs() {
        var tabs = document.querySelectorAll('.restaurant-tab');
        var panels = document.querySelectorAll('.restaurant-tab-panel');

        if (!tabs.length || !panels.length) {
            return;
        }

        function activate(key) {
            if (!isValidTab(key)) {
                return;
            }

            tabs.forEach(function (tab) {
                var isActive = (tab.dataset.tab === key);
                tab.classList.toggle('active', isActive);
                tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });

            panels.forEach(function (panel) {
                var matches = (panel.dataset.panel === key);
                panel.classList.toggle('active', matches);
                if (matches) {
                    panel.removeAttribute('hidden');
                } else {
                    panel.setAttribute('hidden', '');
                }
            });
        }

        function readHash() {
            var raw = (window.location.hash || '').replace(/^#/, '');
            return isValidTab(raw) ? raw : '';
        }

        function writeHash(key) {
            if (!isValidTab(key)) {
                return;
            }

            var newHash = '#' + key;

            if (window.location.hash === newHash) {
                return;
            }

            if (window.history && typeof window.history.replaceState === 'function') {
                window.history.replaceState(null, '', newHash);
            } else {
                window.location.hash = newHash;
            }
        }

        // ---- Initial state ----
        var initial = readHash();
        if (initial !== '' && initial !== DEFAULT_TAB) {
            activate(initial);
        }

        // ---- Click binding ----
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var key = tab.dataset.tab || DEFAULT_TAB;
                activate(key);
                writeHash(key);
            });
        });

        // ---- Keyboard arrow navigation ----
        tabs.forEach(function (tab) {
            tab.addEventListener('keydown', function (event) {
                var key = event.key;
                if (key !== 'ArrowLeft' && key !== 'ArrowRight') {
                    return;
                }

                var tabList = Array.prototype.slice.call(tabs);
                var index = tabList.indexOf(tab);
                if (index === -1) {
                    return;
                }

                var delta = (key === 'ArrowLeft') ? -1 : 1;
                var next  = (index + delta + tabList.length) % tabList.length;
                var target = tabList[next];

                event.preventDefault();
                target.focus();

                var targetKey = target.dataset.tab || DEFAULT_TAB;
                activate(targetKey);
                writeHash(targetKey);
            });
        });

        // ---- hashchange ----
        window.addEventListener('hashchange', function () {
            var key = readHash();
            if (key !== '') {
                activate(key);
            }
        });
    }

    /* ============================================================
       BOOTSTRAP
       ============================================================ */

    function boot() {
        initPeriodCombo();
        initTabs();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();