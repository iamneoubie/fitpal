<?php
/**
 * FitPal Admin Action Handler
 *
 * Handles the three admin mutations from the management pages:
 *   - toggle_customer_active
 *   - set_restaurant_status
 *   - set_rider_status
 *
 * All three redirect back to their respective pages with a session
 * flash message. Contains no SQL — all data access is via
 * admin-queries.php.
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['administrator_id'])) {
    $_SESSION['admin_error'] = 'Please sign in to perform this action.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/admin-queries.php';

// ---- CSRF ----
if (
    !isset($_POST['csrf_token'], $_SESSION['csrf_token']) ||
    !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])
) {
    $_SESSION['admin_error'] = 'Security validation failed. Please try again.';
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../../pages/dashboard.php'));
    exit;
}

$adminId = (int)$_SESSION['administrator_id'];
$action  = (string)($_POST['action'] ?? '');

/**
 * Redirect back to the given page with a flash message.
 */
function adminRedirect(string $page, string $type, string $message): never
{
    $_SESSION['admin_' . $type] = $message;
    header('Location: ../../pages/' . $page);
    exit;
}

try {
    switch ($action) {

        case 'toggle_customer_active': {
            $customerId = (int)($_POST['customer_id'] ?? 0);
            $setActive  = (string)($_POST['set_active'] ?? '') === '1';

            if ($customerId <= 0) {
                adminRedirect('users.php', 'error', 'Invalid customer ID.');
            }

            $changed = setCustomerActive($database_connection, $customerId, $setActive);

            if (!$changed) {
                adminRedirect('users.php', 'error', 'No changes were made to this customer.');
            }

            adminRedirect(
                'users.php',
                'success',
                'Customer #' . $customerId . ' ' . ($setActive ? 'activated' : 'deactivated') . '.'
            );
        }

        case 'set_restaurant_status': {
            $restaurantId = (int)($_POST['restaurant_id'] ?? 0);
            $status       = (string)($_POST['verification_status'] ?? '');

            if ($restaurantId <= 0) {
                adminRedirect('restaurants.php', 'error', 'Invalid restaurant ID.');
            }

            $allowed = ['pending', 'verified', 'denied', 'suspended'];
            if (!in_array($status, $allowed, true)) {
                adminRedirect('restaurants.php', 'error', 'Invalid verification status.');
            }

            $changed = setRestaurantVerificationStatus(
                $database_connection,
                $restaurantId,
                $status,
                $adminId
            );

            if (!$changed) {
                adminRedirect('restaurants.php', 'error', 'No changes were made to this restaurant.');
            }

            adminRedirect(
                'restaurants.php',
                'success',
                'Restaurant #' . $restaurantId . ' marked as ' . $status . '.'
            );
        }

        case 'set_rider_status': {
            $riderId = (int)($_POST['rider_id'] ?? 0);
            $status  = (string)($_POST['verification_status'] ?? '');

            if ($riderId <= 0) {
                adminRedirect('riders.php', 'error', 'Invalid rider ID.');
            }

            $allowed = ['pending', 'verified', 'denied', 'suspended'];
            if (!in_array($status, $allowed, true)) {
                adminRedirect('riders.php', 'error', 'Invalid verification status.');
            }

            $changed = setRiderVerificationStatus(
                $database_connection,
                $riderId,
                $status,
                $adminId
            );

            if (!$changed) {
                adminRedirect('riders.php', 'error', 'No changes were made to this rider.');
            }

            adminRedirect(
                'riders.php',
                'success',
                'Rider #' . $riderId . ' marked as ' . $status . '.'
            );
        }

        default:
            adminRedirect('dashboard.php', 'error', 'Invalid action.');
    }

} catch (PDOException $e) {
    error_log('Admin handler DB error: ' . $e->getMessage());
    adminRedirect('dashboard.php', 'error', 'A system error occurred. Please try again.');
} catch (Throwable $e) {
    error_log('Admin handler error: ' . $e->getMessage());
    adminRedirect('dashboard.php', 'error', 'An unexpected error occurred.');
}