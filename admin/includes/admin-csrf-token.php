<?php
/**
 * FitPal Admin CSRF Token
 *
 * Per-context CSRF token helper for the admin session.
 *
 * ---------------------------------------------------------------------
 * WHY THIS FILE EXISTS
 * ---------------------------------------------------------------------
 * Every FitPal context — customer, rider, restaurant, admin, and
 * public — runs as its own PHP session under its own cookie name
 * (see shared/includes/session-bootstrap.php). The admin session
 * (PHPSESSID_ADMIN) is the only session a request under
 * admin/pages/ or admin/backend/handlers/ can read. The token
 * stored in it is therefore never visible to any other context.
 *
 * The token key is 'admin_csrf_token'. Under the older shared-
 * session design this prefix was a collision guard. Under per-role
 * sessions the prefix is no longer required for safety — the
 * admin session cannot contain another context's key because the
 * admin session is a different session entirely — but it is kept
 * as a naming convention so a reader can tell at a glance which
 * context a token belongs to.
 *
 * ---------------------------------------------------------------------
 * THE FULL CSRF KEY MATRIX (after Option B)
 * ---------------------------------------------------------------------
 *   customer   → 'customer_csrf_token'     in PHPSESSID_CUSTOMER
 *   rider      → 'rider_csrf_token'        in PHPSESSID_RIDER
 *   restaurant → 'restaurant_csrf_token'   in PHPSESSID_RESTAURANT
 *   admin      → 'admin_csrf_token'        in PHPSESSID_ADMIN      ← this file
 *   public     → 'public_csrf_token'       in PHPSESSID_PUBLIC
 *
 * The generic 'csrf_token' key is used by nothing and must never
 * be read, written, or cleared.
 *
 * ---------------------------------------------------------------------
 * CONTRACT
 * ---------------------------------------------------------------------
 * getAdminCsrfToken(): string
 *
 *   - Returns the current admin CSRF token, generating one on
 *     first use.
 *   - Returns an empty string when the active session is NOT the
 *     admin session. This happens when an admin page forgets to
 *     call fitpal_session_bootstrap('admin') before including
 *     this file, or when a non-admin page tries to use it. An
 *     empty return is a hard failure: the caller must not render
 *     a form, and must not treat the empty string as a valid
 *     token.
 *   - Idempotent: calling it more than once in a request is a
 *     no-op after the first call.
 *   - Never touches any session other than the admin session (and
 *     cannot, because PHP gives a request access to exactly one
 *     session).
 *
 * Safe to require_once from any admin page or admin handler. The
 * bootstrap is called defensively; if it has already run for
 * 'admin', it is a no-op.
 *
 * @package FitPal
 * @version 2.0 — Per-role session migration (Option B). Requires
 *                the admin session to have been bootstrapped via
 *                fitpal_session_bootstrap('admin') before the token
 *                is requested. Removed the direct session_start()
 *                call in favor of the bootstrap. Returns an empty
 *                string when the active session is not the admin
 *                session.
 */

declare(strict_types=1);

if (!function_exists('fitpal_session_bootstrap')) {
    // The bootstrap lives under shared/includes/. An admin file
    // that includes this helper directly (rather than through the
    // admin header) may not have loaded the bootstrap yet; pull
    // it in so the guard below can run.
    require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
}

if (!function_exists('getAdminCsrfToken')) {
    /**
     * Return the admin context's CSRF token, generating it on first
     * use.
     *
     * The token is stored under 'admin_csrf_token' inside the admin
     * session. If the active session is not the admin session —
     * because the caller forgot to bootstrap, or because a non-
     * admin page called this helper — the function returns '' and
     * does NOT write a token into whichever session is currently
     * open.
     *
     * @return string 64-character hex string, or '' on misconfiguration.
     */
    function getAdminCsrfToken(): string
    {
        // Ensure the admin session is the one we are operating on.
        // If no bootstrap has run yet, run it now. If a bootstrap
        // ran for a different context, the function is a logged
        // no-op and the check just after will fail closed.
        fitpal_session_bootstrap('admin');

        if (fitpal_session_current_context() !== 'admin') {
            error_log(
                'getAdminCsrfToken: active session is not the admin session '
                . '(current: "' . fitpal_session_current_context() . '"). '
                . 'Refusing to write an admin token into a non-admin session.'
            );
            return '';
        }

        if (empty($_SESSION['admin_csrf_token'])) {
            $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string)$_SESSION['admin_csrf_token'];
    }
}