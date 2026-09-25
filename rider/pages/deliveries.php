<?php
/**
 * FitPal Rider Deliveries Page
 *
 * Layout
 * ------
 * Three tabs, each a separate panel:
 *
 *   Active      The order the rider is currently running. This is
 *               the default tab because it is what a rider opens
 *               the page to check. Renders one card per in-transit
 *               order (currently one, but the markup supports more
 *               if the assignment cap changes). Actions: Call
 *               Customer, Message, Mark Delivered.
 *
 *   Assigned    Orders the kitchen has handed to this rider and
 *               that are waiting on an accept / decline decision.
 *               This is the rider's own review surface — the
 *               assignment panel at the bottom of the page does
 *               notify and offer the same two actions, but a rider
 *               who dismissed the notification, or who wants to
 *               re-check details before deciding, needs a place to
 *               see the offer in full. Actions: Decline, Accept.
 *
 *   History     Closed deliveries, read-only.
 *
 * Tab counts are shown as badges on each tab. The tab bar is
 * styled like customer/pages/orders.php's filter tabs so both
 * roles feel like the same product.
 *
 * Tab state
 * ---------
 * ?tab=active|assigned|history. Default is active. The value is
 * validated against an allowlist so nothing user-supplied reaches
 * the template raw.
 *
 * Panel enter animation
 * ---------------------
 * Only the .active panel runs the fade + slide-up keyframe
 * declared in deliveries.css, so switching tabs reads as a
 * content change rather than a full-page reload. The tab bar
 * itself never animates, so the tap target stays exactly where
 * the user left it.
 *
 * Empty-state routing
 * -------------------
 * When Active is empty but Assigned has rows, the Active panel
 * shows a hint that points the rider at the Assigned tab. When
 * Assigned is empty but the rider has no active work at all, the
 * Active panel points at the assignment panel. History always
 * renders its own empty state.
 *
 * Confirmation model
 * ------------------
 * Delivery actions open a styled confirm modal. The copy is
 * authored on each button via data-confirm-* attributes so the JS
 * never carries text. The modal carries two attributes:
 *   - data-variant: "primary" or "danger" (drives button colour)
 *   - data-icon:    "accept" | "decline" | "delivered"
 *                   (drives which SVG is visible)
 *
 * Chat modal
 * ----------
 * The chat modal is provided by rider/includes/rider-chat-modal.php,
 * loaded on every authenticated rider page by header.php. This
 * page's Message buttons use the delegated trigger contract:
 *
 *   data-rider-chat-open
 *   data-rider-chat-order-id="<order_id>"
 *   data-rider-chat-recipient="customer|restaurant_account"
 *   data-rider-chat-subtitle="<display string>"
 *
 * Button rules
 * ------------
 *   - Positive / confirm → primary (green / white)
 *   - Neutral             → black background / white text
 *   - Negative            → danger (red / white)
 * No transparent or outlined buttons are used on this page.
 *
 * @package FitPal
 * @version 5.0 — Relaid out as three filter tabs with count
 *                badges, matching the customer orders page pattern:
 *                  - Active tab is now the default (was: Active
 *                    section rendered below Assigned).
 *                  - Assigned tab carries the pending decision
 *                    cards.
 *                  - History tab carries the closed deliveries.
 *                Only the active panel renders; the other two
 *                emit empty markup.
 *                Added .rider-deliveries-panel.active fade-in so
 *                a tab switch reads as a content change.
 *
 *                (4.1: stat strip removed, empty sections hidden.
 *                4.0: chat modal extracted to shared include.
 *                3.6: docblock corrected to rider-csrf-token.php.
 *                3.5: local CSRF block removed; $csrfToken
 *                inherited from header.php under rider_csrf_token.)
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['delivery_rider_id'])) {
    header('Location: sign-in.php');
    exit;
}

require_once __DIR__ . '/../backend/database/rider-connect.php';
require_once __DIR__ . '/../backend/database/rider-queries.php';

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

// ---------------------------------------------------------------
// ACTIVE TAB
// ---------------------------------------------------------------
$allowedTabs = ['active', 'assigned', 'history'];
$activeTab   = isset($_GET['tab']) ? strtolower(trim((string)$_GET['tab'])) : 'active';
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'active';
}

/**
 * Build a URL for the given tab, preserving nothing else. Kept as
 * a helper so the template doesn't hand-roll query strings.
 */
function deliveriesTabUrl(string $tab): string
{
    return 'deliveries.php?tab=' . urlencode($tab);
}

/**
 * Format a date string for display on this page.
 */
function formatRiderDate(string $date): string
{
    $ts = strtotime($date);
    return $ts !== false ? date('M d, g:i A', $ts) : $date;
}

/**
 * Badge class for an order status.
 */
function getDeliveryStatusClass(string $status): string
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

/**
 * Human label for an order status.
 */
