/**
 * FitPal Customer Profile JavaScript
 * Version 10.0 — Every Personal-tab field is now editable except
 *                email, which stays locked as the login identifier.
 *
 *                setInputsDisabled() no longer hard-codes a
 *                never-editable list. It disables inputs by data
 *                attribute instead: an input is left permanently
 *                disabled only when it carries
 *                `data-readonly="1"`. Email is the only such field
 *                today. Every other field follows the edit-mode
 *                flag.
 *
 *                Also refreshes the dirty-check snapshot after a
 *                successful save so re-entering edit mode does not
 *                immediately show the customer's own just-saved
 *                values as "changes".
 *
 *                (9.0: adds the fitness tab save pipeline and the
 *                view/edit swap for the dietary and allergy tabs.
 *                8.0: dietary and allergy tab save pipeline. 7.1:
 *                after picture upload, build the avatar URL from the
 *                returned project-root-relative `path`.)
 *
 * @package FitPal
 * @version 10.0
 */

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {

        // ============================================
        // CONFIG
        // ============================================
        var CFG        = window.FITPAL_CUSTOMER_PROFILE || {};
        var CSRF_TOKEN = CFG.csrfToken || '';

        var UPDATE_ENDPOINT = CFG.updateEndpoint || '../backend/handlers/profile-handler.php';
        var UPLOAD_ENDPOINT = CFG.uploadEndpoint || '../backend/handlers/profile-handler.php';

        // ============================================
        // DOM ELEMENTS
        // ============================================
        var pageRoot     = document.querySelector('.profile-page');

        var tabs         = document.querySelectorAll('.profile-tab');
        var tabContents  = document.querySelectorAll('.profile-tab-content');

        var editProfileBtn     = document.getElementById('editProfileBtn');
        var profileEditActions = document.getElementById('profileEditActions');
        var cancelEditBtn      = document.getElementById('cancelEditBtn');
        var saveProfileBtn     = document.getElementById('saveProfileBtn');

        // The four editable forms.
        var profileForm = document.getElementById('profileForm');
        var dietaryForm = document.getElementById('dietaryForm');
        var allergyForm = document.getElementById('allergyForm');
        var fitnessForm = document.getElementById('fitnessForm');

        // Every input across all four forms. Used for the dirty check.
        var formInputs = [];
        [profileForm, dietaryForm, allergyForm, fitnessForm].forEach(function(form) {
            if (!form) return;
            form.querySelectorAll('input, select, textarea').forEach(function(input) {
                formInputs.push(input);
            });
        });

        // Preference summary / edit wrappers for the dietary and
        // allergy tabs.
        var dietarySummary = document.getElementById('dietarySummary');
        var allergySummary = document.getElementById('allergySummary');
        var dietaryEdit    = dietaryForm ? dietaryForm.closest('.preference-edit') : null;
        var allergyEdit    = allergyForm ? allergyForm.closest('.preference-edit') : null;

        var addAddressBtn       = document.getElementById('addAddressBtn');
        var addAddressEmptyBtn  = document.getElementById('addAddressEmptyBtn');
        var addressModal        = document.getElementById('addressModal');
        var closeAddressModal   = document.getElementById('closeAddressModal');
        var addressForm         = document.getElementById('addressForm');
        var addressModalTitle   = document.getElementById('addressModalTitle');
        var addressId           = document.getElementById('addressId');
        var saveAddressBtn      = document.getElementById('saveAddressBtn');

        var modalStep1 = document.getElementById('modalStep1');
        var modalStep2 = document.getElementById('modalStep2');
        var modalStep3 = document.getElementById('modalStep3');
        var modalSteps = [modalStep1, modalStep2, modalStep3];

        var progressSteps = document.querySelectorAll('.modal-progress .progress-step');
        var progressLines = document.querySelectorAll('.modal-progress .progress-line');
        var nextStepBtns  = document.querySelectorAll('.btn-next-step');
        var prevStepBtns  = document.querySelectorAll('.btn-prev-step');

        var deleteAddressModal = document.getElementById('deleteAddressModal');
        var cancelDeleteModal  = document.getElementById('cancelDeleteModal');
        var confirmDeleteModal = document.getElementById('confirmDeleteModal');

        var editAddressBtns   = document.querySelectorAll('.edit-address');
        var deleteAddressBtns = document.querySelectorAll('.delete-address');

        var textFieldsForNames = [
            document.getElementById('block'),
            document.getElementById('barangay'),
            document.getElementById('city'),
            document.getElementById('province'),
            document.getElementById('region')
        ];
        var postalCodeField = document.getElementById('postal_code');

        var confirmEditModal   = document.getElementById('confirmEditModal');
        var confirmEditProceed = document.getElementById('confirmEditProceed');

        var unsavedChangesModal = document.getElementById('unsavedChangesModal');
        var unsavedStayBtn      = document.getElementById('unsavedStayBtn');
        var unsavedSaveBtn      = document.getElementById('unsavedSaveBtn');

        var uploadPictureBtn     = document.getElementById('uploadPictureBtn');
        var profilePictureInput  = document.getElementById('profilePictureInput');
        var profileAvatarImg     = document.getElementById('profileAvatarImg');
        var profileAvatarInitial = document.getElementById('profileAvatarInitial');

        // ============================================
        // STATE
        // ============================================
        var isEditing        = false;
        var isSaving         = false;
        var suppressLeaveGuard = false;
        var pendingLeaveHref = '';
        var deleteAddressId  = null;
        var currentModalStep = 1;
        var totalModalSteps  = 3;

        var pendingPictureFile = null;
        var pendingPictureUrl  = '';

        var initialAvatarSrc = '';
        if (CFG && typeof CFG.initialAvatarSrc === 'string') {
            initialAvatarSrc = CFG.initialAvatarSrc;
        } else if (profileAvatarImg) {
            initialAvatarSrc = profileAvatarImg.getAttribute('src') || '';
        }

        // Snapshot of every form input's starting value.
        var formSnapshot = {};

        function takeFormSnapshot() {
            formSnapshot = {};
            formInputs.forEach(function(input, index) {
                if (!input.name) return;
                var key = input.name + '|' + index;
                if (input.type === 'checkbox' || input.type === 'radio') {
                    formSnapshot[key] = input.checked ? '1' : '0';
                } else {
                    formSnapshot[key] = input.value;
                }
            });
        }

        function formFieldsHaveChanged() {
            var changed = false;
            formInputs.forEach(function(input, index) {
                if (changed) return;
                if (!input.name) return;

                var key = input.name + '|' + index;
                if (!(key in formSnapshot)) return;

                if (input.type === 'checkbox' || input.type === 'radio') {
                    var currentChecked = input.checked ? '1' : '0';
                    if (currentChecked !== formSnapshot[key]) {
                        changed = true;
                    }
                } else {
                    if (input.value !== formSnapshot[key]) {
                        changed = true;
                    }
                }
            });
            return changed;
        }

        function formHasChanged() {
            return formFieldsHaveChanged() || pendingPictureFile !== null;
        }

        function restoreFormSnapshot() {
            formInputs.forEach(function(input, index) {
                if (!input.name) return;

                var key = input.name + '|' + index;
                if (!(key in formSnapshot)) return;

                if (input.type === 'checkbox' || input.type === 'radio') {
                    input.checked = formSnapshot[key] === '1';
                } else {
                    input.value = formSnapshot[key];
                }
            });
        }

        takeFormSnapshot();

        // ============================================
        // AVATAR PREVIEW HELPERS
        // ============================================

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
            applyAvatarSrc(initialAvatarSrc);
        }

        // ============================================
        // CSRF / ASSET RESOLUTION
        // ============================================
        function resolveCsrfToken() {
            if (typeof CSRF_TOKEN === 'string' && CSRF_TOKEN !== '') {
                return CSRF_TOKEN;
            }

            if (typeof window.FITPAL_CSRF_TOKEN === 'string' && window.FITPAL_CSRF_TOKEN !== '') {
                return window.FITPAL_CSRF_TOKEN;
            }

            if (addressForm) {
                var formInput = addressForm.querySelector('input[name="csrf_token"]');
                if (formInput && formInput.value) return formInput.value;
            }

            var fallback = document.querySelector('input[name="csrf_token"]');
            return fallback ? fallback.value : '';
        }

        function resolveAssetBase() {
            if (CFG && typeof CFG.assetBase === 'string' && CFG.assetBase !== '') {
                return CFG.assetBase;
            }
            return '../../shared/';
        }

        function buildProfilePictureUrl(storedPath) {
            if (!storedPath || typeof storedPath !== 'string') return '';

            var base = resolveAssetBase();
            if (!base) return '';

            var projectRootUrl = base.replace(/shared\/$/, '');
            return projectRootUrl + storedPath;
        }

        // ============================================
        // BODY SCROLL LOCK
        // ============================================
        function lockBodyScroll()   { document.body.style.overflow = 'hidden'; }
        function unlockBodyScroll() { document.body.style.overflow = ''; }

        // ============================================
        // MODAL HELPERS
        // ============================================
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
            setTimeout(function() {
                if (!modal.classList.contains('active')) {
                    modal.style.display = '';
                    unlockBodyScroll();
                }
            }, 0);
        }

        [confirmEditModal, unsavedChangesModal].forEach(function(modal) {
            if (!modal) return;
            modal.querySelectorAll('[data-modal-dismiss]').forEach(function(el) {
                el.addEventListener('click', function() {
                    closeModal(modal);
                });
            });
        });

        // ============================================
        // BACK NAVIGATION + LEAVE GUARD
        // ============================================
        var profileBackBtn = document.getElementById('profileBackBtn');

        function navigateAway(href) {
            suppressLeaveGuard = true;
            if (href) {
                window.location.href = href;
            } else if (window.history.length > 1 && document.referrer !== '') {
                window.history.back();
            } else {
                window.location.href = 'menu.php';
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
            profileBackBtn.addEventListener('click', function(e) {
                e.preventDefault();
                var fallback = this.getAttribute('data-fallback-href') || '';
                requestLeave(fallback);
            });
        }

        document.querySelectorAll(
            '.customer-header a[href], .mobile-nav a[href]'
        ).forEach(function(link) {
            link.addEventListener('click', function(e) {
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

        window.addEventListener('beforeunload', function(e) {
            if (suppressLeaveGuard) return;
            if (!isEditing) return;
            if (!formHasChanged()) return;

            e.preventDefault();
            e.returnValue = '';
        });

        // ============================================
        // TABS
        // ============================================
        function switchTab(tabId) {
            tabs.forEach(function(tab) {
                tab.classList.remove('active');
                if (tab.dataset.tab === tabId) tab.classList.add('active');
            });

            tabContents.forEach(function(content) {
                content.classList.remove('active');
                if (content.id === 'tab-' + tabId) content.classList.add('active');
            });
        }

        tabs.forEach(function(tab) {
            tab.addEventListener('click', function() {
                switchTab(this.dataset.tab);
            });
        });

        // ============================================
        // EDIT MODE
        //
        // setInputsDisabled() disables everything in view mode and
        // enables everything in edit mode, EXCEPT for inputs marked
        // with data-readonly="1" in the markup. Email is the only
        // such field today.
        // ============================================

        function setPreferenceEditVisibility(inEditMode) {
            [dietarySummary, allergySummary].forEach(function(summaryEl) {
                if (!summaryEl) return;
                summaryEl.classList.toggle('is-hidden', inEditMode);
            });

            [dietaryEdit, allergyEdit].forEach(function(editEl) {
                if (!editEl) return;
                editEl.classList.toggle('is-hidden', !inEditMode);
            });
        }

        function setInputsDisabled(disabled) {
            formInputs.forEach(function(input) {
                // Anything marked data-readonly="1" is permanently
                // disabled. Email is the only such field today,
                // because it doubles as the login identifier.
                if (input.dataset.readonly === '1') {
                    input.disabled = true;
                    return;
                }
                input.disabled = disabled;
            });
        }

        function enterEditMode() {
            if (isEditing) return;

            isEditing = true;
            if (pageRoot) pageRoot.classList.add('is-editing');

            if (editProfileBtn)     editProfileBtn.style.display = 'none';
            if (profileEditActions) profileEditActions.classList.remove('is-hidden');

            if (uploadPictureBtn) uploadPictureBtn.classList.remove('is-hidden');

            setInputsDisabled(false);
            setPreferenceEditVisibility(true);

            takeFormSnapshot();
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

            setInputsDisabled(true);
            setPreferenceEditVisibility(false);
        }

        if (editProfileBtn) {
            editProfileBtn.addEventListener('click', function() {
                openModal(confirmEditModal);
            });
        }

        if (confirmEditProceed) {
            confirmEditProceed.addEventListener('click', function() {
                closeModal(confirmEditModal);
                enterEditMode();
            });
        }

        if (cancelEditBtn) {
            cancelEditBtn.addEventListener('click', function(e) {
                e.preventDefault();
                exitEditMode({ restoreValues: true });
                showNotification('Changes discarded.', 'success');
            });
        }

        // ============================================
        // PROFILE PICTURE — PREVIEW ONLY ON PICK
        // ============================================
        var ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
        var MAX_UPLOAD_BYTES    = 2 * 1024 * 1024;

        if (uploadPictureBtn && profilePictureInput) {
            uploadPictureBtn.addEventListener('click', function(e) {
                e.preventDefault();
                if (uploadPictureBtn.disabled) return;
                profilePictureInput.click();
            });

            profilePictureInput.addEventListener('change', function() {
                if (!this.files || !this.files[0]) return;

                var file = this.files[0];

                if (!file.type || ALLOWED_IMAGE_TYPES.indexOf(file.type) === -1) {
                    showNotification('Unsupported file type. Use JPG, PNG, or WEBP.', 'error');
                    profilePictureInput.value = '';
                    return;
                }

                if (file.size > MAX_UPLOAD_BYTES) {
                    showNotification('File must be under 2 MB.', 'error');
                    profilePictureInput.value = '';
                    return;
                }

                if (pendingPictureUrl !== '') {
                    URL.revokeObjectURL(pendingPictureUrl);
                    pendingPictureUrl = '';
                }

                pendingPictureFile = file;
                pendingPictureUrl  = URL.createObjectURL(file);

                applyAvatarSrc(pendingPictureUrl);
                showNotification('Picture selected. Press Save Changes to upload.', 'success');
            });
        }

        // ============================================
        // SAVE PIPELINE
        //
        // Four sequential form POSTs, then an optional picture
        // upload. Each step is fully committed before the next
        // begins.
        // ============================================

        function runSave() {
            if (isSaving) return;
            if (!isEditing) return;

            isSaving = true;

            if (saveProfileBtn) {
                saveProfileBtn.disabled = true;
                saveProfileBtn.textContent = 'Saving...';
            }

            var step1 = Promise.resolve();
            if (profileForm) {
                step1 = postForm(profileForm).then(function(data) {
                    if (!data || data.status !== 'success') {
                        throw new Error((data && data.message) || 'Could not update profile.');
                    }
                    return data;
                });
            }

            var step2 = step1.then(function() {
                if (!dietaryForm) return null;
                return postForm(dietaryForm).then(function(data) {
                    if (!data || data.status !== 'success') {
                        throw new Error((data && data.message) || 'Could not update dietary preferences.');
                    }
                    return data;
                });
            });

            var step3 = step2.then(function() {
                if (!allergyForm) return null;
                return postForm(allergyForm).then(function(data) {
                    if (!data || data.status !== 'success') {
                        throw new Error((data && data.message) || 'Could not update allergies.');
                    }
                    return data;
                });
            });

            var step4 = step3.then(function() {
                if (!fitnessForm) return null;
                return postForm(fitnessForm).then(function(data) {
                    if (!data || data.status !== 'success') {
                        throw new Error((data && data.message) || 'Could not update fitness profile.');
                    }
                    return data;
                });
            });

            var step5 = step4.then(function() {
                if (pendingPictureFile === null) return null;

                var pictureData = new FormData();
                pictureData.append('csrf_token', resolveCsrfToken());
                pictureData.append('action', 'upload_picture');
                pictureData.append('profile_picture', pendingPictureFile);

                return fetch(UPLOAD_ENDPOINT, {
                    method: 'POST',
                    body: pictureData,
                    credentials: 'same-origin'
                })
                .then(parseJsonResponse)
                .then(function(picData) {
                    if (!picData || picData.status !== 'success') {
                        throw new Error(
                            (picData && picData.message) || 'Could not upload the picture.'
                        );
                    }
                    return picData;
                });
            });

            step5
                .then(function(picData) {
                    if (picData && typeof picData.path === 'string' && picData.path !== '') {
                        var resolvedUrl = buildProfilePictureUrl(picData.path);
                        if (resolvedUrl !== '') {
                            initialAvatarSrc = resolvedUrl;
                            applyAvatarSrc(initialAvatarSrc);
                        }
                    }

                    if (pendingPictureUrl !== '') {
                        URL.revokeObjectURL(pendingPictureUrl);
                        pendingPictureUrl = '';
                    }
                    pendingPictureFile = null;
                    if (profilePictureInput) profilePictureInput.value = '';

                    exitEditMode({ restoreValues: false });

                    // Refresh the snapshot so the just-saved values
                    // are the baseline for the next edit session.
                    takeFormSnapshot();

                    showNotification('Profile updated.', 'success');
                })
                .catch(function(error) {
                    console.error('[profile.js] Save failed:', error);
                    showNotification(
                        error && error.message ? error.message : 'Could not save changes.',
                        'error'
                    );
                })
                .finally(function() {
                    isSaving = false;
                    if (saveProfileBtn) {
                        saveProfileBtn.disabled = false;
                        saveProfileBtn.textContent = 'Save Changes';
                    }
                });
        }

        function postForm(formEl) {
            if (!formEl) return Promise.resolve(null);

            var formData = new FormData(formEl);

            return fetch(UPDATE_ENDPOINT, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(parseJsonResponse);
        }

        if (saveProfileBtn) {
            saveProfileBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                runSave();
            });
        }

        if (profileForm) {
            profileForm.addEventListener('submit', function(e) {
                e.preventDefault();
                e.stopPropagation();
                runSave();
            });
        }

        function parseJsonResponse(response) {
            return response.text().then(function(text) {
                try {
                    return JSON.parse(text);
                } catch (parseError) {
                    console.error('[profile.js] Non-JSON response:',
                        '\n  status:', response.status,
                        '\n  body  :', text.slice(0, 500));
                    return {
                        status: 'error',
                        message: 'Server returned a non-JSON response (' + response.status + ').'
                    };
                }
            });
        }

        // ============================================
        // UNSAVED-CHANGES MODAL BUTTONS
        // ============================================
        if (unsavedStayBtn) {
            unsavedStayBtn.addEventListener('click', function() {
                closeModal(unsavedChangesModal);
                pendingLeaveHref = '';
            });
        }

        if (unsavedSaveBtn) {
            unsavedSaveBtn.addEventListener('click', function() {
                closeModal(unsavedChangesModal);
                runSave();
            });
        }

        // ============================================
        // INPUT FILTERS
        // ============================================

        function setupNameField(input) {
            if (!input) return;

            input.addEventListener('input', function() {
                var start = this.selectionStart;
                var filtered    = this.value.replace(/[^A-Za-z\s\-']/g, '');
                var capitalized = filtered.replace(/\b\w/g, function(ch) {
                    return ch.toUpperCase();
                });

                if (this.value !== capitalized) {
                    this.value = capitalized;
                    var newStart = Math.min(start, this.value.length);
                    this.setSelectionRange(newStart, newStart);
                }
            });
        }

        function setupDigitsOnlyField(input) {
            if (!input) return;

            input.addEventListener('input', function() {
                var cleaned = this.value.replace(/[^0-9]/g, '');
                if (this.value !== cleaned) this.value = cleaned;
            });
        }

        // Apply the name filter to the personal form's name fields
        // now that they are editable.
        [
            document.getElementById('first_name'),
            document.getElementById('middle_name'),
            document.getElementById('last_name')
        ].forEach(setupNameField);

        textFieldsForNames.forEach(setupNameField);
        setupDigitsOnlyField(postalCodeField);

        // Contact number: digits only.
        var contactField = document.getElementById('contact_number');
        if (contactField) {
            contactField.addEventListener('input', function() {
                var cleaned = this.value.replace(/[^0-9]/g, '').slice(0, 11);
                if (this.value !== cleaned) this.value = cleaned;
            });
        }

        // ============================================
        // MODAL STEP NAVIGATION (address)
        // ============================================
        function goToModalStep(step) {
            if (step > currentModalStep && !validateModalStep(currentModalStep)) return;

            currentModalStep = step;

            modalSteps.forEach(function(el, index) {
                if (!el) return;
                var stepNumber = index + 1;
                if (stepNumber === step) {
                    el.classList.add('active');
                    el.style.display = 'block';
                } else {
                    el.classList.remove('active');
                    el.style.display = 'none';
                }
            });

            progressSteps.forEach(function(el, index) {
                var n = index + 1;
                el.classList.remove('active', 'completed');
                if (n === step) el.classList.add('active');
                else if (n < step) el.classList.add('completed');
            });

            progressLines.forEach(function(el, index) {
                el.classList.remove('completed');
                if (index + 1 < step) el.classList.add('completed');
            });

            if (step === 3) updateAddressPreview();

            var currentStepEl = document.getElementById('modalStep' + step);
            if (currentStepEl) {
                var firstInput = currentStepEl.querySelector('input, select');
                if (firstInput) setTimeout(function() { firstInput.focus(); }, 100);
            }
        }

        function validateModalStep(step) {
            if (step === 1) {
                var block = document.getElementById('block');
                if (!block || !block.value.trim()) {
                    showNotification('Block/Street is required.', 'error');
                    if (block) block.focus();
                    return false;
                }
            }
            if (step === 2) {
                var city = document.getElementById('city');
                if (!city || !city.value.trim()) {
                    showNotification('City is required.', 'error');
                    if (city) city.focus();
                    return false;
                }
            }
            return true;
        }

        function updateAddressPreview() {
            var previewText = document.getElementById('addressPreviewText');
            if (!previewText) return;

            var getVal = function(id) {
                var el = document.getElementById(id);
                return el ? el.value : '';
            };

            var label      = getVal('address_label');
            var block      = getVal('block');
            var barangay   = getVal('barangay');
            var city       = getVal('city');
            var province   = getVal('province');
            var region     = getVal('region');
            var postalCode = getVal('postal_code');
            var country    = getVal('country') || 'Philippines';

            var parts = [];
            if (block)      parts.push(block);
            if (barangay)   parts.push(barangay);
            if (city)       parts.push(city);
            if (province)   parts.push(province);
            if (region)     parts.push(region);
            if (postalCode) parts.push(postalCode);
            if (country)    parts.push(country);

            if (parts.length > 0) {
                var labelText = label ? '[' + label + '] ' : '';
                previewText.textContent = labelText + parts.join(', ');
            } else {
                previewText.textContent = 'Fill in all fields to see preview';
            }
        }

        nextStepBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var nextStep = parseInt(this.dataset.next, 10);
                if (nextStep <= totalModalSteps) goToModalStep(nextStep);
            });
        });

        prevStepBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var prevStep = parseInt(this.dataset.prev, 10);
                if (prevStep >= 1) goToModalStep(prevStep);
            });
        });

        // ============================================
        // ADDRESS MODAL OPEN/CLOSE
        // ============================================
        function openAddressModal(title, addressData) {
            addressData = addressData || null;
            if (addressModalTitle) addressModalTitle.textContent = title;

            goToModalStep(1);

            if (addressData && addressData.customer_address_id) {
                if (addressId) addressId.value = addressData.customer_address_id || '';

                setFieldValue('address_label', addressData.label       || '');
                setFieldValue('block',         addressData.block       || '');
                setFieldValue('barangay',      addressData.barangay    || '');
                setFieldValue('city',          addressData.city        || '');
                setFieldValue('province',      addressData.province    || '');
                setFieldValue('region',        addressData.region      || '');
                setFieldValue('postal_code',   addressData.postal_code || '');
                setFieldValue('country',       addressData.country     || 'Philippines');

                if (addressForm) {
                    var actionInput = addressForm.querySelector('input[name="action"]');
                    if (actionInput) actionInput.value = 'update_address';
                }
            } else {
                if (addressForm) addressForm.reset();
                setFieldValue('country', 'Philippines');
                if (addressForm) {
                    var actionInput2 = addressForm.querySelector('input[name="action"]');
                    if (actionInput2) actionInput2.value = 'add_address';
                }
            }

            openModal(addressModal);
            setTimeout(updateAddressPreview, 100);
        }

        function setFieldValue(id, value) {
            var el = document.getElementById(id);
            if (el) el.value = value;
        }

        function closeAddressModalHandler() {
            closeModal(addressModal);
            if (addressForm) addressForm.reset();
            setFieldValue('country', 'Philippines');
            goToModalStep(1);
        }

        function openAddAddressFlow() {
            openAddressModal('Add New Address');
        }

        if (addAddressBtn) {
            addAddressBtn.addEventListener('click', function(e) {
                e.preventDefault();
                openAddAddressFlow();
            });
        }

        if (addAddressEmptyBtn) {
            addAddressEmptyBtn.addEventListener('click', function(e) {
                e.preventDefault();
                openAddAddressFlow();
            });
        }

        if (closeAddressModal) {
            closeAddressModal.addEventListener('click', closeAddressModalHandler);
        }

        if (addressModal) {
            addressModal.addEventListener('click', function(e) {
                if (e.target === this) closeAddressModalHandler();
            });
        }

        document.addEventListener('keydown', function(e) {
            if (e.key !== 'Escape') return;

            if (addressModal && addressModal.classList.contains('active')) {
                closeAddressModalHandler();
            }
            if (deleteAddressModal && deleteAddressModal.classList.contains('active')) {
                closeDeleteModal();
            }
            if (confirmEditModal && confirmEditModal.classList.contains('active')) {
                closeModal(confirmEditModal);
            }
            if (unsavedChangesModal && unsavedChangesModal.classList.contains('active')) {
                closeModal(unsavedChangesModal);
                pendingLeaveHref = '';
            }
        });

        document.querySelectorAll('#addressForm .form-control').forEach(function(input) {
            input.addEventListener('input', function() {
                if (currentModalStep === 3) updateAddressPreview();
            });
            input.addEventListener('change', function() {
                if (currentModalStep === 3) updateAddressPreview();
            });
        });

        // ============================================
        // EDIT ADDRESS
        // ============================================
        editAddressBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id = this.dataset.id;
                openAddressModal('Edit Address', { customer_address_id: id });
            });
        });

        // ============================================
        // DELETE ADDRESS
        // ============================================
        function openDeleteModal(addressIdValue) {
            deleteAddressId = addressIdValue;
            openModal(deleteAddressModal);
        }

        function closeDeleteModal() {
            closeModal(deleteAddressModal);
            deleteAddressId = null;
        }

        deleteAddressBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (this.disabled) return;
                openDeleteModal(this.dataset.id);
            });
        });

        if (cancelDeleteModal) {
            cancelDeleteModal.addEventListener('click', closeDeleteModal);
        }

        if (deleteAddressModal) {
            deleteAddressModal.addEventListener('click', function(e) {
                if (e.target === this) closeDeleteModal();
            });
        }

        if (confirmDeleteModal) {
            confirmDeleteModal.addEventListener('click', function() {
                if (!deleteAddressId) return;

                var formData = new FormData();
                formData.append('csrf_token', resolveCsrfToken());
                formData.append('action', 'delete_address');
                formData.append('address_id', deleteAddressId);

                fetch('../backend/handlers/address-handler.php', {
                    method: 'POST',
                    body: formData
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.status === 'success') {
                        showNotification('Address deleted successfully', 'success');

                        if (window.location.hash) {
                            history.replaceState(
                                null, '',
                                window.location.pathname + window.location.search
                            );
                        }

                        setTimeout(function() { window.location.reload(); }, 1000);
                    } else {
                        showNotification(data.message || 'Failed to delete address', 'error');
                    }
                })
                .catch(function(error) {
                    console.error('Error:', error);
                    showNotification('Network error. Please try again.', 'error');
                })
                .finally(function() { closeDeleteModal(); });
            });
        }

        // ============================================
        // ADDRESS FORM SUBMISSION
        // ============================================
        if (addressForm) {
            addressForm.addEventListener('submit', function(e) {
                e.preventDefault();

                var block = document.getElementById('block');
                var city  = document.getElementById('city');

                if (!block || !block.value.trim()) {
                    showNotification('Block/Street is required.', 'error');
                    goToModalStep(1);
                    if (block) block.focus();
                    return;
                }
                if (!city || !city.value.trim()) {
                    showNotification('City is required.', 'error');
                    goToModalStep(2);
                    if (city) city.focus();
                    return;
                }

                var formData = new FormData(this);

                if (saveAddressBtn) {
                    saveAddressBtn.disabled = true;
                    saveAddressBtn.textContent = 'Saving...';
                }

                fetch('../backend/handlers/address-handler.php', {
                    method: 'POST',
                    body: formData
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.status === 'success') {
                        showNotification('Address saved successfully', 'success');
                        closeAddressModalHandler();

                        if (window.location.hash) {
                            history.replaceState(
                                null, '',
                                window.location.pathname + window.location.search
                            );
                        }

                        setTimeout(function() { window.location.reload(); }, 1000);
                    } else {
                        showNotification(data.message || 'Failed to save address', 'error');
                    }
                })
                .catch(function(error) {
                    console.error('Error:', error);
                    showNotification('Network error. Please try again.', 'error');
                })
                .finally(function() {
                    if (saveAddressBtn) {
                        saveAddressBtn.disabled = false;
                        saveAddressBtn.textContent = 'Save Address';
                    }
                });
            });
        }

        // ============================================
        // NOTIFICATION SYSTEM
        // ============================================
        function showNotification(message, type) {
            var existing = document.querySelector('.profile-notification');
            if (existing) existing.remove();

            var notification = document.createElement('div');
            notification.className = 'profile-notification ' + type;
            notification.setAttribute('role', 'alert');

            var iconFile = type === 'success' ? 'verified-fill.svg' : 'file-warning-fill.svg';

            var iconSpan = document.createElement('span');
            iconSpan.className = 'notification-icon';

            var iconImg = document.createElement('img');
            iconImg.src = resolveAssetBase() + 'assets/images/icons/' + iconFile;
            iconImg.alt = type;

            iconSpan.appendChild(iconImg);

            var messageSpan = document.createElement('span');
            messageSpan.className = 'notification-message';
            messageSpan.textContent = message;

            notification.appendChild(iconSpan);
            notification.appendChild(messageSpan);
            document.body.appendChild(notification);

            setTimeout(function() {
                notification.style.animation = 'fadeOut 0.3s ease';
                setTimeout(function() { notification.remove(); }, 300);
            }, 3000);
        }

        // ============================================
        // INITIAL SETUP
        // ============================================
        var firstActiveTab = document.querySelector('.profile-tab.active');
        if (!firstActiveTab) {
            var firstTab = document.querySelector('.profile-tab');
            if (firstTab) {
                firstTab.classList.add('active');
                var firstTabId = firstTab.dataset.tab;
                var firstContent = document.getElementById('tab-' + firstTabId);
                if (firstContent) firstContent.classList.add('active');
            }
        }

        if (modalStep1) {
            modalStep1.classList.add('active');
            modalStep1.style.display = 'block';
        }
        if (modalStep2) modalStep2.style.display = 'none';
        if (modalStep3) modalStep3.style.display = 'none';

        setInputsDisabled(true);
        setPreferenceEditVisibility(false);

        if (uploadPictureBtn) {
            uploadPictureBtn.classList.add('is-hidden');
        }

        // ============================================
        // DEEP-LINK HANDLING (consumed once)
        // ============================================
        (function consumeDeepLink() {
            if (!window.location.hash) return;

            var hash = window.location.hash.toLowerCase().replace(/^#/, '');

            var isAddressesHash =
                hash === 'addresses' ||
                hash === 'add-address' ||
                hash === 'addaddress' ||
                hash === 'edit-address' ||
                hash === 'editaddress';

            var isDietaryHash =
                hash === 'dietary' ||
                hash === 'diet';

            var isAllergiesHash =
                hash === 'allergies' ||
                hash === 'allergy';

            var isFitnessHash =
                hash === 'fitness' ||
                hash === 'goal';

            if (!isAddressesHash && !isDietaryHash && !isAllergiesHash && !isFitnessHash) return;

            history.replaceState(
                null, '',
                window.location.pathname + window.location.search
            );

            if (isAddressesHash) {
                var addressesTab = document.getElementById('tabBtnAddresses');
                if (addressesTab) addressesTab.click();
                else {
                    var fallback = document.querySelector('.profile-tab[data-tab="addresses"]');
                    if (fallback) fallback.click();
                }

                if (hash === 'add-address' || hash === 'addaddress') {
                    setTimeout(function() {
                        openAddAddressFlow();
                    }, 250);
                }
            } else if (isDietaryHash) {
                var dietaryTab = document.getElementById('tabBtnDietary');
                if (dietaryTab) dietaryTab.click();
            } else if (isAllergiesHash) {
                var allergyTab = document.getElementById('tabBtnAllergies');
                if (allergyTab) allergyTab.click();
            } else if (isFitnessHash) {
                var fitnessTab = document.getElementById('tabBtnFitness');
                if (fitnessTab) fitnessTab.click();
            }
        })();
    });
})();