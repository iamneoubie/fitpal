<?php
/**
 * FitPal Landing Page
 * 
 * This is the public entry point for the FitPal platform.
 * 
 * Usage: Direct access via web browser
 * 
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include shared header
require_once __DIR__ . '/shared/includes/header.php';

/**
 * Get the base path for assets based on current file location
 * 
 * @return string The asset base path
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

// Check if user is logged in for any role
$isLoggedIn = false;
$userRole = null;
$userName = '';

if (isset($_SESSION['user_id']) && isset($_SESSION['user_role'])) {
    $isLoggedIn = true;
    $userRole = $_SESSION['user_role'];
    $userName = $_SESSION['user_name'] ?? '';
}

// Featured restaurants (placeholder data)
$featuredRestaurants = [
    [
        'name' => 'Green Bowl Cafe',
        'cuisine' => 'Vegan',
        'rating' => 4.8,
        'reviews' => 234,
        'image' => $assetBase . 'assets/images/restaurants/green-bowl.jpg',
        'tags' => ['Vegan', 'Gluten-Free', 'Organic']
    ],
    [
        'name' => 'Keto Kitchen',
        'cuisine' => 'Keto',
        'rating' => 4.6,
        'reviews' => 189,
        'image' => $assetBase . 'assets/images/restaurants/keto-kitchen.jpg',
        'tags' => ['Keto', 'High-Protein', 'Low-Carb']
    ],
    [
        'name' => 'PureFit Meals',
        'cuisine' => 'Healthy',
        'rating' => 4.9,
        'reviews' => 312,
        'image' => $assetBase . 'assets/images/restaurants/purefit.jpg',
        'tags' => ['High-Protein', 'Low-Calorie', 'Organic']
    ],
    [
        'name' => 'Asian Fusion Fit',
        'cuisine' => 'Asian',
        'rating' => 4.5,
        'reviews' => 156,
        'image' => $assetBase . 'assets/images/restaurants/asian-fusion.jpg',
        'tags' => ['Gluten-Free', 'Vegan Options', 'Low-Carb']
    ]
];
?>

<!-- ============================================
    LANDING PAGE CONTENT
    ============================================ -->

<div class="content">

    <!-- Hero Section -->
    <section class="hero-section" aria-labelledby="hero-title">
        <div class="hero-container">
            <div class="hero-content">
                <div class="hero-badge">
                    <span class="hero-badge-icon">&#9679;</span>
                    Dietary Meal Ordering
                </div>
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
                    <a href="<?php echo $assetBase; ?>../customer/pages/register.php" class="btn btn-primary btn-lg">
                        Get Started
                    </a>
                    <a href="<?php echo $assetBase; ?>pages/about.php" class="btn btn-outline btn-lg">
                        Learn More
                    </a>
                    <?php endif; ?>
                </div>
                <div class="hero-stats">
                    <div class="hero-stat">
                        <span class="hero-stat-number">500+</span>
                        <span class="hero-stat-label">Meals</span>
                    </div>
                    <div class="hero-stat-divider"></div>
                    <div class="hero-stat">
                        <span class="hero-stat-number">200+</span>
                        <span class="hero-stat-label">Restaurants</span>
                    </div>
                    <div class="hero-stat-divider"></div>
                    <div class="hero-stat">
                        <span class="hero-stat-number">10K+</span>
                        <span class="hero-stat-label">Active Users</span>
                    </div>
                </div>
            </div>
            <div class="hero-image">
                <img src="<?php echo $assetBase; ?>assets/images/hero-image.svg"
                    alt="Healthy food ordering illustration" class="hero-illustration"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/placeholder.svg';">
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
                        <img src="<?php echo $assetBase; ?>assets/images/icons/nutrition.svg"
                            alt="Nutritional information"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/placeholder.svg';">
                    </div>
                    <p class="feature-title">Nutritional Information</p>
                    <p class="feature-description">
                        View calories, protein, carbs, and fats for every meal.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/dietary.svg" alt="Dietary filters"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/placeholder.svg';">
                    </div>
                    <p class="feature-title">Dietary Filters</p>
                    <p class="feature-description">
                        Filter meals by vegan, keto, gluten-free, and other preferences.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/allergy.svg" alt="Allergy management"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/placeholder.svg';">
                    </div>
                    <p class="feature-title">Allergy Management</p>
                    <p class="feature-description">
                        Set your allergies and get safe meal recommendations.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/instructions.svg"
                            alt="Special instructions"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/placeholder.svg';">
                    </div>
                    <p class="feature-title">Special Instructions</p>
                    <p class="feature-description">
                        Add custom instructions that are communicated to the kitchen.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/tracking.svg" alt="Order tracking"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/placeholder.svg';">
                    </div>
                    <p class="feature-title">Order Tracking</p>
                    <p class="feature-description">
                        Track your orders from preparation to delivery.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/analytics.svg" alt="Nutrition analytics"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/placeholder.svg';">
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
                        <img src="<?php echo $assetBase; ?>assets/images/icons/register.svg" alt="Create account"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/placeholder.svg';">
                    </div>
                    <p class="step-title">Create Account</p>
                    <p class="step-description">
                        Sign up and set your dietary preferences and allergies.
                    </p>
                </div>
                <div class="step-card">
                    <div class="step-number">2</div>
                    <div class="step-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/browse.svg" alt="Browse menus"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/placeholder.svg';">
                    </div>
                    <p class="step-title">Browse Menus</p>
                    <p class="step-description">
                        Explore restaurants and filter meals based on your needs.
                    </p>
                </div>
                <div class="step-card">
                    <div class="step-number">3</div>
                    <div class="step-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/order.svg" alt="Place order"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/placeholder.svg';">
                    </div>
                    <p class="step-title">Place Order</p>
                    <p class="step-description">
                        Add meals to your cart, add instructions, and place your order.
                    </p>
                </div>
                <div class="step-card">
                    <div class="step-number">4</div>
                    <div class="step-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/delivery.svg" alt="Track delivery"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/placeholder.svg';">
                    </div>
                    <p class="step-title">Track Order</p>
                    <p class="step-description">
                        Track your order status from preparation to delivery.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Restaurants Section -->
    <section class="restaurants-section" aria-labelledby="restaurants-title">
        <div class="container">
            <div class="section-header">
                <p class="section-title" id="restaurants-title">Featured <span>Restaurants</span></p>
                <p class="section-subtitle">Restaurants with nutritional information</p>
            </div>
            <div class="restaurants-grid">
                <?php foreach ($featuredRestaurants as $restaurant): ?>
                <div class="restaurant-card">
                    <div class="restaurant-image">
                        <img src="<?php echo htmlspecialchars($restaurant['image'], ENT_QUOTES, 'UTF-8'); ?>"
                            alt="<?php echo htmlspecialchars($restaurant['name'], ENT_QUOTES, 'UTF-8'); ?>"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/restaurants/placeholder.jpg';">
                    </div>
                    <div class="restaurant-info">
                        <div class="restaurant-header">
                            <p class="restaurant-name">
                                <?php echo htmlspecialchars($restaurant['name'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <span
                                class="restaurant-cuisine"><?php echo htmlspecialchars($restaurant['cuisine'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="restaurant-rating">
                            <span class="rating-stars">
                                <?php 
                                $fullStars = floor($restaurant['rating']);
                                for ($i = 1; $i <= 5; $i++) {
                                    if ($i <= $fullStars) {
                                        echo '<span class="star filled">&#9733;</span>';
                                    } else {
                                        echo '<span class="star empty">&#9734;</span>';
                                    }
                                }
                                ?>
                            </span>
                            <span class="rating-value"><?php echo number_format($restaurant['rating'], 1); ?></span>
                            <span class="rating-reviews">(<?php echo number_format($restaurant['reviews']); ?>
                                reviews)</span>
                        </div>
                        <div class="restaurant-tags">
                            <?php foreach ($restaurant['tags'] as $tag): ?>
                            <span
                                class="restaurant-tag"><?php echo htmlspecialchars($tag, ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <a href="#" class="btn btn-primary btn-sm">View Menu</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="restaurants-action">
                <a href="<?php echo $assetBase; ?>../customer/pages/menu.php" class="btn btn-outline">
                    View All Restaurants
                </a>
            </div>
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
                <?php if ($isLoggedIn): ?>
                <a href="<?php echo $assetBase; ?>../<?php echo $userRole; ?>/pages/dashboard.php"
                    class="btn btn-primary btn-lg">
                    Go to Dashboard
                </a>
                <?php else: ?>
                <a href="<?php echo $assetBase; ?>../customer/pages/register.php" class="btn btn-primary btn-lg">
                    Get Started
                </a>
                <a href="<?php echo $assetBase; ?>../customer/pages/login.php" class="btn btn-outline btn-lg">
                    Sign In
                </a>
                <?php endif; ?>
            </div>
        </div>
    </section>

</div>

<?php
// Include shared footer
require_once __DIR__ . '/shared/includes/footer.php';
?>