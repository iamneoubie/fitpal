<?php
/**
 * FitPal Customer Sign-In Handler
 *
 * Runs on the customer session (PHPSESSID_CUSTOMER).
 *
 * ---------------------------------------------------------------------
 * PER-ROLE SESSION MODEL (Option B)
 * ---------------------------------------------------------------------
 * This handler bootstraps the customer session before doing
 * anything else. The customer session is a distinct PHP session
 * under a distinct cookie name, so:
 *
 *   - session_regenerate_id(true) here rotates ONLY the customer
 *     session's ID. It does not touch any other role's session,
 *     because no other role's session is open in this request.
 *
 *   - The CSRF token is validated against
 *     $_SESSION['customer_csrf_token'], which lives in the customer
 *     session. No other role can read or write this key.
 *
 *   - The customer-role keys written on success are visible only
 *     to requests that carry the customer session cookie.
 *
 * ---------------------------------------------------------------------
 * SESSION KEYS WRITTEN ON SUCCESS
 * ---------------------------------------------------------------------
 *   customer_id
 *   customer_role
 *   customer_name
 *   customer_email
 *   customer_username
 *   last_activity           (the role-agnostic idle marker)
 *
 * @package FitPal
 * @version 4.0 — Per-role session migration (Option B). The
 *                handler bootstraps the customer session as its
 *                first executable statement. The idle marker key
 *                is now the role-agnostic 'last_activity'. No other
 *                behavior changed.
 *
 *                (3.0: replaced the write to the dead shared key
 *                $_SESSION['created'] with the customer role's
 *                activity marker. 2.2: migrated customer role
 *                session display keys to the {role}_ prefix
 *                convention. 2.1: validated against
 *                customer_csrf_token.)
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// SESSION BOOTSTRAP
//
// Must run before any other include that might touch the session.
// This handler belongs to the customer context.
// ---------------------------------------------------------------------

require_once __DIR__ . '/../../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('customer');

// ---------------------------------------------------------------------
// DEPENDENCIES
// ---------------------------------------------------------------------

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/customer-queries.php';

// Own the customer context's CSRF helper.
require_once __DIR__ . '/../../includes/customer-csrf-token.php';

// ---------------------------------------------------------------------
// REQUEST METHOD GUARD
// ---------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['login_error'] = 'Invalid request method.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

// ---------------------------------------------------------------------
// CSRF
//
// Validated against the customer context's own key,
// 'customer_csrf_token', inside the customer session. On mismatch,
// rotate the customer token so the next render of sign-in.php
// generates a fresh one.
// ---------------------------------------------------------------------

if (
    !isset($_POST['csrf_token'], $_SESSION['customer_csrf_token']) ||
    !hash_equals((string)$_SESSION['customer_csrf_token'], (string)$_POST['csrf_token'])
) {
    unset($_SESSION['customer_csrf_token']);

    $_SESSION['login_error'] = 'Security validation failed. Please try again.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

$identifier = trim((string)($_POST['identifier'] ?? ''));
$password   = (string)($_POST['password'] ?? '');

if ($identifier === '' || $password === '') {
    $_SESSION['login_error'] = 'Please enter your email/username and password.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

try {
    $customer = findCustomerByIdentifier($database_connection, $identifier);

    if (!$customer) {
        $_SESSION['login_error'] = 'Invalid email/username or password.';
        header('Location: ../../pages/sign-in.php');
        exit;
    }

    if ((int)$customer['is_active'] !== 1) {
        $_SESSION['login_error'] = 'Your account has been deactivated. Please contact support.';
        header('Location: ../../pages/sign-in.php');
        exit;
    }

    $isPasswordValid = false;

    if (password_verify($password, (string)$customer['password'])) {
        $isPasswordValid = true;
    }

    // ============================================================
    // SECURITY WARNING — DEVELOPMENT-ONLY CODE
    // ============================================================
    //
    // WHAT THIS DOES:
    //   If the normal password_verify() check fails, this fallback
    //   compares the submitted password directly against the value
    //   stored in customer.password using a plain string equality.
    //
    //   In effect: whoever submitted the request can log in as ANY
    //   customer simply by pasting that customer's stored password
    //   hash (from the database) into the password field. No
    //   knowledge of the original plaintext password is required.
    //
    // WHY IT EXISTS:
    //   The seed data stores passwords as PLAINTEXT ("user123",
    //   "owner123", etc.) instead of bcrypt hashes. During early
    //   development, password_verify() always returns false against
    //   those rows, which blocks login. This block lets the demo work
    //   without re-seeding.
    //
    // WHY IT IS DANGEROUS:
    //   - It is a complete authentication bypass.
    //   - It is not gated by any environment check.
    //
    // WHEN TO REMOVE:
    //   Before this project is deployed anywhere other than a local
    //   development machine.
    //
    // ============================================================
    if (!$isPasswordValid) {
        $clean = trim($password);
        if (hash_equals((string)$customer['password'], $clean)) {
            $isPasswordValid = true;
        }
    }
    // ============================================================
    // END DEVELOPMENT-ONLY BLOCK
    // ============================================================

    if (!$isPasswordValid) {
        $_SESSION['login_error'] = 'Invalid email/username or password.';
        header('Location: ../../pages/sign-in.php');
        exit;
    }

    // -----------------------------------------------------------------
    // SUCCESS
    //
    // session_regenerate_id(true) rotates ONLY the customer session's
    // ID under Option B. The customer's cookie is updated; no other
    // role's cookie is affected because no other role's session is
    // open in this request.
    // -----------------------------------------------------------------
    session_regenerate_id(true);

    $_SESSION['customer_id']       = (int)$customer['customer_id'];
    $_SESSION['customer_role']     = 'customer';
    $_SESSION['customer_name']     = trim($customer['first_name'] . ' ' . $customer['last_name']);
    $_SESSION['customer_email']    = $customer['email'];
    $_SESSION['customer_username'] = $customer['username'];

    // Idle marker. session-activity.php reads this key when it
    // decides whether the customer session has been idle too long.
    $_SESSION['last_activity'] = time();

    // Clear the customer's own CSRF token so the next render of
    // sign-in.php (or any other form) generates a fresh one.
    unset($_SESSION['customer_csrf_token']);

    header('Location: ../../pages/dashboard.php');
    exit;

} catch (PDOException $e) {
    error_log('Customer sign-in error: ' . $e->getMessage());
    $_SESSION['login_error'] = 'An unexpected error occurred. Please try again.';
    header('Location: ../../pages/sign-in.php');
    exit;
}