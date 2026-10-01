<?php
/**
 * FitPal Customer Order Receipt Page
 *
 * Read-only receipt view for a single terminal order — one that has
 * reached one of: delivered, cancelled, refunded, or failed. Renders
 * the order header, restaurant, rider (when delivered), customer,
 * delivery address, per-item breakdown with customizations, fee-
 * derived totals, and a footer note. No mutations, no forms, no JS.
 *
 * ---------------------------------------------------------------------
 * WHY TERMINAL, NOT DELIVERED-ONLY
 * ---------------------------------------------------------------------
 * The Orders page renders "View Receipt" for every terminal status,
 * not just delivered. A cancelled order still has a record of what
 * was ordered, what it would have cost, and who cancelled it. A
 * refunded order has the same record plus the refund row on the
 * wallet. A failed order has the same record plus the failed-
 * delivery note. All four deserve a receipt.
 *
 * The page therefore gates on isTerminalStatus() rather than on
 * 'delivered'. Anything earlier — pending, preparing, rider_pending,
 * picking_up, delivering — is still in motion and does not yet have
 * a final record to hand the customer. Those are bounced back to
 * orders.php with a clear message.
 *
 * Sections that only make sense for a delivered order are rendered
 * conditionally:
 *
 *     "Delivered At" meta cell      → delivered only
 *     Rider block                    → delivered only
 *     Footer "Thank you" line        → delivered only
 *     Status banner                  → every terminal status
 *     "Cancelled by" line            → cancelled / refunded only
 *
 * Everything else — restaurant, customer, address, items, totals —
 * applies to every terminal order and is rendered unconditionally.
 *
 * ---------------------------------------------------------------------
 * SCOPE RULES APPLIED
 * ---------------------------------------------------------------------
 *  - No SQL in this file. getOrderOwnership(), getOrderDetails(), and
 *    getOrderTotals() come from
 *    customer/backend/database/customer-order-queries.php (which in
 *    turn re-exports the shared order-transaction layer).
 *    getOrderRiderDetails() comes from
 *    customer/backend/database/tracking-queries.php.
 *  - formatCurrency() comes from customer-queries.php.
 *  - No inline CSS. order-receipt.css is loaded via the customer
 *    header's $pageCssMap.
 *  - No inline JS, no inline SVG. Icons come from
 *    shared/assets/images/icons/.
 *  - No window alert. Errors route through $_SESSION['order_error']
 *    and a redirect back to orders.php, matching order-tracking.php.
 * ---------------------------------------------------------------------
 *
 * Access rules:
 *   - Session must have customer_id; otherwise redirect to sign-in.php.
 *   - ?id must be a positive integer; otherwise redirect to orders.php.
 *   - The order must belong to the authenticated customer.
 *   - The order must be in a terminal state.
 *
 * @package FitPal
 * @version 4.0 — Terminal-status receipts.
 *
 *                The gate is now isTerminalStatus() rather than
 *                'delivered'. Cancelled, refunded, and failed
 *                orders render a receipt. Delivered-only sections
 *                (Delivered At meta cell, rider block, thank-you
 *                footer) are conditional.
 *
 *                A status banner renders near the top of the
 *                receipt so the customer sees at a glance how the
 *                order ended. For cancelled and refunded orders a
 *                "Cancelled by" line is added to the meta grid.
 *
 *                No other section changed. Every icon reference,
 *                every item row, every totals row, and the entire
 *                rider block (when rendered) are byte-identical to
 *                v3.0.
 *
 *                (3.0: handler targets verified — the file contains
 *                no form, no fetch, and no handler URL. 2.0:
 *                renamed customer order query layer. 1.2: rider
 *                contact surface as tel: link. 1.1: rider block.
 *                1.0: initial receipt.)
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
    $_SESSION['order_error'] = 'Invalid order.';
    header('Location: orders.php');
    exit;
}

require_once __DIR__ . '/../backend/database/customer-connect.php';
require_once __DIR__ . '/../backend/database/customer-queries.php';
require_once __DIR__ . '/../backend/database/customer-order-queries.php';
require_once __DIR__ . '/../backend/database/tracking-queries.php';

$customerId = (int)$_SESSION['customer_id'];

// Ownership check.
$ownership = getOrderOwnership($database_connection, $orderId, $customerId);
if (!$ownership) {
    $_SESSION['order_error'] = 'Order not found.';
    header('Location: orders.php');
    exit;
}

/**
 * Terminal statuses render a receipt. Live statuses do not.
 *
 * Declared here so the page is self-contained: no page-level helper
 * file needs to be loaded for this page to make its own gate
 * decision.
 */
