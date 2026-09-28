/**
 * FitPal Customer Profile JavaScript
 * Version 7.1 — After a successful picture upload, build the avatar
 *                URL from the returned project-root-relative `path`
 *                and the page's own `assetBase`, instead of using the
 *                handler's resolved `url`. The handler's URL is
 *                computed from $_SERVER['SCRIPT_NAME'] arithmetic and
 *                resolves to /customer/shared/uploads/... when the
 *                browser applies it relative to the current page,
 *                which 404s and causes the avatar to fall back to the
 *                initial letter. Reloading the page worked because
 *                profile.php computes the URL itself from $assetBase,
 *                which is the source of truth. This revision does the
 *                same thing on the client.
 *
 *                All other behavior is unchanged from v7.0.
 *
 * @package FitPal
 * @version 7.1
 */

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {

        // ============================================
        // CONFIG
        // ============================================
        var CFG        = window.FITPAL_CUSTOMER_PROFILE || {};
        var CSRF_TOKEN = CFG.csrfToken || '';

        // Literal endpoints. Never computed from the DOM. The PHP page
        // bootstraps these into FITPAL_CUSTOMER_PROFILE; the hard-coded
        // fallbacks keep the script working if the bootstrap is ever
        // missing.
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
        var profileForm        = document.getElementById('profileForm');
        var formInputs         = profileForm ? profileForm.querySelectorAll('input, select, textarea') : [];

        var addAddressBtn       = document.getElementById('addAddressBtn');
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

        // Avatar elements
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

        // Pending picture: a File object the customer has chosen but
        // not yet saved, plus the blob URL used for the local preview.
        // Cleared on save success, on cancel, and on rollback.
        var pendingPictureFile = null;
        var pendingPictureUrl  = '';

        // The URL of the picture the server rendered on page load.
        // Used as the rollback target when Cancel is pressed after a
        // preview. Falls back to a straight read of the current <img>
        // if the bootstrap object is missing.
        var initialAvatarSrc = '';
        if (CFG
            && typeof CFG.initialAvatarSrc === 'string') {
            initialAvatarSrc = CFG.initialAvatarSrc;
        } else if (profileAvatarImg) {
            initialAvatarSrc = profileAvatarImg.getAttribute('src') || '';
        }

        // Snapshot of the form's starting values.
        var formSnapshot = {};

        function takeFormSnapshot() {
            formSnapshot = {};
            formInputs.forEach(function(input) {
                if (!input.name) return;
                formSnapshot[input.name] = input.value;
            });
        }

        function formFieldsHaveChanged() {
            var changed = false;
            formInputs.forEach(function(input) {
                if (!input.name || changed) return;
                if (!(input.name in formSnapshot)) return;
                if (input.value !== formSnapshot[input.name]) {
                    changed = true;
                }
            });
            return changed;
        }

        // The unsaved-changes guard fires if EITHER the fields changed
        // OR a new picture is pending.
        function formHasChanged() {
            return formFieldsHaveChanged() || pendingPictureFile !== null;
        }

        function restoreFormSnapshot() {
            formInputs.forEach(function(input) {
                if (!input.name) return;
                if (!(input.name in formSnapshot)) return;
                input.value = formSnapshot[input.name];
            });
        }

        takeFormSnapshot();

        // ============================================
        // AVATAR PREVIEW HELPERS
        // ============================================

        /**
         * Apply a URL to the avatar image. When the URL is empty, the
         * image is hidden and the initial placeholder is shown.
         */
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

        /**
         * Clear any pending picture and revert the avatar to the
         * server-rendered state.
         */
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

        /**
         * Return the asset base the page was rendered with. Always
         * ends with 'shared/'. E.g. '../../shared/'.
         */
        function resolveAssetBase() {
            if (CFG
                && typeof CFG.assetBase === 'string'
                && CFG.assetBase !== '') {
                return CFG.assetBase;
            }
            return '../../shared/';
        }

        /**
         * Build a browser-loadable URL for a project-root-relative
         * path stored in the DB (e.g. 'shared/uploads/customer-
         * profiles/customer_1_xxx.jpg').
         *
         * The page renders with an $assetBase that ends in 'shared/'.
         * Trimming that suffix yields the project root URL, which the
         * stored path can be appended to directly. This mirrors
         * exactly what customer/pages/profile.php does on first
         * render, so a URL built here is identical to the one the
         * server would compute on the next page load.
         *
         * Returns '' when the path is empty or the asset base cannot
         * be resolved.
         */
        function buildProfilePictureUrl(storedPath) {
            if (!storedPath || typeof storedPath !== 'string') return '';

            var base = resolveAssetBase();
            if (!base) return '';

            // Strip the trailing 'shared/' so the base becomes the
            // project root URL.
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
        // ============================================

        function enterEditMode() {
            if (isEditing) return;

            isEditing = true;
            if (pageRoot) pageRoot.classList.add('is-editing');

            if (editProfileBtn)     editProfileBtn.style.display = 'none';
            if (profileEditActions) profileEditActions.classList.remove('is-hidden');

            // Reveal the avatar edit button now that edits are allowed.
            if (uploadPictureBtn) uploadPictureBtn.classList.remove('is-hidden');

            formInputs.forEach(function(input) { input.disabled = false; });

            takeFormSnapshot();
        }

        function exitEditMode(opts) {
            opts = opts || {};
            var restoreValues = opts.restoreValues !== false;

            isEditing = false;
            if (pageRoot) pageRoot.classList.remove('is-editing');

            if (editProfileBtn)     editProfileBtn.style.display = '';
            if (profileEditActions) profileEditActions.classList.add('is-hidden');

            // Hide the avatar edit button again.
            if (uploadPictureBtn) uploadPictureBtn.classList.add('is-hidden');

            if (restoreValues) {
                restoreFormSnapshot();
                clearPendingPicture();
            }

            formInputs.forEach(function(input) { input.disabled = true; });
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

        // Any field change updates nothing on its own — the dirty
        // check reads the DOM directly on demand. This listener only
        // exists so a future "live dirty indicator" has a hook.
        formInputs.forEach(function(input) {
            input.addEventListener('input', function() {});
            input.addEventListener('change', function() {});
        });

        // ============================================
        // PROFILE PICTURE — PREVIEW ONLY ON PICK
        //
        // Picking a file does NOT fire a network request. It only
        // captures the File object and shows a local preview. The
        // actual upload happens in the save pipeline below.
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

                // Discard any earlier pending pick first.
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
        // The single entry point for both the Save Changes button and
        // the unsaved-changes modal's Save button. Both fetches use
        // LITERAL URLs read from the config object. Nothing reads
        // profileForm.action.
        //
        // Two sequential steps:
        //   1. POST to UPDATE_ENDPOINT with action=update_profile
        //      (FormData built from the form).
        //   2. If a pending picture exists, POST to UPLOAD_ENDPOINT
        //      with action=upload_picture as a separate request.
        //
        // Step 2 only runs when step 1 succeeded.
        //
        // On both-step success, the avatar URL is rebuilt from the
        // returned project-root-relative `path` using the page's own
        // assetBase, NOT from the handler's resolved `url`. The
        // handler's URL is computed from $_SERVER['SCRIPT_NAME'] and
        // resolves relative to the current page, which prefixes it
        // with /customer/ and 404s. Reloading the page worked because
        // profile.php computes the URL itself from $assetBase — this
        // revision matches that behavior on the client so no reload
        // is needed.
        // ============================================

        function runSave() {
            if (isSaving) return;
            if (!profileForm) return;
            if (!isEditing) return;

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
            .then(function(data) {
                if (!data || data.status !== 'success') {
                    throw new Error((data && data.message) || 'Could not update profile.');
                }
                return data;
            })
            .then(function() {
                // Step 2: upload the pending picture, if any.
                if (pendingPictureFile === null) {
                    return null;
                }

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
            })
            .then(function(picData) {
                // Both steps succeeded. If the upload returned a
                // project-root-relative path, build the browser URL
                // from it using the page's own assetBase. This is the
                // same computation profile.php performs on initial
                // render, so the URL is stable across reloads.
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

                takeFormSnapshot();
                exitEditMode({ restoreValues: false });
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

        // Save button is type="button" — bind click directly. This is
        // the single entry point into runSave().
        if (saveProfileBtn) {
            saveProfileBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                runSave();
            });
        }

        // Form submit is kept as a safety net. The form no longer has
        // a type="submit" button targeting it, so a native submission
        // can only fire from an implicit submit (e.g., Enter key in a
        // field). That path still routes through runSave() and never
        // navigates.
        if (profileForm) {
            profileForm.addEventListener('submit', function(e) {
                e.preventDefault();
                e.stopPropagation();
                runSave();
            });
        }

        /**
         * Read a fetch response and parse it as JSON. On a non-JSON
         * body, resolves to an error object shaped like the server's
         * own error responses so the caller's checks stay uniform.
         */
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

            input.addEventListener('blur', function() {
                if (this.value.length > 0) {
                    var capitalized = this.value.replace(/\b\w/g, function(ch) {
                        return ch.toUpperCase();
                    });
                    if (this.value !== capitalized) this.value = capitalized;
                }
            });
        }

        function setupDigitsOnlyField(input) {
            if (!input) return;

            input.addEventListener('input', function() {
                var cleaned = this.value.replace(/[^0-9]/g, '');
                if (this.value !== cleaned) this.value = cleaned;
            });

            input.addEventListener('paste', function(e) {
                var paste = (e.clipboardData || window.clipboardData).getData('text');
                if (!/^[0-9]+$/.test(paste)) {
                    e.preventDefault();
                    var cleaned = paste.replace(/[^0-9]/g, '');
                    if (cleaned) document.execCommand('insertText', false, cleaned);
                }
            });
        }

        textFieldsForNames.forEach(setupNameField);
        setupDigitsOnlyField(postalCodeField);

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

        if (addAddressBtn) {
            addAddressBtn.addEventListener('click', function(e) {
                e.preventDefault();
                openAddressModal('Add New Address');
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

        formInputs.forEach(function(input) { input.disabled = true; });

        // The avatar edit button ships with .is-hidden in the markup;
        // confirm it is set on load in case a browser strips it.
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

            if (!isAddressesHash) return;

            history.replaceState(
                null, '',
                window.location.pathname + window.location.search
            );

            var addressesTab = document.getElementById('tabBtnAddresses');
            if (addressesTab) addressesTab.click();
            else {
                var fallback = document.querySelector('.profile-tab[data-tab="addresses"]');
                if (fallback) fallback.click();
            }

            if (hash === 'add-address' || hash === 'addaddress') {
                setTimeout(function() {
                    var btn = document.getElementById('addAddressBtn');
                    if (btn) btn.click();
                }, 250);
            }
        })();
    });
})();s