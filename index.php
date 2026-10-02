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
 *   - shared/includes/session-bootstrap.php  (public session)
 *   - shared/includes/header.php             (public chrome)
 *   - shared/includes/footer.php             (footer markup)
 *   - shared/backend/database/database-connect.php
 *   - shared/backend/database/landing-queries.php
 *
 * It contains NO SQL of its own. Every database call goes through
 * landing-queries.php, which lives under shared/ for the same reason.
 *
 * The asset base path ($assetBase) is provided by header.php. This
 * page does NOT compute its own path — one source of truth, no drift.
 *
 * Page-specific CSS (landing.css) is loaded by header.php via its
 * $pageCssMap. This page does NOT emit <link> tags for its own styles.
 *
 * ---------------------------------------------------------------------
 * PER-ROLE SESSION MODEL (Option B)
 * ---------------------------------------------------------------------
 * Every role runs as its own PHP session under its own cookie name.
 * This page belongs to the public context and runs on
 * PHPSESSID_PUBLIC. That means this page CANNOT read any
 * authenticated role's session — not the customer's, not the
 * rider's, not the restaurant's, not the admin's — because a
 * request to this page carries only the public session cookie.
 *
 * The landing page therefore renders the anonymous experience for
 * every visitor. A signed-in customer who lands here and wants to
 * go to their dashboard clicks "Login" in the nav, which takes
 * them to the customer sign-in page; that page detects the already-
 * active customer session and redirects them to their dashboard.
 *
 * ---------------------------------------------------------------------
 * PRODUCT IMAGE RESOLUTION (v5.0)
 * ---------------------------------------------------------------------
 * This page renders product images by using the `image_base` and
 * `primary_image` fields returned by getFeaturedProducts() in
 * landing-queries.php. Those fields are resolved from the raw
 * `dietary_information.images` path by the shared helpers in
 * customer/backend/database/product-queries.php, ensuring the
 * landing page, the customer menu, and the product detail page all
 * display the same correct image for a given product.
 *
 * The local helper `resolveLandingPageImageUrl()` builds the final
 * browser-loadable URL from the page's own `$assetBase` and the
 * resolved folder/filename fields.
 *
 * ---------------------------------------------------------------------
 * VIEW HELPERS (v5.2)
 * ---------------------------------------------------------------------
 * This page uses three pure presentation helpers — formatPrice(),
 * truncateText(), and parseTagList() — which were previously
 * declared in shared/includes/view-helpers.php. That file has been
 * removed from the project. The three helpers are declared inline
 * here, each behind a function_exists() guard, because this page
 * is now their only consumer in the shared/public scope.
 *
 * ---------------------------------------------------------------------
 *
 * @package FitPal
 * @version 5.2 — Removed the require_once for the deleted
 *                shared/includes/view-helpers.php file and inlined
 *                the three pure helpers (formatPrice, truncateText,
 *                parseTagList) this page consumes.
 *
 *                (5.1: restored the require for view-helpers.php.
 *                5.0: resolves product images using image_base and
 *                primary_image from landing-queries.php.)
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// VIEW HELPERS
//
// Inlined because shared/includes/view-helpers.php no longer exists.
// Each declaration is behind a function_exists() guard so a future
// reintroduction of that file (or a page that already declares
// these functions) does not trigger a redeclaration fatal.
// ---------------------------------------------------------------------

if (!function_exists('formatPrice')) {
    /**
     * Format a numeric price as Philippine pesos.
     *
     * @param int|float|string $price
     * @return string
     */
    function formatPrice(int|float|string $price): string
    {
        return '₱' . number_format((float)$price, 2);
    }
}

if (!function_exists('truncateText')) {
    /**
     * Truncate a string to a maximum length, appending an ellipsis
     * if the string was cut.
     *
     * @param string $text
     * @param int $length
     * @return string
     */
    function truncateText(string $text, int $length = 70): string
    {
        $text = trim($text);
        if (strlen($text) <= $length) {
            return $text;
        }
        return substr($text, 0, $length) . '...';
    }
}

if (!function_exists('parseTagList')) {
    /**
     * Split a comma-separated tag list into a clean, trimmed array.
     * Empty entries are filtered out.
     *
     * @param string $raw
     * @return array<int, string>
     */
    function parseTagList(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn(string $v): bool => $v !== ''
        ));
    }
}

