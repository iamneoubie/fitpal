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
 *   - Resolves the signed-in administrator's display name, initial,
 *     and role.
 *
 * Stylesheet load order
 * ---------------------
 * The header emits, in this order:
 *
 *   1. shared/assets/css/global.css       (design tokens + components)
 *   2. shared/assets/css/header.css       (shared header chrome)
 *   3. ../assets/css/header.css           (admin header tuning)
 *   4. ../assets/css/admin-shared.css     (admin list-page chrome)
 *   5. ../assets/css/<page>.css           (page-specific tuning)
 *
 * admin-shared.css was added in v5.4. It carries the list-page
 * chrome that used to be duplicated across admin-tables.css,
 * customers.css, riders.css, and restaurants.css. It must load
 * before the page-specific file so the page tuning wins on cascade
 * position without needing !important. It is linked unconditionally
 * because the page map does not distinguish list pages from
 * non-list pages; the rules it declares only apply to elements
 * that carry the .admin-list-page / .admin-modal / .admin-table-*
 * classes, so loading it on dashboard.php or profile.php is a
 * no-op cost (a small file the browser caches after first use).
 *
 * Session-first display resolution
 * --------------------------------
 * Sign-in-handler.php writes $_SESSION['admin_name'] and
 * $_SESSION['admin_role'] on the success path. This header reads
 * them from the session first and only falls back to a database
 * query when either is missing — which happens only for sessions
 * that predate the migration of the sign-in handler to the
 * {role}_-prefixed key convention (v1.6), or when a session was
 * tampered with to strip the keys.
 *
 * Before this revision the header always queried, once per
 * authenticated page load, on every page, even though the session
 * almost always already carried the values. A five-page admin
 * session paid five redundant LEFT JOIN queries. Now the query
 * fires at most once per session, on the first page view after a
 * sign-in that did not populate the session keys.
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
 *     already present.
 *
 * @package FitPal
 * @version 5.4 — Adds the admin-shared.css <link>, placed between
 *                admin's own header.css and the page-specific CSS
 *                link so shared list-page chrome loads before the
 *                page tuning it is layered under.
 *
 *                (5.3: Session-first display resolution. The header
 *                no longer fires a database query on every
 *                authenticated page load. It reads
 *                $_SESSION['admin_name'] and $_SESSION['admin_role']
 *                first, and only falls back to a query when either
 *                is missing.
 *
 *                5.2: Documented the intentional asymmetry in the
 *                $csrfToken initialization. No code change.
 *                5.1: Rewrote the docblock to describe only current
 *                behavior. 5.0: CSRF consolidation via
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
    // getAdminCsrfToken() is idempotent within the request.
    $csrfToken = getAdminCsrfToken();

    // -----------------------------------------------------------------
    // Session-first display resolution.
    //
    // The session keys are written by sign-in-handler.php v1.6+.
    // Older sessions (pre-v1.6) and sessions that were tampered with
    // will not have them, so a single fallback query fills them in
    // and caches the result back into the session. On every
    // subsequent page load the session values are used and the query
    // is skipped entirely.
    //
    // The session's admin_name is intentionally not trusted for
    // authorization — it is a display string only. Authorization
    // still reads $_SESSION['administrator_id'], which was set by the
    // sign-in handler after session_regenerate_id(true). A tampered
    // display name cannot elevate privileges; it can only change the
    // text rendered in the header. This is the same trust level the
    // customer role's header grants $_SESSION['customer_name'].
    // -----------------------------------------------------------------
    $nameFromSession = trim((string)($_SESSION['admin_name'] ?? ''));
    $roleFromSession = trim((string)($_SESSION['admin_role'] ?? ''));

    if ($nameFromSession === '' || $roleFromSession === '') {
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
                $fetchedName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                $fetchedRole = (string)($row['role'] ?? '');

                $_SESSION['admin_name'] = $fetchedName;
                $_SESSION['admin_role'] = $fetchedRole;

                $nameFromSession = $fetchedName;
                $roleFromSession = $fetchedRole;
            }
        } catch (PDOException $e) {
            // Silent fail — the login state is still valid; the
            // header simply renders without a name and initial. The
            // next page load will retry the fallback query.
        }
    }

    $adminName = $nameFromSession;
    $adminRole = $roleFromSession;

    if ($adminName !== '') {
        $adminInitial = strtoupper(substr($adminName, 0, 1));
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
    <link rel="stylesheet" href="../assets/css/admin-shared.css">

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