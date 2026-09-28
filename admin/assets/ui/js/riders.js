/**
 * FitPal Admin — Riders Page
 *
 * This page loads the shared admin-modal.js module for all modal
 * behaviour. This file only owns page-specific enhancements.
 *
 * @package FitPal
 * @version 4.0 — Removed all modal and tab logic. The page now
 *                relies on the shared admin-modal.js module.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        // Image error handling for avatars and document previews.
        // This is a page-specific concern and does not belong in
        // the shared modal module.
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
    });
})();