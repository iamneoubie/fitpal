<?php
/**
 * FitPal Session Activity Tracking
 *
 * Utility for recording the last-activity timestamp of the active
 * session.
 *
 * ---------------------------------------------------------------------
 * AUTOMATIC IDLE EXPIRY IS DISABLED BY DEFAULT
 * ---------------------------------------------------------------------
 * The idle gate that used to force a sign-out after 30 minutes of
 * inactivity is removed. FitPal now relies on the browser's own
 * session-cookie lifetime: closing the browser clears the session,
 * and signing out is entirely a manual action taken by the user.
 *
 * trackSessionActivity() therefore has two behaviors:
 *
 *   - With no argument (or with $timeout <= 0), it records the
 *     current time and always returns true. It never causes a
 *     caller to log anyone out.
 *
 *   - With a positive $timeout argument, it preserves the old
 *     behavior for a caller that explicitly opts in: it compares
 *     the recorded timestamp to now and returns false when the
 *     session has been idle longer than $timeout. No caller in
 *     the current codebase passes an argument, so this branch is
 *     dormant unless a future feature needs it.
 *
 * ---------------------------------------------------------------------
 * CONTRACT
 * ---------------------------------------------------------------------
 * This file:
 *   - Reads and writes exactly one key in the active session:
 *     $_SESSION['last_activity'].
 *   - Never rotates the session ID.
 *   - Never emits output.
 *   - Never redirects.
 *   - Never touches any other session key.
 *   - Never touches any other session (it cannot; PHP gives a
 *     request access to exactly one session).
 *
 * The caller is responsible for calling fitpal_session_bootstrap()
 * before this file runs, so that $_SESSION refers to the correct
 * role's session.
 *
 * Sign-out is manual. The user presses the logout button, the
 * sign-out handler runs, and the session is destroyed. Nothing in
 * this file can end a session on its own.
 *
 * @package FitPal
 * @version 3.0 — Idle auto-expiry disabled. trackSessionActivity()
 *                now returns true on every call unless the caller
 *                explicitly passes a positive timeout. The constant
 *                FITPAL_SESSION_IDLE_TIMEOUT is retained but set to
 *                0, meaning "no automatic expiry."
 */

declare(strict_types=1);

/**
 * Idle timeout in seconds.
 *
 * 0 means "no automatic expiry." trackSessionActivity() treats any
 * non-positive value as disabled. Set this to a positive number to
 * restore automatic idle logout for a future revision, but be aware
 * that every role's header would need to be re-gated on the return
 * value for it to have any effect.
 */
if (!defined('FITPAL_SESSION_IDLE_TIMEOUT')) {
    define('FITPAL_SESSION_IDLE_TIMEOUT', 0);
}

/**
 * Key used in the active session to store the last-activity
 * timestamp. Named so both helpers reference the same string
 * without duplicating it.
 */
if (!defined('FITPAL_SESSION_ACTIVITY_KEY')) {
    define('FITPAL_SESSION_ACTIVITY_KEY', 'last_activity');
}

if (!function_exists('trackSessionActivity')) {
    /**
     * Record activity in the active session.
     *
     * Called with no argument, this function records the current
     * time and returns true. It never returns false on its own, so
     * a caller that gates on its return value will never be logged
     * out by it.
     *
     * Called with a positive $timeout, it preserves the old
     * idle-check behavior for a caller that explicitly wants it.
     * No caller in the current codebase does.
     *
     * @param int $timeout Idle seconds. Non-positive means
     *                     "disabled" and the function always
     *                     returns true.
     * @return bool        Always true when $timeout <= 0.
     */
    function trackSessionActivity(int $timeout = FITPAL_SESSION_IDLE_TIMEOUT): bool
    {
        $key = FITPAL_SESSION_ACTIVITY_KEY;

        if ($timeout <= 0) {
            // Automatic expiry disabled. Record the timestamp so a
            // future feature can read it, and report success so no
            // caller acts on a stale value.
            $_SESSION[$key] = time();
            return true;
        }

        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = time();
            return true;
        }

        $lastActivity = (int)$_SESSION[$key];

        if (time() - $lastActivity > $timeout) {
            // Caller opted into a finite timeout and the session
            // has been idle past it. Do NOT refresh.
            return false;
        }

        $_SESSION[$key] = time();
        return true;
    }
}

if (!function_exists('clearSessionActivity')) {
    /**
     * Remove the activity marker from the active session.
     *
     * Called by a role's sign-out flow so no activity marker
     * lingers after a manual sign-out.
     *
     * Does not touch any other session key. Does not destroy the
     * session. Destroying the session is a sign-out concern and
     * belongs to the sign-out handler.
     *
     * @return void
     */
    function clearSessionActivity(): void
    {
        unset($_SESSION[FITPAL_SESSION_ACTIVITY_KEY]);
    }
}