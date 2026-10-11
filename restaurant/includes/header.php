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
 * The header emits two JS globals on every authenticated page
 * whose account is scoped to a branch:
 *
 *   window.RESTAURANT_SIGNOUT_GUARD_ENDPOINT
 *     The URL that answers "how many active orders does this
 *     branch have right now?". Points at
 *     kitchen-order-handler.php, whose `active_orders_count`
 *     action is already implemented and already used by the
 *     kitchen page.
 *
 *   window.RESTAURANT_CSRF_TOKEN
 *     The restaurant role's CSRF token, needed by the guard's
 *     POST body.
 *
 * ---------------------------------------------------------------------
 * WHY THE GUARD IS BRANCH-SCOPED, NOT PAGE-SCOPED
 * ---------------------------------------------------------------------
 * The guard answers a branch-scoped question: does the branch the
 * signed-in staff member belongs to have any live orders right
 * now? The kitchen page has an obvious claim to the answer, but so
 * does dashboard.php and profile.php: a staff member who leaves
 * from either of those pages leaves the same set of live orders
 * unattended. Emitting the globals only on kitchen.php was the
 * bug: a branch staff member with live orders who opened
 * dashboard.php saw the plain confirmation modal and was able to
 * sign out.
 *
 * The header therefore emits the globals on every authenticated
 * page whose account is branch-scoped. The scope test is
 * $restaurantScope === 'branch' AND $restaurantBranchId > 0.
 *
 * Owner and partner accounts are never branch-scoped: their
 * restaurant_scope is 'owner' and their restaurant_branch_id is
 * NULL. They are excluded by construction. The guard is not their
 * concern: an owner's session is not tied to a single branch's
 * live orders, and blocking them on someone else's live work would
 * be wrong.
 *
 * The sign-in and sign-up pages are unauthenticated and are also
 * excluded, because the guard endpoint requires a live session.
 *
 * ---------------------------------------------------------------------
 * VERIFICATION GATE
 * ---------------------------------------------------------------------
 * A restaurant whose verification_status is 'pending', 'denied',
 * or 'suspended' has not been cleared to operate. Its accounts may
 * sign in and see the dashboard — where the banner tells them what
 * is wrong and what to do — but nothing else:
 *
 *   - Dashboard, Profile, and Logout are the only nav links.
 *   - Products and Kitchen are hidden.
 *
 * This is the same gate the dashboard's banner expresses. A
 * restaurant that has not been verified cannot use the menu, the
 * kitchen board, or any endpoint those pages call. Hiding the
 * links keeps the chrome honest; the server-side endpoints must
 * also refuse, and they do, because every product and kitchen
 * handler re-checks the verification status through the session
 * keys this header writes.
 *
 * Owner accounts of a pending, denied, or suspended restaurant
 * still see Dashboard and Profile, because those are the two pages
 * that let them correct the problem. Branch accounts of such a
 * restaurant also see only those two pages, because the branch
 * cannot operate before the restaurant is verified.
 *
 * ---------------------------------------------------------------------
 * ROLE GATING (verified restaurants)
 * ---------------------------------------------------------------------
 * Once a restaurant is verified, the nav links are chosen by role:
 *
 *   owner, partner
 *       Dashboard, Products, Profile
 *
 *   manager, staff, kitchen
 *       Dashboard, Kitchen, Profile
 *
 * cashier is in neither list and falls through to Dashboard and
 * Profile only, which matches the schema's role set and the
 * current page set.
 *
 * The sign-out guard globals are emitted only when
 * $restaurantScope === 'branch'. Cashier and kitchen roles also
 * belong to branch scope, so the guard covers them without any
 * role-name list needing to enumerate them.
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
 * @version 6.0 — Verification gate. A restaurant whose
 *                verification_status is not 'verified' sees only
 *                Dashboard, Profile, and Logout. The gate is
 *                applied to both the desktop nav and the mobile
 *                nav from one variable, so the two can never
 *                disagree.
 *
 *                (5.5: window.RESTAURANT_HANDLER_ENDPOINT for
 *                parity with the customer header. 5.4: guard
 *                globals on every branch-scoped page. 5.3:
 *                Products nav link for owner scope. 5.2:
 *                corrected sign-out guard endpoint. 5.1: sign-out
 *                guard globals on kitchen page only. 5.0: per-role
 *                session migration. 4.1: role-scoped display name.
 *                4.0: CSRF helper bootstrap. 3.1: Kitchen nav link
 *                for branch staff.)
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
// ---------------------------------------------------------------------

$isLoggedIn      = !empty($_SESSION['restaurant_account_id']);
$accountName     = $isLoggedIn ? (string)($_SESSION['restaurant_name']  ?? '') : '';
$businessName    = $isLoggedIn ? (string)($_SESSION['business_name']    ?? '') : '';
$restaurantRole  = $isLoggedIn ? (string)($_SESSION['restaurant_role']  ?? '') : '';
$restaurantScope = $isLoggedIn ? (string)($_SESSION['restaurant_scope'] ?? 'owner') : '';
$restaurantBranchId = $isLoggedIn && !empty($_SESSION['restaurant_branch_id'])
    ? (int)$_SESSION['restaurant_branch_id']
    : 0;

$accountInitial = '';
if ($accountName !== '') {
    $accountInitial = strtoupper(substr($accountName, 0, 1));
}

// Always expose a restaurant-scoped token so any form rendered by a
// restaurant page can carry it.
$csrfToken = getRestaurantCsrfToken();

// ---------------------------------------------------------------------
// VERIFICATION STATUS
//
// The header needs the restaurant's verification_status to decide
// whether to show the operating links (Products, Kitchen) or only
// the corrective links (Dashboard, Profile).
//
// The status is read once per request, and only when an account is
// signed in. An unauthenticated request (sign-in, sign-up) does
// not query the database here at all — those pages render their own
// chrome, and there is no restaurant to check.
// ---------------------------------------------------------------------

