<?php
/**
 * FitPal Restaurant Dashboard
 *
 * Renders one of two dashboards based on the signed-in scope:
 *   - Owner  : restaurant-wide stats + four-tab analytics + branch overview
 *   - Branch : branch-scoped stats + four-tab analytics
 *
 * ---------------------------------------------------------------------
 * DOM STRUCTURE
 * ---------------------------------------------------------------------
 * The page's top-level children inside .container are, in order:
 *
 *   header.restaurant-dashboard-header
 *       greeting | controls (scope badge + period combobox)
 *
 *   [conditional] .restaurant-verification-banner
 *       full-width strip. Rendered when verification_status is
 *       'pending', 'denied', or 'suspended'. The banner's copy and
 *       action differ by status:
 *
 *         pending     amber   "Your application is under review"
 *                             no action; the restaurant waits.
 *
 *         denied      red     "Your application was denied"
 *                             Re-apply now → restaurant-reapply.php
 *
 *         suspended   red     "Your account has been suspended"
 *                             no action; the restaurant must
 *                             contact support to resolve it.
 *
 *       Only one banner is ever rendered. The status field picks
 *       exactly one of the three branches.
 *
 *   [conditional] .alert.alert-success
 *       one-shot flash from the previous request.
 *
 *   section.restaurant-stats-grid
 *       four stat cards.
 *
 *   section.restaurant-analytics
 *       nav.restaurant-tabs
 *       .restaurant-tab-panels
 *           #panel-revenue / #panel-trending / #panel-days / #panel-categories
 *
 *   [owner only] section.restaurant-card
 *       branch overview.
 *
 * ---------------------------------------------------------------------
 * REVENUE RECOGNITION
 * ---------------------------------------------------------------------
 * No gross revenue is recognised until an order reaches 'delivered'.
 * Every revenue reader in
 * restaurant/backend/database/restaurant-queries.php filters on
 * order_status = 'delivered' only.
 *
 * ---------------------------------------------------------------------
 * PERIOD COMBOBOX
 * ---------------------------------------------------------------------
 * The page reads ?period= from the query string. Three tokens are
 * accepted:
 *
 *   7d   Last 7 days (default)
 *   30d  Last 30 days
 *   12m  Last 12 months
 *
 * Any other value falls back to 7d. The token is passed to
 * fitpal_analytics_period() which returns a tuple every reader
 * consumes.
 *
 * The period control is a custom combobox: a <button> that opens
 * a popover menu of three options. Native <select> is not used
 * because its option list cannot be styled and clashes with the
 * dashboard. The menu options are plain <button> elements that
 * carry data-value; dashboard.js handles the click, updates the
 * button label, and navigates to ?period=<value>.
 *
 * ---------------------------------------------------------------------
 * ACCESS CONTROL
 * ---------------------------------------------------------------------
 * The page is guarded by $_SESSION['restaurant_account_id']. If it
 * is not set, the user is redirected to sign-in.
 *
 * ---------------------------------------------------------------------
 * SESSION KEYS
 * ---------------------------------------------------------------------
 * The account's display name comes from 'restaurant_name'. The
 * shared 'user_name' key is never read here.
 *
 * @package FitPal
 * @version 4.0 — Adds the verification banner for 'pending',
 *                'denied', and 'suspended' statuses. The banner's
 *                copy and action are chosen from the status. The
 *                denied-only re-apply strip from v3.0 is replaced
 *                by the three-state banner.
 *
 *                (3.0: structural relayout. 2.0: four-tab
 *                analytics, period selector, re-apply entry point.
 *                1.3: revenue-recognition docblock.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('restaurant');

if (empty($_SESSION['restaurant_account_id'])) {
    header('Location: sign-in.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../backend/database/restaurant-queries.php';

// $assetBase and $csrfToken are provided by header.php.

$restaurantId  = (int)($_SESSION['restaurant_id'] ?? 0);
$branchId      = !empty($_SESSION['restaurant_branch_id'])
    ? (int)$_SESSION['restaurant_branch_id']
    : 0;
$scope         = (string)($_SESSION['restaurant_scope'] ?? 'owner');
$branchName    = (string)($_SESSION['restaurant_branch_name'] ?? '');
$branchCode    = (string)($_SESSION['restaurant_branch_code'] ?? '');
$accountName   = (string)($_SESSION['restaurant_name'] ?? '');
$firstName     = $accountName !== '' ? explode(' ', trim($accountName))[0] : 'Account';

$isBranchScope = ($scope === 'branch' && $branchId > 0);

/* --------------------------------------------------------------
 * VERIFICATION STATUS — DRIVES THE BANNER
 * -------------------------------------------------------------- */

$verificationStatus = '';
$businessName       = '';

