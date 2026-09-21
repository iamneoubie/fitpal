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
 * @package FitPal
 * @version 1.2 — Default transaction page size reduced to 5.
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
         WHERE cp.customer_id = :customer_id
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
 * Count the customer's total transactions (for pagination).
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
         WHERE cp.customer_id = :customer_id"
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