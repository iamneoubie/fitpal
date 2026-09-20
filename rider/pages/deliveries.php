<?php
/**
 * FitPal Rider Deliveries Page
 *
 * Active deliveries + delivery history + customer/kitchen chat.
 *
 * @package FitPal
 * @version 1.1 — Icons use shared asset paths; chat modal for both
 *                customer and kitchen conversations.
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
        'pending'    => 'badge-warning',
        'preparing'  => 'badge-info',
        'delivering' => 'badge-primary',
        'delivered'  => 'badge-success',
        'cancelled'  => 'badge-danger',
        'refunded'   => 'badge-secondary',
        default      => 'badge-secondary',
    };
}

function getDeliveryStatusLabel(string $status): string
{
    return match ($status) {
        'pending'    => 'Pending',
        'preparing'  => 'Preparing',
        'delivering' => 'In Transit',
        'delivered'  => 'Delivered',
        'cancelled'  => 'Cancelled',
        'refunded'   => 'Refunded',
        default      => ucfirst($status),
    };
}

// Now that we've done all the DB work, include the header (which outputs HTML).
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content rider-deliveries-page">
    <div class="container">

        <header class="rider-page-header">
            <div>
                <h1 class="heading-2">My <span>Deliveries</span></h1>
                <p class="text-muted">Manage active deliveries and view your history</p>
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
                <span class="rider-delivery-stat-number"><?php echo $counts['active']; ?></span>
                <span class="rider-delivery-stat-label">Active</span>
            </div>
            <div class="rider-delivery-stat">
                <span class="rider-delivery-stat-number"><?php echo $counts['today']; ?></span>
                <span class="rider-delivery-stat-label">Today</span>
            </div>
            <div class="rider-delivery-stat">
                <span class="rider-delivery-stat-number"><?php echo $counts['week']; ?></span>
                <span class="rider-delivery-stat-label">This Week</span>
            </div>
            <div class="rider-delivery-stat">
                <span class="rider-delivery-stat-number"><?php echo $counts['total']; ?></span>
                <span class="rider-delivery-stat-label">All Time</span>
            </div>
        </section>

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
                    <?php if (!$isVerified): ?>
                    Your account is pending verification. You'll receive delivery requests once verified.
                    <?php elseif (!$available): ?>
                    You're offline. Go online from the dashboard to receive delivery requests.
                    <?php else: ?>
                    You're online. New delivery requests will appear here.
                    <?php endif; ?>
                </p>
                <?php if ($available && $isVerified): ?>
                <a href="dashboard.php" class="btn btn-outline btn-sm">Back to Dashboard</a>
                <?php endif; ?>
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

                    $nextAction = match ($orderStatus) {
                        'preparing'  => ['label' => 'Picked Up', 'action' => 'picked_up', 'class' => 'btn-primary'],
                        'delivering' => ['label' => 'Mark Delivered', 'action' => 'delivered', 'class' => 'btn-primary'],
                        default      => null,
                    };
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
                            class="btn btn-outline btn-sm">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/phone-fill.svg" alt=""
                                class="btn-icon" width="16" height="16"
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/contact-us-line.svg'">
                            <span>Call Customer</span>
                        </a>
                        <?php endif; ?>

                        <button type="button" class="btn btn-outline btn-sm delivery-chat-btn"
                            data-order-id="<?php echo $orderId; ?>"
                            data-customer-name="<?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?>"
                            data-restaurant-name="<?php echo htmlspecialchars($restaurantName, ENT_QUOTES, 'UTF-8'); ?>">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/chat-line.svg" alt=""
                                class="btn-icon" width="16" height="16"
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/contact-us-line.svg'">
                            <span>Message</span>
                        </button>

                        <?php if ($nextAction !== null): ?>
                        <button type="button" class="btn <?php echo $nextAction['class']; ?> btn-sm delivery-status-btn"
                            data-order-id="<?php echo $orderId; ?>" data-action="<?php echo $nextAction['action']; ?>">
                            <?php echo htmlspecialchars($nextAction['label'], ENT_QUOTES, 'UTF-8'); ?>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

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
                        <img src="<?php echo $assetBase; ?>assets/images/icons/check-circle-fill.svg" alt="Delivered"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/verified-fill.svg'">
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

<!-- Chat Modal -->
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
                <img src="<?php echo $assetBase; ?>assets/images/icons/send-plane-fill.svg" alt="Send"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/arrow-right-s-line.svg'">
            </button>
        </form>
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