<?php
/**
 * FitPal Rider Dashboard
 *
 * Layout
 * ------
 *   1. Greeting header
 *   2. Four stat cards (available balance, deliveries, rating, today's earnings)
 *   3. Current Work strip — carries the blocked face AND the re-apply
 *      action for a denied rider. This strip is the single surface for
 *      every non-verified state.
 *   4. Order liability strip — rendered only when the rider is
 *      carrying outstanding COD cash
 *   5. Two-column row: weekly chart | info card
 *
 * ---------------------------------------------------------------------
 * NO SEPARATE VERIFICATION WARNING CARD
 * ---------------------------------------------------------------------
 * Earlier revisions rendered a second card below the two-column row
 * whose content mirrored the Current Work strip's blocked face. For
 * every non-verified status the two said the same thing twice. The
 * card is removed. The strip is the only place the rider's
 * verification state is stated on this page.
 *
 * The denied case carries one action: "Re-apply Now". Contact
 * Support is not offered from this page; a rider who needs to reach
 * support can do so from the footer's Contact link or from the
 * profile page.
 *
 * ---------------------------------------------------------------------
 * WHERE THE NUMBERS ON THIS PAGE COME FROM
 * ---------------------------------------------------------------------
 *   Live work counts  — shared order-transaction layer, committed
 *                        subset ('picking_up' and 'delivering').
 *   Per-delivery payout — FITPAL_DELIVERY_BASE_FEE.
 *   Order liability    — getRiderOutstandingCollections().
 *
 * The dashboard never writes to the ledger and never moves a rider
 * between statuses.
 *
 * @package FitPal
 * @version 5.1 — Drops the "Contact Support" button from the Current
 *                Work strip's blocked face. The denied case now
 *                carries only "Re-apply Now". The suspended case
 *                carries no action buttons at all; the copy alone
 *                names the state.
 *
 *                (5.0: verification warning card removed; strip is
 *                the only surface for non-verified copy. 4.9: blocked
 *                face branched on verification status. 4.8: card
 *                gained the Re-apply Now action. 4.7: collection
 *                strip becomes the Order liability strip.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('rider');

if (empty($_SESSION['delivery_rider_id'])) {
    header('Location: sign-in.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../backend/database/rider-assignment-queries.php';

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

$outstandingCollections = getRiderOutstandingCollections($database_connection, $riderId);

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

$collectionTotal = (float)($outstandingCollections['total'] ?? 0);
$collectionCount = (int)($outstandingCollections['count'] ?? 0);

// ============================================
// CURRENT WORK
// ============================================
$activeCount   = count($activeDeliveries);
$assignedCount = count($assignedOrders);

$pickingUpOrders  = [];
$deliveringOrders = [];
foreach ($activeDeliveries as $order) {
    $s = (string)($order['order_status'] ?? '');
    if ($s === 'picking_up') {
        $pickingUpOrders[] = $order;
    } elseif ($s === 'delivering') {
        $deliveringOrders[] = $order;
    }
}
$pickingUpCount  = count($pickingUpOrders);
$deliveringCount = count($deliveringOrders);

$riderPayoutPerDelivery = FITPAL_DELIVERY_BASE_FEE;
$livePayoutTotal        = $activeCount * $riderPayoutPerDelivery;

$currentWork = null;

if (!$isVerified) {

    // ------------------------------------------------------------
    // BLOCKED FACE
    //
    // The strip is the ONLY surface for the rider's non-verified
    // state on this page. Each non-verified status renders its own
    // title and body. Only the denied case carries an action:
    // "Re-apply Now". Contact Support is not offered from this page.
    // ------------------------------------------------------------
    if ($status === 'denied') {
        $currentWork = [
            'kind'    => 'blocked',
            'icon'    => 'close-circle-line.svg',
            'label'   => 'Denied',
            'title'   => 'Denied',
            'text'    => 'Your application was denied. You can update your information and submit a new application.',
            'actions' => [
                [
                    'href'  => 'reapply.php',
                    'label' => 'Re-apply Now',
                    'class' => 'btn btn-primary btn-sm rider-work-cta',
                    'icon'  => 'arrow-right-long-line.svg',
                    'icon_alt' => 'arrow-right-s-line.svg',
                ],
            ],
        ];
    } elseif ($status === 'suspended') {
        $currentWork = [
            'kind'    => 'blocked',
            'icon'    => 'error-warning-line.svg',
            'label'   => 'Suspended',
            'title'   => 'Account suspended',
            'text'    => 'Your account has been suspended. Please reach out through the Contact page.',
            'actions' => [],
        ];
    } else {
        $currentWork = [
            'kind'    => 'blocked',
            'icon'    => 'error-warning-line.svg',
            'label'   => 'Verification required',
            'title'   => 'Awaiting verification',
            'text'    => 'Your account is ' . strtolower($statusLabel) . '. You cannot go online or accept orders until it is approved.',
            'actions' => [],
        ];
    }

} elseif ($activeCount > 0) {
    if ($pickingUpCount > 0 && $deliveringCount === 0) {
        if ($pickingUpCount === 1) {
            $first = $pickingUpOrders[0];
            $restaurantName = (string)($first['restaurant_name'] ?? 'the restaurant');
            $title = 'Head to ' . $restaurantName;
        } else {
            $title = $pickingUpCount . ' pickups in progress';
        }
        $text = $pickingUpCount === 1
            ? 'Tap "Mark Picked Up" once you have the food, then head to the customer.'
            : 'Mark each order as picked up before heading out.';
        $text .= ' ₱' . number_format($livePayoutTotal, 2)
               . ' total on delivery.';
    } elseif ($deliveringCount > 0 && $pickingUpCount === 0) {
        if ($deliveringCount === 1) {
            $first = $deliveringOrders[0];
            $customerName = (string)($first['customer_name'] ?? 'the customer');
            $title = 'Delivering to ' . $customerName;
        } else {
            $title = $deliveringCount . ' deliveries in progress';
        }
        $text = $deliveringCount === 1
            ? 'Mark it delivered to earn ₱' . number_format($riderPayoutPerDelivery, 2) . '.'
            : 'Mark each order delivered to collect ₱' . number_format($livePayoutTotal, 2) . ' total.';
    } else {
        $title = $activeCount . ' in progress';
        $text = $pickingUpCount . ' pickup' . ($pickingUpCount === 1 ? '' : 's')
              . ' and ' . $deliveringCount . ' deliver' . ($deliveringCount === 1 ? 'y' : 'ies')
              . ' on your route. ₱' . number_format($livePayoutTotal, 2) . ' total on completion.';
    }

    $currentWork = [
        'kind'    => 'active',
        'icon'    => 'riding-fill.svg',
        'label'   => 'In progress',
        'title'   => $title,
        'text'    => $text,
        'actions' => [
            [
                'href'  => 'deliveries.php?tab=active',
                'label' => 'Open Deliveries',
                'class' => 'btn btn-primary btn-sm rider-work-cta',
                'icon'  => 'arrow-right-long-line.svg',
                'icon_alt' => 'arrow-right-s-line.svg',
            ],
        ],
    ];
} elseif ($assignedCount > 0) {
    if ($assignedCount === 1) {
        $title = '1 assignment waiting';
        $text  = 'The kitchen assigned you an order. Accept or decline to continue.';
    } else {
        $title = $assignedCount . ' assignments waiting';
        $text  = 'The kitchen assigned you ' . $assignedCount
               . ' orders. Accept or decline each one to continue.';
    }

    $currentWork = [
        'kind'    => 'assigned',
        'icon'    => 'package.svg',
        'label'   => 'Action needed',
        'title'   => $title,
        'text'    => $text,
        'actions' => [
            [
                'href'  => 'deliveries.php?tab=assigned',
                'label' => 'Review Assignment' . ($assignedCount === 1 ? '' : 's'),
                'class' => 'btn btn-primary btn-sm rider-work-cta',
                'icon'  => 'arrow-right-long-line.svg',
                'icon_alt' => 'arrow-right-s-line.svg',
            ],
        ],
    ];
} elseif ($available) {
    $currentWork = [
        'kind'    => 'clear',
        'icon'    => 'verified-fill.svg',
        'label'   => 'Standing by',
        'title'   => "You're clear",
        'text'    => 'No active deliveries and no pending assignments. New orders will appear in the assignments panel.',
        'actions' => [],
    ];
} else {
    $currentWork = [
        'kind'    => 'offline',
        'icon'    => 'information-fill.svg',
        'label'   => 'Offline',
        'title'   => "You're offline",
        'text'    => 'Toggle availability from the assignments panel at the bottom of the page to start receiving orders.',
        'actions' => [],
    ];
}

// Vehicle display values
$vehicleLabel = $vehicle !== '' ? ucfirst($vehicle) : 'Not recorded';
$plateLabel   = $plate   !== '' ? $plate            : 'No plate recorded';

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

            <a href="earnings.php" class="rider-stat-card">
                <div class="rider-stat-icon rider-stat-icon-wallet" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/wallet-line.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/coin-line.svg'">
                </div>
                <div class="rider-stat-info">
                    <p class="rider-stat-number"><?php echo formatRiderCurrency($balance); ?></p>
                    <p class="rider-stat-label">Available Balance</p>
                </div>
                <span class="rider-stat-arrow" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-right-s-line.svg" alt=""
                        class="rider-stat-arrow-img" width="18" height="18">
                </span>
            </a>

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

             This is the ONLY surface on the page that states the
             rider's verification state. There is no separate
             warning card below the two-column row any more.
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

            <?php if (!empty($currentWork['actions'])): ?>
            <div class="rider-work-actions">
                <?php foreach ($currentWork['actions'] as $action): ?>
                <a href="<?php echo htmlspecialchars($action['href'], ENT_QUOTES, 'UTF-8'); ?>"
                    class="<?php echo htmlspecialchars($action['class'], ENT_QUOTES, 'UTF-8'); ?>">
                    <span><?php echo htmlspecialchars($action['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php if (!empty($action['icon'])): ?>
                    <img src="<?php echo $assetBase; ?>assets/images/icons/<?php echo htmlspecialchars($action['icon'], ENT_QUOTES, 'UTF-8'); ?>"
                        alt="" class="rider-work-cta-icon" width="16" height="16"
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/<?php echo htmlspecialchars($action['icon_alt'], ENT_QUOTES, 'UTF-8'); ?>'">
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        <!-- ============================================
             ORDER LIABILITY STRIP
             ============================================ -->
        <?php if ($collectionCount > 0): ?>
        <section class="rider-work-card rider-work-card-assigned" aria-label="Order liability">
            <div class="rider-work-icon" aria-hidden="true">
                <img src="<?php echo $assetBase; ?>assets/images/icons/wallet-fill.svg" alt=""
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/coin-fill.svg'">
            </div>

            <div class="rider-work-body">
                <span class="rider-work-label">Order liability</span>
                <p class="rider-work-title">
                    &minus;<?php echo htmlspecialchars(formatRiderCurrency($collectionTotal), ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <p class="rider-work-text">
                    You are carrying this amount for
                    <?php echo $collectionCount; ?>
                    COD order<?php echo $collectionCount === 1 ? '' : 's'; ?>.
                    It clears when the deliver<?php echo $collectionCount === 1 ? 'y' : 'ies'; ?>
                    complete<?php echo $collectionCount === 1 ? 's' : ''; ?>.
                </p>
            </div>
        </section>
        <?php endif; ?>

        <!-- ============================================
             TWO-COLUMN ROW: Chart + Info Card
             ============================================ -->
        <div class="rider-dashboard-row rider-dashboard-row-primary">

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

            <aside class="rider-card rider-info-card" aria-labelledby="info-title">
                <div class="rider-card-header">
                    <h2 class="heading-5" id="info-title">Profile</h2>
                    <a href="profile.php" class="rider-card-link">View</a>
                </div>

                <div class="rider-info-body">

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