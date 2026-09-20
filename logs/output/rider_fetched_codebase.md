# Web Project: fitpal

**Preset:** rider

**Generated:** 2026-09-20 00:34:54

---

## File: `fitpal/index.php`

**Status:** `FOUND`

```php
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
```

---

## File: `fitpal/rider/assets/css/dashboard.css`

**Status:** `FOUND`

```css
/**
 * FitPal Rider Dashboard Styles
 *
 * Minimal dashboard layout for the rider role. Uses the shared
 * global CSS variables.
 *
 * @package FitPal
 * @version 1.0
 */

/* ============================================
   CONTENT WRAPPER
   ============================================ */

.content {
    flex: 1;
    display: flex;
    flex-direction: column;
    width: 100%;
    padding: 0;
    min-height: calc(100vh - 70px);
}

.rider-dashboard-page {
    background: var(--gray-50);
    padding: 24px 0 32px 0;
    flex: 1;
}

.rider-dashboard-page .container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 16px;
    width: 100%;
}

/* ============================================
   HEADER
   ============================================ */

.rider-dashboard-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
}

.rider-dashboard-header .heading-2 {
    margin: 0 0 4px 0;
    font-size: var(--font-size-3xl);
    line-height: 1.2;
}

.rider-dashboard-header .heading-2 span {
    color: var(--primary);
}

.rider-dashboard-header .text-muted {
    color: var(--gray-500);
    margin: 0;
    font-size: var(--font-size-sm);
}

.rider-dashboard-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
}

/* ============================================
   STAT CARDS
   ============================================ */

.rider-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.rider-stat-card {
    display: flex;
    align-items: center;
    gap: 14px;
    background: var(--white);
    border-radius: var(--radius-lg);
    padding: 18px 20px;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--gray-100);
    text-decoration: none;
    color: inherit;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}

a.rider-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.rider-stat-icon {
    width: 46px;
    height: 46px;
    border-radius: var(--radius-base);
    background: rgba(89, 193, 74, 0.12);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.rider-stat-icon img {
    width: 22px;
    height: 22px;
    display: block;
    filter: brightness(0) saturate(100%) invert(48%) sepia(70%) saturate(450%) hue-rotate(75deg) brightness(95%) contrast(85%);
}

.rider-stat-info {
    min-width: 0;
}

.rider-stat-number {
    font-size: var(--font-size-xl);
    font-weight: var(--font-weight-bold);
    color: var(--text);
    margin: 0;
    line-height: 1.2;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.rider-stat-label {
    font-size: var(--font-size-xs);
    color: var(--gray-500);
    margin: 2px 0 0 0;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    font-weight: var(--font-weight-medium);
}

/* ============================================
   CARD BLOCKS
   ============================================ */

.rider-card {
    background: var(--white);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--gray-100);
    margin-bottom: 16px;
    overflow: hidden;
}

.rider-card-header {
    padding: 14px 20px;
    border-bottom: 1px solid var(--gray-100);
    background: var(--gray-50);
}

.rider-card-header .heading-5 {
    margin: 0;
    font-size: var(--font-size-base);
    font-weight: var(--font-weight-semibold);
}

.rider-card-body {
    padding: 18px 20px;
}

.rider-card-warning {
    border-color: #ffe8a1;
    background: #fffdf5;
}

.rider-warning-title {
    margin: 0 0 4px 0;
    font-weight: var(--font-weight-semibold);
    color: #856404;
}

.rider-availability-line {
    margin: 0 0 4px 0;
    font-size: var(--font-size-base);
}

.rider-availability-line strong {
    font-weight: var(--font-weight-bold);
}

.rider-available {
    color: var(--primary-dark);
}

.rider-unavailable {
    color: var(--danger);
}

.rider-availability-hint {
    margin: 0;
    font-size: var(--font-size-sm);
}

/* ============================================
   BADGES
   ============================================ */

.badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 4px 12px;
    border-radius: var(--radius-full);
    font-size: var(--font-size-xs);
    font-weight: var(--font-weight-semibold);
    line-height: 1;
    white-space: nowrap;
    min-height: 24px;
}

.badge-success {
    background: #d4edda;
    color: #155724;
}

.badge-warning {
    background: #fff3cd;
    color: #856404;
}

.badge-danger {
    background: #f8d7da;
    color: #721c24;
}

.badge-secondary {
    background: var(--gray-200);
    color: var(--gray-600);
}

/* ============================================
   RESPONSIVE
   ============================================ */

@media (max-width: 992px) {
    .rider-stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 480px) {
    .rider-dashboard-page {
        padding: 16px 0 24px 0;
    }

    .rider-dashboard-header .heading-2 {
        font-size: var(--font-size-2xl);
    }

    .rider-stats-grid {
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }

    .rider-stat-card {
        flex-direction: column;
        align-items: flex-start;
        padding: 14px;
        gap: 10px;
    }

    .rider-stat-number {
        font-size: var(--font-size-base);
    }

    .rider-stat-label {
        font-size: 10px;
    }

    .rider-stat-icon {
        width: 40px;
        height: 40px;
    }

    .rider-stat-icon img {
        width: 18px;
        height: 18px;
    }
}
```

---

## File: `fitpal/rider/assets/css/header.css`

**Status:** `FOUND`

```css
/**
 * FitPal Rider Header Styles
 *
 * Rider-specific header overrides. Uses global CSS variables from
 * global.css. Deliberately excludes the .nav-badge rule — riders
 * do not have a cart.
 *
 * @package FitPal
 * @version 1.1 — Retains parity with customer header.
 */

/* ============================================
   RIDER HEADER OVERRIDES
   ============================================ */

.rider-header {
    height: 70px;
    min-height: 70px;
    max-height: 70px;
}

.rider-header .header-container {
    max-width: 1200px;
    margin: 0 auto;
    height: 100%;
}

/* ============================================
   NAVIGATION LINKS
   ============================================ */

.rider-header .nav-link {
    font-weight: 500;
    color: var(--gray-600);
    padding: var(--spacing-2) var(--spacing-3);
    border-radius: var(--radius-base);
    transition: all var(--transition-fast);
    position: relative;
    font-size: var(--font-size-sm);
}

.rider-header .nav-link:hover {
    color: var(--primary);
    background-color: var(--gray-50);
}

.rider-header .nav-link.active {
    color: var(--primary);
    background-color: rgba(89, 193, 74, 0.1);
}

.rider-header .nav-link.active::after {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 50%;
    transform: translateX(-50%);
    width: 20px;
    height: 3px;
    background-color: var(--primary);
    border-radius: var(--radius-full);
}

/* ============================================
   BUTTONS — match shared header exactly
   ============================================ */

.rider-header .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--spacing-2);
    padding: var(--spacing-2) var(--spacing-4);
    border-radius: var(--radius-base);
    font-weight: var(--font-weight-medium);
    font-size: var(--font-size-sm);
    line-height: var(--line-height-normal);
    text-align: center;
    text-decoration: none;
    transition: all var(--transition-fast);
    border: 2px solid transparent;
    cursor: pointer;
    white-space: nowrap;
}

.rider-header .btn-sm {
    padding: var(--spacing-1) var(--spacing-3);
    font-size: var(--font-size-xs);
    min-height: 32px;
}

.rider-header .btn-primary {
    background-color: var(--primary);
    color: var(--white);
    border-color: var(--primary);
}

.rider-header .btn-primary:hover,
.rider-header .btn-primary:focus {
    background-color: var(--primary-dark);
    border-color: var(--primary-dark);
    color: var(--white);
}

.rider-header .btn-outline {
    background-color: transparent;
    color: var(--text);
    border-color: var(--gray-300);
}

.rider-header .btn-outline:hover,
.rider-header .btn-outline:focus {
    background-color: var(--gray-50);
    color: var(--text);
    border-color: var(--gray-400);
}

/* ============================================
   NAV ACTIONS
   ============================================ */

.rider-header .nav-actions {
    display: flex;
    align-items: center;
    gap: var(--spacing-3);
}

.rider-header .logout-btn {
    font-size: var(--font-size-xs);
    padding: var(--spacing-1) var(--spacing-3);
    min-height: 32px;
}

/* ============================================
   MOBILE MENU — matches shared header
   ============================================ */

.menu-toggle {
    display: none;
    flex-direction: column;
    gap: 5px;
    padding: var(--spacing-2);
    border-radius: var(--radius-base);
    background: none;
    border: none;
    cursor: pointer;
    position: relative;
    z-index: 1001;
    touch-action: manipulation;
    -webkit-tap-highlight-color: transparent;
}

.menu-toggle:hover {
    background: var(--gray-50);
}

.menu-icon {
    display: flex;
    flex-direction: column;
    gap: 5px;
    width: 24px;
}

.menu-icon .bar {
    display: block;
    height: 2px;
    width: 100%;
    background: var(--text);
    border-radius: var(--radius-full);
    transition: transform 0.15s ease, opacity 0.15s ease;
    transform-origin: center;
}

.menu-toggle.active .bar:nth-child(1) {
    transform: translateY(7px) rotate(45deg);
}

.menu-toggle.active .bar:nth-child(2) {
    opacity: 0;
    transform: scaleX(0);
}

.menu-toggle.active .bar:nth-child(3) {
    transform: translateY(-7px) rotate(-45deg);
}

/* Mobile overlay */
.mobile-overlay {
    position: fixed;
    top: 70px;
    left: 0;
    width: 100%;
    height: calc(100vh - 70px);
    background: rgba(0, 0, 0, 0.3);
    z-index: var(--z-modal-backdrop);
    opacity: 0;
    transition: opacity 0.15s ease;
    pointer-events: none;
    transform: translateZ(0);
}

.mobile-overlay.active {
    opacity: 1;
    pointer-events: auto;
    will-change: opacity;
}

/* Mobile nav */
.mobile-nav {
    position: fixed;
    top: 70px;
    right: 0;
    width: 320px;
    max-width: 85%;
    height: calc(100vh - 70px);
    background: var(--white);
    z-index: var(--z-modal);
    padding: var(--spacing-6) var(--spacing-4);
    overflow-y: auto;
    transform: translateX(100%) translateZ(0);
    transition: transform 0.15s cubic-bezier(0.4, 0, 0.2, 1);
    backface-visibility: hidden;
    contain: layout;
}

.mobile-nav::before {
    content: '';
    position: absolute;
    top: 0;
    left: -30px;
    width: 30px;
    height: 100%;
    box-shadow: -30px 0 30px rgba(0, 0, 0, 0.08);
    pointer-events: none;
    transform: translateX(0);
}

.mobile-nav.open {
    transform: translateX(0) translateZ(0);
    will-change: transform;
}

.mobile-nav::-webkit-scrollbar {
    display: none;
}

.mobile-nav {
    -ms-overflow-style: none;
    scrollbar-width: none;
}

.mobile-nav-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.mobile-nav-item {
    margin: 0;
    padding: 0;
}

.mobile-nav-link {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--spacing-3) var(--spacing-4);
    color: var(--gray-700);
    font-weight: 500;
    font-size: var(--font-size-base);
    border-radius: var(--radius-base);
    transition: background 0.1s ease, color 0.1s ease;
    text-decoration: none;
    text-align: center;
    width: 100%;
}

.mobile-nav-link:hover {
    background: var(--gray-50);
    color: var(--primary);
}

.mobile-nav-link.active {
    color: var(--primary);
    background: rgba(89, 193, 74, 0.1);
}

.mobile-nav-divider {
    height: 1px;
    background: linear-gradient(to right, transparent, var(--gray-200) 20%, var(--gray-200) 80%, transparent);
    margin: var(--spacing-3) var(--spacing-2);
}

/* ============================================
   MOBILE USER GREETING
   ============================================ */

.mobile-user-greeting {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--spacing-3);
    padding: var(--spacing-3) var(--spacing-4);
    background: var(--gray-50);
    border-radius: var(--radius-base);
    width: 100%;
}

.mobile-user-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.mobile-user-avatar .user-initial-large {
    color: var(--white);
    font-weight: 700;
    font-size: 20px;
    text-transform: uppercase;
}

.mobile-user-avatar img {
    width: 24px;
    height: 24px;
    filter: brightness(0) saturate(100%) invert(100%);
}

.mobile-user-name {
    font-weight: 600;
    font-size: var(--font-size-base);
    color: var(--text);
}

/* ============================================
   MOBILE NAV — special links
   ============================================ */

.mobile-nav-link.mobile-login {
    color: var(--primary);
    font-weight: 600;
    background-color: rgba(89, 193, 74, 0.08);
    margin-top: var(--spacing-1);
}

.mobile-nav-link.mobile-login:hover {
    background-color: rgba(89, 193, 74, 0.16);
    color: var(--primary-dark);
}

.mobile-nav-link.mobile-logout {
    color: var(--danger);
    font-weight: 600;
    background-color: rgba(220, 53, 69, 0.08);
    margin-top: var(--spacing-1);
}

.mobile-nav-link.mobile-logout:hover {
    background-color: rgba(220, 53, 69, 0.16);
    color: var(--danger);
}

/* ============================================
   USER PROFILE CIRCLE (Desktop)
   ============================================ */

.user-profile-circle {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--primary);
    color: var(--white);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 16px;
    flex-shrink: 0;
    border: 2px solid var(--primary);
    transition: all var(--transition-fast);
    cursor: default;
}

.user-profile-circle:hover {
    border-color: var(--primary-dark);
    transform: scale(1.05);
}

.user-profile-circle .user-initial {
    color: var(--white);
    font-weight: 700;
    font-size: 16px;
    text-transform: uppercase;
}

.user-profile-circle .profile-icon {
    width: 20px;
    height: 20px;
    filter: brightness(0) saturate(100%) invert(100%);
}

/* ============================================
   RESPONSIVE
   ============================================ */

@media (max-width: 992px) {
    .rider-header .header-nav {
        display: none;
    }

    .menu-toggle {
        display: flex;
    }
}

@media (max-width: 768px) {
    .rider-header {
        height: 60px;
        min-height: 60px;
        max-height: 60px;
    }

    .rider-header .logo-text {
        font-size: var(--font-size-base);
    }

    .rider-header .logo-image {
        height: 32px;
    }

    .mobile-nav {
        top: 60px;
        height: calc(100vh - 60px);
        width: 280px;
        padding: var(--spacing-4) var(--spacing-3);
    }

    .mobile-overlay {
        top: 60px;
        height: calc(100vh - 60px);
    }
}

@media (max-width: 576px) {
    .mobile-user-avatar {
        width: 38px;
        height: 38px;
    }

    .mobile-user-avatar .user-initial-large {
        font-size: 17px;
    }

    .mobile-user-name {
        font-size: var(--font-size-sm);
    }

    .mobile-nav {
        width: 100%;
        max-width: 100%;
        padding: var(--spacing-4) var(--spacing-3);
    }

    .mobile-nav-link {
        padding: var(--spacing-3) var(--spacing-3);
        font-size: var(--font-size-base);
    }
}
```

---

## File: `fitpal/rider/assets/css/sign-in.css`

**Status:** `FOUND`

