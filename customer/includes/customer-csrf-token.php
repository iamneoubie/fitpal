<?php
/**
 * FitPal Customer CSRF Token
 *
 * Per-context CSRF token helper for the customer session.
 *
 * ---------------------------------------------------------------------
 * WHY THIS FILE EXISTS
 * ---------------------------------------------------------------------
 * Every FitPal context — customer, rider, restaurant, admin, and
 * public — runs as its own PHP session under its own cookie name
 * (see shared/includes/session-bootstrap.php). The customer session
 * (PHPSESSID_CUSTOMER) is the only session a request under
 * customer/pages/ or customer/backend/handlers/ can read. The token
 * stored in it is therefore never visible to any other context.
 *
 * The token key is 'customer_csrf_token'. Under the older shared-
 * session design this prefix was a collision guard. Under per-role
 * sessions the prefix is no longer required for safety — the
 * customer session cannot contain another context's key because
 * the customer session is a different session entirely — but it is
 * kept as a naming convention so a reader can tell at a glance
 * which context a token belongs to.
 *
 * ---------------------------------------------------------------------
 * THE FULL CSRF KEY MATRIX (after Option B)
 * ---------------------------------------------------------------------
 *   customer   → 'customer_csrf_token'     in PHPSESSID_CUSTOMER   ← this file
 *   rider      → 'rider_csrf_token'        in PHPSESSID_RIDER
 *   restaurant → 'restaurant_csrf_token'   in PHPSESSID_RESTAURANT
 *   admin      → 'admin_csrf_token'        in PHPSESSID_ADMIN
 *   public     → 'public_csrf_token'       in PHPSESSID_PUBLIC
 *
 * The generic 'csrf_token' key is used by nothing and must never
 * be read, written, or cleared.
 *
 * ---------------------------------------------------------------------
 * CONTRACT
 * ---------------------------------------------------------------------
 * getCustomerCsrfToken(): string
 *
 *   - Returns the current customer CSRF token, generating one on
 *     first use.
 *   - Returns an empty string when the active session is NOT the
 *     customer session. This happens when a customer page forgets
 *     to call fitpal_session_bootstrap('customer') before including
 *     this file, or when a non-customer page tries to use it. An
 *     empty return is a hard failure: the caller must not render
 *     a form, and must not treat the empty string as a valid token.
 *   - Idempotent: calling it more than once in a request is a
 *     no-op after the first call.
 *   - Never touches any session other than the customer session
 *     (and cannot, because PHP gives a request access to exactly
 *     one session).
 *
 * Safe to require_once from any customer page or customer handler.
 * The bootstrap is called defensively; if it has already run for
 * 'customer', it is a no-op.
 *
 * @package FitPal
 * @version 2.0 — Per-role session migration (Option B). Requires
 *                the customer session to have been bootstrapped via
 *                fitpal_session_bootstrap('customer') before the
 *                token is requested. Removed the direct
 *                session_start() call in favor of the bootstrap.
 *                Returns an empty string when the active session
 *                is not the customer session.
 */

declare(strict_types=1);

if (!function_exists('fitpal_session_bootstrap')) {
    // The bootstrap lives under shared/includes/. A customer file
    // that includes this helper directly (rather than through the
    // customer header) may not have loaded the bootstrap yet; pull
    // it in so the guard below can run.
    require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
}

if (!function_exists('getCustomerCsrfToken')) {
    /**
     * Return the customer context's CSRF token, generating it on
     * first use.
     *
     * The token is stored under 'customer_csrf_token' inside the
     * customer session. If the active session is not the customer
     * session — because the caller forgot to bootstrap, or because
     * a non-customer page called this helper — the function
     * returns '' and does NOT write a token into whichever session
     * is currently open.
     *
     * @return string 64-character hex string, or '' on misconfiguration.
     */
    function getCustomerCsrfToken(): string
    {
        // Ensure the customer session is the one we are operating
        // on. If no bootstrap has run yet, run it now. If a bootstrap
        // ran for a different context, the function is a logged
        // no-op and the check just after will fail closed.
        fitpal_session_bootstrap('customer');

        if (fitpal_session_current_context() !== 'customer') {
            error_log(
                'getCustomerCsrfToken: active session is not the customer session '
                . '(current: "' . fitpal_session_current_context() . '"). '
                . 'Refusing to write a customer token into a non-customer session.'
            );
            return '';
        }

        if (empty($_SESSION['customer_csrf_token'])) {
            $_SESSION['customer_csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string)$_SESSION['customer_csrf_token'];
    }
}