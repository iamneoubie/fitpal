<?php
/**
 * FitPal Customer Login Handler
 *
 * Processes login form submission, validates credentials,
 * and starts a customer session.
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

// ===== SESSION START =====
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ===== INCLUDE DATABASE =====
require_once __DIR__ . '/../../../shared/backend/database/database_connect.php';

// ===== CSRF VALIDATION =====
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    $_SESSION['login_error'] = 'Security validation failed. Please try again.';
    header('Location: ../pages/login.php');
    exit;
}

// ===== INPUT VALIDATION =====
$identifier = trim($_POST['identifier'] ?? '');
$password = $_POST['password'] ?? '';

if (empty($identifier) || empty($password)) {
    $_SESSION['login_error'] = 'Please enter both email/username and password.';
    header('Location: ../pages/login.php');
    exit;
}

// ===== FETCH CUSTOMER =====
try {
    $stmt = $database_connection->prepare(
        "SELECT customer_id, first_name, last_name, email, username, password, is_active
         FROM customer
         WHERE (email = :identifier OR username = :identifier)
         LIMIT 1"
    );
    $stmt->execute([':identifier' => $identifier]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        $_SESSION['login_error'] = 'Invalid email/username or password.';
        header('Location: ../pages/login.php');
        exit;
    }

    // Check if account is active
    if (!$customer['is_active']) {
        $_SESSION['login_error'] = 'Your account is currently disabled. Please contact support.';
        header('Location: ../pages/login.php');
        exit;
    }

    // Verify password
    if (!password_verify($password, $customer['password'])) {
        $_SESSION['login_error'] = 'Invalid email/username or password.';
        header('Location: ../pages/login.php');
        exit;
    }

    // ===== LOGIN SUCCESS =====
    // Regenerate session ID for security
    session_regenerate_id(true);

    // Set customer session variables
    $_SESSION['customer_id'] = (int)$customer['customer_id'];
    $_SESSION['user_role'] = 'customer';
    $_SESSION['user_name'] = $customer['first_name'] . ' ' . $customer['last_name'];
    $_SESSION['user_email'] = $customer['email'];
    $_SESSION['user_username'] = $customer['username'];
    
    // Clear any previous login errors
    unset($_SESSION['login_error']);

    // Redirect
    $redirect = $_POST['redirect'] ?? '';
    if (!empty($redirect) && filter_var($redirect, FILTER_VALIDATE_URL)) {
        header('Location: ' . $redirect);
    } else {
        header('Location: ../pages/dashboard.php');
    }
    exit;

} catch (PDOException $e) {
    error_log('Customer login error: ' . $e->getMessage());
    $_SESSION['login_error'] = 'An unexpected error occurred. Please try again later.';
    header('Location: ../pages/login.php');
    exit;
}