<?php
/**
 * FitPal Customer Order Tracking Page
 *
 * Shows the current status, a progress timeline, the assigned rider,
 * the restaurant contact, order summary, and a chat modal that lets
 * the customer message the kitchen and (once a rider has accepted)
 * the rider.
 *
 * ---------------------------------------------------------------------
 * STATUS FLOW
 * ---------------------------------------------------------------------
 *     pending → preparing → rider_pending → picking_up → delivering
 *                                                              ↓
 *                                                         delivered
 *
 * The 'picking_up' stage sits between 'rider_pending' and
 * 'delivering'. A rider who accepts a rider_pending offer is in
 * 'picking_up' — on the way to or at the restaurant, food not yet
 * in hand. The rider then taps "Mark Picked Up" to move the order
 * to 'delivering', and finally "Mark Delivered" to close it.
 *
 * ---------------------------------------------------------------------
 * REACHABILITY
 * ---------------------------------------------------------------------
 * This page is reachable for every order the customer owns — live,
 * delivered, cancelled, or refunded. Only the chat is time-limited.
 * See CHAT GATING below.
 *
 * ---------------------------------------------------------------------
 * CHAT GATING (mirrors the handler)
 * ---------------------------------------------------------------------
 *   - Kitchen tab     → rendered for every order that is not
 *                       cancelled/refunded, plus a one-hour window
 *                       after delivery.
 *   - Rider tab       → rendered only when the order has a rider
 *                       assigned AND the order is not
 *                       cancelled/refunded. Live from 'picking_up'
 *                       through the one-hour post-delivery window.
 *
 * When BOTH chat channels are closed, the page renders a
 * "messaging closed" notice and the chat modal is not rendered at
 * all.
 *
 * ---------------------------------------------------------------------
 * CHAT MODAL CONTRACT
 * ---------------------------------------------------------------------
 * The chat modal's markup is server-rendered here. Its runtime
 * class names are the boundary between this page, order-tracking.js,
 * and order-tracking.css. Those three files MUST agree on the exact
 * strings below. If any one of them changes a string, the other two
 * must change in the same commit.
 *
 * Element ids the JS queries:
 *   #customerChatModal
 *   #customerChatClose
 *   #customerChatSubtitle
 *   #customerChatMessages
 *   #customerChatForm
 *   #customerChatInput
 *   #customerChatRecipient
 *   #customerChatOrderId
 *   #chatOpenBtn
 *   #chatOpenBtnRestaurant
 *
 * Tab selector the JS queries:
 *   .customer-chat-tab[data-recipient]
 *
 * Message node shape emitted by the JS at runtime:
 *   <div class="customer-chat-message customer-chat-message-sent">
 *       <span class="customer-chat-message-sender">You</span>
 *       <span class="customer-chat-message-text">…</span>
 *       <span class="customer-chat-message-time">…</span>
 *   </div>
 *
 *   <div class="customer-chat-message customer-chat-message-received">
 *       <span class="customer-chat-message-sender">Restaurant</span>
 *       <span class="customer-chat-message-text">…</span>
 *       <span class="customer-chat-message-time">…</span>
 *   </div>
 *
 *   <div class="customer-chat-system">…</div>
 *
 * The outer `.customer-chat-message` carries the bubble surface,
 * padding, radius, and max-width. The two modifier classes
 * `-sent` and `-received` carry ONLY the alignment and the colour.
 * The CSS in order-tracking.css matches those exact strings.
 *
 * ---------------------------------------------------------------------
 * REAL-TIME UPDATES
 * ---------------------------------------------------------------------
 * The page renders once, server-side. On top of that, a light poll
 * (order-tracking.js) hits the customer order handler's
 * `get_tracking_status` action every few seconds with the current
 * revision token. When the revision changes, the client reconciles
 * the modal's gating flags in place.
 *
 * The poll endpoint is published on #trackingPage as
 * data-handler-url, pointing at customer-order-handler.php.
 *
 * ---------------------------------------------------------------------
 * SCOPE RULES APPLIED
 * ---------------------------------------------------------------------
 *  - No SQL. Data comes from tracking-queries.php.
 *  - No inline CSS. order-tracking.css is loaded at the top.
 *  - No inline JS. order-tracking.js is loaded at the bottom.
 *  - No <svg> tags. Shared icons from shared/assets/images/icons/.
 * ---------------------------------------------------------------------
 *
 * @package FitPal
 * @version 3.1 — Docblock records the runtime class-name contract
 *                between this page, order-tracking.js, and
 *                order-tracking.css, so a future revision of any
 *                one of them has one place to confirm the exact
 *                strings. No markup change from v3.0.
 *
 *                (3.0: poll endpoint declared on #trackingPage's
 *                data-handler-url attribute. 2.0: poll URL points
 *                at the renamed customer order handler. 1.5:
 *                reachable for every order. 1.4: picking_up
 *                support, 1-hour grace, real-time tracking. 1.3:
 *                chat gating. 1.2: CSRF inherited from header.
 *                1.1: fixed rider/restaurant buttons opening the
 *                same tab.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('customer');

if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    header('Location: sign-in.php');
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($orderId <= 0) {
    header('Location: orders.php');
    exit;
}

require_once __DIR__ . '/../backend/database/customer-connect.php';
require_once __DIR__ . '/../backend/database/tracking-queries.php';

$customerId = (int)$_SESSION['customer_id'];

$order = getTrackableOrder($database_connection, $orderId, $customerId);
if (!$order) {
    $_SESSION['order_error'] = 'Order not found.';
    header('Location: orders.php');
    exit;
}

$orderStatus = (string)$order['order_status'];

// ---------------------------------------------------------------------
// Derived data
// ---------------------------------------------------------------------
$statusMeta    = getTrackingStatusMeta($orderStatus);
$timelineSteps = getTrackingTimelineSteps();
$currentIndex  = getTrackingStepIndex($orderStatus);
$isTerminal    = in_array($orderStatus, ['cancelled', 'refunded'], true);

$restaurants   = getOrderRestaurants($database_connection, $orderId);
$restaurant    = $restaurants[0] ?? null;

$rider         = getOrderRiderDetails($database_connection, $orderId);
$items         = getTrackingOrderItems($database_connection, $orderId);
$totals        = getTrackingOrderTotals($database_connection, $orderId);

$subtotal     = $totals ? (float)$totals['subtotal']     : 0.0;
$deliveryFee  = $totals ? (float)$totals['delivery_fee'] : 0.0;
$serviceFee   = $totals ? (float)$totals['service_fee']  : 0.0;
$vat          = $totals ? (float)$totals['vat']          : 0.0;
$orderTotal   = $totals ? (float)$totals['total']        : 0.0;

// ---------------------------------------------------------------------
// Chat gating flags
// ---------------------------------------------------------------------
$deliveredGraceOpen = customerOrderDeliveredWithinGrace($order);

$showKitchenTab = !$isTerminal
    && ($orderStatus !== 'delivered' || $deliveredGraceOpen);

$hasRider      = $rider !== false;
$showRiderCard = $hasRider && !$isTerminal;

$riderCanBeMessaged = $hasRider
    && !$isTerminal
    && riderHasAcceptedOrder($database_connection, $orderId);

$showRiderTab = $showRiderCard && $riderCanBeMessaged;

$unreadRestaurant = $showKitchenTab
    ? countUnreadOrderMessages($database_connection, $orderId, 'restaurant_account')
    : 0;

$unreadRider = $showRiderTab
    ? countUnreadOrderMessages($database_connection, $orderId, 'delivery_rider')
    : 0;

$chatIsReachable = $showKitchenTab || $showRiderTab;

$chatClosedReason = '';
if (!$chatIsReachable) {
    if ($orderStatus === 'delivered') {
        $chatClosedReason = 'The one-hour messaging window for this delivered order has ended.';
    } elseif (in_array($orderStatus, ['cancelled', 'refunded'], true)) {
        $chatClosedReason = 'This order was ' . $orderStatus . '. Messaging is no longer available.';
    } else {
        $chatClosedReason = 'Messaging is not available for this order.';
    }
}

$defaultChatTab = $showKitchenTab
    ? 'restaurant_account'
    : ($showRiderTab ? 'delivery_rider' : 'restaurant_account');

$liveSnapshot    = getOrderLiveSnapshot($database_connection, $orderId, $customerId);
$initialRevision = $liveSnapshot['revision'] ?? '';

/**
 * Format a peso amount for display on this page.
 */
