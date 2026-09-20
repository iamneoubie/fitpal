<?php
/**
 * FitPal Admin — Restaurant Verification Queue
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
$restaurants      = [];
$totalRecords     = 0;
$totalPages       = 1;
$counts           = ['restaurants' => ['total' => 0, 'pending' => 0, 'verified' => 0, 'denied' => 0, 'suspended' => 0]];
$restaurantCounts = $counts['restaurants'];

try {
    $allCounts        = getVerificationCounts($database_connection);
    $restaurantCounts = $allCounts['restaurants'];

    $totalRecords = countRestaurantsForVerification($database_connection, $statusFilter ?: null);
    $totalPages   = max(1, (int)ceil($totalRecords / $perPage));
    if ($page > $totalPages) {
        $page   = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $restaurants = getRestaurantsForVerification(
        $database_connection,
        $statusFilter ?: null,
        $perPage,
        $offset
    );
} catch (PDOException $e) {
    error_log('Admin restaurants list error: ' . $e->getMessage());
}

$csrfToken = $_SESSION['csrf_token'] ?? '';

/* -----------------------------------------------------------------
 * URL BUILDER
 * ----------------------------------------------------------------- */
function restaurantsUrl(array $overrides = []): string
{
    $params = array_merge([
        'status' => $_GET['status'] ?? '',
        'q'      => $_GET['q']      ?? '',
        'page'   => $_GET['page']   ?? '',
    ], $overrides);

    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return 'restaurants.php' . ($params ? '?' . http_build_query($params) : '');
}

/* -----------------------------------------------------------------
 * VIEW HELPERS
 * ----------------------------------------------------------------- */
