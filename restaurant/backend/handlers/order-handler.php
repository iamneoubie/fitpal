<?php
/**
 * FitPal Restaurant Kitchen Order Handler
 *
 * Actions:
 *   start_preparing     — pending → preparing
 *   cancel_order        — pending → cancelled (cancelled_by = 'restaurant')
 *   assign_rider        — preparing → rider_pending (first-time assignment)
 *   reassign_rider      — preparing / rider_pending → rider_pending
 *   available_riders    — read-only roster for the kitchen's rider modal
 *   poll                — delta fetch of the live order list
 *   active_orders_count — read-only count for the sign-out guard
 *
 * Handoff model
 * -------------
 * The kitchen does NOT mark an order delivered, in-transit, or paid.
 * Assigning a rider moves the order to 'rider_pending' and hands
 * control to the rider. The rider confirms in their own portal, at
 * which point the order becomes 'picking_up'. The rider then marks
 * it picked up to move to 'delivering', and finally marks it
 * delivered. The kitchen's only remaining touch on an order after
 * assignment is the Reassign action — and that is only available
 * while the order is still 'preparing' or 'rider_pending'. Once the
 * rider has accepted (order is in 'picking_up' or later), the
 * kitchen has lost control.
 *
 * STEP-BY-STEP LIFECYCLE (enforced by this handler)
 * -------------------------------------------------
 * The kitchen's sequence is strict:
 *
 *     pending --Start Preparing--> preparing --Assign Rider--> rider_pending
 *
 * A rider may only be attached to an order that is already being
 * prepared. Assigning on a 'pending' order is refused at both the
 * page level and at the handler level. The query layer's
 * assignRiderToOrder() enforces the same rule with a WHERE clause
 * so a race or a direct POST that skips the UI is refused too.
 *
 * All actions:
 *   - require a signed-in restaurant account
 *   - require a branch-scoped account (manager / staff / kitchen)
 *   - require a valid CSRF token
 *   - verify the order belongs to the account's branch
 *   - verify the order's current status allows the requested transition
 *
 * Concurrent-order cap
 * --------------------
 * A rider may hold at most 3 orders at once, counting across
 * 'rider_pending', 'picking_up', and 'delivering'. The assignment
 * and reassignment handlers enforce this two ways:
 *
 *   1. A pre-check via riderActiveOrderCount() so the kitchen sees a
 *      clear error message if they picked a rider who is already at
 *      the cap in another tab.
 *   2. The authoritative count-check inside
 *      assignRiderToOrder() / reassignRiderToOrder(), which runs
 *      under a FOR UPDATE lock on the rider's profile row.
 *
 * The database trigger before_order_rider_assign is the last-resort
 * guard if a race slips past both.
 *
 * Live polling
 * ------------
 * The `poll` action returns only the changes since a client cursor
 * plus the current tab's paginated page:
 *
 *   - `rows`        — new cards for orders with order_id >
 *                     since_order_id that are currently live.
 *   - `updated`     — cards whose status changed since the last
 *                     poll.
 *   - `removed`     — order_ids that were live before but are now
 *                     closed.
 *   - `counts`      — per-tab counts recomputed server-side.
 *   - `page`        — the paginated slice for the requested tab.
 *   - `max_id`      — highest order_id in the current live set.
 *
 * The poll uses LIVE_BOARD_STATUSES from the query layer.
 *
 * Card renderer
 * -------------
 * renderKitchenCard() produces the exact same markup as
 * kitchenCardHtml() in restaurant/pages/kitchen.php. The two MUST
 * stay in sync: a card swapped in via poll and a card rendered on
 * page load have to look identical. Both call
 * shapeKitchenOrderSummaryRow() from the query layer so the summary
 * line's fields come from one place.
 *
 * The card is three bands:
 *   HEADER  — order id, date, status badge, chevron toggle
 *   SUMMARY — Restaurant • Branch • Customer • Rider • Total
 *   DETAILS — pickup block, drop-off block, rider block, items,
 *             subtotal. Collapsed by default.
 *
 * The collapsed state itself is owned by orders.js. The server only
 * emits the details band as hidden; the client reapplies the user's
 * expanded/collapsed choice after every poll re-render.
 *
 * Message action gating
 * ---------------------
 * A completed order's Message action is available only while the
 * delivered grace window is open. The query layer computes the flag
 * in SQL; renderKitchenCard() reads it and either emits or does not
 * emit the button. chat-handler.php's gateChannel() enforces the
 * same rule server-side, so the button is only ever shown when the
 * gate would accept the send.
 *
 * @package FitPal
 * @version 8.0 — Card renderer synced to the three-band kitchen
 *                card in file #2:
 *                  - renderKitchenCard() now mirrors
 *                    kitchenCardHtml() byte-for-byte, emitting
 *                    header / summary / details bands.
 *                  - The details band renders a pickup block, a
 *                    drop-off block, and a rider block.
 *                  - $canMessage reads the delivered grace flag on
 *                    completed cards, so the polled HTML never
 *                    offers a Message action the chat handler
 *                    would refuse.
 *                  - The action footer is always rendered. The
 *                    buttons inside it are conditional on the
 *                    order status.
 *                  - No other action changed.
 *
 *                (7.0: available_riders action. 6.0: pagination
 *                and step-by-step lifecycle. 5.1: per-rider
 *                concurrent-order cap raised from 1 to 3; added
 *                'picking_up'. 5.0: added poll. 4.0: CSRF
 *                validated against restaurant_csrf_token. 3.0:
 *                rider_pending handoff.)
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

/* --------------------------------------------------------------
 * GRACE CONSTANT
 *
 * Must match RESTAURANT_CHAT_DELIVERED_GRACE_SECONDS in both
 * order-queries.php and chat-queries.php. The handler itself does
 * not use this constant directly — the query layer computes the
 * derived chat_grace_open column. The define() exists so a direct
 * caller who reaches into this file's renderer still resolves the
 * constant if they have not loaded either query file first.
 * -------------------------------------------------------------- */

