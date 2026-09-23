<?php
/**
 * FitPal Rider CSRF Token
 *
 * Per-role CSRF token bootstrap for the rider session.
 *
 * Why this file exists:
 *   The rider, customer, admin, and restaurant roles all run on the
 *   same PHP session (same cookie). If every role stored its token
 *   under the shared 'csrf_token' key, whichever role's handler ran
 *   unset($_SESSION['csrf_token']) on a successful sign-in would
 *   delete the token another role's already-rendered form was still
 *   relying on. The rider role therefore keeps its own key,
 *   'rider_csrf_token', and never touches the shared one.
 *
 * Why it lives in includes/ and not backend/handlers/ or
 * backend/database/:
 *   - It is not a request dispatch (no $_POST handling, no header(),
 *     no echo, no exit), so it is not a handler.
 *   - It is not SQL, so it is not a query file.
 *   - It is session bootstrap that pages require_once before output.
 *     That is the same role header.php plays for the rider role.
 *
 * Relationship to existing code:
 *   rider/pages/sign-in.php and rider/pages/sign-up.php previously
 *   generated the rider token inline. rider/pages/dashboard.php,
 *   rider/pages/deliveries.php, rider/pages/earnings.php, and
 *   rider/pages/profile.php did the same under the shared
 *   'csrf_token' key, which was a collision hazard. Those blocks are
 *   being replaced with a require_once on this file plus a call to
 *   getRiderCsrfToken(). The four authenticated handlers
 *   (rider-handler.php, message-handler.php, sign-out-handler.php,
 *   sign-in-handler.php) are being migrated to validate against
 *   'rider_csrf_token' at the same time.
 *
 * Safe to require_once from any rider page, including sign-in.php
 * and sign-up.php. Calling getRiderCsrfToken() more than once in a
 * request is a no-op after the first call.
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('getRiderCsrfToken')) {
    /**
     * Return the rider role's CSRF token, generating it on first use.
     *
     * The token is stored under 'rider_csrf_token' — never under the
     * shared 'csrf_token' key. See the file header for the reason.
     *
     * @return string 64-character hex string.
     */
    function getRiderCsrfToken(): string
    {
        if (empty($_SESSION['rider_csrf_token'])) {
            $_SESSION['rider_csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['rider_csrf_token'];
    }
}