<?php
/**
 * FitPal Customer Header
 *
 * Customer-specific header with conditional navigation based on
 * login status. Runs on the customer session (PHPSESSID_CUSTOMER),
 * which is separate from every other role's session.
 *
 * ---------------------------------------------------------------------
 * PER-ROLE SESSION MODEL (Option B)
 * ---------------------------------------------------------------------
 * This header is included by an entry-point file under
 * customer/pages/ after that file has run:
 *
 *     require_once '<...>/shared/includes/session-bootstrap.php';
 *     fitpal_session_bootstrap('customer');
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
 * not used to expire the session. Sign-out is a manual action: the
 * user presses the logout button, the sign-out handler runs, and
 * the customer session is destroyed. The browser's own session-
 * cookie lifetime is the only other mechanism that ends this
 * session.
 *
 * ---------------------------------------------------------------------
 * LOGOUT CONFIRMATION MODAL
 * ---------------------------------------------------------------------
 * The header renders TWO modals:
 *
 *   #logoutModal
 *     The normal Yes/No confirmation. Shown only after the
 *     pre-flight check in logout.js reports the customer is
 *     eligible to sign out.
 *
 *   #customerBlockSignOutModal
 *     The blocking modal. Shown when the pre-flight check reports
 *     the customer is NOT eligible — either because they still
 *     have live orders, or because they still have items in the
 *     cart or the session order queue. Single OK button;
 *     informational only. logout.js writes the body text because
 *     the correct copy depends on WHICH condition failed.
 *
 * ---------------------------------------------------------------------
 * AVATAR BLOCK
 * ---------------------------------------------------------------------
 * The desktop avatar is a real <a href="profile.php"> link. Its
 * contents are chosen at render time:
 *
 *   - profile_picture is set and the file exists on disk
 *       → <img src="<projectRoot>/<path>" class="profile-icon profile-icon-image">
 *   - otherwise
 *       → the initial letter, or the fallback user icon when the
 *         initial is empty
 *
 * ---------------------------------------------------------------------
 * SESSION CACHE
 * ---------------------------------------------------------------------
 * The header prefers $_SESSION['customer_name'] and
 * $_SESSION['customer_profile_picture'] when they are set, and
 * falls back to a single DB query that reads both columns at once
 * when they are not.
 *
 * @package FitPal
 * @version 4.0 — Automatic idle logout removed. The header no
 *                longer calls trackSessionActivity()'s return
 *                value to decide whether to expire the session.
 *                It records the timestamp for reference and
 *                proceeds. Sign-out is now manual only.
 *
 *                (3.0: per-role session migration. 2.1: added the
 *                #customerBlockSignOutModal and the
 *                window.CUSTOMER_HANDLER_ENDPOINT global.
 *                2.0: reads profile_picture from customer_profile.
 *                1.8: session-cached display name.)
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// PRECONDITION CHECK
//
// Under Option B, the customer session must be the active session
// before this header can render. If it is not, the entry-point
// file forgot to bootstrap — refuse to render rather than emit
// customer chrome against the wrong session.
// ---------------------------------------------------------------------

if (!function_exists('fitpal_session_current_context')) {
    require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
}

if (fitpal_session_current_context() !== 'customer') {
    error_log(
        'customer/includes/header.php: included without the customer session '
        . 'being bootstrapped. Current context: "'
        . fitpal_session_current_context() . '". '
        . 'The entry-point file must call fitpal_session_bootstrap(\'customer\') '
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
        This page was reached without a customer session being
        started. The entry-point file must call
        <code style="background:#f3f4f6;padding:2px 6px;border-radius:4px;">fitpal_session_bootstrap('customer')</code>
        before including the customer header.
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
// and the customer session is destroyed. The browser's own
// session-cookie lifetime is the only other mechanism that ends
// this session.
// ---------------------------------------------------------------------

require_once __DIR__ . '/../../shared/includes/session-activity.php';

if (!empty($_SESSION['customer_id'])) {
    trackSessionActivity();
}

// ---------------------------------------------------------------------
// CSRF TOKEN (customer context)
//
// getCustomerCsrfToken() verifies the active session is the
// customer session before returning a token, so the value here is
// always the customer's token and never any other role's.
// ---------------------------------------------------------------------

require_once __DIR__ . '/customer-csrf-token.php';
$csrfToken = getCustomerCsrfToken();

// ---------------------------------------------------------------------
// DATABASE
// ---------------------------------------------------------------------

require_once __DIR__ . '/../backend/database/customer-connect.php';

// ---------------------------------------------------------------------
// PATH DETECTION
// ---------------------------------------------------------------------

/**
 * Get the base path to shared/ from the currently executing page.
 *
 * @return string Asset base path ending with 'shared/'
 */
function getCustomerAssetBase(): string
{
    $scriptPath = $_SERVER['SCRIPT_NAME'];
    $dirPath    = dirname($scriptPath);
    $segments   = array_filter(explode('/', $dirPath));
    $depth      = count($segments);
    return str_repeat('../', $depth) . 'shared/';
}

$assetBase = getCustomerAssetBase();