// ---------------------------------------------------------------------
// SESSION BOOTSTRAP
//
// Must run before any include that might touch the session. This
// page belongs to the public context.
// ---------------------------------------------------------------------

require_once __DIR__ . '/shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('public');

// ---------------------------------------------------------------------
// HEADER
//
// The header verifies the active session is the public session and
// computes $assetBase. It also pulls in the shared database
// connection.
// ---------------------------------------------------------------------

require_once __DIR__ . '/shared/includes/header.php';

// ---------------------------------------------------------------------
// DATA
// ---------------------------------------------------------------------

require_once __DIR__ . '/shared/backend/database/landing-queries.php';

$featuredProducts = [];
$stats            = ['restaurants' => 0, 'products' => 0, 'customers' => 0];

try {
    $featuredProducts = getFeaturedProducts($database_connection, 5);
    $stats            = getPlatformStats($database_connection);
} catch (PDOException $e) {
    error_log('Landing page query error: ' . $e->getMessage());
}

$hasProducts = !empty($featuredProducts);

/**
 * Build a browser-loadable URL for a featured product's primary image.
 *
 * @param string $projectRootUrl The project-root-relative URL prefix.
 * @param string $imageBase      The resolved folder path from the reader.
 * @param string $primaryImage   The primary filename from the reader.
 * @param string $fallback       The fallback icon URL.
 * @return string
 */
function resolveLandingPageImageUrl(
    string $projectRootUrl,
    string $imageBase,
    string $primaryImage,
    string $fallback
): string {
    if ($projectRootUrl === '' || $imageBase === '' || $primaryImage === '') {
        return $fallback;
    }
    return $projectRootUrl . $imageBase . $primaryImage;
}

// Derive the project-root URL from $assetBase.
$projectRootUrl = preg_replace('#shared/$#', '', $assetBase);
if (!is_string($projectRootUrl)) {
    $projectRootUrl = '';
}
$productImageFallback = $assetBase . 'assets/images/icons/restaurant.svg';

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
                    <a href="<?php echo $assetBase; ?>../customer/pages/sign-up.php" class="btn btn-primary btn-lg">
                        Get Started
                    </a>
                    <a href="<?php echo $assetBase; ?>pages/about.php" class="btn btn-outline btn-lg">
                        Learn More
                    </a>
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
                    $productCalories    = (int)($product['calories'] ?? 0);
                    $restaurantName     = $product['restaurant_name'] ?? '';
                    $productDescription = $product['description'] ?? '';

                    $dietaryTags = parseTagList($product['dietary_tags'] ?? '');
                    $allergens   = parseTagList($product['allergens'] ?? '');

                    $productImage = resolveLandingPageImageUrl(
                        $projectRootUrl,
                        (string)($product['image_base']    ?? ''),
                        (string)($product['primary_image'] ?? ''),
                        $productImageFallback
                    );

                    $productDetailUrl = $assetBase . '../customer/pages/product-detail.php?id=' . $productId;
                ?>
                <div class="product-card" data-product-id="<?php echo $productId; ?>">

                    <a href="<?php echo htmlspecialchars($productDetailUrl, ENT_QUOTES, 'UTF-8'); ?>"
                        class="product-image-link">
                        <div class="product-image">
                            <img src="<?php echo htmlspecialchars($productImage, ENT_QUOTES, 'UTF-8'); ?>"
                                alt="<?php echo htmlspecialchars($productName, ENT_QUOTES, 'UTF-8'); ?>" loading="lazy"
                                onerror="this.onerror=null; this.src='<?php echo $productImageFallback; ?>'">
                        </div>
                    </a>

                    <div class="product-info">
                        <a href="<?php echo htmlspecialchars($productDetailUrl, ENT_QUOTES, 'UTF-8'); ?>"
                            class="product-name-link">
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
                    <div class="product-actions">
                        <a href="<?php echo htmlspecialchars($productDetailUrl, ENT_QUOTES, 'UTF-8'); ?>"
                            class="btn btn-primary btn-sm">
                            View Meal
                        </a>
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
                    <a href="<?php echo $assetBase; ?>../customer/pages/sign-up.php" class="btn btn-primary btn-lg">
                        Get Started
                    </a>
                    <a href="<?php echo $assetBase; ?>../customer/pages/sign-in.php" class="btn btn-outline btn-lg">
                        Sign In
                    </a>
                </div>
            </div>
        </div>
    </section>

</div>

<?php
require_once __DIR__ . '/shared/includes/footer.php';
?>