<?php
/**
 * FitPal Restaurant CSRF Token
 *
 * Per-context CSRF token helper for the restaurant session.
 *
 * ---------------------------------------------------------------------
 * WHY THIS FILE EXISTS
 * ---------------------------------------------------------------------
 * Every FitPal context — customer, rider, restaurant, admin, and
 * public — runs as its own PHP session under its own cookie name
 * (see shared/includes/session-bootstrap.php). The restaurant
 * session (PHPSESSID_RESTAURANT) is the only session a request
 * under restaurant/pages/ or restaurant/backend/handlers/ can
 * read. The token stored in it is therefore never visible to any
 * other context.
 *
 * The token key is 'restaurant_csrf_token'. Under the older shared-
 * session design this prefix was a collision guard. Under per-role
 * sessions the prefix is no longer required for safety — the
 * restaurant session cannot contain another context's key because
 * the restaurant session is a different session entirely — but it
 * is kept as a naming convention so a reader can tell at a glance
 * which context a token belongs to.
 *
 * ---------------------------------------------------------------------
 * THE FULL CSRF KEY MATRIX (after Option B)
 * ---------------------------------------------------------------------
 *   customer   → 'customer_csrf_token'     in PHPSESSID_CUSTOMER
 *   rider      → 'rider_csrf_token'        in PHPSESSID_RIDER
 *   restaurant → 'restaurant_csrf_token'   in PHPSESSID_RESTAURANT   ← this file
 *   admin      → 'admin_csrf_token'        in PHPSESSID_ADMIN
 *   public     → 'public_csrf_token'       in PHPSESSID_PUBLIC
 *
 * The generic 'csrf_token' key is used by nothing and must never
 * be read, written, or cleared.
 *
 * ---------------------------------------------------------------------
 * CONTRACT
 * ---------------------------------------------------------------------
 * getRestaurantCsrfToken(): string
 *
 *   - Returns the current restaurant CSRF token, generating one on
 *     first use.
 *   - Returns an empty string when the active session is NOT the
 *     restaurant session. This happens when a restaurant page
 *     forgets to call fitpal_session_bootstrap('restaurant')
 *     before including this file, or when a non-restaurant page
 *     tries to use it. An empty return is a hard failure: the
 *     caller must not render a form, and must not treat the empty
 *     string as a valid token.
 *   - Idempotent: calling it more than once in a request is a
 *     no-op after the first call.
 *   - Never touches any session other than the restaurant session
 *     (and cannot, because PHP gives a request access to exactly
 *     one session).
 *
 * Safe to require_once from any restaurant page or restaurant
 * handler. The bootstrap is called defensively; if it has already
 * run for 'restaurant', it is a no-op.
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

if (!function_exists('fitpal_session_bootstrap')) {
    // The bootstrap lives under shared/includes/. A restaurant
    // file that includes this helper directly (rather than through
    // the restaurant header) may not have loaded the bootstrap
    // yet; pull it in so the guard below can run.
    require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
}

if (!function_exists('getRestaurantCsrfToken')) {
    /**
     * Return the restaurant context's CSRF token, generating it on
     * first use.
     *
     * The token is stored under 'restaurant_csrf_token' inside the
     * restaurant session. If the active session is not the
     * restaurant session — because the caller forgot to bootstrap,
     * or because a non-restaurant page called this helper — the
     * function returns '' and does NOT write a token into whichever
     * session is currently open.
     *
     * @return string 64-character hex string, or '' on misconfiguration.
     */
    function getRestaurantCsrfToken(): string
    {
        // Ensure the restaurant session is the one we are operating
        // on. If no bootstrap has run yet, run it now. If a
        // bootstrap ran for a different context, the function is a
        // logged no-op and the check just after will fail closed.
        fitpal_session_bootstrap('restaurant');

        if (fitpal_session_current_context() !== 'restaurant') {
            error_log(
                'getRestaurantCsrfToken: active session is not the restaurant session '
                . '(current: "' . fitpal_session_current_context() . '"). '
                . 'Refusing to write a restaurant token into a non-restaurant session.'
            );
            return '';
        }

        if (empty($_SESSION['restaurant_csrf_token'])) {
            $_SESSION['restaurant_csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string)$_SESSION['restaurant_csrf_token'];
    }
}