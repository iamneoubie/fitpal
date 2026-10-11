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
 * ---------------------------------------------------------------------
 * REVENUE RECOGNITION
 * ---------------------------------------------------------------------
 * Revenue is recognised only when an order reaches order_status =
 * 'delivered'. Orders that are still pending, preparing, awaiting a
 * rider, or in transit are operational counts, not revenue. Orders
 * that closed as 'cancelled', 'refunded', or 'failed' never produced
 * deliverable food revenue and are excluded from every revenue
 * aggregate.
 *
 * The 'failed' status is produced by the shared order-transaction
 * layer's sweepFailedDeliveries() when a rider does not complete a
 * delivery within FITPAL_RIDER_FAILED_DELIVERY_GRACE_SECONDS of the
 * order entering 'delivering'. It is a closed order with no
 * deliverable revenue.
 *
 * ---------------------------------------------------------------------
 * ANALYTICS PERIODS
 * ---------------------------------------------------------------------
 * The dashboard's period dropdown maps to one of three tokens:
 *
 *   '7d'    last 7 rolling days
 *   '30d'   last 30 rolling days
 *   '12m'   last 12 rolling months
 *
 * fitpal_analytics_period() resolves a token to a concrete
 * {label, days, months, bucket, interval_sql} tuple that every
 * analytics reader below consumes.
 *
 * ---------------------------------------------------------------------
 * SCOPE
 * ---------------------------------------------------------------------
 * Every analytics reader below takes a scope argument:
 *
 *   fitpal_analytics_scope_owner($restaurantId)
 *   fitpal_analytics_scope_branch($branchId)
 *
 * The two builders produce a WHERE fragment and a bind-value list
 * that every reader interpolates.
 *
 * ---------------------------------------------------------------------
 * PER-ORDER COUNTING
 * ---------------------------------------------------------------------
 * Every order-scoped count in this file uses COUNT(DISTINCT
 * o.order_id) or COUNT(DISTINCT CASE WHEN ... THEN o.order_id END),
 * never SUM(CASE WHEN ... THEN 1 ELSE 0 END).
 *
 * Every query in this file that reaches `orders` joins through
 * `queue_item`. An order with three line items produces three rows
 * after the join. COUNT(DISTINCT o.order_id) collapses the join
 * back to one row per order, which is what the dashboard labels
 * ("Total Orders", "Active Orders", "Delivered Orders") actually
 * mean.
 *
 * ---------------------------------------------------------------------
 * BRANCH RESOLUTION IN getRestaurantAccountProfile()
 * ---------------------------------------------------------------------
 * An owner account is created by sign-up-handler.php through
 * createRestaurantOwnerAccount(), which writes
 * restaurant_account.branch_id = NULL. The owner is not scoped to
 * a single branch; the owner is scoped to the restaurant.
 *
 * getRestaurantAccountProfile() resolves the branch through the
 * restaurant when the account's own branch_id is NULL, so an owner
 * still sees the branch row:
 *
 *     LEFT JOIN restaurant_branch rb
 *            ON rb.restaurant_branch_id = COALESCE(
 *                   ra.branch_id,
 *                   (SELECT rb2.restaurant_branch_id
 *                      FROM restaurant_branch rb2
 *                     WHERE rb2.restaurant_id = ra.restaurant_id
 *                       AND rb2.is_active = 1
 *                     ORDER BY rb2.restaurant_branch_id ASC
 *                     LIMIT 1)
 *               )
 *
 * For a branch-scoped account, ra.branch_id is positive and the
 * COALESCE returns it. For an owner, ra.branch_id is NULL and the
 * subquery returns the owner's primary active branch.
 *
 * ---------------------------------------------------------------------
 * PERMIT STORAGE — SLOT MODEL
 * ---------------------------------------------------------------------
 * Permits use the same storage shape as product images:
 *
 *     shared/uploads/restaurant/permits/<restaurant_id>/
 *         image-1.jpg
 *         image-2.png
 *         image-3.webp
 *         image-4.jpg
 *         image-5.png
 *
 * The folder is keyed on restaurant_id. A restaurant has one permit
 * set, and every account on that restaurant sees the same files.
 *
 * There is exactly ONE restaurant_permit row per restaurant. Its
 * file_path column carries the folder path ending with a slash:
 *
 *     shared/uploads/restaurant/permits/<restaurant_id>/
 *
 * The five slots are the files image-1.* through image-5.* inside
 * that folder. display_order is written as 0 and never read.
 *
 * createRestaurantPermit() takes the folder path, not a filename.
 * getRestaurantPermits() returns one entry per slot, keyed by slot
 * number, each carrying a browser-loadable URL.
 *
 * A v7.0 revision of these two functions stored one row per file
 * with the whole file path in file_path and read the raw column
 * back. That layout is abandoned.
 *
 * ---------------------------------------------------------------------
 * THE ONE-BUGBEAR OF THE READ SIDE — WHY SLOT 1 APPEARED NOT TO UPDATE
 * ---------------------------------------------------------------------
 * The writer is
 * restaurant/backend/handlers/restaurant-reapply-handler.php's
 * fitpal_permit_write_slot(). It captures the current slot's bytes,
 * unlinks every image-<slot>.* in the folder, and only then writes
 * the replacement. On commit the folder holds exactly one file per
 * slot. On failure the writer's returned byte list lets the caller
 * restore exactly what was there before.
 *
 * None of that guarantees the READER reports what the writer wrote.
 * The previous revision of getRestaurantPermits() scanned the
 * folder and then, for each slot, kept the FIRST file it saw and
 * discarded every other file claiming the same slot:
 *
 *     if (!isset($bySlot[$slot])) {
 *         $bySlot[$slot] = $entry;
 *     }
 *
 * Two failure modes hid behind that guard.
 *
 *   1. scandir() with no sort flag sorts alphabetically. For
 *      image-1.jpg and image-1.webp the reader always picked the
 *      .jpg. On a folder that a prior handler revision left with
 *      both files, the reader reported the old file and silently
 *      discarded the new one. The writer's file was on disk and
 *      the browser showed the previous one.
 *
 *   2. The guard also hid a race. A filesystem write and a
 *      subsequent read on different requests are not ordered by
 *      the reader. If the read request landed between the
 *      writer's unlink and its write — a window the writer keeps
 *      as small as it can, but cannot make zero without a lock —
 *      the reader saw the old file and the writer's file appeared
 *      a moment later. The reader made the choice silently.
 *
 * The fix is not to guess. The fix is to REPORT.
 *
 * getRestaurantPermits() now collects every file that claims a
 * slot, sorts the slot's files by modification time DESCENDING,
 * and returns the newest one as the primary entry. Any other file
 * for the same slot is returned under the 'duplicates' key on the
 * primary entry. The caller decides what to do with duplicates;
 * the reader only reports them.
 *
 * The primary entry keeps the exact same four keys it always had —
 * slot, filename, url, folder — so no consumer of this function
 * changes. The extra 'duplicates' key is additive; a caller that
 * does not read it is unaffected.
 *
 * The primary is chosen by mtime, not alphabetically. mtime is the
 * same clock the writer's move_uploaded_file updates. On a folder
 * the writer produced, that is the newest file for the slot. On a
 * folder an older handler left in a two-file state, the primary is
 * the newer of the two — which is the one the newer handler tried
 * to leave behind.
 *
 * ---------------------------------------------------------------------
 * URL RESOLUTION IN getRestaurantPermits()
 * ---------------------------------------------------------------------
 * getRestaurantPermits() derives the project-root URL prefix from
 * $_SERVER['SCRIPT_NAME'] at call time. A page under
 * restaurant/pages/ gets '../../'; a handler under
 * restaurant/backend/handlers/ gets '../../../'. Both are correct
 * relative to their own request, so the same function returns a
 * URL the browser can load from whichever caller ran it.
 *
 * ---------------------------------------------------------------------
 * TRENDING PANEL — PREVIOUS-PERIOD WINDOW (v11.0)
 * ---------------------------------------------------------------------
 * getRestaurantTrendingProducts() reports a product's unit sales
 * in the currently selected period AND in the period of the same
 * length immediately before it, so the panel can display a
 * change indicator against the previous period.
 *
 * The previous-period window is expressed as a single interval of
 * twice the current period's length, subtracted from CURDATE():
 *
 *     current  period window : [ CURDATE() - N,       CURDATE() ]
 *     previous period window : [ CURDATE() - 2N,      CURDATE() - N )
 *
 * Before v11.0 the previous-period boundary was written as
 *
 *     DATE_SUB(CURDATE(), {$interval} + {$interval})
 *
 * which MySQL parses as `DATE_SUB` receiving the second argument
 * `INTERVAL 7 DAY + INTERVAL 7 DAY` — a single interval spec
 * immediately followed by another. The grammar for DATE_SUB's
 * second argument requires exactly one `INTERVAL expr unit`
 * construct, so this is a parse error for every period token.
 *
 * The dashboard wraps the call in a try/catch and logs the
 * exception, so the panel silently fell back to its empty state.
 * That is why every restaurant saw "No product sales yet" no
 * matter how many orders existed. The seed was never the cause.
 *
 * v11.0 builds a second interval string from the period tuple's
 * own `days` / `months` fields:
 *
 *     $previousInterval = $period['months'] > 0
 *         ? sprintf('INTERVAL %d MONTH', $period['months'] * 2)
 *         : sprintf('INTERVAL %d DAY',   $period['days']   * 2);
 *
 * Both `DATE_SUB(CURDATE(), {$previousInterval})` and
 * `DATE_SUB(CURDATE(), {$interval})` are single-interval
 * expressions and parse cleanly.
 *
 * @package FitPal
 * @version 11.0 — getRestaurantTrendingProducts() computes the
 *                 previous-period boundary from a valid single
 *                 interval instead of the invalid doubled
 *                 interval. The Trending panel now returns rows
 *                 for every restaurant with delivered orders in
 *                 the selected period.
 *
 *                 (10.0: getRestaurantPermits() reports every file
 *                 for a slot. 9.0: permit storage moved to the
 *                 slot shape. 8.0: per-order counting, active-
 *                 branch filter, branch resolution through the
 *                 restaurant. 7.0: analytics section. 6.0:
 *                 self-excluding uniqueness probes. 5.1:
 *                 revenue-recognition docblock. 5.0: delivered-only
 *                 revenue gate.)
 */

