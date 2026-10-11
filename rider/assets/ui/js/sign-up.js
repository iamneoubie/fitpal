/**
 * FitPal Rider Registration JavaScript
 *
 * Six-step application form with:
 *   - Field-level validation and error clearing
 *   - Automatic name and address capitalization
 *   - Title-case capitalization for vehicle make/model
 *   - Email lowercasing
 *   - Password rule feedback
 *   - File upload previews
 *   - Vehicle-dependent identity-document block
 *   - Vehicle-dependent Step 2 layout: plate/make/model/year are
 *     hidden, cleared, and `required`-removed when the vehicle is a
 *     bicycle, and restored when the vehicle switches back
 *   - Emergency-contact validation, including the rule that the
 *     emergency contact number must differ from the rider's own
 *     contact number
 *   - Review summary build on Step 6
 *   - Fetch-based submission with per-field server error routing
 *
 * ---------------------------------------------------------------------
 * SIX STEPS
 * ---------------------------------------------------------------------
 *   Step 1  Personal
 *   Step 2  Vehicle
 *   Step 3  Address
 *   Step 4  Emergency Contact
 *   Step 5  Verification Uploads
 *   Step 6  Review & Submit
 *
 * The two required uploads live on Step 5 in the markup. That means:
 *
 *   - validateStep1 / validateStep2 / validateStep3 / validateStep4
 *     must NOT check the upload inputs, or clicking "Next Step" from
 *     those steps would fail because the file inputs are still empty.
 *   - The uploads are checked in validateStep5().
 *
 * All real validation also runs server-side in sign-up-handler.php.
 * This file is a UX layer only.
 *
 * ---------------------------------------------------------------------
 * VEHICLE-TYPE-DRIVEN UI
 * ---------------------------------------------------------------------
 * Region 1 — Step 2 motor-vehicle-only fields
 *   When the vehicle is 'bicycle', the #vehicleMotorOnlyFields
 *   wrapper is hidden by CSS, this file clears its inputs, and this
 *   file removes the `required` attribute from every input inside
 *   it. When the vehicle switches back to a non-bicycle type, this
 *   file restores `required` on all four inputs.
 *
 * Region 2 — Step 5 identity-document block and license-date row
 *   When the vehicle is 'bicycle', CSS swaps the heading and
 *   guidelines, hides the license-date row, and this file removes
 *   `required` from the two license-date inputs.
 *
 * ---------------------------------------------------------------------
 * EMERGENCY CONTACT MUST DIFFER FROM RIDER'S OWN NUMBER
 * ---------------------------------------------------------------------
 * The client-side rule normalizes both numbers to digit-only strings
 * before comparing. The server does the same. A client that skips
 * JavaScript cannot bypass the rule.
 *
 * @package FitPal
 * @version 6.0 — Six-step wizard. Address and Emergency Contact
 *                split into their own steps. Uploads renumbered to
 *                Step 5. Verify renumbered to Step 6. Step 2's
 *                make/model/year inputs gain `required` toggling
 *                alongside the plate; all four are required for a
 *                non-bicycle vehicle. Step 3's four previously
 *                optional address fields now validate as required.
 *
 *                (5.3: emergency-contact-vs-own-number. 5.2:
 *                clears plate/make/model/year on bicycle switch.
 *                5.0: applyVehicleTypeDependentUI().)
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
        const block      = document.getElementById('block');
        const barangay   = document.getElementById('barangay');
        const city       = document.getElementById('city');
        const province   = document.getElementById('province');
        const region     = document.getElementById('region');
        const postalCode = document.getElementById('postal_code');

        // Step 4
        const emergencyFirstName  = document.getElementById('emergency_first_name');
        const emergencyMiddleName = document.getElementById('emergency_middle_name');
        const emergencyLastName   = document.getElementById('emergency_last_name');
        const emergencyRel        = document.getElementById('emergency_relationship');
        const emergencyContact    = document.getElementById('emergency_contact');

        // Step 5 — uploads + license dates
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

        // Step 6 — review + terms + submit
        const termsCheckbox         = document.getElementById('terms');
        const termsGroup            = document.getElementById('termsGroup');
        const termsError            = document.getElementById('termsError');
        const registerBtn           = document.getElementById('registerBtn');
        const reviewIdDocLabel      = document.getElementById('reviewIdDocLabel');
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
        const vehicleMakeError        = document.getElementById('vehicleMakeError');
        const vehicleModelError       = document.getElementById('vehicleModelError');
        const vehicleYearError        = document.getElementById('vehicleYearError');
        const blockError              = document.getElementById('blockError');
        const barangayError           = document.getElementById('barangayError');
        const cityError               = document.getElementById('cityError');
        const provinceError           = document.getElementById('provinceError');
        const regionError             = document.getElementById('regionError');
        const postalError             = document.getElementById('postalError');
        const emergencyFirstNameError = document.getElementById('emergencyFirstNameError');
        const emergencyLastNameError  = document.getElementById('emergencyLastNameError');
        const emergencyRelError       = document.getElementById('emergencyRelationshipError');
        const emergencyContactError   = document.getElementById('emergencyContactError');

        const stepTitles = [
            'Step 1 of 6 — Personal Information',
            'Step 2 of 6 — Vehicle Details',
            'Step 3 of 6 — Address',
            'Step 4 of 6 — Emergency Contact',
            'Step 5 of 6 — Verification Uploads',
            'Step 6 of 6 — Review & Submit',
        ];

        let currentStep = 1;
        const totalSteps = 6;
        let isSubmitting = false;

        const NAME_PATTERN = /^[A-Za-z\s\-']+$/;
        const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

        // ============================================
        // HELPERS
        // ============================================

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

            if (step === 6 && termsGroup) {
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
        setupNameInput(barangay, barangayError);
        setupNameInput(city, cityError);
        setupNameInput(province, provinceError);
        setupNameInput(region, regionError);

        // Emergency contact fields
        setupNameInput(emergencyFirstName, emergencyFirstNameError);
        setupNameInput(emergencyMiddleName, null);
        setupNameInput(emergencyLastName, emergencyLastNameError);

        // Vehicle make/model — title-case with digit/symbol allowance.
        setupTitleCaseInput(vehicleMake, vehicleMakeError);
        setupTitleCaseInput(vehicleModel, vehicleModelError);

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

            if (!input || !dropzone) return;

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
        // ============================================

        function applyVehicleTypeDependentUI() {
            if (!vehicleType || !pageRoot) return;

            const selectedValue = vehicleType.value;
            const isBicycle     = (selectedValue === 'bicycle');

            pageRoot.setAttribute('data-vehicle-type', selectedValue || '');

            // License date inputs are required for any non-bicycle
            // vehicle and skipped for bicycle.
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

            // Step 2 motor-vehicle-only inputs. When the vehicle is a
            // bicycle, the wrapper is hidden by CSS. This block also
            // clears the four inputs and removes their `required`
            // attribute so the browser will not block submit with a
            // tooltip on a hidden field. When the vehicle switches
            // back, `required` is restored.
            if (isBicycle) {
                if (vehiclePlate) vehiclePlate.value = '';
                if (vehicleMake)  vehicleMake.value  = '';
                if (vehicleModel) vehicleModel.value = '';
                if (vehicleYear)  vehicleYear.value  = '';

                if (vehiclePlate) vehiclePlate.removeAttribute('required');
                if (vehicleMake)  vehicleMake.removeAttribute('required');
                if (vehicleModel) vehicleModel.removeAttribute('required');
                if (vehicleYear)  vehicleYear.removeAttribute('required');

                clearFieldError(vehiclePlate, vehiclePlateError);
                clearFieldError(vehicleMake,  vehicleMakeError);
                clearFieldError(vehicleModel, vehicleModelError);
                clearFieldError(vehicleYear,  vehicleYearError);

                clearFieldError(licenseIssue, licenseIssueError);
                clearFieldError(licenseExpiry, licenseExpiryError);
            } else if (selectedValue !== '') {
                if (vehiclePlate) vehiclePlate.setAttribute('required', 'required');
                if (vehicleMake)  vehicleMake.setAttribute('required', 'required');
                if (vehicleModel) vehicleModel.setAttribute('required', 'required');
                if (vehicleYear)  vehicleYear.setAttribute('required', 'required');
            }

            // Step 6 review label — kept in sync so that the summary
            // is correct even if the applicant never re-visits Step 5
            // after changing Step 2.
            if (reviewIdDocLabel) {
                reviewIdDocLabel.textContent = isBicycle
                    ? 'Valid Government ID'
                    : "Driver's License";
            }

            if (reviewLicenseDatesRow) {
                reviewLicenseDatesRow.style.display = isBicycle ? 'none' : '';
            }
        }

        if (vehicleType) {
            vehicleType.addEventListener('change', applyVehicleTypeDependentUI);
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

                const makeVal = vehicleMake ? vehicleMake.value.trim() : '';
                if (!makeVal) {
                    showFieldError(vehicleMake, vehicleMakeError, 'Make / Brand is required for motor vehicles.');
                    valid = false;
                }

                const modelVal = vehicleModel ? vehicleModel.value.trim() : '';
                if (!modelVal) {
                    showFieldError(vehicleModel, vehicleModelError, 'Model is required for motor vehicles.');
                    valid = false;
                }

                if (!vehicleYear || !vehicleYear.value) {
                    showFieldError(vehicleYear, vehicleYearError, 'Year is required for motor vehicles.');
                    valid = false;
                } else {
                    const y = parseInt(vehicleYear.value, 10);
                    const maxYear = new Date().getFullYear() + 1;
                    if (isNaN(y) || y < 1980 || y > maxYear) {
                        showFieldError(vehicleYear, vehicleYearError, 'Please enter a valid year.');
                        valid = false;
                    }
                }
            }

            return valid;
        }

        // ============================================
        // VALIDATION — STEP 3 (Address)
        // ============================================

        function validateStep3() {
            let valid = true;
            clearStepErrors(3);

            const blockVal = block ? block.value.trim() : '';
            if (!blockVal) {
                showFieldError(block, blockError, 'Block / Street / Unit is required.');
                valid = false;
            }

            const barangayVal = barangay ? barangay.value.trim() : '';
            if (!barangayVal) {
                showFieldError(barangay, barangayError, 'Barangay is required.');
                valid = false;
            }

            const cityVal = city ? city.value.trim() : '';
            if (!cityVal) {
                showFieldError(city, cityError, 'City or municipality is required.');
                valid = false;
            }

            const provinceVal = province ? province.value.trim() : '';
            if (!provinceVal) {
                showFieldError(province, provinceError, 'Province is required.');
                valid = false;
            }

            const regionVal = region ? region.value.trim() : '';
            if (!regionVal) {
                showFieldError(region, regionError, 'Region is required.');
                valid = false;
            }

            const postalVal = postalCode ? postalCode.value.trim() : '';
            if (!postalVal) {
                showFieldError(postalCode, postalError, 'Postal code is required.');
                valid = false;
            } else if (!/^[0-9]{3,10}$/.test(postalVal)) {
                showFieldError(postalCode, postalError, 'Postal code must be numeric (3–10 digits).');
                valid = false;
            }

            return valid;
        }

        // ============================================
        // VALIDATION — STEP 4 (Emergency Contact)
        // ============================================

        function validateStep4() {
            let valid = true;
            clearStepErrors(4);

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
                    const ownDigits = normalizeDigits(contactNumber ? contactNumber.value : '');
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
        // VALIDATION — STEP 5 (uploads + license dates)
        // ============================================

        function validateUploads() {
            let valid = true;

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

            const isBicycle = vehicleType && vehicleType.value === 'bicycle';
            if (isBicycle) {
                clearFieldError(licenseIssue, licenseIssueError);
                clearFieldError(licenseExpiry, licenseExpiryError);
                return true;
            }

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

        function validateStep5() {
            clearStepErrors(5);

            let valid = true;

            if (!validateUploads())      valid = false;
            if (!validateLicenseDates()) valid = false;

            return valid;
        }

        // ============================================
        // VALIDATION — STEP 6 (terms)
        // ============================================

        function validateStep6() {
            clearStepErrors(6);

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
                case 6: return validateStep6();
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

            const isBicycle = vehicleType && vehicleType.value === 'bicycle';
            if (reviewIdDocLabel) {
                reviewIdDocLabel.textContent = isBicycle
                    ? 'Valid Government ID'
                    : "Driver's License";
            }

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

            if (step === 6) {
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
        // ============================================

        const SERVER_FIELD_MAP = {
            first_name:            { input: firstName,          errorEl: firstNameError,          step: 1 },
            last_name:             { input: lastName,           errorEl: lastNameError,           step: 1 },
            birthdate:             { input: birthdate,          errorEl: birthdateError,          step: 1 },
            gender:                { input: gender,             errorEl: genderError,             step: 1 },
            email:                 { input: email,              errorEl: emailError,              step: 1 },
            contact_number:        { input: contactNumber,      errorEl: contactError,            step: 1 },
            username:              { input: username,           errorEl: usernameError,           step: 1 },
            password:              { input: password,           errorEl: passwordError,           step: 1 },
            confirm_password:      { input: confirmPassword,    errorEl: confirmError,            step: 1 },

            vehicle_type:          { input: vehicleType,        errorEl: vehicleTypeError,        step: 2 },
            vehicle_plate:         { input: vehiclePlate,       errorEl: vehiclePlateError,       step: 2 },
            vehicle_make:          { input: vehicleMake,        errorEl: vehicleMakeError,        step: 2 },
            vehicle_model:         { input: vehicleModel,       errorEl: vehicleModelError,       step: 2 },
            vehicle_year:          { input: vehicleYear,        errorEl: vehicleYearError,        step: 2 },

            block:                 { input: block,              errorEl: blockError,              step: 3 },
            barangay:              { input: barangay,           errorEl: barangayError,           step: 3 },
            city:                  { input: city,               errorEl: cityError,               step: 3 },
            province:              { input: province,           errorEl: provinceError,           step: 3 },
            region:                { input: region,             errorEl: regionError,             step: 3 },
            postal_code:           { input: postalCode,         errorEl: postalError,             step: 3 },

            emergency_first_name:  { input: emergencyFirstName, errorEl: emergencyFirstNameError, step: 4 },
            emergency_last_name:   { input: emergencyLastName,  errorEl: emergencyLastNameError,  step: 4 },
            emergency_relationship:{ input: emergencyRel,       errorEl: emergencyRelError,       step: 4 },
            emergency_contact:     { input: emergencyContact,   errorEl: emergencyContactError,   step: 4 },

            profile_picture:       { input: profileInput,       errorEl: profilePicError,         step: 5 },
            drivers_license:       { input: licenseInput,       errorEl: licenseError,            step: 5 },
            license_issue_date:    { input: licenseIssue,       errorEl: licenseIssueError,       step: 5 },
            license_expiry_date:   { input: licenseExpiry,      errorEl: licenseExpiryError,      step: 5 },
            upload:                { input: null,               errorEl: null,                    step: 5 },

            terms:                 { input: null,               errorEl: null,                    step: 6 },
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
                if (!validateStep6()) { goToStep(6); return; }

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

                        if (data && data.field === 'terms') {
                            if (termsError) {
                                termsError.textContent = data.message;
                                termsError.style.display = 'block';
                            }
                            if (termsGroup) termsGroup.classList.add('error');
                            goToStep(6);
                            return;
                        }

                        if (data && data.field === 'upload') {
                            const message = (data && data.message) || '';
                            if (/license|ID|government/i.test(message)) {
                                showFieldError(licenseInput, licenseError, message);
                            } else {
                                showFieldError(profileInput, profilePicError, message);
                            }
                            goToStep(5);
                            return;
                        }

                        const target = (data && data.field && SERVER_FIELD_MAP[data.field])
                            ? SERVER_FIELD_MAP[data.field]
                            : null;

                        if (target && target.input && target.errorEl) {
                            showFieldError(target.input, target.errorEl, data.message);

                            if (target.step < currentStep) {
                                goToStep(target.step);
                            }
                            return;
                        }

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