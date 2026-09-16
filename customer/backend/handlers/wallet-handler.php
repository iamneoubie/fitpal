<?php
/**
 * FitPal Customer Wallet Handler
 *
 * Actions:
 *   recharge        — immediate completed deposit (manual top-up)
 *   initiate_qr     — create a pending deposit, return its ID
 *   confirm_qr      — flip a pending deposit to completed
 *   cancel_qr       — delete a pending deposit (user backed out)
 *
 * Every successful recharge inserts a row into `transaction`. The
 * database trigger `after_transaction_insert` moves the balance, so
 * this handler never writes to financial_account.balance directly.
 *
 * @package FitPal
 * @version 1.1 — Adds cancel_qr so pending recharges don't linger
 *                after the user dismisses the QR modal.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/wallet-queries.php';

if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
    exit;
}

$customerId = (int)$_SESSION['customer_id'];
$action     = (string)($_POST['action'] ?? '');

// ---- Amount validation --------------------------------------------------
const WALLET_MIN_RECHARGE = 50.0;
const WALLET_MAX_RECHARGE = 50000.0;

function parseAmount(mixed $raw): ?float
{
    if (!is_numeric($raw)) {
        return null;
    }
    $value = (float)$raw;
    if ($value < WALLET_MIN_RECHARGE || $value > WALLET_MAX_RECHARGE) {
        return null;
    }
    // Round to two decimals to match DECIMAL(10,2).
    return round($value, 2);
}

try {
    $account = getWalletAccount($database_connection, $customerId);
    if (!$account) {
        echo json_encode(['status' => 'error', 'message' => 'Wallet account not found']);
        exit;
    }

    $accountId = (int)$account['financial_account_id'];

    switch ($action) {

        // -------------------------------------------------------------
        // Immediate recharge — used for the manual path where the
        // customer just wants to top up (no QR scan needed).
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

            // Re-read the balance after the trigger has run.
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
        // The trigger does NOT fire for pending, so the balance stays
        // unchanged until confirm_qr runs.
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
                    'Wallet recharge (QR pending)'
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
        // The trigger now fires and the balance moves.
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
        // Deletes the pending deposit so it doesn't linger in history
        // and can't be confused with a later, real recharge.
        //
        // Best-effort: any failure is logged but still reported as
        // success, because the UI already moved on and there is no
        // useful action the customer could take.
        // -------------------------------------------------------------
        case 'cancel_qr': {
            $txnId = (int)($_POST['transaction_id'] ?? 0);
            if ($txnId <= 0) {
                // Nothing to cancel — treat as success so the caller
                // doesn't surface an error for a no-op.
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