if (!defined('RESTAURANT_CHAT_DELIVERED_GRACE_SECONDS')) {
    define('RESTAURANT_CHAT_DELIVERED_GRACE_SECONDS', 3600);
}

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
 * DEPENDENCIES
 * -------------------------------------------------------------- */

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/order-queries.php';
require_once __DIR__ . '/../../includes/restaurant-csrf-token.php';

/* --------------------------------------------------------------
 * CSRF
 *
 * Validated against the restaurant role's own session key,
 * 'restaurant_csrf_token'. Never the shared 'csrf_token' key.
 * -------------------------------------------------------------- */

if (
    !isset($_POST['csrf_token'], $_SESSION['restaurant_csrf_token']) ||
    !hash_equals((string)$_SESSION['restaurant_csrf_token'], (string)$_POST['csrf_token'])
) {
    unset($_SESSION['restaurant_csrf_token']);

    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
    exit;
}

/* --------------------------------------------------------------
 * ROUTING
 * -------------------------------------------------------------- */

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

        case 'available_riders':
            handleAvailableRiders($database_connection, $orderId, $branchId);
            break;

        case 'poll':
            handlePoll($database_connection, $branchId);
            break;

        case 'active_orders_count':
            handleActiveOrdersCount($database_connection, $branchId);
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
        'message'      => 'Order is now being prepared. You can assign a rider when ready.',
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

    if ($order['order_status'] === 'pending') {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Start preparing this order before assigning a rider.',
            'field'   => 'not_preparing',
        ]);
        exit;
    }

    if ($order['order_status'] !== 'preparing') {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Riders can only be assigned to orders that are being prepared.',
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

    $riderCount = riderActiveOrderCount($db, $riderId, $orderId);
    if ($riderCount >= RIDER_CONCURRENT_CAP) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'That rider already has the maximum of '
                       . RIDER_CONCURRENT_CAP
                       . ' active orders.',
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
            'message' => 'Could not assign the rider. The order may have changed, '
                       . 'or the rider reached the active-order cap in another tab.',
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

    if ($order['order_status'] === 'pending') {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Start preparing this order before assigning a rider.',
            'field'   => 'not_preparing',
        ]);
        exit;
    }

    if (!in_array($order['order_status'], ['preparing', 'rider_pending'], true)) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'This order can no longer be reassigned. '
                       . 'The rider has already accepted it.',
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

    $newRiderCount = riderActiveOrderCount($db, $newRiderId, $orderId);
    if ($newRiderCount >= RIDER_CONCURRENT_CAP) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'That rider already has the maximum of '
                       . RIDER_CONCURRENT_CAP
                       . ' active orders.',
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
            'message' => 'Could not reassign the rider. The order may have changed, '
                       . 'or the rider reached the active-order cap in another tab.',
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

