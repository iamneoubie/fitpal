<?php
/**
 * FitPal Restaurant Database Queries
 *
 * Pure data-access layer for the restaurant, restaurant_account,
 * restaurant_branch, and restaurant_permit tables.
 *
 * No $_POST, no header(), no echo.
 *
 * @package FitPal
 * @version 1.1
 */

declare(strict_types=1);

/* =============================================================
 * AUTHENTICATION
 * ============================================================= */

/**
 * Find a restaurant account by email or username.
 *
 * @param PDO    $db
 * @param string $identifier Email or username
 * @return array<string, mixed>|false
 */
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

/**
 * Find a restaurant account with owner/branch disambiguation.
 *
 * @param PDO    $db
 * @param string $identifier
 * @param string $roleScope 'owner' or 'branch'
 * @return array<string, mixed>|false
 */
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

/**
 * Find a branch account by identifier, scoped to a specific branch.
 *
 * @param PDO    $db
 * @param string $identifier
 * @param string $branchCode
 * @return array<string, mixed>|false
 */
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

/**
 * List all active branches that have at least one active branch-scoped
 * account. Used to populate the branch selector on the sign-in page.
 *
 * @param PDO $db
 * @return array<int, array<string, mixed>>
 */
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

/**
 * Get the full restaurant account profile.
 *
 * @param PDO $db
 * @param int $accountId
 * @return array<string, mixed>|false
 */
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

/**
 * Record a login timestamp.
 *
 * @param PDO $db
 * @param int $accountId
 * @return void
 */
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

/**
 * Create a new restaurant row with verification_status = 'pending'.
 *
 * @param PDO   $db
 * @param array $data
 * @return int New restaurant_id
 */
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

/**
 * Create a new financial account for a restaurant branch.
 *
 * @param PDO $db
 * @return int New financial_account_id
 */
function createRestaurantFinancialAccount(PDO $db): int
{
    $stmt = $db->prepare(
        "INSERT INTO financial_account (balance, account_type)
         VALUES (0.00, 'restaurant')"
    );
    $stmt->execute();
    return (int)$db->lastInsertId();
}

/**
 * Create a restaurant branch.
 *
 * @param PDO   $db
 * @param int   $restaurantId
 * @param int   $financialAccountId
 * @param array $data
 * @return int New restaurant_branch_id
 */
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

/**
 * Create a restaurant owner account (branch_id = NULL, role = 'owner').
 *
 * @param PDO   $db
 * @param int   $restaurantId
 * @param array $data
 * @return int New restaurant_account_id
 */
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

/**
 * Insert a permit photo row for a restaurant.
 *
 * @param PDO    $db
 * @param int    $restaurantId
 * @param string $filePath     Project-root-relative path
 * @param string $originalName Original filename
 * @param int    $order        Display order (0-based)
 * @return int New permit_id
 */
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

/**
 * Fetch all permits for a restaurant, ordered by display_order.
 *
 * @param PDO $db
 * @param int $restaurantId
 * @return array<int, array<string, mixed>>
 */
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

/**
 * Generate a unique branch code from a business name.
 *
 * @param PDO    $db
 * @param string $businessName
 * @return string
 */
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