// ---------------------------------------------------------------------
// FETCH CUSTOMER DATA (if logged in)
//
// Session-canonical for both the display name and the profile
// picture path. The fallback query reads both columns in one LEFT
// JOIN so a pre-existing session pays for a single round trip.
// ---------------------------------------------------------------------

$isLoggedIn         = false;
$userName           = '';
$userInitial        = '';
$profilePicturePath = '';
$profilePictureUrl  = '';

if (isset($_SESSION['customer_id']) && !empty($_SESSION['customer_id'])) {
    $isLoggedIn = true;

    $needName    = empty($_SESSION['customer_name']);
    $needPicture = !array_key_exists('customer_profile_picture', $_SESSION);

    if ($needName || $needPicture) {
        try {
            $stmt = $database_connection->prepare(
                "SELECT
                    c.first_name,
                    c.last_name,
                    cp.profile_picture
                 FROM customer c
                 LEFT JOIN customer_profile cp ON c.customer_id = cp.customer_id
                 WHERE c.customer_id = :id
                 LIMIT 1"
            );
            $stmt->execute([':id' => $_SESSION['customer_id']]);
            $customerData = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($customerData) {
                $fetchedName = trim(
                    ($customerData['first_name'] ?? '') . ' ' .
                    ($customerData['last_name']  ?? '')
                );

                $_SESSION['customer_name'] = $fetchedName;

                $fetchedPicture = (string)($customerData['profile_picture'] ?? '');
                $_SESSION['customer_profile_picture'] = $fetchedPicture;
            } else {
                $_SESSION['customer_name']            = '';
                $_SESSION['customer_profile_picture'] = '';
            }
        } catch (PDOException $e) {
            // Silent fail — login state still valid, name and picture
            // simply won't render. The next page load retries.
        }
    }

    $userName           = (string)($_SESSION['customer_name'] ?? '');
    $profilePicturePath = (string)($_SESSION['customer_profile_picture'] ?? '');

    if ($userName !== '') {
        $userInitial = strtoupper(substr($userName, 0, 1));
    }

    if ($profilePicturePath !== '' && is_string($assetBase) && $assetBase !== '') {
        $projectRootUrl = preg_replace('#shared/$#', '', $assetBase);
        if (is_string($projectRootUrl)) {
            $profilePictureUrl = $projectRootUrl . $profilePicturePath;
        }
    }
}

// ---------------------------------------------------------------------
// CART COUNT (optional badge)
// ---------------------------------------------------------------------

