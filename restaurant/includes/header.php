<?php
/**
 * FitPal Restaurant Header
 *
 * Renders the restaurant chrome (nav, user block, logout modal) and
 * bootstraps the restaurant role's request-scoped needs.
 *
 * ---------------------------------------------------------------------
 * PER-ROLE SESSION MODEL (Option B)
 * ---------------------------------------------------------------------
 * This header is included by an entry-point file under
 * restaurant/pages/ after that file has run:
 *
 *     require_once '<...>/shared/includes/session-bootstrap.php';
 *     fitpal_session_bootstrap('restaurant');
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
 * CSRF TOKEN (restaurant context)
 * ---------------------------------------------------------------------
 * The header assigns $csrfToken on every request — authenticated or
 * not — by calling getRestaurantCsrfToken() from
 * includes/restaurant-csrf-token.php. The helper verifies the
 * active session is the restaurant session before returning a
 * token, so a misconfigured page cannot accidentally write a
 * restaurant token into a non-restaurant session.
 *
 * Every restaurant page reads $csrfToken from this header. No
 * restaurant page generates or fetches the token itself.
 *
 * ---------------------------------------------------------------------
 * SIGN-OUT GUARD CONTRACT
 * ---------------------------------------------------------------------
 * The header emits two JS globals on every AUTHENTICATED page:
 *
 *   window.RESTAURANT_SIGNOUT_GUARD_ENDPOINT
 *     The URL that answers "how many active orders does this
 *     branch have right now?". Points at
 *     kitchen-order-handler.php, whose `active_orders_count`
 *     action is already implemented and already used by the
 *     kitchen page. The previous revision pointed this at
 *     order-handler.php, which was renamed to
 *     kitchen-order-handler.php and no longer exists on disk, so
 *     every guard check returned 404 and logout.js failed open.
 *
 *   window.RESTAURANT_CSRF_TOKEN
 *     The restaurant role's CSRF token, needed by the guard's
 *     POST body.
 *
 * These globals exist so logout.js can run the same pre-flight on
 * dashboard.php and profile.php that it already runs on
 * kitchen.php.
 *
 * The two globals are NOT emitted on the sign-in or sign-up pages,
 * because those pages are unauthenticated and the guard endpoint
 * would be irrelevant.
 *
 * ---------------------------------------------------------------------
 * ROLE GATING
 * ---------------------------------------------------------------------
 * The Kitchen nav link is shown only to role in
 * {manager, staff, kitchen}. Owner and partner accounts see
 * Dashboard and Profile only — the kitchen page is an operational
 * surface for branch staff, and the sign-out guard's
 * active_orders_count action is also branch-scoped, so owner and
 * partner accounts are not subject to the guard.
 *
 * ---------------------------------------------------------------------
 * PAGE-SPECIFIC CSS
 * ---------------------------------------------------------------------
 * The header's $pageCssMap maps the current page basename to a
 * stylesheet under restaurant/assets/css/. It is loaded after the
 * shared global.css and header.css so page tuning wins on cascade
 * position without needing !important.
 *
 * @package FitPal
 * @version 5.2 — Corrected the sign-out guard endpoint.
 *
 *                The previous revision emitted:
 *
 *                    window.RESTAURANT_SIGNOUT_GUARD_ENDPOINT =
 *                        '../backend/handlers/order-handler.php';
 *
 *                That file was renamed to kitchen-order-handler.php
 *                and no longer exists on disk. Every guard check
 *                made by logout.js therefore returned 404, which
 *                logout.js treats as "cannot decide" and fails
 *                open, opening the plain confirmation modal and
 *                letting a branch staff member with active orders
 *                sign out unchecked. This revision points the
 *                global at the file that exists:
 *
 *                    '../backend/handlers/kitchen-order-handler.php'
 *
 *                That handler's active_orders_count action is
 *                already implemented and already used by the
 *                kitchen page, so no handler change is needed.
 *
 *                No other line changed. The precondition check,
 *                the CSRF bootstrap, the nav, the mobile nav,
 *                the logout modal, the block modal, and the
 *                page-specific CSS map are byte-identical to
 *                v5.1.
 *
 *                (5.1: emits the sign-out guard globals on every
 *                authenticated page. 5.0: per-role session
 *                migration. 4.1: role-scoped display name. 4.0:
 *                CSRF helper bootstrap. 3.1: Kitchen nav link for
 *                branch staff.)
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// PRECONDITION CHECK
//
// Under Option B, the restaurant session must be the active session
// before this header can render. If it is not, the entry-point
// file forgot to bootstrap — refuse to render rather than emit
// restaurant chrome against the wrong session.
// ---------------------------------------------------------------------

if (!function_exists('fitpal_session_current_context')) {
    require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
}

if (fitpal_session_current_context() !== 'restaurant') {
    error_log(
        'restaurant/includes/header.php: included without the restaurant session '
        . 'being bootstrapped. Current context: "'
        . fitpal_session_current_context() . '". '
        . 'The entry-point file must call fitpal_session_bootstrap(\'restaurant\') '
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
        This page was reached without a restaurant session being
        started. The entry-point file must call
        <code
            style="background:#f3f4f6;padding:2px 6px;border-radius:4px;">fitpal_session_bootstrap('restaurant')</code>
        before including the restaurant header.
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
// not used to expire the session. Sign-out is a manual action.
// ---------------------------------------------------------------------

require_once __DIR__ . '/../../shared/includes/session-activity.php';

if (!empty($_SESSION['restaurant_account_id'])) {
    trackSessionActivity();
}

// ---------------------------------------------------------------------
// CSRF TOKEN (restaurant context)
//
// getRestaurantCsrfToken() verifies the active session is the
// restaurant session before returning a token, so the value here is
// always the restaurant's token and never any other role's.
// ---------------------------------------------------------------------

require_once __DIR__ . '/restaurant-csrf-token.php';

// ---------------------------------------------------------------------
// DATABASE
// ---------------------------------------------------------------------

require_once __DIR__ . '/../backend/database/restaurant-connect.php';

// ---------------------------------------------------------------------
// PATH DETECTION
// ---------------------------------------------------------------------

if (!function_exists('getRestaurantAssetBase')) {
    /**
     * Get the base path to shared/ from the currently executing page.
     *
     * @return string Asset base path ending with 'shared/'
     */
    function getRestaurantAssetBase(): string
    {
        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
        $dirPath    = dirname($scriptPath);
        $segments   = array_filter(explode('/', $dirPath));
        $depth      = count($segments);
        return str_repeat('../', $depth) . 'shared/';
    }
}

