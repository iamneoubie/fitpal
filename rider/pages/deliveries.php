<?php
/**
 * FitPal Rider Deliveries Page
 *
 * Layout
 * ------
 * Three tabs, each a separate panel:
 *
 *   Active      Every order the rider is currently working: one in
 *               'picking_up' and/or one or more in 'delivering'.
 *               This is the default tab.
 *
 *   Assigned    Orders waiting on an accept / decline decision
 *               (status = 'rider_pending').
 *
 *   History     Closed deliveries, read-only. Not polled.
 *               Delivered orders within the 1-hour post-delivery
 *               window expose a Message button so the rider can
 *               still reach the customer or kitchen.
 *
 * ---------------------------------------------------------------------
 * TAB SWITCHING
 * ---------------------------------------------------------------------
 * All three panels are always rendered by the server. The `active`
 * class on the section decides which one is visible. The `active`
 * class is set from `$activeTab`, which is read from the `?tab=`
 * query parameter.
 *
 * Client-side tab switching (deliveries.js) toggles the `active`
 * class on the sections without re-fetching from the server. If the
 * server rendered only the selected panel, the client-side switch
 * would show an empty panel for any tab the server did not render.
 * Every panel therefore renders its full content on every request.
 *
 * ---------------------------------------------------------------------
 * REAL-TIME REFRESH
 * ---------------------------------------------------------------------
 * The Active and Assigned panels are refreshed by a client-side poll
 * every few seconds. The poll posts action=list to the assignment
 * handler and re-renders the two live lists from the response.
 *
 * The server-side first paint still renders the same rows, so a
 * rider with JavaScript disabled sees a complete page for the
 * moment they load it. The poll takes over after that.
 *
 * The History panel is not polled. It is a closed set of rows. The
 * delivered-row Message button is decided server-side from
 * delivered_at and is not re-evaluated by the poll.
 *
 * ---------------------------------------------------------------------
 * WHERE THE STATUSES ON THIS PAGE COME FROM
 * ---------------------------------------------------------------------
 * Every status this page reads is written by the shared
 * order-transaction layer:
 *
 *     shared/backend/database/order-transaction-queries.php
 *
 *   'rider_pending'  set by the kitchen's assignRiderToOrder().
 *   'picking_up'     set by the rider's accept path.
 *   'delivering'     set by the rider's mark-picked-up transition.
 *   'delivered'      set by the rider's mark-delivered transition.
 *   'failed'         set by sweepFailedDeliveries().
 *   'cancelled' and
 *   'refunded'       set by the customer or restaurant cancel paths.
 *
 * The per-delivery earning shown on every card is read from the
 * shared fee schedule (FITPAL_DELIVERY_BASE_FEE), the same constant
 * the shared layer uses when it writes the delivery credit.
 *
 * ---------------------------------------------------------------------
 * POST-DELIVERY MESSAGING WINDOW (1 HOUR)
 * ---------------------------------------------------------------------
 * A delivered order stays messageable for one hour after delivery.
 * This matches the customer and restaurant sides.
 *
 * The rule is enforced server-side by the rider message handler,
 * which permits the 'delivered' status on both channels while the
 * order's delivered_at is within the window. The History tab on
 * this page renders the Message buttons for any delivered order
 * inside the window and stamps the block with an absolute
 * data-message-window-ends timestamp so deliveries.js can remove
 * the buttons once the window elapses.
 *
 * @package FitPal
 * @version 5.5 — The History panel's content is now rendered on
 *                every request, with no server-side `?tab=history`
 *                guard. The previous revision guarded the panel
 *                content on `$activeTab === 'history'`, so a
 *                client-side tab switch (which deliveries.js
 *                performs without re-fetching the page) made an
 *                empty panel visible. All three panels now render
 *                their full content on every request, matching the
 *                Active and Assigned panels and matching the
 *                client-side tab-switching contract.
 *
 *                (5.4: asset paths on this page now use
 *                $assetBase. History tab renders Message buttons
 *                for delivered orders within the 1-hour window.
 *                5.3: Active and Assigned panels gain a
 *                client-side poll. 5.2: docblock records the
 *                shared layer. 5.1: 'picking_up' added to Active.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('rider');

if (empty($_SESSION['delivery_rider_id'])) {
    header('Location: sign-in.php');
    exit;
}

require_once __DIR__ . '/../backend/database/rider-connect.php';
require_once __DIR__ . '/../backend/database/rider-assignment-queries.php';

$riderId = (int)$_SESSION['delivery_rider_id'];

$profile          = getRiderProfile($database_connection, $riderId) ?: [];
$assignedOrders   = getAssignedOrders($database_connection, $riderId);
$activeDeliveries = getRiderActiveDeliveries($database_connection, $riderId);
$deliveryHistory  = getRiderDeliveryHistory($database_connection, $riderId, 10);

$available  = (int)($profile['is_available'] ?? 0) === 1;
$status     = (string)($profile['verification_status'] ?? 'pending');
$isVerified = $status === 'verified';

$assignedCount = count($assignedOrders);
$activeCount   = count($activeDeliveries);
$historyCount  = count($deliveryHistory);

// The per-delivery earning the shared layer will credit on success.
$riderPayoutPerDelivery = FITPAL_DELIVERY_BASE_FEE;

/**
 * Post-delivery messaging grace window, in seconds.
 *
 * Matches FITPAL_RIDER_FAILED_DELIVERY_GRACE_SECONDS in
 * shared/backend/database/fee-queries.php so the two windows that
 * bracket a delivery have the same length. Referenced here as a
 * view-layer constant: the page decides whether to render the
 * Message button for a delivered row, and the server handler makes
 * the authoritative decision.
 */
