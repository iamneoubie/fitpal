<?php
/**
 * FitPal Rider Deliveries Page
 *
 * Sections:
 *   1. Assigned to You  — orders the kitchen has handed to this
 *                          rider and that are waiting on an
 *                          accept / decline decision.
 *   2. Active           — orders in transit (rider accepted).
 *   3. History          — recently closed deliveries.
 *
 * Confirmation model
 * ------------------
 * Delivery actions open a styled confirm modal. The copy is authored
 * on each button via data-confirm-* attributes so the JS never
 * carries text. The modal carries two attributes:
 *   - data-variant: "primary" or "danger" (drives button colour)
 *   - data-icon:    "accept" | "decline" | "delivered"
 *                   (drives which SVG is visible)
 *
 * Icon rule
 * ---------
 * Every icon file referenced below exists under
 * shared/assets/images/icons/. The three confirm-modal icons are:
 *   accept    -> verified-fill.svg
 *   decline   -> close-circle-fill.svg
 *   delivered -> verified-badge-fill.svg
 *
 * Button rules
 * ------------
 *   - Positive / confirm → primary (green / white)
 *   - Neutral             → black background / white text
 *   - Negative            → danger (red / white)
 * No transparent or outlined buttons are used on this page.
 *
 * @package FitPal
 * @version 3.4 — Icon sources verified against the project tree:
 *                  - check-circle-fill.svg removed (does not exist).
 *                  - send-plane-fill.svg removed (does not exist).
 *                  - accept slot now uses verified-fill.svg.
 *                  - delivered slot now uses verified-badge-fill.svg.
 *                  - chat send now uses arrow-right-s-line.svg.
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
$counts           = getRiderDeliveryCounts($database_connection, $riderId);

$available  = (int)($profile['is_available'] ?? 0) === 1;
$status     = (string)($profile['verification_status'] ?? 'pending');
$isVerified = $status === 'verified';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

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
        'delivering'    => 'badge-primary',
        'delivered'     => 'badge-success',
        'cancelled'     => 'badge-danger',
        'refunded'      => 'badge-secondary',
        default         => 'badge-secondary',
    };
}

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

        <header class="rider-page-header">
            <div>
                <h1 class="heading-2">My <span>Deliveries</span></h1>
                <p class="text-muted">Confirm assignments, manage active deliveries, and view your history</p>
            </div>
            <div class="rider-page-actions">
                <span class="badge <?php echo $available ? 'badge-success' : 'badge-secondary'; ?>">
                    <?php echo $available ? 'Online' : 'Offline'; ?>
                </span>
            </div>
        </header>

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

        <section class="rider-delivery-stats">
            <div class="rider-delivery-stat">
                <span class="rider-delivery-stat-number"><?php echo count($assignedOrders); ?></span>
                <span class="rider-delivery-stat-label">Awaiting You</span>
            </div>
            <div class="rider-delivery-stat">
                <span class="rider-delivery-stat-number"><?php echo $counts['active']; ?></span>
                <span class="rider-delivery-stat-label">Active</span>
            </div>
            <div class="rider-delivery-stat">
                <span class="rider-delivery-stat-number"><?php echo $counts['today']; ?></span>
                <span class="rider-delivery-stat-label">Today</span>
            </div>
            <div class="rider-delivery-stat">
                <span class="rider-delivery-stat-number"><?php echo $counts['total']; ?></span>
                <span class="rider-delivery-stat-label">All Time</span>
            </div>
        </section>

        <!-- ============================================================
             ASSIGNED TO YOU
             ============================================================ -->
        <section class="rider-card">
            <div class="rider-card-header">
                <h2 class="heading-5">
                    Assigned to You
                    <?php if (count($assignedOrders) > 0): ?>
                    <span class="badge badge-warning"><?php echo count($assignedOrders); ?></span>
                    <?php endif; ?>
                </h2>
            </div>

            <?php if (empty($assignedOrders)): ?>
            <div class="rider-empty-state">
                <div class="rider-empty-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/package.svg" alt="No pending assignments"
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/order.svg'">
                </div>
                <p class="rider-empty-title">No assignments yet</p>
                <p class="rider-empty-text">
                    <?php if (!$isVerified): ?>
                    Your account is pending verification. Assignments will appear once you're verified.
                    <?php elseif (!$available): ?>
                    You're offline. Go online from the dashboard so the kitchen can assign you orders.
                    <?php else: ?>
                    When the kitchen assigns you an order, it will appear here for you to accept or decline.
                    <?php endif; ?>
                </p>
            </div>
            <?php else: ?>
            <div class="rider-active-deliveries">
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
                    $acceptBody  = 'You\'ll be responsible for picking up this order and delivering it to the customer. You won\'t be able to go offline until the delivery is complete.';

                    $declineTitle = 'Decline this assignment?';
                    $declineBody  = 'The kitchen will choose another rider for this order. This cannot be undone.';
                ?>
                <div class="rider-active-delivery-card" data-order-id="<?php echo $orderId; ?>">
                    <div class="rider-active-delivery-header">
                        <div>
                            <p class="rider-active-delivery-order">Order #<?php echo $orderId; ?></p>
                            <p class="rider-active-delivery-date">
                                <?php echo formatRiderDate($orderDate); ?>
                            </p>
                        </div>
                        <span class="badge badge-warning">Awaiting Your Confirmation</span>
                    </div>

                    <div class="rider-active-delivery-body">
                        <div class="rider-delivery-stop">
                            <div class="rider-delivery-stop-icon rider-delivery-stop-icon-pickup">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt="Pickup"
                                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/community-general.svg'">
                            </div>
                            <div class="rider-delivery-stop-info">
                                <p class="rider-delivery-stop-label">Pickup</p>
                                <p class="rider-delivery-stop-name">
                                    <?php echo htmlspecialchars($restaurantName, ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                                <p class="rider-delivery-stop-address">
                                    <?php echo htmlspecialchars($branchName, ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                            </div>
                        </div>

                        <div class="rider-delivery-connector" aria-hidden="true"></div>

                        <div class="rider-delivery-stop">
                            <div class="rider-delivery-stop-icon rider-delivery-stop-icon-dropoff">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/location-fill.svg" alt="Dropoff">
                            </div>
                            <div class="rider-delivery-stop-info">
                                <p class="rider-delivery-stop-label">Drop-off</p>
                                <p class="rider-delivery-stop-name">
                                    <?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                                <p class="rider-delivery-stop-address">
                                    <?php echo htmlspecialchars(truncateText($destination, 60), ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="rider-active-delivery-meta">
                        <div class="rider-active-delivery-meta-item">
                            <span class="rider-active-delivery-meta-label">Items</span>
                            <span class="rider-active-delivery-meta-value"><?php echo $itemCount; ?></span>
                        </div>
                        <div class="rider-active-delivery-meta-item">
                            <span class="rider-active-delivery-meta-label">Order Total</span>
                            <span
                                class="rider-active-delivery-meta-value"><?php echo formatRiderCurrency($orderTotal); ?></span>
                        </div>
                        <div class="rider-active-delivery-meta-item">
                            <span class="rider-active-delivery-meta-label">Your Earning</span>
                            <span class="rider-active-delivery-meta-value rider-active-delivery-meta-value-highlight">
                                <?php echo formatRiderCurrency(50.00); ?>
                            </span>
                        </div>
                    </div>

                    <div class="rider-active-delivery-actions">
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
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        <!-- ============================================================
             ACTIVE DELIVERIES
             ============================================================ -->
        <section class="rider-card">
            <div class="rider-card-header">
                <h2 class="heading-5">
                    Active Deliveries
                    <?php if (count($activeDeliveries) > 0): ?>
                    <span class="badge badge-primary"><?php echo count($activeDeliveries); ?></span>
                    <?php endif; ?>
                </h2>
            </div>

            <?php if (empty($activeDeliveries)): ?>
            <div class="rider-empty-state">
                <div class="rider-empty-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/package.svg" alt="No active deliveries"
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/order.svg'">
                </div>
                <p class="rider-empty-title">No active deliveries</p>
                <p class="rider-empty-text">
                    Accept an assignment above to start a delivery.
                </p>
            </div>
            <?php else: ?>
            <div class="rider-active-deliveries">
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
                <div class="rider-active-delivery-card" data-order-id="<?php echo $orderId; ?>">
                    <div class="rider-active-delivery-header">
                        <div>
                            <p class="rider-active-delivery-order">Order #<?php echo $orderId; ?></p>
                            <p class="rider-active-delivery-date">
                                <?php echo formatRiderDate($orderDate); ?>
                            </p>
                        </div>
                        <span class="badge <?php echo getDeliveryStatusClass($orderStatus); ?>">
                            <?php echo getDeliveryStatusLabel($orderStatus); ?>
                        </span>
                    </div>

                    <div class="rider-active-delivery-body">
                        <div class="rider-delivery-stop">
                            <div class="rider-delivery-stop-icon rider-delivery-stop-icon-pickup">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt="Pickup"
                                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/community-general.svg'">
                            </div>
                            <div class="rider-delivery-stop-info">
                                <p class="rider-delivery-stop-label">Pickup</p>
                                <p class="rider-delivery-stop-name">
                                    <?php echo htmlspecialchars($restaurantName, ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                                <p class="rider-delivery-stop-address">
                                    <?php echo htmlspecialchars($branchName, ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                            </div>
                        </div>

                        <div class="rider-delivery-connector" aria-hidden="true"></div>

                        <div class="rider-delivery-stop">
                            <div class="rider-delivery-stop-icon rider-delivery-stop-icon-dropoff">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/location-fill.svg" alt="Dropoff">
                            </div>
                            <div class="rider-delivery-stop-info">
                                <p class="rider-delivery-stop-label">Drop-off</p>
                                <p class="rider-delivery-stop-name">
                                    <?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                                <p class="rider-delivery-stop-address">
                                    <?php echo htmlspecialchars($destination, ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="rider-active-delivery-meta">
                        <div class="rider-active-delivery-meta-item">
                            <span class="rider-active-delivery-meta-label">Items</span>
                            <span class="rider-active-delivery-meta-value"><?php echo $itemCount; ?></span>
                        </div>
                        <div class="rider-active-delivery-meta-item">
                            <span class="rider-active-delivery-meta-label">Order Total</span>
                            <span
                                class="rider-active-delivery-meta-value"><?php echo formatRiderCurrency($orderTotal); ?></span>
                        </div>
                        <div class="rider-active-delivery-meta-item">
                            <span class="rider-active-delivery-meta-label">Your Earning</span>
                            <span class="rider-active-delivery-meta-value rider-active-delivery-meta-value-highlight">
                                <?php echo formatRiderCurrency($riderEarning); ?>
                            </span>
                        </div>
                    </div>

                    <div class="rider-active-delivery-actions">
                        <?php if ($customerPhone !== ''): ?>
                        <a href="tel:<?php echo htmlspecialchars($customerPhone, ENT_QUOTES, 'UTF-8'); ?>"
                            class="btn btn-neutral btn-sm">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/phone-fill.svg" alt=""
                                class="btn-icon" width="16" height="16"
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/contact-us-line.svg'">
                            <span>Call Customer</span>
                        </a>
                        <?php endif; ?>

                        <button type="button" class="btn btn-neutral btn-sm delivery-chat-btn"
                            data-order-id="<?php echo $orderId; ?>"
                            data-customer-name="<?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?>"
                            data-restaurant-name="<?php echo htmlspecialchars($restaurantName, ENT_QUOTES, 'UTF-8'); ?>">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/chat-line.svg" alt=""
                                class="btn-icon" width="16" height="16"
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/contact-us-line.svg'">
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
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        <!-- ============================================================
             DELIVERY HISTORY
             ============================================================ -->
        <section class="rider-card">
            <div class="rider-card-header">
                <h2 class="heading-5">Delivery History</h2>
            </div>

            <?php if (empty($deliveryHistory)): ?>
            <div class="rider-empty-state">
                <div class="rider-empty-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/time-update.svg" alt="No history"
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/update.svg'">
                </div>
                <p class="rider-empty-title">No delivery history</p>
                <p class="rider-empty-text">Your completed deliveries will appear here.</p>
            </div>
            <?php else: ?>
            <div class="rider-history-list">
                <?php foreach ($deliveryHistory as $delivery):
                    $orderId      = (int)$delivery['order_id'];
                    $orderStatus  = (string)$delivery['order_status'];
                    $customerName = (string)$delivery['customer_name'];
                    $deliveredAt  = (string)($delivery['delivered_at'] ?? '');
                    $riderEarning = 50.00;
                ?>
                <div class="rider-history-row">
                    <div
                        class="rider-history-icon <?php echo $orderStatus === 'delivered' ? 'rider-history-icon-success' : 'rider-history-icon-neutral'; ?>">
                        <?php if ($orderStatus === 'delivered'): ?>
                        <img src="<?php echo $assetBase; ?>assets/images/icons/verified-fill.svg" alt="Delivered"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/verified-badge-fill.svg'">
                        <?php else: ?>
                        <img src="<?php echo $assetBase; ?>assets/images/icons/close-circle-fill.svg" alt="Cancelled"
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
                            <?php echo $orderStatus === 'delivered' ? '+' : ''; ?><?php echo formatRiderCurrency($orderStatus === 'delivered' ? $riderEarning : 0); ?>
                        </p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

    </div>
</div>

<!-- ============================================================
     CHAT MODAL
     ============================================================ -->
<div id="riderChatModal" class="modal" style="display: none;">
    <div class="modal-overlay"></div>
    <div class="modal-content rider-chat-modal-content">
        <div class="modal-header">
            <div>
                <p class="heading-5 modal-title">Order Messages</p>
                <p class="modal-subtitle" id="riderChatSubtitle"></p>
            </div>
            <button type="button" class="modal-close" id="riderChatClose">&times;</button>
        </div>

        <div class="rider-chat-tabs">
            <button type="button" class="rider-chat-tab active" data-recipient="customer">
                <img src="<?php echo $assetBase; ?>assets/images/icons/user-profile-circle.svg" alt=""
                    class="rider-chat-tab-icon" width="16" height="16">
                <span>Customer</span>
            </button>
            <button type="button" class="rider-chat-tab" data-recipient="restaurant_account">
                <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt=""
                    class="rider-chat-tab-icon" width="16" height="16">
                <span>Kitchen</span>
            </button>
        </div>

        <div class="rider-chat-body" id="riderChatMessages">
            <div class="rider-chat-loading"><span>Loading messages…</span></div>
        </div>

        <form class="rider-chat-form" id="riderChatForm">
            <input type="hidden" id="riderChatOrderId" value="">
            <input type="hidden" id="riderChatRecipient" value="customer">
            <input type="text" id="riderChatInput" class="rider-chat-input" placeholder="Type your message…"
                maxlength="500" autocomplete="off" required>
            <button type="submit" class="rider-chat-send" aria-label="Send message">
                <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-right-s-line.svg" alt="Send"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/arrow-right-long-line.svg'">
            </button>
        </form>
    </div>
</div>

<!-- ============================================================
     CONFIRM MODAL
     Icon-led card. Three icons are rendered inside the icon
     circle; CSS shows only the one matching the modal's
     data-icon attribute. The confirm button's colour follows the
     modal's data-variant.
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
    riderId: <?php echo $riderId; ?>
};
</script>
<script src="../assets/ui/js/deliveries.js" defer></script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>