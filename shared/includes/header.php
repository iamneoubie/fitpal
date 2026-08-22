<?php
/**
 * FitPal Shared Header
 * 
 * This is the global header component used across all roles and the public landing page.
 * It includes the HTML head, navigation bar, and shared CSS/JS links.
 * 
 * Usage: require_once __DIR__ . '/shared/includes/header.php';
 * 
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ===== PATH DETECTION =====
/**
 * Get the base path for assets based on current file location
 * 
 * @return string The asset base path
 */
function getHeaderAssetBase(): string {
    $scriptPath = $_SERVER['SCRIPT_NAME'];
    $dirPath = dirname($scriptPath);
    $segments = array_filter(explode('/', $dirPath));
    $depth = count($segments);
    
    if ($depth <= 0) {
        return './shared/';
    }
    
    return str_repeat('../', $depth) . 'shared/';
}

$assetBase = getHeaderAssetBase();

// Get current page for active link highlighting
$currentPage = basename($_SERVER['PHP_SELF']);
$currentDir = basename(dirname($_SERVER['PHP_SELF']));

// Check if we're on the landing page (root index.php)
$isLandingPage = ($currentPage === 'index.php' && $currentDir === 'fitpal');

// Determine page-specific CSS to load in head
$pageCssMap = [
    'index.php' => 'landing.css',
    'about.php' => 'about.css',
    'contact.php' => 'contact.css',
    'privacy_policy.php' => 'privacy_policy.css',
    'terms_conditions.php' => 'terms_conditions.css'
];

$pageCssFile = $pageCssMap[$currentPage] ?? '';
$pageCssPath = '';
if (!empty($pageCssFile) && file_exists(__DIR__ . '/../assets/css/' . $pageCssFile)) {
    $pageCssPath = $assetBase . 'assets/css/' . $pageCssFile;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="FitPal - Dietary Meal Ordering and Restaurant Nutrition Analytics System">
    <title>FitPal - Healthy Food Delivery</title>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?php echo $assetBase; ?>assets/images/brand/favicon.ico">
    <link rel="shortcut icon" href="<?php echo $assetBase; ?>assets/images/brand/favicon.ico">

    <!-- ============================================
         CSS - Load in HEAD for proper rendering
         ============================================ -->
    <!-- Global CSS (variables, utilities, base styles) -->
    <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/global.css">

    <!-- Header CSS -->
    <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/header.css">

    <!-- Page-Specific CSS (loaded in head for no FOUC) -->
    <?php if (!empty($pageCssPath)): ?>
    <link rel="stylesheet" href="<?php echo $pageCssPath; ?>">
    <?php endif; ?>
</head>

<body>
    <header class="header" role="banner">
        <div class="header-container">
            <!-- Logo Section -->
            <div class="header-logo">
                <a href="<?php echo $assetBase; ?>../index.php" class="logo-link" aria-label="FitPal Home">
                    <img src="<?php echo $assetBase; ?>assets/images/brand/logo.svg" alt="FitPal Logo"
                        class="logo-image">
                    <span class="logo-text">Fit<span>Pal</span></span>
                </a>
            </div>

            <!-- Mobile Menu Toggle -->
            <button class="menu-toggle" id="menuToggle" aria-label="Toggle navigation menu" aria-expanded="false"
                type="button">
                <span class="menu-icon">
                    <span class="bar"></span>
                    <span class="bar"></span>
                    <span class="bar"></span>
                </span>
            </button>

            <!-- Navigation Section -->
            <nav class="header-nav" id="mainNav" role="navigation" aria-label="Main navigation">
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="<?php echo $assetBase; ?>../index.php"
                            class="nav-link <?php echo ($isLandingPage || $currentPage === '') ? 'active' : ''; ?>">
                            Home
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo $assetBase; ?>pages/about.php"
                            class="nav-link <?php echo ($currentPage === 'about.php') ? 'active' : ''; ?>">
                            About
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo $assetBase; ?>pages/contact.php"
                            class="nav-link <?php echo ($currentPage === 'contact.php') ? 'active' : ''; ?>">
                            Contact
                        </a>
                    </li>
                </ul>

                <div class="nav-actions">
                    <!-- Login Dropdown -->
                    <div class="auth-dropdown">
                        <button class="btn btn-login dropdown-toggle" id="loginDropdown" aria-expanded="false"
                            aria-haspopup="true" type="button">
                            Login ▾
                        </button>
                        <ul class="dropdown-menu" id="dropdownMenu" role="menu">
                            <li role="none">
                                <a href="<?php echo $assetBase; ?>../customer/pages/login.php" role="menuitem">
                                    Customer
                                </a>
                            </li>
                            <li role="none">
                                <a href="<?php echo $assetBase; ?>../restaurant/pages/login.php" role="menuitem">
                                    Restaurant
                                </a>
                            </li>
                            <li role="none">
                                <a href="<?php echo $assetBase; ?>../rider/pages/login.php" role="menuitem">
                                    Rider
                                </a>
                            </li>
                            <li role="none">
                                <a href="<?php echo $assetBase; ?>../admin/pages/login.php" role="menuitem">
                                    Admin
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </nav>
        </div>
    </header>

    <!-- Mobile Navigation Overlay -->
    <div class="mobile-overlay" id="mobileOverlay"></div>

    <!-- Mobile Navigation Menu -->
    <nav class="mobile-nav" id="mobileNav" role="navigation" aria-label="Mobile navigation">
        <ul class="mobile-nav-list">
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>../index.php"
                    class="mobile-nav-link <?php echo ($isLandingPage || $currentPage === '') ? 'active' : ''; ?>">
                    Home
                </a>
            </li>
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>pages/about.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'about.php') ? 'active' : ''; ?>">
                    About
                </a>
            </li>
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>pages/contact.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'contact.php') ? 'active' : ''; ?>">
                    Contact
                </a>
            </li>
            <li class="mobile-nav-divider"></li>
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>../customer/pages/login.php" class="mobile-nav-link mobile-login">
                    Customer Login
                </a>
            </li>
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>../restaurant/pages/login.php" class="mobile-nav-link mobile-login">
                    Restaurant Login
                </a>
            </li>
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>../rider/pages/login.php" class="mobile-nav-link mobile-login">
                    Rider Login
                </a>
            </li>
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>../admin/pages/login.php" class="mobile-nav-link mobile-login">
                    Admin Login
                </a>
            </li>
        </ul>
    </nav>

    <main class="main-content" role="main">

        <!-- ============================================
         JavaScript - Load at end of body with defer
         ============================================ -->
        <script src="<?php echo $assetBase; ?>assets/ui/js/header.js" defer></script>