function receiptIsTerminal(string $status): bool
{
    return in_array($status, ['delivered', 'cancelled', 'refunded', 'failed'], true);
}

if (!receiptIsTerminal($ownership['order_status'])) {
    $_SESSION['order_error'] = 'A receipt is only available for completed, cancelled, refunded, or failed orders.';
    header('Location: orders.php');
    exit;
}

$order = getOrderDetails($database_connection, $orderId);
if (!$order) {
    $_SESSION['order_error'] = 'Order not found.';
    header('Location: orders.php');
    exit;
}

$orderStatus = (string)($order['order_status'] ?? '');
$isDelivered = ($orderStatus === 'delivered');

// The rider block only makes sense for a delivered order: a
// cancelled or failed order has no rider who completed the trip,
// and a refunded order's rider involvement is already captured by
// the "Cancelled by" line.
$rider = $isDelivered
    ? getOrderRiderDetails($database_connection, $orderId)
    : false;

// ---------------------------------------------------------------------
// Order fields
// ---------------------------------------------------------------------
$orderDate      = (string)($order['order_date']          ?? '');
$deliveredAt    = (string)($order['delivered_at']        ?? '');
$paymentMethod  = (string)($order['payment_method']      ?? '');
$destination    = (string)($order['destination_address'] ?? '');
$cancelledBy    = (string)($order['cancelled_by']        ?? '');

$customerFirstName = (string)($order['first_name']     ?? '');
$customerLastName  = (string)($order['last_name']      ?? '');
$customerEmail     = (string)($order['email']          ?? '');
$customerContact   = (string)($order['contact_number'] ?? '');

$branch         = is_array($order['branch'] ?? null) ? $order['branch'] : [];
$restaurantName = (string)($branch['restaurant_name'] ?? '');
$branchName     = (string)($branch['branch_name']     ?? '');
$branchBlock    = (string)($branch['block']           ?? '');
$branchBarangay = (string)($branch['barangay']        ?? '');
$branchCity     = (string)($branch['city']            ?? '');
$branchProvince = (string)($branch['province']        ?? '');

$items = is_array($order['items'] ?? null) ? $order['items'] : [];

$subtotal     = (float)($order['subtotal']     ?? 0);
$deliveryFee  = (float)($order['delivery_fee'] ?? 0);
$serviceFee   = (float)($order['service_fee']  ?? 0);
$vat          = (float)($order['vat']          ?? 0);
$grandTotal   = (float)($order['total_amount'] ?? 0);

$customerName = trim($customerFirstName . ' ' . $customerLastName);
if ($customerName === '') {
    $customerName = 'Customer';
}

$branchAddressParts = array_filter([
    $branchBlock,
    $branchBarangay,
    $branchCity,
    $branchProvince,
]);
$branchAddress = implode(', ', $branchAddressParts);

$paymentLabel = match ($paymentMethod) {
    'COD'    => 'Cash on Delivery',
    'Wallet' => 'Wallet',
    'Online' => 'Online Payment',
    default  => $paymentMethod !== '' ? $paymentMethod : '—',
};

// ---------------------------------------------------------------------
// Status presentation
//
// Every terminal status gets a label, a short description, and a
// CSS modifier that tints the banner. The modifier values are
// derived from the status name and are read by
// order-receipt.css.
// ---------------------------------------------------------------------
$statusLabel = match ($orderStatus) {
    'delivered' => 'Delivered',
    'cancelled' => 'Cancelled',
    'refunded'  => 'Refunded',
    'failed'    => 'Failed',
    default     => ucfirst($orderStatus),
};

