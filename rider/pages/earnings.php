<?php
/**
 * FitPal Rider Earnings Page
 *
 * Shows:
 *   - Available balance and a hero withdraw button
 *   - Four-stat strip (Today / This Week / This Month / All Time)
 *   - Earnings chart for the last 7 days
 *   - Transaction history with pagination
 *   - Withdrawal modal, submitted via earnings.js to rider-handler.php
 *
 * All SQL lives in rider-queries.php. This page contains no SQL.
 *
 * @package FitPal
 * @version 1.2 — Corrected the docblock reference from
 *                includes/csrf-token.php to
 *                includes/rider-csrf-token.php, which is the file the
 *                header actually requires. No code change. (1.1:
 *                Removed the local CSRF block that wrote to the
 *                shared 'csrf_token' session key. The rider role's
 *                token is now generated in includes/header.php via
 *                includes/rider-csrf-token.php under
 *                'rider_csrf_token' and exposed as $csrfToken, so the
 *                FITPAL_RIDER_EARNINGS inline config carries the
 *                rider-scoped value.)
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['delivery_rider_id'])) {
    header('Location: sign-in.php');
    exit;
}

require_once __DIR__ . '/../backend/database/rider-connect.php';
require_once __DIR__ . '/../backend/database/rider-queries.php';

$riderId = (int)$_SESSION['delivery_rider_id'];

$profile    = getRiderProfile($database_connection, $riderId) ?: [];
$stats      = getRiderDashboardStats($database_connection, $riderId);
$weekly     = getRiderWeeklyEarnings($database_connection, $riderId);
$chartScale = getRiderChartScale($stats['week_earnings_max'] ?? 0);

$perPage = 10;
$page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset  = ($page - 1) * $perPage;

$transactions = getRiderTransactions($database_connection, $riderId, $perPage, $offset);
$totalTxns    = countRiderTransactions($database_connection, $riderId);
$totalPages   = max(1, (int)ceil($totalTxns / $perPage));

$balance       = (float)($profile['balance'] ?? 0);
$todayEarnings = (float)($stats['today_earnings'] ?? 0);
$weekEarnings  = (float)($stats['week_earnings'] ?? 0);
$monthEarnings = (float)($stats['month_earnings'] ?? 0);
$totalEarnings = (float)($stats['total_earnings'] ?? 0);

// $csrfToken is provided by header.php (rider_csrf_token).

$chartCeiling = $chartScale['ceiling'];
$today = date('Y-m-d');

$barHeights = [];
foreach ($weekly as $day) {
    $pct = $chartCeiling > 0 ? ($day['amount'] / $chartCeiling) * 100 : 0;
    $barHeights[$day['date']] = $day['amount'] > 0 ? max(4, min(100, $pct)) : 0;
}

function formatTxnDate(string $date): string
{
    $ts = strtotime($date);
    return $ts !== false ? date('M d, Y • g:i A', $ts) : $date;
}

function txnIsCredit(string $type): bool
{
    return in_array($type, ['deposit', 'refund'], true);
}

function txnLabel(string $type): string
{
    return match ($type) {
        'deposit'    => 'Earning',
        'payment'    => 'Payment',
        'refund'     => 'Refund',
        'withdrawal' => 'Withdrawal',
        default      => ucfirst($type),
    };
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="content rider-earnings-page">
    <div class="container">

        <header class="rider-page-header">
            <div>
                <h1 class="heading-2">My <span>Earnings</span></h1>
                <p class="text-muted">Track your income and withdraw funds</p>
            </div>
            <div class="rider-page-actions">
                <button type="button" class="btn btn-primary btn-sm" id="withdrawBtn"
                    <?php echo $balance < 100 ? 'disabled' : ''; ?>>
                    <img src="<?php echo $assetBase; ?>assets/images/icons/add-line.svg" alt="" class="btn-icon"
                        width="16" height="16">
                    <span>Withdraw</span>
                </button>
            </div>
        </header>

        <?php if (isset($_SESSION['rider_success'])): ?>
        <div class="alert alert-success" role="alert">
            <?php echo htmlspecialchars($_SESSION['rider_success'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['rider_success']); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['rider_error'])): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo htmlspecialchars($_SESSION['rider_error'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['rider_error']); ?>
        </div>
        <?php endif; ?>

        <section class="rider-balance-card">
            <div class="rider-balance-left">
                <span class="rider-balance-label">Available Balance</span>
                <span class="rider-balance-amount"><?php echo formatRiderCurrency($balance); ?></span>
                <span class="rider-balance-note">Withdrawals require a minimum of ₱100.00</span>
            </div>
            <div class="rider-balance-right">
                <button type="button" class="btn btn-primary btn-lg" id="withdrawBtnHero"
                    <?php echo $balance < 100 ? 'disabled' : ''; ?>>
                    Withdraw Funds
                </button>
            </div>
        </section>

        <section class="rider-earnings-stats">
            <div class="rider-earnings-stat">
                <span class="rider-earnings-stat-label">Today</span>
                <span class="rider-earnings-stat-value"><?php echo formatRiderCurrency($todayEarnings); ?></span>
                <span class="rider-earnings-stat-hint">
                    <?php echo (int)($stats['today_deliveries'] ?? 0); ?>
                    deliver<?php echo ($stats['today_deliveries'] ?? 0) === 1 ? 'y' : 'ies'; ?>
                </span>
            </div>
            <div class="rider-earnings-stat">
                <span class="rider-earnings-stat-label">This Week</span>
                <span class="rider-earnings-stat-value"><?php echo formatRiderCurrency($weekEarnings); ?></span>
                <span class="rider-earnings-stat-hint">
                    <?php echo (int)($stats['week_deliveries'] ?? 0); ?>
                    deliver<?php echo ($stats['week_deliveries'] ?? 0) === 1 ? 'y' : 'ies'; ?>
                </span>
            </div>
            <div class="rider-earnings-stat">
                <span class="rider-earnings-stat-label">This Month</span>
                <span class="rider-earnings-stat-value"><?php echo formatRiderCurrency($monthEarnings); ?></span>
                <span class="rider-earnings-stat-hint">
                    <?php echo (int)($stats['month_deliveries'] ?? 0); ?>
                    deliver<?php echo ($stats['month_deliveries'] ?? 0) === 1 ? 'y' : 'ies'; ?>
                </span>
            </div>
            <div class="rider-earnings-stat">
                <span class="rider-earnings-stat-label">All Time</span>
                <span class="rider-earnings-stat-value"><?php echo formatRiderCurrency($totalEarnings); ?></span>
                <span class="rider-earnings-stat-hint"><?php echo (int)($stats['total_deliveries'] ?? 0); ?>
                    total</span>
            </div>
        </section>

        <section class="rider-card">
            <div class="rider-card-header">
                <h2 class="heading-5">Earnings - Last 7 Days</h2>
            </div>
            <div class="rider-chart-body">
                <div class="rider-weekly-chart">
                    <div class="rider-chart-y-axis" aria-hidden="true">
                        <?php foreach (array_reverse($chartScale['gridlines']) as $grid): ?>
                        <span class="rider-chart-y-label">₱<?php echo number_format($grid, 0); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="rider-chart-plot">
                        <?php foreach ($chartScale['gridlines'] as $grid): ?>
                        <div class="rider-chart-gridline" aria-hidden="true"></div>
                        <?php endforeach; ?>
                        <div class="rider-chart-columns">
                            <?php foreach ($weekly as $day):
                                $pct = $barHeights[$day['date']];
                                $isToday = ($day['date'] === $today);
                                $hasValue = $day['amount'] > 0;
                            ?>
                            <div class="rider-chart-column"
                                data-day="<?php echo htmlspecialchars($day['short'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-amount="<?php echo htmlspecialchars(formatRiderCurrency($day['amount']), ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="rider-chart-bar-track">
                                    <div class="rider-chart-bar <?php echo $isToday ? 'is-today' : ''; ?> <?php echo $hasValue ? '' : 'is-empty'; ?>"
                                        style="height: <?php echo $pct; ?>%"></div>
                                </div>
                                <span class="rider-chart-label <?php echo $isToday ? 'is-today' : ''; ?>">
                                    <?php echo htmlspecialchars($day['short'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="rider-card">
            <div class="rider-card-header">
                <h2 class="heading-5">Transaction History</h2>
                <?php if ($totalTxns > 0): ?>
                <span class="rider-txn-count"><?php echo number_format($totalTxns); ?> total</span>
                <?php endif; ?>
            </div>

            <?php if (empty($transactions)): ?>
            <div class="rider-empty-state">
                <div class="rider-empty-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/coin-line.svg" alt="No transactions"
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/wallet-fill.svg'">
                </div>
                <p class="rider-empty-title">No transactions yet</p>
                <p class="rider-empty-text">Earnings will appear here after your first delivery.</p>
            </div>
            <?php else: ?>
            <div class="rider-txn-list">
                <?php foreach ($transactions as $txn):
                    $type     = (string)$txn['transaction_type'];
                    $status   = (string)$txn['status'];
                    $amount   = (float)$txn['amount'];
                    $isCredit = txnIsCredit($type);
                ?>
                <div class="rider-txn-row">
                    <div class="rider-txn-icon <?php echo $isCredit ? 'credit' : 'debit'; ?>">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/<?php echo $isCredit ? 'add-line.svg' : 'subtract-line.svg'; ?>"
                            alt="<?php echo $isCredit ? 'Credit' : 'Debit'; ?>">
                    </div>
                    <div class="rider-txn-info">
                        <p class="rider-txn-title">
                            <?php echo htmlspecialchars(txnLabel($type), ENT_QUOTES, 'UTF-8'); ?>
                            <?php if ($txn['order_id']): ?>
                            <span class="rider-txn-order">#<?php echo (int)$txn['order_id']; ?></span>
                            <?php endif; ?>
                        </p>
                        <p class="rider-txn-desc">
                            <?php echo htmlspecialchars($txn['description'] ?: '—', ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                        <p class="rider-txn-date"><?php echo formatTxnDate((string)$txn['transaction_date']); ?></p>
                    </div>
                    <div class="rider-txn-amount-col">
                        <span class="rider-txn-amount <?php echo $isCredit ? 'credit' : 'debit'; ?>">
                            <?php echo ($isCredit ? '+' : '−') . formatRiderCurrency($amount); ?>
                        </span>
                        <?php if ($status !== 'completed'): ?>
                        <span class="rider-txn-status <?php echo $status === 'pending' ? 'pending' : 'failed'; ?>">
                            <?php echo htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
            <nav class="rider-pagination" aria-label="Transaction pagination">
                <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?>" class="rider-pagination-link">Previous</a>
                <?php else: ?>
                <span class="rider-pagination-link disabled">Previous</span>
                <?php endif; ?>

                <span class="rider-pagination-info">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>

                <?php if ($page < $totalPages): ?>
                <a href="?page=<?php echo $page + 1; ?>" class="rider-pagination-link">Next</a>
                <?php else: ?>
                <span class="rider-pagination-link disabled">Next</span>
                <?php endif; ?>
            </nav>
            <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
</div>

<div id="withdrawModal" class="modal" style="display:none;">
    <div class="modal-overlay"></div>
    <div class="modal-content rider-withdraw-modal">
        <div class="modal-header">
            <p class="heading-5 modal-title">Withdraw Funds</p>
            <button type="button" class="modal-close" id="withdrawClose">&times;</button>
        </div>
        <div class="modal-body">
            <p class="rider-withdraw-note">
                Available balance: <strong><?php echo formatRiderCurrency($balance); ?></strong>
            </p>
            <form id="withdrawForm">
                <div class="form-group">
                    <label for="withdrawAmount" class="field-label">Amount to withdraw</label>
                    <input type="number" id="withdrawAmount" class="form-control" placeholder="0.00" min="100"
                        step="0.01" max="<?php echo number_format($balance, 2, '.', ''); ?>" required>
                    <p class="rider-withdraw-hint">Minimum: ₱100.00 • Maximum:
                        <?php echo formatRiderCurrency($balance); ?></p>
                    <p class="rider-withdraw-error" id="withdrawError"></p>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="withdrawCancel">Cancel</button>
            <button type="submit" form="withdrawForm" class="btn btn-primary" id="withdrawSubmit">Request
                Withdrawal</button>
        </div>
    </div>
</div>

<script>
window.FITPAL_RIDER_EARNINGS = {
    csrfToken: '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>',
    assetBase: '<?php echo $assetBase; ?>',
    balance: <?php echo json_encode($balance); ?>
};
</script>
<script src="../assets/ui/js/earnings.js" defer></script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>