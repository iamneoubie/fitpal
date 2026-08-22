<?php
/**
 * FitPal Privacy Policy Page
 * 
 * This is the public privacy policy page for the demonstration project.
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
require_once __DIR__ . '/../includes/header.php';

/**
 * Get the base path for assets based on current file location
 * 
 * @return string The asset base path
 */
function getPrivacyAssetBase(): string {
    $scriptPath = $_SERVER['SCRIPT_NAME'];
    $dirPath = dirname($scriptPath);
    $segments = array_filter(explode('/', $dirPath));
    $depth = count($segments);
    
    if ($depth <= 0) {
        return './shared/';
    }
    
    return str_repeat('../', $depth) . 'shared/';
}

$assetBase = getPrivacyAssetBase();

// Policy sections data
$policySections = [
    [
        'id' => 'overview',
        'title' => 'Project Overview',
        'content' => [
            'FitPal is a Food Ordering and Delivery System with Dietary and Nutritional Information.',
            'It is designed for health-conscious consumers and individuals with dietary restrictions.',
            'This is a demonstration project created for academic purposes.',
            'The platform allows restaurants to input nutritional data and dietary tags for their menus, and enables customers to filter meals by dietary preferences and allergies.'
        ]
    ],
    [
        'id' => 'collection',
        'title' => 'Information We Collect',
        'content' => [
            'For demonstration purposes, the system collects:',
            [
                'Customer Information: Name, email, contact number, address, dietary preferences, allergies',
                'Restaurant Information: Business name, branch locations, menu items with nutritional data',
                'Rider Information: Name, contact number, vehicle details, location',
                'Order Information: Order details, delivery address, special instructions, payment method',
                'Financial Information: Wallet balance, transaction history'
            ],
            'Security Warning: Do not use real personal information. This is a demonstration project only.'
        ]
    ],
    [
        'id' => 'usage',
        'title' => 'How We Use Information',
        'content' => [
            'The collected information is used for demonstration purposes:',
            [
                'To create and manage user accounts',
                'To process orders and track deliveries',
                'To provide dietary filtering and recommendations',
                'To manage restaurant menus and nutritional information',
                'To handle wallet transactions',
                'To generate analytics and reports'
            ]
        ]
    ],
    [
        'id' => 'data',
        'title' => 'Data Storage',
        'content' => [
            'The following data is stored in the database:',
            [
                'Accounts: Customer, rider, restaurant, and administrator accounts',
                'Profiles: Dietary preferences, allergies, fitness goals',
                'Menus: Product information, nutritional data, dietary tags, allergens',
                'Orders: Order history, status, special instructions',
                'Transactions: Wallet payments, deposits, refunds',
                'Feedback: Ratings and reviews'
            ]
        ]
    ],
    [
        'id' => 'security',
        'title' => 'Data Security',
        'content' => [
            'This project uses basic security measures suitable for demonstration only.',
            [
                'Passwords are hashed using PHP\'s password_hash()',
                'Prepared statements prevent SQL injection',
                'Session management with 30-minute timeout',
                'Not secure for production use'
            ]
        ]
    ],
    [
        'id' => 'payment',
        'title' => 'Payment Disclaimer',
        'content' => [
            'No actual payment processing occurs on this platform.',
            [
                'The wallet system is a demonstration',
                'No real money is transacted',
                'QR codes are for demonstration only',
                'Payment methods (COD, Wallet, Online) are simulated'
            ]
        ]
    ],
    [
        'id' => 'liability',
        'title' => 'Limitation of Liability',
        'content' => [
            'The developers of this demonstration project:',
            [
                'Are not responsible for any real money transfers',
                'Are not liable for damages from use',
                'Are not liable for data loss',
                'This is an academic demonstration project'
            ]
        ]
    ],
    [
        'id' => 'contact',
        'title' => 'Contact Information',
        'content' => [
            'For questions regarding this demonstration project:',
            [
                'Project: FitPal - Food Ordering and Delivery System',
                'Purpose: Academic Demonstration',
                'Email: project@fitpal-demo.com'
            ]
        ]
    ]
];
?>
<!-- ============================================
    PRIVACY POLICY PAGE CSS
    ============================================ -->
<link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/privacy_policy.css">
<link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/footer.css">

<!-- ============================================
    PRIVACY POLICY CONTENT
    ============================================ -->

<div class="content">

    <!-- Hero Section -->
    <section class="privacy-hero" aria-labelledby="privacy-hero-title">
        <div class="container">
            <div class="privacy-hero-content">
                <p class="privacy-hero-title" id="privacy-hero-title">
                    Privacy <span>Policy</span>
                </p>
                <p class="privacy-hero-subtitle">Last Updated: August 23, 2026</p>
            </div>
        </div>
    </section>

    <!-- Policy Content -->
    <section class="privacy-section" aria-labelledby="privacy-content-title">
        <div class="container">
            <p class="sr-only" id="privacy-content-title">Privacy Policy Content</p>
            <div class="privacy-content">

                <!-- Disclaimer -->
                <div class="privacy-disclaimer" role="alert">
                    <p class="privacy-disclaimer-text">
                        <strong>Important:</strong> This is a demonstration project. Do not use real personal
                        information. All data entered is for demonstration purposes only.
                    </p>
                </div>

                <?php foreach ($policySections as $section): ?>
                <div id="section-<?php echo $section['id']; ?>" class="privacy-block">
                    <p class="privacy-block-title">
                        <?php echo htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8'); ?></p>

                    <?php foreach ($section['content'] as $item): ?>
                    <?php if (is_string($item)): ?>
                    <p class="privacy-block-text"><?php echo htmlspecialchars($item, ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php elseif (is_array($item)): ?>
                    <ul class="privacy-block-list">
                        <?php foreach ($item as $listItem): ?>
                        <li><?php echo htmlspecialchars($listItem, ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>

            </div>
        </div>
    </section>

</div>

<?php
// Include shared footer
require_once __DIR__ . '/../includes/footer.php';
?>