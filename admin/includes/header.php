<?php
/**
 * FitPal Admin Header
 *
 * Renders the admin chrome (nav, user block, logout modal) and
 * bootstraps the admin role's request-scoped needs:
 *
 *   - Starts or resumes the PHP session.
 *   - Requires includes/admin-csrf-token.php and, for authenticated
 *     pages, calls getAdminCsrfToken() to expose $csrfToken. The
 *     helper stores the token under 'admin_csrf_token' — never the
 *     shared 'csrf_token' key — because all FitPal roles run on the
 *     same PHP session and a shared key would let one role's success
 *     path delete another role's already-rendered token.
 *   - Requires the shared PDO connection via admin-connect.php.
 *   - Computes $assetBase and $pageCssPath for the current page.
 *   - Loads the signed-in administrator's display name, initial, and
 *     role when a session is present.
 *
 * The $csrfToken initialization is deliberately asymmetric and both
 * halves are intentional:
 *
 *   - Before the authenticated check, a guarded init sets an empty
 *     default only when the caller has not already set the variable.
 *     sign-in.php assigns $csrfToken before including this file, so
 *     the guard prevents the header from clobbering it with ''.
 *
 *   - Inside the authenticated branch, the assignment is
 *     UNCONDITIONAL. On an authenticated page the header is the
 *     authoritative source of the token, and getAdminCsrfToken()
 *     is idempotent within the request — if a future authenticated
 *     page pre-set the variable, the value it set would be exactly
 *     what the helper returns anyway. sign-in.php never reaches
 *     this branch because it redirects away when a session is
 *     already present. The asymmetry exists so the sign-in form
 *     (unauthenticated) keeps its pre-set token while authenticated
 *     pages get the header's value regardless of what they passed.
 *
 * @package FitPal
 * @version 5.2 — Documented the intentional asymmetry in the
 *                $csrfToken initialization. No code change: the
 *                guarded default and the unconditional
 *                authenticated-branch assignment are unchanged from
 *                v5.0/v5.1. (5.1: Rewrote the docblock to describe
 *                only current behavior. The historical narrative
 *                about the old inline generation block and the
 *                shared csrf_token key now lives in
 *                includes/admin-csrf-token.php.)
 */

declare(strict_types=1);

// ===== SESSION =====
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['created'])) {
    $_SESSION['created'] = time();
} elseif (time() - $_SESSION['created'] > 1800 && !empty($_SESSION['administrator_id'])) {
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}

// ===== CSRF TOKEN (admin role) =====
//
// Single source of truth for the admin role's CSRF token. The helper
// generates it on first use and stores it under 'admin_csrf_token' —
// never the shared 'csrf_token' key. sign-in.php deliberately requires
// this same file before including the header, because its form must
// render even when the header's authenticated branch is not taken.
require_once __DIR__ . '/admin-csrf-token.php';

// ===== DATABASE =====
require_once __DIR__ . '/../backend/database/admin-connect.php';

// ===== PATH DETECTION =====
function getAdminAssetBase(): string
{
    $scriptPath = $_SERVER['SCRIPT_NAME'];
    $dirPath    = dirname($scriptPath);
    $segments   = array_filter(explode('/', $dirPath));
    $depth      = count($segments);
    return $depth <= 0 ? './shared/' : str_repeat('../', $depth) . 'shared/';
}

$assetBase = getAdminAssetBase();

// ===== FETCH ADMIN DATA (if logged in) =====
$isLoggedIn   = false;
$adminName    = '';
$adminInitial = '';
$adminRole    = '';

// Guarded default. On the sign-in page, sign-in.php assigns
// $csrfToken before including this file; the header must not
// clobber it with an empty default. See the class docblock for why
// the authenticated branch below assigns unconditionally.
if (!isset($csrfToken)) {
    $csrfToken = '';
}

