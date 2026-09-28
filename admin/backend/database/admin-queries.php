<?php
/**
 * FitPal Admin Database Queries
 *
 * Pure data-access layer for the admin role. Owns every query against
 * the customer, delivery_rider, restaurant, financial_account, orders,
 * transaction, and queue_item tables. Also owns the presentation
 * helpers that operate on rows from those tables.
 *
 * No $_POST, no header(), no echo.
 *
 * Pagination contract (every paginated read follows this):
 *   1. Count query with the same WHERE clause as the data query.
 *   2. Data query with LIMIT/OFFSET bound as integers.
 *   3. Return ['rows' => [...], 'total' => N, 'page' => P, 'perPage' => L, 'totalPages' => T].
 *
 * Default page size is 5 across every list.
 *
 * Dashboard analytics
 * -------------------
 * Four readers back the dashboard's chart tabs:
 *
 *   getAdminDashboardStats()    high-level counts + gross revenue
 *   getAdminWeeklyRevenue()     7-day GMV + fee series
 *   getAdminWeeklyFeeBreakdown() 7-day fee-bucket series
 *   getAdminWeeklyOrders()      7-day order count by payment method
 *   getAdminTopRestaurants()    top restaurants by GMV
 *   getAdminTopRiders()         top riders by completed deliveries
 *
 * Every total is computed from queue_item + the fee schedule in
 * customer/backend/database/fee-queries.php. The orders table itself
 * no longer stores subtotal, delivery_charge, total_amount, or any
 * of the fee fields, so the analytics view has to re-derive them
 * from the current fee constants. The constants are loaded here so
 * the dashboard, the checkout page, and createOrderFromQueue() all
 * agree on the same numbers.
 *
 * @package FitPal
 * @version 9.0 — Adds four analytics readers for the dashboard's
 *                chart tabs. All existing readers are unchanged.
 *
 *                (8.0: merged dashboard order scans; added
 *                $withTotal to paginated readers. 7.0: added
 *                admin profile picture functions. 6.0: added
 *                getRestaurantPermits().)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../customer/backend/database/fee-queries.php';

/* =============================================================
 * PAGINATION HELPER
 * ============================================================= */

function adminPaginationEnvelope(int $total, int $perPage, int $page): array
{
    $perPage = max(1, $perPage);
    $page    = max(1, $page);
    $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;

    if ($page > $totalPages) {
        $page = $totalPages;
    }

    return [
        'total'      => $total,
        'perPage'    => $perPage,
        'page'       => $page,
        'totalPages' => $totalPages,
    ];
}

/* =============================================================
 * ADMIN LOOKUP + AUTH
 * ============================================================= */

