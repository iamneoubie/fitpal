<?php
/**
 * FitPal Customer Order Receipt Page
 *
 * Read-only receipt view for a single delivered order. Renders the
 * order header, restaurant, rider, customer, delivery address,
 * per-item breakdown with customizations, fee-derived totals, and a
 * footer note. No mutations, no forms, no JS.
 *
 * ---------------------------------------------------------------------
 * SCOPE RULES APPLIED
 * ---------------------------------------------------------------------
 *  - No SQL in this file. getOrderOwnership(), getOrderDetails(), and
 *    getOrderTotals() come from customer/backend/database/order-queries.php.
 *    getOrderRiderDetails() comes from
 *    customer/backend/database/tracking-queries.php.
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
 * Rider block
 * -----------
 * A "Delivered by" section sits between the restaurant block and the
 * meta grid. It renders:
 *
 *   - an avatar square with the rider icon,
 *   - the rider's display name,
 *   - a contact line rendered as a tel: link so the customer can tap
 *     to call on mobile,
 *   - a small key/value grid for vehicle, plate, rating, and total
 *     deliveries.
 *
 * The block is omitted entirely when getOrderRiderDetails() returns
 * false. That happens when orders.delivery_rider_id is NULL — the
 * schema's ON DELETE SET NULL behavior on the FK to delivery_rider
 * means an old order can lose its rider reference if that rider's row
 * is later deleted. A delivered order in that state is still a valid
 * receipt; it simply has no rider to show.
 *
 * @package FitPal
 * @version 1.2 — Rider block now surfaces the rider's contact number
 *                as a tap-to-call tel: link, and restructures the
 *                rest of the rider's data (vehicle, plate, rating,
 *                deliveries) into a labeled key/value grid so each
 *                value reads on its own line. Adds the receipt-totals
 *                row rule for VAT rate display clarity.
 *
 *                (1.1: rider block added. 1.0: initial receipt.)
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
require_once __DIR__ . '/../backend/database/tracking-queries.php';

$customerId = (int)$_SESSION['customer_id'];

// Ownership check.
$ownership = getOrderOwnership($database_connection, $orderId, $customerId);
if (!$ownership) {
    $_SESSION['order_error'] = 'Order not found.';
    header('Location: orders.php');
    exit;
}

// Receipt is only offered for delivered orders.
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

// Rider. Returns false when no rider is attached to the order.
$rider = getOrderRiderDetails($database_connection, $orderId);

// ---------------------------------------------------------------------
// Order fields
// ---------------------------------------------------------------------
$orderDate      = (string)($order['order_date']          ?? '');
$deliveredAt    = (string)($order['delivered_at']        ?? '');
$paymentMethod  = (string)($order['payment_method']      ?? '');
$destination    = (string)($order['destination_address'] ?? '');
$orderStatus    = (string)($order['order_status']        ?? '');

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

// Branch address is optional.
$branchAddressParts = array_filter([
    $branchBlock,
    $branchBarangay,
    $branchCity,
    $branchProvince,
]);
$branchAddress = implode(', ', $branchAddressParts);

// Payment method label.
$paymentLabel = match ($paymentMethod) {
    'COD'    => 'Cash on Delivery',
    'Wallet' => 'Wallet',
    'Online' => 'Online Payment',
    default  => $paymentMethod !== '' ? $paymentMethod : '—',
};

// ---------------------------------------------------------------------
// Rider normalization
//
// Builds a small struct of display-ready values so the template below
// stays declarative. Every field is coerced to the shape the markup
// expects — a string, a float, or an int — so the template never has
// to null-check.
// ---------------------------------------------------------------------
$hasRider = ($rider !== false && is_array($rider));

$riderName            = '';
$riderContact         = '';
$riderContactLink     = '';
$riderVehicle         = '';
$riderPlate           = '';
$riderVehicleLine     = '';
$riderRating          = 0.0;
$riderDeliveries      = 0;

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
        // tel: links tolerate spaces and hyphens but not arbitrary
        // punctuation. Strip anything that is not a digit or a leading
        // plus so a formatted number like "0917 123 4567" becomes a
        // valid dial string.
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

            <!-- Rider -->
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
                    <p class="receipt-meta-value receipt-meta-value-status">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/verified-fill.svg" alt=""
                            class="receipt-meta-status-icon" width="14" height="14"
                            onerror="this.onerror=null; this.style.display='none';">
                        <span><?php echo htmlspecialchars(ucfirst($orderStatus), ENT_QUOTES, 'UTF-8'); ?></span>
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