function statusPillClass(string $status): string
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
            <h1 class="admin-page-title">Restaurants</h1>
            <span class="admin-page-count">
                <?php echo number_format($restaurantCounts['total']); ?> total
                <?php if ($restaurantCounts['pending'] > 0): ?>
                <span class="count-dot"></span>
                <span class="count-pending">
                    <?php echo $restaurantCounts['pending']; ?> pending
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
                <a href="<?php echo htmlspecialchars(restaurantsUrl(['status' => '', 'page' => '']), ENT_QUOTES, 'UTF-8'); ?>"
                    class="toolbar-tab <?php echo $statusFilter === '' ? 'active' : ''; ?>">
                    All <span class="tab-num"><?php echo $restaurantCounts['total']; ?></span>
                </a>
                <a href="<?php echo htmlspecialchars(restaurantsUrl(['status' => 'pending', 'page' => '']), ENT_QUOTES, 'UTF-8'); ?>"
                    class="toolbar-tab <?php echo $statusFilter === 'pending' ? 'active' : ''; ?>">
                    Pending <span class="tab-num tab-num-warn"><?php echo $restaurantCounts['pending']; ?></span>
                </a>
                <a href="<?php echo htmlspecialchars(restaurantsUrl(['status' => 'verified', 'page' => '']), ENT_QUOTES, 'UTF-8'); ?>"
                    class="toolbar-tab <?php echo $statusFilter === 'verified' ? 'active' : ''; ?>">
                    Verified <span class="tab-num"><?php echo $restaurantCounts['verified']; ?></span>
                </a>
                <a href="<?php echo htmlspecialchars(restaurantsUrl(['status' => 'denied', 'page' => '']), ENT_QUOTES, 'UTF-8'); ?>"
                    class="toolbar-tab <?php echo $statusFilter === 'denied' ? 'active' : ''; ?>">
                    Denied <span class="tab-num"><?php echo $restaurantCounts['denied']; ?></span>
                </a>
                <a href="<?php echo htmlspecialchars(restaurantsUrl(['status' => 'suspended', 'page' => '']), ENT_QUOTES, 'UTF-8'); ?>"
                    class="toolbar-tab <?php echo $statusFilter === 'suspended' ? 'active' : ''; ?>">
                    Suspended <span class="tab-num"><?php echo $restaurantCounts['suspended']; ?></span>
                </a>
            </nav>

            <div class="toolbar-controls">
                <form method="GET" action="restaurants.php" class="toolbar-search">
                    <?php if ($statusFilter !== ''): ?>
                    <input type="hidden" name="status"
                        value="<?php echo htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>
                    <div class="search-input-wrap">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/search-line.svg" alt=""
                            class="search-input-icon" width="14" height="14">
                        <input type="text" name="q"
                            value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
                            placeholder="Search restaurants, owners, emails…" class="search-input">
                        <?php if ($search !== ''): ?>
                        <a href="<?php echo htmlspecialchars(restaurantsUrl(['q' => '', 'page' => '']), ENT_QUOTES, 'UTF-8'); ?>"
                            class="search-clear" aria-label="Clear search">&times;</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <?php if (empty($restaurants)): ?>
        <div class="admin-empty-state">
            <div class="empty-state-icon">
                <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt="" width="32" height="32">
            </div>
            <p class="empty-state-title">
                <?php echo $statusFilter === '' ? 'No restaurants yet' : 'No ' . htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') . ' restaurants'; ?>
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
            <table class="admin-table" id="restaurantsTable">
                <thead>
                    <tr>
                        <th class="col-name">Restaurant</th>
                        <th class="col-owner">Owner</th>
                        <th class="col-submitted">Submitted</th>
                        <th class="col-status">Status</th>
                        <th class="col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($restaurants as $rest):
                        $rid         = (int)$rest['restaurant_id'];
                        $status      = (string)$rest['verification_status'];
                        $branchCount = (int)$rest['branch_count'];
                    ?>
                    <tr data-row-id="<?php echo $rid; ?>"
                        data-status="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">

                        <td class="col-name">
                            <span class="row-primary">
                                <?php echo htmlspecialchars($rest['business_name'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <span class="row-secondary">
                                <?php echo htmlspecialchars($rest['cuisine_type'] ?? 'No cuisine', ENT_QUOTES, 'UTF-8'); ?>
                                &middot; ID #<?php echo $rid; ?>
                            </span>
                        </td>

                        <td class="col-owner">
                            <?php if (!empty($rest['owner_name'])): ?>
                            <span
                                class="row-primary-sm"><?php echo htmlspecialchars($rest['owner_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <span
                                class="row-secondary"><?php echo htmlspecialchars($rest['owner_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php else: ?>
                            <span class="row-muted">—</span>
                            <?php endif; ?>
                        </td>

                        <td class="col-submitted">
                            <span class="row-secondary">
                                <?php echo htmlspecialchars(formatExactDate($rest['created_at']), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>

                        <td class="col-status">
                            <span class="status-pill <?php echo statusPillClass($status); ?>">
                                <?php echo htmlspecialchars(formatVerificationStatus($status), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>

                        <td class="col-actions">
                            <div class="actions-dropdown">
                                <button type="button" class="actions-trigger" aria-label="More actions">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/more-vertical-line.svg"
                                        alt="">
                                </button>
                                <div class="actions-menu">
                                    <button type="button" class="action-item view-details-btn"
                                        data-id="<?php echo $rid; ?>"
                                        data-name="<?php echo htmlspecialchars($rest['business_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-owner="<?php echo htmlspecialchars($rest['owner_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-email="<?php echo htmlspecialchars($rest['owner_email'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-phone="<?php echo htmlspecialchars($rest['owner_contact'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-branches="<?php echo $branchCount; ?>"
                                        data-submitted="<?php echo htmlspecialchars(formatExactDate($rest['created_at']), ENT_QUOTES, 'UTF-8'); ?>">
                                        <img src="<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg"
                                            alt="" width="14" height="14">
                                        View Details
                                    </button>

                                    <?php if ($status === 'pending'): ?>
                                    <button type="button" class="action-item" data-action="verify_restaurant"
                                        data-id="<?php echo $rid; ?>" data-status="verified"
                                        data-name="<?php echo htmlspecialchars($rest['business_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <img src="<?php echo $assetBase; ?>assets/images/icons/verified-fill.svg" alt=""
                                            width="14" height="14">
                                        Approve
                                    </button>
                                    <button type="button" class="action-item text-danger"
                                        data-action="verify_restaurant" data-id="<?php echo $rid; ?>"
                                        data-status="denied"
                                        data-name="<?php echo htmlspecialchars($rest['business_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <img src="<?php echo $assetBase; ?>assets/images/icons/cancel.svg" alt=""
                                            width="14" height="14">
                                        Deny
                                    </button>
                                    <?php elseif ($status === 'verified'): ?>
                                    <button type="button" class="action-item text-danger"
                                        data-action="verify_restaurant" data-id="<?php echo $rid; ?>"
                                        data-status="suspended"
                                        data-name="<?php echo htmlspecialchars($rest['business_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <img src="<?php echo $assetBase; ?>assets/images/icons/cancel.svg" alt=""
                                            width="14" height="14">
                                        Suspend
                                    </button>
                                    <?php elseif ($status === 'denied' || $status === 'suspended'): ?>
                                    <button type="button" class="action-item" data-action="verify_restaurant"
                                        data-id="<?php echo $rid; ?>" data-status="pending"
                                        data-name="<?php echo htmlspecialchars($rest['business_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <img src="<?php echo $assetBase; ?>assets/images/icons/update.svg" alt=""
                                            width="14" height="14">
                                        Reinstate
                                    </button>
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
        <a href="<?php echo htmlspecialchars(restaurantsUrl(['page' => $page - 1]), ENT_QUOTES, 'UTF-8'); ?>"
            class="page-link">← Prev</a>
        <?php else: ?>
        <span class="page-link page-link-disabled">← Prev</span>
        <?php endif; ?>

        <span class="page-info">
            Page <strong><?php echo $page; ?></strong> of <?php echo $totalPages; ?>
            <span class="page-info-muted">(<?php echo number_format($totalRecords); ?> records)</span>
        </span>

        <?php if ($page < $totalPages): ?>
        <a href="<?php echo htmlspecialchars(restaurantsUrl(['page' => $page + 1]), ENT_QUOTES, 'UTF-8'); ?>"
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
    <input type="hidden" name="action" value="verify_restaurant">
    <input type="hidden" name="restaurant_id" id="verifyRestaurantId" value="">
    <input type="hidden" name="status" id="verifyStatus" value="">
</form>

<!-- View Details Modal -->
<div class="admin-modal" id="detailsModal">
    <div class="admin-modal-overlay" onclick="closeDetailsModal()"></div>
    <div class="admin-modal-content">
        <div class="admin-modal-header">
            <h3 class="admin-modal-title">Restaurant Details</h3>
            <button type="button" class="admin-modal-close" onclick="closeDetailsModal()">&times;</button>
        </div>
        <div class="admin-modal-body">
            <div class="detail-row">
                <span class="detail-label">Business Name</span>
                <span class="detail-value" id="modalRestName">—</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Owner</span>
                <span class="detail-value" id="modalRestOwner">—</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Email</span>
                <span class="detail-value" id="modalRestEmail">—</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Phone</span>
                <span class="detail-value" id="modalRestPhone">—</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Branches</span>
                <span class="detail-value" id="modalRestBranches">—</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Submitted</span>
                <span class="detail-value" id="modalRestSubmitted">—</span>
            </div>
        </div>
        <div class="admin-modal-footer">
            <button type="button" class="btn btn-primary" onclick="closeDetailsModal()">Close</button>
        </div>
    </div>
</div>

<script>
window.FITPAL_ADMIN_RESTAURANTS = {
    csrfToken: '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>',
    handlerUrl: '../backend/handlers/admin-handler.php',
    showBulkBar: <?php echo $showBulkBar ? 'true' : 'false'; ?>
};
</script>
<script src="../assets/ui/js/restaurants.js" defer></script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>