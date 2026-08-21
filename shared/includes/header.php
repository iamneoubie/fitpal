<?php
/**
 * FitPal Shared Header
 * 
 * This is the global header component used across all roles and the public landing page.
 * It includes the HTML head, navigation bar, and shared CSS/JS links.
 * 
 * Usage: require_once __DIR__ . '/shared/includes/header.php';
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Get the correct base path for assets based on the current file location
 * 
 * This function calculates how many levels up we need to go to reach the 
 * project root, then appends 'shared/' to point to the shared directory.
 * 
 * @return string The base path for shared assets (e.g., '../../shared/')
 */
function getAssetBasePath() {
    // Get the current script's directory path relative to the document root
    $scriptPath = $_SERVER['SCRIPT_NAME'];
    
    // Remove the filename to get the directory path
    $dirPath = dirname($scriptPath);
    
    // Count how many segments are in the path (excluding empty segments)
    $segments = array_filter(explode('/', $dirPath));
    $depth = count($segments);
    
    // If we're at the root level (depth 0), use './shared/'
    if ($depth <= 0) {
        return './shared/';
    }
    
    // For each directory level, we need to go up one level
    // Example: /fitpal/customer/pages/ -> depth = 3
    // We need: ../../../shared/
    $basePath = str_repeat('../', $depth) . 'shared/';
    
    return $basePath;
}

/**
 * Alternative: Get base path using the current file's directory
 * This is more reliable when files are included from different locations
 */
function getAssetBasePathFromFile() {
    // Get the absolute path of the current file
    $currentFile = $_SERVER['SCRIPT_FILENAME'];
    
    // Get the directory of the current file
    $currentDir = dirname($currentFile);
    
    // Find the project root (where 'shared' directory exists)
    $projectRoot = $currentDir;
    $found = false;
    
    // Try up to 10 levels up to find the 'shared' directory
    for ($i = 0; $i < 10; $i++) {
        if (is_dir($projectRoot . '/shared')) {
            $found = true;
            break;
        }
        $projectRoot = dirname($projectRoot);
    }
    
    if ($found) {
        // Calculate relative path from current file to shared directory
        $relativePath = str_replace($_SERVER['DOCUMENT_ROOT'], '', $projectRoot . '/shared/');
        return $relativePath;
    }
    
    // Fallback: use the depth-based calculation
    return getAssetBasePath();
}

// Use the more reliable method
$assetBase = getAssetBasePathFromFile();

// For debugging - uncomment to see the calculated path
// error_log("Asset base path: " . $assetBase);

// Get current page for active link highlighting
$currentPage = basename($_SERVER['PHP_SELF']);
$currentDir = basename(dirname($_SERVER['PHP_SELF']));

// Determine which role is currently active (if any)
$activeRole = '';
if (in_array($currentDir, ['customer', 'restaurant', 'rider', 'admin'])) {
    $activeRole = $currentDir;
}

// Check if user is logged in for any role
$isLoggedIn = false;
$userRole = null;
$userName = '';

if (isset($_SESSION['user_id']) && isset($_SESSION['user_role'])) {
    $isLoggedIn = true;
    $userRole = $_SESSION['user_role'];
    $userName = $_SESSION['user_name'] ?? '';
}

