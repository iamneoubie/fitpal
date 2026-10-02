<?php
/**
 * FitPal Customer Orders Page
 *
 * Displays customer order history with per-product detail expansion,
 * a dropdown fee breakdown, and one-click reorder.
 *
 * Totals are computed from queue_item + the fee schedule — never read
 * from the orders table.
 *
 * ---------------------------------------------------------------------
 * PRODUCT IMAGES
 * ---------------------------------------------------------------------
 * The raw dietary_information.images value returned by the query
 * layer is resolved into a browser-loadable URL by
 * resolveOrderItemImageUrl() below, which delegates to the helpers
 * in product-queries.php:
 *
 *     getProductImageBasePath()    resolves the folder
 *     getProductPrimaryFilename()  finds the first image file
 *
 * ---------------------------------------------------------------------
 * REAL-TIME POLL
 * ---------------------------------------------------------------------
 * Each .order-card carries a data-revision attribute computed from
 * (status + delivered_at + grace_open). customer-order.js polls
 * get_order_card_state every few seconds and patches only the cards
 * whose revision changed. Nothing else is touched.
 *
 * ---------------------------------------------------------------------
 * CARD LAYOUT (top to bottom)
 * ---------------------------------------------------------------------
 *   .order-card-header
 *       left    — Order # and date
 *       center  — payment pill
 *       right   — status badge         (class js-order-badge)
 *
 *   .order-card-body
 *       per-item rows, each independently expandable
 *
 *   .order-card-totals
 *       single button row: "Total ₱X.XX ⌄"
 *
 *   .order-card-footer
 *       action buttons only            (wrapper class js-order-actions)
 *
 * ---------------------------------------------------------------------
 * FILTER TABS
 * ---------------------------------------------------------------------
 * Nine tabs, wallet-style. "Active" is the default and matches every
 * non-terminal status.
 *
 * ---------------------------------------------------------------------
 * TERMINAL-STATUS ACTIONS
 * ---------------------------------------------------------------------
 *   delivered:
 *       1. Message OR Track History   (btn-neutral)
 *       2. Review                     (btn-primary)
 *       3. View Receipt               (btn-neutral)
 *       4. Reorder                    (btn-primary)
 *
 *   cancelled / refunded / failed:
 *       1. Track History              (btn-neutral)
 *       2. View Receipt               (btn-neutral)
 *       3. Reorder                    (btn-primary)
 *
 * ---------------------------------------------------------------------
 * CLIENT CONFIG
 * ---------------------------------------------------------------------
 *   window.FITPAL_ORDERS.csrfToken      the customer's own token
 *   window.FITPAL_ORDERS.assetBase      project-root-relative
 *   window.FITPAL_ORDERS.handlerUrl     customer-order-handler.php
 *
 * @package FitPal
 * @version 11.0 — Adds data-revision per card and js-* classes on
 *                 the badge, footer actions, and tracking button so
 *                 customer-order.js can patch them without a reload.
 *
 *                 (10.0: order item images resolved through
 *                 product-queries.php. 9.0: wallet-style filter tabs;
 *                 Review footer button. 8.0: chevron totals
 *                 dropdown. 7.0: consistent terminal-status layout.)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('customer');

if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    header('Location: sign-in.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../backend/database/customer-order-queries.php';
require_once __DIR__ . '/../backend/database/tracking-queries.php';
require_once __DIR__ . '/../backend/database/product-queries.php';

$customerId = (int)$_SESSION['customer_id'];

// ============================================
// FETCH ORDERS
// ============================================
$orders = [];

try {
    $stmt = $database_connection->prepare(
        "SELECT
            o.order_id,
            o.order_status,
            o.payment_method,
            o.destination_address,
            o.order_date,
            o.delivered_at,
            o.cancelled_by
         FROM orders o
         WHERE o.customer_id = :customer_id
         ORDER BY o.order_date DESC"
    );
    $stmt->execute([':customer_id' => $customerId]);
    $rawOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rawOrders as $order) {
        $orderId = (int)$order['order_id'];

        $totals = getOrderTotals($database_connection, $orderId);
        $order['subtotal']     = $totals ? $totals['subtotal']     : 0.0;
        $order['delivery_fee'] = $totals ? $totals['delivery_fee'] : 0.0;
        $order['service_fee']  = $totals ? $totals['service_fee']  : 0.0;
        $order['vat']          = $totals ? $totals['vat']          : 0.0;
        $order['total_amount'] = $totals ? $totals['total']        : 0.0;
        $order['branch_count'] = $totals ? $totals['branch_count'] : 0;

        $order['items'] = getOrderItemsWithCustomizations($database_connection, $orderId);
        $order['item_count'] = count($order['items']);

        $orders[] = $order;
    }
} catch (PDOException $e) {
    error_log('Orders page error: ' . $e->getMessage());
}

// ============================================
// HELPERS
// ============================================

function formatOrderCurrency(float|string|null $amount): string
{
    return '₱' . number_format((float)($amount ?? 0), 2);
}

function getOrderStatusBadgeClass(string $status): string
{
    return match ($status) {
        'pending'       => 'badge-warning',
        'preparing'     => 'badge-info',
        'picking_up'    => 'badge-info',
        'rider_pending' => 'badge-info',
        'delivering'    => 'badge-primary',
        'delivered'     => 'badge-success',
        'cancelled'     => 'badge-danger',
        'refunded'      => 'badge-secondary',
        'failed'        => 'badge-danger',
        default         => 'badge-secondary',
    };
}

function getOrderStatusLabel(string $status): string
{
    return match ($status) {
        'pending'       => 'Pending',
        'preparing'     => 'Preparing',
        'rider_pending' => 'Rider Pending',
        'picking_up'    => 'Picking Up',
        'delivering'    => 'For Delivery',
        'delivered'     => 'Delivered',
        'cancelled'     => 'Cancelled',
        'refunded'      => 'Refunded',
        'failed'        => 'Failed',
        default         => ucfirst($status),
    };
}

function formatOrderDateTime(string $date): string
{
    $timestamp = strtotime($date);
    return $timestamp !== false ? date('M d, Y g:i A', $timestamp) : $date;
}

/**
 * Build the browser-loadable URL for an order item's product image.
 *
 * Returns the shared restaurant icon when the folder cannot be
 * resolved, when the folder contains no image file, or when the
 * raw path is empty.
 */
