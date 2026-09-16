<?php
/**
 * FitPal Customer Cart Page
 * Version 2.3 — Reads parsed customizations from cart-queries.php.
 *
 * @package FitPal
 * @version 2.3
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

$customerId = (int)$_SESSION['customer_id'];

$allItems         = getCartItemsWithProductDetails($database_connection, $customerId);
$availableItems   = [];
$unavailableItems = [];
$subtotal         = 0.0;

foreach ($allItems as $item) {
    $isAvailable = ((int)$item['is_active'] === 1) && ((int)$item['stock'] > 0);
    if ($isAvailable) {
        $availableItems[] = $item;
        $subtotal += (float)$item['price'] * (int)$item['quantity'];
    } else {
        $unavailableItems[] = $item;
    }
}

$hasAnyItems  = !empty($allItems);
$hasAvailable = !empty($availableItems);

/**
 * Resolve a cart product image path, falling back to a placeholder icon.
 */
function getCartImageUrl(string $mediaPath, string $assetBase): string
{
    if ($mediaPath === '') {
        return $assetBase . 'assets/images/icons/restaurant.svg';
    }
    return htmlspecialchars($mediaPath, ENT_QUOTES, 'UTF-8');
}

/**
 * Format a numeric amount as Philippine pesos.
 */
function formatCurrency(float|string|null $amount): string
{
    return '₱' . number_format((float)($amount ?? 0), 2);
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="../assets/css/cart.css">

<div class="content cart-page">
    <div class="container">

        <!-- ============================================
             PAGE HEADER
             ============================================ -->
        <div class="page-title-header">
            <p class="heading-2">My <span>Cart</span></p>
            <p class="text-muted">
                Items you have saved for later. Add them to your order queue when you are ready to check out.
            </p>
        </div>

        <!-- ============================================
             FLASH MESSAGES
             ============================================ -->
        <?php if (isset($_SESSION['cart_success'])): ?>
        <div class="alert alert-success" role="alert">
            <?php echo htmlspecialchars($_SESSION['cart_success'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['cart_success']); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['cart_error'])): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo htmlspecialchars($_SESSION['cart_error'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['cart_error']); ?>
        </div>
        <?php endif; ?>

        <?php if (!$hasAnyItems): ?>

        <!-- ============================================
             EMPTY STATE
             ============================================ -->
        <div class="empty-state">
            <div class="empty-state-content">
                <div class="empty-state-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/cart-shopping.svg" alt="Empty cart">
                </div>
                <p class="heading-4">Your cart is empty</p>
                <p class="text-muted">
                    Browse our restaurants and save meals here to order later.
                </p>
                <a href="menu.php" class="btn btn-primary">Browse Menu</a>
            </div>
        </div>

        <?php else: ?>

        <!-- ============================================
             CART CONTAINER
             ============================================ -->
        <div class="cart-container">

            <!-- ============================================
                 AVAILABLE ITEMS
                 ============================================ -->
            <?php if ($hasAvailable): ?>
            <div class="cart-section">
                <p class="cart-section-label">Available Items</p>

                <div class="cart-items" id="cartItems">
                    <?php foreach ($availableItems as $item):
                        $cartId       = (int)$item['cart_id'];
                        $productId    = (int)$item['product_id'];
                        $quantity     = (int)$item['quantity'];
                        $price        = (float)$item['price'];
                        $stock        = (int)$item['stock'];
                        $itemSubtotal = $price * $quantity;
                        $imageUrl     = getCartImageUrl($item['product_image'] ?? '', $assetBase);

                        // cart-queries.php already returns parsed customizations.
                        $customs      = $item['customizations'] ?? [];
                    ?>
                    <div class="cart-item" data-cart-id="<?php echo $cartId; ?>"
                        data-product-id="<?php echo $productId; ?>" data-price="<?php echo $price; ?>"
                        data-stock="<?php echo $stock; ?>">

                        <!-- Product Image -->
                        <div class="cart-item-image">
                            <img src="<?php echo $imageUrl; ?>"
                                alt="<?php echo htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/restaurant.svg'">
                        </div>

                        <!-- Product Details -->
                        <div class="cart-item-details">
                            <p class="heading-6 cart-item-name">
                                <?php echo htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </p>

                            <p class="cart-item-restaurant">
                                <?php echo htmlspecialchars($item['business_name'], ENT_QUOTES, 'UTF-8'); ?>
                                <span class="cart-item-separator">•</span>
                                <?php echo htmlspecialchars($item['branch_name'], ENT_QUOTES, 'UTF-8'); ?>
                            </p>

                            <p class="cart-item-price">
                                <?php echo formatCurrency($price); ?>
                            </p>

                            <?php if (!empty($customs)): ?>
                            <ul class="cart-item-customizations">
                                <?php foreach ($customs as $c): ?>
                                <li>
                                    <?php echo htmlspecialchars($c['ingredient_name'] ?? $c['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if (($c['price_modifier'] ?? $c['price'] ?? 0) != 0): ?>
                                    <span class="cart-item-custom-price">
                                        (<?php echo ($c['price_modifier'] ?? $c['price']) > 0 ? '+' : '−'; ?><?php echo formatCurrency(abs($c['price_modifier'] ?? $c['price'])); ?>)
                                    </span>
                                    <?php endif; ?>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>

                            <p class="cart-item-stock">In stock: <?php echo $stock; ?></p>
                        </div>

                        <!-- Controls -->
                        <div class="cart-item-controls">
                            <div class="cart-item-quantity">
                                <button type="button" class="qty-btn qty-minus" data-cart-id="<?php echo $cartId; ?>"
                                    aria-label="Decrease quantity">−</button>
                                <input type="number" class="qty-input" value="<?php echo $quantity; ?>" min="1"
                                    max="<?php echo $stock; ?>" data-cart-id="<?php echo $cartId; ?>"
                                    aria-label="Quantity for <?php echo htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="button" class="qty-btn qty-plus" data-cart-id="<?php echo $cartId; ?>"
                                    aria-label="Increase quantity">+</button>
                            </div>

                            <p class="cart-item-subtotal">
                                <span class="cart-item-subtotal-label">Subtotal:</span>
                                <span class="cart-item-subtotal-amount" data-cart-id="<?php echo $cartId; ?>">
                                    <?php echo formatCurrency($itemSubtotal); ?>
                                </span>
                            </p>

                            <button type="button" class="btn btn-outline btn-sm cart-item-remove"
                                data-cart-id="<?php echo $cartId; ?>"
                                data-product-name="<?php echo htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                Remove
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ============================================
                 UNAVAILABLE ITEMS
                 ============================================ -->
            <?php if (!empty($unavailableItems)): ?>
            <div class="cart-section cart-section-unavailable">
                <p class="cart-section-label cart-section-label-muted">Unavailable Items</p>

                <div class="cart-items">
                    <?php foreach ($unavailableItems as $item):
                        $cartId    = (int)$item['cart_id'];
                        $reason    = ((int)$item['is_active'] === 1) ? 'Out of Stock' : 'Product Unavailable';
                        $imageUrl  = getCartImageUrl($item['product_image'] ?? '', $assetBase);
                    ?>
                    <div class="cart-item cart-item-unavailable" data-cart-id="<?php echo $cartId; ?>">
                        <div class="cart-item-image">
                            <img src="<?php echo $imageUrl; ?>"
                                alt="<?php echo htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/restaurant.svg'">
                        </div>

                        <div class="cart-item-details">
                            <p class="heading-6 cart-item-name">
                                <?php echo htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </p>

                            <p class="cart-item-restaurant">
                                <?php echo htmlspecialchars($item['business_name'], ENT_QUOTES, 'UTF-8'); ?>
                            </p>

                            <span class="cart-item-badge-unavailable">
                                <?php echo htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>

                        <div class="cart-item-controls">
                            <button type="button" class="btn btn-outline btn-sm cart-item-remove"
                                data-cart-id="<?php echo $cartId; ?>"
                                data-product-name="<?php echo htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                Remove
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ============================================
                 CART SUMMARY
                 ============================================ -->
            <div class="cart-summary">
                <div class="cart-summary-total">
                    <span class="cart-summary-label">Subtotal</span>
                    <span class="cart-summary-amount" id="cartSubtotal">
                        <?php echo formatCurrency($subtotal); ?>
                    </span>
                </div>

                <?php if (!$hasAvailable): ?>
                <p class="cart-summary-note">
                    Add at least one available item to proceed to checkout.
                </p>
                <?php endif; ?>

                <div class="cart-summary-actions">
                    <a href="menu.php" class="btn btn-secondary">
                        Continue Shopping
                    </a>
                    <?php if ($hasAvailable): ?>
                    <a href="checkout.php" class="btn btn-primary" id="cartCheckoutBtn">
                        Proceed to Checkout
                    </a>
                    <?php else: ?>
                    <button type="button" class="btn btn-primary" disabled>
                        Proceed to Checkout
                    </button>
                    <?php endif; ?>
                </div>
            </div>

        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================
     REMOVE ITEM CONFIRMATION MODAL
     ============================================ -->
<div class="cart-modal" id="cartRemoveModal" style="display:none;">
    <div class="cart-modal-overlay"></div>
    <div class="cart-modal-content">
        <div class="cart-modal-icon">
            <img src="<?php echo $assetBase; ?>assets/images/icons/trash.svg" alt="Remove item">
        </div>
        <div class="cart-modal-body">
            <p class="heading-5 cart-modal-title">Remove Item</p>
            <p class="cart-modal-text">
                Are you sure you want to remove
                <strong id="cartModalItemName"></strong>
                from your cart?
            </p>
        </div>
        <div class="cart-modal-footer">
            <button type="button" class="btn btn-secondary" id="cartModalCancel">Cancel</button>
            <button type="button" class="btn btn-danger" id="cartModalConfirm">Remove</button>
        </div>
    </div>
</div>

<script>
window.FITPAL_ASSET_BASE = '<?php echo $assetBase; ?>';
window.FITPAL_CSRF_TOKEN = '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>';
</script>
<script src="../assets/ui/js/cart.js" defer></script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>