if (!defined('FITPAL_RIDER_MESSAGE_GRACE_SECONDS')) {
    define('FITPAL_RIDER_MESSAGE_GRACE_SECONDS', 3600);
}

/**
 * True when the given delivered order is still inside the 1-hour
 * post-delivery messaging window.
 *
 * @param string $deliveredAt Raw delivered_at string from the DB.
 * @return bool
 */
function historyRowMessageable(string $deliveredAt): bool
{
    if ($deliveredAt === '') {
        return false;
    }

    $ts = strtotime($deliveredAt);
    if ($ts === false) {
        return false;
    }

    return (time() - $ts) < FITPAL_RIDER_MESSAGE_GRACE_SECONDS;
}

/**
 * Absolute epoch-millisecond timestamp at which a delivered order's
 * 1-hour messaging window closes.
 *
 * Emitted on the History actions block as
 * data-message-window-ends. deliveries.js reads it and removes the
 * block's buttons once the timestamp has passed, without needing a
 * page reload.
 *
 * @param string $deliveredAt Raw delivered_at string from the DB.
 * @return int  Epoch milliseconds, or 0 when the timestamp cannot
 *              be parsed.
 */
function historyRowWindowEndsMs(string $deliveredAt): int
{
    if ($deliveredAt === '') {
        return 0;
    }

    $ts = strtotime($deliveredAt);
    if ($ts === false) {
        return 0;
    }

    return ($ts + FITPAL_RIDER_MESSAGE_GRACE_SECONDS) * 1000;
}

$allowedTabs = ['active', 'assigned', 'history'];
$activeTab   = isset($_GET['tab']) ? strtolower(trim((string)$_GET['tab'])) : 'active';
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'active';
}

function deliveriesTabUrl(string $tab): string
{
    return 'deliveries.php?tab=' . urlencode($tab);
}

function formatRiderDate(string $date): string
{
    $ts = strtotime($date);
    return $ts !== false ? date('M d, g:i A', $ts) : $date;
}

function getDeliveryStatusClass(string $status): string
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

function getDeliveryStatusLabel(string $status): string
{
    return match ($status) {
        'pending'       => 'Pending',
        'preparing'     => 'Preparing',
        'rider_pending' => 'Awaiting Your Confirmation',
        'picking_up'    => 'Head to Pickup',
        'delivering'    => 'In Transit',
        'delivered'     => 'Delivered',
        'cancelled'     => 'Cancelled',
        'refunded'      => 'Refunded',
        'failed'        => 'Failed',
        default         => ucfirst($status),
    };
}