$assetBase = getRestaurantAssetBase();

// ---------------------------------------------------------------------
// SESSION DATA
//
// The restaurant sign-in handler writes the keys read here on the
// success path. This header trusts those keys for display; the
// authorization gate is $_SESSION['restaurant_account_id'].
// ---------------------------------------------------------------------

$isLoggedIn      = !empty($_SESSION['restaurant_account_id']);
$accountName     = $isLoggedIn ? (string)($_SESSION['restaurant_name']  ?? '') : '';
$businessName    = $isLoggedIn ? (string)($_SESSION['business_name']    ?? '') : '';
$restaurantRole  = $isLoggedIn ? (string)($_SESSION['restaurant_role']  ?? '') : '';
$restaurantScope = $isLoggedIn ? (string)($_SESSION['restaurant_scope'] ?? 'owner') : '';

$showKitchenLink = $isLoggedIn
    && in_array($restaurantRole, ['manager', 'staff', 'kitchen'], true);

$accountInitial = '';
if ($accountName !== '') {
    $accountInitial = strtoupper(substr($accountName, 0, 1));
}

// Always expose a restaurant-scoped token so any form rendered by a
// restaurant page can carry it. getRestaurantCsrfToken() refuses to
// return a token unless the active session is the restaurant
// session, so the value here is always the restaurant's own token.
$csrfToken = getRestaurantCsrfToken();

// ---------------------------------------------------------------------
// SIGN-OUT GUARD ENDPOINT
//
// Only emitted on an authenticated page. The endpoint is the
// existing kitchen-order-handler.php action `active_orders_count`,
// which answers "how many live orders does this branch have right
// now?".
//
// The previous revision pointed this at order-handler.php, which
// was renamed to kitchen-order-handler.php and no longer exists.
// Every guard check returned 404 and logout.js failed open,
// allowing a branch staff member with active orders to sign out
// without being blocked. This revision points at the file that
// exists on disk.
//
// The guard applies to branch-scoped accounts only. Owner and
// partner accounts are not branch-scoped, so the endpoint would
// answer with a branch_id of 0 and the handler would refuse the
// call. Emitting the globals only for branch staff keeps the
// contract honest.
// ---------------------------------------------------------------------

$showSignOutGuard = $isLoggedIn
    && in_array($restaurantRole, ['manager', 'staff', 'kitchen'], true);

// ---------------------------------------------------------------------
// CURRENT PAGE + PAGE-SPECIFIC CSS
// ---------------------------------------------------------------------

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');

