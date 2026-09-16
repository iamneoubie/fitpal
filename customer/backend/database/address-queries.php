<?php
/**
 * FitPal Address Database Queries
 *
 * Pure data-access layer for customer_address. No $_POST, no header(),
 * no echo.
 *
 * The "default address" concept is tracked entirely inside
 * customer_address.is_default. There is no customer.customer_address_id
 * column in the current schema.
 *
 * Only customer pages that deal with addresses should require this file
 * (checkout.php, profile.php). Menu and other browse-only pages do not.
 *
 * @package FitPal
 * @version 2.1 — Scoped ownership checks, boolean returns from mutators
 */

declare(strict_types=1);

/**
 * Get all addresses for a customer, default first.
 *
 * @param PDO $db
 * @param int $customerId
 * @return array<int, array<string, mixed>>
 */
function getCustomerAddresses(PDO $db, int $customerId): array
{
    $stmt = $db->prepare(
        "SELECT
            customer_address_id,
            label,
            block,
            barangay,
            city,
            province,
            region,
            postal_code,
            country,
            is_default
         FROM customer_address
         WHERE customer_id = :customer_id
         ORDER BY is_default DESC, customer_address_id ASC"
    );
    $stmt->execute([':customer_id' => $customerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get a single address by ID, scoped to the owning customer.
 *
 * Scoping is mandatory: without it, a caller could pass an address ID
 * belonging to another customer and receive that row.
 *
 * @param PDO $db
 * @param int $addressId
 * @param int $customerId
 * @return array<string, mixed>|false
 */
function getAddressById(PDO $db, int $addressId, int $customerId): array|false
{
    $stmt = $db->prepare(
        "SELECT
            customer_address_id,
            customer_id,
            label,
            block,
            barangay,
            city,
            province,
            region,
            postal_code,
            country,
            is_default
         FROM customer_address
         WHERE customer_address_id = :address_id
           AND customer_id         = :customer_id"
    );
    $stmt->execute([
        ':address_id'  => $addressId,
        ':customer_id' => $customerId,
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Insert a new address for a customer and return its ID.
 *
 * If the customer has no other addresses, this one is automatically
 * marked as default. Otherwise `is_default` comes from the caller.
 *
 * @param PDO $db
 * @param int $customerId
 * @param array<string, mixed> $data
 * @return int
 */
function createAddress(PDO $db, int $customerId, array $data): int
{
    $check = $db->prepare(
        "SELECT COUNT(*) FROM customer_address WHERE customer_id = :customer_id"
    );
    $check->execute([':customer_id' => $customerId]);
    $isFirst = ((int)$check->fetchColumn()) === 0;

    $isDefault = $isFirst ? 1 : (int)($data['is_default'] ?? 0);

    if ($isDefault === 1) {
        $clear = $db->prepare(
            "UPDATE customer_address
                SET is_default = 0
              WHERE customer_id = :customer_id"
        );
        $clear->execute([':customer_id' => $customerId]);
    }

    $stmt = $db->prepare(
        "INSERT INTO customer_address
            (customer_id, label, block, barangay, city, province,
             region, postal_code, country, is_default)
         VALUES
            (:customer_id, :label, :block, :barangay, :city, :province,
             :region, :postal_code, :country, :is_default)"
    );
    $stmt->execute([
        ':customer_id' => $customerId,
        ':label'       => $data['label']       !== '' ? $data['label']       : null,
        ':block'       => $data['block']       !== '' ? $data['block']       : null,
        ':barangay'    => $data['barangay']    !== '' ? $data['barangay']    : null,
        ':city'        => $data['city'],
        ':province'    => $data['province']    !== '' ? $data['province']    : null,
        ':region'      => $data['region']      !== '' ? $data['region']      : null,
        ':postal_code' => $data['postal_code'] !== '' ? $data['postal_code'] : null,
        ':country'     => $data['country']     !== '' ? $data['country']     : 'Philippines',
        ':is_default'  => $isDefault,
    ]);
    return (int)$db->lastInsertId();
}

/**
 * Update an existing address, scoped to the owning customer.
 *
 * Returns true if a row was actually changed. Returns false if the
 * address does not belong to the customer, or if the submitted values
 * are identical to what's already stored (MySQL reports 0 for a no-op
 * UPDATE). The handler is expected to verify existence separately when
 * it needs to distinguish those two cases.
 *
 * @param PDO $db
 * @param int $addressId
 * @param int $customerId
 * @param array<string, mixed> $data
 * @return bool
 */
function updateAddress(PDO $db, int $addressId, int $customerId, array $data): bool
{
    $stmt = $db->prepare(
        "UPDATE customer_address
            SET label       = :label,
                block       = :block,
                barangay    = :barangay,
                city        = :city,
                province    = :province,
                region      = :region,
                postal_code = :postal_code,
                country     = :country
          WHERE customer_address_id = :address_id
            AND customer_id         = :customer_id"
    );
    $stmt->execute([
        ':label'       => $data['label']       !== '' ? $data['label']       : null,
        ':block'       => $data['block']       !== '' ? $data['block']       : null,
        ':barangay'    => $data['barangay']    !== '' ? $data['barangay']    : null,
        ':city'        => $data['city'],
        ':province'    => $data['province']    !== '' ? $data['province']    : null,
        ':region'      => $data['region']      !== '' ? $data['region']      : null,
        ':postal_code' => $data['postal_code'] !== '' ? $data['postal_code'] : null,
        ':country'     => $data['country']     !== '' ? $data['country']     : 'Philippines',
        ':address_id'  => $addressId,
        ':customer_id' => $customerId,
    ]);

    return $stmt->rowCount() > 0;
}

/**
 * Delete an address, scoped to the owning customer. Refuses to delete
 * the customer's only remaining address. If the deleted address was
 * the default and others remain, promotes the lowest-ID address to
 * default.
 *
 * @param PDO $db
 * @param int $addressId
 * @param int $customerId
 * @return bool  True if a row was deleted, false otherwise.
 */
function deleteAddress(PDO $db, int $addressId, int $customerId): bool
{
    $row = $db->prepare(
        "SELECT is_default FROM customer_address
          WHERE customer_address_id = :address_id
            AND customer_id         = :customer_id"
    );
    $row->execute([':address_id' => $addressId, ':customer_id' => $customerId]);
    $address = $row->fetch(PDO::FETCH_ASSOC);
    if (!$address) {
        return false;
    }

    $count = $db->prepare(
        "SELECT COUNT(*) FROM customer_address WHERE customer_id = :customer_id"
    );
    $count->execute([':customer_id' => $customerId]);
    if ((int)$count->fetchColumn() <= 1) {
        return false;
    }

    $del = $db->prepare(
        "DELETE FROM customer_address
          WHERE customer_address_id = :address_id
            AND customer_id         = :customer_id"
    );
    $del->execute([':address_id' => $addressId, ':customer_id' => $customerId]);

    if ($del->rowCount() === 0) {
        return false;
    }

    if ((int)$address['is_default'] === 1) {
        $promote = $db->prepare(
            "UPDATE customer_address
                SET is_default = 1
              WHERE customer_id = :customer_id
              ORDER BY customer_address_id ASC
              LIMIT 1"
        );
        $promote->execute([':customer_id' => $customerId]);
    }

    return true;
}

/**
 * Mark a specific address as the customer's default, clearing all
 * others. Scoped to the owning customer.
 *
 * @param PDO $db
 * @param int $customerId
 * @param int $addressId
 * @return bool  False if the address does not belong to the customer.
 */
function setCustomerDefaultAddress(PDO $db, int $customerId, int $addressId): bool
{
    $check = $db->prepare(
        "SELECT 1 FROM customer_address
          WHERE customer_address_id = :address_id
            AND customer_id         = :customer_id"
    );
    $check->execute([':address_id' => $addressId, ':customer_id' => $customerId]);
    if ($check->fetchColumn() === false) {
        return false;
    }

    $clear = $db->prepare(
        "UPDATE customer_address
            SET is_default = 0
          WHERE customer_id = :customer_id"
    );
    $clear->execute([':customer_id' => $customerId]);

    $set = $db->prepare(
        "UPDATE customer_address
            SET is_default = 1
          WHERE customer_address_id = :address_id
            AND customer_id         = :customer_id"
    );
    $set->execute([':address_id' => $addressId, ':customer_id' => $customerId]);

    return true;
}

/**
 * Get a customer's currently-selected default address ID (0 if none).
 *
 * @param PDO $db
 * @param int $customerId
 * @return int
 */
function getCustomerDefaultAddressId(PDO $db, int $customerId): int
{
    $stmt = $db->prepare(
        "SELECT customer_address_id
           FROM customer_address
          WHERE customer_id = :customer_id
            AND is_default  = 1
          LIMIT 1"
    );
    $stmt->execute([':customer_id' => $customerId]);
    return (int)($stmt->fetchColumn() ?: 0);
}