function handleAvailableRiders(PDO $db, int $orderId, int $branchId): never
{
    $excludeOrderId = $orderId > 0 ? $orderId : 0;

    $rows = getAvailableRidersForBranch($db, $branchId, $excludeOrderId);

    $riders = shapeAvailableRiderList($rows);

    echo json_encode([
        'status' => 'success',
        'riders' => $riders,
        'count'  => count($riders),
    ]);
    exit;
}

function handleActiveOrdersCount(PDO $db, int $branchId): never
{
    $count = countActiveOrdersForBranch($db, $branchId);

    echo json_encode([
        'status'       => 'success',
        'count'        => $count,
        'has_active'   => $count > 0,
        'max_allowed'  => 0,
    ]);
    exit;
}

function handlePoll(PDO $db, int $branchId): never
{
    $sinceOrderId = (int)($_POST['since_order_id'] ?? 0);
    $tab          = (string)($_POST['tab'] ?? 'new');
    $page         = max(1, (int)($_POST['page'] ?? 1));

    $tabToStatuses = [
        'new'              => ['pending'],
        'preparing'        => ['preparing'],
        'waiting_on_rider' => ['rider_pending', 'picking_up'],
        'out_for_delivery' => ['delivering'],
        'recent'           => ['delivered', 'cancelled', 'refunded'],
    ];

    if (!isset($tabToStatuses[$tab])) {
        $tab = 'new';
    }

    $statuses = $tabToStatuses[$tab];

    $liveOrders = getBranchKitchenOrders($db, $branchId, LIVE_BOARD_STATUSES);

    foreach ($liveOrders as &$order) {
        $order['items'] = getKitchenOrderItems(
            $db,
            (int)$order['order_id'],
            $branchId
        );
    }
    unset($order);

    $rows    = [];
    $updated = [];
    $maxId   = $sinceOrderId;

    foreach ($liveOrders as $order) {
        $oid = (int)$order['order_id'];
        if ($oid > $maxId) {
            $maxId = $oid;
        }

        $html = renderKitchenCard($order);

        if ($oid > $sinceOrderId) {
            $rows[] = [
                'order_id'     => $oid,
                'order_status' => (string)$order['order_status'],
                'html'         => $html,
            ];
        } else {
            $updated[] = [
                'order_id'     => $oid,
                'order_status' => (string)$order['order_status'],
                'html'         => $html,
            ];
        }
    }

    $removed = [];
    if ($sinceOrderId > 0) {
        $closedStmt = $db->prepare(
            "SELECT DISTINCT o.order_id
               FROM orders o
               JOIN queue_item qi ON qi.order_id = o.order_id
              WHERE qi.branch_id = :branch_id
                AND o.order_id <= :since_order_id
                AND o.order_status IN ('delivered','cancelled','refunded')"
        );
        $closedStmt->execute([
            ':branch_id'      => $branchId,
            ':since_order_id' => $sinceOrderId,
        ]);
        while ($r = $closedStmt->fetch(PDO::FETCH_ASSOC)) {
            $removed[] = (int)$r['order_id'];
        }

        $liveIds = [];
        foreach ($liveOrders as $lo) {
            $liveIds[(int)$lo['order_id']] = true;
        }
        $removed = array_values(array_filter(
            $removed,
            static fn($id) => !isset($liveIds[$id])
        ));
    }

    $counts = getKitchenTabCounts($db, $branchId);

    if ($tab === 'recent') {
        $paginated = getBranchCompletedOrdersPaginated(
            $db,
            $branchId,
            $page,
            KITCHEN_DEFAULT_PER_PAGE
        );

        foreach ($paginated['items'] as &$order) {
            $order['items'] = getKitchenOrderItems(
                $db,
                (int)$order['order_id'],
                $branchId
            );
        }
        unset($order);

        $pageItems = [];
        foreach ($paginated['items'] as $order) {
            $pageItems[] = [
                'order_id'     => (int)$order['order_id'],
                'order_status' => (string)$order['order_status'],
                'html'         => renderKitchenCard($order, true),
            ];
        }
    } else {
        $paginated = getBranchKitchenOrdersPaginated(
            $db,
            $branchId,
            $statuses,
            $page,
            KITCHEN_DEFAULT_PER_PAGE
        );

        foreach ($paginated['items'] as &$order) {
            $order['items'] = getKitchenOrderItems(
                $db,
                (int)$order['order_id'],
                $branchId
            );
        }
        unset($order);

        $pageItems = [];
        foreach ($paginated['items'] as $order) {
            $pageItems[] = [
                'order_id'     => (int)$order['order_id'],
                'order_status' => (string)$order['order_status'],
                'html'         => renderKitchenCard($order, false),
            ];
        }
    }

    echo json_encode([
        'status'  => 'success',
        'rows'    => $rows,
        'updated' => $updated,
        'removed' => $removed,
        'counts'  => $counts,
        'page'    => [
            'items'      => $pageItems,
            'total'      => $paginated['total'],
            'totalPages' => $paginated['totalPages'],
            'page'       => $paginated['page'],
            'perPage'    => $paginated['perPage'],
        ],
        'max_id'  => $maxId,
    ]);
    exit;
}

