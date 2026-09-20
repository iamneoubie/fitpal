<?php
/**
 * FitPal Admin — Customers List
 *
 * Paginated list of customer accounts with search, status tabs, and
 * a per-customer detail modal. All mutations go through
 * admin-handler.php via normal form POSTs. No window.confirm().
 *
 * @package FitPal
 * @version 2.0
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

$page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$search   = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$status   = isset($_GET['status']) ? (string)$_GET['status'] : 'all';
$openId   = isset($_GET['open']) ? (int)$_GET['open'] : 0;
$perPage  = 5;

$allowedStatuses = ['all', 'active', 'inactive'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'all';
}

$counts = getCustomerCountsByStatus($database_connection);
$data   = getCustomersPaginated($database_connection, $page, $perPage, $search, $status);
$rows   = $data['rows'];
$pagination = $data;

// If the URL asked us to open a specific customer, load it.
$openCustomer = null;
$openAddresses = [];
$openOrders = [];
if ($openId > 0) {
    $openCustomer = getCustomerDetails($database_connection, $openId);
    if ($openCustomer) {
        $openAddresses = getCustomerAddresses($database_connection, $openId);
        $openOrders    = getCustomerRecentOrders($database_connection, $openId, 5);
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

/**
 * Build a query string preserving current filters.
 */
