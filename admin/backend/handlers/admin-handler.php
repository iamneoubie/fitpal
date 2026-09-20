<?php
/**
 * FitPal Admin Verification Handler
 *
 * POST endpoint for restaurant and rider verification decisions.
 *
 * Actions:
 *   verify_restaurant  → set verification_status on a restaurant
 *   verify_rider       → set verification_status on a delivery_rider_profile
 *
 * Both actions accept a `status` of: pending | verified | denied | suspended.
 *
 * Contains NO SQL. All data access goes through admin-queries.php.
 *
 * Response convention (matches customer handlers):
 *   - Non-AJAX: session flash + redirect back to the referring list
 *   - AJAX:     JSON with {status, message}
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isAjax = (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
);

/**
 * Terminate the request with an error response.
 *
 * @param string $message
 * @param bool   $isAjax
 * @param string $redirect
 * @return never
 */
function adminFail(string $message, bool $isAjax, string $redirect = '../../pages/dashboard.php'): never
{
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => $message]);
        exit;
    }
    $_SESSION['admin_error'] = $message;
    header('Location: ' . $redirect);
    exit;
}

/**
 * Terminate the request with a success response.
 *
 * @param string $message
 * @param bool   $isAjax
 * @param string $redirect
 * @return never
 */
function adminSuccess(string $message, bool $isAjax, string $redirect = '../../pages/dashboard.php'): never
{
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'success', 'message' => $message]);
        exit;
    }
    $_SESSION['admin_success'] = $message;
    header('Location: ' . $redirect);
    exit;
}

// ---- Auth ----
if (empty($_SESSION['administrator_id'])) {
    adminFail('Please sign in to continue.', $isAjax, '../../pages/sign-in.php');
}

$adminId = (int)$_SESSION['administrator_id'];

$givenToken = (string)($_POST['csrf_token'] ?? '');
$sessToken  = (string)($_SESSION['csrf_token'] ?? '');

if ($sessToken === '' || $givenToken === '' || !hash_equals($sessToken, $givenToken)) {
    adminFail('Security validation failed. Please try again.', $isAjax);
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/admin-queries.php';

$action = (string)($_POST['action'] ?? '');
$status = (string)($_POST['status'] ?? '');

if ($action === '') {
    adminFail('Missing action.', $isAjax);
}

$allowedStatuses = ['pending', 'verified', 'denied', 'suspended'];

if (!in_array($status, $allowedStatuses, true)) {
    adminFail('Invalid status value.', $isAjax);
}

try {
    switch ($action) {

        case 'verify_restaurant': {
            $restaurantId = (int)($_POST['restaurant_id'] ?? 0);
            if ($restaurantId <= 0) {
                adminFail('Invalid restaurant ID.', $isAjax, '../../pages/restaurants.php');
            }

            // Ownership check: confirm the restaurant exists before writing.
            $restaurant = getRestaurantForReview($database_connection, $restaurantId);
            if (!$restaurant) {
                adminFail('Restaurant not found.', $isAjax, '../../pages/restaurants.php');
            }

            $updated = setRestaurantVerificationStatus(
                $database_connection,
                $restaurantId,
                $adminId,
                $status
            );

            if (!$updated) {
                adminFail('Could not update verification status.', $isAjax, '../../pages/restaurants.php');
            }

            $label = formatVerificationStatus($status);
            adminSuccess(
                'Restaurant marked as ' . $label . '.',
                $isAjax,
                '../../pages/restaurants.php'
            );
        }

        case 'verify_rider': {
            $riderId = (int)($_POST['rider_id'] ?? 0);
            if ($riderId <= 0) {
                adminFail('Invalid rider ID.', $isAjax, '../../pages/riders.php');
            }

            $rider = getRiderForReview($database_connection, $riderId);
            if (!$rider) {
                adminFail('Rider not found.', $isAjax, '../../pages/riders.php');
            }

            $updated = setRiderVerificationStatus(
                $database_connection,
                $riderId,
                $adminId,
                $status
            );

            if (!$updated) {
                adminFail('Could not update verification status.', $isAjax, '../../pages/riders.php');
            }

            $label = formatVerificationStatus($status);
            adminSuccess(
                'Rider marked as ' . $label . '.',
                $isAjax,
                '../../pages/riders.php'
            );
        }

        default:
            adminFail('Invalid action.', $isAjax);
    }

} catch (PDOException $e) {
    error_log('Admin handler DB error: ' . $e->getMessage());
    adminFail('A system error occurred. Please try again.', $isAjax);
} catch (Throwable $e) {
    error_log('Admin handler error: ' . $e->getMessage());
    adminFail('A system error occurred. Please try again.', $isAjax);
}

// Inside your switch ($action) block in admin-handler.php

case 'verify_restaurant_bulk': {
    $ids = $_POST['restaurant_ids'] ?? [];
    if (!is_array($ids) || empty($ids)) {
        adminFail('No restaurants selected.', $isAjax, '../../pages/restaurants.php');
    }
    
    $updatedCount = 0;
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $updated = setRestaurantVerificationStatus(
                $database_connection,
                $id,
                $adminId,
                $status
            );
            if ($updated) $updatedCount++;
        }
    }
    
    $label = formatVerificationStatus($status);
    adminSuccess(
        "{$updatedCount} restaurant(s) marked as {$label}.",
        $isAjax,
        '../../pages/restaurants.php?status=' . urlencode($status)
    );
}

case 'verify_rider_bulk': {
    $ids = $_POST['rider_ids'] ?? [];
    if (!is_array($ids) || empty($ids)) {
        adminFail('No riders selected.', $isAjax, '../../pages/riders.php');
    }
    
    $updatedCount = 0;
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $updated = setRiderVerificationStatus(
                $database_connection,
                $id,
                $adminId,
                $status
            );
            if ($updated) $updatedCount++;
        }
    }
    
    $label = formatVerificationStatus($status);
    adminSuccess(
        "{$updatedCount} rider(s) marked as {$label}.",
        $isAjax,
        '../../pages/riders.php?status=' . urlencode($status)
    );
}