```css
/**
 * FitPal Rider Sign-In Styles
 *
 * Foodpanda-style auth card rendered inside the standard FitPal
 * page shell. All icons come from shared/assets/images/icons/.
 *
 * Changes in this version:
 *   - Illustration is now clipped and centered inside a fixed-height
 *     band so it never overflows the card's top corners.
 *   - Region indicator is static: Philippines only.
 *   - Password field has a visibility toggle (eye icon).
 *
 * @package FitPal
 * @version 6.0 — Illustration fits the card; bleed-through removed.
 */

/* ============================================
   CONTENT WRAPPER
   ============================================ */

.rider-auth-content-wrap {
    flex: 1;
    display: flex;
    flex-direction: column;
    width: 100%;
    padding: 0;
    min-height: calc(100vh - 70px);
    background: linear-gradient(135deg, var(--gray-50) 0%, var(--white) 100%);
}

.rider-auth-page {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 28px 16px;
    box-sizing: border-box;
    width: 100%;
}

/* ============================================
   AUTH CARD
   ============================================ */

.rider-auth-card {
    width: 100%;
    max-width: 420px;
    background: var(--white);
    border-radius: var(--radius-xl);
    /*
     * overflow: hidden is what clips the illustration cleanly to the
     * card's rounded top corners. Without it the image renders as a
     * hard-edged rectangle that breaks the card's shape.
     */
    overflow: hidden;
    box-shadow: var(--shadow-lg);
    border: 1px solid var(--gray-200);
    display: flex;
    flex-direction: column;
}

/* ============================================
   ILLUSTRATION — fitted band at the top of the card
   ============================================ */

.rider-illustration {
    position: relative;
    width: 100%;
    height: 160px;
    flex-shrink: 0;
    background: var(--gray-50);
    overflow: hidden;
    line-height: 0;
    /*
     * The card already declares overflow: hidden, but we repeat it
     * here so the image is clipped even if the card rule is ever
     * relaxed. Two independent clips, one purpose.
     */
}

.rider-illustration img {
    display: block;
    width: 100%;
    height: 100%;
    /*
     * `contain` shows the entire illustration, scaled down to fit
     * inside the band without cropping. If you prefer a "fill the
     * band edge to edge" look, swap this for `cover`. Either way
     * the band's fixed height and the card's overflow: hidden
     * guarantee the image never spills past the card corners.
     */
    object-fit: contain;
    object-position: center center;
    /*
     * Padding keeps the illustration from touching the band edges.
     * Drop the vertical padding if you want the artwork to bleed to
     * the top of the card.
     */
    padding: 16px 24px 0 24px;
    box-sizing: border-box;
}

/* ============================================
   CONTENT AREA
   ============================================ */

.rider-auth-content {
    padding: 20px 24px 20px 24px;
}

.rider-auth-title {
    margin: 0 0 20px 0;
    font-size: var(--font-size-2xl);
    font-weight: var(--font-weight-bold);
    line-height: 1.3;
    color: var(--text);
}

.rider-auth-title span {
    color: var(--primary);
}

/* ============================================
   REGION INDICATOR (static, Philippines only)
   ============================================ */

.rider-country-selector {
    display: flex;
    align-items: center;
    gap: 12px;
    height: 52px;
    padding: 0 16px;
    margin-bottom: 16px;
    border: 1.5px solid var(--gray-200);
    border-radius: var(--radius-base);
    background: var(--gray-50);
    box-sizing: border-box;
    cursor: default;
    user-select: none;
}

.rider-country-icon {
    width: 18px;
    height: 18px;
    display: block;
    flex-shrink: 0;
    filter: brightness(0) saturate(100%) invert(48%) sepia(70%) saturate(450%) hue-rotate(75deg) brightness(95%) contrast(85%);
}

.rider-country-name {
    flex: 1;
    min-width: 0;
    font-size: var(--font-size-base);
    font-weight: var(--font-weight-semibold);
    color: var(--text);
}

.rider-chevron-icon {
    width: 18px;
    height: 18px;
    display: block;
    flex-shrink: 0;
    opacity: 0.85;
    pointer-events: none;
    filter: brightness(0) saturate(100%) invert(48%) sepia(70%) saturate(450%) hue-rotate(75deg) brightness(95%) contrast(85%);
}

/* ============================================
   FORM FIELDS
   ============================================ */

.rider-form-group {
    margin-bottom: 16px;
}

.rider-form-control {
    width: 100%;
    height: 52px;
    padding: 0 16px;
    border: 1.5px solid var(--gray-300);
    border-radius: var(--radius-base);
    font-size: var(--font-size-base);
    font-family: inherit;
    color: var(--text);
    background: var(--white);
    box-sizing: border-box;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.rider-form-control::placeholder {
    color: var(--gray-400);
}

.rider-form-control:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 4px rgba(89, 193, 74, 0.15);
}

.rider-form-control.is-error {
    border-color: var(--danger);
}

/* ============================================
   PASSWORD FIELD WITH TOGGLE
   ============================================ */

.rider-password-wrapper {
    position: relative;
    width: 100%;
}

.rider-password-wrapper .rider-form-control {
    padding-right: 52px;
}

.rider-password-toggle {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    padding: 0;
    border: none;
    border-radius: var(--radius-base);
    background: transparent;
    cursor: pointer;
    opacity: 0.55;
    transition: opacity 0.15s ease, background-color 0.15s ease;
}

.rider-password-toggle:hover {
    opacity: 1;
    background: rgba(0, 0, 0, 0.05);
}

.rider-password-toggle:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
    opacity: 1;
}

.rider-password-toggle img {
    display: block;
    width: 20px;
    height: 20px;
    pointer-events: none;
}

/* ============================================
   BUTTONS
   ============================================ */

.rider-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 52px;
    padding: 0 20px;
    border-radius: var(--radius-full);
    font-size: var(--font-size-base);
    font-weight: var(--font-weight-bold);
    font-family: inherit;
    text-decoration: none;
    cursor: pointer;
    border: none;
    box-sizing: border-box;
    transition: background-color 0.15s ease, transform 0.1s ease;
}

.rider-btn:active {
    transform: scale(0.98);
}

.rider-btn-dark {
    background: var(--text);
    color: var(--white);
    margin-bottom: 12px;
}

.rider-btn-dark:hover {
    background: var(--black);
}

.rider-btn-light {
    background: var(--gray-100);
    color: var(--text);
}

.rider-btn-light:hover {
    background: var(--gray-200);
}

/* ============================================
   ALERTS
   ============================================ */

.rider-alert {
    padding: 12px 16px;
    border-radius: var(--radius-base);
    font-size: var(--font-size-sm);
    line-height: 1.5;
    margin-bottom: 16px;
    border: 1px solid transparent;
}

.rider-alert-danger {
    color: #721c24;
    background-color: #f8d7da;
    border-color: #f5c6cb;
}

.rider-alert-success {
    color: #155724;
    background-color: #d4edda;
    border-color: #c3e6cb;
}

/* ============================================
   VERSION FOOTER
   ============================================ */

.rider-version {
    text-align: center;
    padding: 0 24px 20px 24px;
    font-size: var(--font-size-xs);
    color: var(--gray-400);
}

/* ============================================
   UTILITIES
   ============================================ */

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

/* ============================================
   RESPONSIVE — TABLET
   ============================================ */

@media (max-width: 768px) {
    .rider-auth-page {
        padding: 20px 12px;
    }

    .rider-auth-card {
        border-radius: var(--radius-lg);
    }

    .rider-illustration {
        height: 140px;
    }

    .rider-illustration img {
        padding: 12px 20px 0 20px;
    }

    .rider-auth-content {
        padding: 18px 18px 16px 18px;
    }

    .rider-auth-title {
        font-size: var(--font-size-xl);
        margin-bottom: 18px;
    }

    .rider-country-selector,
    .rider-form-control,
    .rider-btn {
        height: 48px;
        font-size: var(--font-size-sm);
    }

    .rider-country-icon,
    .rider-chevron-icon {
        width: 16px;
        height: 16px;
    }

    .rider-password-toggle {
        width: 32px;
        height: 32px;
        right: 8px;
    }

    .rider-password-toggle img {
        width: 18px;
        height: 18px;
    }

    .rider-password-wrapper .rider-form-control {
        padding-right: 46px;
    }
}

/* ============================================
   RESPONSIVE — SMALL MOBILE
   ============================================ */

@media (max-width: 400px) {
    .rider-auth-page {
        padding: 12px 8px;
    }

    .rider-illustration {
        height: 120px;
    }

    .rider-illustration img {
        padding: 10px 16px 0 16px;
    }

    .rider-auth-content {
        padding: 16px 14px 12px 14px;
    }

    .rider-country-selector,
    .rider-form-control,
    .rider-btn {
        height: 44px;
    }
}
```

---

## File: `fitpal/rider/assets/css/sign-up.css`

**Status:** `FOUND`

```css
/**
 * FitPal Rider Sign-Up Styles
 *
 * Self-contained stylesheet for the rider application flow. Does not
 * depend on the customer sign-up stylesheet. Uses only the shared
 * global CSS variables from global.css.
 *
 * @package FitPal
 * @version 2.0 — Professional layout, review sections, emergency
 *                contact block, tighter responsive behavior.
 */

/* ============================================
   PAGE SHELL
   ============================================ */

.content {
    flex: 1;
    display: flex;
    flex-direction: column;
    width: 100%;
    padding: 0;
    min-height: calc(100vh - 70px);
}

.register-page {
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 32px 16px 48px 16px;
    background: linear-gradient(135deg, var(--gray-50) 0%, var(--white) 100%);
    flex: 1;
    min-height: calc(100vh - 70px);
}

.register-page .container {
    max-width: 760px;
    margin: 0 auto;
    padding: 0;
    width: 100%;
}

/* ============================================
   CARD
   ============================================ */

.register-card {
    width: 100%;
    background: var(--white);
    border-radius: var(--radius-xl);
    padding: 36px 32px 28px 32px;
    box-shadow: var(--shadow-lg);
    border: 1px solid var(--gray-200);
}

/* ============================================
   PROGRESS
   ============================================ */

.register-progress {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 32px;
    padding: 0 4px;
}

.progress-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    flex: 0 0 auto;
    min-width: 60px;
}

.progress-step .step-number {
    width: 36px;
    height: 36px;
    border-radius: var(--radius-full);
    background: var(--gray-200);
    color: var(--gray-500);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: var(--font-weight-bold);
    font-size: var(--font-size-sm);
    transition: all var(--transition-base);
    line-height: 1;
}

.progress-step.active .step-number {
    background: var(--primary);
    color: var(--white);
    box-shadow: 0 0 0 4px rgba(89, 193, 74, 0.2);
}

.progress-step.completed .step-number {
    background: var(--primary);
    color: var(--white);
}

.progress-step .step-label {
    font-size: 11px;
    color: var(--gray-400);
    text-align: center;
    font-weight: var(--font-weight-semibold);
    text-transform: uppercase;
    letter-spacing: 0.4px;
    line-height: 1;
}

.progress-step.active .step-label,
.progress-step.completed .step-label {
    color: var(--primary);
}

.progress-line {
    flex: 1 1 auto;
    height: 2px;
    background: var(--gray-200);
    margin: 0 6px;
    position: relative;
    top: -14px;
    transition: background var(--transition-base);
}

.progress-line.completed {
    background: var(--primary);
}

/* ============================================
   HEADER
   ============================================ */

.register-header {
    text-align: center;
    margin-bottom: 28px;
}

.register-header .heading-2 {
    margin: 0 0 6px 0;
    color: var(--text);
    font-size: var(--font-size-3xl);
    line-height: 1.2;
}

.register-header .heading-2 span {
    color: var(--primary);
}

.register-header .text-muted {
    color: var(--gray-500);
    font-size: var(--font-size-sm);
    margin: 0;
}

/* ============================================
   STEPS
   ============================================ */

.register-step {
    animation: riderStepFade 0.3s ease;
}

@keyframes riderStepFade {
    from {
        opacity: 0;
        transform: translateY(8px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.step-description {
    margin-bottom: 22px;
    color: var(--gray-600);
    font-size: var(--font-size-sm);
    line-height: 1.6;
    padding: 12px 16px;
    background: var(--gray-50);
    border-radius: var(--radius-base);
    border-left: 3px solid var(--primary);
}

.step-description p {
    margin: 0;
}

.section-divider {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 24px 0 16px 0;
    color: var(--gray-500);
    font-size: var(--font-size-xs);
    font-weight: var(--font-weight-semibold);
    text-transform: uppercase;
    letter-spacing: 0.6px;
}

.section-divider::before,
.section-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--gray-200);
}

/* ============================================
   FORM ELEMENTS
   ============================================ */

.register-form .form-group {
    margin-bottom: 16px;
    flex: 1;
    min-width: 0;
}

.register-form .form-row {
    display: flex;
    gap: 16px;
    margin-bottom: 0;
}

.register-form .form-label {
    display: block;
    font-weight: var(--font-weight-semibold);
    color: var(--gray-700);
    margin-bottom: 6px;
    font-size: var(--font-size-sm);
    line-height: 1.4;
}

.register-form .form-control {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid var(--gray-300);
    border-radius: var(--radius-base);
    font-size: var(--font-size-base);
    font-family: inherit;
    color: var(--text);
    background-color: var(--white);
    height: 48px;
    box-sizing: border-box;
    transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
    -webkit-appearance: none;
    appearance: none;
}

.register-form .form-control:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 4px rgba(89, 193, 74, 0.15);
}

.register-form .form-control.error {
    border-color: var(--danger);
}

.register-form .form-control.error:focus {
    box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.15);
}

.register-form .form-control::placeholder {
    color: var(--gray-400);
    font-size: var(--font-size-sm);
}

.register-form select.form-control {
    background-image: none;
    cursor: pointer;
}

.register-form .form-error {
    color: var(--danger);
    font-size: var(--font-size-xs);
    margin-top: 4px;
    display: none;
    font-weight: var(--font-weight-medium);
    line-height: 1.4;
}

.register-form .form-hint {
    color: var(--gray-500);
    font-size: 11px;
    margin-top: 4px;
    line-height: 1.4;
}

.text-danger {
    color: var(--danger);
}

.text-muted {
    color: var(--gray-500);
}

/* ============================================
   PASSWORD TOGGLE
   ============================================ */

.password-wrapper {
    position: relative;
    width: 100%;
}

.password-wrapper .form-control {
    padding-right: 48px;
}

.password-toggle {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    cursor: pointer;
    padding: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0.5;
    transition: opacity var(--transition-fast);
    border-radius: var(--radius-sm);
}

.password-toggle:hover {
    opacity: 1;
    background: rgba(0, 0, 0, 0.04);
}

.password-toggle img {
    width: 20px;
    height: 20px;
    display: block;
}

/* ============================================
   REVIEW SUMMARY
   ============================================ */

.review-summary {
    display: flex;
    flex-direction: column;
    gap: 20px;
    margin-bottom: 20px;
}

.review-section {
    padding: 16px 18px;
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-base);
}

.review-section-title {
    margin: 0 0 10px 0;
    font-size: 11px;
    font-weight: var(--font-weight-bold);
    color: var(--gray-500);
    text-transform: uppercase;
    letter-spacing: 0.6px;
}

.review-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    font-size: var(--font-size-sm);
    padding: 7px 0;
    border-bottom: 1px dashed var(--gray-200);
}

.review-row:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.review-label {
    color: var(--gray-500);
    font-weight: var(--font-weight-semibold);
    flex-shrink: 0;
    min-width: 130px;
}

.review-value {
    color: var(--text);
    text-align: right;
    word-break: break-word;
    flex: 1;
    font-weight: var(--font-weight-medium);
}

/* ============================================
   STEP ACTIONS
   ============================================ */

.step-actions {
    display: flex;
    gap: 12px;
    margin-top: 28px;
    flex-wrap: wrap;
}

.step-actions .btn {
    padding: 12px 24px;
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-semibold);
    border-radius: var(--radius-base);
    min-width: 140px;
    min-height: 46px;
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background-color var(--transition-fast),
        border-color var(--transition-fast),
        color var(--transition-fast),
        transform 0.1s ease,
        box-shadow var(--transition-fast);
}

.step-actions .btn:active {
    transform: scale(0.98);
}

.step-actions .btn-outline.btn-prev {
    background-color: var(--black);
    color: var(--white);
    border: 2px solid var(--black);
}

.step-actions .btn-outline.btn-prev:hover,
.step-actions .btn-outline.btn-prev:focus {
    background-color: var(--black);
    color: var(--white);
    border-color: var(--black);
    opacity: 0.9;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}

.step-actions .btn-primary.btn-next,
.step-actions .btn-primary#registerBtn {
    background-color: var(--primary);
    color: var(--white);
    border: 2px solid var(--primary);
}

.step-actions .btn-primary.btn-next:hover,
.step-actions .btn-primary.btn-next:focus,
.step-actions .btn-primary#registerBtn:hover,
.step-actions .btn-primary#registerBtn:focus {
    background-color: var(--primary-dark);
    border-color: var(--primary-dark);
    color: var(--white);
    box-shadow: 0 4px 14px rgba(89, 193, 74, 0.28);
}

.step-actions .btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

/* ============================================
   ALERTS
   ============================================ */

.alert {
    padding: 12px 16px;
    border-radius: var(--radius-base);
    margin-bottom: 20px;
    border: 2px solid transparent;
    font-size: var(--font-size-sm);
    line-height: 1.6;
}

.alert-danger {
    color: #721c24;
    background-color: #f8d7da;
    border-color: #f5c6cb;
}

.alert-success {
    color: #155724;
    background-color: #d4edda;
    border-color: #c3e6cb;
}

/* ============================================
   TERMS
   ============================================ */

.terms-group {
    margin: 24px 0 0 0;
    padding: 16px 18px;
    background: rgba(89, 193, 74, 0.06);
    border-radius: var(--radius-base);
    border: 1px solid rgba(89, 193, 74, 0.18);
    transition: background var(--transition-fast), border-color var(--transition-fast);
}

.terms-group.error {
    background: rgba(220, 53, 69, 0.06);
    border-color: var(--danger);
}

.terms-group.error .terms-label {
    color: var(--danger);
}

.terms-group.error .custom-checkbox {
    border-color: var(--danger);
}

.checkbox-wrapper {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    position: relative;
}

.checkbox-wrapper input[type="checkbox"] {
    position: absolute;
    opacity: 0;
    width: 22px;
    height: 22px;
    cursor: pointer;
    z-index: 2;
    margin: 0;
    top: 0;
    left: 0;
}

.checkbox-wrapper .custom-checkbox {
    display: inline-block;
    width: 22px;
    height: 22px;
    border: 2px solid var(--gray-300);
    border-radius: var(--radius-sm);
    background: var(--white);
    position: relative;
    flex-shrink: 0;
    cursor: pointer;
    margin-top: 1px;
    transition: border-color var(--transition-fast), background-color var(--transition-fast);
}

.checkbox-wrapper input[type="checkbox"]:checked+.custom-checkbox {
    background: var(--primary);
    border-color: var(--primary);
}

.checkbox-wrapper input[type="checkbox"]:checked+.custom-checkbox::after {
    content: '';
    position: absolute;
    left: 6px;
    top: 2px;
    width: 6px;
    height: 11px;
    border: solid var(--white);
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
    border-radius: 1px;
}

.checkbox-wrapper input[type="checkbox"]:focus-visible+.custom-checkbox {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
}

.terms-label {
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-medium);
    color: var(--gray-700);
    line-height: 1.6;
    cursor: pointer;
    flex: 1;
}

.terms-label a {
    color: var(--primary);
    text-decoration: none;
    font-weight: var(--font-weight-bold);
}

.terms-label a:hover {
    color: var(--primary-dark);
    text-decoration: underline;
}

/* ============================================
   FOOTER
   ============================================ */

.register-footer {
    margin-top: 28px;
    text-align: center;
    padding-top: 22px;
    border-top: 1px solid var(--gray-200);
}

.register-footer .text-muted {
    font-size: var(--font-size-sm);
    color: var(--gray-500);
    margin: 0;
}

.register-footer .text-muted a {
    color: var(--primary);
    text-decoration: none;
    font-weight: var(--font-weight-semibold);
}

.register-footer .text-muted a:hover {
    color: var(--primary-dark);
    text-decoration: underline;
}

/* ============================================
   NOTIFIER MODAL
   ============================================ */

.notifier {
    position: fixed;
    inset: 0;
    background: rgba(5, 10, 4, 0.5);
    -webkit-backdrop-filter: blur(6px);
    backdrop-filter: blur(6px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: var(--z-modal-backdrop);
    transition: opacity 0.3s ease, visibility 0.3s ease;
    padding: 20px;
}

.notifier.hidden {
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
}

.notifier-content {
    background: var(--white);
    padding: 40px 32px 32px;
    border-radius: var(--radius-xl);
    max-width: 420px;
    width: 100%;
    text-align: center;
    box-shadow: var(--shadow-xl);
    animation: riderModalFadeIn 0.3s ease;
}

@keyframes riderModalFadeIn {
    from {
        opacity: 0;
        transform: scale(0.95) translateY(-20px);
    }

    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}

.notifier-icon {
    margin: 0 auto 20px;
    width: 72px;
    height: 72px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(89, 193, 74, 0.12);
    border-radius: var(--radius-full);
}

.notifier-icon img {
    width: 36px;
    height: 36px;
    display: block;
}

.notifier-content .heading-5 {
    margin: 0 0 8px 0;
    font-size: var(--font-size-lg);
    font-weight: var(--font-weight-bold);
    color: var(--text);
}

.notifier-content p {
    font-size: var(--font-size-sm);
    color: var(--gray-600);
    margin: 0 0 26px 0;
    line-height: 1.7;
}

.notifier-content .btn {
    min-width: 200px;
    padding: 14px 32px;
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-semibold);
    border-radius: var(--radius-base);
    min-height: 50px;
    cursor: pointer;
    background-color: var(--primary);
    color: var(--white);
    border: 2px solid var(--primary);
    transition: background-color var(--transition-fast), border-color var(--transition-fast);
}

.notifier-content .btn:hover {
    background-color: var(--primary-dark);
    border-color: var(--primary-dark);
}

/* ============================================
   RESPONSIVE — TABLET
   ============================================ */

@media (max-width: 768px) {
    .register-page {
        padding: 20px 12px 32px 12px;
    }

    .register-card {
        padding: 26px 20px 22px 20px;
        border-radius: var(--radius-lg);
    }

    .register-header .heading-2 {
        font-size: var(--font-size-2xl);
    }

    .register-form .form-row {
        flex-direction: column;
        gap: 0;
    }

    .register-form .form-group {
        margin-bottom: 14px;
    }

    .step-actions {
        flex-direction: column;
        gap: 10px;
        margin-top: 22px;
    }

    .step-actions .btn {
        width: 100%;
        min-width: 100%;
    }

    .register-progress {
        gap: 2px;
        padding: 0;
        margin-bottom: 24px;
    }

    .progress-step .step-number {
        width: 30px;
        height: 30px;
        font-size: var(--font-size-xs);
    }

    .progress-step .step-label {
        font-size: 9px;
    }

    .progress-line {
        top: -12px;
        margin: 0 4px;
    }

    .review-label {
        min-width: 100px;
        font-size: var(--font-size-xs);
    }

    .review-value {
        font-size: var(--font-size-xs);
    }
}

/* ============================================
   RESPONSIVE — MOBILE
   ============================================ */

@media (max-width: 480px) {
    .register-page {
        padding: 12px 8px 24px 8px;
    }

    .register-card {
        padding: 20px 14px 18px 14px;
        border-radius: var(--radius-base);
    }

    .register-header {
        margin-bottom: 22px;
    }

    .register-header .heading-2 {
        font-size: var(--font-size-xl);
    }

    .register-header .text-muted {
        font-size: var(--font-size-xs);
    }

    .register-form .form-control {
        height: 44px;
        font-size: var(--font-size-sm);
        padding: 10px 12px;
    }

    .register-form .form-label {
        font-size: var(--font-size-xs);
    }

    .register-form .form-group {
        margin-bottom: 12px;
    }

    .progress-step .step-number {
        width: 26px;
        height: 26px;
        font-size: 10px;
    }

    .progress-step .step-label {
        font-size: 8px;
    }

    .step-actions {
        margin-top: 18px;
    }

    .step-actions .btn {
        padding: 10px 16px;
        font-size: var(--font-size-sm);
        min-height: 42px;
    }

    .review-section {
        padding: 12px 14px;
    }

    .review-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 2px;
        padding: 6px 0;
    }

    .review-label {
        min-width: 0;
        font-size: 10px;
    }

    .review-value {
        text-align: left;
        font-size: var(--font-size-xs);
    }

    .terms-group {
        padding: 12px 14px;
    }

    .terms-label {
        font-size: var(--font-size-xs);
    }

    .notifier-content {
        padding: 28px 20px 24px;
    }

    .notifier-icon {
        width: 56px;
        height: 56px;
    }

    .notifier-icon img {
        width: 28px;
        height: 28px;
    }

    .notifier-content .btn {
        min-width: 0;
        width: 100%;
        min-height: 44px;
        font-size: var(--font-size-sm);
    }
}

/* ============================================
   REDUCED MOTION
   ============================================ */

@media (prefers-reduced-motion: reduce) {

    .register-step,
    .notifier,
    .notifier-content,
    .step-actions .btn,
    .password-toggle,
    .terms-group,
    .custom-checkbox {
        transition: none !important;
        animation: none !important;
    }
}
```

