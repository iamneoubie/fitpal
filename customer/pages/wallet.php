<?php
/**
 * FitPal Customer Wallet Page
 *
 * Two-tab layout:
 *   - Credit        balance hero, Recharge button, wallet-only
 *                   activity list (completed movements only)
 *   - Transactions  full wallet-account activity list (every row,
 *                   including pending COD and Online payments)
 *
 * Only the active tab's markup is rendered. The other panel is not
 * in the DOM. The tab is selected by ?tab=credit or
 * ?tab=transactions; anything else falls back to credit.
 *
 * Each tab paginates independently. The Credit tab's page count
 * comes from countWalletTransactions(); the Transactions tab's
 * comes from countWalletAccountActivity(). Both are scoped to the
 * same customer but over different filters, so a page-2 link on one
 * tab never lands on a page that belongs to the other.
 *
 * ---------------------------------------------------------------------
 * SCOPE RULES APPLIED
 * ---------------------------------------------------------------------
 *  - No SQL. getWalletAccount(), getWalletTransactions(),
 *    countWalletTransactions(), getWalletAccountActivity(), and
 *    countWalletAccountActivity() come from wallet-queries.php.
 *  - No inline CSS. wallet.css is loaded via the customer header's
 *    $pageCssMap.
 *  - formatCurrency() comes from customer-queries.php. It is NOT
 *    declared here.
 *  - No inline JS beyond the FITPAL_WALLET config block that
 *    wallet.js already reads. That block is a data bag, not
 *    behaviour.
 * ---------------------------------------------------------------------
 *
 * @package FitPal
 * @version 5.0 — Both tabs now carry a wallet-transactions-card.
 *                  - Credit tab lists completed wallet movements
 *                    only (deposits, wallet payments, refunds,
 *                    withdrawals).
 *                  - Transactions tab lists every row on the
 *                    wallet account, including pending and failed
 *                    COD and Online payments, and labels each row
 *                    with a status pill and a payment-method badge
 *                    where those apply.
 *                  - Pagination is per tab.
 *
 *                (4.0: split into two anchor tabs. 3.1: CSRF
 *                inherited from header.php. 3.0: local walletFmt()
 *                removed; uses formatCurrency().)
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    header('Location: sign-in.php');
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../backend/database/customer-connect.php';
require_once __DIR__ . '/../backend/database/customer-queries.php';
require_once __DIR__ . '/../backend/database/wallet-queries.php';

$customerId = (int)$_SESSION['customer_id'];

/* ============================================
   ORIGIN RESOLUTION FOR THE BACK BUTTON
   ============================================ */
$walletBackMap = [
    'checkout'  => 'checkout.php',
    'orders'    => 'orders.php',
    'menu'      => 'menu.php',
    'profile'   => 'profile.php',
    'dashboard' => 'dashboard.php',
    'cart'      => 'cart.php',
];

$fromParam = isset($_GET['from']) ? strtolower(trim((string)$_GET['from'])) : '';

$walletBackHref = '';

if ($fromParam !== '' && isset($walletBackMap[$fromParam])) {
    $walletBackHref = $walletBackMap[$fromParam];
} else {
    $referrer = $_SERVER['HTTP_REFERER'] ?? '';
    if ($referrer !== '') {
        $referrerFile = basename((string)parse_url($referrer, PHP_URL_PATH));
        if ($referrerFile !== '') {
            $walletBackMapByFile = array_flip($walletBackMap);
            if (isset($walletBackMapByFile[$referrerFile])) {
                $walletBackHref = $referrerFile;
            }
        }
    }
}

/* ============================================
   ACTIVE TAB
   ============================================
   Two tabs. Anything other than 'transactions' lands on 'credit'.
   The default is intentionally Credit, because the header's wallet
   link arrives with no query string and the first thing a customer
   usually wants is their balance.
   ============================================ */
$activeTab = isset($_GET['tab']) ? strtolower(trim((string)$_GET['tab'])) : 'credit';
if (!in_array($activeTab, ['credit', 'transactions'], true)) {
    $activeTab = 'credit';
}

