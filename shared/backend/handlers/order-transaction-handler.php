<?php
/**
 * FitPal Shared Order-Transaction Handler
 *
 * HTTP entry point for cross-role money-movement actions in the
 * order lifecycle.
 *
 * ---------------------------------------------------------------------
 * ATOMIC ACCEPT — WHO WRITES WHAT
 * ---------------------------------------------------------------------
 * The rider's accept transition (rider_pending → picking_up) and
 * the COD collection row for that order are BOTH written by the
 * caller (rider/backend/handlers/assignment-handler.php and
 * rider/backend/handlers/rider-handler.php), inside the caller's
 * own transaction, via acceptOrder() in rider-assignment-queries.php.
 *
 * The shared handler's `rider_accept_assignment` branch is a
 * verification only — it reads the order state and returns. Nothing
 * is written on this branch. It exists so the caller's forward
 * (used to trigger the failed-delivery sweep) still has a defined
 * response shape.
 *
 * ---------------------------------------------------------------------
 * PICKUP — WHO WRITES WHAT
 * ---------------------------------------------------------------------
 * The pickup transition (picking_up → delivering) is owned by the
 * caller via markOrderPickedUp(). The collection row for a COD
 * order was already written at accept time. This handler's
 * `rider_mark_picked_up` branch is therefore also a verification
 * only.
 *
 * ---------------------------------------------------------------------
 * DELIVERY CREDIT PAIR
 * ---------------------------------------------------------------------
 * `rider_mark_delivered` closes the order and writes the credit
 * pair through creditDeliveryPayouts(). That function also settles
 * the rider_collection row (if any) for the order.
 *
 * ---------------------------------------------------------------------
 * CONTEXT RESOLUTION
 * ---------------------------------------------------------------------
 * A single endpoint serves three roles. The caller forwards its
 * request with an `action` parameter whose name encodes the role:
 *
 *     customer_cancel_order     → customer
 *     restaurant_cancel_order   → restaurant
 *     rider_accept_assignment   → rider
 *     rider_mark_picked_up      → rider
 *     rider_mark_delivered      → rider
 *
 * @package FitPal
 * @version 2.1 — handleRiderAccept() and handleRiderMarkPickedUp()
 *                are now pure verifications. Collection writes
 *                moved to acceptOrder() in the rider query layer.
 *
 *                (2.0: rider collection model — accept no longer
 *                debits, pickup wrote the collection. 1.5: connection
 *                from $GLOBALS with scope-safe fallback. 1.4: require
 *                re-emitted at top. 1.3: self-contained connection.
 *                1.2: context from action. 1.1: bootstrap path fixed.
 *                1.0: initial.)
 */

declare(strict_types=1);

if (!isset($GLOBALS['database_connection']) || !($GLOBALS['database_connection'] instanceof PDO)) {
    (static function (): void {
        require_once __DIR__ . '/../database/database-connect.php';

        if (isset($database_connection) && $database_connection instanceof PDO) {
            $GLOBALS['database_connection'] = $database_connection;
        }
    })();
}

/** @var PDO $database_connection */
$database_connection = $GLOBALS['database_connection'];

$actionContextMap = [
    'customer_cancel_order'   => 'customer',
    'restaurant_cancel_order' => 'restaurant',
    'rider_accept_assignment' => 'rider',
    'rider_mark_picked_up'    => 'rider',
    'rider_mark_delivered'    => 'rider',
];

$action = (string)($_POST['action'] ?? '');

header('Content-Type: application/json; charset=utf-8');

if ($action === '' || !isset($actionContextMap[$action])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    exit;
}

$incomingContext = $actionContextMap[$action];

require_once __DIR__ . '/../../includes/session-bootstrap.php';

fitpal_session_bootstrap($incomingContext);

ob_start();

$role = '';

if ($incomingContext === 'customer' && !empty($_SESSION['customer_id'])) {
    $role = 'customer';
} elseif ($incomingContext === 'restaurant' && !empty($_SESSION['restaurant_account_id'])) {
    $role = 'restaurant';
} elseif ($incomingContext === 'rider' && !empty($_SESSION['delivery_rider_id'])) {
    $role = 'rider';
}

