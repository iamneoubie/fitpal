/**
 * FitPal Admin Sign-Out JavaScript
 *
 * Handles admin sign-out with CSRF protection and confirmation.
 * Mirrors the customer sign-out handler.
 *
 * @package FitPal
 * @version 1.0
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        const signOutLinks = document.querySelectorAll('[data-signout]');
        const signOutForms = document.querySelectorAll('[data-signout-form]');

        /**
         * Handle sign-out click.
         *
         * @param {Event} e
         * @param {HTMLElement} element
         */
        function handleSignOut(e, element) {
            e.preventDefault();

            const confirmMessage = element.getAttribute('data-confirm')
                || 'Are you sure you want to sign out?';

            if (!confirm(confirmMessage)) {
                return;
            }

            let csrfToken = '';

            const metaTag = document.querySelector('meta[name="csrf-token"]');
            if (metaTag) {
                csrfToken = metaTag.getAttribute('content') || '';
            }

            if (!csrfToken) {
                const hiddenInput = document.querySelector('input[name="csrf_token"]');
                if (hiddenInput) {
                    csrfToken = hiddenInput.value || '';
                }
            }

            const method   = element.getAttribute('data-method') || 'POST';
            const href     = element.getAttribute('href') || '../backend/handlers/sign-out-handler.php';
            const redirect = element.getAttribute('data-redirect') || '';

            if (element.tagName === 'A') {
                element.style.pointerEvents = 'none';
                element.style.opacity = '0.6';
            } else if (element.tagName === 'BUTTON') {
                element.disabled = true;
                element.textContent = 'Signing out...';
            }

            if (method.toUpperCase() === 'POST') {
                const formData = new FormData();
                if (csrfToken) formData.append('csrf_token', csrfToken);
                if (redirect)  formData.append('redirect', redirect);

                fetch(href, {
                    method: 'POST',
                    body: formData
                })
                .then(function (response) {
                    if (response.redirected) {
                        window.location.href = response.url;
                        return;
                    }
                    return response.text();
                })
                .then(function (data) {
                    try {
                        const json = JSON.parse(data);
                        if (json.redirect) {
                            window.location.href = json.redirect;
                        } else if (json.success) {
                            window.location.reload();
                        }
                    } catch (err) {
                        window.location.reload();
                    }
                })
                .catch(function (error) {
                    console.error('Sign-out error:', error);
                    window.location.href = '../pages/sign-in.php';
                });
            } else {
                let url = href;
                if (csrfToken) {
                    const sep = url.includes('?') ? '&' : '?';
                    url += sep + 'csrf_token=' + encodeURIComponent(csrfToken);
                }
                if (redirect) {
                    const sep = url.includes('?') ? '&' : '?';
                    url += sep + 'redirect=' + encodeURIComponent(redirect);
                }
                window.location.href = url;
            }
        }

        signOutLinks.forEach(function (link) {
            link.addEventListener('click', function (e) {
                handleSignOut(e, this);
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

        signOutLinks.forEach(function (link) {
            link.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.click();
                }
            });
        });

        console.log('Admin sign-out handler initialized');
    });
})();