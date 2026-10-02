<?php
/**
 * FitPal Customer Order Handler
 *
 * The customer-facing dispatch endpoint for order actions. Runs on
 * the customer session (PHPSESSID_CUSTOMER), separate from every
 * other role's session.
 *
 * ---------------------------------------------------------------------
 * ACTIONS
 * ---------------------------------------------------------------------
 *   cancel_order            → customer cancels a pending order.
 *                             Delegates entirely to the shared
 *                             order-transaction handler.
 *
 *   get_order_details       → return an order as JSON. Customer-scoped
 *                             read.
 *
 *   get_tracking_status     → legacy: read-only order_status + revision
 *                             for the tracking page's poll. Retained
 *                             for backward compatibility. New callers
 *                             use get_tracking_payload.
 *
 *   get_order_card_state    → per-order status + derived flags for
 *                             orders.php's list. Read-only.
 *
 *   get_tracking_payload    → full tracking state for
 *                             order-tracking.php's sections. Read-only.
 *
 *   reorder                 → rebuild the session order queue from a
 *                             past order.
 *
 * ---------------------------------------------------------------------
 * WHERE THE MONEY RULES LIVE
 * ---------------------------------------------------------------------
 * This file contains no money logic. Cancel and refund decisions
 * live in shared/backend/handlers/order-transaction-handler.php.
 *
 * ---------------------------------------------------------------------
 * POLLING ENDPOINTS ARE READ-ONLY
 * ---------------------------------------------------------------------
 * get_order_card_state and get_tracking_payload never write to the
 * database. They exist so the customer's orders page and tracking
 * page can patch their own DOM in place when an order's state
 * changes on the kitchen or rider side, without a full page reload.
 *
 * Both endpoints are scoped to the authenticated customer. Neither
 * can return another customer's order.
 *
 * ---------------------------------------------------------------------
 * DEPENDENCY PATHS
 * ---------------------------------------------------------------------
 * This file lives at:
 *
 *     fitpal/customer/backend/handlers/customer-order-handler.php
 *
 * The database connection lives at:
 *
 *     fitpal/shared/backend/database/database-connect.php
 *
 * The session bootstrap lives at:
 *
 *     fitpal/shared/includes/session-bootstrap.php
 *
 * Both are three directories up from this file's own directory.
 *
 * ---------------------------------------------------------------------
 * RESPONSE SHAPE
 * ---------------------------------------------------------------------
 * JSON. Business-rule failures return HTTP 200 with
 * {status:'error', message:'...'}; only auth failures return 401
 * and CSRF mismatches return 403.
 *
 * @package FitPal
 * @version 7.4.0 — Adds two read-only polling endpoints:
 *                  - get_order_card_state  for orders.php
 *                  - get_tracking_payload  for order-tracking.php
 *
 *                  Both return a revision hash the client compares
 *                  against its last-seen value, so only changed
 *                  orders trigger a DOM patch.
 *
 *                  (7.3.0: reorder carries notes + image.
 *                  7.2.0: connection require corrected to walk three
 *                  directories up. 7.1.0: require added. 7.0.0:
 *                  renamed from order-handler.php.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';

require_once __DIR__ . '/../../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('customer');

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$customerId = (int)$_SESSION['customer_id'];

require_once __DIR__ . '/../database/customer-order-queries.php';
require_once __DIR__ . '/../database/tracking-queries.php';

$givenToken = (string)($_POST['csrf_token'] ?? '');
$sessToken  = (string)($_SESSION['customer_csrf_token'] ?? '');

if (
    $sessToken === ''
    || $givenToken === ''
    || !hash_equals($sessToken, $givenToken)
) {
    unset($_SESSION['customer_csrf_token']);

    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
    exit;
}

$action = (string)($_POST['action'] ?? '');

try {
    switch ($action) {

        case 'get_order_details':
            handleGetOrderDetails($database_connection, $customerId);
            break;

        case 'get_tracking_status':
            handleGetTrackingStatus($database_connection, $customerId);
            break;

        case 'get_order_card_state':
            handleGetOrderCardState($database_connection, $customerId);
            break;

        case 'get_tracking_payload':
            handleGetTrackingPayload($database_connection, $customerId);
            break;

        case 'reorder':
            handleReorder($database_connection, $customerId);
            break;

        case 'cancel_order':
            handleCancelOrderDelegation();
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
} catch (PDOException $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Customer order handler DB error: ' . $e->getMessage());
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
    error_log('Customer order handler error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'An unexpected error occurred']);
}

/* =============================================================
 * HANDLERS
 * ============================================================= */

/**
 * Return order details as JSON, scoped to the customer.
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
 * Legacy read-only poll endpoint for the tracking page.
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
 * Per-order card state for orders.php.
 *
 * Returns one entry per order the customer owns, in the same order
 * the page rendered them (newest first). Each entry carries the
 * values the page's cards depend on.
 *
 * The `revision` field is a hash that changes only when the card's
 * rendered state would change. The client compares each card's
 * current revision to the returned one and only patches the cards
 * whose revision changed.
 *
 * POST: csrf_token, action=get_order_card_state
 */