function resolveOrderItemImageUrl(string $rawPath, string $assetBase): string
{
    $fallback = $assetBase . 'assets/images/icons/restaurant.svg';

    if ($rawPath === '') {
        return $fallback;
    }

    $imageBase    = getProductImageBasePath($rawPath);
    $primaryImage = getProductPrimaryFilename($rawPath);

    if ($imageBase === '' || $primaryImage === '') {
        return $fallback;
    }

    $projectRootUrl = preg_replace('#shared/$#', '', $assetBase);

    if (!is_string($projectRootUrl) || $projectRootUrl === '') {
        return $fallback;
    }

    return $projectRootUrl . $imageBase . $primaryImage;
}

function getPaymentMethodMeta(string $method): array
{
    return match ($method) {
        'COD'    => ['icon' => 'coin-line.svg',    'label' => 'Cash on Delivery', 'slug' => 'cod'],
        'Wallet' => ['icon' => 'wallet-fill.svg',  'label' => 'Wallet',           'slug' => 'wallet'],
        'Online' => ['icon' => 'qr-code-line.svg', 'label' => 'Online Payment',   'slug' => 'online'],
        default  => ['icon' => 'coin-line.svg',    'label' => $method,            'slug' => 'other'],
    };
}

function formatCustomizationLine(array $cust): ?string
{
    $name = $cust['ingredient_name'] ?? '';
    if ($name === '') {
        return null;
    }

    $isRemoved = (int)($cust['is_removed'] ?? 0) === 1;
    $qty       = (int)($cust['quantity'] ?? 0);
    $priceMod  = (float)($cust['price_at_time'] ?? 0);

    if ($isRemoved) {
        return '− ' . $name . ' (removed)';
    }

    $line = '+ ' . $name;
    if ($qty > 1) {
        $line .= ' × ' . $qty;
    }
    if ($priceMod > 0) {
        $line .= ' (+' . formatOrderCurrency($priceMod * $qty) . ')';
    } elseif ($priceMod < 0) {
        $line .= ' (−' . formatOrderCurrency(abs($priceMod) * $qty) . ')';
    }

    return $line;
}

