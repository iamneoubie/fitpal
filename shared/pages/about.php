<?php
/**
 * FitPal About Page
 * 
 * This is the public about page that provides information about the platform,
 * its mission, the team, and the technology behind it.
 * 
 * Usage: Direct access via web browser or included via router
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include shared header
require_once __DIR__ . '/../includes/header.php';

// Get the base path for assets
function getAboutAssetBase() {
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

// Team members data
$teamMembers = [
    [
        'name' => 'Lance N. Madelar',
        'role' => 'Lead Developer',
        'bio' => 'Project leader responsible for system architecture, database design, and core functionality implementation.',
        'image' => $sharedAssetBase . 'assets/images/teams/lance-madelar.jpg',
        'email' => 'lance.madelar@fitpal.com'
    ],
    [
        'name' => 'Maria Santos',
        'role' => 'UI/UX Designer',
        'bio' => 'Designs the user interface and user experience for all FitPal platforms, ensuring intuitive navigation and accessibility.',
        'image' => $sharedAssetBase . 'assets/images/teams/maria-santos.jpg',
        'email' => 'maria.santos@fitpal.com'
    ],
    [
        'name' => 'John Dela Cruz',
        'role' => 'Backend Developer',
        'bio' => 'Builds and maintains the server-side logic, database optimization, and API development for the platform.',
        'image' => $sharedAssetBase . 'assets/images/teams/john-dela-cruz.jpg',
        'email' => 'john.delacruz@fitpal.com'
    ],
    [
        'name' => 'Anna Reyes',
        'role' => 'Quality Assurance',
        'bio' => 'Ensures the quality and reliability of the platform through comprehensive testing and bug tracking.',
        'image' => $sharedAssetBase . 'assets/images/teams/anna-reyes.jpg',
        'email' => 'anna.reyes@fitpal.com'
    ],
    [
        'name' => 'Michael Tan',
        'role' => 'Full Stack Developer',
        'bio' => 'Works on both frontend and backend development, focusing on feature implementation and performance optimization.',
        'image' => $sharedAssetBase . 'assets/images/teams/michael-tan.jpg',
        'email' => 'michael.tan@fitpal.com'
    ],
    [
        'name' => 'Sarah Lim',
        'role' => 'Nutrition Consultant',
        'bio' => 'Provides expertise on dietary requirements, nutritional information accuracy, and health guidelines.',
        'image' => $sharedAssetBase . 'assets/images/teams/sarah-lim.jpg',
        'email' => 'sarah.lim@fitpal.com'
    ]
];

// Mission values
$missionValues = [
    [
        'icon' => 'heart',
        'title' => 'Health First',
        'description' => 'We prioritize the health and well-being of our users by providing accurate nutritional information.'
    ],
    [
        'icon' => 'shield',
        'title' => 'Transparency',
        'description' => 'We believe in complete transparency when it comes to food ingredients, allergens, and nutritional data.'
    ],
    [
        'icon' => 'users',
        'title' => 'Community Focused',
        'description' => 'We build a community of health-conscious individuals who support each other in their wellness journey.'
    ],
    [
        'icon' => 'leaf',
        'title' => 'Sustainability',
        'description' => 'We promote sustainable eating habits and support restaurants that prioritize eco-friendly practices.'
    ]
];

// Tech stack
$techStack = [
    [
        'name' => 'PHP',
        'description' => 'Backend logic and server-side processing',
        'icon' => $sharedAssetBase . 'assets/images/icons/php.svg'
    ],
    [
        'name' => 'MySQL',
        'description' => 'Database management and data storage',
        'icon' => $sharedAssetBase . 'assets/images/icons/mysql.svg'
    ],
    [
        'name' => 'HTML5 & CSS3',
        'description' => 'Responsive frontend design and layout',
        'icon' => $sharedAssetBase . 'assets/images/icons/html-css.svg'
    ],
    [
        'name' => 'JavaScript',
        'description' => 'Interactive user interface and dynamic content',
        'icon' => $sharedAssetBase . 'assets/images/icons/javascript.svg'
    ],
    [
        'name' => 'Git',
        'description' => 'Version control and collaboration',
        'icon' => $sharedAssetBase . 'assets/images/icons/git.svg'
    ]
];

// Timeline data
$timeline = [
    [
        'year' => '2024',
        'title' => 'Project Inception',
        'description' => 'FitPal was conceptualized as a solution to the growing need for transparent nutritional information in food delivery.'
    ],
    [
        'year' => '2024',
        'title' => 'Research & Development',
        'description' => 'Extensive research on dietary requirements, user needs, and market analysis to shape the platform features.'
    ],
    [
        'year' => '2025',
        'title' => 'Beta Launch',
        'description' => 'Initial launch with a select group of restaurants and customers to test and refine the platform.'
    ],
    [
        'year' => '2025',
        'title' => 'Full Release',
        'description' => 'Official launch of FitPal with full features, expanded restaurant partnerships, and enhanced user experience.'
    ],
    [
        'year' => '2026',
        'title' => 'Continuous Growth',
        'description' => 'Ongoing development of new features, expansion of restaurant network, and continuous improvement based on user feedback.'
    ]
];
?>

<!-- ============================================
    ABOUT PAGE CSS (Page-specific styles)
    ============================================ -->
<link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/about.css">
<link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/footer.css">

<!-- ============================================
    ABOUT PAGE CONTENT
    ============================================ -->

<!-- Hero Section -->
<section class="about-hero">
    <div class="container">
        <div class="about-hero-content">
            <h1 class="about-hero-title">
                About <span>FitPal</span>
            </h1>
            <p class="about-hero-subtitle">
                Revolutionizing the way you order healthy meals with transparent nutritional information
            </p>
        </div>
    </div>
</section>

<!-- Our Mission Section -->
<section class="mission-section">
    <div class="container">
        <div class="mission-grid">
            <div class="mission-content">
                <h2 class="mission-title">Our <span>Mission</span></h2>
                <p class="mission-description">
                    At FitPal, we believe that everyone deserves access to healthy, delicious food
                    that meets their dietary needs and preferences. Our mission is to bridge the gap
                    between health-conscious consumers and restaurants by providing transparent
                    nutritional information and dietary filtering.
                </p>
                <p class="mission-description">
                    We empower individuals to make informed food choices, support restaurants in
                    showcasing their healthy options, and build a community that values health,
                    transparency, and delicious food.
                </p>
                <div class="mission-values">
                    <?php foreach ($missionValues as $value): ?>
                    <div class="mission-value">
                        <div class="mission-value-icon">
                            <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/<?php echo $value['icon']; ?>.svg"
                                alt="<?php echo htmlspecialchars($value['title']); ?>"
                                onerror="this.onerror=null; this.src='<?php echo $sharedAssetBase; ?>assets/images/icons/default.svg';">
                        </div>
                        <div class="mission-value-content">
                            <h3><?php echo htmlspecialchars($value['title']); ?></h3>
                            <p><?php echo htmlspecialchars($value['description']); ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="mission-image">
                <img src="<?php echo $sharedAssetBase; ?>assets/images/about-mission.svg" alt="Our mission illustration"
                    onerror="this.onerror=null; this.src='<?php echo $sharedAssetBase; ?>assets/images/placeholder.svg';">
            </div>
        </div>
    </div>
</section>

<!-- What We Offer Section -->
<section class="offer-section">
    <div class="container">
        <div class="section-header">
            <h2 class="section-title">What We <span>Offer</span></h2>
            <p class="section-subtitle">Everything you need to make healthier food choices</p>
        </div>
        <div class="offer-grid">
            <div class="offer-card">
                <div class="offer-icon">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/nutrition.svg" alt="Nutritional data">
                </div>
                <h3>Detailed Nutritional Data</h3>
                <p>View calories, protein, carbs, and fat for every meal to make informed decisions.</p>
            </div>
            <div class="offer-card">
                <div class="offer-icon">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/dietary.svg" alt="Dietary filters">
                </div>
                <h3>Smart Dietary Filters</h3>
                <p>Filter meals by vegan, keto, gluten-free, and other dietary preferences.</p>
            </div>
            <div class="offer-card">
                <div class="offer-icon">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/allergy.svg" alt="Allergy management">
                </div>
                <h3>Allergy Management</h3>
                <p>Set your allergies and get safe meal recommendations tailored to you.</p>
            </div>
            <div class="offer-card">
                <div class="offer-icon">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/instructions.svg"
                        alt="Special instructions">
                </div>
                <h3>Special Instructions</h3>
                <p>Add custom instructions to your orders that are communicated directly to the kitchen.</p>
            </div>
            <div class="offer-card">
                <div class="offer-icon">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/tracking.svg" alt="Order tracking">
                </div>
                <h3>Real-Time Tracking</h3>
                <p>Track your orders from preparation to delivery with live updates.</p>
            </div>
            <div class="offer-card">
                <div class="offer-icon">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/analytics.svg"
                        alt="Nutrition analytics">
                </div>
                <h3>Nutrition Analytics</h3>
                <p>Get insights into your eating habits and nutritional trends over time.</p>
            </div>
        </div>
    </div>
</section>

<!-- Our Journey Section -->
<section class="journey-section">
    <div class="container">
        <div class="section-header">
            <h2 class="section-title">Our <span>Journey</span></h2>
            <p class="section-subtitle">The story of FitPal</p>
        </div>
        <div class="timeline">
            <?php foreach ($timeline as $item): ?>
            <div
                class="timeline-item <?php echo $loop->iteration % 2 == 0 ? 'timeline-item-right' : 'timeline-item-left'; ?>">
                <div class="timeline-badge">
                    <span class="timeline-year"><?php echo htmlspecialchars($item['year']); ?></span>
                </div>
                <div class="timeline-content">
                    <h3><?php echo htmlspecialchars($item['title']); ?></h3>
                    <p><?php echo htmlspecialchars($item['description']); ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Technology Stack Section -->
<section class="tech-section">
    <div class="container">
        <div class="section-header">
            <h2 class="section-title">Our <span>Technology Stack</span></h2>
            <p class="section-subtitle">Built with modern tools and technologies</p>
        </div>
        <div class="tech-grid">
            <?php foreach ($techStack as $tech): ?>
            <div class="tech-card">
                <div class="tech-icon">
                    <img src="<?php echo htmlspecialchars($tech['icon']); ?>"
                        alt="<?php echo htmlspecialchars($tech['name']); ?>"
                        onerror="this.onerror=null; this.src='<?php echo $sharedAssetBase; ?>assets/images/icons/default.svg';">
                </div>
                <h3><?php echo htmlspecialchars($tech['name']); ?></h3>
                <p><?php echo htmlspecialchars($tech['description']); ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Team Section -->
<section class="team-section">
    <div class="container">
        <div class="section-header">
            <h2 class="section-title">Meet Our <span>Team</span></h2>
            <p class="section-subtitle">The passionate people behind FitPal</p>
        </div>
        <div class="team-grid">
            <?php foreach ($teamMembers as $member): ?>
            <div class="team-card">
                <div class="team-image">
                    <img src="<?php echo htmlspecialchars($member['image']); ?>"
                        alt="<?php echo htmlspecialchars($member['name']); ?>"
                        onerror="this.onerror=null; this.src='<?php echo $sharedAssetBase; ?>assets/images/teams/default-avatar.jpg';">
                </div>
                <div class="team-info">
                    <h3><?php echo htmlspecialchars($member['name']); ?></h3>
                    <p class="team-role"><?php echo htmlspecialchars($member['role']); ?></p>
                    <p class="team-bio"><?php echo htmlspecialchars($member['bio']); ?></p>
                    <a href="mailto:<?php echo htmlspecialchars($member['email']); ?>" class="team-email">
                        <?php echo htmlspecialchars($member['email']); ?>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- CTA Section -->
<section class="about-cta">
    <div class="container">
        <div class="about-cta-content">
            <h2>Ready to start your healthy journey?</h2>
            <p>Join thousands of health-conscious individuals who trust FitPal for their daily meals.</p>
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

<?php
// Include shared footer
require_once __DIR__ . '/../includes/footer.php';
?>