---

## File: `fitpal/rider/assets/ui/js/header.js`

**Status:** `FOUND`

```javascript
/**
 * FitPal Rider Header JavaScript
 *
 * Direct class toggling, no input locks. All animations via CSS.
 *
 * @package FitPal
 * @version 1.1 — Aligns with customer header JS; adds active-link
 *                highlighting.
 */
(function () {
    'use strict';

    var menuToggle = document.getElementById('menuToggle');
    var mobileNav = document.getElementById('mobileNav');
    var mobileOverlay = document.getElementById('mobileOverlay');
    var header = document.querySelector('.rider-header');

    var isMenuOpen = false;

    // ─── Menu Toggle ───
    function toggleMenu(e) {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
        isMenuOpen = !isMenuOpen;

        mobileNav.classList.toggle('open', isMenuOpen);
        menuToggle.classList.toggle('active', isMenuOpen);
        menuToggle.setAttribute('aria-expanded', String(isMenuOpen));
        if (mobileOverlay) {
            mobileOverlay.classList.toggle('active', isMenuOpen);
        }
    }

    // ─── Sticky header ───
    function handleScroll() {
        if (header) {
            header.classList.toggle('header-scrolled', window.pageYOffset > 10);
        }
    }

    // ─── Initialise ───
    if (menuToggle && mobileNav) {
        menuToggle.addEventListener('click', toggleMenu);

        if (mobileOverlay) {
            mobileOverlay.addEventListener('click', function () {
                if (isMenuOpen) toggleMenu();
            });
        }

        mobileNav.addEventListener('click', function (e) {
            if (e.target.closest('a') && isMenuOpen) {
                toggleMenu();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isMenuOpen) {
                toggleMenu();
                menuToggle.focus();
            }
        });

        var resizeTimer;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                if (window.innerWidth > 992 && isMenuOpen) {
                    toggleMenu();
                }
            }, 100);
        });
    }

    // ─── Sticky header scroll handler ───
    if (header) {
        var ticking = false;
        window.addEventListener('scroll', function () {
            if (!ticking) {
                requestAnimationFrame(function () {
                    handleScroll();
                    ticking = false;
                });
                ticking = true;
            }
        }, { passive: true });
    }

    // ─── Active link highlight ───
    (function highlightActive() {
        var currentPage = window.location.pathname.split('/').pop() || 'index.php';
        var links = document.querySelectorAll('.nav-link, .mobile-nav-link');
        for (var i = 0; i < links.length; i++) {
            var link = links[i];
            var href = link.getAttribute('href');
            if (!href) continue;
            var hrefFile = href.split('/').pop() || '';
            if (
                hrefFile === currentPage ||
                (currentPage === 'index.php' && hrefFile === '') ||
                (currentPage === '' && hrefFile === 'index.php')
            ) {
                link.classList.add('active');
            }
        }
    })();

})();
```

---

## File: `fitpal/rider/assets/ui/js/sign-in.js`

**Status:** `FOUND`

```javascript
/**
 * FitPal Rider Sign-In JavaScript
 *
 * Client-side validation plus password visibility toggle. All real
 * validation happens server-side in sign-in-handler.php.
 *
 * @package FitPal
 * @version 2.0 — Adds password eye-icon toggle; preserves existing
 *                field error clearing.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        const form = document.getElementById('riderSignInForm');
        const identifier = document.getElementById('identifier');
        const password = document.getElementById('password');
        const submitBtn = form ? form.querySelector('button[type="submit"]') : null;

        const toggleBtn = document.getElementById('togglePassword');
        const toggleIcon = document.getElementById('passwordIcon');

        if (!form) return;

        // ============================================
        // PASSWORD VISIBILITY TOGGLE
        // ============================================
        if (toggleBtn && password && toggleIcon) {
            toggleBtn.addEventListener('click', function () {
                const isPassword = password.type === 'password';

                password.type = isPassword ? 'text' : 'password';

                const iconFile = isPassword ? 'password-unhide.svg' : 'password-hide.svg';
                toggleIcon.src = '../../shared/assets/images/icons/' + iconFile;
                toggleIcon.alt = isPassword ? 'Hide password' : 'Show password';

                toggleBtn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
                toggleBtn.setAttribute('aria-pressed', isPassword ? 'true' : 'false');
            });
        }

        // ============================================
        // FORM VALIDATION
        // ============================================
        form.addEventListener('submit', function (e) {
            let isValid = true;

            [identifier, password].forEach(function (el) {
                if (el) el.classList.remove('is-error');
            });

            if (!identifier || !identifier.value.trim()) {
                if (identifier) identifier.classList.add('is-error');
                isValid = false;
            }

            if (!password || !password.value) {
                if (password) password.classList.add('is-error');
                isValid = false;
            }

            if (!isValid) {
                e.preventDefault();
                return;
            }

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Signing in...';
            }
        });

        // Real-time error clearing
        [identifier, password].forEach(function (el) {
            if (!el) return;
            el.addEventListener('input', function () {
                if (this.value.trim() !== '') {
                    this.classList.remove('is-error');
                }
            });
        });
    });
})();
```

---

## File: `fitpal/rider/assets/ui/js/sign-up.js`

**Status:** `FOUND`

