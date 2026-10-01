<?php
/**
 * FitPal Rider Earnings Page
 *
 * Tabs
 * ----
 * Three tabs, each a separate panel:
 *
 *   Overview      Balance hero (net figure) + withdraw button +
 *                 four stat tiles + Order liability tile (when the
 *                 rider is carrying any liability). Default tab.
 *   Chart         Seven-day earnings bar chart.
 *   Transactions  Paginated transaction history, followed by a
 *                 read-only Collection History section.
 *
 * The active tab is a URL query parameter
 * (?tab=overview|chart|transactions). The default is overview.
 *
 * ---------------------------------------------------------------------
 * WHERE THE NUMBERS ON THIS PAGE COME FROM
 * ---------------------------------------------------------------------
 * Four sources feed the three tabs.
 *
 *   Available Balance (Overview hero)
 *       `financial_account.balance` for the rider. Written by the
 *       schema triggers on each successful delivery's deposit row
 *       and on each failed order's liability debit. May go
 *       negative when the rider has a settled liability for a
 *       failed order that exceeded their earned balance.
 *
 *   Earnings (Overview tiles, Chart tab)
 *       Deposits on the rider's financial account, written by
 *       creditDeliveryPayouts() in
 *       shared/backend/database/order-transaction-queries.php.
 *       The per-delivery amount is read from
 *       FITPAL_DELIVERY_BASE_FEE via getRiderDashboardStats().
 *
 *   Transactions tab list
 *       Every `transaction` row on the rider's financial account.
 *       Includes delivery-fee deposits and, on a failed order,
 *       the `payment` row written by
 *       settleFailedRiderLiability() with the description prefix
 *       "Rider liability for order #".
 *
 *   Collection History (Transactions tab, second section)
 *       Rows in `rider_collection` for the rider. This table
 *       remains COD-only: it is the audit trail of physical cash
 *       the rider has handled. It is separate from the uniform
 *       liability figure below, which now applies to every
 *       payment method and is read from
 *       orders.rider_liability_amount.
 *
 * ---------------------------------------------------------------------
 * BALANCE HERO — THE NET FIGURE
 * ---------------------------------------------------------------------
 * The hero card's headline number is the NET position:
 *
 *     displayed_balance = financial_account.balance
 *                       − rider_liability_total
 *
 * `financial_account.balance` is the money the rider has actually
 * earned (minus any settled liability debits) and can withdraw
 * when positive.
 *
 * `rider_liability_total` is the sum of
 * `orders.rider_liability_amount` across every order the rider is
 * currently holding — regardless of payment method. It is the
 * amount of customer money the rider is carrying between accept
 * and delivery.
 *
 * The subtraction is a VIEW-LAYER operation performed in PHP. No
 * column in the database is ever written negative by this page.
 *
 * The note under the amount states the arithmetic when the two
 * operands differ, so the rider never sees a number they cannot
 * reconcile.
 *
 * ---------------------------------------------------------------------
 * ORDER LIABILITY TILE
 * ---------------------------------------------------------------------
 * Below the stat grid, when the rider has any outstanding
 * liability, a dedicated tile restates the figure as its own
 * number:
 *
 *     Order liability
 *     −₱279.00
 *     Across 1 in-flight order; clears on delivery or failure.
 *
 * The tile duplicates a fact the hero note already states. The
 * duplication is intentional: the hero note is prose that explains
 * the arithmetic in passing, while the tile is a labelled figure
 * the rider can find by scanning the grid rather than reading a
 * sentence. Riders asked for both; both are rendered.
 *
 * The tile's leading minus sign is a view-layer prefix, identical
 * to the one on the hero amount. The column behind it stays
 * positive (CHECK rider_liability_amount >= 0).
 *
 * ---------------------------------------------------------------------
 * WITHDRAWAL
 * ---------------------------------------------------------------------
 * The withdraw button's enabled/disabled state is keyed on the RAW
 * wallet balance, not the net figure. A rider who has earned ₱200
 * and is carrying a ₱279 liability has ₱200 available to withdraw;
 * the carried customer money is not theirs to spend.
 *
 * A rider whose balance is negative because of a settled failure
 * liability has no withdrawable amount: the button is disabled and
 * the shortfall note explains the minimum.
 *
 * The withdrawal request inserts a `withdrawal` transaction with
 * status 'pending'. The trigger only moves the balance on a
 * 'completed' row.
 *
 * All SQL lives in rider-assignment-queries.php.
 *
 * @package FitPal
 * @version 6.0 — The hero note and the standalone liability tile
 *                now describe the figure as a rider liability
 *                across every payment method, matching the v2.5.0
 *                model. The words "COD collection" are removed
 *                from both strings; the underlying value is read
 *                from orders.rider_liability_amount via
 *                getRiderOutstandingCollections(). The Collection
 *                History section in the Transactions tab is
 *                unchanged and remains COD-only — it reads
 *                rider_collection, which is a different record.
 *
 *                (5.6: standalone Order liability tile restored.
 *                5.5: hero shows the net figure. 5.4: standalone
 *                tile removed; hero carried the liability line.
 *                5.2: Overview strip and Collection History.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('rider');

if (empty($_SESSION['delivery_rider_id'])) {
    header('Location: sign-in.php');
    exit;
}

require_once __DIR__ . '/../backend/database/rider-connect.php';
require_once __DIR__ . '/../backend/database/rider-assignment-queries.php';

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

// Uniform liability total (every payment method, v2.5.0) and the
// COD-only cash-custody history (v2.4.0, unchanged).
$outstanding = getRiderOutstandingCollections($database_connection, $riderId);
$collections = getRiderCollectionHistory($database_connection, $riderId, 10);

$balance       = (float)($profile['balance'] ?? 0);
$todayEarnings = (float)($stats['today_earnings'] ?? 0);
$weekEarnings  = (float)($stats['week_earnings'] ?? 0);
$monthEarnings = (float)($stats['month_earnings'] ?? 0);
$totalEarnings = (float)($stats['total_earnings'] ?? 0);

$collectionTotal = (float)($outstanding['total'] ?? 0);
$collectionCount = (int)($outstanding['count'] ?? 0);

// ---------------------------------------------------------------------
// NET FIGURE FOR THE HERO CARD
//
// displayed_balance = wallet balance − rider liability total.
//
// The wallet balance itself can be negative (settled failure
// liability). The liability total is always non-negative. The
// result is a view-layer sum and is never written back.
// ---------------------------------------------------------------------

$displayedBalance = $balance - $collectionTotal;
$isCarryingCash   = ($collectionCount > 0);

/**
 * Format a signed currency value with an explicit minus sign for
 * negatives. formatRiderCurrency() renders only the magnitude, so
 * it is not used for the hero's net figure or the liability tile.
 *
 * @param float $amount
 * @return string  e.g. "−₱279.00" or "₱500.00"
 */
