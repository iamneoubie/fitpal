/**
 * FitPal Admin Sign-Out JavaScript
 *
 * Handles admin sign-out with confirmation. Posts to the sign-out
 * handler. All real work happens server-side.
 *
 * @package FitPal
 * @version 1.1 — Simpler CSRF handling; consistent redirect fallback.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        const signOutLinks = document.querySelectorAll('[data-signout]');
        const signOutForms = document.querySelectorAll('[data-signout-form]');

        /**
         * Resolve a CSRF token from anywhere on the page, if present.
         *
         * @returns {string}
         */
        function resolveCsrfToken() {
            const meta = document.querySelector('meta[name="csrf-token"]');
            if (meta && meta.getAttribute('content')) {
                return meta.getAttribute('content');
            }
            const input = document.querySelector('input[name="csrf_token"]');
            if (input && input.value) {
                return input.value;
            }
            return '';
        }

        /**
         * Handle a sign-out click or form submission.
         *
         * @param {Event}       e
         * @param {HTMLElement} element
         */
        function handleSignOut(e, element) {
            e.preventDefault();

            const confirmMessage = element.getAttribute('data-confirm')
                || 'Are you sure you want to sign out?';

            if (!window.confirm(confirmMessage)) {
                return;
            }

            // Disable the control while the request is in flight.
            if (element.tagName === 'A') {
                element.style.pointerEvents = 'none';
                element.style.opacity       = '0.6';
            } else if (element.tagName === 'BUTTON') {
                element.disabled    = true;
                element.textContent = 'Signing out...';
            }

            const href     = element.getAttribute('href') || '../backend/handlers/sign-out-handler.php';
            const method   = (element.getAttribute('data-method') || 'POST').toUpperCase();
            const redirect = element.getAttribute('data-redirect') || '';
            const csrf     = resolveCsrfToken();

            if (method === 'GET') {
                // Simple navigation. Append token + redirect if we have them.
                let url = href;
                const params = new URLSearchParams();
                if (csrf)     params.append('csrf_token', csrf);
                if (redirect) params.append('redirect',   redirect);
                const qs = params.toString();
                if (qs) {
                    url += (url.includes('?') ? '&' : '?') + qs;
                }
                window.location.href = url;
                return;
            }

            // POST — send the token and redirect in the body.
            const formData = new FormData();
            if (csrf)     formData.append('csrf_token', csrf);
            if (redirect) formData.append('redirect',   redirect);

            fetch(href, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(function (response) {
                // The handler always redirects on success. fetch follows
                // it, so response.redirected is true and response.url
                // points at the sign-in page.
                if (response.redirected) {
                    window.location.href = response.url;
                    return null;
                }
                return response.text();
            })
            .then(function (data) {
                if (data === null) return;
                // No redirect happened. Try to interpret the body, then
                // fall back to reloading.
                try {
                    const json = JSON.parse(data);
                    if (json && json.redirect) {
                        window.location.href = json.redirect;
                        return;
                    }
                } catch (_) {
                    // Not JSON.
                }
                window.location.reload();
            })
            .catch(function (error) {
                console.error('Admin sign-out error:', error);
                // Last-resort fallback: navigate to the sign-in page.
                window.location.href = '../pages/sign-in.php';
            });
        }

        signOutLinks.forEach(function (link) {
            link.addEventListener('click', function (e) {
                handleSignOut(e, this);
            });
            link.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.click();
                }
            });
        });

        signOutForms.forEach(function (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                const submitBtn = this.querySelector('[type="submit"]');
                if (submitBtn) {
                    handleSignOut(e, submitBtn);
                }
            });
        });

        console.log('Admin sign-out handler initialized');
    });
})();