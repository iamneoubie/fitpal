<?php
/**
 * FitPal Customer Dashboard
 *
 * Customer dashboard showing order summary, profile info, and quick actions.
 *
 * @package FitPal
 * @version 1.1
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if not logged in
if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    header('Location: sign-in.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';

// $assetBase and $database_connection are provided by header.php (via customer-connect.php)

$customerId = (int)$_SESSION['customer_id'];
$userName   = $_SESSION['user_name'] ?? 'Customer';

// ===== FETCH DASHBOARD DATA =====
$orderCount    = 0;
$recentOrders  = [];
$profileData   = null;

try {
    $stmt = $database_connection->prepare(
        "SELECT COUNT(*) AS total FROM orders WHERE customer_id = :customer_id"
    );
    $stmt->execute([':customer_id' => $customerId]);
    $result     = $stmt->fetch(PDO::FETCH_ASSOC);
    $orderCount = (int)($result['total'] ?? 0);

    $stmt = $database_connection->prepare(
        "SELECT
            o.order_id,
            o.order_status,
            o.total_amount,
            o.order_date,
            o.delivery_charge,
            o.subtotal,
            COUNT(qi.queue_item_id) AS item_count
        FROM orders o
        LEFT JOIN queue_item qi ON o.order_id = qi.order_id
        WHERE o.customer_id = :customer_id
        GROUP BY o.order_id
        ORDER BY o.order_date DESC
        LIMIT 5"
    );
    $stmt->execute([':customer_id' => $customerId]);
    $recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $database_connection->prepare(
        "SELECT
            c.first_name,
            c.last_name,
            c.email,
            c.username,
            c.contact_number,
            cp.dietary_preferences,
            cp.allergies,
            cp.fitness_goal,
            cp.height_cm,
            cp.weight_kg,
            fa.balance
        FROM customer c
        LEFT JOIN customer_profile cp ON c.customer_id = cp.customer_id
        LEFT JOIN financial_account fa ON cp.financial_account_id = fa.financial_account_id
        WHERE c.customer_id = :customer_id
        LIMIT 1"
    );
    $stmt->execute([':customer_id' => $customerId]);
    $profileData = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Dashboard error: ' . $e->getMessage());
}

// ===== HELPER FUNCTIONS =====

/**
 * Format a value as Philippine peso currency.
 *
 * @param float|string|null $amount
 * @return string
 */
function formatCurrency(float|string|null $amount): string {
    return '&#8369;' . number_format((float)($amount ?? 0), 2);
}

/**
 * Return the CSS badge class for an order status.
 *
 * @param string $status
 * @return string
 */
function getStatusBadgeClass(string $status): string {
    return match ($status) {
        'pending'    => 'badge-warning',
        'preparing'  => 'badge-info',
        'delivering' => 'badge-primary',
        'delivered'  => 'badge-success',
        'cancelled'  => 'badge-danger',
        default      => 'badge-secondary',
    };
}

/**
 * Return a human-readable label for an order status.
 *
 * @param string $status
 * @return string
 */
function getStatusLabel(string $status): string {
    return match ($status) {
        'pending'    => 'Pending',
        'preparing'  => 'Preparing',
        'delivering' => 'Delivering',
        'delivered'  => 'Delivered',
        'cancelled'  => 'Cancelled',
        default      => ucfirst($status),
    };
}

/**
 * Format an order date string for display.
 *
 * @param string $date
 * @return string
 */
function formatOrderDate(string $date): string {
    $timestamp = strtotime($date);
    return $timestamp !== false ? date('M d, Y g:i A', $timestamp) : $date;
}

// Count dietary preferences
$dietCount = 0;
if (!empty($profileData['dietary_preferences'])) {
    $diets     = explode(',', $profileData['dietary_preferences']);
    $dietCount = count(array_filter($diets, fn($d) => trim($d) !== ''));
}

$balanceFloat = (float)($profileData['balance'] ?? 0);
?>

