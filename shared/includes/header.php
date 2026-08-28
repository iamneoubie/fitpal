<?php
/**
 * FitPal Shared Header
 * Usage: require_once __DIR__ . '/shared/includes/header.php';
 */

declare(strict_types=1);

// ─── Session ───
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['created'])) {
    $_SESSION['created'] = time();
} elseif (time() - $_SESSION['created'] > 1800) {
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}

// ─── Database ───
require_once __DIR__ . '/../backend/database/database-connect.php';

// ─── Asset base path ───
function getHeaderAssetBase(): string {
    $scriptPath = $_SERVER['SCRIPT_NAME'];
    $dirPath = dirname($scriptPath);
    $segments = array_filter(explode('/', $dirPath));
    $depth = count($segments);
    return ($depth <= 0) ? './shared/' : str_repeat('../', $depth) . 'shared/';
}
$assetBase = getHeaderAssetBase();

$currentPage = basename($_SERVER['PHP_SELF']);
$currentDir = basename(dirname($_SERVER['PHP_SELF']));
$isLandingPage = ($currentPage === 'index.php' && $currentDir === 'fitpal');

// ─── Page‑specific CSS ───
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

// ─── User session ───
$isLoggedIn = false;
$userRole = null;
$userName = '';
if (isset($_SESSION['user_id'], $_SESSION['user_role'])) {
    $isLoggedIn = true;
    $userRole = $_SESSION['user_role'];
    $userName = $_SESSION['user_name'] ?? '';
}

// ─── Brand assets ───
$brandLogoIco = $assetBase . 'assets/images/brand/Logo.ico';
$brandLogoPng = $assetBase . 'assets/images/brand/Logo.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="FitPal - Healthy Food Delivery">
    <title>FitPal - Healthy Food Delivery</title>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?php echo $brandLogoIco; ?>">
    <link rel="shortcut icon" href="<?php echo $brandLogoIco; ?>">

    <!-- Global CSS -->
    <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/global.css">
    <!-- Header CSS -->
    <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/header.css">
    <!-- Page‑specific CSS -->
    <?php if (!empty($pageCssPath)): ?>
    <link rel="stylesheet" href="<?php echo $pageCssPath; ?>">
    <?php endif; ?>
</head>
<body>
    <header class="header" role="banner">
        <div class="header-container">
            <!-- Logo -->
            <div class="header-logo">
                <a href="<?php echo $assetBase; ?>../index.php" class="logo-link" aria-label="FitPal Home">
                    <img src="<?php echo $brandLogoPng; ?>" alt="FitPal Logo" class="logo-image">
                    <span class="logo-text">Fit<span>Pal</span></span>
                </a>
            </div>

            <!-- Mobile Toggle -->
            <button class="menu-toggle" id="menuToggle" aria-label="Toggle navigation menu" aria-expanded="false" type="button">
                <span class="menu-icon">
                    <span class="bar"></span>
                    <span class="bar"></span>
                    <span class="bar"></span>
                </span>
            </button>

            <!-- Desktop Nav -->
            <nav class="header-nav" id="mainNav" role="navigation" aria-label="Main navigation">
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="<?php echo $assetBase; ?>../index.php" class="nav-link <?php echo ($isLandingPage || $currentPage === '') ? 'active' : ''; ?>">Home</a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo $assetBase; ?>pages/about.php" class="nav-link <?php echo ($currentPage === 'about.php') ? 'active' : ''; ?>">About</a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo $assetBase; ?>pages/contact.php" class="nav-link <?php echo ($currentPage === 'contact.php') ? 'active' : ''; ?>">Contact</a>
                    </li>
                </ul>

                <div class="nav-actions">
                    <?php if ($isLoggedIn): ?>
                        <span class="user-greeting">Hello, <?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?></span>
                        <a href="<?php echo $assetBase; ?>../<?php echo $userRole; ?>/pages/dashboard.php" class="btn btn-primary btn-sm">Dashboard</a>
                        <a href="<?php echo $assetBase; ?>../shared/scripts/logout.php" class="btn btn-outline btn-sm">Logout</a>
                    <?php else: ?>
                        <div class="auth-dropdown">
                            <button class="btn btn-login dropdown-toggle" id="loginDropdown" aria-expanded="false" aria-haspopup="true" type="button">Login</button>
                            <ul class="dropdown-menu" id="dropdownMenu" role="menu">
                                <li role="none"><a href="<?php echo $assetBase; ?>../customer/pages/sign-in.php" role="menuitem">Customer</a></li>
                                <li role="none"><a href="<?php echo $assetBase; ?>../restaurant/pages/sign-in.php" role="menuitem">Restaurant</a></li>
                                <li role="none"><a href="<?php echo $assetBase; ?>../rider/pages/sign-in.php" role="menuitem">Rider</a></li>
                                <li role="none"><a href="<?php echo $assetBase; ?>../admin/pages/sign-in.php" role="menuitem">Admin</a></li>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            </nav>
        </div>
    </header>

    <!-- Mobile Overlay -->
    <div class="mobile-overlay" id="mobileOverlay"></div>

    <!-- Mobile Nav -->
    <nav class="mobile-nav" id="mobileNav" role="navigation" aria-label="Mobile navigation">
        <ul class="mobile-nav-list">
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>../index.php" class="mobile-nav-link <?php echo ($isLandingPage || $currentPage === '') ? 'active' : ''; ?>">Home</a>
            </li>
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>pages/about.php" class="mobile-nav-link <?php echo ($currentPage === 'about.php') ? 'active' : ''; ?>">About</a>
            </li>
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>pages/contact.php" class="mobile-nav-link <?php echo ($currentPage === 'contact.php') ? 'active' : ''; ?>">Contact</a>
            </li>
            <li class="mobile-nav-divider"></li>

            <?php if ($isLoggedIn): ?>
                <li class="mobile-nav-item">
                    <span class="mobile-nav-greeting">Hello, <?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?></span>
                </li>
                <li class="mobile-nav-item">
                    <a href="<?php echo $assetBase; ?>../<?php echo $userRole; ?>/pages/dashboard.php" class="mobile-nav-link mobile-dashboard">Dashboard</a>
                </li>
                <li class="mobile-nav-item">
                    <a href="<?php echo $assetBase; ?>../shared/scripts/logout.php" class="mobile-nav-link mobile-logout">Logout</a>
                </li>
            <?php else: ?>
                <li class="mobile-nav-item"><a href="<?php echo $assetBase; ?>../customer/pages/sign-in.php" class="mobile-nav-link mobile-login">Customer</a></li>
                <li class="mobile-nav-item"><a href="<?php echo $assetBase; ?>../restaurant/pages/sign-in.php" class="mobile-nav-link mobile-login">Restaurant</a></li>
                <li class="mobile-nav-item"><a href="<?php echo $assetBase; ?>../rider/pages/sign-in.php" class="mobile-nav-link mobile-login">Rider</a></li>
                <li class="mobile-nav-item"><a href="<?php echo $assetBase; ?>../admin/pages/sign-in.php" class="mobile-nav-link mobile-login">Admin</a></li>
            <?php endif; ?>
        </ul>
    </nav>

    <main class="main-content" role="main">
        <!-- page content -->
    </main>

    <!-- JavaScript deferred -->
    <script src="<?php echo $assetBase; ?>assets/ui/js/header.js" defer></script>
</body>
</html>