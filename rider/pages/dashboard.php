<?php
/**
 * FitPal Rider Dashboard
 *
 * Comprehensive dashboard for the rider role showing:
 *   - Welcome header with verification status
 *   - Key performance stats (earnings, deliveries, rating, completion rate)
 *   - Weekly earnings chart
 *   - Recent deliveries list
 *   - Availability toggle
 *   - Verification status card
 *
 * All SQL lives in rider-queries.php. This page contains no SQL.
 *
 * @package FitPal
 * @version 2.0 — Full analytics dashboard
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['delivery_rider_id'])) {
    header('Location: sign-in.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../backend/database/rider-queries.php';

$riderId = (int)$_SESSION['delivery_rider_id'];

// ============================================
// FETCH ALL DASHBOARD DATA
// ============================================
$profile      = getRiderProfile($database_connection, $riderId) ?: [];
$stats        = getRiderDashboardStats($database_connection, $riderId);
$weeklyEarnings = getRiderWeeklyEarnings($database_connection, $riderId);
$recentDeliveries = getRiderRecentDeliveries($database_connection, $riderId, 5);
$chartScale   = getRiderChartScale($stats['week_earnings_max'] ?? 0);

// ============================================
// DERIVED VIEW DATA
// ============================================
$firstName  = (string)($profile['first_name'] ?? 'Rider');
$fullName   = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));
$balance    = (float)($profile['balance'] ?? 0);
$rating     = (float)($profile['average_rating'] ?? 0);
$deliveries = (int)($profile['total_deliveries'] ?? 0);
$vehicle    = (string)($profile['vehicle_type'] ?? '—');
$plate      = (string)($profile['vehicle_plate'] ?? '');
$status     = (string)($profile['verification_status'] ?? 'pending');
$available  = (int)($profile['is_available'] ?? 0) === 1;

$statusLabel = match ($status) {
    'verified'  => 'Verified',
    'pending'   => 'Pending Verification',
    'denied'    => 'Denied',
    'suspended' => 'Suspended',
    default     => ucfirst($status),
};

$statusClass = match ($status) {
    'verified'  => 'badge-success',
    'pending'   => 'badge-warning',
    'denied'    => 'badge-danger',
    'suspended' => 'badge-secondary',
    default     => 'badge-secondary',
};

// Stats
$todayEarnings    = (float)($stats['today_earnings'] ?? 0);
$todayDeliveries  = (int)($stats['today_deliveries'] ?? 0);
$weekEarnings     = (float)($stats['week_earnings'] ?? 0);
$weekDeliveries   = (int)($stats['week_deliveries'] ?? 0);
$monthEarnings    = (float)($stats['month_earnings'] ?? 0);
$monthDeliveries  = (int)($stats['month_deliveries'] ?? 0);
$totalEarnings    = (float)($stats['total_earnings'] ?? 0);
$acceptanceRate   = (float)($stats['acceptance_rate'] ?? 0);
$completionRate   = (float)($stats['completion_rate'] ?? 0);

// Chart data
$chartCeiling = $chartScale['ceiling'];
$chartStep    = $chartScale['step'];

// Bar heights as percentages
$barHeights = [];
foreach ($weeklyEarnings as $day) {
    $pct = $chartCeiling > 0 ? ($day['amount'] / $chartCeiling) * 100 : 0;
    $barHeights[$day['date']] = $day['amount'] > 0
        ? max(4, min(100, $pct))
        : 0;
}

// Today's date for chart highlighting
$today = date('Y-m-d');

// CSRF for availability toggle
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
?>

<div class="content rider-dashboard-page">
    <div class="container">

        <!-- ============================================
             HEADER
             ============================================ -->
        <header class="rider-dashboard-header">
            <div class="rider-dashboard-greeting">
                <h1 class="heading-2">
                    Welcome back, <span><?php echo htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'); ?></span>
                </h1>
                <p class="text-muted">
                    <?php if ($available): ?>
                    You're online and ready to accept deliveries.
                    <?php else: ?>
                    You're currently offline. Go online to start accepting deliveries.
                    <?php endif; ?>
                </p>
            </div>
            <div class="rider-dashboard-actions">
                <span class="badge <?php echo $statusClass; ?>">
                    <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <button type="button" class="btn <?php echo $available ? 'btn-outline' : 'btn-primary'; ?> btn-sm"
                    id="availabilityToggle" data-available="<?php echo $available ? '1' : '0'; ?>"
                    data-csrf="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if ($available): ?>
                    <img src="<?php echo $assetBase; ?>assets/images/icons/close-circle-line.svg" alt=""
                        class="btn-icon" width="16" height="16">
                    <span>Go Offline</span>
                    <?php else: ?>
                    <img src="<?php echo $assetBase; ?>assets/images/icons/check-line.svg" alt="" class="btn-icon"
                        width="16" height="16">
                    <span>Go Online</span>
                    <?php endif; ?>
                </button>
            </div>
        </header>

        <!-- ============================================
             FLASH MESSAGES
             ============================================ -->
        <?php if (isset($_SESSION['rider_success'])): ?>
        <div class="alert alert-success" role="alert">
            <?php echo htmlspecialchars($_SESSION['rider_success'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['rider_success']); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['rider_error'])): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo htmlspecialchars($_SESSION['rider_error'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['rider_error']); ?>
        </div>
        <?php endif; ?>

        <!-- ============================================
             STAT CARDS ROW
             ============================================ -->
        <section class="rider-stats-grid" aria-label="Performance summary">
            <!-- Wallet Balance -->
            <a href="earnings.php" class="rider-stat-card">
                <div class="rider-stat-icon rider-stat-icon-wallet">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/wallet-fill.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/coin-line.svg'">
                </div>
                <div class="rider-stat-info">
                    <p class="rider-stat-number"><?php echo formatRiderCurrency($balance); ?></p>
                    <p class="rider-stat-label">Wallet Balance</p>
                </div>
                <span class="rider-stat-arrow" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-right-s-line.svg" alt=""
                        class="rider-stat-arrow-img" width="18" height="18">
                </span>
            </a>

            <!-- Total Deliveries -->
            <a href="deliveries.php" class="rider-stat-card">
                <div class="rider-stat-icon rider-stat-icon-deliveries">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/package.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/order.svg'">
                </div>
                <div class="rider-stat-info">
                    <p class="rider-stat-number"><?php echo number_format($deliveries); ?></p>
                    <p class="rider-stat-label">Total Deliveries</p>
                    <?php if ($todayDeliveries > 0): ?>
                    <p class="rider-stat-hint rider-stat-hint-active">
                        +<?php echo $todayDeliveries; ?> today
                    </p>
                    <?php endif; ?>
                </div>
                <span class="rider-stat-arrow" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-right-s-line.svg" alt=""
                        class="rider-stat-arrow-img" width="18" height="18">
                </span>
            </a>

            <!-- Average Rating -->
            <div class="rider-stat-card">
                <div class="rider-stat-icon rider-stat-icon-rating">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/star-fill.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/star-empty.svg'">
                </div>
                <div class="rider-stat-info">
                    <p class="rider-stat-number"><?php echo number_format($rating, 1); ?> <span
                            class="rider-stat-number-small">/ 5.0</span></p>
                    <p class="rider-stat-label">Average Rating</p>
                    <p class="rider-stat-hint">Based on customer feedback</p>
                </div>
            </div>

            <!-- Today's Earnings -->
            <div class="rider-stat-card">
                <div class="rider-stat-icon rider-stat-icon-today">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/coin-line.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/wallet-line.svg'">
                </div>
                <div class="rider-stat-info">
                    <p class="rider-stat-number"><?php echo formatRiderCurrency($todayEarnings); ?></p>
                    <p class="rider-stat-label">Today's Earnings</p>
                    <?php if ($todayDeliveries > 0): ?>
                    <p class="rider-stat-hint">
                        <?php echo $todayDeliveries; ?>
                        deliver<?php echo $todayDeliveries === 1 ? 'y' : 'ies'; ?>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- ============================================
             TWO-COLUMN ROW: Chart + Performance
             ============================================ -->
        <div class="rider-dashboard-row rider-dashboard-row-primary">

            <!-- Weekly Earnings Chart -->
            <section class="rider-card rider-chart-card" aria-labelledby="chart-title">
                <div class="rider-card-header">
                    <h2 class="heading-5" id="chart-title">Earnings - Last 7 Days</h2>
                </div>

                <div class="rider-chart-body">
                    <div class="rider-weekly-chart" role="img"
                        aria-label="Bar chart of earnings over the last seven days">
                        <!-- Y-axis labels -->
                        <div class="rider-chart-y-axis" aria-hidden="true">
                            <?php foreach (array_reverse($chartScale['gridlines']) as $grid): ?>
                            <span class="rider-chart-y-label">₱<?php echo number_format($grid, 0); ?></span>
                            <?php endforeach; ?>
                        </div>

                        <div class="rider-chart-plot">
                            <!-- Gridlines -->
                            <?php foreach ($chartScale['gridlines'] as $grid): ?>
                            <div class="rider-chart-gridline" aria-hidden="true"></div>
                            <?php endforeach; ?>

                            <!-- Bars -->
                            <div class="rider-chart-columns">
                                <?php foreach ($weeklyEarnings as $day):
                                    $pct     = $barHeights[$day['date']];
                                    $isToday = ($day['date'] === $today);
                                    $hasValue = $day['amount'] > 0;
                                ?>
                                <div class="rider-chart-column"
                                    data-day="<?php echo htmlspecialchars($day['short'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-amount="<?php echo htmlspecialchars(formatRiderCurrency($day['amount']), ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="rider-chart-bar-track">
                                        <div class="rider-chart-bar <?php echo $isToday ? 'is-today' : ''; ?> <?php echo $hasValue ? '' : 'is-empty'; ?>"
                                            style="height: <?php echo $pct; ?>%" tabindex="0"
                                            aria-label="<?php echo htmlspecialchars($day['label'] . ' ' . formatRiderCurrency($day['amount']), ENT_QUOTES, 'UTF-8'); ?>">
                                        </div>
                                    </div>
                                    <span class="rider-chart-label <?php echo $isToday ? 'is-today' : ''; ?>">
                                        <?php echo htmlspecialchars($day['short'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Floating tooltip -->
                        <div class="rider-chart-tooltip" id="riderChartTooltip" role="status" aria-live="polite"></div>
                    </div>

                    <!-- Summary strip -->
                    <div class="rider-chart-summary">
                        <div class="rider-chart-summary-item">
                            <span class="rider-chart-summary-label">This Week</span>
                            <span
                                class="rider-chart-summary-value"><?php echo formatRiderCurrency($weekEarnings); ?></span>
                            <?php if ($weekDeliveries > 0): ?>
                            <span class="rider-chart-summary-hint">
                                <?php echo $weekDeliveries; ?>
                                deliver<?php echo $weekDeliveries === 1 ? 'y' : 'ies'; ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="rider-chart-summary-item">
                            <span class="rider-chart-summary-label">Last 30 Days</span>
                            <span
                                class="rider-chart-summary-value"><?php echo formatRiderCurrency($monthEarnings); ?></span>
                            <?php if ($monthDeliveries > 0): ?>
                            <span class="rider-chart-summary-hint">
                                <?php echo $monthDeliveries; ?>
                                deliver<?php echo $monthDeliveries === 1 ? 'y' : 'ies'; ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="rider-chart-summary-item">
                            <span class="rider-chart-summary-label">All Time</span>
                            <span
                                class="rider-chart-summary-value"><?php echo formatRiderCurrency($totalEarnings); ?></span>
                            <span class="rider-chart-summary-hint">Total earnings</span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Performance Metrics -->
            <aside class="rider-card rider-performance-card" aria-labelledby="performance-title">
                <div class="rider-card-header">
                    <h2 class="heading-5" id="performance-title">Performance</h2>
                </div>

                <div class="rider-performance-body">
                    <!-- Acceptance Rate -->
                    <div class="rider-performance-item">
                        <div class="rider-performance-header">
                            <span class="rider-performance-label">Acceptance Rate</span>
                            <span
                                class="rider-performance-value"><?php echo number_format($acceptanceRate, 0); ?>%</span>
                        </div>
                        <div class="rider-performance-bar">
                            <div class="rider-performance-bar-fill"
                                style="width: <?php echo min(100, max(0, $acceptanceRate)); ?>%"></div>
                        </div>
                        <p class="rider-performance-hint">Orders accepted vs. offered</p>
                    </div>

                    <!-- Completion Rate -->
                    <div class="rider-performance-item">
                        <div class="rider-performance-header">
                            <span class="rider-performance-label">Completion Rate</span>
                            <span
                                class="rider-performance-value"><?php echo number_format($completionRate, 0); ?>%</span>
                        </div>
                        <div class="rider-performance-bar">
                            <div class="rider-performance-bar-fill rider-performance-bar-fill-success"
                                style="width: <?php echo min(100, max(0, $completionRate)); ?>%"></div>
                        </div>
                        <p class="rider-performance-hint">Deliveries completed vs. started</p>
                    </div>

                    <!-- Vehicle Info -->
                    <div class="rider-performance-vehicle">
                        <span class="rider-performance-vehicle-label">Vehicle</span>
                        <div class="rider-performance-vehicle-info">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt=""
                                class="rider-performance-vehicle-icon"
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/community-general.svg'">
                            <div>
                                <p class="rider-performance-vehicle-name">
                                    <?php echo htmlspecialchars(ucfirst($vehicle), ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                                <p class="rider-performance-vehicle-plate">
                                    <?php echo $plate !== '' ? htmlspecialchars($plate, ENT_QUOTES, 'UTF-8') : 'No plate'; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </aside>
        </div>

        <!-- ============================================
             RECENT DELIVERIES
             ============================================ -->
        <section class="rider-card rider-deliveries-card" aria-labelledby="deliveries-title">
            <div class="rider-card-header">
                <h2 class="heading-5" id="deliveries-title">Recent Deliveries</h2>
                <a href="deliveries.php" class="rider-card-link">View All</a>
            </div>

            <?php if (empty($recentDeliveries)): ?>
            <div class="rider-empty-state">
                <div class="rider-empty-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/package.svg" alt="No deliveries"
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/order.svg'">
                </div>
                <p class="rider-empty-title">No deliveries yet</p>
                <p class="rider-empty-text">
                    <?php if ($available): ?>
                    You're online. New delivery requests will appear here.
                    <?php else: ?>
                    Go online to start receiving delivery requests.
                    <?php endif; ?>
                </p>
            </div>
            <?php else: ?>
            <div class="rider-deliveries-list">
                <?php foreach ($recentDeliveries as $delivery):
                    $orderId    = (int)($delivery['order_id'] ?? 0);
                    $customerName = (string)($delivery['customer_name'] ?? 'Customer');
                    $destination  = (string)($delivery['destination_address'] ?? '');
                    $deliveredAt  = (string)($delivery['delivered_at'] ?? '');
                    $orderTotal   = (float)($delivery['order_total'] ?? 0);
                    $riderEarning = (float)($delivery['rider_earning'] ?? 0);

                    // Format delivery time
                    $deliveredTime = '';
                    if ($deliveredAt !== '') {
                        $ts = strtotime($deliveredAt);
                        if ($ts !== false) {
                            $deliveredTime = date('M d, g:i A', $ts);
                        }
                    }
                ?>
                <div class="rider-delivery-row">
                    <div class="rider-delivery-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/check-circle-fill.svg" alt="Delivered"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/verified-fill.svg'">
                    </div>
                    <div class="rider-delivery-info">
                        <p class="rider-delivery-order">Order #<?php echo $orderId; ?></p>
                        <p class="rider-delivery-customer">
                            <?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                        <p class="rider-delivery-address">
                            <?php echo htmlspecialchars(truncateText($destination, 50), ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                    </div>
                    <div class="rider-delivery-meta">
                        <p class="rider-delivery-time">
                            <?php echo htmlspecialchars($deliveredTime, ENT_QUOTES, 'UTF-8'); ?></p>
                        <p class="rider-delivery-earning">
                            +<?php echo formatRiderCurrency($riderEarning); ?>
                        </p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        <!-- ============================================
             VERIFICATION WARNING (if not verified)
             ============================================ -->
        <?php if ($status !== 'verified'): ?>
        <section class="rider-card rider-card-warning">
            <div class="rider-card-body rider-warning-body">
                <div class="rider-warning-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg" alt="Warning"
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/information-fill.svg'">
                </div>
                <div class="rider-warning-content">
                    <p class="rider-warning-title">
                        <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <p class="rider-warning-text">
                        <?php if ($status === 'pending'): ?>
                        Your account is under review. You'll be notified once verification is complete.
                        <?php elseif ($status === 'denied'): ?>
                        Your application was denied. Please contact support for more information.
                        <?php elseif ($status === 'suspended'): ?>
                        Your account has been suspended. Please contact support.
                        <?php endif; ?>
                    </p>
                </div>
                <a href="<?php echo $assetBase; ?>pages/contact.php" class="btn btn-outline btn-sm rider-warning-btn">
                    Contact Support
                </a>
            </div>
        </section>
        <?php endif; ?>

    </div>
</div>

<script>
window.FITPAL_RIDER = {
    csrfToken: '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>',
    assetBase: '<?php echo $assetBase; ?>'
};
</script>
<script src="../assets/ui/js/dashboard.js" defer></script>

<?php
require_once __DIR__ . '/../../shared/includes/footer.php';
?>