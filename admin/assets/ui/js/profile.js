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
 *   - Edit-mode lifecycle (edit button → confirm modal → edit mode →
 *     cancel or save)
 *   - Unsaved-changes modal on attempted page leave
 *   - Save pipeline: update_profile POST → optional upload_picture
 *     POST → reload
 *   - Profile picture: local preview on pick, deferred upload on save
 *   - Visible save-failure banner
 *
 * Save-failure banner
 * -------------------
 * When the save pipeline fails, the pipeline writes the error
 * message into the #saveBanner element on the page and unhides it.
 * This replaces the previous silent-reset behavior, in which a 404
 * or non-JSON response from the handler left the admin with no
 * feedback at all — the Save button simply re-enabled itself and
 * nothing else happened.
 *
 * The banner is hidden again on the next successful save attempt
 * and on cancel, so it never lingers after the failure is resolved.
 *
 * @package FitPal
 * @version 4.1 — Adds showSaveBanner() / hideSaveBanner() and wires
 *                them into the pipeline. Every failure path now
 *                surfaces the error in the DOM.
 *
 *                (4.0: edit-mode lifecycle, confirm/unsaved modals,
 *                deferred picture upload. 3.0: picture preview.)
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        /* ============================================
         * CONFIG
         * ============================================ */

        var CFG = window.FITPAL_ADMIN_PROFILE || {};
        var CSRF_TOKEN = CFG.csrfToken || '';
        var UPDATE_ENDPOINT = CFG.updateEndpoint || '../backend/handlers/admin-handler.php';
        var UPLOAD_ENDPOINT = CFG.uploadEndpoint || '../backend/handlers/admin-handler.php';
        var INITIAL_AVATAR_SRC = CFG.initialAvatarSrc || '';

        /* ============================================
         * DOM ELEMENTS
         * ============================================ */

        var pageRoot = document.querySelector('.profile-page');

        var tabs = document.querySelectorAll('.profile-tab');
        var tabContents = document.querySelectorAll('.profile-tab-content');

        var editProfileBtn     = document.getElementById('editProfileBtn');
        var profileEditActions = document.getElementById('profileEditActions');
        var cancelEditBtn      = document.getElementById('cancelEditBtn');
        var saveProfileBtn     = document.getElementById('saveProfileBtn');
        var profileForm        = document.getElementById('profileForm');
        var formInputs         = profileForm ? profileForm.querySelectorAll('input, select, textarea') : [];

        var uploadPictureBtn     = document.getElementById('uploadPictureBtn');
        var profilePictureInput  = document.getElementById('profilePictureInput');
        var profileAvatarImg     = document.getElementById('profileAvatarImg');
        var profileAvatarInitial = document.getElementById('profileAvatarInitial');

        var saveBanner = document.getElementById('saveBanner');

        var confirmEditModal   = document.getElementById('confirmEditModal');
        var confirmEditProceed = document.getElementById('confirmEditProceed');

        var unsavedChangesModal = document.getElementById('unsavedChangesModal');
        var unsavedStayBtn      = document.getElementById('unsavedStayBtn');
        var unsavedSaveBtn      = document.getElementById('unsavedSaveBtn');

        /* ============================================
         * STATE
         * ============================================ */

        var isEditing        = false;
        var isSaving         = false;
        var suppressLeaveGuard = false;
        var pendingLeaveHref = '';

        var pendingPictureFile = null;
        var pendingPictureUrl  = '';

        var formSnapshot = {};

        function takeFormSnapshot() {
            formSnapshot = {};
            formInputs.forEach(function (input) {
                if (!input.name) return;
                formSnapshot[input.name] = input.value;
            });
        }

        function formFieldsHaveChanged() {
            var changed = false;
            formInputs.forEach(function (input) {
                if (!input.name || changed) return;
                if (!(input.name in formSnapshot)) return;
                if (input.value !== formSnapshot[input.name]) {
                    changed = true;
                }
            });
            return changed;
        }

        function formHasChanged() {
            return formFieldsHaveChanged() || pendingPictureFile !== null;
        }

        function restoreFormSnapshot() {
            formInputs.forEach(function (input) {
                if (!input.name) return;
                if (!(input.name in formSnapshot)) return;
                input.value = formSnapshot[input.name];
            });
        }

        takeFormSnapshot();

        /* ============================================
         * SAVE-FAILURE BANNER
         * ============================================ */

        function showSaveBanner(message) {
            if (!saveBanner) return;
            saveBanner.textContent = message;
            saveBanner.hidden = false;
        }

        function hideSaveBanner() {
            if (!saveBanner) return;
            saveBanner.textContent = '';
            saveBanner.hidden = true;
        }

        /* ============================================
         * BODY SCROLL LOCK
         * ============================================ */

        function lockBodyScroll() { document.body.style.overflow = 'hidden'; }
        function unlockBodyScroll() { document.body.style.overflow = ''; }

        /* ============================================
         * MODAL HELPERS
         * ============================================ */

        function openModal(modal) {
            if (!modal) return;
            lockBodyScroll();
            modal.style.display = 'flex';
            void modal.offsetWidth;
            modal.classList.add('active');
        }

        function closeModal(modal) {
            if (!modal) return;
            modal.classList.remove('active');
            setTimeout(function () {
                if (!modal.classList.contains('active')) {
                    modal.style.display = '';
                    unlockBodyScroll();
                }
            }, 0);
        }

        [confirmEditModal, unsavedChangesModal].forEach(function (modal) {
            if (!modal) return;
            modal.querySelectorAll('[data-modal-dismiss]').forEach(function (el) {
                el.addEventListener('click', function () {
                    closeModal(modal);
                });
            });
        });

        /* ============================================
         * AVATAR PREVIEW HELPERS
         * ============================================ */

        function applyAvatarSrc(url) {
            if (!profileAvatarImg || !profileAvatarInitial) return;

            if (url && url !== '') {
                profileAvatarImg.src = url;
                profileAvatarImg.style.display = 'block';
                profileAvatarInitial.style.display = 'none';
            } else {
                profileAvatarImg.removeAttribute('src');
                profileAvatarImg.style.display = 'none';
                profileAvatarInitial.style.display = 'flex';
            }
        }

        function clearPendingPicture() {
            if (pendingPictureUrl !== '') {
                URL.revokeObjectURL(pendingPictureUrl);
                pendingPictureUrl = '';
            }
            pendingPictureFile = null;
            if (profilePictureInput) profilePictureInput.value = '';
            applyAvatarSrc(INITIAL_AVATAR_SRC);
        }

        /* ============================================
         * LEAVE GUARD
         * ============================================ */

        var profileBackBtn = document.querySelector('.page-title-header .back-btn');

        function navigateAway(href) {
            suppressLeaveGuard = true;
            if (href) {
                window.location.href = href;
            } else if (window.history.length > 1 && document.referrer !== '') {
                window.history.back();
            } else {
                window.location.href = 'dashboard.php';
            }
        }

        function requestLeave(href) {
            pendingLeaveHref = href || '';

            if (isEditing && formHasChanged()) {
                openModal(unsavedChangesModal);
                return;
            }

            navigateAway(pendingLeaveHref);
        }

        if (profileBackBtn) {
            profileBackBtn.addEventListener('click', function (e) {
                e.preventDefault();
                var fallback = this.getAttribute('href') || 'dashboard.php';
                requestLeave(fallback);
            });
        }

        document.querySelectorAll(
            '.admin-header a[href], .mobile-nav a[href]'
        ).forEach(function (link) {
            link.addEventListener('click', function (e) {
                if (suppressLeaveGuard) return;
                if (this.target === '_blank') return;

                var href = this.getAttribute('href') || '';
                if (href === '' || href.charAt(0) === '#') return;

                if (isEditing && formHasChanged()) {
                    e.preventDefault();
                    requestLeave(this.href);
                }
            });
        });

        window.addEventListener('beforeunload', function (e) {
            if (suppressLeaveGuard) return;
            if (!isEditing) return;
            if (!formHasChanged()) return;

            e.preventDefault();
            e.returnValue = '';
        });

        /* ============================================
         * TABS
         * ============================================ */

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
         * EDIT MODE
         * ============================================ */

        function enterEditMode() {
            if (isEditing) return;

            isEditing = true;
            if (pageRoot) pageRoot.classList.add('is-editing');

            if (editProfileBtn)     editProfileBtn.style.display = 'none';
            if (profileEditActions) profileEditActions.classList.remove('is-hidden');

            if (uploadPictureBtn) uploadPictureBtn.classList.remove('is-hidden');

            formInputs.forEach(function (input) { input.disabled = false; });

            takeFormSnapshot();
            hideSaveBanner();
        }

        function exitEditMode(opts) {
            opts = opts || {};
            var restoreValues = opts.restoreValues !== false;

            isEditing = false;
            if (pageRoot) pageRoot.classList.remove('is-editing');

            if (editProfileBtn)     editProfileBtn.style.display = '';
            if (profileEditActions) profileEditActions.classList.add('is-hidden');

            if (uploadPictureBtn) uploadPictureBtn.classList.add('is-hidden');

            if (restoreValues) {
                restoreFormSnapshot();
                clearPendingPicture();
            }

            formInputs.forEach(function (input) { input.disabled = true; });
        }

        if (editProfileBtn) {
            editProfileBtn.addEventListener('click', function () {
                openModal(confirmEditModal);
            });
        }

        if (confirmEditProceed) {
            confirmEditProceed.addEventListener('click', function () {
                closeModal(confirmEditModal);
                enterEditMode();
            });
        }

        if (cancelEditBtn) {
            cancelEditBtn.addEventListener('click', function (e) {
                e.preventDefault();
                exitEditMode({ restoreValues: true });
                hideSaveBanner();
            });
        }

        /* ============================================
         * PROFILE PICTURE — PREVIEW ONLY ON PICK
         * ============================================ */

        var ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
        var MAX_UPLOAD_BYTES    = 2 * 1024 * 1024;

        if (uploadPictureBtn && profilePictureInput) {
            uploadPictureBtn.addEventListener('click', function (e) {
                e.preventDefault();
                if (uploadPictureBtn.disabled) return;
                profilePictureInput.click();
            });

            profilePictureInput.addEventListener('change', function () {
                if (!this.files || !this.files[0]) return;

                var file = this.files[0];

                if (!file.type || ALLOWED_IMAGE_TYPES.indexOf(file.type) === -1) {
                    showSaveBanner('Unsupported file type. Use JPG, PNG, or WEBP.');
                    this.value = '';
                    return;
                }

                if (file.size > MAX_UPLOAD_BYTES) {
                    showSaveBanner('File must be under 2 MB.');
                    this.value = '';
                    return;
                }

                if (pendingPictureUrl !== '') {
                    URL.revokeObjectURL(pendingPictureUrl);
                    pendingPictureUrl = '';
                }

                pendingPictureFile = file;
                pendingPictureUrl  = URL.createObjectURL(file);
                applyAvatarSrc(pendingPictureUrl);
                hideSaveBanner();
            });
        }

        /* ============================================
         * SAVE PIPELINE
         * ============================================ */

        function runSave() {
            if (isSaving) return;
            if (!profileForm) return;
            if (!isEditing) return;

            hideSaveBanner();

            // Validate text fields.
            var ok = true;
            var firstName = document.getElementById('first_name');
            var lastName = document.getElementById('last_name');
            var contactNumber = document.getElementById('contact_number');
            var firstNameError = document.getElementById('firstNameError');
            var lastNameError = document.getElementById('lastNameError');
            var contactError = document.getElementById('contactError');

            clearFieldError(firstName, firstNameError);
            clearFieldError(lastName, lastNameError);
            clearFieldError(contactNumber, contactError);

            if (firstName && (!firstName.value.trim() || firstName.value.trim().length < 2)) {
                showFieldError(firstName, firstNameError, 'First name must be at least 2 characters.');
                ok = false;
            }
            if (lastName && (!lastName.value.trim() || lastName.value.trim().length < 2)) {
                showFieldError(lastName, lastNameError, 'Last name must be at least 2 characters.');
                ok = false;
            }
            if (contactNumber && contactNumber.value.trim() !== '') {
                if (!/^09\d{9}$/.test(contactNumber.value.trim())) {
                    showFieldError(contactNumber, contactError,
                        'Enter a valid PH mobile number (11 digits, starting with 09).');
                    ok = false;
                }
            }

            if (!ok) {
                var firstInvalid = profileForm.querySelector('.is-error');
                if (firstInvalid) firstInvalid.focus();
                return;
            }

            isSaving = true;

            if (saveProfileBtn) {
                saveProfileBtn.disabled = true;
                saveProfileBtn.textContent = 'Saving...';
            }

            var formData = new FormData(profileForm);

            fetch(UPDATE_ENDPOINT, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(parseJsonResponse)
            .then(function (data) {
                if (!data || data.status !== 'success') {
                    throw new Error((data && data.message) || 'Could not update profile.');
                }
                return data;
            })
            .then(function () {
                if (pendingPictureFile === null) {
                    return null;
                }

                var pictureData = new FormData();
                pictureData.append('csrf_token', CSRF_TOKEN);
                pictureData.append('action', 'upload_picture');
                pictureData.append('profile_picture', pendingPictureFile);

                return fetch(UPLOAD_ENDPOINT, {
                    method: 'POST',
                    body: pictureData,
                    credentials: 'same-origin'
                }).then(parseJsonResponse);
            })
            .then(function (picData) {
                if (picData && picData.status === 'error') {
                    showSaveBanner(
                        'Profile saved, but the picture could not be uploaded: ' +
                        (picData.message || 'unknown error.')
                    );
                    // Keep the admin on the page so they can see the
                    // banner and retry. The text fields did save.
                    if (saveProfileBtn) {
                        saveProfileBtn.disabled = false;
                        saveProfileBtn.textContent = 'Save Changes';
                    }
                    isSaving = false;
                    return;
                }

                if (pendingPictureUrl !== '') {
                    URL.revokeObjectURL(pendingPictureUrl);
                    pendingPictureUrl = '';
                }
                pendingPictureFile = null;
                if (profilePictureInput) profilePictureInput.value = '';

                // Everything succeeded. Reload so PHP re-renders
                // with the fresh values.
                suppressLeaveGuard = true;
                window.location.reload();
            })
            .catch(function (error) {
                var message = (error && error.message) ? error.message : 'Could not save changes.';
                showSaveBanner(message);

                if (saveProfileBtn) {
                    saveProfileBtn.disabled = false;
                    saveProfileBtn.textContent = 'Save Changes';
                }
                isSaving = false;
            });
        }

        function parseJsonResponse(response) {
            return response.text().then(function (text) {
                try {
                    return JSON.parse(text);
                } catch (parseError) {
                    // Non-JSON body: typically an HTML 404 page from
                    // the built-in dev server when the handler file is
                    // missing, or a PHP fatal-error page. Surface the
                    // HTTP status and a short body excerpt so the
                    // admin sees something actionable instead of a
                    // silent reset.
                    var snippet = text.slice(0, 120).replace(/\s+/g, ' ').trim();
                    return {
                        status: 'error',
                        message: 'Server returned a non-JSON response (' +
                                 response.status + '). ' +
                                 (snippet ? 'Response: ' + snippet : '')
                    };
                }
            });
        }

        if (saveProfileBtn) {
            saveProfileBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                runSave();
            });
        }

        if (profileForm) {
            profileForm.addEventListener('submit', function (e) {
                e.preventDefault();
                e.stopPropagation();
                runSave();
            });
        }

        /* ============================================
         * UNSAVED-CHANGES MODAL BUTTONS
         * ============================================ */

        if (unsavedStayBtn) {
            unsavedStayBtn.addEventListener('click', function () {
                closeModal(unsavedChangesModal);
                pendingLeaveHref = '';
            });
        }

        if (unsavedSaveBtn) {
            unsavedSaveBtn.addEventListener('click', function () {
                closeModal(unsavedChangesModal);
                runSave();
            });
        }

        /* ============================================
         * FIELD ERROR HELPERS
         * ============================================ */

        function showFieldError(input, errorEl, message) {
            if (input) input.classList.add('is-error');
            if (errorEl) errorEl.textContent = message;
        }

        function clearFieldError(input, errorEl) {
            if (input) input.classList.remove('is-error');
            if (errorEl) errorEl.textContent = '';
        }

        /* ============================================
         * PERSONAL INFO INPUT FILTERS
         * ============================================ */

        var contactNumberField = document.getElementById('contact_number');
        var firstNameField     = document.getElementById('first_name');
        var lastNameField      = document.getElementById('last_name');
        var firstNameErrorEl   = document.getElementById('firstNameError');
        var lastNameErrorEl    = document.getElementById('lastNameError');
        var contactErrorEl     = document.getElementById('contactError');

        if (contactNumberField) {
            contactNumberField.addEventListener('input', function () {
                var digits = this.value.replace(/\D/g, '');
                if (digits.length > 11) digits = digits.slice(0, 11);
                if (this.value !== digits) this.value = digits;
                clearFieldError(this, contactErrorEl);
            });
        }

        [[firstNameField, firstNameErrorEl], [lastNameField, lastNameErrorEl]].forEach(function (pair) {
            var input = pair[0];
            var errorEl = pair[1];
            if (!input) return;
            input.addEventListener('input', function () {
                clearFieldError(this, errorEl);
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
                icon.src = '../../shared/assets/images/icons/' + iconFile;
                icon.alt = isPassword ? 'Hide password' : 'Show password';
                btn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
                btn.setAttribute('aria-pressed', isPassword ? 'true' : 'false');
            });
        });

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

        function evaluatePasswordRules(pw) {
            return {
                'length': pw.length >= 8 && pw.length <= 20,
                'alphanumeric': /^[A-Za-z0-9]+$/.test(pw),
                'has-letter': /[A-Za-z]/.test(pw),
                'has-number': /[0-9]/.test(pw)
            };
        }

        function paintPasswordRules(pw) {
            if (!passwordRulesList) return;
            var results = evaluatePasswordRules(pw);
            passwordRulesList.querySelectorAll('.password-rule').forEach(function (li) {
                var rule = li.getAttribute('data-rule');
                var isMet = results[rule] === true;
                li.classList.toggle('is-met', isMet);
            });
        }

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
                clearFieldError(this, newPasswordError);

                if (confirmPassword && confirmPassword.value !== '') {
                    if (confirmPassword.value === this.value) {
                        clearFieldError(confirmPassword, confirmPasswordError);
                    } else {
                        showFieldError(confirmPassword, confirmPasswordError, 'Passwords do not match.');
                    }
                }
            });

            newPassword.addEventListener('blur', function () {
                if (this.value === '') return;
                var violation = firstPasswordViolation(this.value);
                if (violation !== '') {
                    showFieldError(this, newPasswordError, violation);
                }
            });
        }

        if (confirmPassword) {
            confirmPassword.addEventListener('input', function () {
                clearFieldError(this, confirmPasswordError);
                if (this.value === '') return;
                if (newPassword && this.value !== newPassword.value) {
                    showFieldError(this, confirmPasswordError, 'Passwords do not match.');
                }
            });
        }

        if (currentPassword) {
            currentPassword.addEventListener('input', function () {
                clearFieldError(this, currentPasswordError);
            });
        }

        if (passwordForm) {
            passwordForm.addEventListener('submit', function (e) {
                var ok = true;

                clearFieldError(currentPassword, currentPasswordError);
                clearFieldError(newPassword, newPasswordError);
                clearFieldError(confirmPassword, confirmPasswordError);

                if (currentPassword.value === '') {
                    showFieldError(currentPassword, currentPasswordError, 'Please enter your current password.');
                    ok = false;
                }

                var violation = firstPasswordViolation(newPassword.value);
                if (newPassword.value === '') {
                    showFieldError(newPassword, newPasswordError, 'Please enter a new password.');
                    ok = false;
                } else if (violation !== '') {
                    showFieldError(newPassword, newPasswordError, violation);
                    ok = false;
                }

                if (confirmPassword.value === '') {
                    showFieldError(confirmPassword, confirmPasswordError, 'Please confirm your new password.');
                    ok = false;
                } else if (newPassword.value !== '' && confirmPassword.value !== newPassword.value) {
                    showFieldError(confirmPassword, confirmPasswordError, 'Passwords do not match.');
                    ok = false;
                }

                if (!ok) {
                    e.preventDefault();
                    var firstInvalid = passwordForm.querySelector('.is-error');
                    if (firstInvalid) firstInvalid.focus();
                }
            });

            passwordForm.addEventListener('reset', function () {
                clearFieldError(currentPassword, currentPasswordError);
                clearFieldError(newPassword, newPasswordError);
                clearFieldError(confirmPassword, confirmPasswordError);
                paintPasswordRules('');
            });
        }

        /* ============================================
         * INITIAL PAINT
         * ============================================ */

        formInputs.forEach(function (input) { input.disabled = true; });

        if (uploadPictureBtn) {
            uploadPictureBtn.classList.add('is-hidden');
        }

        hideSaveBanner();

        if (newPassword && newPassword.value !== '') {
            paintPasswordRules(newPassword.value);
        }

    });
})();