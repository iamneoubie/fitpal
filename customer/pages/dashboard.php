<?php
/**
 * FitPal Customer Dashboard
 *
 * Order summary, weekly spend analytics, recent orders, and a compact
 * profile snapshot. All SQL lives in dashboard-queries.php.
 *
 * ---------------------------------------------------------------------
 * SCOPE RULES APPLIED
 * ---------------------------------------------------------------------
 *  - No SQL in this file.
 *  - No inline CSS. dashboard.css is loaded via the customer header's
 *    $pageCssMap.
 *  - formatCurrency() comes from customer-queries.php. It is NOT
 *    declared here.
 * ---------------------------------------------------------------------
 *
 * @package FitPal
 * @version 4.0 — Local formatCurrency() removed; uses the query-layer
 *                version.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    header('Location: sign-in.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../backend/database/customer-queries.php';
require_once __DIR__ . '/../backend/database/dashboard-queries.php';

$customerId = (int)$_SESSION['customer_id'];
$userName   = $_SESSION['user_name'] ?? 'Customer';

// ---------------------------------------------------------------------
// DATA
// ---------------------------------------------------------------------
$counts       = ['total_orders' => 0, 'active_orders' => 0, 'wallet_balance' => 0.0];
$spend        = [
    'weekly_spend' => 0.0, 'weekly_order_count' => 0,
    'monthly_spend' => 0.0, 'monthly_order_count' => 0,
    'delivered_total' => 0.0, 'delivered_count' => 0,
    'average_order_value' => 0.0,
];
$weeklySeries = [];
$recentOrders = [];
$profile      = [];

try {
    $counts       = getDashboardCounts($database_connection, $customerId);
    $spend        = getDashboardSpendSummary($database_connection, $customerId);
    $weeklySeries = getWeeklySpendingSeries($database_connection, $customerId, 7);
    $recentOrders = getRecentOrdersWithTotals($database_connection, $customerId, 3);
    $profile      = getDashboardProfileSnapshot($database_connection, $customerId);
} catch (PDOException $e) {
    error_log('Dashboard query error: ' . $e->getMessage());
}

// ---------------------------------------------------------------------
// VIEW HELPERS (page-local; no DB access)
// ---------------------------------------------------------------------

function getStatusBadgeClass(string $status): string
{
    return match ($status) {
        'pending'    => 'badge-warning',
        'preparing'  => 'badge-info',
        'delivering' => 'badge-primary',
        'delivered'  => 'badge-success',
        'cancelled'  => 'badge-danger',
        'refunded'   => 'badge-secondary',
        default      => 'badge-secondary',
    };
}

function getStatusLabel(string $status): string
{
    return match ($status) {
        'pending'    => 'Pending',
        'preparing'  => 'Preparing',
        'delivering' => 'Delivering',
        'delivered'  => 'Delivered',
        'cancelled'  => 'Cancelled',
        'refunded'   => 'Refunded',
        default      => ucfirst($status),
    };
}

function formatOrderDate(string $date): string
{
    $ts = strtotime($date);
    return $ts !== false ? date('M d, g:i A', $ts) : $date;
}

function parseCsvTags(?string $raw): array
{
    if ($raw === null || trim($raw) === '') return [];
    return array_values(array_filter(
        array_map('trim', explode(',', $raw)),
        static fn($v) => $v !== '' && strtolower($v) !== 'none'
    ));
}

// Derived view data
$dietaryTags = parseCsvTags($profile['dietary_preferences'] ?? null);
$allergyTags = parseCsvTags($profile['allergies'] ?? null);
$fitnessGoal = (string)($profile['fitness_goal'] ?? '');
$fullName    = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));

$fitnessGoalLabel = match ($fitnessGoal) {
    'weight_loss' => 'Weight Loss',
    'muscle_gain' => 'Muscle Gain',
    'maintenance' => 'Maintenance',
    default       => 'Not set',
};

// Chart math
$weeklyMax = 0.0;
foreach ($weeklySeries as $d) {
    if ($d['amount'] > $weeklyMax) $weeklyMax = $d['amount'];
}
$scale    = getChartScale($weeklyMax);
$ceiling  = $scale['ceiling'];
$step     = $scale['step'];

// Bar heights as percentages of the ceiling
$barHeights = [];
foreach ($weeklySeries as $d) {
    $pct = $ceiling > 0 ? ($d['amount'] / $ceiling) * 100 : 0;
    $barHeights[$d['date']] = $d['amount'] > 0
        ? max(4, min(100, $pct))
        : 0;
}

// Week-over-month share
$weekShareOfMonth = ($spend['monthly_spend'] ?? 0) > 0
    ? (int)round(($spend['weekly_spend'] / $spend['monthly_spend']) * 100)
    : 0;
?>

<div class="content dashboard-page">
    <div class="container">

        <!-- ============================================
             HEADER
             ============================================ -->
        <header class="dashboard-header">
            <div class="dashboard-greeting">
                <h1 class="heading-2">
                    Welcome back, <span><?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?></span>
                </h1>
                <p class="text-muted">Here's a quick look at your account activity.</p>
            </div>
            <div class="dashboard-actions">
                <a href="menu.php" class="btn btn-primary">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/add-line.svg" alt="" class="btn-icon"
                        width="18" height="18">
                    <span>Browse Menu</span>
                </a>
            </div>
        </header>

        <!-- ============================================
             STAT CARDS
             ============================================ -->
        <section class="stats-grid" aria-label="Account summary">
            <a href="orders.php" class="stat-card">
                <div class="stat-icon stat-icon-orders" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/cart-shopping.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/package.svg'">
                </div>
                <div class="stat-info">
                    <p class="stat-number"><?php echo number_format($counts['total_orders']); ?></p>
                    <p class="stat-label">Total Orders</p>
                    <?php if ($counts['active_orders'] > 0): ?>
                    <p class="stat-hint stat-hint-active">
                        <?php echo (int)$counts['active_orders']; ?> active now
                    </p>
                    <?php endif; ?>
                </div>
                <span class="stat-arrow" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-right-s-line.svg" alt=""
                        class="stat-arrow-img" width="18" height="18">
                </span>
            </a>

            <a href="wallet.php" class="stat-card">
                <div class="stat-icon stat-icon-balance" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/profile.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/cart-shopping.svg'">
                </div>
                <div class="stat-info">
                    <p class="stat-number"><?php echo formatCurrency($counts['wallet_balance']); ?></p>
                    <p class="stat-label">Wallet Balance</p>
                </div>
                <span class="stat-arrow" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-right-s-line.svg" alt=""
                        class="stat-arrow-img" width="18" height="18">
                </span>
            </a>

            <a href="#analytics" class="stat-card">
                <div class="stat-icon stat-icon-week" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/chart-line-up.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/list-view.svg'">
                </div>
                <div class="stat-info">
                    <p class="stat-number"><?php echo formatCurrency($spend['weekly_spend']); ?></p>
                    <p class="stat-label">This Week</p>
                    <?php if ($spend['weekly_order_count'] > 0): ?>
                    <p class="stat-hint">
                        <?php echo (int)$spend['weekly_order_count']; ?>
                        order<?php echo $spend['weekly_order_count'] === 1 ? '' : 's'; ?>
                    </p>
                    <?php endif; ?>
                </div>
                <span class="stat-arrow" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-right-s-line.svg" alt=""
                        class="stat-arrow-img" width="18" height="18">
                </span>
            </a>

            <a href="profile.php" class="stat-card">
                <div class="stat-icon stat-icon-goal" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/target-fill.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/star-empty.svg'">
                </div>
                <div class="stat-info">
                    <p class="stat-number stat-number-text">
                        <?php echo htmlspecialchars($fitnessGoalLabel, ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <p class="stat-label">Fitness Goal</p>
                </div>
                <span class="stat-arrow" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-right-s-line.svg" alt=""
                        class="stat-arrow-img" width="18" height="18">
                </span>
            </a>
        </section>

        <!-- ============================================
             TWO-COLUMN ROW
             ============================================ -->
        <div class="dashboard-row dashboard-row-primary">

            <!-- ANALYTICS -->
            <section class="dashboard-card analytics-card" id="analytics" aria-labelledby="analytics-title">
                <div class="card-header">
                    <h2 class="heading-5" id="analytics-title">Spent - Last 7 Days</h2>
                </div>

                <div class="analytics-body">
                    <div class="weekly-chart" role="img" aria-label="Bar chart of spending over the last seven days">
                        <!-- Y-axis labels -->
                        <div class="chart-y-axis" aria-hidden="true">
                            <?php foreach (array_reverse($scale['gridlines']) as $grid): ?>
                            <span class="chart-y-label">₱<?php echo number_format($grid, 0); ?></span>
                            <?php endforeach; ?>
                        </div>

                        <div class="chart-plot">
                            <!-- Gridlines -->
                            <?php foreach ($scale['gridlines'] as $grid): ?>
                            <div class="chart-gridline" aria-hidden="true"></div>
                            <?php endforeach; ?>

                            <!-- Bars -->
                            <div class="chart-columns">
                                <?php foreach ($weeklySeries as $day):
                                    $pct     = $barHeights[$day['date']];
                                    $isToday = ($day['date'] === date('Y-m-d'));
                                    $hasValue = $day['amount'] > 0;
                                ?>
                                <div class="chart-column"
                                    data-day="<?php echo htmlspecialchars($day['short'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-amount="<?php echo htmlspecialchars(formatCurrency($day['amount']), ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="chart-bar-track">
                                        <div class="chart-bar <?php echo $isToday ? 'is-today' : ''; ?> <?php echo $hasValue ? '' : 'is-empty'; ?>"
                                            style="height: <?php echo $pct; ?>%" tabindex="0"
                                            aria-label="<?php echo htmlspecialchars($day['label'] . ' ' . formatCurrency($day['amount']), ENT_QUOTES, 'UTF-8'); ?>">
                                        </div>
                                    </div>
                                    <span class="chart-label <?php echo $isToday ? 'is-today' : ''; ?>">
                                        <?php echo htmlspecialchars($day['short'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Floating tooltip (positioned by JS) -->
                        <div class="chart-tooltip" id="chartTooltip" role="status" aria-live="polite"></div>
                    </div>

                    <!-- Summary strip -->
                    <div class="analytics-summary">
                        <div class="analytics-item">
                            <span class="analytics-label">This Week</span>
                            <span class="analytics-value"><?php echo formatCurrency($spend['weekly_spend']); ?></span>
                            <?php if ($weekShareOfMonth > 0): ?>
                            <span class="analytics-hint"><?php echo $weekShareOfMonth; ?>% of 30-day spend</span>
                            <?php endif; ?>
                        </div>
                        <div class="analytics-item">
                            <span class="analytics-label">Last 30 Days</span>
                            <span class="analytics-value"><?php echo formatCurrency($spend['monthly_spend']); ?></span>
                            <?php if ($spend['monthly_order_count'] > 0): ?>
                            <span class="analytics-hint">
                                <?php echo (int)$spend['monthly_order_count']; ?>
                                order<?php echo $spend['monthly_order_count'] === 1 ? '' : 's'; ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="analytics-item">
                            <span class="analytics-label">Avg. Order</span>
                            <span
                                class="analytics-value"><?php echo formatCurrency($spend['average_order_value']); ?></span>
                            <span class="analytics-hint">across <?php echo (int)$spend['delivered_count']; ?>
                                delivered</span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- PROFILE SNAPSHOT -->
            <aside class="dashboard-card profile-snapshot-card" aria-labelledby="profile-snapshot-title">
                <div class="card-header">
                    <h2 class="heading-5" id="profile-snapshot-title">Your Profile</h2>
                </div>

                <div class="profile-snapshot">
                    <div class="snapshot-avatar-row">
                        <div class="snapshot-avatar" aria-hidden="true">
                            <?php echo htmlspecialchars(strtoupper(substr($fullName ?: 'U', 0, 1)), ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <div class="snapshot-name-block">
                            <p class="snapshot-name">
                                <?php echo htmlspecialchars($fullName ?: 'Customer', ENT_QUOTES, 'UTF-8'); ?></p>
                            <p class="snapshot-email">
                                <?php echo htmlspecialchars($profile['email'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                    </div>

                    <?php if (!empty($dietaryTags)): ?>
                    <div class="snapshot-section">
                        <span class="snapshot-label">Dietary</span>
                        <div class="snapshot-tags">
                            <?php foreach ($dietaryTags as $tag): ?>
                            <span class="tag tag-diet">
                                <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $tag)), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($allergyTags)): ?>
                    <div class="snapshot-section">
                        <span class="snapshot-label">Allergies</span>
                        <div class="snapshot-tags">
                            <?php foreach ($allergyTags as $tag): ?>
                            <span class="tag tag-allergen">
                                <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $tag)), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="snapshot-section">
                        <span class="snapshot-label">Goal</span>
                        <span
                            class="snapshot-value"><?php echo htmlspecialchars($fitnessGoalLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </div>

                <a href="profile.php" class="btn btn-outline btn-sm snapshot-btn">Edit Profile</a>
            </aside>

        </div>

        <!-- ============================================
             RECENT ORDERS
             ============================================ -->
        <section class="dashboard-card orders-card" aria-labelledby="recent-orders-title">
            <div class="card-header">
                <h2 class="heading-5" id="recent-orders-title">Recent Orders</h2>
                <?php if (!empty($recentOrders)): ?>
                <a href="orders.php" class="card-link">View All</a>
                <?php endif; ?>
            </div>

            <?php if (empty($recentOrders)): ?>
            <div class="empty-state">
                <div class="empty-icon" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/cart-shopping.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/package.svg'">
                </div>
                <p class="empty-title">No orders yet</p>
                <p class="empty-description">Your first order will appear here.</p>
                <a href="menu.php" class="btn btn-primary btn-sm">Browse Menu</a>
            </div>
            <?php else: ?>
            <div class="compact-orders">
                <?php foreach ($recentOrders as $order):
                    $oid    = (int)$order['order_id'];
                    $status = (string)$order['order_status'];
                ?>
                <a href="orders.php" class="compact-order-row">
                    <div class="compact-order-id-block">
                        <span class="compact-order-id">#<?php echo $oid; ?></span>
                        <span class="compact-order-date">
                            <?php echo htmlspecialchars(formatOrderDate($order['order_date']), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                    <span class="badge <?php echo getStatusBadgeClass($status); ?>">
                        <?php echo getStatusLabel($status); ?>
                    </span>
                    <span class="compact-order-total">
                        <?php echo formatCurrency($order['total_amount']); ?>
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