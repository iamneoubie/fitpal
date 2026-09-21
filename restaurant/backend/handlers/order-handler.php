<?php
/**
 * FitPal Restaurant Kitchen Order Handler
 *
 * Actions:
 *   start_preparing  — pending → preparing
 *   cancel_order     — pending → cancelled (cancelled_by = 'restaurant')
 *   assign_rider     — set delivery_rider_id without changing status
 *   mark_delivering  — preparing → delivering (requires an assigned rider)
 *
 * All actions:
 *   - require a signed-in restaurant account
 *   - require a branch-scoped account (manager / staff / kitchen)
 *   - require a valid CSRF token
 *   - verify the order belongs to the account's branch
 *   - verify the order's current status allows the requested transition
 *
 * Responses are always JSON. HTTP 200 carries {status:'error'} for
 * business-rule failures; only missing auth returns 401 and CSRF
 * failures return 403.
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

/* --------------------------------------------------------------
 * AUTHENTICATION
 * -------------------------------------------------------------- */

if (empty($_SESSION['restaurant_account_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$restaurantScope = (string)($_SESSION['restaurant_scope'] ?? '');
$branchId        = !empty($_SESSION['restaurant_branch_id'])
    ? (int)$_SESSION['restaurant_branch_id']
    : 0;
$accountRole     = (string)($_SESSION['restaurant_role'] ?? '');

$allowedRoles = ['manager', 'staff', 'kitchen'];

if ($restaurantScope !== 'branch') {
    echo json_encode([
        'status'  => 'error',
        'message' => 'This action is only available to branch accounts.',
    ]);
    exit;
}

if (!in_array($accountRole, $allowedRoles, true)) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Your role cannot perform kitchen actions.',
    ]);
    exit;
}

if ($branchId <= 0) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'No branch is associated with this account.',
    ]);
    exit;
}

/* --------------------------------------------------------------
 * CSRF
 * -------------------------------------------------------------- */

if (
    !isset($_POST['csrf_token']) ||
    !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$_POST['csrf_token'])
) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
    exit;
}

/* --------------------------------------------------------------
 * ROUTING
 * -------------------------------------------------------------- */

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/order-queries.php';

$action  = (string)($_POST['action'] ?? '');
$orderId = (int)($_POST['order_id'] ?? 0);

try {
    switch ($action) {

        case 'start_preparing':
            handleStartPreparing($database_connection, $orderId, $branchId);
            break;

        case 'cancel_order':
            handleCancelOrder($database_connection, $orderId, $branchId);
            break;

        case 'assign_rider':
            handleAssignRider($database_connection, $orderId, $branchId);
            break;

        case 'mark_delivering':
            handleMarkDelivering($database_connection, $orderId, $branchId);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
            exit;
    }

} catch (PDOException $e) {
    error_log('Kitchen order handler DB error: ' . $e->getMessage());
    echo json_encode([
        'status'  => 'error',
        'message' => 'A system error occurred. Please try again.',
    ]);
    exit;

} catch (Throwable $e) {
    error_log('Kitchen order handler error: ' . $e->getMessage());
    echo json_encode([
        'status'  => 'error',
        'message' => 'A system error occurred. Please try again.',
    ]);
    exit;
}

/* --------------------------------------------------------------
 * HANDLERS
 * -------------------------------------------------------------- */

function handleStartPreparing(PDO $db, int $orderId, int $branchId): never
{
    $order = requireOwnedOrder($db, $orderId, $branchId);

    if ($order['order_status'] !== 'pending') {
        echo json_encode([
            'status'  => 'error',
            'message' => 'This order is no longer pending.',
        ]);
        exit;
    }

    $updated = setOrderPreparing($db, $orderId, $branchId);

    if (!$updated) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Could not start preparing. The order may have already been processed.',
        ]);
        exit;
    }

    echo json_encode([
        'status'       => 'success',
        'message'      => 'Order is now being prepared.',
        'order_id'     => $orderId,
        'order_status' => 'preparing',
    ]);
    exit;
}

function handleCancelOrder(PDO $db, int $orderId, int $branchId): never
{
    $order = requireOwnedOrder($db, $orderId, $branchId);

    if ($order['order_status'] !== 'pending') {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Only pending orders can be cancelled by the kitchen.',
        ]);
        exit;
    }

    $updated = setOrderCancelledByRestaurant($db, $orderId, $branchId);

    if (!$updated) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Could not cancel. The order may have already been processed.',
        ]);
        exit;
    }

    echo json_encode([
        'status'       => 'success',
        'message'      => 'Order cancelled.',
        'order_id'     => $orderId,
        'order_status' => 'cancelled',
    ]);
    exit;
}

function handleAssignRider(PDO $db, int $orderId, int $branchId): never
{
    $riderId = (int)($_POST['rider_id'] ?? 0);

    if ($riderId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Please select a rider.']);
        exit;
    }

    $order = requireOwnedOrder($db, $orderId, $branchId);

    if (!in_array($order['order_status'], ['pending', 'preparing'], true)) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Riders can only be assigned to pending or preparing orders.',
        ]);
        exit;
    }

    $available = getAvailableRidersForBranch($db, $branchId);
    $riderFound = false;
    $riderName  = '';

    foreach ($available as $rider) {
        if ((int)$rider['delivery_rider_id'] === $riderId) {
            $riderFound = true;
            $riderName  = trim($rider['first_name'] . ' ' . $rider['last_name']);
            break;
        }
    }

    if (!$riderFound) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'That rider is no longer available.',
        ]);
        exit;
    }

    $assigned = assignRiderToOrder($db, $orderId, $branchId, $riderId);

    if (!$assigned) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Could not assign the rider. The order may have changed.',
        ]);
        exit;
    }

    echo json_encode([
        'status'      => 'success',
        'message'     => 'Rider ' . $riderName . ' assigned.',
        'order_id'    => $orderId,
        'rider_id'    => $riderId,
        'rider_name'  => $riderName,
    ]);
    exit;
}

function handleMarkDelivering(PDO $db, int $orderId, int $branchId): never
{
    $order = requireOwnedOrder($db, $orderId, $branchId);

    if ($order['order_status'] !== 'preparing') {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Only orders that are being prepared can be handed off.',
        ]);
        exit;
    }

    if (empty($order['delivery_rider_id'])) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Assign a rider before marking this order ready.',
        ]);
        exit;
    }

    $updated = setOrderDelivering($db, $orderId, $branchId);

    if (!$updated) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Could not hand off the order. It may have already been processed.',
        ]);
        exit;
    }

    echo json_encode([
        'status'       => 'success',
        'message'      => 'Order handed off to the rider.',
        'order_id'     => $orderId,
        'order_status' => 'delivering',
    ]);
    exit;
}

/* --------------------------------------------------------------
 * GUARDS
 * -------------------------------------------------------------- */

/**
 * Load the order ownership record for the branch, or emit a JSON
 * error and terminate.
 *
 * @return array{order_id:int, order_status:string, delivery_rider_id:?int, branch_id:int}
 */
function requireOwnedOrder(PDO $db, int $orderId, int $branchId): array
{
    if ($orderId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid order.']);
        exit;
    }

    $order = getKitchenOrderOwnership($db, $orderId, $branchId);

    if ($order === false) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Order not found for this branch.',
        ]);
        exit;
    }

    return $order;
}