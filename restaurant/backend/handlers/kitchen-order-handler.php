<?php
/**
 * FitPal Kitchen Order Handler
 *
 * The restaurant-side dispatch endpoint for every kitchen action:
 * starting preparation, assigning and reassigning riders, fetching
 * the rider roster, polling for board updates, reading the branch's
 * live-order count for the sign-out guard, and cancelling an order.
 *
 * ---------------------------------------------------------------------
 * CARD RENDERER
 * ---------------------------------------------------------------------
 * renderKitchenCard() produces the exact same markup as
 * kitchenCardHtml() in restaurant/pages/kitchen.php. The two MUST
 * stay in sync. Any change to the card structure must be applied to
 * both files in the same commit.
 *
 * The card is a two-state component:
 *
 *   COLLAPSED:
 *     HEADER    thumbnail + order id + first item name + payment
 *               pill + status badge + chevron
 *     SUMMARY   subtotal + compact special instructions
 *
 *   EXPANDED:
 *     HEADER    same as collapsed
 *     DETAILS   route (pickup + drop-off), full special
 *               instructions, rider block, item list with per-item
 *               prices and customization modifiers, and a full
 *               order totals breakdown (Subtotal, Delivery Fee,
 *               Service Fee, VAT, Total)
 *     FOOTER    action buttons
 *
 * The summary band is hidden when the card is expanded, because the
 * details band carries the same information plus the full breakdown.
 *
 * Special instructions are shown in the summary band as a single
 * compact line and in the details band as a full warning-toned
 * block, so the kitchen sees them at a glance and again in full.
 *
 * The 'failed' status is produced by the shared order-transaction
 * layer's sweepFailedDeliveries() when a rider does not complete a
 * delivery in time. kitchenStatusLabel() and kitchenStatusBadge()
 * carry a 'failed' case that matches the page's helpers exactly.
 *
 * ---------------------------------------------------------------------
 * RESPONSE SHAPE
 * ---------------------------------------------------------------------
 * JSON. Business-rule refusals return HTTP 200 with
 * {status:'error', message:'...'}; only auth failures return 401
 * and CSRF mismatches return 403.
 *
 * @package FitPal
 * @version 15.0 — The customization list now shows price modifiers,
 *                 matching the customer order receipt format.
 *
 *                (14.0: card renderer synced with kitchen.php v16.0.
 *                13.0: summary band added. 12.0: card renderer
 *                synced with kitchen.php v14.0. 11.0: card renderer
 *                synced with kitchen.php v11.0. 10.3: cancellation
 *                delegation. 10.2: corrected query-layer require.
 *                10.1: 'failed' status. 10.0: renamed. 9.0: refund
 *                on cancellation. 8.1: kitchen can cancel
 *                'preparing' orders.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('restaurant');

header('Content-Type: application/json; charset=utf-8');

/* --------------------------------------------------------------
 * GRACE CONSTANT
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
require_once __DIR__ . '/../database/kitchen-order-queries.php';

require_once __DIR__ . '/../../includes/restaurant-csrf-token.php';

/* --------------------------------------------------------------
 * CSRF
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

if ($action === 'cancel_order') {

    if ($orderId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid order.']);
        exit;
    }

    $endpoint = __DIR__ . '/../../../shared/backend/handlers/order-transaction-handler.php';

    if (!is_file($endpoint)) {
        error_log('Kitchen order handler: shared order-transaction handler is missing at ' . $endpoint);
        echo json_encode([
            'status'  => 'error',
            'message' => 'The order service is temporarily unavailable. Please try again.',
        ]);
        exit;
    }

    $_POST['action'] = 'restaurant_cancel_order';

    require $endpoint;

    // The shared handler always exits; this line is unreachable.
    exit;
}

try {
    switch ($action) {

        case 'start_preparing':
            handleStartPreparing($database_connection, $orderId, $branchId);
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
        'recent'           => ['delivered', 'cancelled', 'refunded', 'failed'],
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

        $html = renderKitchenCard($order, false);

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
                AND o.order_status IN ('delivered','cancelled','refunded','failed')"
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
        'failed'        => 'Failed',
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
        'failed'        => 'badge-danger',
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

function kitchenPaymentMeta(string $method): array
{
    return match ($method) {
        'COD'    => ['icon' => 'coin-line.svg',    'label' => 'Cash on Delivery', 'slug' => 'cod'],
        'Wallet' => ['icon' => 'wallet-fill.svg',  'label' => 'Wallet',           'slug' => 'wallet'],
        'Online' => ['icon' => 'qr-code-line.svg', 'label' => 'Online Payment',   'slug' => 'online'],
        default  => ['icon' => 'coin-line.svg',    'label' => $method,            'slug' => 'other'],
    };
}

/**
 * Gather every non-empty custom_instructions value from an order's
 * items, in item order, without duplicates.
 *
 * @param array<int, array<string, mixed>> $items
 * @return array<int, string>
 */