/* --------------------------------------------------------------
 * CARD RENDERER (mirror of restaurant/pages/kitchen.php)
 *
 * Byte-for-byte identical to kitchenCardHtml() in the page. Both
 * call shapeKitchenOrderSummaryRow() so the summary line's fields
 * come from one place. If this function and the page's diverge, a
 * card rendered on load and a card swapped in via poll look
 * different — a bug class the mirror invariant prevents.
 * -------------------------------------------------------------- */

function assetBaseFromSession(): string
{
    return '../../../shared/';
}

function kitchenStatusLabel(string $status): string
{
    return match ($status) {
        'pending'       => 'New',
        'preparing'     => 'Preparing',
        'rider_pending' => 'Waiting on Rider',
        'picking_up'    => 'Picking Up',
        'delivering'    => 'Out for Delivery',
        'delivered'     => 'Delivered',
        'cancelled'     => 'Cancelled',
        'refunded'      => 'Refunded',
        default         => ucfirst($status),
    };
}

function kitchenStatusBadge(string $status): string
{
    return match ($status) {
        'pending'       => 'badge-warning',
        'preparing'     => 'badge-info',
        'rider_pending' => 'badge-primary',
        'picking_up'    => 'badge-primary',
        'delivering'    => 'badge-primary',
        'delivered'     => 'badge-success',
        'cancelled'     => 'badge-danger',
        'refunded'      => 'badge-secondary',
        default         => 'badge-secondary',
    };
}

function kitchenMoney(float|string|null $amount): string
{
    return '₱' . number_format((float)($amount ?? 0), 2);
}

function kitchenDate(string $date): string
{
    $ts = strtotime($date);
    return $ts !== false ? date('M d, g:i A', $ts) : $date;
}

function kitchenBranchAddressLine(array $order): string
{
    $parts = [];
    foreach (['branch_block', 'branch_barangay', 'branch_city', 'branch_province'] as $key) {
        $v = trim((string)($order[$key] ?? ''));
        if ($v !== '') {
            $parts[] = $v;
        }
    }
    return implode(', ', $parts);
}

function kitchenRiderVehicleLine(array $order): string
{
    $parts = [];
    $vehicle = trim((string)($order['rider_vehicle_type'] ?? ''));
    $plate   = trim((string)($order['rider_vehicle_plate'] ?? ''));
    if ($vehicle !== '') $parts[] = $vehicle;
    if ($plate   !== '') $parts[] = $plate;
    return implode(' • ', $parts);
}

/**
 * Render a kitchen order card. Mirrors kitchenCardHtml() in
 * restaurant/pages/kitchen.php.
 */
