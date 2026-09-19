<?php
/**
 * FitPal Admin Sign-Out Handler
 *
 * Clears ONLY administrator session data. Does NOT call
 * session_destroy() so that other role sessions (customer, rider,
 * restaurant) that may be active in the same browser are preserved.
 *
 * Mirrors customer/backend/handlers/sign-out-handler.php so all roles
 * behave identically.
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

// ===== SESSION START =====
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ===== CSRF VALIDATION (optional, non-fatal) =====
$token         = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
$expectedToken = $_SESSION['csrf_token'] ?? '';

if ($token !== '' && $expectedToken !== '' && !hash_equals($expectedToken, $token)) {
    error_log('Admin sign-out: CSRF token mismatch — proceeding anyway (idempotent action).');
}

// ===== CLEAR ONLY ADMINISTRATOR SESSION DATA =====
unset($_SESSION['administrator_id']);
unset($_SESSION['admin_role']);

// The following keys are shared across roles. Only unset them if the
// current session's role marker is 'admin'. Otherwise we might wipe
// another role's data.
if (($_SESSION['user_role'] ?? '') === 'admin') {
    unset($_SESSION['user_role']);
    unset($_SESSION['user_name']);
    unset($_SESSION['user_email']);
    unset($_SESSION['user_username']);
}

// Do NOT unset these — they may belong to other roles:
// - $_SESSION['customer_id']
// - $_SESSION['restaurant_id']
// - $_SESSION['delivery_rider_id']

// Leave CSRF token in place for other forms on other roles.

// ===== REGENERATE SESSION ID =====
session_regenerate_id(true);

// ===== REDIRECT =====
// Whitelist the redirect target to prevent open-redirect abuse.
$redirect = $_POST['redirect'] ?? $_GET['redirect'] ?? '';

$allowedRedirects = [
    'sign-in.php',
    '../../pages/sign-in.php',
    '../../../admin/pages/sign-in.php',
];

if ($redirect !== '' && in_array($redirect, $allowedRedirects, true)) {
    header('Location: ' . $redirect);
    exit;
}

// Default: back to the admin sign-in page.
// From: /fitpal/admin/backend/handlers/
// To:   /fitpal/admin/pages/sign-in.php
header('Location: ../../pages/sign-in.php');
exit;