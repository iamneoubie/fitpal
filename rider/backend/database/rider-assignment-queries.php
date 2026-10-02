<?php
/**
 * FitPal Rider Assignment Queries
 *
 * (Header of the v13.0 file, unchanged, except for the note below.)
 *
 * ---------------------------------------------------------------------
 * PASSWORD HASH READER (v14.0)
 * ---------------------------------------------------------------------
 * getRiderPasswordHash() returns the rider's stored bcrypt hash and
 * nothing else. It exists as a separate reader from
 * getRiderProfile() on purpose:
 *
 *   - getRiderProfile() is called by the dashboard, the header,
 *     the profile page, and the assignment panel. None of those
 *     callers have any use for a credential. Returning the hash to
 *     all of them would put the value in array variables in every
 *     request that renders a rider page.
 *
 *   - The only caller that needs the hash is
 *     sign-up-handler.php's reapply branch, and only in the branch
 *     where the rider is trying to change their password. That
 *     caller fetches it directly through this reader.
 *
 * The reader returns false when the rider has no row, and a string
 * (possibly the empty string if the column were empty, which the
 * schema does not permit) otherwise.
 *
 * @package FitPal
 * @version 14.0 — Adds getRiderPasswordHash() in Section 1. No
 *                 other function, SQL string, or index changed.
 *
 *                 (13.0: getRiderEmergencyContact(),
 *                 getRiderLatestDocument(), and the two added
 *                 columns on getRiderProfile()'s SELECT. 12.0:
 *                 updateRiderApplication() and the *ForOther
 *                 probes. 11.1: getRiderProfile() normalizes
 *                 `is_available`. 11.0: item list fetched
 *                 separately. 10.0: uniform rider liability.
 *                 9.2: panel readers select full branch address.
 *                 9.1: COD collection. 9.0: rider collection.
 *                 8.0: merged.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/backend/database/order-transaction-queries.php';

/* =============================================================
 * CONSTANTS
 * ============================================================= */

if (!defined('RIDER_CONCURRENT_CAP')) {
    define('RIDER_CONCURRENT_CAP', 3);
}

if (!defined('COMMITTED_RIDER_STATUSES')) {
    define('COMMITTED_RIDER_STATUSES', ['picking_up', 'delivering']);
}

if (!defined('LIVE_RIDER_STATUSES')) {
    define('LIVE_RIDER_STATUSES', ['rider_pending', 'picking_up', 'delivering']);
}

/* =============================================================
 * SECTION 1 — AUTHENTICATION
 * ============================================================= */

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
            dr.birthdate,
            dr.gender,
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
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        return false;
    }

    $row['is_available'] = (int)($row['is_available'] ?? 0);

    return $row;
}