function kitchenCollectSpecialInstructions(array $items): array
{
    $out   = [];
    $seen  = [];

    foreach ($items as $item) {
        $notes = trim((string)($item['custom_instructions'] ?? ''));
        if ($notes === '') {
            continue;
        }
        if (isset($seen[$notes])) {
            continue;
        }
        $seen[$notes] = true;
        $out[]        = $notes;
    }

    return $out;
}

/**
 * Return the first item's resolved image URL for the card
 * thumbnail, or the shared restaurant icon when the order has
 * no items or the URL could not be resolved.
 *
 * @param array<int, array<string, mixed>> $items
 * @param string $assetBase
 * @return string
 */
function kitchenPrimaryItemImage(array $items, string $assetBase): string
{
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $url = trim((string)($item['product_image_url'] ?? ''));
        if ($url !== '') {
            return $url;
        }
    }
    return $assetBase . 'assets/images/icons/restaurant.svg';
}

/**
 * Return the first item's product name, or a fallback string
 * when the order has no items.
 *
 * @param array<int, array<string, mixed>> $items
 * @return string
 */
function kitchenPrimaryItemName(array $items): string
{
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = trim((string)($item['product_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
    }
    return 'Order Items';
}

function renderKitchenCard(
    array $order,
    bool $isCompleted = false
): string {
    $assetBase = assetBaseFromSession();

    $orderId       = (int)($order['order_id'] ?? 0);
    $orderStatus   = (string)($order['order_status'] ?? '');
    $paymentMethod = (string)($order['payment_method'] ?? '');
    $items         = is_array($order['items'] ?? null) ? $order['items'] : [];
    $itemCount     = (int)($order['item_count'] ?? count($items));

    // Calculate the full order totals using the shared fee schedule.
    $branchCount = 1;
    $subtotal = 0.0;
    foreach ($items as $item) {
        $qty = (int)($item['quantity'] ?? 0);
        $price = (float)($item['final_price'] ?? $item['unit_price'] ?? 0);
        $subtotal += $qty * $price;
    }
    $fees = calculateOrderFees($branchCount, $subtotal);
    $orderTotal = round($subtotal + $fees['delivery_fee'] + $fees['service_fee'] + $fees['vat'], 2);

    $customerName = trim(
        (string)($order['customer_first_name'] ?? '') . ' ' .
        (string)($order['customer_last_name'] ?? '')
    );
    if ($customerName === '') {
        $customerName = 'Customer';
    }
    $customerContact = trim((string)($order['customer_contact'] ?? ''));
    $customerContactDigits = $customerContact !== ''
        ? preg_replace('/[^0-9+]/', '', $customerContact)
        : '';
    $destination     = (string)($order['destination_address'] ?? '');

    $assignedRiderId   = isset($order['delivery_rider_id']) && $order['delivery_rider_id'] !== null
        ? (int)$order['delivery_rider_id']
        : 0;
    $assignedRiderName = trim(
        (string)($order['rider_first_name'] ?? '') . ' ' .
        (string)($order['rider_last_name'] ?? '')
    );
    $riderContact = trim((string)($order['rider_contact'] ?? ''));
    $riderContactDigits = $riderContact !== ''
        ? preg_replace('/[^0-9+]/', '', $riderContact)
        : '';

    $restaurantName = trim((string)($order['restaurant_name'] ?? ''));
    $branchName     = trim((string)($order['branch_name'] ?? ''));
    $branchAddress  = kitchenBranchAddressLine($order);
    $riderVehicle   = kitchenRiderVehicleLine($order);

    $paymentMeta = kitchenPaymentMeta($paymentMethod);

    $specialInstructions = kitchenCollectSpecialInstructions($items);

    $canStartPreparing = !$isCompleted && $orderStatus === 'pending';
    $canCancel         = !$isCompleted && in_array($orderStatus, ['pending', 'preparing'], true);
    $canAssignRider    = !$isCompleted
        && $orderStatus === 'preparing'
        && $assignedRiderId === 0;
    $canReassignRider  = !$isCompleted
        && in_array($orderStatus, ['preparing', 'rider_pending'], true)
        && $assignedRiderId > 0;

    $graceOpen   = (int)($order['chat_grace_open'] ?? 0) === 1;
    $canMessage  = !$isCompleted || ($orderStatus === 'delivered' && $graceOpen);

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
        . ($isCompleted ? ' kitchen-order-card-completed' : '');

    $detailsId = 'kitchenOrderDetails' . $orderId;
    $fallbackIcon = $assetBase . 'assets/images/icons/restaurant.svg';

    // First item for the header
    $primaryImage = kitchenPrimaryItemImage($items, $assetBase);
    $primaryName  = kitchenPrimaryItemName($items);

    ob_start();
    ?>
<article class="<?php echo $cardClass; ?>" data-order-id="<?php echo $orderId; ?>"
    data-order-status="<?php echo htmlspecialchars($orderStatus, ENT_QUOTES, 'UTF-8'); ?>">

    <header class="kitchen-order-header">
        <div class="kitchen-order-header-left">
            <div class="kitchen-order-thumb">
                <img src="<?php echo htmlspecialchars($primaryImage, ENT_QUOTES, 'UTF-8'); ?>" alt=""
                    onerror="this.onerror=null; this.src='<?php echo $fallbackIcon; ?>'">
            </div>
            <div class="kitchen-order-header-details">
                <span class="kitchen-order-id">Order #<?php echo $orderId; ?></span>
                <span
                    class="kitchen-order-item-name"><?php echo htmlspecialchars($primaryName, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        </div>

        <div class="kitchen-order-header-right">
            <span class="badge <?php echo kitchenStatusBadge($orderStatus); ?>">
                <?php echo kitchenStatusLabel($orderStatus); ?>
            </span>
            <span
                class="kitchen-payment-pill kitchen-payment-pill-<?php echo htmlspecialchars($paymentMeta['slug'], ENT_QUOTES, 'UTF-8'); ?>">
                <img src="<?php echo $assetBase; ?>assets/images/icons/<?php echo htmlspecialchars($paymentMeta['icon'], ENT_QUOTES, 'UTF-8'); ?>"
                    alt="" aria-hidden="true" class="kitchen-payment-pill-icon"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                <span class="kitchen-payment-pill-label">
                    <?php echo htmlspecialchars($paymentMeta['label'], ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </span>
            <button type="button" class="kitchen-order-toggle" data-row-expand="1"
                aria-controls="<?php echo $detailsId; ?>" aria-expanded="false" aria-label="Toggle order details">
                <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-drop-down-line.svg" alt=""
                    class="kitchen-order-toggle-icon" width="18" height="18"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/arrow-drop-down-line.svg'">
            </button>
        </div>
    </header>

    <!-- Summary band: visible only when collapsed -->
    <div class="kitchen-order-summary">
        <div class="kitchen-order-summary-info">
            <span class="kitchen-order-summary-label">Subtotal</span>
            <span class="kitchen-order-summary-value"><?php echo kitchenMoney($subtotal); ?></span>
        </div>
        <?php if (!empty($specialInstructions)): ?>
        <div class="kitchen-order-summary-instructions">
            <img src="<?php echo $assetBase; ?>assets/images/icons/information-fill.svg" alt="" aria-hidden="true"
                onerror="this.onerror=null; this.style.display='none';">
            <span><?php echo htmlspecialchars(implode(' | ', $specialInstructions), ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <?php endif; ?>
    </div>

    <div class="kitchen-order-details" id="<?php echo $detailsId; ?>" hidden>

        <div class="kitchen-order-meta-line">
            <span class="kitchen-order-date">
                <?php
                echo $isCompleted && $closedAt !== ''
                    ? htmlspecialchars(kitchenDate($closedAt), ENT_QUOTES, 'UTF-8')
                    : htmlspecialchars(kitchenDate((string)($order['order_date'] ?? '')), ENT_QUOTES, 'UTF-8');
                ?>
            </span>
        </div>

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
                    <?php if ($customerContact !== ''): ?>
                    <?php if ($customerContactDigits !== ''): ?>
                    <a href="tel:<?php echo htmlspecialchars($customerContactDigits, ENT_QUOTES, 'UTF-8'); ?>"
                        class="kitchen-route-contact kitchen-route-contact-link">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/phone-fill.svg" alt=""
                            class="kitchen-route-contact-icon" width="14" height="14"
                            onerror="this.onerror=null; this.style.display='none';">
                        <span><?php echo htmlspecialchars($customerContact, ENT_QUOTES, 'UTF-8'); ?></span>
                    </a>
                    <?php else: ?>
                    <span class="kitchen-route-contact">
                        <?php echo htmlspecialchars($customerContact, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($destination !== ''): ?>
                    <span class="kitchen-route-address">
                        <?php echo htmlspecialchars($destination, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!empty($specialInstructions)): ?>
        <div class="kitchen-special-instructions" role="note" aria-label="Special instructions">
            <div class="kitchen-special-instructions-head">
                <img src="<?php echo $assetBase; ?>assets/images/icons/information-fill.svg" alt=""
                    class="kitchen-special-instructions-icon" width="16" height="16"
                    onerror="this.onerror=null; this.style.display='none';">
                <span class="kitchen-special-instructions-title">Special Instructions</span>
            </div>
            <ul class="kitchen-special-instructions-list">
                <?php foreach ($specialInstructions as $note): ?>
                <li><?php echo nl2br(htmlspecialchars($note, ENT_QUOTES, 'UTF-8')); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div class="kitchen-rider-block">
            <span class="kitchen-rider-block-label">Rider</span>
            <?php if ($assignedRiderId > 0): ?>
            <div class="kitchen-rider-block-body">
                <span class="kitchen-rider-block-name">
                    <?php echo htmlspecialchars($assignedRiderName !== '' ? $assignedRiderName : ('#' . $assignedRiderId), ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <?php if ($riderContact !== ''): ?>
                <?php if ($riderContactDigits !== ''): ?>
                <a href="tel:<?php echo htmlspecialchars($riderContactDigits, ENT_QUOTES, 'UTF-8'); ?>"
                    class="kitchen-rider-block-contact kitchen-route-contact-link">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/phone-fill.svg" alt=""
                        class="kitchen-route-contact-icon" width="14" height="14"
                        onerror="this.onerror=null; this.style.display='none';">
                    <span><?php echo htmlspecialchars($riderContact, ENT_QUOTES, 'UTF-8'); ?></span>
                </a>
                <?php else: ?>
                <span class="kitchen-rider-block-contact">
                    <?php echo htmlspecialchars($riderContact, ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <?php endif; ?>
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
                    $itemImage = trim((string)($item['product_image_url'] ?? ''));
                    if ($itemImage === '') {
                        $itemImage = $fallbackIcon;
                    }
                    $unitPrice = (float)($item['final_price'] ?? $item['unit_price'] ?? 0);
                    $itemTotal = $unitPrice * $itemQty;
                ?>
                <li class="kitchen-item">
                    <div class="kitchen-item-row">
                        <div class="kitchen-item-image">
                            <img src="<?php echo htmlspecialchars($itemImage, ENT_QUOTES, 'UTF-8'); ?>"
                                alt="<?php echo htmlspecialchars($itemName, ENT_QUOTES, 'UTF-8'); ?>" loading="lazy"
                                onerror="this.onerror=null; this.src='<?php echo $fallbackIcon; ?>'">
                        </div>
                        <div class="kitchen-item-body">
                            <div class="kitchen-item-line">
                                <span class="kitchen-item-qty"><?php echo $itemQty; ?>&times;</span>
                                <span class="kitchen-item-name">
                                    <?php echo htmlspecialchars($itemName, ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <span class="kitchen-item-price"><?php echo kitchenMoney($itemTotal); ?></span>
                            </div>

                            <?php if (!empty($customs)): ?>
                            <ul class="kitchen-item-customs">
                                <?php foreach ($customs as $cust):
                                    $custName = (string)($cust['ingredient_name'] ?? '');
                                    if ($custName === '') continue;
                                    $isRemoved = (int)($cust['is_removed'] ?? 0) === 1;
                                    $custQty   = (int)($cust['quantity'] ?? 1);
                                    $priceMod  = (float)($cust['price_at_time'] ?? 0);
                                    $priceLabel = '';
                                    if ($priceMod > 0) {
                                        $priceLabel = '(+' . kitchenMoney($priceMod * $custQty) . ')';
                                    } elseif ($priceMod < 0) {
                                        $priceLabel = '(−' . kitchenMoney(abs($priceMod) * $custQty) . ')';
                                    }
                                ?>
                                <li class="kitchen-item-custom <?php echo $isRemoved ? 'is-removed' : ''; ?>">
                                    <?php if ($isRemoved): ?>
                                    <span class="kitchen-custom-mark">&minus;</span>
                                    <span class="kitchen-custom-text">
                                        <?php echo htmlspecialchars($custName, ENT_QUOTES, 'UTF-8'); ?>
                                        <em>(removed)</em>
                                    </span>
                                    <?php else: ?>
                                    <span class="kitchen-custom-mark">+</span>
                                    <span class="kitchen-custom-text">
                                        <?php echo htmlspecialchars($custName, ENT_QUOTES, 'UTF-8'); ?>
                                        <?php if ($custQty > 1): ?> &times; <?php echo $custQty; ?><?php endif; ?>
                                    </span>
                                    <?php if ($priceLabel !== ''): ?>
                                    <span class="kitchen-custom-price"><?php echo $priceLabel; ?></span>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Full order totals breakdown -->
        <div class="kitchen-order-totals">
            <div class="kitchen-totals-row">
                <span class="kitchen-totals-label">Subtotal</span>
                <span class="kitchen-totals-value"><?php echo kitchenMoney($subtotal); ?></span>
            </div>
            <div class="kitchen-totals-row">
                <span class="kitchen-totals-label">Delivery Fee</span>
                <span class="kitchen-totals-value"><?php echo kitchenMoney($fees['delivery_fee']); ?></span>
            </div>
            <div class="kitchen-totals-row">
                <span class="kitchen-totals-label">Service Fee</span>
                <span class="kitchen-totals-value"><?php echo kitchenMoney($fees['service_fee']); ?></span>
            </div>
            <div class="kitchen-totals-row">
                <span class="kitchen-totals-label">VAT</span>
                <span class="kitchen-totals-value"><?php echo kitchenMoney($fees['vat']); ?></span>
            </div>
            <div class="kitchen-totals-row kitchen-totals-grand">
                <span class="kitchen-totals-label">Total</span>
                <span class="kitchen-totals-value"><?php echo kitchenMoney($orderTotal); ?></span>
            </div>
        </div>
    </div>

    <footer class="kitchen-order-actions">
        <?php if ($canCancel): ?>
        <button type="button" class="btn btn-danger kitchen-action-btn" data-action="cancel_order"
            data-order-id="<?php echo $orderId; ?>">
            Cancel
        </button>
        <?php endif; ?>

        <?php if ($canStartPreparing): ?>
        <button type="button" class="btn btn-primary kitchen-action-btn" data-action="start_preparing"
            data-order-id="<?php echo $orderId; ?>" data-confirm-title="Start preparing Order #<?php echo $orderId; ?>?"
            data-confirm-message="This will mark the order as being prepared. This action cannot be undone."
            data-confirm-label="Yes, start preparing">
            Start Preparing
        </button>
        <?php endif; ?>

        <?php if ($canAssignRider): ?>
        <button type="button" class="btn btn-primary kitchen-action-btn" data-action="assign_rider"
            data-order-id="<?php echo $orderId; ?>" data-reassign="0" data-toggle-modal="rider-modal">
            Assign Rider
        </button>
        <?php endif; ?>

        <?php if ($canReassignRider): ?>
        <button type="button" class="btn btn-primary kitchen-action-btn" data-action="reassign_rider"
            data-order-id="<?php echo $orderId; ?>" data-reassign="1"
            data-current-rider="<?php echo htmlspecialchars($assignedRiderName !== '' ? $assignedRiderName : ('#' . $assignedRiderId), ENT_QUOTES, 'UTF-8'); ?>"
            data-toggle-modal="rider-modal">
            Reassign Rider
        </button>
        <?php endif; ?>

        <?php if ($canMessage): ?>
        <button type="button" class="btn btn-neutral kitchen-action-btn" data-restaurant-chat-open
            data-restaurant-chat-order-id="<?php echo $orderId; ?>" data-restaurant-chat-counterparty="customer"
            data-restaurant-chat-subtitle="Order #<?php echo $orderId; ?> • <?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?>">
            <img src="<?php echo $assetBase; ?>assets/images/icons/contact-us-line.svg" alt="" class="btn-icon"
                width="16" height="16"
                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/contact-us-fill.svg'">
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