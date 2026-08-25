<?php
/**
 * FitPal Customer Database Queries
 *
 * Customer-specific database operations.
 * All queries use prepared statements.
 *
 * @package FitPal
 * @version 1.1
 */

declare(strict_types=1);

/**
 * Find a customer by email or username.
 *
 * @param PDO    $connection  Shared database connection
 * @param string $identifier  Email address or username
 * @return array|false        Customer row or false if not found
 */
function findCustomerByIdentifier(PDO $connection, string $identifier): array|false {
    $stmt = $connection->prepare(
        "SELECT
            customer_id,
            first_name,
            middle_name,
            last_name,
            email,
            username,
            password,
            contact_number,
            birthdate,
            gender,
            is_active,
            date_created
        FROM customer
        WHERE email = :identifier OR username = :identifier
        LIMIT 1"
    );
    $stmt->execute([':identifier' => trim($identifier)]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get full customer profile including financial account balance.
 *
 * @param PDO $connection  Shared database connection
 * @param int $customerId  Customer primary key
 * @return array|false     Profile row or false if not found
 */
function getCustomerProfile(PDO $connection, int $customerId): array|false {
    $stmt = $connection->prepare(
        "SELECT
            c.customer_id,
            c.first_name,
            c.middle_name,
            c.last_name,
            c.email,
            c.username,
            c.contact_number,
            c.birthdate,
            c.gender,
            c.date_created,
            c.is_active,
            cp.customer_profile_id,
            cp.dietary_preferences,
            cp.allergies,
            cp.fitness_goal,
            cp.height_cm,
            cp.weight_kg,
            cp.profile_picture,
            fa.balance
        FROM customer c
        LEFT JOIN customer_profile cp ON c.customer_id = cp.customer_id
        LEFT JOIN financial_account fa ON cp.financial_account_id = fa.financial_account_id
        WHERE c.customer_id = :customer_id
        LIMIT 1"
    );
    $stmt->execute([':customer_id' => $customerId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Check whether a customer account is active.
 *
 * @param PDO $connection  Shared database connection
 * @param int $customerId  Customer primary key
 * @return bool            True if the account is active
 */
function isCustomerActive(PDO $connection, int $customerId): bool {
    $stmt = $connection->prepare(
        "SELECT is_active FROM customer WHERE customer_id = :customer_id LIMIT 1"
    );
    $stmt->execute([':customer_id' => $customerId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? (bool) $result['is_active'] : false;
}