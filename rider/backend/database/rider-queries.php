<?php
/**
 * FitPal Rider Database Queries
 *
 * Pure data-access layer for the delivery_rider and
 * delivery_rider_profile tables. No $_POST, no header(), no echo.
 *
 * This file is safe to require from any page — it only declares
 * functions and performs no request dispatch at load time.
 *
 * @package FitPal
 * @version 1.2 — findRiderByIdentifier() uses two distinct named
 *                placeholders. MySQL with ATTR_EMULATE_PREPARES=false
 *                does not allow a named placeholder to appear twice
 *                in the same statement.
 */

declare(strict_types=1);

/**
 * Find a rider by email or username.
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

/**
 * Get a rider's full profile including financial balance.
 *
 * @param PDO $db
 * @param int $riderId
 * @return array<string, mixed>|false
 */
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

/**
 * Check if the rider account is active.
 *
 * @param PDO $db
 * @param int $riderId
 * @return bool
 */
function isRiderActive(PDO $db, int $riderId): bool
{
    $stmt = $db->prepare(
        "SELECT is_active FROM delivery_rider WHERE delivery_rider_id = ?"
    );
    $stmt->execute([$riderId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Format a monetary amount as Philippine pesos.
 *
 * @param int|float|string|null $amount
 * @return string
 */
function formatRiderCurrency(int|float|string|null $amount): string
{
    return '₱' . number_format((float)($amount ?? 0), 2);
}