```javascript
/**
 * FitPal Rider Registration JavaScript
 *
 * Four-step application form with:
 *   - Field-level validation and error clearing
 *   - Automatic name capitalization and email lowercasing
 *   - Password rule feedback
 *   - Vehicle type dependent plate validation
 *   - Emergency-contact validation
 *   - Review summary build on Step 4
 *   - Fetch-based submission with JSON response handling
 *
 * All real validation runs server-side in sign-up-handler.php.
 * This file is a UX layer only.
 *
 * @package FitPal
 * @version 2.0 — Adds emergency contact, vehicle make/model,
 *                submission via fetch with JSON response.
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ============================================
        // DOM REFERENCES
        // ============================================
        const form = document.getElementById('registerForm');
        if (!form) return;

        const steps = document.querySelectorAll('.register-step');
        const progressSteps = document.querySelectorAll('.progress-step');
        const progressLines = document.querySelectorAll('.progress-line');
        const stepSubtitle = document.getElementById('stepSubtitle');
        const currentStepInput = document.getElementById('currentStep');
        const registerError = document.getElementById('registerError');
        const errorMessage = document.getElementById('errorMessage');

        const nextButtons = document.querySelectorAll('.btn-next');
        const prevButtons = document.querySelectorAll('.btn-prev');

        // Step 1
        const firstName = document.getElementById('first_name');
        const middleName = document.getElementById('middle_name');
        const lastName = document.getElementById('last_name');
        const birthdate = document.getElementById('birthdate');
        const gender = document.getElementById('gender');
        const email = document.getElementById('email');
        const contactNumber = document.getElementById('contact_number');
        const username = document.getElementById('username');
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');

        // Step 2
        const vehicleType = document.getElementById('vehicle_type');
        const vehiclePlate = document.getElementById('vehicle_plate');
        const vehicleMake = document.getElementById('vehicle_make');
        const vehicleModel = document.getElementById('vehicle_model');
        const vehicleYear = document.getElementById('vehicle_year');

        // Step 3
        const addressLabel = document.getElementById('address_label');
        const block = document.getElementById('block');
        const barangay = document.getElementById('barangay');
        const city = document.getElementById('city');
        const province = document.getElementById('province');
        const region = document.getElementById('region');
        const postalCode = document.getElementById('postal_code');
        const emergencyName = document.getElementById('emergency_name');
        const emergencyRelationship = document.getElementById('emergency_relationship');
        const emergencyContact = document.getElementById('emergency_contact');

        // Step 4
        const termsCheckbox = document.getElementById('terms');
        const termsGroup = document.getElementById('termsGroup');
        const termsError = document.getElementById('termsError');
        const registerBtn = document.getElementById('registerBtn');

        // Error elements
        const firstNameError = document.getElementById('firstNameError');
        const lastNameError = document.getElementById('lastNameError');
        const birthdateError = document.getElementById('birthdateError');
        const genderError = document.getElementById('genderError');
        const emailError = document.getElementById('emailError');
        const contactError = document.getElementById('contactError');
        const usernameError = document.getElementById('usernameError');
        const passwordError = document.getElementById('passwordError');
        const confirmError = document.getElementById('confirmError');
        const vehicleTypeError = document.getElementById('vehicleTypeError');
        const vehiclePlateError = document.getElementById('vehiclePlateError');
        const vehicleYearError = document.getElementById('vehicleYearError');
        const blockError = document.getElementById('blockError');
        const cityError = document.getElementById('cityError');
        const postalError = document.getElementById('postalError');
        const emergencyNameError = document.getElementById('emergencyNameError');
        const emergencyRelationshipError = document.getElementById('emergencyRelationshipError');
        const emergencyContactError = document.getElementById('emergencyContactError');

        const stepTitles = [
            'Step 1 of 4 — Personal Information',
            'Step 2 of 4 — Vehicle Details',
            'Step 3 of 4 — Address & Emergency Contact',
            'Step 4 of 4 — Review & Terms',
        ];

        let currentStep = 1;
        const totalSteps = 4;
        let isSubmitting = false;

        const NAME_PATTERN = /^[A-Za-z\s\-']+$/;

        // ============================================
        // HELPERS
        // ============================================

        function isValidPhilippineMobile(number) {
            const cleaned = String(number).replace(/\s/g, '');
            if (!/^09\d{9}$/.test(cleaned)) {
                return { valid: false, message: 'Enter a valid PH mobile number (11 digits, starting with 09).' };
            }
            return { valid: true, message: '' };
        }

        function isValidPassword(pw) {
            if (pw.length < 8) return { valid: false, message: 'Password must be at least 8 characters.' };
            if (pw.length > 20) return { valid: false, message: 'Password must be no more than 20 characters.' };
            if (!/^[A-Za-z0-9]+$/.test(pw)) {
                return { valid: false, message: 'Password can only contain letters and numbers.' };
            }
            if (!/[0-9]/.test(pw)) return { valid: false, message: 'Password must contain at least one number.' };
            if (!/[A-Za-z]/.test(pw)) return { valid: false, message: 'Password must contain at least one letter.' };
            return { valid: true, message: '' };
        }

        function showFieldError(input, errorEl, message) {
            if (input) input.classList.add('error');
            if (errorEl) { errorEl.textContent = message; errorEl.style.display = 'block'; }
        }

        function clearFieldError(input, errorEl) {
            if (input) input.classList.remove('error');
            if (errorEl) { errorEl.textContent = ''; errorEl.style.display = 'none'; }
        }

        function showBanner(message) {
            if (registerError && errorMessage) {
                errorMessage.textContent = message;
                registerError.style.display = 'block';
            }
        }

        function hideBanner() {
            if (registerError) registerError.style.display = 'none';
        }

        function clearStepErrors(step) {
            const stepEl = document.getElementById('step' + step);
            if (!stepEl) return;

            stepEl.querySelectorAll('.form-error').forEach(function (el) {
                el.textContent = '';
                el.style.display = 'none';
            });
            stepEl.querySelectorAll('.form-control').forEach(function (el) {
                el.classList.remove('error');
            });

            if (step === 4 && termsGroup) {
                termsGroup.classList.remove('error');
                if (termsError) {
                    termsError.textContent = '';
                    termsError.style.display = 'none';
                }
            }
        }

        function safeFocus(el) {
            if (el && typeof el.focus === 'function') {
                setTimeout(function () { el.focus(); }, 80);
            }
        }

        // ============================================
        // INPUT FILTERS
        // ============================================

        function setupNameInput(input, errorEl) {
            if (!input) return;

            input.addEventListener('input', function () {
                const start = this.selectionStart;
                const filtered = this.value.replace(/[^A-Za-z\s\-']/g, '');
                const capitalized = filtered.replace(/\b\w/g, function (c) { return c.toUpperCase(); });

                if (this.value !== capitalized) {
                    this.value = capitalized;
                    const newStart = Math.min(start, this.value.length);
                    this.setSelectionRange(newStart, newStart);
                }
                if (errorEl) clearFieldError(this, errorEl);
            });

            input.addEventListener('blur', function () {
                if (this.value.length > 0) {
                    const capitalized = this.value.replace(/\b\w/g, function (c) { return c.toUpperCase(); });
                    if (this.value !== capitalized) this.value = capitalized;
                }
            });
        }

        function setupEmailInput(input) {
            if (!input) return;
            input.addEventListener('input', function () {
                const start = this.selectionStart;
                const lower = this.value.toLowerCase();
                if (this.value !== lower) {
                    this.value = lower;
                    const newStart = Math.min(start, this.value.length);
                    this.setSelectionRange(newStart, newStart);
                }
                clearFieldError(this, emailError);
            });
        }

        function setupDigitsOnly(input, errorEl) {
            if (!input) return;
            input.addEventListener('input', function () {
                this.value = this.value.replace(/[^0-9\s]/g, '');
                if (errorEl) clearFieldError(this, errorEl);
            });
        }

        function setupAlphanumUnderscore(input) {
            if (!input) return;
            input.addEventListener('input', function () {
                this.value = this.value.replace(/[^A-Za-z0-9_]/g, '');
                clearFieldError(this, usernameError);
            });
        }

        function setupAlphanumOnly(input) {
            if (!input) return;
            input.addEventListener('input', function () {
                this.value = this.value.replace(/[^A-Za-z0-9]/g, '');
            });
        }

        function setupPlate(input) {
            if (!input) return;
            input.addEventListener('input', function () {
                this.value = this.value.toUpperCase().replace(/[^A-Z0-9\s\-]/g, '');
                clearFieldError(this, vehiclePlateError);
            });
        }

        function setupPostal(input) {
            if (!input) return;
            input.addEventListener('input', function () {
                this.value = this.value.replace(/[^0-9]/g, '');
                clearFieldError(this, postalError);
            });
        }

        setupNameInput(firstName, firstNameError);
        setupNameInput(middleName, null);
        setupNameInput(lastName, lastNameError);
        setupEmailInput(email);
        setupDigitsOnly(contactNumber, contactError);
        setupDigitsOnly(emergencyContact, emergencyContactError);
        setupAlphanumUnderscore(username);
        setupAlphanumOnly(password);
        setupAlphanumOnly(confirmPassword);
        setupPlate(vehiclePlate);
        setupPostal(postalCode);

        // ============================================
        // PASSWORD TOGGLES
        // ============================================

        function setupPasswordToggle(toggleId, inputId, iconId) {
            const btn = document.getElementById(toggleId);
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (!btn || !input || !icon) return;

            btn.addEventListener('click', function () {
                const isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
                const iconFile = isPassword ? 'password-unhide.svg' : 'password-hide.svg';
                icon.src = '../../shared/assets/images/icons/' + iconFile;
                icon.alt = isPassword ? 'Hide password' : 'Show password';
                this.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            });
        }

        setupPasswordToggle('togglePassword', 'password', 'passwordIcon');
        setupPasswordToggle('toggleConfirmPassword', 'confirm_password', 'confirmPasswordIcon');

        // ============================================
        // VALIDATION — STEP 1
        // ============================================

        function validateStep1() {
            let valid = true;
            clearStepErrors(1);

            const fnVal = firstName ? firstName.value.trim() : '';
            if (fnVal.length < 2) {
                showFieldError(firstName, firstNameError, 'First name must be at least 2 characters.');
                valid = false;
            } else if (!NAME_PATTERN.test(fnVal)) {
                showFieldError(firstName, firstNameError, 'First name contains invalid characters.');
                valid = false;
            }

            const lnVal = lastName ? lastName.value.trim() : '';
            if (lnVal.length < 2) {
                showFieldError(lastName, lastNameError, 'Last name must be at least 2 characters.');
                valid = false;
            } else if (!NAME_PATTERN.test(lnVal)) {
                showFieldError(lastName, lastNameError, 'Last name contains invalid characters.');
                valid = false;
            }

            if (!birthdate || !birthdate.value) {
                showFieldError(birthdate, birthdateError, 'Please select your date of birth.');
                valid = false;
            } else {
                const bd = new Date(birthdate.value);
                const now = new Date();
                let age = now.getFullYear() - bd.getFullYear();
                if (now.getMonth() < bd.getMonth() ||
                    (now.getMonth() === bd.getMonth() && now.getDate() < bd.getDate())) {
                    age--;
                }
                if (age < 18) {
                    showFieldError(birthdate, birthdateError, 'You must be at least 18 years old.');
                    valid = false;
                } else if (age > 70) {
                    showFieldError(birthdate, birthdateError, 'Please enter a valid date of birth.');
                    valid = false;
                }
            }

            if (!gender || !gender.value) {
                showFieldError(gender, genderError, 'Please select your gender.');
                valid = false;
            }

            const emailVal = email ? email.value.trim() : '';
            if (!emailVal) {
                showFieldError(email, emailError, 'Please enter your email address.');
                valid = false;
            } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
                showFieldError(email, emailError, 'Please enter a valid email address.');
                valid = false;
            }

            const contactVal = contactNumber ? contactNumber.value.trim() : '';
            if (!contactVal) {
                showFieldError(contactNumber, contactError, 'Please enter your mobile number.');
                valid = false;
            } else {
                const ph = isValidPhilippineMobile(contactVal);
                if (!ph.valid) {
                    showFieldError(contactNumber, contactError, ph.message);
                    valid = false;
                }
            }

            const unVal = username ? username.value.trim() : '';
            if (!unVal) {
                showFieldError(username, usernameError, 'Please choose a username.');
                valid = false;
            } else if (unVal.length < 3) {
                showFieldError(username, usernameError, 'Username must be at least 3 characters.');
                valid = false;
            } else if (unVal.length > 20) {
                showFieldError(username, usernameError, 'Username must be no more than 20 characters.');
                valid = false;
            } else if (!/^[A-Za-z0-9_]+$/.test(unVal)) {
                showFieldError(username, usernameError, 'Only letters, numbers, and underscores.');
                valid = false;
            }

            const pwVal = password ? password.value : '';
            if (!pwVal) {
                showFieldError(password, passwordError, 'Please create a password.');
                valid = false;
            } else {
                const pwCheck = isValidPassword(pwVal);
                if (!pwCheck.valid) {
                    showFieldError(password, passwordError, pwCheck.message);
                    valid = false;
                }
            }

            const cpVal = confirmPassword ? confirmPassword.value : '';
            if (!cpVal) {
                showFieldError(confirmPassword, confirmError, 'Please confirm your password.');
                valid = false;
            } else if (pwVal !== cpVal) {
                showFieldError(confirmPassword, confirmError, 'Passwords do not match.');
                valid = false;
            }

            return valid;
        }

        // ============================================
        // VALIDATION — STEP 2
        // ============================================

        function validateStep2() {
            let valid = true;
            clearStepErrors(2);

            if (!vehicleType || !vehicleType.value) {
                showFieldError(vehicleType, vehicleTypeError, 'Please select your vehicle type.');
                valid = false;
            }

            const isMotorVehicle = vehicleType?.value && vehicleType.value !== 'bicycle';
            if (isMotorVehicle) {
                const plateVal = vehiclePlate ? vehiclePlate.value.trim() : '';
                if (!plateVal) {
                    showFieldError(vehiclePlate, vehiclePlateError, 'Plate number is required for motor vehicles.');
                    valid = false;
                } else if (plateVal.replace(/\s/g, '').length < 4) {
                    showFieldError(vehiclePlate, vehiclePlateError, 'Plate number looks too short.');
                    valid = false;
                }
            }

            if (vehicleYear && vehicleYear.value) {
                const y = parseInt(vehicleYear.value, 10);
                const maxYear = new Date().getFullYear() + 1;
                if (isNaN(y) || y < 1980 || y > maxYear) {
                    showFieldError(vehicleYear, vehicleYearError, 'Please enter a valid year.');
                    valid = false;
                }
            }

            return valid;
        }

        // ============================================
        // VALIDATION — STEP 3
        // ============================================

        function validateStep3() {
            let valid = true;
            clearStepErrors(3);

            const blockVal = block ? block.value.trim() : '';
            if (!blockVal) {
                showFieldError(block, blockError, 'Block / Street / Unit is required.');
                valid = false;
            }

            const cityVal = city ? city.value.trim() : '';
            if (!cityVal) {
                showFieldError(city, cityError, 'City or municipality is required.');
                valid = false;
            }

            const enVal = emergencyName ? emergencyName.value.trim() : '';
            if (enVal.length < 2) {
                showFieldError(emergencyName, emergencyNameError, 'Emergency contact name is required.');
                valid = false;
            }

            const erVal = emergencyRelationship ? emergencyRelationship.value : '';
            if (!erVal) {
                showFieldError(emergencyRelationship, emergencyRelationshipError, 'Please select a relationship.');
                valid = false;
            }

            const ecVal = emergencyContact ? emergencyContact.value.trim() : '';
            if (!ecVal) {
                showFieldError(emergencyContact, emergencyContactError, 'Please enter an emergency contact number.');
                valid = false;
            } else {
                const ph = isValidPhilippineMobile(ecVal);
                if (!ph.valid) {
                    showFieldError(emergencyContact, emergencyContactError, ph.message);
                    valid = false;
                }
            }

            return valid;
        }

        // ============================================
        // VALIDATION — STEP 4
        // ============================================

        function validateStep4() {
            clearStepErrors(4);

            if (!termsCheckbox || !termsCheckbox.checked) {
                if (termsError) {
                    termsError.textContent = 'You must agree to the Terms and Privacy Policy.';
                    termsError.style.display = 'block';
                }
                if (termsGroup) termsGroup.classList.add('error');
                return false;
            }

            return true;
        }

        function validateStep(step) {
            switch (step) {
                case 1: return validateStep1();
                case 2: return validateStep2();
                case 3: return validateStep3();
                case 4: return validateStep4();
                default: return true;
            }
        }

        // ============================================
        // REVIEW SUMMARY
        // ============================================

        function buildReviewSummary() {
            const fullName = [firstName?.value, middleName?.value, lastName?.value]
                .map(function (v) { return (v || '').trim(); })
                .filter(Boolean)
                .join(' ');

            const addressParts = [block, barangay, city, province, region, postalCode]
                .map(function (el) { return (el?.value || '').trim(); })
                .filter(Boolean);

            const vehicleTypeText = vehicleType?.options[vehicleType.selectedIndex]?.text || '—';
            const plateText = vehiclePlate?.value ? vehiclePlate.value.trim() : '—';
            const makeText = vehicleMake?.value ? vehicleMake.value.trim() : '';
            const modelText = vehicleModel?.value ? vehicleModel.value.trim() : '';
            const makeModel = [makeText, modelText].filter(Boolean).join(' ') || '—';

            const emergencyRelText = emergencyRelationship?.options[emergencyRelationship.selectedIndex]?.text || '—';

            setText('reviewName', fullName || '—');
            setText('reviewEmail', email?.value || '—');
            setText('reviewContact', contactNumber?.value || '—');
            setText('reviewUsername', username?.value || '—');

            setText('reviewVehicleType', vehicleTypeText);
            setText('reviewVehiclePlate', plateText);
            setText('reviewVehicleMakeModel', makeModel);

            setText('reviewAddress', addressParts.join(', ') || '—');

            setText('reviewEmergencyName', emergencyName?.value || '—');
            setText('reviewEmergencyRelationship', emergencyRelText);
            setText('reviewEmergencyContact', emergencyContact?.value || '—');
        }

        function setText(id, value) {
            const el = document.getElementById(id);
            if (el) el.textContent = value;
        }

        // ============================================
        // STEP NAVIGATION
        // ============================================

        function goToStep(step) {
            if (step > currentStep && !validateStep(currentStep)) {
                return;
            }

            currentStep = step;
            if (currentStepInput) currentStepInput.value = String(step);

            steps.forEach(function (el, i) {
                el.style.display = (i + 1 === step) ? 'block' : 'none';
            });

            progressSteps.forEach(function (el, i) {
                const n = i + 1;
                el.classList.toggle('active', n === step);
                el.classList.toggle('completed', n < step);
            });

            progressLines.forEach(function (el, i) {
                el.classList.toggle('completed', i + 1 < step);
            });

            if (stepSubtitle) {
                stepSubtitle.textContent = stepTitles[step - 1] || ('Step ' + step + ' of 4');
            }

            hideBanner();

            if (step === 4) {
                buildReviewSummary();
            }

            const stepEl = document.getElementById('step' + step);
            if (stepEl) {
                const firstInput = stepEl.querySelector('input:not([type="hidden"]), select');
                safeFocus(firstInput);
            }

            const progressBar = document.querySelector('.register-progress');
            if (progressBar) progressBar.setAttribute('aria-valuenow', String(step));
        }

        nextButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                const next = parseInt(this.getAttribute('data-next'), 10);
                if (!isNaN(next) && next <= totalSteps) goToStep(next);
            });
        });

        prevButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                const prev = parseInt(this.getAttribute('data-prev'), 10);
                if (!isNaN(prev) && prev >= 1) goToStep(prev);
            });
        });

        // ============================================
        // REAL-TIME PASSWORD FEEDBACK
        // ============================================

        if (password) {
            password.addEventListener('input', function () {
                const val = this.value;
                if (val.length === 0) { clearFieldError(this, passwordError); return; }
                const check = isValidPassword(val);
                if (check.valid) {
                    clearFieldError(this, passwordError);
                } else {
                    showFieldError(this, passwordError, check.message);
                }

                if (confirmPassword && confirmPassword.value) {
                    if (confirmPassword.value === val) {
                        clearFieldError(confirmPassword, confirmError);
                    } else {
                        showFieldError(confirmPassword, confirmError, 'Passwords do not match.');
                    }
                }
            });
        }

        if (confirmPassword) {
            confirmPassword.addEventListener('input', function () {
                const val = this.value;
                if (!val) { clearFieldError(this, confirmError); return; }
                if (val === (password ? password.value : '')) {
                    clearFieldError(this, confirmError);
                } else {
                    showFieldError(this, confirmError, 'Passwords do not match.');
                }
            });
        }

        // ============================================
        // BLUR VALIDATION
        // ============================================

        if (contactNumber) {
            contactNumber.addEventListener('blur', function () {
                const val = this.value.trim();
                if (!val) return;
                const ph = isValidPhilippineMobile(val);
                if (ph.valid) clearFieldError(this, contactError);
                else showFieldError(this, contactError, ph.message);
            });
        }

        if (email) {
            email.addEventListener('blur', function () {
                const val = this.value.trim();
                if (!val) return;
                if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
                    clearFieldError(this, emailError);
                } else {
                    showFieldError(this, emailError, 'Please enter a valid email address.');
                }
            });
        }

        if (username) {
            username.addEventListener('blur', function () {
                const val = this.value.trim();
                if (!val) return;
                if (val.length < 3) {
                    showFieldError(this, usernameError, 'Username must be at least 3 characters.');
                } else if (val.length > 20) {
                    showFieldError(this, usernameError, 'Username must be no more than 20 characters.');
                } else if (!/^[A-Za-z0-9_]+$/.test(val)) {
                    showFieldError(this, usernameError, 'Only letters, numbers, and underscores.');
                } else {
                    clearFieldError(this, usernameError);
                }
            });
        }

        if (termsCheckbox) {
            termsCheckbox.addEventListener('change', function () {
                if (this.checked) {
                    if (termsError) { termsError.textContent = ''; termsError.style.display = 'none'; }
                    if (termsGroup) termsGroup.classList.remove('error');
                }
            });
        }

        // ============================================
        // FORM SUBMISSION
        // ============================================

        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                if (isSubmitting) return;

                if (currentStep < totalSteps) {
                    goToStep(currentStep + 1);
                    return;
                }

                if (!validateStep1()) { goToStep(1); return; }
                if (!validateStep2()) { goToStep(2); return; }
                if (!validateStep3()) { goToStep(3); return; }
                if (!validateStep4()) { goToStep(4); return; }

                isSubmitting = true;
                if (registerBtn) {
                    registerBtn.disabled = true;
                    registerBtn.textContent = 'Submitting…';
                }

                fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin'
                })
                    .then(function (res) {
                        return res.json().catch(function () {
                            throw new Error('Unexpected server response.');
                        });
                    })
                    .then(function (data) {
                        if (data && data.status === 'success') {
                            showNotification(
                                'Application Received',
                                data.message || 'Your rider application has been submitted. Please sign in.',
                                function () {
                                    window.location.href = data.redirect || 'sign-in.php';
                                }
                            );
                            return;
                        }

                        isSubmitting = false;
                        if (registerBtn) {
                            registerBtn.disabled = false;
                            registerBtn.textContent = 'Submit Application';
                        }

                        if (data && data.field === 'terms') {
                            if (termsError) {
                                termsError.textContent = data.message;
                                termsError.style.display = 'block';
                            }
                            if (termsGroup) termsGroup.classList.add('error');
                            goToStep(4);
                            return;
                        }

                        showBanner((data && data.message) || 'Could not create your account. Please try again.');
                    })
                    .catch(function () {
                        isSubmitting = false;
                        if (registerBtn) {
                            registerBtn.disabled = false;
                            registerBtn.textContent = 'Submit Application';
                        }
                        showBanner('An unexpected error occurred. Please try again.');
                    });
            });
        }

        // ============================================
        // NOTIFIER MODAL
        // ============================================

        const notifierModal = document.getElementById('notifierModal');
        const notifierTitle = document.getElementById('notifierTitle');
        const notifierMessage = document.getElementById('notifierMessage');
        const notifierCloseBtn = document.getElementById('notifierCloseBtn');

        function showNotification(title, message, callback) {
            if (notifierTitle) notifierTitle.textContent = title || 'Success';
            if (notifierMessage) notifierMessage.textContent = message || '';
            if (notifierModal) notifierModal.classList.remove('hidden');
            if (notifierCloseBtn && callback) notifierCloseBtn._callback = callback;
        }

        function closeNotification() {
            if (notifierModal) notifierModal.classList.add('hidden');
            if (notifierCloseBtn && typeof notifierCloseBtn._callback === 'function') {
                const cb = notifierCloseBtn._callback;
                notifierCloseBtn._callback = null;
                cb();
            }
        }

        if (notifierCloseBtn) notifierCloseBtn.addEventListener('click', closeNotification);
        if (notifierModal) {
            notifierModal.addEventListener('click', function (e) {
                if (e.target === this) closeNotification();
            });
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && notifierModal && !notifierModal.classList.contains('hidden')) {
                closeNotification();
            }
        });

        // ============================================
        // INIT
        // ============================================
        goToStep(1);
    });
})();
```

