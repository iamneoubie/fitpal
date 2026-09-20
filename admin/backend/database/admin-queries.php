<?php
/**
 * FitPal Admin Database Queries
 *
 * Every query the admin role needs: authentication, restaurant
 * verification, rider verification, and the dashboard aggregates.
 *
 * Scope: verification review only. Does NOT touch customers, orders,
 * products, financial accounts, or transactions.
 *
 * This file declares functions only. No $_POST, no $_GET, no
 * header(), no echo. Handlers dispatch; pages render; both call
 * these functions.
 *
 * Query discipline: no function here loops over rows to run
 * additional queries. Every aggregate is a single round trip.
 * When a page or handler needs a new shape, add it here rather
 * than embedding SQL elsewhere.
 *
 * Placeholder discipline: the shared PDO connection runs with
 * ATTR_EMULATE_PREPARES = false. MySQL's native prepare does not
 * accept the same named placeholder twice in one statement. When a
 * value is needed in two places, bind it to two distinct names.
 *
 * @package FitPal
 * @version 2.1 — Fixed the repeated-placeholder bug in the admin
 *                lookup. Role now comes from administrator_profile
 *                so the session stores the actual role.
 */

declare(strict_types=1);

/* ---------------------------------------------------------------
 * ADMINISTRATOR / AUTH
 * --------------------------------------------------------------- */

/**
 * Find an administrator by email or username.
 *
 * The `role` column comes from administrator_profile. The sign-in
 * handler stores it in the session so pages can later branch on
 * it. The join is a LEFT JOIN because a profile row may not exist
 * for every account; `role` will be null in that case and the
 * handler falls back to 'support'.
 *
 * Two distinct placeholders are required because the same identifier
 * is compared against two columns. See the file header.
 *
 * @param PDO    $db
 * @param string $identifier
 * @return array<string, mixed>|false
 */
