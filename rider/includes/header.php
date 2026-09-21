<?php
/**
 * FitPal Rider Header
 *
 * Rider-specific header with conditional navigation based on login
 * status. Mirrors customer/includes/header.php and
 * restaurant/includes/header.php so all roles stay consistent.
 *
 * Rider users do NOT have a cart, so there is no cart badge and no
 * cart-count query here. The nav reflects rider-only surfaces:
 * Dashboard, Deliveries, Earnings, and Profile.
 *
 * @package FitPal
 * @version 1.4 — Adds logout confirmation modal (mirrors the
 *                restaurant header). Desktop avatar and mobile
 *                greeting link to profile.php. Logout triggers use
 *                data-logout-trigger so logout.js can intercept.
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
    'sign-in.php'    => 'sign-in.css',
    'sign-up.php'    => 'sign-up.css',
    'dashboard.php'  => 'dashboard.css',
    'deliveries.php' => 'deliveries.css',
    'earnings.php'   => 'earnings.css',
    'profile.php'    => 'profile.css',
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

    <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/global.css">
    <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/header.css">
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
                    <a href="profile.php" class="user-profile-circle"
                        title="<?php echo htmlspecialchars($riderName, ENT_QUOTES, 'UTF-8'); ?>"
                        aria-label="Go to profile">
                        <?php if (!empty($riderInitial)): ?>
                        <span
                            class="user-initial"><?php echo htmlspecialchars($riderInitial, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php else: ?>
                        <img src="<?php echo $assetBase; ?>assets/images/icons/user-profile-circle.svg" alt="Profile"
                            class="profile-icon">
                        <?php endif; ?>
                    </a>
                    <button type="button" class="btn btn-outline btn-sm logout-btn" data-logout-trigger>
                        Logout
                    </button>
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
                <a href="profile.php" class="mobile-user-greeting-link">
                    <div class="mobile-user-avatar">
                        <?php if (!empty($riderInitial)): ?>
                        <span
                            class="user-initial-large"><?php echo htmlspecialchars($riderInitial, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php else: ?>
                        <img src="<?php echo $assetBase; ?>assets/images/icons/user-profile-circle.svg" alt="Profile">
                        <?php endif; ?>
                    </div>
                    <span class="mobile-user-name">
                        <?php echo htmlspecialchars($riderName, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </a>
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
                <button type="button" class="mobile-nav-link mobile-logout" data-logout-trigger>
                    Logout
                </button>
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

    <!-- ============================================
         LOGOUT CONFIRMATION MODAL
         ============================================ -->
    <div class="logout-modal" id="logoutModal" style="display: none;" role="dialog" aria-modal="true"
        aria-labelledby="logoutModalTitle">
        <div class="logout-modal-overlay" data-logout-cancel></div>
        <div class="logout-modal-content">
            <div class="logout-modal-icon" aria-hidden="true">
                <img src="<?php echo $assetBase; ?>assets/images/icons/logoutsvg.svg" alt=""
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/information-fill.svg'">
            </div>
            <p class="logout-modal-title" id="logoutModalTitle">Sign out?</p>
            <p class="logout-modal-text">You'll need to sign in again to access the rider dashboard.</p>
            <div class="logout-modal-actions">
                <button type="button" class="logout-btn-cancel" data-logout-cancel>Cancel</button>
                <a href="../backend/handlers/sign-out-handler.php" class="logout-btn-confirm">
                    Yes, sign out
                </a>
            </div>
        </div>
    </div>

    <main class="main-content" role="main">

        <script src="../assets/ui/js/header.js" defer></script>
        <script src="../assets/ui/js/logout.js" defer></script>