<?php
/**
 * Restaurant Branch Database Queries
 *
 * Handles all branch-related database operations for customer ordering.
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

/**
 * Get branch details by ID
 *
 * @param PDO $db Database connection
 * @param int $branchId Branch ID
 * @return array|false Branch details or false if not found
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
 * Get all active branches for a restaurant
 *
 * @param PDO $db Database connection
 * @param int $restaurantId Restaurant ID
 * @return array List of branches
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
        LEFT JOIN product p ON rb.restaurant_branch_id = p.restaurant_branch_id AND p.is_active = 1
        WHERE rb.restaurant_id = :restaurant_id
        AND rb.is_active = 1
        GROUP BY rb.restaurant_branch_id
        ORDER BY rb.branch_name"
    );
    $stmt->execute([':restaurant_id' => $restaurantId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get branch with its active products
 *
 * @param PDO $db Database connection
 * @param int $branchId Branch ID
 * @return array|false Branch with products or false if not found
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
        LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
        WHERE p.restaurant_branch_id = :branch_id
        AND p.is_active = 1
        ORDER BY p.name"
    );
    $stmt->execute([':branch_id' => $branchId]);
    $branch['products'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $branch['product_count'] = count($branch['products']);

    return $branch;
}

/**
 * Check if a product belongs to a specific branch
 *
 * @param PDO $db Database connection
 * @param int $productId Product ID
 * @param int $branchId Branch ID
 * @return bool True if product belongs to branch
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
        ':branch_id' => $branchId
    ]);
    return $stmt->fetch() !== false;
}

/**
 * Get the branch ID for a product
 *
 * @param PDO $db Database connection
 * @param int $productId Product ID
 * @return int|false Branch ID or false if product not found
 */
function getProductBranchId(PDO $db, int $productId): int|false
{
    $stmt = $db->prepare(
        "SELECT restaurant_branch_id FROM product WHERE product_id = :product_id AND is_active = 1"
    );
    $stmt->execute([':product_id' => $productId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? (int)$result['restaurant_branch_id'] : false;
}

/**
 * Get all branch IDs from cart items
 *
 * @param PDO $db Database connection
 * @param int $customerId Customer ID
 * @return array Array of branch IDs from items in cart
 */
function getCartBranchIds(PDO $db, int $customerId): array
{
    $stmt = $db->prepare(
        "SELECT DISTINCT p.restaurant_branch_id
         FROM cart c
         JOIN product p ON c.product_id = p.product_id
         WHERE c.customer_id = :customer_id"
    );
    $stmt->execute([':customer_id' => $customerId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Get cart items grouped by branch
 *
 * @param PDO $db Database connection
 * @param int $customerId Customer ID
 * @return array Cart items grouped by branch
 */
function getCartGroupedByBranch(PDO $db, int $customerId): array
{
    $stmt = $db->prepare(
        "SELECT 
            c.product_id,
            c.quantity,
            c.price,
            p.name AS product_name,
            p.restaurant_branch_id,
            rb.branch_name,
            rb.restaurant_id,
            r.business_name AS restaurant_name
        FROM cart c
        JOIN product p ON c.product_id = p.product_id
        JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
        JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
        WHERE c.customer_id = :customer_id
        ORDER BY rb.restaurant_id, rb.branch_name, p.name"
    );
    $stmt->execute([':customer_id' => $customerId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $grouped = [];
    foreach ($items as $item) {
        $branchId = $item['restaurant_branch_id'];
        if (!isset($grouped[$branchId])) {
            $grouped[$branchId] = [
                'branch_id' => $branchId,
                'branch_name' => $item['branch_name'],
                'restaurant_id' => $item['restaurant_id'],
                'restaurant_name' => $item['restaurant_name'],
                'items' => [],
                'subtotal' => 0
            ];
        }
        $grouped[$branchId]['items'][] = [
            'product_id' => $item['product_id'],
            'product_name' => $item['product_name'],
            'quantity' => $item['quantity'],
            'price' => (float)$item['price'],
            'total' => (float)$item['price'] * $item['quantity']
        ];
        $grouped[$branchId]['subtotal'] += (float)$item['price'] * $item['quantity'];
    }

    return $grouped;
}