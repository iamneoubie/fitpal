/**
 * FitPal Customer Registration JavaScript
 * 
 * Handles multi-step registration form with validation,
 * dietary preferences, allergies, fitness goals, and terms checkbox.
 * 
 * @package FitPal
 * @version 1.0
 */

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        
        // ============================================
        // DOM ELEMENTS
        // ============================================
        const form = document.getElementById('registerForm');
        const currentStepInput = document.getElementById('currentStep');
        const stepSubtitle = document.getElementById('stepSubtitle');
        const registerBtn = document.getElementById('registerBtn');
        const notifierModal = document.getElementById('notifierModal');
        const notifierMessage = document.getElementById('notifierMessage');
        const notifierCloseBtn = document.getElementById('notifierCloseBtn');
        const errorContainer = document.getElementById('registerError');
        const errorMessage = document.getElementById('errorMessage');

        // Step elements
        const step1 = document.getElementById('step1');
        const step2 = document.getElementById('step2');
        const step3 = document.getElementById('step3');
        const step4 = document.getElementById('step4');

        // Progress steps
        const progressSteps = document.querySelectorAll('.progress-step');

        // Form fields
        const firstName = document.getElementById('first_name');
        const middleName = document.getElementById('middle_name');
        const lastName = document.getElementById('last_name');
        const birthdate = document.getElementById('birthdate');
        const gender = document.getElementById('gender');
        const email = document.getElementById('email');
        const contactNumber = document.getElementById('contact_number');
        const username = document.getElementById('username');
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');

        // Password toggles
        const togglePassword = document.getElementById('togglePassword');
        const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
        const passwordIcon = document.getElementById('passwordIcon');
        const confirmPasswordIcon = document.getElementById('confirmPasswordIcon');

        // Terms checkbox
        const termsCheckbox = document.getElementById('terms');

        // Error elements
        const firstNameError = document.getElementById('firstNameError');
        const lastNameError = document.getElementById('lastNameError');
        const birthdateError = document.getElementById('birthdateError');
        const genderError = document.getElementById('genderError');
        const emailError = document.getElementById('emailError');
        const contactError = document.getElementById('contactError');
        const usernameError = document.getElementById('usernameError');
        const passwordError = document.getElementById('passwordError');
        const confirmError = document.getElementById('confirmError');
        const termsError = document.getElementById('termsError');

        // Step 2: Dietary preferences
        const dietCheckboxes = document.querySelectorAll('#dietaryOptions input[type="checkbox"]');
        const dietNextBtn = document.getElementById('dietNext');

        // Step 3: Allergies
        const allergyCheckboxes = document.querySelectorAll('#allergyOptions input[type="checkbox"]');
        const allergyNextBtn = document.getElementById('allergyNext');

        // Step navigation buttons
        const nextButtons = document.querySelectorAll('.btn-next');
        const prevButtons = document.querySelectorAll('.btn-prev');

        // State
        let isSubmitting = false;
        let hasInteracted = {};
        let currentStep = 1;

        // ============================================
        // STEP TITLES
        // ============================================
        const stepTitles = {
            1: 'Step 1 of 4: Personal Information',
            2: 'Step 2 of 4: Dietary Preferences',
            3: 'Step 3 of 4: Allergies',
            4: 'Step 4 of 4: Fitness Goals'
        };

        // ============================================
        // PASSWORD TOGGLE
        // ============================================
        if (togglePassword && password && passwordIcon) {
            togglePassword.addEventListener('click', function() {
                const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
                password.setAttribute('type', type);
                
                if (type === 'text') {
                    passwordIcon.src = '../../shared/assets/images/icons/password-unhide.svg';
                    passwordIcon.alt = 'Show password';
                } else {
                    passwordIcon.src = '../../shared/assets/images/icons/password-hide.svg';
                    passwordIcon.alt = 'Hide password';
                }
            });
        }

        if (toggleConfirmPassword && confirmPassword && confirmPasswordIcon) {
            toggleConfirmPassword.addEventListener('click', function() {
                const type = confirmPassword.getAttribute('type') === 'password' ? 'text' : 'password';
                confirmPassword.setAttribute('type', type);
                
                if (type === 'text') {
                    confirmPasswordIcon.src = '../../shared/assets/images/icons/password-unhide.svg';
                    confirmPasswordIcon.alt = 'Show password';
                } else {
                    confirmPasswordIcon.src = '../../shared/assets/images/icons/password-hide.svg';
                    confirmPasswordIcon.alt = 'Hide password';
                }
            });
        }

        // ============================================
        // NOTIFIER FUNCTIONS
        // ============================================
        function showNotifier(message) {
            notifierMessage.textContent = message;
            notifierModal.classList.remove('hidden');
        }

        function closeNotifier() {
            notifierModal.classList.add('hidden');
        }

        if (notifierCloseBtn) {
            notifierCloseBtn.addEventListener('click', closeNotifier);
        }

        notifierModal.addEventListener('click', function(e) {
            if (e.target === notifierModal) closeNotifier();
        });

        // ============================================
        // ERROR HANDLING
        // ============================================
        function showError(message) {
            errorMessage.textContent = message;
            errorContainer.style.display = 'block';
        }

        function hideError() {
            errorContainer.style.display = 'none';
        }

        function showFieldError(field, errorElement, message) {
            if (field) field.classList.add('error');
            if (errorElement) {
                errorElement.textContent = message;
                errorElement.style.display = 'block';
            }
        }

        function clearFieldError(field, errorElement) {
            if (field) field.classList.remove('error');
            if (errorElement) {
                errorElement.textContent = '';
                errorElement.style.display = 'none';
            }
        }

        function clearAllErrors() {
            document.querySelectorAll('.form-control.error').forEach(el => el.classList.remove('error'));
            document.querySelectorAll('.form-error').forEach(el => {
                el.textContent = '';
                el.style.display = 'none';
            });
            hideError();
        }

        // ============================================
        // VALIDATION FUNCTIONS
        // ============================================
        function validateEmail(emailValue) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailValue);
        }

        function validateUsername(usernameValue) {
            if (usernameValue.length < 3 || usernameValue.length > 20) return false;
            if (!/^[A-Za-z0-9_]+$/.test(usernameValue)) return false;
            return true;
        }

        function validatePassword(passwordValue) {
            if (passwordValue.length < 8 || passwordValue.length > 16) return false;
            if (!/[A-Z]/.test(passwordValue) || !/[a-z]/.test(passwordValue)) return false;
            if (!/[0-9]/.test(passwordValue)) return false;
            return true;
        }

        function validateAge(birthdateValue) {
            if (!birthdateValue) return { valid: false, reason: 'empty' };
            
            const today = new Date();
            const birthDate = new Date(birthdateValue);
            let age = today.getFullYear() - birthDate.getFullYear();
            const monthDiff = today.getMonth() - birthDate.getMonth();
            
            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                age--;
            }
            
            if (age < 13) return { valid: false, reason: 'too-young' };
            if (age > 120) return { valid: false, reason: 'too-old' };
            
            return { valid: true };
        }

        function validatePhone(phoneValue) {
            const cleaned = phoneValue.replace(/\D/g, '');
            
            if (cleaned.length === 11 && cleaned.startsWith('09')) return true;
            if (cleaned.length === 10 && cleaned.startsWith('9')) return true;
            if ((cleaned.length === 12 && cleaned.startsWith('63')) || 
                (cleaned.length === 13 && cleaned.startsWith('063')) ||
                (cleaned.length === 12 && cleaned.startsWith('639'))) {
                return true;
            }
            
            return false;
        }

        function validateStep1() {
            let isValid = true;
            clearAllErrors();

            // First name
            if (!firstName.value.trim()) {
                showFieldError(firstName, firstNameError, 'First name is required');
                isValid = false;
            }

            // Last name
            if (!lastName.value.trim()) {
                showFieldError(lastName, lastNameError, 'Last name is required');
                isValid = false;
            }

            // Birthdate
            if (!birthdate.value) {
                showFieldError(birthdate, birthdateError, 'Birthdate is required');
                isValid = false;
            } else {
                const ageValidation = validateAge(birthdate.value);
                if (!ageValidation.valid) {
                    if (ageValidation.reason === 'too-young') {
                        showFieldError(birthdate, birthdateError, 'You must be at least 13 years old');
                    } else {
                        showFieldError(birthdate, birthdateError, 'Invalid birthdate');
                    }
                    isValid = false;
                }
            }

            // Gender
            if (!gender.value) {
                showFieldError(gender, genderError, 'Gender is required');
                isValid = false;
            }

            // Email
            if (!email.value.trim()) {
                showFieldError(email, emailError, 'Email is required');
                isValid = false;
            } else if (!validateEmail(email.value)) {
                showFieldError(email, emailError, 'Please enter a valid email address');
                isValid = false;
            }

            // Contact number
            if (!contactNumber.value.trim()) {
                showFieldError(contactNumber, contactError, 'Contact number is required');
                isValid = false;
            } else if (!validatePhone(contactNumber.value)) {
                showFieldError(contactNumber, contactError, 'Please enter a valid Philippine mobile number');
                isValid = false;
            }

            // Username
            if (!username.value.trim()) {
                showFieldError(username, usernameError, 'Username is required');
                isValid = false;
            } else if (!validateUsername(username.value)) {
                showFieldError(username, usernameError, 'Username must be 3-20 characters with letters, numbers, underscores only');
                isValid = false;
            }

            // Password
            if (!password.value) {
                showFieldError(password, passwordError, 'Password is required');
                isValid = false;
            } else if (!validatePassword(password.value)) {
                showFieldError(password, passwordError, 'Password must be 8-16 characters with uppercase, lowercase, and number');
                isValid = false;
            }

            // Confirm password
            if (!confirmPassword.value) {
                showFieldError(confirmPassword, confirmError, 'Please confirm your password');
                isValid = false;
            } else if (password.value !== confirmPassword.value) {
                showFieldError(confirmPassword, confirmError, 'Passwords do not match');
                isValid = false;
            }

            return isValid;
        }

        function validateStep4() {
            let isValid = true;
            
            // Terms checkbox
            if (!termsCheckbox.checked) {
                showFieldError(termsCheckbox, termsError, 'You must agree to the Terms and Conditions and Privacy Policy');
                isValid = false;
            }

            return isValid;
        }

        // ============================================
        // STEP NAVIGATION
        // ============================================
        function goToStep(step) {
            // Validate current step before proceeding
            if (step > currentStep) {
                if (currentStep === 1 && !validateStep1()) {
                    return;
                }
            }

            // Hide all steps
            step1.style.display = 'none';
            step2.style.display = 'none';
            step3.style.display = 'none';
            step4.style.display = 'none';

            // Show target step
            switch(step) {
                case 1: step1.style.display = 'block'; break;
                case 2: step2.style.display = 'block'; break;
                case 3: step3.style.display = 'block'; break;
                case 4: step4.style.display = 'block'; break;
            }

            // Update progress
            progressSteps.forEach(el => {
                const stepNum = parseInt(el.dataset.step);
                el.classList.remove('active', 'completed');
                if (stepNum === step) {
                    el.classList.add('active');
                } else if (stepNum < step) {
                    el.classList.add('completed');
                }
            });

            // Update subtitle
            stepSubtitle.textContent = stepTitles[step] || '';

            // Update hidden input
            currentStepInput.value = step;
            currentStep = step;
        }

        // Next buttons
        nextButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                const nextStep = parseInt(this.dataset.next);
                if (nextStep) {
                    goToStep(nextStep);
                }
            });
        });

        // Previous buttons
        prevButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                const prevStep = parseInt(this.dataset.prev);
                if (prevStep) {
                    goToStep(prevStep);
                }
            });
        });

        // ============================================
        // DIETARY PREFERENCES - Enable/Disable Next Button
        // ============================================
        dietCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                // At least one selected or none (skip allowed)
                const checked = document.querySelectorAll('#dietaryOptions input[type="checkbox"]:checked');
                dietNextBtn.disabled = false; // Always enabled - can skip
            });
        });

        // Skip diet step
        document.getElementById('skipDiet').addEventListener('click', function() {
            // Uncheck all
            dietCheckboxes.forEach(cb => cb.checked = false);
            goToStep(3);
        });

        // ============================================
        // ALLERGIES - Enable/Disable Next Button
        // ============================================
        allergyCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                // At least one selected or none (skip allowed)
                allergyNextBtn.disabled = false; // Always enabled - can skip
            });
        });

        // Skip allergy step
        document.getElementById('skipAllergies').addEventListener('click', function() {
            // Uncheck all
            allergyCheckboxes.forEach(cb => cb.checked = false);
            goToStep(4);
        });

        // ============================================
        // OPTION CARD TOGGLE (Visual feedback)
        // ============================================
        document.querySelectorAll('.option-card').forEach(card => {
            card.addEventListener('click', function(e) {
                // Don't toggle if clicking the checkbox directly
                if (e.target.tagName === 'INPUT') return;
                
                const checkbox = this.querySelector('input[type="checkbox"]');
                if (checkbox) {
                    checkbox.checked = !checkbox.checked;
                    this.classList.toggle('selected', checkbox.checked);
                    
                    // Trigger change event
                    checkbox.dispatchEvent(new Event('change'));
                }
            });

            // Keep selected state in sync with checkbox
            const checkbox = card.querySelector('input[type="checkbox"]');
            if (checkbox) {
                checkbox.addEventListener('change', function() {
                    card.classList.toggle('selected', this.checked);
                });
            }
        });

        // ============================================
        // REAL-TIME FIELD VALIDATION
        // ============================================
        // First name
        firstName.addEventListener('blur', function() {
            if (!this.value.trim()) {
                showFieldError(this, firstNameError, 'First name is required');
            } else {
                clearFieldError(this, firstNameError);
            }
        });
        firstName.addEventListener('input', function() {
            clearFieldError(this, firstNameError);
        });

        // Last name
        lastName.addEventListener('blur', function() {
            if (!this.value.trim()) {
                showFieldError(this, lastNameError, 'Last name is required');
            } else {
                clearFieldError(this, lastNameError);
            }
        });
        lastName.addEventListener('input', function() {
            clearFieldError(this, lastNameError);
        });

        // Email
        email.addEventListener('blur', function() {
            if (!this.value.trim()) {
                showFieldError(this, emailError, 'Email is required');
            } else if (!validateEmail(this.value)) {
                showFieldError(this, emailError, 'Please enter a valid email address');
            } else {
                clearFieldError(this, emailError);
            }
        });
        email.addEventListener('input', function() {
            clearFieldError(this, emailError);
        });

        // Username
        username.addEventListener('blur', function() {
            if (!this.value.trim()) {
                showFieldError(this, usernameError, 'Username is required');
            } else if (!validateUsername(this.value)) {
                showFieldError(this, usernameError, 'Username must be 3-20 characters with letters, numbers, underscores only');
            } else {
                clearFieldError(this, usernameError);
            }
        });
        username.addEventListener('input', function() {
            clearFieldError(this, usernameError);
        });

        // Password
        password.addEventListener('blur', function() {
            if (!this.value) {
                showFieldError(this, passwordError, 'Password is required');
            } else if (!validatePassword(this.value)) {
                showFieldError(this, passwordError, 'Password must be 8-16 characters with uppercase, lowercase, and number');
            } else {
                clearFieldError(this, passwordError);
            }
        });
        password.addEventListener('input', function() {
            clearFieldError(this, passwordError);
            // Check confirm password match
            if (confirmPassword.value && password.value !== confirmPassword.value) {
                showFieldError(confirmPassword, confirmError, 'Passwords do not match');
            } else if (confirmPassword.value) {
                clearFieldError(confirmPassword, confirmError);
            }
        });

        // Confirm password
        confirmPassword.addEventListener('blur', function() {
            if (!this.value) {
                showFieldError(this, confirmError, 'Please confirm your password');
            } else if (password.value !== this.value) {
                showFieldError(this, confirmError, 'Passwords do not match');
            } else {
                clearFieldError(this, confirmError);
            }
        });
        confirmPassword.addEventListener('input', function() {
            if (password.value !== this.value) {
                showFieldError(this, confirmError, 'Passwords do not match');
            } else {
                clearFieldError(this, confirmError);
            }
        });

        // ============================================
        // TERMS CHECKBOX VALIDATION
        // ============================================
        termsCheckbox.addEventListener('change', function() {
            if (this.checked) {
                clearFieldError(this, termsError);
            }
        });

        // ============================================
        // FORM SUBMISSION
        // ============================================
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            if (isSubmitting) return;

            // Validate all steps
            clearAllErrors();

            if (!validateStep1()) {
                goToStep(1);
                showNotifier('Please fix the errors in Personal Information');
                return;
            }

            if (!validateStep4()) {
                goToStep(4);
                showNotifier('Please agree to the Terms and Conditions');
                return;
            }

            // Show loading state
            isSubmitting = true;
            const originalText = registerBtn.textContent;
            registerBtn.textContent = 'Creating Account...';
            registerBtn.disabled = true;

            // Submit form via AJAX
            const formData = new FormData(form);

            fetch('../backend/handlers/register_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    showNotifier(data.message || 'Account created successfully! Redirecting...');
                    setTimeout(() => {
                        window.location.href = data.redirect || 'dashboard.php';
                    }, 2000);
                } else {
                    // Handle specific field errors
                    if (data.field) {
                        switch(data.field) {
                            case 'email':
                                showFieldError(email, emailError, data.message);
                                break;
                            case 'username':
                                showFieldError(username, usernameError, data.message);
                                break;
                            case 'password':
                                showFieldError(password, passwordError, data.message);
                                break;
                            case 'confirm_password':
                                showFieldError(confirmPassword, confirmError, data.message);
                                break;
                            case 'birthdate':
                                showFieldError(birthdate, birthdateError, data.message);
                                break;
                            case 'gender':
                                showFieldError(gender, genderError, data.message);
                                break;
                            case 'terms':
                                showFieldError(termsCheckbox, termsError, data.message);
                                break;
                            default:
                                showError(data.message || 'Registration failed. Please try again.');
                        }
                    } else {
                        // General error
                        if (data.message === 'duplicate-account') {
                            showError('An account with this email, username, or contact number already exists.');
                        } else {
                            showError(data.message || 'Registration failed. Please try again.');
                        }
                    }
                    showNotifier('Please fix the errors and try again.');
                }
            })
            .catch(error => {
                console.error('Registration error:', error);
                showError('Network error. Please check your connection and try again.');
                showNotifier('Network error. Please try again.');
            })
            .finally(() => {
                isSubmitting = false;
                registerBtn.textContent = originalText;
                registerBtn.disabled = false;
            });
        });

        // ============================================
        // INITIALIZATION
        // ============================================
        // Show first step
        goToStep(1);

        console.log('Customer registration initialized successfully');
    });
})();