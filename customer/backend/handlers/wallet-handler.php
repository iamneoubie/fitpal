<?php
/**
 * FitPal Customer Wallet Handler
 *
 * Runs on the customer session (PHPSESSID_CUSTOMER), separate from
 * every other role's session.
 *
 * Actions:
 *   recharge        — immediate completed deposit (manual top-up)
 *   initiate_qr     — create a pending deposit, return its ID
 *   confirm_qr      — flip a pending deposit to completed
 *   cancel_qr       — delete a pending deposit (user backed out)
 *   get_balance     — return the current balance as JSON (used by
 *                     the wallet page's background refresh)
 *
 * Every successful recharge inserts a row into `transaction`. The
 * database trigger `after_transaction_insert` moves the balance, so
 * this handler never writes to financial_account.balance directly.
 *
 * ---------------------------------------------------------------------
 * PER-ROLE SESSION MODEL (Option B)
 * ---------------------------------------------------------------------
 * The handler bootstraps the customer session before doing
 * anything else. Because the request that reaches this handler
 * carries only the customer cookie, the customer session is the
 * only session this code can see. The auth guard reads
 * $_SESSION['customer_id'] and the CSRF check reads
 * $_SESSION['customer_csrf_token'], both inside the customer
 * session and guaranteed to be the customer's own.
 *
 * @package FitPal
 * @version 2.0 — Per-role session migration (Option B). The
 *                handler bootstraps the customer session as its
 *                first executable statement. The obsolete
 *                cross-role commentary in the CSRF block is
 *                replaced with a note about the structural
 *                isolation that per-role sessions provide. No
 *                logic changed; no SQL moved.
 *
 *                (1.3: validated against customer_csrf_token with
 *                hash_equals. 1.2: added get_balance.)
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// SESSION BOOTSTRAP
//
// Must run before any other include that might touch the session.
// This handler belongs to the customer context.
// ---------------------------------------------------------------------

require_once __DIR__ . '/../../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('customer');

// ---------------------------------------------------------------------
// RESPONSE HEADERS
// ---------------------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');

// ---------------------------------------------------------------------
// AUTHENTICATION
// ---------------------------------------------------------------------

if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

// ---------------------------------------------------------------------
// DEPENDENCIES
// ---------------------------------------------------------------------

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/wallet-queries.php';

// ---------------------------------------------------------------------
// CSRF
//
// Validated against the customer context's own key,
// 'customer_csrf_token', inside the customer session. Under
// Option B this key lives in a session that only requests bearing
// the customer cookie can reach, so the token is guaranteed to be
// the customer's own. The key name keeps the {role}_ prefix as a
// naming convention, not as a collision guard.
// ---------------------------------------------------------------------

$givenToken = (string)($_POST['csrf_token'] ?? '');
$sessToken  = (string)($_SESSION['customer_csrf_token'] ?? '');

if ($sessToken === '' || $givenToken === '' || !hash_equals($sessToken, $givenToken)) {
    echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
    exit;
}

$customerId = (int)$_SESSION['customer_id'];
$action     = (string)($_POST['action'] ?? '');

// ---------------------------------------------------------------------
// AMOUNT VALIDATION CONSTANTS
// ---------------------------------------------------------------------

const WALLET_MIN_RECHARGE = 50.0;
const WALLET_MAX_RECHARGE = 50000.0;

/**
 * Parse and validate a recharge amount.
 *
 * @param mixed $raw
 * @return float|null  Null when the value is not numeric or is
 *                     outside the allowed range.
 */
function parseAmount(mixed $raw): ?float
{
    if (!is_numeric($raw)) {
        return null;
    }
    $value = (float)$raw;
    if ($value < WALLET_MIN_RECHARGE || $value > WALLET_MAX_RECHARGE) {
        return null;
    }
    return round($value, 2);
}

// ---------------------------------------------------------------------
// DISPATCH
// ---------------------------------------------------------------------