$verificationStatus = '';
$isVerified         = false;

if ($isLoggedIn) {
    $restaurantIdForStatus = (int)($_SESSION['restaurant_id'] ?? 0);

    if ($restaurantIdForStatus > 0) {
        try {
            $statusStmt = $database_connection->prepare(
                "SELECT verification_status
                   FROM restaurant
                  WHERE restaurant_id = :restaurant_id
                  LIMIT 1"
            );
            $statusStmt->execute([':restaurant_id' => $restaurantIdForStatus]);
            $statusRow = $statusStmt->fetch(PDO::FETCH_ASSOC);

            if ($statusRow) {
                $verificationStatus = (string)$statusRow['verification_status'];
                $isVerified         = ($verificationStatus === 'verified');
            }
        } catch (PDOException $e) {
            error_log('Restaurant header status load error: ' . $e->getMessage());
        }
    }
}

// ---------------------------------------------------------------------
// NAV VISIBILITY
//
// Operating links (Products, Kitchen) are shown only to a VERIFIED
// restaurant. Until the restaurant is verified, the only links are
// Dashboard and Profile, because those are the two pages that let
// the account see why it is blocked and fix it.
//
// When verified, the operating links are gated by role:
//   owner / partner        → Products
//   manager / staff / kitchen → Kitchen
// ---------------------------------------------------------------------

$showKitchenLink  = $isLoggedIn
    && $isVerified
    && in_array($restaurantRole, ['manager', 'staff', 'kitchen'], true);

$showProductsLink = $isLoggedIn
    && $isVerified
    && in_array($restaurantRole, ['owner', 'partner'], true);

// ---------------------------------------------------------------------
// SIGN-OUT GUARD GATE
//
// The guard answers a branch-scoped question. It is emitted on every
// authenticated page whose account is scoped to a branch:
//
//     - Any role whose restaurant_scope is 'branch' (manager, staff,
//       cashier, kitchen) AND whose restaurant_branch_id is a
//       positive integer.
//
// The guard is emitted even when the restaurant is unverified: a
// branch-scoped account cannot have live orders before verification
// in practice, but the guard's job is to answer "how many live
// orders does this branch have right now?" The endpoint it calls
// will simply return zero. Emitting it unconditionally keeps the
// guard from silently disappearing if a branch had live orders at
// the moment its restaurant was suspended.
// ---------------------------------------------------------------------

$showSignOutGuard = $isLoggedIn
    && $restaurantScope === 'branch'
    && $restaurantBranchId > 0;

// ---------------------------------------------------------------------
// CURRENT PAGE + PAGE-SPECIFIC CSS
// ---------------------------------------------------------------------

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');

$pageCssMap = [
    'sign-in.php'      => 'sign-in.css',
    'sign-up.php'      => 'sign-up.css',
    'dashboard.php'    => 'dashboard.css',
    'profile.php'      => 'profile.css',
    'kitchen.php'      => 'kitchen-orders.css',
    'products.php'     => 'products.css',
    'product-edit.php' => 'product-edit.css',
];

$pageCssFile = $pageCssMap[$currentPage] ?? '';
$pageCssPath = '';
if ($pageCssFile !== '' && file_exists(__DIR__ . '/../assets/css/' . $pageCssFile)) {
    $pageCssPath = '../assets/css/' . $pageCssFile;
}

// ---------------------------------------------------------------------
// EXPLICIT ENDPOINT PATH
//
// Mirrors the customer header's window.CUSTOMER_HANDLER_ENDPOINT.
// Exposed as a global so JS files on this page can resolve the
// restaurant profile handler without guessing their own relative
// path.
// ---------------------------------------------------------------------

$restaurantHandlerEndpoint = '../backend/handlers/profile-handler.php';
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
    <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/format-date.css">

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

                    <?php if ($showProductsLink): ?>
                    <li class="nav-item">
                        <a href="products.php"
                            class="nav-link <?php echo in_array($currentPage, ['products.php', 'product-edit.php'], true) ? 'active' : ''; ?>">
                            Products
                        </a>
                    </li>
                    <?php endif; ?>

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
                    <button type="button" class="btn btn-outline btn-sm logout-btn" data-logout-trigger
                        data-csrf-token="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
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

            <?php if ($showProductsLink): ?>
            <li class="mobile-nav-item">
                <a href="products.php"
                    class="mobile-nav-link <?php echo in_array($currentPage, ['products.php', 'product-edit.php'], true) ? 'active' : ''; ?>">Products</a>
            </li>
            <?php endif; ?>

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
                <button type="button" class="mobile-nav-link mobile-logout" data-logout-trigger
                    data-csrf-token="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
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

    <!-- ============================================
         GLOBAL RESTAURANT CONFIG
         ============================================ -->
    <script>
    window.RESTAURANT_CSRF_TOKEN = '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>';
    window.RESTAURANT_ASSET_BASE = '<?php echo $assetBase; ?>';
    window.RESTAURANT_HANDLER_ENDPOINT =
        '<?php echo htmlspecialchars($restaurantHandlerEndpoint, ENT_QUOTES, 'UTF-8'); ?>';
    <?php if ($showSignOutGuard): ?>
    window.RESTAURANT_SIGNOUT_GUARD_ENDPOINT = '../backend/handlers/kitchen-order-handler.php';
    <?php endif; ?>
    </script>
    <script src="<?php echo $assetBase; ?>assets/ui/js/format-date.js" defer></script>

    <main class="main-content" role="main">

        <script src="../assets/ui/js/header.js" defer></script>
        <script src="../assets/ui/js/logout.js" defer></script>