$pageCssMap = [
    'sign-in.php'   => 'sign-in.css',
    'sign-up.php'   => 'sign-up.css',
    'dashboard.php' => 'dashboard.css',
    'profile.php'   => 'profile.css',
    'kitchen.php'   => 'orders.css',
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
    <meta name="description" content="FitPal - Restaurant Portal">
    <title>FitPal - Restaurant</title>

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
    <header class="header restaurant-header" role="banner">
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

            <nav class="header-nav" id="mainNav" role="navigation" aria-label="Restaurant navigation">

                <?php if ($isLoggedIn): ?>
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="dashboard.php"
                            class="nav-link <?php echo ($currentPage === 'dashboard.php') ? 'active' : ''; ?>">
                            Dashboard
                        </a>
                    </li>
                    <?php if ($showKitchenLink): ?>
                    <li class="nav-item">
                        <a href="kitchen.php"
                            class="nav-link <?php echo ($currentPage === 'kitchen.php') ? 'active' : ''; ?>">
                            Kitchen
                        </a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a href="profile.php"
                            class="nav-link <?php echo ($currentPage === 'profile.php') ? 'active' : ''; ?>">
                            Profile
                        </a>
                    </li>
                </ul>

                <div class="nav-actions">
                    <a href="profile.php" class="user-profile-circle"
                        title="<?php echo htmlspecialchars($accountName !== '' ? $accountName : 'Account', ENT_QUOTES, 'UTF-8'); ?>"
                        aria-label="Go to profile">
                        <?php if ($accountInitial !== ''): ?>
                        <span class="user-initial">
                            <?php echo htmlspecialchars($accountInitial, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <?php else: ?>
                        <img src="<?php echo $assetBase; ?>assets/images/icons/user-profile-circle.svg" alt=""
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
                        <?php if ($accountInitial !== ''): ?>
                        <span class="user-initial-large">
                            <?php echo htmlspecialchars($accountInitial, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <?php else: ?>
                        <img src="<?php echo $assetBase; ?>assets/images/icons/user-profile-circle.svg" alt="">
                        <?php endif; ?>
                    </div>
                    <span class="mobile-user-name">
                        <?php echo htmlspecialchars($accountName !== '' ? $accountName : 'Account', ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </a>
            </li>
            <li class="mobile-nav-divider"></li>
            <li class="mobile-nav-item">
                <a href="dashboard.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'dashboard.php') ? 'active' : ''; ?>">Dashboard</a>
            </li>
            <?php if ($showKitchenLink): ?>
            <li class="mobile-nav-item">
                <a href="kitchen.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'kitchen.php') ? 'active' : ''; ?>">Kitchen</a>
            </li>
            <?php endif; ?>
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

    <div class="logout-modal" id="logoutModal" style="display: none;" role="dialog" aria-modal="true"
        aria-labelledby="logoutModalTitle">
        <div class="logout-modal-overlay" data-logout-cancel></div>
        <div class="logout-modal-content">
            <div class="logout-modal-icon" aria-hidden="true">
                <img src="<?php echo $assetBase; ?>assets/images/icons/logoutsvg.svg" alt=""
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/information-fill.svg'">
            </div>
            <p class="logout-modal-title" id="logoutModalTitle">Sign out?</p>
            <p class="logout-modal-text">You'll need to sign in again to access your account.</p>
            <div class="logout-modal-actions">
                <button type="button" class="logout-btn-cancel" data-logout-cancel>Cancel</button>
                <a href="../backend/handlers/sign-out-handler.php" class="logout-btn-confirm">
                    Yes, sign out
                </a>
            </div>
        </div>
    </div>

    <?php if ($showSignOutGuard): ?>
    <div class="logout-modal" id="restaurantBlockSignOutModal" style="display: none;" role="dialog" aria-modal="true"
        aria-labelledby="restaurantBlockSignOutTitle">
        <div class="logout-modal-overlay" data-block-signout-cancel></div>
        <div class="logout-modal-content">
            <div class="logout-modal-icon" aria-hidden="true">
                <img src="<?php echo $assetBase; ?>assets/images/icons/error-warning-line.svg" alt=""
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/information-fill.svg'">
            </div>
            <p class="logout-modal-title" id="restaurantBlockSignOutTitle">Can't sign out right now</p>
            <p class="logout-modal-text" id="restaurantBlockSignOutText"></p>
            <div class="logout-modal-actions">
                <button type="button" class="logout-btn-cancel" data-block-signout-cancel>OK</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <main class="main-content" role="main">

        <?php if ($showSignOutGuard): ?>
        <script>
        window.RESTAURANT_SIGNOUT_GUARD_ENDPOINT = '../backend/handlers/kitchen-order-handler.php';
        window.RESTAURANT_CSRF_TOKEN = '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>';
        </script>
        <?php endif; ?>

        <script src="../assets/ui/js/header.js" defer></script>
        <script src="../assets/ui/js/logout.js" defer></script>