<?php
/**
 * FitPal Terms & Conditions Page
 *
 * Public-facing terms and conditions for the demonstration project.
 * Belongs to the public context and runs on its own PHP session
 * (PHPSESSID_PUBLIC), separate from every authenticated role's
 * session.
 *
 * @package FitPal
 * @version 2.0 — Per-role session migration (Option B). The page
 *                now bootstraps the public session itself before
 *                including the shared header, which resolves the
 *                "Configuration Error" that appeared when the
 *                header's precondition check failed. The local
 *                asset-base function was removed in favor of the
 *                $assetBase provided by the header. The redundant
 *                <link> for the page CSS was removed, as the shared
 *                header now handles it.
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
// The shared header verifies that the active session is the public
// session and provides the $assetBase variable.
// ---------------------------------------------------------------------

require_once __DIR__ . '/../includes/header.php';

// Terms sections data
$termsSections = [
    [
        'id' => 'acceptance',
        'title' => 'Acceptance of Terms',
        'content' => [
            'By accessing or using FitPal, you agree to be bound by these Terms and Conditions.',
            'If you do not agree to all terms, do not use this demonstration site.',
            'These terms apply to all visitors, users, and others who access the service for demonstration purposes.'
        ]
    ],
    [
        'id' => 'demonstration',
        'title' => 'Demonstration Nature',
        'content' => [
            'This platform is a demonstration project only.',
            [
                'No real products will be shipped or delivered',
                'No actual money transactions occur on this platform',
                'All prices, orders, and purchases are simulated',
                'The site exists to demonstrate functionality',
                'This is an academic project'
            ]
        ]
    ],
    [
        'id' => 'accounts',
        'title' => 'User Accounts',
        'content' => [
            'This demonstration project supports four user roles:',
            [
                'Customer: Browse menus, place orders, track deliveries',
                'Restaurant: Manage menu, view orders, process kitchen queue',
                'Rider: Accept deliveries, update order status',
                'Administrator: Manage users, view analytics, oversee operations'
            ],
            'Security Warning: Do not use real passwords or personal information. Use test data only.'
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
                'Payment methods (COD, Wallet, Online) are simulated',
                'QR codes are for demonstration only'
            ]
        ]
    ],
    [
        'id' => 'conduct',
        'title' => 'User Conduct',
        'content' => [
            'Users of this demonstration platform agree to:',
            [
                'Use the platform respectfully for testing purposes',
                'Not attempt to hack or compromise the site',
                'Not upload inappropriate or offensive content',
                'Understand this is a demonstration project, not a commercial service'
            ]
        ]
    ],
    [
        'id' => 'data',
        'title' => 'Data Storage',
        'content' => [
            'The following data is stored in the database:',
            [
                'Accounts: Customer, rider, restaurant, administrator',
                'Profiles: Dietary preferences, allergies, fitness goals',
                'Menus: Products, nutritional data, dietary tags, allergens',
                'Orders: Order history, status, special instructions',
                'Transactions: Wallet payments, deposits, refunds',
                'Feedback: Ratings and reviews'
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

<div class="content">

    <!-- Hero Section -->
    <section class="terms-hero" aria-labelledby="terms-hero-title">
        <div class="container">
            <div class="terms-hero-content">
                <p class="terms-hero-title" id="terms-hero-title">
                    Terms & <span>Conditions</span>
                </p>
                <p class="terms-hero-subtitle">Last Updated: August 23, 2026</p>
            </div>
        </div>
    </section>

    <!-- Terms Content -->
    <section class="terms-section" aria-labelledby="terms-content-title">
        <div class="container">
            <p class="sr-only" id="terms-content-title">Terms and Conditions Content</p>
            <div class="terms-content">

                <!-- Disclaimer -->
                <div class="terms-disclaimer" role="alert">
                    <p class="terms-disclaimer-text">
                        <strong>Important:</strong> This is a demonstration project. By using this website, you
                        acknowledge that this is for demonstration purposes only. Do not use real personal information
                        or attempt real financial transactions.
                    </p>
                </div>

                <?php foreach ($termsSections as $section): ?>
                <div id="section-<?php echo htmlspecialchars($section['id'], ENT_QUOTES, 'UTF-8'); ?>"
                    class="terms-block">
                    <p class="terms-block-title"><?php echo htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8'); ?>
                    </p>

                    <?php foreach ($section['content'] as $item): ?>
                    <?php if (is_string($item)): ?>
                    <p class="terms-block-text"><?php echo htmlspecialchars($item, ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php elseif (is_array($item)): ?>
                    <ul class="terms-block-list">
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