/**
 * FitPal Admin Profile Page JavaScript
 *
 * Responsibilities:
 *   - Tab switching between Personal Information and Security
 *   - Password visibility toggles on all three password fields
 *   - Phone-number input filter (digits only, capped at 11)
 *   - Live password-rule feedback (length, alphanumeric, has letter,
 *     has number)
 *   - Confirm-password matching feedback
 *   - Client-side validation on submit with inline error messages
 *
 * All real validation also runs server-side in admin-handler.php.
 * This file is a UX layer only — it never trusts its own checks as
 * authoritative.
 *
 * No external dependencies, no build step. ES5-compatible so it runs
 * in the same class of browsers as the rest of the admin pages.
 *
 * @package FitPal
 * @version 2.0 — Reads the asset base from the .profile-page
 *                container's data-asset-base attribute instead of
 *                window.FITPAL_ADMIN_PROFILE. The inline <script>
 *                that defined that global was removed from
 *                profile.php.
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        var profilePage = document.querySelector('.profile-page');
        var assetBase = profilePage
            ? (profilePage.getAttribute('data-asset-base') || '')
            : '';
        var ASSET_BASE = assetBase || '../../shared/';

        /* ============================================
         * TABS
         * ============================================ */

        var tabs = document.querySelectorAll('.profile-tab');
        var tabContents = document.querySelectorAll('.profile-tab-content');

        function switchTab(tabId) {
            tabs.forEach(function (tab) {
                var isActive = tab.dataset.tab === tabId;
                tab.classList.toggle('active', isActive);
                tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });
            tabContents.forEach(function (content) {
                content.classList.toggle('active', content.id === 'tab-' + tabId);
            });
        }

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                switchTab(this.dataset.tab);
            });
        });

        /* ============================================
         * PASSWORD VISIBILITY TOGGLES
         * ============================================ */

        document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var targetId = btn.getAttribute('data-toggle-password');
                var input = document.getElementById(targetId);
                var icon = btn.querySelector('[data-password-icon]');
                if (!input || !icon) return;

                var isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';

                var iconFile = isPassword ? 'password-unhide.svg' : 'password-hide.svg';
                icon.src = ASSET_BASE + 'assets/images/icons/' + iconFile;
                icon.alt = isPassword ? 'Hide password' : 'Show password';
                btn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
                btn.setAttribute('aria-pressed', isPassword ? 'true' : 'false');
            });
        });

        /* ============================================
         * HELPERS
         * ============================================ */

        function showError(input, errorEl, message) {
            if (input) input.classList.add('is-error');
            if (errorEl) errorEl.textContent = message;
        }

        function clearError(input, errorEl) {
            if (input) input.classList.remove('is-error');
            if (errorEl) errorEl.textContent = '';
        }

        /* ============================================
         * PERSONAL INFO FORM
         * ============================================ */

        var infoForm = document.getElementById('profileInfoForm');
        var firstName = document.getElementById('first_name');
        var lastName = document.getElementById('last_name');
        var contactNumber = document.getElementById('contact_number');
        var firstNameError = document.getElementById('firstNameError');
        var lastNameError = document.getElementById('lastNameError');
        var contactError = document.getElementById('contactError');

        // Phone number: digits only, capped at 11.
        if (contactNumber) {
            contactNumber.addEventListener('input', function () {
                var digits = this.value.replace(/\D/g, '');
                if (digits.length > 11) digits = digits.slice(0, 11);
                if (this.value !== digits) this.value = digits;
                clearError(this, contactError);
            });
        }

        // Name fields: clear errors on input.
        [[firstName, firstNameError], [lastName, lastNameError]].forEach(function (pair) {
            var input = pair[0];
            var errorEl = pair[1];
            if (!input) return;
            input.addEventListener('input', function () {
                clearError(this, errorEl);
            });
        });

        if (infoForm) {
            infoForm.addEventListener('submit', function (e) {
                var ok = true;

                clearError(firstName, firstNameError);
                clearError(lastName, lastNameError);
                clearError(contactNumber, contactError);

                if (!firstName.value.trim() || firstName.value.trim().length < 2) {
                    showError(firstName, firstNameError, 'First name must be at least 2 characters.');
                    ok = false;
                }

                if (!lastName.value.trim() || lastName.value.trim().length < 2) {
                    showError(lastName, lastNameError, 'Last name must be at least 2 characters.');
                    ok = false;
                }

                if (contactNumber.value.trim() !== '') {
                    if (!/^09\d{9}$/.test(contactNumber.value.trim())) {
                        showError(contactNumber, contactError,
                            'Enter a valid PH mobile number (11 digits, starting with 09).');
                        ok = false;
                    }
                }

                if (!ok) {
                    e.preventDefault();
                    var firstInvalid = infoForm.querySelector('.is-error');
                    if (firstInvalid) firstInvalid.focus();
                }
            });
        }

        var resetInfoBtn = document.getElementById('resetInfoBtn');
        if (resetInfoBtn && infoForm) {
            resetInfoBtn.addEventListener('click', function () {
                clearError(firstName, firstNameError);
                clearError(lastName, lastNameError);
                clearError(contactNumber, contactError);
            });
        }

        /* ============================================
         * PASSWORD FORM
         * ============================================ */

        var passwordForm = document.getElementById('passwordForm');
        var currentPassword = document.getElementById('current_password');
        var newPassword = document.getElementById('new_password');
        var confirmPassword = document.getElementById('confirm_password');
        var currentPasswordError = document.getElementById('currentPasswordError');
        var newPasswordError = document.getElementById('newPasswordError');
        var confirmPasswordError = document.getElementById('confirmPasswordError');
        var passwordRulesList = document.getElementById('passwordRules');

        /**
         * Evaluate each password rule against the given string.
         *
         * @param {string} pw
         * @returns {{length:boolean, alphanumeric:boolean, hasLetter:boolean, hasNumber:boolean}}
         */
        function evaluatePasswordRules(pw) {
            return {
                'length': pw.length >= 8 && pw.length <= 20,
                'alphanumeric': /^[A-Za-z0-9]+$/.test(pw),
                'has-letter': /[A-Za-z]/.test(pw),
                'has-number': /[0-9]/.test(pw)
            };
        }

        /**
         * Paint the rules list: green check for met rules, grey dot
         * for unmet rules.
         */
        function paintPasswordRules(pw) {
            if (!passwordRulesList) return;
            var results = evaluatePasswordRules(pw);
            passwordRulesList.querySelectorAll('.password-rule').forEach(function (li) {
                var rule = li.getAttribute('data-rule');
                var isMet = results[rule] === true;
                li.classList.toggle('is-met', isMet);
            });
        }

        /**
         * Return the first rule violation message for a password, or
         * an empty string if the password is valid.
         */
        function firstPasswordViolation(pw) {
            if (pw.length < 8) return 'Password must be at least 8 characters.';
            if (pw.length > 20) return 'Password must be no more than 20 characters.';
            if (!/^[A-Za-z0-9]+$/.test(pw)) return 'Password can only contain letters and numbers.';
            if (!/[A-Za-z]/.test(pw)) return 'Password must contain at least one letter.';
            if (!/[0-9]/.test(pw)) return 'Password must contain at least one number.';
            return '';
        }

        if (newPassword) {
            newPassword.addEventListener('input', function () {
                paintPasswordRules(this.value);
                clearError(this, newPasswordError);

                if (confirmPassword && confirmPassword.value !== '') {
                    if (confirmPassword.value === this.value) {
                        clearError(confirmPassword, confirmPasswordError);
                    } else {
                        showError(confirmPassword, confirmPasswordError, 'Passwords do not match.');
                    }
                }
            });

            newPassword.addEventListener('blur', function () {
                if (this.value === '') return;
                var violation = firstPasswordViolation(this.value);
                if (violation !== '') {
                    showError(this, newPasswordError, violation);
                }
            });
        }

        if (confirmPassword) {
            confirmPassword.addEventListener('input', function () {
                clearError(this, confirmPasswordError);
                if (this.value === '') return;
                if (newPassword && this.value !== newPassword.value) {
                    showError(this, confirmPasswordError, 'Passwords do not match.');
                }
            });
        }

        if (currentPassword) {
            currentPassword.addEventListener('input', function () {
                clearError(this, currentPasswordError);
            });
        }

        if (passwordForm) {
            passwordForm.addEventListener('submit', function (e) {
                var ok = true;

                clearError(currentPassword, currentPasswordError);
                clearError(newPassword, newPasswordError);
                clearError(confirmPassword, confirmPasswordError);

                if (currentPassword.value === '') {
                    showError(currentPassword, currentPasswordError, 'Please enter your current password.');
                    ok = false;
                }

                var violation = firstPasswordViolation(newPassword.value);
                if (newPassword.value === '') {
                    showError(newPassword, newPasswordError, 'Please enter a new password.');
                    ok = false;
                } else if (violation !== '') {
                    showError(newPassword, newPasswordError, violation);
                    ok = false;
                }

                if (confirmPassword.value === '') {
                    showError(confirmPassword, confirmPasswordError, 'Please confirm your new password.');
                    ok = false;
                } else if (newPassword.value !== '' && confirmPassword.value !== newPassword.value) {
                    showError(confirmPassword, confirmPasswordError, 'Passwords do not match.');
                    ok = false;
                }

                if (!ok) {
                    e.preventDefault();
                    var firstInvalid = passwordForm.querySelector('.is-error');
                    if (firstInvalid) firstInvalid.focus();
                }
            });

            passwordForm.addEventListener('reset', function () {
                clearError(currentPassword, currentPasswordError);
                clearError(newPassword, newPasswordError);
                clearError(confirmPassword, confirmPasswordError);
                paintPasswordRules('');
            });
        }

        /* ============================================
         * INITIAL PAINT
         * ============================================ */

        // If the browser restored a value into the password field on
        // reload (e.g. via form-state restoration), repaint the rule
        // list so the user sees current state instead of a stale one.
        if (newPassword && newPassword.value !== '') {
            paintPasswordRules(newPassword.value);
        }

    });
})();