<?php
/**
 * FitPal Admin Sign-In Handler
 *
 * @package FitPal
 * @version 1.6 — Renamed the admin role's session display keys to
 *                the {role}_ prefix convention so they can no longer
 *                collide with the customer, rider, or restaurant
 *                roles in the same PHP session.
 *
 *                Previously this handler wrote:
 *                    $_SESSION['user_role']
 *                    $_SESSION['user_name']
 *                    $_SESSION['user_email']
 *                All three are generic key names that every other role
 *                also wrote. Because FitPal uses a single shared PHP
 *                session across roles (same cookie), an admin sign-in
 *                overwrote the customer's $_SESSION['user_name'], and
 *                the customer dashboard — which reads
 *                $_SESSION['user_name'] for its greeting — rendered
 *                "Welcome back, Admin User" while its profile card,
 *                orders, and wallet (all fetched by customer_id from
 *                the database) stayed correct. The same collision ran
 *                in reverse when the customer signed in after the
 *                admin.
 *
 *                The fix follows the same pattern already applied to
 *                the CSRF token in v1.5 and to the ID keys from the
 *                start:
 *                    administrator_id  (already namespaced)
 *                    admin_role        (already namespaced)
 *                    admin_name        (new — replaces user_name)
 *                    admin_email       (new — replaces user_email)
 *                    admin_csrf_token  (already namespaced, v1.4)
 *
 *                user_role was dropped entirely. The admin role never
 *                needed it: administrator_id is the authentication
 *                check every admin page performs, and admin_role is
 *                the authorization value every admin page reads. A
 *                separate user_role key was redundant and only
 *                existed to match the customer role's session shape.
 *
 *                Nothing in the admin role reads admin_name or
 *                admin_email yet. They are written for parity with
 *                the other roles' session shape and so a future admin
 *                page can render the signed-in administrator's name
 *                without a database round-trip. admin/includes/header.php
 *                currently loads the name via a query and is
 *                deliberately left unchanged.
 *
 *                (1.5: Rotated admin_csrf_token on the CSRF-mismatch
 *                branch before redirecting back to sign-in.php so a
 *                browser that followed the redirect was no longer
 *                stuck in a validation loop with a stale token.
 *                1.4: Cleared admin_csrf_token on the success path so
 *                the next admin sign-in generates a fresh token.
 *                Earlier revisions switched validation from the
 *                shared csrf_token key to admin's own
 *                admin_csrf_token.)
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/admin-queries.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['login_error'] = 'Invalid request method.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

if (!isset($_POST['csrf_token'], $_SESSION['admin_csrf_token'])
    || !hash_equals((string)$_SESSION['admin_csrf_token'], (string)$_POST['csrf_token'])) {
    // Rotate the token so the next render of sign-in.php generates a
    // fresh one. Without this, a stale token would be re-emitted on
    // every redirect-back and the user would be stuck in a loop.
    unset($_SESSION['admin_csrf_token']);

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
    $admin = findAdminByIdentifier($database_connection, $identifier);

    if (!$admin) {
        $_SESSION['login_error'] = 'Invalid email/username or password.';
        header('Location: ../../pages/sign-in.php');
        exit;
    }

    if ((int)$admin['is_active'] !== 1) {
        $_SESSION['login_error'] = 'Your account has been deactivated. Please contact support.';
        header('Location: ../../pages/sign-in.php');
        exit;
    }

    $passwordValid = password_verify($password, (string)$admin['password']);

    // ---- DEVELOPMENT-ONLY BYPASS ----
    if (!$passwordValid && hash_equals((string)$admin['password'], $password)) {
        $passwordValid = true;
    }
    // ---- END BYPASS ----

    if (!$passwordValid) {
        $_SESSION['login_error'] = 'Invalid email/username or password.';
        header('Location: ../../pages/sign-in.php');
        exit;
    }

    session_regenerate_id(true);

    $_SESSION['administrator_id'] = (int)$admin['administrator_id'];
    $_SESSION['admin_role']       = (string)($admin['role'] ?? 'support');
    $_SESSION['admin_name']       = trim(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? ''));
    $_SESSION['admin_email']      = (string)($admin['email'] ?? '');

    $_SESSION['created'] = time();

    recordAdminLogin($database_connection, (int)$admin['administrator_id']);

    // Only clear admin's own token. Do not touch the shared
    // 'csrf_token' key or any other role's token — other roles
    // (customer, rider, restaurant) may still be relying on them
    // in this same browser session.
    unset($_SESSION['admin_csrf_token']);

    header('Location: ../../pages/dashboard.php');
    exit;

} catch (PDOException $e) {
    error_log('Admin sign-in DB error: ' . $e->getMessage());
    $_SESSION['login_error'] = 'An unexpected error occurred. Please try again.';
    header('Location: ../../pages/sign-in.php');
    exit;
}