function findAdministratorByIdentifier(PDO $db, string $identifier): array|false
{
    $stmt = $db->prepare(
        "SELECT
            a.administrator_id,
            a.first_name,
            a.last_name,
            a.email,
            a.username,
            a.password,
            a.is_active,
            ap.role
         FROM administrator a
         LEFT JOIN administrator_profile ap
                ON a.administrator_id = ap.administrator_id
         WHERE a.email    = :identifier_email
            OR a.username = :identifier_username
         LIMIT 1"
    );
    $stmt->execute([
        ':identifier_email'    => $identifier,
        ':identifier_username' => $identifier,
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Fetch an administrator's full profile row.
 *
 * @param PDO $db
 * @param int $administratorId
 * @return array<string, mixed>|false
 */
function getAdministratorProfile(PDO $db, int $administratorId): array|false
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
            a.date_created,
            ap.role,
            ap.is_active,
            ap.last_login
         FROM administrator a
         LEFT JOIN administrator_profile ap
                ON a.administrator_id = ap.administrator_id
         WHERE a.administrator_id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $administratorId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Record the administrator's last login timestamp.
 *
 * The profile row may not exist for an account created before the
 * profile pattern was introduced. The UPDATE is a no-op in that
 * case; the sign-in handler treats it as non-fatal.
 *
 * @param PDO $db
 * @param int $administratorId
 * @return void
 */
function touchAdministratorLastLogin(PDO $db, int $administratorId): void
{
    $stmt = $db->prepare(
        "UPDATE administrator_profile
            SET last_login = NOW()
          WHERE administrator_id = :id"
    );
    $stmt->execute([':id' => $administratorId]);
}

/* ---------------------------------------------------------------
 * RESTAURANT VERIFICATION
 * --------------------------------------------------------------- */

/**
 * Fetch restaurants, optionally filtered by verification status,
 * with their branch count and primary owner account.
 *
 * @param PDO         $db
 * @param string|null $statusFilter  pending|verified|denied|suspended, or null for all.
 * @param int         $limit
 * @param int         $offset
 * @return array<int, array<string, mixed>>
 */
function getRestaurantsForVerification(
    PDO $db,
    ?string $statusFilter = null,
    int $limit = 20,
    int $offset = 0
): array {
    $sql = "SELECT
                r.restaurant_id,
                r.business_name,
                r.description,
                r.cuisine_type,
                r.dietary_tags,
                r.verification_status,
                r.verified_by_admin_id,
                r.verified_at,
                r.is_active,
                r.created_at,
                r.updated_at,
                (SELECT COUNT(*) FROM restaurant_branch rb
                  WHERE rb.restaurant_id = r.restaurant_id) AS branch_count,
                (SELECT CONCAT(ra.first_name, ' ', ra.last_name)
                   FROM restaurant_account ra
                  WHERE ra.restaurant_id = r.restaurant_id
                    AND ra.role = 'owner'
                  ORDER BY ra.restaurant_account_id ASC
                  LIMIT 1) AS owner_name,
                (SELECT ra.email
                   FROM restaurant_account ra
                  WHERE ra.restaurant_id = r.restaurant_id
                    AND ra.role = 'owner'
                  ORDER BY ra.restaurant_account_id ASC
                  LIMIT 1) AS owner_email,
                (SELECT ra.contact_number
                   FROM restaurant_account ra
                  WHERE ra.restaurant_id = r.restaurant_id
                    AND ra.role = 'owner'
                  ORDER BY ra.restaurant_account_id ASC
                  LIMIT 1) AS owner_contact
            FROM restaurant r";

    $params = [];

    if ($statusFilter !== null && $statusFilter !== '') {
        $sql .= " WHERE r.verification_status = :status";
        $params[':status'] = $statusFilter;
    }

    $sql .= " ORDER BY
                FIELD(r.verification_status, 'pending', 'suspended', 'denied', 'verified'),
                r.created_at DESC
              LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Count restaurants matching a status filter (for pagination).
 *
 * @param PDO         $db
 * @param string|null $statusFilter
 * @return int
 */
function countRestaurantsForVerification(PDO $db, ?string $statusFilter = null): int
{
    if ($statusFilter === null || $statusFilter === '') {
        return (int)$db->query("SELECT COUNT(*) FROM restaurant")->fetchColumn();
    }

    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM restaurant WHERE verification_status = :status"
    );
    $stmt->execute([':status' => $statusFilter]);
    return (int)$stmt->fetchColumn();
}

/**
 * Fetch a single restaurant with full review context: branches,
 * owner account, and the reviewing admin.
 *
 * @param PDO $db
 * @param int $restaurantId
 * @return array<string, mixed>|false
 */
function getRestaurantForReview(PDO $db, int $restaurantId): array|false
{
    $stmt = $db->prepare(
        "SELECT
            r.restaurant_id,
            r.business_name,
            r.description,
            r.cuisine_type,
            r.dietary_tags,
            r.verification_status,
            r.verified_by_admin_id,
            r.verified_at,
            r.is_active,
            r.created_at,
            r.updated_at,
            a.first_name AS verifier_first_name,
            a.last_name  AS verifier_last_name
         FROM restaurant r
         LEFT JOIN administrator a
                ON r.verified_by_admin_id = a.administrator_id
         WHERE r.restaurant_id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $restaurantId]);
    $restaurant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$restaurant) {
        return false;
    }

    $branchStmt = $db->prepare(
        "SELECT
            restaurant_branch_id,
            branch_name,
            branch_code,
            block,
            barangay,
            city,
            province,
            region,
            postal_code,
            country,
            is_active,
            created_at
         FROM restaurant_branch
         WHERE restaurant_id = :id
         ORDER BY restaurant_branch_id ASC"
    );
    $branchStmt->execute([':id' => $restaurantId]);
    $restaurant['branches'] = $branchStmt->fetchAll(PDO::FETCH_ASSOC);

    $ownerStmt = $db->prepare(
        "SELECT
            restaurant_account_id,
            first_name,
            middle_name,
            last_name,
            email,
            contact_number,
            username,
            role,
            is_active,
            date_created
         FROM restaurant_account
         WHERE restaurant_id = :id
           AND role = 'owner'
         ORDER BY restaurant_account_id ASC
         LIMIT 1"
    );
    $ownerStmt->execute([':id' => $restaurantId]);
    $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);
    $restaurant['owner'] = $owner ?: null;

    return $restaurant;
}