function isRiderActive(PDO $db, int $riderId): bool
{
    $stmt = $db->prepare(
        "SELECT is_active FROM delivery_rider WHERE delivery_rider_id = ?"
    );
    $stmt->execute([$riderId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Read the rider's stored password hash.
 *
 * Returns the bcrypt hash string, or false when no row exists.
 *
 * This reader exists as a separate function from getRiderProfile()
 * so the credential is only fetched by the one caller that needs
 * it — sign-up-handler.php's reapply branch, and only in the branch
 * where the rider is trying to change their password. Every other
 * caller of getRiderProfile() gets no password column.
 *
 * The returned string is a hash, not a plaintext password. It is
 * safe to compare with password_verify() and unsafe to render.
 *
 * @param PDO $db
 * @param int $riderId
 * @return string|false
 */
function getRiderPasswordHash(PDO $db, int $riderId): string|false
{
    $stmt = $db->prepare(
        "SELECT password
           FROM delivery_rider
          WHERE delivery_rider_id = :rider_id
          LIMIT 1"
    );
    $stmt->execute([':rider_id' => $riderId]);
    $hash = $stmt->fetchColumn();

    if ($hash === false) {
        return false;
    }

    return (string)$hash;
}

/* =============================================================
 * SECTIONS 2–10
 *
 * Byte-identical to v13.0. Every function below is unchanged.
 * ============================================================= */

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

function riderAtConcurrentCap(PDO $db, int $riderId): bool
{
    return hasActiveOrder($db, $riderId) >= RIDER_CONCURRENT_CAP;
}

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

    if ($orderStmt->rowCount() !== 1) {
        return false;
    }

    recordRiderLiability($db, $riderId, $orderId);
    recordRiderCollection($db, $riderId, $orderId);

    return true;
}

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

function riderEmailExists(PDO $db, string $email): bool
{
    $stmt = $db->prepare("SELECT 1 FROM delivery_rider WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    return $stmt->fetchColumn() !== false;
}

function riderUsernameExists(PDO $db, string $username): bool
{
    $stmt = $db->prepare("SELECT 1 FROM delivery_rider WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    return $stmt->fetchColumn() !== false;
}

function riderContactExists(PDO $db, string $contact): bool
{
    $stmt = $db->prepare("SELECT 1 FROM delivery_rider WHERE contact_number = ? LIMIT 1");
    $stmt->execute([$contact]);
    return $stmt->fetchColumn() !== false;
}

function riderEmailExistsForOther(PDO $db, string $email, int $excludeRiderId): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM delivery_rider
          WHERE email = :email
            AND delivery_rider_id <> :exclude_id
          LIMIT 1"
    );
    $stmt->execute([
        ':email'      => $email,
        ':exclude_id' => $excludeRiderId,
    ]);
    return $stmt->fetchColumn() !== false;
}

function riderUsernameExistsForOther(PDO $db, string $username, int $excludeRiderId): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM delivery_rider
          WHERE username = :username
            AND delivery_rider_id <> :exclude_id
          LIMIT 1"
    );
    $stmt->execute([
        ':username'   => $username,
        ':exclude_id' => $excludeRiderId,
    ]);
    return $stmt->fetchColumn() !== false;
}

function riderContactExistsForOther(PDO $db, string $contact, int $excludeRiderId): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM delivery_rider
          WHERE contact_number = :contact
            AND delivery_rider_id <> :exclude_id
          LIMIT 1"
    );
    $stmt->execute([
        ':contact'    => $contact,
        ':exclude_id' => $excludeRiderId,
    ]);
    return $stmt->fetchColumn() !== false;
}

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

