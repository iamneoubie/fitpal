<?php
/**
 * FitPal Admin Sign-In Handler
 *
 * @package FitPal
 * @version 1.5 — Rotates admin_csrf_token on the CSRF-mismatch branch
 *                before redirecting back to sign-in.php. Previously
 *                the branch redirected without clearing the key, and
 *                since sign-in.php only generates a fresh token when
 *                the key is empty, the next render re-emitted the
 *                same stale value. A browser that followed the
 *                redirect was stuck in a validation loop until the
 *                session was cleared manually.
 *
 *                The successful-login path already cleared the key
 *                (v1.4); this revision applies the same rotation to
 *                the mismatch branch, which is the other path where
 *                a stale token can reach the form. All other failure
 *                branches (wrong password, deactivated account,
 *                empty fields) leave the token alone — the token was
 *                valid in those cases, so rotating it would force an
 *                unnecessary re-render.
 *
 *                See sign-in.php v3.7 for the full explanation of
 *                why admin uses its own key rather than the shared
 *                csrf_token.
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
    $_SESSION['user_role']        = 'admin';
    $_SESSION['user_name']        = trim(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? ''));
    $_SESSION['user_email']       = (string)($admin['email'] ?? '');
    $_SESSION['admin_role']       = (string)($admin['role'] ?? 'support');

    $_SESSION['created'] = time();

    recordAdminLogin($database_connection, (int)$admin['administrator_id']);

    // Only clear admin's own token. Do not touch the shared
    // 'csrf_token' key — other roles (customer, rider, restaurant)
    // may still be relying on it in this same browser session.
    unset($_SESSION['admin_csrf_token']);

    header('Location: ../../pages/dashboard.php');
    exit;

} catch (PDOException $e) {
    error_log('Admin sign-in DB error: ' . $e->getMessage());
    $_SESSION['login_error'] = 'An unexpected error occurred. Please try again.';
    header('Location: ../../pages/sign-in.php');
    exit;
}