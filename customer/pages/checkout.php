<?php
/**
 * FitPal Customer Checkout Page
 * Version 3.6 - Fixed 2x2 layout with proper row-based grouping
 *
 * @package FitPal
 * @version 3.6
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ===== ALL REDIRECTS MUST HAPPEN BEFORE INCLUDING HEADER =====

// Redirect if not logged in
if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    header('Location: sign-in.php');
    exit;
}

require_once __DIR__ . '/../backend/database/customer-connect.php';
require_once __DIR__ . '/../backend/database/cart-queries.php';
require_once __DIR__ . '/../backend/database/customer-queries.php';

$customerId = (int)$_SESSION['customer_id'];

// Fetch cart items
$cartItems = getCustomerCart($database_connection, $customerId);

if (empty($cartItems)) {
    $_SESSION['checkout_error'] = 'Your cart is empty. Please add items before checkout.';
    header('Location: menu.php');
    exit;
}

// Calculate totals
$subtotal = 0;
$branchId = null;
$branchName = '';
$restaurantName = '';
$branchConsistent = true;

foreach ($cartItems as $item) {
    $subtotal += (float)$item['price'] * (int)$item['quantity'];
    if ($branchId === null) {
        $branchId = (int)$item['restaurant_branch_id'];
        $branchName = $item['branch_name'];
        $restaurantName = $item['restaurant_name'];
    } elseif ($branchId !== (int)$item['restaurant_branch_id']) {
        $branchConsistent = false;
    }
}

if (!$branchConsistent) {
    $_SESSION['checkout_error'] = 'All items must be from the same restaurant branch.';
    header('Location: menu.php');
    exit;
}

$deliveryFee = $subtotal > 500 ? 0 : 50.00;
$total = $subtotal + $deliveryFee;

// ===== FETCH ALL ADDRESSES =====
$addresses = [];
$defaultAddressId = null;
try {
    $stmt = $database_connection->prepare(
        "SELECT customer_address_id FROM customer WHERE customer_id = :customer_id"
    );
    $stmt->execute([':customer_id' => $customerId]);
    $defaultAddressId = (int)($stmt->fetchColumn() ?? 0);

    $stmt = $database_connection->prepare(
        "SELECT 
            ca.customer_address_id, 
            ca.label, 
            ca.block, 
            ca.barangay, 
            ca.city, 
            ca.province, 
            ca.region, 
            ca.postal_code, 
            ca.country,
            CASE 
                WHEN ca.customer_address_id = :default_id THEN 1 
                ELSE 0 
            END AS is_default
        FROM customer_address ca
        ORDER BY is_default DESC, ca.customer_address_id"
    );
    $stmt->execute([
        ':default_id' => $defaultAddressId
    ]);
    $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Checkout address fetch error: ' . $e->getMessage());
}

if (empty($addresses)) {
    $_SESSION['checkout_error'] = 'Please add a delivery address in your profile before checking out.';
    header('Location: profile.php');
    exit;
}

// ===== FETCH USER DETAILS =====
$userDetails = [];
try {
    $stmt = $database_connection->prepare(
        "SELECT first_name, last_name, email, contact_number FROM customer WHERE customer_id = :customer_id"
    );
    $stmt->execute([':customer_id' => $customerId]);
    $userDetails = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Checkout user fetch error: ' . $e->getMessage());
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ===== NOW INCLUDE HEADER =====
require_once __DIR__ . '/../includes/header.php';

function formatAddress(array $addr): string {
    $parts = [];
    if (!empty($addr['block'])) $parts[] = $addr['block'];
    if (!empty($addr['barangay'])) $parts[] = $addr['barangay'];
    if (!empty($addr['city'])) $parts[] = $addr['city'];
    if (!empty($addr['province'])) $parts[] = $addr['province'];
    if (!empty($addr['region'])) $parts[] = $addr['region'];
    if (!empty($addr['postal_code'])) $parts[] = $addr['postal_code'];
    if (!empty($addr['country'])) $parts[] = $addr['country'];
    return implode(', ', $parts);
}

function getAddressLabel(array $addr): string {
    return !empty($addr['label']) ? $addr['label'] : 'Address';
}

$selectedAddr = $addresses[0] ?? null;
$selectedAddrId = $selectedAddr ? (int)$selectedAddr['customer_address_id'] : 0;
$selectedAddressText = $selectedAddr ? formatAddress($selectedAddr) : '';

$userName = trim(($userDetails['first_name'] ?? '') . ' ' . ($userDetails['last_name'] ?? ''));
$userEmail = $userDetails['email'] ?? 'Not provided';
$userContact = $userDetails['contact_number'] ?? 'Not provided';
?>
<link rel="stylesheet" href="../assets/css/checkout.css">

<div class="content checkout-page">
    <div class="container">
        <p class="heading-2 checkout-title">Checkout</p>

        <?php if (isset($_SESSION['checkout_error'])): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo htmlspecialchars($_SESSION['checkout_error'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['checkout_error']); ?>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- 2x2 GRID: Personal Details + Delivery Address | Order Summary + Payment -->
        <!-- ============================================ -->
        <div class="checkout-grid">

            <!-- TOP ROW: Personal Details + Delivery Address -->
            <div class="checkout-row checkout-row-top">

                <!-- Personal Details -->
                <div class="card">
                    <div class="card-header">
                        <p class="heading-5">Personal Details</p>
                    </div>
                    <div class="card-body">
                        <div class="detail-row">
                            <span class="detail-label">Name</span>
                            <span
                                class="detail-value"><?php echo htmlspecialchars($userName ?: 'Customer', ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Email</span>
                            <span
                                class="detail-value"><?php echo htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Contact</span>
                            <span
                                class="detail-value"><?php echo htmlspecialchars($userContact, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Delivery Address -->
                <div class="card">
                    <div class="card-header">
                        <p class="heading-5">Delivery Address</p>
                    </div>
                    <div class="card-body">
                        <div class="address-display">
                            <div class="address-display-icon">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/location-fill.svg"
                                    alt="Location">
                            </div>
                            <div class="address-display-info">
                                <p class="address-display-label">
                                    <?php echo htmlspecialchars(getAddressLabel($selectedAddr ?? []), ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if ((int)($selectedAddr['is_default'] ?? 0) === 1): ?>
                                    <span class="badge badge-default">Default</span>
                                    <?php endif; ?>
                                </p>
                                <p class="address-display-text">
                                    <?php echo htmlspecialchars($selectedAddressText, ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                            </div>
                            <button type="button" id="changeAddressBtn" class="btn btn-sm btn-outline">
                                Change
                            </button>
                        </div>
                        <input type="hidden" id="selectedAddressId" value="<?php echo $selectedAddrId; ?>">
                        <input type="hidden" id="selectedAddressText"
                            value="<?php echo htmlspecialchars($selectedAddressText, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>

            </div>

            <!-- BOTTOM ROW: Order Summary + Payment Method -->
            <div class="checkout-row checkout-row-bottom">

                <!-- Order Summary -->
                <div class="card">
                    <div class="card-header">
                        <p class="heading-5">Order Summary</p>
                        <span class="badge"><?php echo count($cartItems); ?></span>
                    </div>
                    <div class="card-body">
                        <div class="order-items">
                            <?php foreach ($cartItems as $item): ?>
                            <div class="order-item">
                                <div class="order-item-image">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg"
                                        alt="<?php echo htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/restaurant.svg'">
                                </div>
                                <div class="order-item-info">
                                    <p class="order-item-name">
                                        <?php echo htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </p>
                                    <p class="order-item-restaurant">
                                        <?php echo htmlspecialchars($restaurantName, ENT_QUOTES, 'UTF-8'); ?>
                                    </p>
                                    <p class="order-item-meta">Qty: <?php echo (int)$item['quantity']; ?></p>
                                    <?php if (!empty($item['customizations'])): ?>
                                    <ul class="order-item-customizations">
                                        <?php foreach ($item['customizations'] as $cust): ?>
                                        <li><?php echo htmlspecialchars($cust['ingredient_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                            <?php if ($cust['price_modifier'] != 0): ?>
                                            (+₱<?php echo number_format((float)$cust['price_modifier'], 2); ?>)
                                            <?php endif; ?>
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php endif; ?>
                                    <p class="order-item-price">
                                        ₱<?php echo number_format((float)$item['price'] * (int)$item['quantity'], 2); ?>
                                    </p>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="order-totals">
                            <div class="totals-row">
                                <span>Subtotal</span>
                                <span>₱<?php echo number_format($subtotal, 2); ?></span>
                            </div>
                            <div class="totals-row">
                                <span>Delivery Fee</span>
                                <span><?php echo $deliveryFee > 0 ? '₱' . number_format($deliveryFee, 2) : 'Free'; ?></span>
                            </div>
                            <div class="totals-row total">
                                <span>Total</span>
                                <span class="total-amount">₱<?php echo number_format($total, 2); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Method -->
                <div class="card">
                    <div class="card-header">
                        <p class="heading-5">Payment Method</p>
                    </div>
                    <div class="card-body">
                        <div class="payment-options">
                            <div class="payment-option selected" data-method="COD">
                                <input type="radio" name="payment_method" value="COD" id="payment_cod" checked>
                                <label for="payment_cod">
                                    <span class="payment-icon">
                                        <img src="<?php echo $assetBase; ?>assets/images/icons/cart-shopping.svg"
                                            alt="COD">
                                    </span>
                                    <span class="payment-details">
                                        <span class="payment-label">Cash on Delivery</span>
                                        <span class="payment-description">Pay when you receive your order</span>
                                    </span>
                                </label>
                            </div>
                            <div class="payment-option" data-method="Wallet">
                                <input type="radio" name="payment_method" value="Wallet" id="payment_wallet">
                                <label for="payment_wallet">
                                    <span class="payment-icon">
                                        <img src="<?php echo $assetBase; ?>assets/images/icons/profile.svg"
                                            alt="Wallet">
                                    </span>
                                    <span class="payment-details">
                                        <span class="payment-label">Wallet</span>
                                        <span class="payment-description">Pay using your FitPal wallet balance</span>
                                    </span>
                                </label>
                            </div>
                            <div class="payment-option" data-method="Online">
                                <input type="radio" name="payment_method" value="Online" id="payment_online">
                                <label for="payment_online">
                                    <span class="payment-icon">
                                        <img src="<?php echo $assetBase; ?>assets/images/icons/mail.svg" alt="Online">
                                    </span>
                                    <span class="payment-details">
                                        <span class="payment-label">Online Payment</span>
                                        <span class="payment-description">Pay via GCash or Maya (simulated)</span>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <div class="checkout-actions">
                            <a href="menu.php" class="btn btn-secondary">Back to Menu</a>
                            <button type="button" id="placeOrderBtn" class="btn btn-primary">Place Order</button>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- ADDRESS MODAL (queue panel pattern - optimized fade) -->
<!-- ============================================ -->
<div id="addressModal" class="modal" style="display:none;">
    <div class="modal-overlay"></div>
    <div class="modal-content">
        <div class="modal-header">
            <p class="heading-5 modal-title">Select Delivery Address</p>
            <button type="button" class="modal-close" id="closeAddressModal">&times;</button>
        </div>

        <div class="modal-body">
            <p class="modal-subtitle">Choose an address for delivery. <a href="profile.php#addresses">Manage
                    addresses</a></p>

            <div class="address-list" id="addressList">
                <?php foreach ($addresses as $addr): 
                    $isDefault = (int)($addr['is_default'] ?? 0) === 1;
                    $addrId = (int)$addr['customer_address_id'];
                ?>
                <div class="address-option <?php echo $isDefault ? 'selected' : ''; ?>"
                    data-address-id="<?php echo $addrId; ?>">
                    <input type="radio" name="modal_address" id="addr_<?php echo $addrId; ?>"
                        value="<?php echo $addrId; ?>" <?php echo $isDefault ? 'checked' : ''; ?>>
                    <label for="addr_<?php echo $addrId; ?>">
                        <div class="address-option-content">
                            <div class="address-option-header">
                                <span class="address-option-label">
                                    <?php echo htmlspecialchars(getAddressLabel($addr), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <?php if ($isDefault): ?>
                                <span class="badge badge-default">Default</span>
                                <?php endif; ?>
                            </div>
                            <p class="address-option-text">
                                <?php echo htmlspecialchars(formatAddress($addr), ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                        </div>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="cancelAddressModal">Cancel</button>
            <button type="button" class="btn btn-primary" id="addAddressModalBtn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19" />
                    <line x1="5" y1="12" x2="19" y2="12" />
                </svg>
                Add Address
            </button>
        </div>
    </div>
</div>

<!-- Hidden form for submitting order -->
<form id="checkoutForm" method="POST" action="../backend/handlers/place-order-handler.php" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
    <input type="hidden" name="address_id" id="hiddenAddressId" value="<?php echo $selectedAddrId; ?>">
    <input type="hidden" name="payment_method" id="hiddenPaymentMethod" value="COD">
    <input type="hidden" name="subtotal" value="<?php echo $subtotal; ?>">
    <input type="hidden" name="delivery_fee" value="<?php echo $deliveryFee; ?>">
    <input type="hidden" name="total" value="<?php echo $total; ?>">
</form>

<script src="../assets/ui/js/checkout.js" defer></script>
<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>