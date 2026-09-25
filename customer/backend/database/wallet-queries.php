<?php
/**
 * FitPal Customer Wallet Database Queries
 *
 * Pure data-access layer for the customer's financial_account and
 * transaction history. No $_POST, no header(), no echo.
 *
 * Balance updates are handled exclusively by the database trigger
 * `after_transaction_insert` in database.sql — inserting a completed
 * 'deposit' or 'refund' increments the balance; a completed 'payment'
 * or 'withdrawal' decrements it. This file therefore never writes to
 * financial_account.balance directly.
 *
 * COD bookkeeping vs wallet ledger
 * --------------------------------
 * Every order creates a `transaction` row, including COD orders —
 * see createOrderFromQueue() in order-queries.php. That row is real
 * bookkeeping: it records that a COD order was placed and that its
 * payment is pending collection. On a COD cancellation,
 * refundOrderToWallet() flips that pending row to 'failed' as part
 * of the cleanup path, so the row cannot simply be deleted.
 *
 * But the customer-facing wallet is not a general order ledger. It
 * is a ledger of money that actually moves through the wallet. A
 * COD payment never touches financial_account.balance, so a pending
 * or failed COD row has no business appearing in the customer's
 * wallet feed. The two read functions in this file therefore exclude
 * COD payment rows via a LEFT JOIN through orders.
 *
 * The filter is:
 *
 *     AND (t.order_id IS NULL OR o.payment_method <> 'COD')
 *
 * Read: keep the row if it has no associated order (top-ups,
 * refunds, withdrawals all have order_id = NULL) or if its order
 * was not paid by COD. Wallet and Online payment rows are kept.
 * COD payment rows — pending or failed — are excluded.
 *
 * LEFT JOIN, not INNER JOIN: a deposit has no order and would be
 * silently dropped by an inner join. The NULL branch in the
 * predicate is what keeps those rows.
 *
 * @package FitPal
 * @version 1.3 — getWalletTransactions() and countWalletTransactions()
 *                now exclude COD payment rows. The wallet shows only
 *                money that actually moves through it: deposits,
 *                wallet payments, online payments, refunds, and
 *                withdrawals. COD bookkeeping rows stay in the table
 *                for accounting integrity but no longer appear in the
 *                customer's wallet feed.
 *
 *                (1.2: default transaction page size reduced to 5.
 *                1.1: initial wallet query layer.)
 */

declare(strict_types=1);

/**
 * Fetch the customer's financial account (balance and account ID).
 *
 * @param PDO $db
 * @param int $customerId
 * @return array<string, mixed>|false
 */
