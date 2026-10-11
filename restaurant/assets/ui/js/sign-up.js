/**
 * FitPal Restaurant Registration JavaScript
 *
 * Five-step form with name/email/address filtering, password
 * feedback, permit upload grid (1–5 photos), review summary, and
 * fetch submission.
 *
 * ---------------------------------------------------------------------
 * STEP LAYOUT
 * ---------------------------------------------------------------------
 *   1  Account Holder    first_name, middle_name, last_name, email,
 *                        contact_number, username
 *   2  Security          password, confirm_password
 *   3  Business Details  business_name, cuisine_type,
 *                        business_description, dietary_tags
 *   4  Branch & Permits  branch_name, block, barangay, city,
 *                        province, region, postal_code,
 *                        permit slots, terms checkbox
 *   5  Review & Submit   read-only summary
 *
 * ---------------------------------------------------------------------
 * FILTER CLASSES
 * ---------------------------------------------------------------------
 *   .name-field       letters, spaces, hyphens, apostrophes only;
 *                     each word capitalized.
 *
 *   .address-field    letters, digits, spaces, commas, periods,
 *                     hyphens, apostrophes, slashes, hash. Each
 *                     word capitalized. Used for block / street /
 *                     unit and barangay.
 *
 *   .email-field      lowercased.
 *   .digits-field     digits and spaces only.
 *   .username-field   letters, numbers, underscore only.
 *   .capitalize-field first letter of first word only, no other
 *                     restriction. Used for Business Description.
 *
 * ---------------------------------------------------------------------
 * VALIDATION
 * ---------------------------------------------------------------------
 * Each step validates on Next Step. Submitting on Step 5 runs every
 * step's validator in order and routes the user to the first
 * failing step.
 *
 * @package FitPal
 * @version 2.0 — Five-step wizard. Step 2 is the password pair.
 *                Step 3 is the business details. Step 4 is branch
 *                address + permit grid + terms. Step 5 is a review
 *                summary before submit. Validators split into
 *                validateStep1 through validateStep5. Address
 *                filter applied to block / barangay / city /
 *                province / region. Business Description wired to
 *                capitalizeField().
 *
 *                (1.3: address-field class. 1.2:
 *                business_description, dietary_tags, barangay,
 *                province, region, postal_code required. 1.1:
 *                permit upload grid. 1.0: initial.)
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        var form = document.getElementById('registerForm');
        if (!form) return;

        var steps = document.querySelectorAll('.register-step');
        var progressSteps = document.querySelectorAll('.progress-step');
        var progressLines = document.querySelectorAll('.progress-line');
        var stepSubtitle = document.getElementById('stepSubtitle');
        var currentStepInput = document.getElementById('currentStep');
        var registerError = document.getElementById('registerError');
        var errorMessage = document.getElementById('errorMessage');

        var nextButtons = document.querySelectorAll('.btn-next');
        var prevButtons = document.querySelectorAll('.btn-prev');

        // Step 1 — Account holder
        var firstName = document.getElementById('first_name');
        var middleName = document.getElementById('middle_name');
        var lastName = document.getElementById('last_name');
        var email = document.getElementById('email');
        var contactNumber = document.getElementById('contact_number');
        var username = document.getElementById('username');

        // Step 2 — Security
        var password = document.getElementById('password');
        var confirmPassword = document.getElementById('confirm_password');

        // Step 3 — Business
        var businessName = document.getElementById('business_name');
        var cuisineType = document.getElementById('cuisine_type');
        var businessDescription = document.getElementById('business_description');
        var dietaryTags = document.getElementById('dietary_tags');

        // Step 4 — Branch & Permits
        var branchName = document.getElementById('branch_name');
        var block = document.getElementById('block');
        var barangay = document.getElementById('barangay');
        var city = document.getElementById('city');
        var province = document.getElementById('province');
        var region = document.getElementById('region');
        var postalCode = document.getElementById('postal_code');

        var termsCheckbox = document.getElementById('terms');
        var termsGroup = document.getElementById('termsGroup');
        var termsError = document.getElementById('termsError');
        var registerBtn = document.getElementById('registerBtn');

        // Error nodes
        var firstNameError = document.getElementById('firstNameError');
        var lastNameError = document.getElementById('lastNameError');
        var emailError = document.getElementById('emailError');
        var contactError = document.getElementById('contactError');
        var usernameError = document.getElementById('usernameError');
        var passwordError = document.getElementById('passwordError');
        var confirmError = document.getElementById('confirmError');
        var businessNameError = document.getElementById('businessNameError');
        var cuisineTypeError = document.getElementById('cuisineTypeError');
        var businessDescriptionError = document.getElementById('businessDescriptionError');
        var dietaryTagsError = document.getElementById('dietaryTagsError');
        var branchNameError = document.getElementById('branchNameError');
        var blockError = document.getElementById('blockError');
        var barangayError = document.getElementById('barangayError');
        var cityError = document.getElementById('cityError');
        var provinceError = document.getElementById('provinceError');
        var regionError = document.getElementById('regionError');
        var postalError = document.getElementById('postalError');
        var permitsError = document.getElementById('permitsError');

        var STEP_TITLES = {
            1: 'Step 1 of 5 — Account Holder',
            2: 'Step 2 of 5 — Security',
            3: 'Step 3 of 5 — Business Details',
            4: 'Step 4 of 5 — Branch & Permits',
            5: 'Step 5 of 5 — Review & Submit'
        };

        var currentStep = 1;
        var totalSteps = 5;
        var isSubmitting = false;

        var NAME_PATTERN = /^[A-Za-z\s\-']+$/;

        /* ============================================
           HELPERS
           ============================================ */

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

        function clearStepErrors(step) {
            var stepEl = document.getElementById('step' + step);
            if (!stepEl) return;

            stepEl.querySelectorAll('.form-error').forEach(function (el) {
                el.textContent = '';
                el.style.display = 'none';
            });
            stepEl.querySelectorAll('.form-control').forEach(function (el) {
                el.classList.remove('error');
            });

            if (step === 4 && termsGroup) {
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

        function focusField(input) {
            if (!input) return;
            try { input.focus(); } catch (e) { /* noop */ }
            if (typeof input.scrollIntoView === 'function') {
                input.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        /* ============================================
           FIRST-LETTER CAPITALIZATION
           ============================================ */

        function capitalizeField(input) {
            if (!input) return;

            input.addEventListener('input', function () {
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

        /* ============================================
           INPUT FILTERS
           ============================================ */

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

        function setupAddressInput(input, errorEl) {
            if (!input) return;

            var allowed = /[^A-Za-z0-9\s\-',.\/#ñÑ]/g;
            var wordStart = /(^|[\s\-'.\/,])([a-zñ])/g;

            input.addEventListener('input', function () {
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
                if (errorEl) clearFieldError(this, errorEl);
            });

            input.addEventListener('blur', function () {
                if (this.value.length > 0) {
                    var capitalized = this.value.replace(wordStart, function (m, p, c) {
                        return p + c.toUpperCase();
                    });
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
        setupNameInput(businessName, businessNameError);
        setupNameInput(branchName, branchNameError);

        setupAddressInput(block, blockError);
        setupAddressInput(barangay, barangayError);
        setupAddressInput(city, cityError);
        setupAddressInput(province, provinceError);
        setupAddressInput(region, regionError);

        setupEmailInput(email);
        setupDigitsOnly(contactNumber, contactError);
        setupAlphanumUnderscore(username);
        setupAlphanumOnly(password);
        setupAlphanumOnly(confirmPassword);
        setupPostal(postalCode);

        capitalizeField(businessDescription);

        if (businessDescription) {
            businessDescription.addEventListener('input', function () {
                clearFieldError(this, businessDescriptionError);
            });
        }
        if (dietaryTags) {
            dietaryTags.addEventListener('input', function () {
                clearFieldError(this, dietaryTagsError);
            });
        }

        /* ============================================
           PASSWORD TOGGLES
           ============================================ */

        function setupPasswordToggle(toggleId, inputId, iconId) {
            var btn = document.getElementById(toggleId);
            var input = document.getElementById(inputId);
            var icon = document.getElementById(iconId);
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

        /* ============================================
           PERMIT UPLOAD GRID
           ============================================ */

        var MAX_PERMITS = 5;
        var MAX_PERMIT_BYTES = 5 * 1024 * 1024;
        var ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

        var permitSlots = document.querySelectorAll('.permit-slot');
        var fileObjects = [];

        function indexOfSlot(slot) {
            for (var i = 0; i < fileObjects.length; i++) {
                if (fileObjects[i].slot === slot) return i;
            }
            return -1;
        }

        function attachFile(slot, file) {
            if (ALLOWED_TYPES.indexOf(file.type) === -1) {
                if (permitsError) {
                    permitsError.textContent = 'Use JPG, PNG, or WEBP.';
                    permitsError.style.display = 'block';
                }
                return;
            }
            if (file.size > MAX_PERMIT_BYTES) {
                if (permitsError) {
                    permitsError.textContent = 'Each permit must be under 5 MB.';
                    permitsError.style.display = 'block';
                }
                return;
            }

            var prev = indexOfSlot(slot);
            if (prev >= 0) {
                URL.revokeObjectURL(fileObjects[prev].url);
                fileObjects.splice(prev, 1);
            }

            var url = URL.createObjectURL(file);
            var input = slot.querySelector('.permit-input');

            try {
                var dt = new DataTransfer();
                dt.items.add(file);
                input.files = dt.files;
            } catch (err) {
                // Some browsers can't set input.files programmatically.
            }

            var preview = slot.querySelector('.permit-preview');
            var previewImg = slot.querySelector('.permit-preview-img');
            var hint = slot.querySelector('.permit-hint');

            previewImg.src = url;
            preview.hidden = false;
            preview.style.display = 'block';
            hint.hidden = true;
            slot.classList.add('has-file');

            fileObjects.push({ slot: slot, file: file, url: url });

            if (permitsError) {
                permitsError.textContent = '';
                permitsError.style.display = 'none';
            }
        }

        function clearSlot(slot) {
            var idx = indexOfSlot(slot);
            if (idx >= 0) {
                URL.revokeObjectURL(fileObjects[idx].url);
                fileObjects.splice(idx, 1);
            }

            var input = slot.querySelector('.permit-input');
            var preview = slot.querySelector('.permit-preview');
            var previewImg = slot.querySelector('.permit-preview-img');
            var hint = slot.querySelector('.permit-hint');

            input.value = '';
            previewImg.removeAttribute('src');
            preview.hidden = true;
            preview.style.display = 'none';
            hint.hidden = false;
            slot.classList.remove('has-file');
        }

        permitSlots.forEach(function (slot) {
            var input = slot.querySelector('.permit-input');
            var removeBtn = slot.querySelector('.permit-remove');

            slot.setAttribute('tabindex', '0');
            slot.setAttribute('role', 'button');

            slot.addEventListener('click', function (e) {
                if (e.target.closest('.permit-remove')) return;
                input.click();
            });

            slot.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    input.click();
                }
            });

            input.addEventListener('change', function () {
                if (this.files && this.files[0]) {
                    attachFile(slot, this.files[0]);
                }
            });

            ['dragenter', 'dragover'].forEach(function (evt) {
                slot.addEventListener(evt, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    slot.classList.add('is-dragover');
                });
            });

            ['dragleave', 'drop'].forEach(function (evt) {
                slot.addEventListener(evt, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    slot.classList.remove('is-dragover');
                });
            });

            slot.addEventListener('drop', function (e) {
                var dt = e.dataTransfer;
                if (!dt || !dt.files || !dt.files[0]) return;
                attachFile(slot, dt.files[0]);
            });

            if (removeBtn) {
                removeBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    clearSlot(slot);
                });
            }
        });

        /* ============================================
           STEP VALIDATION
           ============================================ */

        function validateStep1() {
            var valid = true;
            clearStepErrors(1);

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
                showFieldError(contactNumber, contactError, 'Please enter your contact number.');
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

            return valid;
        }

        function validateStep2() {
            var valid = true;
            clearStepErrors(2);

            var pwVal = password ? password.value : '';
            if (!pwVal) {
                showFieldError(password, passwordError, 'Please create a password.');
                valid = false;
            } else {
                var pwCheck = isValidPassword(pwVal);
                if (!pwCheck.valid) {
                    showFieldError(password, passwordError, pwCheck.message);
                    valid = false;
                }
            }

            var cpVal = confirmPassword ? confirmPassword.value : '';
            if (!cpVal) {
                showFieldError(confirmPassword, confirmError, 'Please confirm your password.');
                valid = false;
            } else if (pwVal !== cpVal) {
                showFieldError(confirmPassword, confirmError, 'Passwords do not match.');
                valid = false;
            }

            return valid;
        }

        function validateStep3() {
            var valid = true;
            clearStepErrors(3);

            var bnVal = businessName ? businessName.value.trim() : '';
            if (bnVal.length < 2) {
                showFieldError(businessName, businessNameError, 'Business name is required.');
                valid = false;
            }

            var ctVal = cuisineType ? cuisineType.value.trim() : '';
            if (ctVal.length < 2) {
                showFieldError(cuisineType, cuisineTypeError, 'Cuisine type is required.');
                valid = false;
            }

            var bdVal = businessDescription ? businessDescription.value.trim() : '';
            if (bdVal.length < 2) {
                showFieldError(businessDescription, businessDescriptionError, 'Business description is required.');
                valid = false;
            }

            var dtVal = dietaryTags ? dietaryTags.value.trim() : '';
            if (dtVal.length < 1) {
                showFieldError(dietaryTags, dietaryTagsError, 'Dietary tags are required.');
                valid = false;
            }

            return valid;
        }

        function validateStep4() {
            var valid = true;
            clearStepErrors(4);

            var brVal = branchName ? branchName.value.trim() : '';
            if (brVal.length < 2) {
                showFieldError(branchName, branchNameError, 'Branch name is required.');
                valid = false;
            }

            var blockVal = block ? block.value.trim() : '';
            if (!blockVal) {
                showFieldError(block, blockError, 'Block / Street / Unit is required.');
                valid = false;
            }

            var barangayVal = barangay ? barangay.value.trim() : '';
            if (!barangayVal) {
                showFieldError(barangay, barangayError, 'Barangay is required.');
                valid = false;
            }

            var cityVal = city ? city.value.trim() : '';
            if (!cityVal) {
                showFieldError(city, cityError, 'City or municipality is required.');
                valid = false;
            }

            var provinceVal = province ? province.value.trim() : '';
            if (!provinceVal) {
                showFieldError(province, provinceError, 'Province is required.');
                valid = false;
            }

            var regionVal = region ? region.value.trim() : '';
            if (!regionVal) {
                showFieldError(region, regionError, 'Region is required.');
                valid = false;
            }

            var postalVal = postalCode ? postalCode.value.trim() : '';
            if (!postalVal) {
                showFieldError(postalCode, postalError, 'Postal code is required.');
                valid = false;
            } else if (!/^[0-9]{3,10}$/.test(postalVal)) {
                showFieldError(postalCode, postalError, 'Postal code must be 3–10 digits.');
                valid = false;
            }

            if (fileObjects.length === 0) {
                if (permitsError) {
                    permitsError.textContent = 'Please upload at least one permit photo.';
                    permitsError.style.display = 'block';
                }
                valid = false;
            } else if (permitsError) {
                permitsError.textContent = '';
                permitsError.style.display = 'none';
            }

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

        /**
         * Step 5 is a read-only review. It has no inputs of its own.
         * It re-runs every earlier step's validator and returns the
         * number of the first failing step, or 0 when all pass.
         *
         * @returns {number}
         */
        function validateAllStepsBeforeReview() {
            if (!validateStep1()) return 1;
            if (!validateStep2()) return 2;
            if (!validateStep3()) return 3;
            if (!validateStep4()) return 4;
            return 0;
        }

        /* ============================================
           REVIEW SUMMARY
           ============================================ */

        function setText(id, value) {
            var el = document.getElementById(id);
            if (!el) return;
            el.textContent = value !== '' ? value : '—';
        }

        function buildReviewSummary() {
            var fn = firstName ? firstName.value.trim() : '';
            var mn = middleName ? middleName.value.trim() : '';
            var ln = lastName ? lastName.value.trim() : '';

            var fullName = [fn, mn, ln].filter(Boolean).join(' ');

            setText('reviewName', fullName);
            setText('reviewEmail', email ? email.value.trim() : '');
            setText('reviewContact', contactNumber ? contactNumber.value.trim() : '');
            setText('reviewUsername', username ? username.value.trim() : '');

            setText('reviewBusinessName', businessName ? businessName.value.trim() : '');
            setText('reviewCuisineType', cuisineType ? cuisineType.value.trim() : '');
            setText('reviewDietaryTags', dietaryTags ? dietaryTags.value.trim() : '');

            setText('reviewBranchName', branchName ? branchName.value.trim() : '');

            var addressParts = [];
            [block, barangay, city, province, region, postalCode].forEach(function (el) {
                if (el && el.value.trim() !== '') {
                    addressParts.push(el.value.trim());
                }
            });

            setText('reviewBranchAddress', addressParts.join(', '));

            var permitCount = fileObjects.length;
            var permitText = permitCount === 0
                ? '—'
                : (permitCount === 1 ? '1 permit uploaded' : permitCount + ' permits uploaded');
            setText('reviewPermitStatus', permitText);
        }

        /* ============================================
           STEP NAVIGATION
           ============================================ */

        function validateStep(step) {
            switch (step) {
                case 1: return validateStep1();
                case 2: return validateStep2();
                case 3: return validateStep3();
                case 4: return validateStep4();
                default: return true;
            }
        }

        function goToStep(step) {
            if (step < 1 || step > totalSteps) return;
            if (step === currentStep) return;

            if (step > currentStep) {
                if (!validateStep(currentStep)) return;
            }

            currentStep = step;
            if (currentStepInput) currentStepInput.value = String(step);

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
                el.classList.toggle('completed', i + 1 < step);
            });

            if (stepSubtitle && STEP_TITLES[step]) {
                stepSubtitle.textContent = STEP_TITLES[step];
            }

            hideBanner();

            if (step === 5) {
                buildReviewSummary();
            }

            var stepEl = document.getElementById('step' + step);
            if (stepEl) {
                var firstInput = stepEl.querySelector(
                    'input:not([type="hidden"]):not([type="file"]), select, textarea'
                );
                safeFocus(firstInput);
            }
        }

        nextButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var next = parseInt(this.getAttribute('data-next'), 10);
                if (!isNaN(next) && next <= totalSteps) goToStep(next);
            });
        });

        prevButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var prev = parseInt(this.getAttribute('data-prev'), 10);
                if (!isNaN(prev) && prev >= 1) goToStep(prev);
            });
        });

        /* ============================================
           REAL-TIME PASSWORD FEEDBACK
           ============================================ */

        if (password) {
            password.addEventListener('input', function () {
                var val = this.value;
                if (val.length === 0) { clearFieldError(this, passwordError); return; }
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
                if (!val) { clearFieldError(this, confirmError); return; }
                if (val === (password ? password.value : '')) {
                    clearFieldError(this, confirmError);
                } else {
                    showFieldError(confirmPassword, confirmError, 'Passwords do not match.');
                }
            });
        }

        /* ============================================
           BLUR VALIDATION
           ============================================ */

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

        /* ============================================
           SERVER FIELD MAP
           ============================================ */

        var SERVER_FIELD_MAP = {
            first_name:            { input: firstName,           errorEl: firstNameError,           step: 1 },
            last_name:             { input: lastName,            errorEl: lastNameError,            step: 1 },
            email:                 { input: email,               errorEl: emailError,               step: 1 },
            contact_number:        { input: contactNumber,       errorEl: contactError,             step: 1 },
            username:              { input: username,            errorEl: usernameError,            step: 1 },
            password:              { input: password,            errorEl: passwordError,            step: 2 },
            confirm_password:      { input: confirmPassword,     errorEl: confirmError,             step: 2 },
            business_name:         { input: businessName,        errorEl: businessNameError,        step: 3 },
            cuisine_type:          { input: cuisineType,         errorEl: cuisineTypeError,         step: 3 },
            business_description:  { input: businessDescription, errorEl: businessDescriptionError, step: 3 },
            dietary_tags:          { input: dietaryTags,         errorEl: dietaryTagsError,         step: 3 },
            branch_name:           { input: branchName,          errorEl: branchNameError,          step: 4 },
            block:                 { input: block,               errorEl: blockError,               step: 4 },
            barangay:              { input: barangay,            errorEl: barangayError,            step: 4 },
            city:                  { input: city,                errorEl: cityError,                step: 4 },
            province:              { input: province,            errorEl: provinceError,            step: 4 },
            region:                { input: region,              errorEl: regionError,              step: 4 },
            postal_code:           { input: postalCode,          errorEl: postalError,              step: 4 },
            permits:               { input: null,                errorEl: permitsError,             step: 4 },
            terms:                 { input: null,                errorEl: null,                     step: 4 }
        };

        /* ============================================
           FORM SUBMISSION
           ============================================ */

        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                if (isSubmitting) return;

                if (currentStep < totalSteps) {
                    goToStep(currentStep + 1);
                    return;
                }

                clearAllErrors();

                var firstFailingStep = validateAllStepsBeforeReview();
                if (firstFailingStep > 0) {
                    console.warn(
                        '[sign-up] Submit blocked: step ' + firstFailingStep + ' failed validation.'
                    );
                    showBanner('Step ' + firstFailingStep + ' needs attention. Please correct it before submitting.');
                    goToStep(firstFailingStep);
                    return;
                }

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
                                'Registration Submitted',
                                data.message || 'Your restaurant application has been submitted. Please sign in.',
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
                            goToStep(4);
                            return;
                        }

                        var target = (data && data.field && SERVER_FIELD_MAP[data.field])
                            ? SERVER_FIELD_MAP[data.field]
                            : null;

                        if (target && target.errorEl) {
                            if (target.input) {
                                showFieldError(target.input, target.errorEl, data.message);
                            } else {
                                target.errorEl.textContent = data.message;
                                target.errorEl.style.display = 'block';
                            }
                            if (target.step < currentStep) {
                                goToStep(target.step);
                            }
                            setTimeout(function () {
                                if (target.input) focusField(target.input);
                            }, 120);
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

        function clearAllErrors() {
            for (var s = 1; s <= totalSteps; s++) {
                clearStepErrors(s);
            }
            hideBanner();
        }

        /* ============================================
           NOTIFIER MODAL
           ============================================ */

        var notifierModal = document.getElementById('notifierModal');
        var notifierTitle = document.getElementById('notifierTitle');
        var notifierMessage = document.getElementById('notifierMessage');
        var notifierCloseBtn = document.getElementById('notifierCloseBtn');

        function showNotification(title, message, callback) {
            if (notifierTitle) notifierTitle.textContent = title || 'Success';
            if (notifierMessage) notifierMessage.textContent = message || '';
            if (notifierModal) notifierModal.classList.remove('hidden');
            if (notifierCloseBtn && callback) notifierCloseBtn._callback = callback;
        }

        function closeNotification() {
            if (notifierModal) notifierModal.classList.add('hidden');
            if (notifierCloseBtn && typeof notifierCloseBtn._callback === 'function') {
                var cb = notifierCloseBtn._callback;
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

        /* ============================================
           INIT
           ============================================ */
        goToStep(1);
    });
})();