<?php
/**
 * FitPal Shared Header
 *
 * Renders the public chrome (logo, nav, mobile menu) for the
 * anonymous pages that belong to the public context:
 *
 *   - fitpal/index.php                          (landing page)
 *   - fitpal/shared/pages/about.php
 *   - fitpal/shared/pages/contact.php
 *   - fitpal/shared/pages/privacy-policy.php
 *   - fitpal/shared/pages/terms-conditions.php
 *
 * ---------------------------------------------------------------------
 * SCOPE
 * ---------------------------------------------------------------------
 * This is the ANONYMOUS header. It never reads any authenticated
 * role's login state, because it never has access to any
 * authenticated role's session — each role runs as its own PHP
 * session under its own cookie name, and a request for a public
 * page carries only the public session cookie.
 *
 * The four authenticated roles each have their own header:
 *
 *   customer/includes/header.php
 *   rider/includes/header.php
 *   restaurant/includes/header.php
 *   admin/includes/header.php
 *
 * Those headers own their role's login state and nav. This one
 * renders only the anonymous chrome.
 *
 * ---------------------------------------------------------------------
 * CONTRACT
 * ---------------------------------------------------------------------
 * This header is included by an entry-point file. It assumes the
 * entry-point file has already run:
 *
 *     require_once '<...>/shared/includes/session-bootstrap.php';
 *     fitpal_session_bootstrap('public');
 *
 * at the very top of the request, BEFORE this header is included.
 * If that precondition is not met, this header emits a minimal
 * error page and exits. It does NOT call session_start() itself,
 * because doing so would open PHP's default PHPSESSID session and
 * silently break the per-role isolation that the rest of the
 * project relies on.
 *
 * This header:
 *   - Verifies the active session is the public session.
 *   - Computes $assetBase from the current script's path depth.
 *   - Loads global.css, header.css, and the page-specific CSS
 *     named in $pageCssMap.
 *   - Renders the anonymous nav and mobile nav.
 *   - Opens <main class="main-content"> for the page body.
 *   - Loads shared/assets/ui/js/header.js.
 *
 * This header does NOT:
 *   - Start a session.
 *   - Generate a CSRF token.
 *   - Read any role's login state.
 *   - Emit the footer (that is shared/includes/footer.php).
 *
 * ---------------------------------------------------------------------
 * ASSET BASE
 * ---------------------------------------------------------------------
 * $assetBase is computed from the depth of the current script's
 * directory. Pages under shared/pages/ get a two-level prefix
 * (../../shared/). The landing page at fitpal/index.php gets a
 * one-level prefix (./shared/).
 *
 * ---------------------------------------------------------------------
 * WHY NO CSRF TOKEN HERE
 * ---------------------------------------------------------------------
 * The public header renders no POST form. Pages that need a public
 * CSRF token — currently only shared/pages/contact.php — call
 * getPublicCsrfToken() from shared/includes/public-csrf-token.php
 * themselves. That helper verifies the active session is the
 * public session before returning a token, so a misconfigured page
 * cannot accidentally write a public token into a role session.
 *
 * @package FitPal
 * @version 3.0 — Per-role session migration (Option B). The header
 *                no longer starts a session. It requires the entry-
 *                point file to have called
 *                fitpal_session_bootstrap('public') before including
 *                it, and fails closed with a minimal error page if
 *                the active session is not the public session.
 *
 *                (2.0: removed the dead logged-in nav block that
 *                referenced retired session keys.)
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// PRECONDITION CHECK
//
// Under Option B, the public session must be the active session
// before this header can render. If it is not, the entry-point
// file forgot to bootstrap — refuse to render rather than silently
// emit a header that belongs to the wrong context.
//
// This block runs before any output so headers can still be sent
// if a future revision decides to redirect.
// ---------------------------------------------------------------------

if (!function_exists('fitpal_session_current_context')) {
    require_once __DIR__ . '/session-bootstrap.php';
}

if (fitpal_session_current_context() !== 'public') {
    error_log(
        'shared/includes/header.php: included without the public session '
        . 'being bootstrapped. Current context: "'
        . fitpal_session_current_context() . '". '
        . 'The entry-point file must call fitpal_session_bootstrap(\'public\') '
        . 'before including this header.'
    );

    http_response_code(500);

    // Minimal inline error output. This header is the last line of
    // defence; if it cannot render the real chrome, it must still
    // tell the developer what went wrong in a way that is visible
    // in the browser.
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
        This page was reached without a public session being started.
        The entry-point file must call
        <code style="background:#f3f4f6;padding:2px 6px;border-radius:4px;">fitpal_session_bootstrap('public')</code>
        before including the shared header.
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
// DATABASE
//
// The public pages that use this header (about, contact, privacy,
// terms) do not query the database from the header itself, but the
// convention across the project is that the header pulls in the
// shared database connection so any page that needs it has it.
// The landing page also relies on this.
// ---------------------------------------------------------------------

require_once __DIR__ . '/../backend/database/database-connect.php';

// ---------------------------------------------------------------------
// ASSET BASE
// ---------------------------------------------------------------------

if (!function_exists('getSharedHeaderAssetBase')) {
    /**
     * Compute the asset base path from the current script's depth.
     *
     * @return string
     */
    function getSharedHeaderAssetBase(): string
    {
        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
        $dirPath    = dirname($scriptPath);
        $segments   = array_filter(explode('/', $dirPath));
        $depth      = count($segments);

        if ($depth <= 0) {
            return './shared/';
        }

        return str_repeat('../', $depth) . 'shared/';
    }
}

