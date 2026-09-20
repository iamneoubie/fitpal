<?php
/**
 * FitPal Admin — Rider Verification Queue
 * Compact layout with contextual bulk actions.
 *
 * @package FitPal
 * @version 4.1 — Replaced inline SVGs with shared icon assets.
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

// ---- Filter params ----
$allowedStatuses = ['pending', 'verified', 'denied', 'suspended'];
$statusFilter = isset($_GET['status']) ? strtolower(trim((string)$_GET['status'])) : '';
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

$search  = isset($_GET['q'])    ? trim((string)$_GET['q'])    : '';
$page    = isset($_GET['page']) ? max(1, (int)$_GET['page'])  : 1;
$perPage = 20;
$offset  = ($page - 1) * $perPage;

// ---- Fetch ----
$riders       = [];
$totalRecords = 0;
$totalPages   = 1;
$riderCounts  = ['total' => 0, 'pending' => 0, 'verified' => 0, 'denied' => 0, 'suspended' => 0];

try {
    $allCounts   = getVerificationCounts($database_connection);
    $riderCounts = $allCounts['riders'];

    $totalRecords = countRidersForVerification($database_connection, $statusFilter ?: null);
    $totalPages   = max(1, (int)ceil($totalRecords / $perPage));
    if ($page > $totalPages) {
        $page   = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $riders = getRidersForVerification(
        $database_connection,
        $statusFilter ?: null,
        $perPage,
        $offset
    );
} catch (PDOException $e) {
    error_log('Admin riders list error: ' . $e->getMessage());
}

$csrfToken = $_SESSION['csrf_token'] ?? '';

/* -----------------------------------------------------------------
 * URL BUILDER
 * ----------------------------------------------------------------- */
function ridersUrl(array $overrides = []): string
{
    $params = array_merge([
        'status' => $_GET['status'] ?? '',
        'q'      => $_GET['q']      ?? '',
        'page'   => $_GET['page']   ?? '',
    ], $overrides);
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return 'riders.php' . ($params ? '?' . http_build_query($params) : '');
}

/* -----------------------------------------------------------------
 * VIEW HELPERS
 * ----------------------------------------------------------------- */
function riderStatusPillClass(string $status): string
{
    return match ($status) {
        'pending'   => 'pill-pending',
        'verified'  => 'pill-verified',
        'denied'    => 'pill-denied',
        'suspended' => 'pill-suspended',
        default     => 'pill-neutral',
    };
}

function formatExactDate(?string $datetime): string
{
    if ($datetime === null || $datetime === '') return '—';
    $ts = strtotime($datetime);
    return $ts !== false ? date('m/d/Y h:i A', $ts) : $datetime;
}

function vehicleShort(?string $type, ?string $plate): string
{
    $type = $type !== null && $type !== '' ? ucfirst($type) : '—';
    if ($plate !== null && $plate !== '') {
        return $type . ' · ' . $plate;
    }
    return $type;
}

function riderInitials(string $first, string $last): string
{
    $a = $first !== '' ? strtoupper(substr($first, 0, 1)) : '';
    $b = $last  !== '' ? strtoupper(substr($last, 0, 1))  : '';
    return $a . $b ?: '?';
}

// Determine bulk action button labels based on active tab
$bulkActions = [];
if ($statusFilter === 'pending') {
    $bulkActions = [
        ['label' => 'Approve Selected', 'status' => 'verified', 'class' => 'bulk-btn-approve', 'icon' => 'verified-fill.svg'],
        ['label' => 'Deny Selected',    'status' => 'denied',   'class' => 'bulk-btn-deny', 'icon' => 'cancel.svg']
    ];
} elseif ($statusFilter === 'verified') {
    $bulkActions = [
        ['label' => 'Suspend Selected', 'status' => 'suspended', 'class' => 'bulk-btn-deny', 'icon' => 'cancel.svg']
    ];
} elseif ($statusFilter === 'denied' || $statusFilter === 'suspended') {
    $bulkActions = [
        ['label' => 'Reinstate Selected', 'status' => 'pending', 'class' => 'bulk-btn-approve', 'icon' => 'update.svg']
    ];
}
$showBulkBar = !empty($bulkActions);
?>

<link rel="stylesheet" href="../assets/css/admin-tables.css">

