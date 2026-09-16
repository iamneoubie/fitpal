<?php
/**
 * FitPal Landing Page
 * 
 * This is the public entry point for the FitPal platform.
 * 
 * @package FitPal
 * @version 2.8 - Removed Browse Menu button from empty state
 */

declare(strict_types=1);

// Session is now handled by header.php
require_once __DIR__ . '/shared/includes/header.php';

/**
 * Get the base path for assets based on current file location
 */
function getLandingAssetBase(): string {
    $scriptPath = $_SERVER['SCRIPT_NAME'];
    $dirPath = dirname($scriptPath);
    $segments = array_filter(explode('/', $dirPath));
    $depth = count($segments);
    
    if ($depth <= 0) {
        return './shared/';
    }
    
    return str_repeat('../', $depth) . 'shared/';
}

$assetBase = getLandingAssetBase();

// ============================================
// DATABASE QUERY — Fetch random products (EXACT same as menu.php)
// ============================================

require_once __DIR__ . '/shared/backend/database/database-connect.php';

function getFeaturedProducts(PDO $db, int $limit = 8): array {
    $stmt = $db->prepare(
        "SELECT 
            p.product_id as id,
            p.name,
            p.description,
            p.price,
            p.stock,
            p.is_active,
            p.restaurant_branch_id,
            p.is_customizable,
            p.customization_type,
            p.base_price,
            rb.branch_name,
            rb.barangay,
            rb.city,
            rb.province,
            r.restaurant_id,
            r.business_name as restaurant_name,
            r.cuisine_type,
            COALESCE(di.dietary_tags, '') as dietary_tags,
            COALESCE(di.allergens, '') as allergens,
            di.calories,
            di.protein,
            di.carbs,
            di.fat,
            COALESCE(di.images, '') as product_image
        FROM product p
        JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
        JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
        LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
        WHERE p.is_active = 1 
        AND rb.is_active = 1 
        AND r.is_active = 1
        ORDER BY RAND()
        LIMIT :limit"
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// FETCH REAL DATA
// ============================================

$featuredProducts = [];

try {
    $featuredProducts = getFeaturedProducts($database_connection, 5);
    error_log('Featured products found: ' . count($featuredProducts));
} catch (PDOException $e) {
    error_log('Landing page query error: ' . $e->getMessage());
    $featuredProducts = [];
}

$hasProducts = !empty($featuredProducts);

// ============================================
// HELPER FUNCTIONS
// ============================================

function formatPrice($price): string {
    return '₱' . number_format((float)$price, 2);
}

function truncateText(string $text, int $length = 70): string {
    $text = trim($text);
    if (strlen($text) <= $length) {
        return $text;
    }
    return substr($text, 0, $length) . '...';
}

// ============================================
// HEADER DATA (from shared/includes/header.php)
// ============================================
?>
<!-- ============================================
    LANDING PAGE CONTENT
    ============================================ -->
<link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/landing.css">

<div class="content">

    <!-- Hero Section -->
    <section class="hero-section" aria-labelledby="hero-title">
        <div class="hero-container">
            <div class="hero-content">
                <p class="hero-title" id="hero-title">
                    Find Meals That Fit<br>
                    <span>Your Diet</span>
                </p>
                <p class="hero-description">
                    FitPal helps you find meals that match your dietary preferences.
                    Restaurants provide nutritional information and dietary tags for their menu items.
                </p>
                <div class="hero-actions">
                    <?php if ($isLoggedIn): ?>
                    <a href="<?php echo $assetBase; ?>../<?php echo $userRole; ?>/pages/dashboard.php"
                        class="btn btn-primary btn-lg">
                        Go to Dashboard
                    </a>
                    <?php else: ?>
                    <a href="<?php echo $assetBase; ?>../customer/pages/sign-up.php" class="btn btn-primary btn-lg">
                        Get Started
                    </a>
                    <a href="<?php echo $assetBase; ?>pages/about.php" class="btn btn-outline btn-lg">
                        Learn More
                    </a>
                    <?php endif; ?>
                </div>
                <div class="hero-stats">
                    <div class="hero-stat">
                        <span class="hero-stat-number">
                            <?php 
                                try {
                                    $countStmt = $database_connection->query("SELECT COUNT(*) FROM restaurant WHERE is_active = 1");
                                    echo number_format((int)$countStmt->fetchColumn());
                                } catch (PDOException $e) {
                                    echo '0';
                                }
                            ?>
                        </span>
                        <span class="hero-stat-label">Restaurants</span>
                    </div>
                    <div class="hero-stat-divider"></div>
                    <div class="hero-stat">
                        <span class="hero-stat-number">
                            <?php 
                                try {
                                    $countStmt = $database_connection->query("SELECT COUNT(*) FROM product WHERE is_active = 1");
                                    echo number_format((int)$countStmt->fetchColumn());
                                } catch (PDOException $e) {
                                    echo '0';
                                }
                            ?>
                        </span>
                        <span class="hero-stat-label">Meals</span>
                    </div>
                    <div class="hero-stat-divider"></div>
                    <div class="hero-stat">
                        <span class="hero-stat-number">
                            <?php 
                                try {
                                    $countStmt = $database_connection->query("SELECT COUNT(*) FROM customer WHERE is_active = 1");
                                    echo number_format((int)$countStmt->fetchColumn());
                                } catch (PDOException $e) {
                                    echo '0';
                                }
                            ?>
                        </span>
                        <span class="hero-stat-label">Active Users</span>
                    </div>
                </div>
            </div>
            <div class="hero-image">
                <img src="<?php echo $assetBase; ?>assets/images/showcase/hero-image.png"
                    alt="Healthy food ordering illustration" class="hero-illustration"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features-section" aria-labelledby="features-title">
        <div class="container">
            <div class="section-header">
                <p class="section-title" id="features-title">Platform <span>Features</span></p>
                <p class="section-subtitle">Tools to help you make informed food choices</p>
            </div>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/list-view.svg"
                            alt="Nutritional information"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                    </div>
                    <p class="feature-title">Nutritional Information</p>
                    <p class="feature-description">
                        View calories, protein, carbs, and fats for every meal.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/equalizer-line.svg" alt="Dietary filters"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                    </div>
                    <p class="feature-title">Dietary Filters</p>
                    <p class="feature-description">
                        Filter meals by vegan, keto, gluten-free, and other preferences.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/list-settings-fill.svg"
                            alt="Allergy management"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                    </div>
                    <p class="feature-title">Allergy Management</p>
                    <p class="feature-description">
                        Set your allergies and get safe meal recommendations.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/contact-us-line.svg"
                            alt="Special instructions"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                    </div>
                    <p class="feature-title">Special Instructions</p>
                    <p class="feature-description">
                        Add custom instructions that are communicated to the kitchen.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/time-update.svg" alt="Order tracking"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                    </div>
                    <p class="feature-title">Order Tracking</p>
                    <p class="feature-description">
                        Track your orders from preparation to delivery.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/chart-line-up.svg"
                            alt="Nutrition analytics"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                    </div>
                    <p class="feature-title">Nutrition Analytics</p>
                    <p class="feature-description">
                        View insights into your eating habits over time.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works Section -->
    <section class="how-it-works-section" aria-labelledby="howitworks-title">
        <div class="container">
            <div class="section-header">
                <p class="section-title" id="howitworks-title">How It <span>Works</span></p>
                <p class="section-subtitle">Simple steps to get started</p>
            </div>
            <div class="steps-grid">
                <div class="step-card">
                    <div class="step-number">1</div>
                    <div class="step-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/user-profile-circle.svg"
                            alt="Create account"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                    </div>
                    <p class="step-title">Create Account</p>
                    <p class="step-description">
                        Sign up and set your dietary preferences and allergies.
                    </p>
                </div>
                <div class="step-card">
                    <div class="step-number">2</div>
                    <div class="step-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/menu-search-fill.svg" alt="Browse menus"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                    </div>
                    <p class="step-title">Browse Menus</p>
                    <p class="step-description">
                        Explore restaurants and filter meals based on your needs.
                    </p>
                </div>
                <div class="step-card">
                    <div class="step-number">3</div>
                    <div class="step-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/location-fill.svg" alt="Place order"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                    </div>
                    <p class="step-title">Place Order</p>
                    <p class="step-description">
                        Add meals to your cart, add instructions, and place your order.
                    </p>
                </div>
                <div class="step-card">
                    <div class="step-number">4</div>
                    <div class="step-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/pages-line.svg" alt="Track delivery"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                    </div>
                    <p class="step-title">Track Order</p>
                    <p class="step-description">
                        Track your order status from preparation to delivery.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================
         FEATURED PRODUCTS SECTION — EXACT match to menu.php
         ============================================ -->
    <section class="featured-products-section" aria-labelledby="featured-products-title">
        <div class="container">
            <div class="section-header">
                <p class="section-title" id="featured-products-title">Featured <span>Meals</span></p>
                <p class="section-subtitle">
                    <?php if ($hasProducts): ?>
                    Discover popular meals from our restaurant partners
                    <?php else: ?>
                    Discover meals that match your dietary preferences
                    <?php endif; ?>
                </p>
            </div>

            <?php if ($hasProducts): ?>
            <div class="product-grid">
                <?php foreach ($featuredProducts as $product): 
                    // Cast all values to proper types
                    $productId = (int)$product['id'];
                    $productName = $product['name'] ?? 'Product';
                    $productPrice = (float)($product['price'] ?? 0);
                    $productStock = (int)($product['stock'] ?? 0);
                    $productCalories = (int)($product['calories'] ?? 0);
                    $restaurantName = $product['restaurant_name'] ?? '';
                    $branchName = $product['branch_name'] ?? '';
                    $productDescription = $product['description'] ?? '';
                    
                    $dietaryTags = !empty($product['dietary_tags']) 
                        ? array_map('trim', explode(',', $product['dietary_tags'])) 
                        : [];
                    $allergens = !empty($product['allergens']) 
                        ? array_map('trim', explode(',', $product['allergens'])) 
                        : [];
                    $productImage = !empty($product['product_image']) 
                        ? htmlspecialchars($product['product_image'], ENT_QUOTES, 'UTF-8')
                        : $assetBase . 'assets/images/icons/restaurant.svg';
                ?>
                <!-- PRODUCT CARD — EXACT match to menu.php -->
                <div class="product-card" data-product-id="<?php echo $productId; ?>"
                    data-product-name="<?php echo htmlspecialchars($productName, ENT_QUOTES, 'UTF-8'); ?>"
                    data-product-price="<?php echo $productPrice; ?>" data-product-stock="<?php echo $productStock; ?>"
                    data-product-image="<?php echo $productImage; ?>"
                    data-restaurant-name="<?php echo htmlspecialchars($restaurantName, ENT_QUOTES, 'UTF-8'); ?>"
                    data-branch-name="<?php echo htmlspecialchars($branchName, ENT_QUOTES, 'UTF-8'); ?>">

                    <!-- Product Image -->
                    <a href="<?php echo $assetBase; ?>../customer/pages/product-detail.php?id=<?php echo $productId; ?>"
                        class="product-image-link" onclick="event.stopPropagation();">
                        <div class="product-image">
                            <img src="<?php echo $productImage; ?>"
                                alt="<?php echo htmlspecialchars($productName, ENT_QUOTES, 'UTF-8'); ?>" loading="lazy"
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/restaurant.svg'">
                        </div>
                    </a>

                    <div class="product-info">
                        <!-- Product Name -->
                        <a href="<?php echo $assetBase; ?>../customer/pages/product-detail.php?id=<?php echo $productId; ?>"
                            class="product-name-link" onclick="event.stopPropagation();">
                            <p class="heading-6">
                                <?php echo htmlspecialchars($productName, ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                        </a>

                        <!-- Restaurant Name -->
                        <p class="product-restaurant-name">
                            <?php echo htmlspecialchars($restaurantName, ENT_QUOTES, 'UTF-8'); ?>
                        </p>

                        <!-- Description -->
                        <p class="product-description">
                            <?php echo htmlspecialchars(truncateText($productDescription, 70), ENT_QUOTES, 'UTF-8'); ?>
                        </p>

                        <!-- Price + Calories -->
                        <div class="product-meta">
                            <span class="product-price"><?php echo formatPrice($productPrice); ?></span>
                            <?php if ($productCalories > 0): ?>
                            <span class="product-calories"><?php echo $productCalories; ?> kcal</span>
                            <?php endif; ?>
                        </div>

                        <!-- Dietary Tags — EXACT match to menu.php -->
                        <?php if (!empty($dietaryTags)): ?>
                        <div class="product-tags-section">
                            <span class="tags-label">Dietary Tags:</span>
                            <div class="product-tags">
                                <?php foreach ($dietaryTags as $tag): ?>
                                <span class="tag dietary-tag">
                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $tag)), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Allergens — EXACT match to menu.php -->
                        <div class="product-allergens-section">
                            <span class="allergen-label">Allergens:</span>
                            <div class="product-allergens-tags">
                                <?php if (!empty($allergens)): ?>
                                <?php foreach ($allergens as $allergen): ?>
                                <span class="tag allergen-tag">
                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $allergen)), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <span class="tag-none">None</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Product Actions -->
                    <div class="product-actions">
                        <?php if ($isLoggedIn && $productStock > 0): ?>
                        <form method="POST"
                            action="<?php echo $assetBase; ?>../customer/backend/handlers/add-to-cart-handler.php"
                            class="add-to-cart-form" style="width: 100%;">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                            <input type="hidden" name="product_id" value="<?php echo $productId; ?>">
                            <div class="action-row">
                                <div class="quantity-control">
                                    <button type="button" class="qty-btn qty-minus"
                                        aria-label="Decrease quantity">−</button>
                                    <input type="number" name="quantity" value="1" min="1"
                                        max="<?php echo $productStock; ?>" class="qty-input">
                                    <button type="button" class="qty-btn qty-plus"
                                        aria-label="Increase quantity">+</button>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm add-btn" aria-label="Add to order">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/add-circle-empty.svg"
                                        alt="Add to order" class="btn-icon" width="18" height="18">
                                </button>
                            </div>
                        </form>
                        <?php elseif (!$isLoggedIn): ?>
                        <a href="<?php echo $assetBase; ?>../customer/pages/sign-in.php"
                            class="btn btn-outline btn-sm">Login to Order</a>
                        <?php else: ?>
                        <span class="btn btn-sm btn-disabled">Out of Stock</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="featured-products-action">
                <a href="<?php echo $assetBase; ?>../customer/pages/menu.php" class="btn btn-outline">
                    View All Meals
                </a>
            </div>
            <?php else: ?>
            <!-- FIXED: Empty State — no Browse Menu button -->
            <div class="empty-state featured-empty">
                <div class="empty-state-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt="No products available">
                </div>
                <p class="heading-4">No products available</p>
                <p class="text-muted">Check back later for featured meals.</p>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta-section" aria-labelledby="cta-title">
        <div class="container">
            <div class="cta-content">
                <p class="cta-title" id="cta-title">Find Meals That Match Your Diet</p>
                <p class="cta-description">
                    Explore restaurants and filter by your dietary preferences.
                </p>
                <div class="cta-buttons">
                    <?php if ($isLoggedIn): ?>
                    <a href="<?php echo $assetBase; ?>../<?php echo $userRole; ?>/pages/dashboard.php"
                        class="btn btn-primary btn-lg">
                        Go to Dashboard
                    </a>
                    <?php else: ?>
                    <a href="<?php echo $assetBase; ?>../customer/pages/sign-up.php" class="btn btn-primary btn-lg">
                        Get Started
                    </a>
                    <a href="<?php echo $assetBase; ?>../customer/pages/sign-in.php" class="btn btn-outline btn-lg">
                        Sign In
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

</div>

<?php
// Include shared footer
require_once __DIR__ . '/shared/includes/footer.php';
?>