/**
 * Update a restaurant's verification status and record the admin.
 *
 * @param PDO    $db
 * @param int    $restaurantId
 * @param int    $adminId
 * @param string $status  pending|verified|denied|suspended
 * @return bool  True if a row was updated.
 */
function setRestaurantVerificationStatus(
    PDO $db,
    int $restaurantId,
    int $adminId,
    string $status
): bool {
    $allowed = ['pending', 'verified', 'denied', 'suspended'];
    if (!in_array($status, $allowed, true)) {
        return false;
    }

    $stmt = $db->prepare(
        "UPDATE restaurant
            SET verification_status   = :status,
                verified_by_admin_id  = :admin_id,
                verified_at           = NOW(),
                updated_at            = NOW()
          WHERE restaurant_id = :id"
    );
    $stmt->execute([
        ':status'   => $status,
        ':admin_id' => $adminId,
        ':id'       => $restaurantId,
    ]);

    return $stmt->rowCount() > 0;
}

/* ---------------------------------------------------------------
 * RIDER VERIFICATION
 * --------------------------------------------------------------- */

/**
 * Fetch riders, optionally filtered by verification status, with
 * profile data and default address city.
 *
 * @param PDO         $db
 * @param string|null $statusFilter
 * @param int         $limit
 * @param int         $offset
 * @return array<int, array<string, mixed>>
 */
function getRidersForVerification(
    PDO $db,
    ?string $statusFilter = null,
    int $limit = 20,
    int $offset = 0
): array {
    $sql = "SELECT
                dr.delivery_rider_id,
                dr.first_name,
                dr.middle_name,
                dr.last_name,
                dr.email,
                dr.contact_number,
                dr.username,
                dr.is_active,
                dr.date_created,
                drp.delivery_rider_profile_id,
                drp.profile_picture,
                drp.vehicle_type,
                drp.vehicle_plate,
                drp.verification_status,
                drp.verified_by_admin_id,
                drp.verified_at,
                drp.average_rating,
                drp.total_deliveries,
                drp.is_available,
                (SELECT dra.city
                   FROM delivery_rider_address dra
                  WHERE dra.delivery_rider_id = dr.delivery_rider_id
                    AND dra.is_default = 1
                  LIMIT 1) AS base_city,
                (SELECT dra.barangay
                   FROM delivery_rider_address dra
                  WHERE dra.delivery_rider_id = dr.delivery_rider_id
                    AND dra.is_default = 1
                  LIMIT 1) AS base_barangay
            FROM delivery_rider dr
            LEFT JOIN delivery_rider_profile drp
                   ON dr.delivery_rider_id = drp.delivery_rider_id";

    $params = [];

    if ($statusFilter !== null && $statusFilter !== '') {
        $sql .= " WHERE drp.verification_status = :status";
        $params[':status'] = $statusFilter;
    }

    $sql .= " ORDER BY
                FIELD(drp.verification_status, 'pending', 'suspended', 'denied', 'verified'),
                dr.date_created DESC
              LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Count riders matching a status filter (for pagination).
 *
 * @param PDO         $db
 * @param string|null $statusFilter
 * @return int
 */
function countRidersForVerification(PDO $db, ?string $statusFilter = null): int
{
    if ($statusFilter === null || $statusFilter === '') {
        return (int)$db->query("SELECT COUNT(*) FROM delivery_rider")->fetchColumn();
    }

    $stmt = $db->prepare(
        "SELECT COUNT(*)
           FROM delivery_rider dr
           LEFT JOIN delivery_rider_profile drp
                  ON dr.delivery_rider_id = drp.delivery_rider_id
          WHERE drp.verification_status = :status"
    );
    $stmt->execute([':status' => $statusFilter]);
    return (int)$stmt->fetchColumn();
}

/**
 * Fetch a single rider with full review context: profile, all
 * addresses, and the reviewing admin.
 *
 * @param PDO $db
 * @param int $riderId
 * @return array<string, mixed>|false
 */
function getRiderForReview(PDO $db, int $riderId): array|false
{
    $stmt = $db->prepare(
        "SELECT
            dr.delivery_rider_id,
            dr.first_name,
            dr.middle_name,
            dr.last_name,
            dr.birthdate,
            dr.gender,
            dr.email,
            dr.contact_number,
            dr.username,
            dr.is_active,
            dr.date_created,
            drp.delivery_rider_profile_id,
            drp.profile_picture,
            drp.vehicle_type,
            drp.vehicle_plate,
            drp.verification_status,
            drp.verified_by_admin_id,
            drp.verified_at,
            drp.average_rating,
            drp.total_deliveries,
            drp.is_available,
            a.first_name AS verifier_first_name,
            a.last_name  AS verifier_last_name
         FROM delivery_rider dr
         LEFT JOIN delivery_rider_profile drp
                ON dr.delivery_rider_id = drp.delivery_rider_id
         LEFT JOIN administrator a
                ON drp.verified_by_admin_id = a.administrator_id
         WHERE dr.delivery_rider_id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $riderId]);
    $rider = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rider) {
        return false;
    }

    $addrStmt = $db->prepare(
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
         WHERE delivery_rider_id = :id
         ORDER BY is_default DESC, delivery_rider_address_id ASC"
    );
    $addrStmt->execute([':id' => $riderId]);
    $rider['addresses'] = $addrStmt->fetchAll(PDO::FETCH_ASSOC);

    return $rider;
}

