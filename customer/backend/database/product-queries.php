<?php
/**
 * FitPal Product Queries - FIXED
 * Replaced REGEXP with FIND_IN_SET, added error handling
 * 
 * @package FitPal
 * @version 3.2
 */

declare(strict_types=1);

/**
 * Get a single product by ID with full details and customization rules
 *
 * @param PDO $db Database connection
 * @param int $productId Product ID
 * @return array|null Product details or null if not found
 */
function getProductById(PDO $db, int $productId): ?array
{
    $stmt = $db->prepare(
        "SELECT 
            p.product_id,
            p.name AS product_name,
            p.description,
            p.price,
            p.stock,
            p.is_active,
            p.restaurant_branch_id,
            p.is_customizable,
            p.customization_type,
            p.base_price,
            COALESCE(di.dietary_tags, '') as dietary_tags,
            COALESCE(di.allergens, '') as allergens,
            di.calories,
            di.protein,
            di.carbs,
            di.fat,
            COALESCE(di.images, '') AS product_image,
            rb.branch_name,
            rb.barangay,
            rb.city,
            rb.province,
            r.business_name AS restaurant_name,
            r.cuisine_type
        FROM product p
        JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
        JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
        LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
        WHERE p.product_id = :product_id
        AND p.is_active = 1
        AND rb.is_active = 1
        AND r.is_active = 1"
    );
    $stmt->execute([':product_id' => $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        return null;
    }
    
    // Get customization rules if product is customizable
    if ($product['is_customizable']) {
        $compStmt = $db->prepare(
            "SELECT 
                pc.product_composition_id,
                pc.composition_type,
                pc.is_required,
                pc.max_selections,
                pc.allowed_ingredients,
                GROUP_CONCAT(
                    CONCAT(i.ingredient_id, '|', i.name, '|', i.price_modifier, '|', i.is_active)
                    SEPARATOR ';;'
                ) AS ingredient_list
            FROM product_composition pc
            LEFT JOIN product_composition_ingredient pci ON pc.product_composition_id = pci.product_composition_id
            LEFT JOIN ingredient i ON pci.ingredient_id = i.ingredient_id AND i.is_active = 1
            WHERE pc.product_id = :product_id
            GROUP BY pc.product_composition_id
            ORDER BY pc.composition_type, pc.product_composition_id"
        );
        $compStmt->execute([':product_id' => $productId]);
        $components = $compStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($components as &$comp) {
            $comp['ingredients'] = [];
            if (!empty($comp['ingredient_list'])) {
                $parts = explode(';;', $comp['ingredient_list']);
                foreach ($parts as $part) {
                    $data = explode('|', $part);
                    if (count($data) >= 4) {
                        $comp['ingredients'][] = [
                            'id' => (int)$data[0],
                            'name' => $data[1],
                            'price_modifier' => (float)$data[2],
                            'is_active' => (bool)$data[3]
                        ];
                    }
                }
            }
            unset($comp['ingredient_list']);
        }
        $product['customization_components'] = $components;
    }
    
    return $product;
}

/**
 * Get menu data with pagination - FIXED with FIND_IN_SET
 */
function getMenuDataPaginated(
    PDO $db,
    int $page = 1,
    int $perPage = 10,
    array $selectedTags = [],
    string $search = '',
    int $restaurantId = 0,
    float $minPrice = 0.0,
    float $maxPrice = 0.0
): array {
    $offset = ($page - 1) * $perPage;

    $from = "FROM product p
             JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
             JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
             LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id";

    $where = "WHERE p.is_active = 1 AND rb.is_active = 1 AND r.is_active = 1";
    $conditions = [];
    $bindValues = [];
    $hasSearch = false;

    // Search filter - simplified to avoid complex binding issues
    if (!empty($search)) {
        $hasSearch = true;
        $searchTerm = '%' . trim($search) . '%';
        $conditions[] = "(p.name LIKE ? OR r.business_name LIKE ? OR p.description LIKE ?)";
        $bindValues[] = $searchTerm;
        $bindValues[] = $searchTerm;
        $bindValues[] = $searchTerm;
    }

    // Restaurant filter
    if ($restaurantId > 0) {
        $conditions[] = "r.restaurant_id = ?";
        $bindValues[] = $restaurantId;
    }

    // Price filters
    if ($minPrice > 0) {
        $conditions[] = "p.price >= ?";
        $bindValues[] = $minPrice;
    }
    if ($maxPrice > 0) {
        $conditions[] = "p.price <= ?";
        $bindValues[] = $maxPrice;
    }

    // Dietary tags filter - Using FIND_IN_SET (more reliable than REGEXP)
    if (!empty($selectedTags)) {
        $tagConditions = [];
        foreach ($selectedTags as $tag) {
            $tagConditions[] = "FIND_IN_SET(?, COALESCE(di.dietary_tags, '')) > 0";
            $bindValues[] = $tag;
        }
        $conditions[] = "(" . implode(' OR ', $tagConditions) . ")";
    }

    if (!empty($conditions)) {
        $where .= " AND " . implode(" AND ", $conditions);
    }

    // Count query - get total product count
    $countSql = "SELECT COUNT(DISTINCT p.product_id) as total $from $where";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($bindValues);
    $totalProducts = (int)$countStmt->fetchColumn();

    // If no products, return early
    if ($totalProducts === 0) {
        return [
            'restaurants' => [],
            'totalProducts' => 0,
            'totalPages' => 1,
            'currentPage' => $page,
            'perPage' => $perPage,
            'searchTerm' => $search
        ];
    }

    // Product query
    $orderBy = $hasSearch && !empty($search) 
        ? "ORDER BY 
            CASE 
                WHEN p.name LIKE ? THEN 1
                WHEN r.business_name LIKE ? THEN 2
                WHEN p.description LIKE ? THEN 3
                ELSE 4
            END,
            r.business_name, rb.branch_name, p.name"
        : "ORDER BY r.business_name, rb.branch_name, p.name";

    $productSql = "SELECT
                    p.product_id as id,
                    p.name,
                    p.description,
                    p.price,
                    p.stock,
                    p.is_active,
                    p.restaurant_branch_id,
                    p.is_customizable,
                    p.customization_type,
                    p.base_price,
                    rb.branch_name,
                    rb.barangay,
                    rb.city,
                    rb.province,
                    r.restaurant_id,
                    r.business_name as restaurant_name,
                    r.cuisine_type,
                    COALESCE(di.dietary_tags, '') as dietary_tags,
                    COALESCE(di.allergens, '') as allergens,
                    di.calories,
                    di.protein,
                    di.carbs,
                    di.fat,
                    COALESCE(di.images, '') as product_image
                  $from $where
                  $orderBy
                  LIMIT ? OFFSET ?";

    $stmt = $db->prepare($productSql);
    $execValues = $bindValues;
    
    // Add search values for ORDER BY CASE
    if ($hasSearch && !empty($search)) {
        $searchForOrder = '%' . trim($search) . '%';
        $execValues[] = $searchForOrder;
        $execValues[] = $searchForOrder;
        $execValues[] = $searchForOrder;
    }
    
    $execValues[] = $perPage;
    $execValues[] = $offset;
    $stmt->execute($execValues);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group results by restaurant and branch
    $restaurants = [];
    foreach ($products as $product) {
        $restId = (int)$product['restaurant_id'];
        $branchId = (int)$product['restaurant_branch_id'];

        $dietaryTags = !empty($product['dietary_tags'])
            ? array_map('trim', explode(',', $product['dietary_tags']))
            : [];
        $allergens = !empty($product['allergens'])
            ? array_map('trim', explode(',', $product['allergens']))
            : [];

        if (!isset($restaurants[$restId])) {
            $restaurants[$restId] = [
                'id' => $restId,
                'name' => $product['restaurant_name'],
                'branches' => []
            ];
        }
        if (!isset($restaurants[$restId]['branches'][$branchId])) {
            $restaurants[$restId]['branches'][$branchId] = [
                'id' => $branchId,
                'name' => $product['branch_name'],
                'products' => []
            ];
        }
        $restaurants[$restId]['branches'][$branchId]['products'][] = [
            'id' => (int)$product['id'],
            'name' => $product['name'],
            'description' => $product['description'] ?? '',
            'price' => (float)$product['price'],
            'stock' => (int)$product['stock'],
            'image' => $product['product_image'] ?? '',
            'calories' => $product['calories'] ?? null,
            'dietary_tags' => $dietaryTags,
            'allergens' => $allergens,
            'is_customizable' => (bool)($product['is_customizable'] ?? false),
            'customization_type' => $product['customization_type'] ?? null,
            'base_price' => (float)($product['base_price'] ?? $product['price']),
        ];
    }

    foreach ($restaurants as &$rest) {
        $rest['branches'] = array_values($rest['branches']);
    }

    return [
        'restaurants' => array_values($restaurants),
        'totalProducts' => $totalProducts,
        'totalPages' => max(1, (int)ceil($totalProducts / $perPage)),
        'currentPage' => $page,
        'perPage' => $perPage,
        'searchTerm' => $search
    ];
}

// ================================================================
// HELPER FUNCTIONS
// ================================================================

/**
 * Get distinct dietary tags from all products
 * 
 * @param PDO $db Database connection
 * @return array List of unique dietary tags
 */
function getDistinctDietaryTags(PDO $db): array
{
    $stmt = $db->query(
        "SELECT DISTINCT 
            TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(
                COALESCE(di.dietary_tags, ''), ',', numbers.n), ',', -1)) AS tag
        FROM dietary_information di
        CROSS JOIN (
            SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 
            UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 
            UNION SELECT 9 UNION SELECT 10
        ) numbers
        WHERE COALESCE(di.dietary_tags, '') != ''
        AND CHAR_LENGTH(COALESCE(di.dietary_tags, '')) - 
            CHAR_LENGTH(REPLACE(COALESCE(di.dietary_tags, ''), ',', '')) >= numbers.n - 1
        HAVING tag != ''
        ORDER BY tag"
    );
    
    $tags = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $tags = array_filter($tags, function($tag) {
        return !empty(trim($tag));
    });
    $tags = array_map('trim', $tags);
    $tags = array_unique($tags);
    sort($tags);
    
    return $tags;
}

