<?php
/**
 * FitPal Rider Header
 *
 * Renders the rider chrome (nav, user block, logout modal, sign-out
 * block modal) and bootstraps the rider role's request-scoped
 * needs.
 *
 * ---------------------------------------------------------------------
 * PER-ROLE SESSION MODEL (Option B)
 * ---------------------------------------------------------------------
 * This header is included by an entry-point file under
 * rider/pages/ after that file has run:
 *
 *     require_once '<...>/shared/includes/session-bootstrap.php';
 *     fitpal_session_bootstrap('rider');
 *
 * If that precondition is not met, this header emits a minimal
 * error page and exits. It does NOT call session_start() itself,
 * because doing so would open PHP's default PHPSESSID session and
 * silently break isolation.
 *
 * ---------------------------------------------------------------------
 * SESSION ACTIVITY
 * ---------------------------------------------------------------------
 * The last-activity timestamp is recorded for reference, but it is
 * not used to expire the session. Sign-out is a manual action.
 *
 * ---------------------------------------------------------------------
 * SHARED RIDER CHROME
 * ---------------------------------------------------------------------
 * On every authenticated rider page EXCEPT reapply.php, this
 * header pulls in FOUR things:
 *
 *   1. rider/includes/rider-chat-modal.php
 *   2. rider/includes/assignment-panel.php
 *   3. <script src="../assets/ui/js/rider-chat-modal.js">
 *   4. <script src="../assets/ui/js/assignment-panel.js">
 *
 * On reapply.php, only the chat modal and its script are pulled in.
 * The assignment panel is suppressed: a rider filling out a
 * re-application form has no live assignments to manage, and the
 * panel's bottom-anchored overlay would sit on top of the form's
 * submit buttons on small viewports.
 *
 * The chat modal is NOT suppressed on reapply.php. It is inert
 * until opened, and a rider mid-application may still want to
 * message the kitchen about an unrelated delivery that has not yet
 * been reassigned. The two components are independent and this
 * header treats them independently.
 *
 * ---------------------------------------------------------------------
 * PAGE-SPECIFIC CSS
 * ---------------------------------------------------------------------
 * $pageCssMap maps a page's basename to a stylesheet under
 * rider/assets/css/. reapply.php is deliberately NOT in the map:
 * it emits its own <link> to rider/assets/css/reapply.css directly
 * in its own markup, because reapply.css is a purpose-built file
 * and not a shared one. Do not add reapply.php to this map unless
 * you also remove the <link> from reapply.php, or the page will
 * load two stylesheets whose rules overlap.
 *
 * ---------------------------------------------------------------------
 * SIGN-OUT GUARD
 * ---------------------------------------------------------------------
 * The header renders TWO modals:
 *
 *   #logoutModal
 *     The normal Yes/No confirmation.
 *
 *   #riderBlockSignOutModal
 *     The blocking modal. Single OK button; informational only.
 *     logout.js writes the body text.
 *
 * ---------------------------------------------------------------------
 * AVATAR
 * ---------------------------------------------------------------------
 * Both the desktop .user-profile-circle and the mobile
 * .mobile-user-avatar render the same three-way fallback:
 *
 *   1. The uploaded picture, when drp.profile_picture resolves to a
 *      usable URL.
 *   2. The initial letter, when there is no picture.
 *   3. The fallback user glyph, when the initial is also empty.
 *
 * ---------------------------------------------------------------------
 * CACHE BUSTING — RIDER SCRIPTS ARE ALWAYS FETCHED FRESH
 * ---------------------------------------------------------------------
 * The four rider-side scripts that drive the live assignment
 * panel and the live logout flow are fetched fresh on every
 * authenticated rider page load:
 *
 *     ../assets/ui/js/header.js
 *     ../assets/ui/js/logout.js
 *     ../assets/ui/js/rider-chat-modal.js
 *     ../assets/ui/js/assignment-panel.js
 *
 * For those four, the ?v= query string is `(string)time()` on
 * every request. Stylesheets and every other script keep the
 * content-hash version. See the file's earlier revisions for the
 * full rationale.
 *
 * @package FitPal
 * @version 6.1 — The assignment panel and its script are no longer
 *                included on reapply.php. The chat modal and its
 *                script are still included everywhere. The
 *                suppression is a single basename check on
 *                $_SERVER['PHP_SELF']; no flag and no per-page
 *                variable is introduced.
 *
 *                (6.0: the four live rider scripts are fetched
 *                fresh on every page load. 5.0: content-hash
 *                version for assets. 4.0: automatic idle logout
 *                removed. 3.0: per-role session migration. 2.8:
 *                added #riderBlockSignOutModal. 2.6: avatar picture
 *                support. 2.5: version helper appended filesize().)
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// PRECONDITION CHECK
//
// Under Option B, the rider session must be the active session
// before this header can render.
// ---------------------------------------------------------------------

if (!function_exists('fitpal_session_current_context')) {
    require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
}

if (fitpal_session_current_context() !== 'rider') {
    error_log(
        'rider/includes/header.php: included without the rider session '
        . 'being bootstrapped. Current context: "'
        . fitpal_session_current_context() . '". '
        . 'The entry-point file must call fitpal_session_bootstrap(\'rider\') '
        . 'before including this header.'
    );

    http_response_code(500);
    ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>FitPal — Configuration Error</title>
</head>

<body
    style="font-family:system-ui,sans-serif;max-width:640px;margin:80px auto;padding:0 24px;line-height:1.5;color:#111;">
    <h1 style="font-size:20px;margin:0 0 12px;">Configuration Error</h1>
    <p style="margin:0 0 12px;">
        This page was reached without a rider session being started.
        The entry-point file must call
        <code
            style="font-family:monospace;background:#f3f4f6;padding:2px 6px;border-radius:4px;">fitpal_session_bootstrap('rider')</code>
        before including the rider header.
    </p>
    <p style="margin:0;color:#6b7280;font-size:14px;">
        If you are a developer, check the server error log for details.
    </p>
</body>

</html>
<?php
    exit;
}

// ---------------------------------------------------------------------
// SESSION ACTIVITY
// ---------------------------------------------------------------------

require_once __DIR__ . '/../../shared/includes/session-activity.php';

if (!empty($_SESSION['delivery_rider_id'])) {
    trackSessionActivity();
}

// ---------------------------------------------------------------------
// CSRF TOKEN (rider context)
// ---------------------------------------------------------------------

require_once __DIR__ . '/rider-csrf-token.php';

// ---------------------------------------------------------------------
// DATABASE
// ---------------------------------------------------------------------

require_once __DIR__ . '/../backend/database/rider-connect.php';

// ---------------------------------------------------------------------
// PATH DETECTION
// ---------------------------------------------------------------------

/**
 * Get the base path to shared/ from the currently executing page.
 *
 * @return string Asset base path ending with 'shared/'
 */