function formatSignedRiderCurrency(float $amount): string
{
    $magnitude = '₱' . number_format(abs($amount), 2);
    return $amount < 0
        ? "\u{2212}" . $magnitude   // U+2212 MINUS SIGN
        : $magnitude;
}

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

function collectionStatusLabel(string $status): string
{
    return match ($status) {
        'collected' => 'In hand',
        'settled'   => 'Settled',
        'void'      => 'Void',
        default     => ucfirst($status),
    };
}

function collectionStatusClass(string $status): string
{
    return match ($status) {
        'collected' => 'pending',
        'settled'   => '',
        'void'      => 'failed',
        default     => '',
    };
}

/*
 * Withdraw eligibility is keyed on the RAW wallet balance, not the
 * displayed net figure. Carried customer money is not the rider's
 * to spend, so it does not unlock the withdraw button. A negative
 * balance — from a settled failure liability — also does not
 * unlock it.
 */
$minWithdrawal     = 100.00;
$canWithdraw       = ($balance >= $minWithdrawal);
$withdrawShortfall = $canWithdraw ? 0.0 : ($minWithdrawal - $balance);

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

            <!-- ============================================================
                 BALANCE HERO

                 Headline number is the NET figure:
                     wallet balance − rider liability total

                 The wallet column itself can be negative on a
                 settled failure liability. The subtraction is a
                 view-layer operation.

                 The note text names the figure as a rider
                 liability across every payment method — not a COD
                 collection — because v2.5.0 records the liability
                 on every accepted order, regardless of how the
                 customer paid.
                 ============================================================ -->
            <div class="rider-balance-card">
                <div class="rider-balance-left">
                    <span class="rider-balance-label">Available Balance</span>
                    <span class="rider-balance-amount">
                        <?php echo htmlspecialchars(formatSignedRiderCurrency($displayedBalance), ENT_QUOTES, 'UTF-8'); ?>
                    </span>

                    <?php if ($isCarryingCash): ?>
                    <span class="rider-balance-note">
                        <?php echo htmlspecialchars(formatRiderCurrency($balance), ENT_QUOTES, 'UTF-8'); ?>
                        balance
                        &minus;
                        <?php echo htmlspecialchars(formatRiderCurrency($collectionTotal), ENT_QUOTES, 'UTF-8'); ?>
                        rider liability across
                        <?php echo $collectionCount; ?>
                        order<?php echo $collectionCount === 1 ? '' : 's'; ?>.
                    </span>
                    <?php elseif ($canWithdraw): ?>
                    <span class="rider-balance-note">Withdrawals require a minimum of ₱100.00</span>
                    <?php elseif ($balance < 0): ?>
                    <span class="rider-balance-note">
                        Your balance is negative from a settled order liability.
                        Complete deliveries to earn it back.
                    </span>
                    <?php else: ?>
                    <span class="rider-balance-note">
                        You need
                        <?php echo htmlspecialchars(formatRiderCurrency($withdrawShortfall), ENT_QUOTES, 'UTF-8'); ?>
                        more before you can withdraw. Minimum is ₱100.00.
                    </span>
                    <?php endif; ?>
                </div>

                <div class="rider-balance-right">
                    <button type="button" class="btn btn-primary btn-lg" id="withdrawBtnHero"
                        <?php echo $canWithdraw ? '' : 'disabled'; ?>>
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

            <!-- ============================================================
                 ORDER LIABILITY TILE

                 Restates the outstanding rider liability as its own
                 labelled figure below the stat grid. Rendered only
                 when the rider is carrying any liability.

                 The figure covers every payment method — COD,
                 Wallet, and Online — because v2.5.0 writes a
                 liability for every accepted order. The leading
                 minus sign is a view-layer prefix; the column stays
                 non-negative per CHECK rider_liability_amount >= 0.
                 ============================================================ -->
            <?php if ($isCarryingCash): ?>
            <div class="rider-earnings-stat rider-earnings-stat-liability">
                <span class="rider-earnings-stat-label">Order liability</span>
                <span class="rider-earnings-stat-value">
                    <?php echo htmlspecialchars(formatSignedRiderCurrency(-$collectionTotal), ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <span class="rider-earnings-stat-hint">
                    Across <?php echo $collectionCount; ?>
                    in-flight order<?php echo $collectionCount === 1 ? '' : 's'; ?>;
                    clears on delivery or failure.
                </span>
            </div>
            <?php endif; ?>

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

            <!-- Collection History — read-only list of physical cash the
                 rider handled on COD orders. This section reads
                 rider_collection directly and is intentionally
                 COD-only: it is the cash-custody audit trail, a
                 different record from the uniform order liability
                 shown on the Overview tab. -->
            <div class="rider-card rider-card-spaced">
                <div class="rider-card-header">
                    <h2 class="heading-5">Collection History</h2>
                    <?php if (!empty($collections)): ?>
                    <span class="rider-txn-count"><?php echo count($collections); ?> shown</span>
                    <?php endif; ?>
                </div>

                <?php if (empty($collections)): ?>
                <div class="rider-empty-state">
                    <div class="rider-empty-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/wallet-line.svg" alt="No collections"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/coin-line.svg'">
                    </div>
                    <p class="rider-empty-title">No cash collections yet</p>
                    <p class="rider-empty-text">
                        Cash you collect from customers on COD orders will appear here.
                    </p>
                </div>
                <?php else: ?>
                <div class="rider-txn-list">
                    <?php foreach ($collections as $col):
                        $colStatus   = (string)($col['status'] ?? 'collected');
                        $colAmount   = (float)($col['amount'] ?? 0);
                        $colOrder    = (int)($col['order_id'] ?? 0);
                        $collectedAt = (string)($col['collected_at'] ?? '');
                        $settledAt   = (string)($col['settled_at'] ?? '');
                        $statusClass = collectionStatusClass($colStatus);
                    ?>
                    <div class="rider-txn-row">
                        <div class="rider-txn-icon <?php echo $colStatus === 'settled' ? 'credit' : 'debit'; ?>">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/<?php echo $colStatus === 'settled' ? 'verified-fill.svg' : 'coin-line.svg'; ?>"
                                alt="<?php echo htmlspecialchars(collectionStatusLabel($colStatus), ENT_QUOTES, 'UTF-8'); ?>"
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/coin-line.svg'">
                        </div>
                        <div class="rider-txn-info">
                            <p class="rider-txn-title">
                                Order #<?php echo $colOrder; ?>
                            </p>
                            <p class="rider-txn-desc">
                                <?php if ($collectedAt !== ''): ?>
                                Collected <?php echo formatTxnDate($collectedAt); ?>
                                <?php endif; ?>
                                <?php if ($settledAt !== ''): ?>
                                &middot; Settled <?php echo formatTxnDate($settledAt); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="rider-txn-amount-col">
                            <span class="rider-txn-amount debit">
                                <?php echo formatRiderCurrency($colAmount); ?>
                            </span>
                            <span
                                class="rider-txn-status <?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars(collectionStatusLabel($colStatus), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php endif; ?>
        </section>

    </div>
</div>

<!-- ============================================================
     WITHDRAWAL MODAL
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