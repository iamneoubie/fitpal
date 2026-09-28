<?php
/**
 * FitPal Customer Order Handler
 *
 * Handles customer-initiated order actions.
 *
 * Actions:
 *   cancel_order        → cancel a 'pending' order. Issues a refund
 *                         when the original payment method was
 *                         Wallet or Online and a payment was
 *                         recorded. COD orders are cancelled with no
 *                         refund. Legacy orders (no payment on
 *                         record) cancel cleanly with no refund.
 *   get_order_details   → return an order as JSON
 *   get_tracking_status → read-only order_status + revision for the
 *                         tracking page's real-time poll
 *   reorder             → rebuild the session order queue from a
 *                         past order, validating each product
 *                         against the current database state.
 *
 * Only 'pending' orders are cancellable. The moment the kitchen
 * accepts the order and moves it to 'preparing', ingredients are
 * committed and the order is locked from the customer's side. The
 * customer must go through support to stop it from that point on.
 *
 * This handler contains NO SQL. All data access goes through
 * customer/backend/database/order-queries.php and
 * tracking-queries.php.
 *
 * This file is NOT safe to require from a page — it runs a full
 * request dispatch at load time. Pure helpers that pages need
 * (e.g. buildReorderLine) live in order-queries.php.
 *
 * @package FitPal
 * @version 5.3 — Adds get_tracking_status. The tracking page polls
 *                this action with the revision it currently holds.
 *                The action returns the current order_status and a
 *                revision hash; the client reloads only when the
 *                hash changes. One indexed read per poll, no full
 *                tracking payload.
 *
 *                (5.2: cancel restricted to 'pending' only. 5.1:
 *                CSRF validated against customer_csrf_token. 5.0:
 *                raw SQL moved to order-queries.php.)
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
require_once __DIR__ . '/../database/tracking-queries.php';

// Per-role CSRF check. The customer role validates against its own
// session key, 'customer_csrf_token', never the shared 'csrf_token'.
// Another role in the same browser session could have unset or
// rotated the shared key on its own sign-in, which would otherwise
// invalidate the token this request was issued under. See general.md.
$givenToken = (string)($_POST['csrf_token'] ?? '');
$sessToken  = (string)($_SESSION['customer_csrf_token'] ?? '');

if ($sessToken === '' || $givenToken === '' || !hash_equals($sessToken, $givenToken)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
    exit;
}

$action = (string)($_POST['action'] ?? '');

try {
    switch ($action) {
        case 'cancel_order':
            handleCancelOrder($database_connection, $customerId);
            break;

        case 'get_order_details':
            handleGetOrderDetails($database_connection, $customerId);
            break;

        case 'get_tracking_status':
            handleGetTrackingStatus($database_connection, $customerId);
            break;

        case 'reorder':
            handleReorder($database_connection, $customerId);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
} catch (PDOException $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Order handler DB error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error occurred']);
} catch (RuntimeException $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Order handler error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'An unexpected error occurred']);
}

// =====================================================
// HANDLERS
// =====================================================

/**
 * Cancel an order (customer-initiated).
 *
 * Only 'pending' orders are cancellable. The moment the kitchen
 * accepts the order and moves it to 'preparing', the customer loses
 * the ability to cancel — ingredients are committed at that point
 * and the order is locked from the customer's side.
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

    $order = getOrderOwnership($db, $orderId, $customerId);
    if (!$order) {
        echo json_encode(['status' => 'error', 'message' => 'Order not found']);
        return;
    }

    // Only 'pending' orders are cancellable by the customer. Once the
    // kitchen flips the order to 'preparing', ingredients are committed
    // and the order is locked. The customer must go through support.
    if ($order['order_status'] !== 'pending') {
        echo json_encode([
            'status'  => 'error',
            'message' => 'This order has already been accepted by the kitchen and can no longer be cancelled. Please contact support if you need help.',
        ]);
        return;
    }

    $paymentMethod  = $order['payment_method'];
    $requiresRefund = in_array($paymentMethod, ['Wallet', 'Online'], true);

    $finalStatus = $requiresRefund ? 'refunded' : 'cancelled';

    $cancelled = cancelOrderAsCustomer($db, $orderId, $customerId, $finalStatus);

    if (!$cancelled) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Could not cancel order. It may have already been processed.',
        ]);
        return;
    }

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

    if (!getOrderOwnership($db, $orderId, $customerId)) {
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

/**
 * Read-only poll endpoint for the tracking page.
 *
 * Returns the current order_status and a revision hash for a single
 * order, scoped to the owner. The client compares the returned
 * revision to what it already holds and reloads the page only when
 * the revision differs. A poll that finds nothing new therefore
 * transfers a tiny JSON body — no full tracking payload, no message
 * history, no totals.
 *
 * The revision is derived from the fields the customer actually
 * sees: order_status, delivered_at, and delivery_rider_id. Any of
 * those changing — the kitchen moving the order forward, a rider
 * being assigned or reassigned, the rider accepting, the rider
 * picking up, the order being delivered, or the order being
 * cancelled/refunded — produces a new hash.
 *
 * `updated_at` is deliberately excluded. It is touched by transient
 * bookkeeping writes that do not change what the customer sees, and
 * including it would make the revision churn for no visible reason.
 *
 * Returns the initial revision when called without a `current_revision`
 * field, so the client can bootstrap without a separate call.
 */