function getRiderAssetBase(): string
{
    $scriptPath = $_SERVER['SCRIPT_NAME'];
    $dirPath    = dirname($scriptPath);
    $segments   = array_filter(explode('/', $dirPath));
    $depth      = count($segments);
    return str_repeat('../', $depth) . 'shared/';
}

$assetBase = getRiderAssetBase();

// ---------------------------------------------------------------------
// ASSET VERSION HELPERS
// ---------------------------------------------------------------------

$isDevEnv = (getenv('APP_ENV') === 'development');

/**
 * Build the cache-busting version string for a cached asset.
 *
 * @param string $absolutePath
 * @param bool   $isDevEnv
 * @return string
 */
function riderAssetVersion(string $absolutePath, bool $isDevEnv): string
{
    if ($isDevEnv) {
        return (string)time();
    }

    if (!file_exists($absolutePath)) {
        return '0';
    }

    $hash = @md5_file($absolutePath);

    if ($hash === false) {
        return '0';
    }

    return substr($hash, 0, 12);
}

/**
 * Build the cache-busting version string for a script that is
 * always fetched fresh.
 *
 * @return string
 */
function riderFreshAssetVersion(): string
{
    return (string)time();
}

// ---------------------------------------------------------------------
// ASSET PATHS
// ---------------------------------------------------------------------

