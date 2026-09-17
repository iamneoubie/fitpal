<?php
/**
 * FitPal Customer Wallet Page
 * Version 1.1 — Uses add-line.svg / subtract-line.svg for credit/debit icons.
 *
 * @package FitPal
 * @version 1.1
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    header('Location: sign-in.php');
    exit;
}

require_once __DIR__ . '/../backend/database/customer-connect.php';
require_once __DIR__ . '/../backend/database/wallet-queries.php';

$customerId = (int)$_SESSION['customer_id'];

$account = getWalletAccount($database_connection, $customerId);
if (!$account) {
    // Missing wallet row — should never happen for an active customer,
    // but fail gracefully rather than crash.
    $_SESSION['profile_error'] = 'Wallet account not found. Please contact support.';
    header('Location: profile.php');
    exit;
}

$balance      = (float)$account['balance'];
$perPage      = 20;
$page         = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset       = ($page - 1) * $perPage;
$totalTxns    = countWalletTransactions($database_connection, $customerId);
$totalPages   = max(1, (int)ceil($totalTxns / $perPage));
$transactions = getWalletTransactions($database_connection, $customerId, $perPage, $offset);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

require_once __DIR__ . '/../includes/header.php';

/**
 * Format an amount as Philippine pesos.
 */
function walletFmt(float|string|null $amount): string {
    return '₱' . number_format((float)($amount ?? 0), 2);
}

/**
 * Human-readable label for a transaction type.
 */
function walletTypeLabel(string $type): string {
    return match ($type) {
        'deposit'    => 'Top Up',
        'payment'    => 'Payment',
        'refund'     => 'Refund',
        'withdrawal' => 'Withdrawal',
        default      => ucfirst($type),
    };
}

/**
 * Whether a transaction increases (credit) or decreases (debit) the balance.
 * Deposits and refunds add to the wallet; payments and withdrawals subtract.
 */
function walletIsCredit(string $type): bool {
    return in_array($type, ['deposit', 'refund'], true);
}

/**
 * Icon filename for a transaction direction.
 * Credit = money in → add-line.svg
 * Debit  = money out → subtract-line.svg
 */
function walletDirectionIcon(string $type): string {
    return walletIsCredit($type) ? 'add-line.svg' : 'subtract-line.svg';
}

/**
 * Alt text for the direction icon.
 */
function walletDirectionAlt(string $type): string {
    return walletIsCredit($type) ? 'Credit' : 'Debit';
}

/**
 * Format a transaction timestamp.
 */
function walletDate(string $date): string {
    $ts = strtotime($date);
    return $ts !== false ? date('M d, Y • g:i A', $ts) : $date;
}
?>

<link rel="stylesheet" href="../assets/css/wallet.css">

<div class="content wallet-page">
    <div class="container">

        <!-- ============================================ -->
        <!-- PAGE HEADER -->
        <!-- ============================================ -->
        <div class="page-title-header">
            <div class="page-title-header-top">
                <button type="button" id="walletBackBtn" class="back-btn" data-fallback-href="dashboard.php">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="19" y1="12" x2="5" y2="12"></line>
                        <polyline points="12,19 5,12 12,5"></polyline>
                    </svg>
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
        <!-- BALANCE CARD -->
        <!-- ============================================ -->
        <div class="wallet-balance-card">
            <div class="wallet-balance-left">
                <span class="wallet-balance-label">Available Balance</span>
                <span class="wallet-balance-amount" id="walletBalanceAmount">
                    <?php echo walletFmt($balance); ?>
                </span>
                <span class="wallet-balance-note">Use your wallet to pay for orders instantly</span>
            </div>
            <div class="wallet-balance-right">
                <button type="button" id="rechargeBtn" class="btn btn-primary btn-lg">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                    Recharge Wallet
                </button>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- TRANSACTIONS -->
        <!-- ============================================ -->
        <div class="wallet-transactions-card">
            <div class="card-header">
                <h3>Transaction History</h3>
                <?php if ($totalTxns > 0): ?>
                <span class="txn-count"><?php echo number_format($totalTxns); ?> total</span>
                <?php endif; ?>
            </div>

            <?php if (empty($transactions)): ?>
            <div class="wallet-empty-state">
                <div class="wallet-empty-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/cart-shopping.svg" alt="No transactions"
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                </div>
                <p class="wallet-empty-title">No transactions yet</p>
                <p class="wallet-empty-text">Recharge your wallet to start ordering with instant payment.</p>
            </div>
            <?php else: ?>
            <ul class="wallet-txn-list">
                <?php foreach ($transactions as $txn):
                    $type      = (string)$txn['transaction_type'];
                    $status    = (string)$txn['status'];
                    $amount    = (float)$txn['amount'];
                    $isCredit  = walletIsCredit($type);
                    $isPending = $status === 'pending';
                    $isFailed  = $status === 'failed';

                    $iconFile = walletDirectionIcon($type);
                    $iconAlt  = walletDirectionAlt($type);
                ?>
                <li
                    class="wallet-txn-item<?php echo $isPending ? ' is-pending' : ''; ?><?php echo $isFailed ? ' is-failed' : ''; ?>">
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
                        <p class="wallet-txn-date"><?php echo walletDate((string)$txn['transaction_date']); ?></p>
                    </div>

                    <div class="wallet-txn-amount-col">
                        <span class="wallet-txn-amount <?php echo $isCredit ? 'credit' : 'debit'; ?>">
                            <?php echo ($isCredit ? '+' : '−') . walletFmt($amount); ?>
                        </span>
                        <?php if ($status !== 'completed'): ?>
                        <span class="wallet-txn-status <?php echo $isPending ? 'pending' : 'failed'; ?>">
                            <?php echo htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($totalPages > 1): ?>
            <nav class="wallet-pagination" role="navigation" aria-label="Transaction pagination">
                <ul class="pagination-list">
                    <?php if ($page > 1): ?>
                    <li>
                        <a href="wallet.php?page=<?php echo $page - 1; ?>"
                            class="pagination-link pagination-prev">Previous</a>
                    </li>
                    <?php else: ?>
                    <li><span class="pagination-link pagination-prev disabled">Previous</span></li>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li>
                        <?php if ($i === $page): ?>
                        <span class="pagination-link active"><?php echo $i; ?></span>
                        <?php else: ?>
                        <a href="wallet.php?page=<?php echo $i; ?>" class="pagination-link"><?php echo $i; ?></a>
                        <?php endif; ?>
                    </li>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                    <li>
                        <a href="wallet.php?page=<?php echo $page + 1; ?>"
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
    </div>
</div>

<!-- ============================================ -->
<!-- RECHARGE MODAL - Step 1: amount entry       -->
<!-- ============================================ -->
<div id="rechargeAmountModal" class="modal" style="display:none;">
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
<div id="rechargeQrModal" class="modal" style="display:none;">
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
<div id="rechargeSuccessModal" class="modal" style="display:none;">
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