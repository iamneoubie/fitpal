<?php
/**
 * FitPal Admin Header
 *
 * Administrator-specific header with conditional navigation based on
 * login status.
 *
 * ---------------------------------------------------------------------
 * CSS LOADING — CRITICAL
 * ---------------------------------------------------------------------
 * The page-specific <link> is emitted UNCONDITIONALLY whenever
 * $pageCssFile is non-empty. The previous version gated the link on
 * file_exists(), which meant a wrong filename or a cache issue
 * produced a silent failure — no <link>, no console error, page
 * looks unstyled and there is nothing pointing at the cause.
 *
 * Now the <link> is always emitted. If the file is missing, the
 * browser will log a 404 in the Network tab, which is exactly the
 * signal we want for debugging.
 *
 * Verify a page is loading its CSS by opening DevTools → Network →
 * filter "CSS" → reload. You should see:
 *     global.css          200
 *     header.css          200
 *     admin-tables.css    200  (on restaurants.php / riders.php)
 *
 * If admin-tables.css shows 404, the file is not at
 *     fitpal/admin/assets/css/admin-tables.css
 * and no CSS on this page will apply.
 * ---------------------------------------------------------------------
 *
 * @package FitPal
 * @version 3.0 — Removed file_exists() gate. Link is now always
 *                emitted when a page CSS file is mapped.
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
 * Absolute path to the shared/ directory from the current page,
 * as a URL fragment. Used for global assets (images, shared CSS).
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

if (!empty($_SESSION['administrator_id'])) {
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

// ===== PAGE-SPECIFIC CSS =====
//
// Map: current page filename → CSS filename inside admin/assets/css/
//
// The CSS file is emitted UNCONDITIONALLY when a mapping exists.
// There is no file_exists() gate. If the file is missing, the
// browser logs a 404 in DevTools → Network, which is the correct
// debugging signal.
//
$pageCssMap = [
    'sign-in.php'     => 'sign-in.css',
    'dashboard.php'   => 'dashboard.css',
    'restaurants.php' => 'admin-tables.css',
    'riders.php'      => 'admin-tables.css',
    'profile.php'     => 'profile.css',
];

$pageCssFile = $pageCssMap[$currentPage] ?? '';
$pageCssHref = $pageCssFile !== '' ? '../assets/css/' . $pageCssFile : '';

// ===== CACHE BUSTER =====
//
// Appends the file's modification time as a query string, so the
// browser refetches whenever the file changes on disk. Without this,
// a hard refresh is required after every CSS edit.
//
$pageCssVersion = '';
if ($pageCssFile !== '') {
    $abs = __DIR__ . '/../assets/css/' . $pageCssFile;
    if (is_file($abs)) {
        $pageCssVersion = '?v=' . filemtime($abs);
    }
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

    <!--
        Admin CSS load order:
          1. shared/global.css    — variables, resets, utilities
          2. admin/header.css     — header + base layout skeleton
          3. page-specific CSS    — MUST be last so a page can override
    -->
    <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/header.css">

    <?php if ($pageCssHref !== ''): ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($pageCssHref . $pageCssVersion, ENT_QUOTES, 'UTF-8'); ?>">
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

    <div class="mobile-overlay" id="mobileOverlay"></div>

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

        <script src="../assets/ui/js/header.js" defer></script>