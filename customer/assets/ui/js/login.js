/**
 * FitPal Customer Login JavaScript
 * 
 * Handles form validation, password toggle, and AJAX submission
 * Uses the same pattern as crooks-cart-collectives sign-in.js
 * 
 * @package FitPal
 * @version 1.0
 */

document.addEventListener('DOMContentLoaded', function() {
    'use strict';

    // ===== DOM ELEMENTS =====
    const form = document.getElementById('loginForm');
    const modal = document.getElementById('notifierModal');
    const modalMessage = document.getElementById('notifierMessage');
    const modalClose = document.getElementById('notifierCloseBtn');
    
    const identifierInput = document.getElementById('identifier');
    const passwordInput = document.getElementById('password');
    const identifierError = document.getElementById('identifierError');
    const passwordError = document.getElementById('passwordError');
    const loginError = document.getElementById('loginError');
    
    const togglePassword = document.getElementById('togglePassword');
    const passwordIcon = document.getElementById('passwordIcon');

    // ===== STATE =====
    let isModalOpen = false;
    let isSubmitting = false;

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

    // ===== NOTIFIER FUNCTIONS =====
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

    function clearErrors() {
        identifierError.textContent = '';
        passwordError.textContent = '';
        identifierError.style.display = 'none';
        passwordError.style.display = 'none';
        identifierInput.classList.remove('error');
        passwordInput.classList.remove('error');
        if (loginError) {
            loginError.style.display = 'none';
        }
    }

    function showFieldError(field, message) {
        let errorElement, inputElement;
        
        if (field === 'identifier') {
            errorElement = identifierError;
            inputElement = identifierInput;
        } else if (field === 'password') {
            errorElement = passwordError;
            inputElement = passwordInput;
        } else {
            return;
        }
        
        errorElement.textContent = message;
        errorElement.style.display = 'block';
        inputElement.classList.add('error');
    }

    // ===== MODAL EVENT LISTENERS =====
    if (modalClose) {
        modalClose.addEventListener('click', closeNotifier);
    }
    
    modal.addEventListener('click', function(e) {
        if (e.target === modal) closeNotifier();
    });

    // ===== BLUR VALIDATION =====
    identifierInput.addEventListener('blur', function() {
        if (!this.value.trim()) {
            showFieldError('identifier', 'Email or username is required');
        } else {
            identifierError.textContent = '';
            identifierError.style.display = 'none';
            identifierInput.classList.remove('error');
        }
    });

    passwordInput.addEventListener('blur', function() {
        if (!this.value) {
            showFieldError('password', 'Password is required');
        } else {
            passwordError.textContent = '';
            passwordError.style.display = 'none';
            passwordInput.classList.remove('error');
        }
    });

    // ===== FORM SUBMISSION =====
    form.addEventListener('submit', function(e) {
        e.preventDefault();

        if (isSubmitting) return;

        clearErrors();

        // ===== CLIENT-SIDE VALIDATION =====
        let isValid = true;
        
        if (!identifierInput.value.trim()) {
            showFieldError('identifier', 'Email or username is required');
            isValid = false;
        }

        if (!passwordInput.value) {
            showFieldError('password', 'Password is required');
            isValid = false;
        }

        if (!isValid) {
            showNotifier('Please fix the errors above');
            return;
        }

        // ===== SUBMIT =====
        isSubmitting = true;
        const submitBtn = form.querySelector('#loginBtn');
        const originalText = submitBtn.textContent;
        submitBtn.textContent = 'Signing In...';
        submitBtn.disabled = true;

        // Get form data
        const formData = new FormData(form);

        // Add redirect from URL if present
        const urlParams = new URLSearchParams(window.location.search);
        const redirectParam = urlParams.get('redirect');
        if (redirectParam) {
            formData.append('redirect', redirectParam);
        }

        // Submit via AJAX (same pattern as crooks-cart-collectives)
        fetch(form.action, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(function(response) {
            // If the server redirects, we need to handle it
            // Since PHP redirects, we check if the response is a redirect
            if (response.redirected) {
                // Server sent a redirect - follow it
                window.location.href = response.url;
                return;
            }
            
            // If not redirected, try to parse as JSON
            return response.text();
        })
        .then(function(data) {
            // If we got here and data is a string, try to parse it
            if (typeof data === 'string' && data.length > 0) {
                try {
                    const result = JSON.parse(data);
                    if (result.status === 'success') {
                        showNotifier('Login successful! Redirecting...');
                        setTimeout(function() {
                            window.location.href = result.redirect || '../pages/dashboard.php';
                        }, 1500);
                    } else {
                        showNotifier(result.message || 'Login failed. Please try again.');
                    }
                } catch (e) {
                    // Not JSON - check if there's an error message in the session
                    showNotifier('Please check your credentials and try again.');
                }
            }
        })
        .catch(function(error) {
            console.error('Login error:', error);
            showNotifier('Network error. Please check your connection and try again.');
        })
        .finally(function() {
            isSubmitting = false;
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
        });
    });

    // ===== FOCUS ON IDENTIFIER =====
    setTimeout(function() {
        identifierInput.focus();
    }, 100);
});