declare(strict_types=1);

/* =============================================================
 * PERMIT SLOT CONSTANTS
 * ============================================================= */

if (!defined('FITPAL_PERMIT_MAX_SLOTS')) {
    define('FITPAL_PERMIT_MAX_SLOTS', 5);
}

if (!defined('FITPAL_PERMIT_ALLOWED_EXT')) {
    define('FITPAL_PERMIT_ALLOWED_EXT', ['jpg', 'jpeg', 'png', 'webp']);
}

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

/**
 * Return the full profile of one restaurant account.
 *
 * Branch fields are resolved through the restaurant when the
 * account's own branch_id is NULL, so an owner account — which is
 * created with branch_id = NULL — still sees its primary branch.
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
         LEFT JOIN restaurant_branch rb
                ON rb.restaurant_branch_id = COALESCE(
                       ra.branch_id,
                       (SELECT rb2.restaurant_branch_id
                          FROM restaurant_branch rb2
                         WHERE rb2.restaurant_id = ra.restaurant_id
                           AND rb2.is_active = 1
                         ORDER BY rb2.restaurant_branch_id ASC
                         LIMIT 1)
                   )
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
 * UNIQUENESS CHECKS — ORIGINAL
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
 * UNIQUENESS CHECKS — SELF-EXCLUDING PROBES
 * ============================================================= */

