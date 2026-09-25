<?php
/**
 * FitPal Restaurant Dashboard
 *
 * Renders one of two dashboards based on the signed-in scope:
 *   - Owner  : restaurant-wide stats + branch overview + weekly chart
 *   - Branch : branch-scoped stats + top products + weekly chart
 *
 * Access control
 * --------------
 * The page is guarded by $_SESSION['restaurant_account_id']. If it is
 * not set, the user is redirected to sign-in. This guard is what
 * makes sign-out work: after sign-out, the key is gone and the
 * dashboard becomes unreachable.
 *
 * Kitchen link
 * ------------
 * For branch-scoped accounts (manager / staff / kitchen), the Total
 * Orders stat tile and the Active Orders hint link into kitchen.php
 * so staff can move from a summary into the working list in one
 * click. Owner accounts do not see a kitchen link — the kitchen page
 * is an operational surface for branch staff only.
 *
 * @package FitPal
 * @version 1.2 — Reads the signed-in account's display name from the
 *                restaurant role's own session key, 'restaurant_name',
 *                instead of the shared 'user_name' key. The shared
 *                key is written by every role on sign-in, so when the
 *                same browser was signed in as both a rider and a
 *                restaurant (one shared PHP session, role-scoped
 *                keys), a rider sign-in overwrote 'user_name' and the
 *                restaurant dashboard then rendered the rider's name
 *                in the greeting. The greeting now reads the
 *                restaurant-scoped key written by
 *                restaurant/backend/handlers/sign-in-handler.php v2.1.
 *
 *                (1.1: Total Orders tile and Active Orders hint link
 *                to kitchen.php for branch accounts.)
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['restaurant_account_id'])) {
    header('Location: sign-in.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../backend/database/restaurant-queries.php';

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
 * LOAD DATA
 * -------------------------------------------------------------- */

$stats          = [];
$weeklyRevenue  = [];
$chartScale     = ['ceiling' => 1000.0, 'step' => 250.0, 'gridlines' => []];
$chartCeiling   = 1000.0;
$barHeights     = [];
$today          = date('Y-m-d');
$branchOverview = [];
$topProducts    = [];

try {
    if ($isBranchScope) {
        $stats         = getBranchDashboardStats($database_connection, $branchId);
        $weeklyRevenue = getBranchWeeklyRevenue($database_connection, $branchId, 7);
        $topProducts   = getBranchTopProducts($database_connection, $branchId, 5);
    } else {
        $stats          = getOwnerDashboardStats($database_connection, $restaurantId);
        $weeklyRevenue  = getOwnerWeeklyRevenue($database_connection, $restaurantId, 7);
        $branchOverview = getOwnerBranchOverview($database_connection, $restaurantId);
    }

    $weeklyMax = 0.0;
    foreach ($weeklyRevenue as $d) {
        if ($d['amount'] > $weeklyMax) $weeklyMax = $d['amount'];
    }
    $chartScale   = getRestaurantChartScale($weeklyMax);
    $chartCeiling = $chartScale['ceiling'];

    foreach ($weeklyRevenue as $d) {
        $pct = $chartCeiling > 0 ? ($d['amount'] / $chartCeiling) * 100 : 0;
        $barHeights[$d['date']] = $d['amount'] > 0 ? max(4, min(100, $pct)) : 0;
    }
} catch (PDOException $e) {
    error_log('Restaurant dashboard error: ' . $e->getMessage());
}

/* --------------------------------------------------------------
 * HELPERS
 * -------------------------------------------------------------- */

function dashFormatCurrency(float|string|null $amount): string
{
    return '₱' . number_format((float)($amount ?? 0), 2);
}
?>

