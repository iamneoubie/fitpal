/**
 * FitPal Customer Registration JavaScript
 * Multi-step form with dietary preferences, allergies, and fitness goals
 * 
 * @package FitPal
 * @version 1.0
 */

document.addEventListener('DOMContentLoaded', function() {
    'use strict';

    // ===== DOM ELEMENTS =====
    const form = document.getElementById('registerForm');
    const steps = document.querySelectorAll('.register-step');
    const progressSteps = document.querySelectorAll('.progress-step');
    const stepSubtitle = document.getElementById('stepSubtitle');
    const currentStepInput = document.getElementById('currentStep');
    
    const btnNext = document.querySelectorAll('.btn-next');
    const btnPrev = document.querySelectorAll('.btn-prev');
    const btnSkipDiet = document.getElementById('skipDiet');
    const btnSkipAllergies = document.getElementById('skipAllergies');
    const btnRegister = document.getElementById('registerBtn');
    
    const dietNext = document.getElementById('dietNext');
    const allergyNext = document.getElementById('allergyNext');
    const dietaryCheckboxes = document.querySelectorAll('input[name="dietary_preferences[]"]');
    const allergyCheckboxes = document.querySelectorAll('input[name="allergies[]"]');
    const optionCards = document.querySelectorAll('.option-card');
    
    // Password toggle
    const togglePassword = document.getElementById('togglePassword');
    const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
    const passwordInput = document.getElementById('password');
    const confirmPasswordInput = document.getElementById('confirm_password');
    const passwordIcon = document.getElementById('passwordIcon');
    const confirmPasswordIcon = document.getElementById('confirmPasswordIcon');
    
    // Error elements
    const registerError = document.getElementById('registerError');
    const errorMessage = document.getElementById('errorMessage');
    
    // Modal
    const modal = document.getElementById('notifierModal');
    const modalMessage = document.getElementById('notifierMessage');
    const modalClose = document.getElementById('notifierCloseBtn');

    // ===== STATE =====
    let currentStep = 1;
    const totalSteps = 4;
    let isSubmitting = false;
    let isModalOpen = false;

    // ===== STEP TITLES =====
    const stepTitles = [
        '',
        'Step 1 of 4: Personal Information',
        'Step 2 of 4: Dietary Preferences',
        'Step 3 of 4: Allergies',
        'Step 4 of 4: Fitness Goals'
    ];

    // ===== PASSWORD TOGGLE =====
    if (togglePassword && passwordInput && passwordIcon) {
        togglePassword.addEventListener('click', function(e) {
            e.preventDefault();
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            if (type === 'text') {
                passwordIcon.src = '../../shared/assets/images/icons/password-unhide.svg';
                passwordIcon.alt = 'Show password';
            } else {
                passwordIcon.src = '../../shared/assets/images/icons/password-hide.svg';
                passwordIcon.alt = 'Hide password';
            }
        });
    }

    if (toggleConfirmPassword && confirmPasswordInput && confirmPasswordIcon) {
        toggleConfirmPassword.addEventListener('click', function(e) {
            e.preventDefault();
            const type = confirmPasswordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            confirmPasswordInput.setAttribute('type', type);
            
            if (type === 'text') {
                confirmPasswordIcon.src = '../../shared/assets/images/icons/password-unhide.svg';
                confirmPasswordIcon.alt = 'Show password';
            } else {
                confirmPasswordIcon.src = '../../shared/assets/images/icons/password-hide.svg';
                confirmPasswordIcon.alt = 'Hide password';
            }
        });
    }

    // ===== OPTION CARD SELECTION =====
    optionCards.forEach(function(card) {
        const checkbox = card.querySelector('input[type="checkbox"]');
        
        card.addEventListener('click', function(e) {
            // Prevent if clicking directly on checkbox (handled by checkbox change)
            if (e.target.tagName === 'INPUT') return;
            
            if (checkbox) {
                checkbox.checked = !checkbox.checked;
                checkbox.dispatchEvent(new Event('change'));
            }
        });
        
        if (checkbox) {
            checkbox.addEventListener('change', function() {
                if (this.checked) {
                    card.classList.add('selected');
                } else {
                    card.classList.remove('selected');
                }
                updateNextButton();
            });
        }
    });

    // ===== UPDATE NEXT BUTTON =====
    function updateNextButton() {
        // Dietary step
        if (dietNext) {
            const checked = document.querySelectorAll('input[name="dietary_preferences[]"]:checked');
            dietNext.disabled = checked.length === 0;
        }
        
        // Allergy step
        if (allergyNext) {
            const checked = document.querySelectorAll('input[name="allergies[]"]:checked');
            allergyNext.disabled = checked.length === 0;
        }
    }

    // ===== SKIP BUTTONS =====
    if (btnSkipDiet) {
        btnSkipDiet.addEventListener('click', function() {
            // Uncheck all dietary preferences
            dietaryCheckboxes.forEach(function(cb) {
                cb.checked = false;
                cb.closest('.option-card').classList.remove('selected');
            });
            dietNext.disabled = false;
            goToStep(3);
        });
    }

    if (btnSkipAllergies) {
        btnSkipAllergies.addEventListener('click', function() {
            // Uncheck all allergies
            allergyCheckboxes.forEach(function(cb) {
                cb.checked = false;
                cb.closest('.option-card').classList.remove('selected');
            });
            allergyNext.disabled = false;
            goToStep(4);
        });
    }

    // ===== NAVIGATION =====
    function goToStep(step) {
        if (step < 1 || step > totalSteps) return;
        
        // Validate current step before moving forward
        if (step > currentStep) {
            if (!validateStep(currentStep)) {
                return;
            }
        }
        
        currentStep = step;
        currentStepInput.value = step;
        
        // Update steps visibility
        steps.forEach(function(s, index) {
            if (index + 1 === step) {
                s.style.display = 'block';
            } else {
                s.style.display = 'none';
            }
        });
        
        // Update progress
        progressSteps.forEach(function(ps, index) {
            const stepNum = index + 1;
            ps.classList.remove('active', 'completed');
            
            if (stepNum === step) {
                ps.classList.add('active');
            } else if (stepNum < step) {
                ps.classList.add('completed');
            }
        });
        
        // Update subtitle
        if (stepSubtitle) {
            stepSubtitle.textContent = stepTitles[step] || '';
        }
        
        // Update button states
        if (dietNext) {
            const checked = document.querySelectorAll('input[name="dietary_preferences[]"]:checked');
            dietNext.disabled = checked.length === 0;
        }
        
        if (allergyNext) {
            const checked = document.querySelectorAll('input[name="allergies[]"]:checked');
            allergyNext.disabled = checked.length === 0;
        }
        
        // Scroll to top of form
        document.querySelector('.register-container').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // ===== VALIDATION =====
    function validateStep(step) {
        clearErrors();
        let isValid = true;
        
        if (step === 1) {
            // Validate personal information
            const firstName = document.getElementById('first_name');
            const lastName = document.getElementById('last_name');
            const birthdate = document.getElementById('birthdate');
            const gender = document.getElementById('gender');
            const email = document.getElementById('email');
            const contact = document.getElementById('contact_number');
            const username = document.getElementById('username');
            const password = document.getElementById('password');
            const confirmPassword = document.getElementById('confirm_password');
            
            // First Name
            if (!firstName.value.trim()) {
                showFieldError('firstNameError', 'First name is required');
                firstName.classList.add('error');
                isValid = false;
            }
            
            // Last Name
            if (!lastName.value.trim()) {
                showFieldError('lastNameError', 'Last name is required');
                lastName.classList.add('error');
                isValid = false;
            }
            
            // Birthdate - MM/DD/YYYY format validation
            if (!birthdate.value) {
                showFieldError('birthdateError', 'Birthdate is required');
                birthdate.classList.add('error');
                isValid = false;
            } else {
                // Validate MM/DD/YYYY format
                const dateParts = birthdate.value.split('-');
                if (dateParts.length === 3) {
                    // HTML5 date input returns YYYY-MM-DD
                    const year = parseInt(dateParts[0]);
                    const month = parseInt(dateParts[1]);
                    const day = parseInt(dateParts[2]);
                    
                    // Check if valid date
                    if (isNaN(year) || isNaN(month) || isNaN(day)) {
                        showFieldError('birthdateError', 'Please enter a valid date');
                        birthdate.classList.add('error');
                        isValid = false;
                    } else {
                        const age = calculateAge(birthdate.value);
                        if (age < 13) {
                            showFieldError('birthdateError', 'You must be at least 13 years old');
                            birthdate.classList.add('error');
                            isValid = false;
                        }
                    }
                }
            }
            
            // Gender
            if (!gender.value) {
                showFieldError('genderError', 'Please select your gender');
                gender.classList.add('error');
                isValid = false;
            }
            
            // Email
            if (!email.value.trim()) {
                showFieldError('emailError', 'Email is required');
                email.classList.add('error');
                isValid = false;
            } else if (!isValidEmail(email.value)) {
                showFieldError('emailError', 'Please enter a valid email address');
                email.classList.add('error');
                isValid = false;
            }
            
            // Contact
            if (!contact.value.trim()) {
                showFieldError('contactError', 'Contact number is required');
                contact.classList.add('error');
                isValid = false;
            } else if (!isValidPhone(contact.value)) {
                showFieldError('contactError', 'Please enter a valid Philippine mobile number');
                contact.classList.add('error');
                isValid = false;
            }
            
            // Username
            if (!username.value.trim()) {
                showFieldError('usernameError', 'Username is required');
                username.classList.add('error');
                isValid = false;
            } else if (username.value.length < 3) {
                showFieldError('usernameError', 'Username must be at least 3 characters');
                username.classList.add('error');
                isValid = false;
            } else if (username.value.length > 20) {
                showFieldError('usernameError', 'Username cannot exceed 20 characters');
                username.classList.add('error');
                isValid = false;
            } else if (!/^[A-Za-z0-9_]+$/.test(username.value)) {
                showFieldError('usernameError', 'Username can only contain letters, numbers, and underscores');
                username.classList.add('error');
                isValid = false;
            }
            
            // Password - Simplified: only require letters and numbers, 6-20 characters
            if (!password.value) {
                showFieldError('passwordError', 'Password is required');
                password.classList.add('error');
                isValid = false;
            } else if (password.value.length < 6) {
                showFieldError('passwordError', 'Password must be at least 6 characters');
                password.classList.add('error');
                isValid = false;
            } else if (password.value.length > 20) {
                showFieldError('passwordError', 'Password cannot exceed 20 characters');
                password.classList.add('error');
                isValid = false;
            } else if (!/^[A-Za-z0-9]+$/.test(password.value)) {
                showFieldError('passwordError', 'Password can only contain letters and numbers');
                password.classList.add('error');
                isValid = false;
            }
            
            // Confirm Password
            if (!confirmPassword.value) {
                showFieldError('confirmError', 'Please confirm your password');
                confirmPassword.classList.add('error');
                isValid = false;
            } else if (password.value !== confirmPassword.value) {
                showFieldError('confirmError', 'Passwords do not match');
                confirmPassword.classList.add('error');
                isValid = false;
            }
        }
        
        if (!isValid) {
            showError('Please fix the errors above before continuing.');
        }
        
        return isValid;
    }

    // ===== HELPER FUNCTIONS =====
    function calculateAge(birthdate) {
        const today = new Date();
        const birthDate = new Date(birthdate);
        let age = today.getFullYear() - birthDate.getFullYear();
        const monthDiff = today.getMonth() - birthDate.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
            age--;
        }
        return age;
    }

    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    function isValidPhone(phone) {
        const cleaned = phone.replace(/\D/g, '');
        return cleaned.length === 11 && cleaned.startsWith('09');
    }

    function showFieldError(id, message) {
        const el = document.getElementById(id);
        if (el) {
            el.textContent = message;
            el.style.display = 'block';
        }
    }

    function clearErrors() {
        document.querySelectorAll('.form-error').forEach(function(el) {
            el.textContent = '';
            el.style.display = 'none';
        });
        document.querySelectorAll('.form-control.error').forEach(function(el) {
            el.classList.remove('error');
        });
        registerError.style.display = 'none';
        errorMessage.textContent = '';
    }

    function showError(message) {
        errorMessage.textContent = message;
        registerError.style.display = 'block';
    }

    // ===== NOTIFIER =====
    function showNotifier(message) {
        if (isModalOpen) return;
        modalMessage.textContent = message;
        modal.classList.remove('hidden');
        isModalOpen = true;
    }

    function closeNotifier() {
        modal.classList.add('hidden');
        isModalOpen = false;
    }

    if (modalClose) {
        modalClose.addEventListener('click', closeNotifier);
    }
    
    modal.addEventListener('click', function(e) {
        if (e.target === modal) closeNotifier();
    });

    // ===== EVENT LISTENERS =====
    // Next buttons
    btnNext.forEach(function(btn) {
        btn.addEventListener('click', function() {
            const nextStep = parseInt(this.dataset.next);
            if (!isNaN(nextStep)) {
                goToStep(nextStep);
            }
        });
    });

    // Previous buttons
    btnPrev.forEach(function(btn) {
        btn.addEventListener('click', function() {
            const prevStep = parseInt(this.dataset.prev);
            if (!isNaN(prevStep)) {
                goToStep(prevStep);
            }
        });
    });

    // ===== FORM SUBMISSION =====
    form.addEventListener('submit', function(e) {
        e.preventDefault();

        if (isSubmitting) return;

        // Validate step 4 (fitness goals - optional)
        clearErrors();

        isSubmitting = true;
        btnRegister.textContent = 'Creating Account...';
        btnRegister.disabled = true;

        // Get form data
        const formData = new FormData(form);

        // Submit via AJAX
        fetch(form.action, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(function(response) {
            if (response.redirected) {
                window.location.href = response.url;
                return;
            }
            return response.text();
        })
        .then(function(data) {
            if (typeof data === 'string' && data.length > 0) {
                try {
                    const result = JSON.parse(data);
                    if (result.status === 'success') {
                        showNotifier('Account created successfully! Redirecting...');
                        setTimeout(function() {
                            window.location.href = result.redirect || '../pages/login.php';
                        }, 2000);
                    } else {
                        showNotifier(result.message || 'Registration failed. Please try again.');
                        isSubmitting = false;
                        btnRegister.textContent = 'Create Account';
                        btnRegister.disabled = false;
                    }
                } catch (e) {
                    showNotifier('An unexpected error occurred. Please try again.');
                    isSubmitting = false;
                    btnRegister.textContent = 'Create Account';
                    btnRegister.disabled = false;
                }
            }
        })
        .catch(function(error) {
            console.error('Registration error:', error);
            showNotifier('Network error. Please check your connection and try again.');
            isSubmitting = false;
            btnRegister.textContent = 'Create Account';
            btnRegister.disabled = false;
        });
    });

    // ===== REAL-TIME VALIDATION =====
    // Password match check
    confirmPasswordInput.addEventListener('input', function() {
        const password = passwordInput.value;
        if (this.value && password !== this.value) {
            showFieldError('confirmError', 'Passwords do not match');
            this.classList.add('error');
        } else {
            const el = document.getElementById('confirmError');
            if (el) {
                el.textContent = '';
                el.style.display = 'none';
            }
            this.classList.remove('error');
        }
    });

    passwordInput.addEventListener('input', function() {
        // Real-time password validation
        const el = document.getElementById('passwordError');
        if (this.value && this.value.length > 0) {
            if (this.value.length < 6) {
                showFieldError('passwordError', 'Password must be at least 6 characters');
                this.classList.add('error');
            } else if (this.value.length > 20) {
                showFieldError('passwordError', 'Password cannot exceed 20 characters');
                this.classList.add('error');
            } else if (!/^[A-Za-z0-9]+$/.test(this.value)) {
                showFieldError('passwordError', 'Password can only contain letters and numbers');
                this.classList.add('error');
            } else {
                if (el) {
                    el.textContent = '';
                    el.style.display = 'none';
                }
                this.classList.remove('error');
            }
        }
        
        // Check confirm password match
        if (confirmPasswordInput.value && this.value !== confirmPasswordInput.value) {
            showFieldError('confirmError', 'Passwords do not match');
            confirmPasswordInput.classList.add('error');
        } else if (confirmPasswordInput.value) {
            const el = document.getElementById('confirmError');
            if (el) {
                el.textContent = '';
                el.style.display = 'none';
            }
            confirmPasswordInput.classList.remove('error');
        }
    });

    // ===== INIT =====
    // Show first step
    goToStep(1);
});