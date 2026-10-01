<?php
/**
 * FitPal Rider Dashboard
 *
 * Layout
 * ------
 *   1. Greeting header
 *   2. Four stat cards (available balance, deliveries, rating, today's earnings)
 *   3. Current Work strip
 *   4. Order liability strip — rendered only when the rider is
 *      carrying outstanding COD cash
 *   5. Two-column row: weekly chart | info card
 *   6. Verification warning (only when not verified)
 *
 * ---------------------------------------------------------------------
 * WHERE THE NUMBERS ON THIS PAGE COME FROM
 * ---------------------------------------------------------------------
 * Three sources feed the dashboard:
 *
 *   Live work counts (stat cards, Current Work strip)
 *       Every order status the dashboard tracks is written by the
 *       shared order-transaction layer. The rider-role dashboard
 *       reads the committed subset ('picking_up' and 'delivering')
 *       through the same queries the assignment panel and the
 *       deliveries page use.
 *
 *   Per-delivery payout
 *       The rider's earnings and today's/wk/30d sums are read from
 *       the `transaction` table (the `deposit` rows written by the
 *       shared layer's delivery credit pair). The per-delivery
 *       figure comes from FITPAL_DELIVERY_BASE_FEE via
 *       getRiderDashboardStats().
 *
 *   Order liability
 *       Under v2.4.0, a COD rider physically collects the order
 *       total from the customer at pickup. The amount they are
 *       currently carrying is the sum of the 'collected' rows in
 *       `rider_collection` for their own rider id. That table is
 *       written by the shared handler and read here through
 *       getRiderOutstandingCollections().
 *
 * The dashboard never writes to the ledger. It never moves a rider
 * between statuses; the assignment handler and the deliveries page
 * are the only surfaces that do.
 *
 * ---------------------------------------------------------------------
 * AVAILABLE BALANCE
 * ---------------------------------------------------------------------
 * Under v2.4.0 the rider is never debited. The accept-time debit
 * does not exist. The rider pays nothing at accept time; they
 * collect cash from the customer at pickup and settle it on
 * delivery. Their `financial_account.balance` therefore only ever
 * increases, by the delivery fee, on each successful delivery. It
 * is exactly what the rider has earned and can withdraw. The label
 * is "Available Balance."
 *
 * ---------------------------------------------------------------------
 * ORDER LIABILITY STRIP — SIGN CONVENTION
 * ---------------------------------------------------------------------
 * The strip tells the rider how much customer cash they are
 * currently carrying: "Order liability: −₱447.00."
 *
 * The number is presented with a leading minus sign because it
 * represents cash the rider is holding for someone else — money
 * that is not theirs and that they will hand back. The rider reads
 * the minus as "this much is not mine."
 *
 * Underneath, the value is a positive sum over a positive column.
 * `rider_collection.amount` carries CHECK (amount >= 0) and the
 * query layer returns a positive float. The minus sign is added
 * here, in the page, by the format call. The ledger column stays
 * positive because a negative value in a column that means
 * "amount" would violate the ACID contract the schema enforces.
 *
 * If the schema ever needed to represent a real negative balance,
 * it would do so with a signed column and an explicit constraint
 * that allows the range — the model in force does not, and does not
 * need to. The sign on this page is a view choice.
 *
 * ---------------------------------------------------------------------
 * CURRENT WORK STRIP — LIVE-STATUS AWARENESS
 * ---------------------------------------------------------------------
 * The rider's live work spans two statuses, both of which count as
 * active:
 *
 *   picking_up  — accepted; en route to or at the restaurant.
 *   delivering  — food in hand; en route to the customer.
 *
 * The strip runs three signals in priority order:
 *
 *   1. If not verified → the blocked face.
 *   2. If the rider has any live order → the working face.
 *   3. If neither, check availability → clear or offline face.
 *
 * Plural copy is dynamic so it reads correctly at every count from
 * 1 to the cap of 3.
 *
 * @package FitPal
 * @version 4.7 — The collection strip becomes the Order liability
 *                strip. The amount is prefixed with a minus sign at
 *                the view layer; the underlying value stays a
 *                positive float from the query layer. The label and
 *                the hint text reflect the v2.4.0 model.
 *
 *                (4.6: label reverted to "Available Balance"; strip
 *                added. 4.5: label renamed under the pre-v2.4.0
 *                model. 4.4: docblock records the shared layer.
 *                4.3: Current Work strip reads 'picking_up'.)
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
// Returns both picking_up and delivering orders.
$activeDeliveries = getRiderActiveDeliveries($database_connection, $riderId);

// Outstanding COD cash the rider is currently carrying. Returned
// as a positive total from the query layer.
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

// Outstanding collections. Kept positive here; the minus sign is
// added in the strip markup below.
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

// The per-delivery payout the shared layer will credit on success.
$riderPayoutPerDelivery = FITPAL_DELIVERY_BASE_FEE;
$livePayoutTotal        = $activeCount * $riderPayoutPerDelivery;

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
        'kind'   => 'active',
        'icon'   => 'riding-fill.svg',
        'label'  => 'In progress',
        'title'  => $title,
        'text'   => $text,
        'href'   => 'deliveries.php?tab=active',
        'cta'    => 'Open Deliveries',
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
        'kind'   => 'assigned',
        'icon'   => 'package.svg',
        'label'  => 'Action needed',
        'title'  => $title,
        'text'   => $text,
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

// Vehicle icon.
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

            <!-- Available Balance -->
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
             ORDER LIABILITY STRIP
             Renders only when the rider is carrying COD cash.

             The amount is the positive sum returned by
             getRiderOutstandingCollections(); the minus sign
             is a view-layer prefix. The ledger column stays
             positive, per the ACID contract.
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