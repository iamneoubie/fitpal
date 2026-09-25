/**
 * FitPal Admin Sign-In JavaScript
 *
 * Handles password toggle and basic client-side validation.
 * All real validation happens server-side; this is a UX layer only.
 *
 * @package FitPal
 * @version 1.1 — Input error class switched from 'error' to
 *                'is-error' to match the admin role's convention.
 *                profile.js has always used 'is-error', and the
 *                shared admin form styles are written against that
 *                name. sign-in.js was the only holdout still adding
 *                the bare 'error' class, which meant the sign-in
 *                page needed its own duplicate CSS rule to style
 *                the invalid state. Both files now agree on
 *                'is-error'.
 *
 *                sign-in.css still carries an `.error` rule during
 *                the transition. A follow-up CSS pass can delete it
 *                once nothing references the old name.
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        const form            = document.getElementById('signInForm');
        const identifier      = document.getElementById('identifier');
        const password        = document.getElementById('password');
        const signInBtn       = document.getElementById('signInBtn');
        const togglePassword  = document.getElementById('togglePassword');
        const passwordIcon    = document.getElementById('passwordIcon');
        const identifierError = document.getElementById('identifierError');
        const passwordError   = document.getElementById('passwordError');

        // ---- Password toggle ----
        if (togglePassword && password && passwordIcon) {
            togglePassword.addEventListener('click', function (e) {
                e.preventDefault();
                const isPassword = password.type === 'password';
                password.type    = isPassword ? 'text' : 'password';

                const iconFile   = isPassword ? 'password-unhide.svg' : 'password-hide.svg';
                passwordIcon.src = '../../shared/assets/images/icons/' + iconFile;
                passwordIcon.alt = isPassword ? 'Hide password' : 'Show password';
                this.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            });
        }

        // ---- Submit validation ----
        if (form && signInBtn) {
            form.addEventListener('submit', function (e) {
                let isValid = true;
                clearErrors();

                if (!identifier || !identifier.value.trim()) {
                    showFieldError(identifier, identifierError, 'Please enter your email or username.');
                    isValid = false;
                }

                if (!password || !password.value) {
                    showFieldError(password, passwordError, 'Please enter your password.');
                    isValid = false;
                }

                if (!isValid) {
                    e.preventDefault();
                    return;
                }

                signInBtn.disabled    = true;
                signInBtn.textContent = 'Signing in...';
            });
        }

        // ---- Real-time clear ----
        if (identifier) {
            identifier.addEventListener('input', function () {
                if (this.value.trim()) clearFieldError(this, identifierError);
            });
        }
        if (password) {
            password.addEventListener('input', function () {
                if (this.value) clearFieldError(this, passwordError);
            });
        }

        // ---- Helpers ----
        function clearErrors() {
            clearFieldError(identifier, identifierError);
            clearFieldError(password, passwordError);
        }
        function showFieldError(input, errorEl, message) {
            if (input)   input.classList.add('is-error');
            if (errorEl) { errorEl.textContent = message; errorEl.style.display = 'block'; }
        }
        function clearFieldError(input, errorEl) {
            if (input)   input.classList.remove('is-error');
            if (errorEl) { errorEl.textContent = ''; errorEl.style.display = 'none'; }
        }
    });
})();