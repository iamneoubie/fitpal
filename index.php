<?php
/**
 * FitPal Landing Page
 *
 * Public entry point for the FitPal platform.
 *
 * ---------------------------------------------------------------------
 * STANDALONE ARCHITECTURE
 * ---------------------------------------------------------------------
 * This page is intentionally decoupled from every role directory
 * (customer/, restaurant/, rider/, admin/). It only depends on:
 *
 *   - shared/includes/header.php        (session, nav, auth state, asset base)
 *   - shared/includes/footer.php        (footer markup)
 *   - shared/includes/view-helpers.php  (presentation helpers)
 *   - shared/backend/database/database-connect.php
 *   - shared/backend/database/landing-queries.php
 *
 * It contains NO SQL of its own. Every database call goes through
 * landing-queries.php, which lives under shared/ for the same reason.
 *
 * The asset base path ($assetBase) is provided by header.php. This page
 * does NOT compute its own path — one source of truth, no drift.
 *
 * Page-specific CSS (landing.css) is loaded by header.php via its
 * $pageCssMap. This page does NOT emit <link> tags for its own styles.
 * ---------------------------------------------------------------------
 *
 * @package FitPal
 * @version 3.2 — Removed inline CSS link and duplicated asset-base helper.
 */

declare(strict_types=1);

require_once __DIR__ . '/shared/includes/header.php';

// ---------------------------------------------------------------------
// CSRF TOKEN
// ---------------------------------------------------------------------
// The header starts the session but does not guarantee a token. The
// landing page renders a cart form for logged-in customers, so we make
// sure a token exists before rendering.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ---------------------------------------------------------------------
// DATA
// ---------------------------------------------------------------------
require_once __DIR__ . '/shared/backend/database/database-connect.php';
require_once __DIR__ . '/shared/backend/database/landing-queries.php';
require_once __DIR__ . '/shared/includes/view-helpers.php';

$featuredProducts = [];
$stats            = ['restaurants' => 0, 'products' => 0, 'customers' => 0];

try {
    $featuredProducts = getFeaturedProducts($database_connection, 5);
    $stats            = getPlatformStats($database_connection);
} catch (PDOException $e) {
    error_log('Landing page query error: ' . $e->getMessage());
}

$hasProducts = !empty($featuredProducts);
?>

