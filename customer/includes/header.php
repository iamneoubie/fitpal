<?php
/**
 * FitPal Customer Header
 * 
 * This is the customer-specific header that extends the shared header.
 * It adds customer-specific navigation items and styling.
 * 
 * Usage: require_once __DIR__ . '/includes/header.php';
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in as customer
$isCustomerLoggedIn = isset($_SESSION['user_id']) && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'customer';

// If not logged in as customer, redirect to login
if (!$isCustomerLoggedIn && basename($_SERVER['PHP_SELF']) !== 'login.php' && basename($_SERVER['PHP_SELF']) !== 'register.php') {
    header('Location: login.php');
    exit;
}

// Get customer data from session
$customerName = $_SESSION['user_name'] ?? 'Customer';
$customerId = $_SESSION['user_id'] ?? null;
$customerEmail = $_SESSION['user_email'] ?? '';

// Get current page for active link highlighting
$currentPage = basename($_SERVER['PHP_SELF']);

// Get the base path for assets
function getCustomerAssetBase() {
    $scriptPath = $_SERVER['SCRIPT_NAME'];
    $dirPath = dirname($scriptPath);
    $segments = array_filter(explode('/', $dirPath));
    $depth = count($segments);
    
    // If we're in customer/pages/, we need to go up 2 levels
    // If we're in customer/includes/, we need to go up 2 levels as well
    if ($depth <= 1) {
        return './';
    }
    
    // For customer directory structure, we need to go up to project root
    // Then down to shared for global assets, or customer for local assets
    $basePath = str_repeat('../', $depth - 1) . 'customer/';
    return $basePath;
}

$customerAssetBase = getCustomerAssetBase();

// For shared assets, we need to go up to project root then to shared
function getSharedAssetBase() {
    $scriptPath = $_SERVER['SCRIPT_NAME'];
    $dirPath = dirname($scriptPath);
    $segments = array_filter(explode('/', $dirPath));
    $depth = count($segments);
    
    if ($depth <= 1) {
        return './shared/';
    }
    
    return str_repeat('../', $depth) . 'shared/';
}

$sharedAssetBase = getSharedAssetBase();

// Get cart count for customer
$cartCount = 0;
if ($isCustomerLoggedIn && $customerId) {
    // This will be implemented when database is ready
    // For now, we'll use a placeholder
    $cartCount = isset($_SESSION['cart_count']) ? (int)$_SESSION['cart_count'] : 0;
}

// Get notification count
$notificationCount = 0;
if ($isCustomerLoggedIn && $customerId) {
    // This will be implemented when database is ready
    $notificationCount = isset($_SESSION['notification_count']) ? (int)$_SESSION['notification_count'] : 0;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="FitPal Customer Portal - Order healthy meals and track your nutrition">
    <title>FitPal - Customer Portal</title>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?php echo $sharedAssetBase; ?>assets/images/brand/favicon.ico">
    <link rel="shortcut icon" href="<?php echo $sharedAssetBase; ?>assets/images/brand/favicon.ico">

    <!-- Shared Global CSS -->
    <link rel="stylesheet" href="<?php echo $sharedAssetBase; ?>assets/css/global.css">
    <link rel="stylesheet" href="<?php echo $sharedAssetBase; ?>assets/css/header.css">

    <!-- Customer-specific CSS -->
    <link rel="stylesheet" href="<?php echo $customerAssetBase; ?>assets/css/customer-header.css">
    <link rel="stylesheet" href="<?php echo $customerAssetBase; ?>assets/css/customer.css">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Shared JavaScript -->
    <script src="<?php echo $sharedAssetBase; ?>assets/ui/js/utils.js" defer></script>
    <script src="<?php echo $sharedAssetBase; ?>assets/ui/js/header.js" defer></script>

    <!-- Customer-specific JavaScript -->
    <script src="<?php echo $customerAssetBase; ?>assets/ui/js/customer-header.js" defer></script>
</head>

<body>
    <header class="header customer-header" role="banner">
        <div class="header-container">
            <!-- Logo Section -->
            <div class="header-logo">
                <a href="<?php echo $customerAssetBase; ?>pages/dashboard.php" class="logo-link"
                    aria-label="FitPal Customer Dashboard">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/brand/logo.svg" alt="FitPal Logo"
                        class="logo-image">
                    <span class="logo-text">Fit<span>Pal</span></span>
                </a>
            </div>

            <!-- Mobile Menu Toggle -->
            <button class="menu-toggle" id="menuToggle" aria-label="Toggle navigation menu" aria-expanded="false">
                <span class="menu-icon">
                    <span class="bar"></span>
                    <span class="bar"></span>
                    <span class="bar"></span>
                </span>
            </button>

            <!-- Desktop Navigation -->
            <nav class="header-nav" id="mainNav" role="navigation" aria-label="Customer navigation">
                <ul class="nav-list">
                    <!-- Dashboard -->
                    <li class="nav-item">
                        <a href="<?php echo $customerAssetBase; ?>pages/dashboard.php"
                            class="nav-link <?php echo ($currentPage === 'dashboard.php') ? 'active' : ''; ?>">
                            Dashboard
                        </a>
                    </li>

                    <!-- Menu / Browse Food -->
                    <li class="nav-item">
                        <a href="<?php echo $customerAssetBase; ?>pages/menu.php"
                            class="nav-link <?php echo ($currentPage === 'menu.php') ? 'active' : ''; ?>">
                            Browse Food
                        </a>
                    </li>

                    <!-- Orders -->
                    <li class="nav-item">
                        <a href="<?php echo $customerAssetBase; ?>pages/orders.php"
                            class="nav-link <?php echo ($currentPage === 'orders.php') ? 'active' : ''; ?>">
                            My Orders
                        </a>
                    </li>

                    <!-- Cart with Badge -->
                    <li class="nav-item nav-item-cart">
                        <a href="<?php echo $customerAssetBase; ?>pages/cart.php"
                            class="nav-link cart-link <?php echo ($currentPage === 'cart.php') ? 'active' : ''; ?>">
                            <span class="cart-icon-wrapper">
                                <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/cart.svg" alt="Cart"
                                    class="cart-icon">
                                <?php if ($cartCount > 0): ?>
                                <span class="cart-badge" id="cartBadge"><?php echo $cartCount; ?></span>
                                <?php endif; ?>
                            </span>
                            Cart
                        </a>
                    </li>

                    <!-- Notifications -->
                    <li class="nav-item nav-item-notifications">
                        <a href="<?php echo $customerAssetBase; ?>pages/notifications.php"
                            class="nav-link notification-link <?php echo ($currentPage === 'notifications.php') ? 'active' : ''; ?>">
                            <span class="notification-icon-wrapper">
                                <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/bell.svg"
                                    alt="Notifications" class="notification-icon">
                                <?php if ($notificationCount > 0): ?>
                                <span class="notification-badge"
                                    id="notificationBadge"><?php echo $notificationCount; ?></span>
                                <?php endif; ?>
                            </span>
                            Notifications
                        </a>
                    </li>
                </ul>

                <!-- User Actions -->
                <div class="nav-actions">
                    <div class="user-profile">
                        <div class="user-avatar-wrapper">
                            <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/user.svg" alt="Profile"
                                class="user-avatar">
                        </div>
                        <div class="user-info">
                            <span class="user-name"><?php echo htmlspecialchars($customerName); ?></span>
                            <span class="user-role">Customer</span>
                        </div>
                        <div class="user-dropdown">
                            <button class="user-dropdown-toggle" id="userDropdownToggle" aria-expanded="false"
                                aria-haspopup="true">
                                <span class="dropdown-arrow"></span>
                            </button>
                            <ul class="user-dropdown-menu" aria-labelledby="userDropdownToggle">
                                <li>
                                    <a href="<?php echo $customerAssetBase; ?>pages/profile.php">
                                        <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/user.svg"
                                            alt="Profile">
                                        Profile
                                    </a>
                                </li>
                                <li>
                                    <a href="<?php echo $customerAssetBase; ?>pages/settings.php">
                                        <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/settings.svg"
                                            alt="Settings">
                                        Settings
                                    </a>
                                </li>
                                <li>
                                    <a href="<?php echo $customerAssetBase; ?>pages/wallet.php">
                                        <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/wallet.svg"
                                            alt="Wallet">
                                        Wallet
                                    </a>
                                </li>
                                <li class="dropdown-divider"></li>
                                <li>
                                    <a href="<?php echo $sharedAssetBase; ?>backend/scripts/logout.php"
                                        class="logout-link">
                                        <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/logout.svg"
                                            alt="Logout">
                                        Logout
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </nav>
        </div>
    </header>

    <!-- Mobile Navigation Overlay -->
    <div class="mobile-overlay" id="mobileOverlay"></div>

    <!-- Mobile Navigation Menu -->
    <nav class="mobile-nav" id="mobileNav" role="navigation" aria-label="Mobile navigation">
        <div class="mobile-user-header">
            <div class="mobile-user-avatar">
                <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/user.svg" alt="Profile">
            </div>
            <div class="mobile-user-info">
                <span class="mobile-user-name"><?php echo htmlspecialchars($customerName); ?></span>
                <span class="mobile-user-role">Customer</span>
            </div>
        </div>

        <ul class="mobile-nav-list">
            <!-- Dashboard -->
            <li class="mobile-nav-item">
                <a href="<?php echo $customerAssetBase; ?>pages/dashboard.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'dashboard.php') ? 'active' : ''; ?>">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/dashboard.svg" alt="Dashboard">
                    Dashboard
                </a>
            </li>

            <!-- Menu / Browse Food -->
            <li class="mobile-nav-item">
                <a href="<?php echo $customerAssetBase; ?>pages/menu.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'menu.php') ? 'active' : ''; ?>">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/food.svg" alt="Browse Food">
                    Browse Food
                </a>
            </li>

            <!-- Orders -->
            <li class="mobile-nav-item">
                <a href="<?php echo $customerAssetBase; ?>pages/orders.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'orders.php') ? 'active' : ''; ?>">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/order.svg" alt="Orders">
                    My Orders
                </a>
            </li>

            <!-- Cart -->
            <li class="mobile-nav-item">
                <a href="<?php echo $customerAssetBase; ?>pages/cart.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'cart.php') ? 'active' : ''; ?>">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/cart.svg" alt="Cart">
                    Cart
                    <?php if ($cartCount > 0): ?>
                    <span class="mobile-cart-badge"><?php echo $cartCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>

            <!-- Notifications -->
            <li class="mobile-nav-item">
                <a href="<?php echo $customerAssetBase; ?>pages/notifications.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'notifications.php') ? 'active' : ''; ?>">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/bell.svg" alt="Notifications">
                    Notifications
                    <?php if ($notificationCount > 0): ?>
                    <span class="mobile-notification-badge"><?php echo $notificationCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>

            <li class="mobile-nav-divider"></li>

            <!-- Profile -->
            <li class="mobile-nav-item">
                <a href="<?php echo $customerAssetBase; ?>pages/profile.php" class="mobile-nav-link">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/user.svg" alt="Profile">
                    Profile
                </a>
            </li>

            <!-- Settings -->
            <li class="mobile-nav-item">
                <a href="<?php echo $customerAssetBase; ?>pages/settings.php" class="mobile-nav-link">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/settings.svg" alt="Settings">
                    Settings
                </a>
            </li>

            <!-- Wallet -->
            <li class="mobile-nav-item">
                <a href="<?php echo $customerAssetBase; ?>pages/wallet.php" class="mobile-nav-link">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/wallet.svg" alt="Wallet">
                    Wallet
                </a>
            </li>

            <li class="mobile-nav-divider"></li>

            <!-- Logout -->
            <li class="mobile-nav-item">
                <a href="<?php echo $sharedAssetBase; ?>backend/scripts/logout.php"
                    class="mobile-nav-link mobile-logout">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/logout.svg" alt="Logout">
                    Logout
                </a>
            </li>
        </ul>
    </nav>

    <!-- Main content wrapper -->
    <main class="main-content customer-main-content" role="main">