---

## File: `fitpal/rider/backend/database/rider-connect.php`

**Status:** `FOUND`

```php
<?php
/**
 * FitPal Rider Database Connection (role wrapper)
 *
 * Rider pages should require THIS file instead of reaching into
 * shared/backend/database/database-connect.php directly.
 *
 * This does NOT open a second database connection - FitPal's
 * architecture requires one shared PDO singleton. This file exists
 * so the rider role has its own include point, consistent with the
 * rest of rider/backend/database/.
 *
 * Usage:
 *   require_once __DIR__ . '/../../backend/database/rider-connect.php';
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';

// $database_connection is now available - the same singleton every role uses.
```

---

## File: `fitpal/rider/backend/database/rider-queries.php`

**Status:** `FOUND`

```php
<?php
/**
 * FitPal Rider Database Queries
 *
 * Pure data-access layer for the delivery_rider and
 * delivery_rider_profile tables. No $_POST, no header(), no echo.
 *
 * This file is safe to require from any page — it only declares
 * functions and performs no request dispatch at load time.
 *
 * @package FitPal
 * @version 1.2 — findRiderByIdentifier() uses two distinct named
 *                placeholders. MySQL with ATTR_EMULATE_PREPARES=false
 *                does not allow a named placeholder to appear twice
 *                in the same statement.
 */

declare(strict_types=1);

/**
 * Find a rider by email or username.
 *
 * The email and username lookups use two distinct placeholders
 * (:email and :username) even though both receive the same value.
 * MySQL's native prepared-statement protocol cannot reuse a named
 * placeholder, so a shared :identifier would trigger HY093.
 *
 * @param PDO $db
 * @param string $identifier
 * @return array<string, mixed>|false
 */
function findRiderByIdentifier(PDO $db, string $identifier): array|false
{
    $stmt = $db->prepare(
        "SELECT
            dr.delivery_rider_id,
            dr.first_name,
            dr.middle_name,
            dr.last_name,
            dr.email,
            dr.username,
            dr.password,
            dr.is_active,
            drp.verification_status
         FROM delivery_rider dr
         LEFT JOIN delivery_rider_profile drp
                ON dr.delivery_rider_id = drp.delivery_rider_id
         WHERE dr.email = :email OR dr.username = :username
         LIMIT 1"
    );
    $stmt->execute([
        ':email'    => $identifier,
        ':username' => $identifier,
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get a rider's full profile including financial balance.
 *
 * @param PDO $db
 * @param int $riderId
 * @return array<string, mixed>|false
 */
function getRiderProfile(PDO $db, int $riderId): array|false
{
    $stmt = $db->prepare(
        "SELECT
            dr.delivery_rider_id,
            dr.first_name,
            dr.middle_name,
            dr.last_name,
            dr.email,
            dr.contact_number,
            dr.username,
            dr.is_active,
            dr.date_created,
            drp.profile_picture,
            drp.vehicle_type,
            drp.vehicle_plate,
            drp.verification_status,
            drp.average_rating,
            drp.total_deliveries,
            drp.is_available,
            fa.balance
         FROM delivery_rider dr
         LEFT JOIN delivery_rider_profile drp
                ON dr.delivery_rider_id = drp.delivery_rider_id
         LEFT JOIN financial_account fa
                ON drp.financial_account_id = fa.financial_account_id
         WHERE dr.delivery_rider_id = :rider_id
         LIMIT 1"
    );
    $stmt->execute([':rider_id' => $riderId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Check if the rider account is active.
 *
 * @param PDO $db
 * @param int $riderId
 * @return bool
 */
function isRiderActive(PDO $db, int $riderId): bool
{
    $stmt = $db->prepare(
        "SELECT is_active FROM delivery_rider WHERE delivery_rider_id = ?"
    );
    $stmt->execute([$riderId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Format a monetary amount as Philippine pesos.
 *
 * @param int|float|string|null $amount
 * @return string
 */
function formatRiderCurrency(int|float|string|null $amount): string
{
    return '₱' . number_format((float)($amount ?? 0), 2);
}
```

---

## File: `fitpal/rider/backend/handlers/sign-in-handler.php`

**Status:** `FOUND`

```php
<?php
/**
 * FitPal Rider Sign-In Handler
 *
 * Validates credentials against the delivery_rider table.
 * Contains no SQL — all data access goes through rider-queries.php.
 *
 * ---------------------------------------------------------------------
 * REDIRECT TARGETS
 * ---------------------------------------------------------------------
 * This file lives at fitpal/rider/backend/handlers/. Relative paths:
 *   ../../pages/sign-in.php    → fitpal/rider/pages/sign-in.php
 *   ../../pages/dashboard.php  → fitpal/rider/pages/dashboard.php
 * ---------------------------------------------------------------------
 *
 * ---------------------------------------------------------------------
 * DEVELOPMENT-ONLY BYPASS
 * ---------------------------------------------------------------------
 * Seed data stores plaintext passwords ("rider123"). password_verify()
 * only accepts bcrypt hashes, so the normal check fails against seeds.
 * The bypass below accepts the stored value as plaintext, matching the
 * customer handler's pattern. REMOVE before any non-local deployment.
 * ---------------------------------------------------------------------
 *
 * @package FitPal
 * @version 1.3 — Uses distinct placeholders via rider-queries; adds
 *                dev bypass for plaintext seed passwords.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/rider-queries.php';

// ===== REQUEST METHOD =====
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['login_error'] = 'Invalid request method.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

// ===== CSRF =====
if (
    !isset($_POST['csrf_token'], $_SESSION['csrf_token']) ||
    !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])
) {
    $_SESSION['login_error'] = 'Security validation failed. Please try again.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

$identifier = trim((string)($_POST['identifier'] ?? ''));
$password   = (string)($_POST['password'] ?? '');

if ($identifier === '' || $password === '') {
    $_SESSION['login_error'] = 'Please enter your email/username and password.';
    header('Location: ../../pages/sign-in.php');
    exit;
}

try {
    $rider = findRiderByIdentifier($database_connection, $identifier);

    if (!$rider) {
        $_SESSION['login_error'] = 'Invalid email/username or password.';
        header('Location: ../../pages/sign-in.php');
        exit;
    }

    if ((int)$rider['is_active'] !== 1) {
        $_SESSION['login_error'] = 'Your account has been deactivated. Please contact support.';
        header('Location: ../../pages/sign-in.php');
        exit;
    }

    $passwordValid = password_verify($password, (string)$rider['password']);

    // ---- DEVELOPMENT-ONLY BYPASS ----
    // Remove before any non-local deployment.
    // Accepts a stored plaintext value against the submitted password
    // so the seed-data demo login works out of the box.
    if (!$passwordValid && hash_equals((string)$rider['password'], $password)) {
        $passwordValid = true;
    }
    // ---- END DEVELOPMENT-ONLY BYPASS ----

    if (!$passwordValid) {
        $_SESSION['login_error'] = 'Invalid email/username or password.';
        header('Location: ../../pages/sign-in.php');
        exit;
    }

    // ===== LOGIN SUCCESSFUL =====
    session_regenerate_id(true);

    $_SESSION['delivery_rider_id'] = (int)$rider['delivery_rider_id'];
    $_SESSION['user_role']         = 'rider';
    $_SESSION['user_name']         = trim(
        ($rider['first_name'] ?? '') . ' ' . ($rider['last_name'] ?? '')
    );
    $_SESSION['user_email']        = (string)($rider['email'] ?? '');
    $_SESSION['created']           = time();

    unset($_SESSION['csrf_token']);

    header('Location: ../../pages/dashboard.php');
    exit;

} catch (PDOException $e) {
    error_log('Rider sign-in DB error: ' . $e->getMessage());
    $_SESSION['login_error'] = 'An unexpected error occurred. Please try again.';
    header('Location: ../../pages/sign-in.php');
    exit;
} catch (Throwable $e) {
    error_log('Rider sign-in error: ' . $e->getMessage());
    $_SESSION['login_error'] = 'An unexpected error occurred. Please try again.';
    header('Location: ../../pages/sign-in.php');
    exit;
}
```

---

## File: `fitpal/rider/backend/handlers/sign-out-handler.php`

**Status:** `FOUND`

```php
<?php
/**
 * FitPal Rider Sign-Out Handler
 *
 * Clears only rider-specific session data. Does NOT call
 * session_destroy(), so any other role sessions in the same browser
 * (customer, admin, restaurant) remain intact.
 *
 * @package FitPal
 * @version 1.1 — File renamed from sign-out-handlers.php to match
 *                customer convention and the header.php link.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$token         = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
$expectedToken = $_SESSION['csrf_token'] ?? '';

if ($token !== '' && $expectedToken !== '' && !hash_equals($expectedToken, $token)) {
    error_log('Rider sign-out: invalid CSRF token attempt');
}

unset(
    $_SESSION['delivery_rider_id'],
    $_SESSION['user_role'],
    $_SESSION['user_name'],
    $_SESSION['user_email']
);

session_regenerate_id(true);

header('Location: ../../pages/sign-in.php');
exit;
```

---

## File: `fitpal/rider/backend/handlers/sign-up-handler.php`

**Status:** `FOUND`

