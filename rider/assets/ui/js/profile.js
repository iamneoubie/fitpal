/**
 * FitPal Rider Profile Page JavaScript
 *
 * Responsibilities:
 *   - Tab switching (Personal | Vehicle | Address)
 *   - Edit-mode toggle with a confirmation modal
 *   - Contact-number input filter (digits only, 11 digits max)
 *   - Profile picture preview on pick, upload on Save
 *   - Form submission via fetch, JSON response
 *   - Unsaved-changes guard on Back and every outgoing link
 *   - Toast notifications
 *
 * Save pipeline
 * -------------
 * The Save button is type="button". It is bound here to runSave(),
 * which is also what the form's submit event calls. Neither path ever
 * lets the browser perform a native form submission. Both fetches use
 * literal URLs read from window.FITPAL_RIDER_PROFILE. Nothing reads
 * form.action at request time.
 *
 * Why this matters
 * ----------------
 * The previous revision used the Save button's `form="riderProfileForm"`
 * attribute to associate it with the form, and read `form.action` from
 * the form element when building the fetch URL. On a page where the
 * header and footer both attach listeners, that path could race with
 * the JS and let a native submission through, which produced a POST to
 * a URL containing the string form of a DOM node — the server log
 * showed /rider/pages/[object HTMLInputElement] as the request path.
 * Taking the button out of native submission entirely, and using a
 * literal URL for every fetch, removes both halves of that failure.
 *
 * Picture upload
 * --------------
 * Picking a file only previews it. The File object is held in
 * pendingPictureFile until Save. On Save, runSave() posts the profile
 * update first, then posts the picture as a separate FormData request
 * against the same endpoint with a different `action`. Both use the
 * same literal URL.
 *
 * Upload response
 * ---------------
 * The rider handler (rider-handler.php v6.1) returns two fields for
 * a successful picture upload:
 *
 *     { status: 'success', path: 'shared/uploads/rider/profiles/...', url: '/...' }
 *
 * `path` is the project-root-relative path stored in the database.
 * `url` is the absolute URL a browser can reach — computed server-side
 * from the difference between the project root's real filesystem path
 * and DOCUMENT_ROOT. The avatar is updated from `url`, so the JS does
 * no path arithmetic and works no matter where the project is deployed
 * under the web root.
 *
 * Fallback: if a server response ever arrives without a `url` (for
 * example, a stale handler is still running after a partial deploy),
 * the JS falls back to prefixing `path` with the configured
 * `assetBase`. That keeps the avatar usable in the deployment window
 * without the page silently breaking.
 *
 * @package FitPal
 * @version 4.2 — Avatar refresh after a successful picture upload now
 *                reads the server-supplied `url` from the upload
 *                response instead of building it from `path` +
 *                `assetBase` locally. The old concatenation remains
 *                as a fallback for a response that has `path` but no
 *                `url`, so the JS keeps working against a handler
 *                that predates the change. No other behavior
 *                changed from 4.1.
 *
 *                (4.1: Save button is type="button"; runSave() is
 *                the single submit path. Both fetches use literal
 *                URLs from the config object. 4.0: unified rider
 *                profile with customer profile — edit-confirm
 *                modal, unsaved-changes modal, Save / Cancel in
 *                header card, deferred upload. 3.0: three-tab
 *                layout. 2.0: picture upload on pick. 1.0: initial
 *                profile edit toggle.)
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ============================================
        // CONFIG
        // ============================================
        var CFG        = window.FITPAL_RIDER_PROFILE || {};
        var CSRF_TOKEN = CFG.csrfToken || '';
        var ASSET_BASE = CFG.assetBase || '../../shared/';

        // Literal endpoints. Never computed from the DOM. The PHP page
        // bootstraps these into FITPAL_RIDER_PROFILE; the hard-coded
        // fallbacks keep the script working if the bootstrap is ever
        // missing.
        var UPDATE_ENDPOINT = CFG.updateEndpoint || '../backend/handlers/rider-handler.php';
        var UPLOAD_ENDPOINT = CFG.uploadEndpoint || '../backend/handlers/rider-handler.php';

        var pageRoot = document.querySelector('.profile-page');

        // ============================================
        // DOM REFERENCES
        // ============================================
        var tabs        = document.querySelectorAll('.profile-tab');
        var tabContents = document.querySelectorAll('.profile-tab-content');

        var editBtn            = document.getElementById('editProfileBtn');
        var cancelBtn          = document.getElementById('cancelEditBtn');
        var saveBtn            = document.getElementById('saveProfileBtn');
        var profileEditActions = document.getElementById('profileEditActions');
        var form               = document.getElementById('riderProfileForm');
        var formInputs         = form ? form.querySelectorAll('input, select, textarea') : [];

        var uploadBtn     = document.getElementById('uploadPictureBtn');
        var pictureInput  = document.getElementById('profilePictureInput');
        var avatarImg     = document.getElementById('profileAvatarImg');
        var avatarInitial = document.getElementById('profileAvatarInitial');

        var confirmEditModal   = document.getElementById('confirmEditModal');
        var confirmEditProceed = document.getElementById('confirmEditProceed');

        var unsavedChangesModal = document.getElementById('unsavedChangesModal');
        var unsavedStayBtn      = document.getElementById('unsavedStayBtn');
        var unsavedSaveBtn      = document.getElementById('unsavedSaveBtn');

        var contactInput = document.getElementById('contact_number');

        // ============================================
        // STATE
        // ============================================
        var isEditing          = false;
        var isSaving           = false;
        var suppressLeaveGuard = false;
        var pendingLeaveHref   = '';

        var pendingPictureFile = null;
        var pendingPictureUrl  = '';

        var initialAvatarSrc = (typeof CFG.initialAvatarSrc === 'string')
            ? CFG.initialAvatarSrc
            : (avatarImg ? (avatarImg.getAttribute('src') || '') : '');

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

        // ============================================
        // TABS
        // ============================================
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

        // ============================================
        // AVATAR PREVIEW HELPERS
        // ============================================
        function applyAvatarSrc(url) {
            if (!avatarImg || !avatarInitial) return;

            if (url && url !== '') {
                avatarImg.src = url;
                avatarImg.style.display = 'block';
                avatarInitial.style.display = 'none';
            } else {
                avatarImg.removeAttribute('src');
                avatarImg.style.display = 'none';
                avatarInitial.style.display = 'flex';
            }
        }

        function clearPendingPicture() {
            if (pendingPictureUrl !== '') {
                URL.revokeObjectURL(pendingPictureUrl);
                pendingPictureUrl = '';
            }
            pendingPictureFile = null;
            if (pictureInput) pictureInput.value = '';
            applyAvatarSrc(initialAvatarSrc);
        }

        // ============================================
        // MODAL HELPERS
        // ============================================
        function lockBodyScroll()   { document.body.style.overflow = 'hidden'; }
        function unlockBodyScroll() { document.body.style.overflow = ''; }

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

        // ============================================
        // BACK NAVIGATION + LEAVE GUARD
        // ============================================
        var backBtn = document.getElementById('profileBackBtn');

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

        if (backBtn) {
            backBtn.addEventListener('click', function (e) {
                e.preventDefault();
                var fallback = this.getAttribute('data-fallback-href') || '';
                requestLeave(fallback);
            });
        }

        document.querySelectorAll(
            '.rider-header a[href], .mobile-nav a[href]'
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

        // ============================================
        // EDIT MODE
        // ============================================
        function enterEditMode() {
            if (isEditing) return;

            isEditing = true;
            if (pageRoot) pageRoot.classList.add('is-editing');

            if (editBtn)            editBtn.style.display = 'none';
            if (profileEditActions) profileEditActions.classList.remove('is-hidden');

            if (uploadBtn) uploadBtn.classList.remove('is-hidden');

            formInputs.forEach(function (input) { input.disabled = false; });

            takeFormSnapshot();
        }

        function exitEditMode(opts) {
            opts = opts || {};
            var restoreValues = opts.restoreValues !== false;

            isEditing = false;
            if (pageRoot) pageRoot.classList.remove('is-editing');

            if (editBtn)            editBtn.style.display = '';
            if (profileEditActions) profileEditActions.classList.add('is-hidden');

            if (uploadBtn) uploadBtn.classList.add('is-hidden');

            if (restoreValues) {
                restoreFormSnapshot();
                clearPendingPicture();
            }

            formInputs.forEach(function (input) { input.disabled = true; });
        }

        if (editBtn) {
            editBtn.addEventListener('click', function () {
                openModal(confirmEditModal);
            });
        }

        if (confirmEditProceed) {
            confirmEditProceed.addEventListener('click', function () {
                closeModal(confirmEditModal);
                enterEditMode();
            });
        }

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function (e) {
                e.preventDefault();
                exitEditMode({ restoreValues: true });
                showToast('Changes discarded.', 'success');
            });
        }

        // ============================================
        // PROFILE PICTURE — PREVIEW ONLY ON PICK
        // ============================================
        var ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
        var MAX_UPLOAD_BYTES    = 2 * 1024 * 1024;

        if (uploadBtn && pictureInput) {
            uploadBtn.addEventListener('click', function (e) {
                e.preventDefault();
                if (uploadBtn.disabled) return;
                pictureInput.click();
            });

            pictureInput.addEventListener('change', function () {
                if (!this.files || !this.files[0]) return;

                var file = this.files[0];

                if (!file.type || ALLOWED_IMAGE_TYPES.indexOf(file.type) === -1) {
                    showToast('Unsupported file type. Use JPG, PNG, or WEBP.', 'error');
                    pictureInput.value = '';
                    return;
                }

                if (file.size > MAX_UPLOAD_BYTES) {
                    showToast('File must be under 2 MB.', 'error');
                    pictureInput.value = '';
                    return;
                }

                if (pendingPictureUrl !== '') {
                    URL.revokeObjectURL(pendingPictureUrl);
                    pendingPictureUrl = '';
                }

                pendingPictureFile = file;
                pendingPictureUrl  = URL.createObjectURL(file);

                applyAvatarSrc(pendingPictureUrl);
                showToast('Picture selected. Press Save Changes to upload.', 'success');
            });
        }

        // ============================================
        // CONTACT FILTER
        // ============================================
        if (contactInput) {
            contactInput.addEventListener('input', function () {
                var cleaned = this.value.replace(/[^0-9]/g, '');
                if (cleaned.length > 11) cleaned = cleaned.slice(0, 11);
                if (this.value !== cleaned) this.value = cleaned;
            });
        }

        // ============================================
        // JSON RESPONSE HELPER
        // ============================================
        function parseJsonResponse(response) {
            return response.text().then(function (text) {
                try {
                    return JSON.parse(text);
                } catch (parseError) {
                    console.error('[rider-profile.js] Non-JSON response:',
                        '\n  url   :', response.url,
                        '\n  status:', response.status,
                        '\n  body  :', text.slice(0, 500));
                    return {
                        status: 'error',
                        message: 'Server returned a non-JSON response (' + response.status + ').'
                    };
                }
            });
        }

        /**
         * Resolve the avatar URL from a successful upload response.
         *
         * The handler (rider-handler.php v6.1) returns both `path`
         * and `url`. This function prefers `url` because it is the
         * canonical absolute URL for the current deployment, computed
         * server-side by comparing the project root to DOCUMENT_ROOT.
         *
         * If a response ever arrives with only `path` (for example,
         * a stale handler running during a partial deploy), the path
         * is prefixed with ASSET_BASE so the avatar still resolves.
         * ASSET_BASE ends with 'shared/', so trimming that suffix
         * yields the project root URL for the local concatenation.
         *
         * Returns '' when neither field is present, which causes the
         * caller to keep the current avatar rather than blank it out.
         *
         * @param {Object} data  Parsed JSON upload response
         * @returns {string}     Absolute or site-relative URL, or ''
         */
        function resolveUploadedPictureUrl(data) {
            if (!data) return '';

            if (typeof data.url === 'string' && data.url !== '') {
                return data.url;
            }

            if (typeof data.path === 'string' && data.path !== '') {
                var projectRootUrl = ASSET_BASE.replace(/shared\/$/, '');
                return projectRootUrl + data.path;
            }

            return '';
        }

        // ============================================
        // SAVE PIPELINE
        //
        // The single submit path. Bound to:
        //   - the Save button's click event (type="button")
        //   - the form's submit event (as a safety net)
        //   - the unsaved-changes modal's Save button
        //
        // Both fetches use literal URLs. Nothing reads form.action.
        // ============================================
        function runSave() {
            if (isSaving) return;
            if (!form) return;
            if (!isEditing) return;

            isSaving = true;

            if (saveBtn) {
                saveBtn.disabled = true;
                saveBtn.textContent = 'Saving...';
            }

            var profileFormData = new FormData(form);

            fetch(UPDATE_ENDPOINT, {
                method: 'POST',
                body: profileFormData,
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
                if (pendingPictureFile === null) return null;

                var pictureData = new FormData();
                pictureData.append('csrf_token', CSRF_TOKEN);
                pictureData.append('action', 'upload_picture');
                pictureData.append('profile_picture', pendingPictureFile);

                return fetch(UPLOAD_ENDPOINT, {
                    method: 'POST',
                    body: pictureData,
                    credentials: 'same-origin'
                })
                .then(parseJsonResponse)
                .then(function (picData) {
                    if (!picData || picData.status !== 'success') {
                        throw new Error(
                            (picData && picData.message) || 'Could not upload the picture.'
                        );
                    }
                    return picData;
                });
            })
            .then(function (picData) {
                if (picData) {
                    var newUrl = resolveUploadedPictureUrl(picData);
                    if (newUrl !== '') {
                        initialAvatarSrc = newUrl;
                        applyAvatarSrc(initialAvatarSrc);
                    }
                }

                if (pendingPictureUrl !== '') {
                    URL.revokeObjectURL(pendingPictureUrl);
                    pendingPictureUrl = '';
                }
                pendingPictureFile = null;
                if (pictureInput) pictureInput.value = '';

                takeFormSnapshot();
                exitEditMode({ restoreValues: false });
                showToast('Profile updated.', 'success');
            })
            .catch(function (error) {
                console.error('[rider-profile.js] Save failed:', error);
                showToast(
                    error && error.message ? error.message : 'Could not save changes.',
                    'error'
                );
            })
            .finally(function () {
                isSaving = false;
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Save Changes';
                }
            });
        }

        // Save button is type="button" — bind click directly.
        if (saveBtn) {
            saveBtn.addEventListener('click', function (e) {
                e.preventDefault();
                runSave();
            });
        }

        // Form submit is kept as a safety net. The form does not submit
        // natively (no type="submit" button targets it, novalidate is
        // set), but if the browser ever fires a submit — e.g. a stray
        // Enter keypress inside the form — this catches it and hands
        // it to the same pipeline.
        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                e.stopPropagation();
                runSave();
            });
        }

        // ============================================
        // UNSAVED-CHANGES MODAL BUTTONS
        // ============================================
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

        // ============================================
        // KEYBOARD
        // ============================================
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            if (confirmEditModal && confirmEditModal.classList.contains('active')) {
                closeModal(confirmEditModal);
            }
            if (unsavedChangesModal && unsavedChangesModal.classList.contains('active')) {
                closeModal(unsavedChangesModal);
                pendingLeaveHref = '';
            }
        });

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

            var messageEl = toast.querySelector('.rider-toast-message');
            if (messageEl) messageEl.textContent = message;

            document.body.appendChild(toast);

            setTimeout(function () {
                toast.style.animation = 'riderToastOut 0.3s ease';
                setTimeout(function () { toast.remove(); }, 300);
            }, 3000);
        }

        // ============================================
        // INITIAL SETUP
        // ============================================
        var firstActiveTab = document.querySelector('.profile-tab.active');
        if (!firstActiveTab) {
            var firstTab = document.querySelector('.profile-tab');
            if (firstTab) switchTab(firstTab.dataset.tab);
        }

        formInputs.forEach(function (input) { input.disabled = true; });

        if (uploadBtn) uploadBtn.classList.add('is-hidden');
    });
})();