/* ============================================
   HELPERS (page-local; no DB access)
   ============================================ */

/**
 * Build a wallet page URL that keeps the customer on the tab the
 * link was clicked from.
 *
 * @param string $tab       'credit' | 'transactions'
 * @param int    $targetPage 1-based
 * @param string $fromParam  origin slug, or '' for none
 * @param array<string,string> $backMap
 */
function walletPageUrl(string $tab, int $targetPage, string $fromParam, array $backMap): string
{
    $params = [
        'tab'  => $tab,
        'page' => $targetPage,
    ];
    if ($fromParam !== '' && isset($backMap[$fromParam])) {
        $params['from'] = $fromParam;
    }
    return 'wallet.php?' . http_build_query($params);
}

/**
 * Build the tab href for a given tab, preserving the from= origin.
 */
function walletTabUrl(string $tab, string $fromParam, array $backMap): string
{
    $params = ['tab' => $tab];
    if ($fromParam !== '' && isset($backMap[$fromParam])) {
        $params['from'] = $fromParam;
    }
    return 'wallet.php?' . http_build_query($params);
}

function walletTypeLabel(string $type): string
{
    return match ($type) {
        'deposit'    => 'Top Up',
        'payment'    => 'Payment',
        'refund'     => 'Refund',
        'withdrawal' => 'Withdrawal',
        default      => ucfirst($type),
    };
}

function walletIsCredit(string $type): bool
{
    return in_array($type, ['deposit', 'refund'], true);
}

function walletDirectionIcon(string $type): string
{
    return walletIsCredit($type) ? 'add-line.svg' : 'subtract-line.svg';
}

function walletDirectionAlt(string $type): string
{
    return walletIsCredit($type) ? 'Credit' : 'Debit';
}

function walletDate(string $date): string
{
    $ts = strtotime($date);
    return $ts !== false ? date('M d, Y • g:i A', $ts) : $date;
}

/**
 * Human-readable label for the order payment method that produced
 * a given transaction row. Returns '' when the row has no
 * associated order (deposits, withdrawals, refunds on legacy
 * orders) or when the value is unrecognized.
 */
function walletPaymentMethodLabel(?string $method): string
{
    return match ($method) {
        'COD'    => 'COD',
        'Wallet' => 'Wallet',
        'Online' => 'Online',
        default  => '',
    };
}

/* ============================================
   DATA
   ============================================ */

$account = getWalletAccount($database_connection, $customerId);
if (!$account) {
    $_SESSION['profile_error'] = 'Wallet account not found. Please contact support.';
    header('Location: profile.php');
    exit;
}

$balance = (float)$account['balance'];

$perPage = 5;
$page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset  = ($page - 1) * $perPage;

$transactions = [];
$totalTxns    = 0;
$totalPages   = 1;

if ($activeTab === 'credit') {
    // Credit tab: completed wallet movements only.
    $transactions = getWalletTransactions($database_connection, $customerId, $perPage, $offset);
    $returnedRows = count($transactions);

    if ($returnedRows > 0 && $returnedRows < $perPage) {
        $totalTxns  = $offset + $returnedRows;
        $totalPages = max(1, $page);
    } elseif ($returnedRows === 0 && $page === 1) {
        $totalTxns  = 0;
        $totalPages = 1;
    } else {
        $totalTxns  = countWalletTransactions($database_connection, $customerId);
        $totalPages = max(1, (int)ceil($totalTxns / $perPage));
    }

    if ($page > $totalPages) {
        $page         = $totalPages;
        $offset       = ($page - 1) * $perPage;
        $transactions = getWalletTransactions($database_connection, $customerId, $perPage, $offset);
    }

} else {
    // Transactions tab: every row on the wallet account.
    $transactions = getWalletAccountActivity($database_connection, $customerId, $perPage, $offset);
    $returnedRows = count($transactions);

    if ($returnedRows > 0 && $returnedRows < $perPage) {
        $totalTxns  = $offset + $returnedRows;
        $totalPages = max(1, $page);
    } elseif ($returnedRows === 0 && $page === 1) {
        $totalTxns  = 0;
        $totalPages = 1;
    } else {
        $totalTxns  = countWalletAccountActivity($database_connection, $customerId);
        $totalPages = max(1, (int)ceil($totalTxns / $perPage));
    }

    if ($page > $totalPages) {
        $page         = $totalPages;
        $offset       = ($page - 1) * $perPage;
        $transactions = getWalletAccountActivity($database_connection, $customerId, $perPage, $offset);
    }
}