function updateRiderApplication(
    PDO $db,
    int $riderId,
    array $account,
    array $profile,
    array $address,
    array $emergency,
    array $document,
    string $documentMode
): array {
    if ($documentMode !== 'replace' && $documentMode !== 'keep') {
        throw new InvalidArgumentException(
            "updateRiderApplication(): \$documentMode must be 'replace' or 'keep', "
            . "got '" . $documentMode . "'."
        );
    }

    if ($riderId <= 0) {
        throw new RuntimeException('updateRiderApplication(): invalid rider id.');
    }

    $profileCheck = $db->prepare(
        "SELECT delivery_rider_profile_id, financial_account_id, verification_status
           FROM delivery_rider_profile
          WHERE delivery_rider_id = :rider_id
          LIMIT 1
          FOR UPDATE"
    );
    $profileCheck->execute([':rider_id' => $riderId]);
    $profileRow = $profileCheck->fetch(PDO::FETCH_ASSOC);

    if ($profileRow === false) {
        throw new RuntimeException(
            'updateRiderApplication(): rider profile row not found for rider #' . $riderId
        );
    }

    $financialAccountId = (int)$profileRow['financial_account_id'];

    $accountParams = [
        ':first_name'     => (string)$account['first_name'],
        ':middle_name'    => ((string)($account['middle_name'] ?? '')) !== ''
            ? (string)$account['middle_name']
            : null,
        ':last_name'      => (string)$account['last_name'],
        ':birthdate'      => (string)$account['birthdate'],
        ':gender'         => (string)$account['gender'],
        ':email'          => (string)$account['email'],
        ':contact_number' => (string)$account['contact_number'],
        ':username'       => (string)$account['username'],
        ':rider_id'       => $riderId,
    ];

    $passwordSql = '';
    if (isset($account['password']) && is_string($account['password']) && $account['password'] !== '') {
        $accountParams[':password'] = password_hash($account['password'], PASSWORD_BCRYPT);
        $passwordSql = ', password = :password';
    }

    $updateAccount = $db->prepare(
        "UPDATE delivery_rider
            SET first_name     = :first_name,
                middle_name    = :middle_name,
                last_name      = :last_name,
                birthdate      = :birthdate,
                gender         = :gender,
                email          = :email,
                contact_number = :contact_number,
                username       = :username
                {$passwordSql}
          WHERE delivery_rider_id = :rider_id"
    );
    $updateAccount->execute($accountParams);

    $updateProfile = $db->prepare(
        "UPDATE delivery_rider_profile
            SET profile_picture     = :profile_picture,
                vehicle_type        = :vehicle_type,
                vehicle_plate       = :vehicle_plate,
                verification_status = 'pending'
          WHERE delivery_rider_id = :rider_id"
    );
    $updateProfile->execute([
        ':profile_picture' => (string)($profile['profile_picture'] ?? ''),
        ':vehicle_type'    => (string)($profile['vehicle_type'] ?? ''),
        ':vehicle_plate'   => ((string)($profile['vehicle_plate'] ?? '')) !== ''
            ? (string)$profile['vehicle_plate']
            : null,
        ':rider_id'        => $riderId,
    ]);

    $addressId = 0;
    $addressStmt = $db->prepare(
        "SELECT delivery_rider_address_id
           FROM delivery_rider_address
          WHERE delivery_rider_id = :rider_id
          ORDER BY is_default DESC, delivery_rider_address_id ASC
          LIMIT 1"
    );
    $addressStmt->execute([':rider_id' => $riderId]);
    $existingAddressId = $addressStmt->fetchColumn();

    if ($existingAddressId !== false && (int)$existingAddressId > 0) {
        $addressId = (int)$existingAddressId;

        $updateAddress = $db->prepare(
            "UPDATE delivery_rider_address
                SET block       = :block,
                    barangay    = :barangay,
                    city        = :city,
                    province    = :province,
                    region      = :region,
                    postal_code = :postal_code
              WHERE delivery_rider_address_id = :address_id"
        );
        $updateAddress->execute([
            ':block'       => (string)$address['block'],
            ':barangay'    => ((string)($address['barangay'] ?? '')) !== ''
                ? (string)$address['barangay']
                : null,
            ':city'        => (string)$address['city'],
            ':province'    => ((string)($address['province'] ?? '')) !== ''
                ? (string)$address['province']
                : null,
            ':region'      => ((string)($address['region'] ?? '')) !== ''
                ? (string)$address['region']
                : null,
            ':postal_code' => ((string)($address['postal_code'] ?? '')) !== ''
                ? (string)$address['postal_code']
                : null,
            ':address_id'  => $addressId,
        ]);
    } else {
        $insertAddress = $db->prepare(
            "INSERT INTO delivery_rider_address
                (delivery_rider_id, block, barangay, city,
                 province, region, postal_code, country, is_default)
             VALUES
                (:rider_id, :block, :barangay, :city,
                 :province, :region, :postal_code, 'Philippines', 1)"
        );
        $insertAddress->execute([
            ':rider_id'    => $riderId,
            ':block'       => (string)$address['block'],
            ':barangay'    => ((string)($address['barangay'] ?? '')) !== ''
                ? (string)$address['barangay']
                : null,
            ':city'        => (string)$address['city'],
            ':province'    => ((string)($address['province'] ?? '')) !== ''
                ? (string)$address['province']
                : null,
            ':region'      => ((string)($address['region'] ?? '')) !== ''
                ? (string)$address['region']
                : null,
            ':postal_code' => ((string)($address['postal_code'] ?? '')) !== ''
                ? (string)$address['postal_code']
                : null,
        ]);
        $addressId = (int)$db->lastInsertId();
    }

    $emergencyId = 0;
    $ecStmt = $db->prepare(
        "SELECT emergency_contact_id
           FROM delivery_rider_emergency_contact
          WHERE delivery_rider_id = :rider_id
          ORDER BY emergency_contact_id ASC
          LIMIT 1"
    );
    $ecStmt->execute([':rider_id' => $riderId]);
    $existingEcId = $ecStmt->fetchColumn();

    if ($existingEcId !== false && (int)$existingEcId > 0) {
        $emergencyId = (int)$existingEcId;

        $updateEc = $db->prepare(
            "UPDATE delivery_rider_emergency_contact
                SET first_name     = :first_name,
                    middle_name    = :middle_name,
                    last_name      = :last_name,
                    contact_number = :contact_number,
                    relationship   = :relationship,
                    address        = :address
              WHERE emergency_contact_id = :emergency_id"
        );
        $updateEc->execute([
            ':first_name'     => (string)$emergency['first_name'],
            ':middle_name'    => ((string)($emergency['middle_name'] ?? '')) !== ''
                ? (string)$emergency['middle_name']
                : null,
            ':last_name'      => (string)$emergency['last_name'],
            ':contact_number' => (string)$emergency['contact_number'],
            ':relationship'   => (string)$emergency['relationship'],
            ':address'        => ((string)($emergency['address'] ?? '')) !== ''
                ? (string)$emergency['address']
                : null,
            ':emergency_id'   => $emergencyId,
        ]);
    } else {
        $insertEc = $db->prepare(
            "INSERT INTO delivery_rider_emergency_contact
                (delivery_rider_id, first_name, middle_name, last_name,
                 contact_number, relationship, address)
             VALUES
                (:rider_id, :first_name, :middle_name, :last_name,
                 :contact_number, :relationship, :address)"
        );
        $insertEc->execute([
            ':rider_id'       => $riderId,
            ':first_name'     => (string)$emergency['first_name'],
            ':middle_name'    => ((string)($emergency['middle_name'] ?? '')) !== ''
                ? (string)$emergency['middle_name']
                : null,
            ':last_name'      => (string)$emergency['last_name'],
            ':contact_number' => (string)$emergency['contact_number'],
            ':relationship'   => (string)$emergency['relationship'],
            ':address'        => ((string)($emergency['address'] ?? '')) !== ''
                ? (string)$emergency['address']
                : null,
        ]);
        $emergencyId = (int)$db->lastInsertId();
    }

    if ($documentMode === 'replace') {
        $deleteDocs = $db->prepare(
            "DELETE FROM delivery_rider_document
              WHERE delivery_rider_id = :rider_id
                AND id_type = :id_type"
        );
        $deleteDocs->execute([
            ':rider_id' => $riderId,
            ':id_type'  => (string)$document['id_type'],
        ]);
    }

    $insertDoc = $db->prepare(
        "INSERT INTO delivery_rider_document
            (delivery_rider_id, id_type, id_path, issue_date, expiry_date)
         VALUES
            (:rider_id, :id_type, :id_path, :issue_date, :expiry_date)"
    );
    $insertDoc->execute([
        ':rider_id'    => $riderId,
        ':id_type'     => (string)$document['id_type'],
        ':id_path'     => (string)$document['id_path'],
        ':issue_date'  => ((string)($document['issue_date'] ?? '')) !== ''
            ? (string)$document['issue_date']
            : null,
        ':expiry_date' => ((string)($document['expiry_date'] ?? '')) !== ''
            ? (string)$document['expiry_date']
            : null,
    ]);

    return [
        'delivery_rider_id'    => $riderId,
        'financial_account_id' => $financialAccountId,
    ];
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

    $payout = FITPAL_DELIVERY_BASE_FEE;

    $stmt = $db->prepare(
        "SELECT
            COALESCE(SUM(CASE
                WHEN o.order_status = 'delivered'
                 AND DATE(o.delivered_at) = CURDATE()
                THEN :p1 ELSE 0 END), 0) AS today_earnings,
            COUNT(DISTINCT CASE
                WHEN o.order_status = 'delivered'
                 AND DATE(o.delivered_at) = CURDATE()
                THEN o.order_id END) AS today_deliveries,
            COALESCE(SUM(CASE
                WHEN o.order_status = 'delivered'
                 AND o.delivered_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                THEN :p2 ELSE 0 END), 0) AS week_earnings,
            COUNT(DISTINCT CASE
                WHEN o.order_status = 'delivered'
                 AND o.delivered_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                THEN o.order_id END) AS week_deliveries,
            COALESCE(SUM(CASE
                WHEN o.order_status = 'delivered'
                 AND o.delivered_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                THEN :p3 ELSE 0 END), 0) AS month_earnings,
            COUNT(DISTINCT CASE
                WHEN o.order_status = 'delivered'
                 AND o.delivered_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                THEN o.order_id END) AS month_deliveries,
            COALESCE(SUM(CASE
                WHEN o.order_status = 'delivered'
                THEN :p4 ELSE 0 END), 0) AS total_earnings,
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
                WHEN o.order_status IN ('cancelled','failed')
                 AND o.delivery_rider_id IS NOT NULL
                THEN o.order_id END) AS total_cancelled
         FROM orders o
         WHERE o.delivery_rider_id = :rider_id"
    );
    $stmt->bindValue(':p1', $payout);
    $stmt->bindValue(':p2', $payout);
    $stmt->bindValue(':p3', $payout);
    $stmt->bindValue(':p4', $payout);
    $stmt->bindValue(':rider_id', $riderId, PDO::PARAM_INT);
    $stmt->execute();
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
                   SUM(:payout) AS daily_earnings
            FROM orders o
            WHERE o.delivery_rider_id = :rider_id
              AND o.order_status = 'delivered'
              AND o.delivered_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
            GROUP BY DATE(o.delivered_at)
         ) AS daily"
    );
    $stmt->bindValue(':payout', $payout);
    $stmt->bindValue(':rider_id', $riderId, PDO::PARAM_INT);
    $stmt->execute();
    $stats['week_earnings_max'] = (float)$stmt->fetchColumn();

    return $stats;
}