$statusDescription = match ($orderStatus) {
    'delivered' => 'This order was delivered successfully.',
    'cancelled' => 'This order was cancelled before it was completed.',
    'refunded'  => 'This order was cancelled and refunded to your wallet.',
    'failed'    => 'This order could not be completed and was closed.',
    default     => '',
};

$statusIconFile = match ($orderStatus) {
    'delivered' => 'verified-fill.svg',
    'cancelled' => 'close-circle-fill.svg',
    'refunded'  => 'coin-fill.svg',
    'failed'    => 'error-warning-fill.svg',
    default     => 'information-fill.svg',
};

// Human-readable "cancelled by" line, used only for cancelled and
// refunded orders where the schema has set orders.cancelled_by.
$cancelledByLabel = '';
if (in_array($orderStatus, ['cancelled', 'refunded'], true) && $cancelledBy !== '') {
    $cancelledByLabel = match ($cancelledBy) {
        'customer'   => 'Customer',
        'restaurant' => 'Restaurant',
        'rider'      => 'Rider',
        'admin'      => 'Admin',
        default      => ucfirst($cancelledBy),
    };
}

// ---------------------------------------------------------------------
// Rider normalization
//
// Only runs when $rider is a real row. For every other terminal
// status the block is skipped entirely; the "Delivered by" section
// never renders.
// ---------------------------------------------------------------------
$hasRider = ($isDelivered && $rider !== false && is_array($rider));

$riderName        = '';
$riderContact     = '';
$riderContactLink = '';
$riderVehicle     = '';
$riderPlate       = '';
$riderVehicleLine = '';
$riderRating      = 0.0;
$riderDeliveries  = 0;

if ($hasRider) {
    $riderNameParts = array_filter([
        (string)($rider['first_name']  ?? ''),
        (string)($rider['middle_name'] ?? ''),
        (string)($rider['last_name']   ?? ''),
    ]);
    $riderName = trim(implode(' ', $riderNameParts));

    $rawContact   = (string)($rider['contact_number'] ?? '');
    $riderContact = trim($rawContact);

    if ($riderContact !== '') {
        $riderContactLink = preg_replace('/[^0-9+]/', '', $riderContact) ?? '';
    }

    $riderVehicle = ucfirst((string)($rider['vehicle_type']  ?? ''));
    $riderPlate   = strtoupper((string)($rider['vehicle_plate'] ?? ''));

    $vehicleParts = array_filter([$riderVehicle, $riderPlate]);
    $riderVehicleLine = implode(' • ', $vehicleParts);

    $riderRating     = (float)($rider['average_rating']   ?? 0);
    $riderDeliveries = (int)  ($rider['total_deliveries'] ?? 0);

    if ($riderName === '') {
        $riderName = 'Rider';
    }
}

/**
 * Format a date string for display on the receipt.
 */