function handleGetOrderCardState(PDO $db, int $customerId): void
{
    $stmt = $db->prepare(
        "SELECT
            o.order_id,
            o.order_status,
            o.delivered_at
         FROM orders o
         WHERE o.customer_id = :customer_id
         ORDER BY o.order_date DESC"
    );
    $stmt->execute([':customer_id' => $customerId]);

    $cards = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $orderId     = (int)$row['order_id'];
        $status      = (string)$row['order_status'];
        $deliveredAt = $row['delivered_at'] !== null
            ? (string)$row['delivered_at']
            : null;

        $terminal  = in_array($status, ['delivered', 'cancelled', 'refunded', 'failed'], true);
        $active    = in_array($status, ['pending', 'preparing', 'rider_pending', 'picking_up', 'delivering'], true);
        $graceOpen = customerOrderHasOpenChatWindow($status, $deliveredAt);

        $badgeClass = match ($status) {
            'pending'       => 'badge-warning',
            'preparing'     => 'badge-info',
            'picking_up'    => 'badge-info',
            'rider_pending' => 'badge-info',
            'delivering'    => 'badge-primary',
            'delivered'     => 'badge-success',
            'cancelled'     => 'badge-danger',
            'refunded'      => 'badge-secondary',
            'failed'        => 'badge-danger',
            default         => 'badge-secondary',
        };

        $badgeLabel = match ($status) {
            'pending'       => 'Pending',
            'preparing'     => 'Preparing',
            'rider_pending' => 'Rider Pending',
            'picking_up'    => 'Picking Up',
            'delivering'    => 'For Delivery',
            'delivered'     => 'Delivered',
            'cancelled'     => 'Cancelled',
            'refunded'      => 'Refunded',
            'failed'        => 'Failed',
            default         => ucfirst($status),
        };

        if ($status === 'delivered' && $graceOpen) {
            $trackingKind  = 'message';
            $trackingLabel = 'Message';
        } elseif ($terminal) {
            $trackingKind  = 'history';
            $trackingLabel = 'Track History';
        } else {
            $trackingKind  = 'track';
            $trackingLabel = 'Track Order';
        }

        $canCancel  = ($status === 'pending');
        $canReview  = ($status === 'delivered');
        $canReorder = $terminal;

        $revision = sha1(implode('|', [
            $status,
            $deliveredAt ?? '',
            $graceOpen ? '1' : '0',
        ]));

        $cards[] = [
            'order_id'       => $orderId,
            'order_status'   => $status,
            'badge_class'    => $badgeClass,
            'badge_label'    => $badgeLabel,
            'is_terminal'    => $terminal,
            'is_active'      => $active,
            'grace_open'     => $graceOpen,
            'can_cancel'     => $canCancel,
            'can_review'     => $canReview,
            'can_reorder'    => $canReorder,
            'tracking_kind'  => $trackingKind,
            'tracking_label' => $trackingLabel,
            'revision'       => $revision,
        ];
    }

    echo json_encode([
        'status' => 'success',
        'cards'  => $cards,
    ]);
}

/**
 * Full tracking payload for order-tracking.php.
 *
 * POST: csrf_token, action=get_tracking_payload, order_id
 */