/**
 * Update a rider's verification status and record the admin.
 *
 * Denial and suspension force is_available = 0 so the rider cannot
 * accept new orders. Reinstatement to 'pending' or 'verified' does
 * not flip availability back — the rider controls that themselves.
 *
 * @param PDO    $db
 * @param int    $riderId
 * @param int    $adminId
 * @param string $status  pending|verified|denied|suspended
 * @return bool  True if a row was updated.
 */
function setRiderVerificationStatus(
    PDO $db,
    int $riderId,
    int $adminId,
    string $status
): bool {
    $allowed = ['pending', 'verified', 'denied', 'suspended'];
    if (!in_array($status, $allowed, true)) {
        return false;
    }

    $forceUnavailable = in_array($status, ['denied', 'suspended'], true);

    $sql = "UPDATE delivery_rider_profile
               SET verification_status  = :status,
                   verified_by_admin_id = :admin_id,
                   verified_at          = NOW()";

    if ($forceUnavailable) {
        $sql .= ", is_available = 0";
    }

    $sql .= " WHERE delivery_rider_id = :id";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':status'   => $status,
        ':admin_id' => $adminId,
        ':id'       => $riderId,
    ]);

    return $stmt->rowCount() > 0;
}

/* ---------------------------------------------------------------
 * DASHBOARD AGGREGATES
 *
 * The dashboard runs exactly five queries:
 *   1. getVerificationCounts()          — both summary tiles
 *   2. getOldestPendingSubmissions()    — "oldest waiting" hint
 *   3. getPendingRestaurantFeed()       — top N pending
 *   4. getPendingRiderFeed()            — top N pending
 *   5. getRecentVerificationDecisions() — last N decisions
 *
 * None of these loop. None scale with table size. Do not add a
 * per-row query to the dashboard — add it here as a new aggregate.
 * --------------------------------------------------------------- */

