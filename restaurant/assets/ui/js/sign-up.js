/**
 * FitPal Restaurant Registration JavaScript
 *
 * Two-step form with name/email filtering, password feedback,
 * permit upload grid (1-5 photos), and fetch submission.
 *
 * @package FitPal
 * @version 1.1 — Adds permit upload grid.
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
        var password = document.getElementById('password');
        var confirmPassword = document.getElementById('confirm_password');

        // Step 2 — Business
        var businessName = document.getElementById('business_name');
        var cuisineType = document.getElementById('cuisine_type');
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

        // Errors
        var firstNameError = document.getElementById('firstNameError');
        var lastNameError = document.getElementById('lastNameError');
        var emailError = document.getElementById('emailError');
        var contactError = document.getElementById('contactError');
        var usernameError = document.getElementById('usernameError');
        var passwordError = document.getElementById('passwordError');
        var confirmError = document.getElementById('confirmError');
        var businessNameError = document.getElementById('businessNameError');
        var cuisineTypeError = document.getElementById('cuisineTypeError');
        var branchNameError = document.getElementById('branchNameError');
        var blockError = document.getElementById('blockError');
        var cityError = document.getElementById('cityError');
        var postalError = document.getElementById('postalError');
        var permitsError = document.getElementById('permitsError');

        var stepTitles = [
            'Step 1 of 2 — Account Holder',
            'Step 2 of 2 — Business Details'
        ];

        var currentStep = 1;
        var totalSteps = 2;
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

            if (step === 2 && termsGroup) {
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

        setupNameInput(block, blockError);
        setupNameInput(barangay, null);
        setupNameInput(city, cityError);
        setupNameInput(province, null);
        setupNameInput(region, null);

        setupEmailInput(email);
        setupDigitsOnly(contactNumber, contactError);
        setupAlphanumUnderscore(username);
        setupAlphanumOnly(password);
        setupAlphanumOnly(confirmPassword);
        setupPostal(postalCode);

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

        function validateStep2() {
            var valid = true;
            clearStepErrors(2);

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

            var cityVal = city ? city.value.trim() : '';
            if (!cityVal) {
                showFieldError(city, cityError, 'City or municipality is required.');
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

        function validateStep(step) {
            switch (step) {
                case 1: return validateStep1();
                case 2: return validateStep2();
                default: return true;
            }
        }

        /* ============================================
           STEP NAVIGATION
           ============================================ */

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
                var n = i + 1;
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
            first_name: { input: firstName, errorEl: firstNameError, step: 1 },
            last_name: { input: lastName, errorEl: lastNameError, step: 1 },
            email: { input: email, errorEl: emailError, step: 1 },
            contact_number: { input: contactNumber, errorEl: contactError, step: 1 },
            username: { input: username, errorEl: usernameError, step: 1 },
            password: { input: password, errorEl: passwordError, step: 1 },
            confirm_password: { input: confirmPassword, errorEl: confirmError, step: 1 },

            business_name: { input: businessName, errorEl: businessNameError, step: 2 },
            cuisine_type: { input: cuisineType, errorEl: cuisineTypeError, step: 2 },
            branch_name: { input: branchName, errorEl: branchNameError, step: 2 },
            block: { input: block, errorEl: blockError, step: 2 },
            city: { input: city, errorEl: cityError, step: 2 },
            postal_code: { input: postalCode, errorEl: postalError, step: 2 },
            permits: { input: null, errorEl: permitsError, step: 2 },
            terms: { input: null, errorEl: null, step: 2 }
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

                if (!validateStep1()) { goToStep(1); return; }
                if (!validateStep2()) { goToStep(2); return; }

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
                            goToStep(2);
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