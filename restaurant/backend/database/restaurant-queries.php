<?php
/**
 * FitPal Restaurant Database Queries
 *
 * Pure data-access layer for restaurant, restaurant_account,
 * restaurant_branch, restaurant_permit, and read-only dashboard
 * stats over orders / queue_item.
 *
 * No $_POST, no header(), no echo.
 *
 * @package FitPal
 * @version 3.0 — Adds profile mutation functions.
 */

declare(strict_types=1);

/* =============================================================
 * AUTHENTICATION
 * ============================================================= */

function findRestaurantAccountByIdentifier(PDO $db, string $identifier): array|false
{
    $stmt = $db->prepare(
        "SELECT
            ra.restaurant_account_id,
            ra.restaurant_id,
            ra.branch_id,
            ra.first_name,
            ra.middle_name,
            ra.last_name,
            ra.email,
            ra.username,
            ra.password,
            ra.role,
            ra.is_active,
            r.business_name,
            r.verification_status,
            r.is_active AS restaurant_active
         FROM restaurant_account ra
         JOIN restaurant r ON ra.restaurant_id = r.restaurant_id
         WHERE ra.email = :email OR ra.username = :username
         LIMIT 1"
    );
    $stmt->execute([
        ':email'    => $identifier,
        ':username' => $identifier,
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function findRestaurantAccountByScope(
    PDO $db,
    string $identifier,
    string $roleScope
): array|false {
    $ownerRoles  = ['owner', 'partner'];
    $branchRoles = ['manager', 'staff', 'cashier', 'kitchen'];

    if ($roleScope === 'owner') {
        $roles = $ownerRoles;
    } elseif ($roleScope === 'branch') {
        $roles = $branchRoles;
    } else {
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($roles), '?'));

    $sql = "SELECT
                ra.restaurant_account_id,
                ra.restaurant_id,
                ra.branch_id,
                ra.first_name,
                ra.middle_name,
                ra.last_name,
                ra.email,
                ra.username,
                ra.password,
                ra.role,
                ra.is_active,
                r.business_name,
                r.verification_status,
                r.is_active AS restaurant_active,
                rb.branch_name,
                rb.branch_code,
                rb.city AS branch_city
            FROM restaurant_account ra
            JOIN restaurant r ON ra.restaurant_id = r.restaurant_id
            LEFT JOIN restaurant_branch rb ON ra.branch_id = rb.restaurant_branch_id
            WHERE (ra.email = ? OR ra.username = ?)
              AND ra.role IN ($placeholders)
            LIMIT 1";

    $params = array_merge([$identifier, $identifier], $roles);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function findBranchAccountByIdentifierAndCode(
    PDO $db,
    string $identifier,
    string $branchCode
): array|false {
    $stmt = $db->prepare(
        "SELECT
            ra.restaurant_account_id,
            ra.restaurant_id,
            ra.branch_id,
            ra.first_name,
            ra.middle_name,
            ra.last_name,
            ra.email,
            ra.username,
            ra.password,
            ra.role,
            ra.is_active,
            r.business_name,
            r.verification_status,
            r.is_active AS restaurant_active,
            rb.branch_name,
            rb.branch_code,
            rb.city AS branch_city
         FROM restaurant_account ra
         JOIN restaurant r ON ra.restaurant_id = r.restaurant_id
         JOIN restaurant_branch rb ON ra.branch_id = rb.restaurant_branch_id
         WHERE (ra.email = :email OR ra.username = :username)
           AND rb.branch_code = :branch_code
           AND ra.role IN ('manager','staff','cashier','kitchen')
         LIMIT 1"
    );
    $stmt->execute([
        ':email'       => $identifier,
        ':username'    => $identifier,
        ':branch_code' => $branchCode,
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getBranchesWithAccounts(PDO $db): array
{
    $stmt = $db->query(
        "SELECT DISTINCT
            rb.restaurant_branch_id,
            rb.branch_code,
            rb.branch_name,
            rb.city,
            r.business_name
         FROM restaurant_branch rb
         JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
         JOIN restaurant_account ra ON ra.branch_id = rb.restaurant_branch_id
         WHERE rb.is_active = 1
           AND r.is_active = 1
           AND ra.is_active = 1
           AND ra.role IN ('manager','staff','cashier','kitchen')
         ORDER BY r.business_name ASC, rb.branch_name ASC"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getRestaurantAccountProfile(PDO $db, int $accountId): array|false
{
    $stmt = $db->prepare(
        "SELECT
            ra.restaurant_account_id,
            ra.restaurant_id,
            ra.branch_id,
            ra.first_name,
            ra.middle_name,
            ra.last_name,
            ra.email,
            ra.username,
            ra.contact_number,
            ra.role,
            ra.is_active,
            ra.date_created,
            r.business_name,
            r.cuisine_type,
            r.description AS restaurant_description,
            r.dietary_tags,
            r.verification_status,
            r.verified_at,
            r.is_active AS restaurant_active,
            rb.branch_name,
            rb.branch_code,
            rb.block,
            rb.barangay,
            rb.city,
            rb.province,
            rb.region,
            rb.postal_code,
            rb.country
         FROM restaurant_account ra
         JOIN restaurant r ON ra.restaurant_id = r.restaurant_id
         LEFT JOIN restaurant_branch rb ON ra.branch_id = rb.restaurant_branch_id
         WHERE ra.restaurant_account_id = :account_id
         LIMIT 1"
    );
    $stmt->execute([':account_id' => $accountId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getRestaurantAccountWithPassword(PDO $db, int $accountId): array|false
{
    $stmt = $db->prepare(
        "SELECT restaurant_account_id, password, role
         FROM restaurant_account
         WHERE restaurant_account_id = :account_id
         LIMIT 1"
    );
    $stmt->execute([':account_id' => $accountId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function recordRestaurantLogin(PDO $db, int $accountId): void
{
    $stmt = $db->prepare(
        "UPDATE restaurant r
         JOIN restaurant_account ra ON r.restaurant_id = ra.restaurant_id
            SET r.updated_at = NOW()
          WHERE ra.restaurant_account_id = :account_id"
    );
    $stmt->execute([':account_id' => $accountId]);
}

/* =============================================================
 * UNIQUENESS CHECKS
 * ============================================================= */

function restaurantEmailExists(PDO $db, string $email): bool
{
    $stmt = $db->prepare("SELECT 1 FROM restaurant_account WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    return $stmt->fetch() !== false;
}

function restaurantUsernameExists(PDO $db, string $username): bool
{
    $stmt = $db->prepare("SELECT 1 FROM restaurant_account WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    return $stmt->fetch() !== false;
}

function restaurantContactExists(PDO $db, string $contact): bool
{
    $stmt = $db->prepare("SELECT 1 FROM restaurant_account WHERE contact_number = ? LIMIT 1");
    $stmt->execute([$contact]);
    return $stmt->fetch() !== false;
}

function restaurantBusinessNameExists(PDO $db, string $name): bool
{
    $stmt = $db->prepare("SELECT 1 FROM restaurant WHERE business_name = ? LIMIT 1");
    $stmt->execute([$name]);
    return $stmt->fetch() !== false;
}

function restaurantBranchCodeExists(PDO $db, string $code): bool
{
    $stmt = $db->prepare("SELECT 1 FROM restaurant_branch WHERE branch_code = ? LIMIT 1");
    $stmt->execute([$code]);
    return $stmt->fetch() !== false;
}

/* =============================================================
 * CREATION
 * ============================================================= */

function createRestaurant(PDO $db, array $data): int
{
    $stmt = $db->prepare(
        "INSERT INTO restaurant
            (business_name, description, cuisine_type, dietary_tags,
             verification_status, is_active)
         VALUES
            (:business_name, :description, :cuisine_type, :dietary_tags,
             'pending', 1)"
    );
    $stmt->execute([
        ':business_name' => $data['business_name'],
        ':description'   => $data['description']  !== '' ? $data['description']  : null,
        ':cuisine_type'  => $data['cuisine_type'] !== '' ? $data['cuisine_type'] : null,
        ':dietary_tags'  => $data['dietary_tags'] !== '' ? $data['dietary_tags'] : null,
    ]);
    return (int)$db->lastInsertId();
}

function createRestaurantFinancialAccount(PDO $db): int
{
    $stmt = $db->prepare(
        "INSERT INTO financial_account (balance, account_type)
         VALUES (0.00, 'restaurant')"
    );
    $stmt->execute();
    return (int)$db->lastInsertId();
}

function createRestaurantBranch(
    PDO $db,
    int $restaurantId,
    int $financialAccountId,
    array $data
): int {
    $stmt = $db->prepare(
        "INSERT INTO restaurant_branch
            (restaurant_id, financial_account_id, branch_name, branch_code,
             block, barangay, city, province, region, postal_code, country,
             is_active)
         VALUES
            (:restaurant_id, :financial_account_id, :branch_name, :branch_code,
             :block, :barangay, :city, :province, :region, :postal_code,
             'Philippines', 1)"
    );
    $stmt->execute([
        ':restaurant_id'        => $restaurantId,
        ':financial_account_id' => $financialAccountId,
        ':branch_name'          => $data['branch_name'],
        ':branch_code'          => $data['branch_code'],
        ':block'                => $data['block']       !== '' ? $data['block']       : null,
        ':barangay'             => $data['barangay']    !== '' ? $data['barangay']    : null,
        ':city'                 => $data['city'],
        ':province'             => $data['province']    !== '' ? $data['province']    : null,
        ':region'               => $data['region']      !== '' ? $data['region']      : null,
        ':postal_code'          => $data['postal_code'] !== '' ? $data['postal_code'] : null,
    ]);
    return (int)$db->lastInsertId();
}

function createRestaurantOwnerAccount(PDO $db, int $restaurantId, array $data): int
{
    $stmt = $db->prepare(
        "INSERT INTO restaurant_account
            (restaurant_id, branch_id, first_name, middle_name, last_name,
             email, contact_number, username, password, role, is_active)
         VALUES
            (:restaurant_id, NULL, :first_name, :middle_name, :last_name,
             :email, :contact_number, :username, :password, 'owner', 1)"
    );
    $stmt->execute([
        ':restaurant_id'  => $restaurantId,
        ':first_name'     => $data['first_name'],
        ':middle_name'    => $data['middle_name'] !== '' ? $data['middle_name'] : null,
        ':last_name'      => $data['last_name'],
        ':email'          => $data['email'],
        ':contact_number' => $data['contact_number'],
        ':username'       => $data['username'],
        ':password'       => $data['password'],
    ]);
    return (int)$db->lastInsertId();
}

/* =============================================================
 * PERMIT HANDLING
 * ============================================================= */

function createRestaurantPermit(
    PDO $db,
    int $restaurantId,
    string $filePath,
    string $originalName,
    int $order
): int {
    $stmt = $db->prepare(
        "INSERT INTO restaurant_permit
            (restaurant_id, file_path, original_name, display_order)
         VALUES
            (:restaurant_id, :file_path, :original_name, :display_order)"
    );
    $stmt->execute([
        ':restaurant_id' => $restaurantId,
        ':file_path'     => $filePath,
        ':original_name' => $originalName !== '' ? $originalName : null,
        ':display_order' => $order,
    ]);
    return (int)$db->lastInsertId();
}

function getRestaurantPermits(PDO $db, int $restaurantId): array
{
    $stmt = $db->prepare(
        "SELECT permit_id, file_path, original_name, display_order, created_at
         FROM restaurant_permit
         WHERE restaurant_id = :restaurant_id
         ORDER BY display_order ASC, permit_id ASC"
    );
    $stmt->execute([':restaurant_id' => $restaurantId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =============================================================
 * GENERATION HELPERS
 * ============================================================= */

function generateBranchCode(PDO $db, string $businessName): string
{
    $clean = preg_replace('/[^A-Za-z0-9 ]/', '', $businessName) ?? '';
    $words = preg_split('/\s+/', trim($clean)) ?: [];
    $initials = '';
    foreach ($words as $w) {
        if ($w !== '' && strlen($initials) < 3) {
            $initials .= strtoupper($w[0]);
        }
    }
    if ($initials === '') {
        $initials = 'RST';
    }

    $base = str_pad(substr($initials, 0, 3), 3, 'X');

    for ($i = 1; $i <= 999; $i++) {
        $candidate = $base . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
        if (!restaurantBranchCodeExists($db, $candidate)) {
            return $candidate;
        }
    }

    return $base . bin2hex(random_bytes(2));
}

/* =============================================================
 * PROFILE MUTATIONS
 * ============================================================= */

function updateRestaurantAccountContact(
    PDO $db,
    int $accountId,
    string $contactNumber
): bool {
    $stmt = $db->prepare(
        "UPDATE restaurant_account
            SET contact_number = :contact_number
          WHERE restaurant_account_id = :account_id"
    );
    $stmt->execute([
        ':contact_number' => $contactNumber !== '' ? $contactNumber : null,
        ':account_id'     => $accountId,
    ]);
    return true;
}

function updateRestaurantBusinessInfo(PDO $db, int $restaurantId, array $data): bool
{
    $stmt = $db->prepare(
        "UPDATE restaurant
            SET description  = :description,
                cuisine_type = :cuisine_type,
                dietary_tags = :dietary_tags
          WHERE restaurant_id = :restaurant_id"
    );
    $stmt->execute([
        ':description'   => $data['description']  !== '' ? $data['description']  : null,
        ':cuisine_type'  => $data['cuisine_type'] !== '' ? $data['cuisine_type'] : null,
        ':dietary_tags'  => $data['dietary_tags'] !== '' ? $data['dietary_tags'] : null,
        ':restaurant_id' => $restaurantId,
    ]);
    return true;
}

function updateRestaurantBranchAddress(PDO $db, int $branchId, array $data): bool
{
    $stmt = $db->prepare(
        "UPDATE restaurant_branch
            SET block       = :block,
                barangay    = :barangay,
                city        = :city,
                province    = :province,
                region      = :region,
                postal_code = :postal_code
          WHERE restaurant_branch_id = :branch_id"
    );
    $stmt->execute([
        ':block'       => $data['block']       !== '' ? $data['block']       : null,
        ':barangay'    => $data['barangay']    !== '' ? $data['barangay']    : null,
        ':city'        => $data['city'],
        ':province'    => $data['province']    !== '' ? $data['province']    : null,
        ':region'      => $data['region']      !== '' ? $data['region']      : null,
        ':postal_code' => $data['postal_code'] !== '' ? $data['postal_code'] : null,
        ':branch_id'   => $branchId,
    ]);
    return true;
}

function updateRestaurantAccountPassword(
    PDO $db,
    int $accountId,
    string $newHashedPassword
): bool {
    $stmt = $db->prepare(
        "UPDATE restaurant_account
            SET password = :password
          WHERE restaurant_account_id = :account_id"
    );
    $stmt->execute([
        ':password'   => $newHashedPassword,
        ':account_id' => $accountId,
    ]);
    return true;
}

/* =============================================================
 * OWNER DASHBOARD
 * ============================================================= */

function getOwnerDashboardStats(PDO $db, int $restaurantId): array
{
    $stats = [
        'branch_count'        => 0,
        'product_count'       => 0,
        'total_orders'        => 0,
        'orders_today'        => 0,
        'orders_this_week'    => 0,
        'active_orders'       => 0,
        'delivered_orders'    => 0,
        'gross_revenue'       => 0.0,
        'revenue_this_week'   => 0.0,
        'revenue_today'       => 0.0,
        'average_order_value' => 0.0,
    ];

    $row = $db->prepare(
        "SELECT
            (SELECT COUNT(*) FROM restaurant_branch WHERE restaurant_id = :rid) AS branch_count,
            (SELECT COUNT(*) FROM product p
                JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
              WHERE rb.restaurant_id = :rid2 AND p.is_active = 1) AS product_count"
    );
    $row->execute([':rid' => $restaurantId, ':rid2' => $restaurantId]);
    $counts = $row->fetch(PDO::FETCH_ASSOC) ?: [];

    $stats['branch_count']  = (int)($counts['branch_count'] ?? 0);
    $stats['product_count'] = (int)($counts['product_count'] ?? 0);

    $orderRow = $db->prepare(
        "SELECT
            COUNT(DISTINCT o.order_id) AS total_orders,
            SUM(CASE WHEN DATE(o.order_date) = CURDATE() THEN 1 ELSE 0 END) AS orders_today,
            SUM(CASE WHEN o.order_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN 1 ELSE 0 END) AS orders_this_week,
            SUM(CASE WHEN o.order_status IN ('pending','preparing','delivering') THEN 1 ELSE 0 END) AS active_orders,
            SUM(CASE WHEN o.order_status = 'delivered' THEN 1 ELSE 0 END) AS delivered_orders
         FROM orders o
         JOIN queue_item qi ON qi.order_id = o.order_id
         JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
         WHERE rb.restaurant_id = :rid"
    );
    $orderRow->execute([':rid' => $restaurantId]);
    $orders = $orderRow->fetch(PDO::FETCH_ASSOC) ?: [];

    $stats['total_orders']     = (int)($orders['total_orders'] ?? 0);
    $stats['orders_today']     = (int)($orders['orders_today'] ?? 0);
    $stats['orders_this_week'] = (int)($orders['orders_this_week'] ?? 0);
    $stats['active_orders']    = (int)($orders['active_orders'] ?? 0);
    $stats['delivered_orders'] = (int)($orders['delivered_orders'] ?? 0);

    $revRow = $db->prepare(
        "SELECT
            COALESCE(SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)), 0) AS gross_revenue,
            COALESCE(SUM(CASE
                WHEN o.order_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                THEN qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)
                ELSE 0 END), 0) AS revenue_this_week,
            COALESCE(SUM(CASE
                WHEN DATE(o.order_date) = CURDATE()
                THEN qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)
                ELSE 0 END), 0) AS revenue_today
         FROM orders o
         JOIN queue_item qi ON qi.order_id = o.order_id
         JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
         WHERE rb.restaurant_id = :rid
           AND o.order_status NOT IN ('cancelled', 'refunded')"
    );
    $revRow->execute([':rid' => $restaurantId]);
    $rev = $revRow->fetch(PDO::FETCH_ASSOC) ?: [];

    $stats['gross_revenue']     = (float)($rev['gross_revenue'] ?? 0);
    $stats['revenue_this_week'] = (float)($rev['revenue_this_week'] ?? 0);
    $stats['revenue_today']     = (float)($rev['revenue_today'] ?? 0);

    if ($stats['delivered_orders'] > 0) {
        $stats['average_order_value'] =
            round($stats['gross_revenue'] / $stats['delivered_orders'], 2);
    }

    return $stats;
}

function getOwnerWeeklyRevenue(PDO $db, int $restaurantId, int $days = 7): array
{
    $stmt = $db->prepare(
        "SELECT
            DATE(o.order_date) AS day,
            COALESCE(SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)), 0) AS amount,
            COUNT(DISTINCT o.order_id) AS orders
         FROM orders o
         JOIN queue_item qi ON qi.order_id = o.order_id
         JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
         WHERE rb.restaurant_id = :rid
           AND o.order_status NOT IN ('cancelled', 'refunded')
           AND o.order_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
         GROUP BY DATE(o.order_date)
         ORDER BY day ASC"
    );
    $stmt->bindValue(':rid', $restaurantId, PDO::PARAM_INT);
    $stmt->bindValue(':days', $days - 1, PDO::PARAM_INT);
    $stmt->execute();

    $byDay = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $byDay[$r['day']] = [
            'amount' => (float)$r['amount'],
            'orders' => (int)$r['orders'],
        ];
    }

    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $ts   = strtotime("-{$i} days");
        $date = date('Y-m-d', $ts);
        $series[] = [
            'date'   => $date,
            'label'  => date('l', $ts),
            'short'  => date('D', $ts),
            'amount' => $byDay[$date]['amount'] ?? 0.0,
            'orders' => $byDay[$date]['orders'] ?? 0,
        ];
    }
    return $series;
}

function getOwnerBranchOverview(PDO $db, int $restaurantId): array
{
    $stmt = $db->prepare(
        "SELECT
            rb.restaurant_branch_id,
            rb.branch_name,
            rb.branch_code,
            rb.city,
            rb.is_active,
            (SELECT COUNT(*) FROM product p
              WHERE p.restaurant_branch_id = rb.restaurant_branch_id
                AND p.is_active = 1) AS product_count,
            (SELECT COUNT(DISTINCT qi.order_id)
               FROM queue_item qi
               JOIN orders o ON o.order_id = qi.order_id
              WHERE qi.branch_id = rb.restaurant_branch_id
                AND o.order_status NOT IN ('cancelled','refunded')) AS order_count,
            (SELECT COALESCE(SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)), 0)
               FROM queue_item qi
               JOIN orders o ON o.order_id = qi.order_id
              WHERE qi.branch_id = rb.restaurant_branch_id
                AND o.order_status NOT IN ('cancelled','refunded')) AS revenue
         FROM restaurant_branch rb
         WHERE rb.restaurant_id = :rid
         ORDER BY rb.is_active DESC, rb.branch_name ASC"
    );
    $stmt->execute([':rid' => $restaurantId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =============================================================
 * BRANCH DASHBOARD
 * ============================================================= */

function getBranchDashboardStats(PDO $db, int $branchId): array
{
    $stats = [
        'product_count'       => 0,
        'total_orders'        => 0,
        'orders_today'        => 0,
        'orders_this_week'    => 0,
        'active_orders'       => 0,
        'delivered_orders'    => 0,
        'gross_revenue'       => 0.0,
        'revenue_this_week'   => 0.0,
        'revenue_today'       => 0.0,
        'average_order_value' => 0.0,
    ];

    $row = $db->prepare(
        "SELECT COUNT(*) AS product_count
         FROM product
         WHERE restaurant_branch_id = :bid AND is_active = 1"
    );
    $row->execute([':bid' => $branchId]);
    $stats['product_count'] = (int)($row->fetchColumn() ?: 0);

    $orderRow = $db->prepare(
        "SELECT
            COUNT(DISTINCT o.order_id) AS total_orders,
            SUM(CASE WHEN DATE(o.order_date) = CURDATE() THEN 1 ELSE 0 END) AS orders_today,
            SUM(CASE WHEN o.order_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN 1 ELSE 0 END) AS orders_this_week,
            SUM(CASE WHEN o.order_status IN ('pending','preparing','delivering') THEN 1 ELSE 0 END) AS active_orders,
            SUM(CASE WHEN o.order_status = 'delivered' THEN 1 ELSE 0 END) AS delivered_orders
         FROM orders o
         JOIN queue_item qi ON qi.order_id = o.order_id
         WHERE qi.branch_id = :bid"
    );
    $orderRow->execute([':bid' => $branchId]);
    $orders = $orderRow->fetch(PDO::FETCH_ASSOC) ?: [];

    $stats['total_orders']     = (int)($orders['total_orders'] ?? 0);
    $stats['orders_today']     = (int)($orders['orders_today'] ?? 0);
    $stats['orders_this_week'] = (int)($orders['orders_this_week'] ?? 0);
    $stats['active_orders']    = (int)($orders['active_orders'] ?? 0);
    $stats['delivered_orders'] = (int)($orders['delivered_orders'] ?? 0);

    $revRow = $db->prepare(
        "SELECT
            COALESCE(SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)), 0) AS gross_revenue,
            COALESCE(SUM(CASE
                WHEN o.order_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                THEN qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)
                ELSE 0 END), 0) AS revenue_this_week,
            COALESCE(SUM(CASE
                WHEN DATE(o.order_date) = CURDATE()
                THEN qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)
                ELSE 0 END), 0) AS revenue_today
         FROM orders o
         JOIN queue_item qi ON qi.order_id = o.order_id
         WHERE qi.branch_id = :bid
           AND o.order_status NOT IN ('cancelled','refunded')"
    );
    $revRow->execute([':bid' => $branchId]);
    $rev = $revRow->fetch(PDO::FETCH_ASSOC) ?: [];

    $stats['gross_revenue']     = (float)($rev['gross_revenue'] ?? 0);
    $stats['revenue_this_week'] = (float)($rev['revenue_this_week'] ?? 0);
    $stats['revenue_today']     = (float)($rev['revenue_today'] ?? 0);

    if ($stats['delivered_orders'] > 0) {
        $stats['average_order_value'] =
            round($stats['gross_revenue'] / $stats['delivered_orders'], 2);
    }

    return $stats;
}

function getBranchWeeklyRevenue(PDO $db, int $branchId, int $days = 7): array
{
    $stmt = $db->prepare(
        "SELECT
            DATE(o.order_date) AS day,
            COALESCE(SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)), 0) AS amount,
            COUNT(DISTINCT o.order_id) AS orders
         FROM orders o
         JOIN queue_item qi ON qi.order_id = o.order_id
         WHERE qi.branch_id = :bid
           AND o.order_status NOT IN ('cancelled','refunded')
           AND o.order_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
         GROUP BY DATE(o.order_date)
         ORDER BY day ASC"
    );
    $stmt->bindValue(':bid', $branchId, PDO::PARAM_INT);
    $stmt->bindValue(':days', $days - 1, PDO::PARAM_INT);
    $stmt->execute();

    $byDay = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $byDay[$r['day']] = [
            'amount' => (float)$r['amount'],
            'orders' => (int)$r['orders'],
        ];
    }

    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $ts   = strtotime("-{$i} days");
        $date = date('Y-m-d', $ts);
        $series[] = [
            'date'   => $date,
            'label'  => date('l', $ts),
            'short'  => date('D', $ts),
            'amount' => $byDay[$date]['amount'] ?? 0.0,
            'orders' => $byDay[$date]['orders'] ?? 0,
        ];
    }
    return $series;
}

function getBranchTopProducts(PDO $db, int $branchId, int $limit = 5): array
{
    $stmt = $db->prepare(
        "SELECT
            p.product_id,
            p.name AS product_name,
            SUM(qi.queue_quantity) AS units_sold,
            COALESCE(SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)), 0) AS revenue
         FROM queue_item qi
         JOIN product p ON qi.product_id = p.product_id
         JOIN orders o ON qi.order_id = o.order_id
         WHERE qi.branch_id = :bid
           AND o.order_status = 'delivered'
         GROUP BY p.product_id
         ORDER BY units_sold DESC
         LIMIT :lim"
    );
    $stmt->bindValue(':bid', $branchId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =============================================================
 * CHART SCALE
 * ============================================================= */

function getRestaurantChartScale(float $maxAmount): array
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