function isTerminalStatus(string $status): bool
{
    return in_array($status, ['delivered', 'cancelled', 'refunded', 'failed'], true);
}

function isActiveStatus(string $status): bool
{
    return in_array(
        $status,
        ['pending', 'preparing', 'rider_pending', 'picking_up', 'delivering'],
        true
    );
}

function getTrackingButtonDescriptor(string $status, ?string $deliveredAt): array
{
    $graceOpen = customerOrderHasOpenChatWindow($status, $deliveredAt);

    if ($status === 'delivered' && $graceOpen) {
        return [
            'show'    => true,
            'label'   => 'Message',
            'neutral' => true,
            'kind'    => 'message',
        ];
    }

    if (isTerminalStatus($status)) {
        return [
            'show'    => true,
            'label'   => 'Track History',
            'neutral' => true,
            'kind'    => 'history',
        ];
    }

    return [
        'show'    => true,
        'label'   => 'Track Order',
        'neutral' => true,
        'kind'    => 'track',
    ];
}

$hasOrders = !empty($orders);
$restaurantIconFallback = $assetBase . 'assets/images/icons/restaurant.svg';
?>

<link rel="stylesheet" href="../assets/css/customer-orders.css">

<div class="content orders-page">
    <div class="container">

        <!-- ============================================
             PAGE HEADER
             ============================================ -->
        <div class="page-title-header">
            <p class="heading-2">My <span>Orders</span></p>
            <p class="text-muted">Track your order history and view item details</p>
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

        <?php if (isset($_SESSION['order_error'])): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo htmlspecialchars($_SESSION['order_error'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['order_error']); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['review_success'])): ?>
        <div class="alert alert-success" role="alert">
            <?php echo htmlspecialchars($_SESSION['review_success'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['review_success']); ?>
        </div>
        <?php endif; ?>

        <?php if (!$hasOrders): ?>

        <!-- ============================================
             EMPTY STATE
             ============================================ -->
        <div class="empty-state">
            <div class="empty-state-content">
                <div class="empty-state-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/package.svg" alt="No orders">
                </div>
                <p class="heading-4">No Orders Yet</p>
                <p class="text-muted">
                    You haven't placed any orders yet. Browse our menu and start ordering healthy meals!
                </p>
                <a href="menu.php" class="btn btn-primary">Browse Menu</a>
            </div>
        </div>

        <?php else: ?>

        <!-- ============================================
             FILTER TABS
             ============================================ -->
        <nav class="filter-tabs" id="filterTabs" aria-label="Order filters">
            <button type="button" class="filter-tab active" data-filter="active" aria-current="page">
                Active
            </button>
            <button type="button" class="filter-tab" data-filter="all">
                All
            </button>
            <button type="button" class="filter-tab" data-filter="pending">
                Pending
            </button>
            <button type="button" class="filter-tab" data-filter="preparing">
                Preparing
            </button>
            <button type="button" class="filter-tab" data-filter="delivering">
                For Delivery
            </button>
            <button type="button" class="filter-tab" data-filter="delivered">
                Delivered
            </button>
            <button type="button" class="filter-tab" data-filter="cancelled">
                Cancelled
            </button>
            <button type="button" class="filter-tab" data-filter="refunded">
                Refunded
            </button>
            <button type="button" class="filter-tab" data-filter="failed">
                Failed
            </button>
        </nav>

        <!-- ============================================
             ORDERS LIST
             ============================================ -->
        <div class="orders-list" id="ordersList" data-orders-page="1"
            data-csrf-token="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"
            data-handler-url="../backend/handlers/customer-order-handler.php">
            <?php foreach ($orders as $order):
                $orderId     = (int)$order['order_id'];
                $status      = (string)$order['order_status'];
                $deliveredAt = $order['delivered_at'] ?? null;
                $items       = $order['items'] ?? [];
                $itemCount   = (int)$order['item_count'];
                $totalAmt    = (float)$order['total_amount'];
                $orderDate   = $order['order_date'];

                $trackBtn   = getTrackingButtonDescriptor($status, $deliveredAt);
                $terminal   = isTerminalStatus($status);
                $active     = isActiveStatus($status);
                $graceOpen  = customerOrderHasOpenChatWindow($status, $deliveredAt);

                $canCancel  = ($status === 'pending');
                $canReview  = ($status === 'delivered');
                $canReorder = $terminal;

                $paymentMeta = getPaymentMethodMeta((string)$order['payment_method']);

                $totalsId = 'order-totals-' . $orderId;

                $cardRevision = sha1(implode('|', [
                    $status,
                    (string)($deliveredAt ?? ''),
                    $graceOpen ? '1' : '0',
                ]));
            ?>
            <div class="order-card" data-order-id="<?php echo $orderId; ?>"
                data-status="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>"
                data-active="<?php echo $active ? '1' : '0'; ?>" data-terminal="<?php echo $terminal ? '1' : '0'; ?>"
                data-grace-open="<?php echo $graceOpen ? '1' : '0'; ?>"
                data-revision="<?php echo htmlspecialchars($cardRevision, ENT_QUOTES, 'UTF-8'); ?>">

                <!-- ============================================
                     ORDER CARD HEADER
                     ============================================ -->
                <div class="order-card-header">
                    <div class="order-header-left">
                        <p class="order-id">Order #<?php echo $orderId; ?></p>
                        <p class="order-date"><?php echo formatOrderDateTime($orderDate); ?></p>
                    </div>

                    <div class="order-header-center">
                        <span class="payment-pill payment-pill-<?php echo $paymentMeta['slug']; ?>">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/<?php echo $paymentMeta['icon']; ?>"
                                alt="" aria-hidden="true" class="payment-pill-icon"
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                            <span class="payment-pill-label">
                                <?php echo htmlspecialchars($paymentMeta['label'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </span>
                    </div>

                    <div class="order-header-right">
                        <span class="badge js-order-badge <?php echo getOrderStatusBadgeClass($status); ?>">
                            <?php echo getOrderStatusLabel($status); ?>
                        </span>
                    </div>
                </div>

                <!-- ============================================
                     ORDER CARD BODY — items
                     ============================================ -->
                <div class="order-card-body">
                    <p class="order-items-heading">
                        <?php echo $itemCount; ?> item<?php echo $itemCount !== 1 ? 's' : ''; ?>
                    </p>

                    <div class="order-items-list">
                        <?php foreach ($items as $index => $item):
                            $productId   = (int)$item['product_id'];

                            $itemImage = resolveOrderItemImageUrl(
                                (string)($item['product_image'] ?? ''),
                                $assetBase
                            );

                            $quantity    = (int)$item['quantity'];
                            $unitPrice   = (float)$item['unit_price'];
                            $finalPrice  = (float)($item['final_price'] ?? $item['unit_price']);
                            $lineTotal   = $finalPrice * $quantity;
                            $customs     = $item['customizations'] ?? [];
                            $notes       = $item['custom_instructions'] ?? '';
                            $isReviewed  = (bool)($item['is_reviewed'] ?? false);

                            $hasDetails = !empty($customs) || $notes !== '';
                            $itemKey = 'item-' . $orderId . '-' . $productId . '-' . $index;
                        ?>
                        <div class="order-item-row" data-item-key="<?php echo $itemKey; ?>">

                            <div class="order-item-summary <?php echo $hasDetails ? 'is-expandable' : ''; ?>"
                                <?php if ($hasDetails): ?> role="button" tabindex="0" aria-expanded="false"
                                aria-controls="<?php echo $itemKey; ?>-details" <?php endif; ?>>

                                <div class="order-item-image">
                                    <img src="<?php echo htmlspecialchars($itemImage, ENT_QUOTES, 'UTF-8'); ?>"
                                        alt="<?php echo htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        onerror="this.onerror=null; this.src='<?php echo $restaurantIconFallback; ?>'">
                                </div>

                                <div class="order-item-info">
                                    <p class="order-item-name">
                                        <?php echo htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </p>
                                    <p class="order-item-meta">
                                        <?php echo $quantity; ?> × <?php echo formatOrderCurrency($unitPrice); ?>
                                        <?php if (!empty($customs)): ?>
                                        <span class="customized-badge">Customized</span>
                                        <?php endif; ?>
                                    </p>
                                </div>

                                <div class="order-item-price-col">
                                    <p class="order-item-line-total"><?php echo formatOrderCurrency($lineTotal); ?></p>
                                    <?php if ($hasDetails): ?>
                                    <span class="order-item-expand-icon" aria-hidden="true">
                                        <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-drop-down-line.svg"
                                            alt="" class="order-item-expand-icon-img" width="16" height="16">
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($hasDetails): ?>
                            <div class="order-item-details" id="<?php echo $itemKey; ?>-details" hidden>

                                <?php if (!empty($customs)): ?>
                                <div class="order-item-details-section">
                                    <p class="order-item-details-label">Customizations</p>
                                    <ul class="order-item-customizations">
                                        <?php foreach ($customs as $cust):
                                            $line = formatCustomizationLine($cust);
                                            if ($line === null) continue;
                                            $isRemoved = (int)($cust['is_removed'] ?? 0) === 1;
                                        ?>
                                        <li class="customization-line <?php echo $isRemoved ? 'is-removed' : ''; ?>">
                                            <?php echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8'); ?>
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <?php endif; ?>

                                <?php if ($notes !== ''): ?>
                                <div class="order-item-details-section">
                                    <p class="order-item-details-label">Special Instructions</p>
                                    <p class="order-item-notes">
                                        <?php echo nl2br(htmlspecialchars($notes, ENT_QUOTES, 'UTF-8')); ?>
                                    </p>
                                </div>
                                <?php endif; ?>

                                <div class="order-item-details-section order-item-price-breakdown">
                                    <div class="price-breakdown-row">
                                        <span>Base price</span>
                                        <span><?php echo formatOrderCurrency($unitPrice); ?></span>
                                    </div>
                                    <?php
                                    $customTotal = 0.0;
                                    foreach ($customs as $cust) {
                                        if ((int)($cust['is_removed'] ?? 0) === 1) continue;
                                        $customTotal += (float)($cust['price_at_time'] ?? 0) * (int)($cust['quantity'] ?? 0);
                                    }
                                    if (abs($customTotal) > 0.001):
                                    ?>
                                    <div class="price-breakdown-row">
                                        <span>Customizations</span>
                                        <span><?php echo $customTotal >= 0 ? '+' : '−'; ?><?php echo formatOrderCurrency(abs($customTotal)); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="price-breakdown-row">
                                        <span>Quantity</span>
                                        <span>× <?php echo $quantity; ?></span>
                                    </div>
                                    <div class="price-breakdown-row total">
                                        <span>Line total</span>
                                        <span><?php echo formatOrderCurrency($lineTotal); ?></span>
                                    </div>
                                </div>

                                <?php if ($canReview && $isReviewed): ?>
                                <div class="order-item-details-actions">
                                    <span class="reviewed-badge">✓ Reviewed</span>
                                </div>
                                <?php endif; ?>

                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- ============================================
                     ORDER CARD TOTALS — DROPDOWN
                     ============================================ -->
                <div class="order-card-totals" data-collapsed="true">
                    <button type="button" class="order-totals-toggle" aria-expanded="false"
                        aria-controls="<?php echo $totalsId; ?>">
                        <span class="order-totals-toggle-label">Total</span>
                        <span class="order-totals-toggle-value">
                            <?php echo formatOrderCurrency($totalAmt); ?>
                        </span>
                        <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-drop-down-line.svg" alt=""
                            aria-hidden="true" class="order-totals-toggle-icon" width="20" height="20"
                            onerror="this.onerror=null; this.style.display='none';">
                    </button>

                    <div class="order-totals-detail" id="<?php echo $totalsId; ?>" hidden>
                        <div class="order-total-row">
                            <span>Subtotal</span>
                            <span><?php echo formatOrderCurrency($order['subtotal']); ?></span>
                        </div>
                        <div class="order-total-row">
                            <span>
                                Delivery Fee
                                <?php if (($order['branch_count'] ?? 0) > 1): ?>
                                <small class="order-total-note">(<?php echo (int)$order['branch_count']; ?>
                                    branches)</small>
                                <?php endif; ?>
                            </span>
                            <span><?php echo formatOrderCurrency($order['delivery_fee']); ?></span>
                        </div>
                        <div class="order-total-row">
                            <span>Service Fee</span>
                            <span><?php echo formatOrderCurrency($order['service_fee']); ?></span>
                        </div>
                        <div class="order-total-row">
                            <span>VAT</span>
                            <span><?php echo formatOrderCurrency($order['vat']); ?></span>
                        </div>
                    </div>
                </div>

                <!-- ============================================
                     ORDER CARD FOOTER — ACTIONS ONLY
                     ============================================ -->
                <div class="order-card-footer">
                    <div class="order-footer-actions js-order-actions">
                        <?php if ($canCancel): ?>
                        <button type="button" class="btn btn-danger btn-sm cancel-order-btn"
                            data-order-id="<?php echo $orderId; ?>"
                            data-order-status="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
                            Cancel Order
                        </button>
                        <?php endif; ?>

                        <?php if ($trackBtn['show']): ?>
                        <a href="order-tracking.php?id=<?php echo $orderId; ?>"
                            class="btn btn-neutral btn-sm tracking-btn js-tracking-btn"
                            data-tracking-label="<?php echo htmlspecialchars($trackBtn['label'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-tracking-kind="<?php echo htmlspecialchars($trackBtn['kind'], ENT_QUOTES, 'UTF-8'); ?>">
                            <span
                                class="tracking-btn-label js-tracking-label"><?php echo htmlspecialchars($trackBtn['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </a>
                        <?php endif; ?>

                        <?php if ($canReview): ?>
                        <a href="review.php?order_id=<?php echo $orderId; ?>"
                            class="btn btn-primary btn-sm review-order-btn">
                            Review
                        </a>
                        <?php endif; ?>

                        <?php if ($terminal): ?>
                        <a href="order-receipt.php?id=<?php echo $orderId; ?>" class="btn btn-neutral btn-sm">
                            View Receipt
                        </a>
                        <?php endif; ?>

                        <?php if ($canReorder): ?>
                        <button type="button" class="btn btn-primary btn-sm reorder-btn"
                            data-order-id="<?php echo $orderId; ?>">
                            <span class="reorder-btn-label">Reorder</span>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- No results for the active filter -->
        <div class="no-filter-results" id="noFilterResults" style="display: none;">
            <div class="empty-state">
                <div class="empty-state-content">
                    <div class="empty-state-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/search-line.svg" alt="No results">
                    </div>
                    <p class="heading-4">No orders found</p>
                    <p class="text-muted">There are no orders with this status.</p>
                </div>
            </div>
        </div>

        <?php endif; ?>
    </div>
</div>

<!-- ============================================
     CANCEL ORDER MODAL
     ============================================ -->
<div id="cancelOrderModal" class="modal" style="display: none;">
    <div class="modal-overlay"></div>
    <div class="modal-content">
        <div class="modal-icon">
            <img src="<?php echo $assetBase; ?>assets/images/icons/cancel.svg" alt="Cancel">
        </div>
        <p class="heading-5 modal-title">Cancel Order</p>
        <p class="modal-message" id="cancelModalMessage">
            Are you sure you want to cancel this order? This action cannot be undone.
        </p>
        <div class="modal-actions">
            <button type="button" class="modal-btn modal-btn-cancel" id="cancelModalNo">Keep Order</button>
            <button type="button" class="modal-btn modal-btn-confirm" id="cancelModalYes">Cancel Order</button>
        </div>
    </div>
</div>

<script>
window.FITPAL_ORDERS = {
    csrfToken: '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>',
    assetBase: '<?php echo $assetBase; ?>',
    handlerUrl: '../backend/handlers/customer-order-handler.php'
};
window.HIGHLIGHT_ORDER_ID = <?php echo (int)($_SESSION['highlight_order'] ?? 0); ?>;
<?php unset($_SESSION['highlight_order']); ?>
</script>
<script src="../assets/ui/js/customer-order.js" defer></script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>