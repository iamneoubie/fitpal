<?php
/**
 * FitPal Rider Database Queries
 *
 * Pure data-access layer for the delivery_rider,
 * delivery_rider_profile, delivery_rider_address, orders,
 * and transaction tables.
 *
 * No $_POST, no header(), no echo.
 *
 * @package FitPal
 * @version 2.1 — Fixed leading whitespace that broke header() calls.
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

function setRiderAvailability(PDO $db, int $riderId, int $isAvailable): bool
{
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
        $ts = strtotime("-{$i} days");
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

    $magnitude = 10 ** floor(log10($maxAmount));
    $normalized = $maxAmount / $magnitude;

    $stepMultiplier = match (true) {
        $normalized <= 1.5 => 0.25,
        $normalized <= 3.0 => 0.5,
        $normalized <= 7.0 => 1.0,
        default            => 2.0,
    };

    $step = $magnitude * $stepMultiplier;
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

function getRiderActiveDeliveries(PDO $db, int $riderId): array
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
           AND o.order_status IN ('preparing', 'delivering')
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
            SUM(CASE WHEN order_status IN ('preparing','delivering') THEN 1 ELSE 0 END) AS active,
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
            label,
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