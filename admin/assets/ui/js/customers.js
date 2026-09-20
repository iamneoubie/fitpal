/**
 * FitPal Admin — Customers Page
 *
 * The customer detail modal is URL-driven (?open=ID). This file
 * owns four behaviors:
 *
 *   1. Backdrop click closes the modal by navigating to the URL in
 *      data-close-url on the backdrop.
 *
 *   2. Empty-state icon fallback. If the search-line icon inside
 *      .admin-table-empty-icon fails to load, the wrapper's
 *      data-fallback-src is swapped in.
 *
 *   3. Escape key closes the modal by navigating to the same
 *      data-close-url.
 *
 *   4. Tab switching inside the detail modal. Tabs carry
 *      data-tab-target and their panels carry a matching id.
 *
 * @package FitPal
 * @version 2.1 — Added modal tab switching, which was previously
 *                only present in dashboard.js and therefore never
 *                registered on this page.
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ============================================
        // BACKDROP CLICK — NAVIGATE TO data-close-url
        // ============================================
        document.addEventListener('click', function (e) {
            var backdrop = e.target.closest('.admin-modal-backdrop[data-close-url]');
            if (!backdrop) return;

            var url = backdrop.getAttribute('data-close-url');
            if (!url) return;

            window.location.href = url;
        }, true);

        // ============================================
        // IMAGE ERROR — SWAP IN data-fallback-src
        // ============================================
        document.addEventListener('error', function (e) {
            var img = e.target;
            if (!img || img.tagName !== 'IMG') return;

            var wrapper = img.closest('.admin-table-empty-icon');
            if (!wrapper) return;

            if (img.dataset.fallbackApplied === '1') return;

            var fallback = wrapper.getAttribute('data-fallback-src');
            if (!fallback) return;

            img.dataset.fallbackApplied = '1';
            img.src = fallback;
        }, true);

        // ============================================
        // ESCAPE KEY — NAVIGATE TO data-close-url
        // ============================================
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;

            var openModal = document.querySelector('.admin-modal.is-open');
            if (!openModal) return;

            var backdrop = openModal.querySelector('.admin-modal-backdrop[data-close-url]');
            if (backdrop) {
                var url = backdrop.getAttribute('data-close-url');
                if (url) {
                    window.location.href = url;
                    return;
                }
            }

            var closeLink = openModal.querySelector('.admin-modal-close');
            if (closeLink) {
                closeLink.click();
            }
        });

        // ============================================
        // TAB SWITCHING INSIDE THE DETAIL MODAL
        // ============================================
        document.addEventListener('click', function (e) {
            var tab = e.target.closest('.admin-modal-tab');
            if (!tab) return;
            e.preventDefault();

            var tabsContainer = tab.closest('.admin-modal-tabs');
            if (!tabsContainer) return;

            var panelGroup = tabsContainer.parentElement;
            var targetId = tab.getAttribute('data-tab-target');
            if (!targetId) return;

            tabsContainer.querySelectorAll('.admin-modal-tab').forEach(function (t) {
                t.classList.toggle('active', t === tab);
            });

            panelGroup.querySelectorAll('.admin-modal-tab-panel').forEach(function (p) {
                p.classList.toggle('active', p.id === targetId);
            });
        });
    });
})();