/**
 * FitPal Rider Sign-In JavaScript
 *
 * Client-side validation only. Server-side validation lives in
 * sign-in-handler.php.
 *
 * @package FitPal
 * @version 1.1 — Aligns with customer sign-in.js; adds password
 *                toggle hook (optional icon element).
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        const form = document.getElementById('riderSignInForm');
        const identifier = document.getElementById('identifier');
        const password = document.getElementById('password');
        const submitBtn = form ? form.querySelector('button[type="submit"]') : null;

        if (!form) return;

        form.addEventListener('submit', function (e) {
            let isValid = true;

            [identifier, password].forEach(function (el) {
                if (el) el.classList.remove('is-error');
            });

            if (!identifier || !identifier.value.trim()) {
                if (identifier) identifier.classList.add('is-error');
                isValid = false;
            }

            if (!password || !password.value) {
                if (password) password.classList.add('is-error');
                isValid = false;
            }

            if (!isValid) {
                e.preventDefault();
                return;
            }

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Signing in...';
            }
        });

        // Real-time error clearing
        [identifier, password].forEach(function (el) {
            if (!el) return;
            el.addEventListener('input', function () {
                if (this.value.trim() !== '') {
                    this.classList.remove('is-error');
                }
            });
        });
    });
})();