try {
    $account = getWalletAccount($database_connection, $customerId);
    if (!$account) {
        echo json_encode(['status' => 'error', 'message' => 'Wallet account not found']);
        exit;
    }

    $accountId = (int)$account['financial_account_id'];

    switch ($action) {

        // -------------------------------------------------------------
        // Read-only balance check. Cheap single-row lookup. Used by
        // the wallet page's background refresh on bfcache restore
        // and long tab-away.
        // -------------------------------------------------------------
        case 'get_balance': {
            echo json_encode([
                'status'  => 'success',
                'balance' => (float)$account['balance'],
            ]);
            exit;
        }

        // -------------------------------------------------------------
        // Immediate recharge — manual top-up, no QR scan.
        // -------------------------------------------------------------
        case 'recharge': {
            $amount = parseAmount($_POST['amount'] ?? null);
            if ($amount === null) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'Amount must be between '
                        . number_format(WALLET_MIN_RECHARGE, 2) . ' and '
                        . number_format(WALLET_MAX_RECHARGE, 2),
                ]);
                exit;
            }

            $database_connection->beginTransaction();
            try {
                $txnId = createDepositTransaction(
                    $database_connection,
                    $accountId,
                    $amount,
                    'Wallet recharge'
                );
                $database_connection->commit();
            } catch (Throwable $e) {
                if ($database_connection->inTransaction()) {
                    $database_connection->rollBack();
                }
                throw $e;
            }

            $updated = getWalletAccount($database_connection, $customerId);

            echo json_encode([
                'status'         => 'success',
                'message'        => 'Wallet recharged successfully',
                'transaction_id' => $txnId,
                'amount'         => $amount,
                'new_balance'    => (float)($updated['balance'] ?? 0),
            ]);
            exit;
        }

        // -------------------------------------------------------------
        // QR flow — step 1: reserve the recharge as a pending row.
        // -------------------------------------------------------------
        case 'initiate_qr': {
            $amount = parseAmount($_POST['amount'] ?? null);
            if ($amount === null) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'Amount must be between '
                        . number_format(WALLET_MIN_RECHARGE, 2) . ' and '
                        . number_format(WALLET_MAX_RECHARGE, 2),
                ]);
                exit;
            }

            $database_connection->beginTransaction();
            try {
                $txnId = createPendingDepositTransaction(
                    $database_connection,
                    $accountId,
                    $amount,
                    'Wallet Recharge'
                );
                $database_connection->commit();
            } catch (Throwable $e) {
                if ($database_connection->inTransaction()) {
                    $database_connection->rollBack();
                }
                throw $e;
            }

            echo json_encode([
                'status'         => 'success',
                'message'        => 'QR payment initiated',
                'transaction_id' => $txnId,
                'amount'         => $amount,
            ]);
            exit;
        }

        // -------------------------------------------------------------
        // QR flow — step 2: mark the pending row as completed.
        // -------------------------------------------------------------
        case 'confirm_qr': {
            $txnId = (int)($_POST['transaction_id'] ?? 0);
            if ($txnId <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Transaction ID required']);
                exit;
            }

            $txn = getTransactionById($database_connection, $txnId, $customerId);
            if (!$txn) {
                echo json_encode(['status' => 'error', 'message' => 'Transaction not found']);
                exit;
            }
            if ($txn['status'] !== 'pending') {
                echo json_encode(['status' => 'error', 'message' => 'Transaction is not pending']);
                exit;
            }

            $database_connection->beginTransaction();
            try {
                $ok = completeTransaction($database_connection, $txnId, $customerId);
                if (!$ok) {
                    $database_connection->rollBack();
                    echo json_encode(['status' => 'error', 'message' => 'Could not complete transaction']);
                    exit;
                }
                $database_connection->commit();
            } catch (Throwable $e) {
                if ($database_connection->inTransaction()) {
                    $database_connection->rollBack();
                }
                throw $e;
            }

            $updated = getWalletAccount($database_connection, $customerId);

            echo json_encode([
                'status'         => 'success',
                'message'        => 'Wallet recharged successfully',
                'transaction_id' => $txnId,
                'new_balance'    => (float)($updated['balance'] ?? 0),
            ]);
            exit;
        }

        // -------------------------------------------------------------
        // QR flow — cancel: user backed out before confirming.
        // -------------------------------------------------------------
        case 'cancel_qr': {
            $txnId = (int)($_POST['transaction_id'] ?? 0);
            if ($txnId <= 0) {
                echo json_encode(['status' => 'success', 'message' => 'Nothing to cancel']);
                exit;
            }

            try {
                deletePendingDeposit($database_connection, $txnId, $customerId);
            } catch (Throwable $e) {
                error_log('cancel_qr error: ' . $e->getMessage());
            }

            echo json_encode(['status' => 'success', 'message' => 'Recharge cancelled']);
            exit;
        }

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
            exit;
    }

} catch (PDOException $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Wallet handler DB error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A system error occurred. Please try again.']);
} catch (Throwable $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Wallet handler error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A system error occurred. Please try again.']);
}