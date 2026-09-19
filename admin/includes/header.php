<?php
/**
 * FitPal Admin Header
 *
 * Admin-specific header with conditional navigation based on login
 * status. Mirrors customer/includes/header.php so the two roles stay
 * consistent in behavior and asset resolution.
 *
 * Admin users have a distinct nav surface: Dashboard, Users,
 * Restaurants, Riders, and Profile.
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
require_once __DIR__ . '/../backend/database/admin-connect.php';

// ===== PATH DETECTION =====
/**
 * Get the base path to shared/ from the currently executing page.
 *
 * @return string Asset base path ending with 'shared/'
 */
function getAdminAssetBase(): string {
    $scriptPath = $_SERVER['SCRIPT_NAME'];
    $dirPath    = dirname($scriptPath);
    $segments   = array_filter(explode('/', $dirPath));
    $depth      = count($segments);
    return str_repeat('../', $depth) . 'shared/';
}

$assetBase = getAdminAssetBase();

// ===== FETCH ADMIN DATA (if logged in) =====
$isLoggedIn   = false;
$adminName    = '';
$adminInitial = '';
$adminRole    = '';

if (isset($_SESSION['administrator_id']) && !empty($_SESSION['administrator_id'])) {
    $isLoggedIn = true;
    try {
        $stmt = $database_connection->prepare(
            "SELECT a.first_name, a.last_name, ap.role
             FROM administrator a
             LEFT JOIN administrator_profile ap
                    ON a.administrator_id = ap.administrator_id
             WHERE a.administrator_id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => (int)$_SESSION['administrator_id']]);
        $adminData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($adminData) {
            $adminName    = trim($adminData['first_name'] . ' ' . $adminData['last_name']);
            $adminInitial = strtoupper(substr($adminData['first_name'], 0, 1));
            $adminRole    = (string)($adminData['role'] ?? '');
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
    'users.php'     => 'users.css',
    'restaurants.php' => 'restaurants.css',
    'riders.php'    => 'riders.css',
    'profile.php'   => 'profile.css',
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
    <meta name="description" content="FitPal - Admin Portal">
    <title>FitPal - Admin</title>

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
    <header class="header admin-header" role="banner">
        <div class="header-container">
            <div class="header-logo">
                <a href="<?php echo $assetBase; ?>../index.php" class="logo-link" aria-label="FitPal Home">
                    <img src="<?php echo $assetBase; ?>assets/images/brand/Logo.png" alt="FitPal Logo"
                        class="logo-image">
                    <span class="logo-text">Fit<span>Pal</span></span>
                </a>
            </div>

            <!-- Mobile Toggle (shared pattern) -->
            <button class="menu-toggle" id="menuToggle" aria-label="Toggle navigation menu" aria-expanded="false"
                type="button">
                <span class="menu-icon">
                    <span class="bar"></span>
                    <span class="bar"></span>
                    <span class="bar"></span>
                </span>
            </button>

            <nav class="header-nav" id="mainNav" role="navigation" aria-label="Admin navigation">

                <?php if ($isLoggedIn): ?>
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="dashboard.php"
                            class="nav-link <?php echo ($currentPage === 'dashboard.php') ? 'active' : ''; ?>">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a href="users.php"
                            class="nav-link <?php echo ($currentPage === 'users.php') ? 'active' : ''; ?>">Users</a>
                    </li>
                    <li class="nav-item">
                        <a href="restaurants.php"
                            class="nav-link <?php echo ($currentPage === 'restaurants.php') ? 'active' : ''; ?>">Restaurants</a>
                    </li>
                    <li class="nav-item">
                        <a href="riders.php"
                            class="nav-link <?php echo ($currentPage === 'riders.php') ? 'active' : ''; ?>">Riders</a>
                    </li>
                    <li class="nav-item">
                        <a href="profile.php"
                            class="nav-link <?php echo ($currentPage === 'profile.php') ? 'active' : ''; ?>">Profile</a>
                    </li>
                </ul>

                <div class="nav-actions">
                    <div class="user-profile-circle"
                        title="<?php echo htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php if (!empty($adminInitial)): ?>
                        <span
                            class="user-initial"><?php echo htmlspecialchars($adminInitial, ENT_QUOTES, 'UTF-8'); ?></span>
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

    <!-- Mobile Overlay (shared pattern) -->
    <div class="mobile-overlay" id="mobileOverlay"></div>

    <!-- Mobile Navigation (shared pattern) -->
    <nav class="mobile-nav" id="mobileNav" role="navigation" aria-label="Mobile navigation">
        <ul class="mobile-nav-list">

            <?php if ($isLoggedIn): ?>
            <li class="mobile-nav-item mobile-user-greeting">
                <div class="mobile-user-avatar">
                    <?php if (!empty($adminInitial)): ?>
                    <span
                        class="user-initial-large"><?php echo htmlspecialchars($adminInitial, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php else: ?>
                    <img src="<?php echo $assetBase; ?>assets/images/icons/user-profile-circle.svg" alt="Profile">
                    <?php endif; ?>
                </div>
                <span class="mobile-user-name"><?php echo htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8'); ?></span>
            </li>
            <li class="mobile-nav-divider"></li>
            <li class="mobile-nav-item">
                <a href="dashboard.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'dashboard.php') ? 'active' : ''; ?>">Dashboard</a>
            </li>
            <li class="mobile-nav-item">
                <a href="users.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'users.php') ? 'active' : ''; ?>">Users</a>
            </li>
            <li class="mobile-nav-item">
                <a href="restaurants.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'restaurants.php') ? 'active' : ''; ?>">Restaurants</a>
            </li>
            <li class="mobile-nav-item">
                <a href="riders.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'riders.php') ? 'active' : ''; ?>">Riders</a>
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

        <!-- Load ONLY the admin header JS (not the shared one) -->
        <script src="../assets/ui/js/header.js" defer></script>