require_once __DIR__ . '/../includes/header.php';

// $csrfToken is provided by header.php (via includes/csrf_token.php),
// stored under the customer role's own session key 'customer_csrf_token'.
?>

<div class="content wallet-page" id="walletPage">
    <div class="container">

        <!-- ============================================ -->
        <!-- PAGE HEADER -->
        <!-- ============================================ -->
        <div class="page-title-header">
            <div class="page-title-header-top">
                <button type="button" id="walletBackBtn" class="back-btn"
                    data-fallback-href="<?php echo htmlspecialchars($walletBackHref, ENT_QUOTES, 'UTF-8'); ?>">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-left-line.svg" alt="Back"
                        class="back-btn-icon" width="20" height="20">
                    <span>Back</span>
                </button>
                <h1>My Wallet</h1>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- FLASH MESSAGES -->
        <!-- ============================================ -->
        <?php if (isset($_SESSION['wallet_success'])): ?>
        <div class="alert alert-success" role="alert">
            <?php echo htmlspecialchars($_SESSION['wallet_success'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['wallet_success']); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['wallet_error'])): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo htmlspecialchars($_SESSION['wallet_error'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['wallet_error']); ?>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- TAB BAR -->
        <!-- ============================================ -->
        <nav class="wallet-tabs" aria-label="Wallet sections">
            <a href="<?php echo htmlspecialchars(walletTabUrl('credit', $fromParam, $walletBackMap), ENT_QUOTES, 'UTF-8'); ?>"
                class="wallet-tab <?php echo $activeTab === 'credit' ? 'active' : ''; ?>"
                <?php echo $activeTab === 'credit' ? 'aria-current="page"' : ''; ?>>
                <img src="<?php echo $assetBase; ?>assets/images/icons/wallet-line.svg" alt="" class="wallet-tab-icon"
                    width="16" height="16"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/wallet-fill.svg'">
                <span>Credit</span>
            </a>

            <a href="<?php echo htmlspecialchars(walletTabUrl('transactions', $fromParam, $walletBackMap), ENT_QUOTES, 'UTF-8'); ?>"
                class="wallet-tab <?php echo $activeTab === 'transactions' ? 'active' : ''; ?>"
                <?php echo $activeTab === 'transactions' ? 'aria-current="page"' : ''; ?>>
                <img src="<?php echo $assetBase; ?>assets/images/icons/history-line.svg" alt="" class="wallet-tab-icon"
                    width="16" height="16"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/list-view.svg'">
                <span>Transactions</span>
            </a>
        </nav>

        <?php if ($activeTab === 'credit'): ?>

        <!-- ============================================ -->
        <!-- CREDIT PANEL -->
        <!-- Balance hero + Recharge button + wallet-only -->
        <!-- activity card. No status pill, no method badge — -->
        <!-- every row here is a completed wallet movement. -->
        <!-- ============================================ -->
        <section class="wallet-panel wallet-panel-credit" aria-label="Wallet credit">

            <div class="wallet-balance-card">
                <div class="wallet-balance-left">
                    <span class="wallet-balance-label">Available Balance</span>
                    <span class="wallet-balance-amount" id="walletBalanceAmount">
                        <?php echo formatCurrency($balance); ?>
                    </span>
                    <span class="wallet-balance-note">Use your wallet to pay for orders instantly</span>
                </div>
                <div class="wallet-balance-right">
                    <button type="button" id="rechargeBtn" class="btn btn-primary btn-lg">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/add-line.svg" alt="" class="btn-icon"
                            width="18" height="18">
                        Recharge Wallet
                    </button>
                </div>
            </div>

            <div class="wallet-transactions-card">
                <div class="card-header">
                    <h3>Wallet Activity</h3>
                    <?php if ($totalTxns > 0): ?>
                    <span class="txn-count"><?php echo number_format($totalTxns); ?> total</span>
                    <?php endif; ?>
                </div>

                <?php if (empty($transactions)): ?>
                <div class="wallet-empty-state">
                    <div class="wallet-empty-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/history-line.svg" alt="No transactions"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                    </div>
                    <p class="wallet-empty-title">No wallet activity yet</p>
                    <p class="wallet-empty-text">
                        Recharges, wallet payments, and refunds will appear here once they happen.
                    </p>
                </div>
                <?php else: ?>
                <ul class="wallet-txn-list">
                    <?php foreach ($transactions as $txn):
                        $type     = (string)$txn['transaction_type'];
                        $amount   = (float)$txn['amount'];
                        $isCredit = walletIsCredit($type);
                        $iconFile = walletDirectionIcon($type);
                        $iconAlt  = walletDirectionAlt($type);
                    ?>
                    <li class="wallet-txn-item">
                        <div class="wallet-txn-icon <?php echo $isCredit ? 'credit' : 'debit'; ?>">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/<?php echo htmlspecialchars($iconFile, ENT_QUOTES, 'UTF-8'); ?>"
                                alt="<?php echo htmlspecialchars($iconAlt, ENT_QUOTES, 'UTF-8'); ?>"
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                        </div>

                        <div class="wallet-txn-info">
                            <p class="wallet-txn-title">
                                <?php echo htmlspecialchars(walletTypeLabel($type), ENT_QUOTES, 'UTF-8'); ?>
                                <?php if ($txn['order_id']): ?>
                                <span class="wallet-txn-order">#<?php echo (int)$txn['order_id']; ?></span>
                                <?php endif; ?>
                            </p>
                            <p class="wallet-txn-desc">
                                <?php echo htmlspecialchars($txn['description'] ?: '—', ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                            <p class="wallet-txn-date">
                                <?php echo walletDate((string)$txn['transaction_date']); ?>
                            </p>
                        </div>

                        <div class="wallet-txn-amount-col">
                            <span class="wallet-txn-amount <?php echo $isCredit ? 'credit' : 'debit'; ?>">
                                <?php echo ($isCredit ? '+' : '−') . formatCurrency($amount); ?>
                            </span>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>

                <?php if ($totalPages > 1): ?>
                <nav class="wallet-pagination" role="navigation" aria-label="Wallet activity pagination">
                    <ul class="pagination-list">
                        <?php if ($page > 1): ?>
                        <li>
                            <a href="<?php echo htmlspecialchars(walletPageUrl('credit', $page - 1, $fromParam, $walletBackMap), ENT_QUOTES, 'UTF-8'); ?>"
                                class="pagination-link pagination-prev">Previous</a>
                        </li>
                        <?php else: ?>
                        <li><span class="pagination-link pagination-prev disabled">Previous</span></li>
                        <?php endif; ?>

                        <?php
                        $maxVisible = 5;
                        $startPage  = max(1, $page - (int)floor($maxVisible / 2));
                        $endPage    = min($totalPages, $startPage + $maxVisible - 1);
                        if ($endPage - $startPage + 1 < $maxVisible) {
                            $startPage = max(1, $endPage - $maxVisible + 1);
                        }
                        ?>

                        <?php if ($startPage > 1): ?>
                        <li>
                            <a href="<?php echo htmlspecialchars(walletPageUrl('credit', 1, $fromParam, $walletBackMap), ENT_QUOTES, 'UTF-8'); ?>"
                                class="pagination-link">1</a>
                        </li>
                        <?php if ($startPage > 2): ?>
                        <li class="pagination-ellipsis"><span>...</span></li>
                        <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <li>
                            <?php if ($i === $page): ?>
                            <span class="pagination-link active" aria-current="page"><?php echo $i; ?></span>
                            <?php else: ?>
                            <a href="<?php echo htmlspecialchars(walletPageUrl('credit', $i, $fromParam, $walletBackMap), ENT_QUOTES, 'UTF-8'); ?>"
                                class="pagination-link"><?php echo $i; ?></a>
                            <?php endif; ?>
                        </li>
                        <?php endfor; ?>

                        <?php if ($endPage < $totalPages): ?>
                        <?php if ($endPage < $totalPages - 1): ?>
                        <li class="pagination-ellipsis"><span>...</span></li>
                        <?php endif; ?>
                        <li>
                            <a href="<?php echo htmlspecialchars(walletPageUrl('credit', $totalPages, $fromParam, $walletBackMap), ENT_QUOTES, 'UTF-8'); ?>"
                                class="pagination-link"><?php echo $totalPages; ?></a>
                        </li>
                        <?php endif; ?>

                        <?php if ($page < $totalPages): ?>
                        <li>
                            <a href="<?php echo htmlspecialchars(walletPageUrl('credit', $page + 1, $fromParam, $walletBackMap), ENT_QUOTES, 'UTF-8'); ?>"
                                class="pagination-link pagination-next">Next</a>
                        </li>
                        <?php else: ?>
                        <li><span class="pagination-link pagination-next disabled">Next</span></li>
                        <?php endif; ?>
                    </ul>
                </nav>
                <?php endif; ?>
                <?php endif; ?>
            </div>

        </section>

        <?php else: ?>

        <!-- ============================================ -->
        <!-- TRANSACTIONS PANEL -->
        <!-- Full wallet-account activity. Every row, -->
        <!-- including pending and failed COD / Online. -->
        <!-- Status pill on non-completed rows. Payment- -->
        <!-- method badge on rows with an associated order. -->
        <!-- ============================================ -->
        <section class="wallet-panel wallet-panel-transactions" aria-label="Wallet transactions">

            <div class="wallet-transactions-card">
                <div class="card-header">
                    <h3>Account Activity</h3>
                    <?php if ($totalTxns > 0): ?>
                    <span class="txn-count"><?php echo number_format($totalTxns); ?> total</span>
                    <?php endif; ?>
                </div>

                <?php if (empty($transactions)): ?>
                <div class="wallet-empty-state">
                    <div class="wallet-empty-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/history-line.svg" alt="No activity"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                    </div>
                    <p class="wallet-empty-title">No account activity yet</p>
                    <p class="wallet-empty-text">
                        Orders, recharges, refunds, and withdrawals will appear here once they happen.
                    </p>
                </div>
                <?php else: ?>
                <ul class="wallet-txn-list">
                    <?php foreach ($transactions as $txn):
                        $type      = (string)$txn['transaction_type'];
                        $status    = (string)$txn['status'];
                        $amount    = (float)$txn['amount'];
                        $isCredit  = walletIsCredit($type);
                        $iconFile  = walletDirectionIcon($type);
                        $iconAlt   = walletDirectionAlt($type);
                        $isPending = $status === 'pending';
                        $isFailed  = $status === 'failed';

                        $methodRaw   = isset($txn['order_payment_method'])
                            ? (string)$txn['order_payment_method']
                            : '';
                        $methodLabel = walletPaymentMethodLabel($methodRaw);

                        $itemClass = 'wallet-txn-item';
                        if ($isPending) $itemClass .= ' is-pending';
                        if ($isFailed)  $itemClass .= ' is-failed';
                    ?>
                    <li class="<?php echo $itemClass; ?>">
                        <div class="wallet-txn-icon <?php echo $isCredit ? 'credit' : 'debit'; ?>">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/<?php echo htmlspecialchars($iconFile, ENT_QUOTES, 'UTF-8'); ?>"
                                alt="<?php echo htmlspecialchars($iconAlt, ENT_QUOTES, 'UTF-8'); ?>"
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                        </div>

                        <div class="wallet-txn-info">
                            <p class="wallet-txn-title">
                                <?php echo htmlspecialchars(walletTypeLabel($type), ENT_QUOTES, 'UTF-8'); ?>
                                <?php if ($txn['order_id']): ?>
                                <span class="wallet-txn-order">#<?php echo (int)$txn['order_id']; ?></span>
                                <?php endif; ?>
                                <?php if ($methodLabel !== ''): ?>
                                <span
                                    class="wallet-txn-method wallet-txn-method-<?php echo strtolower($methodLabel); ?>">
                                    <?php echo htmlspecialchars($methodLabel, ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <?php endif; ?>
                            </p>
                            <p class="wallet-txn-desc">
                                <?php echo htmlspecialchars($txn['description'] ?: '—', ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                            <p class="wallet-txn-date">
                                <?php echo walletDate((string)$txn['transaction_date']); ?>
                            </p>
                        </div>

                        <div class="wallet-txn-amount-col">
                            <span class="wallet-txn-amount <?php echo $isCredit ? 'credit' : 'debit'; ?>">
                                <?php echo ($isCredit ? '+' : '−') . formatCurrency($amount); ?>
                            </span>
                            <?php if ($status !== 'completed'): ?>
                            <?php endif; ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>

                <?php if ($totalPages > 1): ?>
                <nav class="wallet-pagination" role="navigation" aria-label="Account activity pagination">
                    <ul class="pagination-list">
                        <?php if ($page > 1): ?>
                        <li>
                            <a href="<?php echo htmlspecialchars(walletPageUrl('transactions', $page - 1, $fromParam, $walletBackMap), ENT_QUOTES, 'UTF-8'); ?>"
                                class="pagination-link pagination-prev">Previous</a>
                        </li>
                        <?php else: ?>
                        <li><span class="pagination-link pagination-prev disabled">Previous</span></li>
                        <?php endif; ?>

                        <?php
                        $maxVisible = 5;
                        $startPage  = max(1, $page - (int)floor($maxVisible / 2));
                        $endPage    = min($totalPages, $startPage + $maxVisible - 1);
                        if ($endPage - $startPage + 1 < $maxVisible) {
                            $startPage = max(1, $endPage - $maxVisible + 1);
                        }
                        ?>

                        <?php if ($startPage > 1): ?>
                        <li>
                            <a href="<?php echo htmlspecialchars(walletPageUrl('transactions', 1, $fromParam, $walletBackMap), ENT_QUOTES, 'UTF-8'); ?>"
                                class="pagination-link">1</a>
                        </li>
                        <?php if ($startPage > 2): ?>
                        <li class="pagination-ellipsis"><span>...</span></li>
                        <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <li>
                            <?php if ($i === $page): ?>
                            <span class="pagination-link active" aria-current="page"><?php echo $i; ?></span>
                            <?php else: ?>
                            <a href="<?php echo htmlspecialchars(walletPageUrl('transactions', $i, $fromParam, $walletBackMap), ENT_QUOTES, 'UTF-8'); ?>"
                                class="pagination-link"><?php echo $i; ?></a>
                            <?php endif; ?>
                        </li>
                        <?php endfor; ?>

                        <?php if ($endPage < $totalPages): ?>
                        <?php if ($endPage < $totalPages - 1): ?>
                        <li class="pagination-ellipsis"><span>...</span></li>
                        <?php endif; ?>
                        <li>
                            <a href="<?php echo htmlspecialchars(walletPageUrl('transactions', $totalPages, $fromParam, $walletBackMap), ENT_QUOTES, 'UTF-8'); ?>"
                                class="pagination-link"><?php echo $totalPages; ?></a>
                        </li>
                        <?php endif; ?>

                        <?php if ($page < $totalPages): ?>
                        <li>
                            <a href="<?php echo htmlspecialchars(walletPageUrl('transactions', $page + 1, $fromParam, $walletBackMap), ENT_QUOTES, 'UTF-8'); ?>"
                                class="pagination-link pagination-next">Next</a>
                        </li>
                        <?php else: ?>
                        <li><span class="pagination-link pagination-next disabled">Next</span></li>
                        <?php endif; ?>
                    </ul>
                </nav>
                <?php endif; ?>
                <?php endif; ?>
            </div>

        </section>

        <?php endif; ?>

    </div>
</div>

<!-- ============================================ -->
<!-- RECHARGE MODAL - Step 1: amount entry       -->
<!-- ============================================ -->
<div id="rechargeAmountModal" class="modal">
    <div class="modal-overlay"></div>
    <div class="modal-content wallet-modal-content">
        <div class="modal-header">
            <p class="heading-5 modal-title">Recharge Wallet</p>
            <button type="button" class="modal-close" id="closeRechargeAmountModal">&times;</button>
        </div>

        <div class="modal-body">
            <p class="wallet-modal-text">
                Enter the amount you want to add to your wallet. Minimum
                ₱50.00, maximum ₱50,000.00.
            </p>

            <div class="wallet-amount-input-wrap">
                <span class="wallet-amount-prefix">₱</span>
                <input type="number" id="rechargeAmountInput" class="wallet-amount-input" placeholder="0.00" min="50"
                    max="50000" step="0.01" inputmode="decimal" autocomplete="off">
            </div>
            <p class="wallet-amount-error" id="rechargeAmountError" role="alert" aria-live="polite"></p>

            <div class="wallet-quick-amounts">
                <button type="button" class="quick-amount-btn" data-amount="100">₱100</button>
                <button type="button" class="quick-amount-btn" data-amount="200">₱200</button>
                <button type="button" class="quick-amount-btn" data-amount="500">₱500</button>
                <button type="button" class="quick-amount-btn" data-amount="1000">₱1,000</button>
            </div>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="cancelRechargeAmountModal">Cancel</button>
            <button type="button" class="btn btn-primary" id="proceedToQrBtn">Proceed to Payment</button>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- RECHARGE MODAL - Step 2: QR payment         -->
<!-- ============================================ -->
<div id="rechargeQrModal" class="modal">
    <div class="modal-overlay"></div>
    <div class="modal-content wallet-modal-content qr-modal-content">
        <div class="modal-header">
            <p class="heading-5 modal-title">Scan to Pay</p>
            <button type="button" class="modal-close" id="closeRechargeQrModal">&times;</button>
        </div>

        <div class="modal-body">
            <div class="qr-modal-body">
                <div class="qr-amount-block">
                    <span class="qr-amount-label">Recharge Amount</span>
                    <p class="qr-amount" id="qrRechargeAmount">₱0.00</p>
                </div>

                <div class="qr-image-wrapper">
                    <img src="<?php echo $assetBase; ?>assets/images/payment/QR.jpg" alt="Scan to pay QR code"
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                </div>

                <p class="qr-note">
                    <strong>Simulation Notice:</strong> This QR code is for demonstration purposes only.
                    No real payment will be processed. Click <em>I've Paid</em> to simulate a successful
                    recharge.
                </p>
            </div>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="cancelRechargeQrModal">Cancel</button>
            <button type="button" class="btn btn-primary" id="confirmRechargeQrBtn">I've Paid</button>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- SUCCESS MODAL                                -->
<!-- ============================================ -->
<div id="rechargeSuccessModal" class="modal">
    <div class="modal-overlay"></div>
    <div class="modal-content wallet-modal-content">
        <div class="modal-header">
            <p class="heading-5 modal-title">Recharge Successful</p>
            <button type="button" class="modal-close" id="closeRechargeSuccessModal">&times;</button>
        </div>

        <div class="modal-body">
            <div class="wallet-success-body">
                <div class="wallet-success-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/verified-fill.svg" alt="Success"
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                </div>
                <p class="wallet-success-title">Top up complete</p>
                <p class="wallet-success-text">
                    <span id="rechargeSuccessAmount">₱0.00</span> has been added to your wallet.
                </p>
                <div class="wallet-success-balance">
                    <span class="label">New Balance</span>
                    <span class="value" id="rechargeSuccessBalance">₱0.00</span>
                </div>
            </div>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-primary" id="rechargeDoneBtn">Done</button>
        </div>
    </div>
</div>

<script>
window.FITPAL_WALLET = {
    csrfToken: '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>',
    balance: <?php echo json_encode((float)$balance); ?>,
    minRecharge: 50,
    maxRecharge: 50000
};
</script>
<script src="../assets/ui/js/wallet.js" defer></script>
<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>