/**
 * Combined verification-status counts for restaurants and riders.
 *
 * One round trip. Pivots a UNION ALL of both tables into two
 * associative arrays keyed by status.
 *
 * @param PDO $db
 * @return array{
 *     restaurants: array{total:int, pending:int, verified:int, denied:int, suspended:int},
 *     riders:      array{total:int, pending:int, verified:int, denied:int, suspended:int}
 * }
 */
function getVerificationCounts(PDO $db): array
{
    $sql = "
        SELECT 'restaurant' AS entity, verification_status AS status, COUNT(*) AS cnt
          FROM restaurant
         GROUP BY verification_status

        UNION ALL

        SELECT 'rider' AS entity,
               COALESCE(drp.verification_status, 'pending') AS status,
               COUNT(*) AS cnt
          FROM delivery_rider dr
          LEFT JOIN delivery_rider_profile drp
                 ON dr.delivery_rider_id = drp.delivery_rider_id
         GROUP BY COALESCE(drp.verification_status, 'pending')
    ";

    $stmt = $db->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $empty = [
        'total'     => 0,
        'pending'   => 0,
        'verified'  => 0,
        'denied'    => 0,
        'suspended' => 0,
    ];

    $out = [
        'restaurants' => $empty,
        'riders'      => $empty,
    ];

    foreach ($rows as $row) {
        $entity = (string)$row['entity'];
        $status = (string)$row['status'];
        $count  = (int)$row['cnt'];

        $bucket = $entity === 'restaurant' ? 'restaurants' : 'riders';

        if (!array_key_exists($status, $out[$bucket])) {
            $status = 'pending';
        }

        $out[$bucket][$status] += $count;
        $out[$bucket]['total']  += $count;
    }

    return $out;
}

/**
 * The oldest pending restaurant and the oldest pending rider.
 *
 * One round trip. Each subquery is bounded to LIMIT 1.
 *
 * @param PDO $db
 * @return array{
 *     oldest_restaurant: ?array{id:int, name:string, submitted_at:string},
 *     oldest_rider:      ?array{id:int, name:string, submitted_at:string}
 * }
 */
