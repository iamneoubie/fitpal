<?php
/**
 * FitPal Restaurant Kitchen Order Handler
 *
 * Actions:
 *   start_preparing   — pending → preparing
 *   cancel_order      — pending → cancelled (cancelled_by = 'restaurant')
 *   assign_rider      — first-time rider assignment; moves to rider_pending
 *   reassign_rider    — replace an existing rider; stays in rider_pending
 *
 * Handoff model
 * -------------
 * The kitchen does NOT mark an order delivered, in-transit, or paid.
 * Assigning a rider moves the order to 'rider_pending' and hands
 * control to the rider. The rider confirms in their own portal, at
 * which point the order becomes 'delivering' and later 'delivered'.
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
 * @version 2.0 — Introduces the rider_pending handoff:
 *                  - Removes mark_delivering.
 *                  - assign_rider no longer flips the rider's
 *                    availability.
 *                  - reassign_rider accepts rider_pending orders.
 *                  - Rider availability checks exclude the order
 *                    being edited so the current rider is not
 *                    filtered out during a reassignment.
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

        case 'reassign_rider':
            handleReassignRider($database_connection, $orderId, $branchId);
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

    if ($order['delivery_rider_id'] !== null) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'This order already has a rider. Use Reassign to change it.',
            'field'   => 'already_assigned',
        ]);
        exit;
    }

    if (riderHasActiveDelivery($db, $riderId, $orderId)) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'That rider is currently on another delivery.',
        ]);
        exit;
    }

    $available  = getAvailableRidersForBranch($db, $branchId, $orderId);
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
            'message' => 'That rider is not currently available.',
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
        'status'       => 'success',
        'message'      => 'Rider ' . $riderName . ' notified. Waiting for their confirmation.',
        'order_id'     => $orderId,
        'order_status' => 'rider_pending',
        'rider_id'     => $riderId,
        'rider_name'   => $riderName,
    ]);
    exit;
}

function handleReassignRider(PDO $db, int $orderId, int $branchId): never
{
    $newRiderId = (int)($_POST['rider_id'] ?? 0);

    if ($newRiderId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Please select a rider.']);
        exit;
    }

    $order = requireOwnedOrder($db, $orderId, $branchId);

    if (!in_array($order['order_status'], ['pending', 'preparing', 'rider_pending'], true)) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'This order can no longer be reassigned.',
        ]);
        exit;
    }

    if ($order['delivery_rider_id'] === null) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'This order does not have a rider to reassign.',
            'field'   => 'not_assigned',
        ]);
        exit;
    }

    if ($order['delivery_rider_id'] === $newRiderId) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'That rider is already assigned to this order.',
        ]);
        exit;
    }

    if (riderHasActiveDelivery($db, $newRiderId, $orderId)) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'That rider is currently on another delivery.',
        ]);
        exit;
    }

    $available  = getAvailableRidersForBranch($db, $branchId, $orderId);
    $riderFound = false;
    $riderName  = '';

    foreach ($available as $rider) {
        if ((int)$rider['delivery_rider_id'] === $newRiderId) {
            $riderFound = true;
            $riderName  = trim($rider['first_name'] . ' ' . $rider['last_name']);
            break;
        }
    }

    if (!$riderFound) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'That rider is not currently available.',
        ]);
        exit;
    }

    $result = reassignRiderToOrder($db, $orderId, $branchId, $newRiderId);

    if ($result === false) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Could not reassign the rider. The order may have changed.',
        ]);
        exit;
    }

    $previousRiderName = riderNameById($db, (int)$result['previous_rider_id']);

    echo json_encode([
        'status'              => 'success',
        'message'             => 'Order reassigned from '
                                    . ($previousRiderName !== '' ? $previousRiderName : 'the previous rider')
                                    . ' to ' . $riderName . '.',
        'order_id'            => $orderId,
        'order_status'        => 'rider_pending',
        'previous_rider_id'   => (int)$result['previous_rider_id'],
        'previous_rider_name' => $previousRiderName,
        'rider_id'            => $newRiderId,
        'rider_name'          => $riderName,
    ]);
    exit;
}

/* --------------------------------------------------------------
 * GUARDS AND HELPERS
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

/**
 * Resolve a rider's display name for the reassignment response.
 *
 * This is a formatting helper, not a query. It exists only to name
 * the previous rider in the confirmation message. Failures are
 * silent and return an empty string — the reassignment itself has
 * already succeeded by this point, so a name lookup failure must
 * not surface as an error to the kitchen.
 */
function riderNameById(PDO $db, int $riderId): string
{
    if ($riderId <= 0) {
        return '';
    }

    try {
        $stmt = $db->prepare(
            "SELECT first_name, last_name
               FROM delivery_rider
              WHERE delivery_rider_id = :rider_id
              LIMIT 1"
        );
        $stmt->execute([':rider_id' => $riderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return '';
        }

        return trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
    } catch (Throwable $e) {
        return '';
    }
}