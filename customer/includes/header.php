<?php
/**
 * FitPal Customer Header
 *
 * Customer-specific header with conditional navigation based on login status.
 *
 * @package FitPal
 * @version 1.2
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
require_once __DIR__ . '/../backend/database/customer-connect.php';

// ===== PATH DETECTION =====
/**
 * Get the base path to shared/ from the currently executing page.
 *
 * A page at customer/pages/foo.php has SCRIPT_NAME depth 2
 * (customer, pages), so it needs ../../shared/ — no +1 adjustment.
 *
 * @return string Asset base path ending with 'shared/'
 */
function getCustomerAssetBase(): string {
    $scriptPath = $_SERVER['SCRIPT_NAME'];
    $dirPath    = dirname($scriptPath);
    $segments   = array_filter(explode('/', $dirPath));
    $depth      = count($segments);
    return str_repeat('../', $depth) . 'shared/';
}

$assetBase = getCustomerAssetBase();

// ===== FETCH CUSTOMER DATA (if logged in) =====
$isLoggedIn  = false;
$userName    = '';
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
            $userName    = trim($customerData['first_name'] . ' ' . $customerData['last_name']);
            $userInitial = strtoupper(substr($customerData['first_name'], 0, 1));
        }
    } catch (PDOException $e) {
        // Silently fail — login state still valid
    }
}

// ===== CURRENT PAGE =====
$currentPage = basename($_SERVER['PHP_SELF']);

// ===== PAGE-SPECIFIC CSS PRELOADING =====
$pageCssMap = [
    'sign-in.php'   => 'sign-in.css',
    'sign-up.php'   => 'sign-up.css',
    'dashboard.php' => 'dashboard.css',
    'menu.php'      => 'menu.css',
    'orders.php'    => 'orders.css',
    'profile.php'   => 'profile.css',
    'cart.php'      => 'cart.css',
    'checkout.php'  => 'checkout.css',
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

    <link rel="icon" type="image/x-icon" href="<?php echo $assetBase; ?>assets/images/brand/Logo.ico">
    <link rel="shortcut icon" href="<?php echo $assetBase; ?>assets/images/brand/Logo.ico">

    <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/global.css">
    <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/header.css">
    <link rel="stylesheet" href="../assets/css/header.css">

    <?php if (!empty($pageCssPath)): ?>
    <link rel="stylesheet" href="<?php echo $pageCssPath; ?>">
    <?php endif; ?>
</head>

<body>
    <header class="header customer-header" role="banner">
        <div class="header-container">
            <div class="header-logo">
                <a href="<?php echo $assetBase; ?>../index.php" class="logo-link" aria-label="FitPal Home">
                    <img src="<?php echo $assetBase; ?>assets/images/brand/Logo.png" alt="FitPal Logo"
                        class="logo-image">
                    <span class="logo-text">Fit<span>Pal</span></span>
                </a>
            </div>

            <button class="menu-toggle" id="menuToggle" aria-label="Toggle navigation menu"
                aria-expanded="false" type="button">
                <span class="menu-icon">
                    <span class="bar"></span><span class="bar"></span><span class="bar"></span>
                </span>
            </button>

            <nav class="header-nav" id="mainNav" role="navigation" aria-label="Customer navigation">

                <?php if ($isLoggedIn): ?>
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="dashboard.php"
                            class="nav-link <?php echo ($currentPage === 'dashboard.php') ? 'active' : ''; ?>">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a href="menu.php"
                            class="nav-link <?php echo ($currentPage === 'menu.php') ? 'active' : ''; ?>">Menu</a>
                    </li>
                    <li class="nav-item">
                        <a href="orders.php"
                            class="nav-link <?php echo ($currentPage === 'orders.php') ? 'active' : ''; ?>">Orders</a>
                    </li>
                    <li class="nav-item">
                        <a href="profile.php"
                            class="nav-link <?php echo ($currentPage === 'profile.php') ? 'active' : ''; ?>">Profile</a>
                    </li>
                </ul>

                <div class="nav-actions">
                    <div class="user-profile-circle"
                        title="<?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php if (!empty($userInitial)): ?>
                        <span class="user-initial"><?php echo htmlspecialchars($userInitial, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php else: ?>
                        <img src="<?php echo $assetBase; ?>assets/images/icons/user-profile-circle.svg"
                            alt="Profile" class="profile-icon">
                        <?php endif; ?>
                    </div>
                    <a href="../backend/handlers/sign-out-handler.php" data-signout
                        class="btn btn-outline btn-sm logout-btn">Logout</a>
                </div>

                <?php else: ?>
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="<?php echo $assetBase; ?>../index.php"
                            class="nav-link">Home</a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo $assetBase; ?>pages/about.php"
                            class="nav-link">About</a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo $assetBase; ?>pages/contact.php"
                            class="nav-link">Contact</a>
                    </li>
                </ul>

                <div class="nav-actions">
                    <a href="sign-in.php" class="btn btn-primary btn-sm">Sign In</a>
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
                    <?php if (!empty($userInitial)): ?>
                    <span class="user-initial-large"><?php echo htmlspecialchars($userInitial, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php else: ?>
                    <img src="<?php echo $assetBase; ?>assets/images/icons/user-profile-circle.svg" alt="Profile">
                    <?php endif; ?>
                </div>
                <span class="mobile-user-name"><?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?></span>
            </li>
            <li class="mobile-nav-divider"></li>
            <li class="mobile-nav-item">
                <a href="dashboard.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'dashboard.php') ? 'active' : ''; ?>">Dashboard</a>
            </li>
            <li class="mobile-nav-item">
                <a href="menu.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'menu.php') ? 'active' : ''; ?>">Menu</a>
            </li>
            <li class="mobile-nav-item">
                <a href="orders.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'orders.php') ? 'active' : ''; ?>">Orders</a>
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
                <a href="sign-in.php" class="mobile-nav-link mobile-login">Sign In</a>
            </li>
            <?php endif; ?>
        </ul>
    </nav>

    <main class="main-content" role="main">

    <script src="../assets/ui/js/header.js" defer></script>