if ($role === '') {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../database/order-transaction-queries.php';

$csrfKeyMap = [
    'customer'   => 'customer_csrf_token',
    'restaurant' => 'restaurant_csrf_token',
    'rider'      => 'rider_csrf_token',
];

$csrfKey    = $csrfKeyMap[$role];
$givenToken = (string)($_POST['csrf_token'] ?? '');
$sessToken  = (string)($_SESSION[$csrfKey] ?? '');

if (
    $sessToken === ''
    || $givenToken === ''
    || !hash_equals($sessToken, $givenToken)
) {
    unset($_SESSION[$csrfKey]);

    ob_end_clean();
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
    exit;
}

try {
    sweepFailedDeliveries($database_connection);
} catch (Throwable $sweepError) {
    error_log('Order-transaction sweep error: ' . $sweepError->getMessage());
}

$response = ['status' => 'error', 'message' => 'Invalid action'];

try {
    switch ($action) {

        case 'customer_cancel_order':
            if ($role !== 'customer') {
                $response = ['status' => 'error', 'message' => 'Action is not available for this role.'];
                break;
            }
            $response = handleCustomerCancel($database_connection);
            break;

        case 'restaurant_cancel_order':
            if ($role !== 'restaurant') {
                $response = ['status' => 'error', 'message' => 'Action is not available for this role.'];
                break;
            }
            $response = handleRestaurantCancel($database_connection);
            break;

        case 'rider_accept_assignment':
            if ($role !== 'rider') {
                $response = ['status' => 'error', 'message' => 'Action is not available for this role.'];
                break;
            }
            $response = handleRiderAccept($database_connection);
            break;

        case 'rider_mark_picked_up':
            if ($role !== 'rider') {
                $response = ['status' => 'error', 'message' => 'Action is not available for this role.'];
                break;
            }
            $response = handleRiderMarkPickedUp($database_connection);
            break;

        case 'rider_mark_delivered':
            if ($role !== 'rider') {
                $response = ['status' => 'error', 'message' => 'Action is not available for this role.'];
                break;
            }
            $response = handleRiderMarkDelivered($database_connection);
            break;
    }
} catch (PDOException $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Order-transaction handler DB error: ' . $e->getMessage());
    $response = ['status' => 'error', 'message' => 'A database error occurred. Please try again.'];
} catch (RuntimeException $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    $response = ['status' => 'error', 'message' => $e->getMessage()];
} catch (Throwable $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Order-transaction handler error: ' . $e->getMessage());
    $response = ['status' => 'error', 'message' => 'An unexpected error occurred.'];
}

ob_end_clean();
echo json_encode($response);
exit;

/* =============================================================
 * HANDLERS
 * ============================================================= */

function handleCustomerCancel(PDO $db): array
{
    $customerId = (int)$_SESSION['customer_id'];
    $orderId    = (int)($_POST['order_id'] ?? 0);

    if ($orderId <= 0) {
        return ['status' => 'error', 'message' => 'Invalid order ID'];
    }

    $order = getOrderOwnership($db, $orderId, $customerId);
    if (!$order) {
        return ['status' => 'error', 'message' => 'Order not found'];
    }

    if ($order['order_status'] !== 'pending') {
        return [
            'status'  => 'error',
            'message' => 'This order has already been accepted by the kitchen and can no longer be cancelled. Please contact support if you need help.',
        ];
    }

    $paymentMethod  = $order['payment_method'];
    $requiresRefund = in_array($paymentMethod, ['Wallet', 'Online'], true);

    $finalStatus = $requiresRefund ? 'refunded' : 'cancelled';

    $db->beginTransaction();

    try {
        $cancelled = cancelOrderAsCustomer($db, $orderId, $customerId, $finalStatus);

        if (!$cancelled) {
            $db->rollBack();
            return [
                'status'  => 'error',
                'message' => 'Could not cancel order. It may have already been processed.',
            ];
        }

        $refunded = false;
        if ($requiresRefund) {
            $refunded = refundOrderToWallet($db, $orderId);
        } else {
            refundOrderToWallet($db, $orderId);
        }

        $db->commit();

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    $message = match (true) {
        !$requiresRefund        => 'Order cancelled successfully',
        $refunded               => 'Order cancelled and refund issued to your wallet',
        default                 => 'Order cancelled',
    };

    return [
        'status'       => 'success',
        'message'      => $message,
        'order_id'     => $orderId,
        'order_status' => $finalStatus,
        'refunded'     => $refunded,
    ];
}

function handleRestaurantCancel(PDO $db): array
{
    $branchId = !empty($_SESSION['restaurant_branch_id'])
        ? (int)$_SESSION['restaurant_branch_id']
        : 0;
    $orderId  = (int)($_POST['order_id'] ?? 0);

    if ($branchId <= 0) {
        return ['status' => 'error', 'message' => 'No branch is associated with this account.'];
    }
    if ($orderId <= 0) {
        return ['status' => 'error', 'message' => 'Invalid order ID'];
    }

    $order = getRestaurantOrderOwnership($db, $orderId, $branchId);
    if (!$order) {
        return ['status' => 'error', 'message' => 'Order not found for this branch.'];
    }

    if (!in_array($order['order_status'], ['pending', 'preparing'], true)) {
        return [
            'status'  => 'error',
            'message' => 'Only pending or preparing orders can be cancelled by the kitchen. Once a rider has been assigned, the order is out of the kitchen\'s hands.',
        ];
    }

    $db->beginTransaction();

    try {
        $newStatus = cancelOrderAsRestaurant($db, $orderId, $branchId);

        if ($newStatus === false) {
            $db->rollBack();
            return [
                'status'  => 'error',
                'message' => 'Could not cancel. The order may have already been processed.',
            ];
        }

        if ($newStatus === 'refunded') {
            refundOrderToWallet($db, $orderId);
        }

        $db->commit();

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    $message = ($newStatus === 'refunded')
        ? 'Order cancelled and refunded.'
        : 'Order cancelled.';

    return [
        'status'       => 'success',
        'message'      => $message,
        'order_id'     => $orderId,
        'order_status' => $newStatus,
    ];
}

/**
 * Rider accept — verification only.
 *
 * The transition and the COD collection write are performed by the
 * caller via acceptOrder(). This branch confirms the resulting
 * order state so the client's response is honest.
 *
 * @param PDO $db
 * @return array<string, mixed>
 */
function handleRiderAccept(PDO $db): array
{
    $riderId = (int)$_SESSION['delivery_rider_id'];
    $orderId = (int)($_POST['order_id'] ?? 0);

    if ($orderId <= 0) {
        return ['status' => 'error', 'message' => 'Invalid order ID'];
    }

    $check = $db->prepare(
        "SELECT order_status
           FROM orders
          WHERE order_id = :order_id
            AND delivery_rider_id = :rider_id
          LIMIT 1"
    );
    $check->execute([
        ':order_id' => $orderId,
        ':rider_id' => $riderId,
    ]);
    $status = $check->fetchColumn();

    if ($status === false) {
        return ['status' => 'error', 'message' => 'This assignment is no longer available.'];
    }

    if (!in_array((string)$status, ['picking_up', 'delivering'], true)) {
        return [
            'status'  => 'error',
            'message' => 'This assignment has not been accepted yet.',
        ];
    }

    return [
        'status'   => 'success',
        'message'  => 'Assignment accepted.',
        'order_id' => $orderId,
    ];
}

/**
 * Rider pickup — verification only.
 *
 * The picking_up → delivering transition was performed by the
 * caller via markOrderPickedUp(). The COD collection row was
 * already written by acceptOrder() at accept time. Nothing is
 * written here.
 *
 * @param PDO $db
 * @return array<string, mixed>
 */
function handleRiderMarkPickedUp(PDO $db): array
{
    $riderId = (int)$_SESSION['delivery_rider_id'];
    $orderId = (int)($_POST['order_id'] ?? 0);

    if ($orderId <= 0) {
        return ['status' => 'error', 'message' => 'Invalid order ID'];
    }

    $check = $db->prepare(
        "SELECT order_status
           FROM orders
          WHERE order_id = :order_id
            AND delivery_rider_id = :rider_id
          LIMIT 1"
    );
    $check->execute([
        ':order_id' => $orderId,
        ':rider_id' => $riderId,
    ]);
    $status = $check->fetchColumn();

    if ($status === false) {
        return ['status' => 'error', 'message' => 'Order not found.'];
    }

    if (!in_array((string)$status, ['delivering', 'delivered'], true)) {
        return [
            'status'  => 'error',
            'message' => 'Order is not yet in transit. Confirm the pickup step first.',
        ];
    }

    return [
        'status'   => 'success',
        'message'  => 'Order picked up.',
        'order_id' => $orderId,
    ];
}

function handleRiderMarkDelivered(PDO $db): array
{
    $riderId = (int)$_SESSION['delivery_rider_id'];
    $orderId = (int)($_POST['order_id'] ?? 0);

    if ($orderId <= 0) {
        return ['status' => 'error', 'message' => 'Invalid order ID'];
    }

    $db->beginTransaction();

    try {
        $check = $db->prepare(
            "SELECT order_id
               FROM orders
              WHERE order_id = :order_id
                AND delivery_rider_id = :rider_id
                AND order_status = 'delivering'
              LIMIT 1
              FOR UPDATE"
        );
        $check->execute([
            ':order_id' => $orderId,
            ':rider_id' => $riderId,
        ]);

        if ($check->fetchColumn() === false) {
            $db->rollBack();
            return [
                'status'  => 'error',
                'message' => 'Order is not eligible to be marked delivered. Confirm the pickup step first.',
            ];
        }

        $update = $db->prepare(
            "UPDATE orders
                SET order_status = 'delivered',
                    delivered_at = NOW(),
                    updated_at   = NOW()
              WHERE order_id = :order_id
                AND delivery_rider_id = :rider_id
                AND order_status = 'delivering'"
        );
        $update->execute([
            ':order_id' => $orderId,
            ':rider_id' => $riderId,
        ]);

        if ($update->rowCount() === 0) {
            $db->rollBack();
            return ['status' => 'error', 'message' => 'Could not complete the delivery. Please try again.'];
        }

        creditDeliveryPayouts($db, $riderId, $orderId);

        $db->commit();

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return [
        'status'   => 'success',
        'message'  => 'Delivery marked as complete. Your earnings have been credited.',
        'order_id' => $orderId,
    ];
}