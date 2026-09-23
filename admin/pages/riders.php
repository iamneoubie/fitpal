<?php
/**
 * FitPal Admin — Riders List
 *
 * Paginated rider list with verification tabs. Row actions trigger
 * the confirm modal. The detail modal has three tabs: Information,
 * Documents, and Deliveries. Documents uses its own 5-per-page
 * pagination so profile picture and license photos are readable.
 *
 * No inline CSS. No inline JS. Styles come from riders.css. Behavior
 * comes from riders.js.
 *
 * @package FitPal
 * @version 3.5 — Version bump to match the CSRF consolidation in
 *                header.php v5.0 and includes/admin-csrf-token.php
 *                v1.0. No functional change: this page already reads
 *                $csrfToken from header.php and never generated the
 *                token itself. (3.4: Added data-csrf-token to the
 *                top-level .admin-list-page container so riders.js
 *                can read the admin-scoped CSRF token from the DOM
 *                instead of the window.FITPAL_ADMIN_RIDERS global
 *                that no page ever emitted. Mirrors the
 *                restaurants.js pattern established in
 *                restaurants.php v3.3 and profile.php's
 *                data-asset-base contract. No inline JS added; the
 *                token travels as a data attribute, and the POST
 *                field name in the modal footer forms is unchanged.)
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

$page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$search  = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$status  = isset($_GET['status']) ? (string)$_GET['status'] : 'all';
$openId  = isset($_GET['open']) ? (int)$_GET['open'] : 0;
$docPage = isset($_GET['doc_page']) ? max(1, (int)$_GET['doc_page']) : 1;
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

// $csrfToken is provided by header.php (admin_csrf_token).

function buildRiderUrl(array $overrides = []): string
{
    $params = [
        'page'     => $_GET['page']     ?? 1,
        'search'   => $_GET['search']   ?? '',
        'status'   => $_GET['status']   ?? 'all',
        'doc_page' => $_GET['doc_page'] ?? 1,
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

function riderMediaUrl(string $assetBase, string $relPath): string
{
    if ($relPath === '') return '';
    $projectRoot = preg_replace('#shared/$#', '', $assetBase);
    return (is_string($projectRoot) ? $projectRoot : '') . $relPath;
}
?>

<div class="content admin-list-page" data-csrf-token="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
    <div class="container">

        <header class="admin-page-header">
            <div class="admin-page-header-left">
                <h1 class="heading-2">Rider <span>Management</span></h1>
                <p class="text-muted">Review rider applications and manage accounts.</p>
            </div>
            <div class="admin-page-header-actions">
                <a href="dashboard.php" class="btn btn-outline btn-sm">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-left-line.svg" alt=""
                        class="btn-icon btn-icon-no-filter btn-icon-16" width="16" height="16">
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
                    placeholder="Search by name, email, or username…"
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
                    $picUrl = $pic !== '' ? riderMediaUrl($assetBase, $pic) : '';
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
                                <?php echo htmlspecialchars(ucfirst((string)($r['vehicle_type'] ?? '—')), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <span class="admin-cell-meta-item">
                                <?php echo htmlspecialchars((string)($r['vehicle_plate'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
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
                                Rating: <?php echo number_format((float)($r['average_rating'] ?? 0), 1); ?>
                            </span>
                            <span class="admin-cell-meta-item">
                                <?php echo number_format((int)($r['total_deliveries'] ?? 0)); ?> deliveries
                            </span>
                        </div>
                    </div>

                    <div class="admin-cell-actions">
                        <a href="<?php echo htmlspecialchars(buildRiderUrl(['open' => $rid, 'doc_page' => 1]), ENT_QUOTES, 'UTF-8'); ?>"
                            class="btn btn-outline btn-sm">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/pages-line.svg" alt=""
                                class="btn-icon btn-icon-no-filter btn-icon-14" width="14" height="14">
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

<div class="admin-modal <?php echo $openRider ? 'is-open' : ''; ?>" id="riderDetailsModal"
    aria-hidden="<?php echo $openRider ? 'false' : 'true'; ?>" role="dialog">
    <div class="admin-modal-backdrop"
        data-close-url="<?php echo htmlspecialchars(buildRiderUrl(['open' => null, 'doc_page' => null]), ENT_QUOTES, 'UTF-8'); ?>">
    </div>
    <div class="admin-modal-panel admin-modal-panel-wide" role="document">
        <div class="admin-modal-header">
            <div class="admin-modal-header-left">
                <p class="admin-modal-title">
                    <?php echo $openRider
                        ? htmlspecialchars(adminName($openRider), ENT_QUOTES, 'UTF-8')
                        : 'Rider Details'; ?>
                </p>
                <p class="admin-modal-subtitle">
                    <?php echo $openRider
                        ? htmlspecialchars((string)$openRider['email'], ENT_QUOTES, 'UTF-8')
                        : 'Select a rider to review their application.'; ?>
                </p>
            </div>
            <a href="<?php echo htmlspecialchars(buildRiderUrl(['open' => null, 'doc_page' => null]), ENT_QUOTES, 'UTF-8'); ?>"
                class="admin-modal-close" aria-label="Close">&times;</a>
        </div>

        <?php if (!$openRider): ?>
        <div class="admin-modal-panel-body">
            <p class="admin-detail-value admin-detail-value-muted">No rider selected.</p>
        </div>
        <?php else: ?>

        <?php
        $openVerification = (string)($openRider['verification_status'] ?? 'pending');
        $openIsActive = (int)$openRider['is_active'] === 1;

        $docsPerPage = 5;
        $totalDocs = count($openDocuments);
        $totalDocPages = max(1, (int)ceil($totalDocs / $docsPerPage));
        if ($docPage > $totalDocPages) $docPage = $totalDocPages;
        $docOffset = ($docPage - 1) * $docsPerPage;
        $visibleDocs = array_slice($openDocuments, $docOffset, $docsPerPage);
        ?>

        <div class="admin-modal-tabs">
            <button type="button" class="admin-modal-tab active" data-tab-target="rider-panel-info">
                <img src="<?php echo $assetBase; ?>assets/images/icons/riding-fill.svg" alt="" width="14" height="14"
                    class="btn-icon-no-filter">
                <span>Information</span>
            </button>
            <button type="button" class="admin-modal-tab" data-tab-target="rider-panel-documents">
                <img src="<?php echo $assetBase; ?>assets/images/icons/id-card-line.svg" alt="" width="14" height="14"
                    class="btn-icon-no-filter">
                <span>Documents</span>
                <span class="tab-count"><?php echo $totalDocs; ?></span>
            </button>
            <button type="button" class="admin-modal-tab" data-tab-target="rider-panel-deliveries">
                <img src="<?php echo $assetBase; ?>assets/images/icons/order.svg" alt="" width="14" height="14"
                    class="btn-icon-no-filter">
                <span>Deliveries</span>
                <span class="tab-count"><?php echo count($openDeliveries); ?></span>
            </button>
        </div>

        <div class="admin-modal-panel-body">

            <div class="admin-modal-tab-panel active" id="rider-panel-info">
                <div class="admin-detail-grid">
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Full Name</span>
                        <span
                            class="admin-detail-value"><?php echo htmlspecialchars(adminName($openRider), ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Username</span>
                        <span class="admin-detail-value">
                            @<?php echo htmlspecialchars((string)($openRider['username'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Email</span>
                        <span class="admin-detail-value">
                            <?php echo htmlspecialchars((string)$openRider['email'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Contact</span>
                        <span class="admin-detail-value">
                            <?php echo htmlspecialchars((string)($openRider['contact_number'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Gender</span>
                        <span class="admin-detail-value">
                            <?php echo htmlspecialchars((string)($openRider['gender'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Birthdate</span>
                        <span class="admin-detail-value">
                            <?php echo htmlspecialchars(formatAdminDateShort((string)($openRider['birthdate'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Joined</span>
                        <span class="admin-detail-value">
                            <?php echo htmlspecialchars(formatAdminDate((string)($openRider['date_created'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Verification</span>
                        <span class="admin-detail-value">
                            <span class="badge <?php echo adminVerificationBadgeClass($openVerification); ?>">
                                <?php echo adminVerificationLabel($openVerification); ?>
                            </span>
                        </span>
                    </div>
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Account</span>
                        <span class="admin-detail-value">
                            <span class="badge <?php echo $openIsActive ? 'badge-success' : 'badge-secondary'; ?>">
                                <?php echo $openIsActive ? 'Active' : 'Inactive'; ?>
                            </span>
                        </span>
                    </div>
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Wallet Balance</span>
                        <span class="admin-detail-value">
                            <?php echo formatAdminCurrency((float)($openRider['balance'] ?? 0)); ?>
                        </span>
                    </div>
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Vehicle</span>
                        <span class="admin-detail-value">
                            <?php echo htmlspecialchars(ucfirst((string)($openRider['vehicle_type'] ?? '—')), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Plate</span>
                        <span class="admin-detail-value">
                            <?php echo htmlspecialchars((string)($openRider['vehicle_plate'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Average Rating</span>
                        <span class="admin-detail-value">
                            <?php echo number_format((float)($openRider['average_rating'] ?? 0), 1); ?> / 5.0
                        </span>
                    </div>
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Total Deliveries</span>
                        <span class="admin-detail-value">
                            <?php echo number_format((int)($openRider['total_deliveries'] ?? 0)); ?>
                        </span>
                    </div>
                </div>

                <h3 class="admin-section-heading">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/location-fill.svg" alt="" width="14"
                        height="14" class="btn-icon-no-filter">
                    Primary Address
                </h3>
                <?php if ($openAddress): ?>
                <div class="admin-address-card">
                    <p class="admin-address-text">
                        <?php echo htmlspecialchars(formatRiderAddress($openAddress), ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                </div>
                <?php else: ?>
                <p class="admin-detail-value admin-detail-value-muted">No address on file.</p>
                <?php endif; ?>

                <h3 class="admin-section-heading">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/contact-us-line.svg" alt="" width="14"
                        height="14" class="btn-icon-no-filter">
                    Emergency Contacts
                    <span class="section-count"><?php echo count($openContacts); ?></span>
                </h3>

                <?php if (empty($openContacts)): ?>
                <p class="admin-detail-value admin-detail-value-muted">No emergency contacts on file.</p>
                <?php else: ?>
                <?php foreach ($openContacts as $idx => $c): ?>
                <div class="admin-contact-card">
                    <div class="admin-contact-header">
                        <p class="admin-contact-name">
                            <?php echo htmlspecialchars(adminName($c), ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                        <?php if ($idx === 0): ?>
                        <span class="admin-contact-badge">Primary</span>
                        <?php endif; ?>
                        <span class="admin-contact-badge relationship-badge-info">
                            <?php echo htmlspecialchars((string)($c['relationship'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                    <div class="admin-contact-body">
                        <span><strong>Phone:</strong>
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

            <div class="admin-modal-tab-panel" id="rider-panel-documents">
                <?php if ($totalDocs === 0): ?>
                <div class="admin-doc-empty">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/id-card-line.svg" alt="">
                    <span>No documents on file.</span>
                </div>
                <?php else: ?>

                <?php if (!empty($openRider['profile_picture'])): ?>
                <div class="admin-doc-block">
                    <div class="admin-doc-head">
                        <p class="admin-doc-title">Formal Photo</p>
                        <div class="admin-doc-meta">
                            <span>Uploaded by rider</span>
                        </div>
                    </div>
                    <div class="admin-doc-image">
                        <img src="<?php echo htmlspecialchars(riderMediaUrl($assetBase, (string)$openRider['profile_picture']), ENT_QUOTES, 'UTF-8'); ?>"
                            alt="Rider formal photo">
                    </div>
                </div>
                <?php endif; ?>

                <?php foreach ($visibleDocs as $doc): ?>
                <div class="admin-doc-block">
                    <div class="admin-doc-head">
                        <p class="admin-doc-title">Driver's License</p>
                        <div class="admin-doc-meta">
                            <span>Issued:
                                <?php echo htmlspecialchars(formatAdminDateShort((string)($doc['issue_date'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span>
                            <span>Expires:
                                <?php echo htmlspecialchars(formatAdminDateShort((string)($doc['expiry_date'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    </div>
                    <div class="admin-doc-image">
                        <img src="<?php echo htmlspecialchars(riderMediaUrl($assetBase, (string)$doc['drivers_license']), ENT_QUOTES, 'UTF-8'); ?>"
                            alt="Driver's license">
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if ($totalDocPages > 1): ?>
                <nav class="admin-doc-pagination" aria-label="Document pagination">
                    <ul class="admin-pagination-list">
                        <?php if ($docPage > 1): ?>
                        <li>
                            <a href="<?php echo htmlspecialchars(buildRiderUrl(['doc_page' => $docPage - 1, 'open' => $openId]), ENT_QUOTES, 'UTF-8'); ?>"
                                class="admin-pagination-link">Previous</a>
                        </li>
                        <?php else: ?>
                        <li><span class="admin-pagination-link disabled">Previous</span></li>
                        <?php endif; ?>

                        <?php for ($i = 1; $i <= $totalDocPages; $i++): ?>
                        <li>
                            <?php if ($i === $docPage): ?>
                            <span class="admin-pagination-link active"><?php echo $i; ?></span>
                            <?php else: ?>
                            <a href="<?php echo htmlspecialchars(buildRiderUrl(['doc_page' => $i, 'open' => $openId]), ENT_QUOTES, 'UTF-8'); ?>"
                                class="admin-pagination-link"><?php echo $i; ?></a>
                            <?php endif; ?>
                        </li>
                        <?php endfor; ?>

                        <?php if ($docPage < $totalDocPages): ?>
                        <li>
                            <a href="<?php echo htmlspecialchars(buildRiderUrl(['doc_page' => $docPage + 1, 'open' => $openId]), ENT_QUOTES, 'UTF-8'); ?>"
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

            <div class="admin-modal-tab-panel" id="rider-panel-deliveries">
                <?php if (empty($openDeliveries)): ?>
                <div class="admin-doc-empty">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/order.svg" alt="">
                    <span>No deliveries on record.</span>
                </div>
                <?php else: ?>
                <div class="admin-orders-list">
                    <?php foreach ($openDeliveries as $d): ?>
                    <div class="admin-order-row">
                        <div class="admin-order-id-block">
                            <span class="admin-order-id">Order #<?php echo (int)$d['order_id']; ?></span>
                            <span class="admin-order-customer">
                                <?php echo htmlspecialchars((string)($d['customer_name'] ?? 'Customer'), ENT_QUOTES, 'UTF-8'); ?>
                                &middot;
                                <?php echo htmlspecialchars(formatAdminDate((string)($d['delivered_at'] ?? $d['order_date'])), ENT_QUOTES, 'UTF-8'); ?>
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

        </div>

        <div class="admin-modal-footer">
            <form method="POST" action="../backend/handlers/admin-handler.php" class="form-inline">
                <input type="hidden" name="csrf_token"
                    value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="set_rider_verification">
                <input type="hidden" name="rider_id" value="<?php echo (int)$openRider['delivery_rider_id']; ?>">
                <input type="hidden" name="status" value="verified">
                <input type="hidden" name="redirect_to" value="riders.php">
                <button type="submit" class="btn btn-primary btn-sm"
                    <?php echo $openVerification === 'verified' ? 'disabled' : ''; ?>>
                    Approve
                </button>
            </form>

            <form method="POST" action="../backend/handlers/admin-handler.php" class="form-inline">
                <input type="hidden" name="csrf_token"
                    value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="set_rider_verification">
                <input type="hidden" name="rider_id" value="<?php echo (int)$openRider['delivery_rider_id']; ?>">
                <input type="hidden" name="status" value="denied">
                <input type="hidden" name="redirect_to" value="riders.php">
                <button type="submit" class="btn btn-danger btn-sm"
                    <?php echo $openVerification === 'denied' ? 'disabled' : ''; ?>>
                    Deny
                </button>
            </form>

            <form method="POST" action="../backend/handlers/admin-handler.php" class="form-inline">
                <input type="hidden" name="csrf_token"
                    value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="toggle_rider">
                <input type="hidden" name="rider_id" value="<?php echo (int)$openRider['delivery_rider_id']; ?>">
                <input type="hidden" name="activate" value="<?php echo $openIsActive ? '0' : '1'; ?>">
                <input type="hidden" name="redirect_to" value="riders.php">
                <button type="submit" class="btn btn-outline btn-sm">
                    <?php echo $openIsActive ? 'Deactivate' : 'Activate'; ?>
                </button>
            </form>

            <a href="<?php echo htmlspecialchars(buildRiderUrl(['open' => null, 'doc_page' => null]), ENT_QUOTES, 'UTF-8'); ?>"
                class="btn btn-outline btn-sm">Close</a>
        </div>

        <?php endif; ?>
    </div>
</div>

<script src="../assets/ui/js/riders.js" defer></script>
<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>