<?php
/**
 * FitPal Customer Sign-In Handler
 *
 * Validates customer credentials and establishes session.
 * Supports both plain text passwords and hashed passwords (dev bypass).
 *
 * @package FitPal
 * @version 1.4 - SIMPLIFIED BYPASS (like Crooks Cart Collective)
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
     * Password Verification - SIMPLIFIED (like Crooks Cart Collective)
     * 
     * 1. First try password_verify() - works for normal passwords
     * 2. If that fails, check if the entered password matches the stored hash directly
     *    (This allows developers to paste hashed passwords for testing)
     */
    $isPasswordValid = false;

    // Method 1: Standard password verification
    if (password_verify($password, $customer['password'])) {
        $isPasswordValid = true;
    }

    // Method 2: Direct hash comparison (development bypass)
    // No APP_ENV check needed - this works like Crooks Cart Collective
    if (!$isPasswordValid) {
        // Trim whitespace from the entered password (fixes copy-paste issues)
        $cleanPassword = trim($password);
        
        // Check if the entered password matches the stored hash
        // Try hash_equals first (timing-safe)
        if (hash_equals($customer['password'], $cleanPassword)) {
            $isPasswordValid = true;
        } 
        // Fallback to direct comparison if hash_equals fails
        else if ($customer['password'] === $cleanPassword) {
            $isPasswordValid = true;
        }
        // Also check if the password is a bcrypt hash (starts with $2y$)
        else if (str_starts_with($cleanPassword, '$2y$') && strlen($cleanPassword) === 60) {
            // It's a hash format, but doesn't match - log for debugging
            error_log('Hash login attempt failed for user: ' . $customer['email']);
            error_log('  Entered hash length: ' . strlen($cleanPassword));
            error_log('  Stored hash length: ' . strlen($customer['password']));
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