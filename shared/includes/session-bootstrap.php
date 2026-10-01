<?php
/**
 * FitPal Session Bootstrap
 *
 * Single source of truth for every PHP session name and cookie
 * parameter used by the project. Every entry-point file calls
 * fitpal_session_bootstrap('<context>') BEFORE any other include
 * that might touch the session.
 *
 * ---------------------------------------------------------------------
 * WHY PER-ROLE SESSIONS
 * ---------------------------------------------------------------------
 * Every role in FitPal runs as its own PHP session under its own
 * cookie name:
 *
 *     customer     PHPSESSID_CUSTOMER
 *     rider        PHPSESSID_RIDER
 *     restaurant   PHPSESSID_RESTAURANT
 *     admin        PHPSESSID_ADMIN
 *     public       PHPSESSID_PUBLIC
 *
 * Before this file existed, all five contexts shared one PHP session
 * and one cookie. Because a single session ID can only be rotated
 * once per request, a sign-in by one role had to either leave the
 * shared session ID alone (breaking the rotation-on-login security
 * expectation) or rotate it (destroying every other role's in-flight
 * session). Neither was acceptable. Per-role sessions make the two
 * goals — isolation and rotation — compatible:
 *
 *     - Each role rotates ITS OWN session ID on its own sign-in.
 *     - Rotating one role's ID cannot affect another role, because
 *       the browser sends each role's requests under a different
 *       cookie.
 *     - A sign-out by one role destroys only that role's session.
 *
 * ---------------------------------------------------------------------
 * CONTRACT
 * ---------------------------------------------------------------------
 *   fitpal_session_bootstrap(string $context): void
 *
 *   - $context must be exactly one of:
 *       'customer', 'rider', 'restaurant', 'admin', 'public'
 *   - The function MUST be called once per request, at the top of
 *     the entry-point file, before any include that might touch the
 *     session.
 *   - Calling it twice with the SAME context is a silent no-op. This
 *     makes defensive double-bootstraps (e.g. header included twice
 *     by accident) safe.
 *   - Calling it with a DIFFERENT context after a session has
 *     already started is a hard failure: it means a single request
 *     is trying to belong to two roles at once, which is always a
 *     programming error. The function logs the mistake and does
 *     NOT switch sessions. The caller keeps whatever session it
 *     already had.
 *   - The function never writes to $_SESSION, never reads $_SESSION,
 *     never emits output, never redirects.
 *
 *   fitpal_session_detect_context(): string
 *
 *   - Returns the context name whose session cookie is present in
 *     the incoming request, or '' when none is present.
 *   - Purely reads $_COOKIE. Does not start a session, does not
 *     touch $_SESSION, does not emit output.
 *   - Reads cookies in the order the context table declares them
 *     (customer, rider, restaurant, admin, public). A browser can
 *     legally hold several of these cookies at once — one per role
 *     the user is signed in to on this device — but each request
 *     that reaches a FitPal entry point is issued from exactly one
 *     role's context and carries exactly one of these cookies in
 *     practice. When more than one is present, the first match
 *     wins. Callers that must be sure a specific role is the one
 *     being served should still guard on that role's own session
 *     key, which they already do.
 *   - Added so the shared order-transaction handler, which is
 *     reachable from a customer, restaurant, or rider context, can
 *     ask the bootstrap which context the request belongs to
 *     instead of carrying its own cookie-name loop.
 *
 * ---------------------------------------------------------------------
 * COOKIE PARAMETERS
 * ---------------------------------------------------------------------
 * Every role's session cookie is issued with:
 *   - path     '/'    (site-wide; no cross-role leak risk because
 *                     each role has a distinct cookie NAME)
 *   - secure   false  (dev default; flip via APP_ENV=production)
 *   - httponly true   (JS cannot read the session cookie)
 *   - samesite 'Lax'  (blocks CSRF from third-party origins while
 *                     allowing normal top-level navigations)
 *
 * The 'secure' flag is read from APP_ENV. When APP_ENV=production,
 * secure is set to true. In any other environment (including when
 * APP_ENV is unset, as on a default dev install) secure stays false
 * so the session cookie is sent over plain HTTP during local
 * development.
 *
 * ---------------------------------------------------------------------
 * USAGE
 * ---------------------------------------------------------------------
 * In an entry-point file (a page under <role>/pages/, a handler
 * under <role>/backend/handlers/, or the project's index.php):
 *
 *     <?php
 *     declare(strict_types=1);
 *
 *     require_once __DIR__ . '/<correct-relative-path>/shared/includes/session-bootstrap.php';
 *     fitpal_session_bootstrap('customer');
 *
 *     require_once __DIR__ . '/../includes/header.php';
 *
 * The bootstrap MUST run before the header include, because the
 * header assumes a session is already active.
 *
 * In a shared handler reachable from more than one role (the shared
 * order-transaction handler is the only one today):
 *
 *     <?php
 *     require_once __DIR__ . '/../includes/session-bootstrap.php';
 *
 *     $context = fitpal_session_detect_context();
 *     if ($context === '') {
 *         // refuse the request: no FitPal role is authenticated
 *     }
 *     fitpal_session_bootstrap($context);
 *
 * @package FitPal
 * @version 2.0 — Adds fitpal_session_detect_context(). The bootstrap
 *                contract itself is unchanged: every existing caller
 *                of fitpal_session_bootstrap() behaves exactly as it
 *                did in v1.0, including the idempotency and the
 *                double-context refusal.
 *
 *                (1.0: initial per-role bootstrap.)
 */

