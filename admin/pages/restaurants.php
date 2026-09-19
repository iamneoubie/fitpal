<?php
/**
 * FitPal Admin — Restaurant Verification
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

// ------------------------------------------------------------------
// INPUT
// ------------------------------------------------------------------
$search  = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$status  = isset($_GET['status']) ? (string)$_GET['status'] : '';
$page    = isset($_GET['page'])   ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per'])    ? (int)$_GET['per'] : 10;

$allowedPerPage  = [10, 25, 50];
$allowedStatuses = ['', 'pending', 'verified', 'denied', 'suspended'];

if (!in_array($perPage, $allowedPerPage, true))   $perPage = 10;
if (!in_array($status,  $allowedStatuses, true))  $status  = '';

// ------------------------------------------------------------------
// DATA
// ------------------------------------------------------------------
$counts = getRestaurantStatusCounts($database_connection);

$result = getAdminRestaurantsPaginated(
    $database_connection,
    $search,
    $status,
    $page,
    $perPage
);

$restaurants = $result['items'];
$total       = $result['total'];
$totalPages  = $result['totalPages'];
$page        = $result['page'];

$rangeStart = $total > 0 ? (($page - 1) * $perPage) + 1 : 0;
$rangeEnd   = $total > 0 ? min($page * $perPage, $total) : 0;

$pageWindow = buildAdminPageWindow($page, $totalPages, 5);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$linkParams = [
    'search' => $search,
    'status' => $status,
    'per'    => $perPage,
];
$qs = static function (array $overrides = []) use ($linkParams): string {
    $params = array_merge($linkParams, $overrides);
    $params = array_filter($params, static fn($v) => $v !== '' && $v !== null);
    return $params ? ('?' . http_build_query($params)) : '';
};
?>

<div class="content admin-list-page">
    <div class="container">

        <header class="admin-page-header">
            <div class="admin-page-header-text">
                <h1 class="admin-page-title">Restaurant <span>Verification</span></h1>
                <p class="admin-page-subtitle">
                    Review partner restaurants and issue verification decisions.
                </p>
            </div>
            <div class="admin-page-header-actions">
                <a href="dashboard.php" class="btn btn-outline btn-sm">Back to Dashboard</a>
            </div>
        </header>

        <?php if (isset($_SESSION['admin_success'])): ?>
        <div class="alert alert-success" role="alert">
            <?php echo htmlspecialchars($_SESSION['admin_success'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['admin_success']); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['admin_error'])): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo htmlspecialchars($_SESSION['admin_error'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['admin_error']); ?>
        </div>
        <?php endif; ?>

        <section class="admin-summary-strip" aria-label="Restaurant status summary">
            <a href="restaurants.php<?php echo $qs(['status' => '', 'page' => 1]); ?>"
                class="admin-summary-tile <?php echo $status === '' ? 'is-active' : ''; ?>">
                <span class="admin-summary-dot dot-total" aria-hidden="true"></span>
                <span class="admin-summary-body">
                    <span class="admin-summary-count"><?php echo number_format($counts['total']); ?></span>
                    <span class="admin-summary-label">All</span>
                </span>
            </a>
            <a href="restaurants.php<?php echo $qs(['status' => 'pending', 'page' => 1]); ?>"
                class="admin-summary-tile <?php echo $status === 'pending' ? 'is-active' : ''; ?>">
                <span class="admin-summary-dot dot-pending" aria-hidden="true"></span>
                <span class="admin-summary-body">
                    <span class="admin-summary-count"><?php echo number_format($counts['pending']); ?></span>
                    <span class="admin-summary-label">Pending</span>
                </span>
            </a>
            <a href="restaurants.php<?php echo $qs(['status' => 'verified', 'page' => 1]); ?>"
                class="admin-summary-tile <?php echo $status === 'verified' ? 'is-active' : ''; ?>">
                <span class="admin-summary-dot dot-verified" aria-hidden="true"></span>
                <span class="admin-summary-body">
                    <span class="admin-summary-count"><?php echo number_format($counts['verified']); ?></span>
                    <span class="admin-summary-label">Verified</span>
                </span>
            </a>
            <a href="restaurants.php<?php echo $qs(['status' => 'denied', 'page' => 1]); ?>"
                class="admin-summary-tile <?php echo $status === 'denied' ? 'is-active' : ''; ?>">
                <span class="admin-summary-dot dot-denied" aria-hidden="true"></span>
                <span class="admin-summary-body">
                    <span class="admin-summary-count"><?php echo number_format($counts['denied']); ?></span>
                    <span class="admin-summary-label">Denied</span>
                </span>
            </a>
            <a href="restaurants.php<?php echo $qs(['status' => 'suspended', 'page' => 1]); ?>"
                class="admin-summary-tile <?php echo $status === 'suspended' ? 'is-active' : ''; ?>">
                <span class="admin-summary-dot dot-suspended" aria-hidden="true"></span>
                <span class="admin-summary-body">
                    <span class="admin-summary-count"><?php echo number_format($counts['suspended']); ?></span>
                    <span class="admin-summary-label">Suspended</span>
                </span>
            </a>
        </section>

        <div class="admin-filter-card">
            <form method="GET" action="restaurants.php" class="admin-filter-form" id="adminFilterForm">
                <input type="hidden" name="page" value="1">

                <div class="admin-filter-row">
                    <div class="admin-filter-search">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/search-line.svg" alt=""
                            class="admin-filter-search-icon" aria-hidden="true">
                        <input type="text" name="search"
                            value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
                            placeholder="Search by business name or cuisine…" aria-label="Search restaurants">
                    </div>

                    <select name="status" class="admin-filter-select" aria-label="Filter by status"
                        onchange="this.form.submit()">
                        <option value="" <?php echo $status === ''          ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="pending" <?php echo $status === 'pending'   ? 'selected' : ''; ?>>Pending
                        </option>
                        <option value="verified" <?php echo $status === 'verified'  ? 'selected' : ''; ?>>Verified
                        </option>
                        <option value="denied" <?php echo $status === 'denied'    ? 'selected' : ''; ?>>Denied</option>
                        <option value="suspended" <?php echo $status === 'suspended' ? 'selected' : ''; ?>>Suspended
                        </option>
                    </select>

                    <select name="per" id="adminPerPage" class="admin-filter-perpage" aria-label="Results per page">
                        <option value="10" <?php echo $perPage === 10 ? 'selected' : ''; ?>>10 / page</option>
                        <option value="25" <?php echo $perPage === 25 ? 'selected' : ''; ?>>25 / page</option>
                        <option value="50" <?php echo $perPage === 50 ? 'selected' : ''; ?>>50 / page</option>
                    </select>

                    <div class="admin-filter-actions">
                        <button type="submit" class="btn btn-primary">Apply</button>
                        <?php if ($search !== '' || $status !== ''): ?>
                        <a href="restaurants.php" class="btn btn-outline">Reset</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>

        <div class="admin-results-meta">
            <span>
                <?php if ($total > 0): ?>
                Showing <strong><?php echo $rangeStart; ?>–<?php echo $rangeEnd; ?></strong>
                of <strong><?php echo number_format($total); ?></strong>
                restaurant<?php echo $total === 1 ? '' : 's'; ?>
                <?php else: ?>
                No restaurants to display
                <?php endif; ?>
            </span>
        </div>

        <?php if (empty($restaurants)): ?>
        <div class="admin-empty">
            <div class="admin-empty-icon">
                <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt="" aria-hidden="true">
            </div>
            <p class="admin-empty-title">No restaurants found</p>
            <p class="admin-empty-text">
                <?php if ($search !== '' || $status !== ''): ?>
                Nothing matches your current filters. Try broadening the search or resetting.
                <?php else: ?>
                There are no restaurants registered on the platform yet.
                <?php endif; ?>
            </p>
            <?php if ($search !== '' || $status !== ''): ?>
            <a href="restaurants.php" class="btn btn-outline btn-sm">Clear Filters</a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="admin-table-card">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Business</th>
                        <th scope="col">Cuisine</th>
                        <th scope="col">Branches</th>
                        <th scope="col">Products</th>
                        <th scope="col">Verification</th>
                        <th scope="col">Joined</th>
                        <th scope="col" class="admin-col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($restaurants as $r):
                        $rid    = (int)$r['restaurant_id'];
                        $vstat  = (string)($r['verification_status'] ?? 'pending');
                        $badge  = getVerificationBadgeClass($vstat);
                        $label  = getVerificationLabel($vstat);

                        $createdTs = strtotime((string)($r['created_at'] ?? ''));
                        $created   = $createdTs ? date('M d, Y', $createdTs) : '—';
                    ?>
                    <tr>
                        <td data-label="Business">
                            <div class="admin-cell-stack">
                                <span class="admin-cell-primary">
                                    <span
                                        class="admin-cell-id">#<?php echo $rid; ?></span><?php echo htmlspecialchars((string)$r['business_name'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </div>
                        </td>

                        <td data-label="Cuisine">
                            <span class="admin-cell-primary">
                                <?php echo htmlspecialchars((string)($r['cuisine_type'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>

                        <td data-label="Branches">
                            <span
                                class="admin-cell-count"><?php echo number_format((int)($r['branch_count'] ?? 0)); ?></span>
                        </td>

                        <td data-label="Products">
                            <span
                                class="admin-cell-count"><?php echo number_format((int)($r['product_count'] ?? 0)); ?></span>
                        </td>

                        <td data-label="Verification">
                            <span class="badge <?php echo $badge; ?>">
                                <span class="badge-dot" aria-hidden="true"></span>
                                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>

                        <td data-label="Joined">
                            <span class="admin-cell-mono">
                                <?php echo htmlspecialchars($created, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>

                        <td class="admin-col-actions" data-label="">
                            <div class="admin-actions" data-admin-actions>
                                <button type="button" class="admin-actions-toggle" aria-haspopup="true"
                                    aria-expanded="false" aria-label="Restaurant actions"
                                    data-admin-actions-toggle>&#8943;</button>

                                <div class="admin-actions-menu" role="menu">
                                    <div class="admin-actions-menu-title">Restaurant #<?php echo $rid; ?></div>

                                    <?php if ($vstat !== 'verified'): ?>
                                    <form method="POST" action="../backend/handlers/admin-handler.php"
                                        onsubmit="return confirm('Verify this restaurant?');">
                                        <input type="hidden" name="csrf_token"
                                            value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="action" value="set_restaurant_status">
                                        <input type="hidden" name="restaurant_id" value="<?php echo $rid; ?>">
                                        <input type="hidden" name="verification_status" value="verified">
                                        <button type="submit" class="admin-action-btn is-primary" role="menuitem">
                                            Verify
                                        </button>
                                    </form>
                                    <?php endif; ?>

                                    <?php if ($vstat !== 'suspended'): ?>
                                    <form method="POST" action="../backend/handlers/admin-handler.php"
                                        onsubmit="return confirm('Suspend this restaurant?');">
                                        <input type="hidden" name="csrf_token"
                                            value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="action" value="set_restaurant_status">
                                        <input type="hidden" name="restaurant_id" value="<?php echo $rid; ?>">
                                        <input type="hidden" name="verification_status" value="suspended">
                                        <button type="submit" class="admin-action-btn" role="menuitem">
                                            Suspend
                                        </button>
                                    </form>
                                    <?php endif; ?>

                                    <?php if ($vstat !== 'denied'): ?>
                                    <form method="POST" action="../backend/handlers/admin-handler.php"
                                        onsubmit="return confirm('Deny this restaurant?');">
                                        <input type="hidden" name="csrf_token"
                                            value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="action" value="set_restaurant_status">
                                        <input type="hidden" name="restaurant_id" value="<?php echo $rid; ?>">
                                        <input type="hidden" name="verification_status" value="denied">
                                        <button type="submit" class="admin-action-btn is-danger" role="menuitem">
                                            Deny
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <nav class="admin-pagination" aria-label="Restaurant pagination">
            <span class="admin-pagination-info">
                Page <strong><?php echo $page; ?></strong>
                of <strong><?php echo $totalPages; ?></strong>
            </span>

            <ul class="admin-pagination-list">
                <li>
                    <?php if ($page > 1): ?>
                    <a href="restaurants.php<?php echo $qs(['page' => 1]); ?>" class="admin-pagination-link is-nav"
                        aria-label="First page">&laquo;</a>
                    <?php else: ?>
                    <span class="admin-pagination-link is-nav is-disabled">&laquo;</span>
                    <?php endif; ?>
                </li>
                <li>
                    <?php if ($page > 1): ?>
                    <a href="restaurants.php<?php echo $qs(['page' => $page - 1]); ?>"
                        class="admin-pagination-link is-nav" aria-label="Previous page">&lsaquo;</a>
                    <?php else: ?>
                    <span class="admin-pagination-link is-nav is-disabled">&lsaquo;</span>
                    <?php endif; ?>
                </li>

                <?php foreach ($pageWindow as $p): ?>
                <?php if ($p === '…'): ?>
                <li><span class="admin-pagination-ellipsis">…</span></li>
                <?php elseif ((int)$p === $page): ?>
                <li><span class="admin-pagination-link is-active" aria-current="page"><?php echo (int)$p; ?></span></li>
                <?php else: ?>
                <li>
                    <a href="restaurants.php<?php echo $qs(['page' => (int)$p]); ?>"
                        class="admin-pagination-link"><?php echo (int)$p; ?></a>
                </li>
                <?php endif; ?>
                <?php endforeach; ?>

                <li>
                    <?php if ($page < $totalPages): ?>
                    <a href="restaurants.php<?php echo $qs(['page' => $page + 1]); ?>"
                        class="admin-pagination-link is-nav" aria-label="Next page">&rsaquo;</a>
                    <?php else: ?>
                    <span class="admin-pagination-link is-nav is-disabled">&rsaquo;</span>
                    <?php endif; ?>
                </li>
                <li>
                    <?php if ($page < $totalPages): ?>
                    <a href="restaurants.php<?php echo $qs(['page' => $totalPages]); ?>"
                        class="admin-pagination-link is-nav" aria-label="Last page">&raquo;</a>
                    <?php else: ?>
                    <span class="admin-pagination-link is-nav is-disabled">&raquo;</span>
                    <?php endif; ?>
                </li>
            </ul>

            <form class="admin-pagination-jump" method="GET" action="restaurants.php">
                <input type="hidden" name="search"
                    value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="status"
                    value="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="per" value="<?php echo (int)$perPage; ?>">
                <label for="jumpPage">Jump to</label>
                <input type="number" id="jumpPage" name="page" min="1" max="<?php echo $totalPages; ?>"
                    value="<?php echo $page; ?>">
                <button type="submit" class="btn btn-outline btn-sm">Go</button>
            </form>
        </nav>
        <?php endif; ?>
        <?php endif; ?>

    </div>
</div>

<script>
(function() {
    'use strict';

    function closeAllMenus() {
        document.querySelectorAll('[data-admin-actions].is-open').forEach(function(wrap) {
            wrap.classList.remove('is-open');
            var btn = wrap.querySelector('[data-admin-actions-toggle]');
            if (btn) btn.setAttribute('aria-expanded', 'false');
        });
        document.querySelectorAll('.admin-actions-menu.is-open').forEach(function(menu) {
            menu.classList.remove('is-open');
        });
    }
    document.addEventListener('click', function(e) {
        var toggle = e.target.closest('[data-admin-actions-toggle]');
        if (toggle) {
            e.preventDefault();
            e.stopPropagation();
            var wrap = toggle.closest('[data-admin-actions]');
            var menu = wrap ? wrap.querySelector('.admin-actions-menu') : null;
            var isOpen = wrap && wrap.classList.contains('is-open');
            closeAllMenus();
            if (!isOpen && wrap && menu) {
                wrap.classList.add('is-open');
                menu.classList.add('is-open');
                toggle.setAttribute('aria-expanded', 'true');
            }
            return;
        }
        if (e.target.closest('.admin-actions-menu')) return;
        closeAllMenus();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeAllMenus();
    });
    var perPageSelect = document.getElementById('adminPerPage');
    if (perPageSelect) {
        perPageSelect.addEventListener('change', function() {
            var form = document.getElementById('adminFilterForm');
            if (!form) return;
            var hidden = form.querySelector('input[name="page"]');
            if (hidden) hidden.value = '1';
            form.submit();
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>