// Get the project root URL for absolute paths
$projectRoot = '/fitpal/';
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

    <!-- Global CSS -->
    <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/global.css">
    <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/header.css">

    <!-- Google Fonts (Optional) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Shared JavaScript -->
    <script src="<?php echo $assetBase; ?>assets/ui/js/utils.js" defer></script>
    <script src="<?php echo $assetBase; ?>assets/ui/js/header.js" defer></script>
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
            <button class="menu-toggle" id="menuToggle" aria-label="Toggle navigation menu" aria-expanded="false">
                <span class="menu-icon">
                    <span class="bar"></span>
                    <span class="bar"></span>
                    <span class="bar"></span>
                </span>
            </button>

            <!-- Navigation Section -->
            <nav class="header-nav" id="mainNav" role="navigation" aria-label="Main navigation">
                <!-- Primary Navigation Links -->
                <ul class="nav-list">
                    <?php if ($isLoggedIn): ?>
                    <!-- Logged In User Navigation -->
                    <li class="nav-item">
                        <a href="<?php echo $assetBase; ?>../<?php echo $userRole; ?>/pages/dashboard.php"
                            class="nav-link <?php echo ($currentPage === 'dashboard.php') ? 'active' : ''; ?>">
                            Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo $assetBase; ?>../<?php echo $userRole; ?>/pages/orders.php"
                            class="nav-link <?php echo ($currentPage === 'orders.php') ? 'active' : ''; ?>">
                            Orders
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo $assetBase; ?>../<?php echo $userRole; ?>/pages/profile.php"
                            class="nav-link <?php echo ($currentPage === 'profile.php') ? 'active' : ''; ?>">
                            Profile
                        </a>
                    </li>
                    <?php else: ?>
                    <!-- Public Navigation Links -->
                    <li class="nav-item">
                        <a href="<?php echo $assetBase; ?>../index.php"
                            class="nav-link <?php echo ($currentPage === 'index.php' || $currentPage === '') ? 'active' : ''; ?>">
                            Home
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo $assetBase; ?>../shared/pages/about.php"
                            class="nav-link <?php echo ($currentPage === 'about.php') ? 'active' : ''; ?>">
                            About
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo $assetBase; ?>../shared/pages/contact.php"
                            class="nav-link <?php echo ($currentPage === 'contact.php') ? 'active' : ''; ?>">
                            Contact
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>

                <!-- Authentication / User Actions -->
                <div class="nav-actions">
                    <?php if ($isLoggedIn): ?>
                    <!-- Logged In User -->
                    <div class="user-profile">
                        <span class="user-name"><?php echo htmlspecialchars($userName); ?></span>
                        <a href="<?php echo $assetBase; ?>../shared/backend/scripts/logout.php" class="btn btn-logout"
                            onclick="return confirm('Are you sure you want to logout?');">
                            Logout
                        </a>
                    </div>
                    <?php else: ?>
                    <!-- Guest User - Login Links -->
                    <div class="auth-links">
                        <div class="auth-dropdown">
                            <button class="btn btn-login dropdown-toggle" id="loginDropdown" aria-expanded="false"
                                aria-haspopup="true">
                                Login
                            </button>
                            <ul class="dropdown-menu" aria-labelledby="loginDropdown">
                                <li><a href="<?php echo $assetBase; ?>../customer/pages/login.php">Customer</a></li>
                                <li><a href="<?php echo $assetBase; ?>../restaurant/pages/login.php">Restaurant</a></li>
                                <li><a href="<?php echo $assetBase; ?>../rider/pages/login.php">Rider</a></li>
                                <li><a href="<?php echo $assetBase; ?>../admin/pages/login.php">Admin</a></li>
                            </ul>
                        </div>
                        <a href="<?php echo $assetBase; ?>../customer/pages/register.php" class="btn btn-register">
                            Register
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </nav>
        </div>
    </header>

    <!-- Mobile Navigation Overlay -->
    <div class="mobile-overlay" id="mobileOverlay"></div>

    <!-- Mobile Navigation Menu (hidden by default, shown on mobile) -->
    <nav class="mobile-nav" id="mobileNav" role="navigation" aria-label="Mobile navigation">
        <ul class="mobile-nav-list">
            <?php if ($isLoggedIn): ?>
            <!-- Logged In User Mobile Navigation -->
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>../<?php echo $userRole; ?>/pages/dashboard.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'dashboard.php') ? 'active' : ''; ?>">
                    Dashboard
                </a>
            </li>
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>../<?php echo $userRole; ?>/pages/orders.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'orders.php') ? 'active' : ''; ?>">
                    Orders
                </a>
            </li>
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>../<?php echo $userRole; ?>/pages/profile.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'profile.php') ? 'active' : ''; ?>">
                    Profile
                </a>
            </li>
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>../shared/backend/scripts/logout.php"
                    class="mobile-nav-link mobile-logout">
                    Logout
                </a>
            </li>
            <?php else: ?>
            <!-- Guest Mobile Navigation -->
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>../index.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'index.php' || $currentPage === '') ? 'active' : ''; ?>">
                    Home
                </a>
            </li>
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>../shared/pages/about.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'about.php') ? 'active' : ''; ?>">
                    About
                </a>
            </li>
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>../shared/pages/contact.php"
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
            <li class="mobile-nav-divider"></li>
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>../customer/pages/register.php"
                    class="mobile-nav-link mobile-register">
                    Register
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </nav>

    <!-- Main content wrapper starts here -->
    <main class="main-content" role="main">