<div class="content admin-page">

    <header class="admin-page-header">
        <div class="admin-page-header-left">
            <h1 class="admin-page-title">Delivery Riders</h1>
            <span class="admin-page-count">
                <?php echo number_format($riderCounts['total']); ?> total
                <?php if ($riderCounts['pending'] > 0): ?>
                <span class="count-dot"></span>
                <span class="count-pending">
                    <?php echo $riderCounts['pending']; ?> pending
                </span>
                <?php endif; ?>
            </span>
        </div>
    </header>

    <?php if (isset($_SESSION['admin_success'])): ?>
    <div class="admin-flash admin-flash-success" role="alert">
        <span><?php echo htmlspecialchars($_SESSION['admin_success'], ENT_QUOTES, 'UTF-8'); ?></span>
        <button type="button" class="flash-close" onclick="this.parentElement.remove()"
            aria-label="Dismiss">&times;</button>
    </div>
    <?php unset($_SESSION['admin_success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['admin_error'])): ?>
    <div class="admin-flash admin-flash-error" role="alert">
        <span><?php echo htmlspecialchars($_SESSION['admin_error'], ENT_QUOTES, 'UTF-8'); ?></span>
        <button type="button" class="flash-close" onclick="this.parentElement.remove()"
            aria-label="Dismiss">&times;</button>
    </div>
    <?php unset($_SESSION['admin_error']); ?>
    <?php endif; ?>

    <div class="admin-surface">

        <div class="admin-toolbar">
            <nav class="toolbar-tabs" aria-label="Filter by status">
                <a href="<?php echo htmlspecialchars(ridersUrl(['status' => '', 'page' => '']), ENT_QUOTES, 'UTF-8'); ?>"
                    class="toolbar-tab <?php echo $statusFilter === '' ? 'active' : ''; ?>">
                    All <span class="tab-num"><?php echo $riderCounts['total']; ?></span>
                </a>
                <a href="<?php echo htmlspecialchars(ridersUrl(['status' => 'pending', 'page' => '']), ENT_QUOTES, 'UTF-8'); ?>"
                    class="toolbar-tab <?php echo $statusFilter === 'pending' ? 'active' : ''; ?>">
                    Pending <span class="tab-num tab-num-warn"><?php echo $riderCounts['pending']; ?></span>
                </a>
                <a href="<?php echo htmlspecialchars(ridersUrl(['status' => 'verified', 'page' => '']), ENT_QUOTES, 'UTF-8'); ?>"
                    class="toolbar-tab <?php echo $statusFilter === 'verified' ? 'active' : ''; ?>">
                    Verified <span class="tab-num"><?php echo $riderCounts['verified']; ?></span>
                </a>
                <a href="<?php echo htmlspecialchars(ridersUrl(['status' => 'denied', 'page' => '']), ENT_QUOTES, 'UTF-8'); ?>"
                    class="toolbar-tab <?php echo $statusFilter === 'denied' ? 'active' : ''; ?>">
                    Denied <span class="tab-num"><?php echo $riderCounts['denied']; ?></span>
                </a>
                <a href="<?php echo htmlspecialchars(ridersUrl(['status' => 'suspended', 'page' => '']), ENT_QUOTES, 'UTF-8'); ?>"
                    class="toolbar-tab <?php echo $statusFilter === 'suspended' ? 'active' : ''; ?>">
                    Suspended <span class="tab-num"><?php echo $riderCounts['suspended']; ?></span>
                </a>
            </nav>

            <div class="toolbar-controls">
                <form method="GET" action="riders.php" class="toolbar-search">
                    <?php if ($statusFilter !== ''): ?>
                    <input type="hidden" name="status"
                        value="<?php echo htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>
                    <div class="search-input-wrap">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/search-line.svg" alt=""
                            class="search-input-icon" width="14" height="14">
                        <input type="text" name="q"
                            value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
                            placeholder="Search riders, emails, plates…" class="search-input">
                        <?php if ($search !== ''): ?>
                        <a href="<?php echo htmlspecialchars(ridersUrl(['q' => '', 'page' => '']), ENT_QUOTES, 'UTF-8'); ?>"
                            class="search-clear" aria-label="Clear search">&times;</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <?php if (empty($riders)): ?>
        <div class="admin-empty-state">
            <div class="empty-state-icon">
                <img src="<?php echo $assetBase; ?>assets/images/icons/people-team.svg" alt="" width="32" height="32">
            </div>
            <p class="empty-state-title">
                <?php echo $statusFilter === '' ? 'No riders yet' : 'No ' . htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') . ' riders'; ?>
            </p>
            <p class="empty-state-text">
                <?php if ($search !== ''): ?>
                No results for "<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>".
                <?php else: ?>
                Nothing to review in this queue.
                <?php endif; ?>
            </p>
        </div>
        <?php else: ?>

        <div class="admin-table-wrap">
            <table class="admin-table" id="ridersTable">
                <thead>
                    <tr>
                        <th class="col-name">Rider</th>
                        <th class="col-submitted">Submitted</th>
                        <th class="col-status">Status</th>
                        <th class="col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($riders as $rider):
                        $riderId    = (int)$rider['delivery_rider_id'];
                        $statusRaw  = (string)($rider['verification_status'] ?? '');
                        $hasProfile = $statusRaw !== '';
                        $status     = $hasProfile ? $statusRaw : 'pending';
                        $fullName   = trim(($rider['first_name'] ?? '') . ' ' . ($rider['last_name'] ?? ''));
                    ?>
                    <tr data-row-id="<?php echo $riderId; ?>"
                        data-status="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">

                        <td class="col-name">
                            <span
                                class="row-primary"><?php echo htmlspecialchars($fullName ?: 'Unnamed Rider', ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="row-secondary">
                                <?php echo htmlspecialchars($rider['email'] ?? 'No email', ENT_QUOTES, 'UTF-8'); ?>
                                &middot; ID #<?php echo $riderId; ?>
                            </span>
                        </td>

                        <td class="col-submitted">
                            <span class="row-secondary">
                                <?php echo htmlspecialchars(formatExactDate($rider['date_created'] ?? null), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>

                        <td class="col-status">
                            <?php if (!$hasProfile): ?>
                            <span class="status-pill pill-neutral">No Profile</span>
                            <?php else: ?>
                            <span class="status-pill <?php echo riderStatusPillClass($status); ?>">
                                <?php echo htmlspecialchars(formatVerificationStatus($status), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <?php endif; ?>
                        </td>

                        <td class="col-actions">
                            <div class="actions-dropdown">
                                <button type="button" class="actions-trigger" aria-label="More actions">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/more-vertical-line.svg"
                                        alt="">
                                </button>
                                <div class="actions-menu">
                                    <button type="button" class="action-item view-details-btn"
                                        data-id="<?php echo $riderId; ?>"
                                        data-name="<?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>"
                                        data-email="<?php echo htmlspecialchars($rider['email'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-phone="<?php echo htmlspecialchars($rider['contact_number'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-vehicle="<?php echo htmlspecialchars(vehicleShort($rider['vehicle_type'] ?? null, $rider['vehicle_plate'] ?? null), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-city="<?php echo htmlspecialchars($rider['base_city'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-submitted="<?php echo htmlspecialchars(formatExactDate($rider['date_created'] ?? null), ENT_QUOTES, 'UTF-8'); ?>">
                                        <img src="<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg"
                                            alt="" width="14" height="14">
                                        View Details
                                    </button>

                                    <?php if ($hasProfile): ?>
                                    <?php if ($status === 'pending'): ?>
                                    <button type="button" class="action-item" data-action="verify_rider"
                                        data-id="<?php echo $riderId; ?>" data-status="verified"
                                        data-name="<?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>">
                                        <img src="<?php echo $assetBase; ?>assets/images/icons/verified-fill.svg" alt=""
                                            width="14" height="14">
                                        Approve
                                    </button>
                                    <button type="button" class="action-item text-danger" data-action="verify_rider"
                                        data-id="<?php echo $riderId; ?>" data-status="denied"
                                        data-name="<?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>">
                                        <img src="<?php echo $assetBase; ?>assets/images/icons/cancel.svg" alt=""
                                            width="14" height="14">
                                        Deny
                                    </button>
                                    <?php elseif ($status === 'verified'): ?>
                                    <button type="button" class="action-item text-danger" data-action="verify_rider"
                                        data-id="<?php echo $riderId; ?>" data-status="suspended"
                                        data-name="<?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>">
                                        <img src="<?php echo $assetBase; ?>assets/images/icons/cancel.svg" alt=""
                                            width="14" height="14">
                                        Suspend
                                    </button>
                                    <?php elseif ($status === 'denied' || $status === 'suspended'): ?>
                                    <button type="button" class="action-item" data-action="verify_rider"
                                        data-id="<?php echo $riderId; ?>" data-status="pending"
                                        data-name="<?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>">
                                        <img src="<?php echo $assetBase; ?>assets/images/icons/update.svg" alt=""
                                            width="14" height="14">
                                        Reinstate
                                    </button>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($totalPages > 1): ?>
    <nav class="admin-pagination" aria-label="Pagination">
        <?php if ($page > 1): ?>
        <a href="<?php echo htmlspecialchars(ridersUrl(['page' => $page - 1]), ENT_QUOTES, 'UTF-8'); ?>"
            class="page-link">← Prev</a>
        <?php else: ?>
        <span class="page-link page-link-disabled">← Prev</span>
        <?php endif; ?>

        <span class="page-info">
            Page <strong><?php echo $page; ?></strong> of <?php echo $totalPages; ?>
            <span class="page-info-muted">(<?php echo number_format($totalRecords); ?> records)</span>
        </span>

        <?php if ($page < $totalPages): ?>
        <a href="<?php echo htmlspecialchars(ridersUrl(['page' => $page + 1]), ENT_QUOTES, 'UTF-8'); ?>"
            class="page-link">Next →</a>
        <?php else: ?>
        <span class="page-link page-link-disabled">Next →</span>
        <?php endif; ?>
    </nav>
    <?php endif; ?>

</div>

<!-- BULK ACTION BAR (Only rendered if on a specific tab) -->
<?php if ($showBulkBar): ?>
<div class="bulk-bar" id="bulkBar">
    <span class="bulk-count"><strong id="bulkCount">0</strong> selected</span>
    <div class="bulk-actions">
        <?php foreach ($bulkActions as $action): ?>
        <button type="button" class="bulk-btn <?php echo $action['class']; ?>"
            data-bulk-status="<?php echo $action['status']; ?>">
            <img src="<?php echo $assetBase; ?>assets/images/icons/<?php echo $action['icon']; ?>" alt="" width="14"
                height="14" style="filter: brightness(0) invert(1);">
            <?php echo htmlspecialchars($action['label'], ENT_QUOTES, 'UTF-8'); ?>
        </button>
        <?php endforeach; ?>
        <button type="button" class="bulk-btn bulk-btn-clear" id="bulkClear">Clear</button>
    </div>
</div>
<?php endif; ?>

<!-- HIDDEN FORMS & MODALS -->
<form id="verifyForm" method="POST" action="../backend/handlers/admin-handler.php" hidden>
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="action" value="verify_rider">
    <input type="hidden" name="rider_id" id="verifyRiderId" value="">
    <input type="hidden" name="status" id="verifyStatus" value="">
</form>

<!-- View Details Modal -->
<div class="admin-modal" id="detailsModal">
    <div class="admin-modal-overlay" onclick="closeDetailsModal()"></div>
    <div class="admin-modal-content">
        <div class="admin-modal-header">
            <h3 class="admin-modal-title">Rider Details</h3>
            <button type="button" class="admin-modal-close" onclick="closeDetailsModal()">&times;</button>
        </div>
        <div class="admin-modal-body">
            <div class="detail-row">
                <span class="detail-label">Full Name</span>
                <span class="detail-value" id="modalRiderName">—</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Email</span>
                <span class="detail-value" id="modalRiderEmail">—</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Phone</span>
                <span class="detail-value" id="modalRiderPhone">—</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Vehicle</span>
                <span class="detail-value" id="modalRiderVehicle">—</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Base Area</span>
                <span class="detail-value" id="modalRiderCity">—</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Submitted</span>
                <span class="detail-value" id="modalRiderSubmitted">—</span>
            </div>
        </div>
        <div class="admin-modal-footer">
            <button type="button" class="btn btn-primary" onclick="closeDetailsModal()">Close</button>
        </div>
    </div>
</div>

<script>
window.FITPAL_ADMIN_RIDERS = {
    csrfToken: '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>',
    handlerUrl: '../backend/handlers/admin-handler.php',
    showBulkBar: <?php echo $showBulkBar ? 'true' : 'false'; ?>
};
</script>
<script src="../assets/ui/js/riders.js" defer></script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>