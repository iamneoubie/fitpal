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
 * Two read surfaces, two filters
 * ------------------------------
 * The wallet page has two tabs, and each tab has a different read
 * rule.
 *
 * CREDIT TAB — "money that moved through the wallet"
 *   Uses getWalletTransactions() / countWalletTransactions().
 *
 *   Filters:
 *     - status = 'completed'
 *     - (order_id IS NULL OR orders.payment_method <> 'COD')
 *
 *   Rationale: the Credit tab is a ledger of balance movement.
 *   Only completed rows moved the balance. COD orders never touch
 *   financial_account.balance, so their payment rows are excluded
 *   even after they complete. Pending and failed rows never moved
 *   the balance and are excluded. What remains is deposits,
 *   completed wallet payments, completed refunds, and completed
 *   withdrawals.
 *
 * TRANSACTIONS TAB — "everything on this wallet account"
 *   Uses getWalletAccountActivity() / countWalletAccountActivity().
 *
 *   Filters:
 *     - none on status
 *     - none on payment method
 *
 *   Rationale: the Transactions tab is a full account activity log.
 *   Every order creates a `transaction` row against the customer's
 *   wallet account — including COD and Online orders, which write
 *   `payment` rows with status 'pending' as bookkeeping. The
 *   customer should be able to see the whole picture: what moved
 *   (completed wallet payments), what is queued (pending COD and
 *   Online payments), and what failed. The Transactions tab shows
 *   them all, with a status pill on each row that is not completed.
 *
 * LEFT JOIN, not INNER
 * --------------------
 * Both read functions LEFT JOIN `orders` so that a row with
 * order_id = NULL — deposits, withdrawals, and refunds on orders
 * that were later deleted — is not silently dropped by an inner
 * join. The `t.order_id IS NULL OR ...` predicate in the Credit
 * filter is what lets those NULL-order rows through while still
 * excluding COD order payments.
 *
 * @package FitPal
 * @version 3.0 — Adds a second read surface for the Transactions
 *                tab. The previous revision filtered the single
 *                read function to completed, non-COD rows. That
 *                left the Transactions tab with the same content
 *                as the Credit tab. This revision splits the two:
 *
 *                  - getWalletTransactions() and
 *                    countWalletTransactions() keep the
 *                    completed-and-non-COD filter. Credit tab.
 *
 *                  - getWalletAccountActivity() and
 *                    countWalletAccountActivity() are new and
 *                    apply no filter at all. Every row on the
 *                    customer's wallet account is returned, in
 *                    newest-first order, paginated, with the
 *                    order's payment method exposed on each row
 *                    so the page can label it. Transactions tab.
 *
 *                No other function changed. No schema, index, or
 *                trigger changed.
 *
 *                (2.0: single read surface, completed-and-non-COD
 *                filter. 1.3: COD payment rows excluded via LEFT
 *                JOIN. 1.2: default transaction page size reduced
 *                to 5. 1.1: initial wallet query layer.)
 */

declare(strict_types=1);

/* =============================================================
 * ACCOUNT
 * ============================================================= */

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

/* =============================================================
 * CREDIT TAB READS
 *
 * Ledger of money that moved through the wallet. Completed rows
 * only. COD order payments excluded.
 * ============================================================= */

/**
 * Fetch a paginated slice of the customer's wallet ledger, newest
 * first.
 *
 * Only rows that moved the wallet balance are returned:
 *   - completed deposits (recharge)
 *   - completed wallet payments
 *   - completed refunds
 *   - completed withdrawals
 *
 * Excluded:
 *   - any row whose status is 'pending' or 'failed'
 *   - any COD payment row
 *
 * The LEFT JOIN is deliberate. Rows with order_id = NULL — deposits,
 * withdrawals, and refunds on legacy orders — have no matching
 * orders row and would be silently dropped by an INNER JOIN. The
 * NULL branch in the predicate keeps them.
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
           AND t.status = 'completed'
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
 * Count the customer's total wallet-visible transactions, for the
 * Credit tab's pagination.
 *
 * Uses the same filter as getWalletTransactions() so the pagination
 * total matches the rows the customer actually sees.
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
           AND t.status = 'completed'
           AND (t.order_id IS NULL OR o.payment_method <> 'COD')"
    );
    $stmt->execute([':customer_id' => $customerId]);
    return (int)$stmt->fetchColumn();
}

/* =============================================================
 * TRANSACTIONS TAB READS
 *
 * Full activity log for the wallet account. Every row, every
 * status, every payment method. The order's payment method is
 * exposed on the result so the page can label each row.
 * ============================================================= */

/**
 * Fetch a paginated slice of the customer's full wallet-account
 * activity, newest first.
 *
 * Returns every row on the customer's financial account. No status
 * filter. No payment-method filter. COD and Online order payments
 * are included alongside Wallet order payments, deposits, refunds,
 * and withdrawals.
 *
 * The `order_payment_method` column is NULL for rows with no
 * associated order (deposits, withdrawals, refunds on legacy
 * orders). The page uses it to render a per-row label like
 * "Wallet order", "COD order", or "Online order".
 *
 * The LEFT JOIN is deliberate. Rows with order_id = NULL have no
 * matching orders row and would be silently dropped by an INNER
 * JOIN.
 *
 * @param PDO $db
 * @param int $customerId
 * @param int $limit
 * @param int $offset
 * @return array<int, array<string, mixed>>
 */
function getWalletAccountActivity(PDO $db, int $customerId, int $limit = 5, int $offset = 0): array
{
    $stmt = $db->prepare(
        "SELECT
            t.transaction_id,
            t.order_id,
            t.amount,
            t.transaction_type,
            t.status,
            t.description,
            t.transaction_date,
            o.payment_method AS order_payment_method
         FROM transaction t
         JOIN customer_profile cp ON t.financial_account_id = cp.financial_account_id
         LEFT JOIN orders o ON t.order_id = o.order_id
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
 * Count the customer's total wallet-account activity rows, for the
 * Transactions tab's pagination.
 *
 * Uses the same (empty) filter as getWalletAccountActivity() so the
 * pagination total matches the rows the customer actually sees.
 *
 * @param PDO $db
 * @param int $customerId
 * @return int
 */
function countWalletAccountActivity(PDO $db, int $customerId): int
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

/* =============================================================
 * WRITES
 * ============================================================= */

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