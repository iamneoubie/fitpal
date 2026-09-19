<?php
/**
 * FitPal Rider Dashboard
 *
 * Minimal landing page for the rider role. Shows the rider's name,
 * verification status, vehicle info, and a couple of stat cards.
 * All SQL lives in rider-queries.php.
 *
 * @package FitPal
 * @version 1.0
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
$profile = getRiderProfile($database_connection, $riderId) ?: [];

$fullName   = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));
$firstName  = (string)($profile['first_name'] ?? 'Rider');
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
?>

<div class="content rider-dashboard-page">
    <div class="container">

        <header class="rider-dashboard-header">
            <div>
                <h1 class="heading-2">
                    Welcome back, <span><?php echo htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'); ?></span>
                </h1>
                <p class="text-muted">Here's your rider overview.</p>
            </div>
            <div class="rider-dashboard-actions">
                <span class="badge <?php echo $statusClass; ?>">
                    <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
        </header>

        <section class="rider-stats-grid">
            <a href="earnings.php" class="rider-stat-card">
                <div class="rider-stat-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/wallet-line.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/coin-line.svg'">
                </div>
                <div class="rider-stat-info">
                    <p class="rider-stat-number"><?php echo formatRiderCurrency($balance); ?></p>
                    <p class="rider-stat-label">Wallet Balance</p>
                </div>
            </a>

            <a href="deliveries.php" class="rider-stat-card">
                <div class="rider-stat-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/package.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/order.svg'">
                </div>
                <div class="rider-stat-info">
                    <p class="rider-stat-number"><?php echo number_format($deliveries); ?></p>
                    <p class="rider-stat-label">Total Deliveries</p>
                </div>
            </a>

            <div class="rider-stat-card">
                <div class="rider-stat-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/star-fill.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/star-empty.svg'">
                </div>
                <div class="rider-stat-info">
                    <p class="rider-stat-number"><?php echo number_format($rating, 1); ?> / 5.0</p>
                    <p class="rider-stat-label">Average Rating</p>
                </div>
            </div>

            <div class="rider-stat-card">
                <div class="rider-stat-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/community-general.svg'">
                </div>
                <div class="rider-stat-info">
                    <p class="rider-stat-number">
                        <?php echo htmlspecialchars(ucfirst($vehicle), ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <p class="rider-stat-label">
                        <?php echo $plate !== '' ? htmlspecialchars($plate, ENT_QUOTES, 'UTF-8') : 'No plate'; ?>
                    </p>
                </div>
            </div>
        </section>

        <section class="rider-card">
            <div class="rider-card-header">
                <h2 class="heading-5">Availability</h2>
            </div>
            <div class="rider-card-body">
                <p class="rider-availability-line">
                    Current status:
                    <strong class="<?php echo $available ? 'rider-available' : 'rider-unavailable'; ?>">
                        <?php echo $available ? 'Available' : 'Unavailable'; ?>
                    </strong>
                </p>
                <p class="text-muted rider-availability-hint">
                    Set yourself available from the Deliveries page when you're ready to accept orders.
                </p>
            </div>
        </section>

        <?php if ($status !== 'verified'): ?>
        <section class="rider-card rider-card-warning">
            <div class="rider-card-body">
                <p class="rider-warning-title">
                    <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <p class="text-muted">
                    Your account is not yet fully verified. Contact support if this persists.
                </p>
            </div>
        </section>
        <?php endif; ?>

    </div>
</div>

<?php
require_once __DIR__ . '/../../shared/includes/footer.php';
?>