function findAdminByIdentifier(PDO $db, string $identifier): array|false
{
    $stmt = $db->prepare(
        "SELECT
            a.administrator_id,
            a.first_name,
            a.middle_name,
            a.last_name,
            a.email,
            a.username,
            a.password,
            a.is_active,
            ap.role,
            ap.permissions
         FROM administrator a
         LEFT JOIN administrator_profile ap
                ON a.administrator_id = ap.administrator_id
         WHERE a.email = :email OR a.username = :username
         LIMIT 1"
    );
    $stmt->execute([':email' => $identifier, ':username' => $identifier]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getAdminPasswordHash(PDO $db, int $adminId): string
{
    $stmt = $db->prepare(
        "SELECT password
           FROM administrator
          WHERE administrator_id = :admin_id
          LIMIT 1"
    );
    $stmt->execute([':admin_id' => $adminId]);
    return (string)$stmt->fetchColumn();
}

function getAdminProfile(PDO $db, int $adminId): array|false
{
    $stmt = $db->prepare(
        "SELECT
            a.administrator_id,
            a.first_name,
            a.middle_name,
            a.last_name,
            a.email,
            a.contact_number,
            a.username,
            a.birthdate,
            a.gender,
            a.date_created,
            a.is_active,
            ap.role,
            ap.permissions,
            ap.last_login,
            ap.created_at AS profile_created_at,
            ap.profile_picture
         FROM administrator a
         LEFT JOIN administrator_profile ap
                ON a.administrator_id = ap.administrator_id
         WHERE a.administrator_id = :admin_id
         LIMIT 1"
    );
    $stmt->execute([':admin_id' => $adminId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function updateAdminProfile(
    PDO $db,
    int $adminId,
    string $firstName,
    string $middleName,
    string $lastName,
    string $contactNumber
): bool {
    $stmt = $db->prepare(
        "UPDATE administrator
            SET first_name     = :first_name,
                middle_name    = :middle_name,
                last_name      = :last_name,
                contact_number = :contact_number
          WHERE administrator_id = :admin_id"
    );
    $stmt->execute([
        ':first_name'     => $firstName,
        ':middle_name'    => $middleName !== '' ? $middleName : null,
        ':last_name'      => $lastName,
        ':contact_number' => $contactNumber !== '' ? $contactNumber : null,
        ':admin_id'       => $adminId,
    ]);
    return true;
}

function updateAdminProfilePicture(PDO $db, int $adminId, string $relativePath): bool
{
    $stmt = $db->prepare(
        "UPDATE administrator_profile
            SET profile_picture = :picture
          WHERE administrator_id = :admin_id"
    );
    $stmt->execute([
        ':picture'  => $relativePath,
        ':admin_id' => $adminId,
    ]);

    return $stmt->rowCount() > 0;
}

function getAdminProfilePicture(PDO $db, int $adminId): string
{
    $stmt = $db->prepare(
        "SELECT profile_picture
           FROM administrator_profile
          WHERE administrator_id = :admin_id
          LIMIT 1"
    );
    $stmt->execute([':admin_id' => $adminId]);
    $value = $stmt->fetchColumn();
    return $value === false || $value === null ? '' : (string)$value;
}

function updateAdminPassword(PDO $db, int $adminId, string $newHashedPassword): bool
{
    $stmt = $db->prepare(
        "UPDATE administrator SET password = :password WHERE administrator_id = :admin_id"
    );
    $stmt->execute([':password' => $newHashedPassword, ':admin_id' => $adminId]);
    return true;
}

function recordAdminLogin(PDO $db, int $adminId): void
{
    $stmt = $db->prepare(
        "UPDATE administrator_profile SET last_login = NOW() WHERE administrator_id = :admin_id"
    );
    $stmt->execute([':admin_id' => $adminId]);
}

/* =============================================================
 * DASHBOARD STATS
 * ============================================================= */

function getAdminDashboardStats(PDO $db): array
{
    $stats = [
        'total_customers'      => 0,
        'active_customers'     => 0,
        'total_restaurants'    => 0,
        'verified_restaurants' => 0,
        'total_riders'         => 0,
        'verified_riders'      => 0,
        'pending_riders'       => 0,
        'total_orders'         => 0,
        'orders_today'         => 0,
        'orders_this_week'     => 0,
        'active_orders'        => 0,
        'delivered_orders'     => 0,
        'cancelled_orders'     => 0,
        'refunded_orders'      => 0,
        'gross_merchandise_value' => 0.0,
        'platform_revenue'     => 0.0,
        'revenue_this_week'    => 0.0,
        'revenue_today'        => 0.0,
        'average_order_value'  => 0.0,
    ];

    $entityRow = $db->query(
        "SELECT
            (SELECT COUNT(*) FROM customer)                                                        AS total_customers,
            (SELECT COUNT(*) FROM customer WHERE is_active = 1)                                    AS active_customers,
            (SELECT COUNT(*) FROM restaurant)                                                      AS total_restaurants,
            (SELECT COUNT(*) FROM restaurant WHERE verification_status = 'verified')               AS verified_restaurants,
            (SELECT COUNT(*) FROM delivery_rider)                                                  AS total_riders,
            (SELECT COUNT(*) FROM delivery_rider_profile WHERE verification_status = 'verified')   AS verified_riders,
            (SELECT COUNT(*) FROM delivery_rider_profile WHERE verification_status = 'pending')    AS pending_riders"
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    $stats['total_customers']      = (int)($entityRow['total_customers'] ?? 0);
    $stats['active_customers']     = (int)($entityRow['active_customers'] ?? 0);
    $stats['total_restaurants']    = (int)($entityRow['total_restaurants'] ?? 0);
    $stats['verified_restaurants'] = (int)($entityRow['verified_restaurants'] ?? 0);
    $stats['total_riders']         = (int)($entityRow['total_riders'] ?? 0);
    $stats['verified_riders']      = (int)($entityRow['verified_riders'] ?? 0);
    $stats['pending_riders']       = (int)($entityRow['pending_riders'] ?? 0);

    // One scan of orders+queue_item produces every order and revenue
    // aggregate the dashboard needs.
    $orderRow = $db->query(
        "SELECT
            COUNT(DISTINCT o.order_id) AS total_orders,
            COUNT(DISTINCT CASE
                WHEN DATE(o.order_date) = CURDATE()
                THEN o.order_id END) AS orders_today,
            COUNT(DISTINCT CASE
                WHEN o.order_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                THEN o.order_id END) AS orders_this_week,
            COUNT(DISTINCT CASE
                WHEN o.order_status IN ('pending','preparing','rider_pending','picking_up','delivering')
                THEN o.order_id END) AS active_orders,
            COUNT(DISTINCT CASE
                WHEN o.order_status = 'delivered'
                THEN o.order_id END) AS delivered_orders,
            COUNT(DISTINCT CASE
                WHEN o.order_status = 'cancelled'
                THEN o.order_id END) AS cancelled_orders,
            COUNT(DISTINCT CASE
                WHEN o.order_status = 'refunded'
                THEN o.order_id END) AS refunded_orders,
            COALESCE(SUM(CASE
                WHEN o.order_status NOT IN ('cancelled','refunded')
                THEN qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)
                ELSE 0 END), 0) AS gross_merchandise_value,
            COALESCE(SUM(CASE
                WHEN o.order_status NOT IN ('cancelled','refunded')
                 AND o.order_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                THEN qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)
                ELSE 0 END), 0) AS gmv_this_week,
            COALESCE(SUM(CASE
                WHEN o.order_status NOT IN ('cancelled','refunded')
                 AND DATE(o.order_date) = CURDATE()
                THEN qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)
                ELSE 0 END), 0) AS gmv_today,
            COUNT(DISTINCT CASE
                WHEN o.order_status NOT IN ('cancelled','refunded')
                THEN o.order_id END) AS billable_orders
         FROM orders o
         LEFT JOIN queue_item qi ON qi.order_id = o.order_id"
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    $stats['total_orders']     = (int)($orderRow['total_orders'] ?? 0);
    $stats['orders_today']     = (int)($orderRow['orders_today'] ?? 0);
    $stats['orders_this_week'] = (int)($orderRow['orders_this_week'] ?? 0);
    $stats['active_orders']    = (int)($orderRow['active_orders'] ?? 0);
    $stats['delivered_orders'] = (int)($orderRow['delivered_orders'] ?? 0);
    $stats['cancelled_orders'] = (int)($orderRow['cancelled_orders'] ?? 0);
    $stats['refunded_orders']  = (int)($orderRow['refunded_orders'] ?? 0);

    $gmv        = (float)($orderRow['gross_merchandise_value'] ?? 0);
    $gmvWeek    = (float)($orderRow['gmv_this_week'] ?? 0);
    $gmvToday   = (float)($orderRow['gmv_today'] ?? 0);
    $billable   = max(1, (int)($orderRow['billable_orders'] ?? 0));

    $stats['gross_merchandise_value'] = $gmv;
    $stats['average_order_value']     = round($gmv / $billable, 2);

    // Platform revenue = all four fee buckets across non-cancelled
    // orders. Computed with the same constants the checkout page and
    // createOrderFromQueue() use, so the dashboard never disagrees
    // with what the customer was charged.
    $stats['platform_revenue']  = round(
        calculatePlatformRevenueFromGmv($gmv, $stats['delivered_orders'] + $stats['active_orders']),
        2
    );
    $stats['revenue_this_week'] = round(
        calculatePlatformRevenueFromGmv($gmvWeek, 0),
        2
    );
    $stats['revenue_today']     = round(
        calculatePlatformRevenueFromGmv($gmvToday, 0),
        2
    );

    return $stats;
}

/**
 * Compute the platform's take for a given GMV and order count.
 *
 * The fee schedule is defined in fee-queries.php. Because a
 * per-order breakdown by branch count is not available from the
 * aggregate GMV alone, this function applies the base delivery fee
 * and service fee per order, VAT on the GMV, and assumes the
 * average order has 1 branch. Extra-branch surcharges are therefore
 * not modeled here — they only appear in the per-order series used
 * by the Fees tab, where the branch count is available.
 *
 * @param float $gmv
 * @param int   $orderCount
 * @return float
 */
function calculatePlatformRevenueFromGmv(float $gmv, int $orderCount): float
{
    $baseDelivery = FITPAL_DELIVERY_BASE_FEE * $orderCount;
    $serviceFee   = FITPAL_SERVICE_FEE * $orderCount;
    $vat          = $gmv * FITPAL_VAT_RATE;

    return $baseDelivery + $serviceFee + $vat;
}

function getAdminChartScale(float $maxAmount): array
{
    if ($maxAmount <= 0) {
        return [
            'ceiling'   => 1000.0,
            'step'      => 250.0,
            'gridlines' => [0.0, 250.0, 500.0, 750.0, 1000.0],
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

/**
 * Seven-day series of GMV and platform revenue.
 *
 * Each day returns:
 *   gmv              sum of item subtotals for non-cancelled orders
 *   platform_fees    base delivery + service + VAT (no extra branch)
 *   orders           distinct order count for that day
 *
 * Days with no orders are included with zeroed values, so the chart
 * always has exactly $days bars.
 */
function getAdminWeeklyRevenue(PDO $db, int $days = 7): array
{
    $days = max(1, min(30, $days));

    $stmt = $db->prepare(
        "SELECT
            DATE(o.order_date) AS day,
            COALESCE(SUM(
                qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)
            ), 0) AS gmv,
            COUNT(DISTINCT o.order_id) AS orders
         FROM orders o
         JOIN queue_item qi ON qi.order_id = o.order_id
         WHERE o.order_status NOT IN ('cancelled', 'refunded')
           AND o.order_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
         GROUP BY DATE(o.order_date)
         ORDER BY day ASC"
    );
    $stmt->bindValue(':days', $days - 1, PDO::PARAM_INT);
    $stmt->execute();

    $byDay = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $byDay[$r['day']] = [
            'gmv'    => (float)$r['gmv'],
            'orders' => (int)$r['orders'],
        ];
    }

    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $ts   = strtotime("-{$i} days");
        $date = date('Y-m-d', $ts);

        $gmv    = $byDay[$date]['gmv'] ?? 0.0;
        $orders = $byDay[$date]['orders'] ?? 0;

        $series[] = [
            'date'          => $date,
            'label'         => date('l', $ts),
            'short'         => date('D', $ts),
            'gmv'           => $gmv,
            'platform_fees' => calculatePlatformRevenueFromGmv($gmv, $orders),
            'orders'        => $orders,
        ];
    }
    return $series;
}

/**
 * Seven-day series of fee buckets.
 *
 * Each day returns the four fee lines the platform charges:
 *
 *   base_delivery    FITPAL_DELIVERY_BASE_FEE × distinct orders
 *   extra_branches   FITPAL_DELIVERY_EXTRA_PER_BRANCH × extra branches
 *   service_fee      FITPAL_SERVICE_FEE × distinct orders
 *   vat              FITPAL_VAT_RATE × GMV
 *
 * The extra-branch surcharge is per order for each branch beyond the
 * first. It is computed here as
 *   (branch_count_for_order - 1) × FITPAL_DELIVERY_EXTRA_PER_BRANCH
 * summed across the day's orders.
 */
function getAdminWeeklyFeeBreakdown(PDO $db, int $days = 7): array
{
    $days = max(1, min(30, $days));

    // Per-order branch counts, one row per day.
    $stmt = $db->prepare(
        "SELECT
            DATE(o.order_date) AS day,
            o.order_id,
            COUNT(DISTINCT qi.branch_id) AS branch_count,
            COALESCE(SUM(
                qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)
            ), 0) AS order_gmv
         FROM orders o
         JOIN queue_item qi ON qi.order_id = o.order_id
         WHERE o.order_status NOT IN ('cancelled', 'refunded')
           AND o.order_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
         GROUP BY DATE(o.order_date), o.order_id
         ORDER BY day ASC"
    );
    $stmt->bindValue(':days', $days - 1, PDO::PARAM_INT);
    $stmt->execute();

    $byDay = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $day       = $r['day'];
        $branches  = max(1, (int)$r['branch_count']);
        $orderGmv  = (float)$r['order_gmv'];
        $extra     = $branches - 1;

        if (!isset($byDay[$day])) {
            $byDay[$day] = [
                'base_delivery'  => 0.0,
                'extra_branches' => 0.0,
                'service_fee'    => 0.0,
                'vat'            => 0.0,
                'orders'         => 0,
            ];
        }

        $byDay[$day]['base_delivery']  += FITPAL_DELIVERY_BASE_FEE;
        $byDay[$day]['extra_branches'] += $extra * FITPAL_DELIVERY_EXTRA_PER_BRANCH;
        $byDay[$day]['service_fee']    += FITPAL_SERVICE_FEE;
        $byDay[$day]['vat']            += $orderGmv * FITPAL_VAT_RATE;
        $byDay[$day]['orders']         += 1;
    }

    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $ts   = strtotime("-{$i} days");
        $date = date('Y-m-d', $ts);
        $row  = $byDay[$date] ?? [
            'base_delivery'  => 0.0,
            'extra_branches' => 0.0,
            'service_fee'    => 0.0,
            'vat'            => 0.0,
            'orders'         => 0,
        ];

        $series[] = [
            'date'           => $date,
            'label'          => date('l', $ts),
            'short'          => date('D', $ts),
            'base_delivery'  => round($row['base_delivery'], 2),
            'extra_branches' => round($row['extra_branches'], 2),
            'service_fee'    => round($row['service_fee'], 2),
            'vat'            => round($row['vat'], 2),
            'total'          => round(
                $row['base_delivery'] +
                $row['extra_branches'] +
                $row['service_fee'] +
                $row['vat'],
                2
            ),
            'orders'         => $row['orders'],
        ];
    }
    return $series;
}

/**
 * Seven-day series of order counts by payment method.
 */
function getAdminWeeklyOrders(PDO $db, int $days = 7): array
{
    $days = max(1, min(30, $days));

    $stmt = $db->prepare(
        "SELECT
            DATE(order_date) AS day,
            SUM(CASE WHEN payment_method = 'COD'    THEN 1 ELSE 0 END) AS cod,
            SUM(CASE WHEN payment_method = 'Wallet' THEN 1 ELSE 0 END) AS wallet,
            SUM(CASE WHEN payment_method = 'Online' THEN 1 ELSE 0 END) AS online,
            SUM(CASE WHEN order_status = 'delivered' THEN 1 ELSE 0 END) AS delivered,
            SUM(CASE WHEN order_status IN ('cancelled','refunded') THEN 1 ELSE 0 END) AS cancelled,
            COUNT(*) AS total
         FROM orders
         WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
         GROUP BY DATE(order_date)
         ORDER BY day ASC"
    );
    $stmt->bindValue(':days', $days - 1, PDO::PARAM_INT);
    $stmt->execute();

    $byDay = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $byDay[$r['day']] = $r;
    }

    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $ts   = strtotime("-{$i} days");
        $date = date('Y-m-d', $ts);
        $row  = $byDay[$date] ?? [
            'cod'       => 0,
            'wallet'    => 0,
            'online'    => 0,
            'delivered' => 0,
            'cancelled' => 0,
            'total'     => 0,
        ];

        $series[] = [
            'date'      => $date,
            'label'     => date('l', $ts),
            'short'     => date('D', $ts),
            'cod'       => (int)$row['cod'],
            'wallet'    => (int)$row['wallet'],
            'online'    => (int)$row['online'],
            'delivered' => (int)$row['delivered'],
            'cancelled' => (int)$row['cancelled'],
            'total'     => (int)$row['total'],
        ];
    }
    return $series;
}

