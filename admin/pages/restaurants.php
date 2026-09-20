<?php
/**
 * FitPal Admin — Restaurants List
 *
 * Paginated restaurant list with verification tabs, search, and a
 * detail modal that shows branches and account holders.
 *
 * No inline CSS. No inline JS. Styles come from restaurants.css.
 * Behavior comes from restaurants.js.
 *
 * @package FitPal
 * @version 3.1 — Removed inline onerror from empty-state icon and
 *                inline onclick from modal backdrop. Icon fallback
 *                travels as data-fallback-src; backdrop close URL
 *                travels as data-close-url. Both consumed by
 *                restaurants.js.
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

$counts = getRestaurantCountsByStatus($database_connection);
$data   = getRestaurantsPaginated($database_connection, $page, $perPage, $search, $status);
$rows   = $data['rows'];
$pagination = $data;

$openRestaurant = null;
$openBranches = [];
$openAccounts = [];

if ($openId > 0) {
    $openRestaurant = getRestaurantDetails($database_connection, $openId);
    if ($openRestaurant) {
        $openBranches = getRestaurantBranches($database_connection, $openId);
        $openAccounts = getRestaurantAccounts($database_connection, $openId);
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function buildRestaurantUrl(array $overrides = []): string
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

        <header class="admin-page-header">
            <div class="admin-page-header-left">
                <h1 class="heading-2">Restaurant <span>Management</span></h1>
                <p class="text-muted">Review and manage restaurant partners.</p>
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
                    placeholder="Search by business name or cuisine…"
                    value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="admin-search-btn">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/search-line.svg" alt=""
                        class="btn-icon btn-icon-14" width="14" height="14">
                    <span>Search</span>
                </button>
            </form>

            <nav class="admin-filter-tabs" aria-label="Restaurant verification filter">
                <a href="<?php echo htmlspecialchars(buildRestaurantUrl(['status' => 'all', 'page' => 1]), ENT_QUOTES, 'UTF-8'); ?>"
                    class="admin-filter-tab <?php echo $status === 'all' ? 'active' : ''; ?>">
                    All <span class="filter-count"><?php echo number_format($counts['all']); ?></span>
                </a>
                <a href="<?php echo htmlspecialchars(buildRestaurantUrl(['status' => 'pending', 'page' => 1]), ENT_QUOTES, 'UTF-8'); ?>"
                    class="admin-filter-tab <?php echo $status === 'pending' ? 'active' : ''; ?>">
                    Pending <span class="filter-count"><?php echo number_format($counts['pending']); ?></span>
                </a>
                <a href="<?php echo htmlspecialchars(buildRestaurantUrl(['status' => 'verified', 'page' => 1]), ENT_QUOTES, 'UTF-8'); ?>"
                    class="admin-filter-tab <?php echo $status === 'verified' ? 'active' : ''; ?>">
                    Verified <span class="filter-count"><?php echo number_format($counts['verified']); ?></span>
                </a>
                <a href="<?php echo htmlspecialchars(buildRestaurantUrl(['status' => 'denied', 'page' => 1]), ENT_QUOTES, 'UTF-8'); ?>"
                    class="admin-filter-tab <?php echo $status === 'denied' ? 'active' : ''; ?>">
                    Denied <span class="filter-count"><?php echo number_format($counts['denied']); ?></span>
                </a>
                <a href="<?php echo htmlspecialchars(buildRestaurantUrl(['status' => 'suspended', 'page' => 1]), ENT_QUOTES, 'UTF-8'); ?>"
                    class="admin-filter-tab <?php echo $status === 'suspended' ? 'active' : ''; ?>">
                    Suspended <span class="filter-count"><?php echo number_format($counts['suspended']); ?></span>
                </a>
            </nav>
        </div>

        <div class="admin-table-card">
            <?php if (empty($rows)): ?>
            <div class="admin-table-empty">
                <div class="admin-table-empty-icon" aria-hidden="true"
                    data-fallback-src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/search-line.svg" alt="">
                </div>
                <p class="admin-table-empty-title">No restaurants found</p>
                <p class="admin-table-empty-text">
                    <?php if ($search !== ''): ?>
                    No results for "<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>".
                    <?php else: ?>
                    Try a different filter.
                    <?php endif; ?>
                </p>
            </div>
            <?php else: ?>
            <div class="admin-table-restaurants">
                <?php foreach ($rows as $r):
                    $rid = (int)$r['restaurant_id'];
                    $name = (string)$r['business_name'];
                    $verification = (string)($r['verification_status'] ?? 'pending');
                    $isActive = (int)$r['is_active'] === 1;
                ?>
                <div class="admin-table-row">
                    <div class="admin-cell-body">
                        <p class="admin-cell-title"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></p>
                        <p class="admin-cell-subtitle">
                            <?php echo htmlspecialchars((string)($r['cuisine_type'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                        </p>
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
                                <?php echo number_format((int)($r['branch_count'] ?? 0)); ?> branches
                            </span>
                            <span class="admin-cell-meta-item">
                                <?php echo number_format((int)($r['product_count'] ?? 0)); ?> products
                            </span>
                        </div>
                    </div>

                    <div class="admin-cell-actions">
                        <a href="<?php echo htmlspecialchars(buildRestaurantUrl(['open' => $rid]), ENT_QUOTES, 'UTF-8'); ?>"
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
            <nav class="admin-pagination" aria-label="Restaurant pagination">
                <span class="admin-pagination-info">
                    Showing page <?php echo $pagination['page']; ?> of <?php echo $pagination['totalPages']; ?>
                    (<?php echo number_format($pagination['total']); ?> total)
                </span>
                <ul class="admin-pagination-list">
                    <?php if ($pagination['page'] > 1): ?>
                    <li>
                        <a href="<?php echo htmlspecialchars(buildRestaurantUrl(['page' => $pagination['page'] - 1, 'open' => null]), ENT_QUOTES, 'UTF-8'); ?>"
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
                        <a href="<?php echo htmlspecialchars(buildRestaurantUrl(['page' => 1, 'open' => null]), ENT_QUOTES, 'UTF-8'); ?>"
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
                        <a href="<?php echo htmlspecialchars(buildRestaurantUrl(['page' => $i, 'open' => null]), ENT_QUOTES, 'UTF-8'); ?>"
                            class="admin-pagination-link"><?php echo $i; ?></a>
                        <?php endif; ?>
                    </li>
                    <?php endfor; ?>

                    <?php if ($end < $pagination['totalPages']): ?>
                    <?php if ($end < $pagination['totalPages'] - 1): ?>
                    <li><span class="admin-pagination-ellipsis">…</span></li>
                    <?php endif; ?>
                    <li>
                        <a href="<?php echo htmlspecialchars(buildRestaurantUrl(['page' => $pagination['totalPages'], 'open' => null]), ENT_QUOTES, 'UTF-8'); ?>"
                            class="admin-pagination-link"><?php echo $pagination['totalPages']; ?></a>
                    </li>
                    <?php endif; ?>

                    <?php if ($pagination['page'] < $pagination['totalPages']): ?>
                    <li>
                        <a href="<?php echo htmlspecialchars(buildRestaurantUrl(['page' => $pagination['page'] + 1, 'open' => null]), ENT_QUOTES, 'UTF-8'); ?>"
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

<div class="admin-modal <?php echo $openRestaurant ? 'is-open' : ''; ?>" id="restaurantDetailsModal"
    aria-hidden="<?php echo $openRestaurant ? 'false' : 'true'; ?>" role="dialog">
    <div class="admin-modal-backdrop"
        data-close-url="<?php echo htmlspecialchars(buildRestaurantUrl(['open' => null]), ENT_QUOTES, 'UTF-8'); ?>">
    </div>
    <div class="admin-modal-panel admin-modal-panel-wide" role="document">
        <div class="admin-modal-header">
            <div class="admin-modal-header-left">
                <p class="admin-modal-title">
                    <?php echo $openRestaurant
                        ? htmlspecialchars((string)$openRestaurant['business_name'], ENT_QUOTES, 'UTF-8')
                        : 'Restaurant Details'; ?>
                </p>
                <p class="admin-modal-subtitle">
                    <?php echo $openRestaurant
                        ? htmlspecialchars((string)($openRestaurant['cuisine_type'] ?? ''), ENT_QUOTES, 'UTF-8')
                        : 'Select a restaurant to review.'; ?>
                </p>
            </div>
            <a href="<?php echo htmlspecialchars(buildRestaurantUrl(['open' => null]), ENT_QUOTES, 'UTF-8'); ?>"
                class="admin-modal-close" aria-label="Close">&times;</a>
        </div>

        <?php if (!$openRestaurant): ?>
        <div class="admin-modal-panel-body">
            <p class="admin-detail-value admin-detail-value-muted">No restaurant selected.</p>
        </div>
        <?php else: ?>

        <?php
        $openVerification = (string)($openRestaurant['verification_status'] ?? 'pending');
        $openIsActive = (int)$openRestaurant['is_active'] === 1;
        ?>

        <div class="admin-modal-tabs">
            <button type="button" class="admin-modal-tab active" data-tab-target="rest-panel-info">
                <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt="" width="14" height="14"
                    class="btn-icon-no-filter">
                <span>Info</span>
            </button>
            <button type="button" class="admin-modal-tab" data-tab-target="rest-panel-branches">
                <img src="<?php echo $assetBase; ?>assets/images/icons/location-fill.svg" alt="" width="14" height="14"
                    class="btn-icon-no-filter">
                <span>Branches</span>
                <span class="tab-count"><?php echo count($openBranches); ?></span>
            </button>
            <button type="button" class="admin-modal-tab" data-tab-target="rest-panel-accounts">
                <img src="<?php echo $assetBase; ?>assets/images/icons/people-team.svg" alt="" width="14" height="14"
                    class="btn-icon-no-filter">
                <span>Accounts</span>
                <span class="tab-count"><?php echo count($openAccounts); ?></span>
            </button>
        </div>

        <div class="admin-modal-panel-body">

            <div class="admin-modal-tab-panel active" id="rest-panel-info">
                <div class="admin-detail-grid">
                    <div class="admin-detail-item admin-detail-item-full">
                        <span class="admin-detail-label">Business Name</span>
                        <span class="admin-detail-value">
                            <?php echo htmlspecialchars((string)$openRestaurant['business_name'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Cuisine</span>
                        <span class="admin-detail-value">
                            <?php echo htmlspecialchars((string)($openRestaurant['cuisine_type'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
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
                        <span class="admin-detail-label">Account Status</span>
                        <span class="admin-detail-value">
                            <span class="badge <?php echo $openIsActive ? 'badge-success' : 'badge-secondary'; ?>">
                                <?php echo $openIsActive ? 'Active' : 'Inactive'; ?>
                            </span>
                        </span>
                    </div>
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Branches</span>
                        <span class="admin-detail-value">
                            <?php echo number_format((int)($openRestaurant['branch_count'] ?? 0)); ?>
                        </span>
                    </div>
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Products</span>
                        <span class="admin-detail-value">
                            <?php echo number_format((int)($openRestaurant['product_count'] ?? 0)); ?>
                        </span>
                    </div>
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Created</span>
                        <span class="admin-detail-value">
                            <?php echo htmlspecialchars(formatAdminDate((string)($openRestaurant['created_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Verified At</span>
                        <span class="admin-detail-value">
                            <?php echo htmlspecialchars(formatAdminDate((string)($openRestaurant['verified_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                    <div class="admin-detail-item admin-detail-item-full">
                        <span class="admin-detail-label">Dietary Tags</span>
                        <span class="admin-detail-value">
                            <?php
                            $tags = parseAdminTagList((string)($openRestaurant['dietary_tags'] ?? ''));
                            echo empty($tags)
                                ? '—'
                                : htmlspecialchars(implode(', ', array_map(
                                    fn($t) => ucwords(str_replace('_', ' ', $t)),
                                    $tags
                                )), ENT_QUOTES, 'UTF-8');
                            ?>
                        </span>
                    </div>
                    <div class="admin-detail-item admin-detail-item-full">
                        <span class="admin-detail-label">Description</span>
                        <span class="admin-detail-value">
                            <?php echo nl2br(htmlspecialchars((string)($openRestaurant['description'] ?? '—'), ENT_QUOTES, 'UTF-8')); ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="admin-modal-tab-panel" id="rest-panel-branches">
                <?php if (empty($openBranches)): ?>
                <div class="admin-doc-empty">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/location-fill.svg" alt="">
                    <span>No branches on file.</span>
                </div>
                <?php else: ?>
                <?php foreach ($openBranches as $b): ?>
                <div class="admin-address-card">
                    <div class="admin-address-label">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/building.svg" alt="" width="14"
                            height="14" class="btn-icon-no-filter">
                        <span><?php echo htmlspecialchars((string)$b['branch_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="admin-contact-badge branch-code-badge">
                            <?php echo htmlspecialchars((string)$b['branch_code'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <span
                            class="badge <?php echo (int)$b['is_active'] === 1 ? 'badge-success' : 'badge-secondary'; ?>">
                            <?php echo (int)$b['is_active'] === 1 ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>
                    <p class="admin-address-text">
                        <?php
                        $parts = array_filter([
                            $b['block'] ?? '',
                            $b['barangay'] ?? '',
                            $b['city'] ?? '',
                            $b['province'] ?? '',
                            $b['region'] ?? '',
                            $b['postal_code'] ?? '',
                            $b['country'] ?? '',
                        ]);
                        echo htmlspecialchars(implode(', ', $parts) ?: '—', ENT_QUOTES, 'UTF-8');
                        ?>
                    </p>
                    <div class="admin-cell-meta admin-cell-meta-spaced">
                        <span class="admin-cell-meta-item">
                            <?php echo number_format((int)($b['product_count'] ?? 0)); ?> products
                        </span>
                        <span class="admin-cell-meta-item">
                            Balance: <?php echo formatAdminCurrency((float)($b['balance'] ?? 0)); ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="admin-modal-tab-panel" id="rest-panel-accounts">
                <?php if (empty($openAccounts)): ?>
                <div class="admin-doc-empty">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/people-team.svg" alt="">
                    <span>No accounts on file.</span>
                </div>
                <?php else: ?>
                <?php foreach ($openAccounts as $acc): ?>
                <div class="admin-contact-card">
                    <div class="admin-contact-header">
                        <p class="admin-contact-name">
                            <?php echo htmlspecialchars(adminName($acc), ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                        <span class="admin-contact-badge">
                            <?php echo htmlspecialchars(adminRoleLabel((string)$acc['role']), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <span
                            class="badge <?php echo (int)$acc['is_active'] === 1 ? 'badge-success' : 'badge-secondary'; ?>">
                            <?php echo (int)$acc['is_active'] === 1 ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>
                    <div class="admin-contact-body">
                        <span><strong>Email:</strong>
                            <?php echo htmlspecialchars((string)$acc['email'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <span><strong>Contact:</strong>
                            <?php echo htmlspecialchars((string)($acc['contact_number'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>

        <div class="admin-modal-footer">
            <form method="POST" action="../backend/handlers/admin-handler.php" class="form-inline">
                <input type="hidden" name="csrf_token"
                    value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="set_restaurant_verification">
                <input type="hidden" name="restaurant_id" value="<?php echo (int)$openRestaurant['restaurant_id']; ?>">
                <input type="hidden" name="status" value="verified">
                <input type="hidden" name="redirect_to" value="restaurants.php">
                <button type="submit" class="btn btn-primary btn-sm"
                    <?php echo $openVerification === 'verified' ? 'disabled' : ''; ?>>
                    Approve
                </button>
            </form>

            <form method="POST" action="../backend/handlers/admin-handler.php" class="form-inline">
                <input type="hidden" name="csrf_token"
                    value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="set_restaurant_verification">
                <input type="hidden" name="restaurant_id" value="<?php echo (int)$openRestaurant['restaurant_id']; ?>">
                <input type="hidden" name="status" value="denied">
                <input type="hidden" name="redirect_to" value="restaurants.php">
                <button type="submit" class="btn btn-danger btn-sm"
                    <?php echo $openVerification === 'denied' ? 'disabled' : ''; ?>>
                    Deny
                </button>
            </form>

            <form method="POST" action="../backend/handlers/admin-handler.php" class="form-inline">
                <input type="hidden" name="csrf_token"
                    value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="toggle_restaurant">
                <input type="hidden" name="restaurant_id" value="<?php echo (int)$openRestaurant['restaurant_id']; ?>">
                <input type="hidden" name="activate" value="<?php echo $openIsActive ? '0' : '1'; ?>">
                <input type="hidden" name="redirect_to" value="restaurants.php">
                <button type="submit" class="btn btn-outline btn-sm">
                    <?php echo $openIsActive ? 'Deactivate' : 'Activate'; ?>
                </button>
            </form>

            <a href="<?php echo htmlspecialchars(buildRestaurantUrl(['open' => null]), ENT_QUOTES, 'UTF-8'); ?>"
                class="btn btn-outline btn-sm">Close</a>
        </div>

        <?php endif; ?>
    </div>
</div>

<script src="../assets/ui/js/restaurants.js" defer></script>
<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>