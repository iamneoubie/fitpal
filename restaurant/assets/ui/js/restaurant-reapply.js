/**
 * FitPal Restaurant Re-Application JavaScript
 *
 * Five-step wizard driven by restaurant/pages/restaurant-reapply.php.
 *
 * ---------------------------------------------------------------------
 * WHAT THIS FILE OWNS
 * ---------------------------------------------------------------------
 *   1. Step navigation.
 *   2. Field capitalization for `.name-field` inputs.
 *   3. Address filtering for `.address-field` inputs.
 *   4. Digit-only filtering for `.digits-field` inputs.
 *   5. Username filtering for `.username-field`.
 *   6. Email lowercasing for `.email-field`.
 *   7. First-letter capitalization for `.capitalize-field` inputs.
 *   8. Permit slot state machine.
 *   9. Review summary on step 5.
 *  10. Submit.
 *
 * ---------------------------------------------------------------------
 * FILTER CLASSES
 * ---------------------------------------------------------------------
 *   .name-field       letters, spaces, hyphens, apostrophes only;
 *                     each word capitalized. Used for personal
 *                     names and business / branch names, where
 *                     digits are not expected.
 *
 *   .address-field    letters, digits, spaces, commas, periods,
 *                     hyphens, apostrophes, slashes, hash. Each
 *                     word capitalized. Used for block / street /
 *                     unit and barangay, where digits are required
 *                     (house numbers, unit numbers, barangay
 *                     numbers).
 *
 *   .digits-field     digits and spaces only. Used for contact
 *                     number and postal code.
 *
 *   .username-field   letters, numbers, underscore only.
 *
 *   .email-field      lowercased.
 *
 *   .capitalize-field first letter of first word only, no
 *                     character restriction. Used for Business
 *                     Description.
 *
 * ---------------------------------------------------------------------
 * WHY .address-field EXISTS
 * ---------------------------------------------------------------------
 * Before this revision, the Block / Street / Unit field carried
 * .name-field. The .name-field filter is:
 *
 *     this.value.replace(/[^A-Za-z\s\-']/g, '')
 *
 * Every input event stripped every digit. A user typing
 * "12-A Sunrise St., Unit 5B" saw only "A Sunrise St., Unit B".
 * The placeholder in the markup advertised a value the JS would
 * not let them type.
 *
 * .address-field uses a wider allowed set and a gentler
 * capitalization rule so house numbers and unit numbers survive.
 *
 * ---------------------------------------------------------------------
 * SUBMIT HANDLER — WHY STEP 1 WAS THE FALLBACK
 * ---------------------------------------------------------------------
 * The submit handler walks each step in order and remembers the
 * FIRST step that fails. validateStep() returns the first failing
 * field's name (or null when the step passes). The submit handler
 * focuses and scrolls to the failing field, logs it to the console,
 * and shows a banner naming the step and field.
 *
 * ---------------------------------------------------------------------
 * FIRST-LETTER CAPITALIZATION — .capitalize-field
 * ---------------------------------------------------------------------
 * Every input or textarea carrying `.capitalize-field` is wired to
 * capitalizeField(). The helper uppercases the first letter of the
 * first word on input, preserving the caret position.
 *
 * ---------------------------------------------------------------------
 * THE SLOT-1 "DIDN'T UPDATE" BUG
 * ---------------------------------------------------------------------
 * clearLocalFile() must not touch the DOM's file input when it is
 * called from handleFileChosen() as the first step of a
 * replacement. The discard path (the remove button on a slot with a
 * pending local file) passes true and the DOM's file input is
 * cleared along with the JS state. The change handler writes the
 * chosen File back into the DOM via a DataTransfer.
 *
 * ---------------------------------------------------------------------
 * BOOTSTRAP
 * ---------------------------------------------------------------------
 * The script is loaded with `defer`, so a DOMContentLoaded listener
 * attached here would never fire. The readyState guard below is
 * correct whether the script is deferred, async, or plain.
 *
 * @package FitPal
 * @version 9.0 — Introduces .address-field. Block / Street / Unit
 *                and Barangay now accept digits, commas, periods,
 *                slashes, and hash. Their validators no longer
 *                apply NAME_PATTERN.
 *
 *                (8.0: submit handler names the failing field.
 *                7.0: business_description, dietary_tags,
 *                barangay, province, region, postal_code required.
 *                6.0: clearLocalFile fix. 5.0: name/digits/username/
 *                email filters. 2.0: five-step wizard.)
 */