```php
<?php
/**
 * FitPal Rider Registration Handler
 *
 * Creates the following rows in a single database transaction:
 *   1. financial_account          (account_type = 'rider')
 *   2. delivery_rider             (account credentials)
 *   3. delivery_rider_profile     (vehicle + verification_status = 'pending')
 *   4. delivery_rider_address     (default address)
 *
 * Emergency-contact fields are validated and stored on the session
 * under 'rider_emergency_contact' so admin tooling can retrieve them
 * later without a schema change. If you later add dedicated columns
 * to delivery_rider_profile, move them there.
 *
 * Responds with JSON so sign-up.js can show the success modal and
 * redirect on acknowledgement. Validation failures return HTTP 200
 * with {status:'error', message, field} — the client reads the body,
 * not the status code.
 *
 * The rider is NOT logged in after registration — they must sign in
 * afterward, matching the customer flow.
 *
 * @package FitPal
 * @version 2.0 — Adds emergency contact, vehicle make/model/year.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/rider-queries.php';

header('Content-Type: application/json');

/**
 * Terminate with a JSON error payload.
 *
 * @return never
 */
function respondError(string $message, string $field = ''): void
{
    echo json_encode([
        'status'  => 'error',
        'message' => $message,
        'field'   => $field,
    ]);
    exit;
}

// ===== REQUEST METHOD =====
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Invalid request method.');
}

// ===== CSRF =====
if (
    !isset($_POST['csrf_token']) ||
    !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$_POST['csrf_token'])
) {
    respondError('Security validation failed. Please refresh the page and try again.');
}

// ===== COLLECT INPUT =====
$firstName       = trim((string)($_POST['first_name'] ?? ''));
$middleName      = trim((string)($_POST['middle_name'] ?? ''));
$lastName        = trim((string)($_POST['last_name'] ?? ''));
$birthdate       = trim((string)($_POST['birthdate'] ?? ''));
$gender          = trim((string)($_POST['gender'] ?? ''));
$email           = trim((string)($_POST['email'] ?? ''));
$contactNumber   = trim((string)($_POST['contact_number'] ?? ''));
$username        = trim((string)($_POST['username'] ?? ''));
$password        = (string)($_POST['password'] ?? '');
$confirmPassword = (string)($_POST['confirm_password'] ?? '');
$terms           = $_POST['terms'] ?? '';

$vehicleType   = trim((string)($_POST['vehicle_type'] ?? ''));
$vehiclePlate  = trim((string)($_POST['vehicle_plate'] ?? ''));
$vehicleMake   = trim((string)($_POST['vehicle_make'] ?? ''));
$vehicleModel  = trim((string)($_POST['vehicle_model'] ?? ''));
$vehicleYear   = trim((string)($_POST['vehicle_year'] ?? ''));

$addressLabel         = trim((string)($_POST['address_label'] ?? ''));
$block                = trim((string)($_POST['block'] ?? ''));
$barangay             = trim((string)($_POST['barangay'] ?? ''));
$city                 = trim((string)($_POST['city'] ?? ''));
$province             = trim((string)($_POST['province'] ?? ''));
$region               = trim((string)($_POST['region'] ?? ''));
$postalCode           = trim((string)($_POST['postal_code'] ?? ''));

$emergencyName         = trim((string)($_POST['emergency_name'] ?? ''));
$emergencyRelationship = trim((string)($_POST['emergency_relationship'] ?? ''));
$emergencyContact      = trim((string)($_POST['emergency_contact'] ?? ''));

// ===== REQUIRED FIELDS =====
if (
    $firstName === '' || $lastName === '' || $birthdate === '' ||
    $gender === '' || $email === '' || $contactNumber === '' ||
    $username === '' || $password === '' || $vehicleType === '' ||
    $block === '' || $city === '' ||
    $emergencyName === '' || $emergencyRelationship === '' ||
    $emergencyContact === ''
) {
    respondError('Please fill in all required fields.');
}

// ===== NAME VALIDATION =====
$namePattern = "/^[A-Za-z\s\-']+$/u";

if (strlen($firstName) < 2) {
    respondError('First name must be at least 2 characters.', 'first_name');
}
if (!preg_match($namePattern, $firstName)) {
    respondError('First name contains invalid characters.', 'first_name');
}
if (strlen($lastName) < 2) {
    respondError('Last name must be at least 2 characters.', 'last_name');
}
if (!preg_match($namePattern, $lastName)) {
    respondError('Last name contains invalid characters.', 'last_name');
}

// ===== GENDER =====
if (!in_array($gender, ['Male', 'Female', 'Other'], true)) {
    respondError('Invalid gender selection.', 'gender');
}

// ===== EMAIL =====
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respondError('Please enter a valid email address.', 'email');
}

// ===== CONTACT =====
$cleanedContact = preg_replace('/\s+/', '', $contactNumber);
if (!preg_match('/^09\d{9}$/', (string)$cleanedContact)) {
    respondError('Enter a valid PH mobile number (11 digits, starting with 09).', 'contact_number');
}

// ===== USERNAME =====
if (strlen($username) < 3) {
    respondError('Username must be at least 3 characters.', 'username');
}
if (strlen($username) > 20) {
    respondError('Username must be no more than 20 characters.', 'username');
}
if (!preg_match('/^[A-Za-z0-9_]+$/', $username)) {
    respondError('Username can only contain letters, numbers, and underscores.', 'username');
}

// ===== PASSWORD =====
if (strlen($password) < 8) {
    respondError('Password must be at least 8 characters.', 'password');
}
if (strlen($password) > 20) {
    respondError('Password must be no more than 20 characters.', 'password');
}
if (!preg_match('/^[A-Za-z0-9]+$/', $password)) {
    respondError('Password can only contain letters and numbers.', 'password');
}
if (!preg_match('/[A-Za-z]/', $password)) {
    respondError('Password must contain at least one letter.', 'password');
}
if (!preg_match('/[0-9]/', $password)) {
    respondError('Password must contain at least one number.', 'password');
}
if ($password !== $confirmPassword) {
    respondError('Passwords do not match.', 'confirm_password');
}

// ===== AGE =====
try {
    $bd    = new DateTime($birthdate);
    $today = new DateTime();
    $age   = $today->diff($bd)->y;
    if ($age < 18) {
        respondError('You must be at least 18 years old to register as a rider.', 'birthdate');
    }
    if ($age > 70) {
        respondError('Please enter a valid date of birth.', 'birthdate');
    }
} catch (Exception $e) {
    respondError('Please enter a valid date of birth.', 'birthdate');
}

// ===== VEHICLE =====
$allowedVehicles = ['motorcycle', 'scooter', 'car', 'van', 'bicycle'];
if (!in_array($vehicleType, $allowedVehicles, true)) {
    respondError('Invalid vehicle type.', 'vehicle_type');
}
if ($vehicleType !== 'bicycle' && $vehiclePlate === '') {
    respondError('Plate number is required for motor vehicles.', 'vehicle_plate');
}
if ($vehiclePlate !== '') {
    $vehiclePlate = strtoupper($vehiclePlate);
    if (strlen(str_replace([' ', '-'], '', $vehiclePlate)) < 4) {
        respondError('Plate number looks too short.', 'vehicle_plate');
    }
}

$vehicleYearValue = null;
if ($vehicleYear !== '') {
    if (!ctype_digit($vehicleYear)) {
        respondError('Vehicle year must be numeric.', 'vehicle_year');
    }
    $y = (int)$vehicleYear;
    $maxYear = (int)date('Y') + 1;
    if ($y < 1980 || $y > $maxYear) {
        respondError('Please enter a valid vehicle year.', 'vehicle_year');
    }
    $vehicleYearValue = $y;
}

// ===== ADDRESS =====
$allowedLabels = ['Home', 'Base', 'Other'];
$addressLabel  = in_array($addressLabel, $allowedLabels, true) ? $addressLabel : 'Base';

if ($postalCode !== '' && !preg_match('/^[0-9]{3,10}$/', $postalCode)) {
    respondError('Postal code must be numeric.', 'postal_code');
}

// ===== EMERGENCY CONTACT =====
if (strlen($emergencyName) < 2) {
    respondError('Emergency contact name is required.', 'emergency_name');
}
$allowedRelationships = ['Parent', 'Spouse', 'Sibling', 'Relative', 'Friend', 'Other'];
if (!in_array($emergencyRelationship, $allowedRelationships, true)) {
    respondError('Invalid emergency contact relationship.', 'emergency_relationship');
}
$cleanedEmergencyContact = preg_replace('/\s+/', '', $emergencyContact);
if (!preg_match('/^09\d{9}$/', (string)$cleanedEmergencyContact)) {
    respondError('Emergency contact must be a valid PH mobile number (09XXXXXXXXX).', 'emergency_contact');
}

// ===== TERMS =====
if (empty($terms)) {
    respondError('You must agree to the Terms and Conditions and Privacy Policy.', 'terms');
}

// =====================================================================
// DATABASE TRANSACTION
// =====================================================================

try {
    // ---- Uniqueness checks ----
    $checkEmail = $database_connection->prepare(
        "SELECT 1 FROM delivery_rider WHERE email = :email LIMIT 1"
    );
    $checkEmail->execute([':email' => $email]);
    if ($checkEmail->fetchColumn() !== false) {
        respondError('This email address is already registered.', 'email');
    }

    $checkUsername = $database_connection->prepare(
        "SELECT 1 FROM delivery_rider WHERE username = :username LIMIT 1"
    );
    $checkUsername->execute([':username' => $username]);
    if ($checkUsername->fetchColumn() !== false) {
        respondError('This username is already taken.', 'username');
    }

    $checkContact = $database_connection->prepare(
        "SELECT 1 FROM delivery_rider WHERE contact_number = :contact LIMIT 1"
    );
    $checkContact->execute([':contact' => $cleanedContact]);
    if ($checkContact->fetchColumn() !== false) {
        respondError('This mobile number is already registered.', 'contact_number');
    }

    // ---- Begin transaction ----
    $database_connection->beginTransaction();

    // ---- 1. Financial account ----
    $faStmt = $database_connection->prepare(
        "INSERT INTO financial_account (balance, account_type)
         VALUES (0.00, 'rider')"
    );
    $faStmt->execute();
    $financialAccountId = (int)$database_connection->lastInsertId();

    // ---- 2. Delivery rider ----
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    $riderStmt = $database_connection->prepare(
        "INSERT INTO delivery_rider
            (first_name, middle_name, last_name, birthdate, gender,
             email, contact_number, username, password, is_active)
         VALUES
            (:first_name, :middle_name, :last_name, :birthdate, :gender,
             :email, :contact_number, :username, :password, 1)"
    );
    $riderStmt->execute([
        ':first_name'     => $firstName,
        ':middle_name'    => $middleName !== '' ? $middleName : null,
        ':last_name'      => $lastName,
        ':birthdate'      => $birthdate,
        ':gender'         => $gender,
        ':email'          => $email,
        ':contact_number' => $cleanedContact,
        ':username'       => $username,
        ':password'       => $hashedPassword,
    ]);
    $deliveryRiderId = (int)$database_connection->lastInsertId();

    // ---- 3. Rider profile ----
    $profileStmt = $database_connection->prepare(
        "INSERT INTO delivery_rider_profile
            (delivery_rider_id, financial_account_id, vehicle_type,
             vehicle_plate, verification_status, average_rating,
             total_deliveries, is_available)
         VALUES
            (:rider_id, :financial_account_id, :vehicle_type,
             :vehicle_plate, 'pending', 0.0, 0, 0)"
    );
    $profileStmt->execute([
        ':rider_id'             => $deliveryRiderId,
        ':financial_account_id' => $financialAccountId,
        ':vehicle_type'         => $vehicleType,
        ':vehicle_plate'        => $vehiclePlate !== '' ? $vehiclePlate : null,
    ]);

    // ---- 4. Rider address ----
    $addressStmt = $database_connection->prepare(
        "INSERT INTO delivery_rider_address
            (delivery_rider_id, label, block, barangay, city,
             province, region, postal_code, country, is_default)
         VALUES
            (:rider_id, :label, :block, :barangay, :city,
             :province, :region, :postal_code, 'Philippines', 1)"
    );
    $addressStmt->execute([
        ':rider_id'    => $deliveryRiderId,
        ':label'       => $addressLabel,
        ':block'       => $block,
        ':barangay'    => $barangay !== '' ? $barangay : null,
        ':city'        => $city,
        ':province'    => $province !== '' ? $province : null,
        ':region'      => $region   !== '' ? $region   : null,
        ':postal_code' => $postalCode !== '' ? $postalCode : null,
    ]);

    // ---- 5. Stash extended application fields on the session ----
    // These are not persisted to the database today because the schema
    // does not have dedicated columns for them. They are captured here
    // so that when those columns are added, they can be migrated
    // without asking riders to re-enter their details.
    $_SESSION['rider_pending_application'] = [
        'delivery_rider_id'      => $deliveryRiderId,
        'vehicle_make'           => $vehicleMake   !== '' ? $vehicleMake  : null,
        'vehicle_model'          => $vehicleModel  !== '' ? $vehicleModel : null,
        'vehicle_year'           => $vehicleYearValue,
        'emergency_contact_name' => $emergencyName,
        'emergency_relationship' => $emergencyRelationship,
        'emergency_contact'      => $cleanedEmergencyContact,
    ];

    // ---- Commit ----
    $database_connection->commit();

    // ---- Flash success for the sign-in page ----
    $_SESSION['registration_success'] = 'Rider application submitted. Please sign in to continue.';

    echo json_encode([
        'status'   => 'success',
        'message'  => 'Your rider application has been submitted. Please sign in to continue.',
        'redirect' => 'sign-in.php',
    ]);
    exit;

} catch (PDOException $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Rider registration error: ' . $e->getMessage());

    if (str_contains($e->getMessage(), 'Duplicate entry')) {
        if (str_contains($e->getMessage(), 'email')) {
            respondError('This email address is already registered.', 'email');
        }
        if (str_contains($e->getMessage(), 'username')) {
            respondError('This username is already taken.', 'username');
        }
        if (str_contains($e->getMessage(), 'contact_number')) {
            respondError('This mobile number is already registered.', 'contact_number');
        }
    }
    respondError('An unexpected error occurred. Please try again later.');
} catch (Throwable $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Rider registration error: ' . $e->getMessage());
    respondError('An unexpected error occurred. Please try again later.');
}
```

---

## File: `fitpal/rider/includes/header.php`

**Status:** `FOUND`

