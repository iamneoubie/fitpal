<?php
/**
 * FitPal Public CSRF Token
 *
 * Per-context CSRF token helper for the public session.
 *
 * ---------------------------------------------------------------------
 * WHY THIS FILE EXISTS
 * ---------------------------------------------------------------------
 * Every FitPal context — customer, rider, restaurant, admin, and
 * public — runs as its own PHP session under its own cookie name
 * (see shared/includes/session-bootstrap.php). The public session
 * exists so anonymous pages (the landing page, about, contact,
 * privacy-policy, terms-conditions) have somewhere to store a CSRF
 * token without touching any authenticated role's session.
 *
 * The token key is 'public_csrf_token'. Under the older shared-
 * session design this prefix was a collision guard: the customer,
 * rider, restaurant, and admin roles all had their own
 * {role}_csrf_token keys and the public context needed a fifth
 * distinct one. Under per-role sessions the prefix is no longer
 * required for safety — the public session cannot contain another
 * context's key because the public session is a different session
 * entirely — but it is kept as a naming convention so a reader can
 * tell at a glance which context a token belongs to.
 *
 * ---------------------------------------------------------------------
 * THE FULL CSRF KEY MATRIX (after Option B)
 * ---------------------------------------------------------------------
 *   customer   → 'customer_csrf_token'     in PHPSESSID_CUSTOMER
 *   rider      → 'rider_csrf_token'        in PHPSESSID_RIDER
 *   restaurant → 'restaurant_csrf_token'   in PHPSESSID_RESTAURANT
 *   admin      → 'admin_csrf_token'        in PHPSESSID_ADMIN
 *   public     → 'public_csrf_token'       in PHPSESSID_PUBLIC  ← this file
 *
 * The generic 'csrf_token' key is used by nothing and must never be
 * read, written, or cleared.
 *
 * ---------------------------------------------------------------------
 * CONTRACT
 * ---------------------------------------------------------------------
 * getPublicCsrfToken(): string
 *
 *   - Returns the current public CSRF token, generating one on
 *     first use.
 *   - Returns an empty string when the active session is NOT the
 *     public session. This happens when a public page forgets to
 *     call fitpal_session_bootstrap('public') before including
 *     this file. An empty return is a hard failure: the caller
 *     must not render a form, and must not treat the empty string
 *     as a valid token.
 *   - Idempotent: calling it more than once in a request is a
 *     no-op after the first call.
 *   - Never touches any session other than the public session
 *     (and cannot, because PHP gives a request access to exactly
 *     one session).
 *
 * Safe to require_once from any public page. The bootstrap is
 * called defensively; if it has already run for 'public', it is a
 * no-op.
 *
 * @package FitPal
 * @version 2.0 — Requires the public session to have been
 *                bootstrapped via fitpal_session_bootstrap('public')
 *                before the token is requested. Removed the direct
 *                session_start() call in favor of the bootstrap, so
 *                a misconfigured public page cannot accidentally
 *                write a token into whichever session happened to
 *                be open. Returns an empty string when the active
 *                session is not the public session.
 */

declare(strict_types=1);

if (!function_exists('fitpal_session_bootstrap')) {
    // The bootstrap lives one directory up from this file's role
    // (shared/includes/session-bootstrap.php). A public page that
    // includes this file directly without going through the shared
    // header may not have loaded the bootstrap yet; pull it in so
    // the guard below can run.
    require_once __DIR__ . '/session-bootstrap.php';
}

if (!function_exists('getPublicCsrfToken')) {
    /**
     * Return the public context's CSRF token, generating it on
     * first use.
     *
     * The token is stored under 'public_csrf_token' inside the
     * public session. If the active session is not the public
     * session — because the caller forgot to bootstrap — this
     * function returns '' and does NOT write a token into whichever
     * session is currently open.
     *
     * @return string 64-character hex string, or '' on misconfiguration.
     */
    function getPublicCsrfToken(): string
    {
        // Ensure the public session is the one we are operating on.
        // If no bootstrap has run yet, run it now. If a bootstrap
        // ran for a different context, the function below is a
        // logged no-op and the check just after will fail closed.
        fitpal_session_bootstrap('public');

        if (fitpal_session_current_context() !== 'public') {
            error_log(
                'getPublicCsrfToken: active session is not the public session '
                . '(current: "' . fitpal_session_current_context() . '"). '
                . 'Refusing to write a public token into a non-public session.'
            );
            return '';
        }

        if (empty($_SESSION['public_csrf_token'])) {
            $_SESSION['public_csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string)$_SESSION['public_csrf_token'];
    }
}