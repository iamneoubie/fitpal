<?php
/**
 * FitPal About Page
 *
 * Public-facing "About" page. Belongs to the public context and
 * runs on its own PHP session (PHPSESSID_PUBLIC).
 *
 * This page provides information about the FitPal platform's
 * mission, features, and team.
 *
 * @package FitPal
 * @version 2.0 — Per-role session migration (Option B) and layout
 *                redesign. The page bootstraps the public session
 *                itself. The layout was reworked to align with the
 *                project's design system, using standard grid and
 *                card components. The local asset-base helper was
 *                removed in favor of the one provided by the header.
 *
 *                (1.0: initial page.)
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// SESSION BOOTSTRAP
//
// Must run BEFORE any other include that might touch the session.
// Under Option B, this page belongs to the public context.
// ---------------------------------------------------------------------

require_once __DIR__ . '/../includes/session-bootstrap.php';
fitpal_session_bootstrap('public');

// ---------------------------------------------------------------------
// HEADER
//
// The shared header verifies the active session is the public
// session and provides the $assetBase variable.
// ---------------------------------------------------------------------

require_once __DIR__ . '/../includes/header.php';

// Data for the "What We Offer" section
$offerItems = [
    [
        'title' => 'Transparent Nutritional Information',
        'description' => 'View calories, protein, carbs, and fats for every meal to make informed choices.',
        'icon' => 'list-view.svg',
    ],
    [
        'title' => 'Advanced Dietary Filtering',
        'description' => 'Filter meals by vegan, keto, gluten-free, and other preferences to match your lifestyle.',
        'icon' => 'equalizer-line.svg',
    ],
    [
        'title' => 'Allergy Management',
        'description' => 'Set your allergies and get safe meal recommendations you can trust.',
        'icon' => 'list-settings-fill.svg',
    ],
    [
        'title' => 'Direct Kitchen Communication',
        'description' => 'Add custom special instructions that are communicated directly to the kitchen.',
        'icon' => 'edit.svg',
    ],
    [
        'title' => 'Real-Time Order Tracking',
        'description' => 'Track your orders from preparation to delivery for peace of mind.',
        'icon' => 'time-update.svg',
    ],
    [
        'title' => 'Personal Nutrition Analytics',
        'description' => 'View insights into your eating habits over time to track your goals.',
        'icon' => 'chart-line-up.svg',
    ]
];

// Team members data
$teamMembers = [
    [
        'name' => 'Lance N. Madelar',
        'role' => 'Lead Developer',
        'bio' => 'Project leader responsible for system architecture, database design, and core functionality.',
        'image' => $assetBase . 'assets/images/manifest/profiles/profile-1.jpg',
    ],
    [
        'name' => 'Maria Santos',
        'role' => 'UI/UX Designer',
        'bio' => 'Designs the user interface and user experience for all FitPal platforms.',
        'image' => $assetBase . 'assets/images/manifest/profiles/profile-2.jpg',
    ],
    [
        'name' => 'John Dela Cruz',
        'role' => 'Backend Developer',
        'bio' => 'Builds and maintains the server-side logic and database optimization.',
        'image' => $assetBase . 'assets/images/manifest/profiles/profile-3.jpg',
    ]
];
?>

<div class="content">

    <!-- ============================================
         HERO
         ============================================ -->
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

    <!-- ============================================
         MISSION
         ============================================ -->
    <section class="mission-section" aria-labelledby="mission-title">
        <div class="container">
            <div class="mission-grid">
                <div class="mission-content">
                    <p class="mission-title" id="mission-title">
                        Our <span>Mission</span>
                    </p>
                    <p class="mission-description">
                        At FitPal, we believe that everyone deserves access to food that meets their dietary needs
                        and preferences. Our mission is to bridge the gap between health-conscious consumers and
                        restaurants by providing transparent nutritional information and powerful dietary filtering.
                    </p>
                    <p class="mission-description">
                        We empower individuals to make informed food choices, support restaurants in showcasing
                        their healthy options, and build a community that values health, transparency, and
                        delicious food.
                    </p>
                </div>
                <div class="mission-image">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/people-team.svg"
                        alt="Our mission illustration"
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/community-general.svg'">
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================
         WHAT WE OFFER
         ============================================ -->
    <section class="offer-section" aria-labelledby="offer-title">
        <div class="container">
            <div class="section-header">
                <p class="section-title" id="offer-title">What We <span>Offer</span></p>
                <p class="section-subtitle">Features to help you make healthier food choices</p>
            </div>
            <div class="offer-grid">
                <?php foreach ($offerItems as $item): ?>
                <div class="offer-card">
                    <div class="offer-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/<?php echo htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8'); ?>"
                            alt="Feature icon"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                    </div>
                    <p class="heading-6"><?php echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?></p>
                    <p><?php echo htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ============================================
         TEAM
         ============================================ -->
    <!-- <section class="team-section" aria-labelledby="team-title">
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
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/user-profile-circle.svg'">
                    </div>
                    <div class="team-info">
                        <p class="heading-5"><?php echo htmlspecialchars($member['name'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <p class="team-role"><?php echo htmlspecialchars($member['role'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <p class="team-bio"><?php echo htmlspecialchars($member['bio'], ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section> -->

    <!-- ============================================
         CTA
         ============================================ -->
    <section class="about-cta" aria-labelledby="cta-title">
        <div class="container">
            <div class="about-cta-content">
                <p class="heading-2" id="cta-title">Ready to explore healthy meal options?</p>
                <p class="about-cta-description">Find meals that match your dietary preferences.</p>
                <div class="about-cta-actions">
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
require_once __DIR__ . '/../includes/footer.php';
?>