function getDeliveryStatusLabel(string $status): string
{
    return match ($status) {
        'pending'       => 'Pending',
        'preparing'     => 'Preparing',
        'rider_pending' => 'Awaiting Your Confirmation',
        'delivering'    => 'In Transit',
        'delivered'     => 'Delivered',
        'cancelled'     => 'Cancelled',
        'refunded'      => 'Refunded',
        default         => ucfirst($status),
    };
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="content rider-deliveries-page">
    <div class="container">

        <div class="page-title-header">
            <div class="page-title-header-top">
                <a href="dashboard.php" class="back-btn">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-left-line.svg" alt="Back"
                        class="back-btn-icon" width="20" height="20">
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
             Three filter tabs with count badges.
             ============================================================ -->
        <nav class="rider-deliveries-tabs" role="tablist" aria-label="Delivery sections">
            <a href="<?php echo htmlspecialchars(deliveriesTabUrl('active'), ENT_QUOTES, 'UTF-8'); ?>"
                class="rider-deliveries-tab <?php echo $activeTab === 'active' ? 'active' : ''; ?>" role="tab"
                aria-selected="<?php echo $activeTab === 'active' ? 'true' : 'false'; ?>" aria-controls="panel-active"
                id="tabBtnActive">
                <img src="<?php echo $assetBase; ?>assets/images/icons/riding-fill.svg" alt=""
                    class="rider-deliveries-tab-icon"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/car-fill.svg'">
                <span>Active</span>
                <?php if ($activeCount > 0): ?>
                <span class="rider-deliveries-tab-count"><?php echo $activeCount; ?></span>
                <?php endif; ?>
            </a>

            <a href="<?php echo htmlspecialchars(deliveriesTabUrl('assigned'), ENT_QUOTES, 'UTF-8'); ?>"
                class="rider-deliveries-tab <?php echo $activeTab === 'assigned' ? 'active' : ''; ?>" role="tab"
                aria-selected="<?php echo $activeTab === 'assigned' ? 'true' : 'false'; ?>"
                aria-controls="panel-assigned" id="tabBtnAssigned">
                <img src="<?php echo $assetBase; ?>assets/images/icons/package.svg" alt=""
                    class="rider-deliveries-tab-icon"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/order.svg'">
                <span>Assigned</span>
                <?php if ($assignedCount > 0): ?>
                <span class="rider-deliveries-tab-count rider-deliveries-tab-count-alert">
                    <?php echo $assignedCount; ?>
                </span>
                <?php endif; ?>
            </a>

            <a href="<?php echo htmlspecialchars(deliveriesTabUrl('history'), ENT_QUOTES, 'UTF-8'); ?>"
                class="rider-deliveries-tab <?php echo $activeTab === 'history' ? 'active' : ''; ?>" role="tab"
                aria-selected="<?php echo $activeTab === 'history' ? 'true' : 'false'; ?>" aria-controls="panel-history"
                id="tabBtnHistory">
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
            role="tabpanel" aria-labelledby="tabBtnActive">
            <?php if ($activeTab === 'active'): ?>

            <?php if (empty($activeDeliveries)): ?>

            <div class="rider-deliveries-empty">
                <div class="rider-deliveries-empty-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/riding-line.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/car-line.svg'">
                </div>
                <p class="rider-deliveries-empty-title">No active delivery</p>
                <p class="rider-deliveries-empty-text">
                    <?php if (!$isVerified): ?>
                    Your account is pending verification. Active deliveries will appear here once you can go online.
                    <?php elseif ($assignedCount > 0): ?>
                    You have
                    <?php echo $assignedCount; ?>
                    assignment<?php echo $assignedCount === 1 ? '' : 's'; ?>
                    waiting. Open the Assigned tab to accept one.
                    <?php elseif ($available): ?>
                    You're online. When the kitchen assigns you an order and you accept it, the delivery will appear
                    here.
                    <?php else: ?>
                    Go online from the assignments panel at the bottom of the page to start receiving deliveries.
                    <?php endif; ?>
                </p>

                <?php if ($assignedCount > 0): ?>
                <a href="<?php echo htmlspecialchars(deliveriesTabUrl('assigned'), ENT_QUOTES, 'UTF-8'); ?>"
                    class="btn btn-primary btn-sm">
                    View Assigned
                </a>
                <?php endif; ?>
            </div>

            <?php else: ?>

            <div class="rider-deliveries-list">
                <?php foreach ($activeDeliveries as $delivery):
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
                    $riderEarning   = 50.00;

                    $deliveredTitle = 'Mark as delivered?';
                    $deliveredBody  = 'This closes the order and recognises the earning on your account.';
                ?>
                <article class="rider-delivery-card" data-order-id="<?php echo $orderId; ?>">

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
                                <?php echo formatRiderCurrency($riderEarning); ?>
                            </span>
                        </span>
                    </div>

                    <div class="rider-delivery-card-actions">
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

                        <button type="button" class="btn btn-primary btn-sm delivery-status-btn"
                            data-order-id="<?php echo $orderId; ?>" data-action="delivered"
                            data-confirm-title="<?php echo htmlspecialchars($deliveredTitle, ENT_QUOTES, 'UTF-8'); ?>"
                            data-confirm-message="<?php echo htmlspecialchars($deliveredBody, ENT_QUOTES, 'UTF-8'); ?>"
                            data-confirm-label="Mark Delivered" data-confirm-variant="primary"
                            data-confirm-icon="delivered">
                            Mark Delivered
                        </button>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>

            <?php endif; ?>

            <?php endif; ?>
        </section>

        <!-- ============================================================
             TAB: ASSIGNED
             ============================================================ -->
        <section class="rider-deliveries-panel <?php echo $activeTab === 'assigned' ? 'active' : ''; ?>"
            id="panel-assigned" role="tabpanel" aria-labelledby="tabBtnAssigned">
            <?php if ($activeTab === 'assigned'): ?>

            <?php if (empty($assignedOrders)): ?>

            <div class="rider-deliveries-empty">
                <div class="rider-deliveries-empty-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/package.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/order.svg'">
                </div>
                <p class="rider-deliveries-empty-title">No pending assignments</p>
                <p class="rider-deliveries-empty-text">
                    <?php if (!$isVerified): ?>
                    Your account is pending verification. Assignments will appear here once you can go online.
                    <?php elseif (!$available): ?>
                    You're offline. Go online from the assignments panel at the bottom of the page so the kitchen can
                    assign you orders.
                    <?php else: ?>
                    When the kitchen assigns you an order, it will appear here for you to accept or decline. The
                    notification at the bottom of the page will also alert you.
                    <?php endif; ?>
                </p>
            </div>

            <?php else: ?>

            <div class="rider-deliveries-list">
                <?php foreach ($assignedOrders as $pending):
                    $orderId        = (int)$pending['order_id'];
                    $customerName   = (string)$pending['customer_name'];
                    $destination    = (string)$pending['destination_address'];
                    $orderDate      = (string)$pending['order_date'];
                    $branchName     = (string)($pending['branch_name'] ?? '');
                    $restaurantName = (string)($pending['restaurant_name'] ?? '');
                    $itemCount      = (int)($pending['item_count'] ?? 0);
                    $orderTotal     = (float)($pending['order_total'] ?? 0);

                    $canDecide = $isVerified && $available;

                    $acceptTitle = 'Accept this assignment?';
                    $acceptBody  = "You'll be responsible for picking up this order and delivering it to the customer. You won't be able to go offline until the delivery is complete.";

                    $declineTitle = 'Decline this assignment?';
                    $declineBody  = 'The kitchen will choose another rider for this order. This cannot be undone.';
                ?>
                <article class="rider-delivery-card rider-delivery-card-assigned"
                    data-order-id="<?php echo $orderId; ?>">

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
                                <?php echo formatRiderCurrency(50.00); ?>
                            </span>
                        </span>
                    </div>

                    <div class="rider-delivery-card-actions">
                        <?php if ($canDecide): ?>
                        <button type="button" class="btn btn-danger btn-sm delivery-status-btn"
                            data-order-id="<?php echo $orderId; ?>" data-action="decline_assignment"
                            data-confirm-title="<?php echo htmlspecialchars($declineTitle, ENT_QUOTES, 'UTF-8'); ?>"
                            data-confirm-message="<?php echo htmlspecialchars($declineBody, ENT_QUOTES, 'UTF-8'); ?>"
                            data-confirm-label="Decline" data-confirm-variant="danger" data-confirm-icon="decline">
                            Decline
                        </button>
                        <button type="button" class="btn btn-primary btn-sm delivery-status-btn"
                            data-order-id="<?php echo $orderId; ?>" data-action="accept_assignment"
                            data-confirm-title="<?php echo htmlspecialchars($acceptTitle, ENT_QUOTES, 'UTF-8'); ?>"
                            data-confirm-message="<?php echo htmlspecialchars($acceptBody, ENT_QUOTES, 'UTF-8'); ?>"
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
            </div>

            <?php endif; ?>

            <?php endif; ?>
        </section>

        <!-- ============================================================
             TAB: HISTORY
             ============================================================ -->
        <section class="rider-deliveries-panel <?php echo $activeTab === 'history' ? 'active' : ''; ?>"
            id="panel-history" role="tabpanel" aria-labelledby="tabBtnHistory">
            <?php if ($activeTab === 'history'): ?>

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
                        $riderEarning = 50.00;

                        $isDelivered = $orderStatus === 'delivered';
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
                        </div>

                        <div class="rider-history-meta">
                            <span class="badge <?php echo getDeliveryStatusClass($orderStatus); ?>">
                                <?php echo getDeliveryStatusLabel($orderStatus); ?>
                            </span>
                            <p class="rider-history-earning">
                                <?php echo $isDelivered ? '+' : ''; ?><?php echo formatRiderCurrency($isDelivered ? $riderEarning : 0); ?>
                            </p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php endif; ?>
            </div>

            <?php endif; ?>
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
    activeTab: '<?php echo htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8'); ?>'
};
</script>
<script src="../assets/ui/js/deliveries.js" defer></script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>