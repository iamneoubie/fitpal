/**
 * FitPal Rider Profile Page JavaScript
 *
 * Responsibilities:
 *   - Tab switching (Personal Information | Address)
 *   - Edit-profile toggle (enable only the contact field)
 *   - Profile picture upload via rider-handler.php
 *   - Form submission via fetch, JSON response
 *   - Toast notifications
 *
 * Mirrors the customer profile JS style: var-only, ES5-friendly,
 * no external dependencies, single DOMContentLoaded wrapper.
 *
 * @package FitPal
 * @version 2.0 — Tab switching + edit toggle + picture upload;
 *                layout aligned with the customer profile page.
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        var CFG         = window.FITPAL_RIDER_PROFILE || {};
        var CSRF_TOKEN  = CFG.csrfToken  || '';
        var ASSET_BASE  = CFG.assetBase  || '../../shared/';

        // ============================================
        // TABS
        // ============================================
        var tabs        = document.querySelectorAll('.profile-tab');
        var tabContents = document.querySelectorAll('.profile-tab-content');

        function switchTab(tabId) {
            tabs.forEach(function (tab) {
                tab.classList.toggle('active', tab.dataset.tab === tabId);
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

        // ============================================
        // EDIT-PROFILE TOGGLE
        // Only the contact number is editable from this page. Everything
        // else is shown read-only and must go through support.
        // ============================================
        var editBtn         = document.getElementById('editProfileBtn');
        var cancelBtn       = document.getElementById('cancelEditBtn');
        var formActions     = document.getElementById('profileActions');
        var form            = document.getElementById('riderProfileForm');
        var saveBtn         = document.getElementById('saveProfileBtn');
        var contactInput    = document.getElementById('contact_number');

        function enterEditMode() {
            if (contactInput) contactInput.disabled = false;
            if (formActions) formActions.classList.remove('is-hidden');
            if (editBtn) editBtn.style.display = 'none';
            if (contactInput) contactInput.focus();
        }

        function exitEditMode() {
            if (contactInput) contactInput.disabled = true;
            if (formActions) formActions.classList.add('is-hidden');
            if (editBtn) editBtn.style.display = '';
        }

        if (editBtn) {
            editBtn.addEventListener('click', enterEditMode);
        }

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function (e) {
                e.preventDefault();
                // Reset the field to what the server rendered, then
                // leave edit mode without a full reload.
                if (contactInput) {
                    contactInput.value = contactInput.defaultValue || '';
                }
                exitEditMode();
            });
        }

        // ============================================
        // INPUT FILTER — contact number: digits + spaces only
        // ============================================
        if (contactInput) {
            contactInput.addEventListener('input', function () {
                var cleaned = this.value.replace(/[^0-9\s]/g, '');
                if (this.value !== cleaned) {
                    this.value = cleaned;
                }
            });
        }

        // ============================================
        // PROFILE PICTURE UPLOAD
        // ============================================
        var uploadBtn       = document.getElementById('uploadPictureBtn');
        var pictureInput    = document.getElementById('profilePictureInput');
        var avatarImg       = document.getElementById('profileAvatarImg');
        var avatarInitial   = document.getElementById('profileAvatarInitial');

        if (uploadBtn && pictureInput) {
            uploadBtn.addEventListener('click', function () {
                pictureInput.click();
            });

            pictureInput.addEventListener('change', function () {
                if (!this.files || !this.files[0]) return;

                var file = this.files[0];

                if (!file.type.startsWith('image/')) {
                    showToast('Please select an image file.', 'error');
                    return;
                }
                if (file.size > 2 * 1024 * 1024) {
                    showToast('Image must be under 2 MB.', 'error');
                    return;
                }

                // Local preview while the upload is in flight.
                var reader = new FileReader();
                reader.onload = function (e) {
                    if (avatarImg) {
                        avatarImg.src = e.target.result;
                        avatarImg.style.display = 'block';
                    }
                    if (avatarInitial) avatarInitial.style.display = 'none';
                };
                reader.readAsDataURL(file);

                uploadPicture(file);
            });
        }

        function uploadPicture(file) {
            var formData = new FormData();
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('action', 'upload_picture');
            formData.append('profile_picture', file);

            if (uploadBtn) uploadBtn.disabled = true;

            fetch('../backend/handlers/rider-handler.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data && data.status === 'success') {
                        showToast(data.message || 'Profile picture updated', 'success');
                    } else {
                        showToast((data && data.message) || 'Could not upload picture', 'error');
                    }
                })
                .catch(function () {
                    showToast('Network error. Please try again.', 'error');
                })
                .finally(function () {
                    if (uploadBtn) uploadBtn.disabled = false;
                });
        }

        // ============================================
        // FORM SUBMISSION
        // ============================================
        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();

                if (saveBtn) {
                    saveBtn.disabled = true;
                    saveBtn.textContent = 'Saving…';
                }

                var formData = new FormData(form);

                fetch(form.action, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data && data.status === 'success') {
                            showToast(data.message || 'Profile updated', 'success');
                            setTimeout(function () { window.location.reload(); }, 1000);
                        } else {
                            showToast((data && data.message) || 'Could not update profile', 'error');
                            if (saveBtn) {
                                saveBtn.disabled = false;
                                saveBtn.textContent = 'Save Changes';
                            }
                        }
                    })
                    .catch(function () {
                        showToast('Network error. Please try again.', 'error');
                        if (saveBtn) {
                            saveBtn.disabled = false;
                            saveBtn.textContent = 'Save Changes';
                        }
                    });
            });
        }

        // ============================================
        // TOAST
        // ============================================
        function showToast(message, type) {
            var existing = document.querySelector('.rider-toast');
            if (existing) existing.remove();

            var toast = document.createElement('div');
            toast.className = 'rider-toast ' + (type || 'success');
            toast.setAttribute('role', 'alert');

            var iconFile = type === 'error' ? 'file-warning-fill.svg' : 'verified-fill.svg';

            toast.innerHTML =
                '<span class="rider-toast-icon">' +
                    '<img src="' + ASSET_BASE + 'assets/images/icons/' + iconFile + '" alt="">' +
                '</span>' +
                '<span class="rider-toast-message"></span>';

            // Assign message via textContent so nothing user-supplied
            // is ever parsed as HTML.
            var messageEl = toast.querySelector('.rider-toast-message');
            if (messageEl) messageEl.textContent = message;

            document.body.appendChild(toast);

            setTimeout(function () {
                toast.style.animation = 'riderToastOut 0.3s ease';
                setTimeout(function () { toast.remove(); }, 300);
            }, 3000);
        }

        // ============================================
        // INITIAL TAB STATE
        // ============================================
        var firstActiveTab = document.querySelector('.profile-tab.active');
        if (!firstActiveTab) {
            var firstTab = document.querySelector('.profile-tab');
            if (firstTab) {
                switchTab(firstTab.dataset.tab);
            }
        }

        // Ensure the edit action row starts hidden if the server did
        // not already mark it as hidden.
        if (formActions && !formActions.classList.contains('is-hidden')) {
            formActions.classList.add('is-hidden');
        }
    });
})();