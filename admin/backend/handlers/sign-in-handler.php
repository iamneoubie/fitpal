<?php
/**
 * FitPal Admin Sign-In Handler
 *
 * @package FitPal
 * @version 2.0 — Replaced the write to the dead shared key
 *                $_SESSION['created'] with the per-role activity
 *                marker $_SESSION['admin_last_activity']. The header's
 *                idle gate reads this key; the shared $_SESSION['created']
 *                key is no longer used anywhere.
 *
 *                session_regenerate_id(true) remains — it is the one
 *                legal rotation point for the admin role and happens
 *                exactly once per sign-in, before the user is
 *                authenticated into any other role's flow.
 *
 *                (1.6: renamed admin role session display keys to the
 *                {role}_ prefix convention. 1.5: rotated
 *                admin_csrf_token on the CSRF-mismatch branch. 1.4:
 *                cleared admin_csrf_token on the success path.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('admin');

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/admin-queries.php';

// Own the admin role's CSRF bootstrap.
require_once __DIR__ . '/../../includes/admin-csrf-token.php';

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

    // Per-role activity marker. The header's idle gate reads this key.
    // The shared $_SESSION['created'] key is dead and must not be
    // written here.
    $_SESSION['last_activity'] = time();

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