$sharedGlobalCss = __DIR__ . '/../../shared/assets/css/global.css';
$sharedHeaderCss = __DIR__ . '/../../shared/assets/css/header.css';
$riderHeaderCss  = __DIR__ . '/../assets/css/header.css';
$riderPanelCss   = __DIR__ . '/../assets/css/assignment-panel.css';

// ---------------------------------------------------------------------
// ASSET VERSIONS — STYLESHEETS (content-hash)
// ---------------------------------------------------------------------

$sharedGlobalVer = riderAssetVersion($sharedGlobalCss, $isDevEnv);
$sharedHeaderVer = riderAssetVersion($sharedHeaderCss, $isDevEnv);
$riderHeaderVer  = riderAssetVersion($riderHeaderCss,  $isDevEnv);
$riderPanelVer   = riderAssetVersion($riderPanelCss,   $isDevEnv);

// ---------------------------------------------------------------------
// ASSET VERSIONS — LIVE RIDER SCRIPTS (always fresh)
// ---------------------------------------------------------------------

$riderHeaderJsVer = riderFreshAssetVersion();
$riderLogoutJsVer = riderFreshAssetVersion();
$riderChatJsVer   = riderFreshAssetVersion();
$riderPanelJsVer  = riderFreshAssetVersion();

// ---------------------------------------------------------------------
// FETCH RIDER DATA (if logged in)
// ---------------------------------------------------------------------

$isLoggedIn      = false;
$riderName       = '';
$riderInitial    = '';
$riderStatus     = '';
$riderPictureUrl = '';

$csrfToken = getRiderCsrfToken();

if (!empty($_SESSION['delivery_rider_id'])) {
    $isLoggedIn = true;

    try {
        $stmt = $database_connection->prepare(
            "SELECT
                dr.first_name,
                dr.last_name,
                drp.verification_status,
                drp.profile_picture
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

            $picturePath = (string)($riderData['profile_picture'] ?? '');
            if ($picturePath !== '' && is_string($assetBase) && $assetBase !== '') {
                $projectRootUrl = preg_replace('#shared/$#', '', $assetBase);
                if (is_string($projectRootUrl)) {
                    $riderPictureUrl = $projectRootUrl . $picturePath;
                }
            }
        }
    } catch (PDOException $e) {
        // Silently fail — login state still valid
    }
}

// ---------------------------------------------------------------------
// CURRENT PAGE
// ---------------------------------------------------------------------

$currentPage = basename($_SERVER['PHP_SELF']);

// ---------------------------------------------------------------------
// PAGE-SPECIFIC CSS PRELOADING
//
// reapply.php is deliberately absent from this map. It emits its own
// <link> to reapply.css directly in its own markup. See the file
// header for the rationale.
// ---------------------------------------------------------------------

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

// ---------------------------------------------------------------------
// EXPLICIT ENDPOINT PATHS
// ---------------------------------------------------------------------

$riderChatEndpoint       = '../../rider/backend/handlers/message-handler.php';
$riderAssignmentEndpoint = '../../rider/backend/handlers/assignment-handler.php';

// ---------------------------------------------------------------------
// ASSIGNMENT PANEL VISIBILITY
//
// The assignment panel is chrome for pages where the rider is
// working live orders. On reapply.php the rider is filling out a
// form, not managing assignments, and the panel's fixed-position
// overlay would sit on top of the form's own action buttons on
// small viewports.
//
// The chat modal is independent of the assignment panel and is
// NOT suppressed by this flag. A rider mid-application may still
// want to message the kitchen.
// ---------------------------------------------------------------------