<div class="content restaurant-dashboard-page">
    <div class="container">

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
            <div class="restaurant-dashboard-actions">
                <span class="badge badge-<?php echo $isBranchScope ? 'info' : 'success'; ?>">
                    <?php echo $isBranchScope ? 'Branch' : 'Owner'; ?>
                </span>
            </div>
        </header>

        <?php if (!empty($_SESSION['restaurant_success'])): ?>
        <div class="alert alert-success" role="alert">
            <?php echo htmlspecialchars($_SESSION['restaurant_success'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['restaurant_success']); ?>
        </div>
        <?php endif; ?>

        <!-- STAT CARDS -->
        <section class="restaurant-stats-grid" aria-label="Summary">

            <?php if ($isBranchScope): ?>

            <!-- Total Orders — links to the kitchen list for branch accounts -->
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

            <!-- Active Orders — links to the kitchen list -->
            <a href="kitchen.php" class="restaurant-stat-card">
                <div class="restaurant-stat-info">
                    <p class="restaurant-stat-number">
                        <?php echo number_format((int)($stats['active_orders'] ?? 0)); ?>
                    </p>
                    <p class="restaurant-stat-label">Active Orders</p>
                    <p class="restaurant-stat-hint">
                        Open the kitchen
                    </p>
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

        <!-- WEEKLY CHART -->
        <section class="restaurant-card" aria-labelledby="chart-title">
            <div class="restaurant-card-header">
                <h2 class="heading-5" id="chart-title">Revenue — Last 7 Days</h2>
            </div>
            <div class="restaurant-chart-body">
                <div class="restaurant-weekly-chart" role="img"
                    aria-label="Bar chart of revenue over the last seven days">

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
                            <?php foreach ($weeklyRevenue as $day):
                                $pct      = $barHeights[$day['date']];
                                $isToday  = ($day['date'] === $today);
                                $hasValue = $day['amount'] > 0;
                            ?>
                            <div class="restaurant-chart-column"
                                data-day="<?php echo htmlspecialchars($day['short'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-amount="<?php echo htmlspecialchars(dashFormatCurrency($day['amount']), ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="restaurant-chart-bar-track">
                                    <div class="restaurant-chart-bar <?php echo $isToday ? 'is-today' : ''; ?> <?php echo $hasValue ? '' : 'is-empty'; ?>"
                                        style="height: <?php echo $pct; ?>%" tabindex="0"
                                        aria-label="<?php echo htmlspecialchars($day['label'] . ' ' . dashFormatCurrency($day['amount']), ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                </div>
                                <span class="restaurant-chart-label <?php echo $isToday ? 'is-today' : ''; ?>">
                                    <?php echo htmlspecialchars($day['short'], ENT_QUOTES, 'UTF-8'); ?>
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
                            <?php echo dashFormatCurrency($stats['revenue_today'] ?? 0); ?>
                        </span>
                        <span class="restaurant-chart-summary-hint">
                            <?php echo (int)($stats['orders_today'] ?? 0); ?> orders
                        </span>
                    </div>
                    <div class="restaurant-chart-summary-item">
                        <span class="restaurant-chart-summary-label">This Week</span>
                        <span class="restaurant-chart-summary-value">
                            <?php echo dashFormatCurrency($stats['revenue_this_week'] ?? 0); ?>
                        </span>
                        <span class="restaurant-chart-summary-hint">
                            <?php echo (int)($stats['orders_this_week'] ?? 0); ?> orders
                        </span>
                    </div>
                    <div class="restaurant-chart-summary-item">
                        <span class="restaurant-chart-summary-label">All Time</span>
                        <span class="restaurant-chart-summary-value">
                            <?php echo dashFormatCurrency($stats['gross_revenue'] ?? 0); ?>
                        </span>
                        <span class="restaurant-chart-summary-hint">
                            <?php echo (int)($stats['total_orders'] ?? 0); ?> orders
                        </span>
                    </div>
                </div>
            </div>
        </section>

        <!-- OWNER: BRANCH OVERVIEW -->
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

        <!-- BRANCH: TOP PRODUCTS -->
        <?php if ($isBranchScope): ?>
        <section class="restaurant-card" aria-labelledby="top-products-title">
            <div class="restaurant-card-header">
                <h2 class="heading-5" id="top-products-title">Top Products</h2>
            </div>

            <?php if (empty($topProducts)): ?>
            <div class="restaurant-empty-state">
                <p class="restaurant-empty-title">No sales yet</p>
                <p class="restaurant-empty-text">Your best-selling items will appear here.</p>
            </div>
            <?php else: ?>
            <div class="restaurant-product-list">
                <?php foreach ($topProducts as $p): ?>
                <div class="restaurant-product-row">
                    <div class="restaurant-product-info">
                        <p class="restaurant-product-name">
                            <?php echo htmlspecialchars((string)$p['product_name'], ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                        <p class="restaurant-product-meta">
                            <?php echo number_format((int)$p['units_sold']); ?> sold
                        </p>
                    </div>
                    <div class="restaurant-product-revenue">
                        <?php echo dashFormatCurrency($p['revenue']); ?>
                    </div>
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