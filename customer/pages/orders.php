<?php
/**
 * FitPal Customer Orders Page
 *
 * Displays customer order history with per-product detail expansion
 * and one-click reorder.
 *
 * Totals are computed from queue_item + the fee schedule — never read
 * from the orders table.
 *
 * @package FitPal
 * @version 3.3 — CSRF token now inherited from header.php; local
 *                generation removed. (3.2: expand icon uses shared
 *                arrow-drop-down icon, no inline SVG.)
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    header('Location: sign-in.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../backend/database/order-queries.php';

$customerId = (int)$_SESSION['customer_id'];

// ============================================
// FETCH ORDERS
// ============================================
$orders = [];
$statusCounts = [
    'all'        => 0,
    'pending'    => 0,
    'preparing'  => 0,
    'delivering' => 0,
    'delivered'  => 0,
    'cancelled'  => 0,
    'refunded'   => 0,
];

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

        // Computed totals — orders no longer store these columns.
        $totals = getOrderTotals($database_connection, $orderId);
        $order['subtotal']     = $totals ? $totals['subtotal']     : 0.0;
        $order['delivery_fee'] = $totals ? $totals['delivery_fee'] : 0.0;
        $order['service_fee']  = $totals ? $totals['service_fee']  : 0.0;
        $order['vat']          = $totals ? $totals['vat']          : 0.0;
        $order['total_amount'] = $totals ? $totals['total']        : 0.0;
        $order['branch_count'] = $totals ? $totals['branch_count'] : 0;

        // Per-product details with full customization info.
        $order['items'] = getOrderItemsWithCustomizations($database_connection, $orderId);
        $order['item_count'] = count($order['items']);

        $status = $order['order_status'];
        $statusCounts['all']++;
        if (isset($statusCounts[$status])) {
            $statusCounts[$status]++;
        }

        $orders[] = $order;
    }
} catch (PDOException $e) {
    error_log('Orders page error: ' . $e->getMessage());
}

// ============================================
// CSRF
// ============================================
// Provided by header.php (via includes/csrf_token.php), stored under
// the customer role's own session key 'customer_csrf_token'. The
// header is required near the top of this file, so $csrfToken is
// already populated here.

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
        'pending'    => 'badge-warning',
        'preparing'  => 'badge-info',
        'delivering' => 'badge-primary',
        'delivered'  => 'badge-success',
        'cancelled'  => 'badge-danger',
        'refunded'   => 'badge-secondary',
        default      => 'badge-secondary',
    };
}

function getOrderStatusLabel(string $status): string
{
    return match ($status) {
        'pending'    => 'Pending',
        'preparing'  => 'Preparing',
        'delivering' => 'For Delivery',
        'delivered'  => 'Delivered',
        'cancelled'  => 'Cancelled',
        'refunded'   => 'Refunded',
        default      => ucfirst($status),
    };
}

function formatOrderDateTime(string $date): string
{
    $timestamp = strtotime($date);
    return $timestamp !== false ? date('M d, Y g:i A', $timestamp) : $date;
}

function getOrderItemImage(string $imagePath, string $assetBase): string
{
    if (empty($imagePath)) {
        return $assetBase . 'assets/images/icons/restaurant.svg';
    }
    return htmlspecialchars($imagePath, ENT_QUOTES, 'UTF-8');
}

/**
 * Payment method → icon + display label.
 *
 * @return array{icon:string, label:string, slug:string}
 */
function getPaymentMethodMeta(string $method): array
{
    return match ($method) {
        'COD'    => ['icon' => 'coin-line.svg',    'label' => 'Cash on Delivery', 'slug' => 'cod'],
        'Wallet' => ['icon' => 'wallet-fill.svg',  'label' => 'Wallet',           'slug' => 'wallet'],
        'Online' => ['icon' => 'qr-code-line.svg', 'label' => 'Online Payment',   'slug' => 'online'],
        default  => ['icon' => 'coin-line.svg',    'label' => $method,            'slug' => 'other'],
    };
}

