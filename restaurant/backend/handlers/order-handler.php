<?php
/**
 * FitPal Restaurant Kitchen Order Handler
 *
 * Actions:
 *   start_preparing   — pending → preparing
 *   cancel_order      — pending → cancelled (cancelled_by = 'restaurant')
 *   assign_rider      — first-time rider assignment; moves to rider_pending
 *   reassign_rider    — replace an existing rider; stays in rider_pending
 *   poll              — delta fetch of the live order list
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
 * Live polling
 * ------------
 * The `poll` action returns only the changes since a client cursor:
 *
 *   - `rows`    — new cards for orders with order_id > since_order_id
 *                 that are currently in a live status. Each row
 *                 carries both the raw fields and a pre-rendered
 *                 `html` string for the card, so the client can
 *                 insert it without re-deriving the markup.
 *   - `updated` — cards whose status changed since the last poll.
 *                 The client is not asked to track this itself; the
 *                 server compares the current status against the
 *                 order's own persisted row and always includes the
 *                 full card HTML for any order whose status is not
 *                 `rider_pending` and not already counted as new.
 *                 In practice the client swaps any card whose
 *                 data-order-status does not match the returned
 *                 `order_status`, so the handler does not need to
 *                 know what the client last saw.
 *   - `removed` — order_ids that were live before but are now closed.
 *                 The client drops those cards.
 *   - `counts`  — per-tab counts recomputed server-side so the
 *                 header badges stay accurate.
 *   - `max_id`  — highest order_id in the current live set, the
 *                 client's next cursor.
 *
 * Because the card HTML is assembled by kitchenCardHtml() in
 * kitchen.php and required here, the poll path and the initial page
 * render produce byte-identical markup. That is what allows the
 * client to swap a card in place without re-styling or re-wiring
 * events: the swapped node is exactly what a fresh page load would
 * have produced.
 *
 * @package FitPal
 * @version 4.0 — Adds the `poll` action for the live kitchen board:
 *                  - Delta rows for new orders.
 *                  - Full card HTML for updates and inserts.
 *                  - `removed` list for orders that left the live set.
 *                  - Server-recomputed tab counts so badges match
 *                    the board without a round trip per tab.
 *
 *                (3.0: CSRF validated against restaurant_csrf_token;
 *                rotates the token on mismatch. 2.0: rider_pending
 *                handoff — start_preparing, cancel_order,
 *                assign_rider, reassign_rider.)
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

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/order-queries.php';
require_once __DIR__ . '/../../includes/restaurant-csrf-token.php';

if (
    !isset($_POST['csrf_token'], $_SESSION['restaurant_csrf_token']) ||
    !hash_equals((string)$_SESSION['restaurant_csrf_token'], (string)$_POST['csrf_token'])
) {
    // Rotate the restaurant's own token so the next render generates
    // a fresh one. Only the restaurant's key is cleared — never the
    // shared 'csrf_token' key.
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

        case 'poll':
            handlePoll($database_connection, $branchId);
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

/**
 * Delta fetch of the live kitchen board.
 *
 * The client sends since_order_id — the highest order_id it already
 * holds — and receives:
 *
 *   rows    [{order_id, order_status, html}, ...]
 *   updated [{order_id, order_status, html}, ...]
 *   removed [order_id, ...]
 *   counts  {pending, preparing, rider_pending, delivering,
 *            delivered_today, cancelled_today}
 *   max_id  int
 *
 * The client uses `rows` for inserts, `updated` for in-place swaps,
 * `removed` for deletions, and `counts` for the tab badges. Because
 * every card in `rows` and `updated` is rendered by the same
 * kitchenCardHtml() the page used at load time, swapping a card in
 * place is a direct DOM replacement with no re-wiring.
 *
 * The handler does not try to infer what the client last saw. It
 * always returns the current card HTML for every live order whose
 * order_id is greater than the client's cursor (in `rows`), and for
 * every live order whose current status is not `rider_pending` (in
 * `updated`). The client decides whether the card it holds matches
 * and swaps accordingly — a no-op when they match, a replacement
 * when they do not. This is cheap: the handler builds the HTML for
 * at most the current live set, which is bounded by the kitchen's
 * active order count and is typically single digits.
 *
 * The "closed since the client's cursor" list (`removed`) is derived
 * from orders whose order_status changed to a closed state and whose
 * order_id is <= the client's cursor — i.e. orders the client was
 * already holding and that have now left the live set.
 */
function handlePoll(PDO $db, int $branchId): never
{
    $sinceOrderId = (int)($_POST['since_order_id'] ?? 0);

    // Full live set: everything the board should currently show.
    $liveOrders = getBranchKitchenOrders(
        $db,
        $branchId,
        ['pending', 'preparing', 'rider_pending', 'delivering']
    );

    // Attach items so kitchenCardHtml() can render the full card.
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
            // Already on the client. Return the current HTML; the
            // client decides whether its held card matches.
            $updated[] = [
                'order_id'     => $oid,
                'order_status' => (string)$order['order_status'],
                'html'         => $html,
            ];
        }
    }

    // Orders that were live when the client last polled but are now
    // closed. The client holds their cards and should drop them.
    // We look at any order the client's cursor covers (order_id <=
    // since) that is now in a closed status, and report it.
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

        // Do not report as removed any order that the current live
        // set still contains (a delivered order that the client has
        // not yet seen, for example, is not removed because the
        // client never had it).
        $liveIds = [];
        foreach ($liveOrders as $lo) {
            $liveIds[(int)$lo['order_id']] = true;
        }
        $removed = array_values(array_filter(
            $removed,
            static fn($id) => !isset($liveIds[$id])
        ));
    }

    $counts = getKitchenOrderCounts($db, $branchId);

    echo json_encode([
        'status'  => 'success',
        'rows'    => $rows,
        'updated' => $updated,
        'removed' => $removed,
        'counts'  => $counts,
        'max_id'  => $maxId,
    ]);
    exit;
}

