<?php
/**
 * FitPal Product Queries - FIXED FOR YOUR SCHEMA
 * 
 * @package FitPal
 * @version 4.2 - Added allergen exclusion filter
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
                pc.composition_id,
                pc.product_id,
                pc.ingredient_id,
                pc.is_default,
                pc.default_quantity,
                pc.max_quantity,
                pc.price_modifier,
                pc.display_order,
                pc.is_required,
                pc.min_quantity,
                pc.max_quantity_per_item,
                i.name AS ingredient_name,
                i.unit_price,
                i.calories AS ingredient_calories,
                i.dietary_tags,
                i.allergens,
                i.is_active
            FROM product_composition pc
            LEFT JOIN ingredient i ON pc.ingredient_id = i.ingredient_id
            WHERE pc.product_id = :product_id
            AND (i.is_active = 1 OR i.is_active IS NULL)
            ORDER BY pc.display_order ASC, pc.composition_id ASC"
        );
        $compStmt->execute([':product_id' => $productId]);
        $ingredientRows = $compStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($ingredientRows)) {
            $components = [];
            foreach ($ingredientRows as $row) {
                $compId = $row['composition_id'];
                if (!isset($components[$compId])) {
                    $maxQty = (int)($row['max_quantity_per_item'] ?? 1);
                    $isRequired = (bool)($row['is_required'] ?? false);
                    $components[$compId] = [
                        'composition_id' => $compId,
                        'product_id' => (int)$row['product_id'],
                        'type' => $maxQty <= 1 ? 'choice' : 'modifier',
                        'is_required' => $isRequired,
                        'max_selections' => $maxQty,
                        'display_order' => (int)($row['display_order'] ?? 0),
                        'ingredients' => []
                    ];
                }

                if ($row['ingredient_id'] && $row['is_active']) {
                    $priceModifier = (float)($row['price_modifier'] ?? 0);
                    if ($priceModifier == 0) {
                        $priceModifier = (float)($row['unit_price'] ?? 0);
                    }

                    $components[$compId]['ingredients'][] = [
                        'id' => (int)$row['ingredient_id'],
                        'name' => $row['ingredient_name'],
                        'price_modifier' => $priceModifier,
                        'is_default' => (bool)($row['is_default'] ?? false),
                        'is_active' => (bool)($row['is_active'] ?? true),
                        'max_quantity' => (int)($row['max_quantity'] ?? 1),
                        'default_quantity' => (int)($row['default_quantity'] ?? 0),
                        'min_quantity' => (int)($row['min_quantity'] ?? 0)
                    ];
                }
            }
            $product['customization_components'] = array_values($components);
        }
    }
    
    return $product;
}

/**
 * Get menu data with pagination
 *
 * @param PDO $db Database connection
 * @param int $page Current page
 * @param int $perPage Items per page
 * @param array $selectedTags Dietary tags to include (OR logic)
 * @param string $search Search term
 * @param int $restaurantId Restaurant filter
 * @param float $minPrice Minimum price
 * @param float $maxPrice Maximum price
 * @param array $excludeAllergens Allergens to exclude (products containing these will be hidden)
 * @return array Menu data with restaurants, pagination info
 */
