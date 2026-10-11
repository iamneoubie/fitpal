<?php
/**
 * FitPal Restaurant Products Page
 *
 * Owner-facing list of every product the restaurant has.
 *
 * ---------------------------------------------------------------------
 * LAYOUT
 * ---------------------------------------------------------------------
 * The grid's first cell is always an "Add Product" card. It uses
 * the exact same .product-card shell as every other product card:
 * an image area on top, an info column in the middle, and a single
 * full-width action button at the bottom. The image area shows the
 * restaurant icon instead of a product photo, and the info column
 * carries the heading and one-line description instead of a price
 * and stock.
 *
 * Because the add card uses the same shell, it occupies exactly one
 * grid track, at the same aspect ratio and the same internal
 * spacing as a product card. The row it lands in reads as a
 * uniform block, not as one wide call-to-action cell followed by
 * normal cards.
 *
 * ---------------------------------------------------------------------
 * TITLE HEADER
 * ---------------------------------------------------------------------
 * The page title sits at the right edge of its row. The back link
 * sits at the left. The two are pushed apart with
 * justify-content: space-between and the title is text-align: right
 * so on narrow screens it stays flush with the right column edge.
 *
 * ---------------------------------------------------------------------
 * MODALS
 * ---------------------------------------------------------------------
 * Three shared modals sit at the bottom of the page:
 *
 *   #editModal          confirmation before opening the editor
 *   #deleteModal        confirmation before deletion
 *   #notificationModal  response after a delete
 *
 * All three use the .modal / .modal-overlay / .modal-content shell
 * from products.css. The overlay is position: fixed, so opening a
 * modal never shifts the page beneath it.
 *
 * ---------------------------------------------------------------------
 * ICONS
 * ---------------------------------------------------------------------
 * Every icon referenced here exists under
 * shared/assets/images/icons/. No <svg> is written inline.
 *
 * @package FitPal
 * @version 3.0 — Add card uses the product-card shell with the
 *                restaurant icon. Title header anchored to the
 *                right. Grid columns driven by viewport width only.
 *
 *                (2.0: empty add card led the grid as a dashed
 *                tile. 1.0: initial version.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('restaurant');

if (empty($_SESSION['restaurant_account_id'])) {
    header('Location: sign-in.php');
    exit;
}

$accountScope = (string)($_SESSION['restaurant_scope'] ?? '');
$restaurantId = (int)($_SESSION['restaurant_id'] ?? 0);

if ($accountScope !== 'owner') {
    header('Location: dashboard.php');
    exit;
}

if ($restaurantId <= 0) {
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../backend/database/product-queries.php';

// $assetBase and $csrfToken are provided by header.php.

$products = [];
$loadError = '';

try {
    $products = getProductsByRestaurant($database_connection, $restaurantId);
} catch (PDOException $e) {
    error_log('Products page load error: ' . $e->getMessage());
    $loadError = 'Could not load products. Please try again.';
}

/* --------------------------------------------------------------
 * PAGE LOCALS
 * -------------------------------------------------------------- */

$projectRootUrl = preg_replace('#shared/$#', '', $assetBase);
if (!is_string($projectRootUrl)) {
    $projectRootUrl = '';
}

$productImageFallback = $assetBase . 'assets/images/icons/restaurant.svg';

$activeCount   = 0;
$inactiveCount = 0;
foreach ($products as $p) {
    if ((int)$p['is_active'] === 1) {
        $activeCount++;
    } else {
        $inactiveCount++;
    }
}

/**
 * Build a browser-loadable image URL for a product row.
 */
function ownerProductImageUrl(
    string $projectRootUrl,
    string $imageBase,
    string $primaryImage,
    string $fallbackUrl
): string {
    if ($projectRootUrl === '' || $imageBase === '' || $primaryImage === '') {
        return $fallbackUrl;
    }
    return $projectRootUrl . $imageBase . $primaryImage;
}

/**
 * Short display string for the dietary tags line.
 */
