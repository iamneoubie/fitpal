<?php
/**
 * FitPal Landing Page
 * 
 * This is the public entry point for the FitPal platform.
 * It serves as the marketing and informational homepage for the application.
 * 
 * Usage: Direct access via web browser
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include shared header (this will handle the navigation based on login status)
require_once __DIR__ . '/shared/includes/header.php';

// Get the base path for assets
function getLandingAssetBase() {
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

// Get the shared asset base
function getSharedAssetBase() {
    $scriptPath = $_SERVER['SCRIPT_NAME'];
    $dirPath = dirname($scriptPath);
    $segments = array_filter(explode('/', $dirPath));
    $depth = count($segments);
    
    if ($depth <= 0) {
        return './shared/';
    }
    
    return str_repeat('../', $depth) . 'shared/';
}

$sharedAssetBase = getSharedAssetBase();

// Check if user is logged in for any role
$isLoggedIn = false;
$userRole = null;
$userName = '';

if (isset($_SESSION['user_id']) && isset($_SESSION['user_role'])) {
    $isLoggedIn = true;
    $userRole = $_SESSION['user_role'];
    $userName = $_SESSION['user_name'] ?? '';
}

// Featured restaurants (placeholder data - will come from database later)
$featuredRestaurants = [
    [
        'name' => 'Green Bowl Cafe',
        'cuisine' => 'Vegan',
        'rating' => 4.8,
        'reviews' => 234,
        'image' => $sharedAssetBase . 'assets/images/restaurants/green-bowl.jpg',
        'tags' => ['Vegan', 'Gluten-Free', 'Organic']
    ],
    [
        'name' => 'Keto Kitchen',
        'cuisine' => 'Keto',
        'rating' => 4.6,
        'reviews' => 189,
        'image' => $sharedAssetBase . 'assets/images/restaurants/keto-kitchen.jpg',
        'tags' => ['Keto', 'High-Protein', 'Low-Carb']
    ],
    [
        'name' => 'PureFit Meals',
        'cuisine' => 'Healthy',
        'rating' => 4.9,
        'reviews' => 312,
        'image' => $sharedAssetBase . 'assets/images/restaurants/purefit.jpg',
        'tags' => ['High-Protein', 'Low-Calorie', 'Organic']
    ],
    [
        'name' => 'Asian Fusion Fit',
        'cuisine' => 'Asian',
        'rating' => 4.5,
        'reviews' => 156,
        'image' => $sharedAssetBase . 'assets/images/restaurants/asian-fusion.jpg',
        'tags' => ['Gluten-Free', 'Vegan Options', 'Low-Carb']
    ]
];

// Testimonials (placeholder data)
$testimonials = [
    [
        'name' => 'Maria Santos',
        'role' => 'Regular Customer',
        'image' => $sharedAssetBase . 'assets/images/teams/default-avatar.jpg',
        'quote' => 'FitPal has completely changed how I eat. The ability to filter by dietary needs makes finding the right meal so easy!'
    ],
    [
        'name' => 'John Dela Cruz',
        'role' => 'Fitness Enthusiast',
        'image' => $sharedAssetBase . 'assets/images/teams/default-avatar.jpg',
        'quote' => 'I love that I can track my macros and order meals that fit my keto diet perfectly. Highly recommended!'
    ],
    [
        'name' => 'Anna Reyes',
        'role' => 'Busy Professional',
        'image' => $sharedAssetBase . 'assets/images/teams/default-avatar.jpg',
        'quote' => 'With FitPal, I never have to worry about what to eat. The special instructions feature ensures my allergies are always accommodated.'
    ]
];
?>

<!-- ============================================
    LANDING PAGE CSS (Page-specific styles)
    ============================================ -->
<link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/landing.css">
<link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/footer.css">

<!-- ============================================
    LANDING PAGE CONTENT
    ============================================ -->

<!-- Hero Section -->
<section class="hero-section">
    <div class="hero-container">
        <div class="hero-content">
            <div class="hero-badge">
                <span class="hero-badge-icon">&#9679;</span>
                Smart Nutrition Analytics
            </div>
            <h1 class="hero-title">
                Order Healthy Meals<br>
                <span>Track Your Nutrition</span>
            </h1>
            <p class="hero-description">
                FitPal connects health-conscious consumers with restaurants that provide
                detailed nutritional information. Filter meals by dietary preferences,
                track your macros, and order with confidence.
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
                <a href="<?php echo $assetBase; ?>../shared/pages/about.php" class="btn btn-outline btn-lg">
                    Learn More
                </a>
                <?php endif; ?>
            </div>
            <div class="hero-stats">
                <div class="hero-stat">
                    <span class="hero-stat-number">500+</span>
                    <span class="hero-stat-label">Healthy Meals</span>
                </div>
                <div class="hero-stat-divider"></div>
                <div class="hero-stat">
                    <span class="hero-stat-number">200+</span>
                    <span class="hero-stat-label">Partner Restaurants</span>
                </div>
                <div class="hero-stat-divider"></div>
                <div class="hero-stat">
                    <span class="hero-stat-number">10K+</span>
                    <span class="hero-stat-label">Happy Customers</span>
                </div>
            </div>
        </div>
        <div class="hero-image">
            <img src="<?php echo $sharedAssetBase; ?>assets/images/hero-image.svg"
                alt="Healthy food ordering illustration" class="hero-illustration">
        </div>
    </div>
</section>

<!-- Features Section -->
<section class="features-section">
    <div class="container">
        <div class="section-header">
            <h2 class="section-title">Why Choose <span>FitPal</span></h2>
            <p class="section-subtitle">Everything you need for a healthier lifestyle</p>
        </div>
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/nutrition.svg"
                        alt="Nutrition tracking">
                </div>
                <h3 class="feature-title">Nutritional Information</h3>
                <p class="feature-description">
                    View detailed nutritional data for every meal including calories,
                    protein, carbs, and fats.
                </p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/dietary.svg" alt="Dietary filters">
                </div>
                <h3 class="feature-title">Dietary Filters</h3>
                <p class="feature-description">
                    Filter meals by vegan, keto, gluten-free, and other dietary
                    preferences with ease.
                </p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/allergy.svg" alt="Allergy management">
                </div>
                <h3 class="feature-title">Allergy Management</h3>
                <p class="feature-description">
                    Set your allergies and get meal recommendations that are safe
                    for you to consume.
                </p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/instructions.svg"
                        alt="Special instructions">
                </div>
                <h3 class="feature-title">Special Instructions</h3>
                <p class="feature-description">
                    Add custom instructions to your orders that are communicated
                    directly to the kitchen.
                </p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/tracking.svg" alt="Order tracking">
                </div>
                <h3 class="feature-title">Order Tracking</h3>
                <p class="feature-description">
                    Track your orders in real-time from preparation to delivery
                    at your doorstep.
                </p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/analytics.svg"
                        alt="Nutrition analytics">
                </div>
                <h3 class="feature-title">Nutrition Analytics</h3>
                <p class="feature-description">
                    Get insights into your eating habits and nutritional trends
                    to make healthier choices.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- How It Works Section -->
<section class="how-it-works-section">
    <div class="container">
        <div class="section-header">
            <h2 class="section-title">How It <span>Works</span></h2>
            <p class="section-subtitle">Simple steps to get started</p>
        </div>
        <div class="steps-grid">
            <div class="step-card">
                <div class="step-number">1</div>
                <div class="step-icon">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/register.svg" alt="Create account">
                </div>
                <h3 class="step-title">Create Account</h3>
                <p class="step-description">
                    Sign up as a customer and set your dietary preferences and allergies.
                </p>
            </div>
            <div class="step-card">
                <div class="step-number">2</div>
                <div class="step-icon">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/browse.svg" alt="Browse menus">
                </div>
                <h3 class="step-title">Browse Menus</h3>
                <p class="step-description">
                    Explore restaurants and filter meals based on your dietary needs.
                </p>
            </div>
            <div class="step-card">
                <div class="step-number">3</div>
                <div class="step-icon">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/order.svg" alt="Place order">
                </div>
                <h3 class="step-title">Place Order</h3>
                <p class="step-description">
                    Add meals to your cart, add special instructions, and place your order.
                </p>
            </div>
            <div class="step-card">
                <div class="step-number">4</div>
                <div class="step-icon">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/delivery.svg" alt="Track delivery">
                </div>
                <h3 class="step-title">Track & Enjoy</h3>
                <p class="step-description">
                    Track your order from preparation to delivery and enjoy your healthy meal.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- Restaurants Section -->
<section class="restaurants-section">
    <div class="container">
        <div class="section-header">
            <h2 class="section-title">Featured <span>Restaurants</span></h2>
            <p class="section-subtitle">Partner restaurants with detailed nutritional information</p>
        </div>
        <div class="restaurants-grid">
            <?php foreach ($featuredRestaurants as $restaurant): ?>
            <div class="restaurant-card">
                <div class="restaurant-image">
                    <img src="<?php echo htmlspecialchars($restaurant['image']); ?>"
                        alt="<?php echo htmlspecialchars($restaurant['name']); ?>"
                        onerror="this.onerror=null; this.src='<?php echo $sharedAssetBase; ?>assets/images/restaurants/placeholder.jpg';">
                </div>
                <div class="restaurant-info">
                    <div class="restaurant-header">
                        <h3 class="restaurant-name"><?php echo htmlspecialchars($restaurant['name']); ?></h3>
                        <span class="restaurant-cuisine"><?php echo htmlspecialchars($restaurant['cuisine']); ?></span>
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
                        <span class="restaurant-tag"><?php echo htmlspecialchars($tag); ?></span>
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

<!-- Testimonials Section -->
<section class="testimonials-section">
    <div class="container">
        <div class="section-header">
            <h2 class="section-title">What Our <span>Customers Say</span></h2>
            <p class="section-subtitle">Real stories from real people</p>
        </div>
        <div class="testimonials-grid">
            <?php foreach ($testimonials as $testimonial): ?>
            <div class="testimonial-card">
                <div class="testimonial-quote">
                    <span class="quote-icon">&#8220;</span>
                    <p class="testimonial-text"><?php echo htmlspecialchars($testimonial['quote']); ?></p>
                </div>
                <div class="testimonial-author">
                    <img src="<?php echo htmlspecialchars($testimonial['image']); ?>"
                        alt="<?php echo htmlspecialchars($testimonial['name']); ?>" class="testimonial-avatar"
                        onerror="this.onerror=null; this.src='<?php echo $sharedAssetBase; ?>assets/images/teams/default-avatar.jpg';">
                    <div class="testimonial-info">
                        <span class="testimonial-name"><?php echo htmlspecialchars($testimonial['name']); ?></span>
                        <span class="testimonial-role"><?php echo htmlspecialchars($testimonial['role']); ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- CTA Section -->
<section class="cta-section">
    <div class="container">
        <div class="cta-content">
            <h2 class="cta-title">Start Your Healthy Journey Today</h2>
            <p class="cta-description">
                Join thousands of health-conscious individuals who trust FitPal for their daily meals.
            </p>
            <?php if ($isLoggedIn): ?>
            <a href="<?php echo $assetBase; ?>../<?php echo $userRole; ?>/pages/dashboard.php"
                class="btn btn-primary btn-lg">
                Go to Dashboard
            </a>
            <?php else: ?>
            <a href="<?php echo $assetBase; ?>../customer/pages/register.php" class="btn btn-primary btn-lg">
                Get Started Now
            </a>
            <a href="<?php echo $assetBase; ?>../customer/pages/login.php" class="btn btn-outline btn-lg">
                Sign In
            </a>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php
// Include shared footer
require_once __DIR__ . '/shared/includes/footer.php';
?>