<?php
/**
 * FitPal Admin Handler
 *
 * All admin mutations go through this single endpoint. Actions are
 * dispatched by the `action` POST field. Each action verifies CSRF,
 * calls a query-layer function, then flashes a message and redirects.
 *
 * Response shape: HTML redirect with a session flash. There is no
 * JSON API for mutations because every mutation originates from a
 * normal form submit and every page already renders flashes. AJAX is
 * unnecessary here.
 *
 * This file contains NO SQL. Every read and write goes through
 * admin-queries.php.
 *
 * @package FitPal
 * @version 2.0 — Removed the inline SELECT in handleChangePassword();
 *                it now calls getAdminPasswordHash() from the query
 *                layer.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/admin-queries.php';

if (empty($_SESSION['administrator_id'])) {
    header('Location: ../../pages/sign-in.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../pages/dashboard.php');
    exit;
}

if (!isset($_POST['csrf_token'], $_SESSION['csrf_token'])
    || !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
    $_SESSION['admin_error'] = 'Security validation failed. Please try again.';
    header('Location: ../../pages/dashboard.php');
    exit;
}

$adminId = (int)$_SESSION['administrator_id'];
$action  = (string)($_POST['action'] ?? '');
$redirect = (string)($_POST['redirect_to'] ?? 'dashboard.php');

// Whitelist redirect destinations so the field cannot be abused.
$allowedRedirects = [
    'dashboard.php', 'customers.php', 'riders.php', 'restaurants.php', 'profile.php',
];
if (!in_array($redirect, $allowedRedirects, true)) {
    $redirect = 'dashboard.php';
}

$redirectUrl = '../../pages/' . $redirect;

try {
    switch ($action) {

        case 'update_profile':
            handleUpdateProfile($database_connection, $adminId);
            $_SESSION['admin_success'] = 'Profile updated successfully.';
            break;

        case 'change_password':
            handleChangePassword($database_connection, $adminId);
            $_SESSION['admin_success'] = 'Password changed successfully.';
            break;

        case 'toggle_customer':
            handleToggleCustomer($database_connection);
            $_SESSION['admin_success'] = 'Customer status updated.';
            break;

        case 'set_rider_verification':
            handleSetRiderVerification($database_connection, $adminId);
            $_SESSION['admin_success'] = 'Rider verification status updated.';
            break;

        case 'toggle_rider':
            handleToggleRider($database_connection);
            $_SESSION['admin_success'] = 'Rider status updated.';
            break;

        case 'set_restaurant_verification':
            handleSetRestaurantVerification($database_connection, $adminId);
            $_SESSION['admin_success'] = 'Restaurant verification status updated.';
            break;

        case 'toggle_restaurant':
            handleToggleRestaurant($database_connection);
            $_SESSION['admin_success'] = 'Restaurant status updated.';
            break;

        default:
            $_SESSION['admin_error'] = 'Unknown action.';
    }
} catch (PDOException $e) {
    error_log('Admin handler DB error: ' . $e->getMessage());
    $_SESSION['admin_error'] = 'A database error occurred. Please try again.';
} catch (RuntimeException $e) {
    $_SESSION['admin_error'] = $e->getMessage();
} catch (Throwable $e) {
    error_log('Admin handler error: ' . $e->getMessage());
    $_SESSION['admin_error'] = 'An unexpected error occurred.';
}

header('Location: ' . $redirectUrl);
exit;

/* =============================================================
 * ACTION HANDLERS
 * ============================================================= */

function handleUpdateProfile(PDO $db, int $adminId): void
{
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $middleName = trim((string)($_POST['middle_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    $contact = trim((string)($_POST['contact_number'] ?? ''));

    if (strlen($firstName) < 2 || strlen($lastName) < 2) {
        throw new RuntimeException('First and last name must be at least 2 characters.');
    }

    if ($contact !== '' && !preg_match('/^09\d{9}$/', $contact)) {
        throw new RuntimeException('Contact number must be a valid PH mobile (09XXXXXXXXX).');
    }

    updateAdminProfile($db, $adminId, $firstName, $middleName, $lastName, $contact);
}

function handleChangePassword(PDO $db, int $adminId): void
{
    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if (strlen($new) < 8 || strlen($new) > 20) {
        throw new RuntimeException('New password must be 8–20 characters.');
    }
    if (!preg_match('/^[A-Za-z0-9]+$/', $new)) {
        throw new RuntimeException('Password can only contain letters and numbers.');
    }
    if (!preg_match('/[A-Za-z]/', $new) || !preg_match('/[0-9]/', $new)) {
        throw new RuntimeException('Password must contain at least one letter and one number.');
    }
    if ($new !== $confirm) {
        throw new RuntimeException('New password and confirmation do not match.');
    }

    $stored = getAdminPasswordHash($db, $adminId);

    $valid = password_verify($current, $stored);
    if (!$valid && hash_equals($stored, $current)) {
        $valid = true;
    }
    if (!$valid) {
        throw new RuntimeException('Current password is incorrect.');
    }

    $hashed = password_hash($new, PASSWORD_BCRYPT);
    updateAdminPassword($db, $adminId, $hashed);
}

function handleToggleCustomer(PDO $db): void
{
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $activate   = (string)($_POST['activate'] ?? '') === '1';
    if ($customerId <= 0) {
        throw new RuntimeException('Invalid customer.');
    }
    setCustomerActiveStatus($db, $customerId, $activate);
}

function handleSetRiderVerification(PDO $db, int $adminId): void
{
    $riderId = (int)($_POST['rider_id'] ?? 0);
    $status  = (string)($_POST['status'] ?? '');
    if ($riderId <= 0) {
        throw new RuntimeException('Invalid rider.');
    }
    if (!setRiderVerificationStatus($db, $riderId, $status, $adminId)) {
        throw new RuntimeException('Invalid verification status.');
    }
}

function handleToggleRider(PDO $db): void
{
    $riderId  = (int)($_POST['rider_id'] ?? 0);
    $activate = (string)($_POST['activate'] ?? '') === '1';
    if ($riderId <= 0) {
        throw new RuntimeException('Invalid rider.');
    }
    setRiderActiveStatus($db, $riderId, $activate);
}

function handleSetRestaurantVerification(PDO $db, int $adminId): void
{
    $restaurantId = (int)($_POST['restaurant_id'] ?? 0);
    $status       = (string)($_POST['status'] ?? '');
    if ($restaurantId <= 0) {
        throw new RuntimeException('Invalid restaurant.');
    }
    if (!setRestaurantVerificationStatus($db, $restaurantId, $status, $adminId)) {
        throw new RuntimeException('Invalid verification status.');
    }
}

function handleToggleRestaurant(PDO $db): void
{
    $restaurantId = (int)($_POST['restaurant_id'] ?? 0);
    $activate     = (string)($_POST['activate'] ?? '') === '1';
    if ($restaurantId <= 0) {
        throw new RuntimeException('Invalid restaurant.');
    }
    setRestaurantActiveStatus($db, $restaurantId, $activate);
}