<?php
/**
 * FitPal Customer Sign-In Handler
 *
 * Validates customer credentials and establishes session.
 *
 * @package FitPal
 * @version 1.2
 */

declare(strict_types=1);

// ===== SESSION START =====
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ===== INCLUDE DATABASE =====
require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';

// ===== REQUEST VALIDATION =====
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['login_error'] = 'Invalid request method.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

// ===== CSRF VALIDATION =====
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || 
    $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    $_SESSION['login_error'] = 'Security validation failed. Please try again.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

// ===== COLLECT INPUT =====
$identifier = trim($_POST['identifier'] ?? '');
$password = $_POST['password'] ?? '';

// ===== VALIDATE INPUT =====
if (empty($identifier) || empty($password)) {
    $_SESSION['login_error'] = 'Please enter your email/username and password.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

// ===== QUERY DATABASE =====
try {
    $stmt = $database_connection->prepare(
        "SELECT 
            customer_id,
            first_name,
            last_name,
            email,
            username,
            password,
            is_active
        FROM customer 
        WHERE email = :email OR username = :username
        LIMIT 1"
    );
    
    $stmt->execute([
        ':email' => $identifier,
        ':username' => $identifier
    ]);
    
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    // ===== VALIDATE CREDENTIALS =====
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

    /*
     * Password Verification with Development Bypass
     *
     * Normal flow: password_verify() compares plain text against stored hash.
     * Development bypass: allows direct hash-to-hash comparison when APP_ENV is 'development'.
     *
     * WARNING: This bypass is intended for development/testing only.
     * It allows logging in by pasting the hashed password directly into the login form.
     * NEVER enable this in production.
     */
    $isPasswordValid = false;

    // Standard verification - plain text password against stored hash
    if (password_verify($password, $customer['password'])) {
        $isPasswordValid = true;
    }

    /*
     * Development bypass: allow direct hash input
     * 
     * This enables developers to copy the hashed password from the database
     * and paste it directly into the login form during testing.
     * 
     * To enable: set APP_ENV=development in your environment
     * To disable: set APP_ENV=production or remove this block
     */
    $appEnv = getenv('APP_ENV') ?: 'production';
    
    // Debug logging to help identify the issue
    error_log('APP_ENV: ' . $appEnv);
    error_log('Password length: ' . strlen($password));
    error_log('Stored hash length: ' . strlen($customer['password']));
    error_log('Password starts with $2y$: ' . (str_starts_with($password, '$2y$') ? 'yes' : 'no'));
    
    if (!$isPasswordValid && $appEnv === 'development') {
        // Check if the entered password matches the stored hash directly
        // Use hash_equals for timing-safe comparison
        if (hash_equals($customer['password'], $password)) {
            $isPasswordValid = true;
            // Log the bypass for security auditing
            error_log('DEVELOPMENT BYPASS: Hash login used for user: ' . $customer['email']);
        } else {
            // Log the mismatch for debugging
            error_log('DEVELOPMENT BYPASS FAILED: Hash mismatch for user: ' . $customer['email']);
            error_log('  Entered hash: ' . substr($password, 0, 20) . '...');
            error_log('  Stored hash: ' . substr($customer['password'], 0, 20) . '...');
        }
    }

    if (!$isPasswordValid) {
        $_SESSION['login_error'] = 'Invalid email/username or password.';
        header('Location: ../../pages/sign-in.php');
        exit;
    }

    // ===== REGENERATE SESSION ID =====
    session_regenerate_id(true);

    // ===== SET SESSION VARIABLES =====
    $_SESSION['customer_id'] = (int)$customer['customer_id'];
    $_SESSION['user_role'] = 'customer';
    $_SESSION['user_name'] = trim($customer['first_name'] . ' ' . $customer['last_name']);
    $_SESSION['user_email'] = $customer['email'];
    $_SESSION['user_username'] = $customer['username'];
    $_SESSION['created'] = time();

    // Clear CSRF token
    unset($_SESSION['csrf_token']);

    // ===== REDIRECT TO DASHBOARD =====
    // From: /fitpal/customer/backend/handlers/
    // To:   /fitpal/customer/pages/dashboard.php
    header('Location: ../../pages/dashboard.php');
    exit;

} catch (PDOException $e) {
    error_log('Customer sign-in error: ' . $e->getMessage());
    $_SESSION['login_error'] = 'An unexpected error occurred. Please try again.';
    header('Location: ../../pages/sign-in.php');
    exit;
} catch (Exception $e) {
    error_log('Customer sign-in error: ' . $e->getMessage());
    $_SESSION['login_error'] = 'An unexpected error occurred. Please try again.';
    header('Location: ../../pages/sign-in.php');
    exit;
}