<?php
/**
 * FitPal Rider Dashboard
 *
 * Layout
 * ------
 *   1. Greeting header
 *   2. Four stat cards (wallet, deliveries, rating, today's earnings)
 *   3. Current Work strip
 *        A compact card that answers "what should I do next?":
 *          - Active delivery exists    → link to Deliveries (Active tab)
 *          - Pending assignment exists → link to Deliveries (Assigned tab)
 *          - Neither, rider is online  → "You're clear" status
 *          - Neither, rider is offline → "Go online" reminder
 *          - Not verified              → verification reminder
 *   4. Two-column row: weekly chart | info card
 *   5. Verification warning (only when not verified)
 *
 * Recent Deliveries removed
 * -------------------------
 * The previous version rendered a Recent Deliveries list at the
 * bottom of the dashboard. That list duplicated the deliveries
 * page's History tab, and having two surfaces showing the same
 * closed deliveries made it unclear which one was authoritative.
 * The History tab on deliveries.php is now the single source for
 * closed deliveries; the dashboard no longer touches them.
 *
 * In its place, the Current Work strip answers the question a
 * rider opens the dashboard to ask mid-shift: "what's next?" That
 * is dashboard-specific information — it is not shown on the
 * deliveries page in the same at-a-glance form.
 *
 * Info card (right column)
 * ------------------------
 * The right column used to be a "Performance" card with two rate
 * bars (Acceptance Rate, Completion Rate) and a vehicle block.
 * That mixed two different concerns: numbers that already live on
 * the earnings page, and identity data the rider actually wants
 * at a glance.
 *
 * The card now reads top to bottom:
 *
 *   Profile            header, with a link to profile.php
 *   ├── Name           first + middle + last, concatenated
 *   ├── Email
 *   └── Contact number
 *
 *   Vehicle
 *   ├── Icon + type
 *   └── Plate
 *
 *   Status             verification badge
 *   Availability       online / offline
 *   Total deliveries
 *   Average rating
 *
 * The acceptance-rate and completion-rate bars were removed. They
 * are order-metric surfaces, not identity surfaces, and both
 * numbers are still readable on the earnings page. The two stat
 * cards at the top of the dashboard (Total Deliveries and Average
 * Rating) already cover the same ground, so the bars were
 * duplicating data too.
 *
 * Data sources
 * ------------
 *   - getRiderProfile()            profile, wallet balance, status
 *   - getRiderDashboardStats()     stat-card numbers + chart scale
 *   - getRiderWeeklyEarnings()     chart bars
 *   - getAssignedOrders()          Current Work strip (pending)
 *   - getRiderActiveDeliveries()   Current Work strip (active)
 *
 * Every one of those lives in rider/backend/database/rider-queries.php.
 * This page contains no SQL.
 *
 * @package FitPal
 * @version 4.2 — Right column rebuilt. The Performance card now
 *                shows identity (name, email, contact), vehicle,
 *                and the status meta list. The two metric bars
 *                (Acceptance Rate, Completion Rate) were removed
 *                because they duplicate the earnings page and the
 *                top stat cards. Vehicle icon still maps to the
 *                rider's registered vehicle type.
 *
 *                (4.1: vehicle icon corrected from coin-line.svg
 *                to a type-mapped icon. 4.0: Recent Deliveries
 *                removed, Current Work strip added. 3.6: removed
 *                dashboard-actions block. 3.5: docblock reference
 *                corrected to rider-csrf-token.php. 3.4: local
 *                CSRF block removed; $csrfToken inherited from
 *                header.php under rider_csrf_token.)
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
$profile          = getRiderProfile($database_connection, $riderId) ?: [];
$stats            = getRiderDashboardStats($database_connection, $riderId);
$weeklyEarnings   = getRiderWeeklyEarnings($database_connection, $riderId);
$chartScale       = getRiderChartScale($stats['week_earnings_max'] ?? 0);

$assignedOrders   = getAssignedOrders($database_connection, $riderId);
$activeDeliveries = getRiderActiveDeliveries($database_connection, $riderId);

// ============================================
// DERIVED VIEW DATA
// ============================================
$firstName  = (string)($profile['first_name']  ?? 'Rider');
$middleName = (string)($profile['middle_name'] ?? '');
$lastName   = (string)($profile['last_name']   ?? '');
$email      = (string)($profile['email']       ?? '');
$contact    = (string)($profile['contact_number'] ?? '');

$balance    = (float)($profile['balance'] ?? 0);
$rating     = (float)($profile['average_rating'] ?? 0);
$deliveries = (int)($profile['total_deliveries'] ?? 0);
$vehicle    = (string)($profile['vehicle_type'] ?? '');
$plate      = (string)($profile['vehicle_plate'] ?? '');
$status     = (string)($profile['verification_status'] ?? 'pending');
$available  = (int)($profile['is_available'] ?? 0) === 1;

$isVerified = ($status === 'verified');

// Full display name — first + middle + last, collapsed to single
// spaces, middle omitted when blank.
$fullName = trim(
    preg_replace('/\s+/', ' ', $firstName . ' ' . $middleName . ' ' . $lastName)
);
if ($fullName === '') {
    $fullName = 'Rider';
}

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
$todayEarnings   = (float)($stats['today_earnings'] ?? 0);
$todayDeliveries = (int)($stats['today_deliveries'] ?? 0);
$weekEarnings    = (float)($stats['week_earnings'] ?? 0);
$weekDeliveries  = (int)($stats['week_deliveries'] ?? 0);
$monthEarnings   = (float)($stats['month_earnings'] ?? 0);
$monthDeliveries = (int)($stats['month_deliveries'] ?? 0);
$totalEarnings   = (float)($stats['total_earnings'] ?? 0);

// Chart data
$chartCeiling = $chartScale['ceiling'];

$barHeights = [];
foreach ($weeklyEarnings as $day) {
    $pct = $chartCeiling > 0 ? ($day['amount'] / $chartCeiling) * 100 : 0;
    $barHeights[$day['date']] = $day['amount'] > 0
        ? max(4, min(100, $pct))
        : 0;
}

$today = date('Y-m-d');

// ============================================
// CURRENT WORK
//
// What should the rider do next? Three signals, checked in
// priority order. The first one that matches wins the strip.
// ============================================
$activeCount   = count($activeDeliveries);
$assignedCount = count($assignedOrders);

$currentWork = null;

if (!$isVerified) {
    $currentWork = [
        'kind'   => 'blocked',
        'icon'   => 'error-warning-line.svg',
        'label'  => 'Verification required',
        'title'  => 'Awaiting verification',
        'text'   => 'Your account is ' . strtolower($statusLabel) . '. You cannot go online or accept orders until it is approved.',
    ];
} elseif ($activeCount > 0) {
    $firstActive = $activeDeliveries[0];
    $customerName = (string)($firstActive['customer_name'] ?? 'the customer');

    $currentWork = [
        'kind'   => 'active',
        'icon'   => 'riding-fill.svg',
        'label'  => 'In progress',
        'title'  => $activeCount === 1
            ? 'Delivering to ' . $customerName
            : $activeCount . ' active deliveries',
        'text'   => 'Finish the run and mark it delivered to earn ₱50.00.',
        'href'   => 'deliveries.php?tab=active',
        'cta'    => 'Open Deliveries',
    ];
} elseif ($assignedCount > 0) {
    $currentWork = [
        'kind'   => 'assigned',
        'icon'   => 'package.svg',
        'label'  => 'Action needed',
        'title'  => $assignedCount === 1
            ? '1 assignment waiting'
            : $assignedCount . ' assignments waiting',
        'text'   => 'The kitchen assigned you '
                  . ($assignedCount === 1 ? 'an order' : 'orders')
                  . '. Accept or decline to continue.',
        'href'   => 'deliveries.php?tab=assigned',
        'cta'    => 'Review Assignment' . ($assignedCount === 1 ? '' : 's'),
    ];
} elseif ($available) {
    $currentWork = [
        'kind'   => 'clear',
        'icon'   => 'verified-fill.svg',
        'label'  => 'Standing by',
        'title'  => "You're clear",
        'text'   => 'No active deliveries and no pending assignments. New orders will appear in the assignments panel.',
    ];
} else {
    $currentWork = [
        'kind'   => 'offline',
        'icon'   => 'information-fill.svg',
        'label'  => 'Offline',
        'title'  => "You're offline",
        'text'   => 'Toggle availability from the assignments panel at the bottom of the page to start receiving orders.',
    ];
}

// Vehicle display values
$vehicleLabel = $vehicle !== '' ? ucfirst($vehicle) : 'Not recorded';
$plateLabel   = $plate   !== '' ? $plate            : 'No plate recorded';

// Vehicle icon — matches the tile glyph to the rider's actual
// registered vehicle type.
$vehicleIconMap = [
    'motorcycle' => ['riding-line.svg', 'taxi-line.svg'],
    'scooter'    => ['riding-line.svg', 'taxi-line.svg'],
    'bicycle'    => ['riding-line.svg', 'taxi-line.svg'],
    'car'        => ['car-line.svg',    'taxi-line.svg'],
    'van'        => ['car-line.svg',    'taxi-line.svg'],
];

$vehicleIconPair = $vehicleIconMap[$vehicle] ?? ['car-line.svg', 'taxi-line.svg'];
$vehicleIcon     = $vehicleIconPair[0];
$vehicleIconAlt  = $vehicleIconPair[1];

// Contact display — fall back to an em-dash when not on file.
$contactLabel = $contact !== '' ? $contact : '—';
$emailLabel   = $email   !== '' ? $email   : '—';

// $csrfToken is provided by header.php (rider_csrf_token).
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
                    <?php if (!$isVerified): ?>
                    Your account is <?php echo htmlspecialchars(strtolower($statusLabel), ENT_QUOTES, 'UTF-8'); ?>.
                    You can't go online until your account is verified.
                    <?php elseif ($available): ?>
                    You're online and ready to accept deliveries.
                    <?php else: ?>
                    You're currently offline. Toggle availability from the assignments panel
                    at the bottom of the screen to start accepting deliveries.
                    <?php endif; ?>
                </p>
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
                <div class="rider-stat-icon rider-stat-icon-wallet" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/wallet-line.svg" alt=""
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
            <a href="deliveries.php?tab=history" class="rider-stat-card">
                <div class="rider-stat-icon rider-stat-icon-deliveries" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/order.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/package.svg'">
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
                <div class="rider-stat-icon rider-stat-icon-rating" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/star-fill.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/star-empty.svg'">
                </div>
                <div class="rider-stat-info">
                    <p class="rider-stat-number">
                        <?php echo number_format($rating, 1); ?>
                        <span class="rider-stat-number-small">/ 5.0</span>
                    </p>
                    <p class="rider-stat-label">Average Rating</p>
                </div>
            </div>

            <!-- Today's Earnings -->
            <div class="rider-stat-card">
                <div class="rider-stat-icon rider-stat-icon-today" aria-hidden="true">
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
             CURRENT WORK STRIP
             Answers "what should I do next?" for the rider.
             ============================================ -->
        <section
            class="rider-work-card rider-work-card-<?php echo htmlspecialchars($currentWork['kind'], ENT_QUOTES, 'UTF-8'); ?>"
            aria-label="Current work">
            <div class="rider-work-icon" aria-hidden="true">
                <img src="<?php echo $assetBase; ?>assets/images/icons/<?php echo htmlspecialchars($currentWork['icon'], ENT_QUOTES, 'UTF-8'); ?>"
                    alt=""
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/information-fill.svg'">
            </div>

            <div class="rider-work-body">
                <span class="rider-work-label">
                    <?php echo htmlspecialchars($currentWork['label'], ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <p class="rider-work-title">
                    <?php echo htmlspecialchars($currentWork['title'], ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <p class="rider-work-text">
                    <?php echo htmlspecialchars($currentWork['text'], ENT_QUOTES, 'UTF-8'); ?>
                </p>
            </div>

            <?php if (!empty($currentWork['href'])): ?>
            <a href="<?php echo htmlspecialchars($currentWork['href'], ENT_QUOTES, 'UTF-8'); ?>" class="rider-work-cta">
                <span><?php echo htmlspecialchars($currentWork['cta'], ENT_QUOTES, 'UTF-8'); ?></span>
                <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-right-long-line.svg" alt=""
                    class="rider-work-cta-icon" width="16" height="16"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/arrow-right-s-line.svg'">
            </a>
            <?php endif; ?>
        </section>

        <!-- ============================================
             TWO-COLUMN ROW: Chart + Info Card
             ============================================ -->
        <div class="rider-dashboard-row rider-dashboard-row-primary">

            <!-- Weekly Earnings Chart -->
            <section class="rider-card rider-chart-card" aria-labelledby="chart-title">
                <div class="rider-card-header">
                    <h2 class="heading-5" id="chart-title">Earnings - Last 7 Days</h2>
                    <a href="earnings.php?tab=chart" class="rider-card-link">Full Chart</a>
                </div>

                <div class="rider-chart-body">
                    <div class="rider-weekly-chart" role="img"
                        aria-label="Bar chart of earnings over the last seven days">
                        <div class="rider-chart-y-axis" aria-hidden="true">
                            <?php foreach (array_reverse($chartScale['gridlines']) as $grid): ?>
                            <span class="rider-chart-y-label">₱<?php echo number_format($grid, 0); ?></span>
                            <?php endforeach; ?>
                        </div>

                        <div class="rider-chart-plot">
                            <?php foreach ($chartScale['gridlines'] as $grid): ?>
                            <div class="rider-chart-gridline" aria-hidden="true"></div>
                            <?php endforeach; ?>

                            <div class="rider-chart-columns">
                                <?php foreach ($weeklyEarnings as $day):
                                    $pct      = $barHeights[$day['date']];
                                    $isToday  = ($day['date'] === $today);
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

                        <div class="rider-chart-tooltip" id="riderChartTooltip" role="status" aria-live="polite"></div>
                    </div>

                    <div class="rider-chart-summary">
                        <div class="rider-chart-summary-item">
                            <span class="rider-chart-summary-label">This Week</span>
                            <span class="rider-chart-summary-value">
                                <?php echo formatRiderCurrency($weekEarnings); ?>
                            </span>
                            <?php if ($weekDeliveries > 0): ?>
                            <span class="rider-chart-summary-hint">
                                <?php echo $weekDeliveries; ?>
                                deliver<?php echo $weekDeliveries === 1 ? 'y' : 'ies'; ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="rider-chart-summary-item">
                            <span class="rider-chart-summary-label">Last 30 Days</span>
                            <span class="rider-chart-summary-value">
                                <?php echo formatRiderCurrency($monthEarnings); ?>
                            </span>
                            <?php if ($monthDeliveries > 0): ?>
                            <span class="rider-chart-summary-hint">
                                <?php echo $monthDeliveries; ?>
                                deliver<?php echo $monthDeliveries === 1 ? 'y' : 'ies'; ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="rider-chart-summary-item">
                            <span class="rider-chart-summary-label">All Time</span>
                            <span class="rider-chart-summary-value">
                                <?php echo formatRiderCurrency($totalEarnings); ?>
                            </span>
                            <span class="rider-chart-summary-hint">Total earnings</span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Info Card: Profile + Vehicle + Status -->
            <aside class="rider-card rider-info-card" aria-labelledby="info-title">
                <div class="rider-card-header">
                    <h2 class="heading-5" id="info-title">Profile</h2>
                    <a href="profile.php" class="rider-card-link">View</a>
                </div>

                <div class="rider-info-body">

                    <!-- Identity -->
                    <dl class="rider-info-identity">
                        <div class="rider-info-row">
                            <dt>Name</dt>
                            <dd><?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?></dd>
                        </div>
                        <div class="rider-info-row">
                            <dt>Email</dt>
                            <dd><?php echo htmlspecialchars($emailLabel, ENT_QUOTES, 'UTF-8'); ?></dd>
                        </div>
                        <div class="rider-info-row">
                            <dt>Contact</dt>
                            <dd><?php echo htmlspecialchars($contactLabel, ENT_QUOTES, 'UTF-8'); ?></dd>
                        </div>
                    </dl>

                    <!-- Vehicle -->
                    <div class="rider-info-vehicle">
                        <span class="rider-info-section-label">Vehicle</span>

                        <div class="rider-info-vehicle-tile">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/<?php echo htmlspecialchars($vehicleIcon, ENT_QUOTES, 'UTF-8'); ?>"
                                alt="" class="rider-info-vehicle-icon"
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/<?php echo htmlspecialchars($vehicleIconAlt, ENT_QUOTES, 'UTF-8'); ?>'">
                            <div class="rider-info-vehicle-details">
                                <p class="rider-info-vehicle-name">
                                    <?php echo htmlspecialchars($vehicleLabel, ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                                <p class="rider-info-vehicle-plate">
                                    <?php echo htmlspecialchars($plateLabel, ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Status meta -->
                    <dl class="rider-info-meta">
                        <div class="rider-info-meta-row">
                            <dt>Status</dt>
                            <dd>
                                <span class="badge <?php echo $statusClass; ?>">
                                    <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </dd>
                        </div>
                        <div class="rider-info-meta-row">
                            <dt>Availability</dt>
                            <dd>
                                <span
                                    class="rider-info-availability <?php echo $available ? 'is-online' : 'is-offline'; ?>">
                                    <?php echo $available ? 'Online' : 'Offline'; ?>
                                </span>
                            </dd>
                        </div>
                        <div class="rider-info-meta-row">
                            <dt>Total deliveries</dt>
                            <dd><?php echo number_format($deliveries); ?></dd>
                        </div>
                        <div class="rider-info-meta-row">
                            <dt>Average rating</dt>
                            <dd><?php echo number_format($rating, 1); ?> / 5.0</dd>
                        </div>
                    </dl>
                </div>
            </aside>
        </div>

        <!-- ============================================
             VERIFICATION WARNING (if not verified)
             ============================================ -->
        <?php if (!$isVerified): ?>
        <section class="rider-card rider-card-warning">
            <div class="rider-card-body rider-warning-body">
                <div class="rider-warning-icon" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/error-warning-line.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/information-fill.svg'">
                </div>
                <div class="rider-warning-content">
                    <p class="rider-warning-title">
                        <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <p class="rider-warning-text">
                        <?php if ($status === 'pending'): ?>
                        Your account is under review. You'll be notified once verification is complete.
                        You can't go online until then.
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
    assetBase: '<?php echo $assetBase; ?>',
    isVerified: <?php echo $isVerified ? 'true' : 'false'; ?>
};
</script>
<script src="../assets/ui/js/dashboard.js" defer></script>

<?php
require_once __DIR__ . '/../../shared/includes/footer.php';
?>