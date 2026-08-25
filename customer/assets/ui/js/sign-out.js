/**
 * FitPal Customer Sign-Out JavaScript
 * 
 * Handles customer sign-out with CSRF protection and confirmation.
 * Uses const/let, no var, defer loading.
 * 
 * @package FitPal
 * @version 1.0
 */

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {

        // ============================================
        // DOM REFERENCES
        // ============================================
        const signOutLinks = document.querySelectorAll('[data-signout]');
        const signOutForms = document.querySelectorAll('[data-signout-form]');

        // ============================================
        // SIGN OUT HANDLER
        // ============================================

        /**
         * Handle sign-out click
         * 
         * @param {Event} e - Click event
         * @param {HTMLElement} element - The clicked element
         */
        function handleSignOut(e, element) {
            e.preventDefault();

            // Show confirmation
            const confirmMessage = element.getAttribute('data-confirm') || 'Are you sure you want to sign out?';
            
            if (!confirm(confirmMessage)) {
                return;
            }

            // Get CSRF token from meta tag or hidden input
            let csrfToken = '';
            
            // Try to get from meta tag
            const metaTag = document.querySelector('meta[name="csrf-token"]');
            if (metaTag) {
                csrfToken = metaTag.getAttribute('content');
            }
            
            // Try to get from hidden input
            if (!csrfToken) {
                const hiddenInput = document.querySelector('input[name="csrf_token"]');
                if (hiddenInput) {
                    csrfToken = hiddenInput.value;
                }
            }

            // Determine if we should use GET or POST
            const method = element.getAttribute('data-method') || 'POST';
            const href = element.getAttribute('href') || '../backend/handlers/sign-out-handler.php';
            const redirect = element.getAttribute('data-redirect') || '';

            // Disable the button/link to prevent double clicks
            if (element.tagName === 'A') {
                element.style.pointerEvents = 'none';
                element.style.opacity = '0.6';
            } else if (element.tagName === 'BUTTON') {
                element.disabled = true;
                element.textContent = 'Signing out...';
            }

            if (method.toUpperCase() === 'POST') {
                // POST request with CSRF token
                const formData = new FormData();
                if (csrfToken) {
                    formData.append('csrf_token', csrfToken);
                }
                if (redirect) {
                    formData.append('redirect', redirect);
                }

                fetch(href, {
                    method: 'POST',
                    body: formData
                })
                .then(function(response) {
                    // Check if response is a redirect
                    if (response.redirected) {
                        window.location.href = response.url;
                        return;
                    }
                    // If not redirected, follow the redirect in the response
                    return response.text();
                })
                .then(function(data) {
                    // If we got text back, try to parse it or follow redirects
                    try {
                        const json = JSON.parse(data);
                        if (json.redirect) {
                            window.location.href = json.redirect;
                        } else if (json.success) {
                            window.location.reload();
                        }
                    } catch (e) {
                        // Not JSON, just reload
                        window.location.reload();
                    }
                })
                .catch(function(error) {
                    console.error('Sign-out error:', error);
                    // Fallback: redirect to sign-in page
                    window.location.href = '../pages/sign-in.php';
                });
            } else {
                // GET request (simple redirect)
                let url = href;
                if (csrfToken) {
                    const separator = url.includes('?') ? '&' : '?';
                    url += separator + 'csrf_token=' + encodeURIComponent(csrfToken);
                }
                if (redirect) {
                    const separator = url.includes('?') ? '&' : '?';
                    url += separator + 'redirect=' + encodeURIComponent(redirect);
                }
                window.location.href = url;
            }
        }

        // ============================================
        // ATTACH EVENT LISTENERS
        // ============================================

        // Handle sign-out links (click)
        signOutLinks.forEach(function(link) {
            link.addEventListener('click', function(e) {
                handleSignOut(e, this);
            });
        });

        // Handle sign-out forms (submit)
        signOutForms.forEach(function(form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const submitBtn = this.querySelector('[type="submit"]');
                if (submitBtn) {
                    handleSignOut(e, submitBtn);
                }
            });
        });

        // ============================================
        // KEYBOARD SUPPORT - Enter key on links
        // ============================================

        signOutLinks.forEach(function(link) {
            link.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.click();
                }
            });
        });

        console.log('Sign-out handler initialized successfully');

    });
})();