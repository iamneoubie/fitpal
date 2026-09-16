<?php
/**
 * FitPal Customer Checkout Page
 * Version 4.3 — Tiered delivery fee (base + per-branch surcharge),
 *                flat service fee, multi-branch carts supported.
 *
 * @package FitPal
 * @version 4.3
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    header('Location: sign-in.php');
    exit;
}

require_once __DIR__ . '/../backend/database/customer-connect.php';
require_once __DIR__ . '/../backend/database/cart-queries.php';
require_once __DIR__ . '/../backend/database/customer-queries.php';
require_once __DIR__ . '/../backend/database/address-queries.php';
require_once __DIR__ . '/../backend/database/fee-queries.php';

$customerId = (int)$_SESSION['customer_id'];

// ---- Re-sync: if the session queue has items, mirror them into the cart.
if (!empty($_SESSION['order_queue']) && is_array($_SESSION['order_queue'])) {
    try {
        $database_connection->beginTransaction();

        clearCart($database_connection, $customerId);

        foreach ($_SESSION['order_queue'] as $qItem) {
            if (!is_array($qItem)) continue;

            $pid = (int)($qItem['product_id'] ?? 0);
            $qty = (int)($qItem['quantity'] ?? 0);
            if ($pid <= 0 || $qty <= 0) continue;

            $unitPrice = (float)($qItem['price'] ?? 0);

            $custJson = null;
            if (isset($qItem['customization_data'])) {
                $custJson = is_string($qItem['customization_data'])
                    ? $qItem['customization_data']
                    : json_encode($qItem['customization_data']);
            }

            insertCartItem(
                $database_connection,
                $customerId,
                $pid,
                $qty,
                $unitPrice,
                $custJson
            );
        }

        $database_connection->commit();

    } catch (Throwable $e) {
        if ($database_connection->inTransaction()) {
            $database_connection->rollBack();
        }
        error_log('Checkout queue re-sync failed: ' . $e->getMessage());
    }
}

$cartItems = getCustomerCart($database_connection, $customerId);

if (empty($cartItems)) {
    $_SESSION['checkout_error'] = 'Your cart is empty. Please add items before checkout.';
    header('Location: menu.php');
    exit;
}

// ---------------------------------------------------------------
// Cart aggregation + fee calculation
//
// Delivery fee:
//   Base 50.00 for the first branch.
//   +30.00 for each additional distinct branch.
//
// Service fee:
//   Flat 5.00 per order.
//
// Examples:
//   1 product                        -> 50.00 + 5.00
//   2 products, same branch          -> 50.00 + 5.00
//   2 products, different branches   -> 80.00 + 5.00
//   3 products, 2 same + 1 other     -> 80.00 + 5.00
//   3 products, all different        -> 110.00 + 5.00
// ---------------------------------------------------------------
$subtotal       = 0.0;
$branchIds      = [];
$branchName     = '';
$restaurantName = '';

foreach ($cartItems as $item) {
    $subtotal += (float)$item['price'] * (int)$item['quantity'];

    $itemBranchId = (int)$item['restaurant_branch_id'];
    if (!in_array($itemBranchId, $branchIds, true)) {
        $branchIds[] = $itemBranchId;
    }

    if ($branchName === '') {
        $branchName     = $item['branch_name'];
        $restaurantName = $item['restaurant_name'];
    }
}

$fees          = calculateOrderFees(count($branchIds), $subtotal);
$deliveryFee   = $fees['delivery_fee'];
$serviceFee    = $fees['service_fee'];
$vatAmount     = $fees['vat'];
$vatRate       = $fees['vat_rate'];
$extraBranches = $fees['extra_branches'];
$branchCount   = $fees['branch_count'];
$total         = $subtotal + $deliveryFee + $serviceFee + $vatAmount;

// ---- Addresses ----
$addresses        = getCustomerAddresses($database_connection, $customerId);
$defaultAddressId = getCustomerDefaultAddressId($database_connection, $customerId);
$hasAddress       = !empty($addresses);

// ---- User details ----
$userDetails = getCustomerContactInfo($database_connection, $customerId) ?: [];

// ---- Wallet balance ----
$walletBalance = 0.0;
try {
    $walletStmt = $database_connection->prepare(
        "SELECT fa.balance
           FROM customer_profile cp
           JOIN financial_account fa ON cp.financial_account_id = fa.financial_account_id
          WHERE cp.customer_id = :customer_id
          LIMIT 1"
    );
    $walletStmt->execute([':customer_id' => $customerId]);
    $walletRow = $walletStmt->fetch(PDO::FETCH_ASSOC);
    if ($walletRow) {
        $walletBalance = (float)$walletRow['balance'];
    }
} catch (PDOException $e) {
    error_log('Checkout wallet balance error: ' . $e->getMessage());
}

// ---- CSRF ----
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

require_once __DIR__ . '/../includes/header.php';

function formatAddress(array $addr): string {
    $parts = [];
    if (!empty($addr['block']))       $parts[] = $addr['block'];
    if (!empty($addr['barangay']))    $parts[] = $addr['barangay'];
    if (!empty($addr['city']))        $parts[] = $addr['city'];
    if (!empty($addr['province']))    $parts[] = $addr['province'];
    if (!empty($addr['region']))      $parts[] = $addr['region'];
    if (!empty($addr['postal_code'])) $parts[] = $addr['postal_code'];
    if (!empty($addr['country']))     $parts[] = $addr['country'];
    return implode(', ', $parts);
}

function getAddressLabel(array $addr): string {
    return !empty($addr['label']) ? $addr['label'] : 'Address';
}

$selectedAddr = null;

if ($hasAddress) {
    $sessionAddressId = isset($_SESSION['checkout_address_id'])
        ? (int)$_SESSION['checkout_address_id']
        : 0;

    if ($sessionAddressId > 0) {
        foreach ($addresses as $addr) {
            if ((int)$addr['customer_address_id'] === $sessionAddressId) {
                $selectedAddr = $addr;
                break;
            }
        }
    }

    if ($selectedAddr === null) {
        $selectedAddr = $addresses[0];
        $_SESSION['checkout_address_id'] = (int)$selectedAddr['customer_address_id'];
    }
}

$selectedAddrId      = $selectedAddr ? (int)$selectedAddr['customer_address_id'] : 0;
$selectedAddressText = $selectedAddr ? formatAddress($selectedAddr) : '';

$userName    = trim(($userDetails['first_name'] ?? '') . ' ' . ($userDetails['last_name'] ?? ''));
$userEmail   = $userDetails['email'] ?? 'Not provided';
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

        <?php if (isset($_SESSION['cart_success'])): ?>
        <div class="alert alert-success" role="alert">
            <?php echo htmlspecialchars($_SESSION['cart_success'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['cart_success']); ?>
        </div>
        <?php endif; ?>

        <div class="checkout-grid">

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
                        <?php if ($hasAddress): ?>
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
                        <?php else: ?>
                        <div class="address-empty-state">
                            <div class="address-empty-icon">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/location-fill.svg"
                                    alt="No address">
                            </div>
                            <p class="address-empty-title">No delivery address yet</p>
                            <p class="address-empty-text">
                                Add a delivery address so we know where to send your order.
                            </p>
                            <a href="profile.php?from=checkout#add-address" class="btn btn-primary btn-sm">
                                Add Address
                            </a>
                        </div>
                        <input type="hidden" id="selectedAddressId" value="0">
                        <input type="hidden" id="selectedAddressText" value="">
                        <?php endif; ?>
                    </div>
                </div>

            </div>

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
                                        <?php echo htmlspecialchars($item['restaurant_name'] ?? $restaurantName, ENT_QUOTES, 'UTF-8'); ?>
                                    </p>
                                    <p class="order-item-meta">Qty: <?php echo (int)$item['quantity']; ?></p>
                                    <?php if (!empty($item['customizations'])): ?>
                                    <ul class="order-item-customizations">
                                        <?php foreach ($item['customizations'] as $cust): ?>
                                        <li><?php echo htmlspecialchars($cust['ingredient_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                            <?php if (($cust['price_modifier'] ?? 0) != 0): ?>
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
                                <span>
                                    Delivery Fee
                                    <?php if ($extraBranches > 0): ?>
                                    <small class="totals-row-note">(<?php echo $branchCount; ?> branches)</small>
                                    <?php endif; ?>
                                </span>
                                <span>₱<?php echo number_format($deliveryFee, 2); ?></span>
                            </div>
                            <div class="totals-row">
                                <span>Service Fee</span>
                                <span>₱<?php echo number_format($serviceFee, 2); ?></span>
                            </div>
                            <div class="totals-row">
                                <span>
                                    VAT
                                    <small
                                        class="totals-row-note">(<?php echo number_format($vatRate * 100, 0); ?>%)</small>
                                </span>
                                <span>₱<?php echo number_format($vatAmount, 2); ?></span>
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
                                        <span class="payment-description">
                                            Balance: ₱<?php echo number_format($walletBalance, 2); ?>
                                        </span>
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
                                        <span class="payment-description">Pay via GCash or Maya</span>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="checkout-actions">
                        <a href="menu.php" class="btn btn-secondary">Back to Menu</a>
                        <button type="button" id="placeOrderBtn" class="btn btn-primary"
                            data-has-address="<?php echo $hasAddress ? '1' : '0'; ?>">
                            <?php echo $hasAddress ? 'Place Order' : 'Add Address to Continue'; ?>
                        </button>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- ADDRESS MODAL -->
<!-- ============================================ -->
<?php if ($hasAddress): ?>
<div id="addressModal" class="modal" style="display:none;">
    <div class="modal-overlay"></div>
    <div class="modal-content">
        <div class="modal-header">
            <p class="heading-5 modal-title">Select Delivery Address</p>
            <button type="button" class="modal-close" id="closeAddressModal">&times;</button>
        </div>

        <div class="modal-body">
            <p class="modal-subtitle">Choose an address for delivery.
                <a href="profile.php?from=checkout#addresses">Manage addresses</a>
            </p>

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
            <a href="profile.php?from=checkout#add-address" class="btn btn-primary" id="addAddressModalBtn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19" />
                    <line x1="5" y1="12" x2="19" y2="12" />
                </svg>
                Add Address
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- ONLINE PAYMENT QR MODAL -->
<!-- ============================================ -->
<div id="qrPaymentModal" class="modal" style="display:none;">
    <div class="modal-overlay"></div>
    <div class="modal-content qr-modal-content">
        <div class="modal-header">
            <p class="heading-5 modal-title">Online Payment</p>
            <button type="button" class="modal-close" id="closeQrModal">&times;</button>
        </div>

        <div class="modal-body">
            <div class="qr-modal-body">
                <div class="qr-amount-block">
                    <span class="qr-amount-label">Amount to Pay</span>
                    <p class="qr-amount">₱<?php echo number_format($total, 2); ?></p>
                </div>

                <div class="qr-image-wrapper">
                    <img src="<?php echo $assetBase; ?>assets/images/payment/QR.jpg" alt="Scan to pay QR code"
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                </div>

                <p class="qr-note">
                    <strong>Simulation Notice:</strong> This QR code is for demonstration purposes only.
                    No real payment will be processed. Click <em>I've Paid</em> to simulate a successful
                    transaction.
                </p>
            </div>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="cancelQrModal">Cancel</button>
            <button type="button" class="btn btn-primary" id="confirmQrPayment">I've Paid</button>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- WALLET INSUFFICIENT MODAL -->
<!-- ============================================ -->
<div id="walletInsufficientModal" class="modal" style="display:none;">
    <div class="modal-overlay"></div>
    <div class="modal-content wallet-modal-content">
        <div class="modal-header">
            <p class="heading-5 modal-title">Insufficient Wallet Balance</p>
            <button type="button" class="modal-close" id="closeWalletModal">&times;</button>
        </div>

        <div class="modal-body">
            <div class="wallet-modal-body">
                <div class="wallet-modal-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/funds-circle-analytic-fill.svg" alt="Wallet"
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                </div>

                <p class="wallet-modal-title">Not enough funds</p>
                <p class="wallet-modal-text">
                    Your FitPal wallet balance is lower than the order total. Please top up your
                    wallet to continue, or choose a different payment method.
                </p>

                <div class="wallet-balance-card">
                    <div class="wallet-balance-row available">
                        <span class="label">Current Balance</span>
                        <span class="value">₱<?php echo number_format($walletBalance, 2); ?></span>
                    </div>
                    <div class="wallet-balance-row">
                        <span class="label">Order Total</span>
                        <span class="value">₱<?php echo number_format($total, 2); ?></span>
                    </div>
                    <div class="wallet-balance-row shortfall">
                        <span class="label">Shortfall</span>
                        <span class="value">−₱<?php echo number_format(max(0, $total - $walletBalance), 2); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="cancelWalletModal">Cancel</button>
            <a href="wallet.php" class="btn btn-primary" id="proceedWalletRecharge">Recharge Wallet</a>
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
    <input type="hidden" name="service_fee" value="<?php echo $serviceFee; ?>">
    <input type="hidden" name="vat_amount" value="<?php echo $vatAmount; ?>">
    <input type="hidden" name="total" value="<?php echo $total; ?>">
</form>

<script>
window.FITPAL_CHECKOUT = {
    total: <?php echo json_encode((float)$total); ?>,
    subtotal: <?php echo json_encode((float)$subtotal); ?>,
    deliveryFee: <?php echo json_encode((float)$deliveryFee); ?>,
    serviceFee: <?php echo json_encode((float)$serviceFee); ?>,
    vatAmount: <?php echo json_encode((float)$vatAmount); ?>,
    vatRate: <?php echo json_encode((float)$vatRate); ?>,
    walletBalance: <?php echo json_encode((float)$walletBalance); ?>,
    hasAddress: <?php echo $hasAddress ? 'true' : 'false'; ?>,
    csrfToken: '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>'
};
</script>
<script src="../assets/ui/js/checkout.js" defer></script>
<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>