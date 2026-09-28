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
 * ---------------------------------------------------------------------
 * Order lifecycle (v7.5)
 * ---------------------------------------------------------------------
 * The rider's delivery lifecycle is a two-step accept:
 *
 *     rider_pending  --acceptOrder()-->  picking_up
 *     picking_up     --markOrderPickedUp()-->  delivering
 *     delivering     --(delivered by handleDelivered)-->  delivered
 *
 * Accepting no longer puts the order in transit. The rider must take
 * a second explicit action ("Mark Picked Up") to move from
 * picking_up to delivering. No step may be skipped:
 *
 *   - markOrderPickedUp() only accepts from 'picking_up'
 *   - handleDelivered() (in rider-handler.php) only accepts from
 *     'delivering'
 *
 * ---------------------------------------------------------------------
 * Availability model
 * ---------------------------------------------------------------------
 * `delivery_rider_profile.is_available` is the rider's OWN toggle.
 * It is set at sign-in (forced offline), flipped by the rider from
 * the dashboard, and cleared on sign-out. It is NOT touched by the
 * accept path, the pickup path, the deliver path, or the kitchen's
 * assign/reassign paths.
 *
 * Going offline is refused while the rider has any order in
 * 'rider_pending', 'picking_up', or 'delivering'. The rider must
 * finish all in-flight work before they can go offline. The refusal
 * is enforced by setRiderAvailability().
 *
 * ---------------------------------------------------------------------
 * Concurrent-order cap (v7.5)
 * ---------------------------------------------------------------------
 * A rider may hold at most 3 orders at once. The cap counts the
 * orders the rider has ACTUALLY COMMITTED TO:
 *
 *     picking_up   accepted; en route to or at the restaurant
 *     delivering   rider has the food; en route to the customer
 *
 * 'rider_pending' is deliberately EXCLUDED. An order in
 * 'rider_pending' is a kitchen offer the rider has not yet accepted
 * or declined. It does not occupy a delivery slot. The rider may
 * accept all of the pending offers the kitchen sends; the cap only
 * applies once the rider has actually accepted.
 *
 * This is what lets a rider with 3 pending offers accept all 3.
 * Before the accept, the rider holds zero committed orders. After
 * the accept, each order becomes 'picking_up' and starts counting.
 * To accept a 4th, the rider must finish at least one of the three
 * already accepted — dropping the committed count back to 2, which
 * leaves room for one more.
 *
 * The cap number lives in RIDER_CONCURRENT_CAP in this file. The
 * same number is enforced by:
 *
 *   - acceptOrder()'s caller (handleAccept in assignment-handler.php,
 *     and handleAcceptAssignment in rider-handler.php),
 *   - the restaurant's assignRiderToOrder() / reassignRiderToOrder()
 *     under a FOR UPDATE lock on the rider's profile row,
 *   - the SQL trigger before_order_rider_assign (which also counts
 *     only the committed statuses — see the schema trigger body).
 *
 * ---------------------------------------------------------------------
 * Payout model
 * ---------------------------------------------------------------------
 * A completed delivery credits the rider's financial_account via a
 * `deposit` transaction. The database trigger
 * `after_transaction_insert` moves the balance — this file never
 * writes financial_account.balance directly. creditRiderForDelivery()
 * is the single write path and is idempotent per order.
 *
 * ---------------------------------------------------------------------
 * Change log
 * ---------------------------------------------------------------------
 * v7.5 — The concurrent-order cap now counts only the orders the
 *        rider has actually accepted:
 *
 *        - COMMITTED_RIDER_STATUSES replaces LIVE_RIDER_STATUSES as
 *          the set that hasActiveOrder() and riderAtConcurrentCap()
 *          count against the cap. It is ['picking_up','delivering'].
 *          'rider_pending' is excluded.
 *
 *        - LIVE_RIDER_STATUSES is retained and still used by
 *          setRiderAvailability() — a rider cannot go offline while
 *          any order is still assigned to them, whether they have
 *          accepted it or not. This is a different rule with a
 *          different purpose, so it keeps a different set.
 *
 *        - hasActiveOrder() now counts committed orders only.
 *
 *        - riderAtConcurrentCap() now checks against the committed
 *          count.
 *
 *        - getRiderDeliveryCounts()'s 'active' bucket, which is what
 *          the deliveries page tab badge shows, now counts committed
 *          orders only as well. 'rider_pending' offers are shown on
 *          the Assigned tab, and their count lives in the Assigned
 *          tab badge, not the Active tab badge.
 *
 *        Rationale: a kitchen offer the rider has not accepted must
 *        not occupy a delivery slot. A rider with 3 pending offers
 *        was blocked from accepting any of them because the old
 *        cap counted 'rider_pending' against the limit. The new cap
 *        lets the rider accept all 3, and only starts refusing once
 *        the rider actually holds 3 accepted orders.
 *
 *        No other function changed from v7.4. The write functions
 *        (acceptOrder, markOrderPickedUp, declineOrder) are
 *        unchanged, because they already transition through the
 *        right statuses and do not themselves read the cap.
 *
 * v7.4 — Added 'picking_up' between 'rider_pending' and 'delivering'.
 *        Retained.
 *
 * v7.3 — insertRiderDocument() rewritten for the generalized
 *        schema (id_type + id_path). Retained.
 *
 * v7.2 — Adds updateRiderProfilePicture(). Retained.
 *
 * v7.1 — DELTA-FRIENDLY ACTIVE DELIVERIES. Retained.
 *
 * v7.0 — DELIVERY PAYOUT + AVAILABILITY CLEANUP. Retained.
 *
 * v6.0 — REGISTRATION SQL CONSOLIDATED HERE. Retained.
 *
 * v5.0 — RIDER ACCEPT / DECLINE FOR THE RIDER_PENDING HANDOFF.
 */