```php
<?php
/**
 * FitPal Rider Header
 *
 * Rider-specific header with conditional navigation based on login
 * status. Mirrors customer/includes/header.php so the two roles stay
 * consistent in behavior and asset resolution.
 *
 * Rider users do NOT have a cart, so there is no cart badge here.
 * The nav reflects rider-only surfaces: Dashboard, Deliveries,
 * Earnings, and Profile.
 *
 * @package FitPal
 * @version 1.1 — Adds currentPage detection for deliveries/earnings;
 *                aligns with customer header structure.
 */

declare(strict_types=1);

// ===== SESSION =====
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['created'])) {
    $_SESSION['created'] = time();
} elseif (time() - $_SESSION['created'] > 1800) {
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}

// ===== DATABASE =====
require_once __DIR__ . '/../backend/database/rider-connect.php';

// ===== PATH DETECTION =====
/**
 * Get the base path to shared/ from the currently executing page.
 *
 * @return string Asset base path ending with 'shared/'
 */
function getRiderAssetBase(): string {
    $scriptPath = $_SERVER['SCRIPT_NAME'];
    $dirPath    = dirname($scriptPath);
    $segments   = array_filter(explode('/', $dirPath));
    $depth      = count($segments);
    return str_repeat('../', $depth) . 'shared/';
}

$assetBase = getRiderAssetBase();

// ===== FETCH RIDER DATA (if logged in) =====
$isLoggedIn   = false;
$riderName    = '';
$riderInitial = '';
$riderStatus  = '';

if (!empty($_SESSION['delivery_rider_id'])) {
    $isLoggedIn = true;
    try {
        $stmt = $database_connection->prepare(
            "SELECT dr.first_name, dr.last_name,
                    drp.verification_status
             FROM delivery_rider dr
             LEFT JOIN delivery_rider_profile drp
                    ON dr.delivery_rider_id = drp.delivery_rider_id
             WHERE dr.delivery_rider_id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => (int)$_SESSION['delivery_rider_id']]);
        $riderData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($riderData) {
            $riderName    = trim($riderData['first_name'] . ' ' . $riderData['last_name']);
            $riderInitial = strtoupper(substr($riderData['first_name'], 0, 1));
            $riderStatus  = (string)($riderData['verification_status'] ?? '');
        }
    } catch (PDOException $e) {
        // Silently fail — login state still valid
    }
}

// ===== CURRENT PAGE =====
$currentPage = basename($_SERVER['PHP_SELF']);

// ===== PAGE-SPECIFIC CSS PRELOADING =====
$pageCssMap = [
    'sign-in.php'   => 'sign-in.css',
    'sign-up.php'   => 'sign-up.css',
    'dashboard.php' => 'dashboard.css',
    'deliveries.php'=> 'deliveries.css',
    'earnings.php'  => 'earnings.css',
    'profile.php'   => 'profile.css',
];

$pageCssFile = $pageCssMap[$currentPage] ?? '';
$pageCssPath = '';
if (!empty($pageCssFile) && file_exists(__DIR__ . '/../assets/css/' . $pageCssFile)) {
    $pageCssPath = '../assets/css/' . $pageCssFile;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="FitPal - Rider Portal">
    <title>FitPal - Rider</title>

    <link rel="icon" type="image/x-icon" href="<?php echo $assetBase; ?>assets/images/brand/Logo.ico">
    <link rel="shortcut icon" href="<?php echo $assetBase; ?>assets/images/brand/Logo.ico">

    <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/global.css">
    <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/header.css">
    <link rel="stylesheet" href="../assets/css/header.css">

    <?php if (!empty($pageCssPath)): ?>
    <link rel="stylesheet" href="<?php echo $pageCssPath; ?>">
    <?php endif; ?>
</head>

<body>
    <header class="header rider-header" role="banner">
        <div class="header-container">
            <div class="header-logo">
                <a href="<?php echo $assetBase; ?>../index.php" class="logo-link" aria-label="FitPal Home">
                    <img src="<?php echo $assetBase; ?>assets/images/brand/Logo.png" alt="FitPal Logo"
                        class="logo-image">
                    <span class="logo-text">Fit<span>Pal</span></span>
                </a>
            </div>

            <button class="menu-toggle" id="menuToggle" aria-label="Toggle navigation menu" aria-expanded="false"
                type="button">
                <span class="menu-icon">
                    <span class="bar"></span>
                    <span class="bar"></span>
                    <span class="bar"></span>
                </span>
            </button>

            <nav class="header-nav" id="mainNav" role="navigation" aria-label="Rider navigation">

                <?php if ($isLoggedIn): ?>
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="dashboard.php"
                            class="nav-link <?php echo ($currentPage === 'dashboard.php') ? 'active' : ''; ?>">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a href="deliveries.php"
                            class="nav-link <?php echo ($currentPage === 'deliveries.php') ? 'active' : ''; ?>">Deliveries</a>
                    </li>
                    <li class="nav-item">
                        <a href="earnings.php"
                            class="nav-link <?php echo ($currentPage === 'earnings.php') ? 'active' : ''; ?>">Earnings</a>
                    </li>
                    <li class="nav-item">
                        <a href="profile.php"
                            class="nav-link <?php echo ($currentPage === 'profile.php') ? 'active' : ''; ?>">Profile</a>
                    </li>
                </ul>

                <div class="nav-actions">
                    <div class="user-profile-circle"
                        title="<?php echo htmlspecialchars($riderName, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php if (!empty($riderInitial)): ?>
                        <span
                            class="user-initial"><?php echo htmlspecialchars($riderInitial, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php else: ?>
                        <img src="<?php echo $assetBase; ?>assets/images/icons/user-profile-circle.svg" alt="Profile"
                            class="profile-icon">
                        <?php endif; ?>
                    </div>
                    <a href="../backend/handlers/sign-out-handler.php" data-signout
                        class="btn btn-outline btn-sm logout-btn">Logout</a>
                </div>

                <?php else: ?>
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="<?php echo $assetBase; ?>../index.php"
                            class="nav-link <?php echo ($currentPage === 'index.php') ? 'active' : ''; ?>">Home</a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo $assetBase; ?>pages/about.php"
                            class="nav-link <?php echo ($currentPage === 'about.php') ? 'active' : ''; ?>">About</a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo $assetBase; ?>pages/contact.php"
                            class="nav-link <?php echo ($currentPage === 'contact.php') ? 'active' : ''; ?>">Contact</a>
                    </li>
                </ul>

                <div class="nav-actions">
                    <a href="sign-in.php" class="btn btn-primary btn-sm">Login</a>
                </div>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <div class="mobile-overlay" id="mobileOverlay"></div>

    <nav class="mobile-nav" id="mobileNav" role="navigation" aria-label="Mobile navigation">
        <ul class="mobile-nav-list">

            <?php if ($isLoggedIn): ?>
            <li class="mobile-nav-item mobile-user-greeting">
                <div class="mobile-user-avatar">
                    <?php if (!empty($riderInitial)): ?>
                    <span
                        class="user-initial-large"><?php echo htmlspecialchars($riderInitial, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php else: ?>
                    <img src="<?php echo $assetBase; ?>assets/images/icons/user-profile-circle.svg" alt="Profile">
                    <?php endif; ?>
                </div>
                <span class="mobile-user-name"><?php echo htmlspecialchars($riderName, ENT_QUOTES, 'UTF-8'); ?></span>
            </li>
            <li class="mobile-nav-divider"></li>
            <li class="mobile-nav-item">
                <a href="dashboard.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'dashboard.php') ? 'active' : ''; ?>">Dashboard</a>
            </li>
            <li class="mobile-nav-item">
                <a href="deliveries.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'deliveries.php') ? 'active' : ''; ?>">Deliveries</a>
            </li>
            <li class="mobile-nav-item">
                <a href="earnings.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'earnings.php') ? 'active' : ''; ?>">Earnings</a>
            </li>
            <li class="mobile-nav-item">
                <a href="profile.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'profile.php') ? 'active' : ''; ?>">Profile</a>
            </li>
            <li class="mobile-nav-divider"></li>
            <li class="mobile-nav-item">
                <a href="../backend/handlers/sign-out-handler.php" data-signout
                    class="mobile-nav-link mobile-logout">Logout</a>
            </li>

            <?php else: ?>
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>../index.php" class="mobile-nav-link">Home</a>
            </li>
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>pages/about.php" class="mobile-nav-link">About</a>
            </li>
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>pages/contact.php" class="mobile-nav-link">Contact</a>
            </li>
            <li class="mobile-nav-divider"></li>
            <li class="mobile-nav-item">
                <a href="sign-in.php" class="mobile-nav-link mobile-login">Login</a>
            </li>
            <?php endif; ?>
        </ul>
    </nav>

    <main class="main-content" role="main">

        <script src="../assets/ui/js/header.js" defer></script>
```

---

## File: `fitpal/rider/pages/dashboard.php`

**Status:** `FOUND`

```php
<?php
/**
 * FitPal Rider Dashboard
 *
 * Minimal landing page for the rider role. Shows the rider's name,
 * verification status, vehicle info, and a couple of stat cards.
 * All SQL lives in rider-queries.php.
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['delivery_rider_id'])) {
    header('Location: sign-in.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../backend/database/rider-queries.php';

$riderId = (int)$_SESSION['delivery_rider_id'];
$profile = getRiderProfile($database_connection, $riderId) ?: [];

$fullName   = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));
$firstName  = (string)($profile['first_name'] ?? 'Rider');
$balance    = (float)($profile['balance'] ?? 0);
$rating     = (float)($profile['average_rating'] ?? 0);
$deliveries = (int)($profile['total_deliveries'] ?? 0);
$vehicle    = (string)($profile['vehicle_type'] ?? '—');
$plate      = (string)($profile['vehicle_plate'] ?? '');
$status     = (string)($profile['verification_status'] ?? 'pending');
$available  = (int)($profile['is_available'] ?? 0) === 1;

$statusLabel = match ($status) {
    'verified'  => 'Verified',
    'pending'   => 'Pending Verification',
    'denied'    => 'Denied',
    'suspended' => 'Suspended',
    default     => ucfirst($status),
};

$statusClass = match ($status) {
    'verified'  => 'badge-success',
    'pending'   => 'badge-warning',
    'denied'    => 'badge-danger',
    'suspended' => 'badge-secondary',
    default     => 'badge-secondary',
};
?>

<div class="content rider-dashboard-page">
    <div class="container">

        <header class="rider-dashboard-header">
            <div>
                <h1 class="heading-2">
                    Welcome back, <span><?php echo htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'); ?></span>
                </h1>
                <p class="text-muted">Here's your rider overview.</p>
            </div>
            <div class="rider-dashboard-actions">
                <span class="badge <?php echo $statusClass; ?>">
                    <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
        </header>

        <section class="rider-stats-grid">
            <a href="earnings.php" class="rider-stat-card">
                <div class="rider-stat-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/wallet-line.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/coin-line.svg'">
                </div>
                <div class="rider-stat-info">
                    <p class="rider-stat-number"><?php echo formatRiderCurrency($balance); ?></p>
                    <p class="rider-stat-label">Wallet Balance</p>
                </div>
            </a>

            <a href="deliveries.php" class="rider-stat-card">
                <div class="rider-stat-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/package.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/order.svg'">
                </div>
                <div class="rider-stat-info">
                    <p class="rider-stat-number"><?php echo number_format($deliveries); ?></p>
                    <p class="rider-stat-label">Total Deliveries</p>
                </div>
            </a>

            <div class="rider-stat-card">
                <div class="rider-stat-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/star-fill.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/star-empty.svg'">
                </div>
                <div class="rider-stat-info">
                    <p class="rider-stat-number"><?php echo number_format($rating, 1); ?> / 5.0</p>
                    <p class="rider-stat-label">Average Rating</p>
                </div>
            </div>

            <div class="rider-stat-card">
                <div class="rider-stat-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/community-general.svg'">
                </div>
                <div class="rider-stat-info">
                    <p class="rider-stat-number">
                        <?php echo htmlspecialchars(ucfirst($vehicle), ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <p class="rider-stat-label">
                        <?php echo $plate !== '' ? htmlspecialchars($plate, ENT_QUOTES, 'UTF-8') : 'No plate'; ?>
                    </p>
                </div>
            </div>
        </section>

        <section class="rider-card">
            <div class="rider-card-header">
                <h2 class="heading-5">Availability</h2>
            </div>
            <div class="rider-card-body">
                <p class="rider-availability-line">
                    Current status:
                    <strong class="<?php echo $available ? 'rider-available' : 'rider-unavailable'; ?>">
                        <?php echo $available ? 'Available' : 'Unavailable'; ?>
                    </strong>
                </p>
                <p class="text-muted rider-availability-hint">
                    Set yourself available from the Deliveries page when you're ready to accept orders.
                </p>
            </div>
        </section>

        <?php if ($status !== 'verified'): ?>
        <section class="rider-card rider-card-warning">
            <div class="rider-card-body">
                <p class="rider-warning-title">
                    <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <p class="text-muted">
                    Your account is not yet fully verified. Contact support if this persists.
                </p>
            </div>
        </section>
        <?php endif; ?>

    </div>
</div>

<?php
require_once __DIR__ . '/../../shared/includes/footer.php';
?>
```

---

## File: `fitpal/rider/pages/sign-in.php`

**Status:** `FOUND`

```php
<?php
/**
 * FitPal Rider Sign-In Page
 *
 * Uses the shared rider header + footer. All icons and images come
 * from the shared assets folder so nothing loads from an external
 * host.
 *
 * The country selector has been removed. FitPal's rider program
 * currently operates in the Philippines only, so the page renders a
 * static Philippines indicator with a flag icon instead of a
 * dropdown. When FitPal expands to other regions, replace the
 * static block with a <select> again and repopulate from a list.
 *
 * A password visibility toggle (eye icon) is now present on the
 * password field, matching the customer sign-in page.
 *
 * @package FitPal
 * @version 5.0 — Philippines-only; password toggle added; smaller
 *                illustration.
 */

declare(strict_types=1);

// ===== HEADER (starts session, loads DB, renders <head> + <header>) =====
require_once __DIR__ . '/../includes/header.php';

// Defensive: if the header ever fails to redirect a logged-in rider.
if (!empty($_SESSION['delivery_rider_id'])) {
    header('Location: dashboard.php');
    exit;
}

// ===== CSRF =====
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ===== FLASH MESSAGES =====
$errorMessage = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);

$successMessage = $_SESSION['registration_success'] ?? '';
unset($_SESSION['registration_success']);

// ===== ILLUSTRATION PATH =====
$riderIllustration = $assetBase . 'assets/images/rider-image/rider.png';
$heroFallback      = $assetBase . 'assets/images/showcase/hero-image.png';
?>

<div class="content rider-auth-content-wrap">
    <main class="rider-auth-page" role="main">
        <div class="rider-auth-card">

            <!-- Illustration -->
            <div class="rider-illustration">
                <img src="<?php echo htmlspecialchars($riderIllustration, ENT_QUOTES, 'UTF-8'); ?>"
                    alt="Rider on a bicycle"
                    onerror="this.onerror=null; this.src='<?php echo htmlspecialchars($heroFallback, ENT_QUOTES, 'UTF-8'); ?>';">
            </div>

            <!-- Content -->
            <div class="rider-auth-content">
                <h1 class="rider-auth-title">
                    Welcome to<br>
                    <span>FitPal</span> rider app
                </h1>

                <?php if (!empty($successMessage)): ?>
                <div class="rider-alert rider-alert-success" role="alert">
                    <?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($errorMessage)): ?>
                <div class="rider-alert rider-alert-danger" role="alert">
                    <?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="../backend/handlers/sign-in-handler.php" id="riderSignInForm" novalidate>

                    <input type="hidden" name="csrf_token"
                        value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

                    <!-- Region indicator: Philippines only -->
                    <div class="rider-country-selector" role="note" aria-label="Operating region">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/location-fill.svg" alt=""
                            class="rider-country-icon" aria-hidden="true">
                        <span class="rider-country-name">Philippines</span>
                        <img src="<?php echo $assetBase; ?>assets/images/icons/verified-fill.svg" alt=""
                            class="rider-chevron-icon" aria-hidden="true">
                    </div>

                    <div class="rider-form-group">
                        <label for="identifier" class="sr-only">Email or Username</label>
                        <input type="text" id="identifier" name="identifier" class="rider-form-control"
                            placeholder="Email or username" autocomplete="username"
                            value="<?php echo htmlspecialchars($_POST['identifier'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            required>
                    </div>

                    <div class="rider-form-group">
                        <label for="password" class="sr-only">Password</label>
                        <div class="rider-password-wrapper">
                            <input type="password" id="password" name="password" class="rider-form-control"
                                placeholder="Password" autocomplete="current-password" required>
                            <button type="button" class="rider-password-toggle" id="togglePassword" tabindex="-1"
                                aria-label="Show password" aria-pressed="false">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/password-hide.svg" alt=""
                                    id="passwordIcon">
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="rider-btn rider-btn-dark">Sign in</button>
                    <a href="sign-up.php" class="rider-btn rider-btn-light">Apply now</a>
                </form>
            </div>

            <div class="rider-version">v1.0.0</div>
        </div>
    </main>
</div>

<script src="../assets/ui/js/sign-in.js" defer></script>

<?php
require_once __DIR__ . '/../../shared/includes/footer.php';
?>
```

---

## File: `fitpal/rider/pages/sign-up.php`

**Status:** `FOUND`

