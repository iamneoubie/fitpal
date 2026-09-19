<?php
/**
 * FitPal Admin — Users (Customer Management)
 *
 * List, search, and toggle active status for customers.
 * All SQL lives in admin-queries.php.
 *
 * @package FitPal
 * @version 1.0
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

$search   = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$status   = isset($_GET['status']) ? (string)$_GET['status'] : '';
$page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage  = 10;

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

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
?>

<div class="content admin-users-page">
    <div class="container">

        <header class="admin-page-header">
            <div>
                <h1 class="heading-2">Customer <span>Accounts</span></h1>
                <p class="text-muted">Search, review, and manage customer accounts.</p>
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

        <!-- Filters -->
        <form method="GET" action="" class="admin-filter-bar">
            <div class="admin-filter-search">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
                    placeholder="Search by name, email, or username…" class="form-control">
            </div>

            <select name="status" class="form-control admin-filter-select">
                <option value="" <?php echo $status === ''         ? 'selected' : ''; ?>>All Statuses</option>
                <option value="active" <?php echo $status === 'active'   ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
            </select>

            <button type="submit" class="btn btn-primary btn-sm">Apply</button>

            <?php if ($search !== '' || $status !== ''): ?>
            <a href="users.php" class="btn btn-outline btn-sm">Reset</a>
            <?php endif; ?>
        </form>

        <p class="admin-results-count">
            <strong><?php echo number_format($total); ?></strong>
            customer<?php echo $total === 1 ? '' : 's'; ?> found
        </p>

        <?php if (empty($customers)): ?>
        <div class="empty-state">
            <div class="empty-icon">
                <img src="<?php echo $assetBase; ?>assets/images/icons/user-profile-circle.svg" alt="No customers">
            </div>
            <p class="heading-5">No customers found</p>
            <p class="text-muted">Try adjusting your search or filters.</p>
        </div>
        <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Contact</th>
                        <th>Orders</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th class="admin-col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $c):
                        $cid  = (int)$c['customer_id'];
                        $name = trim(
                            ($c['first_name'] ?? '') . ' ' .
                            ($c['middle_name'] ?? '') . ' ' .
                            ($c['last_name'] ?? '')
                        );
                        $name = preg_replace('/\s+/', ' ', $name);
                        $active = (int)($c['is_active'] ?? 0) === 1;

                        $joinedTs = strtotime((string)($c['date_created'] ?? ''));
                        $joined   = $joinedTs ? date('M d, Y', $joinedTs) : '—';
                    ?>
                    <tr>
                        <td data-label="Name">
                            <span class="admin-strong">
                                <?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <?php if (!empty($c['username'])): ?>
                            <span class="admin-sub">
                                @<?php echo htmlspecialchars($c['username'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Email">
                            <?php echo htmlspecialchars((string)($c['email'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td data-label="Contact">
                            <?php echo htmlspecialchars((string)($c['contact_number'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td data-label="Orders"><?php echo number_format((int)($c['order_count'] ?? 0)); ?></td>
                        <td data-label="Status">
                            <span class="badge <?php echo $active ? 'badge-success' : 'badge-secondary'; ?>">
                                <?php echo $active ? 'Active' : 'Inactive'; ?>
                            </span>
                        </td>
                        <td data-label="Joined"><?php echo htmlspecialchars($joined, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td data-label="Actions" class="admin-col-actions">
                            <form method="POST" action="../backend/handlers/admin-handler.php" class="admin-inline-form"
                                onsubmit="return confirm('<?php echo $active ? 'Deactivate' : 'Activate'; ?> this customer?');">
                                <input type="hidden" name="csrf_token"
                                    value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="toggle_customer_active">
                                <input type="hidden" name="customer_id" value="<?php echo $cid; ?>">
                                <input type="hidden" name="set_active" value="<?php echo $active ? '0' : '1'; ?>">
                                <button type="submit"
                                    class="btn btn-sm <?php echo $active ? 'btn-outline' : 'btn-primary'; ?>">
                                    <?php echo $active ? 'Deactivate' : 'Activate'; ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <nav class="admin-pagination" aria-label="Customer pagination">
            <ul class="pagination-list">
                <li>
                    <?php if ($page > 1): ?>
                    <a href="users.php<?php echo adminBuildQuery(['page' => $page - 1]); ?>"
                        class="pagination-link pagination-prev">Previous</a>
                    <?php else: ?>
                    <span class="pagination-link pagination-prev disabled">Previous</span>
                    <?php endif; ?>
                </li>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <li>
                    <?php if ($i === $page): ?>
                    <span class="pagination-link active" aria-current="page"><?php echo $i; ?></span>
                    <?php else: ?>
                    <a href="users.php<?php echo adminBuildQuery(['page' => $i]); ?>"
                        class="pagination-link"><?php echo $i; ?></a>
                    <?php endif; ?>
                </li>
                <?php endfor; ?>

                <li>
                    <?php if ($page < $totalPages): ?>
                    <a href="users.php<?php echo adminBuildQuery(['page' => $page + 1]); ?>"
                        class="pagination-link pagination-next">Next</a>
                    <?php else: ?>
                    <span class="pagination-link pagination-next disabled">Next</span>
                    <?php endif; ?>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
        <?php endif; ?>

    </div>
</div>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>