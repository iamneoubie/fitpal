<?php
/**
 * Product Database Queries
 *
 * @package FitPal
 * @version 2.0
 */

declare(strict_types=1);

/**
 * Get all active restaurants for the restaurant selector
 *
 * @param PDO $db Database connection
 * @return array List of restaurants with id and name
 */
function getAllRestaurants(PDO $db): array
{
    $stmt = $db->prepare(
        "SELECT restaurant_id, business_name 
         FROM restaurant 
         WHERE is_active = 1 
         ORDER BY business_name"
    );
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get paginated menu data with all filters
 *
 * @param PDO $db Database connection
 * @param int $page Current page
 * @param int $perPage Items per page
 * @param array $selectedTags Dietary tags filter
 * @param string $search Search term
 * @param int $restaurantId Restaurant ID filter (0 = all)
 * @param float $minPrice Minimum price
 * @param float $maxPrice Maximum price
 * @return array Menu data with restaurants, total products, total pages
 */
function getMenuDataPaginated(
    PDO $db, 
    int $page, 
    int $perPage = 12, 
    array $selectedTags = [], 
    string $search = '',
    int $restaurantId = 0,
    float $minPrice = 0,
    float $maxPrice = 0
): array {
    $offset = ($page - 1) * $perPage;
    $params = [];
    $whereClause = buildMenuWhereClause($selectedTags, $search, $restaurantId, $minPrice, $maxPrice, $params);
    
    // Count total products
    $countSql = "SELECT COUNT(DISTINCT p.product_id) AS total 
                 FROM product p
                 JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
                 JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
                 LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
                 WHERE r.is_active = 1 AND rb.is_active = 1 AND p.is_active = 1 {$whereClause}";
    
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();
    
    // Get data
    $dataSql = "
        SELECT 
            r.restaurant_id, r.business_name,
            rb.restaurant_branch_id AS branch_id, rb.branch_name,
            p.product_id, p.name AS product_name, p.description, p.price, p.stock,
            di.dietary_tags, di.allergens, di.calories, di.protein, di.carbs, di.fat, di.images AS product_image
        FROM product p
        JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
        JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
        LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
        WHERE r.is_active = 1 AND rb.is_active = 1 AND p.is_active = 1 {$whereClause}
        ORDER BY r.business_name, rb.branch_name, p.name
        LIMIT ? OFFSET ?";
    
    $params[] = $perPage;
    $params[] = $offset;
    
    $stmt = $db->prepare($dataSql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'restaurants' => organizeMenuData($rows),
        'totalProducts' => $total,
        'totalPages' => $perPage > 0 ? ceil($total / $perPage) : 1
    ];
}

/**
 * Build WHERE clause for menu filters
 *
 * @param array $tags Dietary tags
 * @param string $search Search term
 * @param int $restaurantId Restaurant ID
 * @param float $minPrice Minimum price
 * @param float $maxPrice Maximum price
 * @param array $params Reference to parameters array
 * @return string WHERE clause
 */
function buildMenuWhereClause(array $tags, string $search, int $restaurantId, float $minPrice, float $maxPrice, array &$params): string
{
    $conditions = [];

    // Restaurant filter
    if ($restaurantId > 0) {
        $conditions[] = "r.restaurant_id = ?";
        $params[] = $restaurantId;
    }

    // Dietary tags filter (AND condition - must match all selected tags)
    if (!empty($tags)) {
        foreach ($tags as $tag) {
            $conditions[] = "FIND_IN_SET(?, di.dietary_tags) > 0";
            $params[] = trim($tag);
        }
    }

    // Search filter
    if (!empty($search)) {
        $searchTerm = '%' . trim($search) . '%';
        $conditions[] = "(p.name LIKE ? OR p.description LIKE ?)";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    // Price range filter
    if ($minPrice > 0) {
        $conditions[] = "p.price >= ?";
        $params[] = $minPrice;
    }
    if ($maxPrice > 0) {
        $conditions[] = "p.price <= ?";
        $params[] = $maxPrice;
    }

    return $conditions ? ' AND ' . implode(' AND ', $conditions) : '';
}

/**
 * Organize menu data into nested structure
 *
 * @param array $rows Database rows
 * @return array Organized structure
 */
function organizeMenuData(array $rows): array
{
    $restaurants = [];
    foreach ($rows as $row) {
        $restId = $row['restaurant_id'];
        $branchId = $row['branch_id'];

        if (!isset($restaurants[$restId])) {
            $restaurants[$restId] = [
                'id' => $restId,
                'name' => $row['business_name'],
                'branches' => []
            ];
        }

        if (!isset($restaurants[$restId]['branches'][$branchId])) {
            $restaurants[$restId]['branches'][$branchId] = [
                'id' => $branchId,
                'name' => $row['branch_name'],
                'products' => []
            ];
        }

        $restaurants[$restId]['branches'][$branchId]['products'][] = [
            'id' => $row['product_id'],
            'name' => $row['product_name'],
            'description' => $row['description'],
            'price' => (float) $row['price'],
            'stock' => (int) $row['stock'],
            'dietary_tags' => $row['dietary_tags'] ? explode(',', $row['dietary_tags']) : [],
            'allergens' => $row['allergens'] ? explode(',', $row['allergens']) : [],
            'calories' => $row['calories'],
            'protein' => $row['protein'],
            'carbs' => $row['carbs'],
            'fat' => $row['fat'],
            'image' => $row['product_image']
        ];
    }

    foreach ($restaurants as &$rest) {
        $rest['branches'] = array_values($rest['branches']);
    }

    return array_values($restaurants);
}

/**
 * Get distinct dietary tags
 *
 * @param PDO $db Database connection
 * @return array List of unique tags
 */
function getDistinctDietaryTags(PDO $db): array
{
    $sql = "
        SELECT DISTINCT TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(di.dietary_tags, ',', numbers.n), ',', -1)) AS tag
        FROM dietary_information di
        JOIN product p ON di.dietary_information_id = p.dietary_information_id
        JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
        JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
        CROSS JOIN (
            SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5
            UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10
        ) numbers
        WHERE di.dietary_tags IS NOT NULL AND di.dietary_tags != ''
          AND r.is_active = 1 AND rb.is_active = 1 AND p.is_active = 1
        HAVING tag IS NOT NULL AND tag != ''
        ORDER BY tag";
    
    $stmt = $db->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}