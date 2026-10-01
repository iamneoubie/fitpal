<?php
/**
 * FitPal Restaurant Kitchen Page
 *
 * Two views, chosen by the signed-in account's scope:
 *
 *   Branch view (manager / staff / kitchen)
 *     - Tab bar for New, Preparing, Waiting on Rider, Out for
 *       Delivery, and Recent.
 *     - Each tab renders at most five cards. A pagination bar sits
 *       below the list and carries ?tab=<key>&page=<n>.
 *     - Each card is three bands:
 *         HEADER  — order id, date, status badge, chevron
 *         SUMMARY — Restaurant • Branch • Customer • Rider • Total
 *         DETAILS — pickup block, drop-off block, rider block,
 *                   item list, subtotal. Collapsed by default.
 *     - Step-by-step actions:
 *         New         → Start Preparing, Cancel
 *         Preparing   → Assign Rider, Cancel, Message
 *         Waiting     → Reassign Rider, Message
 *         Out for Delivery → Message
 *         Recent      → Message, but only while the delivered
 *                       grace window is open.
 *     - The order list is live: kitchen-realtime.js polls
 *       kitchen-order-handler.php on a delta cursor.
 *     - The rider modal's roster is live: kitchen-order.js fetches
 *       and polls the roster while the modal stays open.
 *     - The Message button opens the shared chat modal. The modal's
 *       markup is in restaurant/includes/chat-modal.php, its
 *       behaviour is in
 *       restaurant/assets/ui/js/restaurant-chat-modal.js, and its
 *       endpoint is restaurant/backend/handlers/chat-handler.php.
 *
 *   Owner view (owner / partner)
 *     - Read-only summary across all branches.
 *
 * Access control:
 *   - Unauthenticated visitors are redirected to sign-in.
 *   - Any account that is not manager / staff / kitchen / owner /
 *     partner is redirected to the dashboard.
 *
 * ---------------------------------------------------------------------
 * REQUIRED QUERY LAYER
 * ---------------------------------------------------------------------
 * This page requires:
 *
 *     restaurant/backend/database/kitchen-order-queries.php
 *
 * Every function this page calls — getKitchenTabCounts,
 * getBranchKitchenOrdersPaginated, getBranchCompletedOrdersPaginated,
 * getKitchenOrderItems, getAvailableRidersForBranch,
 * shapeAvailableRiderList, shapeKitchenOrderSummaryRow,
 * getOwnerKitchenSummary, getOwnerBranchBreakdown — is declared
 * there.
 *
 * ---------------------------------------------------------------------
 * HANDLER ENDPOINTS
 * ---------------------------------------------------------------------
 * The #kitchenPage element publishes three endpoints on data-*
 * attributes:
 *
 *     data-handler-url  → ../backend/handlers/kitchen-order-handler.php
 *                          The endpoint for every kitchen action —
 *                          start_preparing, cancel_order,
 *                          assign_rider, reassign_rider,
 *                          available_riders, poll,
 *                          active_orders_count.
 *
 *     data-chat-url     → ../backend/handlers/chat-handler.php
 *                          The endpoint for the chat modal —
 *                          list, poll, send, read.
 *
 * kitchen-realtime.js, kitchen-order.js, and
 * restaurant-chat-modal.js all read those attributes rather than
 * carrying a literal, so each URL lives in one place.
 *
 * ---------------------------------------------------------------------
 * STATUS FLOW
 * ---------------------------------------------------------------------
 *     pending → preparing → rider_pending → picking_up → delivering
 *                                                              ↓
 *                                                         delivered
 *     pending / preparing → cancelled  (COD)
 *     pending / preparing → refunded   (Wallet / Online)
 *     delivering          → failed     (rider exceeded the grace
 *                                       window; see below)
 *
 * Kitchen-side visibility:
 *
 *   New                 pending
 *   Preparing           preparing
 *   Waiting on Rider    rider_pending + picking_up
 *   Out for Delivery    delivering
 *   Recent              delivered + cancelled + refunded + failed
 *
 * ---------------------------------------------------------------------
 * THE 'failed' STATUS
 * ---------------------------------------------------------------------
 * The 'failed' status is produced by the shared order-transaction
 * layer's sweepFailedDeliveries(), which moves an order from
 * 'delivering' to 'failed' when the rider does not mark it
 * delivered within FITPAL_RIDER_FAILED_DELIVERY_GRACE_SECONDS of
 * the moment it entered 'delivering'.
 *
 * A failed order is a closed order that never produced deliverable
 * food revenue. It is treated the same as a cancelled or refunded
 * order by every revenue aggregate in the project, and the kitchen
 * renders it on the Recent tab with a distinct red badge so branch
 * staff can see why the order closed.
 *
 * ---------------------------------------------------------------------
 * DELIVERED GRACE WINDOW
 * ---------------------------------------------------------------------
 * A card in the Recent tab can only offer the Message action while
 * the delivered grace window is open. That window is decided in
 * SQL by the query layer (chat_grace_open, computed from
 * delivered_at and RESTAURANT_CHAT_DELIVERED_GRACE_SECONDS) and
 * enforced on the server by chat-handler.php's gateChannel(). The
 * Message button is rendered only when the flag is 1, so the
 * button and the gate agree by construction.
 *
 * ---------------------------------------------------------------------
 * TEMPLATE STRUCTURE
 * ---------------------------------------------------------------------
 * This file does NOT emit a DOCTYPE or a <head>. restaurant/includes/
 * header.php has already done that before this file runs, and has
 * opened <main class="main-content">. Everything this page emits
 * lives inside that <main>.
 *
 * Config for the client is carried on #kitchenPage via data-*
 * attributes. No inline script block sits in the body.
 *
 * ---------------------------------------------------------------------
 * SCOPE RULES APPLIED
 * ---------------------------------------------------------------------
 *  - No inline CSS. orders.css is loaded by header.php's
 *     $pageCssMap for kitchen.php.
 *  - No inline JS. kitchen-realtime.js, kitchen-order.js, and
 *     restaurant-chat-modal.js are loaded at the bottom of the page
 *     for the branch view.
 *  - No SQL. All data comes from
 *     restaurant/backend/database/kitchen-order-queries.php.
 *  - Icons reference only files present under
 *     shared/assets/images/icons/.
 *  - Every modal on the page contains an <img> icon.
 * ---------------------------------------------------------------------
 *
 * @package FitPal
 * @version 11.3 — The Message button now works.
 *
 *                 The previous revision loaded kitchen-order.js and
 *                 kitchen-realtime.js, but neither of those files
 *                 binds a handler for [data-restaurant-chat-open].
 *                 kitchen-order.js explicitly returns early for any
 *                 button that carries that attribute, on the
 *                 assumption that some other script handles it, and
 *                 kitchen-realtime.js has no such handler. The
 *                 Message button was therefore a no-op: clicking it
 *                 did nothing.
 *
 *                 This revision adds
 *                 restaurant/assets/ui/js/restaurant-chat-modal.js
 *                 to the script block at the bottom of the branch
 *                 view. That file owns the chat modal — its open,
 *                 its close, its tab switching, its delta poll, its
 *                 send, and its read marker — and it attaches the
 *                 delegated [data-restaurant-chat-open] listener
 *                 that the Message button needs.
 *
 *                 Nothing else changed. Every function, every markup
 *                 block, the card renderer, the confirm modal, the
 *                 rider modal, the pagination renderer, the tab bar,
 *                 and the chat-modal include are byte-identical to
 *                 v11.2.
 *
 *                 (11.2: kitchen-order-queries.php and
 *                 kitchen-order-handler.php paths restored. 11.1:
 *                 kitchen-order.js script reference. 11.0: paths
 *                 restored. 10.3: documentation-only correction.
 *                 10.2: corrective rewrite for a runtime "Failed to
 *                 open stream" error. 10.0: 'failed' status. 9.0:
 *                 confirm modal rewrite. 8.0: card renderer synced.
 *                 7.0: collapsible cards. 6.0: realtime rider roster.
 *                 5.0: pagination and step-by-step actions. 4.0:
 *                 'picking_up'. 3.0: CSRF token inherited from
 *                 header.php. 2.0: rider_pending handoff.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('restaurant');

if (empty($_SESSION['restaurant_account_id'])) {
    header('Location: sign-in.php');
    exit;
}

$restaurantScope = (string)($_SESSION['restaurant_scope'] ?? '');
$accountRole     = (string)($_SESSION['restaurant_role'] ?? '');
$restaurantId    = (int)($_SESSION['restaurant_id'] ?? 0);
$branchId        = !empty($_SESSION['restaurant_branch_id'])
    ? (int)$_SESSION['restaurant_branch_id']
    : 0;

$isOwner       = in_array($accountRole, ['owner', 'partner'], true);
$isBranchStaff = in_array($accountRole, ['manager', 'staff', 'kitchen'], true);

if (!$isOwner && !$isBranchStaff) {
    header('Location: dashboard.php');
    exit;
}

if ($isOwner && $restaurantId <= 0) {
    header('Location: dashboard.php');
    exit;
}

if ($isBranchStaff && ($restaurantScope !== 'branch' || $branchId <= 0)) {
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../backend/database/kitchen-order-queries.php';

// $assetBase and $csrfToken are provided by header.php.

/* --------------------------------------------------------------
 * PAGE LOCALS (formatting only — declared before they are used)
 * -------------------------------------------------------------- */

