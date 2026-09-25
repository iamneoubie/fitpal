<?php
/**
 * FitPal Rider Header
 *
 * Renders the rider chrome (nav, user block, logout modal) and
 * bootstraps the rider role's request-scoped needs:
 *
 *   - Starts or resumes the PHP session.
 *   - Requires includes/rider-csrf-token.php and assigns $csrfToken
 *     on every request, authenticated or not. The helper stores the
 *     token under 'rider_csrf_token' — never the shared 'csrf_token'
 *     key — because all FitPal roles run on the same PHP session and
 *     a shared key would let one role's success path delete another
 *     role's already-rendered token.
 *   - Requires the shared PDO connection via rider-connect.php.
 *   - Computes $assetBase and $pageCssPath for the current page.
 *   - Loads the signed-in rider's display name, initial, and
 *     verification status when a session is present.
 *
 * Shared rider chrome
 * -------------------
 * On every authenticated rider page (i.e. when
 * $_SESSION['delivery_rider_id'] is set), this header pulls in FOUR
 * things:
 *
 *   1. rider/includes/rider-chat-modal.php
 *      The chat modal markup. One modal per page.
 *
 *   2. rider/includes/assignment-panel.php
 *      The bottom-anchored assignment panel and its notification
 *      modal. Chrome, not page content.
 *
 *   3. <script src="../assets/ui/js/rider-chat-modal.js">
 *      Chat modal open/close/tabs/send/delta poll.
 *
 *   4. <script src="../assets/ui/js/assignment-panel.js">
 *      Assignment panel polling, row rendering, accept/decline,
 *      and the notification modal.
 *
 * Cache busting — the important part
 * ----------------------------------
 * Every stylesheet link and every script tag carries a ?v=<version>
 * query string. The version is built from the file's modification
 * time AND its byte size, not from modification time alone.
 *
 * Why both: some editors preserve a file's mtime when you save new
 * content into it. When that happens, filemtime() returns the same
 * value before and after an edit, the query string stays the same,
 * and the browser keeps serving its cached copy of the old file —
 * even though the new file is on disk. Appending filesize() means
 * the version changes whenever the file's byte count changes, which
 * it always does when you edit it.
 *
 * When APP_ENV=development is set in the environment, the version
 * is a fresh time() on every request, so nothing is cached at all
 * while you iterate.
 *
 * filemtime() and filesize() both return false if the file is
 * missing. The '0' fallback keeps the tag well-formed in that case.
 *
 * @package FitPal
 * @version 2.5 — Version helper now appends filesize() so the query
 *                string changes on every edit even when the editor
 *                preserves mtime. Adds an APP_ENV=development
 *                override that uses time() so nothing is cached
 *                during iteration. This is what makes a saved CSS
 *                change actually reach the browser on the next
 *                reload.
 *
 *                (2.4: added the explicit comment block above the
 *                two shared include script tags. 2.3: extended
 *                mtime cache-busting to every script tag. 2.2:
 *                extended mtime cache-busting to every stylesheet.
 *                2.1: added the shared rider chrome.)
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

// ===== CSRF TOKEN (rider role) =====
//
// Single source of truth for the rider role's CSRF token. The helper
// generates it on first use and stores it under 'rider_csrf_token' —
// never the shared 'csrf_token' key.
require_once __DIR__ . '/rider-csrf-token.php';

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

// ===== ASSET VERSION HELPER =====
//
// Every stylesheet link and every script tag carries ?v=<version>.
// The version is built from mtime AND filesize so it changes on every
// edit, even when the editor preserves the file's mtime. When
// APP_ENV=development is set, the version is a fresh time() on every
// request so nothing is cached at all.
$isDevEnv = (getenv('APP_ENV') === 'development');

/**
 * Build the cache-busting version string for a local asset.
 *
 * @param string $absolutePath Absolute path to the file on disk.
 * @return string Version string, safe to place in a URL query.
 */
function riderAssetVersion(string $absolutePath, bool $isDevEnv): string
{
    if ($isDevEnv) {
        return (string)time();
    }

    if (!file_exists($absolutePath)) {
        return '0';
    }

    $mtime = filemtime($absolutePath);
    $size  = filesize($absolutePath);

    if ($mtime === false) {
        $mtime = 0;
    }
    if ($size === false) {
        $size = 0;
    }

    return $mtime . '-' . $size;
}

// ===== ASSET PATHS =====
$sharedGlobalCss  = __DIR__ . '/../../shared/assets/css/global.css';
$sharedHeaderCss  = __DIR__ . '/../../shared/assets/css/header.css';
$riderHeaderCss   = __DIR__ . '/../assets/css/header.css';
$riderPanelCss    = __DIR__ . '/../assets/css/assignment-panel.css';

$riderHeaderJs    = __DIR__ . '/../assets/ui/js/header.js';
$riderLogoutJs    = __DIR__ . '/../assets/ui/js/logout.js';
$riderChatJs      = __DIR__ . '/../assets/ui/js/rider-chat-modal.js';
$riderPanelJs     = __DIR__ . '/../assets/ui/js/assignment-panel.js';

