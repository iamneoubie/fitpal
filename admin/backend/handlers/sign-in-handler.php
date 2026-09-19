<?php
/**
 * FitPal Administrator Sign-In Handler
 *
 * Validates credentials against the administrator table, sets the
 * admin session, and redirects to the dashboard.
 *
 * @package FitPal
 * @version 1.1 — Reads role from administrator_profile via the
 *                updated query. Falls back to 'support' when the
 *                profile row is missing.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/admin-queries.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['admin_login_error'] = 'Invalid request method.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

if (!isset($_POST['csrf_token'], $_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $_SESSION['admin_login_error'] = 'Security validation failed. Please try again.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

$identifier = trim($_POST['identifier'] ?? '');
$password   = $_POST['password'] ?? '';

if ($identifier === '' || $password === '') {
    $_SESSION['admin_login_error'] = 'Please enter your email/username and password.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

try {
    $admin = findAdministratorByIdentifier($database_connection, $identifier);

    if (!$admin) {
        $_SESSION['admin_login_error'] = 'Invalid email/username or password.';
        header('Location: ../../pages/sign-in.php');
        exit;
    }

    if ((int)$admin['is_active'] !== 1) {
        $_SESSION['admin_login_error'] = 'Your account has been deactivated.';
        header('Location: ../../pages/sign-in.php');
        exit;
    }

    $isPasswordValid = false;

    if (password_verify($password, $admin['password'])) {
        $isPasswordValid = true;
    }

    // ============================================================
    // SECURITY WARNING — DEVELOPMENT-ONLY CODE
    // ============================================================
    //
    // WHAT THIS DOES:
    //   If password_verify() fails, this fallback compares the
    //   submitted password against the stored value using plain
    //   string equality. Anyone who can read the stored value
    //   (from the DB) can log in as that admin.
    //
    // WHY IT EXISTS:
    //   Seed data stores passwords as plaintext ("admin123"), so
    //   password_verify() always fails against those rows.
    //
    // WHEN TO REMOVE:
    //   Before any deployment outside local development. See
    //   customer/backend/handlers/sign-in-handler.php for the full
    //   rationale — this block mirrors it exactly.
    // ============================================================
    if (!$isPasswordValid) {
        $clean = trim($password);
        if (hash_equals((string)$admin['password'], $clean)) {
            $isPasswordValid = true;
        }
    }
    // ============================================================
    // END DEVELOPMENT-ONLY BLOCK
    // ============================================================

    if (!$isPasswordValid) {
        $_SESSION['admin_login_error'] = 'Invalid email/username or password.';
        header('Location: ../../pages/sign-in.php');
        exit;
    }

    session_regenerate_id(true);

    // Role comes from administrator_profile. When the profile row
    // is missing, $admin['role'] is null and we default to 'support'.
    $role = isset($admin['role']) && $admin['role'] !== null && $admin['role'] !== ''
        ? (string)$admin['role']
        : 'support';

    $_SESSION['administrator_id'] = (int)$admin['administrator_id'];
    $_SESSION['admin_role']       = $role;
    $_SESSION['user_role']        = 'admin';
    $_SESSION['user_name']        = trim($admin['first_name'] . ' ' . $admin['last_name']);
    $_SESSION['user_email']       = $admin['email'];
    $_SESSION['user_username']    = $admin['username'];
    $_SESSION['created']          = time();

    try {
        touchAdministratorLastLogin($database_connection, (int)$admin['administrator_id']);
    } catch (Throwable $e) {
        error_log('Admin last-login update failed: ' . $e->getMessage());
    }

    unset($_SESSION['csrf_token']);

    header('Location: ../../pages/dashboard.php');
    exit;

} catch (PDOException $e) {
    error_log('Admin sign-in error: ' . $e->getMessage());
    $_SESSION['admin_login_error'] = 'An unexpected error occurred. Please try again.';
    header('Location: ../../pages/sign-in.php');
    exit;
}