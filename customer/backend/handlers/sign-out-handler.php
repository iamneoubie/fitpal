<?php
/**
 * FitPal Customer Sign-Out Handler
 *
 * This handler clears ONLY customer session data.
 * It does NOT call session_destroy() to preserve other role sessions
 * (admin, restaurant, rider) that may be active in the same browser.
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

// ===== SESSION START =====
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ===== CSRF VALIDATION (optional) =====
$token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
$expectedToken = $_SESSION['csrf_token'] ?? '';

if (!empty($token) && !empty($expectedToken)) {
    if (!hash_equals($expectedToken, $token)) {
        error_log('Customer sign-out: Invalid CSRF token attempt');
    }
}

// ===== CLEAR ONLY CUSTOMER SESSION DATA =====
unset($_SESSION['customer_id']);
unset($_SESSION['user_role']);
unset($_SESSION['user_name']);
unset($_SESSION['user_email']);
unset($_SESSION['user_username']);

// Do NOT unset these as they may belong to other roles:
// - $_SESSION['administrator_id']
// - $_SESSION['restaurant_id']
// - $_SESSION['delivery_rider_id']

// Keep CSRF token for other forms
// unset($_SESSION['csrf_token']);

// ===== REGENERATE SESSION ID =====
session_regenerate_id(true);

// ===== FIXED: REDIRECT TO SIGN-IN =====
// From: /fitpal/customer/backend/handlers/
// To:   /fitpal/customer/pages/sign-in.php
$redirect = $_POST['redirect'] ?? $_GET['redirect'] ?? '';

if (!empty($redirect)) {
    if (strpos($redirect, '..') === false && strpos($redirect, '/') !== 0) {
        header('Location: ' . htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8'));
        exit;
    }
}

header('Location: ../../pages/sign-in.php');
exit;