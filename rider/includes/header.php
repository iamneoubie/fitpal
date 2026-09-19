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
 * ---------------------------------------------------------------------
 * CSS LOADING NOTES (v1.1)
 * ---------------------------------------------------------------------
 * This file loads ONLY admin/assets/css/header.css — NOT
 * shared/assets/css/header.css. The shared file defines the public
 * site header (.header, .nav-list, .menu-toggle, etc.) but admin
 * pages have their own copy of those rules inside admin/header.css.
 * Loading both produced a size-pop when the second file arrived,
 * because both define the same selectors with subtly different
 * box-model values.
 *
 * The base layout skeleton (.main-content, .content) is also
 * declared in admin/header.css so it is present on EVERY admin page,
 * regardless of which page-specific CSS is loaded. Previously
 * .content lived in dashboard.css / profile.css / sign-in.css, so
 * switching pages made the rule appear and disappear — which is
 * what caused the footer to jump.
 * ---------------------------------------------------------------------
 *
 * @package FitPal
<<<<<<< Updated upstream:rider/includes/header.php
 * @version 1.1 — Adds currentPage detection for deliveries/earnings;
 *                aligns with customer header structure.
=======
 * @version 1.1 — Single header.css, layout skeleton moved to admin/header.css
>>>>>>> Stashed changes:admin/includes/header.php
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
<<<<<<< Updated upstream:rider/includes/header.php
    'sign-in.php'   => 'sign-in.css',
    'sign-up.php'   => 'sign-up.css',
    'dashboard.php' => 'dashboard.css',
    'deliveries.php'=> 'deliveries.css',
    'earnings.php'  => 'earnings.css',
    'profile.php'   => 'profile.css',
=======
    'sign-in.php'     => 'sign-in.css',
    'dashboard.php'   => 'dashboard.css',
    'users.php'       => 'admin-tables.css',
    'restaurants.php' => 'admin-tables.css',
    'riders.php'      => 'admin-tables.css',
    'profile.php'     => 'profile.css',
>>>>>>> Stashed changes:admin/includes/header.php
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

    <!--
        Admin CSS load order:
          1. shared/global.css    — CSS variables, resets, utility classes
          2. admin/header.css     — admin header + base layout skeleton
          3. page-specific CSS    — dashboard.css / admin-tables.css / etc.

        shared/header.css is deliberately NOT loaded. Its selectors
        duplicate what's already in admin/header.css, and loading both
        caused a visible size-pop on first paint.
    -->
    <link rel="preload" href="../assets/css/header.css" as="style">
    <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/global.css">
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