/**
 * FitPal Rider Sign-In JavaScript
 *
 * Client-side validation plus password visibility toggle. All real
 * validation happens server-side in sign-in-handler.php.
 *
 * @package FitPal
 * @version 2.0 — Adds password eye-icon toggle; preserves existing
 *                field error clearing.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        const form = document.getElementById('riderSignInForm');
        const identifier = document.getElementById('identifier');
        const password = document.getElementById('password');
        const submitBtn = form ? form.querySelector('button[type="submit"]') : null;

        const toggleBtn = document.getElementById('togglePassword');
        const toggleIcon = document.getElementById('passwordIcon');

        if (!form) return;

        // ============================================
        // PASSWORD VISIBILITY TOGGLE
        // ============================================
        if (toggleBtn && password && toggleIcon) {
            toggleBtn.addEventListener('click', function () {
                const isPassword = password.type === 'password';

                password.type = isPassword ? 'text' : 'password';

                const iconFile = isPassword ? 'password-unhide.svg' : 'password-hide.svg';
                toggleIcon.src = '../../shared/assets/images/icons/' + iconFile;
                toggleIcon.alt = isPassword ? 'Hide password' : 'Show password';

                toggleBtn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
                toggleBtn.setAttribute('aria-pressed', isPassword ? 'true' : 'false');
            });
        }

        // ============================================
        // FORM VALIDATION
        // ============================================
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