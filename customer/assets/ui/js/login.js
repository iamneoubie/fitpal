/**
 * FitPal Customer Login JavaScript
 * 
 * Handles login form validation and submission with modal feedback.
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
        const form = document.getElementById('loginForm');
        const loginBtn = document.getElementById('loginBtn');
        const identifier = document.getElementById('identifier');
        const password = document.getElementById('password');
        const identifierError = document.getElementById('identifierError');
        const passwordError = document.getElementById('passwordError');
        const loginError = document.getElementById('loginError');
        const errorMessage = document.getElementById('errorMessage');
        const notifierModal = document.getElementById('notifierModal');
        const notifierMessage = document.getElementById('notifierMessage');
        const notifierCloseBtn = document.getElementById('notifierCloseBtn');

        // Password toggle
        const togglePassword = document.getElementById('togglePassword');
        const passwordIcon = document.getElementById('passwordIcon');

        // State
        let isSubmitting = false;
        let isModalOpen = false;

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

        // ============================================
        // NOTIFIER FUNCTIONS
        // ============================================
        function showNotifier(message) {
            if (isModalOpen) return;
            notifierMessage.textContent = message;
            notifierModal.classList.remove('hidden');
            isModalOpen = true;
        }

        function closeNotifier() {
            notifierModal.classList.add('hidden');
            isModalOpen = false;
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
            if (loginError) {
                loginError.style.display = 'none';
            }
        }

        // ============================================
        // VALIDATION
        // ============================================
        function validateForm() {
            let isValid = true;
            clearAllErrors();

            if (!identifier.value.trim()) {
                showFieldError(identifier, identifierError, 'Email or username is required');
                isValid = false;
            }

            if (!password.value) {
                showFieldError(password, passwordError, 'Password is required');
                isValid = false;
            }

            return isValid;
        }

        // ============================================
        // REAL-TIME VALIDATION
        // ============================================
        identifier.addEventListener('blur', function() {
            if (!this.value.trim()) {
                showFieldError(this, identifierError, 'Email or username is required');
            } else {
                clearFieldError(this, identifierError);
            }
        });
        identifier.addEventListener('input', function() {
            clearFieldError(this, identifierError);
        });

        password.addEventListener('blur', function() {
            if (!this.value) {
                showFieldError(this, passwordError, 'Password is required');
            } else {
                clearFieldError(this, passwordError);
            }
        });
        password.addEventListener('input', function() {
            clearFieldError(this, passwordError);
        });

        // ============================================
        // FORM SUBMISSION
        // ============================================
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            if (isSubmitting) return;

            if (!validateForm()) {
                showNotifier('Please fix the errors above');
                return;
            }

            // Show loading state
            isSubmitting = true;
            const originalText = loginBtn.textContent;
            loginBtn.textContent = 'Signing In...';
            loginBtn.disabled = true;

            const formData = new FormData(form);

            fetch('../backend/handlers/login_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(text => {
                // If response is HTML, it's a redirect (success)
                if (text.includes('<!DOCTYPE html>') || text.includes('<html')) {
                    // Success - page will redirect
                    showNotifier('Login successful! Redirecting...');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    // Try to parse as JSON
                    try {
                        const data = JSON.parse(text);
                        if (data.status === 'success') {
                            showNotifier('Login successful! Redirecting...');
                            setTimeout(() => {
                                window.location.href = data.redirect || 'dashboard.php';
                            }, 1000);
                        } else {
                            showNotifier(data.message || 'Login failed. Please try again.');
                        }
                    } catch (e) {
                        // Not JSON - might be a redirect
                        showNotifier('Login successful! Redirecting...');
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000);
                    }
                }
            })
            .catch(error => {
                console.error('Login error:', error);
                showNotifier('Network error. Please check your connection and try again.');
            })
            .finally(() => {
                isSubmitting = false;
                loginBtn.textContent = originalText;
                loginBtn.disabled = false;
            });
        });

        // ============================================
        // INITIALIZATION
        // ============================================
        // Show any existing error from PHP
        if (loginError && loginError.style.display !== 'none') {
            // Error is already displayed
        }

        // Focus on identifier field
        setTimeout(function() {
            if (identifier) identifier.focus();
        }, 100);

        console.log('Customer login initialized successfully');
    });
})();