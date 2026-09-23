/**
 * FitPal Admin — Riders Page
 *
 * Responsibilities:
 *
 *   1. Backdrop click closes the detail modal by navigating to the
 *      URL in data-close-url on the backdrop.
 *
 *   2. Image error handling, capture phase (error does not bubble):
 *      - .admin-cell-avatar img  → replace image with the rider's
 *        initial from data-initial.
 *      - .admin-doc-image img    → replace wrapper content with the
 *        empty-state block.
 *      - .admin-table-empty-icon img → swap in data-fallback-src.
 *
 *   3. Escape key closes the modal by navigating to the same
 *      data-close-url.
 *
 *   4. Tab switching inside the detail modal (Information /
 *      Documents / Deliveries).
 *
 * All mutations (approve / deny / toggle) are plain HTML form POSTs
 * rendered by riders.php and handled by admin-handler.php. No
 * client-side fetch, no dynamically built forms, no bulk-action
 * machinery lives here because the page does not render a bulk
 * selection UI.
 *
 * @package FitPal
 * @version 3.0 — Removed the dead row-verify and bulk-action blocks.
 *                They targeted DOM that riders.php does not render
 *                (#ridersTable, .row-btn, .row-checkbox, #bulkBar,
 *                .bulk-btn[data-bulk-status]), and the fetch payload
 *                they would have sent (action=verify_rider) was not
 *                even a case in admin-handler.php's switch — only
 *                set_rider_verification is. They were guarded by
 *                `if (!table) return;` so they never ran, but they
 *                were a maintenance hazard: any future page that
 *                happened to render the same class names would
 *                silently activate them and post an unrecognized
 *                action. The remaining four behaviors are the only
 *                ones the current page actually uses.
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
        // IMAGE ERROR — CONTEXT-SPECIFIC FALLBACKS
        // ============================================
        document.addEventListener('error', function (e) {
            var img = e.target;
            if (!img || img.tagName !== 'IMG') return;

            // --- 1. Avatar fallback: replace image with initial ---
            var avatarCell = img.closest('.admin-cell-avatar');
            if (avatarCell && avatarCell.contains(img)) {
                if (img.dataset.fallbackApplied === '1') return;
                img.dataset.fallbackApplied = '1';

                var initial = img.getAttribute('data-initial') || '';
                avatarCell.textContent = initial;
                return;
            }

            // --- 2. Document preview fallback: empty-state block ---
            var docImage = img.closest('.admin-doc-image');
            if (docImage && docImage.contains(img)) {
                if (docImage.dataset.fallbackApplied === '1') return;
                docImage.dataset.fallbackApplied = '1';

                docImage.innerHTML =
                    '<div class="admin-doc-empty">' +
                    '<span>Image could not be loaded.</span>' +
                    '</div>';
                return;
            }

            // --- 3. Empty-state icon fallback: swap src ---
            var emptyIcon = img.closest('.admin-table-empty-icon');
            if (emptyIcon && emptyIcon.contains(img)) {
                if (img.dataset.fallbackApplied === '1') return;

                var fallback = emptyIcon.getAttribute('data-fallback-src');
                if (!fallback) return;

                img.dataset.fallbackApplied = '1';
                img.src = fallback;
                return;
            }
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