function restaurantEmailExistsForOther(PDO $db, string $email, int $excludeAccountId): bool
{
    $stmt = $db->prepare(
        "SELECT 1
           FROM restaurant_account
          WHERE email = :email
            AND restaurant_account_id <> :exclude_id
          LIMIT 1"
    );
    $stmt->execute([
        ':email'      => $email,
        ':exclude_id' => $excludeAccountId,
    ]);
    return $stmt->fetch() !== false;
}

function restaurantUsernameExistsForOther(PDO $db, string $username, int $excludeAccountId): bool
{
    $stmt = $db->prepare(
        "SELECT 1
           FROM restaurant_account
          WHERE username = :username
            AND restaurant_account_id <> :exclude_id
          LIMIT 1"
    );
    $stmt->execute([
        ':username'   => $username,
        ':exclude_id' => $excludeAccountId,
    ]);
    return $stmt->fetch() !== false;
}

function restaurantContactExistsForOther(PDO $db, string $contact, int $excludeAccountId): bool
{
    $stmt = $db->prepare(
        "SELECT 1
           FROM restaurant_account
          WHERE contact_number = :contact
            AND restaurant_account_id <> :exclude_id
          LIMIT 1"
    );
    $stmt->execute([
        ':contact'    => $contact,
        ':exclude_id' => $excludeAccountId,
    ]);
    return $stmt->fetch() !== false;
}