<div class="content">

    <!-- ============================================
         HERO
         ============================================ -->
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
                    <a href="<?php echo $assetBase; ?>../<?php echo htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8'); ?>/pages/dashboard.php"
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
                        <span class="hero-stat-number"><?php echo number_format($stats['restaurants']); ?></span>
                        <span class="hero-stat-label">Restaurants</span>
                    </div>
                    <div class="hero-stat-divider"></div>
                    <div class="hero-stat">
                        <span class="hero-stat-number"><?php echo number_format($stats['products']); ?></span>
                        <span class="hero-stat-label">Meals</span>
                    </div>
                    <div class="hero-stat-divider"></div>
                    <div class="hero-stat">
                        <span class="hero-stat-number"><?php echo number_format($stats['customers']); ?></span>
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

    <!-- ============================================
         FEATURES
         ============================================ -->
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
                    <p class="feature-description">View calories, protein, carbs, and fats for every meal.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/equalizer-line.svg" alt="Dietary filters"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                    </div>
                    <p class="feature-title">Dietary Filters</p>
                    <p class="feature-description">Filter meals by vegan, keto, gluten-free, and other preferences.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/list-settings-fill.svg"
                            alt="Allergy management"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                    </div>
                    <p class="feature-title">Allergy Management</p>
                    <p class="feature-description">Set your allergies and get safe meal recommendations.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/contact-us-line.svg"
                            alt="Special instructions"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                    </div>
                    <p class="feature-title">Special Instructions</p>
                    <p class="feature-description">Add custom instructions that are communicated to the kitchen.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/time-update.svg" alt="Order tracking"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                    </div>
                    <p class="feature-title">Order Tracking</p>
                    <p class="feature-description">Track your orders from preparation to delivery.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/chart-line-up.svg"
                            alt="Nutrition analytics"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                    </div>
                    <p class="feature-title">Nutrition Analytics</p>
                    <p class="feature-description">View insights into your eating habits over time.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================
         HOW IT WORKS
         ============================================ -->
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
                    <p class="step-description">Sign up and set your dietary preferences and allergies.</p>
                </div>
                <div class="step-card">
                    <div class="step-number">2</div>
                    <div class="step-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/menu-search-fill.svg" alt="Browse menus"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                    </div>
                    <p class="step-title">Browse Menus</p>
                    <p class="step-description">Explore restaurants and filter meals based on your needs.</p>
                </div>
                <div class="step-card">
                    <div class="step-number">3</div>
                    <div class="step-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/location-fill.svg" alt="Place order"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                    </div>
                    <p class="step-title">Place Order</p>
                    <p class="step-description">Add meals to your cart, add instructions, and place your order.</p>
                </div>
                <div class="step-card">
                    <div class="step-number">4</div>
                    <div class="step-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/pages-line.svg" alt="Track delivery"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                    </div>
                    <p class="step-title">Track Order</p>
                    <p class="step-description">Track your order status from preparation to delivery.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================
         FEATURED MEALS
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
                    $productId          = (int)$product['id'];
                    $productName        = $product['name'] ?? 'Product';
                    $productPrice       = (float)($product['price'] ?? 0);
                    $productStock       = (int)($product['stock'] ?? 0);
                    $productCalories    = (int)($product['calories'] ?? 0);
                    $restaurantName     = $product['restaurant_name'] ?? '';
                    $branchName         = $product['branch_name'] ?? '';
                    $productDescription = $product['description'] ?? '';

                    $dietaryTags = parseTagList($product['dietary_tags'] ?? '');
                    $allergens   = parseTagList($product['allergens'] ?? '');

                    $productImage = !empty($product['product_image'])
                        ? htmlspecialchars($product['product_image'], ENT_QUOTES, 'UTF-8')
                        : $assetBase . 'assets/images/icons/restaurant.svg';
                ?>
                <div class="product-card" data-product-id="<?php echo $productId; ?>"
                    data-product-name="<?php echo htmlspecialchars($productName, ENT_QUOTES, 'UTF-8'); ?>"
                    data-product-price="<?php echo $productPrice; ?>" data-product-stock="<?php echo $productStock; ?>"
                    data-product-image="<?php echo $productImage; ?>"
                    data-restaurant-name="<?php echo htmlspecialchars($restaurantName, ENT_QUOTES, 'UTF-8'); ?>"
                    data-branch-name="<?php echo htmlspecialchars($branchName, ENT_QUOTES, 'UTF-8'); ?>">

                    <a href="<?php echo $assetBase; ?>../customer/pages/product-detail.php?id=<?php echo $productId; ?>"
                        class="product-image-link" onclick="event.stopPropagation();">
                        <div class="product-image">
                            <img src="<?php echo $productImage; ?>"
                                alt="<?php echo htmlspecialchars($productName, ENT_QUOTES, 'UTF-8'); ?>" loading="lazy"
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/restaurant.svg'">
                        </div>
                    </a>

                    <div class="product-info">
                        <a href="<?php echo $assetBase; ?>../customer/pages/product-detail.php?id=<?php echo $productId; ?>"
                            class="product-name-link" onclick="event.stopPropagation();">
                            <p class="heading-6">
                                <?php echo htmlspecialchars($productName, ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                        </a>

                        <p class="product-restaurant-name">
                            <?php echo htmlspecialchars($restaurantName, ENT_QUOTES, 'UTF-8'); ?>
                        </p>

                        <p class="product-description">
                            <?php echo htmlspecialchars(truncateText($productDescription, 70), ENT_QUOTES, 'UTF-8'); ?>
                        </p>

                        <div class="product-meta">
                            <span class="product-price"><?php echo formatPrice($productPrice); ?></span>
                            <?php if ($productCalories > 0): ?>
                            <span class="product-calories"><?php echo $productCalories; ?> kcal</span>
                            <?php endif; ?>
                        </div>

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

                    <!--
                        Product Actions

                        Posts to the CUSTOMER cart handler. The handler
                        expects `action=add` plus `csrf_token` and
                        `product_id`. We also send `total_price=0` because
                        the handler logs a warning when the client-supplied
                        total does not match the server-computed total. For
                        a plain add-from-landing-page with no customizations,
                        sending 0 skips that warning path.
                    -->
                    <div class="product-actions">
                        <?php if ($isLoggedIn && $productStock > 0): ?>
                        <form method="POST"
                            action="<?php echo $assetBase; ?>../customer/backend/handlers/cart-handler.php"
                            class="add-to-cart-form">
                            <input type="hidden" name="action" value="add">
                            <input type="hidden" name="csrf_token"
                                value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="product_id" value="<?php echo $productId; ?>">
                            <input type="hidden" name="total_price" value="0">
                            <div class="action-row">
                                <div class="quantity-control">
                                    <button type="button" class="qty-btn qty-minus"
                                        aria-label="Decrease quantity">&minus;</button>
                                    <input type="number" name="quantity" value="1" min="1"
                                        max="<?php echo $productStock; ?>" class="qty-input">
                                    <button type="button" class="qty-btn qty-plus"
                                        aria-label="Increase quantity">+</button>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm add-btn" aria-label="Add to cart">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/add-circle-empty.svg" alt=""
                                        class="btn-icon" width="18" height="18">
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

    <!-- ============================================
         CTA
         ============================================ -->
    <section class="cta-section" aria-labelledby="cta-title">
        <div class="container">
            <div class="cta-content">
                <p class="cta-title" id="cta-title">Find Meals That Match Your Diet</p>
                <p class="cta-description">Explore restaurants and filter by your dietary preferences.</p>
                <div class="cta-buttons">
                    <?php if ($isLoggedIn): ?>
                    <a href="<?php echo $assetBase; ?>../<?php echo htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8'); ?>/pages/dashboard.php"
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
require_once __DIR__ . '/shared/includes/footer.php';