function receiptDate(string $date): string
{
    if ($date === '') {
        return '—';
    }
    $ts = strtotime($date);
    return $ts !== false ? date('M d, Y • g:i A', $ts) : $date;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="content order-receipt-page">
    <div class="container">

        <div class="page-title-header">
            <div class="page-title-header-top">
                <a href="orders.php" class="back-btn">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-left-line.svg" alt="Back"
                        class="back-btn-icon" width="20" height="20">
                    <span>Back to Orders</span>
                </a>
                <h1>Order Receipt</h1>
            </div>
        </div>

        <article class="receipt-card" aria-label="Order receipt">

            <header class="receipt-header">
                <div class="receipt-header-left">
                    <img src="<?php echo $assetBase; ?>assets/images/brand/Logo.png" alt="FitPal" class="receipt-logo"
                        onerror="this.onerror=null; this.style.display='none';">
                    <div class="receipt-brand">
                        <p class="receipt-brand-name">Fit<span>Pal</span></p>
                        <p class="receipt-brand-sub">Order Receipt</p>
                    </div>
                </div>
                <div class="receipt-header-right">
                    <p class="receipt-order-id">Order #<?php echo (int)$order['order_id']; ?></p>
                    <p class="receipt-order-date">
                        <?php echo htmlspecialchars(receiptDate($orderDate), ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                </div>
            </header>

            <!-- ============================================
                 STATUS BANNER
                 Renders for every terminal status so the
                 customer sees at a glance how the order
                 ended before scrolling into the detail.
                 ============================================ -->
            <section
                class="receipt-status-banner receipt-status-<?php echo htmlspecialchars($orderStatus, ENT_QUOTES, 'UTF-8'); ?>"
                aria-label="Order status">

                <div class="receipt-status-icon" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/<?php echo htmlspecialchars($statusIconFile, ENT_QUOTES, 'UTF-8'); ?>"
                        alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/information-fill.svg'">
                </div>

                <div class="receipt-status-body">
                    <p class="receipt-status-label">
                        <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <p class="receipt-status-description">
                        <?php echo htmlspecialchars($statusDescription, ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                </div>
            </section>

            <?php if ($restaurantName !== '' || $branchName !== ''): ?>
            <section class="receipt-restaurant" aria-label="Restaurant">
                <?php if ($restaurantName !== ''): ?>
                <p class="receipt-restaurant-name">
                    <?php echo htmlspecialchars($restaurantName, ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <?php endif; ?>

                <?php if ($branchName !== '' || $branchAddress !== ''): ?>
                <p class="receipt-restaurant-branch">
                    <?php
                    $branchLine = $branchName;
                    if ($branchAddress !== '') {
                        $branchLine .= ($branchLine !== '' ? ' • ' : '') . $branchAddress;
                    }
                    echo htmlspecialchars($branchLine, ENT_QUOTES, 'UTF-8');
                    ?>
                </p>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <!-- ============================================
                 RIDER BLOCK
                 Delivered orders only. Every other terminal
                 status skips this section entirely.
                 ============================================ -->
            <?php if ($hasRider): ?>
            <section class="receipt-rider" aria-label="Delivery rider">
                <div class="receipt-rider-top">
                    <div class="receipt-rider-avatar" aria-hidden="true">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/riding-fill.svg" alt=""
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/user-profile-circle.svg'">
                    </div>
                    <div class="receipt-rider-headline">
                        <p class="receipt-rider-title">Delivered by</p>
                        <p class="receipt-rider-name">
                            <?php echo htmlspecialchars($riderName, ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                    </div>
                </div>

                <dl class="receipt-rider-grid">
                    <?php if ($riderContact !== ''): ?>
                    <div class="receipt-rider-grid-item">
                        <dt class="receipt-rider-grid-label">Contact</dt>
                        <dd class="receipt-rider-grid-value">
                            <?php if ($riderContactLink !== ''): ?>
                            <a href="tel:<?php echo htmlspecialchars($riderContactLink, ENT_QUOTES, 'UTF-8'); ?>"
                                class="receipt-rider-contact">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/phone-fill.svg" alt=""
                                    class="receipt-rider-contact-icon" width="14" height="14"
                                    onerror="this.onerror=null; this.style.display='none';">
                                <span><?php echo htmlspecialchars($riderContact, ENT_QUOTES, 'UTF-8'); ?></span>
                            </a>
                            <?php else: ?>
                            <span><?php echo htmlspecialchars($riderContact, ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <?php endif; ?>

                    <?php if ($riderVehicleLine !== ''): ?>
                    <div class="receipt-rider-grid-item">
                        <dt class="receipt-rider-grid-label">Vehicle</dt>
                        <dd class="receipt-rider-grid-value">
                            <?php echo htmlspecialchars($riderVehicleLine, ENT_QUOTES, 'UTF-8'); ?>
                        </dd>
                    </div>
                    <?php endif; ?>

                    <?php if ($riderRating > 0): ?>
                    <div class="receipt-rider-grid-item">
                        <dt class="receipt-rider-grid-label">Rating</dt>
                        <dd class="receipt-rider-grid-value">
                            <span class="receipt-rider-rating">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/star-fill.svg" alt=""
                                    class="receipt-rider-rating-icon" width="14" height="14"
                                    onerror="this.onerror=null; this.style.display='none';">
                                <span><?php echo htmlspecialchars(number_format($riderRating, 1), ENT_QUOTES, 'UTF-8'); ?></span>
                            </span>
                        </dd>
                    </div>
                    <?php endif; ?>

                    <?php if ($riderDeliveries > 0): ?>
                    <div class="receipt-rider-grid-item">
                        <dt class="receipt-rider-grid-label">Deliveries</dt>
                        <dd class="receipt-rider-grid-value">
                            <?php echo (int)$riderDeliveries; ?>
                            <span class="receipt-rider-grid-value-note">
                                completed
                            </span>
                        </dd>
                    </div>
                    <?php endif; ?>
                </dl>
            </section>
            <?php endif; ?>

            <section class="receipt-meta-grid" aria-label="Order details">
                <div class="receipt-meta-block">
                    <p class="receipt-meta-label">Order Date</p>
                    <p class="receipt-meta-value">
                        <?php echo htmlspecialchars(receiptDate($orderDate), ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                </div>

                <?php if ($isDelivered): ?>
                <div class="receipt-meta-block">
                    <p class="receipt-meta-label">Delivered At</p>
                    <p class="receipt-meta-value">
                        <?php echo htmlspecialchars(receiptDate($deliveredAt), ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                </div>
                <?php endif; ?>

                <div class="receipt-meta-block">
                    <p class="receipt-meta-label">Payment Method</p>
                    <p class="receipt-meta-value">
                        <?php echo htmlspecialchars($paymentLabel, ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                </div>

                <div class="receipt-meta-block">
                    <p class="receipt-meta-label">Status</p>
                    <p
                        class="receipt-meta-value receipt-meta-value-status receipt-meta-value-status-<?php echo htmlspecialchars($orderStatus, ENT_QUOTES, 'UTF-8'); ?>">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/<?php echo htmlspecialchars($statusIconFile, ENT_QUOTES, 'UTF-8'); ?>"
                            alt="" class="receipt-meta-status-icon" width="14" height="14"
                            onerror="this.onerror=null; this.style.display='none';">
                        <span><?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                    </p>
                </div>

                <?php if ($cancelledByLabel !== ''): ?>
                <div class="receipt-meta-block">
                    <p class="receipt-meta-label">Cancelled By</p>
                    <p class="receipt-meta-value">
                        <?php echo htmlspecialchars($cancelledByLabel, ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                </div>
                <?php endif; ?>
            </section>

            <section class="receipt-block" aria-label="Customer">
                <p class="receipt-block-title">Customer</p>
                <p class="receipt-block-line">
                    <?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <?php if ($customerEmail !== ''): ?>
                <p class="receipt-block-line muted">
                    <?php echo htmlspecialchars($customerEmail, ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <?php endif; ?>
                <?php if ($customerContact !== ''): ?>
                <p class="receipt-block-line muted">
                    <?php echo htmlspecialchars($customerContact, ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <?php endif; ?>
            </section>

            <?php if ($destination !== ''): ?>
            <section class="receipt-block" aria-label="Delivery address">
                <p class="receipt-block-title">Delivered To</p>
                <p class="receipt-block-line">
                    <?php echo htmlspecialchars($destination, ENT_QUOTES, 'UTF-8'); ?>
                </p>
            </section>
            <?php endif; ?>

            <section class="receipt-items-section" aria-label="Order items">
                <p class="receipt-items-title">Items</p>

                <?php if (empty($items)): ?>
                <p class="receipt-block-line muted">No items recorded for this order.</p>
                <?php else: ?>
                <table class="receipt-items-table">
                    <thead>
                        <tr>
                            <th scope="col">Item</th>
                            <th scope="col" class="num receipt-col-qty">Qty</th>
                            <th scope="col" class="num receipt-col-unit">Unit</th>
                            <th scope="col" class="num receipt-col-line">Line Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item):
                            $productName = (string)($item['product_name'] ?? 'Product');
                            $quantity    = (int)($item['quantity']       ?? 0);
                            $unitPrice   = (float)($item['unit_price']   ?? 0);
                            $finalPrice  = isset($item['final_price']) && $item['final_price'] !== null
                                ? (float)$item['final_price']
                                : $unitPrice;
                            $lineTotal   = $finalPrice * $quantity;

                            $customs = is_array($item['customizations'] ?? null)
                                ? $item['customizations']
                                : [];
                        ?>
                        <tr>
                            <td class="receipt-col-name">
                                <span class="receipt-item-name">
                                    <?php echo htmlspecialchars($productName, ENT_QUOTES, 'UTF-8'); ?>
                                </span>

                                <?php if (!empty($customs)): ?>
                                <ul class="receipt-item-customs">
                                    <?php foreach ($customs as $cust):
                                        if (!is_array($cust)) continue;

                                        $ingredientName = (string)($cust['ingredient_name'] ?? '');
                                        if ($ingredientName === '') continue;

                                        $isRemoved = (int)($cust['is_removed'] ?? 0) === 1;
                                        $custQty   = (int)($cust['quantity'] ?? 0);
                                        $custPrice = (float)($cust['price_at_time'] ?? 0);

                                        $line = $ingredientName;
                                        if ($isRemoved) {
                                            $line .= ' (removed)';
                                        } else {
                                            if ($custQty > 1) {
                                                $line .= ' × ' . $custQty;
                                            }
                                            if ($custPrice > 0) {
                                                $line .= ' (+' . formatCurrency($custPrice * max(1, $custQty)) . ')';
                                            } elseif ($custPrice < 0) {
                                                $line .= ' (−' . formatCurrency(abs($custPrice) * max(1, $custQty)) . ')';
                                            }
                                        }
                                    ?>
                                    <li class="<?php echo $isRemoved ? 'removed' : ''; ?>">
                                        <?php echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8'); ?>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php endif; ?>
                            </td>

                            <td class="num receipt-col-qty"><?php echo $quantity; ?></td>
                            <td class="num receipt-col-unit"><?php echo formatCurrency($unitPrice); ?></td>
                            <td class="num receipt-col-line"><?php echo formatCurrency($lineTotal); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </section>

            <section class="receipt-totals" aria-label="Order totals">
                <div class="receipt-totals-inner">
                    <div class="receipt-total-row">
                        <span class="label">Subtotal</span>
                        <span class="value"><?php echo formatCurrency($subtotal); ?></span>
                    </div>

                    <div class="receipt-total-row">
                        <span class="label">Delivery Fee</span>
                        <span class="value"><?php echo formatCurrency($deliveryFee); ?></span>
                    </div>

                    <div class="receipt-total-row">
                        <span class="label">Service Fee</span>
                        <span class="value"><?php echo formatCurrency($serviceFee); ?></span>
                    </div>

                    <div class="receipt-total-row">
                        <span class="label">VAT</span>
                        <span class="value"><?php echo formatCurrency($vat); ?></span>
                    </div>

                    <div class="receipt-total-row grand">
                        <span class="label">Total</span>
                        <span class="value"><?php echo formatCurrency($grandTotal); ?></span>
                    </div>
                </div>
            </section>

            <footer class="receipt-footer-note">
                <?php if ($isDelivered): ?>
                <p class="thanks">Thank you for ordering with FitPal.</p>
                <p>This receipt is a record of your completed order.</p>
                <?php else: ?>
                <p class="thanks">Order record.</p>
                <p>This receipt is a record of an order that <?php
                    echo htmlspecialchars(
                        $orderStatus === 'cancelled' ? 'was cancelled' :
                        ($orderStatus === 'refunded' ? 'was cancelled and refunded' :
                        ($orderStatus === 'failed' ? 'could not be completed' : 'is closed')),
                        ENT_QUOTES, 'UTF-8'
                    );
                ?>.</p>
                <?php endif; ?>
            </footer>

        </article>

    </div>
</div>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>