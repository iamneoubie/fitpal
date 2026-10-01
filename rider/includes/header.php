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
 * On every authenticated rider page, this header pulls in FOUR
 * things:
 *
 *   1. rider/includes/rider-chat-modal.php
 *   2. rider/includes/assignment-panel.php
 *   3. <script src="../assets/ui/js/rider-chat-modal.js">
 *   4. <script src="../assets/ui/js/assignment-panel.js">
 *
 * ---------------------------------------------------------------------
 * SIGN-OUT GUARD
 * ---------------------------------------------------------------------
 * The header renders TWO modals:
 *
 *   #logoutModal
 *     The normal Yes/No confirmation. Shown only after the
 *     pre-flight check in logout.js reports the rider is eligible
 *     to sign out.
 *
 *   #riderBlockSignOutModal
 *     The blocking modal. Shown when the pre-flight check reports
 *     the rider is NOT eligible — either because they still have
 *     live orders, or because they are still online. Single OK
 *     button; informational only. logout.js writes the body text
 *     because the correct copy depends on WHICH condition failed.
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
 * CACHE BUSTING
 * ---------------------------------------------------------------------
 * Every stylesheet link and every script tag carries a
 * ?v=<version> query string built from the file's modification
 * time AND its byte size. When APP_ENV=development is set, the
 * version is a fresh time() on every request.
 *
 * @package FitPal
 * @version 4.0 — Automatic idle logout removed. The header no
 *                longer calls trackSessionActivity()'s return
 *                value to decide whether to expire the session.
 *                It records the timestamp for reference and
 *                proceeds. Sign-out is now manual only.
 *
 *                (3.0: per-role session migration. 2.8: added
 *                #riderBlockSignOutModal. 2.7: docblock-only
 *                update. 2.6: avatar picture support. 2.5: version
 *                helper appends filesize().)
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// PRECONDITION CHECK
//
// Under Option B, the rider session must be the active session
// before this header can render. If it is not, the entry-point
// file forgot to bootstrap — refuse to render rather than emit
// rider chrome against the wrong session.
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
        <code style="background:#f3f4f6;padding:2px 6px;border-radius:4px;">fitpal_session_bootstrap('rider')</code>
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
//
// The last-activity timestamp is recorded for reference, but it is
// not used to expire the session. Sign-out is a manual action:
// the user presses the logout button, the sign-out handler runs,
// and the rider session is destroyed. The browser's own session-
// cookie lifetime is the only other mechanism that ends this
// session.
// ---------------------------------------------------------------------

require_once __DIR__ . '/../../shared/includes/session-activity.php';

if (!empty($_SESSION['delivery_rider_id'])) {
    trackSessionActivity();
}

// ---------------------------------------------------------------------
// CSRF TOKEN (rider context)
//
// getRiderCsrfToken() verifies the active session is the rider
// session before returning a token, so the value here is always
// the rider's token and never any other role's.
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
// ASSET VERSION HELPER
// ---------------------------------------------------------------------

$isDevEnv = (getenv('APP_ENV') === 'development');

/**
 * Build the cache-busting version string for a local asset.
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

// ---------------------------------------------------------------------
// ASSET PATHS
// ---------------------------------------------------------------------

$sharedGlobalCss = __DIR__ . '/../../shared/assets/css/global.css';
$sharedHeaderCss = __DIR__ . '/../../shared/assets/css/header.css';
$riderHeaderCss  = __DIR__ . '/../assets/css/header.css';
$riderPanelCss   = __DIR__ . '/../assets/css/assignment-panel.css';

$riderHeaderJs = __DIR__ . '/../assets/ui/js/header.js';
$riderLogoutJs = __DIR__ . '/../assets/ui/js/logout.js';
$riderChatJs   = __DIR__ . '/../assets/ui/js/rider-chat-modal.js';
$riderPanelJs  = __DIR__ . '/../assets/ui/js/assignment-panel.js';

// ---------------------------------------------------------------------
// ASSET VERSIONS
// ---------------------------------------------------------------------

$sharedGlobalVer = riderAssetVersion($sharedGlobalCss, $isDevEnv);
$sharedHeaderVer = riderAssetVersion($sharedHeaderCss, $isDevEnv);
$riderHeaderVer  = riderAssetVersion($riderHeaderCss,  $isDevEnv);
$riderPanelVer   = riderAssetVersion($riderPanelCss,   $isDevEnv);

$riderHeaderJsVer = riderAssetVersion($riderHeaderJs, $isDevEnv);
$riderLogoutJsVer = riderAssetVersion($riderLogoutJs, $isDevEnv);
$riderChatJsVer   = riderAssetVersion($riderChatJs,   $isDevEnv);
$riderPanelJsVer  = riderAssetVersion($riderPanelJs,  $isDevEnv);

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