/**
 * FitPal Restaurant Sign-In JavaScript
 *
 * Handles Owner/Branch tab switching, password toggles, and
 * client-side validation. Real validation is server-side.
 *
 * @package FitPal
 * @version 1.1
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ============================================
        // TAB SWITCHING
        // ============================================
        var tabs = document.querySelectorAll('.sign-in-tab');
        var panels = {
            owner: document.getElementById('panel-owner'),
            branch: document.getElementById('panel-branch')
        };

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var target = this.getAttribute('data-tab');
                if (!panels[target]) return;

                tabs.forEach(function (t) {
                    var isActive = t === tab;
                    t.classList.toggle('active', isActive);
                    t.setAttribute('aria-selected', isActive ? 'true' : 'false');
                });

                Object.keys(panels).forEach(function (key) {
                    panels[key].classList.toggle('active', key === target);
                });

                var firstField = panels[target].querySelector('input:not([type="hidden"]), select');
                if (firstField) setTimeout(function () { firstField.focus(); }, 60);
            });
        });

        // ============================================
        // PASSWORD TOGGLES
        // ============================================
        function wirePasswordToggle(toggleId, inputId, iconId) {
            var btn = document.getElementById(toggleId);
            var input = document.getElementById(inputId);
            var icon = document.getElementById(iconId);
            if (!btn || !input || !icon) return;

            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
                var iconFile = isPassword ? 'password-unhide.svg' : 'password-hide.svg';
                icon.src = '../../shared/assets/images/icons/' + iconFile;
                icon.alt = isPassword ? 'Hide password' : 'Show password';
                this.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            });
        }

        wirePasswordToggle('ownerTogglePassword', 'owner_password', 'ownerPasswordIcon');
        wirePasswordToggle('branchTogglePassword', 'branch_password', 'branchPasswordIcon');

        // ============================================
        // HELPERS
        // ============================================
        function showFieldError(input, errorEl, message) {
            if (input) input.classList.add('error');
            if (errorEl) { errorEl.textContent = message; errorEl.style.display = 'block'; }
        }
        function clearFieldError(input, errorEl) {
            if (input) input.classList.remove('error');
            if (errorEl) { errorEl.textContent = ''; errorEl.style.display = 'none'; }
        }

        // ============================================
        // OWNER FORM
        // ============================================
        var ownerForm = document.getElementById('signInFormOwner');
        var ownerIdentifier = document.getElementById('owner_identifier');
        var ownerPassword = document.getElementById('owner_password');
        var ownerBtn = document.getElementById('ownerSignInBtn');
        var ownerIdError = document.getElementById('ownerIdentifierError');
        var ownerPwError = document.getElementById('ownerPasswordError');

        if (ownerForm) {
            ownerForm.addEventListener('submit', function (e) {
                var ok = true;
                clearFieldError(ownerIdentifier, ownerIdError);
                clearFieldError(ownerPassword, ownerPwError);

                if (!ownerIdentifier.value.trim()) {
                    showFieldError(ownerIdentifier, ownerIdError, 'Please enter your email or username.');
                    ok = false;
                }
                if (!ownerPassword.value) {
                    showFieldError(ownerPassword, ownerPwError, 'Please enter your password.');
                    ok = false;
                }
                if (!ok) { e.preventDefault(); return; }

                ownerBtn.disabled = true;
                ownerBtn.textContent = 'Signing in...';
            });

            ownerIdentifier.addEventListener('input', function () {
                if (this.value.trim()) clearFieldError(this, ownerIdError);
            });
            ownerPassword.addEventListener('input', function () {
                if (this.value) clearFieldError(this, ownerPwError);
            });
        }

        // ============================================
        // BRANCH FORM
        // ============================================
        var branchForm = document.getElementById('signInFormBranch');
        var branchCode = document.getElementById('branch_code');
        var branchIdentifier = document.getElementById('branch_identifier');
        var branchPassword = document.getElementById('branch_password');
        var branchBtn = document.getElementById('branchSignInBtn');
        var branchCodeError = document.getElementById('branchCodeError');
        var branchIdError = document.getElementById('branchIdentifierError');
        var branchPwError = document.getElementById('branchPasswordError');

        if (branchForm) {
            branchForm.addEventListener('submit', function (e) {
                var ok = true;
                clearFieldError(branchCode, branchCodeError);
                clearFieldError(branchIdentifier, branchIdError);
                clearFieldError(branchPassword, branchPwError);

                if (!branchCode.value) {
                    showFieldError(branchCode, branchCodeError, 'Please select a branch.');
                    ok = false;
                }
                if (!branchIdentifier.value.trim()) {
                    showFieldError(branchIdentifier, branchIdError, 'Please enter your email or username.');
                    ok = false;
                }
                if (!branchPassword.value) {
                    showFieldError(branchPassword, branchPwError, 'Please enter your password.');
                    ok = false;
                }
                if (!ok) { e.preventDefault(); return; }

                branchBtn.disabled = true;
                branchBtn.textContent = 'Signing in...';
            });

            branchCode.addEventListener('change', function () {
                if (this.value) clearFieldError(this, branchCodeError);
            });
            branchIdentifier.addEventListener('input', function () {
                if (this.value.trim()) clearFieldError(this, branchIdError);
            });
            branchPassword.addEventListener('input', function () {
                if (this.value) clearFieldError(this, branchPwError);
            });
        }
    });
})();