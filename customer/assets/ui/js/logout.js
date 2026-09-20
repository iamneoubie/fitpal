/**
 * FitPal Customer Logout Confirmation
 *
 * Intercepts clicks on [data-logout-trigger] elements and shows a
 * Yes/No confirmation modal. The modal's confirm anchor navigates to
 * sign-out-handler.php. Cancel closes the modal.
 *
 * The modal markup is rendered by includes/header.php on every
 * authenticated page, so this script works everywhere.
 *
 * @package FitPal
 * @version 1.0
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        var modal = document.getElementById('logoutModal');
        if (!modal) return;

        var confirmBtn = modal.querySelector('.logout-btn-confirm');
        var cancelNodes = modal.querySelectorAll('[data-logout-cancel]');
        var triggers = document.querySelectorAll('[data-logout-trigger]');

        function openModal() {
            document.body.style.overflow = 'hidden';
            modal.style.display = 'flex';
            void modal.offsetWidth;
            modal.classList.add('active');
            if (confirmBtn) setTimeout(function () { confirmBtn.focus(); }, 80);
        }

        function closeModal() {
            modal.classList.remove('active');
            setTimeout(function () {
                if (!modal.classList.contains('active')) {
                    modal.style.display = 'none';
                    document.body.style.overflow = '';
                }
            }, 220);
        }

        triggers.forEach(function (trigger) {
            trigger.addEventListener('click', function (e) {
                e.preventDefault();
                openModal();
            });
        });

        cancelNodes.forEach(function (node) {
            node.addEventListener('click', function (e) {
                e.preventDefault();
                closeModal();
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('active')) {
                closeModal();
            }
        });
    });
})();