$cartCount = 0;
if ($isLoggedIn) {
    try {
        $stmt = $database_connection->prepare(
            "SELECT COALESCE(SUM(quantity), 0) FROM cart WHERE customer_id = :id"
        );
        $stmt->execute([':id' => $_SESSION['customer_id']]);
        $cartCount = (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        // Silent fail — badge simply won't show.
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
    'sign-in.php'        => 'sign-in.css',
    'sign-up.php'        => 'sign-up.css',
    'dashboard.php'      => 'dashboard.css',
    'menu.php'           => 'menu.css',
    'orders.php'         => 'orders.css',
    'cart.php'           => 'cart.css',
    'checkout.php'       => 'checkout.css',
    'wallet.php'         => 'wallet.css',
    'profile.php'        => 'profile.css',
    'order-receipt.php'  => 'order-receipt.css',
    'product-detail.php' => 'product-detail.css',
    'order-tracking.php' => 'order-tracking.css',
];

$pageCssFile = $pageCssMap[$currentPage] ?? '';
$pageCssPath = '';
if (!empty($pageCssFile) && file_exists(__DIR__ . '/../assets/css/' . $pageCssFile)) {
    $pageCssPath = '../assets/css/' . $pageCssFile;
}

// ---------------------------------------------------------------------
// EXPLICIT ENDPOINT PATH
//
// The sign-out guard in logout.js POSTs to this endpoint with
// action=check_sign_out. Exposed as a global so the JS does not
// have to guess its own relative path.
// ---------------------------------------------------------------------

$customerHandlerEndpoint = '../backend/handlers/profile-handler.php';
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

            <!-- Mobile Toggle -->
            <button class="menu-toggle" id="menuToggle" aria-label="Toggle navigation menu" aria-expanded="false"
                type="button">
                <span class="menu-icon">
                    <span class="bar"></span>
                    <span class="bar"></span>
                    <span class="bar"></span>
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
                            class="nav-link <?php echo in_array($currentPage, ['orders.php', 'order-tracking.php', 'order-receipt.php'], true) ? 'active' : ''; ?>">Orders</a>
                    </li>
                    <li class="nav-item">
                        <a href="cart.php"
                            class="nav-link <?php echo ($currentPage === 'cart.php') ? 'active' : ''; ?>">
                            Cart
                            <?php if ($cartCount > 0): ?>
                            <span class="nav-badge"><?php echo $cartCount; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="wallet.php"
                            class="nav-link <?php echo ($currentPage === 'wallet.php') ? 'active' : ''; ?>">Wallet</a>
                    </li>
                    <li class="nav-item">
                        <a href="profile.php"
                            class="nav-link <?php echo ($currentPage === 'profile.php') ? 'active' : ''; ?>">Profile</a>
                    </li>
                </ul>

                <div class="nav-actions">
                    <a href="profile.php" class="user-profile-circle"
                        title="<?php echo htmlspecialchars($userName !== '' ? $userName : 'Customer', ENT_QUOTES, 'UTF-8'); ?>"
                        aria-label="Go to profile">
                        <?php if ($profilePictureUrl !== ''): ?>
                        <img src="<?php echo htmlspecialchars($profilePictureUrl, ENT_QUOTES, 'UTF-8'); ?>" alt=""
                            class="profile-icon profile-icon-image"
                            onerror="this.onerror=null; this.style.display='none'; if (this.nextElementSibling) { this.nextElementSibling.style.display='inline-flex'; }">
                        <span class="user-initial" style="display: none;">
                            <?php echo htmlspecialchars($userInitial !== '' ? $userInitial : 'U', ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <?php elseif (!empty($userInitial)): ?>
                        <span
                            class="user-initial"><?php echo htmlspecialchars($userInitial, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php else: ?>
                        <img src="<?php echo $assetBase; ?>assets/images/icons/user-profile-circle.svg" alt="Profile"
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
                <div class="mobile-user-avatar">
                    <?php if ($profilePictureUrl !== ''): ?>
                    <img src="<?php echo htmlspecialchars($profilePictureUrl, ENT_QUOTES, 'UTF-8'); ?>" alt=""
                        class="profile-icon profile-icon-image"
                        onerror="this.onerror=null; this.style.display='none'; if (this.nextElementSibling) { this.nextElementSibling.style.display='inline-flex'; }">
                    <span class="user-initial-large" style="display: none;">
                        <?php echo htmlspecialchars($userInitial !== '' ? $userInitial : 'U', ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <?php elseif (!empty($userInitial)): ?>
                    <span
                        class="user-initial-large"><?php echo htmlspecialchars($userInitial, ENT_QUOTES, 'UTF-8'); ?></span>
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
                    class="mobile-nav-link <?php echo in_array($currentPage, ['orders.php', 'order-tracking.php', 'order-receipt.php'], true) ? 'active' : ''; ?>">Orders</a>
            </li>
            <li class="mobile-nav-item">
                <a href="cart.php" class="mobile-nav-link <?php echo ($currentPage === 'cart.php') ? 'active' : ''; ?>">
                    Cart
                    <?php if ($cartCount > 0): ?>
                    <span class="nav-badge"><?php echo $cartCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="mobile-nav-item">
                <a href="wallet.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'wallet.php') ? 'active' : ''; ?>">Wallet</a>
            </li>
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

    <!-- ============================================
         LOGOUT CONFIRMATION MODAL

         The confirm action is a POST form so the customer
         context's CSRF token is carried in the request
         body. sign-out-handler.php reads it from POST.
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
            <p class="logout-modal-text">You'll need to sign in again to access your account.</p>
            <div class="logout-modal-actions">
                <button type="button" class="logout-btn-cancel" data-logout-cancel>Cancel</button>
                <form method="POST" action="../backend/handlers/sign-out-handler.php" class="logout-form">
                    <input type="hidden" name="csrf_token"
                        value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" class="logout-btn-confirm">Yes, sign out</button>
                </form>
            </div>
        </div>
    </div>

    <!-- ============================================
         SIGN-OUT BLOCK MODAL

         Shown when the customer is NOT eligible to sign out.
         Two reasons, one modal:

           - active orders the kitchen is already cooking
           - items still in the cart or the session order queue

         logout.js writes the body text. Single OK button.
         ============================================ -->
    <div class="logout-modal" id="customerBlockSignOutModal" style="display: none;" role="dialog" aria-modal="true"
        aria-labelledby="customerBlockSignOutTitle">
        <div class="logout-modal-overlay" data-block-signout-cancel></div>
        <div class="logout-modal-content">
            <div class="logout-modal-icon" aria-hidden="true">
                <img src="<?php echo $assetBase; ?>assets/images/icons/error-warning-line.svg" alt=""
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/information-fill.svg'">
            </div>
            <p class="logout-modal-title" id="customerBlockSignOutTitle">Can't sign out yet</p>
            <p class="logout-modal-text" id="customerBlockSignOutText"></p>
            <div class="logout-modal-actions">
                <button type="button" class="logout-btn-cancel" data-block-signout-cancel>OK</button>
            </div>
        </div>
    </div>

    <!-- ============================================
         GLOBAL CUSTOMER CONFIG
         ============================================ -->
    <script>
    window.FITPAL_CSRF_TOKEN = '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>';
    window.FITPAL_ASSET_BASE = '<?php echo $assetBase; ?>';
    window.CUSTOMER_HANDLER_ENDPOINT = '<?php echo htmlspecialchars($customerHandlerEndpoint, ENT_QUOTES, 'UTF-8'); ?>';
    </script>

    <main class="main-content" role="main">

        <!-- Load ONLY the customer header JS (not the shared one) -->
        <script src="../assets/ui/js/header.js" defer></script>
        <script src="../assets/ui/js/logout.js" defer></script>