<?php
/**
 * FitPal Rider Sign-Out Handler
 *
 * Fully destroys the rider session (PHPSESSID_RIDER) and redirects
 * to the rider sign-in page.
 *
 * ---------------------------------------------------------------------
 * PER-ROLE SESSION MODEL (Option B)
 * ---------------------------------------------------------------------
 * Under Option B, each role runs as its own PHP session under its
 * own cookie name. This handler bootstraps the rider session
 * before doing anything else. Because the request that reaches
 * this handler carries only the rider session cookie, this handler
 * can only ever see the rider session — it cannot read or write
 * any other role's session.
 *
 * The sign-out is a FULL DESTROY of the rider session:
 *
 *   session_unset()    clears every key in the rider session.
 *   session_destroy()  deletes the rider session file.
 *
 * There is no longer any need for a role-scoped unset list. The
 * rider session exists for exactly one purpose — to hold the
 * rider's login state and the rider's transient UI state
 * (dismissed assignment ids, pending application flag) — and
 * tearing it down completely is the correct behavior.
 *
 * ---------------------------------------------------------------------
 * CSRF (NON-FATAL)
 * ---------------------------------------------------------------------
 * The POST may carry a csrf_token. It is validated against
 * $_SESSION['rider_csrf_token'] for audit purposes. A mismatch is
 * logged but does NOT block the sign-out. The user already asked
 * to leave; refusing the exit over a stale token would be a worse
 * failure mode than accepting a forged sign-out.
 *
 * ---------------------------------------------------------------------
 * COOKIE EXPIRY
 * ---------------------------------------------------------------------
 * session_destroy() removes the session file but does NOT remove
 * the browser cookie. The cookie is expired explicitly, using the
 * exact parameters the bootstrap set, so the browser's cookie jar
 * matches and clears the entry.
 *
 * @package FitPal
 * @version 3.0 — Per-role session migration (Option B). The
 *                handler bootstraps the rider session and then
 *                fully destroys it. The role-scoped unset list is
 *                replaced by session_unset() + session_destroy().
 *                The CSRF check and the final redirect target are
 *                unchanged.
 *
 *                (2.0: full rewrite targeting a curated set of
 *                rider-scoped keys instead of a global
 *                session_destroy.)
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// SESSION BOOTSTRAP
//
// Must run before any other include that might touch the session.
// This handler belongs to the rider context. Under Option B, this
// ensures the handler operates on PHPSESSID_RIDER and not on any
// other role's session.
// ---------------------------------------------------------------------

require_once __DIR__ . '/../../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('rider');

// ---------------------------------------------------------------------
// CSRF VALIDATION (NON-FATAL)
//
// Read the rider context's own token key. A mismatch is logged for
// auditing but never blocks the sign-out.
// ---------------------------------------------------------------------

$token         = (string)($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '');
$expectedToken = (string)($_SESSION['rider_csrf_token'] ?? '');

if ($token !== '' && $expectedToken !== '' && !hash_equals($expectedToken, $token)) {
    error_log('Rider sign-out: invalid CSRF token attempt');
}

// ---------------------------------------------------------------------
// DESTROY THE RIDER SESSION
//
// session_unset() clears every key in the active session.
// session_destroy() deletes the session file.
//
// Under Option B the active session is the rider session, and the
// rider session contains only rider keys. There is no cross-role
// contamination risk, so a blanket unset is correct.
// ---------------------------------------------------------------------

session_unset();
session_destroy();

// ---------------------------------------------------------------------
// EXPIRE THE SESSION COOKIE
//
// session_destroy() removes the server-side session file but the
// browser still carries the cookie. Expire it explicitly, using
// the same attributes the bootstrap set so the browser's cookie
// jar matches and removes the entry.
// ---------------------------------------------------------------------

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        [
            'expires'  => time() - 42000,
            'path'     => $params['path']    ?: '/',
            'domain'   => $params['domain']  ?: '',
            'secure'   => (bool)$params['secure'],
            'httponly' => (bool)$params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]
    );
}

// ---------------------------------------------------------------------
// REDIRECT
//
// From: /<project>/rider/backend/handlers/
// To:   /<project>/rider/pages/sign-in.php
// ---------------------------------------------------------------------

header('Location: ../../pages/sign-in.php');
exit;