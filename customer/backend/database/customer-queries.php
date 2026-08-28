<?php
/**
 * Customer and Profile Database Queries
 */

declare(strict_types=1);

/**
 * Find customer by email or username
 */
function findCustomerByIdentifier(PDO $db, string $identifier): array|false
{
    $stmt = $db->prepare(
        "SELECT customer_id, first_name, last_name, email, username, password, is_active 
         FROM customer 
         WHERE email = ? OR username = ? 
         LIMIT 1"
    );
    $stmt->execute([$identifier, $identifier]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get customer profile with financial info
 */
function getCustomerProfile(PDO $db, int $customerId): array|false
{
    $stmt = $db->prepare(
        "SELECT 
            c.customer_id,
            c.first_name,
            c.last_name,
            c.email,
            c.username,
            c.contact_number,
            c.birthdate,
            c.gender,
            cp.dietary_preferences,
            cp.allergies,
            cp.fitness_goal,
            cp.height_cm,
            cp.weight_kg,
            fa.balance
        FROM customer c
        LEFT JOIN customer_profile cp ON c.customer_id = cp.customer_id
        LEFT JOIN financial_account fa ON cp.financial_account_id = fa.financial_account_id
        WHERE c.customer_id = ?"
    );
    $stmt->execute([$customerId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Check if customer account is active
 */
function isCustomerActive(PDO $db, int $customerId): bool
{
    $stmt = $db->prepare(
        "SELECT is_active FROM customer WHERE customer_id = ?"
    );
    $stmt->execute([$customerId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? (bool) $result['is_active'] : false;
}

/**
 * Create new customer account
 * Note: This is used by sign-up-handler.php
 */
function createCustomer(PDO $db, array $data): int
{
    $stmt = $db->prepare(
        "INSERT INTO customer 
            (first_name, middle_name, last_name, birthdate, gender, email, contact_number, username, password, is_active)
         VALUES 
            (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
    );
    $stmt->execute([
        $data['first_name'],
        $data['middle_name'] ?? null,
        $data['last_name'],
        $data['birthdate'],
        $data['gender'],
        $data['email'],
        $data['contact_number'],
        $data['username'],
        $data['password']
    ]);
    return (int) $db->lastInsertId();
}

/**
 * Create customer profile
 */
function createCustomerProfile(PDO $db, int $customerId, int $financialAccountId, array $profileData): void
{
    $stmt = $db->prepare(
        "INSERT INTO customer_profile 
            (customer_id, financial_account_id, dietary_preferences, allergies, fitness_goal, height_cm, weight_kg)
         VALUES 
            (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $customerId,
        $financialAccountId,
        $profileData['dietary_preferences'] ?? null,
        $profileData['allergies'] ?? null,
        $profileData['fitness_goal'] ?? null,
        $profileData['height_cm'] ?? null,
        $profileData['weight_kg'] ?? null
    ]);
}

/**
 * Create financial account
 */
function createFinancialAccount(PDO $db): int
{
    $stmt = $db->prepare(
        "INSERT INTO financial_account (balance, account_type) VALUES (0.00, 'customer')"
    );
    $stmt->execute();
    return (int) $db->lastInsertId();
}

/**
 * Check if email already exists
 */
function emailExists(PDO $db, string $email): bool
{
    $stmt = $db->prepare("SELECT 1 FROM customer WHERE email = ?");
    $stmt->execute([$email]);
    return $stmt->fetch() !== false;
}

/**
 * Check if username already exists
 */
function usernameExists(PDO $db, string $username): bool
{
    $stmt = $db->prepare("SELECT 1 FROM customer WHERE username = ?");
    $stmt->execute([$username]);
    return $stmt->fetch() !== false;
}

/**
 * Check if contact number already exists
 */
function contactExists(PDO $db, string $contact): bool
{
    $stmt = $db->prepare("SELECT 1 FROM customer WHERE contact_number = ?");
    $stmt->execute([$contact]);
    return $stmt->fetch() !== false;
}