function restaurantBusinessNameExistsForOther(PDO $db, string $name, int $excludeRestaurantId): bool
{
    $stmt = $db->prepare(
        "SELECT 1
           FROM restaurant
          WHERE business_name = :business_name
            AND restaurant_id <> :exclude_id
          LIMIT 1"
    );
    $stmt->execute([
        ':business_name' => $name,
        ':exclude_id'    => $excludeRestaurantId,
    ]);
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
 * PERMIT HANDLING — SLOT MODEL
 * ============================================================= */

/**
 * Record a permit folder for a restaurant.
 *
 * There is exactly one restaurant_permit row per restaurant under
 * the slot model. The row carries the folder path. The five slots
 * are the files image-1.* through image-5.* inside that folder.
 *
 * display_order is written as 0 and never read.
 *
 * When a row already exists for the restaurant it is updated in
 * place; the folder path is overwritten. When no row exists, one
 * is inserted.
 *
 * @param PDO    $db
 * @param int    $restaurantId
 * @param string $folderPath Project-root-relative folder ending
 *                           with a slash.
 * @return int   The permit_id, or 0 on failure.
 */
function createRestaurantPermit(
    PDO $db,
    int $restaurantId,
    string $folderPath
): int {
    if ($restaurantId <= 0) {
        return 0;
    }

    if ($folderPath === '') {
        return 0;
    }

    // Normalize: exactly one trailing slash.
    $folderPath = rtrim($folderPath, '/') . '/';

    $stmt = $db->prepare(
        "SELECT permit_id
           FROM restaurant_permit
          WHERE restaurant_id = :restaurant_id
          ORDER BY permit_id ASC
          LIMIT 1"
    );
    $stmt->execute([':restaurant_id' => $restaurantId]);
    $permitId = $stmt->fetchColumn();

    if ($permitId !== false) {
        $update = $db->prepare(
            "UPDATE restaurant_permit
                SET file_path = :file_path
              WHERE permit_id = :permit_id
                AND restaurant_id = :restaurant_id"
        );
        $update->execute([
            ':file_path'     => $folderPath,
            ':permit_id'     => (int)$permitId,
            ':restaurant_id' => $restaurantId,
        ]);
        return (int)$permitId;
    }

    $insert = $db->prepare(
        "INSERT INTO restaurant_permit
            (restaurant_id, file_path, original_name, display_order)
         VALUES
            (:restaurant_id, :file_path, NULL, 0)"
    );
    $insert->execute([
        ':restaurant_id' => $restaurantId,
        ':file_path'     => $folderPath,
    ]);
    return (int)$db->lastInsertId();
}

/**
 * Return the current permit slot set for a restaurant.
 *
 * The permit folder is read from restaurant_permit.file_path, which
 * under the slot model stores a folder path ending with a slash:
 *
 *     shared/uploads/restaurant/permits/<restaurant_id>/
 *
 * The five slots are the files image-1.* through image-5.* inside
 * that folder. The return is keyed by slot number, 1 through 5. A
 * slot with no file is absent from the array.
 *
 * Each primary entry carries:
 *
 *   slot          1..5
 *   filename      the newest filename for that slot
 *   url           browser-loadable URL for that filename, with the
 *                 project-root URL prefix already applied
 *   folder        the folder path exactly as stored, ending with
 *                 a slash
 *   duplicates    every OTHER file in the same slot, newest-first,
 *                 each shaped like the primary minus 'duplicates'.
 *                 Empty array when the slot holds exactly one file.
 *
 * Sorting.
 *   For each slot, the files are sorted by filemtime() DESCENDING.
 *   The primary is the newest. Ties are broken by name, ascending,
 *   so the order is deterministic on a filesystem that does not
 *   give the same mtime to two files written in the same second.
 *
 * Why duplicates are reported and not hidden.
 *   A folder that a prior handler revision left in a two-file
 *   state — image-1.jpg AND image-1.webp — is malformed, but it
 *   exists on disk. The reader's job is to report it. The
 *   previous revision silently kept the alphabetically first file
 *   and discarded the other, which made a replacement written by
 *   the current handler appear to have been ignored. The
 *   current revision returns both, with the newest first, so the
 *   caller can log the folder state and let the operator decide
 *   what to do.
 *
 * An empty array means the restaurant has no permit files, or the
 * stored value does not resolve to a folder with slot files in it.
 *
 * A stored value that does not end with a slash is treated as a
 * folder anyway and has one appended. A stored value that is
 * actually a file path from an older layout is not recognized as a
 * folder, is_dir() fails, and this function returns an empty
 * array — the caller shows the empty state.
 *
 * @param PDO $db
 * @param int $restaurantId
 * @return array<int, array{
 *   slot:int,
 *   filename:string,
 *   url:string,
 *   folder:string,
 *   duplicates: array<int, array{slot:int, filename:string, url:string, folder:string}>
 * }>
 */
function getRestaurantPermits(PDO $db, int $restaurantId): array
{
    if ($restaurantId <= 0) {
        return [];
    }

    $stmt = $db->prepare(
        "SELECT file_path
           FROM restaurant_permit
          WHERE restaurant_id = :restaurant_id
          ORDER BY permit_id ASC
          LIMIT 1"
    );
    $stmt->execute([':restaurant_id' => $restaurantId]);
    $storedFolder = (string)($stmt->fetchColumn() ?: '');

    if ($storedFolder === '') {
        return [];
    }

    // Normalize to a project-root-relative folder ending with a
    // slash.
    $relativeFolder = rtrim($storedFolder, '/') . '/';

    $projectRoot = dirname(__DIR__, 3);
    $absolute    = $projectRoot . '/' . $relativeFolder;

    if (!is_dir($absolute)) {
        return [];
    }

    $allowedExt = FITPAL_PERMIT_ALLOWED_EXT;
    $entries    = @scandir($absolute);

    if ($entries === false) {
        return [];
    }

    // Collect every file that claims a slot. Multiple files can
    // claim the same slot on a folder a previous handler revision
    // left in a malformed state; report all of them, sorted.
    $bySlot = [];

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        if (!preg_match('/^image-(\d+)\.([A-Za-z0-9]+)$/', $entry, $m)) {
            continue;
        }

        $slot = (int)$m[1];
        if ($slot < 1 || $slot > FITPAL_PERMIT_MAX_SLOTS) {
            continue;
        }

        $ext = strtolower($m[2]);
        if (!in_array($ext, $allowedExt, true)) {
            continue;
        }

        $path = $absolute . DIRECTORY_SEPARATOR . $entry;
        if (!is_file($path)) {
            continue;
        }

        $mtime = @filemtime($path);
        if ($mtime === false) {
            // A file whose mtime cannot be read is skipped rather
            // than assigned an arbitrary sort position. The caller
            // sees the slot as it was before this file appeared.
            continue;
        }

        $bySlot[$slot][] = [
            'filename' => $entry,
            'mtime'    => (int)$mtime,
        ];
    }

    if (empty($bySlot)) {
        return [];
    }

    // Derive the project-root URL prefix for the current request.
    // The page that calls this function sits under restaurant/pages/,
    // so the prefix resolves to '../../'. The handler that calls
    // this function sits under restaurant/backend/handlers/, so the
    // prefix resolves to '../../../'. Both are correct relative to
    // their own requests.
    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
    $dirPath    = $scriptPath !== '' ? dirname($scriptPath) : '';
    $segments   = $dirPath !== '' ? array_filter(explode('/', $dirPath)) : [];
    $depth      = count($segments);
    $rootUrl    = $depth > 0 ? str_repeat('../', $depth) : './';

    $out = [];

    ksort($bySlot, SORT_NUMERIC);

    foreach ($bySlot as $slot => $files) {

        // Sort by modification time DESCENDING. Ties broken by
        // filename ascending so the order is deterministic when two
        // files share an mtime — a case that arises on filesystems
        // with one-second mtime resolution and a rapid replace.
        usort($files, static function (array $a, array $b): int {
            if ($a['mtime'] !== $b['mtime']) {
                return $b['mtime'] <=> $a['mtime'];
            }
            return strcmp($a['filename'], $b['filename']);
        });

        $primary = $files[0];
        $rest    = array_slice($files, 1);

        $duplicates = [];
        foreach ($rest as $file) {
            $duplicates[] = [
                'slot'     => $slot,
                'filename' => $file['filename'],
                'url'      => $rootUrl . $relativeFolder . $file['filename'],
                'folder'   => $relativeFolder,
            ];
        }

        $out[$slot] = [
            'slot'       => $slot,
            'filename'   => $primary['filename'],
            'url'        => $rootUrl . $relativeFolder . $primary['filename'],
            'folder'     => $relativeFolder,
            'duplicates' => $duplicates,
        ];
    }

    return $out;
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
 * OWNER DASHBOARD — BASE STATS
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
            (SELECT COUNT(*)
               FROM product p
               JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
              WHERE rb.restaurant_id = :rid2
                AND rb.is_active = 1
                AND p.is_active = 1) AS product_count"
    );
    $row->execute([':rid' => $restaurantId, ':rid2' => $restaurantId]);
    $counts = $row->fetch(PDO::FETCH_ASSOC) ?: [];

    $stats['branch_count']  = (int)($counts['branch_count'] ?? 0);
    $stats['product_count'] = (int)($counts['product_count'] ?? 0);

    $orderRow = $db->prepare(
        "SELECT
            COUNT(DISTINCT o.order_id) AS total_orders,
            COUNT(DISTINCT CASE WHEN DATE(o.order_date) = CURDATE()
                                 THEN o.order_id END) AS orders_today,
            COUNT(DISTINCT CASE WHEN o.order_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                                 THEN o.order_id END) AS orders_this_week,
            COUNT(DISTINCT CASE WHEN o.order_status IN
                                 ('pending','preparing','rider_pending','picking_up','delivering')
                                 THEN o.order_id END) AS active_orders,
            COUNT(DISTINCT CASE WHEN o.order_status = 'delivered'
                                 THEN o.order_id END) AS delivered_orders
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
                WHEN o.delivered_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                THEN qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)
                ELSE 0 END), 0) AS revenue_this_week,
            COALESCE(SUM(CASE
                WHEN DATE(o.delivered_at) = CURDATE()
                THEN qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)
                ELSE 0 END), 0) AS revenue_today
         FROM orders o
         JOIN queue_item qi ON qi.order_id = o.order_id
         JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
         WHERE rb.restaurant_id = :rid
           AND o.order_status = 'delivered'"
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
            DATE(o.delivered_at) AS day,
            COALESCE(SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)), 0) AS amount,
            COUNT(DISTINCT o.order_id) AS orders
         FROM orders o
         JOIN queue_item qi ON qi.order_id = o.order_id
         JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
         WHERE rb.restaurant_id = :rid
           AND o.order_status = 'delivered'
           AND o.delivered_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
         GROUP BY DATE(o.delivered_at)
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
                AND o.order_status NOT IN ('cancelled','refunded','failed')) AS order_count,
            (SELECT COALESCE(SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)), 0)
               FROM queue_item qi
               JOIN orders o ON o.order_id = qi.order_id
              WHERE qi.branch_id = rb.restaurant_branch_id
                AND o.order_status = 'delivered') AS revenue
         FROM restaurant_branch rb
         WHERE rb.restaurant_id = :rid
         ORDER BY rb.is_active DESC, rb.branch_name ASC"
    );
    $stmt->execute([':rid' => $restaurantId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =============================================================
 * BRANCH DASHBOARD — BASE STATS
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
            COUNT(DISTINCT CASE WHEN DATE(o.order_date) = CURDATE()
                                 THEN o.order_id END) AS orders_today,
            COUNT(DISTINCT CASE WHEN o.order_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                                 THEN o.order_id END) AS orders_this_week,
            COUNT(DISTINCT CASE WHEN o.order_status IN
                                 ('pending','preparing','rider_pending','picking_up','delivering')
                                 THEN o.order_id END) AS active_orders,
            COUNT(DISTINCT CASE WHEN o.order_status = 'delivered'
                                 THEN o.order_id END) AS delivered_orders
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
                WHEN o.delivered_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                THEN qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)
                ELSE 0 END), 0) AS revenue_this_week,
            COALESCE(SUM(CASE
                WHEN DATE(o.delivered_at) = CURDATE()
                THEN qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)
                ELSE 0 END), 0) AS revenue_today
         FROM orders o
         JOIN queue_item qi ON qi.order_id = o.order_id
         WHERE qi.branch_id = :bid
           AND o.order_status = 'delivered'"
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
            DATE(o.delivered_at) AS day,
            COALESCE(SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)), 0) AS amount,
            COUNT(DISTINCT o.order_id) AS orders
         FROM orders o
         JOIN queue_item qi ON qi.order_id = o.order_id
         WHERE qi.branch_id = :bid
           AND o.order_status = 'delivered'
           AND o.delivered_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
         GROUP BY DATE(o.delivered_at)
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

