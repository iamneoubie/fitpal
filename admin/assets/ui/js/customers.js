/**
 * FitPal Admin — Customers Page
 *
 * This page loads the shared admin-modal.js module for all modal
 * behaviour (open/close, tab switching, arrow navigation). This
 * file only owns page-specific enhancements.
 *
 * @package FitPal
 * @version 3.0 — Removed all modal logic. The page now relies on
 *                the shared admin-modal.js module.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        // Image error fallback for the empty-state icon.
        // This is a page-specific concern and does not belong in
        // the shared modal module.
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
    });
})();