function getOldestPendingSubmissions(PDO $db): array
{
    $rStmt = $db->query("
        SELECT restaurant_id AS id, business_name AS name, created_at AS submitted_at
          FROM restaurant
         WHERE verification_status = 'pending'
         ORDER BY created_at ASC
         LIMIT 1
    ");
    $rRow = $rStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $dStmt = $db->query("
        SELECT dr.delivery_rider_id AS id,
               CONCAT(dr.first_name, ' ', dr.last_name) AS name,
               dr.date_created AS submitted_at
          FROM delivery_rider dr
          LEFT JOIN delivery_rider_profile drp
                 ON dr.delivery_rider_id = drp.delivery_rider_id
         WHERE drp.verification_status = 'pending'
         ORDER BY dr.date_created ASC
         LIMIT 1
    ");
    $dRow = $dStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    return [
        'oldest_restaurant' => $rRow ? [
            'id'           => (int)$rRow['id'],
            'name'         => (string)$rRow['name'],
            'submitted_at' => (string)$rRow['submitted_at'],
        ] : null,
        'oldest_rider' => $dRow ? [
            'id'           => (int)$dRow['id'],
            'name'         => (string)$dRow['name'],
            'submitted_at' => (string)$dRow['submitted_at'],
        ] : null,
    ];
}

/**
 * Newest pending restaurant submissions, shaped for the dashboard
 * feed. Only fetches the fields the feed renders.
 *
 * @param PDO $db
 * @param int $limit
 * @return array<int, array{
 *     id:int, name:string, meta:string,
 *     submitted_at:string, submitted_human:string
 * }>
 */
function getPendingRestaurantFeed(PDO $db, int $limit = 4): array
{
    $stmt = $db->prepare("
        SELECT
            r.restaurant_id AS id,
            r.business_name AS name,
            COALESCE(r.cuisine_type, '') AS cuisine,
            r.created_at AS submitted_at,
            (
                SELECT COUNT(*)
                  FROM restaurant_branch rb
                 WHERE rb.restaurant_id = r.restaurant_id
            ) AS branch_count
          FROM restaurant r
         WHERE r.verification_status = 'pending'
         ORDER BY r.created_at DESC
         LIMIT :lim
    ");
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $branchCount = (int)$row['branch_count'];
        $cuisine     = (string)$row['cuisine'];
        $submitted   = (string)$row['submitted_at'];

        $meta = $cuisine !== '' ? $cuisine : 'No cuisine set';
        $meta .= ' · ' . $branchCount
               . ' branch' . ($branchCount === 1 ? '' : 'es');

        $out[] = [
            'id'              => (int)$row['id'],
            'name'            => (string)$row['name'],
            'meta'            => $meta,
            'submitted_at'    => $submitted,
            'submitted_human' => formatAdminRelativeTime($submitted),
        ];
    }

    return $out;
}

/**
 * Newest pending rider submissions, shaped for the dashboard feed.
 *
 * @param PDO $db
 * @param int $limit
 * @return array<int, array{
 *     id:int, name:string, meta:string,
 *     submitted_at:string, submitted_human:string
 * }>
 */
function getPendingRiderFeed(PDO $db, int $limit = 4): array
{
    $stmt = $db->prepare("
        SELECT
            dr.delivery_rider_id AS id,
            CONCAT(dr.first_name, ' ', dr.last_name) AS name,
            COALESCE(drp.vehicle_type, '') AS vehicle_type,
            dr.date_created AS submitted_at,
            (
                SELECT dra.city
                  FROM delivery_rider_address dra
                 WHERE dra.delivery_rider_id = dr.delivery_rider_id
                   AND dra.is_default = 1
                 LIMIT 1
            ) AS base_city
          FROM delivery_rider dr
          LEFT JOIN delivery_rider_profile drp
                 ON dr.delivery_rider_id = drp.delivery_rider_id
         WHERE drp.verification_status = 'pending'
         ORDER BY dr.date_created DESC
         LIMIT :lim
    ");
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $vehicle   = (string)$row['vehicle_type'];
        $city      = $row['base_city'] !== null ? (string)$row['base_city'] : '';
        $submitted = (string)$row['submitted_at'];

        $meta = $vehicle !== '' ? ucfirst($vehicle) : 'No vehicle set';
        if ($city !== '') {
            $meta .= ' · ' . $city;
        }

        $out[] = [
            'id'              => (int)$row['id'],
            'name'            => (string)$row['name'],
            'meta'            => $meta,
            'submitted_at'    => $submitted,
            'submitted_human' => formatAdminRelativeTime($submitted),
        ];
    }

    return $out;
}

/**
 * Recent verification decisions across both entity types.
 *
 * @param PDO $db
 * @param int $limit
 * @return array<int, array{
 *     entity:string, id:int, name:string, status:string,
 *     decided_at:string, admin_name:string, decided_human:string
 * }>
 */
function getRecentVerificationDecisions(PDO $db, int $limit = 5): array
{
    $sql = "
        (
            SELECT
                'restaurant' AS entity,
                r.restaurant_id AS id,
                r.business_name AS name,
                r.verification_status AS status,
                r.verified_at AS decided_at,
                COALESCE(CONCAT(a.first_name, ' ', a.last_name), 'System') AS admin_name
              FROM restaurant r
              LEFT JOIN administrator a
                     ON r.verified_by_admin_id = a.administrator_id
             WHERE r.verified_at IS NOT NULL
               AND r.verification_status <> 'pending'
             ORDER BY r.verified_at DESC
             LIMIT :lim_r
        )

        UNION ALL

        (
            SELECT
                'rider' AS entity,
                dr.delivery_rider_id AS id,
                CONCAT(dr.first_name, ' ', dr.last_name) AS name,
                drp.verification_status AS status,
                drp.verified_at AS decided_at,
                COALESCE(CONCAT(a.first_name, ' ', a.last_name), 'System') AS admin_name
              FROM delivery_rider_profile drp
              JOIN delivery_rider dr
                ON dr.delivery_rider_id = drp.delivery_rider_id
              LEFT JOIN administrator a
                     ON drp.verified_by_admin_id = a.administrator_id
             WHERE drp.verified_at IS NOT NULL
               AND drp.verification_status <> 'pending'
             ORDER BY drp.verified_at DESC
             LIMIT :lim_d
        )

        ORDER BY decided_at DESC
        LIMIT :lim_total
    ";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':lim_r',     $limit, PDO::PARAM_INT);
    $stmt->bindValue(':lim_d',     $limit, PDO::PARAM_INT);
    $stmt->bindValue(':lim_total', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_map(static function (array $row): array {
        $decided = (string)$row['decided_at'];
        return [
            'entity'        => (string)$row['entity'],
            'id'            => (int)$row['id'],
            'name'          => (string)$row['name'],
            'status'        => (string)$row['status'],
            'decided_at'    => $decided,
            'admin_name'    => (string)$row['admin_name'],
            'decided_human' => formatAdminRelativeTime($decided),
        ];
    }, $rows);
}

/* ---------------------------------------------------------------
 * PRESENTATION HELPERS (pure — no DB access)
 * --------------------------------------------------------------- */

/**
 * Human-readable label for a verification status.
 *
 * @param string $status
 * @return string
 */
function formatVerificationStatus(string $status): string
{
    return match ($status) {
        'pending'   => 'Pending',
        'verified'  => 'Verified',
        'denied'    => 'Denied',
        'suspended' => 'Suspended',
        default     => ucfirst($status),
    };
}

/**
 * CSS badge class for a verification status.
 *
 * @param string $status
 * @return string
 */
function getVerificationBadgeClass(string $status): string
{
    return match ($status) {
        'pending'   => 'badge-warning',
        'verified'  => 'badge-success',
        'denied'    => 'badge-danger',
        'suspended' => 'badge-secondary',
        default     => 'badge-secondary',
    };
}

/**
 * Format a timestamp for admin display. Em dash when null.
 *
 * @param string|null $datetime
 * @param string      $format
 * @return string
 */
function formatAdminDate(?string $datetime, string $format = 'M d, Y g:i A'): string
{
    if ($datetime === null || $datetime === '') {
        return '—';
    }
    $ts = strtotime($datetime);
    return $ts !== false ? date($format, $ts) : $datetime;
}

/**
 * Human-readable relative time ("2 hours ago"). Falls back to an
 * absolute date for timestamps older than 30 days.
 *
 * @param string $datetime
 * @return string
 */
function formatAdminRelativeTime(string $datetime): string
{
    $ts = strtotime($datetime);
    if ($ts === false) {
        return $datetime;
    }

    $diff = time() - $ts;

    if ($diff < 0) {
        return date('M d, Y', $ts);
    }
    if ($diff < 60) {
        return 'Just now';
    }
    if ($diff < 3600) {
        $mins = (int)floor($diff / 60);
        return $mins . ' minute' . ($mins === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 86400) {
        $hrs = (int)floor($diff / 3600);
        return $hrs . ' hour' . ($hrs === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 2592000) {
        $days = (int)floor($diff / 86400);
        return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
    }

    return date('M d, Y', $ts);
}

/**
 * Format a rider's vehicle as a short label.
 *
 * @param string|null $vehicleType
 * @param string|null $plate
 * @return string
 */
function formatVehicle(?string $vehicleType, ?string $plate): string
{
    $type = $vehicleType !== null && $vehicleType !== ''
        ? ucfirst($vehicleType)
        : 'Unknown';

    if ($plate !== null && $plate !== '') {
        return $type . ' · ' . $plate;
    }

    return $type;
}