/* =============================================================
 * BRANCH SIGN-IN AUTOCOMPLETE
 * ============================================================= */

function searchRestaurantsByName(PDO $db, string $query, int $limit = 8): array
{
    $query = trim($query);

    if ($query === '') {
        $stmt = $db->prepare(
            "SELECT
                r.restaurant_id,
                r.business_name,
                r.cuisine_type,
                MIN(rb.city) AS city
             FROM restaurant r
             LEFT JOIN restaurant_branch rb
                    ON rb.restaurant_id = r.restaurant_id
                   AND rb.is_active = 1
             WHERE r.is_active = 1
               AND r.verification_status = 'verified'
             GROUP BY r.restaurant_id, r.business_name, r.cuisine_type
             ORDER BY r.business_name ASC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $db->prepare(
        "SELECT
            r.restaurant_id,
            r.business_name,
            r.cuisine_type,
            MIN(rb.city) AS city
         FROM restaurant r
         LEFT JOIN restaurant_branch rb
                ON rb.restaurant_id = r.restaurant_id
               AND rb.is_active = 1
         WHERE r.is_active = 1
           AND r.verification_status = 'verified'
           AND r.business_name LIKE :q
         GROUP BY r.restaurant_id, r.business_name, r.cuisine_type
         ORDER BY
            CASE WHEN r.business_name LIKE :q_prefix THEN 0 ELSE 1 END,
            r.business_name ASC
         LIMIT :limit"
    );
    $stmt->bindValue(':q', '%' . $query . '%', PDO::PARAM_STR);
    $stmt->bindValue(':q_prefix', $query . '%', PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function searchBranchesByRestaurant(PDO $db, int $restaurantId): array
{
    if ($restaurantId <= 0) {
        return [];
    }

    $stmt = $db->prepare(
        "SELECT DISTINCT
            rb.branch_code,
            rb.branch_name,
            rb.city
         FROM restaurant_branch rb
         JOIN restaurant_account ra
                ON ra.branch_id = rb.restaurant_branch_id
               AND ra.is_active = 1
               AND ra.role IN ('manager','staff','cashier','kitchen')
         WHERE rb.restaurant_id = :rid
           AND rb.is_active = 1
         ORDER BY rb.branch_name ASC"
    );
    $stmt->execute([':rid' => $restaurantId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =============================================================
 * ANALYTICS — PERIOD RESOLVER
 * ============================================================= */

function fitpal_analytics_period(string $token): array
{
    $token = strtolower(trim($token));

    if ($token === '30d') {
        return [
            'token'        => '30d',
            'label'        => 'Last 30 days',
            'days'         => 30,
            'months'       => 0,
            'bucket'       => 'day',
            'interval_sql' => 'INTERVAL 30 DAY',
            'bucket_sql'   => 'DATE(o.delivered_at)',
            'bucket_fmt'   => 'Y-m-d',
        ];
    }

    if ($token === '12m') {
        return [
            'token'        => '12m',
            'label'        => 'Last 12 months',
            'days'         => 0,
            'months'       => 12,
            'bucket'       => 'month',
            'interval_sql' => 'INTERVAL 12 MONTH',
            'bucket_sql'   => "DATE_FORMAT(o.delivered_at, '%Y-%m-01')",
            'bucket_fmt'   => 'Y-m-01',
        ];
    }

    return [
        'token'        => '7d',
        'label'        => 'Last 7 days',
        'days'         => 7,
        'months'       => 0,
        'bucket'       => 'day',
        'interval_sql' => 'INTERVAL 7 DAY',
        'bucket_sql'   => 'DATE(o.delivered_at)',
        'bucket_fmt'   => 'Y-m-d',
    ];
}

/**
 * Build the WHERE fragment + bind values that narrow every
 * analytics reader to one scope.
 *
 * @return array{where:string, bind:array<string,int>}
 */
function fitpal_analytics_scope_owner(int $restaurantId): array
{
    return [
        'where' => 'rb.restaurant_id = :scope_id',
        'bind'  => [':scope_id' => $restaurantId],
    ];
}

function fitpal_analytics_scope_branch(int $branchId): array
{
    return [
        'where' => 'qi.branch_id = :scope_id',
        'bind'  => [':scope_id' => $branchId],
    ];
}

/* =============================================================
 * ANALYTICS — REVENUE TAB
 * ============================================================= */

/**
 * Build the time series for the Revenue tab.
 *
 * @param PDO    $db
 * @param array  $scope      Output of a scope builder.
 * @param array  $period     Output of fitpal_analytics_period().
 * @return array<int, array{key:string, label:string, short:string, amount:float, orders:int}>
 */
function getRestaurantRevenueSeries(PDO $db, array $scope, array $period): array
{
    $bucketSql = $period['bucket_sql'];
    $interval  = $period['interval_sql'];

    $sql = "
        SELECT
            {$bucketSql} AS bucket_key,
            COALESCE(SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)), 0) AS amount,
            COUNT(DISTINCT o.order_id) AS orders
        FROM orders o
        JOIN queue_item qi ON qi.order_id = o.order_id
        JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
        WHERE o.order_status = 'delivered'
          AND o.delivered_at >= DATE_SUB(CURDATE(), {$interval})
          AND {$scope['where']}
        GROUP BY bucket_key
        ORDER BY bucket_key ASC
    ";

    $stmt = $db->prepare($sql);

    foreach ($scope['bind'] as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    }

    $stmt->execute();

    $byKey = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $byKey[$row['bucket_key']] = [
            'amount' => (float)$row['amount'],
            'orders' => (int)$row['orders'],
        ];
    }

    $series = [];

    if ($period['bucket'] === 'month') {
        for ($i = $period['months'] - 1; $i >= 0; $i--) {
            $ts   = strtotime("first day of -{$i} months");
            $key  = date('Y-m-01', $ts);
            $series[] = [
                'key'    => $key,
                'label'  => date('F Y', $ts),
                'short'  => date('M', $ts),
                'amount' => $byKey[$key]['amount'] ?? 0.0,
                'orders' => $byKey[$key]['orders'] ?? 0,
            ];
        }
        return $series;
    }

    for ($i = $period['days'] - 1; $i >= 0; $i--) {
        $ts   = strtotime("-{$i} days");
        $key  = date('Y-m-d', $ts);
        $series[] = [
            'key'    => $key,
            'label'  => date('l', $ts),
            'short'  => date('D', $ts),
            'amount' => $byKey[$key]['amount'] ?? 0.0,
            'orders' => $byKey[$key]['orders'] ?? 0,
        ];
    }

    return $series;
}

/**
 * Period-aware summary row for the Revenue tab.
 *
 * @return array{
 *   period_revenue: float,
 *   period_orders: int,
 *   period_aov: float,
 *   today_revenue: float,
 *   today_orders: int,
 *   all_time_revenue: float,
 *   all_time_orders: int,
 * }
 */
function getRestaurantRevenueSummary(PDO $db, array $scope, array $period): array
{
    $interval = $period['interval_sql'];

    $sql = "
        SELECT
            COALESCE(SUM(CASE
                WHEN o.delivered_at >= DATE_SUB(CURDATE(), {$interval})
                THEN qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)
                ELSE 0 END), 0) AS period_revenue,
            COUNT(DISTINCT CASE
                WHEN o.delivered_at >= DATE_SUB(CURDATE(), {$interval})
                THEN o.order_id END) AS period_orders,
            COALESCE(SUM(CASE
                WHEN DATE(o.delivered_at) = CURDATE()
                THEN qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)
                ELSE 0 END), 0) AS today_revenue,
            COUNT(DISTINCT CASE
                WHEN DATE(o.delivered_at) = CURDATE()
                THEN o.order_id END) AS today_orders,
            COALESCE(SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)), 0) AS all_time_revenue,
            COUNT(DISTINCT o.order_id) AS all_time_orders
        FROM orders o
        JOIN queue_item qi ON qi.order_id = o.order_id
        JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
        WHERE o.order_status = 'delivered'
          AND {$scope['where']}
    ";

    $stmt = $db->prepare($sql);

    foreach ($scope['bind'] as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    }

    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $periodRevenue = (float)($row['period_revenue'] ?? 0);
    $periodOrders  = (int)($row['period_orders'] ?? 0);

    $aov = $periodOrders > 0
        ? round($periodRevenue / $periodOrders, 2)
        : 0.0;

    return [
        'period_revenue'   => $periodRevenue,
        'period_orders'    => $periodOrders,
        'period_aov'       => $aov,
        'today_revenue'    => (float)($row['today_revenue'] ?? 0),
        'today_orders'     => (int)($row['today_orders'] ?? 0),
        'all_time_revenue' => (float)($row['all_time_revenue'] ?? 0),
        'all_time_orders'  => (int)($row['all_time_orders'] ?? 0),
    ];
}

