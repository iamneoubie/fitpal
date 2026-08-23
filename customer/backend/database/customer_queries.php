<?php
/**
 * FitPal Customer Database Queries
 * 
 * Customer-specific database operations.
 * All queries use prepared statements for security.
 * 
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

/**
 * Find a customer by email or username
 * 
 * @param PDO $connection Database connection
 * @param string $identifier Email or username
 * @return array|false Customer data or false if not found
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
 * Get customer profile data
 * 
 * @param PDO $connection Database connection
 * @param int $customerId Customer ID
 * @return array|false Customer profile data or false if not found
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
 * Update last login timestamp
 * 
 * @param PDO $connection Database connection
 * @param int $customerId Customer ID
 * @return bool True on success
 */
function updateCustomerLastLogin(PDO $connection, int $customerId): bool {
    $stmt = $connection->prepare(
        "UPDATE administrator_profile 
         SET last_login = CURRENT_TIMESTAMP 
         WHERE administrator_id = (
             SELECT administrator_id FROM administrator WHERE email = (
                 SELECT email FROM customer WHERE customer_id = :customer_id
             )
         )"
    );
    return $stmt->execute([':customer_id' => $customerId]);
}

/**
 * Check if customer account is active
 * 
 * @param PDO $connection Database connection
 * @param int $customerId Customer ID
 * @return bool True if active
 */
function isCustomerActive(PDO $connection, int $customerId): bool {
    $stmt = $connection->prepare(
        "SELECT is_active FROM customer WHERE customer_id = :customer_id LIMIT 1"
    );
    $stmt->execute([':customer_id' => $customerId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? (bool)$result['is_active'] : false;
}