function getMenuDataPaginated(
    PDO $db,
    int $page = 1,
    int $perPage = 10,
    array $selectedTags = [],
    string $search = '',
    int $restaurantId = 0,
    float $minPrice = 0.0,
    float $maxPrice = 0.0,
    array $excludeAllergens = []
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

    // Search filter
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

    // Dietary tags filter (include - OR logic)
    if (!empty($selectedTags)) {
        $tagConditions = [];
        foreach ($selectedTags as $tag) {
            $tagConditions[] = "FIND_IN_SET(?, REPLACE(COALESCE(di.dietary_tags, ''), ' ', '')) > 0";
            $bindValues[] = trim($tag);
        }
        $conditions[] = "(" . implode(' OR ', $tagConditions) . ")";
    }

    // ============================================================
    // ALLERGEN EXCLUSION FILTER
    // Products containing ANY of the excluded allergens are hidden.
    // This is a hard exclusion - if a product's allergens field
    // contains any of the user's allergies, it is removed entirely.
    // ============================================================
    if (!empty($excludeAllergens)) {
        $allergenConditions = [];
        foreach ($excludeAllergens as $allergen) {
            $allergen = trim($allergen);
            if ($allergen === '' || $allergen === 'none') {
                continue;
            }
            // NOT FIND_IN_SET: product allergens must NOT contain this allergen
            $allergenConditions[] = "NOT FIND_IN_SET(?, REPLACE(COALESCE(di.allergens, ''), ' ', ''))";
            $bindValues[] = $allergen;
        }
        if (!empty($allergenConditions)) {
            $conditions[] = "(" . implode(' AND ', $allergenConditions) . ")";
        }
    }

    if (!empty($conditions)) {
        $where .= " AND " . implode(" AND ", $conditions);
    }

    // Count query
    $countSql = "SELECT COUNT(DISTINCT p.product_id) as total $from $where";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($bindValues);
    $totalProducts = (int)$countStmt->fetchColumn();

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
 * Get all distinct allergens from dietary_information
 *
 * @param PDO $db Database connection
 * @return array List of allergen strings
 */
function getAllDistinctAllergens(PDO $db): array
{
    $stmt = $db->query(
        "SELECT allergens FROM dietary_information 
         WHERE allergens IS NOT NULL AND allergens != '' AND allergens != 'none'"
    );
    
    $allergenCounts = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['allergens'])) {
            $parts = array_map('trim', explode(',', $row['allergens']));
            foreach ($parts as $allergen) {
                $allergen = trim($allergen);
                if ($allergen !== '' && $allergen !== 'none') {
                    $allergenCounts[$allergen] = ($allergenCounts[$allergen] ?? 0) + 1;
                }
            }
        }
    }
    
    ksort($allergenCounts);
    
    $result = [];
    foreach ($allergenCounts as $allergen => $count) {
        $result[] = [
            'allergen' => $allergen,
            'count' => $count,
            'label' => ucwords(str_replace('_', ' ', $allergen))
        ];
    }
    
    return $result;
}

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

function getProductCustomizationComponents(PDO $db, int $productId): array
{
    $stmt = $db->prepare(
        "SELECT 
            pc.composition_id,
            pc.product_id,
            pc.ingredient_id,
            pc.is_default,
            pc.default_quantity,
            pc.max_quantity,
            pc.price_modifier,
            pc.display_order,
            pc.is_required,
            pc.min_quantity,
            pc.max_quantity_per_item,
            i.name AS ingredient_name,
            i.unit_price,
            i.calories AS ingredient_calories,
            i.dietary_tags,
            i.allergens,
            i.is_active
        FROM product_composition pc
        LEFT JOIN ingredient i ON pc.ingredient_id = i.ingredient_id
        WHERE pc.product_id = :product_id
        AND (i.is_active = 1 OR i.is_active IS NULL)
        ORDER BY pc.display_order ASC, pc.composition_id ASC"
    );
    $stmt->execute([':product_id' => $productId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $grouped = [];
    foreach ($rows as $row) {
        $compId = $row['composition_id'];
        if (!isset($grouped[$compId])) {
            $grouped[$compId] = [
                'composition_id' => $compId,
                'product_id' => (int)$row['product_id'],
                'is_required' => (bool)($row['is_required'] ?? false),
                'max_selections' => (int)($row['max_quantity_per_item'] ?? 1),
                'ingredients' => []
            ];
        }
        
        if ($row['ingredient_id'] && $row['is_active']) {
            $priceModifier = (float)($row['price_modifier'] ?? 0);
            if ($priceModifier == 0) {
                $priceModifier = (float)($row['unit_price'] ?? 0);
            }
            
            $grouped[$compId]['ingredients'][] = [
                'ingredient_id' => (int)$row['ingredient_id'],
                'ingredient_name' => $row['ingredient_name'],
                'price_modifier' => $priceModifier,
                'is_default' => (bool)($row['is_default'] ?? false),
                'is_active' => (bool)($row['is_active'] ?? true),
                'max_quantity' => (int)($row['max_quantity'] ?? 1),
                'default_quantity' => (int)($row['default_quantity'] ?? 0),
                'min_quantity' => (int)($row['min_quantity'] ?? 0)
            ];
        }
    }
    
    return array_values($grouped);
}

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