/* =============================================================
 * ANALYTICS — TRENDING TAB
 * ============================================================= */

/**
 * Top products for the Trending tab.
 *
 * Reports each product's unit sales in the current period and in
 * the period of the same length immediately before it, so the
 * panel can display a change indicator against the previous
 * period.
 *
 * The current-period window is:
 *
 *     [ CURDATE() - N, CURDATE() ]
 *
 * where N is the current period's interval. The previous-period
 * window is:
 *
 *     [ CURDATE() - 2N, CURDATE() - N )
 *
 * Both windows are expressed as a SINGLE INTERVAL passed to
 * DATE_SUB. The doubled boundary is built from the period tuple's
 * own `days` / `months` fields.
 *
 * A prior revision wrote the previous-period boundary as
 *
 *     DATE_SUB(CURDATE(), {$interval} + {$interval})
 *
 * which MySQL rejects as a parse error, because DATE_SUB's second
 * argument must be exactly one `INTERVAL expr unit` construct.
 * The dashboard's try/catch logged the exception and left the
 * panel's data array empty, so every restaurant saw the empty
 * state regardless of how many delivered orders it had.
 *
 * The doubled interval is computed here rather than added to
 * fitpal_analytics_period() so that no other reader has to change.
 *
 * @param PDO   $db
 * @param array $scope   Output of a scope builder.
 * @param array $period  Output of fitpal_analytics_period().
 * @param int   $limit   Maximum number of products to return.
 * @return array<int, array{
 *   product_id:int,
 *   product_name:string,
 *   units_current:int,
 *   units_previous:int,
 *   delta_pct:float|null,
 *   revenue:float
 * }>
 */
