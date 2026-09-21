<?php
/**
 * FitPal Restaurant Kitchen Page
 *
 * Two views, chosen by the signed-in account's scope:
 *
 *   Branch view (manager / staff / kitchen)
 *     - Tabs for New, Preparing, Waiting on Rider, Out for Delivery,
 *       and Recent (delivered / cancelled / refunded).
 *     - Actions: Start Preparing, Cancel, Assign Rider,
 *       Reassign Rider.
 *     - An order NEVER disappears when its status changes; it slides
 *       into the correct tab.
 *
 *   Owner view (owner / partner)
 *     - Read-only summary across all branches.
 *     - Counts include rider_pending and delivering buckets.
 *     - No action buttons.
 *
 * Access control:
 *   - Unauthenticated visitors are redirected to sign-in.
 *   - Any account that is not manager / staff / kitchen / owner /
 *     partner is redirected to the dashboard.
 *
 * ---------------------------------------------------------------------
 * SCOPE RULES APPLIED
 * ---------------------------------------------------------------------
 *  - No inline CSS. orders.css is loaded via the header's
 *    $pageCssMap and re-linked at the top of this page.
 *  - No inline JS. orders.js is loaded at the bottom of the page.
 *    Config is passed to JS via data-* attributes on #kitchenPage.
 *  - No SQL. All data comes from
 *    restaurant/backend/database/order-queries.php.
 *  - No view helpers. Formatting is done inline where needed.
 *  - Icons reference only files present under
 *    shared/assets/images/icons/.
 * ---------------------------------------------------------------------
 *
 * @package FitPal
 * @version 2.0 — Introduces the rider_pending handoff:
 *                  - Adds Waiting on Rider and Out for Delivery tabs.
 *                  - Adds a Recent tab so closed orders stay visible.
 *                  - Removes the Mark Ready button.
 *                  - Branch view loads five status buckets in one
 *                    request.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
require_once __DIR__ . '/../backend/database/order-queries.php';

/* --------------------------------------------------------------
 * LOAD DATA FOR THE ACTIVE VIEW
 * -------------------------------------------------------------- */

$activeOrders    = [];
$completedOrders = [];
$kitchenCounts   = [
    'pending'         => 0,
    'preparing'       => 0,
    'rider_pending'   => 0,
    'delivering'      => 0,
    'delivered_today' => 0,
    'cancelled_today' => 0,
];
$availableRiders = [];
$ownerSummary    = null;
$ownerBranchRows = [];
$loadError       = '';

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
        $activeOrders = getBranchKitchenOrders(
            $database_connection,
            $branchId,
            ['pending', 'preparing', 'rider_pending', 'delivering']
        );

        $completedOrders = getBranchCompletedOrders($database_connection, $branchId, 30);

        $kitchenCounts = getKitchenOrderCounts($database_connection, $branchId);

        $availableRiders = getAvailableRidersForBranch($database_connection, $branchId, 0);

        foreach ($activeOrders as &$order) {
            $order['items'] = getKitchenOrderItems(
                $database_connection,
                (int)$order['order_id'],
                $branchId
            );
        }
        unset($order);

        foreach ($completedOrders as &$order) {
            $order['items'] = getKitchenOrderItems(
                $database_connection,
                (int)$order['order_id'],
                $branchId
            );
        }
        unset($order);

    } catch (PDOException $e) {
        error_log('Kitchen branch view load error: ' . $e->getMessage());
        $loadError = 'Could not load the order list. Please try again.';
    }
}

/* --------------------------------------------------------------
 * CSRF
 * -------------------------------------------------------------- */

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['csrf_token'];