/**
 * Top restaurants by GMV across non-cancelled orders.
 *
 * @param int $limit
 * @return array<int, array{
 *     restaurant_id:int,
 *     restaurant_name:string,
 *     order_count:int,
 *     gmv:float,
 *     platform_fees:float
 * }>
 */
function getAdminTopRestaurants(PDO $db, int $limit = 5): array
{
    $limit = max(1, min(20, $limit));

    $stmt = $db->prepare(
        "SELECT
            r.restaurant_id,
            r.business_name AS restaurant_name,
            COUNT(DISTINCT o.order_id) AS order_count,
            COALESCE(SUM(
                qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)
            ), 0) AS gmv
         FROM restaurant r
         JOIN restaurant_branch rb ON rb.restaurant_id = r.restaurant_id
         JOIN queue_item qi ON qi.branch_id = rb.restaurant_branch_id
         JOIN orders o ON o.order_id = qi.order_id
         WHERE o.order_status NOT IN ('cancelled', 'refunded')
         GROUP BY r.restaurant_id, r.business_name
         ORDER BY gmv DESC
         LIMIT :lim"
    );
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['restaurant_id'] = (int)$row['restaurant_id'];
        $row['order_count']   = (int)$row['order_count'];
        $row['gmv']           = (float)$row['gmv'];
        $row['platform_fees'] = calculatePlatformRevenueFromGmv(
            $row['gmv'],
            $row['order_count']
        );
    }
    unset($row);

    return $rows;
}