function getRestaurantTrendingProducts(
    PDO $db,
    array $scope,
    array $period,
    int $limit = 8
): array {
    $interval = $period['interval_sql'];

    // Build the doubled interval as a SINGLE interval spec. The
    // period tuple carries either a positive `days` value (7d, 30d)
    // or a positive `months` value (12m); never both.
    $previousInterval = $period['months'] > 0
        ? sprintf('INTERVAL %d MONTH', $period['months'] * 2)
        : sprintf('INTERVAL %d DAY',   $period['days']   * 2);

    $sql = "
        SELECT
            p.product_id,
            p.name AS product_name,
            COALESCE(SUM(CASE
                WHEN o.delivered_at >= DATE_SUB(CURDATE(), {$interval})
                THEN qi.queue_quantity
                ELSE 0 END), 0) AS units_current,
            COALESCE(SUM(CASE
                WHEN o.delivered_at >= DATE_SUB(CURDATE(), {$previousInterval})
                 AND o.delivered_at <  DATE_SUB(CURDATE(), {$interval})
                THEN qi.queue_quantity
                ELSE 0 END), 0) AS units_previous,
            COALESCE(SUM(CASE
                WHEN o.delivered_at >= DATE_SUB(CURDATE(), {$interval})
                THEN qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)
                ELSE 0 END), 0) AS revenue_current
        FROM queue_item qi
        JOIN product p ON qi.product_id = p.product_id
        JOIN orders o ON qi.order_id = o.order_id
        JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
        WHERE o.order_status = 'delivered'
          AND {$scope['where']}
        GROUP BY p.product_id
        HAVING units_current > 0
        ORDER BY units_current DESC, revenue_current DESC
        LIMIT :lim
    ";

    $stmt = $db->prepare($sql);

    foreach ($scope['bind'] as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);

    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out  = [];

    foreach ($rows as $row) {
        $current  = (int)$row['units_current'];
        $previous = (int)$row['units_previous'];
        $revenue  = (float)$row['revenue_current'];

        $deltaPct = null;
        if ($previous > 0) {
            $deltaPct = round((($current - $previous) / $previous) * 100, 1);
        } elseif ($current > 0) {
            $deltaPct = 100.0;
        }

        $out[] = [
            'product_id'     => (int)$row['product_id'],
            'product_name'   => (string)$row['product_name'],
            'units_current'  => $current,
            'units_previous' => $previous,
            'delta_pct'      => $deltaPct,
            'revenue'        => $revenue,
        ];
    }

    return $out;
}