function buildCustomerUrl(array $overrides = []): string
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
                <h1 class="heading-2">Customer <span>Management</span></h1>
                <p class="text-muted">Browse, search, and manage customer accounts.</p>
            </div>
            <div class="admin-page-header-actions">
                <a href="dashboard.php" class="btn btn-outline btn-sm">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-left-line.svg" alt="" class="btn-icon"
                        width="16" height="16" style="filter: none;">
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

        <!-- FILTER BAR -->
        <div class="admin-filter-bar">
            <form method="GET" action="" class="admin-search-form">
                <input type="hidden" name="status"
                    value="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="text" name="search" class="admin-search-input"
                    placeholder="Search by name, email, username, or contact…"
                    value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="admin-search-btn">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/search-line.svg" alt="" class="btn-icon"
                        width="14" height="14">
                    <span>Search</span>
                </button>
            </form>

            <nav class="admin-filter-tabs" aria-label="Customer status filter">
                <a href="<?php echo htmlspecialchars(buildCustomerUrl(['status' => 'all', 'page' => 1, 'search' => $search]), ENT_QUOTES, 'UTF-8'); ?>"
                    class="admin-filter-tab <?php echo $status === 'all' ? 'active' : ''; ?>">
                    All <span class="filter-count"><?php echo number_format($counts['all']); ?></span>
                </a>
                <a href="<?php echo htmlspecialchars(buildCustomerUrl(['status' => 'active', 'page' => 1, 'search' => $search]), ENT_QUOTES, 'UTF-8'); ?>"
                    class="admin-filter-tab <?php echo $status === 'active' ? 'active' : ''; ?>">
                    Active <span class="filter-count"><?php echo number_format($counts['active']); ?></span>
                </a>
                <a href="<?php echo htmlspecialchars(buildCustomerUrl(['status' => 'inactive', 'page' => 1, 'search' => $search]), ENT_QUOTES, 'UTF-8'); ?>"
                    class="admin-filter-tab <?php echo $status === 'inactive' ? 'active' : ''; ?>">
                    Inactive <span class="filter-count"><?php echo number_format($counts['inactive']); ?></span>
                </a>
            </nav>
        </div>

        <!-- TABLE -->
        <div class="admin-table-card">
            <?php if (empty($rows)): ?>
            <div class="admin-table-empty">
                <div class="admin-table-empty-icon" aria-hidden="true">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/search-line.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/user-profile-circle.svg'">
                </div>
                <p class="admin-table-empty-title">No customers found</p>
                <p class="admin-table-empty-text">
                    <?php if ($search !== ''): ?>
                    No results for "<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>".
                    <?php else: ?>
                    Try a different filter or clear your search.
                    <?php endif; ?>
                </p>
            </div>
            <?php else: ?>
            <div class="admin-table-customers">
                <?php foreach ($rows as $c):
                    $cid = (int)$c['customer_id'];
                    $name = adminName($c);
                    $initial = adminInitial($c);
                    $isActive = (int)$c['is_active'] === 1;
                ?>
                <div class="admin-table-row">
                    <div class="admin-cell-avatar">
                        <?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?>
                    </div>

                    <div class="admin-cell-body">
                        <p class="admin-cell-title"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></p>
                        <p class="admin-cell-subtitle">
                            <?php echo htmlspecialchars((string)$c['email'], ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                        <div class="admin-cell-meta">
                            <span class="admin-cell-meta-item">
                                <?php echo htmlspecialchars((string)($c['contact_number'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <span class="admin-cell-meta-item">
                                @<?php echo htmlspecialchars((string)($c['username'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                    </div>

                    <div class="admin-cell-body">
                        <div class="admin-cell-meta">
                            <span class="admin-cell-meta-item">
                                <strong><?php echo number_format((int)($c['order_count'] ?? 0)); ?></strong>&nbsp;orders
                            </span>
                            <span class="admin-cell-meta-item">
                                Wallet: <?php echo formatAdminCurrency((float)($c['balance'] ?? 0)); ?>
                            </span>
                        </div>
                        <div class="admin-cell-meta">
                            <span class="badge <?php echo $isActive ? 'badge-success' : 'badge-secondary'; ?>">
                                <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                            </span>
                            <span class="admin-cell-meta-item">
                                Joined
                                <?php echo htmlspecialchars(formatAdminDateShort((string)($c['date_created'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                    </div>

                    <div class="admin-cell-actions">
                        <button type="button" class="btn btn-outline btn-sm"
                            onclick="window.location.href='<?php echo htmlspecialchars(buildCustomerUrl(['open' => $cid]), ENT_QUOTES, 'UTF-8'); ?>'">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/pages-line.svg" alt=""
                                class="btn-icon" width="14" height="14" style="filter:none;">
                            <span>View Details</span>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- PAGINATION -->
            <?php if ($pagination['totalPages'] > 1): ?>
            <nav class="admin-pagination" aria-label="Customer pagination">
                <span class="admin-pagination-info">
                    Showing page <?php echo $pagination['page']; ?> of <?php echo $pagination['totalPages']; ?>
                    (<?php echo number_format($pagination['total']); ?> total)
                </span>
                <ul class="admin-pagination-list">
                    <?php if ($pagination['page'] > 1): ?>
                    <li>
                        <a href="<?php echo htmlspecialchars(buildCustomerUrl(['page' => $pagination['page'] - 1]), ENT_QUOTES, 'UTF-8'); ?>"
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
                        <a href="<?php echo htmlspecialchars(buildCustomerUrl(['page' => 1]), ENT_QUOTES, 'UTF-8'); ?>"
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
                        <a href="<?php echo htmlspecialchars(buildCustomerUrl(['page' => $i]), ENT_QUOTES, 'UTF-8'); ?>"
                            class="admin-pagination-link"><?php echo $i; ?></a>
                        <?php endif; ?>
                    </li>
                    <?php endfor; ?>

                    <?php if ($end < $pagination['totalPages']): ?>
                    <?php if ($end < $pagination['totalPages'] - 1): ?>
                    <li><span class="admin-pagination-ellipsis">…</span></li>
                    <?php endif; ?>
                    <li>
                        <a href="<?php echo htmlspecialchars(buildCustomerUrl(['page' => $pagination['totalPages']]), ENT_QUOTES, 'UTF-8'); ?>"
                            class="admin-pagination-link"><?php echo $pagination['totalPages']; ?></a>
                    </li>
                    <?php endif; ?>

                    <?php if ($pagination['page'] < $pagination['totalPages']): ?>
                    <li>
                        <a href="<?php echo htmlspecialchars(buildCustomerUrl(['page' => $pagination['page'] + 1]), ENT_QUOTES, 'UTF-8'); ?>"
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

<!-- ============================================
     CUSTOMER DETAILS MODAL
     ============================================ -->
<div class="admin-modal <?php echo $openCustomer ? 'is-open' : ''; ?>" id="customerDetailsModal"
    aria-hidden="<?php echo $openCustomer ? 'false' : 'true'; ?>" role="dialog">
    <div class="admin-modal-backdrop"
        onclick="window.location.href='<?php echo htmlspecialchars(buildCustomerUrl(['open' => null]), ENT_QUOTES, 'UTF-8'); ?>'">
    </div>
    <div class="admin-modal-panel admin-modal-panel-wide" role="document">
        <div class="admin-modal-header">
            <div class="admin-modal-header-left">
                <p class="admin-modal-title">
                    <?php echo $openCustomer
                        ? htmlspecialchars(adminName($openCustomer), ENT_QUOTES, 'UTF-8')
                        : 'Customer Details'; ?>
                </p>
                <p class="admin-modal-subtitle">
                    <?php echo $openCustomer
                        ? htmlspecialchars((string)$openCustomer['email'], ENT_QUOTES, 'UTF-8')
                        : 'Select a customer to view their details.'; ?>
                </p>
            </div>
            <a href="<?php echo htmlspecialchars(buildCustomerUrl(['open' => null]), ENT_QUOTES, 'UTF-8'); ?>"
                class="admin-modal-close" aria-label="Close">&times;</a>
        </div>

        <?php if (!$openCustomer): ?>
        <div class="admin-modal-panel-body">
            <p class="admin-detail-value admin-detail-value-muted">No customer selected.</p>
        </div>
        <?php else: ?>
        <div class="admin-modal-tabs">
            <button type="button" class="admin-modal-tab active" data-tab-target="cust-panel-info">
                <img src="<?php echo $assetBase; ?>assets/images/icons/file-user-line.svg" alt="" width="14" height="14"
                    style="filter:none;">
                <span>Info</span>
            </button>
            <button type="button" class="admin-modal-tab" data-tab-target="cust-panel-addresses">
                <img src="<?php echo $assetBase; ?>assets/images/icons/location-fill.svg" alt="" width="14" height="14"
                    style="filter:none;">
                <span>Addresses</span>
                <span class="tab-count"><?php echo count($openAddresses); ?></span>
            </button>
            <button type="button" class="admin-modal-tab" data-tab-target="cust-panel-orders">
                <img src="<?php echo $assetBase; ?>assets/images/icons/cart-shopping.svg" alt="" width="14" height="14"
                    style="filter:none;">
                <span>Recent Orders</span>
                <span class="tab-count"><?php echo count($openOrders); ?></span>
            </button>
        </div>

        <div class="admin-modal-panel-body">

            <!-- TAB: INFO -->
            <div class="admin-modal-tab-panel active" id="cust-panel-info">
                <div class="admin-detail-grid">
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Full Name</span>
                        <span class="admin-detail-value">
                            <?php echo htmlspecialchars(adminName($openCustomer), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Username</span>
                        <span class="admin-detail-value">
                            @<?php echo htmlspecialchars((string)($openCustomer['username'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Email</span>
                        <span class="admin-detail-value">
                            <?php echo htmlspecialchars((string)($openCustomer['email'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Contact</span>
                        <span class="admin-detail-value">
                            <?php echo htmlspecialchars((string)($openCustomer['contact_number'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Gender</span>
                        <span class="admin-detail-value">
                            <?php echo htmlspecialchars((string)($openCustomer['gender'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Birthdate</span>
                        <span class="admin-detail-value">
                            <?php echo htmlspecialchars(formatAdminDateShort((string)($openCustomer['birthdate'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Joined</span>
                        <span class="admin-detail-value">
                            <?php echo htmlspecialchars(formatAdminDate((string)($openCustomer['date_created'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Status</span>
                        <span class="admin-detail-value">
                            <span
                                class="badge <?php echo (int)$openCustomer['is_active'] === 1 ? 'badge-success' : 'badge-secondary'; ?>">
                                <?php echo (int)$openCustomer['is_active'] === 1 ? 'Active' : 'Inactive'; ?>
                            </span>
                        </span>
                    </div>
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Wallet Balance</span>
                        <span class="admin-detail-value">
                            <?php echo formatAdminCurrency((float)($openCustomer['balance'] ?? 0)); ?>
                        </span>
                    </div>
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Orders Placed</span>
                        <span class="admin-detail-value">
                            <?php echo number_format((int)($openCustomer['order_count'] ?? 0)); ?>
                        </span>
                    </div>

                    <div class="admin-detail-item admin-detail-item-full">
                        <span class="admin-detail-label">Fitness Goal</span>
                        <span class="admin-detail-value">
                            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($openCustomer['fitness_goal'] ?? '—'))), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>

                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Height (cm)</span>
                        <span class="admin-detail-value">
                            <?php echo htmlspecialchars((string)($openCustomer['height_cm'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                    <div class="admin-detail-item">
                        <span class="admin-detail-label">Weight (kg)</span>
                        <span class="admin-detail-value">
                            <?php echo htmlspecialchars((string)($openCustomer['weight_kg'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>

                    <div class="admin-detail-item admin-detail-item-full">
                        <span class="admin-detail-label">Dietary Preferences</span>
                        <span class="admin-detail-value">
                            <?php
                            $diet = parseAdminTagList((string)($openCustomer['dietary_preferences'] ?? ''));
                            echo empty($diet)
                                ? '—'
                                : htmlspecialchars(implode(', ', array_map(
                                    fn($t) => ucwords(str_replace('_', ' ', $t)),
                                    $diet
                                )), ENT_QUOTES, 'UTF-8');
                            ?>
                        </span>
                    </div>

                    <div class="admin-detail-item admin-detail-item-full">
                        <span class="admin-detail-label">Allergies</span>
                        <span class="admin-detail-value">
                            <?php
                            $alg = parseAdminTagList((string)($openCustomer['allergies'] ?? ''));
                            echo empty($alg)
                                ? '—'
                                : htmlspecialchars(implode(', ', array_map(
                                    fn($t) => ucwords(str_replace('_', ' ', $t)),
                                    $alg
                                )), ENT_QUOTES, 'UTF-8');
                            ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- TAB: ADDRESSES -->
            <div class="admin-modal-tab-panel" id="cust-panel-addresses">
                <?php if (empty($openAddresses)): ?>
                <div class="admin-doc-empty">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/location-fill.svg" alt="">
                    <span>No addresses on file.</span>
                </div>
                <?php else: ?>
                <?php foreach ($openAddresses as $addr): ?>
                <div class="admin-address-card">
                    <div class="admin-address-label">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/location-fill.svg" alt="" width="14"
                            height="14" style="filter:none;">
                        <span><?php echo htmlspecialchars((string)($addr['label'] ?? 'Address'), ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php if ((int)($addr['is_default'] ?? 0) === 1): ?>
                        <span class="admin-contact-badge">Default</span>
                        <?php endif; ?>
                    </div>
                    <p class="admin-address-text">
                        <?php echo htmlspecialchars(formatCustomerAddress($addr), ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- TAB: RECENT ORDERS -->
            <div class="admin-modal-tab-panel" id="cust-panel-orders">
                <?php if (empty($openOrders)): ?>
                <div class="admin-doc-empty">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/cart-shopping.svg" alt="">
                    <span>No orders yet.</span>
                </div>
                <?php else: ?>
                <div class="admin-orders-list">
                    <?php foreach ($openOrders as $o): ?>
                    <div class="admin-order-row">
                        <div class="admin-order-id-block">
                            <span class="admin-order-id">Order #<?php echo (int)$o['order_id']; ?></span>
                            <span class="admin-order-customer">
                                <?php echo htmlspecialchars(formatAdminDate((string)$o['order_date']), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                        <span class="badge <?php echo adminOrderStatusBadgeClass((string)$o['order_status']); ?>">
                            <?php echo adminOrderStatusLabel((string)$o['order_status']); ?>
                        </span>
                        <span class="admin-order-total">
                            <?php echo formatAdminCurrency((float)$o['order_total']); ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

        </div>

        <div class="admin-modal-footer">
            <form method="POST" action="../backend/handlers/admin-handler.php" style="display:inline;">
                <input type="hidden" name="csrf_token"
                    value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="toggle_customer">
                <input type="hidden" name="customer_id" value="<?php echo (int)$openCustomer['customer_id']; ?>">
                <input type="hidden" name="activate"
                    value="<?php echo (int)$openCustomer['is_active'] === 1 ? '0' : '1'; ?>">
                <input type="hidden" name="redirect_to" value="customers.php">
                <button type="submit"
                    class="btn <?php echo (int)$openCustomer['is_active'] === 1 ? 'btn-danger' : 'btn-primary'; ?> btn-sm">
                    <?php echo (int)$openCustomer['is_active'] === 1 ? 'Deactivate Customer' : 'Activate Customer'; ?>
                </button>
            </form>
            <a href="<?php echo htmlspecialchars(buildCustomerUrl(['open' => null]), ENT_QUOTES, 'UTF-8'); ?>"
                class="btn btn-outline btn-sm">Close</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="../assets/ui/js/dashboard.js" defer></script>
<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>