declare(strict_types=1);

if (!defined('RIDER_CONCURRENT_CAP')) {
    define('RIDER_CONCURRENT_CAP', 3);
}

/**
 * Statuses that count against the concurrent-order cap.
 *
 * These are the statuses the rider has ACTUALLY COMMITTED TO. Once
 * an order enters one of them, the rider owns it and it occupies a
 * delivery slot until it reaches a closed state.
 *
 * 'rider_pending' is deliberately absent. An order in
 * 'rider_pending' is a kitchen offer the rider has not yet accepted
 * or declined. It is not yet the rider's order, so it must not
 * occupy a slot. Excluding it is what lets a rider with several
 * pending offers accept all of them.
 */
if (!defined('COMMITTED_RIDER_STATUSES')) {
    define('COMMITTED_RIDER_STATUSES', ['picking_up', 'delivering']);
}

/**
 * Statuses that block a rider from going offline.
 *
 * This is a broader set than COMMITTED_RIDER_STATUSES. A rider must
 * not be allowed to go offline while ANY order is still assigned to
 * them — including a 'rider_pending' offer they have not yet
 * decided on. An offer sitting in 'rider_pending' needs the rider's
 * decision; going offline would strand it.
 */
if (!defined('LIVE_RIDER_STATUSES')) {
    define('LIVE_RIDER_STATUSES', ['rider_pending', 'picking_up', 'delivering']);
}

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
 * Set the rider's own availability flag.
 *
 * Going offline (isAvailable = 0) is refused while the rider has
 * any order in 'rider_pending', 'picking_up', or 'delivering'. The
 * rider must finish all in-flight work — and decide on every
 * pending offer — before they can go offline. Going online
 * (isAvailable = 1) has no guard.
 *
 * Note this guard uses the BROADER LIVE_RIDER_STATUSES set, not
 * COMMITTED_RIDER_STATUSES. A 'rider_pending' offer the rider has
 * not yet answered still blocks sign-out, because leaving it
 * unanswered would strand the order. The cap, by contrast, uses the
 * narrower committed set — the two rules are deliberately different.
 *
 * @param PDO $db
 * @param int $riderId
 * @param int $isAvailable
 * @return bool  false when the offline transition is refused
 */
