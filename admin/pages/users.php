<?php
/**
 * FitPal Admin — Customer Accounts
 *
 * Full list page with summary strip, filter bar, per-page control,
 * action menu, and enhanced pagination.
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

// Validate per-page against a whitelist.
$allowedPerPage = [10, 25, 50];
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 10;
}

// Validate status against a whitelist.
$allowedStatuses = ['', 'active', 'inactive'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

// ------------------------------------------------------------------
// DATA
// ------------------------------------------------------------------
$counts = getCustomerStatusCounts($database_connection);

$result = getAdminCustomersPaginated(
    $database_connection,
    $search,
    $status,
    $page,
    $perPage
);

$customers  = $result['items'];
$total      = $result['total'];
$totalPages = $result['totalPages'];
$page       = $result['page'];

$rangeStart = $total > 0 ? (($page - 1) * $perPage) + 1 : 0;
$rangeEnd   = $total > 0 ? min($page * $perPage, $total) : 0;

$pageWindow = buildAdminPageWindow($page, $totalPages, 5);

// ------------------------------------------------------------------
// CSRF
// ------------------------------------------------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ------------------------------------------------------------------
// HELPER: preserve query params across links
// ------------------------------------------------------------------
$linkParams = [
    'search' => $search,
    'status' => $status,
    'per'    => $perPage,
];
$qs = static function (array $overrides = []) use ($linkParams): string {
    $params = array_merge($linkParams, $overrides);
    // Drop empty values so URLs stay clean.
    $params = array_filter($params, static fn($v) => $v !== '' && $v !== null);
    return $params ? ('?' . http_build_query($params)) : '';
};
?>

<div class="content admin-list-page">
    <div class="container">

        <!-- ============================================
             PAGE HEADER
             ============================================ -->
        <header class="admin-page-header">
            <div class="admin-page-header-text">
                <h1 class="admin-page-title">Customer <span>Accounts</span></h1>
                <p class="admin-page-subtitle">
                    Search, review, and manage customer accounts across the platform.
                </p>
            </div>
            <div class="admin-page-header-actions">
                <a href="dashboard.php" class="btn btn-outline btn-sm">Back to Dashboard</a>
            </div>
        </header>

        <!-- ============================================
             FLASH MESSAGES
             ============================================ -->
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

        <!-- ============================================
             SUMMARY STRIP
             ============================================ -->
        <section class="admin-summary-strip" aria-label="Customer status summary">
            <a href="users.php<?php echo $qs(['status' => '', 'page' => 1]); ?>"
                class="admin-summary-tile <?php echo $status === '' ? 'is-active' : ''; ?>">
                <span class="admin-summary-dot dot-total" aria-hidden="true"></span>
                <span class="admin-summary-body">
                    <span class="admin-summary-count"><?php echo number_format($counts['total']); ?></span>
                    <span class="admin-summary-label">All Customers</span>
                </span>
            </a>
            <a href="users.php<?php echo $qs(['status' => 'active', 'page' => 1]); ?>"
                class="admin-summary-tile <?php echo $status === 'active' ? 'is-active' : ''; ?>">
                <span class="admin-summary-dot dot-active" aria-hidden="true"></span>
                <span class="admin-summary-body">
                    <span class="admin-summary-count"><?php echo number_format($counts['active']); ?></span>
                    <span class="admin-summary-label">Active</span>
                </span>
            </a>
            <a href="users.php<?php echo $qs(['status' => 'inactive', 'page' => 1]); ?>"
                class="admin-summary-tile <?php echo $status === 'inactive' ? 'is-active' : ''; ?>">
                <span class="admin-summary-dot dot-inactive" aria-hidden="true"></span>
                <span class="admin-summary-body">
                    <span class="admin-summary-count"><?php echo number_format($counts['inactive']); ?></span>
                    <span class="admin-summary-label">Inactive</span>
                </span>
            </a>
        </section>

        <!-- ============================================
             FILTER BAR
             ============================================ -->
        <div class="admin-filter-card">
            <form method="GET" action="users.php" class="admin-filter-form" id="adminFilterForm">
                <!-- Preserve the current per-page size across filter submits. -->
                <input type="hidden" name="per" value="<?php echo (int)$perPage; ?>">
                <input type="hidden" name="page" value="1">

                <div class="admin-filter-row">
                    <div class="admin-filter-search">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/search-line.svg" alt=""
                            class="admin-filter-search-icon" aria-hidden="true">
                        <input type="text" name="search"
                            value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
                            placeholder="Search by name, email, or username…" aria-label="Search customers">
                    </div>

                    <select name="status" class="admin-filter-select" aria-label="Filter by status"
                        onchange="this.form.submit()">
                        <option value="" <?php echo $status === ''         ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="active" <?php echo $status === 'active'   ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive
                        </option>
                    </select>

                    <div class="admin-filter-actions">
                        <button type="submit" class="btn btn-primary">Apply</button>
                        <?php if ($search !== '' || $status !== ''): ?>
                        <a href="users.php" class="btn btn-outline">Reset</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>

        <!-- ============================================
             RESULTS META
             ============================================ -->
        <div class="admin-results-meta">
            <span>
                <?php if ($total > 0): ?>
                Showing <strong><?php echo $rangeStart; ?>–<?php echo $rangeEnd; ?></strong>
                of <strong><?php echo number_format($total); ?></strong>
                customer<?php echo $total === 1 ? '' : 's'; ?>
                <?php else: ?>
                No customers to display
                <?php endif; ?>
            </span>

            <?php if ($search !== '' || $status !== ''): ?>
            <span class="admin-results-filter">
                Filters:
                <?php if ($search !== ''): ?>
                &ldquo;<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>&rdquo;
                <?php endif; ?>
                <?php if ($status !== ''): ?>
                <?php echo $search !== '' ? '·' : ''; ?>
                status = <?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>
                <?php endif; ?>
            </span>
            <?php endif; ?>
        </div>

        <!-- ============================================
             TABLE / EMPTY STATE
             ============================================ -->
        <?php if (empty($customers)): ?>
        <div class="admin-empty">
            <div class="admin-empty-icon">
                <img src="<?php echo $assetBase; ?>assets/images/icons/user-profile-circle.svg" alt=""
                    aria-hidden="true">
            </div>
            <p class="admin-empty-title">No customers found</p>
            <p class="admin-empty-text">
                <?php if ($search !== '' || $status !== ''): ?>
                Nothing matches your current filters. Try broadening the search or resetting.
                <?php else: ?>
                There are no customer accounts registered yet.
                <?php endif; ?>
            </p>
            <?php if ($search !== '' || $status !== ''): ?>
            <a href="users.php" class="btn btn-outline btn-sm">Clear Filters</a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="admin-table-card">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Customer</th>
                        <th scope="col">Email</th>
                        <th scope="col">Contact</th>
                        <th scope="col">Orders</th>
                        <th scope="col">Status</th>
                        <th scope="col">Joined</th>
                        <th scope="col" class="admin-col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $c):
                        $cid     = (int)$c['customer_id'];
                        $name    = trim(
                            ($c['first_name'] ?? '') . ' ' .
                            ($c['middle_name'] ?? '') . ' ' .
                            ($c['last_name'] ?? '')
                        );
                        $name    = preg_replace('/\s+/', ' ', $name);
                        $active  = (int)($c['is_active'] ?? 0) === 1;
                        $orderCt = (int)($c['order_count'] ?? 0);

                        $joinedTs = strtotime((string)($c['date_created'] ?? ''));
                        $joined   = $joinedTs ? date('M d, Y', $joinedTs) : '—';

                        $nextActive = $active ? '0' : '1';
                        $actionVerb = $active ? 'Deactivate' : 'Activate';
                        $actionClass = $active ? 'is-danger' : 'is-primary';
                    ?>
                    <tr>
                        <td data-label="Customer">
                            <div class="admin-cell-stack">
                                <span class="admin-cell-primary">
                                    <span
                                        class="admin-cell-id">#<?php echo $cid; ?></span><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <?php if (!empty($c['username'])): ?>
                                <span class="admin-cell-secondary">
                                    @<?php echo htmlspecialchars((string)$c['username'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </td>

                        <td data-label="Email">
                            <span class="admin-cell-primary">
                                <?php echo htmlspecialchars((string)($c['email'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>

                        <td data-label="Contact">
                            <span class="admin-cell-mono">
                                <?php echo htmlspecialchars((string)($c['contact_number'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>

                        <td data-label="Orders">
                            <span class="admin-cell-count"><?php echo number_format($orderCt); ?></span>
                        </td>

                        <td data-label="Status">
                            <span class="badge <?php echo $active ? 'badge-success' : 'badge-secondary'; ?>">
                                <span class="badge-dot" aria-hidden="true"></span>
                                <?php echo $active ? 'Active' : 'Inactive'; ?>
                            </span>
                        </td>

                        <td data-label="Joined">
                            <span class="admin-cell-mono">
                                <?php echo htmlspecialchars($joined, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>

                        <td class="admin-col-actions" data-label="">
                            <div class="admin-actions" data-admin-actions>
                                <button type="button" class="admin-actions-toggle" aria-haspopup="true"
                                    aria-expanded="false" aria-label="Customer actions"
                                    data-admin-actions-toggle>&#8943;</button>

                                <div class="admin-actions-menu" role="menu">
                                    <div class="admin-actions-menu-title">Customer #<?php echo $cid; ?></div>

                                    <form method="POST" action="../backend/handlers/admin-handler.php"
                                        onsubmit="return confirm('<?php echo $actionVerb; ?> this customer?');">
                                        <input type="hidden" name="csrf_token"
                                            value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="action" value="toggle_customer_active">
                                        <input type="hidden" name="customer_id" value="<?php echo $cid; ?>">
                                        <input type="hidden" name="set_active" value="<?php echo $nextActive; ?>">
                                        <button type="submit" class="admin-action-btn <?php echo $actionClass; ?>"
                                            role="menuitem">
                                            <?php echo $actionVerb; ?>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- ============================================
             PAGINATION
             ============================================ -->
        <?php if ($totalPages > 1): ?>
        <nav class="admin-pagination" aria-label="Customer pagination">

            <span class="admin-pagination-info">
                Page <strong><?php echo $page; ?></strong>
                of <strong><?php echo $totalPages; ?></strong>
            </span>

            <ul class="admin-pagination-list">
                <!-- First -->
                <li>
                    <?php if ($page > 1): ?>
                    <a href="users.php<?php echo $qs(['page' => 1]); ?>" class="admin-pagination-link is-nav"
                        aria-label="First page">&laquo;</a>
                    <?php else: ?>
                    <span class="admin-pagination-link is-nav is-disabled" aria-hidden="true">&laquo;</span>
                    <?php endif; ?>
                </li>

                <!-- Previous -->
                <li>
                    <?php if ($page > 1): ?>
                    <a href="users.php<?php echo $qs(['page' => $page - 1]); ?>" class="admin-pagination-link is-nav"
                        aria-label="Previous page">&lsaquo;</a>
                    <?php else: ?>
                    <span class="admin-pagination-link is-nav is-disabled" aria-hidden="true">&lsaquo;</span>
                    <?php endif; ?>
                </li>

                <!-- Window -->
                <?php foreach ($pageWindow as $p): ?>
                <?php if ($p === '…'): ?>
                <li><span class="admin-pagination-ellipsis">…</span></li>
                <?php elseif ((int)$p === $page): ?>
                <li><span class="admin-pagination-link is-active" aria-current="page"><?php echo (int)$p; ?></span></li>
                <?php else: ?>
                <li>
                    <a href="users.php<?php echo $qs(['page' => (int)$p]); ?>"
                        class="admin-pagination-link"><?php echo (int)$p; ?></a>
                </li>
                <?php endif; ?>
                <?php endforeach; ?>

                <!-- Next -->
                <li>
                    <?php if ($page < $totalPages): ?>
                    <a href="users.php<?php echo $qs(['page' => $page + 1]); ?>" class="admin-pagination-link is-nav"
                        aria-label="Next page">&rsaquo;</a>
                    <?php else: ?>
                    <span class="admin-pagination-link is-nav is-disabled" aria-hidden="true">&rsaquo;</span>
                    <?php endif; ?>
                </li>

                <!-- Last -->
                <li>
                    <?php if ($page < $totalPages): ?>
                    <a href="users.php<?php echo $qs(['page' => $totalPages]); ?>" class="admin-pagination-link is-nav"
                        aria-label="Last page">&raquo;</a>
                    <?php else: ?>
                    <span class="admin-pagination-link is-nav is-disabled" aria-hidden="true">&raquo;</span>
                    <?php endif; ?>
                </li>
            </ul>

            <form class="admin-pagination-jump" method="GET" action="users.php">
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
/**
 * Action menu behaviour.
 *   - Toggle on click.
 *   - Close when clicking outside.
 *   - Close on Escape.
 *   - Only one open at a time.
 */
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

        // Click inside menu keeps it open.
        if (e.target.closest('.admin-actions-menu')) {
            return;
        }

        closeAllMenus();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeAllMenus();
    });
})();

/**
 * Per-page selector: preserve current filters and reset page to 1.
 */
(function() {
    'use strict';
    var perPageSelect = document.getElementById('adminPerPage');
    if (!perPageSelect) return;
    perPageSelect.addEventListener('change', function() {
        var form = document.getElementById('adminFilterForm');
        if (!form) return;
        var hidden = form.querySelector('input[name="page"]');
        if (hidden) hidden.value = '1';
        form.submit();
    });
})();
</script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>