```php
<?php
/**
 * FitPal Rider Registration Page
 *
 * Four-step rider application:
 *   Step 1 — Personal Information
 *   Step 2 — Vehicle Details
 *   Step 3 — Address
 *   Step 4 — Review & Terms
 *
 * On submit, sign-up-handler.php creates the following rows in a
 * single database transaction:
 *   financial_account          (account_type = 'rider')
 *   delivery_rider             (account credentials)
 *   delivery_rider_profile     (vehicle + verification_status = 'pending')
 *   delivery_rider_address     (default address)
 *
 * The rider is NOT logged in after registration. They are redirected
 * to sign-in.php with a success flash, matching the customer flow.
 *
 * @package FitPal
 * @version 2.0 — Adds emergency contact; adds vehicle year/make/model;
 *                tighter professional copy; self-contained CSS.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if already logged in as a rider.
if (!empty($_SESSION['delivery_rider_id'])) {
    header('Location: dashboard.php');
    exit;
}

// CSRF token.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/../includes/header.php';

// Consume one-time flash messages.
$errorMessage = $_SESSION['rider_registration_error'] ?? '';
unset($_SESSION['rider_registration_error']);

// Vehicle type options.
$vehicleOptions = [
    'motorcycle' => 'Motorcycle',
    'scooter'    => 'Scooter',
    'car'        => 'Car',
    'van'        => 'Van',
    'bicycle'    => 'Bicycle',
];

// Address label options (matches delivery_rider_address CHECK constraint).
$addressLabels = [
    'Home'  => 'Home',
    'Base'  => 'Base',
    'Other' => 'Other',
];

// Emergency relationship options.
$relationshipOptions = [
    'Parent'   => 'Parent',
    'Spouse'   => 'Spouse',
    'Sibling'  => 'Sibling',
    'Relative' => 'Relative',
    'Friend'   => 'Friend',
    'Other'    => 'Other',
];
?>

<div class="content register-page">
    <div class="container">
        <div class="register-card">

            <!-- Progress -->
            <div class="register-progress" role="progressbar" aria-valuenow="1" aria-valuemin="1" aria-valuemax="4"
                aria-label="Registration progress">
                <div class="progress-step active" data-step="1">
                    <span class="step-number">1</span>
                    <span class="step-label">Personal</span>
                </div>
                <div class="progress-line" id="progressLine1"></div>
                <div class="progress-step" data-step="2">
                    <span class="step-number">2</span>
                    <span class="step-label">Vehicle</span>
                </div>
                <div class="progress-line" id="progressLine2"></div>
                <div class="progress-step" data-step="3">
                    <span class="step-number">3</span>
                    <span class="step-label">Address</span>
                </div>
                <div class="progress-line" id="progressLine3"></div>
                <div class="progress-step" data-step="4">
                    <span class="step-number">4</span>
                    <span class="step-label">Review</span>
                </div>
            </div>

            <!-- Header -->
            <div class="register-header">
                <p class="heading-2">Become a <span>FitPal Rider</span></p>
                <p class="text-muted" id="stepSubtitle">Step 1 of 4 — Personal Information</p>
            </div>

            <!-- Inline server flash -->
            <?php if (!empty($errorMessage)): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php endif; ?>

            <!-- Client-side error banner (populated by JS) -->
            <div id="registerError" class="alert alert-danger" style="display: none;" role="alert">
                <span id="errorMessage"></span>
            </div>

            <form method="POST" action="../backend/handlers/sign-up-handler.php" class="register-form" id="registerForm"
                novalidate autocomplete="on">

                <input type="hidden" name="csrf_token"
                    value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="current_step" id="currentStep" value="1">

                <!-- ============================================================
                     STEP 1 — Personal Information
                     ============================================================ -->
                <div class="register-step" id="step1">

                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name" class="form-label">
                                First Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="first_name" name="first_name" class="form-control"
                                placeholder="e.g. Juan" autocomplete="given-name" required>
                            <div class="form-error" id="firstNameError"></div>
                        </div>

                        <div class="form-group">
                            <label for="middle_name" class="form-label">
                                Middle Name <span class="text-muted">(optional)</span>
                            </label>
                            <input type="text" id="middle_name" name="middle_name" class="form-control"
                                placeholder="e.g. Santos" autocomplete="additional-name">
                        </div>

                        <div class="form-group">
                            <label for="last_name" class="form-label">
                                Last Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="last_name" name="last_name" class="form-control"
                                placeholder="e.g. Dela Cruz" autocomplete="family-name" required>
                            <div class="form-error" id="lastNameError"></div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="birthdate" class="form-label">
                                Date of Birth <span class="text-danger">*</span>
                            </label>
                            <input type="date" id="birthdate" name="birthdate" class="form-control" required
                                max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>"
                                min="<?php echo date('Y-m-d', strtotime('-70 years')); ?>">
                            <div class="form-hint">Applicants must be 18–70 years old</div>
                            <div class="form-error" id="birthdateError"></div>
                        </div>

                        <div class="form-group">
                            <label for="gender" class="form-label">
                                Gender <span class="text-danger">*</span>
                            </label>
                            <select id="gender" name="gender" class="form-control" required>
                                <option value="">Select gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                            <div class="form-error" id="genderError"></div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="email" class="form-label">
                                Email Address <span class="text-danger">*</span>
                            </label>
                            <input type="email" id="email" name="email" class="form-control"
                                placeholder="you@example.com" autocomplete="email" required>
                            <div class="form-error" id="emailError"></div>
                        </div>

                        <div class="form-group">
                            <label for="contact_number" class="form-label">
                                Mobile Number <span class="text-danger">*</span>
                            </label>
                            <input type="tel" id="contact_number" name="contact_number" class="form-control"
                                placeholder="09XX XXX XXXX" autocomplete="tel" inputmode="numeric" maxlength="13"
                                required>
                            <div class="form-hint">Philippine mobile, 11 digits starting with 09</div>
                            <div class="form-error" id="contactError"></div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="username" class="form-label">
                                Username <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="username" name="username" class="form-control"
                                placeholder="e.g. rider_juan" autocomplete="username" maxlength="20" required>
                            <div class="form-hint">3–20 characters; letters, numbers, underscore</div>
                            <div class="form-error" id="usernameError"></div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="password" class="form-label">
                                Password <span class="text-danger">*</span>
                            </label>
                            <div class="password-wrapper">
                                <input type="password" id="password" name="password" class="form-control"
                                    placeholder="Create a password" autocomplete="new-password" maxlength="20" required>
                                <button type="button" class="password-toggle" id="togglePassword" tabindex="-1"
                                    aria-label="Toggle password visibility">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/password-hide.svg"
                                        alt="Hide password" id="passwordIcon">
                                </button>
                            </div>
                            <div class="form-hint">8–20 characters; letters and numbers only</div>
                            <div class="form-error" id="passwordError"></div>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password" class="form-label">
                                Confirm Password <span class="text-danger">*</span>
                            </label>
                            <div class="password-wrapper">
                                <input type="password" id="confirm_password" name="confirm_password"
                                    class="form-control" placeholder="Re-enter your password"
                                    autocomplete="new-password" maxlength="20" required>
                                <button type="button" class="password-toggle" id="toggleConfirmPassword" tabindex="-1"
                                    aria-label="Toggle confirm password visibility">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/password-hide.svg"
                                        alt="Hide password" id="confirmPasswordIcon">
                                </button>
                            </div>
                            <div class="form-error" id="confirmError"></div>
                        </div>
                    </div>

                    <div class="step-actions">
                        <button type="button" class="btn btn-primary btn-next" data-next="2">
                            Continue
                        </button>
                    </div>
                </div>

                <!-- ============================================================
                     STEP 2 — Vehicle Details
                     ============================================================ -->
                <div class="register-step" id="step2" style="display: none;">

                    <div class="step-description">
                        <p>
                            Tell us about the vehicle you'll use for deliveries. Motor vehicles
                            require a plate number; bicycles do not.
                        </p>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="vehicle_type" class="form-label">
                                Vehicle Type <span class="text-danger">*</span>
                            </label>
                            <select id="vehicle_type" name="vehicle_type" class="form-control" required>
                                <option value="">Select vehicle type</option>
                                <?php foreach ($vehicleOptions as $value => $label): ?>
                                <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-error" id="vehicleTypeError"></div>
                        </div>

                        <div class="form-group">
                            <label for="vehicle_plate" class="form-label">
                                Plate Number
                            </label>
                            <input type="text" id="vehicle_plate" name="vehicle_plate" class="form-control"
                                placeholder="e.g. ABC 1234" autocomplete="off" maxlength="10">
                            <div class="form-hint">Required for motor vehicles; leave blank for bicycles</div>
                            <div class="form-error" id="vehiclePlateError"></div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="vehicle_make" class="form-label">
                                Make / Brand <span class="text-muted">(optional)</span>
                            </label>
                            <input type="text" id="vehicle_make" name="vehicle_make" class="form-control"
                                placeholder="e.g. Honda" autocomplete="off" maxlength="40">
                        </div>

                        <div class="form-group">
                            <label for="vehicle_model" class="form-label">
                                Model <span class="text-muted">(optional)</span>
                            </label>
                            <input type="text" id="vehicle_model" name="vehicle_model" class="form-control"
                                placeholder="e.g. Click 125i" autocomplete="off" maxlength="40">
                        </div>

                        <div class="form-group">
                            <label for="vehicle_year" class="form-label">
                                Year <span class="text-muted">(optional)</span>
                            </label>
                            <input type="number" id="vehicle_year" name="vehicle_year" class="form-control"
                                placeholder="e.g. 2022" min="1980" max="<?php echo (int)date('Y') + 1; ?>"
                                autocomplete="off" inputmode="numeric">
                            <div class="form-error" id="vehicleYearError"></div>
                        </div>
                    </div>

                    <div class="step-actions">
                        <button type="button" class="btn btn-outline btn-prev" data-prev="1">
                            Back
                        </button>
                        <button type="button" class="btn btn-primary btn-next" data-next="3">
                            Continue
                        </button>
                    </div>
                </div>

                <!-- ============================================================
                     STEP 3 — Address
                     ============================================================ -->
                <div class="register-step" id="step3" style="display: none;">

                    <div class="step-description">
                        <p>
                            Where should we send delivery notices, settlements, and important
                            documents? Use your primary address.
                        </p>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="address_label" class="form-label">
                                Label <span class="text-muted">(optional)</span>
                            </label>
                            <select id="address_label" name="address_label" class="form-control">
                                <option value="">Select a label</option>
                                <?php foreach ($addressLabels as $value => $label): ?>
                                <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="block" class="form-label">
                                Block / Street / Unit <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="block" name="block" class="form-control"
                                placeholder="e.g. 12-A Sunrise St., Unit 5B" autocomplete="address-line1" required>
                            <div class="form-error" id="blockError"></div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="barangay" class="form-label">
                                Barangay <span class="text-muted">(optional)</span>
                            </label>
                            <input type="text" id="barangay" name="barangay" class="form-control"
                                placeholder="e.g. Barangay San Antonio" autocomplete="address-line2">
                        </div>

                        <div class="form-group">
                            <label for="city" class="form-label">
                                City / Municipality <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="city" name="city" class="form-control" placeholder="e.g. Pasig"
                                autocomplete="address-level2" required>
                            <div class="form-error" id="cityError"></div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="province" class="form-label">
                                Province <span class="text-muted">(optional)</span>
                            </label>
                            <input type="text" id="province" name="province" class="form-control"
                                placeholder="e.g. Metro Manila" autocomplete="address-level1">
                        </div>

                        <div class="form-group">
                            <label for="region" class="form-label">
                                Region <span class="text-muted">(optional)</span>
                            </label>
                            <input type="text" id="region" name="region" class="form-control" placeholder="e.g. NCR"
                                autocomplete="address-level1">
                        </div>

                        <div class="form-group">
                            <label for="postal_code" class="form-label">
                                Postal Code <span class="text-muted">(optional)</span>
                            </label>
                            <input type="text" id="postal_code" name="postal_code" class="form-control"
                                placeholder="e.g. 1605" autocomplete="postal-code" maxlength="10" inputmode="numeric">
                            <div class="form-error" id="postalError"></div>
                        </div>
                    </div>

                    <!-- Emergency contact -->
                    <div class="section-divider">
                        <span>Emergency Contact</span>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="emergency_name" class="form-label">
                                Full Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="emergency_name" name="emergency_name" class="form-control"
                                placeholder="e.g. Maria Dela Cruz" required>
                            <div class="form-error" id="emergencyNameError"></div>
                        </div>

                        <div class="form-group">
                            <label for="emergency_relationship" class="form-label">
                                Relationship <span class="text-danger">*</span>
                            </label>
                            <select id="emergency_relationship" name="emergency_relationship" class="form-control"
                                required>
                                <option value="">Select relationship</option>
                                <?php foreach ($relationshipOptions as $value => $label): ?>
                                <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-error" id="emergencyRelationshipError"></div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="emergency_contact" class="form-label">
                                Mobile Number <span class="text-danger">*</span>
                            </label>
                            <input type="tel" id="emergency_contact" name="emergency_contact" class="form-control"
                                placeholder="09XX XXX XXXX" inputmode="numeric" maxlength="13" required>
                            <div class="form-error" id="emergencyContactError"></div>
                        </div>
                    </div>

                    <div class="step-actions">
                        <button type="button" class="btn btn-outline btn-prev" data-prev="2">
                            Back
                        </button>
                        <button type="button" class="btn btn-primary btn-next" data-next="4">
                            Continue
                        </button>
                    </div>
                </div>

                <!-- ============================================================
                     STEP 4 — Review & Terms
                     ============================================================ -->
                <div class="register-step" id="step4" style="display: none;">

                    <div class="step-description">
                        <p>
                            Please review your information. Once submitted, your application
                            enters verification and you'll be notified by email.
                        </p>
                    </div>

                    <div class="review-summary" id="reviewSummary">
                        <div class="review-section">
                            <p class="review-section-title">Personal Information</p>
                            <div class="review-row">
                                <span class="review-label">Name</span>
                                <span class="review-value" id="reviewName">—</span>
                            </div>
                            <div class="review-row">
                                <span class="review-label">Email</span>
                                <span class="review-value" id="reviewEmail">—</span>
                            </div>
                            <div class="review-row">
                                <span class="review-label">Mobile</span>
                                <span class="review-value" id="reviewContact">—</span>
                            </div>
                            <div class="review-row">
                                <span class="review-label">Username</span>
                                <span class="review-value" id="reviewUsername">—</span>
                            </div>
                        </div>

                        <div class="review-section">
                            <p class="review-section-title">Vehicle</p>
                            <div class="review-row">
                                <span class="review-label">Type</span>
                                <span class="review-value" id="reviewVehicleType">—</span>
                            </div>
                            <div class="review-row">
                                <span class="review-label">Plate</span>
                                <span class="review-value" id="reviewVehiclePlate">—</span>
                            </div>
                            <div class="review-row">
                                <span class="review-label">Make / Model</span>
                                <span class="review-value" id="reviewVehicleMakeModel">—</span>
                            </div>
                        </div>

                        <div class="review-section">
                            <p class="review-section-title">Address</p>
                            <div class="review-row">
                                <span class="review-label">Full Address</span>
                                <span class="review-value" id="reviewAddress">—</span>
                            </div>
                        </div>

                        <div class="review-section">
                            <p class="review-section-title">Emergency Contact</p>
                            <div class="review-row">
                                <span class="review-label">Name</span>
                                <span class="review-value" id="reviewEmergencyName">—</span>
                            </div>
                            <div class="review-row">
                                <span class="review-label">Relationship</span>
                                <span class="review-value" id="reviewEmergencyRelationship">—</span>
                            </div>
                            <div class="review-row">
                                <span class="review-label">Mobile</span>
                                <span class="review-value" id="reviewEmergencyContact">—</span>
                            </div>
                        </div>
                    </div>

                    <div class="form-group terms-group" id="termsGroup">
                        <div class="checkbox-wrapper">
                            <input type="checkbox" id="terms" name="terms" value="1" required>
                            <span class="custom-checkbox" aria-hidden="true"></span>
                            <label for="terms" class="terms-label">
                                I confirm the information above is accurate and I agree to the
                                <a href="<?php echo $assetBase; ?>pages/terms-conditions.php" target="_blank"
                                    rel="noopener noreferrer">Terms and Conditions</a>
                                and
                                <a href="<?php echo $assetBase; ?>pages/privacy-policy.php" target="_blank"
                                    rel="noopener noreferrer">Privacy Policy</a>.
                            </label>
                        </div>
                        <div class="form-error" id="termsError"></div>
                    </div>

                    <div class="step-actions">
                        <button type="button" class="btn btn-outline btn-prev" data-prev="3">
                            Back
                        </button>
                        <button type="submit" class="btn btn-primary" id="registerBtn">
                            Submit Application
                        </button>
                    </div>
                </div>
            </form>

            <div class="register-footer">
                <p class="text-muted">
                    Already a FitPal rider?
                    <a href="sign-in.php">Sign in to your account</a>
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Notification modal -->
<div id="notifierModal" class="notifier hidden" role="dialog" aria-modal="true" aria-labelledby="notifierTitle"
    aria-describedby="notifierMessage">
    <div class="notifier-content">
        <div class="notifier-icon" aria-hidden="true">
            <img src="<?php echo $assetBase; ?>assets/images/icons/verified-fill.svg" alt=""
                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/mail.svg'">
        </div>
        <p class="heading-5" id="notifierTitle">Application Received</p>
        <p id="notifierMessage"></p>
        <button id="notifierCloseBtn" class="btn btn-primary" type="button">Continue to Sign In</button>
    </div>
</div>

<script src="../assets/ui/js/sign-up.js" defer></script>

<?php
require_once __DIR__ . '/../../shared/includes/footer.php';
?>
```

---

