/**
 * FitPal Restaurant Profile JavaScript
 *
 * Handles tab switching, password toggles, form submissions, inline
 * error rendering, and toast feedback.
 *
 * @package FitPal
 * @version 1.0
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        var CFG = window.FITPAL_RESTAURANT_PROFILE || {};
        var CSRF = CFG.csrfToken || '';
        var ASSET = CFG.assetBase || '../../shared/';
        var HANDLER = '../backend/handlers/profile-handler.php';

        /* ============================================
           TABS
           ============================================ */
        var tabs = document.querySelectorAll('.profile-tab');
        var panels = document.querySelectorAll('.profile-tab-content');

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var target = this.getAttribute('data-tab');

                tabs.forEach(function (t) {
                    var active = t === tab;
                    t.classList.toggle('active', active);
                    t.setAttribute('aria-selected', active ? 'true' : 'false');
                });

                panels.forEach(function (p) {
                    p.classList.toggle('active', p.id === 'tab-' + target);
                });
            });
        });

        /* ============================================
           PASSWORD TOGGLES
           ============================================ */
        document.querySelectorAll('[data-toggle-pw]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var targetId = this.getAttribute('data-toggle-pw');
                var input = document.getElementById(targetId);
                var icon = this.querySelector('img');
                if (!input) return;

                var isPw = input.type === 'password';
                input.type = isPw ? 'text' : 'password';

                if (icon) {
                    var file = isPw ? 'password-unhide.svg' : 'password-hide.svg';
                    icon.src = ASSET + 'assets/images/icons/' + file;
                }
            });
        });

        /* ============================================
           HELPERS
           ============================================ */
        function showError(id, message) {
            var el = document.getElementById(id);
            if (!el) return;
            el.textContent = message;
            el.style.display = 'block';
        }
        function clearError(id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.textContent = '';
            el.style.display = 'none';
        }
        function clearAllErrors(form) {
            form.querySelectorAll('.form-error').forEach(function (el) {
                el.textContent = '';
                el.style.display = 'none';
            });
        }

        function showToast(message, type) {
            var toast = document.getElementById('restaurantProfileToast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'restaurantProfileToast';
                toast.style.cssText = [
                    'position:fixed', 'top:80px', 'right:20px',
                    'padding:12px 20px', 'border-radius:8px',
                    'font-size:14px', 'font-weight:500', 'z-index:9999',
                    'transform:translateX(120%)',
                    'transition:transform .3s cubic-bezier(.4,0,.2,1)',
                    'max-width:360px', 'box-shadow:0 4px 16px rgba(0,0,0,.15)'
                ].join(';');
                document.body.appendChild(toast);
            }

            var palette = {
                success: ['#d1fae5', '#065f46'],
                error: ['#fee2e2', '#991b1b'],
                info: ['#dbeafe', '#1e40af']
            };
            var c = palette[type] || palette.info;
            toast.style.background = c[0];
            toast.style.color = c[1];
            toast.textContent = message;

            void toast.offsetWidth;
            toast.style.transform = 'translateX(0)';

            clearTimeout(toast._timer);
            toast._timer = setTimeout(function () {
                toast.style.transform = 'translateX(120%)';
            }, 2800);
        }

        /* ============================================
           GENERIC FORM WIRER
           ============================================ */
        function wireForm(formId, options) {
            var form = document.getElementById(formId);
            if (!form) return;

            var btn = form.querySelector('button[type="submit"]');
            var originalLabel = btn ? btn.textContent.trim() : 'Save';

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                clearAllErrors(form);

                if (options.validate) {
                    var err = options.validate(form);
                    if (err) {
                        showError(err.id, err.message);
                        return;
                    }
                }

                if (btn) {
                    btn.disabled = true;
                    btn.textContent = options.loadingLabel || 'Saving…';
                }

                fetch(HANDLER, {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin'
                })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data && data.status === 'success') {
                            showToast(data.message || 'Saved.', 'success');
                            if (options.onSuccess) options.onSuccess(data);
                        } else {
                            var field = data && data.field;
                            var map = options.errorMap || {};
                            if (field && map[field]) {
                                showError(map[field], data.message);
                            } else {
                                showToast((data && data.message) || 'Could not save.', 'error');
                            }
                        }
                    })
                    .catch(function () {
                        showToast('Network error. Please try again.', 'error');
                    })
                    .finally(function () {
                        if (btn) {
                            btn.disabled = false;
                            btn.textContent = originalLabel;
                        }
                    });
            });
        }

        /* ============================================
           CONTACT FORM
           ============================================ */
        wireForm('contactForm', {
            loadingLabel: 'Saving…',
            errorMap: { contact_number: 'contactError' },
            validate: function (form) {
                var input = form.querySelector('#contact_number');
                var val = input.value.trim();
                if (val !== '' && !/^09\d{9}$/.test(val)) {
                    return { id: 'contactError', message: 'Enter a valid PH mobile number (09XXXXXXXXX).' };
                }
                return null;
            }
        });

        /* ============================================
           BUSINESS FORM
           ============================================ */
        wireForm('businessForm', {
            loadingLabel: 'Saving…',
            errorMap: {
                description: 'descriptionError',
                cuisine_type: 'cuisineTypeError'
            }
        });

        /* ============================================
           BRANCH FORM
           ============================================ */
        wireForm('branchForm', {
            loadingLabel: 'Saving…',
            errorMap: {
                block: 'blockError',
                city: 'cityError',
                postal_code: 'postalCodeError'
            },
            validate: function (form) {
                var block = form.querySelector('#block');
                var city = form.querySelector('#city');
                if (!block.value.trim()) return { id: 'blockError', message: 'Block / Street is required.' };
                if (!city.value.trim()) return { id: 'cityError', message: 'City is required.' };
                return null;
            }
        });

        /* ============================================
           PASSWORD FORM
           ============================================ */
        wireForm('passwordForm', {
            loadingLabel: 'Changing…',
            errorMap: {
                current_password: 'currentPasswordError',
                new_password: 'newPasswordError',
                confirm_password: 'confirmPasswordError'
            },
            validate: function (form) {
                var cur = form.querySelector('#current_password').value;
                var nw = form.querySelector('#new_password').value;
                var cf = form.querySelector('#confirm_password').value;

                if (cur === '') return { id: 'currentPasswordError', message: 'Current password is required.' };
                if (nw.length < 8) return { id: 'newPasswordError', message: 'New password must be at least 8 characters.' };
                if (nw.length > 20) return { id: 'newPasswordError', message: 'New password must be no more than 20 characters.' };
                if (!/^[A-Za-z0-9]+$/.test(nw)) return { id: 'newPasswordError', message: 'Only letters and numbers.' };
                if (!/[A-Za-z]/.test(nw) || !/[0-9]/.test(nw)) return { id: 'newPasswordError', message: 'Must contain letters and numbers.' };
                if (nw !== cf) return { id: 'confirmPasswordError', message: 'Passwords do not match.' };
                return null;
            },
            onSuccess: function () {
                ['current_password', 'new_password', 'confirm_password'].forEach(function (id) {
                    var el = document.getElementById(id);
                    if (el) el.value = '';
                });
            }
        });
    });
})();