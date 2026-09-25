<?php
/**
 * FitPal Rider Database Queries
 *
 * Pure data-access layer for the delivery_rider,
 * delivery_rider_profile, delivery_rider_address,
 * delivery_rider_emergency_contact, delivery_rider_document,
 * orders, and transaction tables.
 *
 * No $_POST, no header(), no echo, no session writes.
 *
 * Assignment model
 * ----------------
 * The kitchen assigns a rider, which moves an order to
 * 'rider_pending' and sets orders.delivery_rider_id. The rider then
 * accepts (moves to 'delivering') or declines (returns the order to
 * 'preparing', clears the rider). This file is the only place those
 * two transitions are written.
 *
 * Availability model
 * ------------------
 * `delivery_rider_profile.is_available` is the rider's OWN toggle.
 * It is set at sign-in (forced offline), flipped by the rider from
 * the dashboard, and cleared on sign-out. It is NOT touched by the
 * accept path, the deliver path, or the kitchen's assign/reassign
 * paths. A rider who is online stays online across every delivery
 * they accept. The "one active delivery per rider" rule is enforced
 * by the locking busy-check inside
 * restaurant/backend/database/order-queries.php::assignRiderToOrder(),
 * not by flipping this flag.
 *
 * Payout model
 * ------------
 * A completed delivery credits the rider's financial_account via a
 * `deposit` transaction. The database trigger
 * `after_transaction_insert` moves the balance — this file never
 * writes financial_account.balance directly. creditRiderForDelivery()
 * is the single write path and is idempotent per order.
 *
 * Registration model
 * ------------------
 * sign-up-handler.php does no SQL of its own. It delegates every
 * read and write to this file:
 *   - riderEmailExists / riderUsernameExists / riderContactExists
 *     for the pre-transaction uniqueness probes.
 *   - createRiderAccount for the four core inserts (financial
 *     account, delivery rider, profile, address) inside one
 *     transaction, including the password hash.
 *   - insertRiderEmergencyContact and insertRiderDocument for the
 *     two child rows the handler writes after the rider exists.
 *
 * Change log
 * ----------
 * v7.1 — DELTA-FRIENDLY ACTIVE DELIVERIES.
 *   - getRiderActiveDeliveries() accepts an optional $sinceOrderId.
 *     When > 0, the query filters to orders with order_id strictly
 *     greater than it. When 0, it returns the whole active list.
 *     The old call sites keep working because the parameter is
 *     optional.
 *
 *   - No other function changed. The panel uses its own list
 *     functions in assignment-queries.php; this file's active-list
 *     reader is kept because the deliveries page still calls it.
 *
 *   - Payout constant alignment note: the flat ₱50.00 per delivery
 *     is hard-coded in getRiderDashboardStats(), getRiderWeeklyEarnings(),
 *     and getRiderRecentDeliveries() below, and it also lives as
 *     RIDER_DELIVERY_PAYOUT in rider/backend/handlers/rider-handler.php.
 *     If the schedule ever changes, all four places must move
 *     together — or the three readers here should switch to
 *     SUM(transaction.amount) over completed deposits.
 *
 * v7.0 — DELIVERY PAYOUT + AVAILABILITY CLEANUP.
 *   - acceptOrder() no longer touches is_available. Availability is
 *     the rider's own toggle and must survive across deliveries.
 *   - creditRiderForDelivery() added. Inserts a completed deposit
 *     for a finished delivery, idempotent per order.
 *   - setRiderAvailability() remains the only function that writes
 *     is_available.
 *
 * v6.0 — REGISTRATION SQL CONSOLIDATED HERE.
 *   - riderEmailExists / riderUsernameExists / riderContactExists.
 *   - createRiderAccount.
 *
 * v5.0 — RIDER ACCEPT / DECLINE FOR THE RIDER_PENDING HANDOFF.
 */

declare(strict_types=1);

// ============================================
// AUTHENTICATION
// ============================================