/* --------------------------------------------------------------
 * PAGE LOCALS
 *
 * Formatting only. No DB access, no session writes.
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

function kitchenDate(string $date): string
{
    $ts = strtotime($date);
    return $ts !== false ? date('M d, g:i A', $ts) : $date;
}
?>

<link rel="stylesheet" href="../assets/css/orders.css">

<div class="content kitchen-page" id="kitchenPage"
    data-csrf-token="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"
    data-scope="<?php echo $isOwner ? 'owner' : 'branch'; ?>" data-branch-id="<?php echo $branchId; ?>"
    data-asset-base="<?php echo htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8'); ?>"
    data-handler-url="../backend/handlers/order-handler.php">

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
                <span class="kitchen-summary-count">
                    <?php echo (int)($ownerSummary['pending'] ?? 0); ?>
                </span>
                <span class="kitchen-summary-label">Pending</span>
            </div>
            <div class="kitchen-summary-tile">
                <span class="kitchen-summary-count">
                    <?php echo (int)($ownerSummary['preparing'] ?? 0); ?>
                </span>
                <span class="kitchen-summary-label">Preparing</span>
            </div>
            <div class="kitchen-summary-tile">
                <span class="kitchen-summary-count">
                    <?php echo (int)($ownerSummary['rider_pending'] ?? 0); ?>
                </span>
                <span class="kitchen-summary-label">Waiting on Rider</span>
            </div>
            <div class="kitchen-summary-tile">
                <span class="kitchen-summary-count">
                    <?php echo (int)($ownerSummary['delivering'] ?? 0); ?>
                </span>
                <span class="kitchen-summary-label">Out for Delivery</span>
            </div>
            <div class="kitchen-summary-tile">
                <span class="kitchen-summary-count">
                    <?php echo (int)($ownerSummary['delivered_today'] ?? 0); ?>
                </span>
                <span class="kitchen-summary-label">Delivered Today</span>
            </div>
        </section>

        <section class="kitchen-revenue-grid" aria-label="Revenue summary">
            <div class="kitchen-revenue-tile">
                <span class="kitchen-revenue-label">Today</span>
                <span class="kitchen-revenue-value">
                    <?php echo kitchenMoney($ownerSummary['revenue_today'] ?? 0); ?>
                </span>
            </div>
            <div class="kitchen-revenue-tile">
                <span class="kitchen-revenue-label">Last 7 Days</span>
                <span class="kitchen-revenue-value">
                    <?php echo kitchenMoney($ownerSummary['revenue_7d'] ?? 0); ?>
                </span>
            </div>
            <div class="kitchen-revenue-tile">
                <span class="kitchen-revenue-label">Last 30 Days</span>
                <span class="kitchen-revenue-value">
                    <?php echo kitchenMoney($ownerSummary['revenue_30d'] ?? 0); ?>
                </span>
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
                        <?php foreach ($ownerBranchRows as $row): ?>
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
                            <td class="is-numeric"><?php echo (int)$row['rider_pending']; ?></td>
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

        <div class="kitchen-tabs" role="tablist">
            <button type="button" class="kitchen-tab active" data-filter="pending" role="tab" aria-selected="true">
                New
                <span class="kitchen-tab-count"><?php echo (int)$kitchenCounts['pending']; ?></span>
            </button>
            <button type="button" class="kitchen-tab" data-filter="preparing" role="tab" aria-selected="false">
                Preparing
                <span class="kitchen-tab-count"><?php echo (int)$kitchenCounts['preparing']; ?></span>
            </button>
            <button type="button" class="kitchen-tab" data-filter="rider_pending" role="tab" aria-selected="false">
                Waiting on Rider
                <span class="kitchen-tab-count"><?php echo (int)$kitchenCounts['rider_pending']; ?></span>
            </button>
            <button type="button" class="kitchen-tab" data-filter="delivering" role="tab" aria-selected="false">
                Out for Delivery
                <span class="kitchen-tab-count"><?php echo (int)$kitchenCounts['delivering']; ?></span>
            </button>
            <button type="button" class="kitchen-tab" data-filter="recent" role="tab" aria-selected="false">
                Recent
                <span class="kitchen-tab-count">
                    <?php echo (int)$kitchenCounts['delivered_today'] + (int)$kitchenCounts['cancelled_today']; ?>
                </span>
            </button>
        </div>

        <section class="kitchen-orders" id="kitchenOrderList" aria-label="Incoming orders">

            <?php if (empty($activeOrders) && empty($completedOrders)): ?>
            <div class="kitchen-empty-state">
                <p class="kitchen-empty-title">No orders yet</p>
                <p class="kitchen-empty-text">
                    New orders will appear here as soon as they are placed.
                </p>
            </div>
            <?php endif; ?>

            <?php
            /* ============================================================
               ACTIVE ORDERS
               ============================================================ */
            foreach ($activeOrders as $order):
                $orderId      = (int)$order['order_id'];
                $orderStatus  = (string)$order['order_status'];
                $items        = $order['items'] ?? [];
                $itemCount    = (int)($order['item_count'] ?? 0);
                $subtotal     = (float)($order['subtotal'] ?? 0);
                $customerName = trim(
                    (string)($order['customer_first_name'] ?? '') . ' ' .
                    (string)($order['customer_last_name'] ?? '')
                );
                if ($customerName === '') {
                    $customerName = 'Customer';
                }
                $assignedRiderId = $order['delivery_rider_id'] !== null
                    ? (int)$order['delivery_rider_id']
                    : 0;
                $assignedRiderName = trim(
                    (string)($order['rider_first_name'] ?? '') . ' ' .
                    (string)($order['rider_last_name'] ?? '')
                );

                $canStartPreparing = ($orderStatus === 'pending');
                $canCancel         = in_array($orderStatus, ['pending', 'preparing'], true);
                $canAssignRider    = in_array($orderStatus, ['pending', 'preparing'], true)
                                        && $assignedRiderId === 0;
                $canReassignRider  = in_array($orderStatus, ['pending', 'preparing', 'rider_pending'], true)
                                        && $assignedRiderId > 0;
            ?>
            <article class="kitchen-order-card" data-order-id="<?php echo $orderId; ?>"
                data-order-status="<?php echo htmlspecialchars($orderStatus, ENT_QUOTES, 'UTF-8'); ?>">

                <header class="kitchen-order-header">
                    <div class="kitchen-order-header-left">
                        <span class="kitchen-order-id">#<?php echo $orderId; ?></span>
                        <span class="kitchen-order-date">
                            <?php echo kitchenDate((string)$order['order_date']); ?>
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
                            <span
                                class="kitchen-meta-value"><?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?></span>
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
                                $itemQty      = (int)($item['quantity'] ?? 0);
                                $itemName     = (string)($item['product_name'] ?? 'Item');
                                $customs      = $item['customizations'] ?? [];
                                $itemNotes    = (string)($item['custom_instructions'] ?? '');
                            ?>
                            <li class="kitchen-item">
                                <div class="kitchen-item-line">
                                    <span class="kitchen-item-qty"><?php echo $itemQty; ?>&times;</span>
                                    <span
                                        class="kitchen-item-name"><?php echo htmlspecialchars($itemName, ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>

                                <?php if (!empty($customs)): ?>
                                <ul class="kitchen-item-customs">
                                    <?php foreach ($customs as $cust):
                                        $custName = (string)($cust['ingredient_name'] ?? '');
                                        if ($custName === '') {
                                            continue;
                                        }
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

                    <?php if ($canStartPreparing): ?>
                    <button type="button" class="btn btn-primary btn-sm kitchen-action-btn"
                        data-action="start_preparing" data-order-id="<?php echo $orderId; ?>">
                        Start Preparing
                    </button>
                    <?php endif; ?>
                </footer>
            </article>
            <?php endforeach; ?>

            <?php
            /* ============================================================
               RECENT (CLOSED) ORDERS
               Rendered as read-only cards. Hidden by default; shown
               when the Recent tab is selected.
               ============================================================ */
            foreach ($completedOrders as $order):
                $orderId      = (int)$order['order_id'];
                $orderStatus  = (string)$order['order_status'];
                $items        = $order['items'] ?? [];
                $itemCount    = (int)($order['item_count'] ?? 0);
                $subtotal     = (float)($order['subtotal'] ?? 0);
                $customerName = trim(
                    (string)($order['customer_first_name'] ?? '') . ' ' .
                    (string)($order['customer_last_name'] ?? '')
                );
                if ($customerName === '') {
                    $customerName = 'Customer';
                }
                $assignedRiderName = trim(
                    (string)($order['rider_first_name'] ?? '') . ' ' .
                    (string)($order['rider_last_name'] ?? '')
                );

                $closedAt = '';
                if ($orderStatus === 'delivered' && !empty($order['delivered_at'])) {
                    $closedAt = (string)$order['delivered_at'];
                } elseif (!empty($order['updated_at'])) {
                    $closedAt = (string)$order['updated_at'];
                } elseif (!empty($order['order_date'])) {
                    $closedAt = (string)$order['order_date'];
                }
            ?>
            <article class="kitchen-order-card kitchen-order-card-completed is-hidden"
                data-order-id="<?php echo $orderId; ?>"
                data-order-status="<?php echo htmlspecialchars($orderStatus, ENT_QUOTES, 'UTF-8'); ?>">

                <header class="kitchen-order-header">
                    <div class="kitchen-order-header-left">
                        <span class="kitchen-order-id">#<?php echo $orderId; ?></span>
                        <span class="kitchen-order-date">
                            <?php echo $closedAt !== '' ? kitchenDate($closedAt) : ''; ?>
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
                            <span
                                class="kitchen-meta-value"><?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?></span>
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
                                $itemQty  = (int)($item['quantity'] ?? 0);
                                $itemName = (string)($item['product_name'] ?? 'Item');
                            ?>
                            <li class="kitchen-item">
                                <div class="kitchen-item-line">
                                    <span class="kitchen-item-qty"><?php echo $itemQty; ?>&times;</span>
                                    <span
                                        class="kitchen-item-name"><?php echo htmlspecialchars($itemName, ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
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
                            <?php if ($assignedRiderName !== ''): ?>
                            <span class="kitchen-rider-assigned">
                                <?php echo htmlspecialchars($assignedRiderName, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <?php else: ?>
                            <span class="kitchen-rider-unassigned">—</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>

        </section>

        <div class="kitchen-modal" id="riderModal" style="display:none;" role="dialog" aria-modal="true"
            aria-labelledby="riderModalTitle">
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

                    <?php if (empty($availableRiders)): ?>
                    <div class="kitchen-empty-state">
                        <p class="kitchen-empty-title">No riders available</p>
                        <p class="kitchen-empty-text">
                            Every verified rider is currently busy. Try again in a moment.
                        </p>
                    </div>
                    <?php else: ?>
                    <ul class="kitchen-rider-list" id="riderList">
                        <?php foreach ($availableRiders as $rider):
                            $riderId    = (int)$rider['delivery_rider_id'];
                            $riderName  = trim(
                                (string)($rider['first_name'] ?? '') . ' ' .
                                (string)($rider['last_name'] ?? '')
                            );
                            $riderMeta  = array_filter([
                                (string)($rider['vehicle_type'] ?? ''),
                                (string)($rider['vehicle_plate'] ?? ''),
                                (string)($rider['rider_city'] ?? ''),
                            ]);
                            $riderRating = number_format((float)($rider['average_rating'] ?? 0), 1);
                        ?>
                        <li>
                            <button type="button" class="kitchen-rider-option" data-rider-id="<?php echo $riderId; ?>"
                                data-rider-name="<?php echo htmlspecialchars($riderName, ENT_QUOTES, 'UTF-8'); ?>">
                                <span class="kitchen-rider-name">
                                    <?php echo htmlspecialchars($riderName !== '' ? $riderName : ('Rider #' . $riderId), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <span class="kitchen-rider-meta">
                                    <?php echo htmlspecialchars(implode(' • ', $riderMeta), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <span class="kitchen-rider-rating">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/star-fill.svg" alt="Rating"
                                        width="14" height="14"
                                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/star-empty.svg'">
                                    <?php echo $riderRating; ?>
                                </span>
                            </button>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>

                <div class="kitchen-modal-footer">
                    <button type="button" class="btn btn-secondary" data-close-modal="rider-modal">Cancel</button>
                </div>
            </div>
        </div>

        <div class="kitchen-modal" id="confirmModal" style="display:none;" role="dialog" aria-modal="true"
            aria-labelledby="confirmModalTitle">
            <div class="kitchen-modal-overlay" data-close-modal="confirm-modal"></div>
            <div class="kitchen-modal-content kitchen-confirm-content">
                <div class="kitchen-modal-header">
                    <h2 class="heading-5" id="confirmModalTitle">Confirm</h2>
                    <button type="button" class="kitchen-modal-close" data-close-modal="confirm-modal"
                        aria-label="Close">&times;</button>
                </div>

                <div class="kitchen-modal-body">
                    <p id="confirmModalMessage" class="kitchen-confirm-text">
                        Are you sure?
                    </p>
                </div>

                <div class="kitchen-modal-footer">
                    <button type="button" class="btn btn-secondary" data-close-modal="confirm-modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmModalBtn">Confirm</button>
                </div>
            </div>
        </div>

        <?php endif; ?>
    </div>
</div>

<script src="../assets/ui/js/orders.js" defer></script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>