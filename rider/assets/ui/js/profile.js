/**
 * FitPal Rider Profile Page JavaScript
 *
 * Handles:
 *   - Profile edit toggle
 *   - Profile picture upload
 *   - Form submission
 *
 * @package FitPal
 * @version 1.0
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        var CFG = window.FITPAL_RIDER_PROFILE || {};
        var CSRF_TOKEN = CFG.csrfToken || '';
        var ASSET_BASE = CFG.assetBase || '../../shared/';

        // ============================================
        // EDIT TOGGLE
        // ============================================
        var editBtn = document.getElementById('editProfileBtn');
        var cancelBtn = document.getElementById('cancelEditBtn');
        var formActions = document.getElementById('profileFormActions');
        var form = document.getElementById('riderProfileForm');
        var saveBtn = document.getElementById('saveProfileBtn');
        var contactInput = document.getElementById('contactInput');

        if (editBtn && form && formActions) {
            editBtn.addEventListener('click', function () {
                // Enable only editable fields
                if (contactInput) contactInput.disabled = false;

                formActions.style.display = 'flex';
                editBtn.style.display = 'none';

                if (contactInput) contactInput.focus();
            });
        }

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                // Reload page to reset
                window.location.reload();
            });
        }

        // ============================================
        // PROFILE PICTURE UPLOAD
        // ============================================
        var uploadBtn = document.getElementById('uploadPictureBtn');
        var pictureInput = document.getElementById('profilePictureInput');
        var avatarImg = document.getElementById('profileAvatarImg');
        var avatarInitial = document.getElementById('profileAvatarInitial');

        if (uploadBtn && pictureInput) {
            uploadBtn.addEventListener('click', function () {
                pictureInput.click();
            });

            pictureInput.addEventListener('change', function () {
                if (!this.files || !this.files[0]) return;

                var file = this.files[0];

                // Validate type
                if (!file.type.startsWith('image/')) {
                    showToast('Please select an image file.', 'error');
                    return;
                }

                // Validate size (max 2 MB)
                if (file.size > 2 * 1024 * 1024) {
                    showToast('Image must be under 2 MB.', 'error');
                    return;
                }

                // Show preview immediately
                var reader = new FileReader();
                reader.onload = function (e) {
                    if (avatarImg) {
                        avatarImg.src = e.target.result;
                        avatarImg.style.display = 'block';
                    }
                    if (avatarInitial) avatarInitial.style.display = 'none';
                };
                reader.readAsDataURL(file);

                // Upload
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

        function showToast(message, type) {
            var toast = document.getElementById('riderToast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'riderToast';
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
                error: ['#fee2e2', '#991b1b']
            };
            var colors = palette[type] || palette.success;
            toast.style.background = colors[0];
            toast.style.color = colors[1];
            toast.textContent = message;
            void toast.offsetWidth;
            toast.style.transform = 'translateX(0)';
            clearTimeout(toast._timer);
            toast._timer = setTimeout(function () {
                toast.style.transform = 'translateX(120%)';
            }, 2800);
        }
    });
})();