function handleGetTrackingPayload(PDO $db, int $customerId): void
{
    $orderId = (int)($_POST['order_id'] ?? 0);

    if ($orderId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid order ID']);
        return;
    }

    $order = getTrackableOrder($db, $orderId, $customerId);
    if (!$order) {
        echo json_encode(['status' => 'error', 'message' => 'Order not found']);
        return;
    }

    $status      = (string)$order['order_status'];
    $deliveredAt = $order['delivered_at'] !== null
        ? (string)$order['delivered_at']
        : null;

    $statusMeta    = getTrackingStatusMeta($status);
    $timelineIndex = getTrackingStepIndex($status);
    $isTerminal    = in_array($status, ['cancelled', 'refunded'], true);

    // ---- Restaurant ----
    $restaurants   = getOrderRestaurants($db, $orderId);
    $restaurantRow = $restaurants[0] ?? null;
    $restaurantOut = null;
    if ($restaurantRow) {
        $restaurantOut = [
            'restaurant_name' => getRestaurantDisplayName($restaurantRow),
            'branch_name'     => (string)($restaurantRow['branch_name'] ?? ''),
            'barangay'        => (string)($restaurantRow['barangay']    ?? ''),
            'city'            => (string)($restaurantRow['city']        ?? ''),
        ];
    }

    // ---- Rider ----
    $riderRow = getOrderRiderDetails($db, $orderId);
    $riderOut = null;
    $hasRider = ($riderRow !== false);
    if ($hasRider) {
        $riderOut = [
            'name'            => getRiderDisplayName($riderRow),
            'vehicle_type'    => (string)($riderRow['vehicle_type']  ?? ''),
            'vehicle_plate'   => (string)($riderRow['vehicle_plate'] ?? ''),
            'average_rating'  => (float)($riderRow['average_rating'] ?? 0),
            'profile_picture' => (string)($riderRow['profile_picture'] ?? ''),
        ];
    }

    // ---- Chat gating ----
    $deliveredGraceOpen = customerOrderDeliveredWithinGrace($order);

    $showKitchenTab = !$isTerminal
        && ($status !== 'delivered' || $deliveredGraceOpen);

    $showRiderCard = $hasRider && !$isTerminal;

    $riderCanBeMessaged = $hasRider
        && !$isTerminal
        && riderHasAcceptedOrder($db, $orderId);

    $showRiderTab = $showRiderCard && $riderCanBeMessaged;

    $chatIsReachable = $showKitchenTab || $showRiderTab;

    $closedReason = '';
    if (!$chatIsReachable) {
        if ($status === 'delivered') {
            $closedReason = 'The one-hour messaging window for this delivered order has ended.';
        } elseif (in_array($status, ['cancelled', 'refunded'], true)) {
            $closedReason = 'This order was ' . $status . '. Messaging is no longer available.';
        } else {
            $closedReason = 'Messaging is not available for this order.';
        }
    }

    $defaultTab = $showKitchenTab
        ? 'restaurant_account'
        : ($showRiderTab ? 'delivery_rider' : 'restaurant_account');

    $revision = sha1(implode('|', [
        $status,
        $deliveredAt ?? '',
        $riderOut ? $riderOut['name'] : '',
        $showKitchenTab ? '1' : '0',
        $showRiderTab   ? '1' : '0',
    ]));

    echo json_encode([
        'status'         => 'success',
        'order_id'       => $orderId,
        'order_status'   => $status,
        'delivered_at'   => $deliveredAt,
        'status_meta'    => $statusMeta,
        'timeline_index' => $timelineIndex,
        'is_terminal'    => $isTerminal,
        'restaurant'     => $restaurantOut,
        'rider'          => $riderOut,
        'has_rider'      => $hasRider,
        'chat'           => [
            'can_message_kitchen' => $showKitchenTab,
            'can_message_rider'   => $riderCanBeMessaged,
            'show_rider_tab'      => $showRiderTab,
            'default_tab'         => $defaultTab,
            'closed_reason'       => $closedReason,
            'is_reachable'        => $chatIsReachable,
        ],
        'revision'       => $revision,
    ]);
}

/**
 * Rebuild the session order queue from a past order.
 *
 * For each line in the original order:
 *   1. Check the product still exists, is active, and is in stock.
 *   2. Re-apply the original customizations against the CURRENT
 *      product_composition rules.
 *   3. Compute a fresh unit price from base_price + modifiers.
 *   4. Reconstruct the special-instructions notes entry.
 *   5. Merge with an existing queue line that has the same
 *      (product_id + customization) signature, or append.
 *
 * The line buildReorderLine() returns is stored in the queue
 * exactly as returned.
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
            'product_id'          => $productId,
            'quantity'            => $qty,
            'customization_data'  => $customJson,
            'custom_instructions' => $item['custom_instructions'] ?? null,
        ], $skipped, $name);

        if ($enriched === null) {
            continue;
        }

        $hashInput = $enriched['customization_data'] ?? '';
        $lineKey   = $productId . '::' . sha1((string)$hashInput);

        $enriched['line_key'] = $lineKey;

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

                $row['image']              = $enriched['image'];
                $row['customizations']     = $enriched['customizations'];
                $row['customization_data'] = $enriched['customization_data'];
                $row['name']               = $enriched['name'];
                $row['restaurant_name']    = $enriched['restaurant_name'];
                $row['branch_name']        = $enriched['branch_name'];
                $row['stock']              = $enriched['stock'];

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

/**
 * Hand a cancel request off to the shared order-transaction handler.
 */
function handleCancelOrderDelegation(): never
{
    $orderId = (int)($_POST['order_id'] ?? 0);
    $token   = (string)($_POST['csrf_token'] ?? '');

    if ($orderId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid order ID']);
        exit;
    }

    $endpoint = __DIR__ . '/../../../shared/backend/handlers/order-transaction-handler.php';

    if (!is_file($endpoint)) {
        error_log('Customer order handler: shared order-transaction handler is missing at ' . $endpoint);
        echo json_encode([
            'status'  => 'error',
            'message' => 'The order service is temporarily unavailable. Please try again.',
        ]);
        exit;
    }

    $_POST['action'] = 'customer_cancel_order';

    require $endpoint;

    exit;
}