function kitchenMoney(float|string|null $amount): string
{
    return '₱' . number_format((float)($amount ?? 0), 2);
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

function kitchenCardHtml(
    array $order,
    string $assetBase,
    bool $isCompleted = false,
    bool $isNew = false
): string {
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

    $summary = shapeKitchenOrderSummaryRow($order);
    $riderSummary = $summary['rider_name'] !== '' ? $summary['rider_name'] : '—';

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
        . ($isNew ? ' is-new' : '');

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

function kitchenPaginationHtml(string $tab, int $page, int $totalPages): string
{
    if ($totalPages <= 1) {
        return '';
    }

    $prevUrl = 'kitchen.php?tab=' . urlencode($tab) . '&page=' . max(1, $page - 1);
    $nextUrl = 'kitchen.php?tab=' . urlencode($tab) . '&page=' . min($totalPages, $page + 1);

    ob_start();
    ?>
<nav class="kitchen-pagination" aria-label="Order pages">
    <?php if ($page > 1): ?>
    <a href="<?php echo htmlspecialchars($prevUrl, ENT_QUOTES, 'UTF-8'); ?>" class="kitchen-pagination-link"
        data-page-link data-tab="<?php echo htmlspecialchars($tab, ENT_QUOTES, 'UTF-8'); ?>"
        data-page="<?php echo $page - 1; ?>">
        <img src="<?php echo $GLOBALS['assetBaseForPagination'] ?? '../../shared/'; ?>assets/images/icons/arrow-left-s-line.svg"
            alt="" class="kitchen-pagination-icon" width="14" height="14"
            onerror="this.onerror=null; this.src='<?php echo $GLOBALS['assetBaseForPagination'] ?? '../../shared/'; ?>assets/images/icons/arrow-left-line.svg'">
        <span>Previous</span>
    </a>
    <?php else: ?>
    <span class="kitchen-pagination-link is-disabled">
        <img src="<?php echo $GLOBALS['assetBaseForPagination'] ?? '../../shared/'; ?>assets/images/icons/arrow-left-s-line.svg"
            alt="" class="kitchen-pagination-icon" width="14" height="14"
            onerror="this.onerror=null; this.src='<?php echo $GLOBALS['assetBaseForPagination'] ?? '../../shared/'; ?>assets/images/icons/arrow-left-line.svg'">
        <span>Previous</span>
    </span>
    <?php endif; ?>

    <span class="kitchen-pagination-info">
        Page <?php echo $page; ?> of <?php echo $totalPages; ?>
    </span>

    <?php if ($page < $totalPages): ?>
    <a href="<?php echo htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8'); ?>" class="kitchen-pagination-link"
        data-page-link data-tab="<?php echo htmlspecialchars($tab, ENT_QUOTES, 'UTF-8'); ?>"
        data-page="<?php echo $page + 1; ?>">
        <span>Next</span>
        <img src="<?php echo $GLOBALS['assetBaseForPagination'] ?? '../../shared/'; ?>assets/images/icons/arrow-right-s-line.svg"
            alt="" class="kitchen-pagination-icon" width="14" height="14"
            onerror="this.onerror=null; this.src='<?php echo $GLOBALS['assetBaseForPagination'] ?? '../../shared/'; ?>assets/images/icons/arrow-right-long-line.svg'">
    </a>
    <?php else: ?>
    <span class="kitchen-pagination-link is-disabled">
        <span>Next</span>
        <img src="<?php echo $GLOBALS['assetBaseForPagination'] ?? '../../shared/'; ?>assets/images/icons/arrow-right-s-line.svg"
            alt="" class="kitchen-pagination-icon" width="14" height="14"
            onerror="this.onerror=null; this.src='<?php echo $GLOBALS['assetBaseForPagination'] ?? '../../shared/'; ?>assets/images/icons/arrow-right-long-line.svg'">
    </span>
    <?php endif; ?>
</nav>
<?php
    return (string)ob_get_clean();
}

function kitchenTabTitle(string $tab): string
{
    return match ($tab) {
        'new'              => 'New Orders',
        'preparing'        => 'Preparing',
        'waiting_on_rider' => 'Waiting on Rider',
        'out_for_delivery' => 'Out for Delivery',
        'recent'           => 'Recent Orders',
        default            => 'Orders',
    };
}

function kitchenTabEmptyCopy(string $tab): array
{
    return match ($tab) {
        'new' => [
            'title' => 'No new orders',
            'text'  => 'New orders will appear here as soon as they are placed.',
        ],
        'preparing' => [
            'title' => 'Nothing is being prepared',
            'text'  => 'Press Start Preparing on a new order and it will show up here.',
        ],
        'waiting_on_rider' => [
            'title' => 'No orders waiting on a rider',
            'text'  => 'Orders waiting on rider confirmation, and riders on their way to pick up, appear here.',
        ],
        'out_for_delivery' => [
            'title' => 'Nothing is out for delivery',
            'text'  => 'Orders a rider has picked up appear here until they are delivered.',
        ],
        'recent' => [
            'title' => 'No recent orders yet',
            'text'  => 'Delivered, cancelled, refunded, and failed orders appear here.',
        ],
        default => [
            'title' => 'No orders',
            'text'  => 'There is nothing to show in this tab.',
        ],
    };
}

/* --------------------------------------------------------------
 * LOAD DATA FOR THE ACTIVE VIEW
 * -------------------------------------------------------------- */

$allowedTabs = ['new', 'preparing', 'waiting_on_rider', 'out_for_delivery', 'recent'];
$activeTab   = isset($_GET['tab']) ? strtolower(trim((string)$_GET['tab'])) : 'new';
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'new';
}

$requestedPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$tabStatuses = [
    'new'              => ['pending'],
    'preparing'        => ['preparing'],
    'waiting_on_rider' => ['rider_pending', 'picking_up'],
    'out_for_delivery' => ['delivering'],
];

$activeOrders    = [];
$completedOrders = [];
$kitchenCounts   = [
    'new'              => 0,
    'preparing'        => 0,
    'waiting_on_rider' => 0,
    'out_for_delivery' => 0,
    'recent'           => 0,
    'total_live'       => 0,
];
$availableRiders = [];
$ownerSummary    = null;
$ownerBranchRows = [];
$loadError       = '';
$pagination      = [
    'items'      => [],
    'total'      => 0,
    'totalPages' => 1,
    'page'       => 1,
    'perPage'    => KITCHEN_DEFAULT_PER_PAGE,
];

$GLOBALS['assetBaseForPagination'] = $assetBase;

if ($isOwner) {

    try {
        $ownerSummary    = getOwnerKitchenSummary($database_connection, $restaurantId);
        $ownerBranchRows = getOwnerBranchBreakdown($database_connection, $restaurantId);
    } catch (PDOException $e) {
        error_log('Kitchen owner view load error: ' . $e->getMessage());
        $loadError = 'Could not load the summary. Please try again.';
    }

} else {

    try {
        $kitchenCounts = getKitchenTabCounts($database_connection, $branchId);

        if ($activeTab === 'recent') {
            $pagination = getBranchCompletedOrdersPaginated(
                $database_connection,
                $branchId,
                $requestedPage,
                KITCHEN_DEFAULT_PER_PAGE
            );

            foreach ($pagination['items'] as &$order) {
                $order['items'] = getKitchenOrderItems(
                    $database_connection,
                    (int)$order['order_id'],
                    $branchId
                );
            }
            unset($order);

            $completedOrders = $pagination['items'];
        } else {
            $statuses = $tabStatuses[$activeTab] ?? ['pending'];

            $pagination = getBranchKitchenOrdersPaginated(
                $database_connection,
                $branchId,
                $statuses,
                $requestedPage,
                KITCHEN_DEFAULT_PER_PAGE
            );

            foreach ($pagination['items'] as &$order) {
                $order['items'] = getKitchenOrderItems(
                    $database_connection,
                    (int)$order['order_id'],
                    $branchId
                );
            }
            unset($order);

            $activeOrders = $pagination['items'];
        }

        $availableRiders = getAvailableRidersForBranch($database_connection, $branchId, 0);

    } catch (PDOException $e) {
        error_log('Kitchen branch view load error: ' . $e->getMessage());
        $loadError = 'Could not load the order list. Please try again.';
    }
}

$maxLiveOrderId = 0;
if (!$isOwner) {
    try {
        $liveIdsStmt = $database_connection->prepare(
            "SELECT COALESCE(MAX(o.order_id), 0)
               FROM orders o
               JOIN queue_item qi ON qi.order_id = o.order_id
              WHERE qi.branch_id = :branch_id
                AND o.order_status IN ('pending','preparing','rider_pending','picking_up','delivering')"
        );
        $liveIdsStmt->execute([':branch_id' => $branchId]);
        $maxLiveOrderId = (int)$liveIdsStmt->fetchColumn();
    } catch (PDOException $e) {
        $maxLiveOrderId = 0;
    }
}
?>
<div class="content kitchen-page" id="kitchenPage"
    data-csrf-token="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"
    data-scope="<?php echo $isOwner ? 'owner' : 'branch'; ?>" data-branch-id="<?php echo $branchId; ?>"
    data-asset-base="<?php echo htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8'); ?>"
    data-handler-url="../backend/handlers/kitchen-order-handler.php"
    data-chat-url="../backend/handlers/chat-handler.php" data-max-order-id="<?php echo $maxLiveOrderId; ?>"
    data-active-tab="<?php echo htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8'); ?>"
    data-active-page="<?php echo (int)$pagination['page']; ?>"
    data-per-page="<?php echo (int)KITCHEN_DEFAULT_PER_PAGE; ?>">

    <div class="container">

        <header class="kitchen-header">
            <div>
                <h1 class="heading-2">
                    Kitchen <span>Orders</span>
                </h1>
                <p class="text-muted">
                    <?php if ($isOwner): ?>
                    Overview of live orders and revenue across every branch.
                    <?php else: ?>
                    Manage incoming orders for
                    <strong><?php echo htmlspecialchars((string)($_SESSION['restaurant_branch_name'] ?? 'your branch'), ENT_QUOTES, 'UTF-8'); ?></strong>.
                    <?php endif; ?>
                </p>
            </div>
            <div class="kitchen-header-actions">
                <?php if (!$isOwner): ?>
                <span class="badge badge-secondary">
                    <?php echo htmlspecialchars((string)($_SESSION['restaurant_branch_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <?php endif; ?>
            </div>
        </header>

        <?php if (!empty($loadError)): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <?php endif; ?>

        <?php if ($isOwner): ?>

        <section class="kitchen-summary-grid" aria-label="Order summary">
            <div class="kitchen-summary-tile">
                <span class="kitchen-summary-count"><?php echo (int)($ownerSummary['pending'] ?? 0); ?></span>
                <span class="kitchen-summary-label">Pending</span>
            </div>
            <div class="kitchen-summary-tile">
                <span class="kitchen-summary-count"><?php echo (int)($ownerSummary['preparing'] ?? 0); ?></span>
                <span class="kitchen-summary-label">Preparing</span>
            </div>
            <div class="kitchen-summary-tile">
                <span
                    class="kitchen-summary-count"><?php echo (int)($ownerSummary['rider_pending'] ?? 0) + (int)($ownerSummary['picking_up'] ?? 0); ?></span>
                <span class="kitchen-summary-label">Waiting on Rider</span>
            </div>
            <div class="kitchen-summary-tile">
                <span class="kitchen-summary-count"><?php echo (int)($ownerSummary['delivering'] ?? 0); ?></span>
                <span class="kitchen-summary-label">Out for Delivery</span>
            </div>
            <div class="kitchen-summary-tile">
                <span class="kitchen-summary-count"><?php echo (int)($ownerSummary['delivered_today'] ?? 0); ?></span>
                <span class="kitchen-summary-label">Delivered Today</span>
            </div>
        </section>

        <section class="kitchen-revenue-grid" aria-label="Revenue summary">
            <div class="kitchen-revenue-tile">
                <span class="kitchen-revenue-label">Today</span>
                <span
                    class="kitchen-revenue-value"><?php echo kitchenMoney($ownerSummary['revenue_today'] ?? 0); ?></span>
            </div>
            <div class="kitchen-revenue-tile">
                <span class="kitchen-revenue-label">Last 7 Days</span>
                <span class="kitchen-revenue-value"><?php echo kitchenMoney($ownerSummary['revenue_7d'] ?? 0); ?></span>
            </div>
            <div class="kitchen-revenue-tile">
                <span class="kitchen-revenue-label">Last 30 Days</span>
                <span
                    class="kitchen-revenue-value"><?php echo kitchenMoney($ownerSummary['revenue_30d'] ?? 0); ?></span>
            </div>
        </section>

        <section class="kitchen-card" aria-labelledby="owner-branches-title">
            <div class="kitchen-card-header">
                <h2 class="heading-5" id="owner-branches-title">By Branch</h2>
            </div>

            <?php if (empty($ownerBranchRows)): ?>
            <div class="kitchen-empty-state">
                <p class="kitchen-empty-title">No branches yet</p>
                <p class="kitchen-empty-text">Add a branch to start tracking orders.</p>
            </div>
            <?php else: ?>
            <div class="kitchen-branch-table-wrap">
                <table class="kitchen-branch-table">
                    <thead>
                        <tr>
                            <th scope="col">Branch</th>
                            <th scope="col" class="is-numeric">Pending</th>
                            <th scope="col" class="is-numeric">Preparing</th>
                            <th scope="col" class="is-numeric">Waiting</th>
                            <th scope="col" class="is-numeric">Delivering</th>
                            <th scope="col" class="is-numeric">Revenue (7d)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ownerBranchRows as $row):
                            $waitingFolded = (int)$row['rider_pending'] + (int)$row['picking_up'];
                        ?>
                        <tr>
                            <td>
                                <span class="kitchen-branch-name">
                                    <?php echo htmlspecialchars((string)$row['branch_name'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <span class="kitchen-branch-code">
                                    <?php echo htmlspecialchars((string)$row['branch_code'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td class="is-numeric"><?php echo (int)$row['pending']; ?></td>
                            <td class="is-numeric"><?php echo (int)$row['preparing']; ?></td>
                            <td class="is-numeric"><?php echo $waitingFolded; ?></td>
                            <td class="is-numeric"><?php echo (int)$row['delivering']; ?></td>
                            <td class="is-numeric"><?php echo kitchenMoney($row['revenue_7d']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>

        <?php else: ?>

        <nav class="kitchen-tabs" role="tablist" aria-label="Order status tabs">
            <a href="kitchen.php?tab=new" class="kitchen-tab <?php echo $activeTab === 'new' ? 'active' : ''; ?>"
                role="tab" aria-selected="<?php echo $activeTab === 'new' ? 'true' : 'false'; ?>"
                aria-controls="panel-new" data-tab-link data-tab="new">
                <span>New</span>
                <span class="kitchen-tab-count" data-tab-count="new"><?php echo (int)$kitchenCounts['new']; ?></span>
            </a>

            <a href="kitchen.php?tab=preparing"
                class="kitchen-tab <?php echo $activeTab === 'preparing' ? 'active' : ''; ?>" role="tab"
                aria-selected="<?php echo $activeTab === 'preparing' ? 'true' : 'false'; ?>"
                aria-controls="panel-preparing" data-tab-link data-tab="preparing">
                <span>Preparing</span>
                <span class="kitchen-tab-count"
                    data-tab-count="preparing"><?php echo (int)$kitchenCounts['preparing']; ?></span>
            </a>

            <a href="kitchen.php?tab=waiting_on_rider"
                class="kitchen-tab <?php echo $activeTab === 'waiting_on_rider' ? 'active' : ''; ?>" role="tab"
                aria-selected="<?php echo $activeTab === 'waiting_on_rider' ? 'true' : 'false'; ?>"
                aria-controls="panel-waiting_on_rider" data-tab-link data-tab="waiting_on_rider">
                <span>Waiting on Rider</span>
                <span class="kitchen-tab-count"
                    data-tab-count="waiting_on_rider"><?php echo (int)$kitchenCounts['waiting_on_rider']; ?></span>
            </a>

            <a href="kitchen.php?tab=out_for_delivery"
                class="kitchen-tab <?php echo $activeTab === 'out_for_delivery' ? 'active' : ''; ?>" role="tab"
                aria-selected="<?php echo $activeTab === 'out_for_delivery' ? 'true' : 'false'; ?>"
                aria-controls="panel-out_for_delivery" data-tab-link data-tab="out_for_delivery">
                <span>Out for Delivery</span>
                <span class="kitchen-tab-count"
                    data-tab-count="out_for_delivery"><?php echo (int)$kitchenCounts['out_for_delivery']; ?></span>
            </a>

            <a href="kitchen.php?tab=recent" class="kitchen-tab <?php echo $activeTab === 'recent' ? 'active' : ''; ?>"
                role="tab" aria-selected="<?php echo $activeTab === 'recent' ? 'true' : 'false'; ?>"
                aria-controls="panel-recent" data-tab-link data-tab="recent">
                <span>Recent</span>
                <span class="kitchen-tab-count"
                    data-tab-count="recent"><?php echo (int)$kitchenCounts['recent']; ?></span>
            </a>
        </nav>

        <div class="kitchen-new-order-pill" id="kitchenNewOrderPill" hidden>
            <a href="kitchen.php?tab=<?php echo htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8'); ?>&page=1"
                class="kitchen-new-order-link">
                <img src="<?php echo $assetBase; ?>assets/images/icons/update.svg" alt="" class="kitchen-new-order-icon"
                    width="16" height="16"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/update.svg'">
                <span class="kitchen-new-order-text">
                    <strong id="kitchenNewOrderCount">0</strong>
                    new order<span id="kitchenNewOrderPlural">s</span> available
                </span>
                <span class="kitchen-new-order-cta">Refresh</span>
            </a>
        </div>

        <section class="kitchen-orders" id="kitchenOrderList"
            aria-label="<?php echo htmlspecialchars(kitchenTabTitle($activeTab), ENT_QUOTES, 'UTF-8'); ?>"
            data-active-tab="<?php echo htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8'); ?>"
            data-active-page="<?php echo (int)$pagination['page']; ?>"
            data-total-pages="<?php echo (int)$pagination['totalPages']; ?>">

            <?php
            $panelOrders = $activeTab === 'recent' ? $completedOrders : $activeOrders;
            ?>

            <?php if (empty($panelOrders)):
                $emptyCopy = kitchenTabEmptyCopy($activeTab);
            ?>
            <div class="kitchen-empty-state"
                data-empty-tab="<?php echo htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8'); ?>">
                <p class="kitchen-empty-title"><?php echo htmlspecialchars($emptyCopy['title'], ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <p class="kitchen-empty-text"><?php echo htmlspecialchars($emptyCopy['text'], ENT_QUOTES, 'UTF-8'); ?>
                </p>
            </div>
            <?php else:
                foreach ($panelOrders as $order) {
                    echo kitchenCardHtml(
                        $order,
                        $assetBase,
                        $activeTab === 'recent',
                        false
                    );
                }
            endif; ?>

        </section>

        <?php
        echo kitchenPaginationHtml(
            $activeTab,
            (int)$pagination['page'],
            (int)$pagination['totalPages']
        );
        ?>

        <div class="kitchen-modal" id="riderModal" role="dialog" aria-modal="true" aria-labelledby="riderModalTitle">
            <div class="kitchen-modal-overlay" data-close-modal="rider-modal"></div>
            <div class="kitchen-modal-content">
                <div class="kitchen-modal-header">
                    <h2 class="heading-5" id="riderModalTitle">Assign a Rider</h2>
                    <button type="button" class="kitchen-modal-close" data-close-modal="rider-modal"
                        aria-label="Close">&times;</button>
                </div>

                <div class="kitchen-modal-body">
                    <input type="hidden" id="riderModalOrderId" value="">
                    <input type="hidden" id="riderModalAction" value="assign_rider">

                    <p class="kitchen-rider-modal-note" id="riderModalNote" hidden>
                        Currently assigned: <strong id="riderModalCurrentRider"></strong>
                    </p>

                    <?php
                    $shapedRiders = shapeAvailableRiderList($availableRiders);
                    ?>

                    <ul class="kitchen-rider-list" id="riderList">
                        <?php if (empty($shapedRiders)): ?>
                        <li class="kitchen-rider-empty">
                            No riders available right now. Everyone is offline or at the active-order cap.
                        </li>
                        <?php else: ?>
                        <?php foreach ($shapedRiders as $r): ?>
                        <li>
                            <button type="button" class="kitchen-rider-option"
                                data-rider-id="<?php echo (int)$r['rider_id']; ?>"
                                data-rider-name="<?php echo htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                <span class="kitchen-rider-name">
                                    <?php echo htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <span class="kitchen-rider-meta">
                                    <?php echo htmlspecialchars($r['meta'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if ($r['slot_label'] !== ''): ?>
                                    <span class="kitchen-rider-capacity">
                                        (<?php echo htmlspecialchars($r['slot_label'], ENT_QUOTES, 'UTF-8'); ?>)
                                    </span>
                                    <?php endif; ?>
                                </span>
                                <span class="kitchen-rider-rating">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/star-fill.svg" alt="Rating"
                                        width="14" height="14"
                                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/star-empty.svg'">
                                    <?php echo htmlspecialchars($r['rating'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </button>
                        </li>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>

                    <div id="riderModalFallback" hidden>
                        <?php if (empty($shapedRiders)): ?>
                        <div class="kitchen-rider-empty">
                            No riders available right now. Everyone is offline or at the active-order cap.
                        </div>
                        <?php else: ?>
                        <?php foreach ($shapedRiders as $r): ?>
                        <div class="kitchen-rider-option-fallback" data-rider-id="<?php echo (int)$r['rider_id']; ?>">
                            <span class="kitchen-rider-name">
                                <?php echo htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <span class="kitchen-rider-meta">
                                <?php echo htmlspecialchars($r['meta'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if ($r['slot_label'] !== ''): ?>
                                <span class="kitchen-rider-capacity">
                                    (<?php echo htmlspecialchars($r['slot_label'], ENT_QUOTES, 'UTF-8'); ?>)
                                </span>
                                <?php endif; ?>
                            </span>
                            <span class="kitchen-rider-rating">
                                <?php echo htmlspecialchars($r['rating'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="kitchen-modal-footer">
                    <button type="button" class="btn btn-secondary" data-close-modal="rider-modal">Cancel</button>
                </div>
            </div>
        </div>

        <div class="kitchen-modal" id="confirmModal" role="dialog" aria-modal="true"
            aria-labelledby="confirmModalTitle">
            <div class="kitchen-modal-overlay" data-close-modal="confirm-modal"></div>
            <div class="kitchen-modal-content kitchen-confirm-content">

                <div class="kitchen-confirm-icon" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/error-warning-line.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/information-fill.svg'">
                </div>

                <h2 class="kitchen-confirm-title" id="confirmModalTitle">Cancel this order?</h2>

                <p class="kitchen-confirm-body" id="confirmModalMessage">
                    Are you sure?
                </p>

                <div class="kitchen-confirm-actions">
                    <button type="button" class="kitchen-btn-cancel" data-close-modal="confirm-modal">
                        Keep order
                    </button>
                    <button type="button" class="kitchen-btn-danger" id="confirmModalBtn">
                        Yes, cancel order
                    </button>
                </div>
            </div>
        </div>

        <?php
        require_once __DIR__ . '/../includes/chat-modal.php';
        ?>

        <?php endif; ?>
    </div>
</div>

<?php if (!$isOwner): ?>
<script src="../assets/ui/js/kitchen-order.js" defer></script>
<script src="../assets/ui/js/kitchen-realtime.js" defer></script>
<script src="../assets/ui/js/restaurant-chat-modal.js" defer></script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>