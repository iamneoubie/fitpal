<?php
/**
 * FitPal Customer CSRF Token
 *
 * Per-role CSRF token bootstrap for the customer session.
 *
 * Why this file exists:
 *   The customer, admin, rider, and restaurant roles all run on the
 *   same PHP session (same cookie). If every role stored its token
 *   under the shared 'csrf_token' key, whichever role's handler ran
 *   unset($_SESSION['csrf_token']) on a successful sign-in would
 *   delete the token another role's already-rendered form was still
 *   relying on. The customer role therefore keeps its own key,
 *   'customer_csrf_token', and never touches the shared one.
 *
 * Why it lives in includes/ and not backend/handlers/ or
 * backend/database/:
 *   - It is not a request dispatch (no $_POST handling, no header(),
 *     no echo, no exit), so it is not a handler.
 *   - It is not SQL, so it is not a query file.
 *   - It is session bootstrap that pages require_once before output.
 *     That is the same role header.php plays for the customer role.
 *
 * Safe to require_once from any customer page, including sign-in.php
 * and sign-up.php. Calling getCustomerCsrfToken() more than once in a
 * request is a no-op after the first call.
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('getCustomerCsrfToken')) {
    /**
     * Return the customer role's CSRF token, generating it on first use.
     *
     * The token is stored under 'customer_csrf_token' — never under the
     * shared 'csrf_token' key. See the file header for the reason.
     *
     * @return string 64-character hex string.
     */
    function getCustomerCsrfToken(): string
    {
        if (empty($_SESSION['customer_csrf_token'])) {
            $_SESSION['customer_csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['customer_csrf_token'];
    }
}