function setRiderAvailability(PDO $db, int $riderId, int $isAvailable): bool
{
    if ($isAvailable === 0) {
        $busyStmt = $db->prepare(
            "SELECT 1 FROM orders
              WHERE delivery_rider_id = :rider_id
                AND order_status IN ('rider_pending','picking_up','delivering')
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

/**
 * Update the rider's profile picture path.
 *
 * Scoped to the owning rider — the WHERE clause pins delivery_rider_id,
 * so a call can only ever change the picture on the row that belongs
 * to the authenticated rider.
 *
 * Returns true when a row was actually written, false when the
 * submitted path equals the stored one (MySQL reports 0 affected rows
 * on a no-op UPDATE). The handler must not treat that false as a
 * failure: from the rider's point of view the picture they chose is
 * now on file, and the response should still be status: success.
 *
 * @param PDO    $db
 * @param int    $riderId
 * @param string $relativePath
 *        e.g. 'shared/uploads/rider/profiles/12/09_27_2026_0.jpg'
 * @return bool
 */
function updateRiderProfilePicture(PDO $db, int $riderId, string $relativePath): bool
{
    $stmt = $db->prepare(
        "UPDATE delivery_rider_profile
            SET profile_picture = :picture
          WHERE delivery_rider_id = :rider_id"
    );
    $stmt->execute([
        ':picture'  => $relativePath,
        ':rider_id' => $riderId,
    ]);

    return $stmt->rowCount() > 0;
}

// ============================================
// ASSIGNED ORDERS (kitchen handoff)
// ============================================

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
 * Count the rider's committed concurrent orders.
 *
 * Counts ONLY 'picking_up' and 'delivering'. These are the orders
 * the rider has actually accepted and is now responsible for.
 *
 * 'rider_pending' is excluded. An order in 'rider_pending' is a
 * kitchen offer the rider has not yet decided on; it does not
 * occupy a delivery slot. Excluding it is what lets a rider with
 * several pending offers accept all of them.
 *
 * Closed work ('delivered', 'cancelled', 'refunded') does not
 * count. The current order is not excluded — callers who want to
 * exclude a specific order should subtract it themselves or use a
 * dedicated helper.
 *
 * @param PDO $db
 * @param int $riderId
 * @return int
 */
function hasActiveOrder(PDO $db, int $riderId): int
{
    $stmt = $db->prepare(
        "SELECT COUNT(*)
         FROM orders
         WHERE delivery_rider_id = :rider_id
           AND order_status IN ('picking_up','delivering')"
    );
    $stmt->execute([':rider_id' => $riderId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Convenience: true when the rider is at or above the concurrent
 * order cap of 3.
 *
 * Checks the committed count from hasActiveOrder() — the orders the
 * rider has accepted. A rider with 3 'rider_pending' offers but no
 * accepted orders is NOT at the cap and can accept all of them.
 * Once 3 orders are accepted, the rider is at the cap and must
 * finish at least one before accepting a 4th.
 *
 * @param PDO $db
 * @param int $riderId
 * @return bool
 */
function riderAtConcurrentCap(PDO $db, int $riderId): bool
{
    return hasActiveOrder($db, $riderId) >= RIDER_CONCURRENT_CAP;
}

/**
 * Accept a rider_pending assignment.
 *
 * Moves the order to 'picking_up' — NOT to 'delivering'. The rider
 * must then call markOrderPickedUp() once they have the food in
 * hand. This two-step flow means the order is visible to the
 * kitchen as "picking up" until the rider explicitly confirms the
 * pickup.
 *
 * Returns true on a successful transition, false if the order was
 * not in 'rider_pending' for this rider (already accepted,
 * declined, or reassigned by the kitchen).
 *
 * @param PDO $db
 * @param int $riderId
 * @param int $orderId
 * @return bool
 */
function acceptOrder(PDO $db, int $riderId, int $orderId): bool
{
    $orderStmt = $db->prepare(
        "UPDATE orders
            SET order_status = 'picking_up',
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
 * Mark the order as physically picked up: 'picking_up' → 'delivering'.
 *
 * Only fires from 'picking_up' for this rider. A rider who never
 * accepted, or whose order was already moved to 'delivering', will
 * get a false return. This is the only way to enter 'delivering'
 * via the rider side.
 *
 * @param PDO $db
 * @param int $riderId
 * @param int $orderId
 * @return bool
 */
function markOrderPickedUp(PDO $db, int $riderId, int $orderId): bool
{
    $stmt = $db->prepare(
        "UPDATE orders
            SET order_status = 'delivering',
                updated_at   = NOW()
          WHERE order_id = :order_id
            AND delivery_rider_id = :rider_id
            AND order_status = 'picking_up'"
    );
    $stmt->execute([
        ':order_id' => $orderId,
        ':rider_id' => $riderId,
    ]);

    return $stmt->rowCount() === 1;
}

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

function creditRiderForDelivery(PDO $db, int $riderId, int $orderId, float $amount): bool
{
    if ($riderId <= 0 || $orderId <= 0 || $amount <= 0) {
        return false;
    }

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

function riderEmailExists(PDO $db, string $email): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM delivery_rider WHERE email = ? LIMIT 1"
    );
    $stmt->execute([$email]);
    return $stmt->fetchColumn() !== false;
}

function riderUsernameExists(PDO $db, string $username): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM delivery_rider WHERE username = ? LIMIT 1"
    );
    $stmt->execute([$username]);
    return $stmt->fetchColumn() !== false;
}

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

function createRiderAccount(PDO $db, array $account, array $profile, array $address): array
{
    $db->beginTransaction();

    try {
        $fa = $db->prepare(
            "INSERT INTO financial_account (balance, account_type)
             VALUES (0.00, 'rider')"
        );
        $fa->execute();
        $financialAccountId = (int)$db->lastInsertId();

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
 * Insert a rider identity document row.
 *
 * The generalized delivery_rider_document schema (v1.2.0) uses
 * id_type + id_path. issue_date and expiry_date are optional; an
 * empty string is coerced to SQL NULL.
 *
 * @param PDO    $db
 * @param int    $riderId
 * @param array{
 *     id_type:    string,
 *     id_path:    string,
 *     issue_date: string,
 *     expiry_date: string
 * } $data
 * @return int   Inserted document_id.
 */
function insertRiderDocument(PDO $db, int $riderId, array $data): int
{
    $stmt = $db->prepare(
        "INSERT INTO delivery_rider_document
            (delivery_rider_id, id_type, id_path, issue_date, expiry_date)
         VALUES
            (:rider_id, :id_type, :id_path, :issue_date, :expiry_date)"
    );
    $stmt->execute([
        ':rider_id'    => $riderId,
        ':id_type'     => $data['id_type'],
        ':id_path'     => $data['id_path'],
        ':issue_date'  => $data['issue_date']  !== '' ? $data['issue_date']  : null,
        ':expiry_date' => $data['expiry_date'] !== '' ? $data['expiry_date'] : null,
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
 * Fetch the rider's active deliveries — orders in either
 * 'picking_up' or 'delivering'.
 *
 * The rider's "Active" tab on deliveries.php shows both statuses,
 * so this query returns both. A 'picking_up' order and a
 * 'delivering' order appear side by side with different action
 * buttons on each card.
 *
 * Delta support: when $sinceOrderId > 0, only rows with order_id
 * greater than it are returned. The client uses the delta path to
 * poll for newly accepted orders without re-fetching the whole
 * list.
 *
 * @param PDO $db
 * @param int $riderId
 * @param int $sinceOrderId
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
               AND o.order_status IN ('picking_up','delivering')
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
           AND o.order_status IN ('picking_up','delivering')
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

/**
 * Counts of the rider's orders grouped by the buckets that matter
 * to the deliveries page.
 *
 *   'active'  → order_status IN ('picking_up','delivering').
 *               These are the orders the rider has actually
 *               accepted and is now responsible for. This bucket
 *               matches the concurrent-order cap's committed set,
 *               so the Active tab badge shows the same count the
 *               cap enforces against.
 *               'rider_pending' offers are shown on the Assigned
 *               tab, and their count lives in the Assigned tab
 *               badge (assignedOrders.length), not here.
 *   'today'   → delivered today
 *   'week'    → delivered in the last 7 days (including today)
 *   'total'   → total delivered
 *
 * @param PDO $db
 * @param int $riderId
 * @return array{active:int, today:int, week:int, total:int}
 */
function getRiderDeliveryCounts(PDO $db, int $riderId): array
{
    $stmt = $db->prepare(
        "SELECT
            SUM(CASE WHEN order_status IN ('picking_up','delivering') THEN 1 ELSE 0 END) AS active,
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