if ($restaurantId > 0) {
    try {
        $statusStmt = $database_connection->prepare(
            "SELECT verification_status, business_name
               FROM restaurant
              WHERE restaurant_id = :restaurant_id
              LIMIT 1"
        );
        $statusStmt->execute([':restaurant_id' => $restaurantId]);
        $statusRow = $statusStmt->fetch(PDO::FETCH_ASSOC);

        if ($statusRow) {
            $verificationStatus = (string)$statusRow['verification_status'];
            $businessName       = (string)$statusRow['business_name'];
        }
    } catch (PDOException $e) {
        error_log('Restaurant dashboard status load error: ' . $e->getMessage());
    }
}

$isVerified = ($verificationStatus === 'verified');

/* --------------------------------------------------------------
 * PERIOD TOKEN
 * -------------------------------------------------------------- */

$rawPeriodToken = isset($_GET['period']) ? (string)$_GET['period'] : '7d';
$period         = fitpal_analytics_period($rawPeriodToken);
$periodToken    = $period['token'];
$periodLabel    = $period['label'];

/* --------------------------------------------------------------
 * SCOPE BUILDER
 * -------------------------------------------------------------- */

$analyticsScope = $isBranchScope
    ? fitpal_analytics_scope_branch($branchId)
    : fitpal_analytics_scope_owner($restaurantId);

/* --------------------------------------------------------------
 * LOAD STATS + ANALYTICS
 * -------------------------------------------------------------- */

$stats           = [];
$weeklyRevenue   = [];
$chartScale      = ['ceiling' => 1000.0, 'step' => 250.0, 'gridlines' => []];
$chartCeiling    = 1000.0;
$barHeights      = [];
$today           = date('Y-m-d');
$branchOverview  = [];

$revenueSummary    = [];
$trendingProducts  = [];
$bestSalesDay      = false;
$weekdayBreakdown  = [];
$categoryBreakdown = [];

try {
    if ($isBranchScope) {
        $stats = getBranchDashboardStats($database_connection, $branchId);
    } else {
        $stats = getOwnerDashboardStats($database_connection, $restaurantId);
    }
} catch (PDOException $e) {
    error_log('Restaurant dashboard stats load error: ' . $e->getMessage());
}

try {
    $weeklyRevenue = getRestaurantRevenueSeries(
        $database_connection,
        $analyticsScope,
        $period
    );

    $weeklyMax = 0.0;
    foreach ($weeklyRevenue as $bucket) {
        if ($bucket['amount'] > $weeklyMax) {
            $weeklyMax = $bucket['amount'];
        }
    }

    $chartScale   = getRestaurantChartScale($weeklyMax);
    $chartCeiling = $chartScale['ceiling'];

    foreach ($weeklyRevenue as $bucket) {
        $pct = $chartCeiling > 0
            ? ($bucket['amount'] / $chartCeiling) * 100
            : 0;
        $barHeights[$bucket['key']] = $bucket['amount'] > 0
            ? max(4, min(100, $pct))
            : 0;
    }
} catch (PDOException $e) {
    error_log('Restaurant dashboard revenue series error: ' . $e->getMessage());
}

try {
    $revenueSummary = getRestaurantRevenueSummary(
        $database_connection,
        $analyticsScope,
        $period
    );
} catch (PDOException $e) {
    error_log('Restaurant dashboard revenue summary error: ' . $e->getMessage());
}

try {
    $trendingProducts = getRestaurantTrendingProducts(
        $database_connection,
        $analyticsScope,
        $period,
        8
    );
} catch (PDOException $e) {
    error_log('Restaurant dashboard trending load error: ' . $e->getMessage());
}

try {
    $bestSalesDay = getRestaurantBestSalesDay(
        $database_connection,
        $analyticsScope,
        $period
    );
} catch (PDOException $e) {
    error_log('Restaurant dashboard best-day load error: ' . $e->getMessage());
    $bestSalesDay = false;
}

try {
    $weekdayBreakdown = getRestaurantWeekdayBreakdown(
        $database_connection,
        $analyticsScope,
        $period
    );
} catch (PDOException $e) {
    error_log('Restaurant dashboard weekday load error: ' . $e->getMessage());
    $weekdayBreakdown = [];
}

try {
    $categoryBreakdown = getRestaurantCategoryBreakdown(
        $database_connection,
        $analyticsScope,
        $period
    );
} catch (PDOException $e) {
    error_log('Restaurant dashboard category load error: ' . $e->getMessage());
    $categoryBreakdown = [];
}

if (!$isBranchScope) {
    try {
        $branchOverview = getOwnerBranchOverview($database_connection, $restaurantId);
    } catch (PDOException $e) {
        error_log('Restaurant dashboard branch overview error: ' . $e->getMessage());
    }
}

/* --------------------------------------------------------------
 * HELPERS
 * -------------------------------------------------------------- */

function dashFormatCurrency(float|string|null $amount): string
{
    return '₱' . number_format((float)($amount ?? 0), 2);
}