declare(strict_types=1);

if (!defined('FITPAL_SESSION_BOOTSTRAP_LOADED')) {
    define('FITPAL_SESSION_BOOTSTRAP_LOADED', true);
}

if (!function_exists('fitpal_session_contexts')) {
    /**
     * The full set of valid context identifiers and their session
     * names. Returned as an array so a caller can enumerate them,
     * but the bootstrap itself only ever looks up one key.
     *
     * Order is significant for fitpal_session_detect_context(): the
     * first matching cookie wins.
     *
     * @return array<string, string> context => session_name
     */
    function fitpal_session_contexts(): array
    {
        return [
            'customer'   => 'PHPSESSID_CUSTOMER',
            'rider'      => 'PHPSESSID_RIDER',
            'restaurant' => 'PHPSESSID_RESTAURANT',
            'admin'      => 'PHPSESSID_ADMIN',
            'public'     => 'PHPSESSID_PUBLIC',
        ];
    }
}

if (!function_exists('fitpal_session_current_context')) {
    /**
     * Read back the context the current request was bootstrapped
     * with, or '' when no bootstrap has run yet.
     *
     * Stored in a $GLOBALS entry rather than a constant so the
     * bootstrap can be called from a test harness that resets state
     * between cases without redefining a constant.
     *
     * @return string
     */
    function fitpal_session_current_context(): string
    {
        return (string)($GLOBALS['FITPAL_SESSION_CONTEXT'] ?? '');
    }
}

if (!function_exists('fitpal_session_detect_context')) {
    /**
     * Return the context name whose session cookie is present on the
     * incoming request, or '' when none is present.
     *
     * Purely reads $_COOKIE. Does not start a session, does not
     * touch $_SESSION, does not emit output.
     *
     * The shared order-transaction handler uses this to know which
     * role's session to bootstrap before it reads $_SESSION. Every
     * other FitPal entry point belongs to exactly one role and can
     * call fitpal_session_bootstrap() with a hard-coded context.
     *
     * When more than one FitPal cookie is present on the request —
     * legal, because a single browser can hold a customer cookie and
     * a rider cookie at the same time — the first entry in
     * fitpal_session_contexts() whose cookie is set wins. Callers
     * that must be sure a specific role is the one being served
     * should still guard on that role's own session key after the
     * session is open.
     *
     * @return string One of 'customer', 'rider', 'restaurant',
     *                'admin', 'public', or '' when none is set.
     */
    function fitpal_session_detect_context(): string
    {
        foreach (fitpal_session_contexts() as $contextName => $sessionName) {
            if (!empty($_COOKIE[$sessionName])) {
                return $contextName;
            }
        }
        return '';
    }
}

if (!function_exists('fitpal_session_bootstrap')) {
    /**
     * Configure PHP's session engine for the given context and start
     * the session.
     *
     * See the file header for the full contract. This function:
     *
     *   1. Validates the context identifier.
     *   2. If a session is already active:
     *        - same context   → returns (idempotent no-op)
     *        - different ctx  → logs and returns without switching
     *   3. Otherwise, sets session_name(), session_set_cookie_params(),
     *      and calls session_start().
     *   4. Records the chosen context in $GLOBALS so a later call can
     *      detect the double-bootstrap case.
     *
     * @param string $context
     * @return void
     */
    function fitpal_session_bootstrap(string $context): void
    {
        $contexts = fitpal_session_contexts();

        if (!isset($contexts[$context])) {
            // Unknown context is a programming error. Do not guess.
            error_log(
                'fitpal_session_bootstrap: unknown context "' . $context . '". '
                . 'Allowed: ' . implode(', ', array_keys($contexts))
            );
            return;
        }

        $desiredName = $contexts[$context];

        // ---- Idempotency + conflict guard ----
        $currentContext = fitpal_session_current_context();

        if ($currentContext !== '') {
            if ($currentContext === $context) {
                return; // already bootstrapped for this context
            }

            error_log(
                'fitpal_session_bootstrap: request already bootstrapped as "'
                . $currentContext . '"; refusing to switch to "' . $context . '". '
                . 'A single request must belong to exactly one role.'
            );
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            // A session is already running but we did not start it —
            // someone else called session_start() before us. We
            // cannot switch the name now. Log the mistake and keep
            // whatever session is already open.
            error_log(
                'fitpal_session_bootstrap: a session was already active when '
                . 'bootstrap was called for "' . $context . '". '
                . 'Bootstrap must run before any session_start().'
            );
            $GLOBALS['FITPAL_SESSION_CONTEXT'] = $context;
            return;
        }

        // ---- Environment-aware cookie flags ----
        $isProduction = (getenv('APP_ENV') === 'production');

        // ---- Configure and start ----
        session_name($desiredName);

        session_set_cookie_params([
            'lifetime' => 0,           // session cookie (dies with browser tab)
            'path'     => '/',         // site-wide; safe because names differ
            'domain'   => '',          // current host only
            'secure'   => $isProduction,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        // Use strict mode so a caller-supplied session ID that was not
        // issued by the server is rejected. This is a defence against
        // session fixation that costs nothing in normal operation.
        ini_set('session.use_strict_mode', '1');

        session_start();

        $GLOBALS['FITPAL_SESSION_CONTEXT'] = $context;
    }
}