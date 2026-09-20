<?php
/**
 * FitPal Admin Dashboard
 *
 * Two queues, one decision each, and a record of what just happened.
 *
 * ---------------------------------------------------------------------
 * QUERY CONTRACT
 * ---------------------------------------------------------------------
 * This page issues exactly 5 queries on every request. All bounded
 * by LIMIT. None scale with table size.
 *
 *   1. getVerificationCounts()          — both summary tiles
 *   2. getOldestPendingSubmissions()    — "oldest waiting" hints
 *   3. getPendingRestaurantFeed()       — top 4 newest pending
 *   4. getPendingRiderFeed()            — top 4 newest pending
 *   5. getRecentVerificationDecisions() — last 5 decisions
 *
 * Do not add a sixth. The dashboard is a router, not a report.
 * ---------------------------------------------------------------------
 *
 * @package FitPal
 * @version 5.0
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

// ---- Defaults (used if any query throws) ----
$counts = [
    'restaurants' => ['total' => 0, 'pending' => 0, 'verified' => 0, 'denied' => 0, 'suspended' => 0],
    'riders'      => ['total' => 0, 'pending' => 0, 'verified' => 0, 'denied' => 0, 'suspended' => 0],
];

$oldest = ['oldest_restaurant' => null, 'oldest_rider' => null];

$pendingRest     = [];
$pendingRiders   = [];
$recentDecisions = [];

try {
    $counts          = getVerificationCounts($database_connection);
    $oldest          = getOldestPendingSubmissions($database_connection);
    $pendingRest     = getPendingRestaurantFeed($database_connection, 4);
    $pendingRiders   = getPendingRiderFeed($database_connection, 4);
    $recentDecisions = getRecentVerificationDecisions($database_connection, 5);
} catch (PDOException $e) {
    error_log('Admin dashboard query error: ' . $e->getMessage());
}

$restaurantCounts = $counts['restaurants'];
$riderCounts      = $counts['riders'];

$totalPending = $restaurantCounts['pending'] + $riderCounts['pending'];

$adminName = $_SESSION['user_name'] ?? 'Administrator';

// Age label for the "oldest waiting" hint
$oldestRestHuman = '';
if (!empty($oldest['oldest_restaurant']['submitted_at'])) {
    $oldestRestHuman = formatAdminRelativeTime($oldest['oldest_restaurant']['submitted_at']);
}
$oldestRiderHuman = '';
if (!empty($oldest['oldest_rider']['submitted_at'])) {
    $oldestRiderHuman = formatAdminRelativeTime($oldest['oldest_rider']['submitted_at']);
}
?>

<div class="content admin-page admin-dashboard">
    <div class="container">

        <!-- ============================================
             PAGE HEADER
             ============================================ -->
        <header class="dashboard-header">
            <div class="dashboard-greeting">
                <h1 class="heading-2">
                    Welcome back, <span><?php echo htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8'); ?></span>
                </h1>
                <p class="text-muted">
                    <?php if ($totalPending > 0): ?>
                    <strong><?php echo $totalPending; ?></strong>
                    submission<?php echo $totalPending === 1 ? '' : 's'; ?> awaiting review.
                    <?php else: ?>
                    No submissions are awaiting review. Nice work.
                    <?php endif; ?>
                </p>
            </div>
            <div class="dashboard-actions">
                <a href="restaurants.php?status=pending" class="btn btn-primary">
                    Review Restaurants
                </a>
                <a href="riders.php?status=pending" class="btn btn-outline">
                    Review Riders
                </a>
            </div>
        </header>

        <!-- ============================================
             FLASH MESSAGES
             ============================================ -->
        <?php if (isset($_SESSION['admin_success'])): ?>
        <div class="admin-alert admin-alert-success" role="alert">
            <?php echo htmlspecialchars($_SESSION['admin_success'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['admin_success']); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['admin_error'])): ?>
        <div class="admin-alert admin-alert-error" role="alert">
            <?php echo htmlspecialchars($_SESSION['admin_error'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['admin_error']); ?>
        </div>
        <?php endif; ?>

        <!-- ============================================
             SUMMARY TILES — TWO ACROSS
             ============================================ -->
        <section class="admin-summary-row" aria-label="Verification summary">

            <a href="restaurants.php?status=pending" class="admin-summary-tile">
                <div class="tile-icon tile-icon-restaurant" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt="">
                </div>
                <div class="tile-body">
                    <span class="tile-number"><?php echo $restaurantCounts['pending']; ?></span>
                    <span class="tile-label">
                        Restaurant<?php echo $restaurantCounts['pending'] === 1 ? '' : 's'; ?> Pending
                    </span>
                    <?php if ($oldestRestHuman !== ''): ?>
                    <span class="tile-hint">
                        Oldest waiting <?php echo htmlspecialchars($oldestRestHuman, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <?php else: ?>
                    <span class="tile-hint tile-hint-muted">Queue is clear</span>
                    <?php endif; ?>
                </div>
                <span class="tile-total">
                    <?php echo $restaurantCounts['total']; ?> total
                </span>
            </a>

            <a href="riders.php?status=pending" class="admin-summary-tile">
                <div class="tile-icon tile-icon-rider" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/package.svg" alt="">
                </div>
                <div class="tile-body">
                    <span class="tile-number"><?php echo $riderCounts['pending']; ?></span>
                    <span class="tile-label">
                        Rider<?php echo $riderCounts['pending'] === 1 ? '' : 's'; ?> Pending
                    </span>
                    <?php if ($oldestRiderHuman !== ''): ?>
                    <span class="tile-hint">
                        Oldest waiting <?php echo htmlspecialchars($oldestRiderHuman, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <?php else: ?>
                    <span class="tile-hint tile-hint-muted">Queue is clear</span>
                    <?php endif; ?>
                </div>
                <span class="tile-total">
                    <?php echo $riderCounts['total']; ?> total
                </span>
            </a>

        </section>

        <!-- ============================================
             QUEUE ROW — TWO PENDING PANELS
             ============================================ -->
        <div class="admin-queue-row">

            <!-- Pending restaurants -->
            <section class="admin-card" aria-labelledby="pending-rest-title">
                <div class="admin-card-header">
                    <h2 class="admin-card-title" id="pending-rest-title">
                        Pending Restaurants
                    </h2>
                    <?php if ($restaurantCounts['pending'] > 0): ?>
                    <a href="restaurants.php?status=pending" class="card-link">View All</a>
                    <?php endif; ?>
                </div>

                <?php if (empty($pendingRest)): ?>
                <div class="admin-empty-inline">
                    <div class="empty-inline-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/verified-fill.svg" alt="">
                    </div>
                    <p class="empty-inline-title">All caught up</p>
                    <p class="empty-inline-text">No restaurants awaiting review.</p>
                </div>
                <?php else: ?>
                <ul class="admin-feed">
                    <?php foreach ($pendingRest as $row): ?>
                    <li class="admin-feed-item">
                        <div class="feed-item-main">
                            <p class="feed-item-name">
                                <?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                            <p class="feed-item-meta">
                                <?php echo htmlspecialchars($row['meta'], ENT_QUOTES, 'UTF-8'); ?>
                                · <?php echo htmlspecialchars($row['submitted_human'], ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                        </div>
                        <a href="restaurants.php?status=pending&focus=<?php echo (int)$row['id']; ?>"
                            class="feed-item-action">Review</a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </section>

            <!-- Pending riders -->
            <section class="admin-card" aria-labelledby="pending-riders-title">
                <div class="admin-card-header">
                    <h2 class="admin-card-title" id="pending-riders-title">
                        Pending Riders
                    </h2>
                    <?php if ($riderCounts['pending'] > 0): ?>
                    <a href="riders.php?status=pending" class="card-link">View All</a>
                    <?php endif; ?>
                </div>

                <?php if (empty($pendingRiders)): ?>
                <div class="admin-empty-inline">
                    <div class="empty-inline-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/verified-fill.svg" alt="">
                    </div>
                    <p class="empty-inline-title">All caught up</p>
                    <p class="empty-inline-text">No riders awaiting review.</p>
                </div>
                <?php else: ?>
                <ul class="admin-feed">
                    <?php foreach ($pendingRiders as $row): ?>
                    <li class="admin-feed-item">
                        <div class="feed-item-main">
                            <p class="feed-item-name">
                                <?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                            <p class="feed-item-meta">
                                <?php echo htmlspecialchars($row['meta'], ENT_QUOTES, 'UTF-8'); ?>
                                · <?php echo htmlspecialchars($row['submitted_human'], ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                        </div>
                        <a href="riders.php?status=pending&focus=<?php echo (int)$row['id']; ?>"
                            class="feed-item-action">Review</a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </section>

        </div>

        <!-- ============================================
             RECENT DECISIONS — QUIET RECORD
             ============================================ -->
        <section class="admin-card" aria-labelledby="recent-decisions-title">
            <div class="admin-card-header">
                <h2 class="admin-card-title" id="recent-decisions-title">
                    Recent Decisions
                </h2>
            </div>

            <?php if (empty($recentDecisions)): ?>
            <div class="admin-empty-inline">
                <div class="empty-inline-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/history-line.svg" alt="">
                </div>
                <p class="empty-inline-title">No decisions yet</p>
                <p class="empty-inline-text">
                    Approvals, denials, and suspensions will appear here.
                </p>
            </div>
            <?php else: ?>
            <ul class="decision-list">
                <?php foreach ($recentDecisions as $decision):
                    $status     = $decision['status'];
                    $badgeClass = getVerificationBadgeClass($status);
                    $entity     = $decision['entity'];
                    $entityPath = $entity === 'restaurant'
                        ? 'restaurants.php?focus=' . $decision['id']
                        : 'riders.php?focus=' . $decision['id'];
                ?>
                <li class="decision-item">
                    <div class="decision-main">
                        <div class="decision-line">
                            <a href="<?php echo htmlspecialchars($entityPath, ENT_QUOTES, 'UTF-8'); ?>"
                                class="decision-name">
                                <?php echo htmlspecialchars($decision['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                            <span class="admin-badge <?php echo $badgeClass; ?>">
                                <?php echo htmlspecialchars(formatVerificationStatus($status), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                        <p class="decision-meta">
                            <?php echo htmlspecialchars(ucfirst($entity), ENT_QUOTES, 'UTF-8'); ?>
                            · by <?php echo htmlspecialchars($decision['admin_name'], ENT_QUOTES, 'UTF-8'); ?>
                            · <?php echo htmlspecialchars($decision['decided_human'], ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>

    </div>
</div>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>