/**
 * FitPal Customer Registration JavaScript
 *
 * Multi-step form: validation, dietary/allergy option cards, password toggles.
 * Form submits via fetch and redirects on success.
 *
 * Icon/image paths are relative to the PAGE that loads this script
 * (customer/pages/sign-up.php), not to this script file's own location.
 * shared/ from customer/pages/ = ../../shared/
 *
 * @package FitPal
 * @version 1.8
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ============================================
        // DOM REFERENCES
        // ============================================
        const form            = document.getElementById('registerForm');
        const steps           = document.querySelectorAll('.register-step');
        const progressSteps   = document.querySelectorAll('.progress-step');
        const progressLines   = document.querySelectorAll('.progress-line');
        const stepSubtitle    = document.getElementById('stepSubtitle');
        const currentStepInput = document.getElementById('currentStep');
        const registerError   = document.getElementById('registerError');
        const errorMessage    = document.getElementById('errorMessage');

        const nextButtons  = document.querySelectorAll('.btn-next');
        const prevButtons  = document.querySelectorAll('.btn-prev');
        const dietAction   = document.getElementById('dietAction');
        const allergyAction = document.getElementById('allergyAction');

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
        const termsCheckbox   = document.getElementById('terms');

        const firstNameError  = document.getElementById('firstNameError');
        const lastNameError   = document.getElementById('lastNameError');
        const birthdateError  = document.getElementById('birthdateError');
        const genderError     = document.getElementById('genderError');
        const emailError      = document.getElementById('emailError');
        const contactError    = document.getElementById('contactError');
        const usernameError   = document.getElementById('usernameError');
        const passwordError   = document.getElementById('passwordError');
        const confirmError    = document.getElementById('confirmError');
        const termsError      = document.getElementById('termsError');

        const stepTitles = [
            'Step 1 of 4: Personal Information',
            'Step 2 of 4: Dietary Preferences',
            'Step 3 of 4: Allergies',
            'Step 4 of 4: Fitness Goals',
        ];

        let currentStep  = 1;
        const totalSteps = 4;
        let isSubmitting = false;

        // ============================================
        // VALIDATION HELPERS
        // ============================================

        /**
         * Validate a Philippine mobile number.
         * Exactly 11 digits, starting with 09.
         *
         * @param {string} number
         * @returns {{ valid: boolean, message: string }}
         */
        function isValidPhilippineMobile(number) {
            const cleaned = number.replace(/\s/g, '');
            if (!/^09\d{9}$/.test(cleaned)) {
                return { valid: false, message: 'Enter a valid Philippine mobile number (11 digits, starting with 09).' };
            }
            return { valid: true, message: '' };
        }

        /**
         * Validate password rules.
         *
         * @param {string} pw
         * @returns {{ valid: boolean, message: string }}
         */
        function isValidPassword(pw) {
            if (pw.length < 8)  return { valid: false, message: 'Password must be at least 8 characters.' };
            if (pw.length > 20) return { valid: false, message: 'Password must be no more than 20 characters.' };
            if (!/^[A-Za-z0-9]+$/.test(pw)) {
                return { valid: false, message: 'Password can only contain letters and numbers.' };
            }
            if (!/[0-9]/.test(pw)) return { valid: false, message: 'Password must contain at least one number.' };
            if (!/[A-Za-z]/.test(pw)) return { valid: false, message: 'Password must contain at least one letter.' };
            return { valid: true, message: '' };
        }

        // Name pattern: letters, spaces, hyphens, straight apostrophe only
        const NAME_PATTERN = /^[A-Za-z\s\-']+$/;

        // ============================================
        // INPUT FILTERS
        // ============================================

        /**
         * Auto-capitalize the first letter of a name field.
         * Also filters out invalid characters.
         *
         * @param {HTMLElement} input - The input element
         */
        function setupNameInput(input) {
            if (!input) return;

            // Filter invalid characters on input
            input.addEventListener('input', function () {
                // Store cursor position
                const start = this.selectionStart;
                const end = this.selectionEnd;

                // Filter out invalid characters
                const filtered = this.value.replace(/[^A-Za-z\s\-']/g, '');
                
                // Auto-capitalize: capitalize first letter of each word
                const capitalized = filtered.replace(/\b\w/g, function (char) {
                    return char.toUpperCase();
                });

                // Only update if value changed
                if (this.value !== capitalized) {
                    this.value = capitalized;
                    
                    // Restore cursor position
                    const newStart = Math.min(start, this.value.length);
                    this.setSelectionRange(newStart, newStart);
                }

                clearFieldError(this, document.getElementById(this.id + 'Error'));
            });

            // Also handle blur to ensure proper capitalization
            input.addEventListener('blur', function () {
                if (this.value.length > 0) {
                    const capitalized = this.value.replace(/\b\w/g, function (char) {
                        return char.toUpperCase();
                    });
                    if (this.value !== capitalized) {
                        this.value = capitalized;
                    }
                }
            });
        }

        /**
         * Auto-lowercase the email field.
         *
         * @param {HTMLElement} input - The input element
         */
        function setupEmailInput(input) {
            if (!input) return;

            input.addEventListener('input', function () {
                // Store cursor position
                const start = this.selectionStart;
                const end = this.selectionEnd;

                // Convert to lowercase
                const lowercased = this.value.toLowerCase();

                // Only update if value changed
                if (this.value !== lowercased) {
                    this.value = lowercased;
                    
                    // Restore cursor position
                    const newStart = Math.min(start, this.value.length);
                    this.setSelectionRange(newStart, newStart);
                }

                clearFieldError(this, emailError);
            });

            // Also handle blur to ensure lowercase
            input.addEventListener('blur', function () {
                if (this.value.length > 0) {
                    const lowercased = this.value.toLowerCase();
                    if (this.value !== lowercased) {
                        this.value = lowercased;
                    }
                }
            });
        }

        function filterDigitsOnly(input) {
            if (!input) return;
            input.addEventListener('input', function () {
                this.value = this.value.replace(/[^0-9\s]/g, '');
                clearFieldError(this, contactError);
            });
        }

        function filterAlphanumUnderscore(input) {
            if (!input) return;
            input.addEventListener('input', function () {
                this.value = this.value.replace(/[^A-Za-z0-9_]/g, '');
                clearFieldError(this, usernameError);
            });
        }

        function filterAlphanumOnly(input) {
            if (!input) return;
            input.addEventListener('input', function () {
                this.value = this.value.replace(/[^A-Za-z0-9]/g, '');
            });
        }

        // Apply name auto-capitalization and filtering
        setupNameInput(firstName);
        setupNameInput(middleName);
        setupNameInput(lastName);

        // Apply email auto-lowercase
        setupEmailInput(email);

        filterDigitsOnly(contactNumber);
        filterAlphanumUnderscore(username);
        filterAlphanumOnly(password);
        filterAlphanumOnly(confirmPassword);

        // ============================================
        // STEP NAVIGATION
        // ============================================

        function goToStep(step) {
            if (step > currentStep && !validateStep(currentStep)) {
                return;
            }

            currentStep = step;
            if (currentStepInput) currentStepInput.value = step;

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

            if (stepSubtitle) stepSubtitle.textContent = stepTitles[step - 1] || ('Step ' + step + ' of 4');

            hideError();

            const firstInput = document.querySelector('#step' + step + ' input, #step' + step + ' select');
            if (firstInput) setTimeout(function () { firstInput.focus(); }, 100);

            const progressbar = document.querySelector('.register-progress');
            if (progressbar) progressbar.setAttribute('aria-valuenow', step);
        }

        // ============================================
        // VALIDATION
        // ============================================

        function validateStep(step) {
            if (step !== 1) return true; // Only step 1 has required fields to check here

            let valid = true;
            clearStepErrors(1);

            const fnVal = firstName ? firstName.value.trim() : '';
            if (fnVal.length < 2) {
                showFieldError(firstName, firstNameError, 'First name must be at least 2 characters.');
                valid = false;
            } else if (!NAME_PATTERN.test(fnVal)) {
                showFieldError(firstName, firstNameError, 'First name can only contain letters, spaces, hyphens, and apostrophes.');
                valid = false;
            }

            const lnVal = lastName ? lastName.value.trim() : '';
            if (lnVal.length < 2) {
                showFieldError(lastName, lastNameError, 'Last name must be at least 2 characters.');
                valid = false;
            } else if (!NAME_PATTERN.test(lnVal)) {
                showFieldError(lastName, lastNameError, 'Last name can only contain letters, spaces, hyphens, and apostrophes.');
                valid = false;
            }

            if (!birthdate || !birthdate.value) {
                showFieldError(birthdate, birthdateError, 'Please select your birthdate.');
                valid = false;
            } else {
                const bd  = new Date(birthdate.value);
                const now = new Date();
                let age   = now.getFullYear() - bd.getFullYear();
                if (now.getMonth() < bd.getMonth() || (now.getMonth() === bd.getMonth() && now.getDate() < bd.getDate())) {
                    age--;
                }
                if (age < 13) {
                    showFieldError(birthdate, birthdateError, 'You must be at least 13 years old.');
                    valid = false;
                } else if (age > 120) {
                    showFieldError(birthdate, birthdateError, 'Please enter a valid birthdate.');
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
                showFieldError(contactNumber, contactError, 'Please enter your contact number.');
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
                showFieldError(username, usernameError, 'Username can only contain letters, numbers, and underscores.');
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

        function validateStep4() {
            if (!termsCheckbox || termsCheckbox.checked) return true;
            if (termsError) {
                termsError.textContent   = 'You must agree to the Terms and Conditions and Privacy Policy.';
                termsError.style.display = 'block';
            }
            const termsGroup = document.getElementById('termsGroup');
            if (termsGroup) termsGroup.classList.add('error');
            return false;
        }

        function clearStepErrors(step) {
            document.querySelectorAll('#step' + step + ' .form-error').forEach(function (el) {
                el.textContent   = '';
                el.style.display = 'none';
            });
            document.querySelectorAll('#step' + step + ' .form-control').forEach(function (el) {
                el.classList.remove('error');
            });
            if (step === 4) {
                const tg = document.getElementById('termsGroup');
                if (tg) tg.classList.remove('error');
                if (termsError) { termsError.textContent = ''; termsError.style.display = 'none'; }
            }
        }

        // ============================================
        // FIELD ERROR HELPERS
        // ============================================

        function showFieldError(input, errorEl, message) {
            if (input)   input.classList.add('error');
            if (errorEl) { errorEl.textContent = message; errorEl.style.display = 'block'; }
        }

        function clearFieldError(input, errorEl) {
            if (input)   input.classList.remove('error');
            if (errorEl) { errorEl.textContent = ''; errorEl.style.display = 'none'; }
        }

        function showError(message) {
            if (registerError && errorMessage) {
                errorMessage.textContent  = message;
                registerError.style.display = 'block';
            }
        }

        function hideError() {
            if (registerError) registerError.style.display = 'none';
        }

        // ============================================
        // PASSWORD TOGGLES
        // Icon paths relative to the page (customer/pages/sign-up.php):
        //   shared/ = ../../shared/
        // ============================================
        function setupPasswordToggle(toggleId, inputId, iconId) {
            const btn   = document.getElementById(toggleId);
            const input = document.getElementById(inputId);
            const icon  = document.getElementById(iconId);
            if (!btn || !input || !icon) return;

            btn.addEventListener('click', function () {
                const isPassword = input.type === 'password';
                input.type       = isPassword ? 'text' : 'password';
                const iconFile   = isPassword ? 'password-unhide.svg' : 'password-hide.svg';
                icon.src         = '../../shared/assets/images/icons/' + iconFile;
                icon.alt         = isPassword ? 'Hide password' : 'Show password';
                this.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            });
        }

        setupPasswordToggle('togglePassword', 'password', 'passwordIcon');
        setupPasswordToggle('toggleConfirmPassword', 'confirm_password', 'confirmPasswordIcon');

        // ============================================
        // OPTION CARDS
        // ============================================
        function setupOptionCards(containerId) {
            const container = document.getElementById(containerId);
            if (!container) return;

            container.querySelectorAll('.option-card').forEach(function (card) {
                const checkbox = card.querySelector('input[type="checkbox"]');
                if (!checkbox) return;

                card.addEventListener('click', function (e) {
                    if (e.target.tagName === 'INPUT') return;
                    checkbox.checked = !checkbox.checked;
                    card.classList.toggle('selected', checkbox.checked);
                    checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                });

                checkbox.addEventListener('change', function () {
                    card.classList.toggle('selected', this.checked);
                });

                if (checkbox.checked) card.classList.add('selected');
            });
        }

        setupOptionCards('dietaryOptions');
        setupOptionCards('allergyOptions');

        // ============================================
        // DIETARY - Dynamic button for Step 2
        // ============================================
        const dietCheckboxes = document.querySelectorAll('#dietaryOptions input[type="checkbox"]');

        if (dietCheckboxes.length && dietAction) {
            function updateDietAction() {
                const hasSelection = Array.from(dietCheckboxes).some(function (cb) { return cb.checked; });
                dietAction.textContent = hasSelection ? 'Next Step \u2192' : 'Skip Step';
                dietAction.disabled = false;
            }

            dietCheckboxes.forEach(function (cb) {
                cb.addEventListener('change', updateDietAction);
            });

            dietAction.addEventListener('click', function () {
                // If no selection, we're skipping
                // If selection exists, we're proceeding to next step
                goToStep(3);
            });

            // Initialize button state
            updateDietAction();
        }

        // ============================================
        // ALLERGIES - Dynamic button for Step 3
        // ============================================
        const allergyCheckboxes = document.querySelectorAll('#allergyOptions input[type="checkbox"]');

        if (allergyCheckboxes.length && allergyAction) {
            function updateAllergyAction() {
                const hasSelection = Array.from(allergyCheckboxes).some(function (cb) { return cb.checked; });
                allergyAction.textContent = hasSelection ? 'Next Step \u2192' : 'Skip Step';
                allergyAction.disabled = false;
            }

            allergyCheckboxes.forEach(function (cb) {
                cb.addEventListener('change', updateAllergyAction);
            });

            allergyAction.addEventListener('click', function () {
                // If no selection, we're skipping
                // If selection exists, we're proceeding to next step
                goToStep(4);
            });

            // Initialize button state
            updateAllergyAction();
        }

        // ============================================
        // STEP NAVIGATION EVENTS
        // ============================================
        nextButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var nextStep = parseInt(this.getAttribute('data-next'), 10);
                if (isNaN(nextStep) || nextStep > totalSteps) return;

                goToStep(nextStep);
            });
        });

        prevButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var prevStep = parseInt(this.getAttribute('data-prev'), 10);
                if (!isNaN(prevStep) && prevStep >= 1) goToStep(prevStep);
            });
        });

        // ============================================
        // REAL-TIME PASSWORD FEEDBACK
        // ============================================
        if (password) {
            password.addEventListener('input', function () {
                var val = this.value;
                if (val.length === 0) { clearFieldError(this, passwordError); return; }
                var check = isValidPassword(val);
                check.valid ? clearFieldError(this, passwordError) : showFieldError(this, passwordError, check.message);

                if (confirmPassword && confirmPassword.value) {
                    confirmPassword.value === val
                        ? clearFieldError(confirmPassword, confirmError)
                        : showFieldError(confirmPassword, confirmError, 'Passwords do not match.');
                }
            });
        }

        if (confirmPassword) {
            confirmPassword.addEventListener('input', function () {
                var val = this.value;
                if (!val) { clearFieldError(this, confirmError); return; }
                val === (password ? password.value : '')
                    ? clearFieldError(this, confirmError)
                    : showFieldError(this, confirmError, 'Passwords do not match.');
            });
        }

        // ============================================
        // BLUR VALIDATION FOR KEY FIELDS
        // ============================================
        [firstName, lastName].forEach(function (field) {
            if (!field) return;
            field.addEventListener('blur', function () {
                var val     = this.value.trim();
                var errorEl = document.getElementById(this.id + 'Error');
                if (!val) return;
                if (val.length < 2) {
                    showFieldError(this, errorEl, 'Must be at least 2 characters.');
                } else if (!NAME_PATTERN.test(val)) {
                    showFieldError(this, errorEl, 'Can only contain letters, spaces, hyphens, and apostrophes.');
                } else {
                    clearFieldError(this, errorEl);
                }
            });
        });

        if (contactNumber) {
            contactNumber.addEventListener('blur', function () {
                var val = this.value.trim();
                if (!val) return;
                var ph = isValidPhilippineMobile(val);
                ph.valid ? clearFieldError(this, contactError) : showFieldError(this, contactError, ph.message);
            });
        }

        if (email) {
            // Email validation on blur
            email.addEventListener('blur', function () {
                var val = this.value.trim();
                if (!val) return;
                /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)
                    ? clearFieldError(this, emailError)
                    : showFieldError(this, emailError, 'Please enter a valid email address.');
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
                    showFieldError(this, usernameError, 'Username can only contain letters, numbers, and underscores.');
                } else {
                    clearFieldError(this, usernameError);
                }
            });
        }

        if (termsCheckbox) {
            termsCheckbox.addEventListener('change', function () {
                if (this.checked) {
                    clearFieldError(null, termsError);
                    var tg = document.getElementById('termsGroup');
                    if (tg) tg.classList.remove('error');
                }
            });
        }

        // ============================================
        // FORM SUBMISSION
        // ============================================
        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                if (isSubmitting) return;

                // If not on step 4, move to next step instead of submitting
                if (currentStep < 4) {
                    // For step 1, validate before moving
                    if (currentStep === 1 && !validateStep(1)) {
                        goToStep(1);
                        return;
                    }
                    // For steps 2 and 3, just move forward (validation is handled by the dynamic button)
                    // but we need to ensure at least one option is selected if the button says "Next Step"
                    if (currentStep === 2) {
                        var checkedDiet = Array.from(dietCheckboxes).some(function (cb) { return cb.checked; });
                        if (!checkedDiet && dietAction.textContent.includes('Next')) {
                            // This shouldn't happen since the button text changes based on selection
                            showError('Please select at least one dietary preference, or click "Skip Step".');
                            return;
                        }
                        goToStep(3);
                        return;
                    }
                    if (currentStep === 3) {
                        var checkedAllergy = Array.from(allergyCheckboxes).some(function (cb) { return cb.checked; });
                        if (!checkedAllergy && allergyAction.textContent.includes('Next')) {
                            showError('Please select at least one allergy, or click "Skip Step".');
                            return;
                        }
                        goToStep(4);
                        return;
                    }
                    // For any other step, just move forward
                    goToStep(currentStep + 1);
                    return;
                }

                // --- Step 4 submission logic ---
                if (!validateStep(1)) { goToStep(1); return; }
                if (!validateStep4()) { goToStep(4); return; }

                isSubmitting = true;
                var submitBtn = document.getElementById('registerBtn');
                if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Creating Account...'; }

                fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form)
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.status === 'success') {
                        showNotification('Account Created', data.message || 'Account created successfully!', function () {
                            window.location.href = data.redirect || 'sign-in.php';
                        });
                    } else {
                        isSubmitting = false;
                        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Create Account'; }
                        if (data.field === 'terms') {
                            if (termsError) { termsError.textContent = data.message; termsError.style.display = 'block'; }
                            var tg2 = document.getElementById('termsGroup');
                            if (tg2) tg2.classList.add('error');
                            goToStep(4);
                        } else {
                            showError(data.message || 'An error occurred. Please try again.');
                        }
                    }
                })
                .catch(function () {
                    isSubmitting = false;
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Create Account'; }
                    showError('An unexpected error occurred. Please try again.');
                });
            });
        }

        // ============================================
        // NOTIFICATION MODAL
        // ============================================
        var notifierModal   = document.getElementById('notifierModal');
        var notifierTitle   = document.getElementById('notifierTitle');
        var notifierMessage = document.getElementById('notifierMessage');
        var notifierCloseBtn = document.getElementById('notifierCloseBtn');

        function showNotification(title, message, callback) {
            if (notifierTitle)   notifierTitle.textContent   = title || 'Success!';
            if (notifierMessage) notifierMessage.textContent = message || '';
            if (notifierModal)   notifierModal.classList.remove('hidden');
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

        // ============================================
        // INITIALISE
        // ============================================
        goToStep(1);

    });

})();