$showAssignmentPanel = ($currentPage !== 'reapply.php');
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

    <?php if ($isLoggedIn && $showAssignmentPanel): ?>
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
                        title="<?php echo htmlspecialchars($riderName !== '' ? $riderName : 'Rider', ENT_QUOTES, 'UTF-8'); ?>"
                        aria-label="Go to profile">
                        <?php if ($riderPictureUrl !== ''): ?>
                        <img src="<?php echo htmlspecialchars($riderPictureUrl, ENT_QUOTES, 'UTF-8'); ?>" alt=""
                            class="profile-icon profile-icon-image"
                            onerror="this.onerror=null; this.style.display='none'; if (this.nextElementSibling) { this.nextElementSibling.style.display='inline-flex'; }">
                        <span class="user-initial" style="display: none;">
                            <?php echo htmlspecialchars($riderInitial !== '' ? $riderInitial : 'R', ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <?php elseif (!empty($riderInitial)): ?>
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
                        <?php if ($riderPictureUrl !== ''): ?>
                        <img src="<?php echo htmlspecialchars($riderPictureUrl, ENT_QUOTES, 'UTF-8'); ?>" alt=""
                            class="profile-icon profile-icon-image"
                            onerror="this.onerror=null; this.style.display='none'; if (this.nextElementSibling) { this.nextElementSibling.style.display='inline-flex'; }">
                        <span class="user-initial-large" style="display: none;">
                            <?php echo htmlspecialchars($riderInitial !== '' ? $riderInitial : 'R', ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <?php elseif (!empty($riderInitial)): ?>
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
         SIGN-OUT BLOCK MODAL
         ============================================ -->
    <div class="logout-modal" id="riderBlockSignOutModal" style="display: none;" role="dialog" aria-modal="true"
        aria-labelledby="riderBlockSignOutTitle">
        <div class="logout-modal-overlay" data-block-signout-cancel></div>
        <div class="logout-modal-content">
            <div class="logout-modal-icon" aria-hidden="true">
                <img src="<?php echo $assetBase; ?>assets/images/icons/error-warning-line.svg" alt=""
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/information-fill.svg'">
            </div>
            <p class="logout-modal-title" id="riderBlockSignOutTitle">Can't sign out yet</p>
            <p class="logout-modal-text" id="riderBlockSignOutText"></p>
            <div class="logout-modal-actions">
                <button type="button" class="logout-btn-cancel" data-block-signout-cancel>OK</button>
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
    window.RIDER_HANDLER_ENDPOINT = '../backend/handlers/rider-handler.php';
    </script>

    <main class="main-content" role="main">

        <script src="../assets/ui/js/header.js?v=<?php echo $riderHeaderJsVer; ?>" defer></script>
        <script src="../assets/ui/js/logout.js?v=<?php echo $riderLogoutJsVer; ?>" defer></script>

        <?php if ($isLoggedIn): ?>

        <?php
        // ============================================================
        // SHARED RIDER CHROME
        //
        // Chat modal:  always rendered on authenticated pages.
        // Assignment panel: rendered everywhere EXCEPT reapply.php.
        // See the ASSIGNMENT PANEL VISIBILITY section above.
        // ============================================================

        // 1. Chat modal markup. One instance per page.
        require_once __DIR__ . '/rider-chat-modal.php';
        ?>

        <script src="../assets/ui/js/rider-chat-modal.js?v=<?php echo $riderChatJsVer; ?>" defer></script>

        <?php if ($showAssignmentPanel): ?>

        <?php
        // 2. Assignment panel markup + its notification modal.
        require_once __DIR__ . '/assignment-panel.php';
        ?>

        <script src="../assets/ui/js/assignment-panel.js?v=<?php echo $riderPanelJsVer; ?>" defer></script>

        <?php endif; ?>

        <?php endif; ?>