<?php
/**
 * FitPal Customer Sign-Out Handler
 *
 * Fully destroys the customer session (PHPSESSID_CUSTOMER) and
 * redirects to the customer sign-in page.
 *
 * ---------------------------------------------------------------------
 * PER-ROLE SESSION MODEL (Option B)
 * ---------------------------------------------------------------------
 * Under Option B, each role runs as its own PHP session under its
 * own cookie name. This handler bootstraps the customer session
 * before doing anything else. Because the request that reaches
 * this handler carries only the customer session cookie, this
 * handler can only ever see the customer session — it cannot read
 * or write any other role's session.
 *
 * The sign-out is a FULL DESTROY of the customer session:
 *
 *   session_unset()    clears every key in the customer session.
 *   session_destroy()  deletes the customer session file.
 *
 * There is no longer any need for a role-scoped unset list. The
 * customer session exists for exactly one purpose — to hold the
 * customer's login state — and tearing it down completely is the
 * correct behavior. In the older shared-session design, a similar
 * handler had to carefully unset only the customer's keys and
 * leave the shared session file alive; under Option B that
 * workaround is unnecessary and would actually be wrong (it would
 * leave a customer session file behind on disk for no reason).
 *
 * ---------------------------------------------------------------------
 * CSRF (NON-FATAL)
 * ---------------------------------------------------------------------
 * The POST may carry a csrf_token. It is validated against
 * $_SESSION['customer_csrf_token'] for audit purposes. A mismatch
 * is logged but does NOT block the sign-out. The user already
 * asked to leave; refusing the exit over a stale token would be a
 * worse failure mode than accepting a forged sign-out.
 *
 * ---------------------------------------------------------------------
 * OPTIONAL REDIRECT
 * ---------------------------------------------------------------------
 * A POST or GET 'redirect' parameter may name a relative path the
 * browser should return to after sign-out. It is honored only when
 * it is a relative path that does not attempt to escape upward,
 * so the parameter cannot become an open redirect.
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
 *                handler bootstraps the customer session and then
 *                fully destroys it. The role-scoped unset list is
 *                replaced by session_unset() + session_destroy().
 *                The CSRF check, the open-redirect guard, and the
 *                final redirect target are unchanged.
 *
 *                (2.0: full rewrite targeting a curated set of
 *                customer-scoped keys instead of a global
 *                session_destroy.)
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// SESSION BOOTSTRAP
//
// Must run before any other include that might touch the session.
// This handler belongs to the customer context. Under Option B,
// this ensures the handler operates on PHPSESSID_CUSTOMER and not
// on any other role's session.
// ---------------------------------------------------------------------

require_once __DIR__ . '/../../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('customer');

// ---------------------------------------------------------------------
// NO-CACHE HEADERS
//
// The sign-out response must not be cached. If the browser serves
// a cached copy on Back, the user could see an authenticated page
// after they have signed out.
// ---------------------------------------------------------------------

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// ---------------------------------------------------------------------
// CSRF VALIDATION (NON-FATAL)
//
// Read the customer context's own token key. A mismatch is logged
// for auditing but never blocks the sign-out.
// ---------------------------------------------------------------------

$token         = (string)($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '');
$expectedToken = (string)($_SESSION['customer_csrf_token'] ?? '');

if ($token !== '' && $expectedToken !== '' && !hash_equals($expectedToken, $token)) {
    error_log('Customer sign-out: invalid CSRF token attempt');
}

// ---------------------------------------------------------------------
// DESTROY THE CUSTOMER SESSION
//
// session_unset() clears every key in the active session.
// session_destroy() deletes the session file.
//
// Under Option B the active session is the customer session, and
// the customer session contains only customer keys. There is no
// cross-role contamination risk, so a blanket unset is correct.
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
// From: /<project>/customer/backend/handlers/
// To:   /<project>/customer/pages/sign-in.php
//
// The redirect target is only honored when it is a relative path
// that does not attempt to escape upward. No output escaping is
// applied — htmlspecialchars() escapes for HTML context, not for a
// Location header, and would corrupt URLs containing '&'. The '..'
// and leading-'/' guards keep the value from becoming an open
// redirect to an external site.
// ---------------------------------------------------------------------

$redirect = (string)($_POST['redirect'] ?? $_GET['redirect'] ?? '');

if (
    $redirect !== ''
    && strpos($redirect, '..') === false
    && strpos($redirect, '/') !== 0
    && preg_match('#^[A-Za-z0-9_\-./?=&%]+$#', $redirect) === 1
) {
    header('Location: ' . $redirect);
    exit;
}

header('Location: ../../pages/sign-in.php');
exit;