<?php
/**
 * FitPal Customer Order Receipt Page
 *
 * Read-only receipt view for a single delivered order. Renders the
 * order header, restaurant, customer, delivery address, per-item
 * breakdown with customizations, fee-derived totals, and a footer
 * note. No mutations, no forms, no JS.
 *
 * ---------------------------------------------------------------------
 * SCOPE RULES APPLIED
 * ---------------------------------------------------------------------
 *  - No SQL in this file. getOrderOwnership(), getOrderDetails(), and
 *    getOrderTotals() come from customer/backend/database/order-queries.php.
 *  - formatCurrency() comes from customer-queries.php. It is NOT declared
 *    here.
 *  - No inline CSS. order-receipt.css is loaded via the customer header's
 *    $pageCssMap.
 *  - No inline JS, no inline SVG. Icons come from
 *    shared/assets/images/icons/.
 *  - No window alert. Errors route through $_SESSION['order_error'] and
 *    a redirect back to orders.php, matching order-tracking.php.
 * ---------------------------------------------------------------------
 *
 * Access rules:
 *   - Session must have customer_id; otherwise redirect to sign-in.php.
 *   - ?id must be a positive integer; otherwise redirect to orders.php.
 *   - The order must belong to the authenticated customer.
 *   - The order must be in the 'delivered' state. Anything else bounces
 *     back to orders.php with a flash, matching the same constraint the
 *     "View Receipt" button on orders.php enforces.
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
require_once __DIR__ . '/../backend/database/order-queries.php';

$customerId = (int)$_SESSION['customer_id'];

// Ownership check. getOrderOwnership() returns false if the order does
// not belong to this customer, which is the only signal we need here.
$ownership = getOrderOwnership($database_connection, $orderId, $customerId);
if (!$ownership) {
    $_SESSION['order_error'] = 'Order not found.';
    header('Location: orders.php');
    exit;
}

// Receipt is only offered for delivered orders. The View Receipt link
// on orders.php is only rendered for delivered orders, so any other
// status reaching this page is treated as a stale or forged request.
if ($ownership['order_status'] !== 'delivered') {
    $_SESSION['order_error'] = 'A receipt is only available for delivered orders.';
    header('Location: orders.php');
    exit;
}

$order = getOrderDetails($database_connection, $orderId);
if (!$order) {
    $_SESSION['order_error'] = 'Order not found.';
    header('Location: orders.php');
    exit;
}

// getOrderDetails() already attaches subtotal, delivery_charge,
// total_amount, service_fee, and vat. Pull them out for readability.
$orderDate      = (string)($order['order_date']      ?? '');
$deliveredAt    = (string)($order['delivered_at']    ?? '');
$paymentMethod  = (string)($order['payment_method']  ?? '');
$destination    = (string)($order['destination_address'] ?? '');
$orderStatus    = (string)($order['order_status']    ?? '');

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

// Branch address is optional — build it defensively.
$branchAddressParts = array_filter([
    $branchBlock,
    $branchBarangay,
    $branchCity,
    $branchProvince,
]);
$branchAddress = implode(', ', $branchAddressParts);

// Payment method label mapping. Falls back to the raw value.
$paymentLabel = match ($paymentMethod) {
    'COD'    => 'Cash on Delivery',
    'Wallet' => 'Wallet',
    'Online' => 'Online Payment',
    default  => $paymentMethod !== '' ? $paymentMethod : '—',
};

/**
 * Format a date string for display on the receipt. Returns '—' when the
 * input is empty or unparsable.
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
                <h1>Order Receipt</h1>
            </div>
        </div>

        <!-- ============================================
             RECEIPT CARD
             ============================================ -->
        <article class="receipt-card" aria-label="Order receipt">

            <!-- Receipt header -->
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

            <!-- Restaurant -->
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

            <!-- Meta grid -->
            <section class="receipt-meta-grid" aria-label="Order details">
                <div class="receipt-meta-block">
                    <p class="receipt-meta-label">Order Date</p>
                    <p class="receipt-meta-value">
                        <?php echo htmlspecialchars(receiptDate($orderDate), ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                </div>

                <div class="receipt-meta-block">
                    <p class="receipt-meta-label">Delivered At</p>
                    <p class="receipt-meta-value">
                        <?php echo htmlspecialchars(receiptDate($deliveredAt), ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                </div>

                <div class="receipt-meta-block">
                    <p class="receipt-meta-label">Payment Method</p>
                    <p class="receipt-meta-value">
                        <?php echo htmlspecialchars($paymentLabel, ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                </div>

                <div class="receipt-meta-block">
                    <p class="receipt-meta-label">Status</p>
                    <p class="receipt-meta-value">
                        <?php echo htmlspecialchars(ucfirst($orderStatus), ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                </div>
            </section>

            <!-- Customer -->
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

            <!-- Delivery address -->
            <?php if ($destination !== ''): ?>
            <section class="receipt-block" aria-label="Delivery address">
                <p class="receipt-block-title">Delivered To</p>
                <p class="receipt-block-line">
                    <?php echo htmlspecialchars($destination, ENT_QUOTES, 'UTF-8'); ?>
                </p>
            </section>
            <?php endif; ?>

            <!-- Items -->
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

            <!-- Totals -->
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

            <!-- Footer note -->
            <footer class="receipt-footer-note">
                <p class="thanks">Thank you for ordering with FitPal.</p>
                <p>This receipt is a record of your completed order.</p>
            </footer>

        </article>

    </div>
</div>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>