<?php
/**
<<<<<<< Updated upstream:rider/backend/handlers/sign-out-handler.php
 * FitPal Rider Sign-Out Handler
 *
 * Clears only rider-specific session data. Does NOT call
 * session_destroy(), so any other role sessions in the same browser
 * (customer, admin, restaurant) remain intact.
 *
 * @package FitPal
 * @version 1.1 — File renamed from sign-out-handlers.php to match
 *                customer convention and the header.php link.
=======
 * FitPal Admin Sign-Out Handler
 *
 * Clears ONLY administrator session data. Does NOT call
 * session_destroy() so that other role sessions (customer, rider,
 * restaurant) that may be active in the same browser are preserved.
 *
 * Mirrors customer/backend/handlers/sign-out-handler.php so all four
 * roles behave identically.
 *
 * @package FitPal
 * @version 1.0
>>>>>>> Stashed changes:admin/backend/handlers/sign-out-handler.php
 */

declare(strict_types=1);

<<<<<<< Updated upstream:rider/backend/handlers/sign-out-handler.php
=======
// ===== SESSION START =====
>>>>>>> Stashed changes:admin/backend/handlers/sign-out-handler.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

<<<<<<< Updated upstream:rider/backend/handlers/sign-out-handler.php
=======
// ===== CSRF VALIDATION (optional, non-fatal) =====
>>>>>>> Stashed changes:admin/backend/handlers/sign-out-handler.php
$token         = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
$expectedToken = $_SESSION['csrf_token'] ?? '';

if ($token !== '' && $expectedToken !== '' && !hash_equals($expectedToken, $token)) {
<<<<<<< Updated upstream:rider/backend/handlers/sign-out-handler.php
    error_log('Rider sign-out: invalid CSRF token attempt');
}

unset(
    $_SESSION['delivery_rider_id'],
    $_SESSION['user_role'],
    $_SESSION['user_name'],
    $_SESSION['user_email']
);

session_regenerate_id(true);

=======
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
// unset($_SESSION['csrf_token']);

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
>>>>>>> Stashed changes:admin/backend/handlers/sign-out-handler.php
header('Location: ../../pages/sign-in.php');
exit;