/**
 * Top riders by completed deliveries.
 *
 * @param int $limit
 * @return array<int, array{
 *     delivery_rider_id:int,
 *     rider_name:string,
 *     deliveries:int,
 *     avg_rating:float
 * }>
 */
function getAdminTopRiders(PDO $db, int $limit = 5): array
{
    $limit = max(1, min(20, $limit));

    $stmt = $db->prepare(
        "SELECT
            dr.delivery_rider_id,
            CONCAT(
                dr.first_name, ' ',
                COALESCE(dr.middle_name, ''), ' ',
                dr.last_name
            ) AS rider_name,
            COUNT(o.order_id) AS deliveries,
            COALESCE(drp.average_rating, 0.0) AS avg_rating
         FROM delivery_rider dr
         LEFT JOIN delivery_rider_profile drp
                ON drp.delivery_rider_id = dr.delivery_rider_id
         LEFT JOIN orders o
                ON o.delivery_rider_id = dr.delivery_rider_id
               AND o.order_status = 'delivered'
         GROUP BY dr.delivery_rider_id, rider_name, drp.average_rating
         ORDER BY deliveries DESC, avg_rating DESC
         LIMIT :lim"
    );
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['delivery_rider_id'] = (int)$row['delivery_rider_id'];
        $row['rider_name']        = trim((string)preg_replace('/\s+/', ' ', (string)$row['rider_name']));
        $row['deliveries']        = (int)$row['deliveries'];
        $row['avg_rating']        = (float)$row['avg_rating'];
    }
    unset($row);

    return $rows;
}

/* =============================================================
 * CUSTOMER / RIDER / RESTAURANT MANAGEMENT (unchanged)
 * ============================================================= */