function findRiderByIdentifier(PDO $db, string $identifier): array|false
{
    $stmt = $db->prepare(
        "SELECT
            dr.delivery_rider_id,
            dr.first_name,
            dr.middle_name,
            dr.last_name,
            dr.email,
            dr.username,
            dr.password,
            dr.is_active,
            drp.verification_status
         FROM delivery_rider dr
         LEFT JOIN delivery_rider_profile drp
                ON dr.delivery_rider_id = drp.delivery_rider_id
         WHERE dr.email = :email OR dr.username = :username
         LIMIT 1"
    );
    $stmt->execute([
        ':email'    => $identifier,
        ':username' => $identifier,
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getRiderProfile(PDO $db, int $riderId): array|false
{
    $stmt = $db->prepare(
        "SELECT
            dr.delivery_rider_id,
            dr.first_name,
            dr.middle_name,
            dr.last_name,
            dr.email,
            dr.contact_number,
            dr.username,
            dr.is_active,
            dr.date_created,
            drp.profile_picture,
            drp.vehicle_type,
            drp.vehicle_plate,
            drp.verification_status,
            drp.average_rating,
            drp.total_deliveries,
            drp.is_available,
            fa.balance
         FROM delivery_rider dr
         LEFT JOIN delivery_rider_profile drp
                ON dr.delivery_rider_id = drp.delivery_rider_id
         LEFT JOIN financial_account fa
                ON drp.financial_account_id = fa.financial_account_id
         WHERE dr.delivery_rider_id = :rider_id
         LIMIT 1"
    );
    $stmt->execute([':rider_id' => $riderId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function isRiderActive(PDO $db, int $riderId): bool
{
    $stmt = $db->prepare(
        "SELECT is_active FROM delivery_rider WHERE delivery_rider_id = ?"
    );
    $stmt->execute([$riderId]);
    return (bool)$stmt->fetchColumn();
}

// ============================================
// AVAILABILITY
// ============================================

/**
 * Flip a rider's availability.
 *
 * This is the ONLY function in the rider codebase that writes
 * delivery_rider_profile.is_available on a per-toggle basis. It is
 * called from:
 *   - sign-in-handler.php on every successful sign-in (forced
 *     offline, so a rider explicitly opts in after each login);
 *   - rider-handler.php when the rider toggles from the dashboard.
 *
 * A rider cannot be switched to offline while they have an order in
 * 'delivering' — they must finish the run first. Switching to online
 * is always allowed.
 *
 * Returns true when the write was performed, false when the rule
 * refused it.
 */
function setRiderAvailability(PDO $db, int $riderId, int $isAvailable): bool
{
    if ($isAvailable === 0) {
        $busyStmt = $db->prepare(
            "SELECT 1 FROM orders
              WHERE delivery_rider_id = :rider_id
                AND order_status = 'delivering'
              LIMIT 1"
        );
        $busyStmt->execute([':rider_id' => $riderId]);
        if ($busyStmt->fetchColumn() !== false) {
            return false;
        }
    }

    $stmt = $db->prepare(
        "UPDATE delivery_rider_profile
            SET is_available = :is_available
          WHERE delivery_rider_id = :rider_id"
    );
    $stmt->execute([
        ':is_available' => $isAvailable,
        ':rider_id'     => $riderId,
    ]);
    return true;
}

function updateRiderContact(PDO $db, int $riderId, string $contactNumber): bool
{
    $stmt = $db->prepare(
        "UPDATE delivery_rider
            SET contact_number = :contact_number
          WHERE delivery_rider_id = :rider_id"
    );
    $stmt->execute([
        ':contact_number' => $contactNumber !== '' ? $contactNumber : null,
        ':rider_id'       => $riderId,
    ]);
    return true;
}

// ============================================
// ASSIGNED ORDERS (kitchen handoff)
// ============================================

/**
 * Orders the kitchen has assigned to this rider that are waiting on
 * an accept/decline decision.
 *
 * Only orders in 'rider_pending' whose delivery_rider_id matches the
 * caller are returned. Read-only. No side effects.
 *
 * @param PDO $db
 * @param int $riderId
 * @param int $limit
 * @return array<int, array<string, mixed>>
 */
function getAssignedOrders(PDO $db, int $riderId, int $limit = 20): array
{
    $stmt = $db->prepare(
        "SELECT
            o.order_id,
            o.order_status,
            o.order_date,
            o.destination_address,
            CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
            c.contact_number AS customer_contact,
            rb.branch_name,
            r.business_name AS restaurant_name,
            COUNT(DISTINCT qi.queue_item_id) AS item_count,
            COALESCE(SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)), 0) AS order_total
         FROM orders o
         JOIN customer c ON o.customer_id = c.customer_id
         LEFT JOIN queue_item qi ON o.order_id = qi.order_id
         LEFT JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
         LEFT JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
         WHERE o.delivery_rider_id = :rider_id
           AND o.order_status = 'rider_pending'
         GROUP BY o.order_id
         ORDER BY o.order_date ASC
         LIMIT :limit"
    );
    $stmt->bindValue(':rider_id', $riderId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Count how many orders this rider is already committed to.
 *
 * Counts orders that are in an active delivery state or waiting on
 * this rider's decision. Used to enforce the rider's cap before an
 * accept, so the rider gets a clean message instead of a DB-level
 * trigger SIGNAL.
 */
function hasActiveOrder(PDO $db, int $riderId): int
{
    $stmt = $db->prepare(
        "SELECT COUNT(*)
         FROM orders
         WHERE delivery_rider_id = :rider_id
           AND order_status IN ('rider_pending', 'delivering')"
    );
    $stmt->execute([':rider_id' => $riderId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Accept the kitchen's assignment.
 *
 * Only succeeds when the order is in 'rider_pending' AND the caller
 * is the assigned rider. On success, the order moves to 'delivering'.
 *
 * The rider's is_available flag is intentionally NOT modified here.
 * Availability is the rider's own toggle and must survive across
 * deliveries — a rider who was online when they accepted an order
 * must still be online after they finish it. The kitchen's "one
 * active delivery per rider" rule is enforced at assignment time by
 * assignRiderToOrder() in restaurant/backend/database/order-queries.php,
 * not by locking the rider offline.
 *
 * Returns true when the transition ran, false otherwise.
 */
function acceptOrder(PDO $db, int $riderId, int $orderId): bool
{
    $orderStmt = $db->prepare(
        "UPDATE orders
            SET order_status = 'delivering',
                updated_at   = NOW()
          WHERE order_id = :order_id
            AND delivery_rider_id = :rider_id
            AND order_status = 'rider_pending'"
    );
    $orderStmt->execute([
        ':order_id' => $orderId,
        ':rider_id' => $riderId,
    ]);

    return $orderStmt->rowCount() === 1;
}

/**
 * Decline the kitchen's assignment.
 *
 * Returns the order to 'preparing' and clears delivery_rider_id so
 * it reappears in the kitchen's Preparing tab for a new assignment.
 *
 * Only succeeds when the order is in 'rider_pending' AND the caller
 * is the assigned rider. The rider's is_available is not touched:
 * they were never marked unavailable for a pending request.
 *
 * Returns true when the transition ran, false otherwise.
 */
function declineOrder(PDO $db, int $riderId, int $orderId): bool
{
    $stmt = $db->prepare(
        "UPDATE orders
            SET order_status      = 'preparing',
                delivery_rider_id = NULL,
                updated_at        = NOW()
          WHERE order_id = :order_id
            AND delivery_rider_id = :rider_id
            AND order_status = 'rider_pending'"
    );
    $stmt->execute([
        ':order_id' => $orderId,
        ':rider_id' => $riderId,
    ]);

    return $stmt->rowCount() === 1;
}

// ============================================
// DELIVERY PAYOUT
// ============================================

/**
 * Credit the rider's wallet for a completed delivery.
 *
 * Inserts a `completed` deposit transaction into the rider's
 * financial_account. The database trigger `after_transaction_insert`
 * moves the balance — this function never writes
 * financial_account.balance directly.
 *
 * Idempotent per order: if a completed deposit already exists for
 * this rider and this order, the function returns false and writes
 * nothing. That makes it safe to call from any path that might also
 * trigger a payout (e.g. a future admin mark-delivered override, or
 * a retry after a transient failure).
 *
 * Called inside an open transaction by handleDelivered() in
 * rider-handler.php. It does not open or commit one of its own.
 *
 * @param PDO   $db
 * @param int   $riderId
 * @param int   $orderId
 * @param float $amount
 * @return bool  True if a deposit row was inserted, false otherwise.
 */
function creditRiderForDelivery(PDO $db, int $riderId, int $orderId, float $amount): bool
{
    if ($riderId <= 0 || $orderId <= 0 || $amount <= 0) {
        return false;
    }

    // Resolve the rider's financial account.
    $acct = $db->prepare(
        "SELECT financial_account_id
           FROM delivery_rider_profile
          WHERE delivery_rider_id = :rider_id
          LIMIT 1"
    );
    $acct->execute([':rider_id' => $riderId]);
    $accountId = (int)$acct->fetchColumn();

    if ($accountId <= 0) {
        return false;
    }

    // Idempotency: has this order already been credited to this rider?
    $dup = $db->prepare(
        "SELECT 1
           FROM transaction
          WHERE financial_account_id = :account_id
            AND order_id = :order_id
            AND transaction_type = 'deposit'
            AND status = 'completed'
          LIMIT 1"
    );
    $dup->execute([
        ':account_id' => $accountId,
        ':order_id'   => $orderId,
    ]);

    if ($dup->fetchColumn() !== false) {
        return false;
    }

    // Completed deposit — the trigger credits the balance.
    $ins = $db->prepare(
        "INSERT INTO transaction
            (financial_account_id, order_id, amount, transaction_type,
             status, description, transaction_date)
         VALUES
            (:account_id, :order_id, :amount, 'deposit',
             'completed', :description, NOW())"
    );
    $ins->execute([
        ':account_id'  => $accountId,
        ':order_id'    => $orderId,
        ':amount'      => round($amount, 2),
        ':description' => 'Delivery earnings for order #' . $orderId,
    ]);

    return true;
}

// ============================================
// REGISTRATION LOOKUPS
// ============================================

/**
 * Return true if a delivery_rider row already exists with this email.
 *
 * Called by sign-up-handler.php before the registration transaction
 * opens so the client can be told which field collided. This is a
 * pre-check, not a replacement for the UNIQUE constraint on
 * delivery_rider.email — the DB remains the authoritative guard.
 */
function riderEmailExists(PDO $db, string $email): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM delivery_rider WHERE email = ? LIMIT 1"
    );
    $stmt->execute([$email]);
    return $stmt->fetchColumn() !== false;
}

/**
 * Return true if a delivery_rider row already exists with this
 * username. See riderEmailExists() for the contract.
 */
function riderUsernameExists(PDO $db, string $username): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM delivery_rider WHERE username = ? LIMIT 1"
    );
    $stmt->execute([$username]);
    return $stmt->fetchColumn() !== false;
}

/**
 * Return true if a delivery_rider row already exists with this
 * contact number. See riderEmailExists() for the contract.
 */
function riderContactExists(PDO $db, string $contact): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM delivery_rider WHERE contact_number = ? LIMIT 1"
    );
    $stmt->execute([$contact]);
    return $stmt->fetchColumn() !== false;
}

// ============================================
// REGISTRATION WRITES
// ============================================

/**
 * Create the four core rider rows in one transaction:
 *
 *   1. financial_account    (balance 0.00, type 'rider')
 *   2. delivery_rider       (credentials; password hashed here)
 *   3. delivery_rider_profile
 *        - points at the rider and the financial account
 *        - holds the two upload paths
 *        - verification_status = 'pending'
 *        - is_available = 0
 *   4. delivery_rider_address  (default row for the rider)
 *
 * Contract with the caller:
 *   - The two upload files have already been moved to their final
 *     directories before this function runs. This function does not
 *     touch $_FILES or the filesystem. It only stores the paths it
 *     is given.
 *   - Every scalar field has already been validated. This function
 *     does not re-validate shape.
 *   - The caller is responsible for unlinking the moved files if
 *     this function throws. The handler's catch blocks already do
 *     that.
 *
 * On any failure the transaction is rolled back and the original
 * Throwable is re-thrown so the caller can decide how to respond.
 *
 * @param PDO   $db
 * @param array $account {
 *     first_name:     string,
 *     middle_name:    string,   // '' means NULL
 *     last_name:      string,
 *     birthdate:      string,   // Y-m-d
 *     gender:         string,
 *     email:          string,
 *     contact_number: string,   // digits only, no spaces
 *     username:       string,
 *     password:       string,   // PLAINTEXT — hashed inside
 * }
 * @param array $profile {
 *     profile_picture: string,  // project-root-relative path
 *     vehicle_type:    string,
 *     vehicle_plate:   string,  // '' means NULL
 * }
 * @param array $address {
 *     block:       string,
 *     barangay:    string,   // '' means NULL
 *     city:        string,
 *     province:    string,   // '' means NULL
 *     region:      string,   // '' means NULL
 *     postal_code: string,   // '' means NULL
 * }
 *
 * @return array{delivery_rider_id:int, financial_account_id:int}
 * @throws Throwable  Rolls back the transaction and re-throws.
 */
function createRiderAccount(PDO $db, array $account, array $profile, array $address): array
{
    $db->beginTransaction();

    try {
        // ---- 1. Financial account ----
        $fa = $db->prepare(
            "INSERT INTO financial_account (balance, account_type)
             VALUES (0.00, 'rider')"
        );
        $fa->execute();
        $financialAccountId = (int)$db->lastInsertId();

        // ---- 2. Delivery rider ----
        $hashed = password_hash((string)$account['password'], PASSWORD_BCRYPT);

        $rider = $db->prepare(
            "INSERT INTO delivery_rider
                (first_name, middle_name, last_name, birthdate, gender,
                 email, contact_number, username, password, is_active)
             VALUES
                (:first_name, :middle_name, :last_name, :birthdate, :gender,
                 :email, :contact_number, :username, :password, 1)"
        );
        $rider->execute([
            ':first_name'     => $account['first_name'],
            ':middle_name'    => $account['middle_name'] !== '' ? $account['middle_name'] : null,
            ':last_name'      => $account['last_name'],
            ':birthdate'      => $account['birthdate'],
            ':gender'         => $account['gender'],
            ':email'          => $account['email'],
            ':contact_number' => $account['contact_number'],
            ':username'       => $account['username'],
            ':password'       => $hashed,
        ]);
        $deliveryRiderId = (int)$db->lastInsertId();

        // ---- 3. Rider profile ----
        $profileStmt = $db->prepare(
            "INSERT INTO delivery_rider_profile
                (delivery_rider_id, financial_account_id, profile_picture,
                 vehicle_type, vehicle_plate, verification_status,
                 average_rating, total_deliveries, is_available)
             VALUES
                (:rider_id, :financial_account_id, :profile_picture,
                 :vehicle_type, :vehicle_plate, 'pending',
                 0.0, 0, 0)"
        );
        $profileStmt->execute([
            ':rider_id'             => $deliveryRiderId,
            ':financial_account_id' => $financialAccountId,
            ':profile_picture'      => $profile['profile_picture'],
            ':vehicle_type'         => $profile['vehicle_type'],
            ':vehicle_plate'        => $profile['vehicle_plate'] !== ''
                ? $profile['vehicle_plate']
                : null,
        ]);

        // ---- 4. Rider address ----
        $addressStmt = $db->prepare(
            "INSERT INTO delivery_rider_address
                (delivery_rider_id, block, barangay, city,
                 province, region, postal_code, country, is_default)
             VALUES
                (:rider_id, :block, :barangay, :city,
                 :province, :region, :postal_code, 'Philippines', 1)"
        );
        $addressStmt->execute([
            ':rider_id'    => $deliveryRiderId,
            ':block'       => $address['block'],
            ':barangay'    => $address['barangay']    !== '' ? $address['barangay']    : null,
            ':city'        => $address['city'],
            ':province'    => $address['province']    !== '' ? $address['province']    : null,
            ':region'      => $address['region']      !== '' ? $address['region']      : null,
            ':postal_code' => $address['postal_code'] !== '' ? $address['postal_code'] : null,
        ]);

        $db->commit();

        return [
            'delivery_rider_id'    => $deliveryRiderId,
            'financial_account_id' => $financialAccountId,
        ];

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Insert a rider's emergency contact.
 *
 * Called by sign-up-handler.php after createRiderAccount() returns
 * the rider id. Kept as a separate function (rather than folded into
 * createRiderAccount()) because:
 *   - it is a child of the rider and is written after the rider
 *     exists;
 *   - a future "add another emergency contact" flow can reuse this
 *     function unchanged.
 *
 * @param PDO   $db
 * @param int   $riderId
 * @param array $data {
 *     first_name:     string,
 *     middle_name:    string,   // '' means NULL
 *     last_name:      string,
 *     contact_number: string,
 *     relationship:   string,
 *     address:        string,   // '' means NULL
 * }
 * @return int  New emergency_contact_id
 */
function insertRiderEmergencyContact(PDO $db, int $riderId, array $data): int
{
    $stmt = $db->prepare(
        "INSERT INTO delivery_rider_emergency_contact
            (delivery_rider_id, first_name, middle_name, last_name,
             contact_number, relationship, address)
         VALUES
            (:rider_id, :first_name, :middle_name, :last_name,
             :contact_number, :relationship, :address)"
    );
    $stmt->execute([
        ':rider_id'       => $riderId,
        ':first_name'     => $data['first_name'],
        ':middle_name'    => $data['middle_name'] !== '' ? $data['middle_name'] : null,
        ':last_name'      => $data['last_name'],
        ':contact_number' => $data['contact_number'],
        ':relationship'   => $data['relationship'],
        ':address'        => $data['address']     !== '' ? $data['address']     : null,
    ]);

    return (int)$db->lastInsertId();
}

/**
 * Insert a rider's driver's license document row.
 *
 * Called by sign-up-handler.php after createRiderAccount() returns
 * the rider id. Kept as a separate function for the same reasons as
 * insertRiderEmergencyContact().
 *
 * @param PDO   $db
 * @param int   $riderId
 * @param array $data {
 *     drivers_license: string,   // project-root-relative path
 *     issue_date:      string,   // Y-m-d, '' means NULL
 *     expiry_date:     string,   // Y-m-d, '' means NULL
 * }
 * @return int  New document_id
 */
function insertRiderDocument(PDO $db, int $riderId, array $data): int
{
    $stmt = $db->prepare(
        "INSERT INTO delivery_rider_document
            (delivery_rider_id, drivers_license, issue_date, expiry_date)
         VALUES
            (:rider_id, :drivers_license, :issue_date, :expiry_date)"
    );
    $stmt->execute([
        ':rider_id'        => $riderId,
        ':drivers_license' => $data['drivers_license'],
        ':issue_date'      => $data['issue_date']  !== '' ? $data['issue_date']  : null,
        ':expiry_date'     => $data['expiry_date'] !== '' ? $data['expiry_date'] : null,
    ]);

    return (int)$db->lastInsertId();
}

// ============================================
// DASHBOARD STATISTICS
// ============================================

function getRiderDashboardStats(PDO $db, int $riderId): array
{
    $stats = [
        'today_earnings'    => 0.0,
        'today_deliveries'  => 0,
        'week_earnings'     => 0.0,
        'week_deliveries'   => 0,
        'week_earnings_max' => 0.0,
        'month_earnings'    => 0.0,
        'month_deliveries'  => 0,
        'total_earnings'    => 0.0,
        'total_deliveries'  => 0,
        'acceptance_rate'   => 100.0,
        'completion_rate'   => 100.0,
    ];

    $stmt = $db->prepare(
        "SELECT
            COALESCE(SUM(CASE
                WHEN o.order_status = 'delivered'
                 AND DATE(o.delivered_at) = CURDATE()
                THEN 50.00 ELSE 0 END), 0) AS today_earnings,

            COUNT(DISTINCT CASE
                WHEN o.order_status = 'delivered'
                 AND DATE(o.delivered_at) = CURDATE()
                THEN o.order_id END) AS today_deliveries,

            COALESCE(SUM(CASE
                WHEN o.order_status = 'delivered'
                 AND o.delivered_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                THEN 50.00 ELSE 0 END), 0) AS week_earnings,

            COUNT(DISTINCT CASE
                WHEN o.order_status = 'delivered'
                 AND o.delivered_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                THEN o.order_id END) AS week_deliveries,

            COALESCE(SUM(CASE
                WHEN o.order_status = 'delivered'
                 AND o.delivered_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                THEN 50.00 ELSE 0 END), 0) AS month_earnings,

            COUNT(DISTINCT CASE
                WHEN o.order_status = 'delivered'
                 AND o.delivered_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                THEN o.order_id END) AS month_deliveries,

            COALESCE(SUM(CASE
                WHEN o.order_status = 'delivered'
                THEN 50.00 ELSE 0 END), 0) AS total_earnings,

            COUNT(DISTINCT CASE
                WHEN o.order_status = 'delivered'
                THEN o.order_id END) AS total_deliveries,

            COUNT(DISTINCT CASE
                WHEN o.delivery_rider_id IS NOT NULL
                THEN o.order_id END) AS total_assigned,

            COUNT(DISTINCT CASE
                WHEN o.order_status = 'delivered'
                 AND o.delivery_rider_id IS NOT NULL
                THEN o.order_id END) AS total_completed,

            COUNT(DISTINCT CASE
                WHEN o.order_status = 'cancelled'
                 AND o.delivery_rider_id IS NOT NULL
                THEN o.order_id END) AS total_cancelled

         FROM orders o
         WHERE o.delivery_rider_id = :rider_id"
    );
    $stmt->execute([':rider_id' => $riderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stats['today_earnings']   = (float)($row['today_earnings'] ?? 0);
    $stats['today_deliveries'] = (int)($row['today_deliveries'] ?? 0);
    $stats['week_earnings']    = (float)($row['week_earnings'] ?? 0);
    $stats['week_deliveries']  = (int)($row['week_deliveries'] ?? 0);
    $stats['month_earnings']   = (float)($row['month_earnings'] ?? 0);
    $stats['month_deliveries'] = (int)($row['month_deliveries'] ?? 0);
    $stats['total_earnings']   = (float)($row['total_earnings'] ?? 0);
    $stats['total_deliveries'] = (int)($row['total_deliveries'] ?? 0);

    $totalAssigned  = (int)($row['total_assigned'] ?? 0);
    $totalCompleted = (int)($row['total_completed'] ?? 0);
    $totalCancelled = (int)($row['total_cancelled'] ?? 0);

    if ($totalAssigned > 0) {
        $accepted = $totalAssigned - $totalCancelled;
        $stats['acceptance_rate'] = round(($accepted / $totalAssigned) * 100, 1);
        $stats['completion_rate'] = round(($totalCompleted / $totalAssigned) * 100, 1);
    }

    $stmt = $db->prepare(
        "SELECT COALESCE(MAX(daily_earnings), 0) AS max_daily
         FROM (
            SELECT DATE(o.delivered_at) AS delivery_day,
                   SUM(50.00) AS daily_earnings
            FROM orders o
            WHERE o.delivery_rider_id = :rider_id
              AND o.order_status = 'delivered'
              AND o.delivered_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
            GROUP BY DATE(o.delivered_at)
         ) AS daily"
    );
    $stmt->execute([':rider_id' => $riderId]);
    $stats['week_earnings_max'] = (float)$stmt->fetchColumn();

    return $stats;
}

function getRiderWeeklyEarnings(PDO $db, int $riderId, int $days = 7): array
{
    $stmt = $db->prepare(
        "SELECT
            DATE(o.delivered_at) AS day,
            COUNT(DISTINCT o.order_id) AS deliveries,
            SUM(50.00) AS amount
         FROM orders o
         WHERE o.delivery_rider_id = :rider_id
           AND o.order_status = 'delivered'
           AND o.delivered_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
         GROUP BY DATE(o.delivered_at)
         ORDER BY day ASC"
    );
    $stmt->bindValue(':rider_id', $riderId, PDO::PARAM_INT);
    $stmt->bindValue(':days', $days - 1, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $byDay = [];
    foreach ($rows as $r) {
        $byDay[$r['day']] = [
            'amount'     => (float)$r['amount'],
            'deliveries' => (int)$r['deliveries'],
        ];
    }

    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $ts   = strtotime("-{$i} days");
        $date = date('Y-m-d', $ts);
        $series[] = [
            'date'       => $date,
            'label'      => date('l', $ts),
            'short'      => date('D', $ts),
            'amount'     => $byDay[$date]['amount'] ?? 0.0,
            'deliveries' => $byDay[$date]['deliveries'] ?? 0,
        ];
    }

    return $series;
}

function getRiderRecentDeliveries(PDO $db, int $riderId, int $limit = 5): array
{
    $stmt = $db->prepare(
        "SELECT
            o.order_id,
            CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
            o.destination_address,
            o.delivered_at,
            o.order_date,
            COALESCE((
                SELECT SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price))
                FROM queue_item qi
                WHERE qi.order_id = o.order_id
            ), 0) AS order_total
         FROM orders o
         JOIN customer c ON o.customer_id = c.customer_id
         WHERE o.delivery_rider_id = :rider_id
           AND o.order_status = 'delivered'
         ORDER BY o.delivered_at DESC
         LIMIT :limit"
    );
    $stmt->bindValue(':rider_id', $riderId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['rider_earning'] = 50.00;
    }
    unset($row);

    return $rows;
}

function getRiderChartScale(float $maxAmount): array
{
    if ($maxAmount <= 0) {
        return [
            'ceiling'   => 500.0,
            'step'      => 100.0,
            'gridlines' => [0.0, 100.0, 200.0, 300.0, 400.0, 500.0],
        ];
    }

    $magnitude  = 10 ** floor(log10($maxAmount));
    $normalized = $maxAmount / $magnitude;

    $stepMultiplier = match (true) {
        $normalized <= 1.5 => 0.25,
        $normalized <= 3.0 => 0.5,
        $normalized <= 7.0 => 1.0,
        default            => 2.0,
    };

    $step    = $magnitude * $stepMultiplier;
    $ceiling = ceil($maxAmount / $step) * $step;

    if ($ceiling < $maxAmount * 2) {
        $ceiling += $step;
    }

    $gridlines = [];
    for ($v = 0.0; $v <= $ceiling + 0.001; $v += $step) {
        $gridlines[] = round($v, 2);
    }

    return [
        'ceiling'   => round($ceiling, 2),
        'step'      => round($step, 2),
        'gridlines' => $gridlines,
    ];
}

// ============================================
// DELIVERIES
// ============================================

/**
 * Active deliveries for a rider — orders in 'delivering' that are
 * assigned to them.
 *
 * Optional delta filter
 * ---------------------
 * When $sinceOrderId > 0, only orders with order_id strictly greater
 * than it are returned. This mirrors the assignment panel's poll
 * contract and lets a caller drive a delta refresh without a second
 * query function.
 *
 * The deliveries page calls this with no argument (full list).
 * Nothing else in the current codebase calls the delta form; the
 * parameter is here so a future caller — e.g. a live refresh of the
 * Active Deliveries section — can reuse the same SELECT shape
 * without duplicating it.
 *
 * @param PDO $db
 * @param int $riderId
 * @param int $sinceOrderId  0 for full list; > 0 for delta.
 * @return array<int, array<string, mixed>>
 */
function getRiderActiveDeliveries(PDO $db, int $riderId, int $sinceOrderId = 0): array
{
    if ($riderId <= 0) {
        return [];
    }

    if ($sinceOrderId > 0) {
        $stmt = $db->prepare(
            "SELECT
                o.order_id,
                o.order_status,
                o.order_date,
                o.destination_address,
                CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                c.contact_number AS customer_contact,
                rb.branch_name,
                r.business_name AS restaurant_name,
                COUNT(DISTINCT qi.queue_item_id) AS item_count,
                COALESCE(SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)), 0) AS order_total
             FROM orders o
             JOIN customer c ON o.customer_id = c.customer_id
             LEFT JOIN queue_item qi ON o.order_id = qi.order_id
             LEFT JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
             LEFT JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
             WHERE o.delivery_rider_id = :rider_id
               AND o.order_status = 'delivering'
               AND o.order_id > :since_order_id
             GROUP BY o.order_id
             ORDER BY o.order_id ASC"
        );
        $stmt->execute([
            ':rider_id'       => $riderId,
            ':since_order_id' => $sinceOrderId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $db->prepare(
        "SELECT
            o.order_id,
            o.order_status,
            o.order_date,
            o.destination_address,
            CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
            c.contact_number AS customer_contact,
            rb.branch_name,
            r.business_name AS restaurant_name,
            COUNT(DISTINCT qi.queue_item_id) AS item_count,
            COALESCE(SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)), 0) AS order_total
         FROM orders o
         JOIN customer c ON o.customer_id = c.customer_id
         LEFT JOIN queue_item qi ON o.order_id = qi.order_id
         LEFT JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
         LEFT JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
         WHERE o.delivery_rider_id = :rider_id
           AND o.order_status = 'delivering'
         GROUP BY o.order_id
         ORDER BY o.order_date ASC"
    );
    $stmt->execute([':rider_id' => $riderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getRiderDeliveryHistory(PDO $db, int $riderId, int $limit = 10): array
{
    $stmt = $db->prepare(
        "SELECT
            o.order_id,
            o.order_status,
            o.order_date,
            o.delivered_at,
            o.destination_address,
            CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
            COALESCE((
                SELECT SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price))
                FROM queue_item qi
                WHERE qi.order_id = o.order_id
            ), 0) AS order_total
         FROM orders o
         JOIN customer c ON o.customer_id = c.customer_id
         WHERE o.delivery_rider_id = :rider_id
           AND o.order_status IN ('delivered', 'cancelled', 'refunded')
         ORDER BY COALESCE(o.delivered_at, o.order_date) DESC
         LIMIT :limit"
    );
    $stmt->bindValue(':rider_id', $riderId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getRiderDeliveryCounts(PDO $db, int $riderId): array
{
    $stmt = $db->prepare(
        "SELECT
            SUM(CASE WHEN order_status IN ('rider_pending','delivering') THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN order_status = 'delivered'
                      AND DATE(delivered_at) = CURDATE() THEN 1 ELSE 0 END) AS today,
            SUM(CASE WHEN order_status = 'delivered'
                      AND delivered_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN 1 ELSE 0 END) AS week,
            SUM(CASE WHEN order_status = 'delivered' THEN 1 ELSE 0 END) AS total
         FROM orders
         WHERE delivery_rider_id = :rider_id"
    );
    $stmt->execute([':rider_id' => $riderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'active' => (int)($row['active'] ?? 0),
        'today'  => (int)($row['today']  ?? 0),
        'week'   => (int)($row['week']   ?? 0),
        'total'  => (int)($row['total']  ?? 0),
    ];
}

// ============================================
// EARNINGS / TRANSACTIONS
// ============================================

function getRiderTransactions(PDO $db, int $riderId, int $limit = 10, int $offset = 0): array
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
         JOIN delivery_rider_profile drp ON t.financial_account_id = drp.financial_account_id
         WHERE drp.delivery_rider_id = :rider_id
         ORDER BY t.transaction_date DESC, t.transaction_id DESC
         LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue(':rider_id', $riderId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function countRiderTransactions(PDO $db, int $riderId): int
{
    $stmt = $db->prepare(
        "SELECT COUNT(*)
         FROM transaction t
         JOIN delivery_rider_profile drp ON t.financial_account_id = drp.financial_account_id
         WHERE drp.delivery_rider_id = :rider_id"
    );
    $stmt->execute([':rider_id' => $riderId]);
    return (int)$stmt->fetchColumn();
}

function requestRiderWithdrawal(PDO $db, int $riderId, float $amount): int|false
{
    $stmt = $db->prepare(
        "SELECT drp.financial_account_id, fa.balance
         FROM delivery_rider_profile drp
         JOIN financial_account fa ON drp.financial_account_id = fa.financial_account_id
         WHERE drp.delivery_rider_id = :rider_id
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([':rider_id' => $riderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return false;
    }

    $accountId = (int)$row['financial_account_id'];
    $balance   = (float)$row['balance'];

    if ($amount <= 0 || $amount > $balance) {
        return false;
    }

    $insert = $db->prepare(
        "INSERT INTO transaction
            (financial_account_id, order_id, amount, transaction_type, status, description)
         VALUES
            (:account_id, NULL, :amount, 'withdrawal', 'pending', :description)"
    );
    $insert->execute([
        ':account_id'  => $accountId,
        ':amount'      => $amount,
        ':description' => 'Withdrawal request',
    ]);

    return (int)$db->lastInsertId();
}

// ============================================
// ADDRESS
// ============================================

function getRiderDefaultAddress(PDO $db, int $riderId): array|false
{
    $stmt = $db->prepare(
        "SELECT
            delivery_rider_address_id,
            block,
            barangay,
            city,
            province,
            region,
            postal_code,
            country,
            is_default
         FROM delivery_rider_address
         WHERE delivery_rider_id = :rider_id
         ORDER BY is_default DESC, delivery_rider_address_id ASC
         LIMIT 1"
    );
    $stmt->execute([':rider_id' => $riderId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// ============================================
// FORMATTING HELPERS
// ============================================

function formatRiderCurrency(int|float|string|null $amount): string
{
    return '₱' . number_format((float)($amount ?? 0), 2);
}

if (!function_exists('truncateText')) {
    function truncateText(string $text, int $length = 70): string
    {
        $text = trim($text);
        if (strlen($text) <= $length) {
            return $text;
        }
        return substr($text, 0, $length) . '...';
    }
}