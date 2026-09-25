<?php
/**
 * FitPal Rider Sign-Out Handler
 *
 * Clears only rider-specific session data. Does NOT call
 * session_destroy(), so any other role sessions in the same browser
 * (customer, admin, restaurant) remain intact.
 *
 * @package FitPal
 * @version 1.4 — Added rider_pending_application to the unset list.
 *                The key is no longer written (sign-up-handler.php
 *                v3.6 removed the write because nothing ever read
 *                it), but any session that still carries a stale
 *                copy from before that revision should be cleaned
 *                on sign-out. The unset is unconditional and costs
 *                nothing when the key is absent.
 *
 *                (1.3: Dropped the unsets of the shared session
 *                keys 'user_role', 'user_name', and 'user_email'.
 *                The rider sign-in handler (v2.3) no longer writes
 *                those keys, so there is nothing to clear here.
 *                Leaving the unsets in place meant a rider sign-out
 *                would wipe values the restaurant, customer, or
 *                admin role had written to the same shared keys in
 *                the same browser — the exact cross-role collision
 *                this pass is closing.
 *
 *                Reads the rider role's own CSRF key,
 *                rider_csrf_token, instead of the shared csrf_token.
 *                The comparison is still non-fatal (matching the
 *                original intent: a stale CSRF token must never block
 *                a sign-out), but it now inspects the correct session
 *                key. Also unsets rider_csrf_token and
 *                $_SESSION['created'] as part of the sign-out so the
 *                next sign-in page renders a fresh rider-scoped token
 *                and re-pins the session's rotation marker. The shared
 *                csrf_token key and every other role's token are
 *                deliberately left alone — another role in the same
 *                browser session may still have forms open that
 *                depend on them.
 *
 *                1.2: Explicit cookie expiry + no-cache headers.
 *                1.1: File renamed from sign-out-handlers.php to
 *                match customer convention and the header.php link.)
 */

declare(strict_types=1);

// ===== SESSION START =====
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ===== CSRF VALIDATION (optional, non-fatal) =====
// Read the rider's own key, not the shared csrf_token. A mismatch is
// logged but never blocks the sign-out: the user asked to leave, and
// refusing to sign them out over a stale form field would be worse
// than the alternative.
$token         = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
$expectedToken = $_SESSION['rider_csrf_token'] ?? '';

if ($token !== '' && $expectedToken !== '' && !hash_equals($expectedToken, $token)) {
    error_log('Rider sign-out: invalid CSRF token attempt');
}

// ===== CLEAR ONLY RIDER SESSION DATA =====
unset(
    $_SESSION['delivery_rider_id'],
    $_SESSION['rider_csrf_token'],
    $_SESSION['rider_pending_application'],
    $_SESSION['created']
);

// Do NOT unset these as they may belong to other roles:
// - $_SESSION['customer_id']
// - $_SESSION['administrator_id']
// - $_SESSION['restaurant_account_id']

// ===== REGENERATE SESSION ID =====
session_regenerate_id(true);

header('Location: ../../pages/sign-in.php');
exit;