function getCustomersPaginated(
    PDO $db,
    int $page = 1,
    int $perPage = 5,
    string $search = '',
    string $statusFilter = 'all',
    bool $withTotal = true
): array {
    $where  = "WHERE 1=1";
    $params = [];

    if ($search !== '') {
        $where .= " AND (c.first_name LIKE :search
                    OR c.last_name LIKE :search
                    OR c.email LIKE :search
                    OR c.username LIKE :search
                    OR c.contact_number LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    if ($statusFilter === 'active') {
        $where .= " AND c.is_active = 1";
    } elseif ($statusFilter === 'inactive') {
        $where .= " AND c.is_active = 0";
    }

    if ($withTotal) {
        $countStmt = $db->prepare("SELECT COUNT(*) FROM customer c {$where}");
        foreach ($params as $k => $v) {
            $countStmt->bindValue($k, $v);
        }
        $countStmt->execute();
        $total = (int)$countStmt->fetchColumn();
    } else {
        $total = 0;
    }

    $env = adminPaginationEnvelope($total, $perPage, $page);

    $stmt = $db->prepare(
        "SELECT
            c.customer_id, c.first_name, c.middle_name, c.last_name,
            c.email, c.contact_number, c.username, c.is_active, c.date_created,
            cp.dietary_preferences, cp.allergies, cp.fitness_goal,
            fa.balance,
            (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.customer_id) AS order_count
         FROM customer c
         LEFT JOIN customer_profile cp ON cp.customer_id = c.customer_id
         LEFT JOIN financial_account fa ON cp.financial_account_id = fa.financial_account_id
         {$where}
         ORDER BY c.date_created DESC
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit',  $env['perPage'], PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($env['page'] - 1) * $env['perPage'], PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$withTotal) {
        $env['total']      = count($rows);
        $env['totalPages'] = 1;
    }

    return [
        'rows'       => $rows,
        'total'      => $env['total'],
        'page'       => $env['page'],
        'perPage'    => $env['perPage'],
        'totalPages' => $env['totalPages'],
    ];
}

function getCustomerCountsByStatus(PDO $db): array
{
    $row = $db->query(
        "SELECT
            COUNT(*) AS all_count,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS inactive_count
         FROM customer"
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'all'      => (int)($row['all_count'] ?? 0),
        'active'   => (int)($row['active_count'] ?? 0),
        'inactive' => (int)($row['inactive_count'] ?? 0),
    ];
}

function getCustomerDetails(PDO $db, int $customerId): array|false
{
    $stmt = $db->prepare(
        "SELECT
            c.customer_id, c.first_name, c.middle_name, c.last_name,
            c.email, c.contact_number, c.username, c.is_active,
            c.birthdate, c.gender, c.date_created,
            cp.dietary_preferences, cp.allergies, cp.fitness_goal,
            cp.height_cm, cp.weight_kg, cp.profile_picture,
            fa.balance,
            (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.customer_id) AS order_count,
            (SELECT COUNT(*) FROM customer_address ca WHERE ca.customer_id = c.customer_id) AS address_count
         FROM customer c
         LEFT JOIN customer_profile cp ON cp.customer_id = c.customer_id
         LEFT JOIN financial_account fa ON cp.financial_account_id = fa.financial_account_id
         WHERE c.customer_id = :cid
         LIMIT 1"
    );
    $stmt->execute([':cid' => $customerId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getCustomerAddresses(PDO $db, int $customerId): array
{
    $stmt = $db->prepare(
        "SELECT customer_address_id, label, block, barangay, city, province,
                region, postal_code, country, is_default
         FROM customer_address
         WHERE customer_id = :cid
         ORDER BY is_default DESC, customer_address_id ASC"
    );
    $stmt->execute([':cid' => $customerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCustomerRecentOrders(PDO $db, int $customerId, int $limit = 5): array
{
    $limit = max(1, min(20, $limit));

    $stmt = $db->prepare(
        "SELECT
            o.order_id, o.order_status, o.order_date, o.payment_method,
            o.destination_address,
            COALESCE((
                SELECT SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price))
                FROM queue_item qi WHERE qi.order_id = o.order_id
            ), 0) AS order_total
         FROM orders o
         WHERE o.customer_id = :cid
         ORDER BY o.order_date DESC
         LIMIT :limit"
    );
    $stmt->bindValue(':cid', $customerId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function setCustomerActiveStatus(PDO $db, int $customerId, bool $isActive): bool
{
    $stmt = $db->prepare(
        "UPDATE customer SET is_active = :is_active WHERE customer_id = :cid"
    );
    $stmt->execute([
        ':is_active' => $isActive ? 1 : 0,
        ':cid'       => $customerId,
    ]);
    return $stmt->rowCount() > 0;
}

function getRidersPaginated(
    PDO $db,
    int $page = 1,
    int $perPage = 5,
    string $search = '',
    string $statusFilter = 'all',
    bool $withTotal = true
): array {
    $where  = "WHERE 1=1";
    $params = [];

    if ($search !== '') {
        $where .= " AND (dr.first_name LIKE :search
                    OR dr.last_name LIKE :search
                    OR dr.email LIKE :search
                    OR dr.username LIKE :search
                    OR dr.contact_number LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    if ($statusFilter !== 'all') {
        $where .= " AND drp.verification_status = :status";
        $params[':status'] = $statusFilter;
    }

    if ($withTotal) {
        $countStmt = $db->prepare(
            "SELECT COUNT(*)
             FROM delivery_rider dr
             LEFT JOIN delivery_rider_profile drp ON drp.delivery_rider_id = dr.delivery_rider_id
             {$where}"
        );
        foreach ($params as $k => $v) {
            $countStmt->bindValue($k, $v);
        }
        $countStmt->execute();
        $total = (int)$countStmt->fetchColumn();
    } else {
        $total = 0;
    }

    $env = adminPaginationEnvelope($total, $perPage, $page);

    $stmt = $db->prepare(
        "SELECT
            dr.delivery_rider_id, dr.first_name, dr.middle_name, dr.last_name,
            dr.email, dr.contact_number, dr.username, dr.is_active, dr.date_created,
            drp.profile_picture, drp.vehicle_type, drp.vehicle_plate,
            drp.verification_status, drp.average_rating, drp.total_deliveries,
            drp.is_available, drp.verified_at,
            fa.balance,
            (SELECT COUNT(*) FROM delivery_rider_document drd
              WHERE drd.delivery_rider_id = dr.delivery_rider_id) AS document_count,
            (SELECT COUNT(*) FROM delivery_rider_emergency_contact ec
              WHERE ec.delivery_rider_id = dr.delivery_rider_id) AS emergency_contact_count
         FROM delivery_rider dr
         LEFT JOIN delivery_rider_profile drp ON drp.delivery_rider_id = dr.delivery_rider_id
         LEFT JOIN financial_account fa ON fa.financial_account_id = drp.financial_account_id
         {$where}
         ORDER BY dr.date_created DESC
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit',  $env['perPage'], PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($env['page'] - 1) * $env['perPage'], PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$withTotal) {
        $env['total']      = count($rows);
        $env['totalPages'] = 1;
    }

    return [
        'rows'       => $rows,
        'total'      => $env['total'],
        'page'       => $env['page'],
        'perPage'    => $env['perPage'],
        'totalPages' => $env['totalPages'],
    ];
}

function getRiderCountsByStatus(PDO $db): array
{
    $row = $db->query(
        "SELECT
            COUNT(*) AS all_count,
            SUM(CASE WHEN verification_status = 'pending'   THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN verification_status = 'verified'  THEN 1 ELSE 0 END) AS verified_count,
            SUM(CASE WHEN verification_status = 'denied'    THEN 1 ELSE 0 END) AS denied_count,
            SUM(CASE WHEN verification_status = 'suspended' THEN 1 ELSE 0 END) AS suspended_count
         FROM delivery_rider_profile"
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'all'       => (int)($row['all_count'] ?? 0),
        'pending'   => (int)($row['pending_count'] ?? 0),
        'verified'  => (int)($row['verified_count'] ?? 0),
        'denied'    => (int)($row['denied_count'] ?? 0),
        'suspended' => (int)($row['suspended_count'] ?? 0),
    ];
}

function getRiderDetails(PDO $db, int $riderId): array|false
{
    $stmt = $db->prepare(
        "SELECT
            dr.delivery_rider_id, dr.first_name, dr.middle_name, dr.last_name,
            dr.email, dr.contact_number, dr.username, dr.is_active, dr.date_created,
            dr.birthdate, dr.gender,
            drp.profile_picture, drp.vehicle_type, drp.vehicle_plate,
            drp.verification_status, drp.average_rating, drp.total_deliveries,
            drp.is_available, drp.verified_at,
            fa.balance
         FROM delivery_rider dr
         LEFT JOIN delivery_rider_profile drp ON drp.delivery_rider_id = dr.delivery_rider_id
         LEFT JOIN financial_account fa ON fa.financial_account_id = drp.financial_account_id
         WHERE dr.delivery_rider_id = :rid
         LIMIT 1"
    );
    $stmt->execute([':rid' => $riderId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getRiderAddress(PDO $db, int $riderId): array|false
{
    $stmt = $db->prepare(
        "SELECT delivery_rider_address_id, block, barangay, city, province,
                region, postal_code, country, is_default
         FROM delivery_rider_address
         WHERE delivery_rider_id = :rid
         ORDER BY is_default DESC, delivery_rider_address_id ASC
         LIMIT 1"
    );
    $stmt->execute([':rid' => $riderId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getRiderEmergencyContacts(PDO $db, int $riderId): array
{
    $stmt = $db->prepare(
        "SELECT emergency_contact_id, first_name, middle_name, last_name,
                contact_number, relationship, address, created_at
         FROM delivery_rider_emergency_contact
         WHERE delivery_rider_id = :rid
         ORDER BY emergency_contact_id ASC"
    );
    $stmt->execute([':rid' => $riderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getRiderDocuments(PDO $db, int $riderId): array
{
    $stmt = $db->prepare(
        "SELECT document_id, id_type, id_path, issue_date, expiry_date,
                created_at, updated_at
         FROM delivery_rider_document
         WHERE delivery_rider_id = :rid
         ORDER BY document_id ASC"
    );
    $stmt->execute([':rid' => $riderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getRiderRecentDeliveries(PDO $db, int $riderId, int $limit = 5): array
{
    $limit = max(1, min(20, $limit));

    $stmt = $db->prepare(
        "SELECT
            o.order_id, o.order_status, o.order_date, o.delivered_at,
            o.destination_address,
            CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
            COALESCE((
                SELECT SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price))
                FROM queue_item qi WHERE qi.order_id = o.order_id
            ), 0) AS order_total
         FROM orders o
         JOIN customer c ON c.customer_id = o.customer_id
         WHERE o.delivery_rider_id = :rid
         ORDER BY COALESCE(o.delivered_at, o.order_date) DESC
         LIMIT :limit"
    );
    $stmt->bindValue(':rid', $riderId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function setRiderVerificationStatus(
    PDO $db,
    int $riderId,
    string $status,
    int $adminId
): bool {
    $allowed = ['pending', 'verified', 'denied', 'suspended'];
    if (!in_array($status, $allowed, true)) {
        return false;
    }
    $stmt = $db->prepare(
        "UPDATE delivery_rider_profile
            SET verification_status  = :status,
                verified_by_admin_id = :admin_id,
                verified_at          = CASE WHEN :status2 = 'verified' THEN NOW() ELSE NULL END
          WHERE delivery_rider_id = :rid"
    );
    $stmt->execute([
        ':status'   => $status,
        ':status2'  => $status,
        ':admin_id' => $adminId,
        ':rid'      => $riderId,
    ]);
    return $stmt->rowCount() > 0;
}

function setRiderActiveStatus(PDO $db, int $riderId, bool $isActive): bool
{
    $stmt = $db->prepare(
        "UPDATE delivery_rider SET is_active = :is_active WHERE delivery_rider_id = :rid"
    );
    $stmt->execute([
        ':is_active' => $isActive ? 1 : 0,
        ':rid'       => $riderId,
    ]);
    return $stmt->rowCount() > 0;
}

function getRestaurantsPaginated(
    PDO $db,
    int $page = 1,
    int $perPage = 5,
    string $search = '',
    string $statusFilter = 'all',
    bool $withTotal = true
): array {
    $where  = "WHERE 1=1";
    $params = [];

    if ($search !== '') {
        $where .= " AND (r.business_name LIKE :search OR r.cuisine_type LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    if ($statusFilter !== 'all') {
        $where .= " AND r.verification_status = :status";
        $params[':status'] = $statusFilter;
    }

    if ($withTotal) {
        $countStmt = $db->prepare("SELECT COUNT(*) FROM restaurant r {$where}");
        foreach ($params as $k => $v) {
            $countStmt->bindValue($k, $v);
        }
        $countStmt->execute();
        $total = (int)$countStmt->fetchColumn();
    } else {
        $total = 0;
    }

    $env = adminPaginationEnvelope($total, $perPage, $page);

    $stmt = $db->prepare(
        "SELECT
            r.restaurant_id, r.business_name, r.description, r.cuisine_type,
            r.dietary_tags, r.verification_status, r.verified_at,
            r.is_active, r.created_at,
            (SELECT COUNT(*) FROM restaurant_branch rb WHERE rb.restaurant_id = r.restaurant_id) AS branch_count,
            (SELECT COUNT(*) FROM product p
                JOIN restaurant_branch rb2 ON p.restaurant_branch_id = rb2.restaurant_branch_id
              WHERE rb2.restaurant_id = r.restaurant_id AND p.is_active = 1) AS product_count,
            (SELECT COUNT(DISTINCT qi.order_id)
                FROM queue_item qi
                JOIN restaurant_branch rb3 ON qi.branch_id = rb3.restaurant_branch_id
              WHERE rb3.restaurant_id = r.restaurant_id) AS order_count
         FROM restaurant r
         {$where}
         ORDER BY r.created_at DESC
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit',  $env['perPage'], PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($env['page'] - 1) * $env['perPage'], PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$withTotal) {
        $env['total']      = count($rows);
        $env['totalPages'] = 1;
    }

    return [
        'rows'       => $rows,
        'total'      => $env['total'],
        'page'       => $env['page'],
        'perPage'    => $env['perPage'],
        'totalPages' => $env['totalPages'],
    ];
}

function getRestaurantCountsByStatus(PDO $db): array
{
    $row = $db->query(
        "SELECT
            COUNT(*) AS all_count,
            SUM(CASE WHEN verification_status = 'pending'   THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN verification_status = 'verified'  THEN 1 ELSE 0 END) AS verified_count,
            SUM(CASE WHEN verification_status = 'denied'    THEN 1 ELSE 0 END) AS denied_count,
            SUM(CASE WHEN verification_status = 'suspended' THEN 1 ELSE 0 END) AS suspended_count
         FROM restaurant"
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'all'       => (int)($row['all_count'] ?? 0),
        'pending'   => (int)($row['pending_count'] ?? 0),
        'verified'  => (int)($row['verified_count'] ?? 0),
        'denied'    => (int)($row['denied_count'] ?? 0),
        'suspended' => (int)($row['suspended_count'] ?? 0),
    ];
}

function getRestaurantDetails(PDO $db, int $restaurantId): array|false
{
    $stmt = $db->prepare(
        "SELECT
            r.restaurant_id, r.business_name, r.description, r.cuisine_type,
            r.dietary_tags, r.verification_status, r.verified_at,
            r.is_active, r.created_at, r.updated_at,
            (SELECT COUNT(*) FROM restaurant_branch rb WHERE rb.restaurant_id = r.restaurant_id) AS branch_count,
            (SELECT COUNT(*) FROM product p
                JOIN restaurant_branch rb2 ON p.restaurant_branch_id = rb2.restaurant_branch_id
              WHERE rb2.restaurant_id = r.restaurant_id AND p.is_active = 1) AS product_count
         FROM restaurant r
         WHERE r.restaurant_id = :rid
         LIMIT 1"
    );
    $stmt->execute([':rid' => $restaurantId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getRestaurantBranches(PDO $db, int $restaurantId): array
{
    $stmt = $db->prepare(
        "SELECT
            rb.restaurant_branch_id, rb.branch_name, rb.branch_code,
            rb.block, rb.barangay, rb.city, rb.province, rb.region,
            rb.postal_code, rb.country, rb.is_active, rb.created_at,
            fa.balance,
            (SELECT COUNT(*) FROM product p
              WHERE p.restaurant_branch_id = rb.restaurant_branch_id AND p.is_active = 1) AS product_count
         FROM restaurant_branch rb
         LEFT JOIN financial_account fa ON fa.financial_account_id = rb.financial_account_id
         WHERE rb.restaurant_id = :rid
         ORDER BY rb.is_active DESC, rb.branch_name ASC"
    );
    $stmt->execute([':rid' => $restaurantId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getRestaurantAccounts(PDO $db, int $restaurantId): array
{
    $stmt = $db->prepare(
        "SELECT
            restaurant_account_id, first_name, middle_name, last_name,
            email, contact_number, username, role, is_active, date_created
         FROM restaurant_account
         WHERE restaurant_id = :rid
         ORDER BY FIELD(role, 'owner', 'partner', 'manager', 'staff', 'cashier', 'kitchen'),
                  date_created ASC"
    );
    $stmt->execute([':rid' => $restaurantId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getRestaurantPermits(PDO $db, int $restaurantId): array
{
    $stmt = $db->prepare(
        "SELECT
            permit_id,
            restaurant_id,
            file_path,
            original_name,
            display_order,
            created_at
         FROM restaurant_permit
         WHERE restaurant_id = :rid
         ORDER BY display_order ASC, permit_id ASC"
    );
    $stmt->execute([':rid' => $restaurantId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function setRestaurantVerificationStatus(
    PDO $db,
    int $restaurantId,
    string $status,
    int $adminId
): bool {
    $allowed = ['pending', 'verified', 'denied', 'suspended'];
    if (!in_array($status, $allowed, true)) {
        return false;
    }
    $stmt = $db->prepare(
        "UPDATE restaurant
            SET verification_status  = :status,
                verified_by_admin_id = :admin_id,
                verified_at          = CASE WHEN :status2 = 'verified' THEN NOW() ELSE NULL END
          WHERE restaurant_id = :rid"
    );
    $stmt->execute([
        ':status'   => $status,
        ':status2'  => $status,
        ':admin_id' => $adminId,
        ':rid'      => $restaurantId,
    ]);
    return $stmt->rowCount() > 0;
}

function setRestaurantActiveStatus(PDO $db, int $restaurantId, bool $isActive): bool
{
    $stmt = $db->prepare(
        "UPDATE restaurant SET is_active = :is_active WHERE restaurant_id = :rid"
    );
    $stmt->execute([
        ':is_active' => $isActive ? 1 : 0,
        ':rid'       => $restaurantId,
    ]);
    return $stmt->rowCount() > 0;
}

/* =============================================================
 * PRESENTATION HELPERS
 * ============================================================= */

function formatAdminCurrency(int|float|string|null $amount): string
{
    return '₱' . number_format((float)($amount ?? 0), 2);
}

function formatAdminCurrencyCompact(int|float|string|null $amount): string
{
    $n = (float)($amount ?? 0);

    if ($n >= 1_000_000) {
        return '₱' . number_format($n / 1_000_000, 1) . 'M';
    }
    if ($n >= 1_000) {
        return '₱' . number_format($n / 1_000, 1) . 'K';
    }
    return '₱' . number_format($n, 0);
}

function formatAdminDate(?string $date): string
{
    if ($date === null || $date === '') {
        return '—';
    }
    $ts = strtotime($date);
    return $ts !== false ? date('M d, Y g:i A', $ts) : $date;
}

function formatAdminDateShort(?string $date): string
{
    if ($date === null || $date === '') {
        return '—';
    }
    $ts = strtotime($date);
    return $ts !== false ? date('M d, Y', $ts) : $date;
}

function adminName(array $row): string
{
    $parts = array_filter([
        $row['first_name']  ?? '',
        $row['middle_name'] ?? '',
        $row['last_name']   ?? '',
    ], static fn($v) => trim((string)$v) !== '');

    return trim(implode(' ', $parts)) ?: '—';
}

function adminInitial(array $row): string
{
    $first = trim((string)($row['first_name'] ?? ''));
    return strtoupper(substr($first !== '' ? $first : 'A', 0, 1));
}

function adminVerificationBadgeClass(string $status): string
{
    return match ($status) {
        'verified'  => 'badge-success',
        'pending'   => 'badge-warning',
        'denied'    => 'badge-danger',
        'suspended' => 'badge-secondary',
        default     => 'badge-secondary',
    };
}

function adminVerificationLabel(string $status): string
{
    return match ($status) {
        'verified'  => 'Verified',
        'pending'   => 'Pending',
        'denied'    => 'Denied',
        'suspended' => 'Suspended',
        default     => ucfirst($status),
    };
}

function adminOrderStatusBadgeClass(string $status): string
{
    return match ($status) {
        'pending'    => 'badge-warning',
        'preparing'  => 'badge-info',
        'picking_up' => 'badge-info',
        'rider_pending' => 'badge-info',
        'delivering' => 'badge-primary',
        'delivered'  => 'badge-success',
        'cancelled'  => 'badge-danger',
        'refunded'   => 'badge-secondary',
        default      => 'badge-secondary',
    };
}

function adminOrderStatusLabel(string $status): string
{
    return match ($status) {
        'pending'    => 'Pending',
        'preparing'  => 'Preparing',
        'rider_pending' => 'Rider Pending',
        'picking_up' => 'Picking Up',
        'delivering' => 'For Delivery',
        'delivered'  => 'Delivered',
        'cancelled'  => 'Cancelled',
        'refunded'   => 'Refunded',
        default      => ucfirst($status),
    };
}

function adminRoleLabel(string $role): string
{
    return match ($role) {
        'super_admin' => 'Super Admin',
        'manager'     => 'Manager',
        'support'     => 'Support',
        'owner'       => 'Owner',
        'partner'     => 'Partner',
        'cashier'     => 'Cashier',
        'kitchen'     => 'Kitchen',
        default       => ucwords(str_replace('_', ' ', $role)),
    };
}

function formatRiderAddress(?array $address): string
{
    if (!$address) {
        return '—';
    }
    $parts = array_filter([
        $address['block']       ?? '',
        $address['barangay']    ?? '',
        $address['city']        ?? '',
        $address['province']    ?? '',
        $address['region']      ?? '',
        $address['postal_code'] ?? '',
        $address['country']     ?? '',
    ], static fn($v) => trim((string)$v) !== '');

    return implode(', ', $parts) ?: '—';
}

function formatCustomerAddress(array $address): string
{
    return formatRiderAddress($address);
}

function parseAdminTagList(?string $raw): array
{
    if ($raw === null || trim($raw) === '') {
        return [];
    }
    return array_values(array_filter(
        array_map('trim', explode(',', $raw)),
        static fn($v) => $v !== '' && strtolower($v) !== 'none'
    ));
}

function adminAssetUrl(string $assetBase, string $relativePath): string
{
    if ($relativePath === '') {
        return '';
    }

    $projectRoot = preg_replace('#shared/$#', '', $assetBase);
    if (!is_string($projectRoot)) {
        $projectRoot = '';
    }

    return $projectRoot . $relativePath;
}