function getRiderWeeklyEarnings(PDO $db, int $riderId, int $days = 7): array
{
    $payout = FITPAL_DELIVERY_BASE_FEE;

    $stmt = $db->prepare(
        "SELECT
            DATE(o.delivered_at) AS day,
            COUNT(DISTINCT o.order_id) AS deliveries,
            SUM(:payout) AS amount
         FROM orders o
         WHERE o.delivery_rider_id = :rider_id
           AND o.order_status = 'delivered'
           AND o.delivered_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
         GROUP BY DATE(o.delivered_at)
         ORDER BY day ASC"
    );
    $stmt->bindValue(':payout', $payout);
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

    $payout = FITPAL_DELIVERY_BASE_FEE;

    foreach ($rows as &$row) {
        $row['rider_earning'] = $payout;
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

function getRiderOutstandingCollections(PDO $db, int $riderId): array
{
    $stmt = $db->prepare(
        "SELECT
            COALESCE(SUM(rider_liability_amount), 0) AS total,
            COUNT(*) AS count
         FROM orders
         WHERE delivery_rider_id = :rider_id
           AND rider_liability_amount IS NOT NULL
           AND order_status IN ('picking_up', 'delivering')"
    );
    $stmt->execute([':rider_id' => $riderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total' => (float)($row['total'] ?? 0),
        'count' => (int)($row['count'] ?? 0),
    ];
}

function getRiderCollectionHistory(PDO $db, int $riderId, int $limit = 20): array
{
    $stmt = $db->prepare(
        "SELECT
            rc.rider_collection_id,
            rc.order_id,
            rc.amount,
            rc.status,
            rc.collected_at,
            rc.settled_at,
            rc.notes,
            rc.created_at,
            rc.updated_at
         FROM rider_collection rc
         WHERE rc.delivery_rider_id = :rider_id
         ORDER BY rc.created_at DESC, rc.rider_collection_id DESC
         LIMIT :limit"
    );
    $stmt->bindValue(':rider_id', $riderId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

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
           AND o.order_status IN ('delivered', 'cancelled', 'refunded', 'failed')
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

function getRiderEmergencyContact(PDO $db, int $riderId): array|false
{
    $stmt = $db->prepare(
        "SELECT
            emergency_contact_id,
            delivery_rider_id,
            first_name,
            middle_name,
            last_name,
            contact_number,
            relationship,
            address,
            created_at,
            updated_at
         FROM delivery_rider_emergency_contact
         WHERE delivery_rider_id = :rider_id
         ORDER BY emergency_contact_id ASC
         LIMIT 1"
    );
    $stmt->execute([':rider_id' => $riderId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getRiderLatestDocument(PDO $db, int $riderId): array|false
{
    $stmt = $db->prepare(
        "SELECT
            document_id,
            delivery_rider_id,
            id_type,
            id_path,
            issue_date,
            expiry_date,
            created_at,
            updated_at
         FROM delivery_rider_document
         WHERE delivery_rider_id = :rider_id
         ORDER BY document_id DESC
         LIMIT 1"
    );
    $stmt->execute([':rider_id' => $riderId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

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

function getPanelAssignments(PDO $db, int $riderId, int $limit = 20): array
{
    if ($riderId <= 0) {
        return [];
    }

    $stmt = $db->prepare(
        "SELECT
            o.order_id,
            o.order_status,
            o.order_date,
            o.destination_address,
            o.payment_method,
            c.customer_id,
            CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
            c.contact_number AS customer_contact,
            rb.restaurant_branch_id,
            rb.branch_name,
            rb.block        AS branch_block,
            rb.barangay     AS branch_barangay,
            rb.city         AS branch_city,
            rb.province     AS branch_province,
            rb.region       AS branch_region,
            rb.postal_code  AS branch_postal_code,
            rb.country      AS branch_country,
            r.restaurant_id,
            r.business_name AS restaurant_name,
            (
                SELECT ra.contact_number
                  FROM restaurant_account ra
                 WHERE ra.restaurant_id = r.restaurant_id
                   AND ra.is_active = 1
                 ORDER BY FIELD(ra.role, 'owner', 'manager', 'staff') ASC,
                          ra.restaurant_account_id ASC
                 LIMIT 1
            ) AS kitchen_contact,
            (
                SELECT COUNT(*)
                  FROM queue_item qi
                 WHERE qi.order_id = o.order_id
            ) AS item_count,
            (
                SELECT COALESCE(SUM(qi.queue_quantity
                                    * COALESCE(qi.final_price, qi.unit_price)), 0)
                  FROM queue_item qi
                 WHERE qi.order_id = o.order_id
            ) AS order_total
         FROM orders o
         JOIN customer c ON o.customer_id = c.customer_id
         JOIN queue_item qi0 ON qi0.order_id = o.order_id
         JOIN restaurant_branch rb ON qi0.branch_id = rb.restaurant_branch_id
         JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
         WHERE o.delivery_rider_id = :rider_id
           AND o.order_status IN ('rider_pending', 'picking_up', 'delivering')
         GROUP BY o.order_id
         ORDER BY
            FIELD(o.order_status, 'rider_pending', 'picking_up', 'delivering') ASC,
            o.order_date ASC
         LIMIT :limit"
    );
    $stmt->bindValue(':rider_id', $riderId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getPanelAssignmentsSince(PDO $db, int $riderId, int $sinceOrderId): array
{
    if ($riderId <= 0) {
        return [];
    }

    $stmt = $db->prepare(
        "SELECT
            o.order_id,
            o.order_status,
            o.order_date,
            o.destination_address,
            o.payment_method,
            c.customer_id,
            CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
            c.contact_number AS customer_contact,
            rb.restaurant_branch_id,
            rb.branch_name,
            rb.block        AS branch_block,
            rb.barangay     AS branch_barangay,
            rb.city         AS branch_city,
            rb.province     AS branch_province,
            rb.region       AS branch_region,
            rb.postal_code  AS branch_postal_code,
            rb.country      AS branch_country,
            r.restaurant_id,
            r.business_name AS restaurant_name,
            (
                SELECT ra.contact_number
                  FROM restaurant_account ra
                 WHERE ra.restaurant_id = r.restaurant_id
                   AND ra.is_active = 1
                 ORDER BY FIELD(ra.role, 'owner', 'manager', 'staff') ASC,
                          ra.restaurant_account_id ASC
                 LIMIT 1
            ) AS kitchen_contact,
            (
                SELECT COUNT(*)
                  FROM queue_item qi
                 WHERE qi.order_id = o.order_id
            ) AS item_count,
            (
                SELECT COALESCE(SUM(qi.queue_quantity
                                    * COALESCE(qi.final_price, qi.unit_price)), 0)
                  FROM queue_item qi
                 WHERE qi.order_id = o.order_id
            ) AS order_total
         FROM orders o
         JOIN customer c ON o.customer_id = c.customer_id
         JOIN queue_item qi0 ON qi0.order_id = o.order_id
         JOIN restaurant_branch rb ON qi0.branch_id = rb.restaurant_branch_id
         JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
         WHERE o.delivery_rider_id = :rider_id
           AND o.order_id > :since_order_id
           AND o.order_status IN ('rider_pending', 'picking_up', 'delivering')
         GROUP BY o.order_id
         ORDER BY o.order_id ASC"
    );
    $stmt->execute([
        ':rider_id'       => $riderId,
        ':since_order_id' => $sinceOrderId,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getPanelAssignmentRow(PDO $db, int $riderId, int $orderId): array|false
{
    if ($riderId <= 0 || $orderId <= 0) {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT
            o.order_id,
            o.order_status,
            o.order_date,
            o.destination_address,
            o.payment_method,
            c.customer_id,
            CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
            c.contact_number AS customer_contact,
            rb.restaurant_branch_id,
            rb.branch_name,
            rb.block        AS branch_block,
            rb.barangay     AS branch_barangay,
            rb.city         AS branch_city,
            rb.province     AS branch_province,
            rb.region       AS branch_region,
            rb.postal_code  AS branch_postal_code,
            rb.country      AS branch_country,
            r.restaurant_id,
            r.business_name AS restaurant_name,
            (
                SELECT ra.contact_number
                  FROM restaurant_account ra
                 WHERE ra.restaurant_id = r.restaurant_id
                   AND ra.is_active = 1
                 ORDER BY FIELD(ra.role, 'owner', 'manager', 'staff') ASC,
                          ra.restaurant_account_id ASC
                 LIMIT 1
            ) AS kitchen_contact,
            (
                SELECT COUNT(*)
                  FROM queue_item qi
                 WHERE qi.order_id = o.order_id
            ) AS item_count,
            (
                SELECT COALESCE(SUM(qi.queue_quantity
                                    * COALESCE(qi.final_price, qi.unit_price)), 0)
                  FROM queue_item qi
                 WHERE qi.order_id = o.order_id
            ) AS order_total
         FROM orders o
         JOIN customer c ON o.customer_id = c.customer_id
         JOIN queue_item qi0 ON qi0.order_id = o.order_id
         JOIN restaurant_branch rb ON qi0.branch_id = rb.restaurant_branch_id
         JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
         WHERE o.order_id = :order_id
           AND o.delivery_rider_id = :rider_id
           AND o.order_status IN ('rider_pending', 'picking_up', 'delivering')
         GROUP BY o.order_id
         LIMIT 1"
    );
    $stmt->execute([
        ':order_id' => $orderId,
        ':rider_id' => $riderId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: false;
}

function getPanelMaxOrderId(PDO $db, int $riderId): int
{
    if ($riderId <= 0) {
        return 0;
    }

    $stmt = $db->prepare(
        "SELECT COALESCE(MAX(order_id), 0)
           FROM orders
          WHERE delivery_rider_id = :rider_id
            AND order_status IN ('rider_pending', 'picking_up', 'delivering')"
    );
    $stmt->execute([':rider_id' => $riderId]);
    return (int)$stmt->fetchColumn();
}

function getPanelAssignmentCounts(PDO $db, int $riderId): array
{
    if ($riderId <= 0) {
        return ['pending' => 0, 'picking_up' => 0, 'active' => 0, 'total' => 0];
    }

    $stmt = $db->prepare(
        "SELECT
            SUM(CASE WHEN order_status = 'rider_pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN order_status = 'picking_up'    THEN 1 ELSE 0 END) AS picking_up,
            SUM(CASE WHEN order_status = 'delivering'    THEN 1 ELSE 0 END) AS active,
            COUNT(*) AS total
         FROM orders
         WHERE delivery_rider_id = :rider_id
           AND order_status IN ('rider_pending', 'picking_up', 'delivering')"
    );
    $stmt->execute([':rider_id' => $riderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'pending'    => (int)($row['pending']    ?? 0),
        'picking_up' => (int)($row['picking_up'] ?? 0),
        'active'     => (int)($row['active']     ?? 0),
        'total'      => (int)($row['total']      ?? 0),
    ];
}

function resolvePanelMessageRecipient(PDO $db, int $orderId, string $channel): int
{
    if ($orderId <= 0) {
        return 0;
    }

    if ($channel === 'customer') {
        $stmt = $db->prepare(
            "SELECT customer_id
               FROM orders
              WHERE order_id = :order_id
              LIMIT 1"
        );
        $stmt->execute([':order_id' => $orderId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    if ($channel === 'restaurant_account') {
        $stmt = $db->prepare(
            "SELECT ra.restaurant_account_id
               FROM queue_item qi
               JOIN restaurant_branch rb ON rb.restaurant_branch_id = qi.branch_id
               JOIN restaurant_account ra ON ra.restaurant_id = rb.restaurant_id
              WHERE qi.order_id = :order_id
                AND ra.is_active = 1
              ORDER BY FIELD(ra.role, 'owner', 'manager', 'staff', 'cashier', 'kitchen') ASC,
                       ra.restaurant_account_id ASC
              LIMIT 1"
        );
        $stmt->execute([':order_id' => $orderId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    return 0;
}

function panelShouldNotify(PDO $db, int $riderId, int $orderId): bool
{
    if ($riderId <= 0 || $orderId <= 0) {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT
            o.order_status,
            drp.verification_status,
            drp.is_available
         FROM orders o
         JOIN delivery_rider_profile drp
              ON drp.delivery_rider_id = o.delivery_rider_id
         WHERE o.order_id = :order_id
           AND o.delivery_rider_id = :rider_id
         LIMIT 1"
    );
    $stmt->execute([
        ':order_id' => $orderId,
        ':rider_id' => $riderId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return false;
    }

    if ((string)$row['order_status'] !== 'rider_pending') {
        return false;
    }
    if ((string)$row['verification_status'] !== 'verified') {
        return false;
    }
    if ((int)$row['is_available'] !== 1) {
        return false;
    }

    return true;
}

function panelRiderIsEligible(PDO $db, int $riderId): bool
{
    if ($riderId <= 0) {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT 1
           FROM delivery_rider dr
           JOIN delivery_rider_profile drp
                ON dr.delivery_rider_id = drp.delivery_rider_id
          WHERE dr.delivery_rider_id = :rider_id
            AND dr.is_active = 1
            AND drp.verification_status = 'verified'
          LIMIT 1"
    );
    $stmt->execute([':rider_id' => $riderId]);
    return $stmt->fetchColumn() !== false;
}

function panelStatusLabel(string $status): string
{
    return match ($status) {
        'rider_pending' => 'Awaiting Your Decision',
        'picking_up'    => 'Head to Pickup',
        'delivering'    => 'In Transit',
        default         => ucfirst($status),
    };
}

function panelStatusBadge(string $status): string
{
    return match ($status) {
        'rider_pending' => 'badge-warning',
        'picking_up'    => 'badge-warning',
        'delivering'    => 'badge-primary',
        default         => 'badge-secondary',
    };
}

function panelMessageChannel(string $status): ?string
{
    return match ($status) {
        'rider_pending' => 'restaurant_account',
        'picking_up'    => 'restaurant_account',
        'delivering'    => 'customer',
        default         => null,
    };
}