function getWalletAccount(PDO $db, int $customerId): array|false
{
    $stmt = $db->prepare(
        "SELECT
            fa.financial_account_id,
            fa.balance,
            fa.account_type,
            fa.date_created
         FROM customer_profile cp
         JOIN financial_account fa ON cp.financial_account_id = fa.financial_account_id
         WHERE cp.customer_id = :customer_id
         LIMIT 1"
    );
    $stmt->execute([':customer_id' => $customerId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Fetch a paginated slice of the customer's transaction history,
 * newest first.
 *
 * Excludes COD payment rows. A COD order creates a `payment` row
 * with status 'pending' (or 'failed' after cancellation cleanup) for
 * bookkeeping, but the wallet is a ledger of money that actually
 * moves through it, and COD never does. Deposits, wallet payments,
 * online payments, refunds, and withdrawals are all still shown.
 *
 * The LEFT JOIN is deliberate. Rows with order_id = NULL — deposits,
 * withdrawals, and refunds on legacy orders — have no matching orders
 * row and would be silently dropped by an INNER JOIN. The NULL branch
 * in the predicate keeps them.
 *
 * @param PDO $db
 * @param int $customerId
 * @param int $limit
 * @param int $offset
 * @return array<int, array<string, mixed>>
 */
function getWalletTransactions(PDO $db, int $customerId, int $limit = 5, int $offset = 0): array
{
    $stmt = $db->prepare(
        "SELECT
            t.transaction_id,
            t.order_id,
            t.amount,
            t.transaction_type,
            t.status,
            t.description,
            t.transaction_date
         FROM transaction t
         JOIN customer_profile cp ON t.financial_account_id = cp.financial_account_id
         LEFT JOIN orders o ON t.order_id = o.order_id
         WHERE cp.customer_id = :customer_id
           AND (t.order_id IS NULL OR o.payment_method <> 'COD')
         ORDER BY t.transaction_date DESC, t.transaction_id DESC
         LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue(':customer_id', $customerId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Count the customer's total wallet-visible transactions, for
 * pagination.
 *
 * Uses the same filter as getWalletTransactions() so the pagination
 * total matches the rows the customer actually sees. A count that
 * included COD rows would produce empty pages at the end of the
 * wallet feed once the COD rows were filtered out of the display.
 *
 * @param PDO $db
 * @param int $customerId
 * @return int
 */
function countWalletTransactions(PDO $db, int $customerId): int
{
    $stmt = $db->prepare(
        "SELECT COUNT(*)
         FROM transaction t
         JOIN customer_profile cp ON t.financial_account_id = cp.financial_account_id
         LEFT JOIN orders o ON t.order_id = o.order_id
         WHERE cp.customer_id = :customer_id
           AND (t.order_id IS NULL OR o.payment_method <> 'COD')"
    );
    $stmt->execute([':customer_id' => $customerId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Insert a completed deposit transaction. The database trigger
 * `after_transaction_insert` then increments the balance automatically.
 *
 * @param PDO $db
 * @param int $financialAccountId
 * @param float $amount
 * @param string $description
 * @return int New transaction ID
 */
function createDepositTransaction(
    PDO $db,
    int $financialAccountId,
    float $amount,
    string $description
): int {
    $stmt = $db->prepare(
        "INSERT INTO transaction
            (financial_account_id, order_id, amount, transaction_type, status, description)
         VALUES
            (:account_id, NULL, :amount, 'deposit', 'completed', :description)"
    );
    $stmt->execute([
        ':account_id'  => $financialAccountId,
        ':amount'      => $amount,
        ':description' => $description,
    ]);
    return (int)$db->lastInsertId();
}

/**
 * Insert a pending online-payment deposit transaction, used while the
 * customer is still simulating a QR scan. The trigger only fires on
 * status = 'completed', so this row does NOT move the balance yet.
 *
 * @param PDO $db
 * @param int $financialAccountId
 * @param float $amount
 * @param string $description
 * @return int New transaction ID
 */
function createPendingDepositTransaction(
    PDO $db,
    int $financialAccountId,
    float $amount,
    string $description
): int {
    $stmt = $db->prepare(
        "INSERT INTO transaction
            (financial_account_id, order_id, amount, transaction_type, status, description)
         VALUES
            (:account_id, NULL, :amount, 'deposit', 'pending', :description)"
    );
    $stmt->execute([
        ':account_id'  => $financialAccountId,
        ':amount'      => $amount,
        ':description' => $description,
    ]);
    return (int)$db->lastInsertId();
}

/**
 * Mark a transaction as completed, which fires the trigger and moves
 * the balance. Scoped to the owning customer.
 *
 * @param PDO $db
 * @param int $transactionId
 * @param int $customerId
 * @return bool  True if a row was updated.
 */
function completeTransaction(PDO $db, int $transactionId, int $customerId): bool
{
    $stmt = $db->prepare(
        "UPDATE transaction t
         JOIN customer_profile cp ON t.financial_account_id = cp.financial_account_id
            SET t.status = 'completed'
          WHERE t.transaction_id = :transaction_id
            AND cp.customer_id   = :customer_id
            AND t.status         = 'pending'"
    );
    $stmt->execute([
        ':transaction_id' => $transactionId,
        ':customer_id'    => $customerId,
    ]);
    return $stmt->rowCount() > 0;
}

/**
 * Fetch a single transaction by ID, scoped to the customer.
 *
 * @param PDO $db
 * @param int $transactionId
 * @param int $customerId
 * @return array<string, mixed>|false
 */
function getTransactionById(PDO $db, int $transactionId, int $customerId): array|false
{
    $stmt = $db->prepare(
        "SELECT t.*
         FROM transaction t
         JOIN customer_profile cp ON t.financial_account_id = cp.financial_account_id
         WHERE t.transaction_id = :transaction_id
           AND cp.customer_id   = :customer_id
         LIMIT 1"
    );
    $stmt->execute([
        ':transaction_id' => $transactionId,
        ':customer_id'    => $customerId,
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Delete a pending deposit transaction, scoped to the owning customer.
 *
 * Used when the customer cancels the QR payment before confirming.
 * Only rows with status = 'pending' and transaction_type = 'deposit'
 * are eligible — a completed deposit can never be removed this way,
 * so there is no way for this to reverse an actual balance change.
 *
 * @param PDO $db
 * @param int $transactionId
 * @param int $customerId
 * @return bool  True if a row was deleted.
 */
function deletePendingDeposit(PDO $db, int $transactionId, int $customerId): bool
{
    $stmt = $db->prepare(
        "DELETE t
         FROM transaction t
         JOIN customer_profile cp ON t.financial_account_id = cp.financial_account_id
         WHERE t.transaction_id   = :transaction_id
           AND cp.customer_id     = :customer_id
           AND t.status           = 'pending'
           AND t.transaction_type = 'deposit'"
    );
    $stmt->execute([
        ':transaction_id' => $transactionId,
        ':customer_id'    => $customerId,
    ]);
    return $stmt->rowCount() > 0;
}