(function () {
    'use strict';

    function boot() {
        var page = document.getElementById('restaurantReapplyPage');
        var form = document.getElementById('restaurantReapplyForm');

        if (!page || !form) {
            return;
        }

        var TOTAL_STEPS = parseInt(page.dataset.totalSteps || '5', 10) || 5;

        var HANDLER_URL = page.dataset.handlerUrl
            || '../backend/handlers/restaurant-reapply-handler.php';
        var CSRF_TOKEN = page.dataset.csrfToken || '';
        var ASSET_BASE = page.dataset.assetBase || '../../shared/';

        var ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];
        var MAX_BYTES = 5 * 1024 * 1024;

        var STEP_TITLES = {
            1: 'Step 1 of 5 — Personal Information',
            2: 'Step 2 of 5 — Business Information',
            3: 'Step 3 of 5 — Branch Information',
            4: 'Step 4 of 5 — Permits & Documents',
            5: 'Step 5 of 5 — Review & Submit'
        };

        /* ============================================
           ELEMENT REFERENCES
           ============================================ */

        var steps = form.querySelectorAll('.register-step');
        var progressSteps = document.querySelectorAll('.progress-step');
        var progressLines = document.querySelectorAll('.progress-line');
        var stepSubtitle = document.getElementById('stepSubtitle');
        var currentStepInput = document.getElementById('currentStep');

        var nextButtons = form.querySelectorAll('.btn-next');
        var prevButtons = form.querySelectorAll('.btn-prev');

        var submitBtn = document.getElementById('submitBtn');
        var submitLabel = document.getElementById('submitBtnLabel');

        var banner = document.getElementById('registerError');
        var bannerMessage = document.getElementById('errorMessage');

        var permitGrid = document.getElementById('permitSlotGrid');
        var permitCountEl = document.getElementById('permitFooterCount');
        var permitsError = document.getElementById('permitsError');

        var notifier = document.getElementById('reapplyNotifier');
        var notifierTitle = document.getElementById('reapplyNotifierTitle');
        var notifierMessage = document.getElementById('reapplyNotifierMessage');
        var notifierBtn = document.getElementById('reapplyNotifierBtn');

        var currentStep = 1;
        var isSubmitting = false;

        /* ============================================
           FIELD REGISTRY
           ============================================ */

        var fieldIds = [
            'first_name',
            'middle_name',
            'last_name',
            'email',
            'contact_number',
            'username',
            'business_name',
            'cuisine_type',
            'business_description',
            'dietary_tags',
            'branch_name',
            'block',
            'barangay',
            'city',
            'province',
            'region',
            'postal_code'
        ];

        var fieldStep = {
            first_name: 1,
            middle_name: 1,
            last_name: 1,
            email: 1,
            contact_number: 1,
            username: 1,
            business_name: 2,
            cuisine_type: 2,
            business_description: 2,
            dietary_tags: 2,
            branch_name: 3,
            block: 3,
            barangay: 3,
            city: 3,
            province: 3,
            region: 3,
            postal_code: 3,
            permits: 4
        };

        var fieldLabels = {
            first_name: 'First name',
            middle_name: 'Middle name',
            last_name: 'Last name',
            email: 'Email address',
            contact_number: 'Contact number',
            username: 'Username',
            business_name: 'Business name',
            cuisine_type: 'Cuisine type',
            business_description: 'Business description',
            dietary_tags: 'Dietary tags',
            branch_name: 'Branch name',
            block: 'Block / Street / Unit',
            barangay: 'Barangay',
            city: 'City / Municipality',
            province: 'Province',
            region: 'Region',
            postal_code: 'Postal code',
            permits: 'Permits'
        };

        function errorElementId(postName) {
            var el = document.getElementById(postName + 'Error');
            if (el) {
                return postName + 'Error';
            }
            var camel = postName.replace(/_([a-z])/g, function (m, c) {
                return c.toUpperCase();
            });
            return camel + 'Error';
        }

        function fieldElement(postName) {
            return document.getElementById(postName);
        }

        function errorElement(postName) {
            var id = errorElementId(postName);
            return document.getElementById(id);
        }

        /* ============================================
           BANNER + NOTIFIER
           ============================================ */

        function showBanner(message) {
            if (!banner || !bannerMessage) return;
            bannerMessage.textContent = message;
            banner.style.display = 'block';
        }

        function hideBanner() {
            if (!banner) return;
            banner.style.display = 'none';
        }

        function showNotifier(title, message, redirectUrl) {
            if (notifierTitle) notifierTitle.textContent = title || 'Notice';
            if (notifierMessage) notifierMessage.textContent = message || '';
            if (notifier) notifier.classList.remove('hidden');
            if (notifierBtn) notifierBtn._redirect = redirectUrl || '';
        }

        function closeNotifier() {
            if (notifier) notifier.classList.add('hidden');
            if (notifierBtn && typeof notifierBtn._redirect === 'string' && notifierBtn._redirect !== '') {
                var target = notifierBtn._redirect;
                notifierBtn._redirect = '';
                window.location.href = target;
            }
        }

        if (notifierBtn) notifierBtn.addEventListener('click', closeNotifier);
        if (notifier) {
            notifier.addEventListener('click', function (e) {
                if (e.target === this) closeNotifier();
            });
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && notifier && !notifier.classList.contains('hidden')) {
                closeNotifier();
            }
        });

        /* ============================================
           FIELD VALIDATORS
           ============================================ */

        var NAME_PATTERN = /^[A-Za-z\s\-']+$/;

        function normalizeDigits(value) {
            return String(value || '').replace(/[^0-9]/g, '');
        }

        function validateField(postName) {
            var el = fieldElement(postName);
            if (!el) return '';

            var value = String(el.value || '').trim();

            switch (postName) {
                case 'first_name':
                    if (value.length < 2) return 'First name must be at least 2 characters.';
                    if (!NAME_PATTERN.test(value)) return 'First name contains invalid characters.';
                    return '';

                case 'middle_name':
                    if (value === '') return '';
                    if (!NAME_PATTERN.test(value)) return 'Middle name contains invalid characters.';
                    return '';

                case 'last_name':
                    if (value.length < 2) return 'Last name must be at least 2 characters.';
                    if (!NAME_PATTERN.test(value)) return 'Last name contains invalid characters.';
                    return '';

                case 'email':
                    if (value === '') return 'Email address is required.';
                    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) return 'Please enter a valid email address.';
                    return '';

                case 'contact_number': {
                    var digits = normalizeDigits(value);
                    if (digits === '') return 'Contact number is required.';
                    if (!/^09\d{9}$/.test(digits)) {
                        return 'Enter a valid PH mobile number (11 digits, starting with 09).';
                    }
                    return '';
                }

                case 'username':
                    if (value.length < 3) return 'Username must be at least 3 characters.';
                    if (value.length > 20) return 'Username must be no more than 20 characters.';
                    if (!/^[A-Za-z0-9_]+$/.test(value)) return 'Only letters, numbers, and underscores.';
                    return '';

                case 'business_name':
                    if (value.length < 2) return 'Business name must be at least 2 characters.';
                    if (value.length > 100) return 'Business name must be at most 100 characters.';
                    return '';

                case 'cuisine_type':
                    if (value.length < 2) return 'Cuisine type must be at least 2 characters.';
                    if (value.length > 50) return 'Cuisine type must be at most 50 characters.';
                    return '';

                case 'business_description':
                    if (value === '') return 'Business description is required.';
                    if (value.length < 2) return 'Business description must be at least 2 characters.';
                    if (value.length > 2000) return 'Description is too long (max 2000 characters).';
                    return '';

                case 'dietary_tags':
                    if (value === '') return 'Dietary tags are required.';
                    if (value.length > 200) return 'Dietary tags are too long (max 200 characters).';
                    return '';

                case 'branch_name':
                    if (value.length < 2) return 'Branch name must be at least 2 characters.';
                    if (value.length > 50) return 'Branch name must be at most 50 characters.';
                    return '';

                case 'block':
                    if (value === '') return 'Block / Street / Unit is required.';
                    if (value.length > 120) return 'This field is too long (max 120 characters).';
                    return '';

                case 'barangay':
                    if (value === '') return 'Barangay is required.';
                    if (value.length > 120) return 'Barangay is too long (max 120 characters).';
                    return '';

                case 'city':
                    if (value === '') return 'City / Municipality is required.';
                    if (value.length > 120) return 'City is too long (max 120 characters).';
                    return '';

                case 'province':
                    if (value === '') return 'Province is required.';
                    if (value.length > 120) return 'Province is too long (max 120 characters).';
                    return '';

                case 'region':
                    if (value === '') return 'Region is required.';
                    if (value.length > 120) return 'Region is too long (max 120 characters).';
                    return '';

                case 'postal_code':
                    if (value === '') return 'Postal code is required.';
                    if (!/^[0-9]{3,10}$/.test(value)) return 'Postal code must be numeric (3–10 digits).';
                    return '';
            }

            return '';
        }

        function setFieldError(postName, message) {
            var el = fieldElement(postName);
            var err = errorElement(postName);
            if (el) el.classList.add('error');
            if (err) err.textContent = message;
        }

        function clearFieldError(postName) {
            var el = fieldElement(postName);
            var err = errorElement(postName);
            if (el) el.classList.remove('error');
            if (err) err.textContent = '';
        }

        function clearAllFieldErrors() {
            fieldIds.forEach(clearFieldError);
        }

        /* ============================================
           LIVE VALIDATION
           ============================================ */

        fieldIds.forEach(function (postName) {
            var el = fieldElement(postName);
            if (!el) return;

            el.addEventListener('blur', function () {
                var msg = validateField(postName);
                if (msg) setFieldError(postName, msg);
                else clearFieldError(postName);
            });

            el.addEventListener('input', function () {
                if (el.classList.contains('error')) clearFieldError(postName);
            });
        });

        /* ============================================
           INPUT FILTERS
           ============================================ */

        // .name-field — letters, spaces, hyphens, apostrophes only.
        // Each word capitalized. Used for personal names and
        // business / branch names, where digits are not expected.
        function setupNameField(el, errorName) {
            if (!el) return;

            el.addEventListener('input', function () {
                var start = this.selectionStart;
                var filtered = this.value.replace(/[^A-Za-z\s\-']/g, '');
                var capitalized = filtered.replace(/\b\w/g, function (c) {
                    return c.toUpperCase();
                });

                if (this.value !== capitalized) {
                    this.value = capitalized;
                    var newStart = Math.min(start, this.value.length);
                    try { this.setSelectionRange(newStart, newStart); } catch (e) { /* noop */ }
                }
                if (errorName) clearFieldError(errorName);
            });

            el.addEventListener('blur', function () {
                if (this.value.length > 0) {
                    var capitalized = this.value.replace(/\b\w/g, function (c) {
                        return c.toUpperCase();
                    });
                    if (this.value !== capitalized) {
                        this.value = capitalized;
                    }
                }
                if (errorName) {
                    var msg = validateField(errorName);
                    if (msg) setFieldError(errorName, msg);
                    else clearFieldError(errorName);
                }
            });
        }

        // .address-field — letters, digits, spaces, commas, periods,
        // hyphens, apostrophes, slashes, hash. Each word capitalized.
        // Used for block / street / unit and barangay, where digits
        // are required (house numbers, unit numbers, barangay
        // numbers). The allowed set covers the forms a Philippine
        // street address normally takes:
        //
        //     12-A Sunrise St., Unit 5B
        //     Block 7 Lot 12
        //     Barangay Poblacion 5
        //     123 Rizal Ave. Ext.
        //
        // The character class keeps all of those intact while still
        // rejecting control characters and other junk.
        function setupAddressField(el, errorName) {
            if (!el) return;

            var allowed = /[^A-Za-z0-9\s\-',.\/#ñÑ]/g;
            var wordStart = /(^|[\s\-'.\/,])([a-zñ])/g;

            el.addEventListener('input', function () {
                var start = this.selectionStart;
                var filtered = this.value.replace(allowed, '');
                var capitalized = filtered.replace(wordStart, function (m, p, c) {
                    return p + c.toUpperCase();
                });

                if (this.value !== capitalized) {
                    this.value = capitalized;
                    var newStart = Math.min(start, this.value.length);
                    try { this.setSelectionRange(newStart, newStart); } catch (e) { /* noop */ }
                }
                if (errorName) clearFieldError(errorName);
            });

            el.addEventListener('blur', function () {
                if (this.value.length > 0) {
                    var capitalized = this.value.replace(wordStart, function (m, p, c) {
                        return p + c.toUpperCase();
                    });
                    if (this.value !== capitalized) {
                        this.value = capitalized;
                    }
                }
                if (errorName) {
                    var msg = validateField(errorName);
                    if (msg) setFieldError(errorName, msg);
                    else clearFieldError(errorName);
                }
            });
        }

        var nameFieldEls = form.querySelectorAll('.name-field');
        nameFieldEls.forEach(function (el) {
            setupNameField(el, el.id);
        });

        var addressFieldEls = form.querySelectorAll('.address-field');
        addressFieldEls.forEach(function (el) {
            setupAddressField(el, el.id);
        });

        // First-letter capitalization only. Applied to
        // .capitalize-field elements — currently just the Business
        // Description textarea on Step 2.
        function capitalizeField(el) {
            if (!el) return;

            el.addEventListener('input', function () {
                var original = this.value;
                if (original.length === 0) return;

                var first = original.charAt(0);
                var upperFirst = first.toUpperCase();

                if (first === upperFirst) return;

                var caret = this.selectionStart;
                this.value = upperFirst + original.slice(1);

                if (caret !== null) {
                    try {
                        this.setSelectionRange(caret, caret);
                    } catch (e) { /* noop */ }
                }
            });
        }

        var capitalizeFieldEls = form.querySelectorAll('.capitalize-field');
        capitalizeFieldEls.forEach(function (el) {
            capitalizeField(el);
        });

        var emailEl = fieldElement('email');
        if (emailEl) {
            emailEl.addEventListener('input', function () {
                var start = this.selectionStart;
                var lower = this.value.toLowerCase();
                if (this.value !== lower) {
                    this.value = lower;
                    var newStart = Math.min(start, this.value.length);
                    try { this.setSelectionRange(newStart, newStart); } catch (e) { /* noop */ }
                }
                clearFieldError('email');
            });
        }

        var contactEl = fieldElement('contact_number');
        if (contactEl) {
            contactEl.addEventListener('input', function () {
                var filtered = this.value.replace(/[^0-9\s]/g, '');
                if (this.value !== filtered) {
                    this.value = filtered;
                }
                clearFieldError('contact_number');
            });
        }

        var postalEl = fieldElement('postal_code');
        if (postalEl) {
            postalEl.addEventListener('input', function () {
                var filtered = this.value.replace(/[^0-9]/g, '');
                if (this.value !== filtered) {
                    this.value = filtered;
                }
                clearFieldError('postal_code');
            });
        }

        var usernameEl = fieldElement('username');
        if (usernameEl) {
            usernameEl.addEventListener('input', function () {
                var filtered = this.value.replace(/[^A-Za-z0-9_]/g, '');
                if (this.value !== filtered) {
                    this.value = filtered;
                }
                clearFieldError('username');
            });
        }

        /* ============================================
           PERMIT SLOT GRID
           ============================================ */

        var slotStates = {};

        function initSlotState(slotEl) {
            var index = parseInt(slotEl.dataset.slot, 10) || 0;
            if (index < 1 || index > 5) return;

            var hasPermit = slotEl.dataset.hasPermit === '1';
            var existingUrl = slotEl.dataset.existingUrl || '';
            var existingFilename = slotEl.dataset.existingFilename || '';

            slotStates[index] = {
                el: slotEl,
                index: index,
                serverFilename: existingFilename,
                serverPermitUrl: hasPermit ? existingUrl : '',
                hasServerPermit: hasPermit,
                hasLocalFile: false,
                markedForRemoval: false,
                localFile: null,
                localPreviewUrl: ''
            };
        }

        function currentOp(state) {
            if (state.hasLocalFile) return 'replace';
            if (state.markedForRemoval && state.hasServerPermit) return 'remove';
            return 'keep';
        }

        function updateSlotUi(state) {
            var el = state.el;
            if (!el) return;

            var preview = el.querySelector('.permit-slot-preview');
            var empty = el.querySelector('.permit-slot-empty');
            var newBadge = el.querySelector('.permit-slot-badge-new');
            var removeBtn = el.querySelector('.permit-slot-remove');
            var removeBanner = el.querySelector('.permit-slot-removal-banner');
            var opInput = el.querySelector('.permit-slot-op');
            var idInput = el.querySelector('.permit-slot-id');

            var op = currentOp(state);

            el.classList.toggle('has-permit',
                state.hasServerPermit && !state.hasLocalFile && !state.markedForRemoval);
            el.classList.toggle('has-new-file', state.hasLocalFile);
            el.classList.toggle('is-op-remove', op === 'remove');
            el.classList.remove('is-broken');

            if (state.hasLocalFile && state.localPreviewUrl !== '') {
                if (preview) {
                    preview.src = state.localPreviewUrl;
                    preview.style.display = '';
                }
                if (empty) empty.style.display = 'none';
            } else if (state.hasServerPermit && state.serverPermitUrl !== '') {
                if (preview) {
                    preview.src = state.serverPermitUrl;
                    preview.style.display = '';
                }
                if (empty) empty.style.display = 'none';
            } else {
                if (preview) {
                    preview.src = '';
                    preview.style.display = 'none';
                }
                if (empty) empty.style.display = 'flex';
            }

            if (newBadge) newBadge.hidden = !state.hasLocalFile;
            if (removeBtn) removeBtn.hidden = !(state.hasServerPermit || state.hasLocalFile);
            if (removeBanner) removeBanner.hidden = (op !== 'remove');

            if (opInput) opInput.value = op;
            if (idInput) idInput.value = '0';
        }

        function clearLocalFile(state, clearDom) {
            if (state.localPreviewUrl !== '') {
                try { URL.revokeObjectURL(state.localPreviewUrl); }
                catch (e) { /* noop */ }
                state.localPreviewUrl = '';
            }
            state.localFile = null;
            state.hasLocalFile = false;

            if (clearDom) {
                var fileInput = state.el.querySelector('.permit-slot-input');
                if (fileInput) fileInput.value = '';
            }
        }

        function handleFileChosen(state, file) {
            if (permitsError) permitsError.textContent = '';

            if (!file) return;

            if (ALLOWED_MIME.indexOf(file.type) === -1) {
                if (permitsError) permitsError.textContent = 'Unsupported file type. Use JPG, PNG, or WEBP.';
                return;
            }
            if (file.size > MAX_BYTES) {
                if (permitsError) permitsError.textContent = 'Each permit must be under 5 MB.';
                return;
            }

            clearLocalFile(state, false);

            state.hasLocalFile = true;
            state.markedForRemoval = false;
            state.localFile = file;
            state.localPreviewUrl = URL.createObjectURL(file);

            updateSlotUi(state);
            updatePermitCount();
        }

        function toggleRemoval(state) {
            if (state.hasLocalFile) {
                clearLocalFile(state, true);
                state.markedForRemoval = false;
                updateSlotUi(state);
                updatePermitCount();
                return;
            }
            if (!state.hasServerPermit) return;

            state.markedForRemoval = !state.markedForRemoval;
            updateSlotUi(state);
            updatePermitCount();
        }

        function slotClickOpensPicker(slotEl) {
            var input = slotEl.querySelector('.permit-slot-input');
            if (input) input.click();
        }

        function initPermitGrid() {
            if (!permitGrid) return;

            var slots = permitGrid.querySelectorAll('.permit-slot');
            if (!slots.length) return;

            slots.forEach(function (slotEl) {
                initSlotState(slotEl);
                var index = parseInt(slotEl.dataset.slot, 10);
                var state = slotStates[index];
                if (!state) return;

                var fileInput = slotEl.querySelector('.permit-slot-input');
                var removeBtn = slotEl.querySelector('.permit-slot-remove');
                var hit = slotEl.querySelector('.permit-slot-hit');

                if (hit) {
                    hit.addEventListener('click', function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        slotClickOpensPicker(slotEl);
                    });
                }

                slotEl.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        if (e.target.closest('.permit-slot-remove')) return;
                        e.preventDefault();
                        slotClickOpensPicker(slotEl);
                    }
                });

                if (fileInput) {
                    fileInput.addEventListener('change', function () {
                        var file = this.files && this.files[0];
                        if (!file) return;

                        try {
                            var dt = new DataTransfer();
                            dt.items.add(file);
                            this.files = dt.files;
                        } catch (e) {
                            // Browser does not support assigning FileList.
                        }

                        state.localFile = file;
                        handleFileChosen(state, file);
                    });
                }

                if (removeBtn) {
                    removeBtn.addEventListener('click', function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        toggleRemoval(state);
                    });
                }

                updateSlotUi(state);
            });

            updatePermitCount();
        }

        function countFinalPermits() {
            var present = 0;

            Object.keys(slotStates).forEach(function (key) {
                var state = slotStates[key];

                if (state.hasLocalFile) {
                    present++;
                    return;
                }

                if (state.hasServerPermit && !state.markedForRemoval) {
                    present++;
                    return;
                }
            });

            return present;
        }

        function updatePermitCount() {
            if (!permitGrid || !permitCountEl) return;

            var finalCount = countFinalPermits();

            var removing = 0;
            Object.keys(slotStates).forEach(function (key) {
                if (currentOp(slotStates[key]) === 'remove') {
                    removing++;
                }
            });

            var label = finalCount === 1 ? 'permit' : 'permits';
            permitCountEl.textContent = finalCount + ' ' + label + ' on file after saving';

            if (removing > 0) {
                permitCountEl.textContent += ' · ' + removing + ' will be removed';
            }
        }

        initPermitGrid();

        /* ============================================
           STEP NAVIGATION
           ============================================ */

        function activateStepVisuals(step) {
            steps.forEach(function (el) {
                var isCurrent = (parseInt(el.id.replace('step', ''), 10) === step);
                el.style.display = isCurrent ? 'block' : 'none';
            });

            progressSteps.forEach(function (el, i) {
                var n = i + 1;
                el.classList.toggle('active', n === step);
                el.classList.toggle('completed', n < step);
            });

            progressLines.forEach(function (el, i) {
                el.classList.toggle('completed', (i + 1) < step);
            });

            if (stepSubtitle && STEP_TITLES[step]) {
                stepSubtitle.textContent = STEP_TITLES[step];
            }

            if (currentStepInput) currentStepInput.value = String(step);

            var bar = document.querySelector('.register-progress');
            if (bar) bar.setAttribute('aria-valuenow', String(step));
        }

        function validateStep(step) {
            var stepFieldMap = {
                1: ['first_name', 'middle_name', 'last_name', 'email', 'contact_number', 'username'],
                2: ['business_name', 'cuisine_type', 'business_description', 'dietary_tags'],
                3: ['branch_name', 'block', 'barangay', 'city', 'province', 'region', 'postal_code'],
                4: []
            };

            var fields = stepFieldMap[step] || [];
            var firstFailingField = null;

            fields.forEach(function (postName) {
                var msg = validateField(postName);
                if (msg) {
                    setFieldError(postName, msg);
                    if (!firstFailingField) firstFailingField = postName;
                }
            });

            if (step === 4) {
                if (countFinalPermits() < 1) {
                    if (permitsError) {
                        permitsError.textContent =
                            'At least one permit must remain on file. Add a new permit before removing the last one.';
                    }
                    if (!firstFailingField) firstFailingField = 'permits';
                } else if (permitsError) {
                    permitsError.textContent = '';
                }
            }

            return firstFailingField;
        }

        function focusField(postName) {
            var el = fieldElement(postName);
            if (!el) return;

            try { el.focus(); } catch (e) { /* noop */ }

            if (typeof el.scrollIntoView === 'function') {
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        function goToStep(step) {
            if (step < 1 || step > TOTAL_STEPS) return;
            if (step === currentStep) return;

            if (step > currentStep) {
                if (validateStep(currentStep) !== null) return;
            }

            currentStep = step;
            activateStepVisuals(step);
            hideBanner();

            if (step === 5) buildReviewSummary();

            var stepEl = document.getElementById('step' + step);
            if (stepEl) {
                var firstInput = stepEl.querySelector(
                    'input:not([type="hidden"]):not([type="file"]), select, textarea'
                );
                if (firstInput) {
                    setTimeout(function () {
                        try { firstInput.focus(); } catch (e) { /* noop */ }
                    }, 80);
                }
            }
        }

        nextButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var next = parseInt(this.dataset.next, 10);
                if (!isNaN(next)) goToStep(next);
            });
        });

        prevButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var prev = parseInt(this.dataset.prev, 10);
                if (!isNaN(prev)) goToStep(prev);
            });
        });

        /* ============================================
           REVIEW SUMMARY
           ============================================ */

        function setText(id, value) {
            var el = document.getElementById(id);
            if (!el) return;
            el.textContent = value !== '' ? value : '—';
        }

        function buildReviewSummary() {
            var fn = (document.getElementById('first_name') || {}).value || '';
            var mn = (document.getElementById('middle_name') || {}).value || '';
            var ln = (document.getElementById('last_name') || {}).value || '';

            var fullName = [fn, mn, ln]
                .map(function (v) { return String(v).trim(); })
                .filter(Boolean)
                .join(' ');

            setText('reviewName', fullName);
            setText('reviewEmail', ((document.getElementById('email') || {}).value || '').trim());
            setText('reviewContact', ((document.getElementById('contact_number') || {}).value || '').trim());
            setText('reviewUsername', ((document.getElementById('username') || {}).value || '').trim());

            setText('reviewBusinessName', ((document.getElementById('business_name') || {}).value || '').trim());
            setText('reviewCuisineType', ((document.getElementById('cuisine_type') || {}).value || '').trim());
            setText('reviewDietaryTags', ((document.getElementById('dietary_tags') || {}).value || '').trim());

            setText('reviewBranchName', ((document.getElementById('branch_name') || {}).value || '').trim());

            var addressParts = ['block', 'barangay', 'city', 'province', 'region', 'postal_code']
                .map(function (id) {
                    var el = document.getElementById(id);
                    return el ? String(el.value || '').trim() : '';
                })
                .filter(Boolean);

            setText('reviewBranchAddress', addressParts.join(', '));

            var permitCount = countFinalPermits();
            var permitText = permitCount === 1
                ? '1 permit on file'
                : permitCount + ' permits on file';

            setText('reviewPermitStatus', permitText);
        }

        /* ============================================
           SUBMIT
           ============================================ */

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (isSubmitting) return;

            clearAllFieldErrors();
            if (permitsError) permitsError.textContent = '';
            hideBanner();

            var firstFailingStep = 0;
            var firstFailingField = null;

            for (var s = 1; s <= 4; s++) {
                var failing = validateStep(s);
                if (failing !== null && !firstFailingStep) {
                    firstFailingStep = s;
                    firstFailingField = failing;
                }
            }

            if (firstFailingStep > 0) {
                var label = firstFailingField
                    ? (fieldLabels[firstFailingField] || firstFailingField)
                    : 'a required field';

                console.warn(
                    '[restaurant-reapply] Submit blocked: step ' + firstFailingStep
                    + ' field "' + firstFailingField + '" failed validation.'
                );

                showBanner(
                    'Step ' + firstFailingStep + ' needs attention: ' + label
                    + '. Please correct it before submitting.'
                );

                goToStep(firstFailingStep);

                setTimeout(function () {
                    if (firstFailingField) focusField(firstFailingField);
                }, 120);

                return;
            }

            var formData = new FormData(form);
            if (!formData.has('csrf_token') && CSRF_TOKEN) {
                formData.set('csrf_token', CSRF_TOKEN);
            }
            formData.set('current_step', '5');

            isSubmitting = true;
            if (submitBtn) submitBtn.disabled = true;
            if (submitLabel) submitLabel.textContent = 'Submitting…';

            fetch(HANDLER_URL, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData,
                credentials: 'same-origin'
            })
                .then(function (res) {
                    return res.text().then(function (text) {
                        try { return JSON.parse(text); }
                        catch (err) {
                            console.error('[restaurant-reapply] Non-JSON response:', res.status, text.slice(0, 240));
                            return { status: 'error', message: 'Server returned an unexpected response.' };
                        }
                    });
                })
                .then(function (data) {
                    if (data && data.status === 'success') {
                        showNotifier(
                            'Application Submitted',
                            data.message || 'Your updated application has been submitted.',
                            data.redirect || 'dashboard.php'
                        );
                        return;
                    }

                    isSubmitting = false;
                    if (submitBtn) submitBtn.disabled = false;
                    if (submitLabel) submitLabel.textContent = 'Submit Application';

                    if (data && data.field) {
                        var field = String(data.field);
                        var step = fieldStep[field] || 0;

                        if (step > 0 && step < 5) {
                            goToStep(step);
                            setFieldError(field, data.message || 'Please check this field.');
                            setTimeout(function () {
                                focusField(field);
                            }, 120);
                            return;
                        }

                        if (field === 'permits') {
                            goToStep(4);
                            if (permitsError) permitsError.textContent = data.message || 'Please check your permits.';
                            return;
                        }

                        showNotifier('Could Not Submit', data.message || 'Please check the form.', '');
                        return;
                    }

                    showNotifier(
                        'Could Not Submit',
                        (data && data.message) || 'Please check the form and try again.',
                        ''
                    );
                })
                .catch(function (err) {
                    console.error('[restaurant-reapply] Submit failed:', err);
                    isSubmitting = false;
                    if (submitBtn) submitBtn.disabled = false;
                    if (submitLabel) submitLabel.textContent = 'Submit Application';
                    showNotifier('Network Error', 'Could not reach the server. Please try again.', '');
                });
        });

        /* ============================================
           INITIAL STATE
           ============================================ */

        currentStep = 1;
        activateStepVisuals(1);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();