<?php
/**
 * FitPal Restaurant Branch Database Queries
 *
 * Pure data-access layer for restaurant_branch and restaurant.
 * No cart logic, no customer logic, no $_POST, no header().
 *
 * @package FitPal
 * @version 2.0
 */

declare(strict_types=1);

/**
 * Get branch details by ID (with its parent restaurant).
 *
 * @param PDO $db
 * @param int $branchId
 * @return array<string, mixed>|false
 */
function getBranchById(PDO $db, int $branchId): array|false
{
    $stmt = $db->prepare(
        "SELECT
            rb.restaurant_branch_id AS branch_id,
            rb.branch_name,
            rb.branch_code,
            rb.block,
            rb.barangay,
            rb.city,
            rb.province,
            rb.region,
            rb.postal_code,
            rb.country,
            rb.is_active,
            r.restaurant_id,
            r.business_name AS restaurant_name,
            r.cuisine_type,
            r.dietary_tags AS restaurant_dietary_tags
         FROM restaurant_branch rb
         JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
         WHERE rb.restaurant_branch_id = :branch_id
           AND rb.is_active = 1
           AND r.is_active = 1"
    );
    $stmt->execute([':branch_id' => $branchId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get all active branches for a restaurant.
 *
 * @param PDO $db
 * @param int $restaurantId
 * @return array<int, array<string, mixed>>
 */
function getBranchesByRestaurant(PDO $db, int $restaurantId): array
{
    $stmt = $db->prepare(
        "SELECT
            rb.restaurant_branch_id AS branch_id,
            rb.branch_name,
            rb.branch_code,
            rb.block,
            rb.barangay,
            rb.city,
            rb.province,
            rb.is_active,
            COUNT(p.product_id) AS product_count
         FROM restaurant_branch rb
         LEFT JOIN product p ON rb.restaurant_branch_id = p.restaurant_branch_id
                              AND p.is_active = 1
         WHERE rb.restaurant_id = :restaurant_id
           AND rb.is_active = 1
         GROUP BY rb.restaurant_branch_id
         ORDER BY rb.branch_name"
    );
    $stmt->execute([':restaurant_id' => $restaurantId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get a branch together with its active products.
 *
 * @param PDO $db
 * @param int $branchId
 * @return array<string, mixed>|false
 */
function getBranchWithProducts(PDO $db, int $branchId): array|false
{
    $branch = getBranchById($db, $branchId);
    if (!$branch) {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT
            p.product_id,
            p.name AS product_name,
            p.description,
            p.price,
            p.stock,
            p.is_active,
            di.dietary_tags,
            di.allergens,
            di.calories,
            di.protein,
            di.carbs,
            di.fat,
            di.images AS product_image
         FROM product p
         LEFT JOIN dietary_information di
                ON p.dietary_information_id = di.dietary_information_id
         WHERE p.restaurant_branch_id = :branch_id
           AND p.is_active = 1
         ORDER BY p.name"
    );
    $stmt->execute([':branch_id' => $branchId]);
    $branch['products']      = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $branch['product_count'] = count($branch['products']);

    return $branch;
}

/**
 * Check whether a product belongs to a specific branch.
 *
 * @param PDO $db
 * @param int $productId
 * @param int $branchId
 * @return bool
 */
function isProductInBranch(PDO $db, int $productId, int $branchId): bool
{
    $stmt = $db->prepare(
        "SELECT 1
         FROM product
         WHERE product_id = :product_id
           AND restaurant_branch_id = :branch_id
           AND is_active = 1"
    );
    $stmt->execute([
        ':product_id' => $productId,
        ':branch_id'  => $branchId,
    ]);
    return $stmt->fetch() !== false;
}

/**
 * Get the branch ID that a product belongs to.
 *
 * @param PDO $db
 * @param int $productId
 * @return int|false
 */
function getProductBranchId(PDO $db, int $productId): int|false
{
    $stmt = $db->prepare(
        "SELECT restaurant_branch_id
         FROM product
         WHERE product_id = :product_id AND is_active = 1"
    );
    $stmt->execute([':product_id' => $productId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? (int)$result['restaurant_branch_id'] : false;
}