<div class="content dashboard-page">
    <div class="container">
        <div class="dashboard-header">
            <div>
                <p class="heading-2">Welcome, <span><?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?></span></p>
                <p class="text-muted">Here's an overview of your account activity</p>
            </div>
            <div class="dashboard-actions">
                <a href="menu.php" class="btn btn-primary">
                    <span class="btn-icon">+</span> Browse Menu
                </a>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon stat-icon-orders">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/order.svg" alt="Orders"
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/placeholder.svg'">
                </div>
                <div class="stat-info">
                    <p class="stat-number"><?php echo number_format($orderCount); ?></p>
                    <p class="stat-label">Total Orders</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon stat-icon-balance">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/wallet.svg" alt="Wallet"
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/placeholder.svg'">
                </div>
                <div class="stat-info">
                    <p class="stat-number"><?php echo formatCurrency($balanceFloat); ?></p>
                    <p class="stat-label">Wallet Balance</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon stat-icon-profile">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/profile.svg" alt="Profile"
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/placeholder.svg'">
                </div>
                <div class="stat-info">
                    <p class="stat-number"><?php
                        $goal = $profileData['fitness_goal'] ?? '';
                        echo htmlspecialchars(match ($goal) {
                            'weight_loss' => 'Weight Loss',
                            'muscle_gain' => 'Muscle Gain',
                            'maintenance' => 'Maintenance',
                            default       => 'Not Set',
                        }, ENT_QUOTES, 'UTF-8');
                    ?></p>
                    <p class="stat-label">Fitness Goal</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon stat-icon-diet">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/dietary.svg" alt="Dietary"
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/placeholder.svg'">
                </div>
                <div class="stat-info">
                    <p class="stat-number"><?php echo $dietCount; ?></p>
                    <p class="stat-label">Dietary Preferences</p>
                </div>
            </div>
        </div>

        <!-- Two Column Layout -->
        <div class="dashboard-grid">

            <!-- Recent Orders -->
            <div class="dashboard-card recent-orders">
                <div class="card-header">
                    <p class="heading-5">Recent Orders</p>
                    <?php if ($orderCount > 5): ?>
                    <a href="orders.php" class="btn btn-sm btn-outline">View All</a>
                    <?php endif; ?>
                </div>

                <?php if (empty($recentOrders)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/order.svg" alt="No orders"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/placeholder.svg'">
                    </div>
                    <p class="empty-title">No Orders Yet</p>
                    <p class="empty-description">Start ordering healthy meals from your favorite restaurants.</p>
                    <a href="menu.php" class="btn btn-primary">Browse Menu</a>
                </div>
                <?php else: ?>
                <div class="orders-list">
                    <?php foreach ($recentOrders as $order): ?>
                    <?php
                    $orderId     = (int)($order['order_id'] ?? 0);
                    $orderStatus = $order['order_status'] ?? 'pending';
                    $orderTotal  = (float)($order['total_amount'] ?? 0);
                    $itemCount   = (int)($order['item_count'] ?? 0);
                    $orderDate   = $order['order_date'] ?? '';
                    ?>
                    <div class="order-item">
                        <div class="order-info">
                            <div class="order-details">
                                <p class="order-id">Order #<?php echo $orderId; ?></p>
                                <p class="order-date"><?php echo formatOrderDate($orderDate); ?></p>
                                <p class="order-items"><?php echo $itemCount; ?> item(s)</p>
                            </div>
                            <div class="order-meta">
                                <span class="badge <?php echo getStatusBadgeClass($orderStatus); ?>">
                                    <?php echo getStatusLabel($orderStatus); ?>
                                </span>
                                <p class="order-total"><?php echo formatCurrency($orderTotal); ?></p>
                            </div>
                        </div>
                        <div class="order-actions">
                            <a href="order-details.php?id=<?php echo $orderId; ?>"
                                class="btn btn-sm btn-outline">View</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Quick Actions & Profile -->
            <div class="dashboard-sidebar">

                <div class="dashboard-card quick-actions">
                    <p class="heading-5">Quick Actions</p>
                    <div class="actions-grid">
                        <a href="menu.php" class="action-item">
                            <div class="action-icon">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/menu.svg" alt="Menu"
                                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/placeholder.svg'">
                            </div>
                            <span>Browse Menu</span>
                        </a>
                        <a href="orders.php" class="action-item">
                            <div class="action-icon">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/order.svg" alt="Orders"
                                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/placeholder.svg'">
                            </div>
                            <span>My Orders</span>
                        </a>
                        <a href="profile.php" class="action-item">
                            <div class="action-icon">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/profile.svg" alt="Profile"
                                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/placeholder.svg'">
                            </div>
                            <span>Profile</span>
                        </a>
                        <a href="wallet.php" class="action-item">
                            <div class="action-icon">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/wallet.svg" alt="Wallet"
                                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/placeholder.svg'">
                            </div>
                            <span>Wallet</span>
                        </a>
                    </div>
                </div>

                <div class="dashboard-card profile-summary">
                    <p class="heading-5">Profile Summary</p>
                    <div class="profile-info">
                        <div class="profile-row">
                            <span class="profile-label">Name</span>
                            <span class="profile-value">
                                <?php
                                $fn = trim(($profileData['first_name'] ?? '') . ' ' . ($profileData['last_name'] ?? ''));
                                echo htmlspecialchars($fn ?: 'Not Set', ENT_QUOTES, 'UTF-8');
                                ?>
                            </span>
                        </div>
                        <div class="profile-row">
                            <span class="profile-label">Email</span>
                            <span class="profile-value">
                                <?php echo htmlspecialchars($profileData['email'] ?? 'Not Set', ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                        <div class="profile-row">
                            <span class="profile-label">Username</span>
                            <span class="profile-value">
                                <?php echo htmlspecialchars($profileData['username'] ?? 'Not Set', ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>

                        <?php if (!empty($profileData['dietary_preferences'])): ?>
                        <div class="profile-row">
                            <span class="profile-label">Dietary</span>
                            <span class="profile-value profile-tags">
                                <?php
                                foreach (explode(',', $profileData['dietary_preferences']) as $diet) {
                                    $diet = trim($diet);
                                    if ($diet !== '' && $diet !== 'none') {
                                        echo '<span class="tag">'
                                            . htmlspecialchars(ucwords(str_replace('_', ' ', $diet)), ENT_QUOTES, 'UTF-8')
                                            . '</span>';
                                    }
                                }
                                ?>
                            </span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($profileData['allergies']) && $profileData['allergies'] !== 'none'): ?>
                        <div class="profile-row">
                            <span class="profile-label">Allergies</span>
                            <span class="profile-value profile-tags">
                                <?php
                                foreach (explode(',', $profileData['allergies']) as $allergy) {
                                    $allergy = trim($allergy);
                                    if ($allergy !== '' && $allergy !== 'none') {
                                        echo '<span class="tag tag-danger">'
                                            . htmlspecialchars(ucwords(str_replace('_', ' ', $allergy)), ENT_QUOTES, 'UTF-8')
                                            . '</span>';
                                    }
                                }
                                ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <a href="profile.php" class="btn btn-sm btn-outline profile-edit-btn">Edit Profile</a>
                </div>

            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>