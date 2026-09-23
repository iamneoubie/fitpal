<?php
/**
 * FitPal Customer Cart Page
 * Version 3.4 — Customization panel lives inside the details column.
 *
 * @package FitPal
 * @version 3.5 - CSRF token now inherited from header.php; local
 *                generation removed.
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
$perPage    = 5;
$page       = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$totals = getCartTotals($database_connection, $customerId);
$totalRows  = $totals['rowCount'];
$totalUnits = $totals['unitCount'];

$hasAnyItems = $totalRows > 0;

$pageData         = null;
$items            = [];
$availableItems   = [];
$unavailableItems = [];
$totalPages       = 1;

if ($hasAnyItems) {
    $pageData = getCartItemsWithProductDetailsPaginated(
        $database_connection,
        $customerId,
        $page,
        $perPage
    );

    $items      = $pageData['items'];
    $totalPages = $pageData['totalPages'];
    $page       = $pageData['page'];

    foreach ($items as $item) {
        $isAvailable = ((int)$item['is_active'] === 1) && ((int)$item['stock'] > 0);
        if ($isAvailable) {
            $availableItems[] = $item;
        } else {
            $unavailableItems[] = $item;
        }
    }
}

$hasAvailable = !empty($availableItems);

function getCartImageUrl(string $mediaPath, string $assetBase): string
{
    if ($mediaPath === '') {
        return $assetBase . 'assets/images/icons/restaurant.svg';
    }
    return htmlspecialchars($mediaPath, ENT_QUOTES, 'UTF-8');
}

function formatCurrency(float|string|null $amount): string
{
    return '₱' . number_format((float)($amount ?? 0), 2);
}

function buildCartPageUrl(int $page): string
{
    return '?page=' . max(1, $page);
}

require_once __DIR__ . '/../includes/header.php';

// $csrfToken is provided by header.php (via includes/csrf_token.php),
// stored under the customer role's own session key 'customer_csrf_token'.
?>

<link rel="stylesheet" href="../assets/css/cart.css">

<div class="content cart-page">
    <div class="container">

        <div class="page-title-header">
            <p class="heading-2">My <span>Cart</span></p>
            <p class="text-muted">
                Select items you want to add to your order queue when you are ready to check out.
            </p>
        </div>

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

        <div class="cart-container">

            <div class="cart-meta-row">
                <p class="cart-meta-text">
                    Showing <strong><?php echo count($items); ?></strong>
                    of <strong><?php echo $totalRows; ?></strong> items
                </p>
            </div>

            <?php if ($hasAvailable): ?>
            <div class="cart-section">
                <div class="cart-section-head">
                    <label class="cart-select-all">
                        <input type="checkbox" id="cartSelectAllPage" checked>
                        <span class="cart-checkbox-mark" aria-hidden="true"></span>
                        <span class="cart-select-all-text">Select all on this page</span>
                    </label>
                    <span class="cart-section-label-inline">Available Items</span>
                </div>

                <div class="cart-items" id="cartItems">
                    <?php foreach ($availableItems as $item):
                        $cartId       = (int)$item['cart_id'];
                        $productId    = (int)$item['product_id'];
                        $quantity     = (int)$item['quantity'];
                        $price        = (float)$item['price'];
                        $stock        = (int)$item['stock'];
                        $itemSubtotal = $price * $quantity;
                        $imageUrl     = getCartImageUrl($item['product_image'] ?? '', $assetBase);

                        $breakdown  = getCartCustomizationBreakdown($item);
                        $hasCustoms = !empty($breakdown['modifications']);
                        $customsId  = 'cart-customs-' . $cartId;
                    ?>
                    <div class="cart-item" data-cart-id="<?php echo $cartId; ?>"
                        data-product-id="<?php echo $productId; ?>" data-price="<?php echo $price; ?>"
                        data-stock="<?php echo $stock; ?>">

                        <div class="cart-item-select">
                            <label class="cart-checkbox">
                                <input type="checkbox" class="cart-item-checkbox" value="<?php echo $cartId; ?>"
                                    checked>
                                <span class="cart-checkbox-mark" aria-hidden="true"></span>
                            </label>
                        </div>

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
                                <span class="cart-item-separator">•</span>
                                <?php echo htmlspecialchars($item['branch_name'], ENT_QUOTES, 'UTF-8'); ?>
                            </p>

                            <p class="cart-item-price">
                                <?php echo formatCurrency($price); ?>
                            </p>

                            <?php if ($hasCustoms): ?>
                            <button type="button" class="cart-customs-toggle" aria-expanded="false"
                                aria-controls="<?php echo $customsId; ?>">
                                <span>Customized</span>
                                <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-drop-down-line.svg" alt=""
                                    class="cart-customs-toggle-icon" width="14" height="14">
                            </button>

                            <div class="cart-customs-row-wrap" id="<?php echo $customsId; ?>" hidden>
                                <div class="cart-customs-panel">
                                    <p class="cart-customs-heading">Customizations</p>

                                    <ul class="cart-customs-list">
                                        <?php foreach ($breakdown['modifications'] as $mod): ?>
                                        <li
                                            class="cart-customs-row cart-customs-<?php echo htmlspecialchars($mod['kind'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <span class="cart-customs-symbol">
                                                <?php echo $mod['kind'] === 'remove' ? '−' : '+'; ?>
                                            </span>
                                            <span class="cart-customs-name">
                                                <?php echo htmlspecialchars($mod['name'], ENT_QUOTES, 'UTF-8'); ?>
                                                <?php if ($mod['kind'] === 'remove'): ?>
                                                <span class="cart-customs-removed">(removed)</span>
                                                <?php endif; ?>
                                            </span>
                                            <?php if ($mod['price'] != 0): ?>
                                            <span class="cart-customs-price">
                                                (<?php echo $mod['price'] > 0 ? '+' : '−'; ?><?php echo formatCurrency(abs($mod['price'])); ?>)
                                            </span>
                                            <?php endif; ?>
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>

                                    <div class="cart-customs-summary">
                                        <div class="cart-customs-summary-row">
                                            <span>Base price</span>
                                            <span><?php echo formatCurrency($breakdown['base_price']); ?></span>
                                        </div>
                                        <?php if ($breakdown['modifier_total'] != 0): ?>
                                        <div class="cart-customs-summary-row">
                                            <span>Customizations</span>
                                            <span>
                                                <?php echo $breakdown['modifier_total'] > 0 ? '+' : '−'; ?><?php echo formatCurrency(abs($breakdown['modifier_total'])); ?>
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                        <div class="cart-customs-summary-row">
                                            <span>Unit price</span>
                                            <span><?php echo formatCurrency($breakdown['unit_price']); ?></span>
                                        </div>
                                        <div class="cart-customs-summary-row">
                                            <span>Quantity</span>
                                            <span>× <?php echo $quantity; ?></span>
                                        </div>
                                        <div class="cart-customs-summary-row cart-customs-summary-total">
                                            <span>Line total</span>
                                            <span><?php echo formatCurrency($itemSubtotal); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <p class="cart-item-stock">In stock: <?php echo $stock; ?></p>
                        </div>

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

            <?php if (!empty($unavailableItems)): ?>
            <div class="cart-section cart-section-unavailable">
                <div class="cart-section-head">
                    <span class="cart-section-label-inline cart-section-label-muted">Unavailable Items</span>
                </div>

                <div class="cart-items">
                    <?php foreach ($unavailableItems as $item):
                        $cartId    = (int)$item['cart_id'];
                        $reason    = ((int)$item['is_active'] === 1) ? 'Out of Stock' : 'Product Unavailable';
                        $imageUrl  = getCartImageUrl($item['product_image'] ?? '', $assetBase);
                    ?>
                    <div class="cart-item cart-item-unavailable" data-cart-id="<?php echo $cartId; ?>">
                        <div class="cart-item-select">
                            <label class="cart-checkbox cart-checkbox-disabled">
                                <input type="checkbox" disabled>
                                <span class="cart-checkbox-mark" aria-hidden="true"></span>
                            </label>
                        </div>

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

            <?php if ($totalPages > 1): ?>
            <nav class="cart-pagination" aria-label="Cart pagination">
                <ul class="cart-pagination-list">
                    <li>
                        <?php if ($page > 1): ?>
                        <a href="<?php echo htmlspecialchars(buildCartPageUrl($page - 1), ENT_QUOTES, 'UTF-8'); ?>"
                            class="cart-pagination-link">Previous</a>
                        <?php else: ?>
                        <span class="cart-pagination-link disabled">Previous</span>
                        <?php endif; ?>
                    </li>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li>
                        <?php if ($i === $page): ?>
                        <span class="cart-pagination-link active" aria-current="page"><?php echo $i; ?></span>
                        <?php else: ?>
                        <a href="<?php echo htmlspecialchars(buildCartPageUrl($i), ENT_QUOTES, 'UTF-8'); ?>"
                            class="cart-pagination-link"><?php echo $i; ?></a>
                        <?php endif; ?>
                    </li>
                    <?php endfor; ?>

                    <li>
                        <?php if ($page < $totalPages): ?>
                        <a href="<?php echo htmlspecialchars(buildCartPageUrl($page + 1), ENT_QUOTES, 'UTF-8'); ?>"
                            class="cart-pagination-link">Next</a>
                        <?php else: ?>
                        <span class="cart-pagination-link disabled">Next</span>
                        <?php endif; ?>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>

            <div class="cart-summary">
                <div class="cart-summary-total">
                    <span class="cart-summary-label">Selected subtotal</span>
                    <span class="cart-summary-amount" id="cartSubtotal">₱0.00</span>
                </div>

                <div class="cart-summary-selection">
                    <span id="cartSelectionCount">0</span> of
                    <?php echo count($availableItems); ?> item(s) selected on this page.
                </div>

                <?php if (!$hasAvailable): ?>
                <p class="cart-summary-note">
                    Add at least one available item to proceed.
                </p>
                <?php endif; ?>

                <div class="cart-summary-actions">
                    <a href="menu.php" class="btn btn-secondary">Continue Shopping</a>

                    <?php if ($hasAvailable): ?>
                    <form method="POST" action="../backend/handlers/cart-handler.php" id="cartPushToQueueForm"
                        class="cart-push-form">
                        <input type="hidden" name="action" value="push_to_queue">
                        <input type="hidden" name="csrf_token"
                            value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <div id="cartSelectedIds"></div>
                        <button type="submit" class="btn btn-primary" id="cartAddToOrderBtn">
                            Add to Order
                        </button>
                    </form>
                    <?php else: ?>
                    <button type="button" class="btn btn-primary" disabled>Add to Order</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

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