/**
 * FitPal Rider Re-Application Page JavaScript
 *
 * Client-side behaviour for rider/pages/reapply.php. Five
 * responsibilities:
 *
 *   1. Step gating for the five-step wizard. Only one
 *      .register-step is visible at a time; the progress bar's
 *      .active and .completed states track which step the rider
 *      is on; the Next and Back buttons move between steps and
 *      validate the departing step first.
 *
 *   2. Input filters and real-time validation, on the same rules
 *      sign-up-handler.php enforces server-side.
 *
 *   3. Vehicle-type-dependent UI swaps — the page-level
 *      data-vehicle-type attribute, the `required` toggle on the
 *      two license date inputs, and the id_type-dependent heading
 *      swaps that reapply.css keys on the same attribute.
 *
 *   4. Upload dropzone previews for the two file inputs
 *      (profile_picture, drivers_license).
 *
 *   5. Form submission via fetch, with the handler's JSON response
 *      routed to the correct inline field error, banner, or — if
 *      the error is on a step the rider has already passed — back
 *      to the step that owns the field.
 *
 * ---------------------------------------------------------------------
 * WHY THIS IS NOT sign-up.js
 * ---------------------------------------------------------------------
 * The two pages look similar but are structurally different:
 *
 *   sign-up.php    five-step wizard, password required.
 *   reapply.php    five-step wizard, password optional, every
 *                  field pre-filled from the rider's existing row.
 *
 * A shared script would need to feature-detect which page it is
 * on and branch at every entry point. Keeping a separate file means
 * each page's JS can be read top to bottom without skipping
 * "if the form is prefilled, do X; otherwise do Y" clauses.
 *
 * ---------------------------------------------------------------------
 * PASSWORD FIELDS ARE OPTIONAL
 * ---------------------------------------------------------------------
 * Both password inputs start empty and stay empty unless the rider
 * types into them. The validation rule is:
 *
 *   - Both blank  → no password change; the handler leaves the
 *                   existing hash alone.
 *   - Both filled → the new password must meet the rules and the
 *                   two values must match. The handler rewrites the
 *                   hash.
 *   - Only one filled → refused with an inline error on the
 *                   blank field, because half a password is never a
 *                   valid submission.
 *
 * The handler cannot pre-fill these fields, because
 * delivery_rider.password holds a bcrypt hash and a hash is not
 * reversible. There is nothing to fetch into the inputs.
 *
 * ---------------------------------------------------------------------
 * WHAT THIS FILE DOES NOT DO
 * ---------------------------------------------------------------------
 *   - It does not inject markup. Every element it manipulates is
 *     rendered by reapply.php.
 *   - It does not compute the POST target from the DOM. The endpoint
 *     is a literal string, matching the form's own action attribute.
 *   - It does not pre-fill the two license date inputs. The page
 *     renders them empty and this file keeps them empty; the rider
 *     is expected to enter the new document's dates.
 *   - It does not use window.alert, window.confirm, or
 *     window.prompt. Every message is either an inline field error
 *     or the top banner.
 *   - It does not add inline CSS. The upload dropzone's `.has-file`
 *     and `.is-dragover` classes, and the wizard's `.active` /
 *     `.completed` classes on the progress bar, are styled by
 *     reapply.css.
 *
 * @package FitPal
 * @version 2.1 — The "confirm only, no new password" inline error
 *                message is reworded to match the page's hint text.
 *                No validation logic, filter, dropzone, password
 *                toggle, vehicle-type swap, step gate, or submit
 *                behaviour changed from v2.0.
 *
 *                (2.0: five-step wizard gating added. 1.1: comment
 *                update. 1.0: initial reapply-page script.)
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ============================================
        // CONFIG
        // ============================================
        var form = document.getElementById('registerForm');
        if (!form) return;

        // Literal endpoint. Matches the <form action> reapply.php
        // renders. Never read from form.action at request time.
        var ENDPOINT = '../backend/handlers/sign-up-handler.php';

        var TOTAL_STEPS = 5;

        var STEP_TITLES = [
            'Step 1 of 5 — Personal Information',
            'Step 2 of 5 — Vehicle',
            'Step 3 of 5 — Address & Emergency Contact',
            'Step 4 of 5 — Verification Uploads',
            'Step 5 of 5 — Review & Submit',
        ];

        // ============================================
        // DOM REFERENCES
        // ============================================
        var pageRoot = document.querySelector('.register-page');

        var registerError = document.getElementById('registerError');
        var errorMessage  = document.getElementById('errorMessage');

        // Wizard elements
        var steps          = document.querySelectorAll('.register-step');
        var progressSteps  = document.querySelectorAll('.progress-step');
        var progressLines  = document.querySelectorAll('.progress-line');
        var stepSubtitle   = document.getElementById('stepSubtitle');
        var currentStepInput = document.getElementById('currentStep');
        var nextButtons    = document.querySelectorAll('.btn-next');
        var prevButtons    = document.querySelectorAll('.btn-prev');

        // Personal
        var firstName       = document.getElementById('first_name');
        var middleName      = document.getElementById('middle_name');
        var lastName        = document.getElementById('last_name');
        var birthdate       = document.getElementById('birthdate');
        var gender          = document.getElementById('gender');
        var email           = document.getElementById('email');
        var contactNumber   = document.getElementById('contact_number');
        var username        = document.getElementById('username');
        var password        = document.getElementById('password');
        var confirmPassword = document.getElementById('confirm_password');

        // Vehicle
        var vehicleType  = document.getElementById('vehicle_type');
        var vehiclePlate = document.getElementById('vehicle_plate');
        var vehicleMake  = document.getElementById('vehicle_make');
        var vehicleModel = document.getElementById('vehicle_model');
        var vehicleYear  = document.getElementById('vehicle_year');

        // Address + Emergency
        var block               = document.getElementById('block');
        var barangay            = document.getElementById('barangay');
        var city                = document.getElementById('city');
        var province            = document.getElementById('province');
        var region              = document.getElementById('region');
        var postalCode          = document.getElementById('postal_code');
        var emergencyFirstName  = document.getElementById('emergency_first_name');
        var emergencyMiddleName = document.getElementById('emergency_middle_name');
        var emergencyLastName   = document.getElementById('emergency_last_name');
        var emergencyRel        = document.getElementById('emergency_relationship');
        var emergencyContact    = document.getElementById('emergency_contact');

        // Uploads + license dates
        var profileInput      = document.getElementById('profile_picture');
        var profileDropzone   = document.getElementById('profileDropzone');
        var profilePreview    = document.getElementById('profilePreview');
        var profilePreviewImg = document.getElementById('profilePreviewImg');
        var profileHint       = document.getElementById('profileHint');
        var profileRemove     = document.getElementById('profileRemove');
        var profilePicError   = document.getElementById('profilePictureError');

        var licenseInput      = document.getElementById('drivers_license');
        var licenseDropzone   = document.getElementById('licenseDropzone');
        var licensePreview    = document.getElementById('licensePreview');
        var licensePreviewImg = document.getElementById('licensePreviewImg');
        var licenseHint       = document.getElementById('licenseHint');
        var licenseRemove     = document.getElementById('licenseRemove');
        var licenseError      = document.getElementById('driversLicenseError');

        var licenseIssue     = document.getElementById('license_issue_date');
        var licenseExpiry    = document.getElementById('license_expiry_date');
        var licenseIssueError  = document.getElementById('licenseIssueError');
        var licenseExpiryError = document.getElementById('licenseExpiryError');

        // Terms + Submit
        var termsCheckbox = document.getElementById('terms');
        var termsGroup    = document.getElementById('termsGroup');
        var termsError    = document.getElementById('termsError');
        var registerBtn   = document.getElementById('registerBtn');

        // Error elements
        var firstNameError          = document.getElementById('firstNameError');
        var lastNameError           = document.getElementById('lastNameError');
        var birthdateError          = document.getElementById('birthdateError');
        var genderError             = document.getElementById('genderError');
        var emailError              = document.getElementById('emailError');
        var contactError            = document.getElementById('contactError');
        var usernameError           = document.getElementById('usernameError');
        var passwordError           = document.getElementById('passwordError');
        var confirmError            = document.getElementById('confirmError');
        var vehicleTypeError        = document.getElementById('vehicleTypeError');
        var vehiclePlateError       = document.getElementById('vehiclePlateError');
        var vehicleYearError        = document.getElementById('vehicleYearError');
        var blockError              = document.getElementById('blockError');
        var cityError               = document.getElementById('cityError');
        var postalError             = document.getElementById('postalError');
        var emergencyFirstNameError = document.getElementById('emergencyFirstNameError');
        var emergencyLastNameError  = document.getElementById('emergencyLastNameError');
        var emergencyRelError       = document.getElementById('emergencyRelationshipError');
        var emergencyContactError   = document.getElementById('emergencyContactError');

        var currentStep  = 1;
        var isSubmitting = false;

        var NAME_PATTERN        = /^[A-Za-z\s\-']+$/;
        var ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

        // ============================================
        // SHARED HELPERS
        // ============================================

        /**
         * Reduce a phone number to its digit-only form.
         *
         * Matches the server-side rule in sign-up-handler.php,
         * which uses preg_replace('/\D+/', '', ...).
         *
         * @param {string} value
         * @returns {string}
         */
        function normalizeDigits(value) {
            return String(value || '').replace(/[^0-9]/g, '');
        }

        function isValidPhilippineMobile(number) {
            var cleaned = String(number).replace(/\s/g, '');
            if (!/^09\d{9}$/.test(cleaned)) {
                return { valid: false, message: 'Enter a valid PH mobile number (11 digits, starting with 09).' };
            }
            return { valid: true, message: '' };
        }

        function isValidPassword(pw) {
            if (pw.length < 8) return { valid: false, message: 'Password must be at least 8 characters.' };
            if (pw.length > 20) return { valid: false, message: 'Password must be no more than 20 characters.' };
            if (!/^[A-Za-z0-9]+$/.test(pw)) {
                return { valid: false, message: 'Password can only contain letters and numbers.' };
            }
            if (!/[0-9]/.test(pw)) return { valid: false, message: 'Password must contain at least one number.' };
            if (!/[A-Za-z]/.test(pw)) return { valid: false, message: 'Password must contain at least one letter.' };
            return { valid: true, message: '' };
        }

        function showFieldError(input, errorEl, message) {
            if (input) input.classList.add('error');
            if (errorEl) { errorEl.textContent = message; errorEl.style.display = 'block'; }
        }

        function clearFieldError(input, errorEl) {
            if (input) input.classList.remove('error');
            if (errorEl) { errorEl.textContent = ''; errorEl.style.display = 'none'; }
        }

        function showBanner(message) {
            if (registerError && errorMessage) {
                errorMessage.textContent = message;
                registerError.style.display = 'block';
            }
        }

        function hideBanner() {
            if (registerError) registerError.style.display = 'none';
        }

        function clearStepErrors(stepNumber) {
            var stepEl = document.getElementById('step' + stepNumber);
            if (!stepEl) return;

            stepEl.querySelectorAll('.form-error').forEach(function (el) {
                el.textContent = '';
                el.style.display = 'none';
            });
            stepEl.querySelectorAll('.form-control').forEach(function (el) {
                el.classList.remove('error');
            });

            if (stepNumber === 5 && termsGroup) {
                termsGroup.classList.remove('error');
                if (termsError) {
                    termsError.textContent = '';
                    termsError.style.display = 'none';
                }
            }
        }

        function focusFirstErrorInStep(stepNumber) {
            var stepEl = document.getElementById('step' + stepNumber);
            if (!stepEl) return;
            var firstErrorInput = stepEl.querySelector('.form-control.error');
            if (firstErrorInput && typeof firstErrorInput.focus === 'function') {
                firstErrorInput.focus();
                if (typeof firstErrorInput.scrollIntoView === 'function') {
                    firstErrorInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        }

        function focusFirstInputInStep(stepNumber) {
            var stepEl = document.getElementById('step' + stepNumber);
            if (!stepEl) return;
            var firstInput = stepEl.querySelector(
                'input:not([type="hidden"]):not([type="file"]), select'
            );
            if (firstInput && typeof firstInput.focus === 'function') {
                setTimeout(function () { firstInput.focus(); }, 80);
            }
        }

        // ============================================
        // INPUT FILTERS
        // ============================================

        function setupNameInput(input, errorEl) {
            if (!input) return;

            input.addEventListener('input', function () {
                var start = this.selectionStart;
                var filtered = this.value.replace(/[^A-Za-z\s\-']/g, '');
                var capitalized = filtered.replace(/\b\w/g, function (c) { return c.toUpperCase(); });

                if (this.value !== capitalized) {
                    this.value = capitalized;
                    var newStart = Math.min(start, this.value.length);
                    this.setSelectionRange(newStart, newStart);
                }
                if (errorEl) clearFieldError(this, errorEl);
            });

            input.addEventListener('blur', function () {
                if (this.value.length > 0) {
                    var capitalized = this.value.replace(/\b\w/g, function (c) { return c.toUpperCase(); });
                    if (this.value !== capitalized) this.value = capitalized;
                }
            });
        }

        function setupTitleCaseInput(input, errorEl) {
            if (!input) return;

            input.addEventListener('input', function () {
                var start = this.selectionStart;
                var filtered = this.value.replace(/[^A-Za-z0-9\s\-./]/g, '');
                var capitalized = filtered.replace(/\b\w/g, function (c) { return c.toUpperCase(); });

                if (this.value !== capitalized) {
                    this.value = capitalized;
                    var newStart = Math.min(start, this.value.length);
                    this.setSelectionRange(newStart, newStart);
                }
                if (errorEl) clearFieldError(this, errorEl);
            });

            input.addEventListener('blur', function () {
                if (this.value.length > 0) {
                    var capitalized = this.value.replace(/\b\w/g, function (c) { return c.toUpperCase(); });
                    if (this.value !== capitalized) this.value = capitalized;
                }
            });
        }

        function setupEmailInput(input) {
            if (!input) return;
            input.addEventListener('input', function () {
                var start = this.selectionStart;
                var lower = this.value.toLowerCase();
                if (this.value !== lower) {
                    this.value = lower;
                    var newStart = Math.min(start, this.value.length);
                    this.setSelectionRange(newStart, newStart);
                }
                clearFieldError(this, emailError);
            });
        }

        function setupDigitsOnly(input, errorEl) {
            if (!input) return;
            input.addEventListener('input', function () {
                this.value = this.value.replace(/[^0-9\s]/g, '');
                if (errorEl) clearFieldError(this, errorEl);
            });
        }

        function setupAlphanumUnderscore(input) {
            if (!input) return;
            input.addEventListener('input', function () {
                this.value = this.value.replace(/[^A-Za-z0-9_]/g, '');
                clearFieldError(this, usernameError);
            });
        }

        function setupAlphanumOnly(input) {
            if (!input) return;
            input.addEventListener('input', function () {
                this.value = this.value.replace(/[^A-Za-z0-9]/g, '');
            });
        }

        function setupPlate(input) {
            if (!input) return;
            input.addEventListener('input', function () {
                this.value = this.value.toUpperCase().replace(/[^A-Z0-9\s\-]/g, '');
                clearFieldError(this, vehiclePlateError);
            });
        }

        function setupPostal(input) {
            if (!input) return;
            input.addEventListener('input', function () {
                this.value = this.value.replace(/[^0-9]/g, '');
                clearFieldError(this, postalError);
            });
        }

        // Wire the filters.
        setupNameInput(firstName, firstNameError);
        setupNameInput(middleName, null);
        setupNameInput(lastName, lastNameError);
        setupNameInput(block, blockError);
        setupNameInput(barangay, null);
        setupNameInput(city, cityError);
        setupNameInput(province, null);
        setupNameInput(region, null);
        setupNameInput(emergencyFirstName, emergencyFirstNameError);
        setupNameInput(emergencyMiddleName, null);
        setupNameInput(emergencyLastName, emergencyLastNameError);
        setupTitleCaseInput(vehicleMake, null);
        setupTitleCaseInput(vehicleModel, null);
        setupEmailInput(email);
        setupDigitsOnly(contactNumber, contactError);
        setupDigitsOnly(emergencyContact, emergencyContactError);
        setupAlphanumUnderscore(username);
        setupAlphanumOnly(password);
        setupAlphanumOnly(confirmPassword);
        setupPlate(vehiclePlate);
        setupPostal(postalCode);

        // ============================================
        // PASSWORD TOGGLES
        // ============================================

        function setupPasswordToggle(toggleId, inputId, iconId) {
            var btn   = document.getElementById(toggleId);
            var input = document.getElementById(inputId);
            var icon  = document.getElementById(iconId);
            if (!btn || !input || !icon) return;

            btn.addEventListener('click', function () {
                var isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
                var iconFile = isPassword ? 'password-unhide.svg' : 'password-hide.svg';
                icon.src = '../../shared/assets/images/icons/' + iconFile;
                icon.alt = isPassword ? 'Hide password' : 'Show password';
                this.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            });
        }

        setupPasswordToggle('togglePassword', 'password', 'passwordIcon');
        setupPasswordToggle('toggleConfirmPassword', 'confirm_password', 'confirmPasswordIcon');

        // ============================================
        // FILE UPLOAD HELPERS
        // ============================================

        function wireUploader(cfg) {
            var input      = cfg.input;
            var dropzone   = cfg.dropzone;
            var preview    = cfg.preview;
            var previewImg = cfg.previewImg;
            var hint       = cfg.hint;
            var removeBtn  = cfg.removeBtn;
            var errorEl    = cfg.errorEl;
            var maxBytes   = cfg.maxBytes;

            if (!input || !dropzone) return;

            var currentUrl = null;

            function clearPreview() {
                if (currentUrl) {
                    URL.revokeObjectURL(currentUrl);
                    currentUrl = null;
                }
                if (preview) preview.hidden = true;
                if (hint)    hint.hidden    = false;
                if (previewImg) previewImg.removeAttribute('src');
                input.value = '';
                dropzone.classList.remove('has-file');
            }

            function setPreview(file) {
                if (currentUrl) URL.revokeObjectURL(currentUrl);
                currentUrl = URL.createObjectURL(file);
                if (previewImg) previewImg.src = currentUrl;
                if (preview) preview.hidden = false;
                if (hint)    hint.hidden    = true;
                dropzone.classList.add('has-file');
            }

            function validateAndSet(file) {
                clearFieldError(input, errorEl);
                if (!file) return;

                if (ALLOWED_IMAGE_TYPES.indexOf(file.type) === -1) {
                    showFieldError(input, errorEl, 'Unsupported file type. Use JPG, PNG, or WEBP.');
                    clearPreview();
                    return;
                }
                if (file.size > maxBytes) {
                    var maxMB = Math.round(maxBytes / 1048576 * 10) / 10;
                    showFieldError(input, errorEl, 'File must be under ' + maxMB + ' MB.');
                    clearPreview();
                    return;
                }
                setPreview(file);
            }

            dropzone.addEventListener('click', function (e) {
                if (e.target.closest('.upload-remove')) return;
                input.click();
            });
            dropzone.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    input.click();
                }
            });

            input.addEventListener('change', function () {
                if (this.files && this.files[0]) validateAndSet(this.files[0]);
            });

            ['dragenter', 'dragover'].forEach(function (evt) {
                dropzone.addEventListener(evt, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropzone.classList.add('is-dragover');
                });
            });
            ['dragleave', 'drop'].forEach(function (evt) {
                dropzone.addEventListener(evt, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropzone.classList.remove('is-dragover');
                });
            });
            dropzone.addEventListener('drop', function (e) {
                var dt = e.dataTransfer;
                if (!dt || !dt.files || !dt.files[0]) return;
                var file = dt.files[0];
                try {
                    var transfer = new DataTransfer();
                    transfer.items.add(file);
                    input.files = transfer.files;
                } catch (err) {
                    if (window.console && console.warn) {
                        console.warn('[reapply] Could not attach dropped file to input.');
                    }
                }
                validateAndSet(file);
            });

            if (removeBtn) {
                removeBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    clearPreview();
                    clearFieldError(input, errorEl);
                });
            }
        }

        wireUploader({
            input:      profileInput,
            dropzone:   profileDropzone,
            preview:    profilePreview,
            previewImg: profilePreviewImg,
            hint:       profileHint,
            removeBtn:  profileRemove,
            errorEl:    profilePicError,
            maxBytes:   2 * 1024 * 1024,
        });

        wireUploader({
            input:      licenseInput,
            dropzone:   licenseDropzone,
            preview:    licensePreview,
            previewImg: licensePreviewImg,
            hint:       licenseHint,
            removeBtn:  licenseRemove,
            errorEl:    licenseError,
            maxBytes:   5 * 1024 * 1024,
        });

        // ============================================
        // VEHICLE-TYPE-DEPENDENT UI
        // ============================================

        function applyVehicleTypeDependentUI() {
            if (!vehicleType || !pageRoot) return;

            var selectedValue = vehicleType.value;
            var isBicycle     = (selectedValue === 'bicycle');

            pageRoot.setAttribute('data-vehicle-type', selectedValue || '');

            if (licenseIssue) {
                if (isBicycle) {
                    licenseIssue.removeAttribute('required');
                } else {
                    licenseIssue.setAttribute('required', 'required');
                }
            }
            if (licenseExpiry) {
                if (isBicycle) {
                    licenseExpiry.removeAttribute('required');
                } else {
                    licenseExpiry.setAttribute('required', 'required');
                }
            }

            if (isBicycle) {
                if (vehiclePlate) vehiclePlate.value = '';
                if (vehicleMake)  vehicleMake.value  = '';
                if (vehicleModel) vehicleModel.value = '';
                if (vehicleYear)  vehicleYear.value  = '';

                clearFieldError(vehiclePlate, vehiclePlateError);
                clearFieldError(vehicleYear,  vehicleYearError);
                clearFieldError(licenseIssue, licenseIssueError);
                clearFieldError(licenseExpiry, licenseExpiryError);
            }
        }

        if (vehicleType) {
            vehicleType.addEventListener('change', applyVehicleTypeDependentUI);
            applyVehicleTypeDependentUI();
        }

        // ============================================
        // REAL-TIME PASSWORD FEEDBACK
        // ============================================

        if (password) {
            password.addEventListener('input', function () {
                var val = this.value;
                if (val.length === 0) {
                    clearFieldError(this, passwordError);
                    return;
                }
                var check = isValidPassword(val);
                if (check.valid) {
                    clearFieldError(this, passwordError);
                } else {
                    showFieldError(this, passwordError, check.message);
                }

                if (confirmPassword && confirmPassword.value) {
                    if (confirmPassword.value === val) {
                        clearFieldError(confirmPassword, confirmError);
                    } else {
                        showFieldError(confirmPassword, confirmError, 'Passwords do not match.');
                    }
                }
            });
        }

        if (confirmPassword) {
            confirmPassword.addEventListener('input', function () {
                var val = this.value;
                if (!val) {
                    clearFieldError(this, confirmError);
                    return;
                }
                if (val === (password ? password.value : '')) {
                    clearFieldError(this, confirmError);
                } else {
                    showFieldError(confirmPassword, confirmError, 'Passwords do not match.');
                }
            });
        }

        // ============================================
        // BLUR VALIDATION
        // ============================================

        if (contactNumber) {
            contactNumber.addEventListener('blur', function () {
                var val = this.value.trim();
                if (!val) return;
                var ph = isValidPhilippineMobile(val);
                if (ph.valid) clearFieldError(this, contactError);
                else showFieldError(this, contactError, ph.message);
            });
        }

        if (email) {
            email.addEventListener('blur', function () {
                var val = this.value.trim();
                if (!val) return;
                if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
                    clearFieldError(this, emailError);
                } else {
                    showFieldError(this, emailError, 'Please enter a valid email address.');
                }
            });
        }

        if (username) {
            username.addEventListener('blur', function () {
                var val = this.value.trim();
                if (!val) return;
                if (val.length < 3) {
                    showFieldError(this, usernameError, 'Username must be at least 3 characters.');
                } else if (val.length > 20) {
                    showFieldError(this, usernameError, 'Username must be no more than 20 characters.');
                } else if (!/^[A-Za-z0-9_]+$/.test(val)) {
                    showFieldError(this, usernameError, 'Only letters, numbers, and underscores.');
                } else {
                    clearFieldError(this, usernameError);
                }
            });
        }

        if (termsCheckbox) {
            termsCheckbox.addEventListener('change', function () {
                if (this.checked) {
                    if (termsError) { termsError.textContent = ''; termsError.style.display = 'none'; }
                    if (termsGroup) termsGroup.classList.remove('error');
                }
            });
        }

        // ============================================
        // STEP VALIDATORS
        // ============================================

        function validateStep1() {
            clearStepErrors(1);
            var valid = true;

            var fnVal = firstName ? firstName.value.trim() : '';
            if (fnVal.length < 2) {
                showFieldError(firstName, firstNameError, 'First name must be at least 2 characters.');
                valid = false;
            } else if (!NAME_PATTERN.test(fnVal)) {
                showFieldError(firstName, firstNameError, 'First name contains invalid characters.');
                valid = false;
            }

            var lnVal = lastName ? lastName.value.trim() : '';
            if (lnVal.length < 2) {
                showFieldError(lastName, lastNameError, 'Last name must be at least 2 characters.');
                valid = false;
            } else if (!NAME_PATTERN.test(lnVal)) {
                showFieldError(lastName, lastNameError, 'Last name contains invalid characters.');
                valid = false;
            }

            if (!birthdate || !birthdate.value) {
                showFieldError(birthdate, birthdateError, 'Please select your date of birth.');
                valid = false;
            } else {
                var bd  = new Date(birthdate.value);
                var now = new Date();
                var age = now.getFullYear() - bd.getFullYear();
                if (now.getMonth() < bd.getMonth() ||
                    (now.getMonth() === bd.getMonth() && now.getDate() < bd.getDate())) {
                    age--;
                }
                if (age < 18) {
                    showFieldError(birthdate, birthdateError, 'You must be at least 18 years old.');
                    valid = false;
                } else if (age > 70) {
                    showFieldError(birthdate, birthdateError, 'Please enter a valid date of birth.');
                    valid = false;
                }
            }

            if (!gender || !gender.value) {
                showFieldError(gender, genderError, 'Please select your gender.');
                valid = false;
            }

            var emailVal = email ? email.value.trim() : '';
            if (!emailVal) {
                showFieldError(email, emailError, 'Please enter your email address.');
                valid = false;
            } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
                showFieldError(email, emailError, 'Please enter a valid email address.');
                valid = false;
            }

            var contactVal = contactNumber ? contactNumber.value.trim() : '';
            if (!contactVal) {
                showFieldError(contactNumber, contactError, 'Please enter your mobile number.');
                valid = false;
            } else {
                var ph = isValidPhilippineMobile(contactVal);
                if (!ph.valid) {
                    showFieldError(contactNumber, contactError, ph.message);
                    valid = false;
                }
            }

            var unVal = username ? username.value.trim() : '';
            if (!unVal) {
                showFieldError(username, usernameError, 'Please choose a username.');
                valid = false;
            } else if (unVal.length < 3) {
                showFieldError(username, usernameError, 'Username must be at least 3 characters.');
                valid = false;
            } else if (unVal.length > 20) {
                showFieldError(username, usernameError, 'Username must be no more than 20 characters.');
                valid = false;
            } else if (!/^[A-Za-z0-9_]+$/.test(unVal)) {
                showFieldError(username, usernameError, 'Only letters, numbers, and underscores.');
                valid = false;
            }

            // Passwords are OPTIONAL on this page.
            //
            //   both blank   → keep the current password
            //   both filled  → new password, validated and matched
            //   only one set → refused
            var pwVal = password ? password.value : '';
            var cpVal = confirmPassword ? confirmPassword.value : '';
            if (pwVal !== '') {
                var pwCheck = isValidPassword(pwVal);
                if (!pwCheck.valid) {
                    showFieldError(password, passwordError, pwCheck.message);
                    valid = false;
                }
                if (cpVal === '') {
                    showFieldError(confirmPassword, confirmError, 'Please confirm your new password.');
                    valid = false;
                } else if (pwVal !== cpVal) {
                    showFieldError(confirmPassword, confirmError, 'Passwords do not match.');
                    valid = false;
                }
            } else if (cpVal !== '') {
                showFieldError(
                    password,
                    passwordError,
                    'Please enter a new password, or leave both fields blank to keep your current one.'
                );
                valid = false;
            }

            return valid;
        }

        function validateStep2() {
            clearStepErrors(2);
            var valid = true;

            var vtVal = vehicleType ? vehicleType.value : '';
            if (!vtVal) {
                showFieldError(vehicleType, vehicleTypeError, 'Please select your vehicle type.');
                valid = false;
            }

            var isMotorVehicle = (vtVal !== '' && vtVal !== 'bicycle');
            if (isMotorVehicle) {
                var plateVal = vehiclePlate ? vehiclePlate.value.trim() : '';
                if (!plateVal) {
                    showFieldError(vehiclePlate, vehiclePlateError, 'Plate number is required for motor vehicles.');
                    valid = false;
                } else if (plateVal.replace(/\s/g, '').length < 4) {
                    showFieldError(vehiclePlate, vehiclePlateError, 'Plate number looks too short.');
                    valid = false;
                }
            }

            if (vehicleYear && vehicleYear.value) {
                var y = parseInt(vehicleYear.value, 10);
                var maxYear = new Date().getFullYear() + 1;
                if (isNaN(y) || y < 1980 || y > maxYear) {
                    showFieldError(vehicleYear, vehicleYearError, 'Please enter a valid year.');
                    valid = false;
                }
            }

            return valid;
        }

        function validateStep3() {
            clearStepErrors(3);
            var valid = true;

            var blockVal = block ? block.value.trim() : '';
            if (!blockVal) {
                showFieldError(block, blockError, 'Block / Street / Unit is required.');
                valid = false;
            }

            var cityVal = city ? city.value.trim() : '';
            if (!cityVal) {
                showFieldError(city, cityError, 'City or municipality is required.');
                valid = false;
            }

            if (postalCode && postalCode.value.trim() !== '' &&
                !/^[0-9]{3,10}$/.test(postalCode.value.trim())) {
                showFieldError(postalCode, postalError, 'Postal code must be numeric.');
                valid = false;
            }

            var efnVal = emergencyFirstName ? emergencyFirstName.value.trim() : '';
            if (efnVal.length < 2) {
                showFieldError(emergencyFirstName, emergencyFirstNameError, 'Emergency contact first name is required.');
                valid = false;
            }

            var elnVal = emergencyLastName ? emergencyLastName.value.trim() : '';
            if (elnVal.length < 2) {
                showFieldError(emergencyLastName, emergencyLastNameError, 'Emergency contact last name is required.');
                valid = false;
            }

            var erVal = emergencyRel ? emergencyRel.value : '';
            if (!erVal) {
                showFieldError(emergencyRel, emergencyRelError, 'Please select a relationship.');
                valid = false;
            }

            var ecVal = emergencyContact ? emergencyContact.value.trim() : '';
            if (!ecVal) {
                showFieldError(emergencyContact, emergencyContactError, 'Please enter an emergency contact number.');
                valid = false;
            } else {
                var ecPhone = isValidPhilippineMobile(ecVal);
                if (!ecPhone.valid) {
                    showFieldError(emergencyContact, emergencyContactError, ecPhone.message);
                    valid = false;
                } else {
                    var ownDigits = normalizeDigits(contactNumber ? contactNumber.value : '');
                    var ecDigits  = normalizeDigits(ecVal);
                    if (ownDigits !== '' && ownDigits === ecDigits) {
                        showFieldError(
                            emergencyContact,
                            emergencyContactError,
                            'Emergency contact number must be different from your own contact number.'
                        );
                        valid = false;
                    }
                }
            }

            return valid;
        }

        function validateStep4() {
            clearStepErrors(4);
            var valid = true;

            if (!profileInput || !profileInput.files || !profileInput.files[0]) {
                showFieldError(profileInput, profilePicError, 'Please upload a formal profile picture.');
                valid = false;
            } else {
                var pf = profileInput.files[0];
                if (ALLOWED_IMAGE_TYPES.indexOf(pf.type) === -1) {
                    showFieldError(profileInput, profilePicError, 'Unsupported file type.');
                    valid = false;
                } else if (pf.size > 2 * 1024 * 1024) {
                    showFieldError(profileInput, profilePicError, 'File must be under 2 MB.');
                    valid = false;
                }
            }

            var vtVal = vehicleType ? vehicleType.value : '';
            var isBicycle = (vtVal === 'bicycle');
            var idLabel = isBicycle ? 'a valid government-issued ID' : "your driver's license";

            if (!licenseInput || !licenseInput.files || !licenseInput.files[0]) {
                showFieldError(licenseInput, licenseError, 'Please upload a photo of ' + idLabel + '.');
                valid = false;
            } else {
                var lf = licenseInput.files[0];
                if (ALLOWED_IMAGE_TYPES.indexOf(lf.type) === -1) {
                    showFieldError(licenseInput, licenseError, 'Unsupported file type.');
                    valid = false;
                } else if (lf.size > 5 * 1024 * 1024) {
                    showFieldError(licenseInput, licenseError, 'File must be under 5 MB.');
                    valid = false;
                }
            }

            if (!isBicycle) {
                if (!licenseIssue || !licenseIssue.value) {
                    showFieldError(licenseIssue, licenseIssueError, 'Please enter the license issue date.');
                    valid = false;
                } else {
                    var issue = new Date(licenseIssue.value);
                    if (issue > new Date()) {
                        showFieldError(licenseIssue, licenseIssueError, 'Issue date cannot be in the future.');
                        valid = false;
                    }
                }

                if (!licenseExpiry || !licenseExpiry.value) {
                    showFieldError(licenseExpiry, licenseExpiryError, 'Please enter the license expiry date.');
                    valid = false;
                } else {
                    var expiry = new Date(licenseExpiry.value);
                    if (expiry <= new Date()) {
                        showFieldError(licenseExpiry, licenseExpiryError, 'Expiry date must be in the future.');
                        valid = false;
                    }
                    if (licenseIssue && licenseIssue.value) {
                        var issueD = new Date(licenseIssue.value);
                        if (expiry <= issueD) {
                            showFieldError(licenseExpiry, licenseExpiryError, 'Expiry must be after issue date.');
                            valid = false;
                        }
                    }
                }
            }

            return valid;
        }

        function validateStep5() {
            clearStepErrors(5);
            var valid = true;

            if (!termsCheckbox || !termsCheckbox.checked) {
                if (termsError) {
                    termsError.textContent = 'You must agree to the Terms and Privacy Policy.';
                    termsError.style.display = 'block';
                }
                if (termsGroup) termsGroup.classList.add('error');
                valid = false;
            }

            return valid;
        }

        function validateStep(stepNumber) {
            switch (stepNumber) {
                case 1: return validateStep1();
                case 2: return validateStep2();
                case 3: return validateStep3();
                case 4: return validateStep4();
                case 5: return validateStep5();
                default: return true;
            }
        }

        // ============================================
        // STEP NAVIGATION
        // ============================================

        function goToStep(stepNumber) {
            if (stepNumber < 1 || stepNumber > TOTAL_STEPS) return;

            if (stepNumber > currentStep) {
                if (!validateStep(currentStep)) {
                    focusFirstErrorInStep(currentStep);
                    return;
                }
            }

            currentStep = stepNumber;

            if (currentStepInput) currentStepInput.value = String(stepNumber);

            steps.forEach(function (el, index) {
                el.style.display = ((index + 1) === stepNumber) ? 'block' : 'none';
            });

            progressSteps.forEach(function (el, index) {
                var n = index + 1;
                el.classList.toggle('active', n === stepNumber);
                el.classList.toggle('completed', n < stepNumber);
            });

            progressLines.forEach(function (el, index) {
                el.classList.toggle('completed', (index + 1) < stepNumber);
            });

            if (stepSubtitle) {
                stepSubtitle.textContent = STEP_TITLES[stepNumber - 1]
                    || ('Step ' + stepNumber + ' of ' + TOTAL_STEPS);
            }

            hideBanner();

            var progressBar = document.querySelector('.register-progress');
            if (progressBar) progressBar.setAttribute('aria-valuenow', String(stepNumber));

            focusFirstInputInStep(stepNumber);
        }

        nextButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var next = parseInt(this.getAttribute('data-next'), 10);
                if (!isNaN(next) && next <= TOTAL_STEPS) goToStep(next);
            });
        });

        prevButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var prev = parseInt(this.getAttribute('data-prev'), 10);
                if (!isNaN(prev) && prev >= 1) goToStep(prev);
            });
        });

        // ============================================
        // SERVER FIELD → INLINE SLOT MAP
        // ============================================

        var SERVER_FIELD_MAP = {
            first_name:             { input: firstName,          errorEl: firstNameError,          step: 1 },
            last_name:              { input: lastName,           errorEl: lastNameError,           step: 1 },
            birthdate:              { input: birthdate,          errorEl: birthdateError,          step: 1 },
            gender:                 { input: gender,             errorEl: genderError,             step: 1 },
            email:                  { input: email,              errorEl: emailError,              step: 1 },
            contact_number:         { input: contactNumber,      errorEl: contactError,            step: 1 },
            username:               { input: username,           errorEl: usernameError,           step: 1 },
            password:               { input: password,           errorEl: passwordError,           step: 1 },
            confirm_password:       { input: confirmPassword,    errorEl: confirmError,            step: 1 },

            vehicle_type:           { input: vehicleType,        errorEl: vehicleTypeError,        step: 2 },
            vehicle_plate:          { input: vehiclePlate,       errorEl: vehiclePlateError,       step: 2 },
            vehicle_year:           { input: vehicleYear,        errorEl: vehicleYearError,        step: 2 },

            block:                  { input: block,              errorEl: blockError,              step: 3 },
            city:                   { input: city,               errorEl: cityError,               step: 3 },
            postal_code:            { input: postalCode,         errorEl: postalError,             step: 3 },
            emergency_first_name:   { input: emergencyFirstName, errorEl: emergencyFirstNameError, step: 3 },
            emergency_last_name:    { input: emergencyLastName,  errorEl: emergencyLastNameError,  step: 3 },
            emergency_relationship: { input: emergencyRel,       errorEl: emergencyRelError,       step: 3 },
            emergency_contact:      { input: emergencyContact,   errorEl: emergencyContactError,   step: 3 },

            profile_picture:        { input: profileInput,       errorEl: profilePicError,         step: 4 },
            drivers_license:        { input: licenseInput,       errorEl: licenseError,            step: 4 },
            license_issue_date:     { input: licenseIssue,       errorEl: licenseIssueError,       step: 4 },
            license_expiry_date:    { input: licenseExpiry,      errorEl: licenseExpiryError,      step: 4 },

            upload:                 { input: null,               errorEl: null,                    step: 4 },
            terms:                  { input: null,               errorEl: null,                    step: 5 },
        };

        // ============================================
        // FORM SUBMISSION
        // ============================================

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (isSubmitting) return;

            for (var s = 1; s <= TOTAL_STEPS; s++) {
                if (!validateStep(s)) {
                    goToStep(s);
                    focusFirstErrorInStep(s);
                    return;
                }
            }

            isSubmitting = true;
            if (registerBtn) {
                registerBtn.disabled = true;
                registerBtn.textContent = 'Submitting…';
            }

            fetch(ENDPOINT, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin'
            })
                .then(function (res) {
                    return res.json().catch(function () {
                        throw new Error('Unexpected server response.');
                    });
                })
                .then(function (data) {
                    if (data && data.status === 'success') {
                        window.location.href = data.redirect || 'dashboard.php';
                        return;
                    }

                    isSubmitting = false;
                    if (registerBtn) {
                        registerBtn.disabled = false;
                        registerBtn.textContent = 'Submit Updated Application';
                    }

                    if (data && data.field === 'terms') {
                        goToStep(5);
                        if (termsError) {
                            termsError.textContent = data.message;
                            termsError.style.display = 'block';
                        }
                        if (termsGroup) termsGroup.classList.add('error');
                        return;
                    }

                    if (data && data.field === 'upload') {
                        var message = (data && data.message) || '';
                        goToStep(4);
                        if (/license|ID|government/i.test(message)) {
                            showFieldError(licenseInput, licenseError, message);
                        } else {
                            showFieldError(profileInput, profilePicError, message);
                        }
                        return;
                    }

                    var target = (data && data.field && SERVER_FIELD_MAP[data.field])
                        ? SERVER_FIELD_MAP[data.field]
                        : null;

                    if (target && target.input && target.errorEl) {
                        if (target.step) goToStep(target.step);
                        showFieldError(target.input, target.errorEl, data.message);
                        if (typeof target.input.focus === 'function') {
                            target.input.focus();
                        }
                        return;
                    }

                    showBanner((data && data.message) || 'Could not submit your application. Please try again.');
                })
                .catch(function () {
                    isSubmitting = false;
                    if (registerBtn) {
                        registerBtn.disabled = false;
                        registerBtn.textContent = 'Submit Updated Application';
                    }
                    showBanner('An unexpected error occurred. Please try again.');
                });
        });

        // ============================================
        // INITIAL STATE
        // ============================================

        goToStep(1);
    });
})();