function dashWeekdayWidths(array $rows): array
{
    $max = 0.0;
    foreach ($rows as $row) {
        if ((float)$row['amount'] > $max) {
            $max = (float)$row['amount'];
        }
    }

    $widths = [];
    foreach ($rows as $row) {
        $amount = (float)$row['amount'];
        $widths[(int)$row['weekday']] = ($max > 0 && $amount > 0)
            ? max(2, ($amount / $max) * 100)
            : 0;
    }

    return $widths;
}

function dashBestWeekday(array $rows): int
{
    $best = -1;
    $bestAvg = 0.0;

    foreach ($rows as $row) {
        $occurrences = (int)($row['occurrences'] ?? 0);
        if ($occurrences <= 0) {
            continue;
        }
        $avg = (float)$row['amount'] / $occurrences;
        if ($avg > $bestAvg) {
            $bestAvg = $avg;
            $best = (int)$row['weekday'];
        }
    }

    return $best;
}

$weekdayWidths = dashWeekdayWidths($weekdayBreakdown);
$bestWeekday   = dashBestWeekday($weekdayBreakdown);

/**
 * The period combobox options, in display order.
 */
$periodOptions = [
    '7d'  => 'Last 7 days',
    '30d' => 'Last 30 days',
    '12m' => 'Last 12 months',
];

/* --------------------------------------------------------------
 * VERIFICATION BANNER DATA
 *
 * The banner's copy, tone, icon, and action are chosen from the
 * status. Only one of the three statuses can be active at a time.
 *
 * A status of 'verified' produces no banner.
 * -------------------------------------------------------------- */

$verificationBanner = null;