if (!empty($_SESSION['administrator_id'])) {
    $isLoggedIn = true;

    // Unconditional on purpose. On an authenticated page the header
    // is the authoritative source of the token, and
    // getAdminCsrfToken() is idempotent within the request, so any
    // value a caller pre-set would equal what the helper returns
    // anyway. sign-in.php never reaches this branch — it redirects
    // away when a session is already present.
    $csrfToken = getAdminCsrfToken();

    try {
        $stmt = $database_connection->prepare(
            "SELECT a.first_name, a.last_name, ap.role
             FROM administrator a
             LEFT JOIN administrator_profile ap ON a.administrator_id = ap.administrator_id
             WHERE a.administrator_id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => (int)$_SESSION['administrator_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $adminName    = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            $adminInitial = strtoupper(substr((string)($row['first_name'] ?? 'A'), 0, 1));
            $adminRole    = (string)($row['role'] ?? '');
        }
    } catch (PDOException $e) {
        // Silently fail — login state still valid
    }
}

// ===== CURRENT PAGE =====
$currentPage = basename($_SERVER['PHP_SELF']);

// ===== PAGE-SPECIFIC CSS =====
$pageCssMap = [
    'sign-in.php'      => 'sign-in.css',
    'dashboard.php'    => 'dashboard.css',
    'customers.php'    => 'customers.css',
    'riders.php'       => 'riders.css',
    'restaurants.php'  => 'restaurants.css',
    'profile.php'      => 'profile.css',
];

$pageCssFile = $pageCssMap[$currentPage] ?? '';
$pageCssPath = '';
if ($pageCssFile !== '' && file_exists(__DIR__ . '/../assets/css/' . $pageCssFile)) {
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

    <?php if ($pageCssPath !== ''): ?>
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
                        <a href="customers.php"
                            class="nav-link <?php echo ($currentPage === 'customers.php') ? 'active' : ''; ?>">Customers</a>
                    </li>
                    <li class="nav-item">
                        <a href="riders.php"
                            class="nav-link <?php echo ($currentPage === 'riders.php') ? 'active' : ''; ?>">Riders</a>
                    </li>
                    <li class="nav-item">
                        <a href="restaurants.php"
                            class="nav-link <?php echo ($currentPage === 'restaurants.php') ? 'active' : ''; ?>">Restaurants</a>
                    </li>
                    <li class="nav-item">
                        <a href="profile.php"
                            class="nav-link <?php echo ($currentPage === 'profile.php') ? 'active' : ''; ?>">Profile</a>
                    </li>
                </ul>

                <div class="nav-actions">
                    <div class="user-profile-circle"
                        title="<?php echo htmlspecialchars($adminName !== '' ? $adminName : 'Admin', ENT_QUOTES, 'UTF-8'); ?>">
                        <?php if ($adminInitial !== ''): ?>
                        <span
                            class="user-initial"><?php echo htmlspecialchars($adminInitial, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php else: ?>
                        <img src="<?php echo $assetBase; ?>assets/images/icons/user-profile-circle.svg" alt="Profile"
                            class="profile-icon">
                        <?php endif; ?>
                    </div>
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
                <div class="mobile-user-avatar">
                    <?php if ($adminInitial !== ''): ?>
                    <span
                        class="user-initial-large"><?php echo htmlspecialchars($adminInitial, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php else: ?>
                    <img src="<?php echo $assetBase; ?>assets/images/icons/user-profile-circle.svg" alt="Profile">
                    <?php endif; ?>
                </div>
                <span
                    class="mobile-user-name"><?php echo htmlspecialchars($adminName !== '' ? $adminName : 'Admin', ENT_QUOTES, 'UTF-8'); ?></span>
            </li>
            <li class="mobile-nav-divider"></li>
            <li class="mobile-nav-item">
                <a href="dashboard.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'dashboard.php') ? 'active' : ''; ?>">Dashboard</a>
            </li>
            <li class="mobile-nav-item">
                <a href="customers.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'customers.php') ? 'active' : ''; ?>">Customers</a>
            </li>
            <li class="mobile-nav-item">
                <a href="riders.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'riders.php') ? 'active' : ''; ?>">Riders</a>
            </li>
            <li class="mobile-nav-item">
                <a href="restaurants.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'restaurants.php') ? 'active' : ''; ?>">Restaurants</a>
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
            <p class="logout-modal-text">You'll need to sign in again to access the admin portal.</p>
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