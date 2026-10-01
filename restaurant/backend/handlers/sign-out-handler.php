<?php
/**
 * FitPal Restaurant Sign-Out Handler
 *
 * Fully destroys the restaurant session (PHPSESSID_RESTAURANT) and
 * redirects to the restaurant sign-in page.
 *
 * ---------------------------------------------------------------------
 * PER-ROLE SESSION MODEL (Option B)
 * ---------------------------------------------------------------------
 * Under Option B, each role runs as its own PHP session under its
 * own cookie name. This handler bootstraps the restaurant session
 * before doing anything else. Because the request that reaches
 * this handler carries only the restaurant session cookie, this
 * handler can only ever see the restaurant session — it cannot
 * read or write any other role's session.
 *
 * The sign-out is a FULL DESTROY of the restaurant session:
 *
 *   session_unset()    clears every key in the restaurant session.
 *   session_destroy()  deletes the restaurant session file.
 *
 * There is no longer any need for a role-scoped unset list. The
 * restaurant session exists for exactly one purpose — to hold the
 * restaurant user's login state — and tearing it down completely
 * is the correct behavior.
 *
 * ---------------------------------------------------------------------
 * CSRF (NON-FATAL)
 * ---------------------------------------------------------------------
 * The POST may carry a csrf_token. It is validated against
 * $_SESSION['restaurant_csrf_token'] for audit purposes. A
 * mismatch is logged but does NOT block the sign-out. The user
 * already asked to leave; refusing the exit over a stale token
 * would be a worse failure mode than accepting a forged sign-out.
 *
 * ---------------------------------------------------------------------
 * COOKIE EXPIRY
 * ---------------------------------------------------------------------
 * session_destroy() removes the session file but does NOT remove
 * the browser cookie. The cookie is expired explicitly, using the
 * exact parameters the bootstrap set, so the browser's cookie jar
 * matches and clears the entry.
 *
 * ---------------------------------------------------------------------
 * NO-CACHE HEADERS
 * ---------------------------------------------------------------------
 * The response must not be cached, otherwise the browser's Back
 * button could resurrect a cached authenticated page after the
 * user has signed out.
 *
 * @package FitPal
 * @version 4.0 — Per-role session migration (Option B). The handler
 *                bootstraps the restaurant session and then fully
 *                destroys it. The role-scoped unset list is
 *                replaced by session_unset() + session_destroy().
 *                The CSRF check and the final redirect target are
 *                unchanged.
 *
 *                (3.0: moved to the per-role session bootstrap.
 *                2.0: explicit cookie expiry, no-cache headers.
 *                1.0: initial.)
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// SESSION BOOTSTRAP
//
// Must run before any other include that might touch the session.
// This handler belongs to the restaurant context. Under Option B,
// this ensures the handler operates on PHPSESSID_RESTAURANT and not
// on any other role's session.
// ---------------------------------------------------------------------

require_once __DIR__ . '/../../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('restaurant');

// ---------------------------------------------------------------------
// NO-CACHE HEADERS
// ---------------------------------------------------------------------

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// ---------------------------------------------------------------------
// CSRF VALIDATION (NON-FATAL)
//
// Read the restaurant context's own token key. A mismatch is logged
// for auditing but never blocks the sign-out.
// ---------------------------------------------------------------------

$token         = (string)($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '');
$expectedToken = (string)($_SESSION['restaurant_csrf_token'] ?? '');

if ($token !== '' && $expectedToken !== '' && !hash_equals($expectedToken, $token)) {
    error_log('Restaurant sign-out: invalid CSRF token attempt');
}

// ---------------------------------------------------------------------
// DESTROY THE RESTAURANT SESSION
//
// session_unset() clears every key in the active session.
// session_destroy() deletes the session file.
//
// Under Option B the active session is the restaurant session, and
// the restaurant session contains only restaurant keys. There is
// no cross-role contamination risk, so a blanket unset is
// correct.
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
// From: /<project>/restaurant/backend/handlers/
// To:   /<project>/restaurant/pages/sign-in.php
// ---------------------------------------------------------------------

header('Location: ../../pages/sign-in.php?logged_out=1');
exit;