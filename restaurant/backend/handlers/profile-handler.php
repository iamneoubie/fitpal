<?php
/**
 * FitPal Restaurant Profile Handler
 *
 * Actions:
 *   update_contact   — contact number (any account)
 *   update_business  — restaurant-level info (owner only)
 *   update_branch    — branch address (owner only)
 *   change_password  — password change (any account)
 *
 * Responds with JSON.
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (empty($_SESSION['restaurant_account_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/restaurant-queries.php';

if (
    !isset($_POST['csrf_token']) ||
    !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$_POST['csrf_token'])
) {
    echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
    exit;
}

$accountId    = (int)$_SESSION['restaurant_account_id'];
$restaurantId = (int)($_SESSION['restaurant_id'] ?? 0);
$branchId     = !empty($_SESSION['restaurant_branch_id'])
    ? (int)$_SESSION['restaurant_branch_id']
    : 0;
$scope        = (string)($_SESSION['restaurant_scope'] ?? 'owner');

$action = (string)($_POST['action'] ?? '');

try {
    switch ($action) {

        case 'update_contact': {
            $contact = trim((string)($_POST['contact_number'] ?? ''));

            if ($contact !== '' && !preg_match('/^09\d{9}$/', $contact)) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'Enter a valid PH mobile number (09XXXXXXXXX).',
                    'field'   => 'contact_number',
                ]);
                exit;
            }

            updateRestaurantAccountContact($database_connection, $accountId, $contact);

            $_SESSION['user_contact'] = $contact;

            echo json_encode([
                'status'  => 'success',
                'message' => 'Contact number updated.',
            ]);
            exit;
        }

        case 'update_business': {
            if ($scope !== 'owner') {
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'Only owners can edit business information.',
                ]);
                exit;
            }

            $description = trim((string)($_POST['description'] ?? ''));
            $cuisine     = trim((string)($_POST['cuisine_type'] ?? ''));
            $tags        = trim((string)($_POST['dietary_tags'] ?? ''));

            if (strlen($description) > 2000) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'Description is too long (max 2000 characters).',
                    'field'   => 'description',
                ]);
                exit;
            }
            if ($cuisine !== '' && strlen($cuisine) < 2) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'Cuisine type must be at least 2 characters.',
                    'field'   => 'cuisine_type',
                ]);
                exit;
            }

            updateRestaurantBusinessInfo($database_connection, $restaurantId, [
                'description'  => $description,
                'cuisine_type' => $cuisine,
                'dietary_tags' => $tags,
            ]);

            echo json_encode([
                'status'  => 'success',
                'message' => 'Business information updated.',
            ]);
            exit;
        }

        case 'update_branch': {
            if ($scope !== 'owner') {
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'Only owners can edit branch address.',
                ]);
                exit;
            }
            if ($branchId <= 0) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'No branch selected.',
                ]);
                exit;
            }

            $block      = trim((string)($_POST['block'] ?? ''));
            $barangay   = trim((string)($_POST['barangay'] ?? ''));
            $city       = trim((string)($_POST['city'] ?? ''));
            $province   = trim((string)($_POST['province'] ?? ''));
            $region     = trim((string)($_POST['region'] ?? ''));
            $postalCode = trim((string)($_POST['postal_code'] ?? ''));

            if ($block === '') {
                echo json_encode(['status' => 'error', 'message' => 'Block / Street is required.', 'field' => 'block']);
                exit;
            }
            if ($city === '') {
                echo json_encode(['status' => 'error', 'message' => 'City is required.', 'field' => 'city']);
                exit;
            }
            if ($postalCode !== '' && !preg_match('/^[0-9]{3,10}$/', $postalCode)) {
                echo json_encode(['status' => 'error', 'message' => 'Postal code must be numeric.', 'field' => 'postal_code']);
                exit;
            }

            updateRestaurantBranchAddress($database_connection, $branchId, [
                'block'       => $block,
                'barangay'    => $barangay,
                'city'        => $city,
                'province'    => $province,
                'region'      => $region,
                'postal_code' => $postalCode,
            ]);

            echo json_encode([
                'status'  => 'success',
                'message' => 'Branch address updated.',
            ]);
            exit;
        }

        case 'change_password': {
            $current = (string)($_POST['current_password'] ?? '');
            $new     = (string)($_POST['new_password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');

            if ($current === '' || $new === '' || $confirm === '') {
                echo json_encode(['status' => 'error', 'message' => 'All password fields are required.']);
                exit;
            }

            if (strlen($new) < 8 || strlen($new) > 20) {
                echo json_encode(['status' => 'error', 'message' => 'New password must be 8–20 characters.', 'field' => 'new_password']);
                exit;
            }
            if (!preg_match('/^[A-Za-z0-9]+$/', $new)) {
                echo json_encode(['status' => 'error', 'message' => 'Password can only contain letters and numbers.', 'field' => 'new_password']);
                exit;
            }
            if (!preg_match('/[A-Za-z]/', $new) || !preg_match('/[0-9]/', $new)) {
                echo json_encode(['status' => 'error', 'message' => 'Password must contain at least one letter and one number.', 'field' => 'new_password']);
                exit;
            }
            if ($new !== $confirm) {
                echo json_encode(['status' => 'error', 'message' => 'Passwords do not match.', 'field' => 'confirm_password']);
                exit;
            }

            $row = getRestaurantAccountWithPassword($database_connection, $accountId);
            if (!$row) {
                echo json_encode(['status' => 'error', 'message' => 'Account not found.']);
                exit;
            }

            $stored = (string)$row['password'];

            $valid = password_verify($current, $stored);
            if (!$valid && hash_equals($stored, $current)) {
                // DEV-ONLY bypass for plaintext seeds. Remove before deploying.
                $valid = true;
            }

            if (!$valid) {
                echo json_encode(['status' => 'error', 'message' => 'Current password is incorrect.', 'field' => 'current_password']);
                exit;
            }

            updateRestaurantAccountPassword(
                $database_connection,
                $accountId,
                password_hash($new, PASSWORD_BCRYPT)
            );

            echo json_encode([
                'status'  => 'success',
                'message' => 'Password changed.',
            ]);
            exit;
        }

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
            exit;
    }

} catch (PDOException $e) {
    error_log('Restaurant profile handler DB error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A system error occurred.']);
} catch (Throwable $e) {
    error_log('Restaurant profile handler error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'An unexpected error occurred.']);
}