function trackFmt(float|string|null $amount): string
{
    return '₱' . number_format((float)($amount ?? 0), 2);
}

require_once __DIR__ . '/../includes/header.php';

// $csrfToken is provided by header.php, stored under
// 'customer_csrf_token'.
?>

<link rel="stylesheet" href="../assets/css/order-tracking.css">

<div class="content tracking-page" id="trackingPage" data-order-id="<?php echo $orderId; ?>"
    data-csrf-token="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"
    data-default-chat-tab="<?php echo htmlspecialchars($defaultChatTab, ENT_QUOTES, 'UTF-8'); ?>"
    data-can-message-kitchen="<?php echo $showKitchenTab ? '1' : '0'; ?>"
    data-can-message-rider="<?php echo $riderCanBeMessaged ? '1' : '0'; ?>"
    data-order-status="<?php echo htmlspecialchars($orderStatus, ENT_QUOTES, 'UTF-8'); ?>"
    data-revision="<?php echo htmlspecialchars($initialRevision, ENT_QUOTES, 'UTF-8'); ?>"
    data-handler-url="../backend/handlers/customer-order-handler.php">

    <div class="container">

        <!-- ============================================
             PAGE HEADER
             ============================================ -->
        <div class="page-title-header">
            <div class="page-title-header-top">
                <a href="orders.php" class="back-btn">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-left-line.svg" alt="Back"
                        class="back-btn-icon" width="20" height="20">
                    <span>Back to Orders</span>
                </a>
                <h1>Track Order</h1>
            </div>
        </div>

        <!-- ============================================
             FLASH MESSAGES
             ============================================ -->
        <?php if (isset($_SESSION['order_success'])): ?>
        <div class="alert alert-success" role="alert">
            <?php echo htmlspecialchars($_SESSION['order_success'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['order_success']); ?>
        </div>
        <?php endif; ?>

        <!-- ============================================
             STATUS SUMMARY
             ============================================ -->
        <section class="tracking-status-card" aria-label="Order status">
            <div class="tracking-status-left">
                <div class="tracking-status-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/<?php echo htmlspecialchars($statusMeta['icon'], ENT_QUOTES, 'UTF-8'); ?>"
                        alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/information-fill.svg'">
                </div>
                <div class="tracking-status-info">
                    <p class="tracking-status-label">Current Status</p>
                    <p class="tracking-status-title">
                        <?php echo htmlspecialchars($statusMeta['label'], ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <p class="tracking-status-description">
                        <?php echo htmlspecialchars($statusMeta['description'], ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                </div>
            </div>
            <div class="tracking-status-right">
                <span class="badge <?php echo htmlspecialchars($statusMeta['badge'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($statusMeta['label'], ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <p class="tracking-order-id">Order #<?php echo $orderId; ?></p>
                <p class="tracking-order-date">
                    <?php echo htmlspecialchars(formatTrackingTimestamp((string)$order['order_date']), ENT_QUOTES, 'UTF-8'); ?>
                </p>
            </div>
        </section>

        <!-- ============================================
             PROGRESS TIMELINE
             ============================================ -->
        <?php if (!$isTerminal): ?>
        <section class="tracking-timeline-card" aria-label="Order progress">
            <p class="tracking-timeline-heading">Order Progress</p>
            <div class="tracking-timeline">
                <?php foreach ($timelineSteps as $index => $step):
                    $state = 'pending';
                    if ($currentIndex >= 0) {
                        if ($index < $currentIndex) {
                            $state = 'done';
                        } elseif ($index === $currentIndex) {
                            $state = 'current';
                        }
                    }
                ?>
                <div class="tracking-step <?php echo $state; ?>">
                    <div class="tracking-step-dot">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/<?php
                            echo $state === 'done' ? 'verified-fill.svg' : 'time-fill.svg';
                        ?>" alt=""
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/information-fill.svg'">
                    </div>
                    <p class="tracking-step-label">
                        <?php echo htmlspecialchars($step['label'], ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <span class="tracking-step-connector" aria-hidden="true"></span>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- ============================================
             CHAT-CLOSED NOTICE
             ============================================ -->
        <?php if (!$chatIsReachable && $chatClosedReason !== ''): ?>
        <div class="tracking-chat-closed-notice" role="status">
            <img src="<?php echo $assetBase; ?>assets/images/icons/information-fill.svg" alt=""
                class="tracking-chat-closed-icon" width="18" height="18"
                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
            <p class="tracking-chat-closed-text">
                <?php echo htmlspecialchars($chatClosedReason, ENT_QUOTES, 'UTF-8'); ?>
            </p>
        </div>
        <?php endif; ?>

        <!-- ============================================
             MAIN ROW
             ============================================ -->
        <div class="tracking-row">

            <!-- LEFT COLUMN: rider + restaurant -->
            <div>

                <!-- Rider Card -->
                <?php if ($showRiderCard): ?>
                <section class="tracking-card" style="margin-bottom: 24px;" aria-labelledby="rider-card-title">
                    <div class="card-header">
                        <h2 class="heading-5" id="rider-card-title">Your Rider</h2>
                    </div>
                    <div class="card-body">
                        <div class="rider-info">
                            <div class="rider-avatar">
                                <?php if (!empty($rider['profile_picture'])): ?>
                                <img src="<?php echo htmlspecialchars($rider['profile_picture'], ENT_QUOTES, 'UTF-8'); ?>"
                                    alt=""
                                    onerror="this.onerror=null; this.style.display='none'; this.parentNode.textContent='<?php echo htmlspecialchars(strtoupper(substr((string)($rider['first_name'] ?? 'R'), 0, 1)), ENT_QUOTES, 'UTF-8'); ?>';">
                                <?php else: ?>
                                <?php echo htmlspecialchars(strtoupper(substr((string)($rider['first_name'] ?? 'R'), 0, 1)), ENT_QUOTES, 'UTF-8'); ?>
                                <?php endif; ?>
                            </div>
                            <div class="rider-details">
                                <p class="rider-name">
                                    <?php echo htmlspecialchars(getRiderDisplayName($rider), ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                                <p class="rider-meta">
                                    <?php
                                    $vehicle = ucfirst((string)($rider['vehicle_type'] ?? ''));
                                    $plate   = (string)($rider['vehicle_plate'] ?? '');
                                    $rating  = (float)($rider['average_rating'] ?? 0);
                                    $parts   = [];
                                    if ($vehicle !== '') {
                                        $parts[] = $vehicle . ($plate !== '' ? ' • ' . $plate : '');
                                    }
                                    if ($rating > 0) {
                                        $parts[] = '★ ' . number_format($rating, 1);
                                    }
                                    echo htmlspecialchars(implode(' • ', $parts), ENT_QUOTES, 'UTF-8');
                                    ?>
                                </p>
                            </div>
                        </div>
                        <div class="rider-actions">
                            <?php if ($riderCanBeMessaged): ?>
                            <button type="button" class="btn btn-chat" id="chatOpenBtn" data-open-tab="delivery_rider">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/contact-us-line.svg" alt=""
                                    class="btn-icon" width="16" height="16">
                                <span>Message<?php echo $unreadRider > 0 ? ' (' . $unreadRider . ')' : ''; ?></span>
                            </button>
                            <?php elseif ($orderStatus === 'rider_pending'): ?>
                            <button type="button" class="btn btn-chat" disabled aria-disabled="true"
                                title="Waiting for the rider to accept your order">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/contact-us-line.svg" alt=""
                                    class="btn-icon" width="16" height="16">
                                <span>Waiting for rider to accept</span>
                            </button>
                            <?php elseif ($orderStatus === 'delivered'): ?>
                            <button type="button" class="btn btn-chat" disabled aria-disabled="true"
                                title="The messaging window for this order has ended">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/contact-us-line.svg" alt=""
                                    class="btn-icon" width="16" height="16">
                                <span>Messaging window closed</span>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
                <?php elseif (!$hasRider && !$isTerminal): ?>
                <section class="tracking-card" style="margin-bottom: 24px;" aria-labelledby="rider-card-title">
                    <div class="card-header">
                        <h2 class="heading-5" id="rider-card-title">Your Rider</h2>
                    </div>
                    <div class="card-body">
                        <div class="rider-placeholder">
                            <div class="rider-placeholder-icon">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/riding-line.svg" alt=""
                                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/user-profile-circle.svg'">
                            </div>
                            <p>A rider will be assigned once your order is ready for pickup.</p>
                        </div>
                    </div>
                </section>
                <?php endif; ?>

                <!-- Restaurant Card -->
                <?php if ($restaurant): ?>
                <section class="tracking-card" aria-labelledby="restaurant-card-title">
                    <div class="card-header">
                        <h2 class="heading-5" id="restaurant-card-title">Restaurant</h2>
                    </div>
                    <div class="card-body">
                        <div class="restaurant-info">
                            <div class="restaurant-avatar">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt=""
                                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/restaurant-fill.svg'">
                            </div>
                            <div class="restaurant-details">
                                <p class="restaurant-name">
                                    <?php echo htmlspecialchars(getRestaurantDisplayName($restaurant), ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                                <p class="restaurant-branch">
                                    <?php
                                    $branchParts = array_filter([
                                        $restaurant['branch_name'] ?? '',
                                        $restaurant['barangay']    ?? '',
                                        $restaurant['city']        ?? '',
                                    ]);
                                    echo htmlspecialchars(implode(', ', $branchParts), ENT_QUOTES, 'UTF-8');
                                    ?>
                                </p>
                            </div>
                        </div>
                        <?php if ($showKitchenTab): ?>
                        <div class="restaurant-actions">
                            <button type="button" class="btn btn-chat" id="chatOpenBtnRestaurant"
                                data-open-tab="restaurant_account">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/contact-us-line.svg" alt=""
                                    class="btn-icon" width="16" height="16">
                                <span>Message<?php echo $unreadRestaurant > 0 ? ' (' . $unreadRestaurant . ')' : ''; ?></span>
                            </button>
                        </div>
                        <?php elseif ($orderStatus === 'delivered'): ?>
                        <div class="restaurant-actions">
                            <button type="button" class="btn btn-chat" disabled aria-disabled="true"
                                title="The messaging window for this order has ended">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/contact-us-line.svg" alt=""
                                    class="btn-icon" width="16" height="16">
                                <span>Messaging window closed</span>
                            </button>
                        </div>
                        <?php elseif ($isTerminal): ?>
                        <p class="rider-placeholder" style="margin-top: 12px;">
                            This order was <?php echo htmlspecialchars($orderStatus, ENT_QUOTES, 'UTF-8'); ?>.
                            Messaging is no longer available.
                        </p>
                        <?php endif; ?>
                    </div>
                </section>
                <?php endif; ?>

            </div>

            <!-- RIGHT COLUMN: order summary -->
            <aside class="tracking-card" aria-labelledby="summary-card-title">
                <div class="card-header">
                    <h2 class="heading-5" id="summary-card-title">Order Summary</h2>
                </div>
                <div class="card-body">
                    <p class="summary-order-id">Order #<?php echo $orderId; ?></p>

                    <ul class="summary-items">
                        <?php foreach ($items as $item): ?>
                        <li class="summary-item">
                            <span class="summary-item-name">
                                <?php echo htmlspecialchars((string)$item['product_name'], ENT_QUOTES, 'UTF-8'); ?>
                                <span class="summary-item-qty">×<?php echo (int)$item['quantity']; ?></span>
                            </span>
                            <span class="summary-item-price">
                                <?php echo trackFmt((float)($item['final_price'] ?? $item['unit_price']) * (int)$item['quantity']); ?>
                            </span>
                        </li>
                        <?php endforeach; ?>
                    </ul>

                    <div class="summary-totals">
                        <div class="summary-total-row">
                            <span>Subtotal</span>
                            <span><?php echo trackFmt($subtotal); ?></span>
                        </div>
                        <div class="summary-total-row">
                            <span>Delivery Fee</span>
                            <span><?php echo trackFmt($deliveryFee); ?></span>
                        </div>
                        <div class="summary-total-row">
                            <span>Service Fee</span>
                            <span><?php echo trackFmt($serviceFee); ?></span>
                        </div>
                        <div class="summary-total-row">
                            <span>VAT</span>
                            <span><?php echo trackFmt($vat); ?></span>
                        </div>
                        <div class="summary-total-row grand">
                            <span>Total</span>
                            <span><?php echo trackFmt($orderTotal); ?></span>
                        </div>
                    </div>

                    <div class="summary-address">
                        <p class="summary-address-label">Deliver to</p>
                        <p class="summary-address-text">
                            <?php echo htmlspecialchars((string)$order['destination_address'], ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                    </div>
                </div>
            </aside>

        </div>
    </div>
</div>

<!-- ============================================
     CUSTOMER CHAT MODAL
     ============================================ -->
<?php if ($chatIsReachable): ?>
<div id="customerChatModal" class="modal" style="display: none;">
    <div class="modal-overlay"></div>
    <div class="modal-content customer-chat-modal-content">
        <div class="modal-header">
            <div>
                <p class="heading-5 modal-title">Order Messages</p>
                <p class="modal-subtitle" id="customerChatSubtitle">Order #<?php echo $orderId; ?></p>
            </div>
            <button type="button" class="modal-close" id="customerChatClose">&times;</button>
        </div>

        <div class="customer-chat-tabs">
            <?php if ($showKitchenTab): ?>
            <button type="button"
                class="customer-chat-tab<?php echo $defaultChatTab === 'restaurant_account' ? ' active' : ''; ?>"
                data-recipient="restaurant_account"
                aria-selected="<?php echo $defaultChatTab === 'restaurant_account' ? 'true' : 'false'; ?>">
                <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt=""
                    class="customer-chat-tab-icon" width="16" height="16">
                <span>Restaurant</span>
            </button>
            <?php endif; ?>

            <?php if ($showRiderTab): ?>
            <button type="button"
                class="customer-chat-tab<?php echo $defaultChatTab === 'delivery_rider' ? ' active' : ''; ?>"
                data-recipient="delivery_rider"
                aria-selected="<?php echo $defaultChatTab === 'delivery_rider' ? 'true' : 'false'; ?>">
                <img src="<?php echo $assetBase; ?>assets/images/icons/riding-fill.svg" alt=""
                    class="customer-chat-tab-icon" width="16" height="16">
                <span>Rider</span>
            </button>
            <?php endif; ?>
        </div>

        <div class="customer-chat-body" id="customerChatMessages">
            <div class="customer-chat-loading"><span>Loading messages…</span></div>
        </div>

        <form class="customer-chat-form" id="customerChatForm" action="../backend/handlers/message-handler.php">
            <input type="hidden" id="customerChatOrderId" value="<?php echo $orderId; ?>">
            <input type="hidden" id="customerChatRecipient"
                value="<?php echo htmlspecialchars($defaultChatTab, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="text" id="customerChatInput" class="customer-chat-input" placeholder="Type your message…"
                maxlength="500" autocomplete="off" required>
            <button type="submit" class="customer-chat-send" aria-label="Send message">
                <img src="<?php echo $assetBase; ?>assets/images/icons/send-plane-fill.svg" alt="Send"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/arrow-right-s-line.svg'">
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<script src="../assets/ui/js/order-tracking.js" defer></script>
<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>