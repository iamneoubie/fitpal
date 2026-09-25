<?php
/**
 * FitPal Customer Sign-Out Handler
 *
 * This handler clears ONLY customer session data.
 * It does NOT call session_destroy() to preserve other role sessions
 * (admin, restaurant, rider) that may be active in the same browser.
 *
 * @package FitPal
 * @version 1.3 — The unset list now targets the customer role's own
 *                namespaced keys after the sign-in handler was
 *                migrated to the {role}_ prefix convention in v2.2.
 *
 *                Removed from the unset list:
 *                    user_role     — no longer written by customer sign-in
 *                    user_name     — replaced by customer_name
 *                    user_email    — replaced by customer_email
 *                    user_username — replaced by customer_username
 *
 *                Added to the unset list:
 *                    customer_role     — new customer-scoped role key
 *                    customer_name     — new customer-scoped display key
 *                    customer_email    — new customer-scoped contact key
 *                    customer_username — new customer-scoped username key
 *
 *                Previously this handler unset the generic user_* keys
 *                alongside customer_id and customer_csrf_token. Those
 *                generic keys were also written by the admin, rider,
 *                and restaurant roles — all sharing the same PHP
 *                session — so a customer signing out could wipe an
 *                admin's user_name and leave any admin page that read
 *                that key rendering a blank greeting. Removing them
 *                from this handler's unset list closes that
 *                reverse-direction collision.
 *
 *                The shared 'csrf_token' key is still deliberately
 *                left alone. The customer role never read or wrote
 *                it; touching it here would risk breaking another
 *                role whose form is already rendered in this same
 *                browser session.
 *
 *                (1.2: Validated against customer_csrf_token — the
 *                customer role's own key — and unset it on the way
 *                out so the next customer sign-in generates a fresh
 *                one.
 *                1.1: Hardened the redirect whitelist so the
 *                redirect target cannot become an open redirect.
 *                1.0: Initial version.)
 */

declare(strict_types=1);

// ===== SESSION START =====
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ===== CSRF VALIDATION (optional) =====
//
// Per-role check. The customer role validates against its own
// session key, 'customer_csrf_token', never the shared 'csrf_token'.
// Sign-out still proceeds if the token is missing or stale — the
// user has already asked to leave, and blocking the exit would be a
// worse failure mode than accepting a forged sign-out. A mismatched
// token is logged for auditing and then ignored.
$token         = (string)($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '');
$expectedToken = (string)($_SESSION['customer_csrf_token'] ?? '');

if ($token !== '' && $expectedToken !== '' && !hash_equals($expectedToken, $token)) {
    error_log('Customer sign-out: Invalid CSRF token attempt');
}

// ===== CLEAR ONLY CUSTOMER SESSION DATA =====
unset($_SESSION['customer_id']);
unset($_SESSION['customer_role']);
unset($_SESSION['customer_name']);
unset($_SESSION['customer_email']);
unset($_SESSION['customer_username']);

// The customer's own CSRF token is retired with the session it
// belonged to. Do NOT touch the shared 'csrf_token' key, and do NOT
// touch any other role's token — another role in this same browser
// session may still be relying on them.
unset($_SESSION['customer_csrf_token']);

// Do NOT unset these as they may belong to other roles:
// - $_SESSION['administrator_id']
// - $_SESSION['admin_role']
// - $_SESSION['admin_name']
// - $_SESSION['admin_email']
// - $_SESSION['restaurant_id']
// - $_SESSION['delivery_rider_id']

// ===== REGENERATE SESSION ID =====
session_regenerate_id(true);

// ===== REDIRECT =====
//
// From: /fitpal/customer/backend/handlers/
// To:   /fitpal/customer/pages/sign-in.php
//
// The redirect target is only honored when it is a relative path
// that does not attempt to escape upward. No output escaping is
// applied — htmlspecialchars() escapes for HTML context, not for a
// Location header, and would corrupt URLs containing '&'. The
// '..' and leading-'/' guards keep the value from becoming an
// open redirect to an external site.
$redirect = (string)($_POST['redirect'] ?? $_GET['redirect'] ?? '');

if ($redirect !== ''
    && strpos($redirect, '..') === false
    && strpos($redirect, '/') !== 0
    && preg_match('#^[A-Za-z0-9_\-./?=&%]+$#', $redirect) === 1
) {
    header('Location: ' . $redirect);
    exit;
}

header('Location: ../../pages/sign-in.php');
exit;