require_once __DIR__ . '/../includes/header.php';

// The poll endpoint. Corrected in the panel include; reused here.
$deliveriesEndpoint = '../backend/handlers/assignment-handler.php';

// Resolve the post-delivery window in seconds so the JS can build
// a countdown if it ever wants to. The History rows are not polled,
// so this is informational only.
$historyGraceSeconds = FITPAL_RIDER_MESSAGE_GRACE_SECONDS;
?>

<div class="content rider-deliveries-page">
    <div class="container">

        <div class="page-title-header">
            <div class="page-title-header-top">
                <a href="dashboard.php" class="back-btn">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-left-line.svg" alt="Back"
                        class="back-btn-icon" width="20" height="20"
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/arrow-left-s-line.svg'">
                    <span>Back to Dashboard</span>
                </a>
                <h1>My Deliveries</h1>
            </div>
        </div>

        <?php if (isset($_SESSION['rider_success'])): ?>
        <div class="alert alert-success" role="alert">
            <?php echo htmlspecialchars($_SESSION['rider_success'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['rider_success']); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['rider_error'])): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo htmlspecialchars($_SESSION['rider_error'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['rider_error']); ?>
        </div>
        <?php endif; ?>

        <!-- ============================================================
             TAB BAR
             ============================================================ -->
        <nav class="rider-deliveries-tabs" role="tablist" aria-label="Delivery sections">
            <a href="<?php echo htmlspecialchars(deliveriesTabUrl('active'), ENT_QUOTES, 'UTF-8'); ?>"
                class="rider-deliveries-tab <?php echo $activeTab === 'active' ? 'active' : ''; ?>" role="tab"
                aria-selected="<?php echo $activeTab === 'active' ? 'true' : 'false'; ?>" aria-controls="panel-active"
                id="tabBtnActive" data-deliveries-tab="active">
                <img src="<?php echo $assetBase; ?>assets/images/icons/riding-fill.svg" alt=""
                    class="rider-deliveries-tab-icon"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/car-fill.svg'">
                <span>Active</span>
                <span class="rider-deliveries-tab-count" id="deliveriesActiveCount"
                    <?php if ($activeCount === 0) echo 'style="display:none;"'; ?>>
                    <?php echo $activeCount; ?>
                </span>
            </a>

            <a href="<?php echo htmlspecialchars(deliveriesTabUrl('assigned'), ENT_QUOTES, 'UTF-8'); ?>"
                class="rider-deliveries-tab <?php echo $activeTab === 'assigned' ? 'active' : ''; ?>" role="tab"
                aria-selected="<?php echo $activeTab === 'assigned' ? 'true' : 'false'; ?>"
                aria-controls="panel-assigned" id="tabBtnAssigned" data-deliveries-tab="assigned">
                <img src="<?php echo $assetBase; ?>assets/images/icons/package.svg" alt=""
                    class="rider-deliveries-tab-icon"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/order.svg'">
                <span>Assigned</span>
                <span class="rider-deliveries-tab-count rider-deliveries-tab-count-alert" id="deliveriesAssignedCount"
                    <?php if ($assignedCount === 0) echo 'style="display:none;"'; ?>>
                    <?php echo $assignedCount; ?>
                </span>
            </a>

            <a href="<?php echo htmlspecialchars(deliveriesTabUrl('history'), ENT_QUOTES, 'UTF-8'); ?>"
                class="rider-deliveries-tab <?php echo $activeTab === 'history' ? 'active' : ''; ?>" role="tab"
                aria-selected="<?php echo $activeTab === 'history' ? 'true' : 'false'; ?>" aria-controls="panel-history"
                id="tabBtnHistory" data-deliveries-tab="history">
                <img src="<?php echo $assetBase; ?>assets/images/icons/history-line.svg" alt=""
                    class="rider-deliveries-tab-icon"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/time-update.svg'">
                <span>History</span>
                <?php if ($historyCount > 0): ?>
                <span class="rider-deliveries-tab-count"><?php echo $historyCount; ?></span>
                <?php endif; ?>
            </a>
        </nav>

        <!-- ============================================================
             TAB: ACTIVE
             ============================================================ -->
        <section class="rider-deliveries-panel <?php echo $activeTab === 'active' ? 'active' : ''; ?>" id="panel-active"
            role="tabpanel" aria-labelledby="tabBtnActive" data-deliveries-panel="active">

            <div class="rider-deliveries-list" id="deliveriesActiveList">
                <?php if (empty($activeDeliveries)): ?>
                <div class="rider-deliveries-empty" id="deliveriesActiveEmpty">
                    <div class="rider-deliveries-empty-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/riding-line.svg" alt=""
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/car-line.svg'">
                    </div>
                    <p class="rider-deliveries-empty-title">No active delivery</p>
                    <p class="rider-deliveries-empty-text">
                        <?php if (!$isVerified): ?>
                        Your account is pending verification. Active deliveries will appear here once you can go
                        online.
                        <?php elseif ($assignedCount > 0): ?>
                        You have
                        <?php echo $assignedCount; ?>
                        assignment<?php echo $assignedCount === 1 ? '' : 's'; ?>
                        waiting. Open the Assigned tab to accept one.
                        <?php elseif ($available): ?>
                        You're online. When the kitchen assigns you an order and you accept it, it will appear here.
                        <?php else: ?>
                        Go online from the assignments panel at the bottom of the page to start receiving deliveries.
                        <?php endif; ?>
                    </p>
                </div>
                <?php else: ?>
                <?php foreach ($activeDeliveries as $delivery): ?>
                <?php
                $orderId        = (int)$delivery['order_id'];
                $orderStatus    = (string)$delivery['order_status'];
                $customerName   = (string)$delivery['customer_name'];
                $customerPhone  = (string)($delivery['customer_contact'] ?? '');
                $destination    = (string)$delivery['destination_address'];
                $orderDate      = (string)$delivery['order_date'];
                $branchName     = (string)($delivery['branch_name'] ?? '');
                $restaurantName = (string)($delivery['restaurant_name'] ?? '');
                $itemCount      = (int)($delivery['item_count'] ?? 0);
                $orderTotal     = (float)($delivery['order_total'] ?? 0);
                $isPickingUp    = ($orderStatus === 'picking_up');
                ?>
                <article class="rider-delivery-card" data-order-id="<?php echo $orderId; ?>"
                    data-order-status="<?php echo htmlspecialchars($orderStatus, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="rider-delivery-card-head">
                        <div class="rider-delivery-card-id">
                            <p class="rider-delivery-card-order">Order #<?php echo $orderId; ?></p>
                            <p class="rider-delivery-card-date"><?php echo formatRiderDate($orderDate); ?></p>
                        </div>
                        <span class="badge <?php echo getDeliveryStatusClass($orderStatus); ?>">
                            <?php echo getDeliveryStatusLabel($orderStatus); ?>
                        </span>
                    </div>

                    <div class="rider-delivery-card-route">
                        <div class="rider-route-stop">
                            <div class="rider-route-icon rider-route-icon-pickup" aria-hidden="true">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt=""
                                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/community-general.svg'">
                            </div>
                            <div class="rider-route-info">
                                <p class="rider-route-label">
                                    <?php echo $isPickingUp ? 'Pickup — head here now' : 'Pickup'; ?>
                                </p>
                                <p class="rider-route-name">
                                    <?php echo htmlspecialchars($restaurantName, ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                                <p class="rider-route-address">
                                    <?php echo htmlspecialchars($branchName, ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                            </div>
                        </div>

                        <div class="rider-route-connector" aria-hidden="true"></div>

                        <div class="rider-route-stop">
                            <div class="rider-route-icon rider-route-icon-dropoff" aria-hidden="true">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/location-fill.svg" alt="">
                            </div>
                            <div class="rider-route-info">
                                <p class="rider-route-label">Drop-off</p>
                                <p class="rider-route-name">
                                    <?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                                <p class="rider-route-address">
                                    <?php echo htmlspecialchars($destination, ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="rider-delivery-card-facts">
                        <span class="rider-fact">
                            <span class="rider-fact-label">Items</span>
                            <span class="rider-fact-value"><?php echo $itemCount; ?></span>
                        </span>
                        <span class="rider-fact">
                            <span class="rider-fact-label">Order</span>
                            <span class="rider-fact-value"><?php echo formatRiderCurrency($orderTotal); ?></span>
                        </span>
                        <span class="rider-fact">
                            <span class="rider-fact-label">Your Earning</span>
                            <span class="rider-fact-value rider-fact-value-highlight">
                                <?php echo formatRiderCurrency($riderPayoutPerDelivery); ?>
                            </span>
                        </span>
                    </div>

                    <div class="rider-delivery-card-actions">
                        <?php if ($isPickingUp): ?>
                        <button type="button" class="btn btn-neutral btn-sm" data-rider-chat-open
                            data-rider-chat-order-id="<?php echo $orderId; ?>"
                            data-rider-chat-recipient="restaurant_account"
                            data-rider-chat-subtitle="Order #<?php echo $orderId; ?> • <?php echo htmlspecialchars($restaurantName, ENT_QUOTES, 'UTF-8'); ?>">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/contact-us-line.svg" alt=""
                                class="btn-icon" width="16" height="16">
                            <span>Message Kitchen</span>
                        </button>
                        <?php else: ?>
                        <?php if ($customerPhone !== ''): ?>
                        <a href="tel:<?php echo htmlspecialchars($customerPhone, ENT_QUOTES, 'UTF-8'); ?>"
                            class="btn btn-neutral btn-sm">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/phone-fill.svg" alt=""
                                class="btn-icon" width="16" height="16"
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/contact-us-line.svg'">
                            <span>Call</span>
                        </a>
                        <?php endif; ?>

                        <button type="button" class="btn btn-neutral btn-sm" data-rider-chat-open
                            data-rider-chat-order-id="<?php echo $orderId; ?>" data-rider-chat-recipient="customer"
                            data-rider-chat-subtitle="Order #<?php echo $orderId; ?> • <?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?>">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/contact-us-line.svg" alt=""
                                class="btn-icon" width="16" height="16">
                            <span>Message</span>
                        </button>
                        <?php endif; ?>

                        <button type="button" class="btn btn-primary btn-sm delivery-status-btn"
                            data-order-id="<?php echo $orderId; ?>"
                            data-action="<?php echo $isPickingUp ? 'mark_picked_up' : 'delivered'; ?>"
                            data-confirm-title="<?php echo htmlspecialchars($isPickingUp ? 'Confirm pickup?' : 'Mark as delivered?', ENT_QUOTES, 'UTF-8'); ?>"
                            data-confirm-message="<?php echo htmlspecialchars($isPickingUp
                                ? 'Only mark this after you have the food in hand. The customer will see the order move to \"In Transit\".'
                                : 'This closes the order and recognises the earning on your account.', ENT_QUOTES, 'UTF-8'); ?>"
                            data-confirm-label="<?php echo htmlspecialchars($isPickingUp ? 'Mark Picked Up' : 'Mark Delivered', ENT_QUOTES, 'UTF-8'); ?>"
                            data-confirm-variant="primary"
                            data-confirm-icon="<?php echo $isPickingUp ? 'picked_up' : 'delivered'; ?>">
                            <?php echo $isPickingUp ? 'Mark Picked Up' : 'Mark Delivered'; ?>
                        </button>
                    </div>
                </article>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <!-- ============================================================
             TAB: ASSIGNED
             ============================================================ -->
        <section class="rider-deliveries-panel <?php echo $activeTab === 'assigned' ? 'active' : ''; ?>"
            id="panel-assigned" role="tabpanel" aria-labelledby="tabBtnAssigned" data-deliveries-panel="assigned">

            <div class="rider-deliveries-list" id="deliveriesAssignedList">
                <?php if (empty($assignedOrders)): ?>
                <div class="rider-deliveries-empty" id="deliveriesAssignedEmpty">
                    <div class="rider-deliveries-empty-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/package.svg" alt=""
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/order.svg'">
                    </div>
                    <p class="rider-deliveries-empty-title">No pending assignments</p>
                    <p class="rider-deliveries-empty-text">
                        <?php if (!$isVerified): ?>
                        Your account is pending verification. Assignments will appear here once you can go online.
                        <?php elseif (!$available): ?>
                        You're offline. Go online from the assignments panel at the bottom of the page so the kitchen
                        can assign you orders.
                        <?php else: ?>
                        When the kitchen assigns you an order, it will appear here for you to accept or decline.
                        <?php endif; ?>
                    </p>
                </div>
                <?php else: ?>
                <?php foreach ($assignedOrders as $pending): ?>
                <?php
                $orderId        = (int)$pending['order_id'];
                $customerName   = (string)$pending['customer_name'];
                $destination    = (string)$pending['destination_address'];
                $orderDate      = (string)$pending['order_date'];
                $branchName     = (string)($pending['branch_name'] ?? '');
                $restaurantName = (string)($pending['restaurant_name'] ?? '');
                $itemCount      = (int)($pending['item_count'] ?? 0);
                $orderTotal     = (float)($pending['order_total'] ?? 0);
                $canDecide      = $isVerified && $available;
                ?>
                <article class="rider-delivery-card rider-delivery-card-assigned"
                    data-order-id="<?php echo $orderId; ?>" data-order-status="rider_pending">
                    <div class="rider-delivery-card-head">
                        <div class="rider-delivery-card-id">
                            <p class="rider-delivery-card-order">Order #<?php echo $orderId; ?></p>
                            <p class="rider-delivery-card-date"><?php echo formatRiderDate($orderDate); ?></p>
                        </div>
                        <span class="badge badge-warning">Awaiting Confirmation</span>
                    </div>

                    <div class="rider-delivery-card-route">
                        <div class="rider-route-stop">
                            <div class="rider-route-icon rider-route-icon-pickup" aria-hidden="true">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt=""
                                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/community-general.svg'">
                            </div>
                            <div class="rider-route-info">
                                <p class="rider-route-label">Pickup</p>
                                <p class="rider-route-name">
                                    <?php echo htmlspecialchars($restaurantName, ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                                <p class="rider-route-address">
                                    <?php echo htmlspecialchars($branchName, ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                            </div>
                        </div>

                        <div class="rider-route-connector" aria-hidden="true"></div>

                        <div class="rider-route-stop">
                            <div class="rider-route-icon rider-route-icon-dropoff" aria-hidden="true">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/location-fill.svg" alt="">
                            </div>
                            <div class="rider-route-info">
                                <p class="rider-route-label">Drop-off</p>
                                <p class="rider-route-name">
                                    <?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                                <p class="rider-route-address">
                                    <?php echo htmlspecialchars(truncateText($destination, 70), ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="rider-delivery-card-facts">
                        <span class="rider-fact">
                            <span class="rider-fact-label">Items</span>
                            <span class="rider-fact-value"><?php echo $itemCount; ?></span>
                        </span>
                        <span class="rider-fact">
                            <span class="rider-fact-label">Order</span>
                            <span class="rider-fact-value"><?php echo formatRiderCurrency($orderTotal); ?></span>
                        </span>
                        <span class="rider-fact">
                            <span class="rider-fact-label">Your Earning</span>
                            <span class="rider-fact-value rider-fact-value-highlight">
                                <?php echo formatRiderCurrency($riderPayoutPerDelivery); ?>
                            </span>
                        </span>
                    </div>

                    <div class="rider-delivery-card-actions">
                        <?php if ($canDecide): ?>
                        <button type="button" class="btn btn-danger btn-sm delivery-status-btn"
                            data-order-id="<?php echo $orderId; ?>" data-action="decline_assignment"
                            data-confirm-title="Decline this assignment?"
                            data-confirm-message="The kitchen will choose another rider for this order. This cannot be undone."
                            data-confirm-label="Decline" data-confirm-variant="danger" data-confirm-icon="decline">
                            Decline
                        </button>
                        <button type="button" class="btn btn-primary btn-sm delivery-status-btn"
                            data-order-id="<?php echo $orderId; ?>" data-action="accept_assignment"
                            data-confirm-title="Accept this assignment?"
                            data-confirm-message="You'll be responsible for picking up this order and delivering it to the customer. You won't be able to go offline until the delivery is complete."
                            data-confirm-label="Accept" data-confirm-variant="primary" data-confirm-icon="accept">
                            Accept Assignment
                        </button>
                        <?php else: ?>
                        <span class="btn btn-sm btn-disabled" aria-disabled="true">
                            <?php echo !$isVerified ? 'Awaiting Verification' : 'Go Online to Decide'; ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </article>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <!-- ============================================================
             TAB: HISTORY

             Rendered on every request. The `active` class on the
             section decides visibility. The previous revision wrapped
             this panel's content in an `if ($activeTab === 'history')`
             guard, which meant the panel was empty in the DOM
             whenever the server rendered the page for any other tab.
             A client-side tab switch then made that empty panel
             visible. All three panels now render their full content
             on every request.

             Delivered orders within the 1-hour post-delivery grace
             window expose a Message button. The block carries an
             absolute data-message-window-ends timestamp so
             deliveries.js can remove the buttons once the window
             elapses, without a page reload.

             Orders outside the window render no Message button.
             Cancelled, refunded, and failed orders never render one.
             ============================================================ -->
        <section class="rider-deliveries-panel <?php echo $activeTab === 'history' ? 'active' : ''; ?>"
            id="panel-history" role="tabpanel" aria-labelledby="tabBtnHistory" data-deliveries-panel="history">

            <div class="rider-deliveries-card">
                <?php if (empty($deliveryHistory)): ?>
                <div class="rider-deliveries-empty">
                    <div class="rider-deliveries-empty-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/time-update.svg" alt=""
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/update.svg'">
                    </div>
                    <p class="rider-deliveries-empty-title">No delivery history</p>
                    <p class="rider-deliveries-empty-text">
                        <?php if (!$isVerified): ?>
                        Your account is pending verification. Completed deliveries will appear here.
                        <?php elseif ($available): ?>
                        You're online. Your first completed delivery will appear here.
                        <?php else: ?>
                        Go online from the assignments panel to start receiving delivery requests.
                        <?php endif; ?>
                    </p>
                </div>
                <?php else: ?>
                <div class="rider-history-list">
                    <?php foreach ($deliveryHistory as $delivery):
                        $orderId      = (int)$delivery['order_id'];
                        $orderStatus  = (string)$delivery['order_status'];
                        $customerName = (string)$delivery['customer_name'];
                        $deliveredAt  = (string)($delivery['delivered_at'] ?? '');
                        $isDelivered  = $orderStatus === 'delivered';

                        // A delivered order stays messageable for one
                        // hour. Cancelled, refunded, and failed orders
                        // are closed on both channels and never render
                        // a Message button.
                        $canMessage   = $isDelivered && historyRowMessageable($deliveredAt);
                        $windowEndsMs = $isDelivered ? historyRowWindowEndsMs($deliveredAt) : 0;
                    ?>
                    <div class="rider-history-row">
                        <div
                            class="rider-history-icon <?php echo $isDelivered ? 'rider-history-icon-success' : 'rider-history-icon-neutral'; ?>">
                            <?php if ($isDelivered): ?>
                            <img src="<?php echo $assetBase; ?>assets/images/icons/verified-fill.svg" alt="Delivered"
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/verified-badge-fill.svg'">
                            <?php else: ?>
                            <img src="<?php echo $assetBase; ?>assets/images/icons/close-circle-fill.svg"
                                alt="Cancelled"
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/cancel.svg'">
                            <?php endif; ?>
                        </div>

                        <div class="rider-history-info">
                            <p class="rider-history-order">Order #<?php echo $orderId; ?></p>
                            <p class="rider-history-customer">
                                <?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                            <p class="rider-history-date">
                                <?php echo $deliveredAt !== '' ? formatRiderDate($deliveredAt) : '—'; ?>
                            </p>

                            <?php if ($canMessage): ?>
                            <div class="rider-history-actions"
                                data-message-window-ends="<?php echo (int)$windowEndsMs; ?>">
                                <button type="button" class="btn btn-neutral btn-sm" data-rider-chat-open
                                    data-rider-chat-order-id="<?php echo $orderId; ?>"
                                    data-rider-chat-recipient="customer"
                                    data-rider-chat-subtitle="Order #<?php echo $orderId; ?> • <?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?>">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/contact-us-line.svg" alt=""
                                        class="btn-icon" width="16" height="16">
                                    <span>Message Customer</span>
                                </button>
                                <button type="button" class="btn btn-neutral btn-sm" data-rider-chat-open
                                    data-rider-chat-order-id="<?php echo $orderId; ?>"
                                    data-rider-chat-recipient="restaurant_account"
                                    data-rider-chat-subtitle="Order #<?php echo $orderId; ?> • Kitchen">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/contact-us-line.svg" alt=""
                                        class="btn-icon" width="16" height="16">
                                    <span>Message Kitchen</span>
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="rider-history-meta">
                            <span class="badge <?php echo getDeliveryStatusClass($orderStatus); ?>">
                                <?php echo getDeliveryStatusLabel($orderStatus); ?>
                            </span>
                            <p class="rider-history-earning">
                                <?php echo $isDelivered ? '+' : ''; ?><?php echo formatRiderCurrency($isDelivered ? $riderPayoutPerDelivery : 0); ?>
                            </p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </section>

    </div>
</div>

<!-- ============================================================
     CONFIRM MODAL
     ============================================================ -->
<div class="rider-confirm-modal" id="riderConfirmModal" style="display: none;" data-variant="primary" data-icon="accept"
    role="dialog" aria-modal="true" aria-labelledby="riderConfirmTitle">
    <div class="rider-confirm-overlay" data-close-confirm></div>
    <div class="rider-confirm-content">
        <div class="rider-confirm-icon" aria-hidden="true">
            <img src="<?php echo $assetBase; ?>assets/images/icons/verified-fill.svg" alt=""
                class="rider-confirm-icon-accept">
            <img src="<?php echo $assetBase; ?>assets/images/icons/close-circle-fill.svg" alt=""
                class="rider-confirm-icon-decline">
            <img src="<?php echo $assetBase; ?>assets/images/icons/package.svg" alt=""
                class="rider-confirm-icon-picked_up"
                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/cart-arrow-up.svg'">
            <img src="<?php echo $assetBase; ?>assets/images/icons/verified-badge-fill.svg" alt=""
                class="rider-confirm-icon-delivered">
        </div>

        <p class="rider-confirm-title" id="riderConfirmTitle">Confirm</p>
        <p class="rider-confirm-text" id="riderConfirmMessage"></p>

        <div class="rider-confirm-footer">
            <button type="button" class="btn btn-neutral" data-close-confirm>Cancel</button>
            <button type="button" class="rider-confirm-btn" id="riderConfirmBtn">Confirm</button>
        </div>
    </div>
</div>

<script>
window.FITPAL_RIDER_DELIVERIES = {
    csrfToken: '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>',
    assetBase: '<?php echo $assetBase; ?>',
    riderId: <?php echo $riderId; ?>,
    payout: <?php echo (float)$riderPayoutPerDelivery; ?>,
    activeTab: '<?php echo htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8'); ?>',
    panelEndpoint: '<?php echo htmlspecialchars($deliveriesEndpoint, ENT_QUOTES, 'UTF-8'); ?>',
    messageGraceSeconds: <?php echo (int)$historyGraceSeconds; ?>
};
</script>
<script src="../assets/ui/js/deliveries.js" defer></script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>