// ===== ASSET VERSIONS =====
$sharedGlobalVer  = riderAssetVersion($sharedGlobalCss,  $isDevEnv);
$sharedHeaderVer  = riderAssetVersion($sharedHeaderCss,  $isDevEnv);
$riderHeaderVer   = riderAssetVersion($riderHeaderCss,   $isDevEnv);
$riderPanelVer    = riderAssetVersion($riderPanelCss,    $isDevEnv);

$riderHeaderJsVer = riderAssetVersion($riderHeaderJs,    $isDevEnv);
$riderLogoutJsVer = riderAssetVersion($riderLogoutJs,    $isDevEnv);
$riderChatJsVer   = riderAssetVersion($riderChatJs,      $isDevEnv);
$riderPanelJsVer  = riderAssetVersion($riderPanelJs,     $isDevEnv);

// ===== FETCH RIDER DATA (if logged in) =====
$isLoggedIn   = false;
$riderName    = '';
$riderInitial = '';
$riderStatus  = '';

// Always expose a rider-scoped token so any form rendered below can
// carry it, regardless of whether the visitor is authenticated.
$csrfToken = getRiderCsrfToken();

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
$pageCssVer  = '0';
if (!empty($pageCssFile) && file_exists(__DIR__ . '/../assets/css/' . $pageCssFile)) {
    $pageCssFull = __DIR__ . '/../assets/css/' . $pageCssFile;
    $pageCssPath = '../assets/css/' . $pageCssFile;
    $pageCssVer  = riderAssetVersion($pageCssFull, $isDevEnv);
}

// ===== EXPLICIT ENDPOINT PATHS =====
//
// Exposed to JS so neither shared script has to guess its own
// relative path from its <script> src.
$riderChatEndpoint       = '../../rider/backend/handlers/message-handler.php';
$riderAssignmentEndpoint = '../../rider/backend/handlers/assignment-handler.php';
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

    <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/global.css?v=<?php echo $sharedGlobalVer; ?>">
    <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/header.css?v=<?php echo $sharedHeaderVer; ?>">
    <link rel="stylesheet" href="../assets/css/header.css?v=<?php echo $riderHeaderVer; ?>">

    <?php if (!empty($pageCssPath)): ?>
    <link rel="stylesheet" href="<?php echo $pageCssPath; ?>?v=<?php echo $pageCssVer; ?>">
    <?php endif; ?>

    <?php if ($isLoggedIn): ?>
    <link rel="stylesheet" href="../assets/css/assignment-panel.css?v=<?php echo $riderPanelVer; ?>">
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

    <!-- ============================================
         GLOBAL RIDER CONFIG
         ============================================ -->
    <script>
    window.RIDER_CSRF_TOKEN = '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>';
    window.RIDER_ASSET_BASE = '<?php echo $assetBase; ?>';
    window.RIDER_CHAT_ENDPOINT = '<?php echo htmlspecialchars($riderChatEndpoint, ENT_QUOTES, 'UTF-8'); ?>';
    window.RIDER_ASSIGNMENT_ENDPOINT = '<?php echo htmlspecialchars($riderAssignmentEndpoint, ENT_QUOTES, 'UTF-8'); ?>';
    window.RIDER_ASSIGNMENT_CSRF = '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>';
    </script>

    <main class="main-content" role="main">

        <script src="../assets/ui/js/header.js?v=<?php echo $riderHeaderJsVer; ?>" defer></script>
        <script src="../assets/ui/js/logout.js?v=<?php echo $riderLogoutJsVer; ?>" defer></script>

        <?php if ($isLoggedIn): ?>

        <?php
        // ============================================================
        // SHARED RIDER CHROME
        //
        // Two includes and two scripts, loaded on every authenticated
        // rider page. They own non-overlapping concerns:
        //
        //   rider-chat-modal.php + rider-chat-modal.js
        //     → the chat modal, its open/close/tabs/send, its delta
        //       poll, and the delegated [data-rider-chat-open]
        //       listener that opens it from any page.
        //
        //   assignment-panel.php + assignment-panel.js
        //     → the bottom-anchored assignment panel, its poll, its
        //       row rendering, accept/decline, and the assignment
        //       notification modal.
        //
        // Do NOT merge these files. Do NOT let one include a copy of
        // the other.
        // ============================================================

        // 1. Chat modal markup. One instance per page.
        require_once __DIR__ . '/rider-chat-modal.php';

        // 2. Assignment panel markup + its notification modal.
        require_once __DIR__ . '/assignment-panel.php';
        ?>

        <script src="../assets/ui/js/rider-chat-modal.js?v=<?php echo $riderChatJsVer; ?>" defer></script>
        <script src="../assets/ui/js/assignment-panel.js?v=<?php echo $riderPanelJsVer; ?>" defer></script>

        <?php endif; ?>