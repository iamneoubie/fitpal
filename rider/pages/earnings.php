<?php
/**
 * FitPal Rider Earnings Page
 *
 * Tabs
 * ----
 * Three tabs, each a separate panel:
 *
 *   Overview      Balance hero + withdraw button + four stat tiles.
 *                 Default tab.
 *   Chart         Seven-day earnings bar chart.
 *   Transactions  Paginated transaction history.
 *
 * The active tab is a URL query parameter
 * (?tab=overview|chart|transactions). The default is overview.
 *
 * Withdrawal modal
 * ----------------
 * The modal uses the shared .modal > .modal-content skeleton
 * exactly as the customer wallet recharge modal does. The outer
 * .modal wrapper carries the page-specific class
 * .rider-withdraw-modal so every scoped modal rule in
 * rider-earnings.css can match the DOM:
 *
 *     <div class="modal rider-withdraw-modal">  ← outer wrapper
 *         <div class="modal-overlay"></div>
 *         <div class="modal-content">           ← shared skeleton
 *             …
 *
 * The modal body carries ONE lead-in paragraph
 * (.rider-withdraw-note) — no second hint line below the input —
 * so the vertical rhythm matches the customer wallet recharge
 * modal exactly:
 *
 *     lead-in → input → error slot → quick amounts → footer
 *
 * All SQL lives in rider-queries.php. This page contains no SQL.
 *
 * @package FitPal
 * @version 5.0 — Moved .rider-withdraw-modal from the inner
 *                .modal-content div to the outer #withdrawModal
 *                wrapper, so the compound selectors in
 *                rider-earnings.css
 *                (.rider-withdraw-modal .modal-header, etc.)
 *                actually match the DOM.
 *
 *                (4.0: single lead-in paragraph, inline
 *                display:none removed, header carries inline SVG.
 *                3.0: modal structure aligned with customer wallet
 *                recharge modal. 2.3: page header rebuilt.
 *                2.2: withdrawal modal rebuilt. 2.0: tabs added.
 *                1.2: docblock corrected. 1.1: local CSRF block
 *                removed.)
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
$today        = date('Y-m-d');

$barHeights = [];
foreach ($weekly as $day) {
    $pct = $chartCeiling > 0 ? ($day['amount'] / $chartCeiling) * 100 : 0;
    $barHeights[$day['date']] = $day['amount'] > 0 ? max(4, min(100, $pct)) : 0;
}

$allowedTabs = ['overview', 'chart', 'transactions'];
$activeTab   = isset($_GET['tab']) ? strtolower(trim((string)$_GET['tab'])) : 'overview';
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'overview';
}

function earningsTabUrl(string $tab, int $page = 1): string
{
    $params = ['tab' => $tab];
    if ($page > 1) {
        $params['page'] = $page;
    }
    return 'earnings.php?' . http_build_query($params);
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

<div class="content rider-earnings-page" id="riderEarningsPage"
    data-csrf-token="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"
    data-balance="<?php echo htmlspecialchars((string)$balance, ENT_QUOTES, 'UTF-8'); ?>">
    <div class="container">

        <div class="page-title-header">
            <div class="page-title-header-top">
                <a href="dashboard.php" class="back-btn">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-left-line.svg" alt="Back"
                        class="back-btn-icon" width="20" height="20">
                    <span>Back to Dashboard</span>
                </a>
                <h1>My Earnings</h1>
            </div>
        </div>

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

        <!-- ============================================================
             TABS
             ============================================================ -->
        <nav class="rider-earnings-tabs" role="tablist" aria-label="Earnings sections">
            <a href="<?php echo htmlspecialchars(earningsTabUrl('overview'), ENT_QUOTES, 'UTF-8'); ?>"
                class="rider-earnings-tab <?php echo $activeTab === 'overview' ? 'active' : ''; ?>" role="tab"
                aria-selected="<?php echo $activeTab === 'overview' ? 'true' : 'false'; ?>"
                aria-controls="panel-overview" id="tabBtnOverview">
                <img src="<?php echo $assetBase; ?>assets/images/icons/wallet-line.svg" alt=""
                    class="rider-earnings-tab-icon"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/wallet-fill.svg'">
                <span>Overview</span>
            </a>

            <a href="<?php echo htmlspecialchars(earningsTabUrl('chart'), ENT_QUOTES, 'UTF-8'); ?>"
                class="rider-earnings-tab <?php echo $activeTab === 'chart' ? 'active' : ''; ?>" role="tab"
                aria-selected="<?php echo $activeTab === 'chart' ? 'true' : 'false'; ?>" aria-controls="panel-chart"
                id="tabBtnChart">
                <img src="<?php echo $assetBase; ?>assets/images/icons/chart-line-up.svg" alt=""
                    class="rider-earnings-tab-icon"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/list-view.svg'">
                <span>Chart</span>
            </a>

            <a href="<?php echo htmlspecialchars(earningsTabUrl('transactions'), ENT_QUOTES, 'UTF-8'); ?>"
                class="rider-earnings-tab <?php echo $activeTab === 'transactions' ? 'active' : ''; ?>" role="tab"
                aria-selected="<?php echo $activeTab === 'transactions' ? 'true' : 'false'; ?>"
                aria-controls="panel-transactions" id="tabBtnTransactions">
                <img src="<?php echo $assetBase; ?>assets/images/icons/history-line.svg" alt=""
                    class="rider-earnings-tab-icon"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/time-update.svg'">
                <span>Transactions</span>
            </a>
        </nav>

        <!-- ============================================================
             TAB: OVERVIEW
             ============================================================ -->
        <section class="rider-earnings-panel <?php echo $activeTab === 'overview' ? 'active' : ''; ?>"
            id="panel-overview" role="tabpanel" aria-labelledby="tabBtnOverview">
            <?php if ($activeTab === 'overview'): ?>

            <div class="rider-balance-card">
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
            </div>

            <div class="rider-earnings-stats">
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
                    <span class="rider-earnings-stat-hint">
                        <?php echo (int)($stats['total_deliveries'] ?? 0); ?> total
                    </span>
                </div>
            </div>

            <?php endif; ?>
        </section>

        <!-- ============================================================
             TAB: CHART
             ============================================================ -->
        <section class="rider-earnings-panel <?php echo $activeTab === 'chart' ? 'active' : ''; ?>" id="panel-chart"
            role="tabpanel" aria-labelledby="tabBtnChart">
            <?php if ($activeTab === 'chart'): ?>

            <div class="rider-card">
                <div class="rider-card-header">
                    <h2 class="heading-5">Earnings — Last 7 Days</h2>
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
                                    $pct      = $barHeights[$day['date']];
                                    $isToday  = ($day['date'] === $today);
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
            </div>

            <?php endif; ?>
        </section>

        <!-- ============================================================
             TAB: TRANSACTIONS
             ============================================================ -->
        <section class="rider-earnings-panel <?php echo $activeTab === 'transactions' ? 'active' : ''; ?>"
            id="panel-transactions" role="tabpanel" aria-labelledby="tabBtnTransactions">
            <?php if ($activeTab === 'transactions'): ?>

            <div class="rider-card">
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
                    <a href="<?php echo htmlspecialchars(earningsTabUrl('transactions', $page - 1), ENT_QUOTES, 'UTF-8'); ?>"
                        class="rider-pagination-link">Previous</a>
                    <?php else: ?>
                    <span class="rider-pagination-link disabled">Previous</span>
                    <?php endif; ?>

                    <span class="rider-pagination-info">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>

                    <?php if ($page < $totalPages): ?>
                    <a href="<?php echo htmlspecialchars(earningsTabUrl('transactions', $page + 1), ENT_QUOTES, 'UTF-8'); ?>"
                        class="rider-pagination-link">Next</a>
                    <?php else: ?>
                    <span class="rider-pagination-link disabled">Next</span>
                    <?php endif; ?>
                </nav>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php endif; ?>
        </section>

    </div>
</div>

<!-- ============================================================
     WITHDRAWAL MODAL
     The outer .modal wrapper carries the rider-specific class
     .rider-withdraw-modal so the scoped CSS selectors match.
     The inner .modal-content stays the shared skeleton.
     ============================================================ -->
<div id="withdrawModal" class="modal rider-withdraw-modal" aria-hidden="true">
    <div class="modal-overlay"></div>
    <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="withdrawModalTitle">
        <div class="modal-header">
            <p class="heading-5 modal-title" id="withdrawModalTitle">
                <span>Withdraw Funds</span>
            </p>
            <button type="button" class="modal-close" id="withdrawClose" aria-label="Close">&times;</button>
        </div>

        <div class="modal-body">
            <p class="rider-withdraw-note">
                Available balance: <strong><?php echo formatRiderCurrency($balance); ?></strong>.
                Minimum ₱100.00 &bull; Maximum <?php echo formatRiderCurrency($balance); ?>.
            </p>

            <div class="rider-withdraw-amount-wrap">
                <span class="rider-withdraw-amount-prefix" aria-hidden="true">₱</span>
                <input type="text" id="withdrawAmount" class="rider-withdraw-amount-input" placeholder="0.00"
                    inputmode="decimal" autocomplete="off" aria-label="Amount to withdraw">
            </div>

            <p class="rider-withdraw-error" id="withdrawError" role="alert" aria-live="polite"></p>

            <div class="rider-withdraw-quick">
                <button type="button" class="rider-withdraw-quick-btn" data-amount="100">₱100</button>
                <button type="button" class="rider-withdraw-quick-btn" data-amount="200">₱200</button>
                <button type="button" class="rider-withdraw-quick-btn" data-amount="500">₱500</button>
                <button type="button" class="rider-withdraw-quick-btn" data-amount="1000">₱1,000</button>
            </div>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="withdrawCancel">Cancel</button>
            <button type="button" class="btn btn-primary" id="withdrawSubmit">Request Withdrawal</button>
        </div>
    </div>
</div>

<script src="../assets/ui/js/earnings.js" defer></script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>