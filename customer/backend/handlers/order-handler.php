<?php
/**
 * FitPal Customer Order Handler
 *
 * Handles customer-initiated order actions.
 *
 * Actions:
 *   cancel_order      → cancel a pending/preparing order. Issues a
 *                       refund when the original payment method was
 *                       Wallet or Online and a payment was recorded.
 *                       COD orders are cancelled with no refund.
 *                       Legacy orders (no payment on record) cancel
 *                       cleanly with no refund.
 *   get_order_details → return an order as JSON
 *
 * @package FitPal
 * @version 3.0 — Legacy-order cancels no longer surface as errors;
 *                response includes the final order_status.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$customerId = (int)$_SESSION['customer_id'];

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/order-queries.php';

$csrfToken = $_POST['csrf_token'] ?? '';
if (empty($csrfToken) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'cancel_order':
            handleCancelOrder($database_connection, $customerId);
            break;

        case 'get_order_details':
            handleGetOrderDetails($database_connection, $customerId);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
} catch (PDOException $e) {
    error_log('Order handler DB error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error occurred']);
} catch (RuntimeException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

// =====================================================
// HANDLERS
// =====================================================

/**
 * Cancel an order (customer-initiated).
 *
 * Wallet and Online orders move to 'refunded'. A refund transaction
 * is issued when a payment was on record; legacy orders with no
 * recorded payment cancel cleanly with no refund.
 *
 * COD orders move to 'cancelled'. Any pending payment transaction is
 * marked failed by refundOrderToWallet().
 */
function handleCancelOrder(PDO $db, int $customerId): void
{
    $orderId = (int)($_POST['order_id'] ?? 0);

    if ($orderId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid order ID']);
        return;
    }

    // ---- Verify ownership and cancellable state ----
    $stmt = $db->prepare(
        "SELECT order_id, order_status, payment_method
           FROM orders
          WHERE order_id = :order_id
            AND customer_id = :customer_id"
    );
    $stmt->execute([
        ':order_id'    => $orderId,
        ':customer_id' => $customerId,
    ]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(['status' => 'error', 'message' => 'Order not found']);
        return;
    }

    $cancellableStatuses = ['pending', 'preparing'];
    if (!in_array($order['order_status'], $cancellableStatuses, true)) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'This order cannot be cancelled at this stage',
        ]);
        return;
    }

    $paymentMethod  = (string)$order['payment_method'];
    $requiresRefund = in_array($paymentMethod, ['Wallet', 'Online'], true);

    // -----------------------------------------------------------
    // Cancel the order first.
    //
    // refundOrderToWallet() opens its own transaction, so we can't
    // nest it inside ours. Committing the cancel first means a
    // failed refund leaves the order correctly cancelled — support
    // can retry the refund without re-cancelling.
    // -----------------------------------------------------------
    $finalStatus = $requiresRefund ? 'refunded' : 'cancelled';

    $db->beginTransaction();

    try {
        $updateStmt = $db->prepare(
            "UPDATE orders
                SET order_status = :new_status,
                    cancelled_by = 'customer',
                    updated_at   = NOW()
              WHERE order_id = :order_id
                AND customer_id = :customer_id
                AND order_status IN ('pending', 'preparing')"
        );
        $updateStmt->execute([
            ':new_status'  => $finalStatus,
            ':order_id'    => $orderId,
            ':customer_id' => $customerId,
        ]);

        if ($updateStmt->rowCount() === 0) {
            $db->rollBack();
            echo json_encode([
                'status'  => 'error',
                'message' => 'Could not cancel order. It may have already been processed.',
            ]);
            return;
        }

        $db->commit();

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    // -----------------------------------------------------------
    // Attempt the refund.
    //
    // refundOrderToWallet() returns:
    //   true  → a refund was issued (or COD cleanup ran)
    //   false → no refund was needed (already refunded, or no
    //           payment on record)
    //
    // It only throws on genuine errors (missing account, DB failure).
    // -----------------------------------------------------------
    $refunded = false;

    try {
        $refunded = refundOrderToWallet($db, $orderId);
    } catch (Throwable $refundError) {
        error_log('Refund failed for order ' . $orderId . ': ' . $refundError->getMessage());

        echo json_encode([
            'status'       => 'error',
            'message'      => 'Order cancelled, but the refund could not be processed. Please contact support.',
            'order_id'     => $orderId,
            'order_status' => $finalStatus,
        ]);
        return;
    }

    // -----------------------------------------------------------
    // Compose the response message.
    //
    // Three cases matter here:
    //   COD                                     → simple cancel
    //   Wallet/Online with refund issued        → confirm refund
    //   Wallet/Online with no payment on record → cancel, no refund
    // -----------------------------------------------------------
    $message = match (true) {
        !$requiresRefund
            => 'Order cancelled successfully',
        $refunded
            => 'Order cancelled and refund issued to your wallet',
        default
            => 'Order cancelled',
    };

    echo json_encode([
        'status'       => 'success',
        'message'      => $message,
        'order_id'     => $orderId,
        'order_status' => $finalStatus,
        'refunded'     => $refunded,
    ]);
}

/**
 * Get order details as JSON (ownership-scoped).
 */
function handleGetOrderDetails(PDO $db, int $customerId): void
{
    $orderId = (int)($_POST['order_id'] ?? 0);

    if ($orderId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid order ID']);
        return;
    }

    $stmt = $db->prepare(
        "SELECT order_id FROM orders
          WHERE order_id = :order_id AND customer_id = :customer_id"
    );
    $stmt->execute([
        ':order_id'    => $orderId,
        ':customer_id' => $customerId,
    ]);

    if (!$stmt->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Order not found']);
        return;
    }

    $order = getOrderDetails($db, $orderId);

    if (!$order) {
        echo json_encode(['status' => 'error', 'message' => 'Order not found']);
        return;
    }

    echo json_encode([
        'status' => 'success',
        'order'  => $order,
    ]);
}