/**
 * FitPal Admin Restaurants Page
 *
 * Responsibilities owned by this file:
 *
 *   1. Backdrop click closes the detail modal by navigating to the
 *      URL in data-close-url on the backdrop.
 *
 *   2. Empty-state icon fallback: if the search-line icon inside
 *      .admin-table-empty-icon fails to load, the wrapper's
 *      data-fallback-src is swapped in.
 *
 *   3. Escape key closes the modal by navigating to the same
 *      data-close-url.
 *
 *   4. Tab switching inside the detail modal (Info / Branches /
 *      Accounts).
 *
 * All mutations (approve / deny / toggle) are plain HTML form POSTs
 * rendered by restaurants.php and handled by admin-handler.php. No
 * client-side fetch, no dynamically built forms, no bulk-action
 * machinery lives here because the page does not render a bulk
 * selection UI.
 *
 * @package FitPal
 * @version 4.0 — Removed the dead dropdown / legacy #detailsModal /
 *                single-row verify / bulk-selection blocks. They
 *                targeted DOM that restaurants.php does not render
 *                (#verifyForm, #detailsModal, #bulkBar,
 *                .actions-trigger, .view-details-btn,
 *                .action-item[data-action="verify_restaurant"],
 *                .bulk-btn[data-bulk-status]). They were guarded by
 *                length checks so they never ran, but they were a
 *                maintenance hazard: any future page that happened
 *                to render the same class names would silently
 *                activate them. The remaining four behaviors are the
 *                only ones the current page actually uses.
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