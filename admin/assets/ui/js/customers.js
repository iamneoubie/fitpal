/**
 * FitPal Admin — Customers Page
 *
 * The customer detail modal is URL-driven (?open=ID). This file
 * adds keyboard support for closing it.
 *
 * @package FitPal
 * @version 1.0
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;

            var openModal = document.querySelector('.admin-modal.is-open');
            if (!openModal) return;

            var closeLink = openModal.querySelector('.admin-modal-close');
            if (closeLink) {
                closeLink.click();
            }
        });
    });
})();