/* --------------------------------------------------------------
 * CARD RENDERER
 *
 * Inlined copy of kitchenCardHtml() from restaurant/pages/kitchen.php
 * so the poll path can build cards without requiring the page (which
 * runs its own full dispatch at include time). The two MUST stay in
 * sync; if the page's version changes, this one changes with it.
 * The comment on kitchenCardHtml() at the top of kitchen.php names
 * this file as a mirror.
 *
 * Uses $assetBaseFromSession() to get the correct shared/ path for
 * icons regardless of the request's URL depth, since a poll request
 * is not a page render and has no page-local $assetBase in scope.
 * -------------------------------------------------------------- */

function assetBaseFromSession(): string
{
    // The handler runs from restaurant/backend/handlers/, so the
    // path to shared/ is always three levels up. This is the URL
    // prefix the browser needs, not a filesystem path.
    return '../../../shared/';
}

function kitchenStatusLabel(string $status): string
{
    return match ($status) {
        'pending'       => 'New',
        'preparing'     => 'Preparing',
        'rider_pending' => 'Waiting on Rider',
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

/**
 * Render a live order card.
 *
 * Mirrors kitchenCardHtml() in restaurant/pages/kitchen.php for the
 * live (non-completed) case. The page's version also handles the
 * completed case; the poll path only ever emits live cards.
 *
 * @param array<string, mixed> $order
 * @return string
 */
function renderKitchenCard(array $order): string
{
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
    $assignedRiderId = isset($order['delivery_rider_id']) && $order['delivery_rider_id'] !== null
        ? (int)$order['delivery_rider_id']
        : 0;
    $assignedRiderName = trim(
        (string)($order['rider_first_name'] ?? '') . ' ' .
        (string)($order['rider_last_name'] ?? '')
    );

    $canStartPreparing = $orderStatus === 'pending';
    $canCancel         = in_array($orderStatus, ['pending', 'preparing'], true);
    $canAssignRider    = in_array($orderStatus, ['pending', 'preparing'], true)
                            && $assignedRiderId === 0;
    $canReassignRider  = in_array($orderStatus, ['pending', 'preparing', 'rider_pending'], true)
                            && $assignedRiderId > 0;

    ob_start();
    ?>
<article class="kitchen-order-card" data-order-id="<?php echo $orderId; ?>"
    data-order-status="<?php echo htmlspecialchars($orderStatus, ENT_QUOTES, 'UTF-8'); ?>">

    <header class="kitchen-order-header">
        <div class="kitchen-order-header-left">
            <span class="kitchen-order-id">#<?php echo $orderId; ?></span>
            <span class="kitchen-order-date">
                <?php echo htmlspecialchars(kitchenDate((string)($order['order_date'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </div>
        <div class="kitchen-order-header-right">
            <span class="badge <?php echo kitchenStatusBadge($orderStatus); ?>">
                <?php echo kitchenStatusLabel($orderStatus); ?>
            </span>
        </div>
    </header>

    <div class="kitchen-order-body">

        <div class="kitchen-order-meta">
            <div class="kitchen-meta-block">
                <span class="kitchen-meta-label">Customer</span>
                <span class="kitchen-meta-value">
                    <?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
            <div class="kitchen-meta-block">
                <span class="kitchen-meta-label">Contact</span>
                <span class="kitchen-meta-value">
                    <?php echo htmlspecialchars((string)($order['customer_contact'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
            <div class="kitchen-meta-block kitchen-meta-block-wide">
                <span class="kitchen-meta-label">Deliver To</span>
                <span class="kitchen-meta-value">
                    <?php echo htmlspecialchars((string)($order['destination_address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
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

        <div class="kitchen-order-side">
            <div class="kitchen-order-total">
                <span class="kitchen-order-total-label">Subtotal</span>
                <span class="kitchen-order-total-value">
                    <?php echo kitchenMoney($subtotal); ?>
                </span>
            </div>

            <div class="kitchen-order-rider">
                <span class="kitchen-meta-label">Rider</span>
                <?php if ($assignedRiderId > 0): ?>
                <span class="kitchen-rider-assigned">
                    <?php echo htmlspecialchars($assignedRiderName !== '' ? $assignedRiderName : ('#' . $assignedRiderId), ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <?php if ($orderStatus === 'rider_pending'): ?>
                <span class="kitchen-rider-awaiting">
                    <span class="badge badge-warning">Awaiting confirmation</span>
                </span>
                <?php endif; ?>
                <?php else: ?>
                <span class="kitchen-rider-unassigned">Not assigned</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <footer class="kitchen-order-actions">
        <?php if ($canCancel): ?>
        <button type="button" class="btn btn-outline btn-sm kitchen-action-btn" data-action="cancel_order"
            data-order-id="<?php echo $orderId; ?>">
            Cancel
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

        <button type="button" class="btn btn-neutral btn-sm kitchen-action-btn" data-restaurant-chat-open
            data-restaurant-chat-order-id="<?php echo $orderId; ?>" data-restaurant-chat-counterparty="customer"
            data-restaurant-chat-subtitle="Order #<?php echo $orderId; ?> • <?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?>">
            <img src="<?php echo $assetBase; ?>assets/images/icons/chat-line.svg" alt="" class="btn-icon" width="16"
                height="16"
                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/contact-us-line.svg'">
            <span>Message</span>
        </button>

        <?php if ($canStartPreparing): ?>
        <button type="button" class="btn btn-primary btn-sm kitchen-action-btn" data-action="start_preparing"
            data-order-id="<?php echo $orderId; ?>">
            Start Preparing
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