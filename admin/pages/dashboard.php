<?php
/**
 * FitPal Admin Dashboard
 *
 * Overview of platform health and the moderation queue.
 *
 * All SQL lives in admin-queries.php. This page contains no SQL,
 * no inline CSS, and no inline JS. Per-bar chart geometry travels
 * as data-bar-height and is applied by dashboard.js on load, so no
 * style attribute ever appears in the markup.
 *
 * @package FitPal
 * @version 4.2 — Removed the last inline style attribute. Chart bar
 *                heights now travel as data-bar-height and are
 *                applied by dashboard.js.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['administrator_id'])) {
    header('Location: sign-in.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../backend/database/admin-queries.php';

$adminId = (int)$_SESSION['administrator_id'];
$adminProfile = getAdminProfile($database_connection, $adminId) ?: [];
$firstName = (string)($adminProfile['first_name'] ?? 'Admin');

$stats = getAdminDashboardStats($database_connection);

$weeklyRevenue = getAdminWeeklyRevenue($database_connection, 7);

$weeklyMax = 0.0;
foreach ($weeklyRevenue as $d) {
    if ($d['amount'] > $weeklyMax) $weeklyMax = $d['amount'];
}
$chartScale   = getAdminChartScale($weeklyMax);
$chartCeiling = $chartScale['ceiling'];
$today        = date('Y-m-d');

$barHeights = [];
foreach ($weeklyRevenue as $d) {
    $pct = $chartCeiling > 0 ? ($d['amount'] / $chartCeiling) * 100 : 0;
    $barHeights[$d['date']] = $d['amount'] > 0 ? max(4, min(100, $pct)) : 0;
}

$pendingRidersData = getRidersPaginated($database_connection, 1, 5, '', 'pending');
$pendingRiders = $pendingRidersData['rows'];

$recentActivity = getRecentVerificationActivity($database_connection, 6);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
?>

<div class="content admin-dashboard-page">
    <div class="container">

        <header class="admin-dashboard-header">
            <div class="admin-dashboard-greeting">
                <h1 class="heading-2">
                    Welcome back, <span><?php echo htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'); ?></span>
                </h1>
                <p class="text-muted">Platform health and the moderation queue at a glance.</p>
            </div>
            <div class="admin-dashboard-actions">
                <a href="customers.php" class="btn btn-outline btn-sm">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/people-team.svg" alt="" class="btn-icon"
                        width="16" height="16">
                    <span>Customers</span>
                </a>
                <a href="riders.php" class="btn btn-outline btn-sm">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/riding-fill.svg" alt="" class="btn-icon"
                        width="16" height="16">
                    <span>Riders</span>
                </a>
                <a href="restaurants.php" class="btn btn-primary btn-sm">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt="" class="btn-icon"
                        width="16" height="16">
                    <span>Restaurants</span>
                </a>
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
                    <p class="admin-stat-number"><?php echo formatAdminCurrency($stats['gross_revenue']); ?></p>
                    <p class="admin-stat-label">Gross Revenue</p>
                    <p class="admin-stat-hint">
                        <?php echo number_format($stats['orders_this_week']); ?> orders this week
                    </p>
                </div>
            </div>

        </section>

        <div class="admin-dashboard-row admin-dashboard-row-primary">

            <section class="admin-card" aria-labelledby="admin-chart-title">
                <div class="admin-card-header">
                    <h2 class="heading-5" id="admin-chart-title">Revenue — Last 7 Days</h2>
                </div>

                <div class="admin-chart-body">
                    <div class="admin-weekly-chart" role="img"
                        aria-label="Bar chart of revenue over the last seven days">
                        <div class="admin-chart-y-axis" aria-hidden="true">
                            <?php foreach (array_reverse($chartScale['gridlines']) as $grid): ?>
                            <span class="admin-chart-y-label">₱<?php echo number_format($grid, 0); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="admin-chart-plot">
                            <?php foreach ($chartScale['gridlines'] as $grid): ?>
                            <div class="admin-chart-gridline" aria-hidden="true"></div>
                            <?php endforeach; ?>
                            <div class="admin-chart-columns">
                                <?php foreach ($weeklyRevenue as $day):
                                    $pct = $barHeights[$day['date']];
                                    $isToday = ($day['date'] === $today);
                                    $hasValue = $day['amount'] > 0;
                                ?>
                                <div class="admin-chart-column"
                                    data-day="<?php echo htmlspecialchars($day['short'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-amount="<?php echo htmlspecialchars(formatAdminCurrency($day['amount']), ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="admin-chart-bar-track">
                                        <div class="admin-chart-bar <?php echo $isToday ? 'is-today' : ''; ?> <?php echo $hasValue ? '' : 'is-empty'; ?>"
                                            data-bar-height="<?php echo $pct; ?>" tabindex="0"
                                            aria-label="<?php echo htmlspecialchars($day['label'] . ' ' . formatAdminCurrency($day['amount']), ENT_QUOTES, 'UTF-8'); ?>">
                                        </div>
                                    </div>
                                    <span class="admin-chart-label <?php echo $isToday ? 'is-today' : ''; ?>">
                                        <?php echo htmlspecialchars($day['short'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="admin-chart-tooltip" id="adminChartTooltip" role="status" aria-live="polite"></div>
                    </div>

                    <div class="admin-chart-summary">
                        <div class="admin-chart-summary-item">
                            <span class="admin-chart-summary-label">Today</span>
                            <span class="admin-chart-summary-value">
                                <?php echo formatAdminCurrency($stats['revenue_today']); ?>
                            </span>
                            <span class="admin-chart-summary-hint">
                                <?php echo number_format($stats['orders_today']); ?> orders
                            </span>
                        </div>
                        <div class="admin-chart-summary-item">
                            <span class="admin-chart-summary-label">This Week</span>
                            <span class="admin-chart-summary-value">
                                <?php echo formatAdminCurrency($stats['revenue_this_week']); ?>
                            </span>
                            <span class="admin-chart-summary-hint">
                                <?php echo number_format($stats['orders_this_week']); ?> orders
                            </span>
                        </div>
                        <div class="admin-chart-summary-item">
                            <span class="admin-chart-summary-label">All Time</span>
                            <span class="admin-chart-summary-value">
                                <?php echo formatAdminCurrency($stats['gross_revenue']); ?>
                            </span>
                            <span class="admin-chart-summary-hint">
                                <?php echo number_format($stats['total_orders']); ?> orders
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="admin-card" aria-labelledby="admin-pending-title">
                <div class="admin-card-header">
                    <h2 class="heading-5" id="admin-pending-title">Pending Riders</h2>
                    <?php if ($stats['pending_riders'] > 0): ?>
                    <a href="riders.php?status=pending" class="admin-card-link">View All</a>
                    <?php endif; ?>
                </div>

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

        <section class="admin-card" aria-labelledby="admin-activity-title">
            <div class="admin-card-header">
                <h2 class="heading-5" id="admin-activity-title">Recent Moderation Activity</h2>
            </div>

            <?php if (empty($recentActivity)): ?>
            <div class="admin-empty-state">
                <div class="admin-empty-icon" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/time-update.svg" alt="">
                </div>
                <p class="admin-empty-title">No moderation activity yet</p>
                <p class="admin-empty-text">Verification decisions will appear here.</p>
            </div>
            <?php else: ?>
            <div class="admin-activity-list">
                <?php foreach ($recentActivity as $a):
                    $kind      = (string)$a['entity_type'];
                    $entityId  = (int)$a['entity_id'];
                    $entityName = (string)$a['entity_name'];
                    $status    = (string)$a['verification_status'];
                    $when      = (string)$a['verified_at'];

                    $openUrl = $kind === 'rider'
                        ? 'riders.php?open=' . $entityId
                        : 'restaurants.php?open=' . $entityId;

                    $iconFile = $kind === 'rider'
                        ? 'order.svg'
                        : 'restaurant.svg';

                    $statusBadge = adminVerificationBadgeClass($status);
                    $statusLabel = adminVerificationLabel($status);
                ?>
                <a href="<?php echo htmlspecialchars($openUrl, ENT_QUOTES, 'UTF-8'); ?>" class="admin-activity-row">
                    <div
                        class="admin-activity-icon admin-activity-icon-<?php echo htmlspecialchars($kind, ENT_QUOTES, 'UTF-8'); ?>">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/<?php echo htmlspecialchars($iconFile, ENT_QUOTES, 'UTF-8'); ?>"
                            alt="" width="18" height="18">
                    </div>
                    <div class="admin-activity-body">
                        <p class="admin-activity-name">
                            <?php echo htmlspecialchars($entityName, ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                        <p class="admin-activity-meta">
                            <?php echo $kind === 'rider' ? 'Rider' : 'Restaurant'; ?>
                            &middot;
                            <?php echo htmlspecialchars(formatAdminDate($when), ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                    </div>
                    <span class="badge <?php echo $statusBadge; ?>">
                        <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

    </div>
</div>

<script src="../assets/ui/js/dashboard.js" defer></script>
<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>