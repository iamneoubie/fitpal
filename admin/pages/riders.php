<?php
/**
 * FitPal Admin — Riders List
 *
 * Paginated rider list with verification tabs, search, and a nested
 * detail modal.
 *
 * Page header
 * -----------
 * Single flex row: heading block on the left, back button on the
 * right. The back button is a .btn-outline inside
 * .admin-page-header-actions, which the shared admin-tables.css
 * renders as a neutral black/white pill anchored top-right.
 *
 * Modal layout
 * ------------
 *   Personal Info tab → sub-tabs: Credentials · Vehicle · Address & Contacts
 *   Documents tab     → sub-tabs: Profile Picture · ID Documents
 *   Account tab       → sub-tabs: Summary · Recent Deliveries
 *
 * Footer is state-driven — buttons rendered depend on the rider's
 * current verification_status:
 *
 *     pending   → [Verify] [Deny]
 *     verified  → [Suspend]
 *     denied    → [Verify] [Suspend]
 *     suspended → [Remove Suspension]
 *
 * No inline CSS. No inline JS. Styles come from admin-tables.css
 * plus the compact-override block at the end of riders.css.
 * Behaviour comes from the shared admin-modal.js.
 *
 * @package FitPal
 * @version 12.0 — Page header markup adjusted so the title is
 *                 top-left and the back button is top-right,
 *                 matching the customer role's pattern and the
 *                 admin-tables.css layout.
 *                 (10.0: Footer is state-driven.)
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

$page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$status = isset($_GET['status']) ? (string)$_GET['status'] : 'all';
$openId = isset($_GET['open']) ? (int)$_GET['open'] : 0;
$perPage = 5;

$allowedStatuses = ['all', 'pending', 'verified', 'denied', 'suspended'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'all';
}

$counts = getRiderCountsByStatus($database_connection);
$data   = getRidersPaginated($database_connection, $page, $perPage, $search, $status);
$rows   = $data['rows'];
$pagination = $data;

$openRider = null;
$openAddress = null;
$openContacts = [];
$openDocuments = [];
$openDeliveries = [];

if ($openId > 0) {
    $openRider = getRiderDetails($database_connection, $openId);
    if ($openRider) {
        $openAddress    = getRiderAddress($database_connection, $openId);
        $openContacts   = getRiderEmergencyContacts($database_connection, $openId);
        $openDocuments  = getRiderDocuments($database_connection, $openId);
        $openDeliveries = getRiderRecentDeliveries($database_connection, $openId, 5);
    }
}

function buildRiderUrl(array $overrides = []): string
{
    $params = [
        'page'   => $_GET['page']   ?? 1,
        'search' => $_GET['search'] ?? '',
        'status' => $_GET['status'] ?? 'all',
    ];
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    return '?' . http_build_query($params);
}
?>

<div class="content admin-list-page">
    <div class="container">

        <!-- ============================================
             PAGE HEADER
             Title block left, back button right.
             ============================================ -->
        <header class="admin-page-header">
            <div class="admin-page-header-left">
                <h1 class="heading-2">Rider <span>Management</span></h1>
                <p class="text-muted">Review rider verification and delivery performance.</p>
            </div>
            <div class="admin-page-header-actions">
                <a href="dashboard.php" class="btn btn-outline btn-sm">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-left-line.svg" alt="" class="btn-icon"
                        width="16" height="16">
                    <span>Dashboard</span>
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

        <div class="admin-filter-bar">
            <form method="GET" action="" class="admin-search-form">
                <input type="hidden" name="status"
                    value="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="text" name="search" class="admin-search-input"
                    placeholder="Search by name, email, username, or contact…"
                    value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="admin-search-btn">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/search-line.svg" alt=""
                        class="btn-icon btn-icon-14" width="14" height="14">
                    <span>Search</span>
                </button>
            </form>

            <nav class="admin-filter-tabs" aria-label="Rider verification filter">
                <a href="<?php echo htmlspecialchars(buildRiderUrl(['status' => 'all', 'page' => 1]), ENT_QUOTES, 'UTF-8'); ?>"
                    class="admin-filter-tab <?php echo $status === 'all' ? 'active' : ''; ?>">
                    All <span class="filter-count"><?php echo number_format($counts['all']); ?></span>
                </a>
                <a href="<?php echo htmlspecialchars(buildRiderUrl(['status' => 'pending', 'page' => 1]), ENT_QUOTES, 'UTF-8'); ?>"
                    class="admin-filter-tab <?php echo $status === 'pending' ? 'active' : ''; ?>">
                    Pending <span class="filter-count"><?php echo number_format($counts['pending']); ?></span>
                </a>
                <a href="<?php echo htmlspecialchars(buildRiderUrl(['status' => 'verified', 'page' => 1]), ENT_QUOTES, 'UTF-8'); ?>"
                    class="admin-filter-tab <?php echo $status === 'verified' ? 'active' : ''; ?>">
                    Verified <span class="filter-count"><?php echo number_format($counts['verified']); ?></span>
                </a>
                <a href="<?php echo htmlspecialchars(buildRiderUrl(['status' => 'denied', 'page' => 1]), ENT_QUOTES, 'UTF-8'); ?>"
                    class="admin-filter-tab <?php echo $status === 'denied' ? 'active' : ''; ?>">
                    Denied <span class="filter-count"><?php echo number_format($counts['denied']); ?></span>
                </a>
                <a href="<?php echo htmlspecialchars(buildRiderUrl(['status' => 'suspended', 'page' => 1]), ENT_QUOTES, 'UTF-8'); ?>"
                    class="admin-filter-tab <?php echo $status === 'suspended' ? 'active' : ''; ?>">
                    Suspended <span class="filter-count"><?php echo number_format($counts['suspended']); ?></span>
                </a>
            </nav>
        </div>

        <div class="admin-table-card">
            <?php if (empty($rows)): ?>
            <div class="admin-table-empty">
                <div class="admin-table-empty-icon" aria-hidden="true"
                    data-fallback-src="<?php echo $assetBase; ?>assets/images/icons/order.svg">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/search-line.svg" alt="">
                </div>
                <p class="admin-table-empty-title">No riders found</p>
                <p class="admin-table-empty-text">
                    <?php if ($search !== ''): ?>
                    No results for "<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>".
                    <?php else: ?>
                    Try a different filter.
                    <?php endif; ?>
                </p>
            </div>
            <?php else: ?>
            <div class="admin-table-riders">
                <?php foreach ($rows as $r):
                    $rid = (int)$r['delivery_rider_id'];
                    $name = adminName($r);
                    $initial = adminInitial($r);
                    $verification = (string)($r['verification_status'] ?? 'pending');
                    $isActive = (int)$r['is_active'] === 1;
                    $pic = (string)($r['profile_picture'] ?? '');
                    $picUrl = $pic !== '' ? adminAssetUrl($assetBase, $pic) : '';
                ?>
                <div class="admin-table-row">
                    <div class="admin-cell-avatar">
                        <?php if ($picUrl !== ''): ?>
                        <img src="<?php echo htmlspecialchars($picUrl, ENT_QUOTES, 'UTF-8'); ?>" alt=""
                            data-initial="<?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php else: ?>
                        <?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </div>

                    <div class="admin-cell-body">
                        <p class="admin-cell-title"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></p>
                        <p class="admin-cell-subtitle">
                            <?php echo htmlspecialchars((string)$r['email'], ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                        <div class="admin-cell-meta">
                            <span class="admin-cell-meta-item">
                                <?php echo htmlspecialchars((string)($r['contact_number'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <span class="admin-cell-meta-item">
                                @<?php echo htmlspecialchars((string)($r['username'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                    </div>

                    <div class="admin-cell-body">
                        <div class="admin-cell-meta">
                            <span class="badge <?php echo adminVerificationBadgeClass($verification); ?>">
                                <?php echo adminVerificationLabel($verification); ?>
                            </span>
                            <span class="badge <?php echo $isActive ? 'badge-success' : 'badge-secondary'; ?>">
                                <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>
                        <div class="admin-cell-meta">
                            <span class="admin-cell-meta-item">
                                <?php echo htmlspecialchars(ucfirst((string)($r['vehicle_type'] ?? '—')), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <span class="admin-cell-meta-item">
                                <?php echo number_format((int)($r['total_deliveries'] ?? 0)); ?> deliveries
                            </span>
                        </div>
                    </div>

                    <div class="admin-cell-actions">
                        <a href="<?php echo htmlspecialchars(buildRiderUrl(['open' => $rid]), ENT_QUOTES, 'UTF-8'); ?>"
                            class="btn btn-outline btn-sm">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/pages-line.svg" alt=""
                                class="btn-icon btn-icon-14" width="14" height="14">
                            <span>View Details</span>
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($pagination['totalPages'] > 1): ?>
            <nav class="admin-pagination" aria-label="Rider pagination">
                <span class="admin-pagination-info">
                    Showing page <?php echo $pagination['page']; ?> of <?php echo $pagination['totalPages']; ?>
                    (<?php echo number_format($pagination['total']); ?> total)
                </span>
                <ul class="admin-pagination-list">
                    <?php if ($pagination['page'] > 1): ?>
                    <li>
                        <a href="<?php echo htmlspecialchars(buildRiderUrl(['page' => $pagination['page'] - 1, 'open' => null]), ENT_QUOTES, 'UTF-8'); ?>"
                            class="admin-pagination-link">Previous</a>
                    </li>
                    <?php else: ?>
                    <li><span class="admin-pagination-link disabled">Previous</span></li>
                    <?php endif; ?>

                    <?php
                    $maxVisible = 5;
                    $start = max(1, $pagination['page'] - (int)floor($maxVisible / 2));
                    $end   = min($pagination['totalPages'], $start + $maxVisible - 1);
                    if ($end - $start + 1 < $maxVisible) {
                        $start = max(1, $end - $maxVisible + 1);
                    }
                    if ($start > 1): ?>
                    <li>
                        <a href="<?php echo htmlspecialchars(buildRiderUrl(['page' => 1, 'open' => null]), ENT_QUOTES, 'UTF-8'); ?>"
                            class="admin-pagination-link">1</a>
                    </li>
                    <?php if ($start > 2): ?>
                    <li><span class="admin-pagination-ellipsis">…</span></li>
                    <?php endif; endif; ?>

                    <?php for ($i = $start; $i <= $end; $i++): ?>
                    <li>
                        <?php if ($i === $pagination['page']): ?>
                        <span class="admin-pagination-link active"><?php echo $i; ?></span>
                        <?php else: ?>
                        <a href="<?php echo htmlspecialchars(buildRiderUrl(['page' => $i, 'open' => null]), ENT_QUOTES, 'UTF-8'); ?>"
                            class="admin-pagination-link"><?php echo $i; ?></a>
                        <?php endif; ?>
                    </li>
                    <?php endfor; ?>

                    <?php if ($end < $pagination['totalPages']): ?>
                    <?php if ($end < $pagination['totalPages'] - 1): ?>
                    <li><span class="admin-pagination-ellipsis">…</span></li>
                    <?php endif; ?>
                    <li>
                        <a href="<?php echo htmlspecialchars(buildRiderUrl(['page' => $pagination['totalPages'], 'open' => null]), ENT_QUOTES, 'UTF-8'); ?>"
                            class="admin-pagination-link"><?php echo $pagination['totalPages']; ?></a>
                    </li>
                    <?php endif; ?>

                    <?php if ($pagination['page'] < $pagination['totalPages']): ?>
                    <li>
                        <a href="<?php echo htmlspecialchars(buildRiderUrl(['page' => $pagination['page'] + 1, 'open' => null]), ENT_QUOTES, 'UTF-8'); ?>"
                            class="admin-pagination-link">Next</a>
                    </li>
                    <?php else: ?>
                    <li><span class="admin-pagination-link disabled">Next</span></li>
                    <?php endif; ?>
                </ul>
            </nav>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============================================================
     MODAL: Rider Details
     Tabs: Personal Info (3 sub-tabs) / Documents (2 sub-tabs) / Account (2 sub-tabs)
     Footer: state-driven verification actions (see file header)
     ============================================================ -->
<div class="admin-modal <?php echo $openRider ? 'is-open' : ''; ?>" id="riderDetailsModal"
    aria-hidden="<?php echo $openRider ? 'false' : 'true'; ?>" role="dialog">
    <div class="admin-modal-backdrop"
        data-close-url="<?php echo htmlspecialchars(buildRiderUrl(['open' => null]), ENT_QUOTES, 'UTF-8'); ?>">
    </div>
    <div class="admin-modal-panel admin-modal-panel-wide" role="document">

        <div class="admin-modal-header">
            <div class="admin-modal-header-left">
                <p class="admin-modal-title">Rider Details</p>
                <p class="admin-modal-subtitle">
                    <?php echo $openRider
                        ? htmlspecialchars(adminName($openRider) . ' · ' . $openRider['email'], ENT_QUOTES, 'UTF-8')
                        : 'Select a rider to review.'; ?>
                </p>
            </div>
            <a href="<?php echo htmlspecialchars(buildRiderUrl(['open' => null]), ENT_QUOTES, 'UTF-8'); ?>"
                class="admin-modal-close" aria-label="Close">&times;</a>
        </div>

        <?php if (!$openRider): ?>
        <div class="admin-modal-panel-body">
            <p class="admin-detail-value admin-detail-value-muted">No rider selected.</p>
        </div>
        <?php else:
            $verification = (string)($openRider['verification_status'] ?? 'pending');
            $isActive = (int)$openRider['is_active'] === 1;
            $picUrl = !empty($openRider['profile_picture'])
                ? adminAssetUrl($assetBase, (string)$openRider['profile_picture']) : '';
        ?>

        <div class="admin-modal-tabs" role="tablist">
            <button type="button" class="admin-modal-tab active" data-tab="personal" role="tab">
                Personal Info
            </button>
            <button type="button" class="admin-modal-tab" data-tab="documents" role="tab">
                Documents <span class="tab-count"><?php echo count($openDocuments); ?></span>
            </button>
            <button type="button" class="admin-modal-tab" data-tab="account" role="tab">
                Account
            </button>
        </div>

        <div class="admin-modal-panel-body">

            <!-- ============ Tab: Personal Info ============ -->
            <div class="admin-modal-tab-panel active" data-tab-panel="personal" role="tabpanel">

                <div class="admin-subtabs" role="tablist">
                    <button type="button" class="admin-subtab active" data-subtab="credentials" role="tab">
                        Credentials
                    </button>
                    <button type="button" class="admin-subtab" data-subtab="vehicle" role="tab">
                        Vehicle
                    </button>
                    <button type="button" class="admin-subtab" data-subtab="contact" role="tab">
                        Address &amp; Contacts
                    </button>
                </div>

                <!-- Sub-phase: Credentials -->
                <div class="admin-modal-phase active" data-phase="credentials" role="tabpanel">
                    <h3 class="admin-section-heading">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/id-card-fill.svg" alt="" width="14"
                            height="14" class="btn-icon-no-filter">
                        Credentials
                    </h3>
                    <div class="admin-detail-grid">
                        <div class="admin-detail-item">
                            <span class="admin-detail-label">Full Name</span>
                            <span
                                class="admin-detail-value"><?php echo htmlspecialchars(adminName($openRider), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="admin-detail-item">
                            <span class="admin-detail-label">Username</span>
                            <span
                                class="admin-detail-value">@<?php echo htmlspecialchars((string)($openRider['username'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="admin-detail-item">
                            <span class="admin-detail-label">Email</span>
                            <span
                                class="admin-detail-value"><?php echo htmlspecialchars((string)$openRider['email'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="admin-detail-item">
                            <span class="admin-detail-label">Contact</span>
                            <span
                                class="admin-detail-value"><?php echo htmlspecialchars((string)($openRider['contact_number'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="admin-detail-item">
                            <span class="admin-detail-label">Gender</span>
                            <span
                                class="admin-detail-value"><?php echo htmlspecialchars((string)($openRider['gender'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="admin-detail-item">
                            <span class="admin-detail-label">Birthdate</span>
                            <span
                                class="admin-detail-value"><?php echo htmlspecialchars(formatAdminDateShort((string)($openRider['birthdate'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Sub-phase: Vehicle -->
                <div class="admin-modal-phase" data-phase="vehicle" role="tabpanel">
                    <h3 class="admin-section-heading">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/car-fill.svg" alt="" width="14"
                            height="14" class="btn-icon-no-filter">
                        Vehicle &amp; Performance
                    </h3>
                    <div class="admin-detail-grid">
                        <div class="admin-detail-item">
                            <span class="admin-detail-label">Vehicle Type</span>
                            <span
                                class="admin-detail-value"><?php echo htmlspecialchars(ucfirst((string)($openRider['vehicle_type'] ?? '—')), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="admin-detail-item">
                            <span class="admin-detail-label">Plate Number</span>
                            <span
                                class="admin-detail-value"><?php echo htmlspecialchars((string)($openRider['vehicle_plate'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="admin-detail-item">
                            <span class="admin-detail-label">Average Rating</span>
                            <span
                                class="admin-detail-value"><?php echo htmlspecialchars((string)($openRider['average_rating'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="admin-detail-item">
                            <span class="admin-detail-label">Total Deliveries</span>
                            <span
                                class="admin-detail-value"><?php echo number_format((int)($openRider['total_deliveries'] ?? 0)); ?></span>
                        </div>
                        <div class="admin-detail-item">
                            <span class="admin-detail-label">Currently Available</span>
                            <span class="admin-detail-value">
                                <span
                                    class="badge <?php echo (int)($openRider['is_available'] ?? 0) === 1 ? 'badge-success' : 'badge-secondary'; ?>">
                                    <?php echo (int)($openRider['is_available'] ?? 0) === 1 ? 'Yes' : 'No'; ?>
                                </span>
                            </span>
                        </div>
                        <div class="admin-detail-item">
                            <span class="admin-detail-label">Verification</span>
                            <span class="admin-detail-value">
                                <span class="badge <?php echo adminVerificationBadgeClass($verification); ?>">
                                    <?php echo adminVerificationLabel($verification); ?>
                                </span>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Sub-phase: Address & Contacts -->
                <div class="admin-modal-phase" data-phase="contact" role="tabpanel">
                    <h3 class="admin-section-heading">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/location-fill.svg" alt="" width="14"
                            height="14" class="btn-icon-no-filter">
                        Address
                    </h3>
                    <?php if (!$openAddress): ?>
                    <div class="admin-doc-empty">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/location-fill.svg" alt="">
                        <span>No address on file.</span>
                    </div>
                    <?php else: ?>
                    <div class="admin-address-card">
                        <div class="admin-address-label">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/location-fill.svg" alt="" width="14"
                                height="14" class="btn-icon-no-filter">
                            <span><?php echo htmlspecialchars((string)($openAddress['label'] ?? 'Address'), ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php if ((int)($openAddress['is_default'] ?? 0) === 1): ?>
                            <span class="admin-contact-badge">Default</span>
                            <?php endif; ?>
                        </div>
                        <p class="admin-address-text">
                            <?php echo htmlspecialchars(formatRiderAddress($openAddress), ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                    </div>
                    <?php endif; ?>

                    <h3 class="admin-section-heading">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/phone-fill.svg" alt="" width="14"
                            height="14" class="btn-icon-no-filter">
                        Emergency Contacts
                        <span class="section-count"><?php echo count($openContacts); ?></span>
                    </h3>
                    <?php if (empty($openContacts)): ?>
                    <div class="admin-doc-empty">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/phone-fill.svg" alt="">
                        <span>No emergency contacts on file.</span>
                    </div>
                    <?php else: ?>
                    <?php foreach ($openContacts as $idx => $c): ?>
                    <div class="admin-contact-card">
                        <div class="admin-contact-header">
                            <p class="admin-contact-name">
                                <?php echo htmlspecialchars(adminName($c), ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php if ($idx === 0): ?>
                            <span class="admin-contact-badge">Primary</span>
                            <?php endif; ?>
                            <span class="admin-contact-badge">
                                <?php echo htmlspecialchars((string)$c['relationship'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                        <div class="admin-contact-body">
                            <span><strong>Contact:</strong>
                                <?php echo htmlspecialchars((string)$c['contact_number'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php if (!empty($c['address'])): ?>
                            <span><strong>Address:</strong>
                                <?php echo htmlspecialchars((string)$c['address'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            </div><!-- /personal -->

            <!-- ============ Tab: Documents ============ -->
            <div class="admin-modal-tab-panel" data-tab-panel="documents" role="tabpanel">

                <div class="admin-subtabs" role="tablist">
                    <button type="button" class="admin-subtab active" data-subtab="photo" role="tab">
                        Profile Picture
                    </button>
                    <button type="button" class="admin-subtab" data-subtab="ids" role="tab">
                        ID Documents <span class="subtab-count"><?php echo count($openDocuments); ?></span>
                    </button>
                </div>

                <!-- Sub-phase: Profile Picture -->
                <div class="admin-modal-phase active" data-phase="photo" role="tabpanel">
                    <h3 class="admin-section-heading">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/image-fill.svg" alt="" width="14"
                            height="14" class="btn-icon-no-filter">
                        Formal Photo
                    </h3>
                    <div class="admin-doc-block">
                        <div class="admin-doc-head">
                            <p class="admin-doc-title">Profile Picture</p>
                            <div class="admin-doc-meta">
                                <?php if ($picUrl !== ''): ?>
                                <span>Uploaded</span>
                                <?php else: ?>
                                <span class="badge badge-secondary">Not uploaded</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="admin-doc-image">
                            <?php if ($picUrl !== ''): ?>
                            <img src="<?php echo htmlspecialchars($picUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                alt="Profile picture"
                                data-initial="<?php echo htmlspecialchars(adminInitial($openRider), ENT_QUOTES, 'UTF-8'); ?>">
                            <?php else: ?>
                            <div class="admin-doc-empty">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/image-fill.svg" alt="">
                                <span>No profile picture uploaded.</span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Sub-phase: ID Documents -->
                <div class="admin-modal-phase" data-phase="ids" role="tabpanel">
                    <h3 class="admin-section-heading">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/id-card-fill.svg" alt="" width="14"
                            height="14" class="btn-icon-no-filter">
                        Identity Documents
                        <span class="section-count"><?php echo count($openDocuments); ?></span>
                    </h3>
                    <?php if (empty($openDocuments)): ?>
                    <div class="admin-doc-empty">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/id-card-fill.svg" alt="">
                        <span>No identity documents on file.</span>
                    </div>
                    <?php else: ?>
                    <?php foreach ($openDocuments as $doc):
                        $docUrl = adminAssetUrl($assetBase, (string)$doc['id_path']);
                    ?>
                    <div class="admin-doc-block">
                        <div class="admin-doc-head">
                            <p class="admin-doc-title">
                                <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$doc['id_type'])), ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                            <div class="admin-doc-meta">
                                <?php if (!empty($doc['issue_date'])): ?>
                                <span>Issued:
                                    <?php echo htmlspecialchars(formatAdminDateShort((string)$doc['issue_date']), ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($doc['expiry_date'])): ?>
                                <span>Expires:
                                    <?php echo htmlspecialchars(formatAdminDateShort((string)$doc['expiry_date']), ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php else: ?>
                                <span class="badge badge-secondary">No expiry</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="admin-doc-image">
                            <img src="<?php echo htmlspecialchars($docUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                alt="<?php echo htmlspecialchars((string)$doc['id_type'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            </div><!-- /documents -->

            <!-- ============ Tab: Account ============ -->
            <div class="admin-modal-tab-panel" data-tab-panel="account" role="tabpanel">

                <div class="admin-subtabs" role="tablist">
                    <button type="button" class="admin-subtab active" data-subtab="summary" role="tab">
                        Summary
                    </button>
                    <button type="button" class="admin-subtab" data-subtab="deliveries" role="tab">
                        Recent Deliveries <span class="subtab-count"><?php echo count($openDeliveries); ?></span>
                    </button>
                </div>

                <!-- Sub-phase: Summary -->
                <div class="admin-modal-phase active" data-phase="summary" role="tabpanel">
                    <h3 class="admin-section-heading">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/list-settings-fill.svg" alt="" width="14"
                            height="14" class="btn-icon-no-filter">
                        Account Summary
                    </h3>
                    <div class="admin-detail-grid">
                        <div class="admin-detail-item">
                            <span class="admin-detail-label">Joined</span>
                            <span
                                class="admin-detail-value"><?php echo htmlspecialchars(formatAdminDate((string)($openRider['date_created'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="admin-detail-item">
                            <span class="admin-detail-label">Account Status</span>
                            <span class="admin-detail-value">
                                <span class="badge <?php echo $isActive ? 'badge-success' : 'badge-secondary'; ?>">
                                    <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                                </span>
                            </span>
                        </div>
                        <div class="admin-detail-item">
                            <span class="admin-detail-label">Wallet Balance</span>
                            <span
                                class="admin-detail-value"><?php echo formatAdminCurrency((float)($openRider['balance'] ?? 0)); ?></span>
                        </div>
                        <div class="admin-detail-item">
                            <span class="admin-detail-label">Verified At</span>
                            <span
                                class="admin-detail-value"><?php echo htmlspecialchars(formatAdminDate((string)($openRider['verified_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Sub-phase: Recent Deliveries -->
                <div class="admin-modal-phase" data-phase="deliveries" role="tabpanel">
                    <h3 class="admin-section-heading">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/order.svg" alt="" width="14" height="14"
                            class="btn-icon-no-filter">
                        Recent Deliveries
                        <span class="section-count"><?php echo count($openDeliveries); ?></span>
                    </h3>
                    <?php if (empty($openDeliveries)): ?>
                    <div class="admin-doc-empty">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/order.svg" alt="">
                        <span>No deliveries yet.</span>
                    </div>
                    <?php else: ?>
                    <div class="admin-orders-list">
                        <?php foreach ($openDeliveries as $d): ?>
                        <div class="admin-order-row">
                            <div class="admin-order-id-block">
                                <span class="admin-order-id">Order #<?php echo (int)$d['order_id']; ?></span>
                                <span class="admin-order-customer">
                                    <?php echo htmlspecialchars((string)$d['customer_name'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </div>
                            <span class="badge <?php echo adminOrderStatusBadgeClass((string)$d['order_status']); ?>">
                                <?php echo adminOrderStatusLabel((string)$d['order_status']); ?>
                            </span>
                            <span class="admin-order-total">
                                <?php echo formatAdminCurrency((float)$d['order_total']); ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

            </div><!-- /account -->

        </div>

        <div class="admin-modal-tab-footer">
            <button type="button" class="tab-arrow" data-phase-prev aria-label="Previous">
                <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-left-s-line.svg" alt="" width="16"
                    height="16">
            </button>

            <div class="admin-modal-footer-center">
                <div class="admin-modal-footer-actions">

                    <?php if ($verification === 'pending'): ?>

                    <form method="POST" action="../backend/handlers/admin-handler.php">
                        <input type="hidden" name="csrf_token"
                            value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="set_rider_verification">
                        <input type="hidden" name="rider_id"
                            value="<?php echo (int)$openRider['delivery_rider_id']; ?>">
                        <input type="hidden" name="status" value="verified">
                        <input type="hidden" name="redirect_to" value="riders.php">
                        <button type="submit" class="btn btn-primary btn-sm">Verify</button>
                    </form>

                    <form method="POST" action="../backend/handlers/admin-handler.php">
                        <input type="hidden" name="csrf_token"
                            value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="set_rider_verification">
                        <input type="hidden" name="rider_id"
                            value="<?php echo (int)$openRider['delivery_rider_id']; ?>">
                        <input type="hidden" name="status" value="denied">
                        <input type="hidden" name="redirect_to" value="riders.php">
                        <button type="submit" class="btn btn-danger btn-sm">Deny</button>
                    </form>

                    <?php elseif ($verification === 'verified'): ?>

                    <form method="POST" action="../backend/handlers/admin-handler.php">
                        <input type="hidden" name="csrf_token"
                            value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="set_rider_verification">
                        <input type="hidden" name="rider_id"
                            value="<?php echo (int)$openRider['delivery_rider_id']; ?>">
                        <input type="hidden" name="status" value="suspended">
                        <input type="hidden" name="redirect_to" value="riders.php">
                        <button type="submit" class="btn btn-danger btn-sm">Suspend</button>
                    </form>

                    <?php elseif ($verification === 'denied'): ?>

                    <form method="POST" action="../backend/handlers/admin-handler.php">
                        <input type="hidden" name="csrf_token"
                            value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="set_rider_verification">
                        <input type="hidden" name="rider_id"
                            value="<?php echo (int)$openRider['delivery_rider_id']; ?>">
                        <input type="hidden" name="status" value="verified">
                        <input type="hidden" name="redirect_to" value="riders.php">
                        <button type="submit" class="btn btn-primary btn-sm">Verify</button>
                    </form>

                    <form method="POST" action="../backend/handlers/admin-handler.php">
                        <input type="hidden" name="csrf_token"
                            value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="set_rider_verification">
                        <input type="hidden" name="rider_id"
                            value="<?php echo (int)$openRider['delivery_rider_id']; ?>">
                        <input type="hidden" name="status" value="suspended">
                        <input type="hidden" name="redirect_to" value="riders.php">
                        <button type="submit" class="btn btn-danger btn-sm">Suspend</button>
                    </form>

                    <?php elseif ($verification === 'suspended'): ?>

                    <form method="POST" action="../backend/handlers/admin-handler.php">
                        <input type="hidden" name="csrf_token"
                            value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="set_rider_verification">
                        <input type="hidden" name="rider_id"
                            value="<?php echo (int)$openRider['delivery_rider_id']; ?>">
                        <input type="hidden" name="status" value="pending">
                        <input type="hidden" name="redirect_to" value="riders.php">
                        <button type="submit" class="btn btn-primary btn-sm">Remove Suspension</button>
                    </form>

                    <?php endif; ?>

                </div>
            </div>

            <button type="button" class="tab-arrow" data-phase-next aria-label="Next">
                <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-right-s-line.svg" alt="" width="16"
                    height="16">
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="../assets/ui/js/admin-modal.js" defer></script>
<script src="../assets/ui/js/riders.js" defer></script>
<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>