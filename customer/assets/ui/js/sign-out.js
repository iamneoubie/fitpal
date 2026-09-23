/**
 * FitPal Customer Sign-Out JavaScript
 *
 * Handles customer sign-out with CSRF protection and confirmation.
 *
 * @package FitPal
 * @version 1.3 — resolveCsrfToken() now consults a documented priority
 *                chain and prefers window.FITPAL_CSRF_TOKEN first. The
 *                customer role validates against its own session key,
 *                customer_csrf_token, on the server side; this file
 *                only needs to forward whatever token the page
 *                rendered. It never reads or writes the shared
 *                csrf_token key — see general.md.
 */

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {

        // ============================================
        // DOM REFERENCES
        // ============================================
        const signOutLinks   = document.querySelectorAll('[data-signout]');
        const signOutForms   = document.querySelectorAll('[data-signout-form]');
        const logoutTriggers = document.querySelectorAll('[data-logout-trigger]');

        // ============================================
        // TOKEN RESOLUTION
        // ============================================

        /**
         * Resolve the customer role's CSRF token.
         *
         * Priority:
         *   1. window.FITPAL_CSRF_TOKEN — bootstrapped by pages that
         *      set it. Always the customer role's own token.
         *   2. data-csrf-token on any [data-logout-trigger] element —
         *      header.php attaches the customer token to every logout
         *      button, so this is present on every authenticated page.
         *   3. input[name="csrf_token"] — the customer token is the
         *      only CSRF value rendered by customer pages.
         *
         * Never reads a meta tag named "csrf-token" — that was the
         * shared-key convention and is no longer used by this role.
         *
         * @returns {string}
         */
        function resolveCsrfToken() {
            // 1. Global token set by the page (most reliable).
            if (typeof window.FITPAL_CSRF_TOKEN === 'string' && window.FITPAL_CSRF_TOKEN !== '') {
                return window.FITPAL_CSRF_TOKEN;
            }

            // 2. Per-element token on logout triggers.
            for (const trigger of logoutTriggers) {
                const token = trigger.getAttribute('data-csrf-token');
                if (token) return token;
            }

            // 3. First customer CSRF hidden input on the page.
            const hiddenInput = document.querySelector('input[name="csrf_token"]');
            if (hiddenInput && hiddenInput.value) {
                return hiddenInput.value;
            }

            return '';
        }

        // ============================================
        // SIGN OUT HANDLER
        // ============================================

        /**
         * Handle sign-out click.
         *
         * @param {Event} e - Click event
         * @param {HTMLElement} element - The clicked element
         */
        function handleSignOut(e, element) {
            e.preventDefault();

            const confirmMessage = element.getAttribute('data-confirm')
                || 'Are you sure you want to sign out?';

            if (!confirm(confirmMessage)) {
                return;
            }

            const csrfToken = resolveCsrfToken();

            const method   = element.getAttribute('data-method') || 'POST';
            const href     = element.getAttribute('href') || '../backend/handlers/sign-out-handler.php';
            const redirect = element.getAttribute('data-redirect') || '';

            // Disable the trigger to prevent double clicks
            if (element.tagName === 'A') {
                element.style.pointerEvents = 'none';
                element.style.opacity = '0.6';
            } else if (element.tagName === 'BUTTON') {
                element.disabled = true;
                element.textContent = 'Signing out...';
            }

            if (method.toUpperCase() === 'POST') {
                const formData = new FormData();
                if (csrfToken) {
                    formData.append('csrf_token', csrfToken);
                }
                if (redirect) {
                    formData.append('redirect', redirect);
                }

                fetch(href, {
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
                    try {
                        const json = JSON.parse(data);
                        if (json.redirect) {
                            window.location.href = json.redirect;
                        } else if (json.success) {
                            window.location.reload();
                        }
                    } catch (e) {
                        window.location.reload();
                    }
                })
                .catch(function(error) {
                    console.error('Sign-out error:', error);
                    window.location.href = '../pages/sign-in.php';
                });
            } else {
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

        signOutLinks.forEach(function(link) {
            link.addEventListener('click', function(e) {
                handleSignOut(e, this);
            });
        });

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

        console.log('Sign-out handler v1.3 initialized');
    });
})();