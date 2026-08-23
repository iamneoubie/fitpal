<?php
/**
 * FitPal About Page
 * 
 * This is the public about page that provides information about the platform.
 * 
 * Usage: Direct access via web browser
 * 
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

// Session is now handled by header.php
// No need to start session here

// Include shared header (handles session)
require_once __DIR__ . '/../includes/header.php';

/**
 * Get the base path for assets based on current file location
 * 
 * @return string The asset base path
 */
function getAboutAssetBase(): string {
    $scriptPath = $_SERVER['SCRIPT_NAME'];
    $dirPath = dirname($scriptPath);
    $segments = array_filter(explode('/', $dirPath));
    $depth = count($segments);
    
    if ($depth <= 0) {
        return './shared/';
    }
    
    return str_repeat('../', $depth) . 'shared/';
}

$assetBase = getAboutAssetBase();

// ===== FIXED: Define mission points =====
$missionPoints = [
    'Transparent nutritional information for every meal',
    'Dietary filtering by vegan, keto, gluten-free, and more',
    'Allergy management for safe food choices',
    'Special instructions communicated directly to the kitchen',
    'Real-time order tracking from preparation to delivery',
    'Nutrition analytics to understand your eating habits'
];

// Team members data
$teamMembers = [
    [
        'name' => 'Lance N. Madelar',
        'role' => 'Lead Developer',
        'bio' => 'Project leader responsible for system architecture, database design, and core functionality implementation.',
        'image' => $assetBase . 'assets/images/teams/lance-madelar.jpg',
        'email' => 'lance.madelar@fitpal.com'
    ],
    [
        'name' => 'Maria Santos',
        'role' => 'UI/UX Designer',
        'bio' => 'Designs the user interface and user experience for all FitPal platforms.',
        'image' => $assetBase . 'assets/images/teams/maria-santos.jpg',
        'email' => 'maria.santos@fitpal.com'
    ],
    [
        'name' => 'John Dela Cruz',
        'role' => 'Backend Developer',
        'bio' => 'Builds and maintains the server-side logic and database optimization.',
        'image' => $assetBase . 'assets/images/teams/john-dela-cruz.jpg',
        'email' => 'john.delacruz@fitpal.com'
    ]
];
?>
<!-- ============================================
    ABOUT PAGE CONTENT
    ============================================ -->

<div class="content">

    <!-- Hero Section -->
    <section class="about-hero" aria-labelledby="about-hero-title">
        <div class="container">
            <div class="about-hero-content">
                <p class="about-hero-title" id="about-hero-title">
                    About <span>FitPal</span>
                </p>
                <p class="about-hero-subtitle">
                    Making it easier to find meals that match your dietary needs
                </p>
            </div>
        </div>
    </section>

    <!-- Mission Section -->
    <section class="mission-section" aria-labelledby="mission-title">
        <div class="container">
            <div class="mission-grid">
                <div class="mission-content">
                    <p class="mission-title" id="mission-title">
                        Our <span>Mission</span>
                    </p>
                    <p class="mission-description">
                        At FitPal, we believe that everyone deserves access to food
                        that meets their dietary needs and preferences. Our mission is to bridge the gap
                        between health-conscious consumers and restaurants by providing transparent
                        nutritional information and dietary filtering.
                    </p>
                    <p class="mission-description">
                        We empower individuals to make informed food choices, support restaurants in
                        showcasing their healthy options, and build a community that values health,
                        transparency, and delicious food.
                    </p>
                </div>
                <div class="mission-image">
                    <img src="<?php echo $assetBase; ?>assets/images/about-mission.svg" alt="Our mission illustration"
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/placeholder.svg';">
                </div>
            </div>
        </div>
    </section>

    <!-- What We Offer Section -->
    <section class="offer-section" aria-labelledby="offer-title">
        <div class="container">
            <div class="section-header">
                <p class="section-title" id="offer-title">What We <span>Offer</span></p>
                <p class="section-subtitle">Features to help you make healthier food choices</p>
            </div>
            <div class="offer-grid">
                <?php foreach ($missionPoints as $point): ?>
                <div class="offer-card">
                    <div class="offer-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/check.svg" alt="Checkmark"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/placeholder.svg';">
                    </div>
                    <p class="heading-6"><?php echo htmlspecialchars($point, ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Team Section -->
    <section class="team-section" aria-labelledby="team-title">
        <div class="container">
            <div class="section-header">
                <p class="section-title" id="team-title">Meet Our <span>Team</span></p>
                <p class="section-subtitle">The passionate people behind FitPal</p>
            </div>
            <div class="team-grid">
                <?php foreach ($teamMembers as $member): ?>
                <div class="team-card">
                    <div class="team-image">
                        <img src="<?php echo htmlspecialchars($member['image'], ENT_QUOTES, 'UTF-8'); ?>"
                            alt="<?php echo htmlspecialchars($member['name'], ENT_QUOTES, 'UTF-8'); ?>"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/teams/default-avatar.jpg';">
                    </div>
                    <div class="team-info">
                        <p class="heading-5"><?php echo htmlspecialchars($member['name'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <p class="team-role"><?php echo htmlspecialchars($member['role'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <p class="team-bio"><?php echo htmlspecialchars($member['bio'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <a href="mailto:<?php echo htmlspecialchars($member['email'], ENT_QUOTES, 'UTF-8'); ?>"
                            class="team-email">
                            <?php echo htmlspecialchars($member['email'], ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="about-cta" aria-labelledby="cta-title">
        <div class="container">
            <div class="about-cta-content">
                <p class="heading-2" id="cta-title">Ready to explore healthy meal options?</p>
                <p class="about-cta-description">Find meals that match your dietary preferences.</p>
                <div class="about-cta-actions">
                    <a href="<?php echo $assetBase; ?>../customer/pages/register.php" class="btn btn-primary btn-lg">
                        Get Started
                    </a>
                    <a href="<?php echo $assetBase; ?>../customer/pages/login.php" class="btn btn-outline btn-lg">
                        Sign In
                    </a>
                </div>
            </div>
        </div>
    </section>

</div>

<?php
// Include shared footer
require_once __DIR__ . '/../includes/footer.php';
?>