function handleGetTrackingStatus(PDO $db, int $customerId): void
{
    $orderId = (int)($_POST['order_id'] ?? 0);

    if ($orderId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid order ID']);
        return;
    }

    $snapshot = getOrderLiveSnapshot($db, $orderId, $customerId);

    if ($snapshot === null) {
        echo json_encode(['status' => 'error', 'message' => 'Order not found']);
        return;
    }

    $currentRevision = (string)($_POST['current_revision'] ?? '');
    $changed = ($currentRevision === '') || ($currentRevision !== $snapshot['revision']);

    echo json_encode([
        'status'       => 'success',
        'order_id'     => $snapshot['order_id'],
        'order_status' => $snapshot['order_status'],
        'revision'     => $snapshot['revision'],
        'changed'      => $changed,
    ]);
}

/**
 * Rebuild the session order queue from a past order.
 *
 * For each line in the original order:
 *   1. Check the product still exists, is active, and is in stock.
 *      Branches and restaurants must be active too.
 *   2. Re-apply the original customizations against the CURRENT
 *      product_composition rules. Quantities are clamped to
 *      max_quantity; ingredients no longer in the composition are
 *      dropped.
 *   3. Compute a fresh unit price from base_price + modifiers.
 *      The historical price_at_time is only used to reconstruct
 *      what the customer asked for — never trusted as money.
 *   4. Merge with an existing queue line that has the same
 *      (product_id + customization) signature, or append.
 *
 * Failures are collected per-line into `skipped` so the UI can
 * show exactly what could not be re-added and why. The queue is
 * always APPENDED to — the user can clear it via the queue panel's
 * Cancel Order button if they want a clean slate.
 *
 * Response shape:
 *   {
 *     status:  'success' | 'partial' | 'error',
 *     message: string,
 *     added:   [ { name, quantity } ... ],
 *     skipped: [ { name, reason } ... ],
 *     queue:   [ ... final session queue ... ],
 *     redirect: 'menu.php'
 *   }
 */
function handleReorder(PDO $db, int $customerId): void
{
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid order ID']);
        return;
    }

    $orderItems = getReorderableItems($db, $orderId, $customerId);

    if (empty($orderItems)) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'That order has no items to reorder.',
        ]);
        return;
    }

    $queue = $_SESSION['order_queue'] ?? [];
    if (!is_array($queue)) {
        $queue = [];
    }

    $added   = [];
    $skipped = [];

    foreach ($orderItems as $item) {
        $productId = (int)$item['product_id'];
        $name      = (string)$item['product_name'];
        $qty       = (int)$item['quantity'];

        // Rebuild the customization payload in the shape the queue
        // expects — the same shape queue-handler.php's add action
        // accepts from product-detail.js.
        $customizations = [];
        foreach (($item['customizations'] ?? []) as $cust) {
            $customizations[] = [
                'ingredient_id'   => (int)$cust['ingredient_id'],
                'quantity'        => (int)$cust['quantity'],
                'price_modifier'  => (float)$cust['price_at_time'],
                'calories'        => (int)($cust['calories_at_time'] ?? 0),
                'selected_option' => ((int)$cust['is_removed'] === 1) ? 'remove' : 'selected',
                'notes'           => $cust['custom_text'] ?? null,
            ];
        }

        $customJson = !empty($customizations) ? json_encode($customizations) : null;

        $enriched = buildReorderLine($db, [
            'product_id'         => $productId,
            'quantity'           => $qty,
            'customization_data' => $customJson,
        ], $skipped, $name);

        if ($enriched === null) {
            continue;
        }

        $lineKey = $productId . '::' . sha1((string)$customJson);
        $enriched['customizations'] = $customizations;
        $enriched['line_key']       = $lineKey;

        // Merge with an existing line that has the same signature,
        // or append.
        $merged = false;
        foreach ($queue as &$row) {
            $rowKey = $row['line_key']
                ?? ($row['product_id'] . '::' . sha1((string)(
                    is_string($row['customization_data'] ?? null)
                        ? $row['customization_data']
                        : json_encode($row['customization_data'] ?? null)
                )));

            if ($rowKey === $lineKey) {
                $newQty = (int)$row['quantity'] + $qty;
                $max    = (int)($enriched['stock'] ?? 999);
                $row['quantity'] = min($newQty, $max);
                $row['price']    = $enriched['price'];
                $merged = true;
                break;
            }
        }
        unset($row);

        if (!$merged) {
            $queue[] = $enriched;
        }

        $added[] = [
            'name'     => $name,
            'quantity' => $qty,
        ];
    }

    $_SESSION['order_queue'] = array_values($queue);

    $addedCount   = count($added);
    $skippedCount = count($skipped);

    if ($addedCount === 0) {
        echo json_encode([
            'status'   => 'error',
            'message'  => 'None of the items from that order are available right now.',
            'added'    => [],
            'skipped'  => $skipped,
            'queue'    => $_SESSION['order_queue'],
            'redirect' => 'menu.php',
        ]);
        return;
    }

    if ($skippedCount === 0) {
        echo json_encode([
            'status'   => 'success',
            'message'  => sprintf(
                'Added %d item%s to your order.',
                $addedCount,
                $addedCount === 1 ? '' : 's'
            ),
            'added'    => $added,
            'skipped'  => [],
            'queue'    => $_SESSION['order_queue'],
            'redirect' => 'menu.php',
        ]);
        return;
    }

    echo json_encode([
        'status'   => 'partial',
        'message'  => sprintf(
            'Added %d item%s. %d item%s unavailable.',
            $addedCount,
            $addedCount === 1 ? '' : 's',
            $skippedCount,
            $skippedCount === 1 ? ' was' : 's were'
        ),
        'added'    => $added,
        'skipped'  => $skipped,
        'queue'    => $_SESSION['order_queue'],
        'redirect' => 'menu.php',
    ]);
}