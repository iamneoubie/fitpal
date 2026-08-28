<?php
/**
 * FitPal Product Detail Page
 *
 * Displays detailed information about a specific product.
 *
 * @package FitPal
 * @version 2.0 - Mobile-first redesign with SVG icons and back button
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ===== VALIDATE PRODUCT ID BEFORE INCLUDING HEADER =====
$productId = isset($_GET['id']) ? max(1, (int)$_GET['id']) : 0;

if ($productId <= 0) {
    header('Location: menu.php');
    exit;
}

// ===== INCLUDE HEADER =====
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../backend/database/product-queries.php';
require_once __DIR__ . '/../backend/database/order-queries.php';

// ===== FETCH PRODUCT DATA =====
try {
    $stmt = $database_connection->prepare(
        "SELECT 
            p.product_id,
            p.name AS product_name,
            p.description,
            p.price,
            p.stock,
            p.is_active,
            p.restaurant_branch_id,
            di.dietary_tags,
            di.allergens,
            di.calories,
            di.protein,
            di.carbs,
            di.fat,
            di.images AS product_image,
            rb.branch_name,
            rb.barangay,
            rb.city,
            rb.province,
            r.business_name AS restaurant_name,
            r.cuisine_type
        FROM product p
        JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
        JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
        LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
        WHERE p.product_id = :product_id
        AND p.is_active = 1"
    );
    $stmt->execute([':product_id' => $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        header('Location: menu.php');
        exit;
    }

    // Fetch related products
    $relatedStmt = $database_connection->prepare(
        "SELECT 
            p.product_id,
            p.name AS product_name,
            p.price,
            p.stock,
            di.dietary_tags,
            di.calories,
            di.images AS product_image
        FROM product p
        LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
        WHERE p.restaurant_branch_id = :branch_id
        AND p.product_id != :product_id
        AND p.is_active = 1
        LIMIT 4"
    );
    $relatedStmt->execute([
        ':branch_id' => $product['restaurant_branch_id'],
        ':product_id' => $productId
    ]);
    $relatedProducts = $relatedStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Product detail error: ' . $e->getMessage());
    header('Location: menu.php');
    exit;
}

$isLoggedIn = isset($_SESSION['customer_id']) && !empty($_SESSION['customer_id']);
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Parse dietary tags and allergens
$dietaryTags = !empty($product['dietary_tags']) ? explode(',', $product['dietary_tags']) : [];
$allergens = !empty($product['allergens']) ? explode(',', $product['allergens']) : [];

// Format price
$price = (float)($product['price'] ?? 0);
$formattedPrice = '&#8369;' . number_format($price, 2);

// Check if product is in stock
$inStock = (int)($product['stock'] ?? 0) > 0 && (int)($product['is_active'] ?? 0) === 1;

// Product image
$productImage = !empty($product['product_image']) 
    ? htmlspecialchars($product['product_image'], ENT_QUOTES, 'UTF-8')
    : $assetBase . 'assets/images/icons/restaurant.svg';
?>
<link rel="stylesheet" href="../assets/css/product-detail.css">

<div class="content product-detail-page">
    <div class="container">

        <!-- Flash Messages -->
        <?php if (isset($_SESSION['cart_success'])): ?>
        <div class="alert alert-success" role="alert">
            <svg class="alert-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                <polyline points="22 4 12 14.01 9 11.01" />
            </svg>
            <?php echo htmlspecialchars($_SESSION['cart_success'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['cart_success']); ?>
        </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['cart_error'])): ?>
        <div class="alert alert-danger" role="alert">
            <svg class="alert-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2">
                <circle cx="12" cy="12" r="10" />
                <line x1="12" y1="8" x2="12" y2="12" />
                <line x1="12" y1="16" x2="12.01" y2="16" />
            </svg>
            <?php echo htmlspecialchars($_SESSION['cart_error'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['cart_error']); ?>
        </div>
        <?php endif; ?>

        <!-- Back Button -->
        <div class="back-nav">
            <a href="menu.php<?php echo isset($_GET['restaurant_id']) ? '?restaurant_id=' . (int)$_GET['restaurant_id'] : ''; ?>"
                class="back-btn">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="15 18 9 12 15 6" />
                </svg>
                <span>Back to Menu</span>
            </a>
        </div>

        <!-- Product Detail -->
        <div class="product-detail-card">

            <!-- Product Image -->
            <div class="product-image-wrapper">
                <div class="product-image-container">
                    <img src="<?php echo $productImage; ?>"
                        alt="<?php echo htmlspecialchars($product['product_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                        class="product-image"
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/restaurant.svg'">
                    <?php if (!$inStock): ?>
                    <div class="product-badge out-of-stock-badge">Out of Stock</div>
                    <?php elseif ((int)($product['stock'] ?? 0) < 10): ?>
                    <div class="product-badge low-stock-badge">Only <?php echo (int)($product['stock'] ?? 0); ?> left
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Product Info -->
            <div class="product-info-section">
                <div class="product-header">
                    <h1 class="product-title">
                        <?php echo htmlspecialchars($product['product_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></h1>
                    <div class="product-restaurant">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                            <polyline points="9 22 9 12 15 12 15 22" />
                        </svg>
                        <a href="menu.php?restaurant_id=<?php echo (int)($product['restaurant_branch_id'] ?? 0); ?>">
                            <?php echo htmlspecialchars($product['restaurant_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                        <span class="separator">•</span>
                        <span><?php echo htmlspecialchars($product['branch_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </div>

                <!-- Price -->
                <div class="product-price-section">
                    <span class="product-price-large"><?php echo $formattedPrice; ?></span>
                </div>

                <!-- Description -->
                <?php if (!empty($product['description'])): ?>
                <div class="product-description">
                    <h3 class="section-label">Description</h3>
                    <p><?php echo nl2br(htmlspecialchars($product['description'], ENT_QUOTES, 'UTF-8')); ?></p>
                </div>
                <?php endif; ?>

                <!-- Nutritional Information -->
                <div class="product-nutrition">
                    <h3 class="section-label">Nutritional Information</h3>
                    <div class="nutrition-grid">
                        <div class="nutrition-item">
                            <span class="nutrition-value"><?php echo (int)($product['calories'] ?? 0); ?></span>
                            <span class="nutrition-label">Calories</span>
                        </div>
                        <div class="nutrition-item">
                            <span
                                class="nutrition-value"><?php echo number_format((float)($product['protein'] ?? 0), 1); ?>g</span>
                            <span class="nutrition-label">Protein</span>
                        </div>
                        <div class="nutrition-item">
                            <span
                                class="nutrition-value"><?php echo number_format((float)($product['carbs'] ?? 0), 1); ?>g</span>
                            <span class="nutrition-label">Carbs</span>
                        </div>
                        <div class="nutrition-item">
                            <span
                                class="nutrition-value"><?php echo number_format((float)($product['fat'] ?? 0), 1); ?>g</span>
                            <span class="nutrition-label">Fat</span>
                        </div>
                    </div>
                </div>

                <!-- Dietary Tags -->
                <?php if (!empty($dietaryTags)): ?>
                <div class="product-tags">
                    <h3 class="section-label">Dietary Tags</h3>
                    <div class="tags-container">
                        <?php foreach ($dietaryTags as $tag): ?>
                        <span class="tag dietary-tag">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <path
                                    d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z" />
                                <line x1="7" y1="7" x2="7.01" y2="7" />
                            </svg>
                            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $tag)), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Allergens -->
                <div class="product-allergens">
                    <h3 class="section-label">Allergens</h3>
                    <div class="tags-container">
                        <?php if (!empty($allergens)): ?>
                        <?php foreach ($allergens as $allergen): ?>
                        <span class="tag allergen-tag">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <circle cx="12" cy="12" r="10" />
                                <line x1="12" y1="8" x2="12" y2="12" />
                                <line x1="12" y1="16" x2="12.01" y2="16" />
                            </svg>
                            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $allergen)), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <span class="tag no-allergens">No allergens listed</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Add to Cart - Fixed Bottom -->
                <div class="add-to-cart-section">
                    <?php if ($isLoggedIn && $inStock): ?>
                    <form method="POST" action="../backend/handlers/add-to-cart-handler.php" class="add-to-cart-form">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="product_id" value="<?php echo $productId; ?>">
                        <input type="hidden" name="redirect" value="product-detail.php?id=<?php echo $productId; ?>">

                        <div class="quantity-control">
                            <button type="button" class="qty-btn qty-minus" aria-label="Decrease quantity">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2">
                                    <line x1="5" y1="12" x2="19" y2="12" />
                                </svg>
                            </button>
                            <input type="number" name="quantity" value="1" min="1"
                                max="<?php echo (int)($product['stock'] ?? 0); ?>" class="qty-input">
                            <button type="button" class="qty-btn qty-plus" aria-label="Increase quantity">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2">
                                    <line x1="12" y1="5" x2="12" y2="19" />
                                    <line x1="5" y1="12" x2="19" y2="12" />
                                </svg>
                            </button>
                        </div>

                        <button type="submit" class="btn btn-primary add-to-cart-btn">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <circle cx="9" cy="21" r="1" />
                                <circle cx="20" cy="21" r="1" />
                                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
                            </svg>
                            <span>Add to Cart</span>
                        </button>
                    </form>
                    <?php elseif (!$isLoggedIn): ?>
                    <a href="sign-in.php" class="btn btn-primary add-to-cart-btn">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
                            <polyline points="10 17 15 12 10 7" />
                            <line x1="15" y1="12" x2="3" y2="12" />
                        </svg>
                        <span>Login to Order</span>
                    </a>
                    <?php else: ?>
                    <button class="btn btn-disabled add-to-cart-btn" disabled>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <circle cx="12" cy="12" r="10" />
                            <line x1="12" y1="8" x2="12" y2="12" />
                            <line x1="12" y1="16" x2="12.01" y2="16" />
                        </svg>
                        <span>Out of Stock</span>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Related Products -->
        <?php if (!empty($relatedProducts)): ?>
        <section class="related-products">
            <h2 class="related-title">You might also like</h2>
            <div class="related-grid">
                <?php foreach ($relatedProducts as $related): ?>
                <a href="product-detail.php?id=<?php echo (int)$related['product_id']; ?>" class="related-card">
                    <div class="related-image">
                        <?php if (!empty($related['product_image'])): ?>
                        <img src="<?php echo htmlspecialchars($related['product_image'], ENT_QUOTES, 'UTF-8'); ?>"
                            alt="<?php echo htmlspecialchars($related['product_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            loading="lazy"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/restaurant.svg'">
                        <?php else: ?>
                        <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt="Restaurant icon"
                            loading="lazy">
                        <?php endif; ?>
                    </div>
                    <div class="related-info">
                        <p class="related-name">
                            <?php echo htmlspecialchars($related['product_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                        <p class="related-price">&#8369;<?php echo number_format((float)($related['price'] ?? 0), 2); ?>
                        </p>
                        <?php if (!empty($related['calories'])): ?>
                        <p class="related-calories"><?php echo (int)$related['calories']; ?> kcal</p>
                        <?php endif; ?>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

    </div>
</div>

<script src="../assets/ui/js/product-detail.js" defer></script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>