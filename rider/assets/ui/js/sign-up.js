/**
 * FitPal Rider Registration JavaScript
 *
 * Five-step application form with:
 *   - Field-level validation and error clearing
 *   - Automatic name and address capitalization
 *   - Title-case capitalization for vehicle make/model
 *   - Email lowercasing
 *   - Password rule feedback
 *   - File upload previews for the profile picture and identity document
 *   - Vehicle-dependent identity-document block:
 *       - bicycle       → "Valid Government ID" copy, no license dates
 *       - any other     → "Driver's License" copy, license dates required
 *   - Vehicle-dependent Step 2 layout:
 *       - bicycle       → plate/make/model/year hidden and cleared
 *       - any other     → plate/make/model/year visible
 *   - Emergency-contact validation, including the rule that the
 *     emergency contact number must differ from the rider's own
 *     contact number
 *   - Review summary build on Step 5
 *   - Fetch-based submission with per-field server error routing
 *
 * IMPORTANT — WHERE THE UPLOADS LIVE
 * ----------------------------------
 * The profile-picture and identity-document inputs live physically
 * inside Step 4 in the markup. That means:
 *
 *   - validateStep1 / validateStep2 / validateStep3 must NOT check the
 *     upload inputs, or clicking "Next Step" from those steps will fail
 *     because the file inputs are still empty at that point.
 *   - The uploads are checked in validateStep4(), which runs when the
 *     user leaves Step 4.
 *
 * All real validation also runs server-side in sign-up-handler.php.
 * This file is a UX layer only.
 *
 * Vehicle-type-driven UI
 * ----------------------
 * Two regions of the form respond to the vehicle type selected in
 * Step 2. Both are driven by a single page-level data attribute that
 * this file writes:
 *
 *     .register-page[data-vehicle-type="bicycle"]
 *
 * Region 1 — Step 2 motor-vehicle-only fields (#vehicleMotorOnlyFields)
 *   When the vehicle is 'bicycle', the wrapper is hidden by a CSS
 *   rule keyed on the attribute, and this file also clears the plate,
 *   make, model, and year inputs. Clearing is necessary because
 *   hiding an input via CSS does not empty it: a value typed while
 *   'motorcycle' was selected would otherwise persist in the DOM
 *   and be posted on submit. The handler drops those fields for a
 *   bicycle anyway (the delivery_rider_profile schema has no
 *   columns for them), but leaving stale values in the DOM would
 *   also leak them into the Step 5 review summary. Clearing keeps
 *   the DOM, the review, and the submission in agreement.
 *
 * Region 2 — Step 4 identity-document block + license dates
 *   When the vehicle is 'bicycle', CSS swaps to the government-ID
 *   heading and guidelines, and hides the license-date row. This
 *   file toggles the `required` attribute on the two date inputs
 *   (a DOM property, not a style) and clears any stale errors on
 *   them. validateLicenseDates() short-circuits to true for
 *   bicycle.
 *
 * `required` is toggled by JS, not CSS, because CSS cannot remove a
 * DOM property and the browser will refuse to submit a form with an
 * empty `required` input even when that input is hidden.
 *
 * Emergency-contact number must differ from the rider's own number
 * -----------------------------------------------------------------
 * The rider's own contact_number and the emergency contact's number
 * are two different people's phone numbers. If an applicant enters
 * the same digits in both fields, the emergency contact is not
 * actually a distinct person and the field does not serve its
 * purpose. Two layers enforce this:
 *
 *   - Client (this file): validateStep3() compares the two inputs
 *     after stripping every non-digit character, so "0917 123 4567"
 *     and "09171234567" are treated as the same number. On a match,
 *     the error is attached to the emergency contact field and Step
 *     3 fails. The check also fires implicitly on final submit via
 *     goToStep()'s guard.
 *
 *   - Server (sign-up-handler.php): the same comparison runs against
 *     the two normalized digit-only strings. A client that skips
 *     JavaScript or posts directly to the handler cannot bypass the
 *     rule. The server's refusal carries field='emergency_contact',
 *     so the message lands on the right input.
 *
 * Comparing digit-only strings rather than raw values means the
 * check is stable across the formatting the input filter allows
 * (spaces are stripped live) and across any future mask that might
 * admit hyphens or parentheses on the server side.
 *
 * @package FitPal
 * @version 5.3 — validateStep3() now rejects an emergency contact
 *                number that normalizes to the same digits as the
 *                rider's own contact number. A new helper
 *                normalizeDigits() is the single place that rule is
 *                expressed, so the client and the server stay in
 *                agreement on what "the same number" means.
 *
 *                No other function, filter, or submission path
 *                changed from v5.2.
 *
 *                (5.2: applyVehicleTypeDependentUI() clears the
 *                plate/make/model/year inputs when the vehicle is a
 *                bicycle. 5.1: plate group reached by id from CSS.
 *                5.0: added applyVehicleTypeDependentUI() and the
 *                vehicle-aware validateStep4() and review summary.)
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ============================================
        // DOM REFERENCES
        // ============================================
        const form = document.getElementById('registerForm');
        if (!form) return;

        const pageRoot         = document.querySelector('.register-page');
        const steps            = document.querySelectorAll('.register-step');
        const progressSteps    = document.querySelectorAll('.progress-step');
        const progressLines    = document.querySelectorAll('.progress-line');
        const stepSubtitle     = document.getElementById('stepSubtitle');
        const currentStepInput = document.getElementById('currentStep');
        const registerError    = document.getElementById('registerError');
        const errorMessage     = document.getElementById('errorMessage');

        const nextButtons = document.querySelectorAll('.btn-next');
        const prevButtons = document.querySelectorAll('.btn-prev');

        // Step 1
        const firstName       = document.getElementById('first_name');
        const middleName      = document.getElementById('middle_name');
        const lastName        = document.getElementById('last_name');
        const birthdate       = document.getElementById('birthdate');
        const gender          = document.getElementById('gender');
        const email           = document.getElementById('email');
        const contactNumber   = document.getElementById('contact_number');
        const username        = document.getElementById('username');
        const password        = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');

        // Step 2
        const vehicleType  = document.getElementById('vehicle_type');
        const vehiclePlate = document.getElementById('vehicle_plate');
        const vehicleMake  = document.getElementById('vehicle_make');
        const vehicleModel = document.getElementById('vehicle_model');
        const vehicleYear  = document.getElementById('vehicle_year');

        // Step 3
        const block               = document.getElementById('block');
        const barangay            = document.getElementById('barangay');
        const city                = document.getElementById('city');
        const province            = document.getElementById('province');
        const region              = document.getElementById('region');
        const postalCode          = document.getElementById('postal_code');
        const emergencyFirstName  = document.getElementById('emergency_first_name');
        const emergencyMiddleName = document.getElementById('emergency_middle_name');
        const emergencyLastName   = document.getElementById('emergency_last_name');
        const emergencyRel        = document.getElementById('emergency_relationship');
        const emergencyContact    = document.getElementById('emergency_contact');

        // Step 4 — uploads + license dates
        const profileInput      = document.getElementById('profile_picture');
        const profileDropzone   = document.getElementById('profileDropzone');
        const profilePreview    = document.getElementById('profilePreview');
        const profilePreviewImg = document.getElementById('profilePreviewImg');
        const profileHint       = document.getElementById('profileHint');
        const profileRemove     = document.getElementById('profileRemove');
        const profilePicError   = document.getElementById('profilePictureError');

        const licenseInput      = document.getElementById('drivers_license');
        const licenseDropzone   = document.getElementById('licenseDropzone');
        const licensePreview    = document.getElementById('licensePreview');
        const licensePreviewImg = document.getElementById('licensePreviewImg');
        const licenseHint       = document.getElementById('licenseHint');
        const licenseRemove     = document.getElementById('licenseRemove');
        const licenseError      = document.getElementById('driversLicenseError');

        const licenseIssue     = document.getElementById('license_issue_date');
        const licenseExpiry    = document.getElementById('license_expiry_date');
        const licenseIssueError  = document.getElementById('licenseIssueError');
        const licenseExpiryError = document.getElementById('licenseExpiryError');

        // Step 5 — review + terms + submit
        const termsCheckbox         = document.getElementById('terms');
        const termsGroup            = document.getElementById('termsGroup');
        const termsError            = document.getElementById('termsError');
        const registerBtn           = document.getElementById('registerBtn');
        const reviewIdDocLabel      = document.getElementById('reviewIdDocLabel');
        const reviewLicenseDates    = document.getElementById('reviewLicenseDates');
        const reviewLicenseDatesRow = document.getElementById('reviewLicenseDatesRow');

        // Error elements
        const firstNameError          = document.getElementById('firstNameError');
        const lastNameError           = document.getElementById('lastNameError');
        const birthdateError          = document.getElementById('birthdateError');
        const genderError             = document.getElementById('genderError');
        const emailError              = document.getElementById('emailError');
        const contactError            = document.getElementById('contactError');
        const usernameError           = document.getElementById('usernameError');
        const passwordError           = document.getElementById('passwordError');
        const confirmError            = document.getElementById('confirmError');
        const vehicleTypeError        = document.getElementById('vehicleTypeError');
        const vehiclePlateError       = document.getElementById('vehiclePlateError');
        const vehicleYearError        = document.getElementById('vehicleYearError');
        const blockError              = document.getElementById('blockError');
        const cityError               = document.getElementById('cityError');
        const postalError             = document.getElementById('postalError');
        const emergencyFirstNameError = document.getElementById('emergencyFirstNameError');
        const emergencyLastNameError  = document.getElementById('emergencyLastNameError');
        const emergencyRelError       = document.getElementById('emergencyRelationshipError');
        const emergencyContactError   = document.getElementById('emergencyContactError');

        const stepTitles = [
            'Step 1 of 5 — Personal Information',
            'Step 2 of 5 — Vehicle Details',
            'Step 3 of 5 — Address & Emergency Contact',
            'Step 4 of 5 — Verification Uploads',
            'Step 5 of 5 — Review & Submit',
        ];

        let currentStep = 1;
        const totalSteps = 5;
        let isSubmitting = false;

        const NAME_PATTERN = /^[A-Za-z\s\-']+$/;
        const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

        // ============================================
        // HELPERS
        // ============================================

        /**
         * Reduce a phone number to its digit-only form.
         *
         * Used by the client-side emergency-contact comparison and by
         * nothing else. The server has its own equivalent reduction
         * in sign-up-handler.php ($cleanedContact / $cleanedEcContact),
         * built on the same /[^0-9]/ rule so the two layers agree on
         * what "the same number" means.
         *
         * "0917 123 4567" and "09171234567" both normalize to
         * "09171234567". A future mask that admits hyphens or
         * parentheses would also be covered by this rule.
         *
         * @param {string} value
         * @returns {string}
         */
        function normalizeDigits(value) {
            return String(value || '').replace(/[^0-9]/g, '');
        }

        function isValidPhilippineMobile(number) {
            const cleaned = String(number).replace(/\s/g, '');
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

        function clearStepErrors(step) {
            const stepEl = document.getElementById('step' + step);
            if (!stepEl) return;

            stepEl.querySelectorAll('.form-error').forEach(function (el) {
                el.textContent = '';
                el.style.display = 'none';
            });
            stepEl.querySelectorAll('.form-control').forEach(function (el) {
                el.classList.remove('error');
            });

            if (step === 5 && termsGroup) {
                termsGroup.classList.remove('error');
                if (termsError) {
                    termsError.textContent = '';
                    termsError.style.display = 'none';
                }
            }
        }

        function safeFocus(el) {
            if (el && typeof el.focus === 'function') {
                setTimeout(function () { el.focus(); }, 80);
            }
        }

        // ============================================
        // INPUT FILTERS
        // ============================================

        /**
         * Name-style filter: letters, spaces, hyphens, apostrophes.
         * Auto-capitalizes the first letter of each word.
         */
        function setupNameInput(input, errorEl) {
            if (!input) return;

            input.addEventListener('input', function () {
                const start = this.selectionStart;
                const filtered = this.value.replace(/[^A-Za-z\s\-']/g, '');
                const capitalized = filtered.replace(/\b\w/g, function (c) { return c.toUpperCase(); });

                if (this.value !== capitalized) {
                    this.value = capitalized;
                    const newStart = Math.min(start, this.value.length);
                    this.setSelectionRange(newStart, newStart);
                }
                if (errorEl) clearFieldError(this, errorEl);
            });

            input.addEventListener('blur', function () {
                if (this.value.length > 0) {
                    const capitalized = this.value.replace(/\b\w/g, function (c) { return c.toUpperCase(); });
                    if (this.value !== capitalized) this.value = capitalized;
                }
            });
        }

        /**
         * Title-case filter for vehicle make/model.
         * Permits letters, digits, spaces, hyphens, dots, slashes
         * so values like "Click 125i", "CR-V", "R 1250 GS" survive.
         * Auto-capitalizes the first letter of each word.
         */
        function setupTitleCaseInput(input, errorEl) {
            if (!input) return;

            input.addEventListener('input', function () {
                const start = this.selectionStart;
                const filtered = this.value.replace(/[^A-Za-z0-9\s\-./]/g, '');
                const capitalized = filtered.replace(/\b\w/g, function (c) { return c.toUpperCase(); });

                if (this.value !== capitalized) {
                    this.value = capitalized;
                    const newStart = Math.min(start, this.value.length);
                    this.setSelectionRange(newStart, newStart);
                }
                if (errorEl) clearFieldError(this, errorEl);
            });

            input.addEventListener('blur', function () {
                if (this.value.length > 0) {
                    const capitalized = this.value.replace(/\b\w/g, function (c) { return c.toUpperCase(); });
                    if (this.value !== capitalized) this.value = capitalized;
                }
            });
        }

        function setupEmailInput(input) {
            if (!input) return;
            input.addEventListener('input', function () {
                const start = this.selectionStart;
                const lower = this.value.toLowerCase();
                if (this.value !== lower) {
                    this.value = lower;
                    const newStart = Math.min(start, this.value.length);
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

        setupNameInput(firstName, firstNameError);
        setupNameInput(middleName, null);
        setupNameInput(lastName, lastNameError);

        // Address fields — same capitalization + filter treatment as names.
        setupNameInput(block, blockError);
        setupNameInput(barangay, null);
        setupNameInput(city, cityError);
        setupNameInput(province, null);
        setupNameInput(region, null);

        // Emergency contact fields
        setupNameInput(emergencyFirstName, emergencyFirstNameError);
        setupNameInput(emergencyMiddleName, null);
        setupNameInput(emergencyLastName, emergencyLastNameError);

        // Vehicle make/model — title-case with digit/symbol allowance.
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
            const btn   = document.getElementById(toggleId);
            const input = document.getElementById(inputId);
            const icon  = document.getElementById(iconId);
            if (!btn || !input || !icon) return;

            btn.addEventListener('click', function () {
                const isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
                const iconFile = isPassword ? 'password-unhide.svg' : 'password-hide.svg';
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
            const {
                input, dropzone, preview, previewImg, hint, removeBtn, errorEl,
                allowedTypes, maxBytes,
            } = cfg;

            if (!input || !dropzone) return { clearPreview: function () {} };

            let currentUrl = null;

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

                if (!allowedTypes.includes(file.type)) {
                    showFieldError(input, errorEl, 'Unsupported file type. Use JPG, PNG, or WEBP.');
                    clearPreview();
                    return;
                }
                if (file.size > maxBytes) {
                    const maxMB = Math.round(maxBytes / 1048576 * 10) / 10;
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
                const dt = e.dataTransfer;
                if (!dt || !dt.files || !dt.files[0]) return;
                const file = dt.files[0];
                try {
                    const transfer = new DataTransfer();
                    transfer.items.add(file);
                    input.files = transfer.files;
                } catch (err) {
                    console.warn('[sign-up] Could not attach dropped file to input.');
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

            return { clearPreview };
        }

        wireUploader({
            input:        profileInput,
            dropzone:     profileDropzone,
            preview:      profilePreview,
            previewImg:   profilePreviewImg,
            hint:         profileHint,
            removeBtn:    profileRemove,
            errorEl:      profilePicError,
            allowedTypes: ALLOWED_IMAGE_TYPES,
            maxBytes:     2 * 1024 * 1024,
        });

        wireUploader({
            input:        licenseInput,
            dropzone:     licenseDropzone,
            preview:      licensePreview,
            previewImg:   licensePreviewImg,
            hint:         licenseHint,
            removeBtn:    licenseRemove,
            errorEl:      licenseError,
            allowedTypes: ALLOWED_IMAGE_TYPES,
            maxBytes:     5 * 1024 * 1024,
        });

        // ============================================
        // VEHICLE-TYPE-DEPENDENT UI
        //
        // Keeps the whole form in sync with the vehicle the applicant
        // selected in Step 2. The visual swaps live in sign-up.css:
        //
        //   .register-page[data-vehicle-type="bicycle"]        → bicycle shape
        //   .register-page[data-vehicle-type="<other>|''"]     → motor-vehicle shape
        //
        // This function's jobs are:
        //
        //   1. write the data attribute on the page root, and
        //   2. toggle the `required` attribute on the two license date
        //      inputs, and
        //   3. clear the plate/make/model/year inputs when the vehicle
        //      becomes bicycle.
        //
        // Job 2 is JS-side, not CSS-side, because `required` is a DOM
        // property. CSS cannot remove it, and the browser will refuse
        // to submit the form if a `required` input is empty — even
        // when the input is hidden by a CSS rule.
        //
        // Job 3 is JS-side because hiding an input via CSS does not
        // empty it. A value typed while a motor-vehicle option was
        // selected would persist in the DOM and would be posted on
        // submit. The handler drops those fields for a bicycle, but
        // the stale values would still leak into the Step 5 review
        // summary. Clearing keeps the DOM, the review, and the
        // submitted form in agreement.
        // ============================================

        function applyVehicleTypeDependentUI() {
            if (!vehicleType || !pageRoot) return;

            const selectedValue = vehicleType.value;
            const isBicycle     = (selectedValue === 'bicycle');

            // Page-level attribute drives every CSS swap. Empty
            // string when nothing is selected yet — CSS treats an
            // empty value the same as any non-bicycle value.
            pageRoot.setAttribute('data-vehicle-type', selectedValue || '');

            // License date inputs are required for any non-bicycle
            // vehicle and skipped for bicycle. Toggling the attribute
            // here (not just hiding the row) is what stops the browser
            // from blocking a bicycle applicant's submit with a
            // "please fill out this field" tooltip on an input they
            // cannot see.
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

            // Motor-vehicle-only fields: when the vehicle is a
            // bicycle, the whole #vehicleMotorOnlyFields container is
            // hidden by CSS. This block empties the inputs so a
            // value typed while a motor vehicle was selected does
            // not persist in the DOM after the switch.
            //
            // The three optional inputs (make, model, year) are
            // cleared along with the plate: none of them has a
            // sensible value for a bicycle, and clearing them keeps
            // the Step 5 review summary and the submitted form
            // consistent with what the applicant actually sees.
            if (isBicycle) {
                if (vehiclePlate) vehiclePlate.value = '';
                if (vehicleMake)  vehicleMake.value  = '';
                if (vehicleModel) vehicleModel.value = '';
                if (vehicleYear)  vehicleYear.value  = '';

                // Clear any stale errors on those fields, since they
                // are about to be hidden.
                clearFieldError(vehiclePlate, vehiclePlateError);
                clearFieldError(vehicleYear,  vehicleYearError);
            }

            // Clear any stale errors on the two license date fields
            // when the applicant switches to bicycle, since the
            // fields are about to be hidden.
            if (isBicycle) {
                clearFieldError(licenseIssue, licenseIssueError);
                clearFieldError(licenseExpiry, licenseExpiryError);
            }

            // Step 5 review label — kept in sync so that the summary
            // is correct even if the applicant never re-visits Step 4
            // after changing Step 2.
            if (reviewIdDocLabel) {
                reviewIdDocLabel.textContent = isBicycle
                    ? 'Valid Government ID'
                    : "Driver's License";
            }

            // The license-validity row in the review summary is not
            // applicable for a bicycle applicant. Hide it entirely
            // rather than showing a placeholder dash.
            if (reviewLicenseDatesRow) {
                reviewLicenseDatesRow.style.display = isBicycle ? 'none' : '';
            }
        }

        if (vehicleType) {
            vehicleType.addEventListener('change', applyVehicleTypeDependentUI);
            // Apply once on load so the DOM matches the initial
            // select value. On a fresh page load the select is empty
            // and the motor-vehicle shape is the default; the
            // attribute is set to '' which the CSS treats the same
            // as "motor-vehicle shape".
            applyVehicleTypeDependentUI();
        }

        // ============================================
        // VALIDATION — STEP 1
        // ============================================

        function validateStep1() {
            let valid = true;
            clearStepErrors(1);

            const fnVal = firstName ? firstName.value.trim() : '';
            if (fnVal.length < 2) {
                showFieldError(firstName, firstNameError, 'First name must be at least 2 characters.');
                valid = false;
            } else if (!NAME_PATTERN.test(fnVal)) {
                showFieldError(firstName, firstNameError, 'First name contains invalid characters.');
                valid = false;
            }

            const lnVal = lastName ? lastName.value.trim() : '';
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
                const bd = new Date(birthdate.value);
                const now = new Date();
                let age = now.getFullYear() - bd.getFullYear();
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

            const emailVal = email ? email.value.trim() : '';
            if (!emailVal) {
                showFieldError(email, emailError, 'Please enter your email address.');
                valid = false;
            } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
                showFieldError(email, emailError, 'Please enter a valid email address.');
                valid = false;
            }

            const contactVal = contactNumber ? contactNumber.value.trim() : '';
            if (!contactVal) {
                showFieldError(contactNumber, contactError, 'Please enter your mobile number.');
                valid = false;
            } else {
                const ph = isValidPhilippineMobile(contactVal);
                if (!ph.valid) {
                    showFieldError(contactNumber, contactError, ph.message);
                    valid = false;
                }
            }

            const unVal = username ? username.value.trim() : '';
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

            const pwVal = password ? password.value : '';
            if (!pwVal) {
                showFieldError(password, passwordError, 'Please create a password.');
                valid = false;
            } else {
                const pwCheck = isValidPassword(pwVal);
                if (!pwCheck.valid) {
                    showFieldError(password, passwordError, pwCheck.message);
                    valid = false;
                }
            }

            const cpVal = confirmPassword ? confirmPassword.value : '';
            if (!cpVal) {
                showFieldError(confirmPassword, confirmError, 'Please confirm your password.');
                valid = false;
            } else if (pwVal !== cpVal) {
                showFieldError(confirmPassword, confirmError, 'Passwords do not match.');
                valid = false;
            }

            return valid;
        }

        // ============================================
        // VALIDATION — STEP 2
        // ============================================

        function validateStep2() {
            let valid = true;
            clearStepErrors(2);

            if (!vehicleType || !vehicleType.value) {
                showFieldError(vehicleType, vehicleTypeError, 'Please select your vehicle type.');
                valid = false;
            }

            // The plate is required for any non-bicycle vehicle. The
            // entire motor-vehicle-only block (#vehicleMotorOnlyFields)
            // is hidden by CSS when the vehicle is a bicycle, so a
            // bicycle applicant never sees or fills the plate input.
            // The rule below is the one that actually enforces
            // "required for motor vehicles" — matching the markup's
            // default `required` attribute and the server-side check
            // in sign-up-handler.php.
            const isMotorVehicle = vehicleType && vehicleType.value && vehicleType.value !== 'bicycle';
            if (isMotorVehicle) {
                const plateVal = vehiclePlate ? vehiclePlate.value.trim() : '';
                if (!plateVal) {
                    showFieldError(vehiclePlate, vehiclePlateError, 'Plate number is required for motor vehicles.');
                    valid = false;
                } else if (plateVal.replace(/\s/g, '').length < 4) {
                    showFieldError(vehiclePlate, vehiclePlateError, 'Plate number looks too short.');
                    valid = false;
                }
            }

            if (vehicleYear && vehicleYear.value) {
                const y = parseInt(vehicleYear.value, 10);
                const maxYear = new Date().getFullYear() + 1;
                if (isNaN(y) || y < 1980 || y > maxYear) {
                    showFieldError(vehicleYear, vehicleYearError, 'Please enter a valid year.');
                    valid = false;
                }
            }

            return valid;
        }

        // ============================================
        // VALIDATION — STEP 3
        // ============================================

        function validateStep3() {
            let valid = true;
            clearStepErrors(3);

            const blockVal = block ? block.value.trim() : '';
            if (!blockVal) {
                showFieldError(block, blockError, 'Block / Street / Unit is required.');
                valid = false;
            }

            const cityVal = city ? city.value.trim() : '';
            if (!cityVal) {
                showFieldError(city, cityError, 'City or municipality is required.');
                valid = false;
            }

            const efnVal = emergencyFirstName ? emergencyFirstName.value.trim() : '';
            if (efnVal.length < 2) {
                showFieldError(emergencyFirstName, emergencyFirstNameError, 'Emergency contact first name is required.');
                valid = false;
            }

            const elnVal = emergencyLastName ? emergencyLastName.value.trim() : '';
            if (elnVal.length < 2) {
                showFieldError(emergencyLastName, emergencyLastNameError, 'Emergency contact last name is required.');
                valid = false;
            }

            const erVal = emergencyRel ? emergencyRel.value : '';
            if (!erVal) {
                showFieldError(emergencyRel, emergencyRelError, 'Please select a relationship.');
                valid = false;
            }

            const ecVal = emergencyContact ? emergencyContact.value.trim() : '';
            if (!ecVal) {
                showFieldError(emergencyContact, emergencyContactError, 'Please enter an emergency contact number.');
                valid = false;
            } else {
                const ph = isValidPhilippineMobile(ecVal);
                if (!ph.valid) {
                    showFieldError(emergencyContact, emergencyContactError, ph.message);
                    valid = false;
                } else {
                    // The emergency contact number must differ from
                    // the rider's own number. Both sides are reduced
                    // to digit-only strings first, so a differently
                    // spaced form of the same digits is still caught.
                    // The rider's own number is validated in Step 1
                    // and is available in the DOM here regardless of
                    // which step is currently on screen.
                    const ownDigits = normalizeDigits(
                        contactNumber ? contactNumber.value : ''
                    );
                    const ecDigits  = normalizeDigits(ecVal);

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

        // ============================================
        // VALIDATION — STEP 4 (uploads + license dates)
        // ============================================

        function validateUploads() {
            let valid = true;

            // Profile picture
            if (!profileInput || !profileInput.files || !profileInput.files[0]) {
                showFieldError(profileInput, profilePicError, 'Please upload a formal profile picture.');
                valid = false;
            } else {
                const f = profileInput.files[0];
                if (!ALLOWED_IMAGE_TYPES.includes(f.type)) {
                    showFieldError(profileInput, profilePicError, 'Unsupported file type.');
                    valid = false;
                } else if (f.size > 2 * 1024 * 1024) {
                    showFieldError(profileInput, profilePicError, 'File must be under 2 MB.');
                    valid = false;
                }
            }

            // Identity document.
            //
            // The field name is always 'drivers_license' regardless of
            // vehicle type — the handler reads $_FILES['drivers_license']
            // and decides the correct id_type from the posted
            // vehicle_type. Only the user-facing error message is
            // tailored to which document is expected.
            const isBicycle = vehicleType && vehicleType.value === 'bicycle';
            const idLabel = isBicycle ? 'a valid government-issued ID' : "your driver's license";

            if (!licenseInput || !licenseInput.files || !licenseInput.files[0]) {
                showFieldError(licenseInput, licenseError, 'Please upload a photo of ' + idLabel + '.');
                valid = false;
            } else {
                const f = licenseInput.files[0];
                if (!ALLOWED_IMAGE_TYPES.includes(f.type)) {
                    showFieldError(licenseInput, licenseError, 'Unsupported file type.');
                    valid = false;
                } else if (f.size > 5 * 1024 * 1024) {
                    showFieldError(licenseInput, licenseError, 'File must be under 5 MB.');
                    valid = false;
                }
            }

            return valid;
        }

        function validateLicenseDates() {
            let valid = true;

            // A bicycle applicant does not submit a driver's license,
            // so neither date applies. The two inputs also lose their
            // `required` attribute in applyVehicleTypeDependentUI(),
            // so the browser will not block submission. Skipping this
            // branch here keeps the JS-side validation consistent
            // with what the DOM now says.
            const isBicycle = vehicleType && vehicleType.value === 'bicycle';
            if (isBicycle) {
                clearFieldError(licenseIssue, licenseIssueError);
                clearFieldError(licenseExpiry, licenseExpiryError);
                return true;
            }

            // Issue date — REQUIRED
            if (!licenseIssue || !licenseIssue.value) {
                showFieldError(licenseIssue, licenseIssueError, 'Please enter the license issue date.');
                valid = false;
            } else {
                const issue = new Date(licenseIssue.value);
                if (issue > new Date()) {
                    showFieldError(licenseIssue, licenseIssueError, 'Issue date cannot be in the future.');
                    valid = false;
                }
            }

            // Expiry date — REQUIRED
            if (!licenseExpiry || !licenseExpiry.value) {
                showFieldError(licenseExpiry, licenseExpiryError, 'Please enter the license expiry date.');
                valid = false;
            } else {
                const expiry = new Date(licenseExpiry.value);
                if (expiry <= new Date()) {
                    showFieldError(licenseExpiry, licenseExpiryError, 'Expiry date must be in the future.');
                    valid = false;
                }
                if (licenseIssue && licenseIssue.value) {
                    const issue = new Date(licenseIssue.value);
                    if (expiry <= issue) {
                        showFieldError(licenseExpiry, licenseExpiryError, 'Expiry must be after issue date.');
                        valid = false;
                    }
                }
            }

            return valid;
        }

        function validateStep4() {
            clearStepErrors(4);

            let valid = true;

            if (!validateUploads())      valid = false;
            if (!validateLicenseDates()) valid = false;

            return valid;
        }

        // ============================================
        // VALIDATION — STEP 5 (terms)
        // ============================================

        function validateStep5() {
            clearStepErrors(5);

            let valid = true;

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

        function validateStep(step) {
            switch (step) {
                case 1: return validateStep1();
                case 2: return validateStep2();
                case 3: return validateStep3();
                case 4: return validateStep4();
                case 5: return validateStep5();
                default: return true;
            }
        }

        // ============================================
        // REVIEW SUMMARY
        // ============================================

        function buildReviewSummary() {
            const fullName = [firstName && firstName.value, middleName && middleName.value, lastName && lastName.value]
                .map(function (v) { return (v || '').trim(); })
                .filter(Boolean)
                .join(' ');

            const emergencyFullName = [
                emergencyFirstName && emergencyFirstName.value,
                emergencyMiddleName && emergencyMiddleName.value,
                emergencyLastName && emergencyLastName.value,
            ]
                .map(function (v) { return (v || '').trim(); })
                .filter(Boolean)
                .join(' ');

            const addressParts = [block, barangay, city, province, region, postalCode]
                .map(function (el) { return (el && el.value || '').trim(); })
                .filter(Boolean);

            const vehicleTypeText = (vehicleType && vehicleType.options[vehicleType.selectedIndex])
                ? vehicleType.options[vehicleType.selectedIndex].text
                : '—';
            const plateText = (vehiclePlate && vehiclePlate.value) ? vehiclePlate.value.trim() : '—';
            const makeText  = (vehicleMake && vehicleMake.value) ? vehicleMake.value.trim() : '';
            const modelText = (vehicleModel && vehicleModel.value) ? vehicleModel.value.trim() : '';
            const makeModel = [makeText, modelText].filter(Boolean).join(' ') || '—';

            const emergencyRelText = (emergencyRel && emergencyRel.options[emergencyRel.selectedIndex])
                ? emergencyRel.options[emergencyRel.selectedIndex].text
                : '—';

            const profileFileName = (profileInput && profileInput.files && profileInput.files[0])
                ? profileInput.files[0].name
                : '—';
            const licenseFileName = (licenseInput && licenseInput.files && licenseInput.files[0])
                ? licenseInput.files[0].name
                : '—';

            // Identity-document label reflects the vehicle type. A
            // bicycle applicant submits a national ID or other
            // government-issued ID rather than a driver's license.
            const isBicycle = vehicleType && vehicleType.value === 'bicycle';
            if (reviewIdDocLabel) {
                reviewIdDocLabel.textContent = isBicycle
                    ? 'Valid Government ID'
                    : "Driver's License";
            }

            // License validity only applies when the applicant is
            // submitting a driver's license. For a bicycle applicant
            // the row is hidden and its text is left blank.
            if (reviewLicenseDatesRow) {
                reviewLicenseDatesRow.style.display = isBicycle ? 'none' : '';
            }

            let licenseDatesText = '—';
            if (!isBicycle && ((licenseIssue && licenseIssue.value) || (licenseExpiry && licenseExpiry.value))) {
                const fmt = function (d) {
                    if (!d) return '?';
                    const t = new Date(d);
                    if (isNaN(t.getTime())) return d;
                    return t.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
                };
                licenseDatesText = fmt(licenseIssue && licenseIssue.value) + ' → ' + fmt(licenseExpiry && licenseExpiry.value);
            } else if (isBicycle) {
                licenseDatesText = '';
            }

            setText('reviewName', fullName || '—');
            setText('reviewEmail', (email && email.value) || '—');
            setText('reviewContact', (contactNumber && contactNumber.value) || '—');
            setText('reviewUsername', (username && username.value) || '—');

            setText('reviewVehicleType', vehicleTypeText);
            setText('reviewVehiclePlate', plateText);
            setText('reviewVehicleMakeModel', makeModel);

            setText('reviewAddress', addressParts.join(', ') || '—');

            setText('reviewEmergencyName', emergencyFullName || '—');
            setText('reviewEmergencyRelationship', emergencyRelText);
            setText('reviewEmergencyContact', (emergencyContact && emergencyContact.value) || '—');

            setText('reviewProfilePhoto', profileFileName);
            setText('reviewLicensePhoto', licenseFileName);
            setText('reviewLicenseDates', licenseDatesText);
        }

        function setText(id, value) {
            const el = document.getElementById(id);
            if (!el) return;
            el.textContent = value;
        }

        // ============================================
        // STEP NAVIGATION
        // ============================================

        function goToStep(step) {
            if (step > currentStep && !validateStep(currentStep)) {
                return;
            }

            currentStep = step;
            if (currentStepInput) currentStepInput.value = String(step);

            steps.forEach(function (el, i) {
                el.style.display = (i + 1 === step) ? 'block' : 'none';
            });

            progressSteps.forEach(function (el, i) {
                const n = i + 1;
                el.classList.toggle('active', n === step);
                el.classList.toggle('completed', n < step);
            });

            progressLines.forEach(function (el, i) {
                el.classList.toggle('completed', i + 1 < step);
            });

            if (stepSubtitle) {
                stepSubtitle.textContent = stepTitles[step - 1] || ('Step ' + step + ' of ' + totalSteps);
            }

            hideBanner();

            if (step === 5) {
                buildReviewSummary();
            }

            const stepEl = document.getElementById('step' + step);
            if (stepEl) {
                const firstInput = stepEl.querySelector(
                    'input:not([type="hidden"]):not([type="file"]), select'
                );
                safeFocus(firstInput);
            }

            const progressBar = document.querySelector('.register-progress');
            if (progressBar) progressBar.setAttribute('aria-valuenow', String(step));
        }

        nextButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                const next = parseInt(this.getAttribute('data-next'), 10);
                if (!isNaN(next) && next <= totalSteps) goToStep(next);
            });
        });

        prevButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                const prev = parseInt(this.getAttribute('data-prev'), 10);
                if (!isNaN(prev) && prev >= 1) goToStep(prev);
            });
        });

        // ============================================
        // REAL-TIME PASSWORD FEEDBACK
        // ============================================

        if (password) {
            password.addEventListener('input', function () {
                const val = this.value;
                if (val.length === 0) { clearFieldError(this, passwordError); return; }
                const check = isValidPassword(val);
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
                const val = this.value;
                if (!val) { clearFieldError(this, confirmError); return; }
                if (val === (password ? password.value : '')) {
                    clearFieldError(this, confirmError);
                } else {
                    showFieldError(this, confirmError, 'Passwords do not match.');
                }
            });
        }

        // ============================================
        // BLUR VALIDATION
        // ============================================

        if (contactNumber) {
            contactNumber.addEventListener('blur', function () {
                const val = this.value.trim();
                if (!val) return;
                const ph = isValidPhilippineMobile(val);
                if (ph.valid) clearFieldError(this, contactError);
                else showFieldError(this, contactError, ph.message);
            });
        }

        if (email) {
            email.addEventListener('blur', function () {
                const val = this.value.trim();
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
                const val = this.value.trim();
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
        // SERVER FIELD → INLINE SLOT MAP
        //
        // Used by the submit handler to route every server-side
        // `field` value to its inline element and owning step.
        //
        // The `upload` entry is a generic bucket the handler uses
        // for any validateUpload() failure. The submit handler
        // inspects the message text to decide which of the two
        // upload slots should receive it.
        // ============================================

        const SERVER_FIELD_MAP = {
            // Step 1
            first_name:            { input: firstName,          errorEl: firstNameError,          step: 1 },
            last_name:             { input: lastName,           errorEl: lastNameError,           step: 1 },
            birthdate:             { input: birthdate,          errorEl: birthdateError,          step: 1 },
            gender:                { input: gender,             errorEl: genderError,             step: 1 },
            email:                 { input: email,              errorEl: emailError,              step: 1 },
            contact_number:        { input: contactNumber,      errorEl: contactError,            step: 1 },
            username:              { input: username,           errorEl: usernameError,           step: 1 },
            password:              { input: password,           errorEl: passwordError,           step: 1 },
            confirm_password:      { input: confirmPassword,    errorEl: confirmError,            step: 1 },

            // Step 2
            vehicle_type:          { input: vehicleType,        errorEl: vehicleTypeError,        step: 2 },
            vehicle_plate:         { input: vehiclePlate,       errorEl: vehiclePlateError,       step: 2 },
            vehicle_year:          { input: vehicleYear,        errorEl: vehicleYearError,        step: 2 },

            // Step 3
            block:                 { input: block,              errorEl: blockError,              step: 3 },
            city:                  { input: city,               errorEl: cityError,               step: 3 },
            postal_code:           { input: postalCode,         errorEl: postalError,             step: 3 },
            emergency_first_name:  { input: emergencyFirstName, errorEl: emergencyFirstNameError, step: 3 },
            emergency_last_name:   { input: emergencyLastName,  errorEl: emergencyLastNameError,  step: 3 },
            emergency_relationship:{ input: emergencyRel,       errorEl: emergencyRelError,       step: 3 },
            emergency_contact:     { input: emergencyContact,   errorEl: emergencyContactError,   step: 3 },

            // Step 4
            profile_picture:       { input: profileInput,       errorEl: profilePicError,         step: 4 },
            drivers_license:       { input: licenseInput,       errorEl: licenseError,            step: 4 },
            license_issue_date:    { input: licenseIssue,       errorEl: licenseIssueError,       step: 4 },
            license_expiry_date:   { input: licenseExpiry,      errorEl: licenseExpiryError,      step: 4 },
            // Generic upload bucket — routed explicitly in the submit
            // handler by inspecting the message text.
            upload:                { input: null,               errorEl: null,                    step: 4 },

            // Step 5 — terms uses the group wrapper, handled explicitly.
            terms:                 { input: null,               errorEl: null,                    step: 5 },
        };

        // ============================================
        // FORM SUBMISSION
        // ============================================

        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                if (isSubmitting) return;

                if (currentStep < totalSteps) {
                    goToStep(currentStep + 1);
                    return;
                }

                if (!validateStep1()) { goToStep(1); return; }
                if (!validateStep2()) { goToStep(2); return; }
                if (!validateStep3()) { goToStep(3); return; }
                if (!validateStep4()) { goToStep(4); return; }
                if (!validateStep5()) { goToStep(5); return; }

                isSubmitting = true;
                if (registerBtn) {
                    registerBtn.disabled = true;
                    registerBtn.textContent = 'Submitting…';
                }

                fetch(form.action, {
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
                            showNotification(
                                'Application Received',
                                data.message || 'Your rider application has been submitted. Please sign in.',
                                function () {
                                    window.location.href = data.redirect || 'sign-in.php';
                                }
                            );
                            return;
                        }

                        isSubmitting = false;
                        if (registerBtn) {
                            registerBtn.disabled = false;
                            registerBtn.textContent = 'Submit Application';
                        }

                        // ---- Terms: uses the group wrapper class ----
                        if (data && data.field === 'terms') {
                            if (termsError) {
                                termsError.textContent = data.message;
                                termsError.style.display = 'block';
                            }
                            if (termsGroup) termsGroup.classList.add('error');
                            goToStep(5);
                            return;
                        }

                        // ---- Generic upload bucket ----
                        // The handler returns field="upload" for any
                        // validateUpload() failure. Route by inspecting
                        // the message: if it mentions the ID document,
                        // land on the license slot, otherwise on the
                        // photo slot.
                        if (data && data.field === 'upload') {
                            const message = (data && data.message) || '';
                            if (/license|ID|government/i.test(message)) {
                                showFieldError(licenseInput, licenseError, message);
                            } else {
                                showFieldError(profileInput, profilePicError, message);
                            }
                            goToStep(4);
                            return;
                        }

                        // ---- Map every other server field to its slot ----
                        const target = (data && data.field && SERVER_FIELD_MAP[data.field])
                            ? SERVER_FIELD_MAP[data.field]
                            : null;

                        if (target && target.input && target.errorEl) {
                            showFieldError(target.input, target.errorEl, data.message);

                            // Only bounce back if the user is past the step
                            // that owns this field. If they're on or before
                            // it, just highlight the input.
                            if (target.step < currentStep) {
                                goToStep(target.step);
                            }
                            return;
                        }

                        // ---- Fallthrough: no specific field ----
                        showBanner((data && data.message) || 'Could not create your account. Please try again.');
                    })
                    .catch(function () {
                        isSubmitting = false;
                        if (registerBtn) {
                            registerBtn.disabled = false;
                            registerBtn.textContent = 'Submit Application';
                        }
                        showBanner('An unexpected error occurred. Please try again.');
                    });
            });
        }

        // ============================================
        // NOTIFIER MODAL
        // ============================================

        const notifierModal    = document.getElementById('notifierModal');
        const notifierTitle    = document.getElementById('notifierTitle');
        const notifierMessage  = document.getElementById('notifierMessage');
        const notifierCloseBtn = document.getElementById('notifierCloseBtn');

        function showNotification(title, message, callback) {
            if (notifierTitle)   notifierTitle.textContent   = title || 'Success';
            if (notifierMessage) notifierMessage.textContent = message || '';
            if (notifierModal)   notifierModal.classList.remove('hidden');
            if (notifierCloseBtn && callback) notifierCloseBtn._callback = callback;
        }

        function closeNotification() {
            if (notifierModal) notifierModal.classList.add('hidden');
            if (notifierCloseBtn && typeof notifierCloseBtn._callback === 'function') {
                const cb = notifierCloseBtn._callback;
                notifierCloseBtn._callback = null;
                cb();
            }
        }

        if (notifierCloseBtn) notifierCloseBtn.addEventListener('click', closeNotification);
        if (notifierModal) {
            notifierModal.addEventListener('click', function (e) {
                if (e.target === this) closeNotification();
            });
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && notifierModal && !notifierModal.classList.contains('hidden')) {
                closeNotification();
            }
        });

        // ============================================
        // INIT
        // ============================================
        goToStep(1);
    });
})();