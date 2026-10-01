<?php
/**
 * FitPal Admin Dashboard
 *
 * The dashboard surfaces the metrics an admin, marketing, and finance
 * reader need on a single screen:
 *
 *   - Four stat cards at the top (customers, restaurants, riders,
 *     platform revenue). Each links to its list page.
 *   - A chart card with four sub-tabs:
 *       Revenue      7-day GMV vs platform fees
 *       Fees         7-day fee-bucket breakdown
 *       Orders       7-day order count by payment method
 *       Performers   Top restaurants by GMV, top riders by deliveries
 *
 * The dashboard header does not render a navigation button row —
 * the stat cards already act as the navigation.
 *
 * The recent-moderation-activity card has been removed. The
 * pending-riders list stays in the right column of the first row and
 * is still served by getRidersPaginated() with $withTotal = false,
 * because the card renders no pagination and discards the total.
 *
 * All SQL lives in admin-queries.php. This page contains no SQL, no
 * inline CSS, and no inline JS.
 *
 * ---------------------------------------------------------------------
 * REVENUE RECOGNITION AND THE 'failed' STATUS
 * ---------------------------------------------------------------------
 * No gross revenue is recognised until an order reaches 'delivered'.
 * The admin-query layer excludes 'cancelled', 'refunded', and
 * 'failed' from every revenue aggregate; this page's stat cards and
 * the Revenue chart panel therefore already show the correct
 * "billable" numbers without any additional filtering here.
 *
 * The 'failed' status was added by the shared order-transaction
 * layer's sweepFailedDeliveries() and is surfaced to the admin as a
 * distinct outcome from 'cancelled' so the closed-order split stays
 * readable:
 *
 *   - The Orders chart panel's summary strip now shows the refund
 *     rate (cancelled + refunded over total, unchanged) AND a
 *     separate failed count.
 *   - The Orders series carries a per-day 'failed' bucket alongside
 *     'cancelled' so a future chart variant can stack it.
 *
 * The stacked dimension in the Orders chart is the payment method
 * (COD / Wallet / Online), not the outcome, so the stacked bars
 * themselves do not change. Payment-method totals in the summary
 * strip also do not change.
 *
 * @package FitPal
 * @version 7.0 — Surfaces the 'failed' order status in the Orders
 *                chart panel's summary strip. The refund rate is
 *                unchanged; a new "Failed" summary item reports the
 *                7-day failed count so an admin can see the
 *                failed-delivery outcome alongside cancellations.
 *
 *                (6.0: analytics-first layout. 5.0: loaded
 *                admin-modal.js for consistency. 4.4: no functional
 *                change from 4.3.)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('admin');

if (empty($_SESSION['administrator_id'])) {
    header('Location: sign-in.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../backend/database/admin-queries.php';

$adminId      = (int)$_SESSION['administrator_id'];
$adminProfile = getAdminProfile($database_connection, $adminId) ?: [];
$firstName    = (string)($adminProfile['first_name'] ?? 'Admin');

$stats = getAdminDashboardStats($database_connection);

$weeklyRevenue = getAdminWeeklyRevenue($database_connection, 7);
$weeklyFees    = getAdminWeeklyFeeBreakdown($database_connection, 7);
$weeklyOrders  = getAdminWeeklyOrders($database_connection, 7);

$topRestaurants = getAdminTopRestaurants($database_connection, 5);
$topRiders      = getAdminTopRiders($database_connection, 5);

$today = date('Y-m-d');

// ---- Revenue chart scale ----
$revenueMax = 0.0;
foreach ($weeklyRevenue as $d) {
    $stacked = $d['gmv'] + $d['platform_fees'];
    if ($stacked > $revenueMax) $revenueMax = $stacked;
}
$revenueScale = getAdminChartScale($revenueMax);

$revenueBarHeights = [];
foreach ($weeklyRevenue as $d) {
    $stacked = $d['gmv'] + $d['platform_fees'];
    $pct = $revenueScale['ceiling'] > 0
        ? ($stacked / $revenueScale['ceiling']) * 100
        : 0;
    $revenueBarHeights[$d['date']] = $stacked > 0
        ? max(4, min(100, $pct))
        : 0;
}

// ---- Fees chart scale ----
$feesMax = 0.0;
foreach ($weeklyFees as $d) {
    if ($d['total'] > $feesMax) $feesMax = $d['total'];
}
$feesScale = getAdminChartScale($feesMax);

$feesBarHeights = [];
foreach ($weeklyFees as $d) {
    $pct = $feesScale['ceiling'] > 0
        ? ($d['total'] / $feesScale['ceiling']) * 100
        : 0;
    $feesBarHeights[$d['date']] = $d['total'] > 0
        ? max(4, min(100, $pct))
        : 0;
}

// ---- Orders chart scale ----
$ordersMax = 0;
foreach ($weeklyOrders as $d) {
    if ($d['total'] > $ordersMax) $ordersMax = $d['total'];
}
$ordersScale = getAdminChartScale((float)$ordersMax);

$ordersBarHeights = [];
foreach ($weeklyOrders as $d) {
    $pct = $ordersScale['ceiling'] > 0
        ? ($d['total'] / $ordersScale['ceiling']) * 100
        : 0;
    $ordersBarHeights[$d['date']] = $d['total'] > 0
        ? max(4, min(100, $pct))
        : 0;
}

// ---- Fee bucket totals for the strip ----
$feeTotals = [
    'base_delivery'  => 0.0,
    'extra_branches' => 0.0,
    'service_fee'    => 0.0,
    'vat'            => 0.0,
];
foreach ($weeklyFees as $d) {
    $feeTotals['base_delivery']  += $d['base_delivery'];
    $feeTotals['extra_branches'] += $d['extra_branches'];
    $feeTotals['service_fee']    += $d['service_fee'];
    $feeTotals['vat']            += $d['vat'];
}

// ---- Order payment totals for the strip ----
//
// $paymentTotals['failed'] is the 7-day count of failed-delivery
// orders, surfaced as a distinct outcome next to cancellations. It
// is deliberately NOT folded into $paymentTotals['cancelled']
// because a failed order is not a refund.
$paymentTotals = [
    'cod'       => 0,
    'wallet'    => 0,
    'online'    => 0,
    'total'     => 0,
    'delivered' => 0,
    'cancelled' => 0,
    'failed'    => 0,
];
foreach ($weeklyOrders as $d) {
    $paymentTotals['cod']       += $d['cod'];
    $paymentTotals['wallet']    += $d['wallet'];
    $paymentTotals['online']    += $d['online'];
    $paymentTotals['total']     += $d['total'];
    $paymentTotals['delivered'] += $d['delivered'];
    $paymentTotals['cancelled'] += $d['cancelled'];
    $paymentTotals['failed']    += (int)($d['failed'] ?? 0);
}

$refundRate = $paymentTotals['total'] > 0
    ? round(($paymentTotals['cancelled'] / $paymentTotals['total']) * 100, 1)
    : 0.0;

// ---- Top-performer normalization ----
$maxTopRestaurantGmv = 0.0;
foreach ($topRestaurants as $r) {
    if ($r['gmv'] > $maxTopRestaurantGmv) $maxTopRestaurantGmv = $r['gmv'];
}

$maxTopRiderDeliveries = 0;
foreach ($topRiders as $r) {
    if ($r['deliveries'] > $maxTopRiderDeliveries) $maxTopRiderDeliveries = $r['deliveries'];
}

// $csrfToken is provided by header.php (admin_csrf_token).
?>

<div class="content admin-dashboard-page">
    <div class="container">

        <!-- ============================================
             HEADER
             No action row — the stat cards are the nav.
             ============================================ -->
        <header class="admin-dashboard-header">
            <div class="admin-dashboard-greeting">
                <h1 class="heading-2">
                    Welcome back, <span><?php echo htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'); ?></span>
                </h1>
                <p class="text-muted">Platform health and analytics at a glance.</p>
            </div>
        </header>

        <?php if (!empty($_SESSION['admin_success'])): ?>
        <div class="alert alert-success" role="alert">
            <?php echo htmlspecialchars($_SESSION['admin_success'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['admin_success']); ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['admin_error'])): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo htmlspecialchars($_SESSION['admin_error'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['admin_error']); ?>
        </div>
        <?php endif; ?>

        <!-- ============================================
             STAT CARDS
             ============================================ -->
        <section class="admin-stats-grid" aria-label="Platform statistics">

            <a href="customers.php" class="admin-stat-card">
                <div class="admin-stat-icon admin-stat-icon-customers" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/people-team.svg" alt="">
                </div>
                <div class="admin-stat-info">
                    <p class="admin-stat-number"><?php echo number_format($stats['total_customers']); ?></p>
                    <p class="admin-stat-label">Customers</p>
                    <p class="admin-stat-hint admin-stat-hint-active">
                        <?php echo number_format($stats['active_customers']); ?> active
                    </p>
                </div>
                <span class="admin-stat-arrow" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-right-s-line.svg" alt=""
                        class="admin-stat-arrow-img" width="18" height="18">
                </span>
            </a>

            <a href="restaurants.php" class="admin-stat-card">
                <div class="admin-stat-icon admin-stat-icon-restaurants" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt="">
                </div>
                <div class="admin-stat-info">
                    <p class="admin-stat-number"><?php echo number_format($stats['total_restaurants']); ?></p>
                    <p class="admin-stat-label">Restaurants</p>
                    <p class="admin-stat-hint">
                        <?php echo number_format($stats['verified_restaurants']); ?> verified
                    </p>
                </div>
                <span class="admin-stat-arrow" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-right-s-line.svg" alt=""
                        class="admin-stat-arrow-img" width="18" height="18">
                </span>
            </a>

            <a href="riders.php" class="admin-stat-card">
                <div class="admin-stat-icon admin-stat-icon-riders" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/order.svg" alt="">
                </div>
                <div class="admin-stat-info">
                    <p class="admin-stat-number"><?php echo number_format($stats['total_riders']); ?></p>
                    <p class="admin-stat-label">Riders</p>
                    <p
                        class="admin-stat-hint <?php echo $stats['pending_riders'] > 0 ? 'admin-stat-hint-active' : ''; ?>">
                        <?php echo number_format($stats['pending_riders']); ?> pending review
                    </p>
                </div>
                <span class="admin-stat-arrow" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-right-s-line.svg" alt=""
                        class="admin-stat-arrow-img" width="18" height="18">
                </span>
            </a>

            <div class="admin-stat-card">
                <div class="admin-stat-icon admin-stat-icon-revenue" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/coin-line.svg" alt="">
                </div>
                <div class="admin-stat-info">
                    <p class="admin-stat-number">
                        <?php echo formatAdminCurrencyCompact($stats['platform_revenue']); ?>
                    </p>
                    <p class="admin-stat-label">Platform Revenue</p>
                    <p class="admin-stat-hint">
                        from <?php echo number_format($stats['total_orders']); ?> orders
                    </p>
                </div>
            </div>

        </section>

        <!-- ============================================
             ROW: Chart card (2/3) + Pending riders (1/3)
             ============================================ -->
        <div class="admin-dashboard-row admin-dashboard-row-primary">

            <!-- ============================================
                 CHART CARD — four sub-tabs
                 ============================================ -->
            <section class="admin-card admin-chart-card" aria-labelledby="admin-chart-title">
                <div class="admin-card-header">
                    <h2 class="heading-5" id="admin-chart-title">Analytics</h2>
                    <span class="admin-chart-range">Last 7 Days</span>
                </div>

                <!-- Sub-tab row -->
                <div class="admin-chart-subtabs" role="tablist" aria-label="Analytics view">
                    <button type="button" class="admin-chart-subtab active" data-chart="revenue" role="tab"
                        aria-selected="true">
                        Revenue
                    </button>
                    <button type="button" class="admin-chart-subtab" data-chart="fees" role="tab" aria-selected="false">
                        Fees
                    </button>
                    <button type="button" class="admin-chart-subtab" data-chart="orders" role="tab"
                        aria-selected="false">
                        Orders
                    </button>
                    <button type="button" class="admin-chart-subtab" data-chart="performers" role="tab"
                        aria-selected="false">
                        Performers
                    </button>
                </div>

                <div class="admin-chart-body">

                    <!-- ============================================
                         PANEL: Revenue
                         ============================================ -->
                    <div class="admin-chart-panel active" data-chart-panel="revenue" role="tabpanel">
                        <div class="admin-weekly-chart" role="img"
                            aria-label="Bar chart of GMV and platform revenue over the last seven days">
                            <div class="admin-chart-y-axis" aria-hidden="true">
                                <?php foreach (array_reverse($revenueScale['gridlines']) as $grid): ?>
                                <span class="admin-chart-y-label">₱<?php echo number_format($grid, 0); ?></span>
                                <?php endforeach; ?>
                            </div>
                            <div class="admin-chart-plot">
                                <?php foreach ($revenueScale['gridlines'] as $grid): ?>
                                <div class="admin-chart-gridline" aria-hidden="true"></div>
                                <?php endforeach; ?>
                                <div class="admin-chart-columns">
                                    <?php foreach ($weeklyRevenue as $day):
                                        $pct     = $revenueBarHeights[$day['date']];
                                        $isToday = ($day['date'] === $today);
                                        $stacked = $day['gmv'] + $day['platform_fees'];
                                        $gmvPct  = $stacked > 0 ? ($day['gmv'] / $stacked) * 100 : 0;
                                    ?>
                                    <div class="admin-chart-column"
                                        data-day="<?php echo htmlspecialchars($day['short'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-amount="<?php echo htmlspecialchars(formatAdminCurrency($stacked), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-detail="GMV <?php echo htmlspecialchars(formatAdminCurrency($day['gmv']), ENT_QUOTES, 'UTF-8'); ?> · Fees <?php echo htmlspecialchars(formatAdminCurrency($day['platform_fees']), ENT_QUOTES, 'UTF-8'); ?>">
                                        <div class="admin-chart-bar-track">
                                            <div class="admin-chart-bar admin-chart-bar-stacked <?php echo $isToday ? 'is-today' : ''; ?>"
                                                data-bar-height="<?php echo $pct; ?>" tabindex="0"
                                                aria-label="<?php echo htmlspecialchars($day['label'] . ' ' . formatAdminCurrency($stacked), ENT_QUOTES, 'UTF-8'); ?>">
                                                <span class="admin-chart-bar-fill admin-chart-bar-fill-gmv"
                                                    data-fill-height="<?php echo $gmvPct; ?>"></span>
                                                <span class="admin-chart-bar-fill admin-chart-bar-fill-fees"
                                                    data-fill-height="<?php echo 100 - $gmvPct; ?>"></span>
                                            </div>
                                        </div>
                                        <span class="admin-chart-label <?php echo $isToday ? 'is-today' : ''; ?>">
                                            <?php echo htmlspecialchars($day['short'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="admin-chart-tooltip" id="adminChartTooltip" role="status" aria-live="polite">
                            </div>
                        </div>

                        <div class="admin-chart-legend">
                            <span class="admin-chart-legend-item">
                                <span class="admin-chart-legend-swatch admin-chart-legend-swatch-gmv"></span>
                                Item Subtotal (GMV)
                            </span>
                            <span class="admin-chart-legend-item">
                                <span class="admin-chart-legend-swatch admin-chart-legend-swatch-fees"></span>
                                Platform Fees
                            </span>
                        </div>

                        <div class="admin-chart-summary">
                            <div class="admin-chart-summary-item">
                                <span class="admin-chart-summary-label">GMV (All Time)</span>
                                <span class="admin-chart-summary-value">
                                    <?php echo formatAdminCurrency($stats['gross_merchandise_value']); ?>
                                </span>
                                <span class="admin-chart-summary-hint">
                                    what customers spent on food
                                </span>
                            </div>
                            <div class="admin-chart-summary-item">
                                <span class="admin-chart-summary-label">Platform Revenue (All Time)</span>
                                <span class="admin-chart-summary-value">
                                    <?php echo formatAdminCurrency($stats['platform_revenue']); ?>
                                </span>
                                <span class="admin-chart-summary-hint">
                                    delivery + service + VAT
                                </span>
                            </div>
                            <div class="admin-chart-summary-item">
                                <span class="admin-chart-summary-label">Avg. Order Value</span>
                                <span class="admin-chart-summary-value">
                                    <?php echo formatAdminCurrency($stats['average_order_value']); ?>
                                </span>
                                <span class="admin-chart-summary-hint">
                                    across <?php echo number_format($stats['total_orders']); ?> orders
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- ============================================
                         PANEL: Fees
                         ============================================ -->
                    <div class="admin-chart-panel" data-chart-panel="fees" role="tabpanel">
                        <div class="admin-weekly-chart" role="img"
                            aria-label="Bar chart of fee breakdown over the last seven days">
                            <div class="admin-chart-y-axis" aria-hidden="true">
                                <?php foreach (array_reverse($feesScale['gridlines']) as $grid): ?>
                                <span class="admin-chart-y-label">₱<?php echo number_format($grid, 0); ?></span>
                                <?php endforeach; ?>
                            </div>
                            <div class="admin-chart-plot">
                                <?php foreach ($feesScale['gridlines'] as $grid): ?>
                                <div class="admin-chart-gridline" aria-hidden="true"></div>
                                <?php endforeach; ?>
                                <div class="admin-chart-columns">
                                    <?php foreach ($weeklyFees as $day):
                                        $pct     = $feesBarHeights[$day['date']];
                                        $isToday = ($day['date'] === $today);
                                        $total   = $day['total'];
                                        $basePct = $total > 0 ? ($day['base_delivery'] / $total) * 100 : 0;
                                        $extraPct = $total > 0 ? ($day['extra_branches'] / $total) * 100 : 0;
                                        $svcPct  = $total > 0 ? ($day['service_fee'] / $total) * 100 : 0;
                                        $vatPct  = $total > 0 ? ($day['vat'] / $total) * 100 : 0;
                                    ?>
                                    <div class="admin-chart-column"
                                        data-day="<?php echo htmlspecialchars($day['short'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-amount="<?php echo htmlspecialchars(formatAdminCurrency($total), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-detail="Delivery <?php echo htmlspecialchars(formatAdminCurrency($day['base_delivery']), ENT_QUOTES, 'UTF-8'); ?> · Branches <?php echo htmlspecialchars(formatAdminCurrency($day['extra_branches']), ENT_QUOTES, 'UTF-8'); ?> · Service <?php echo htmlspecialchars(formatAdminCurrency($day['service_fee']), ENT_QUOTES, 'UTF-8'); ?> · VAT <?php echo htmlspecialchars(formatAdminCurrency($day['vat']), ENT_QUOTES, 'UTF-8'); ?>">
                                        <div class="admin-chart-bar-track">
                                            <div class="admin-chart-bar admin-chart-bar-stacked <?php echo $isToday ? 'is-today' : ''; ?>"
                                                data-bar-height="<?php echo $pct; ?>" tabindex="0"
                                                aria-label="<?php echo htmlspecialchars($day['label'] . ' ' . formatAdminCurrency($total), ENT_QUOTES, 'UTF-8'); ?>">
                                                <span class="admin-chart-bar-fill admin-chart-bar-fill-fee-base"
                                                    data-fill-height="<?php echo $basePct; ?>"></span>
                                                <span class="admin-chart-bar-fill admin-chart-bar-fill-fee-extra"
                                                    data-fill-height="<?php echo $extraPct; ?>"></span>
                                                <span class="admin-chart-bar-fill admin-chart-bar-fill-fee-service"
                                                    data-fill-height="<?php echo $svcPct; ?>"></span>
                                                <span class="admin-chart-bar-fill admin-chart-bar-fill-fee-vat"
                                                    data-fill-height="<?php echo $vatPct; ?>"></span>
                                            </div>
                                        </div>
                                        <span class="admin-chart-label <?php echo $isToday ? 'is-today' : ''; ?>">
                                            <?php echo htmlspecialchars($day['short'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="admin-chart-legend">
                            <span class="admin-chart-legend-item">
                                <span class="admin-chart-legend-swatch admin-chart-legend-swatch-fee-base"></span>
                                Base Delivery
                            </span>
                            <span class="admin-chart-legend-item">
                                <span class="admin-chart-legend-swatch admin-chart-legend-swatch-fee-extra"></span>
                                Extra Branches
                            </span>
                            <span class="admin-chart-legend-item">
                                <span class="admin-chart-legend-swatch admin-chart-legend-swatch-fee-service"></span>
                                Service Fee
                            </span>
                            <span class="admin-chart-legend-item">
                                <span class="admin-chart-legend-swatch admin-chart-legend-swatch-fee-vat"></span>
                                VAT
                            </span>
                        </div>

                        <div class="admin-chart-summary">
                            <div class="admin-chart-summary-item">
                                <span class="admin-chart-summary-label">Base Delivery</span>
                                <span class="admin-chart-summary-value">
                                    <?php echo formatAdminCurrency($feeTotals['base_delivery']); ?>
                                </span>
                                <span class="admin-chart-summary-hint">7-day total</span>
                            </div>
                            <div class="admin-chart-summary-item">
                                <span class="admin-chart-summary-label">Extra Branches</span>
                                <span class="admin-chart-summary-value">
                                    <?php echo formatAdminCurrency($feeTotals['extra_branches']); ?>
                                </span>
                                <span class="admin-chart-summary-hint">7-day total</span>
                            </div>
                            <div class="admin-chart-summary-item">
                                <span class="admin-chart-summary-label">Service Fee</span>
                                <span class="admin-chart-summary-value">
                                    <?php echo formatAdminCurrency($feeTotals['service_fee']); ?>
                                </span>
                                <span class="admin-chart-summary-hint">7-day total</span>
                            </div>
                            <div class="admin-chart-summary-item">
                                <span class="admin-chart-summary-label">VAT Collected</span>
                                <span class="admin-chart-summary-value">
                                    <?php echo formatAdminCurrency($feeTotals['vat']); ?>
                                </span>
                                <span class="admin-chart-summary-hint">7-day total</span>
                            </div>
                        </div>
                    </div>

                    <!-- ============================================
                         PANEL: Orders
                         ============================================ -->
                    <div class="admin-chart-panel" data-chart-panel="orders" role="tabpanel">
                        <div class="admin-weekly-chart" role="img"
                            aria-label="Bar chart of order counts by payment method over the last seven days">
                            <div class="admin-chart-y-axis" aria-hidden="true">
                                <?php foreach (array_reverse($ordersScale['gridlines']) as $grid): ?>
                                <span class="admin-chart-y-label"><?php echo number_format($grid, 0); ?></span>
                                <?php endforeach; ?>
                            </div>
                            <div class="admin-chart-plot">
                                <?php foreach ($ordersScale['gridlines'] as $grid): ?>
                                <div class="admin-chart-gridline" aria-hidden="true"></div>
                                <?php endforeach; ?>
                                <div class="admin-chart-columns">
                                    <?php foreach ($weeklyOrders as $day):
                                        $pct     = $ordersBarHeights[$day['date']];
                                        $isToday = ($day['date'] === $today);
                                        $total   = $day['total'];
                                        $codPct    = $total > 0 ? ($day['cod'] / $total) * 100 : 0;
                                        $walletPct = $total > 0 ? ($day['wallet'] / $total) * 100 : 0;
                                        $onlinePct = $total > 0 ? ($day['online'] / $total) * 100 : 0;
                                    ?>
                                    <div class="admin-chart-column"
                                        data-day="<?php echo htmlspecialchars($day['short'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-amount="<?php echo $total; ?> orders"
                                        data-detail="COD <?php echo (int)$day['cod']; ?> · Wallet <?php echo (int)$day['wallet']; ?> · Online <?php echo (int)$day['online']; ?>">
                                        <div class="admin-chart-bar-track">
                                            <div class="admin-chart-bar admin-chart-bar-stacked <?php echo $isToday ? 'is-today' : ''; ?>"
                                                data-bar-height="<?php echo $pct; ?>" tabindex="0"
                                                aria-label="<?php echo htmlspecialchars($day['label'] . ' ' . $total . ' orders', ENT_QUOTES, 'UTF-8'); ?>">
                                                <span class="admin-chart-bar-fill admin-chart-bar-fill-pay-cod"
                                                    data-fill-height="<?php echo $codPct; ?>"></span>
                                                <span class="admin-chart-bar-fill admin-chart-bar-fill-pay-wallet"
                                                    data-fill-height="<?php echo $walletPct; ?>"></span>
                                                <span class="admin-chart-bar-fill admin-chart-bar-fill-pay-online"
                                                    data-fill-height="<?php echo $onlinePct; ?>"></span>
                                            </div>
                                        </div>
                                        <span class="admin-chart-label <?php echo $isToday ? 'is-today' : ''; ?>">
                                            <?php echo htmlspecialchars($day['short'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="admin-chart-legend">
                            <span class="admin-chart-legend-item">
                                <span class="admin-chart-legend-swatch admin-chart-legend-swatch-pay-cod"></span>
                                Cash on Delivery
                            </span>
                            <span class="admin-chart-legend-item">
                                <span class="admin-chart-legend-swatch admin-chart-legend-swatch-pay-wallet"></span>
                                Wallet
                            </span>
                            <span class="admin-chart-legend-item">
                                <span class="admin-chart-legend-swatch admin-chart-legend-swatch-pay-online"></span>
                                Online
                            </span>
                        </div>

                        <div class="admin-chart-summary">
                            <div class="admin-chart-summary-item">
                                <span class="admin-chart-summary-label">Total Orders</span>
                                <span class="admin-chart-summary-value">
                                    <?php echo number_format($paymentTotals['total']); ?>
                                </span>
                                <span class="admin-chart-summary-hint">7-day total</span>
                            </div>
                            <div class="admin-chart-summary-item">
                                <span class="admin-chart-summary-label">Delivered</span>
                                <span class="admin-chart-summary-value">
                                    <?php echo number_format($paymentTotals['delivered']); ?>
                                </span>
                                <span class="admin-chart-summary-hint">7-day total</span>
                            </div>
                            <div class="admin-chart-summary-item">
                                <span class="admin-chart-summary-label">Refund Rate</span>
                                <span class="admin-chart-summary-value">
                                    <?php echo number_format($refundRate, 1); ?>%
                                </span>
                                <span class="admin-chart-summary-hint">
                                    <?php echo number_format($paymentTotals['cancelled']); ?> cancelled
                                </span>
                            </div>
                            <div class="admin-chart-summary-item">
                                <span class="admin-chart-summary-label">Failed</span>
                                <span class="admin-chart-summary-value">
                                    <?php echo number_format($paymentTotals['failed']); ?>
                                </span>
                                <span class="admin-chart-summary-hint">7-day total</span>
                            </div>
                        </div>
                    </div>

                    <!-- ============================================
                         PANEL: Performers
                         ============================================ -->
                    <div class="admin-chart-panel" data-chart-panel="performers" role="tabpanel">

                        <div class="admin-performer-block">
                            <h3 class="admin-performer-heading">Top Restaurants by GMV</h3>

                            <?php if (empty($topRestaurants)): ?>
                            <p class="admin-performer-empty">No orders yet.</p>
                            <?php else: ?>
                            <ul class="admin-performer-list">
                                <?php foreach ($topRestaurants as $idx => $r):
                                    $pct = $maxTopRestaurantGmv > 0
                                        ? ($r['gmv'] / $maxTopRestaurantGmv) * 100
                                        : 0;
                                ?>
                                <li class="admin-performer-item">
                                    <span class="admin-performer-rank"><?php echo $idx + 1; ?></span>
                                    <div class="admin-performer-info">
                                        <div class="admin-performer-top">
                                            <span class="admin-performer-name">
                                                <?php echo htmlspecialchars($r['restaurant_name'], ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                            <span class="admin-performer-value">
                                                <?php echo formatAdminCurrency($r['gmv']); ?>
                                            </span>
                                        </div>
                                        <div class="admin-performer-track">
                                            <span class="admin-performer-bar admin-performer-bar-restaurant"
                                                data-bar-width="<?php echo $pct; ?>"></span>
                                        </div>
                                        <span class="admin-performer-meta">
                                            <?php echo number_format($r['order_count']); ?> orders ·
                                            <?php echo formatAdminCurrency($r['platform_fees']); ?> platform fees
                                        </span>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                        </div>

                        <div class="admin-performer-block">
                            <h3 class="admin-performer-heading">Top Riders by Completed Deliveries</h3>

                            <?php if (empty($topRiders)): ?>
                            <p class="admin-performer-empty">No deliveries yet.</p>
                            <?php else: ?>
                            <ul class="admin-performer-list">
                                <?php foreach ($topRiders as $idx => $r):
                                    $pct = $maxTopRiderDeliveries > 0
                                        ? ($r['deliveries'] / $maxTopRiderDeliveries) * 100
                                        : 0;
                                ?>
                                <li class="admin-performer-item">
                                    <span class="admin-performer-rank"><?php echo $idx + 1; ?></span>
                                    <div class="admin-performer-info">
                                        <div class="admin-performer-top">
                                            <span class="admin-performer-name">
                                                <?php echo htmlspecialchars($r['rider_name'], ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                            <span class="admin-performer-value">
                                                <?php echo number_format($r['deliveries']); ?> deliveries
                                            </span>
                                        </div>
                                        <div class="admin-performer-track">
                                            <span class="admin-performer-bar admin-performer-bar-rider"
                                                data-bar-width="<?php echo $pct; ?>"></span>
                                        </div>
                                        <span class="admin-performer-meta">
                                            <?php echo number_format($r['avg_rating'], 1); ?> ★ average rating
                                        </span>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                        </div>

                    </div>

                </div>
            </section>

            <!-- ============================================
                 PENDING RIDERS CARD
                 ============================================ -->
            <section class="admin-card" aria-labelledby="admin-pending-title">
                <div class="admin-card-header">
                    <h2 class="heading-5" id="admin-pending-title">Pending Riders</h2>
                    <?php if ($stats['pending_riders'] > 0): ?>
                    <a href="riders.php?status=pending" class="admin-card-link">View All</a>
                    <?php endif; ?>
                </div>

                <?php
                $pendingRidersData = getRidersPaginated(
                    $database_connection,
                    1,
                    5,
                    '',
                    'pending',
                    false
                );
                $pendingRiders = $pendingRidersData['rows'];
                ?>

                <?php if (empty($pendingRiders)): ?>
                <div class="admin-empty-state">
                    <div class="admin-empty-icon" aria-hidden="true">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/verified-fill.svg" alt="">
                    </div>
                    <p class="admin-empty-title">All caught up</p>
                    <p class="admin-empty-text">No riders are awaiting verification.</p>
                </div>
                <?php else: ?>
                <div class="admin-pending-list">
                    <?php foreach ($pendingRiders as $r):
                        $riderId = (int)$r['delivery_rider_id'];
                        $riderName = adminName($r);
                        $initial = adminInitial($r);
                        $pic = (string)($r['profile_picture'] ?? '');
                        $picUrl = $pic !== '' ? adminAssetUrl($assetBase, $pic) : '';
                    ?>
                    <a href="riders.php?status=pending&amp;open=<?php echo $riderId; ?>" class="admin-pending-row">
                        <div class="admin-pending-avatar">
                            <?php if ($picUrl !== ''): ?>
                            <img src="<?php echo htmlspecialchars($picUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                            <?php else: ?>
                            <?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?>
                            <?php endif; ?>
                        </div>
                        <div class="admin-pending-info">
                            <p class="admin-pending-name">
                                <?php echo htmlspecialchars($riderName, ENT_QUOTES, 'UTF-8'); ?></p>
                            <p class="admin-pending-meta">
                                <?php echo htmlspecialchars((string)($r['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                        </div>
                        <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-right-s-line.svg" alt=""
                            class="admin-pending-arrow" width="18" height="18">
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>

        </div>

    </div>
</div>

<script src="../assets/ui/js/admin-modal.js" defer></script>
<script src="../assets/ui/js/dashboard.js" defer></script>
<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>