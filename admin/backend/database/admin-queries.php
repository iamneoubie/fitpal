<?php
/**
 * FitPal Admin Database Queries
 *
 * Pure data-access layer for the administrator and administrator_profile
 * tables, plus platform-wide read helpers used by admin dashboards and
 * management pages.
 *
 * This file is safe to require from any page — it only declares
 * functions and performs no request dispatch at load time.
 *
 * NOTE: All SQL for the admin role belongs here. Admin handlers and
 *       pages must not contain raw SQL.
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

/* ---------------------------------------------------------------
 * AUTHENTICATION
 * --------------------------------------------------------------- */

/**
 * Find an administrator by email or username.
 *
 * The email and username lookups use two distinct placeholders
 * (:email and :username) even though both receive the same value.
 * MySQL's native prepared-statement protocol cannot reuse a named
 * placeholder, so a shared :identifier would trigger HY093.
 *
 * @param PDO $db
 * @param string $identifier
 * @return array<string, mixed>|false
 */
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
    $stmt->execute([
        ':email'    => $identifier,
        ':username' => $identifier,
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Check if the administrator account is active.
 *
 * @param PDO $db
 * @param int $adminId
 * @return bool
 */
function isAdminActive(PDO $db, int $adminId): bool
{
    $stmt = $db->prepare(
        "SELECT is_active FROM administrator WHERE administrator_id = ?"
    );
    $stmt->execute([$adminId]);
    return (bool)$stmt->fetchColumn();
}

/* ---------------------------------------------------------------
 * PROFILE
 * --------------------------------------------------------------- */

/**
 * Get an administrator's full profile including role and permissions.
 *
 * @param PDO $db
 * @param int $adminId
 * @return array<string, mixed>|false
 */
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
            a.is_active,
            a.date_created,
            ap.role,
            ap.permissions,
            ap.last_login
         FROM administrator a
         LEFT JOIN administrator_profile ap
                ON a.administrator_id = ap.administrator_id
         WHERE a.administrator_id = :admin_id
         LIMIT 1"
    );
    $stmt->execute([':admin_id' => $adminId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Update an administrator's last login timestamp.
 *
 * @param PDO $db
 * @param int $adminId
 * @return void
 */
function updateAdminLastLogin(PDO $db, int $adminId): void
{
    $stmt = $db->prepare(
        "UPDATE administrator_profile
            SET last_login = NOW()
          WHERE administrator_id = :admin_id"
    );
    $stmt->execute([':admin_id' => $adminId]);
}

/* ---------------------------------------------------------------
 * PRESENTATION HELPERS (pure — no DB access)
 * --------------------------------------------------------------- */

/**
 * Format a monetary amount as Philippine pesos.
 *
 * @param int|float|string|null $amount
 * @return string
 */
function formatAdminCurrency(int|float|string|null $amount): string
{
    return '₱' . number_format((float)($amount ?? 0), 2);
}

/**
 * Return a human-readable label for an admin role slug.
 *
 * @param string $role
 * @return string
 */
function getAdminRoleLabel(string $role): string
{
    return match ($role) {
        'super_admin' => 'Super Admin',
        'manager'     => 'Manager',
        'support'     => 'Support',
        default       => ucfirst(str_replace('_', ' ', $role)),
    };
}

/* ---------------------------------------------------------------
 * PLATFORM COUNTS
 * --------------------------------------------------------------- */

/**
 * Return counts of customers, restaurants, and riders for the
 * dashboard stat cards.
 *
 * @param PDO $db
 * @return array{customers:int, restaurants:int, riders:int, orders:int}
 */
function getAdminPlatformCounts(PDO $db): array
{
    return [
        'customers'   => (int)$db->query(
            "SELECT COUNT(*) FROM customer"
        )->fetchColumn(),
        'restaurants' => (int)$db->query(
            "SELECT COUNT(*) FROM restaurant"
        )->fetchColumn(),
        'riders'      => (int)$db->query(
            "SELECT COUNT(*) FROM delivery_rider"
        )->fetchColumn(),
        'orders'      => (int)$db->query(
            "SELECT COUNT(*) FROM orders"
        )->fetchColumn(),
    ];
}

/* ---------------------------------------------------------------
 * CUSTOMERS
 * --------------------------------------------------------------- */

/**
 * Paginated customer list with profile and order count.
 *
 * @param PDO    $db
 * @param string $search   Matches first/last name, email, or username
 * @param string $status   'active' | 'inactive' | '' (all)
 * @param int    $page
 * @param int    $perPage
 * @return array{items: array<int, array<string,mixed>>, total:int, totalPages:int, page:int}
 */
function getAdminCustomersPaginated(
    PDO $db,
    string $search = '',
    string $status = '',
    int $page = 1,
    int $perPage = 10
): array {
    if ($page    < 1) $page    = 1;
    if ($perPage < 1) $perPage = 10;

    $where  = [];
    $params = [];

    if ($search !== '') {
        $where[] = "(c.first_name LIKE :search OR c.last_name LIKE :search
                     OR c.email LIKE :search OR c.username LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    if ($status === 'active') {
        $where[] = "c.is_active = 1";
    } elseif ($status === 'inactive') {
        $where[] = "c.is_active = 0";
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Count
    $countStmt = $db->prepare(
        "SELECT COUNT(*) FROM customer c {$whereSql}"
    );
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
    if ($page > $totalPages) $page = $totalPages;

    $offset = ($page - 1) * $perPage;

    // Page
    $stmt = $db->prepare(
        "SELECT
            c.customer_id,
            c.first_name,
            c.middle_name,
            c.last_name,
            c.email,
            c.contact_number,
            c.username,
            c.is_active,
            c.date_created,
            cp.fitness_goal,
            cp.dietary_preferences,
            cp.allergies,
            (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.customer_id) AS order_count
         FROM customer c
         LEFT JOIN customer_profile cp ON cp.customer_id = c.customer_id
         {$whereSql}
         ORDER BY c.date_created DESC, c.customer_id DESC
         LIMIT :lim OFFSET :off"
    );
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
    $stmt->execute();

    return [
        'items'      => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'total'      => $total,
        'totalPages' => $totalPages,
        'page'       => $page,
    ];
}

/**
 * Fetch a single customer with profile, financial account, and
 * address count. Scoped by admin role — no ownership filter.
 *
 * @param PDO $db
 * @param int $customerId
 * @return array<string,mixed>|false
 */
function getAdminCustomerById(PDO $db, int $customerId): array|false
{
    $stmt = $db->prepare(
        "SELECT
            c.customer_id, c.first_name, c.middle_name, c.last_name,
            c.email, c.contact_number, c.username, c.birthdate, c.gender,
            c.is_active, c.date_created,
            cp.fitness_goal, cp.dietary_preferences, cp.allergies,
            cp.height_cm, cp.weight_kg,
            fa.balance,
            (SELECT COUNT(*) FROM customer_address ca
              WHERE ca.customer_id = c.customer_id) AS address_count,
            (SELECT COUNT(*) FROM orders o
              WHERE o.customer_id = c.customer_id) AS order_count
         FROM customer c
         LEFT JOIN customer_profile cp ON cp.customer_id = c.customer_id
         LEFT JOIN financial_account fa ON fa.financial_account_id = cp.financial_account_id
         WHERE c.customer_id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $customerId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Toggle a customer's is_active flag.
 *
 * @param PDO  $db
 * @param int  $customerId
 * @param bool $active
 * @return bool  True if a row was changed
 */
function setCustomerActive(PDO $db, int $customerId, bool $active): bool
{
    $stmt = $db->prepare(
        "UPDATE customer SET is_active = :active WHERE customer_id = :id"
    );
    $stmt->execute([
        ':active' => $active ? 1 : 0,
        ':id'     => $customerId,
    ]);
    return $stmt->rowCount() > 0;
}

/* ---------------------------------------------------------------
 * RESTAURANTS
 * --------------------------------------------------------------- */

/**
 * Paginated restaurant list with branch and product counts.
 *
 * @param PDO    $db
 * @param string $search
 * @param string $status   '' | 'pending' | 'verified' | 'denied' | 'suspended'
 * @param int    $page
 * @param int    $perPage
 * @return array{items: array<int,array<string,mixed>>, total:int, totalPages:int, page:int}
 */
function getAdminRestaurantsPaginated(
    PDO $db,
    string $search = '',
    string $status = '',
    int $page = 1,
    int $perPage = 10
): array {
    if ($page    < 1) $page    = 1;
    if ($perPage < 1) $perPage = 10;

    $where  = [];
    $params = [];

    if ($search !== '') {
        $where[] = "(r.business_name LIKE :search OR r.cuisine_type LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    $allowedStatuses = ['pending', 'verified', 'denied', 'suspended'];
    if (in_array($status, $allowedStatuses, true)) {
        $where[] = "r.verification_status = :status";
        $params[':status'] = $status;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countStmt = $db->prepare(
        "SELECT COUNT(*) FROM restaurant r {$whereSql}"
    );
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
    if ($page > $totalPages) $page = $totalPages;

    $offset = ($page - 1) * $perPage;

    $stmt = $db->prepare(
        "SELECT
            r.restaurant_id,
            r.business_name,
            r.cuisine_type,
            r.dietary_tags,
            r.verification_status,
            r.is_active,
            r.created_at,
            (SELECT COUNT(*) FROM restaurant_branch rb
              WHERE rb.restaurant_id = r.restaurant_id) AS branch_count,
            (SELECT COUNT(*) FROM product p
              JOIN restaurant_branch rb2 ON rb2.restaurant_branch_id = p.restaurant_branch_id
              WHERE rb2.restaurant_id = r.restaurant_id) AS product_count
         FROM restaurant r
         {$whereSql}
         ORDER BY r.created_at DESC, r.restaurant_id DESC
         LIMIT :lim OFFSET :off"
    );
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
    $stmt->execute();

    return [
        'items'      => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'total'      => $total,
        'totalPages' => $totalPages,
        'page'       => $page,
    ];
}

/**
 * Fetch one restaurant with its branches.
 *
 * @param PDO $db
 * @param int $restaurantId
 * @return array<string,mixed>|false
 */
function getAdminRestaurantById(PDO $db, int $restaurantId): array|false
{
    $stmt = $db->prepare(
        "SELECT
            r.restaurant_id, r.business_name, r.description, r.cuisine_type,
            r.dietary_tags, r.verification_status, r.is_active, r.created_at,
            a.first_name AS verified_by_first,
            a.last_name  AS verified_by_last
         FROM restaurant r
         LEFT JOIN administrator a ON a.administrator_id = r.verified_by_admin_id
         WHERE r.restaurant_id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $restaurantId]);
    $restaurant = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$restaurant) return false;

    $branchStmt = $db->prepare(
        "SELECT
            rb.restaurant_branch_id, rb.branch_name, rb.branch_code,
            rb.block, rb.barangay, rb.city, rb.province, rb.region,
            rb.postal_code, rb.country, rb.is_active,
            (SELECT COUNT(*) FROM product p
              WHERE p.restaurant_branch_id = rb.restaurant_branch_id) AS product_count
         FROM restaurant_branch rb
         WHERE rb.restaurant_id = :id
         ORDER BY rb.branch_name"
    );
    $branchStmt->execute([':id' => $restaurantId]);
    $restaurant['branches'] = $branchStmt->fetchAll(PDO::FETCH_ASSOC);

    return $restaurant;
}

/**
 * Update a restaurant's verification_status. Also records which admin
 * verified it and when.
 *
 * @param PDO    $db
 * @param int    $restaurantId
 * @param string $status
 * @param int    $adminId
 * @return bool
 */
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
            SET verification_status = :status,
                verified_by_admin_id = :admin_id,
                verified_at = NOW()
          WHERE restaurant_id = :id"
    );
    $stmt->execute([
        ':status'   => $status,
        ':admin_id' => $adminId,
        ':id'       => $restaurantId,
    ]);
    return $stmt->rowCount() > 0;
}

/**
 * Toggle a restaurant's is_active flag.
 *
 * @param PDO  $db
 * @param int  $restaurantId
 * @param bool $active
 * @return bool
 */
function setRestaurantActive(PDO $db, int $restaurantId, bool $active): bool
{
    $stmt = $db->prepare(
        "UPDATE restaurant SET is_active = :active WHERE restaurant_id = :id"
    );
    $stmt->execute([
        ':active' => $active ? 1 : 0,
        ':id'     => $restaurantId,
    ]);
    return $stmt->rowCount() > 0;
}

/* ---------------------------------------------------------------
 * RIDERS
 * --------------------------------------------------------------- */

/**
 * Paginated rider list with profile info.
 *
 * @param PDO    $db
 * @param string $search
 * @param string $status   '' | 'pending' | 'verified' | 'denied' | 'suspended'
 * @param int    $page
 * @param int    $perPage
 * @return array{items: array<int,array<string,mixed>>, total:int, totalPages:int, page:int}
 */
function getAdminRidersPaginated(
    PDO $db,
    string $search = '',
    string $status = '',
    int $page = 1,
    int $perPage = 10
): array {
    if ($page    < 1) $page    = 1;
    if ($perPage < 1) $perPage = 10;

    $where  = [];
    $params = [];

    if ($search !== '') {
        $where[] = "(dr.first_name LIKE :search OR dr.last_name LIKE :search
                     OR dr.email LIKE :search OR dr.username LIKE :search
                     OR dr.contact_number LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    $allowedStatuses = ['pending', 'verified', 'denied', 'suspended'];
    if (in_array($status, $allowedStatuses, true)) {
        $where[] = "drp.verification_status = :status";
        $params[':status'] = $status;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countStmt = $db->prepare(
        "SELECT COUNT(*)
         FROM delivery_rider dr
         LEFT JOIN delivery_rider_profile drp
                ON drp.delivery_rider_id = dr.delivery_rider_id
         {$whereSql}"
    );
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
    if ($page > $totalPages) $page = $totalPages;

    $offset = ($page - 1) * $perPage;

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
            drp.vehicle_type,
            drp.vehicle_plate,
            drp.verification_status,
            drp.average_rating,
            drp.total_deliveries,
            drp.is_available
         FROM delivery_rider dr
         LEFT JOIN delivery_rider_profile drp
                ON drp.delivery_rider_id = dr.delivery_rider_id
         {$whereSql}
         ORDER BY dr.date_created DESC, dr.delivery_rider_id DESC
         LIMIT :lim OFFSET :off"
    );
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
    $stmt->execute();

    return [
        'items'      => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'total'      => $total,
        'totalPages' => $totalPages,
        'page'       => $page,
    ];
}

/**
 * Fetch one rider with profile, address, and financial account.
 *
 * @param PDO $db
 * @param int $riderId
 * @return array<string,mixed>|false
 */
function getAdminRiderById(PDO $db, int $riderId): array|false
{
    $stmt = $db->prepare(
        "SELECT
            dr.delivery_rider_id, dr.first_name, dr.middle_name, dr.last_name,
            dr.email, dr.contact_number, dr.username, dr.birthdate, dr.gender,
            dr.is_active, dr.date_created,
            drp.vehicle_type, drp.vehicle_plate, drp.verification_status,
            drp.average_rating, drp.total_deliveries, drp.is_available,
            drp.verified_at,
            fa.balance
         FROM delivery_rider dr
         LEFT JOIN delivery_rider_profile drp
                ON drp.delivery_rider_id = dr.delivery_rider_id
         LEFT JOIN financial_account fa
                ON fa.financial_account_id = drp.financial_account_id
         WHERE dr.delivery_rider_id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $riderId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Update a rider's verification_status. Also records which admin
 * verified it and when.
 *
 * @param PDO    $db
 * @param int    $riderId
 * @param string $status
 * @param int    $adminId
 * @return bool
 */
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
            SET verification_status = :status,
                verified_by_admin_id = :admin_id,
                verified_at = NOW()
          WHERE delivery_rider_id = :id"
    );
    $stmt->execute([
        ':status'   => $status,
        ':admin_id' => $adminId,
        ':id'       => $riderId,
    ]);
    return $stmt->rowCount() > 0;
}

/**
 * Toggle a rider's is_active flag.
 *
 * @param PDO  $db
 * @param int  $riderId
 * @param bool $active
 * @return bool
 */
function setRiderActive(PDO $db, int $riderId, bool $active): bool
{
    $stmt = $db->prepare(
        "UPDATE delivery_rider SET is_active = :active WHERE delivery_rider_id = :id"
    );
    $stmt->execute([
        ':active' => $active ? 1 : 0,
        ':id'     => $riderId,
    ]);
    return $stmt->rowCount() > 0;
}

/* ---------------------------------------------------------------
 * PRESENTATION HELPERS
 * --------------------------------------------------------------- */

/**
 * Verification status → CSS badge class.
 *
 * @param string $status
 * @return string
 */
function getVerificationBadgeClass(string $status): string
{
    return match ($status) {
        'verified'  => 'badge-success',
        'pending'   => 'badge-warning',
        'denied'    => 'badge-danger',
        'suspended' => 'badge-secondary',
        default     => 'badge-secondary',
    };
}

/**
 * Verification status → display label.
 *
 * @param string $status
 * @return string
 */
function getVerificationLabel(string $status): string
{
    return match ($status) {
        'verified'  => 'Verified',
        'pending'   => 'Pending',
        'denied'    => 'Denied',
        'suspended' => 'Suspended',
        default     => ucfirst($status),
    };
}

/**
 * Build a query string preserving current filters, overriding the
 * given keys.
 *
 * @param array<string,mixed> $overrides
 * @return string
 */
function adminBuildQuery(array $overrides = []): string
{
    $params = $_GET;
    unset($params['page']);

    foreach ($overrides as $k => $v) {
        if ($v === '' || $v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }

    return $params ? ('?' . http_build_query($params)) : '';
}

/* ---------------------------------------------------------------
 * SUMMARY COUNTS PER LIST PAGE
 * --------------------------------------------------------------- */

/**
 * Customer status counts for the summary strip.
 *
 * @param PDO $db
 * @return array{total:int, active:int, inactive:int}
 */
function getCustomerStatusCounts(PDO $db): array
{
    $row = $db->query(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS inactive
         FROM customer"
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total'    => (int)($row['total']    ?? 0),
        'active'   => (int)($row['active']   ?? 0),
        'inactive' => (int)($row['inactive'] ?? 0),
    ];
}

/**
 * Restaurant verification status counts.
 *
 * @param PDO $db
 * @return array{total:int, pending:int, verified:int, denied:int, suspended:int}
 */
function getRestaurantStatusCounts(PDO $db): array
{
    $row = $db->query(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN verification_status = 'pending'   THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN verification_status = 'verified'  THEN 1 ELSE 0 END) AS verified,
            SUM(CASE WHEN verification_status = 'denied'    THEN 1 ELSE 0 END) AS denied,
            SUM(CASE WHEN verification_status = 'suspended' THEN 1 ELSE 0 END) AS suspended
         FROM restaurant"
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total'     => (int)($row['total']     ?? 0),
        'pending'   => (int)($row['pending']   ?? 0),
        'verified'  => (int)($row['verified']  ?? 0),
        'denied'    => (int)($row['denied']    ?? 0),
        'suspended' => (int)($row['suspended'] ?? 0),
    ];
}

/**
 * Rider verification status counts.
 *
 * @param PDO $db
 * @return array{total:int, pending:int, verified:int, denied:int, suspended:int}
 */
function getRiderStatusCounts(PDO $db): array
{
    $row = $db->query(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN drp.verification_status = 'pending'   THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN drp.verification_status = 'verified'  THEN 1 ELSE 0 END) AS verified,
            SUM(CASE WHEN drp.verification_status = 'denied'    THEN 1 ELSE 0 END) AS denied,
            SUM(CASE WHEN drp.verification_status = 'suspended' THEN 1 ELSE 0 END) AS suspended
         FROM delivery_rider dr
         LEFT JOIN delivery_rider_profile drp
                ON drp.delivery_rider_id = dr.delivery_rider_id"
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total'     => (int)($row['total']     ?? 0),
        'pending'   => (int)($row['pending']   ?? 0),
        'verified'  => (int)($row['verified']  ?? 0),
        'denied'    => (int)($row['denied']    ?? 0),
        'suspended' => (int)($row['suspended'] ?? 0),
    ];
}

/* ---------------------------------------------------------------
 * PAGINATION HELPER
 * --------------------------------------------------------------- */

/**
 * Return the numeric page window with ellipsis markers.
 *
 * Given $page = 7, $totalPages = 20, $window = 5:
 *   [1, '…', 5, 6, 7, 8, 9, '…', 20]
 *
 * The returned array contains ints and the literal string '…'.
 *
 * @param int $page
 * @param int $totalPages
 * @param int $window
 * @return array<int, int|string>
 */
function buildAdminPageWindow(int $page, int $totalPages, int $window = 5): array
{
    if ($totalPages <= 1) return [];

    if ($window < 3) $window = 3;

    // If everything fits, return 1..totalPages.
    if ($totalPages <= $window + 2) {
        return range(1, $totalPages);
    }

    $half = (int)floor($window / 2);

    $start = max(1, $page - $half);
    $end   = min($totalPages, $start + $window - 1);

    // Push the window to the right if we bumped into the left edge.
    if ($end - $start + 1 < $window) {
        $start = max(1, $end - $window + 1);
    }

    $out = [];

    if ($start > 1) {
        $out[] = 1;
        if ($start > 2) $out[] = '…';
    }

    for ($i = $start; $i <= $end; $i++) {
        $out[] = $i;
    }

    if ($end < $totalPages) {
        if ($end < $totalPages - 1) $out[] = '…';
        $out[] = $totalPages;
    }

    return $out;
}