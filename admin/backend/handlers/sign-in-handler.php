<?php
/**
 * FitPal Admin Sign-In Handler
 *
 * Validates credentials against the administrator table.
 * Contains no SQL — all data access goes through admin-queries.php.
 *
 * ---------------------------------------------------------------------
 * REDIRECT TARGETS
 * ---------------------------------------------------------------------
 * This file lives at fitpal/admin/backend/handlers/. Relative paths:
 *   ../../pages/sign-in.php    → fitpal/admin/pages/sign-in.php
 *   ../../pages/dashboard.php  → fitpal/admin/pages/dashboard.php
 * ---------------------------------------------------------------------
 *
 * ---------------------------------------------------------------------
 * DEVELOPMENT-ONLY BYPASS
 * ---------------------------------------------------------------------
 * Seed data stores plaintext passwords ("admin123"). password_verify()
 * only accepts bcrypt hashes, so the normal check fails against seeds.
 * The bypass below accepts the stored value as plaintext, matching the
 * customer and rider handler patterns. REMOVE before any non-local
 * deployment.
 * ---------------------------------------------------------------------
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/admin-queries.php';

// ===== REQUEST METHOD =====
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['login_error'] = 'Invalid request method.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

// ===== CSRF =====
if (
    !isset($_POST['csrf_token'], $_SESSION['csrf_token']) ||
    !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])
) {
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
    // Remove before any non-local deployment.
    if (!$passwordValid && hash_equals((string)$admin['password'], $password)) {
        $passwordValid = true;
    }
    // ---- END DEVELOPMENT-ONLY BYPASS ----

    if (!$passwordValid) {
        $_SESSION['login_error'] = 'Invalid email/username or password.';
        header('Location: ../../pages/sign-in.php');
        exit;
    }

    // ===== LOGIN SUCCESSFUL =====
    session_regenerate_id(true);

    $_SESSION['administrator_id'] = (int)$admin['administrator_id'];
    $_SESSION['user_role']        = 'admin';
    $_SESSION['user_name']        = trim(
        ($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? '')
    );
    $_SESSION['user_email']       = (string)($admin['email'] ?? '');
    $_SESSION['user_username']    = (string)($admin['username'] ?? '');
    $_SESSION['admin_role']       = (string)($admin['role'] ?? 'support');
    $_SESSION['created']          = time();

    unset($_SESSION['csrf_token']);

    // Record the login timestamp (best-effort; failure is non-fatal).
    try {
        updateAdminLastLogin($database_connection, (int)$admin['administrator_id']);
    } catch (Throwable $e) {
        error_log('Admin last_login update failed: ' . $e->getMessage());
    }

    header('Location: ../../pages/dashboard.php');
    exit;

} catch (PDOException $e) {
    error_log('Admin sign-in DB error: ' . $e->getMessage());
    $_SESSION['login_error'] = 'An unexpected error occurred. Please try again.';
    header('Location: ../../pages/sign-in.php');
    exit;
} catch (Throwable $e) {
    error_log('Admin sign-in error: ' . $e->getMessage());
    $_SESSION['login_error'] = 'An unexpected error occurred. Please try again.';
    header('Location: ../../pages/sign-in.php');
    exit;
}