function ownerFormatTags(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    $parts = array_filter(
        array_map('trim', explode(',', $raw)),
        static fn(string $v): bool => $v !== '' && $v !== 'none'
    );

    if (empty($parts)) {
        return '';
    }

    $pretty = array_map(
        static fn(string $v): string => ucwords(str_replace('_', ' ', $v)),
        array_slice($parts, 0, 3)
    );

    $out = implode(' · ', $pretty);
    if (count($parts) > 3) {
        $out .= ' +' . (count($parts) - 3);
    }
    return $out;
}
?>
<div class="content restaurant-products-page" id="productsPage"
    data-csrf-token="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"
    data-handler-url="../backend/handlers/product-handler.php">

    <div class="container">

        <header class="page-title-header">
            <div class="page-title-header-top">
                <a href="dashboard.php" class="back-btn">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-left-line.svg" alt="Back"
                        class="back-btn-icon" width="20" height="20"
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/arrow-left-long-line.svg'">
                    <span>Back to Dashboard</span>
                </a>
                <h1>Products</h1>
            </div>
        </header>

        <?php if (!empty($loadError)): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($products)): ?>
        <nav class="filter-tabs" id="filterTabs" aria-label="Product filters">
            <span class="filter-tab active" data-filter="all">All (<?php echo count($products); ?>)</span>
            <span class="filter-tab" data-filter="active">Active (<?php echo $activeCount; ?>)</span>
            <span class="filter-tab" data-filter="inactive">Inactive (<?php echo $inactiveCount; ?>)</span>
        </nav>

        <div class="product-count" id="productCount">
            Showing all products (<?php echo count($products); ?>)
        </div>
        <?php endif; ?>

        <div class="products-grid" id="productsGrid">

            <!-- ============================================================
                 ADD PRODUCT CARD
                 Uses the same .product-card shell as every product card
                 so it occupies one grid track at the same aspect ratio.
                 ============================================================ -->
            <article class="product-card add-product-card" id="addProductCard" data-active="1">
                <div class="product-image-container">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt="Add product"
                        class="product-image"
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/package.svg'">
                </div>

                <div class="product-info">
                    <h3 class="product-title">Add a new product</h3>

                    <div class="product-meta-line">
                        <p class="product-stock">List a new item on your menu.</p>
                    </div>

                    <div class="product-badges">
                        <span class="product-badge badge-customizable">New</span>
                    </div>

                    <p class="product-tags-inline">
                        Add images, nutrition, and customization in one place.
                    </p>

                    <div class="product-actions">
                        <a href="product-edit.php" class="btn btn-primary add-product-btn">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/add-line.svg" alt="" class="btn-icon"
                                width="14" height="14"
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/add.svg'">
                            <span>Add Product</span>
                        </a>
                    </div>
                </div>
            </article>

            <?php foreach ($products as $product):
                $pid          = (int)$product['product_id'];
                $name         = (string)$product['name'];
                $price        = (float)$product['price'];
                $stock        = (int)$product['stock'];
                $isActive     = ((int)$product['is_active'] === 1);
                $isCustom     = ((int)$product['is_customizable'] === 1);
                $tagsDisplay  = ownerFormatTags((string)($product['dietary_tags'] ?? ''));

                $imageBase    = (string)($product['image_base'] ?? '');
                $primaryImage = (string)($product['primary_image'] ?? '');
                $imageUrl     = ownerProductImageUrl(
                    $projectRootUrl,
                    $imageBase,
                    $primaryImage,
                    $productImageFallback
                );

                $cardClass = 'product-card';
                if (!$isActive) {
                    $cardClass .= ' is-inactive';
                }
            ?>

            <article class="<?php echo $cardClass; ?>" data-product-id="<?php echo $pid; ?>"
                data-active="<?php echo $isActive ? '1' : '0'; ?>">

                <div class="product-image-container">
                    <img src="<?php echo htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8'); ?>"
                        alt="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>" class="product-image"
                        loading="lazy" onerror="this.onerror=null; this.src='<?php echo $productImageFallback; ?>'">
                </div>

                <div class="product-info">
                    <h3 class="product-title"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></h3>

                    <div class="product-meta-line">
                        <p class="product-price">₱<?php echo number_format($price, 2); ?></p>
                        <?php if ($stock <= 0): ?>
                        <p class="product-stock stock-out">Out of stock</p>
                        <?php else: ?>
                        <p class="product-stock">Stock: <?php echo $stock; ?></p>
                        <?php endif; ?>
                    </div>

                    <?php if ($isCustom || !$isActive || $stock <= 0): ?>
                    <div class="product-badges">
                        <?php if ($isCustom): ?>
                        <span class="product-badge badge-customizable">Customizable</span>
                        <?php endif; ?>
                        <?php if (!$isActive): ?>
                        <span class="product-badge badge-inactive">Inactive</span>
                        <?php endif; ?>
                        <?php if ($stock <= 0): ?>
                        <span class="product-badge badge-out">Out</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($tagsDisplay !== ''): ?>
                    <p class="product-tags-inline"
                        title="<?php echo htmlspecialchars($tagsDisplay, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($tagsDisplay, ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <?php endif; ?>

                    <div class="product-actions">
                        <button type="button" class="btn btn-primary edit-btn" data-product-id="<?php echo $pid; ?>"
                            data-product-name="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/edit.svg" alt="" class="btn-icon"
                                width="14" height="14"
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/update.svg'">
                            <span>Edit</span>
                        </button>
                        <button type="button" class="btn btn-danger delete-btn" data-product-id="<?php echo $pid; ?>"
                            data-product-name="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/trash.svg" alt="" class="btn-icon"
                                width="14" height="14" onerror="this.onerror=null; this.style.display='none';">
                            <span>Delete</span>
                        </button>
                    </div>
                </div>
            </article>

            <?php endforeach; ?>

        </div>

    </div>

    <!-- ============================================================
         EDIT CONFIRMATION MODAL
         ============================================================ -->
    <div class="modal" id="editModal" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
        <div class="modal-overlay" data-close-modal="edit-modal"></div>
        <div class="modal-content">
            <div class="modal-icon modal-icon-primary" aria-hidden="true">
                <img src="<?php echo $assetBase; ?>assets/images/icons/edit.svg" alt=""
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/update.svg'">
            </div>
            <h2 class="modal-title" id="editModalTitle">Edit this product?</h2>
            <p class="modal-message">
                You're about to open
                <strong id="editProductName">this product</strong>
                in the editor. Any changes you save will update the menu
                immediately.
            </p>
            <div class="modal-actions">
                <button type="button" class="modal-btn modal-btn-cancel" data-close-modal="edit-modal">
                    Cancel
                </button>
                <a href="#" class="modal-btn modal-btn-confirm" id="confirmEditBtn">
                    Continue to editor
                </a>
            </div>
        </div>
    </div>

    <!-- ============================================================
         DELETE CONFIRMATION MODAL
         ============================================================ -->
    <div class="modal" id="deleteModal" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
        <div class="modal-overlay" data-close-modal="delete-modal"></div>
        <div class="modal-content">
            <div class="modal-icon" aria-hidden="true">
                <img src="<?php echo $assetBase; ?>assets/images/icons/trash.svg" alt=""
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/information-fill.svg'">
            </div>
            <h2 class="modal-title" id="deleteModalTitle">Delete this product?</h2>
            <p class="modal-message">
                You're about to permanently delete
                <strong id="deleteProductName">this product</strong>.
                This removes it from your catalog and cannot be undone.
                Products with active orders cannot be deleted.
            </p>
            <div class="modal-actions">
                <button type="button" class="modal-btn modal-btn-cancel" data-close-modal="delete-modal">
                    Cancel
                </button>
                <button type="button" class="modal-btn modal-btn-danger" id="confirmDeleteBtn">
                    Yes, delete
                </button>
            </div>
        </div>
    </div>

    <!-- ============================================================
         NOTIFICATION MODAL
         ============================================================ -->
    <div class="modal" id="notificationModal" role="dialog" aria-modal="true" style="display: none;">
        <div class="modal-overlay" data-close-modal="notification-modal"></div>
        <div class="modal-content modal-content-narrow">
            <div class="modal-icon modal-icon-primary" aria-hidden="true">
                <img src="<?php echo $assetBase; ?>assets/images/icons/information-fill.svg" alt=""
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/mail.svg'">
            </div>
            <p class="modal-message" id="notificationMessage"></p>
            <div class="modal-actions modal-actions-single">
                <button type="button" class="modal-btn modal-btn-confirm" data-close-modal="notification-modal">
                    OK
                </button>
            </div>
        </div>
    </div>

</div>

<script>
window.FITPAL_PRODUCTS_CONFIG = {
    csrfToken: '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>',
    handlerUrl: '../backend/handlers/product-handler.php',
    assetBase: '<?php echo htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8'); ?>'
};
</script>
<script src="../assets/ui/js/products.js" defer></script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>