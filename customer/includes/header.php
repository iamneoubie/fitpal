<?php
/**
 * FitPal Customer Header
 *
 * Customer-specific header with conditional navigation based on login status.
 * Only shows Dashboard, Orders, Profile when logged in.
 *
 * @package FitPal
 * @version 1.0
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
require_once __DIR__ . '/../../shared/backend/database/database_connect.php';

// ===== PATH DETECTION =====
function getCustomerAssetBase(): string {
    $scriptPath = $_SERVER['SCRIPT_NAME'];
    $dirPath = dirname($scriptPath);
    $segments = array_filter(explode('/', $dirPath));
    $depth = count($segments);
    return str_repeat('../', $depth + 1) . 'shared/';
}
$assetBase = getCustomerAssetBase();

// ===== FETCH CUSTOMER DATA (if logged in) =====
$isLoggedIn = false;
$userName = '';
$userInitial = '';

if (isset($_SESSION['customer_id']) && !empty($_SESSION['customer_id'])) {
    $isLoggedIn = true;
    try {
        $stmt = $database_connection->prepare(
            "SELECT first_name, last_name FROM customer WHERE customer_id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $_SESSION['customer_id']]);
        $customerData = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($customerData) {
            $userName = trim($customerData['first_name'] . ' ' . $customerData['last_name']);
            $userInitial = strtoupper(substr($customerData['first_name'], 0, 1));
        }
    } catch (PDOException $e) {
        // Silently fail - user will still be logged in
    }
}

// ===== CURRENT PAGE =====
$currentPage = basename($_SERVER['PHP_SELF']);

// ===== PAGE-SPECIFIC CSS PRELOADING =====
$pageCssMap = [
    'login.php'      => 'login.css',
    'register.php'   => 'register.css',
    'dashboard.php'  => 'dashboard.css',
    'menu.php'       => 'menu.css',
    'orders.php'     => 'orders.css',
    'profile.php'    => 'profile.css',
    'cart.php'       => 'cart.css',
    'checkout.php'   => 'checkout.css',
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
    <meta name="description" content="FitPal - Customer Portal">
    <title>FitPal - Customer</title>

    <link rel="icon" type="image/x-icon" href="<?php echo $assetBase; ?>assets/images/brand/favicon.ico">
    <link rel="shortcut icon" href="<?php echo $assetBase; ?>assets/images/brand/favicon.ico">

    <!-- Global CSS -->
    <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/global.css">
    <!-- Shared Header CSS -->
    <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/header.css">
    <!-- Customer Header overrides -->
    <link rel="stylesheet" href="../assets/css/header.css">

    <!-- Page-specific CSS (loaded in head to prevent FOUC) -->
    <?php if (!empty($pageCssPath)): ?>
    <link rel="stylesheet" href="<?php echo $pageCssPath; ?>">
    <?php endif; ?>
</head>

<body>
    <header class="header customer-header" role="banner">
        <div class="header-container">
            <!-- Logo -->
            <div class="header-logo">
                <a href="<?php echo $assetBase; ?>../index.php" class="logo-link" aria-label="FitPal Home">
                    <img src="<?php echo $assetBase; ?>assets/images/brand/logo.svg" alt="FitPal Logo"
                        class="logo-image"
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/brand/logo-placeholder.svg'">
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

            <!-- Navigation -->
            <nav class="header-nav" id="mainNav" role="navigation" aria-label="Customer navigation">

                <?php if ($isLoggedIn): ?>
                <!-- ===== LOGGED IN NAVIGATION ===== -->
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="<?php echo $assetBase; ?>../customer/pages/dashboard.php"
                            class="nav-link <?php echo ($currentPage === 'dashboard.php') ? 'active' : ''; ?>">
                            Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo $assetBase; ?>../customer/pages/menu.php"
                            class="nav-link <?php echo ($currentPage === 'menu.php') ? 'active' : ''; ?>">
                            Menu
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo $assetBase; ?>../customer/pages/orders.php"
                            class="nav-link <?php echo ($currentPage === 'orders.php') ? 'active' : ''; ?>">
                            Orders
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo $assetBase; ?>../customer/pages/profile.php"
                            class="nav-link <?php echo ($currentPage === 'profile.php') ? 'active' : ''; ?>">
                            Profile
                        </a>
                    </li>
                </ul>

                <div class="nav-actions">
                    <!-- User Profile Circle -->
                    <div class="user-profile-circle"
                        title="<?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php if (!empty($userInitial)): ?>
                        <span
                            class="user-initial"><?php echo htmlspecialchars($userInitial, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php else: ?>
                        <img src="<?php echo $assetBase; ?>assets/images/icons/user-profile-circle.svg" alt="Profile"
                            class="profile-icon">
                        <?php endif; ?>
                    </div>
                    <a href="<?php echo $assetBase; ?>../shared/scripts/logout.php"
                        class="btn btn-outline btn-sm logout-btn">Logout</a>
                </div>

                <?php else: ?>
                <!-- ===== NOT LOGGED IN NAVIGATION ===== -->
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="<?php echo $assetBase; ?>../index.php"
                            class="nav-link <?php echo ($currentPage === 'index.php' || $currentPage === '') ? 'active' : ''; ?>">
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
                    <a href="<?php echo $assetBase; ?>../customer/pages/login.php" class="btn btn-primary btn-sm">Sign
                        In</a>
                </div>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <!-- Mobile Overlay & Menu -->
    <div class="mobile-overlay" id="mobileOverlay"></div>

    <nav class="mobile-nav" id="mobileNav" role="navigation" aria-label="Mobile navigation">
        <ul class="mobile-nav-list">

            <?php if ($isLoggedIn): ?>
            <!-- ===== LOGGED IN MOBILE NAV ===== -->
            <li class="mobile-nav-item mobile-user-greeting">
                <div class="mobile-user-avatar">
                    <?php if (!empty($userInitial)): ?>
                    <span
                        class="user-initial-large"><?php echo htmlspecialchars($userInitial, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php else: ?>
                    <img src="<?php echo $assetBase; ?>assets/images/icons/user-profile-circle.svg" alt="Profile">
                    <?php endif; ?>
                </div>
                <span class="mobile-user-name"><?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?></span>
            </li>
            <li class="mobile-nav-divider"></li>

            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>../customer/pages/dashboard.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'dashboard.php') ? 'active' : ''; ?>">
                    Dashboard
                </a>
            </li>
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>../customer/pages/menu.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'menu.php') ? 'active' : ''; ?>">
                    Menu
                </a>
            </li>
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>../customer/pages/orders.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'orders.php') ? 'active' : ''; ?>">
                    Orders
                </a>
            </li>
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>../customer/pages/profile.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'profile.php') ? 'active' : ''; ?>">
                    Profile
                </a>
            </li>
            <li class="mobile-nav-divider"></li>
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>../shared/scripts/logout.php"
                    class="mobile-nav-link mobile-logout">Logout</a>
            </li>

            <?php else: ?>
            <!-- ===== NOT LOGGED IN MOBILE NAV ===== -->
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>../index.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'index.php' || $currentPage === '') ? 'active' : ''; ?>">
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
                <a href="<?php echo $assetBase; ?>../customer/pages/login.php" class="mobile-nav-link mobile-login">Sign
                    In</a>
            </li>
            <?php endif; ?>

        </ul>
    </nav>

    <main class="main-content" role="main">
        <!-- Shared header JS (mobile menu, dropdown, etc.) -->
        <script src="<?php echo $assetBase; ?>assets/ui/js/header.js" defer></script>