/* =============================================================
 * ANALYTICS — DAYS TAB
 * ============================================================= */

function getRestaurantBestSalesDay(PDO $db, array $scope, array $period): array|false
{
    $bucketSql = $period['bucket_sql'];
    $interval  = $period['interval_sql'];

    $sql = "
        SELECT
            {$bucketSql} AS bucket_key,
            COALESCE(SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)), 0) AS amount,
            COUNT(DISTINCT o.order_id) AS orders
        FROM orders o
        JOIN queue_item qi ON qi.order_id = o.order_id
        JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
        WHERE o.order_status = 'delivered'
          AND o.delivered_at >= DATE_SUB(CURDATE(), {$interval})
          AND {$scope['where']}
        GROUP BY bucket_key
        ORDER BY amount DESC, bucket_key ASC
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);

    foreach ($scope['bind'] as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    }

    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return false;
    }

    $key = (string)$row['bucket_key'];
    $label = ($period['bucket'] === 'month')
        ? date('F Y', strtotime($key))
        : date('l, F j, Y', strtotime($key));

    return [
        'key'    => $key,
        'label'  => $label,
        'amount' => (float)$row['amount'],
        'orders' => (int)$row['orders'],
    ];
}

/**
 * Weekday breakdown for the Days tab.
 *
 * @return array<int, array{
 *   weekday:int,
 *   label:string,
 *   short:string,
 *   amount:float,
 *   orders:int,
 *   occurrences:int
 * }>
 */
function getRestaurantWeekdayBreakdown(PDO $db, array $scope, array $period): array
{
    $interval = $period['interval_sql'];

    $sql = "
        SELECT
            WEEKDAY(o.delivered_at) AS weekday,
            COALESCE(SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)), 0) AS amount,
            COUNT(DISTINCT o.order_id) AS orders,
            COUNT(DISTINCT DATE(o.delivered_at)) AS occurrences
        FROM orders o
        JOIN queue_item qi ON qi.order_id = o.order_id
        JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
        WHERE o.order_status = 'delivered'
          AND o.delivered_at >= DATE_SUB(CURDATE(), {$interval})
          AND {$scope['where']}
        GROUP BY WEEKDAY(o.delivered_at)
        ORDER BY weekday ASC
    ";

    $stmt = $db->prepare($sql);

    foreach ($scope['bind'] as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    }

    $stmt->execute();

    $byWeekday = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $byWeekday[(int)$row['weekday']] = [
            'amount'      => (float)$row['amount'],
            'orders'      => (int)$row['orders'],
            'occurrences' => (int)$row['occurrences'],
        ];
    }

    $labels = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $shorts = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    $out = [];
    for ($i = 0; $i < 7; $i++) {
        $out[] = [
            'weekday'     => $i,
            'label'       => $labels[$i],
            'short'       => $shorts[$i],
            'amount'      => $byWeekday[$i]['amount']      ?? 0.0,
            'orders'      => $byWeekday[$i]['orders']      ?? 0,
            'occurrences' => $byWeekday[$i]['occurrences'] ?? 0,
        ];
    }

    return $out;
}

/* =============================================================
 * ANALYTICS — CATEGORIES TAB
 * ============================================================= */

function getRestaurantCategoryBreakdown(PDO $db, array $scope, array $period): array
{
    $interval = $period['interval_sql'];

    $sql = "
        SELECT
            COALESCE(di.category, 'Uncategorized') AS category,
            COALESCE(SUM(qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)), 0) AS amount,
            COALESCE(SUM(qi.queue_quantity), 0) AS units,
            COUNT(DISTINCT o.order_id) AS orders
        FROM orders o
        JOIN queue_item qi ON qi.order_id = o.order_id
        JOIN product p ON qi.product_id = p.product_id
        LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
        JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
        WHERE o.order_status = 'delivered'
          AND o.delivered_at >= DATE_SUB(CURDATE(), {$interval})
          AND {$scope['where']}
        GROUP BY category
        ORDER BY amount DESC
    ";

    $stmt = $db->prepare($sql);

    foreach ($scope['bind'] as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    }

    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total = 0.0;

    foreach ($rows as $row) {
        $total += (float)$row['amount'];
    }

    $out = [];
    foreach ($rows as $row) {
        $amount = (float)$row['amount'];
        $out[] = [
            'category'   => (string)$row['category'],
            'amount'     => $amount,
            'units'      => (int)$row['units'],
            'orders'     => (int)$row['orders'],
            'share_pct'  => $total > 0
                ? round(($amount / $total) * 100, 1)
                : 0.0,
        ];
    }

    return $out;
}