/**
 * Get all restaurants for the filter dropdown
 * 
 * @param PDO $db Database connection
 * @return array List of restaurants
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
 * Get distinct dietary tags with count of products using each tag
 * 
 * @param PDO $db Database connection
 * @return array List of dietary tags with counts
 */
function getDistinctDietaryTagsWithCount(PDO $db): array
{
    $stmt = $db->query(
        "SELECT dietary_tags FROM dietary_information 
         WHERE dietary_tags IS NOT NULL AND dietary_tags != ''"
    );
    
    $tagCounts = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['dietary_tags'])) {
            $parts = array_map('trim', explode(',', $row['dietary_tags']));
            foreach ($parts as $tag) {
                if (!empty($tag)) {
                    $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
                }
            }
        }
    }
    
    ksort($tagCounts);
    
    $result = [];
    foreach ($tagCounts as $tag => $count) {
        $result[] = [
            'tag' => $tag,
            'count' => $count,
            'label' => ucwords(str_replace('_', ' ', $tag))
        ];
    }
    
    return $result;
}

/**
 * Get customization components for a product
 * 
 * @param PDO $db Database connection
 * @param int $productId Product ID
 * @return array Customization components with ingredients
 */
function getProductCustomizationComponents(PDO $db, int $productId): array
{
    $stmt = $db->prepare(
        "SELECT 
            pc.product_composition_id,
            pc.composition_type,
            pc.is_required,
            pc.max_selections,
            pc.allowed_ingredients
        FROM product_composition pc
        WHERE pc.product_id = :product_id
        ORDER BY pc.composition_type, pc.product_composition_id"
    );
    $stmt->execute([':product_id' => $productId]);
    $components = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($components as &$comp) {
        $ingStmt = $db->prepare(
            "SELECT 
                i.ingredient_id,
                i.name AS ingredient_name,
                i.price_modifier,
                i.is_active,
                pci.is_default
            FROM product_composition_ingredient pci
            JOIN ingredient i ON pci.ingredient_id = i.ingredient_id
            WHERE pci.product_composition_id = :comp_id
            AND i.is_active = 1
            ORDER BY pci.is_default DESC, i.name"
        );
        $ingStmt->execute([':comp_id' => $comp['product_composition_id']]);
        $comp['ingredients'] = $ingStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($comp['allowed_ingredients'])) {
            $allowed = json_decode($comp['allowed_ingredients'], true);
            if (is_array($allowed)) {
                $comp['allowed_ingredients'] = $allowed;
            } else {
                $comp['allowed_ingredients'] = array_map('trim', explode(',', $comp['allowed_ingredients']));
            }
        }
    }
    
    return $components;
}

/**
 * Get related products from the same branch
 * 
 * @param PDO $db Database connection
 * @param int $productId Product ID to exclude
 * @param int $branchId Branch ID
 * @param int $limit Number of products to return
 * @return array List of related products
 */
function getRelatedProducts(PDO $db, int $productId, int $branchId, int $limit = 4): array
{
    $stmt = $db->prepare(
        "SELECT 
            p.product_id,
            p.name AS product_name,
            p.price,
            p.stock,
            p.is_customizable,
            COALESCE(di.dietary_tags, '') as dietary_tags,
            di.calories,
            COALESCE(di.images, '') AS product_image
        FROM product p
        LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
        WHERE p.restaurant_branch_id = :branch_id
        AND p.product_id != :product_id
        AND p.is_active = 1
        LIMIT :limit"
    );
    $stmt->bindValue(':branch_id', $branchId, PDO::PARAM_INT);
    $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}