if ($verificationStatus === 'pending') {
    $verificationBanner = [
        'tone'         => 'pending',
        'title'        => 'Your restaurant is under review',
        'message'      => 'An admin is reviewing your registration and permits. '
                        . 'You can explore your dashboard while you wait. '
                        . 'Products and the kitchen board unlock once you are verified.',
        'icon'         => 'time-fill.svg',
        'fallback_icon'=> 'information-fill.svg',
        'action_href'  => '',
        'action_label' => '',
        'action_icon'  => '',
        'action_fallback_icon' => '',
    ];
} elseif ($verificationStatus === 'denied') {
    $verificationBanner = [
        'tone'         => 'danger',
        'title'        => 'Your application was denied',
        'message'      => 'An admin reviewed your registration and did not approve it. '
                        . 'Update your information and re-submit so they can take another look.',
        'icon'         => 'file-warning-fill.svg',
        'fallback_icon'=> 'error-warning-fill.svg',
        'action_href'  => 'restaurant-reapply.php',
        'action_label' => 'Re-apply now',
        'action_icon'  => 'fill-form.svg',
        'action_fallback_icon' => 'edit.svg',
    ];
} elseif ($verificationStatus === 'suspended') {
    $verificationBanner = [
        'tone'         => 'danger',
        'title'        => 'Your account has been suspended',
        'message'      => 'Your restaurant is temporarily suspended and cannot take new orders. '
                        . 'Please contact FitPal support to resolve this before you can resume operating.',
        'icon'         => 'error-warning-fill.svg',
        'fallback_icon'=> 'file-warning-fill.svg',
        'action_href'  => '',
        'action_label' => '',
        'action_icon'  => '',
        'action_fallback_icon' => '',
    ];
}
?>
<div class="content restaurant-dashboard-page">
    <div class="container">

        <!-- ============================================================
             HEADER
             ============================================================ -->
        <header class="restaurant-dashboard-header">
            <div class="restaurant-dashboard-greeting">
                <h1 class="heading-2">
                    Welcome back, <span><?php echo htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'); ?></span>
                </h1>
                <p class="text-muted">
                    <?php if ($isBranchScope): ?>
                    You are signed in to
                    <strong><?php echo htmlspecialchars($branchName !== '' ? $branchName : 'Branch', ENT_QUOTES, 'UTF-8'); ?></strong>
                    <?php if ($branchCode !== ''): ?>
                    (<?php echo htmlspecialchars($branchCode, ENT_QUOTES, 'UTF-8'); ?>)
                    <?php endif; ?>.
                    <?php else: ?>
                    Managing
                    <strong><?php echo htmlspecialchars($businessName !== '' ? $businessName : 'your restaurant', ENT_QUOTES, 'UTF-8'); ?></strong>
                    across <?php echo (int)($stats['branch_count'] ?? 0); ?>
                    branch<?php echo ((int)($stats['branch_count'] ?? 0)) === 1 ? '' : 'es'; ?>.
                    <?php endif; ?>
                </p>
            </div>

            <div class="restaurant-dashboard-controls">
                <span class="badge badge-<?php echo $isBranchScope ? 'info' : 'success'; ?>">
                    <?php echo $isBranchScope ? 'Branch' : 'Owner'; ?>
                </span>

                <div class="period-combo" id="periodCombo"
                    data-active="<?php echo htmlspecialchars($periodToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="button" class="period-combo-button" id="periodComboButton" aria-haspopup="listbox"
                        aria-expanded="false" aria-labelledby="periodComboButton">
                        <span class="period-combo-button-left">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/time-fill.svg" alt=""
                                class="period-combo-button-icon" width="16" height="16"
                                onerror="this.onerror=null; this.style.display='none';">
                            <span class="period-combo-button-label" id="periodComboLabel">
                                <?php echo htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </span>
                        <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-drop-down-line.svg" alt=""
                            class="period-combo-button-chevron" width="12" height="12"
                            onerror="this.onerror=null; this.style.display='none';">
                    </button>

                    <ul class="period-combo-menu" id="periodComboMenu" role="listbox"
                        aria-labelledby="periodComboButton" hidden>
                        <?php foreach ($periodOptions as $token => $label):
                            $isSelected = ($token === $periodToken);
                        ?>
                        <li role="none">
                            <button type="button"
                                class="period-combo-option <?php echo $isSelected ? 'is-selected' : ''; ?>"
                                role="option" aria-selected="<?php echo $isSelected ? 'true' : 'false'; ?>"
                                data-value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>"
                                data-label="<?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>">
                                <span class="period-combo-option-label">
                                    <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <img src="<?php echo $assetBase; ?>assets/images/icons/verified-fill.svg" alt=""
                                    class="period-combo-option-check" width="16" height="16"
                                    onerror="this.onerror=null; this.style.display='none';">
                            </button>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </header>

        <!-- ============================================================
             VERIFICATION BANNER
             Rendered for pending, denied, or suspended.
             ============================================================ -->
        <?php if ($verificationBanner !== null): ?>
        <div class="restaurant-verification-banner tone-<?php echo htmlspecialchars($verificationBanner['tone'], ENT_QUOTES, 'UTF-8'); ?>"
            role="alert">
            <div class="restaurant-verification-banner-icon" aria-hidden="true">
                <img src="<?php echo $assetBase; ?>assets/images/icons/<?php echo htmlspecialchars($verificationBanner['icon'], ENT_QUOTES, 'UTF-8'); ?>"
                    alt=""
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/<?php echo htmlspecialchars($verificationBanner['fallback_icon'], ENT_QUOTES, 'UTF-8'); ?>'">
            </div>

            <div class="restaurant-verification-banner-text">
                <p class="restaurant-verification-banner-title">
                    <?php echo htmlspecialchars($verificationBanner['title'], ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <p class="restaurant-verification-banner-message">
                    <?php echo htmlspecialchars($verificationBanner['message'], ENT_QUOTES, 'UTF-8'); ?>
                </p>
            </div>

            <?php if ($verificationBanner['action_href'] !== ''): ?>
            <a href="<?php echo htmlspecialchars($verificationBanner['action_href'], ENT_QUOTES, 'UTF-8'); ?>"
                class="restaurant-verification-banner-action">
                <img src="<?php echo $assetBase; ?>assets/images/icons/<?php echo htmlspecialchars($verificationBanner['action_icon'], ENT_QUOTES, 'UTF-8'); ?>"
                    alt="" width="16" height="16"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/<?php echo htmlspecialchars($verificationBanner['action_fallback_icon'], ENT_QUOTES, 'UTF-8'); ?>'">
                <span><?php echo htmlspecialchars($verificationBanner['action_label'], ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ============================================================
             FLASH MESSAGES
             ============================================================ -->
        <?php if (!empty($_SESSION['restaurant_success'])): ?>
        <div class="alert alert-success" role="alert">
            <?php echo htmlspecialchars((string)$_SESSION['restaurant_success'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['restaurant_success']); ?>
        </div>
        <?php endif; ?>

        <!-- ============================================================
             STAT CARDS
             ============================================================ -->
        <section class="restaurant-stats-grid" aria-label="Summary">
            <?php if ($isBranchScope): ?>

            <a href="kitchen.php" class="restaurant-stat-card">
                <div class="restaurant-stat-info">
                    <p class="restaurant-stat-number">
                        <?php echo number_format((int)($stats['total_orders'] ?? 0)); ?>
                    </p>
                    <p class="restaurant-stat-label">Orders (All Time)</p>
                    <?php if ((int)($stats['orders_today'] ?? 0) > 0): ?>
                    <p class="restaurant-stat-hint restaurant-stat-hint-active">
                        +<?php echo (int)$stats['orders_today']; ?> today
                    </p>
                    <?php endif; ?>
                </div>
            </a>

            <a href="kitchen.php" class="restaurant-stat-card">
                <div class="restaurant-stat-info">
                    <p class="restaurant-stat-number">
                        <?php echo number_format((int)($stats['active_orders'] ?? 0)); ?>
                    </p>
                    <p class="restaurant-stat-label">Active Orders</p>
                    <p class="restaurant-stat-hint">Open the kitchen</p>
                </div>
            </a>

            <div class="restaurant-stat-card">
                <div class="restaurant-stat-info">
                    <p class="restaurant-stat-number">
                        <?php echo dashFormatCurrency($stats['gross_revenue'] ?? 0); ?>
                    </p>
                    <p class="restaurant-stat-label">Gross Revenue</p>
                    <p class="restaurant-stat-hint">
                        Avg <?php echo dashFormatCurrency($stats['average_order_value'] ?? 0); ?> per order
                    </p>
                </div>
            </div>

            <div class="restaurant-stat-card">
                <div class="restaurant-stat-info">
                    <p class="restaurant-stat-number">
                        <?php echo number_format((int)($stats['product_count'] ?? 0)); ?>
                    </p>
                    <p class="restaurant-stat-label">Active Products</p>
                </div>
            </div>

            <?php else: ?>

            <div class="restaurant-stat-card">
                <div class="restaurant-stat-info">
                    <p class="restaurant-stat-number">
                        <?php echo number_format((int)($stats['branch_count'] ?? 0)); ?>
                    </p>
                    <p class="restaurant-stat-label">Branches</p>
                </div>
            </div>

            <div class="restaurant-stat-card">
                <div class="restaurant-stat-info">
                    <p class="restaurant-stat-number">
                        <?php echo number_format((int)($stats['product_count'] ?? 0)); ?>
                    </p>
                    <p class="restaurant-stat-label">Active Products</p>
                </div>
            </div>

            <div class="restaurant-stat-card">
                <div class="restaurant-stat-info">
                    <p class="restaurant-stat-number">
                        <?php echo number_format((int)($stats['total_orders'] ?? 0)); ?>
                    </p>
                    <p class="restaurant-stat-label">Total Orders</p>
                    <?php if ((int)($stats['orders_today'] ?? 0) > 0): ?>
                    <p class="restaurant-stat-hint restaurant-stat-hint-active">
                        +<?php echo (int)$stats['orders_today']; ?> today
                    </p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="restaurant-stat-card">
                <div class="restaurant-stat-info">
                    <p class="restaurant-stat-number">
                        <?php echo dashFormatCurrency($stats['gross_revenue'] ?? 0); ?>
                    </p>
                    <p class="restaurant-stat-label">Gross Revenue</p>
                    <p class="restaurant-stat-hint">
                        Avg <?php echo dashFormatCurrency($stats['average_order_value'] ?? 0); ?> per order
                    </p>
                </div>
            </div>

            <?php endif; ?>
        </section>

        <!-- ============================================================
             ANALYTICS
             ============================================================ -->
        <section class="restaurant-analytics" aria-label="Analytics">

            <nav class="restaurant-tabs" role="tablist" aria-label="Analytics views">
                <button type="button" class="restaurant-tab active" role="tab" aria-selected="true"
                    aria-controls="panel-revenue" data-tab="revenue">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/chart-line-up.svg" alt=""
                        class="restaurant-tab-icon" width="16" height="16"
                        onerror="this.onerror=null; this.style.display='none';">
                    <span>Revenue</span>
                </button>

                <button type="button" class="restaurant-tab" role="tab" aria-selected="false"
                    aria-controls="panel-trending" data-tab="trending">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/funds-box-analytic-fill.svg" alt=""
                        class="restaurant-tab-icon" width="16" height="16"
                        onerror="this.onerror=null; this.style.display='none';">
                    <span>Trending</span>
                </button>

                <button type="button" class="restaurant-tab" role="tab" aria-selected="false" aria-controls="panel-days"
                    data-tab="days">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/time-fill.svg" alt=""
                        class="restaurant-tab-icon" width="16" height="16"
                        onerror="this.onerror=null; this.style.display='none';">
                    <span>Days</span>
                </button>

                <button type="button" class="restaurant-tab" role="tab" aria-selected="false"
                    aria-controls="panel-categories" data-tab="categories">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/list-settings-fill.svg" alt=""
                        class="restaurant-tab-icon" width="16" height="16"
                        onerror="this.onerror=null; this.style.display='none';">
                    <span>Categories</span>
                </button>
            </nav>

            <div class="restaurant-tab-panels">

                <!-- ==============================================
                     PANEL — REVENUE
                     ============================================== -->
                <div class="restaurant-tab-panel active" id="panel-revenue" role="tabpanel"
                    aria-labelledby="tab-revenue" data-panel="revenue">

                    <article class="restaurant-card">
                        <div class="restaurant-card-header">
                            <h2 class="heading-5">
                                Revenue — <?php echo htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </h2>
                        </div>

                        <?php if (empty($weeklyRevenue) || array_sum(array_column($weeklyRevenue, 'amount')) <= 0): ?>
                        <div class="restaurant-tab-empty">
                            <div class="restaurant-tab-empty-icon" aria-hidden="true">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/chart-line-up.svg" alt=""
                                    onerror="this.onerror=null; this.style.display='none';">
                            </div>
                            <p class="restaurant-tab-empty-title">No revenue in this period</p>
                            <p class="restaurant-tab-empty-text">
                                Delivered orders will appear here. Try a longer period.
                            </p>
                        </div>
                        <?php else: ?>

                        <div class="restaurant-chart-body">
                            <div class="restaurant-weekly-chart" role="img"
                                aria-label="Bar chart of revenue over <?php echo htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8'); ?>">

                                <div class="restaurant-chart-y-axis" aria-hidden="true">
                                    <?php foreach (array_reverse($chartScale['gridlines']) as $grid): ?>
                                    <span class="restaurant-chart-y-label">
                                        ₱<?php echo number_format($grid, 0); ?>
                                    </span>
                                    <?php endforeach; ?>
                                </div>

                                <div class="restaurant-chart-plot">
                                    <?php foreach ($chartScale['gridlines'] as $grid): ?>
                                    <div class="restaurant-chart-gridline" aria-hidden="true"></div>
                                    <?php endforeach; ?>

                                    <div class="restaurant-chart-columns">
                                        <?php foreach ($weeklyRevenue as $bucket):
                                            $key      = (string)$bucket['key'];
                                            $pct      = $barHeights[$key] ?? 0;
                                            $isToday  = ($key === $today);
                                            $hasValue = $bucket['amount'] > 0;
                                        ?>
                                        <div class="restaurant-chart-column"
                                            data-bucket="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-label="<?php echo htmlspecialchars($bucket['label'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-amount="<?php echo htmlspecialchars(dashFormatCurrency($bucket['amount']), ENT_QUOTES, 'UTF-8'); ?>">
                                            <div class="restaurant-chart-bar-track">
                                                <div class="restaurant-chart-bar <?php echo $isToday ? 'is-today' : ''; ?> <?php echo $hasValue ? '' : 'is-empty'; ?>"
                                                    style="height: <?php echo $pct; ?>%" tabindex="0"
                                                    aria-label="<?php echo htmlspecialchars($bucket['label'] . ' ' . dashFormatCurrency($bucket['amount']), ENT_QUOTES, 'UTF-8'); ?>">
                                                </div>
                                            </div>
                                            <span
                                                class="restaurant-chart-label <?php echo $isToday ? 'is-today' : ''; ?>">
                                                <?php echo htmlspecialchars($bucket['short'], ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="restaurant-chart-summary">
                                <div class="restaurant-chart-summary-item">
                                    <span class="restaurant-chart-summary-label">Today</span>
                                    <span class="restaurant-chart-summary-value">
                                        <?php echo dashFormatCurrency($revenueSummary['today_revenue'] ?? 0); ?>
                                    </span>
                                    <span class="restaurant-chart-summary-hint">
                                        <?php echo (int)($revenueSummary['today_orders'] ?? 0); ?> orders
                                    </span>
                                </div>
                                <div class="restaurant-chart-summary-item">
                                    <span class="restaurant-chart-summary-label">
                                        <?php echo htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                    <span class="restaurant-chart-summary-value">
                                        <?php echo dashFormatCurrency($revenueSummary['period_revenue'] ?? 0); ?>
                                    </span>
                                    <span class="restaurant-chart-summary-hint">
                                        <?php echo (int)($revenueSummary['period_orders'] ?? 0); ?> orders
                                        · Avg <?php echo dashFormatCurrency($revenueSummary['period_aov'] ?? 0); ?>
                                    </span>
                                </div>
                                <div class="restaurant-chart-summary-item">
                                    <span class="restaurant-chart-summary-label">All Time</span>
                                    <span class="restaurant-chart-summary-value">
                                        <?php echo dashFormatCurrency($revenueSummary['all_time_revenue'] ?? 0); ?>
                                    </span>
                                    <span class="restaurant-chart-summary-hint">
                                        <?php echo (int)($revenueSummary['all_time_orders'] ?? 0); ?> orders
                                    </span>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </article>
                </div>

                <!-- ==============================================
                     PANEL — TRENDING
                     ============================================== -->
                <div class="restaurant-tab-panel" id="panel-trending" role="tabpanel" aria-labelledby="tab-trending"
                    data-panel="trending" hidden>

                    <article class="restaurant-card">
                        <div class="restaurant-card-header">
                            <h2 class="heading-5">
                                Top products — <?php echo htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </h2>
                            <span class="badge badge-secondary">vs previous period</span>
                        </div>

                        <?php if (empty($trendingProducts)): ?>
                        <div class="restaurant-tab-empty">
                            <div class="restaurant-tab-empty-icon" aria-hidden="true">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/funds-box-analytic-fill.svg"
                                    alt="" onerror="this.onerror=null; this.style.display='none';">
                            </div>
                            <p class="restaurant-tab-empty-title">No product sales yet</p>
                            <p class="restaurant-tab-empty-text">
                                Your best-selling items will appear here once orders are delivered.
                            </p>
                        </div>
                        <?php else: ?>

                        <div class="restaurant-trending-list">
                            <?php foreach ($trendingProducts as $index => $product):
                                $rank      = $index + 1;
                                $delta     = $product['delta_pct'];
                                $deltaClass = 'is-flat';
                                $deltaText  = '—';

                                if ($delta !== null) {
                                    if ($delta > 0) {
                                        $deltaClass = 'is-up';
                                        $deltaText  = '+' . number_format($delta, 1) . '%';
                                    } elseif ($delta < 0) {
                                        $deltaClass = 'is-down';
                                        $deltaText  = number_format($delta, 1) . '%';
                                    } else {
                                        $deltaText = '0.0%';
                                    }
                                }
                            ?>
                            <div class="restaurant-trending-row">
                                <span class="restaurant-trending-rank" aria-hidden="true">
                                    <?php echo $rank; ?>
                                </span>
                                <div class="restaurant-trending-info">
                                    <span class="restaurant-trending-name">
                                        <?php echo htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                    <span class="restaurant-trending-meta">
                                        <?php echo number_format((int)$product['units_current']); ?> sold this period
                                    </span>
                                </div>
                                <span class="restaurant-trending-delta <?php echo $deltaClass; ?>"
                                    aria-label="Change vs previous period">
                                    <?php echo htmlspecialchars($deltaText, ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <span class="restaurant-trending-revenue">
                                    <?php echo dashFormatCurrency($product['revenue']); ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </article>
                </div>

                <!-- ==============================================
                     PANEL — DAYS
                     ============================================== -->
                <div class="restaurant-tab-panel" id="panel-days" role="tabpanel" aria-labelledby="tab-days"
                    data-panel="days" hidden>

                    <article class="restaurant-card">
                        <div class="restaurant-card-header">
                            <h2 class="heading-5">
                                Best sales <?php echo $period['bucket'] === 'month' ? 'month' : 'day'; ?>
                            </h2>
                        </div>

                        <?php if ($bestSalesDay === false): ?>
                        <div class="restaurant-tab-empty">
                            <div class="restaurant-tab-empty-icon" aria-hidden="true">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/time-fill.svg" alt=""
                                    onerror="this.onerror=null; this.style.display='none';">
                            </div>
                            <p class="restaurant-tab-empty-title">No sales in this period</p>
                            <p class="restaurant-tab-empty-text">
                                Your strongest day will appear here once orders come in.
                            </p>
                        </div>
                        <?php else: ?>
                        <div class="restaurant-best-day">
                            <div class="restaurant-best-day-icon" aria-hidden="true">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/time-update.svg" alt=""
                                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/time-fill.svg'">
                            </div>
                            <div class="restaurant-best-day-info">
                                <span class="restaurant-best-day-label">
                                    Best <?php echo $period['bucket'] === 'month' ? 'month' : 'day'; ?> of the period
                                </span>
                                <span class="restaurant-best-day-name">
                                    <?php echo htmlspecialchars($bestSalesDay['label'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </div>
                            <div class="restaurant-best-day-stats">
                                <span class="restaurant-best-day-amount">
                                    <?php echo dashFormatCurrency($bestSalesDay['amount']); ?>
                                </span>
                                <span class="restaurant-best-day-orders">
                                    <?php echo (int)$bestSalesDay['orders']; ?> orders
                                </span>
                            </div>
                        </div>
                        <?php endif; ?>
                    </article>

                    <article class="restaurant-card">
                        <div class="restaurant-card-header">
                            <h2 class="heading-5">Revenue by weekday</h2>
                        </div>

                        <?php if (empty($weekdayBreakdown)): ?>
                        <div class="restaurant-tab-empty">
                            <div class="restaurant-tab-empty-icon" aria-hidden="true">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/time-fill.svg" alt=""
                                    onerror="this.onerror=null; this.style.display='none';">
                            </div>
                            <p class="restaurant-tab-empty-title">No weekday data yet</p>
                            <p class="restaurant-tab-empty-text">
                                Complete a few deliveries to see which day of the week is strongest.
                            </p>
                        </div>
                        <?php else: ?>
                        <div class="restaurant-weekday-chart">
                            <?php foreach ($weekdayBreakdown as $row):
                                $wd          = (int)$row['weekday'];
                                $amount      = (float)$row['amount'];
                                $occurrences = (int)$row['occurrences'];
                                $width       = $weekdayWidths[$wd] ?? 0;
                                $isBest      = ($wd === $bestWeekday);
                                $avg         = $occurrences > 0 ? $amount / $occurrences : 0;
                            ?>
                            <div class="restaurant-weekday-row">
                                <span class="restaurant-weekday-label">
                                    <?php echo htmlspecialchars($row['short'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <div class="restaurant-weekday-bar-track"
                                    aria-label="<?php echo htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="restaurant-weekday-bar <?php echo $isBest ? 'is-best' : ''; ?> <?php echo $amount <= 0 ? 'is-empty' : ''; ?>"
                                        style="width: <?php echo $width; ?>%"></div>
                                </div>
                                <span class="restaurant-weekday-value">
                                    <?php echo dashFormatCurrency($amount); ?>
                                    <?php if ($occurrences > 0): ?>
                                    <span class="restaurant-weekday-value-sub">
                                        <?php echo dashFormatCurrency($avg); ?>/day
                                    </span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </article>
                </div>

                <!-- ==============================================
                     PANEL — CATEGORIES
                     ============================================== -->
                <div class="restaurant-tab-panel" id="panel-categories" role="tabpanel" aria-labelledby="tab-categories"
                    data-panel="categories" hidden>

                    <article class="restaurant-card">
                        <div class="restaurant-card-header">
                            <h2 class="heading-5">
                                Revenue by category — <?php echo htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </h2>
                        </div>

                        <?php if (empty($categoryBreakdown)): ?>
                        <div class="restaurant-tab-empty">
                            <div class="restaurant-tab-empty-icon" aria-hidden="true">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/list-settings-fill.svg" alt=""
                                    onerror="this.onerror=null; this.style.display='none';">
                            </div>
                            <p class="restaurant-tab-empty-title">No category data yet</p>
                            <p class="restaurant-tab-empty-text">
                                Products with an assigned category will appear here once delivered.
                            </p>
                        </div>
                        <?php else: ?>
                        <div class="restaurant-category-list">
                            <?php foreach ($categoryBreakdown as $row):
                                $share = (float)$row['share_pct'];
                            ?>
                            <div class="restaurant-category-row">
                                <div class="restaurant-category-row-head">
                                    <span class="restaurant-category-name">
                                        <?php echo htmlspecialchars($row['category'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                    <span class="restaurant-category-amount">
                                        <?php echo dashFormatCurrency($row['amount']); ?>
                                    </span>
                                </div>
                                <div class="restaurant-category-share-track"
                                    aria-label="<?php echo htmlspecialchars($row['category'], ENT_QUOTES, 'UTF-8'); ?> revenue share">
                                    <div class="restaurant-category-share-bar" style="width: <?php echo $share; ?>%">
                                    </div>
                                </div>
                                <div class="restaurant-category-meta">
                                    <span>
                                        <span class="restaurant-category-meta-strong">
                                            <?php echo number_format($share, 1); ?>%
                                        </span>
                                        of period revenue
                                    </span>
                                    <span>
                                        <span class="restaurant-category-meta-strong">
                                            <?php echo number_format((int)$row['units']); ?>
                                        </span>
                                        units · <?php echo number_format((int)$row['orders']); ?> orders
                                    </span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </article>
                </div>

            </div>
        </section>

        <!-- ============================================================
             OWNER: BRANCH OVERVIEW
             ============================================================ -->
        <?php if (!$isBranchScope): ?>
        <section class="restaurant-card" aria-labelledby="branches-title">
            <div class="restaurant-card-header">
                <h2 class="heading-5" id="branches-title">Branches</h2>
            </div>

            <?php if (empty($branchOverview)): ?>
            <div class="restaurant-empty-state">
                <p class="restaurant-empty-title">No branches yet</p>
                <p class="restaurant-empty-text">Add your first branch to start tracking sales.</p>
            </div>
            <?php else: ?>
            <div class="restaurant-branch-list">
                <?php foreach ($branchOverview as $b): ?>
                <div class="restaurant-branch-row">
                    <div class="restaurant-branch-info">
                        <p class="restaurant-branch-name">
                            <?php echo htmlspecialchars((string)$b['branch_name'], ENT_QUOTES, 'UTF-8'); ?>
                            <span class="restaurant-branch-code">
                                <?php echo htmlspecialchars((string)$b['branch_code'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </p>
                        <p class="restaurant-branch-meta">
                            <?php echo htmlspecialchars((string)($b['city'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            &middot;
                            <?php echo number_format((int)$b['product_count']); ?> products
                            &middot;
                            <?php echo number_format((int)$b['order_count']); ?> orders
                        </p>
                    </div>
                    <div class="restaurant-branch-revenue">
                        <?php echo dashFormatCurrency($b['revenue']); ?>
                    </div>
                    <span class="badge <?php echo (int)$b['is_active'] === 1 ? 'badge-success' : 'badge-secondary'; ?>">
                        <?php echo (int)$b['is_active'] === 1 ? 'Active' : 'Inactive'; ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

    </div>
</div>

<script src="../assets/ui/js/dashboard.js" defer></script>
<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>