/**
 * Format a customization line for display.
 * Returns null if the customization should be skipped.
 */
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

$hasOrders = !empty($orders);
?>

<link rel="stylesheet" href="../assets/css/orders.css">

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
        <div class="filter-tabs" id="filterTabs">
            <button type="button" class="filter-tab active" data-filter="all">
                All <span class="filter-count"><?php echo $statusCounts['all']; ?></span>
            </button>
            <button type="button" class="filter-tab" data-filter="pending">
                Pending <span class="filter-count"><?php echo $statusCounts['pending']; ?></span>
            </button>
            <button type="button" class="filter-tab" data-filter="preparing">
                Preparing <span class="filter-count"><?php echo $statusCounts['preparing']; ?></span>
            </button>
            <button type="button" class="filter-tab" data-filter="delivering">
                For Delivery <span class="filter-count"><?php echo $statusCounts['delivering']; ?></span>
            </button>
            <button type="button" class="filter-tab" data-filter="delivered">
                Delivered <span class="filter-count"><?php echo $statusCounts['delivered']; ?></span>
            </button>
            <button type="button" class="filter-tab" data-filter="cancelled">
                Cancelled <span class="filter-count"><?php echo $statusCounts['cancelled']; ?></span>
            </button>
            <button type="button" class="filter-tab" data-filter="refunded">
                Refunded <span class="filter-count"><?php echo $statusCounts['refunded']; ?></span>
            </button>
        </div>

        <!-- ============================================
             ORDERS LIST
             ============================================ -->
        <div class="orders-list" id="ordersList">
            <?php foreach ($orders as $order):
                $orderId    = (int)$order['order_id'];
                $status     = $order['order_status'];
                $items      = $order['items'] ?? [];
                $itemCount  = (int)$order['item_count'];
                $totalAmt   = (float)$order['total_amount'];
                $orderDate  = $order['order_date'];

                $canCancel  = in_array($status, ['pending', 'preparing'], true);
                $canTrack   = ($status === 'delivering');
                $canReview  = ($status === 'delivered');
                $canReorder = in_array($status, ['delivered', 'cancelled', 'refunded'], true);

                $paymentMeta = getPaymentMethodMeta((string)$order['payment_method']);
            ?>
            <div class="order-card" data-order-id="<?php echo $orderId; ?>"
                data-status="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">

                <!-- ========== Order Header ========== -->
                <div class="order-card-header">
                    <div class="order-header-left">
                        <p class="order-id">Order #<?php echo $orderId; ?></p>
                        <p class="order-date"><?php echo formatOrderDateTime($orderDate); ?></p>
                    </div>
                    <div class="order-header-right">
                        <span class="badge <?php echo getOrderStatusBadgeClass($status); ?>">
                            <?php echo getOrderStatusLabel($status); ?>
                        </span>
                    </div>
                </div>

                <!-- ========== Order Items (expandable) ========== -->
                <div class="order-card-body">
                    <p class="order-items-heading">
                        <?php echo $itemCount; ?> item<?php echo $itemCount !== 1 ? 's' : ''; ?>
                    </p>

                    <div class="order-items-list">
                        <?php foreach ($items as $index => $item):
                            $productId   = (int)$item['product_id'];
                            $itemImage   = getOrderItemImage($item['product_image'] ?? '', $assetBase);
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

                            <!-- Item summary -->
                            <div class="order-item-summary <?php echo $hasDetails ? 'is-expandable' : ''; ?>"
                                <?php if ($hasDetails): ?> role="button" tabindex="0" aria-expanded="false"
                                aria-controls="<?php echo $itemKey; ?>-details" <?php endif; ?>>

                                <div class="order-item-image">
                                    <img src="<?php echo $itemImage; ?>"
                                        alt="<?php echo htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/restaurant.svg'">
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

                            <!-- Item details -->
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

                                <!-- Price breakdown -->
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

                                <!-- Per-item review -->
                                <?php if ($canReview && !$isReviewed): ?>
                                <div class="order-item-details-actions">
                                    <button type="button" class="btn btn-outline btn-sm review-item-btn"
                                        data-order-id="<?php echo $orderId; ?>"
                                        data-product-id="<?php echo $productId; ?>"
                                        data-product-name="<?php echo htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                        Write Review
                                    </button>
                                </div>
                                <?php elseif ($canReview && $isReviewed): ?>
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

                <!-- ========== Order Totals ========== -->
                <div class="order-card-totals">
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
                    <div class="order-total-row grand-total">
                        <span>Total</span>
                        <span><?php echo formatOrderCurrency($totalAmt); ?></span>
                    </div>
                </div>

                <!-- ========== Footer: Payment pill + actions ========== -->
                <div class="order-card-footer">
                    <span class="payment-pill payment-pill-<?php echo $paymentMeta['slug']; ?>">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/<?php echo $paymentMeta['icon']; ?>"
                            alt="" aria-hidden="true" class="payment-pill-icon"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                        <span class="payment-pill-label">
                            <?php echo htmlspecialchars($paymentMeta['label'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </span>

                    <div class="order-footer-actions">
                        <?php if ($canCancel): ?>
                        <button type="button" class="btn btn-outline btn-sm cancel-order-btn"
                            data-order-id="<?php echo $orderId; ?>"
                            data-order-status="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
                            Cancel Order
                        </button>
                        <?php endif; ?>

                        <?php if ($canTrack): ?>
                        <a href="order-tracking.php?id=<?php echo $orderId; ?>" class="btn btn-primary btn-sm">
                            Track Order
                        </a>
                        <?php endif; ?>

                        <?php if ($status === 'delivered'): ?>
                        <a href="order-receipt.php?id=<?php echo $orderId; ?>" class="btn btn-outline btn-sm">
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

<!-- ============================================
     REVIEW MODAL
     ============================================ -->
<div id="reviewModal" class="modal" style="display: none;">
    <div class="modal-overlay"></div>
    <div class="modal-content">
        <div class="modal-header">
            <div>
                <p class="heading-5 modal-title">Write a Review</p>
                <p class="modal-subtitle" id="reviewProductName"></p>
            </div>
            <button type="button" class="modal-close" id="closeReviewModal">&times;</button>
        </div>

        <form id="reviewForm" method="POST" action="../backend/handlers/feedback-handler.php">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            <input type="hidden" name="action" value="submit_review">
            <input type="hidden" name="order_id" id="reviewOrderId" value="">
            <input type="hidden" name="product_id" id="reviewProductId" value="">

            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Rating</label>
                    <div class="star-rating" id="starRatingContainer">
                        <button type="button" class="star" data-value="1" aria-label="1 star">★</button>
                        <button type="button" class="star" data-value="2" aria-label="2 stars">★</button>
                        <button type="button" class="star" data-value="3" aria-label="3 stars">★</button>
                        <button type="button" class="star" data-value="4" aria-label="4 stars">★</button>
                        <button type="button" class="star" data-value="5" aria-label="5 stars">★</button>
                    </div>
                    <input type="hidden" name="rating" id="ratingValue" required>
                    <div class="rating-error" id="ratingError"></div>
                </div>

                <div class="form-group">
                    <label for="comment" class="form-label">Review (Optional)</label>
                    <textarea id="comment" name="comment" class="form-textarea" rows="4"
                        placeholder="Share your experience with this product..."></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="cancelReviewBtn">Cancel</button>
                <button type="submit" class="btn btn-primary" id="submitReviewBtn">Submit Review</button>
            </div>
        </form>
    </div>
</div>

<script>
window.FITPAL_ORDERS = {
    csrfToken: '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>',
    assetBase: '<?php echo $assetBase; ?>'
};
</script>
<script src="../assets/ui/js/orders.js" defer></script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>