function renderKitchenCard(
    array $order,
    bool $isCompleted = false,
    bool $newOrderHighlight = false
): string {
    $assetBase = assetBaseFromSession();

    $orderId      = (int)($order['order_id'] ?? 0);
    $orderStatus  = (string)($order['order_status'] ?? '');
    $items        = is_array($order['items'] ?? null) ? $order['items'] : [];
    $itemCount    = (int)($order['item_count'] ?? count($items));
    $subtotal     = (float)($order['subtotal'] ?? 0);

    $customerName = trim(
        (string)($order['customer_first_name'] ?? '') . ' ' .
        (string)($order['customer_last_name'] ?? '')
    );
    if ($customerName === '') {
        $customerName = 'Customer';
    }
    $customerContact = (string)($order['customer_contact'] ?? '—');
    $destination     = (string)($order['destination_address'] ?? '');

    $assignedRiderId   = isset($order['delivery_rider_id']) && $order['delivery_rider_id'] !== null
        ? (int)$order['delivery_rider_id']
        : 0;
    $assignedRiderName = trim(
        (string)($order['rider_first_name'] ?? '') . ' ' .
        (string)($order['rider_last_name'] ?? '')
    );
    $riderContact = (string)($order['rider_contact'] ?? '');

    $restaurantName = trim((string)($order['restaurant_name'] ?? ''));
    $branchName     = trim((string)($order['branch_name'] ?? ''));
    $branchAddress  = kitchenBranchAddressLine($order);
    $riderVehicle   = kitchenRiderVehicleLine($order);

    // ---- Summary line -----------------------------------------
    $summary = shapeKitchenOrderSummaryRow($order);
    $riderSummary = $summary['rider_name'] !== '' ? $summary['rider_name'] : '—';

    // ---- Step-by-step action visibility -----------------------
    $canStartPreparing = !$isCompleted && $orderStatus === 'pending';
    $canCancel         = !$isCompleted && in_array($orderStatus, ['pending', 'preparing'], true);
    $canAssignRider    = !$isCompleted
        && $orderStatus === 'preparing'
        && $assignedRiderId === 0;
    $canReassignRider  = !$isCompleted
        && in_array($orderStatus, ['preparing', 'rider_pending'], true)
        && $assignedRiderId > 0;

    $canMessage = !$isCompleted
        || ($summary['is_delivered'] && $summary['chat_grace_open']);

    // Rider-block sub-badge.
    $riderBlockBadge = '';
    if (!$isCompleted) {
        if ($orderStatus === 'rider_pending') {
            $riderBlockBadge = '<span class="badge badge-warning">Awaiting confirmation</span>';
        } elseif ($orderStatus === 'picking_up') {
            $riderBlockBadge = '<span class="badge badge-primary">Picking up</span>';
        }
    }

    $closedAt = '';
    if ($isCompleted) {
        if ($orderStatus === 'delivered' && !empty($order['delivered_at'])) {
            $closedAt = (string)$order['delivered_at'];
        } elseif (!empty($order['updated_at'])) {
            $closedAt = (string)$order['updated_at'];
        } elseif (!empty($order['order_date'])) {
            $closedAt = (string)$order['order_date'];
        }
    }

    $cardClass = 'kitchen-order-card'
        . ($isCompleted ? ' kitchen-order-card-completed' : '')
        . ($newOrderHighlight ? ' is-new' : '');

    $detailsId = 'kitchenOrderDetails' . $orderId;

    $summarySegments = [
        $summary['restaurant_name'],
        $summary['branch_name'],
        $summary['customer_name'],
        $riderSummary,
        kitchenMoney($summary['order_total']),
    ];

    ob_start();
    ?>
<article class="<?php echo $cardClass; ?>" data-order-id="<?php echo $orderId; ?>"
    data-order-status="<?php echo htmlspecialchars($orderStatus, ENT_QUOTES, 'UTF-8'); ?>">

    <header class="kitchen-order-header">
        <div class="kitchen-order-header-left">
            <span class="kitchen-order-id">#<?php echo $orderId; ?></span>
            <span class="kitchen-order-date">
                <?php
                echo $isCompleted && $closedAt !== ''
                    ? htmlspecialchars(kitchenDate($closedAt), ENT_QUOTES, 'UTF-8')
                    : htmlspecialchars(kitchenDate((string)($order['order_date'] ?? '')), ENT_QUOTES, 'UTF-8');
                ?>
            </span>
        </div>
        <div class="kitchen-order-header-right">
            <span class="badge <?php echo kitchenStatusBadge($orderStatus); ?>">
                <?php echo kitchenStatusLabel($orderStatus); ?>
            </span>
            <button type="button" class="kitchen-order-toggle" data-row-expand="1"
                aria-controls="<?php echo $detailsId; ?>" aria-expanded="false" aria-label="Toggle order details">
                <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-drop-down-line.svg" alt=""
                    class="kitchen-order-toggle-icon" width="18" height="18"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/arrow-drop-down-line.svg'">
            </button>
        </div>
    </header>

    <div class="kitchen-order-summary">
        <?php foreach ($summarySegments as $i => $segment): ?>
        <?php if ($i > 0): ?>
        <span class="kitchen-order-summary-sep" aria-hidden="true">•</span>
        <?php endif; ?>
        <span class="kitchen-order-summary-seg">
            <?php echo htmlspecialchars((string)$segment, ENT_QUOTES, 'UTF-8'); ?>
        </span>
        <?php endforeach; ?>
    </div>

    <div class="kitchen-order-details" id="<?php echo $detailsId; ?>" hidden>

        <div class="kitchen-route">

            <div class="kitchen-route-stop kitchen-route-stop-pickup">
                <div class="kitchen-route-icon kitchen-route-icon-pickup" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/community-general.svg'">
                </div>
                <div class="kitchen-route-info">
                    <span class="kitchen-route-label">Pickup</span>
                    <span class="kitchen-route-name">
                        <?php echo htmlspecialchars($restaurantName !== '' ? $restaurantName : '—', ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <?php if ($branchName !== ''): ?>
                    <span class="kitchen-route-sub">
                        <?php echo htmlspecialchars($branchName, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <?php endif; ?>
                    <?php if ($branchAddress !== ''): ?>
                    <span class="kitchen-route-address">
                        <?php echo htmlspecialchars($branchAddress, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="kitchen-route-connector" aria-hidden="true"></div>

            <div class="kitchen-route-stop kitchen-route-stop-dropoff">
                <div class="kitchen-route-icon kitchen-route-icon-dropoff" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/location-fill.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/location-target-fill.svg'">
                </div>
                <div class="kitchen-route-info">
                    <span class="kitchen-route-label">Drop-off</span>
                    <span class="kitchen-route-name">
                        <?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <span class="kitchen-route-contact">
                        <?php echo htmlspecialchars($customerContact, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <?php if ($destination !== ''): ?>
                    <span class="kitchen-route-address">
                        <?php echo htmlspecialchars($destination, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="kitchen-rider-block">
            <span class="kitchen-rider-block-label">Rider</span>
            <?php if ($assignedRiderId > 0): ?>
            <div class="kitchen-rider-block-body">
                <span class="kitchen-rider-block-name">
                    <?php echo htmlspecialchars($assignedRiderName !== '' ? $assignedRiderName : ('#' . $assignedRiderId), ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <?php if ($riderContact !== ''): ?>
                <span class="kitchen-rider-block-contact">
                    <?php echo htmlspecialchars($riderContact, ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <?php endif; ?>
                <?php if ($riderVehicle !== ''): ?>
                <span class="kitchen-rider-block-vehicle">
                    <?php echo htmlspecialchars($riderVehicle, ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <?php endif; ?>
                <?php if ($riderBlockBadge !== ''): ?>
                <span class="kitchen-rider-block-badge">
                    <?php echo $riderBlockBadge; ?>
                </span>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="kitchen-rider-block-body">
                <span class="kitchen-rider-block-empty">
                    <?php echo $isCompleted ? '—' : 'Not assigned'; ?>
                </span>
            </div>
            <?php endif; ?>
        </div>

        <div class="kitchen-order-items">
            <p class="kitchen-items-heading">
                <?php echo $itemCount; ?> item<?php echo $itemCount === 1 ? '' : 's'; ?>
            </p>
            <ul class="kitchen-item-list">
                <?php foreach ($items as $item):
                    $itemQty   = (int)($item['quantity'] ?? 0);
                    $itemName  = (string)($item['product_name'] ?? 'Item');
                    $customs   = $item['customizations'] ?? [];
                    $itemNotes = (string)($item['custom_instructions'] ?? '');
                ?>
                <li class="kitchen-item">
                    <div class="kitchen-item-line">
                        <span class="kitchen-item-qty"><?php echo $itemQty; ?>&times;</span>
                        <span class="kitchen-item-name">
                            <?php echo htmlspecialchars($itemName, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>

                    <?php if (!empty($customs)): ?>
                    <ul class="kitchen-item-customs">
                        <?php foreach ($customs as $cust):
                            $custName = (string)($cust['ingredient_name'] ?? '');
                            if ($custName === '') continue;
                            $isRemoved = (int)($cust['is_removed'] ?? 0) === 1;
                            $custQty   = (int)($cust['quantity'] ?? 1);
                        ?>
                        <li class="kitchen-item-custom <?php echo $isRemoved ? 'is-removed' : ''; ?>">
                            <?php if ($isRemoved): ?>
                            <span class="kitchen-custom-mark">&minus;</span>
                            <span class="kitchen-custom-text">
                                <?php echo htmlspecialchars($custName, ENT_QUOTES, 'UTF-8'); ?>
                                <em>(remove)</em>
                            </span>
                            <?php else: ?>
                            <span class="kitchen-custom-mark">+</span>
                            <span class="kitchen-custom-text">
                                <?php echo htmlspecialchars($custName, ENT_QUOTES, 'UTF-8'); ?>
                                <?php if ($custQty > 1): ?> &times; <?php echo $custQty; ?><?php endif; ?>
                            </span>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>

                    <?php if ($itemNotes !== ''): ?>
                    <p class="kitchen-item-notes">
                        <strong>Note:</strong>
                        <?php echo nl2br(htmlspecialchars($itemNotes, ENT_QUOTES, 'UTF-8')); ?>
                    </p>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="kitchen-order-total">
            <span class="kitchen-order-total-label">Subtotal</span>
            <span class="kitchen-order-total-value">
                <?php echo kitchenMoney($subtotal); ?>
            </span>
        </div>
    </div>

    <footer class="kitchen-order-actions">
        <?php if ($canCancel): ?>
        <button type="button" class="btn btn-outline btn-sm kitchen-action-btn" data-action="cancel_order"
            data-order-id="<?php echo $orderId; ?>">
            Cancel
        </button>
        <?php endif; ?>

        <?php if ($canStartPreparing): ?>
        <button type="button" class="btn btn-primary btn-sm kitchen-action-btn" data-action="start_preparing"
            data-order-id="<?php echo $orderId; ?>">
            Start Preparing
        </button>
        <?php endif; ?>

        <?php if ($canAssignRider): ?>
        <button type="button" class="btn btn-outline btn-sm kitchen-action-btn" data-action="assign_rider"
            data-order-id="<?php echo $orderId; ?>" data-reassign="0" data-toggle-modal="rider-modal">
            Assign Rider
        </button>
        <?php endif; ?>

        <?php if ($canReassignRider): ?>
        <button type="button" class="btn btn-outline btn-sm kitchen-action-btn" data-action="reassign_rider"
            data-order-id="<?php echo $orderId; ?>" data-reassign="1"
            data-current-rider="<?php echo htmlspecialchars($assignedRiderName !== '' ? $assignedRiderName : ('#' . $assignedRiderId), ENT_QUOTES, 'UTF-8'); ?>"
            data-toggle-modal="rider-modal">
            Reassign Rider
        </button>
        <?php endif; ?>

        <?php if ($canMessage): ?>
        <button type="button" class="btn btn-neutral btn-sm kitchen-action-btn" data-restaurant-chat-open
            data-restaurant-chat-order-id="<?php echo $orderId; ?>" data-restaurant-chat-counterparty="customer"
            data-restaurant-chat-subtitle="Order #<?php echo $orderId; ?> • <?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?>">
            <img src="<?php echo $assetBase; ?>assets/images/icons/chat-line.svg" alt="" class="btn-icon" width="16"
                height="16"
                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/contact-us-line.svg'">
            <span>Message</span>
        </button>
        <?php endif; ?>
    </footer>
</article>
<?php
    return (string)ob_get_clean();
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