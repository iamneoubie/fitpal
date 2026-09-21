/**
 * FitPal Customer Sign-In JavaScript
 *
 * Handles password toggle and basic client-side validation before form submit.
 * All real validation happens server-side; this is a UX layer only.
 *
 * @package FitPal
 * @version 1.1
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

        // ============================================
        // PASSWORD TOGGLE
        // Icon paths are relative to the PAGE that loads this script,
        // not to this script file's location.
        // sign-in.php lives at customer/pages/ → shared/ is ../../shared/
        // ============================================
        if (togglePassword && password && passwordIcon) {
            togglePassword.addEventListener('click', function (e) {
                e.preventDefault();
                const isPassword = password.type === 'password';
                password.type    = isPassword ? 'text' : 'password';

                const iconFile    = isPassword ? 'password-unhide.svg' : 'password-hide.svg';
                passwordIcon.src  = '../../shared/assets/images/icons/' + iconFile;
                passwordIcon.alt  = isPassword ? 'Hide password' : 'Show password';
                this.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            });
        }

        // ============================================
        // FORM VALIDATION
        // ============================================
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
                // Allow native form submit to proceed
            });
        }

        // ============================================
        // REAL-TIME ERROR CLEARING
        // ============================================
        if (identifier) {
            identifier.addEventListener('input', function () {
                if (this.value.trim()) {
                    clearFieldError(this, identifierError);
                }
            });
        }

        if (password) {
            password.addEventListener('input', function () {
                if (this.value) {
                    clearFieldError(this, passwordError);
                }
            });
        }

        // ============================================
        // HELPER FUNCTIONS
        // ============================================

        function clearErrors() {
            clearFieldError(identifier, identifierError);
            clearFieldError(password, passwordError);
        }

        /**
         * @param {HTMLElement|null} input
         * @param {HTMLElement|null} errorEl
         * @param {string} message
         */
        function showFieldError(input, errorEl, message) {
            if (input) {
                input.classList.add('error');
            }
            if (errorEl) {
                errorEl.textContent    = message;
                errorEl.style.display  = 'block';
            }
        }

        /**
         * @param {HTMLElement|null} input
         * @param {HTMLElement|null} errorEl
         */
        function clearFieldError(input, errorEl) {
            if (input) {
                input.classList.remove('error');
            }
            if (errorEl) {
                errorEl.textContent   = '';
                errorEl.style.display = 'none';
            }
        }

    });

})();