$assetBase = getSharedHeaderAssetBase();

$currentPage   = basename($_SERVER['PHP_SELF'] ?? '');
$isLandingPage = ($currentPage === 'index.php');

// ---------------------------------------------------------------------
// PAGE-SPECIFIC CSS
// ---------------------------------------------------------------------

$pageCssMap = [
    'index.php'            => 'landing.css',
    'about.php'            => 'about.css',
    'contact.php'          => 'contact.css',
    'privacy-policy.php'   => 'privacy-policy.css',
    'terms-conditions.php' => 'terms-conditions.css',
];

$pageCssFile = $pageCssMap[$currentPage] ?? '';
$pageCssPath = '';

if ($pageCssFile !== '' && file_exists(__DIR__ . '/../assets/css/' . $pageCssFile)) {
    $pageCssPath = $assetBase . 'assets/css/' . $pageCssFile;
}

// ---------------------------------------------------------------------
// BRAND ASSETS
// ---------------------------------------------------------------------

$brandLogoIco = $assetBase . 'assets/images/brand/Logo.ico';
$brandLogoPng = $assetBase . 'assets/images/brand/Logo.png';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="FitPal - Healthy Food Delivery">
    <title>FitPal - Healthy Food Delivery</title>

    <link rel="icon" type="image/x-icon" href="<?php echo $brandLogoIco; ?>">
    <link rel="shortcut icon" href="<?php echo $brandLogoIco; ?>">

    <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/global.css">
    <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/header.css">

    <?php if ($pageCssPath !== ''): ?>
    <link rel="stylesheet" href="<?php echo $pageCssPath; ?>">
    <?php endif; ?>
</head>

<body>
    <header class="header" role="banner">
        <div class="header-container">
            <!-- Logo -->
            <div class="header-logo">
                <a href="<?php echo $assetBase; ?>../index.php" class="logo-link" aria-label="FitPal Home">
                    <img src="<?php echo $brandLogoPng; ?>" alt="FitPal Logo" class="logo-image">
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

            <!-- Desktop Nav -->
            <nav class="header-nav" id="mainNav" role="navigation" aria-label="Main navigation">
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="<?php echo $assetBase; ?>../index.php"
                            class="nav-link <?php echo $isLandingPage ? 'active' : ''; ?>">Home</a>
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
                    <div class="auth-dropdown">
                        <button class="btn btn-login dropdown-toggle" id="loginDropdown" aria-expanded="false"
                            aria-haspopup="true" type="button">Login</button>
                        <ul class="dropdown-menu" id="dropdownMenu" role="menu">
                            <li role="none">
                                <a href="<?php echo $assetBase; ?>../customer/pages/sign-in.php"
                                    role="menuitem">Customer</a>
                            </li>
                            <li role="none">
                                <a href="<?php echo $assetBase; ?>../restaurant/pages/sign-in.php"
                                    role="menuitem">Restaurant</a>
                            </li>
                            <li role="none">
                                <a href="<?php echo $assetBase; ?>../rider/pages/sign-in.php" role="menuitem">Rider</a>
                            </li>
                            <li role="none">
                                <a href="<?php echo $assetBase; ?>../admin/pages/sign-in.php" role="menuitem">Admin</a>
                            </li>
                        </ul>
                    </div>
                </div>
            </nav>
        </div>
    </header>

    <!-- Mobile Overlay -->
    <div class="mobile-overlay" id="mobileOverlay"></div>

    <!-- Mobile Nav -->
    <nav class="mobile-nav" id="mobileNav" role="navigation" aria-label="Mobile navigation">
        <ul class="mobile-nav-list">
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>../index.php"
                    class="mobile-nav-link <?php echo $isLandingPage ? 'active' : ''; ?>">Home</a>
            </li>
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>pages/about.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'about.php') ? 'active' : ''; ?>">About</a>
            </li>
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>pages/contact.php"
                    class="mobile-nav-link <?php echo ($currentPage === 'contact.php') ? 'active' : ''; ?>">Contact</a>
            </li>
            <li class="mobile-nav-divider"></li>
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>../customer/pages/sign-in.php"
                    class="mobile-nav-link mobile-login">Customer</a>
            </li>
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>../restaurant/pages/sign-in.php"
                    class="mobile-nav-link mobile-login">Restaurant</a>
            </li>
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>../rider/pages/sign-in.php"
                    class="mobile-nav-link mobile-login">Rider</a>
            </li>
            <li class="mobile-nav-item">
                <a href="<?php echo $assetBase; ?>../admin/pages/sign-in.php"
                    class="mobile-nav-link mobile-login">Admin</a>
            </li>
        </ul>
    </nav>

    <main class="main-content" role="main">

        <script src="<?php echo $assetBase; ?>assets/ui/js/header.js" defer></script>