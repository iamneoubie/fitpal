<?php
/**
 * FitPal Product Queries
 * Version 2.7 – Improved search with relevance sorting and empty result handling
 * 
 * @package FitPal
 * @version 2.7
 */

/**
 * Get menu data with pagination – uses `?` placeholders for all variable data.
 * This guarantees no "Invalid parameter number" errors.
 * 
 * @param PDO $db Database connection
 * @param int $page Current page number
 * @param int $perPage Number of products per page
 * @param array $selectedTags Selected dietary tags
 * @param string $search Search term
 * @param int $restaurantId Selected restaurant ID
 * @param float $minPrice Minimum price filter
 * @param float $maxPrice Maximum price filter
 * @return array Menu data with pagination info
 */
function getMenuDataPaginated(
    PDO $db,
    int $page = 1,
    int $perPage = 15,
    array $selectedTags = [],
    string $search = '',
    int $restaurantId = 0,
    float $minPrice = 0.0,
    float $maxPrice = 0.0
): array {
    $offset = ($page - 1) * $perPage;

    // Base FROM clause
    $from = "FROM product p
             JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
             JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
             LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id";

    $where = "WHERE p.is_active = 1";
    $conditions = [];
    $bindValues = []; // indexed array for `?` placeholders
    $hasSearch = false;

    // ============================================
    // SEARCH FILTER - IMPROVED VERSION
    // ============================================
    if (!empty($search)) {
        $hasSearch = true;
        $searchTerm = trim($search);
        
        // Split search into individual words for better matching
        $searchWords = array_filter(explode(' ', $searchTerm), function($word) {
            return strlen($word) >= 2; // Only search for words with 2+ characters
        });
        
        $wordConditions = [];
        
        if (!empty($searchWords)) {
            // Build conditions for each word
            foreach ($searchWords as $word) {
                $wordConditions[] = "(p.name LIKE ? OR r.business_name LIKE ? OR p.description LIKE ?)";
                $bindValues[] = '%' . $word . '%';
                $bindValues[] = '%' . $word . '%';
                $bindValues[] = '%' . $word . '%';
            }
            
            // If multiple words, require all to match (AND logic for precision)
            $conditions[] = "(" . implode(" AND ", $wordConditions) . ")";
        } else {
            // Fallback: if all words were too short, use the original search term
            $conditions[] = "(p.name LIKE ? OR r.business_name LIKE ? OR p.description LIKE ?)";
            $bindValues[] = '%' . $searchTerm . '%';
            $bindValues[] = '%' . $searchTerm . '%';
            $bindValues[] = '%' . $searchTerm . '%';
        }
    }

    // ============================================
    // RESTAURANT FILTER
    // ============================================
    if ($restaurantId > 0) {
        $conditions[] = "r.restaurant_id = ?";
        $bindValues[] = $restaurantId;
    }

    // ============================================
    // PRICE FILTERS
    // ============================================
    if ($minPrice > 0) {
        $conditions[] = "p.price >= ?";
        $bindValues[] = $minPrice;
    }
    if ($maxPrice > 0) {
        $conditions[] = "p.price <= ?";
        $bindValues[] = $maxPrice;
    }

    // ============================================
    // DIETARY TAGS FILTER – uses REGEXP with escaped values
    // ============================================
    if (!empty($selectedTags)) {
        $tagConditions = [];
        foreach ($selectedTags as $tag) {
            $escaped = preg_quote($tag, '/');
            $tagConditions[] = "di.dietary_tags REGEXP '(^|,){$escaped}(,|$)'";
        }
        $conditions[] = "(" . implode(' OR ', $tagConditions) . ")";
    }

    // Build final WHERE clause
    if (!empty($conditions)) {
        $where .= " AND " . implode(" AND ", $conditions);
    }

    // ============================================
    // COUNT QUERY (no LIMIT)
    // ============================================
    $countSql = "SELECT COUNT(DISTINCT p.product_id) as total $from $where";
    $countStmt = $db->prepare($countSql);
    
    try {
        $countStmt->execute($bindValues);
        $totalProducts = (int)$countStmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('Count query error: ' . $e->getMessage());
        error_log('SQL: ' . $countSql);
        error_log('Params: ' . print_r($bindValues, true));
        $totalProducts = 0;
    }

    // If no products found, return early with empty restaurants
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

    // ============================================
    // PRODUCT QUERY (with LIMIT and OFFSET)
    // ============================================
    // Build ORDER BY with relevance sorting if search exists
    $orderBy = "ORDER BY r.business_name, rb.branch_name, p.name";
    
    if ($hasSearch && !empty($search)) {
        // Relevance sorting: exact matches first, then partial matches
        $orderBy = "ORDER BY 
            CASE 
                WHEN p.name LIKE ? THEN 1
                WHEN r.business_name LIKE ? THEN 2
                WHEN p.description LIKE ? THEN 3
                ELSE 4
            END,
            r.business_name, rb.branch_name, p.name";
    }

    $productSql = "SELECT
                    p.product_id as id,
                    p.name,
                    p.description,
                    p.price,
                    p.stock,
                    p.is_active,
                    p.restaurant_branch_id,
                    rb.branch_name,
                    rb.barangay,
                    rb.city,
                    rb.province,
                    r.restaurant_id,
                    r.business_name as restaurant_name,
                    r.cuisine_type,
                    di.dietary_tags,
                    di.allergens,
                    di.calories,
                    di.protein,
                    di.carbs,
                    di.fat,
                    di.images as product_image
                  $from $where
                  $orderBy
                  LIMIT ? OFFSET ?";

    $stmt = $db->prepare($productSql);
    
    // Build execution values based on whether we have search
    $execValues = $bindValues;
    
    if ($hasSearch && !empty($search)) {
        // Add search terms for ORDER BY CASE (3 placeholders)
        $searchForOrder = '%' . $search . '%';
        $execValues[] = $searchForOrder;
        $execValues[] = $searchForOrder;
        $execValues[] = $searchForOrder;
    }
    
    // Add pagination values
    $execValues[] = $perPage;
    $execValues[] = $offset;
    
    try {
        $stmt->execute($execValues);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Product query error: ' . $e->getMessage());
        error_log('SQL: ' . $productSql);
        error_log('Params: ' . print_r($execValues, true));
        $products = [];
    }

    // If no products returned (shouldn't happen if count > 0, but just in case)
    if (empty($products)) {
        return [
            'restaurants' => [],
            'totalProducts' => 0,
            'totalPages' => 1,
            'currentPage' => $page,
            'perPage' => $perPage,
            'searchTerm' => $search
        ];
    }

    // ============================================
    // GROUP RESULTS BY RESTAURANT / BRANCH
    // ============================================
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
                'id'       => $restId,
                'name'     => $product['restaurant_name'],
                'branches' => []
            ];
        }
        if (!isset($restaurants[$restId]['branches'][$branchId])) {
            $restaurants[$restId]['branches'][$branchId] = [
                'id'       => $branchId,
                'name'     => $product['branch_name'],
                'products' => []
            ];
        }
        $restaurants[$restId]['branches'][$branchId]['products'][] = [
            'id'           => (int)$product['id'],
            'name'         => $product['name'],
            'description'  => $product['description'] ?? '',
            'price'        => (float)$product['price'],
            'stock'        => (int)$product['stock'],
            'image'        => $product['product_image'] ?? '',
            'calories'     => $product['calories'] ?? null,
            'dietary_tags' => $dietaryTags,
            'allergens'    => $allergens,
        ];
    }

    // Convert branch associative arrays to indexed
    foreach ($restaurants as &$rest) {
        $rest['branches'] = array_values($rest['branches']);
    }

    return [
        'restaurants'    => array_values($restaurants),
        'totalProducts'  => $totalProducts,
        'totalPages'     => max(1, (int)ceil($totalProducts / $perPage)),
        'currentPage'    => $page,
        'perPage'        => $perPage,
        'searchTerm'     => $search
    ];
}

/**
 * Get all restaurants for the filter dropdown
 */
function getAllRestaurants(PDO $db): array {
    $stmt = $db->query("SELECT restaurant_id, business_name FROM restaurant WHERE is_active = 1 ORDER BY business_name");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get distinct dietary tags for the filter
 */
function getDistinctDietaryTags(PDO $db): array {
    $stmt = $db->query("SELECT DISTINCT dietary_tags FROM dietary_information WHERE dietary_tags IS NOT NULL AND dietary_tags != ''");
    $tags = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $tagArray = array_map('trim', explode(',', $row['dietary_tags']));
        foreach ($tagArray as $tag) {
            if (!empty($tag) && !in_array($tag, $tags)) {
                $tags[] = $tag;
            }
        }
    }
    sort($tags);
    return $tags;
}

/**
 * Get a single product by ID with full details
 */
function getProductById(PDO $db, int $productId): ?array {
    $stmt = $db->prepare("
        SELECT 
            p.product_id,
            p.name AS product_name,
            p.description,
            p.price,
            p.stock,
            p.is_active,
            p.restaurant_branch_id,
            di.dietary_tags,
            di.allergens,
            di.calories,
            di.protein,
            di.carbs,
            di.fat,
            di.images AS product_image,
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
    ");
    $stmt->execute([':product_id' => $productId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

/**
 * Get related products from the same branch
 */
function getRelatedProducts(PDO $db, int $productId, int $branchId, int $limit = 4): array {
    $stmt = $db->prepare("
        SELECT 
            p.product_id,
            p.name AS product_name,
            p.price,
            p.stock,
            di.dietary_tags,
            di.calories,
            di.images AS product_image
        FROM product p
        LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
        WHERE p.restaurant_branch_id = :branch_id
        AND p.product_id != :product_id
        AND p.is_active = 1
        LIMIT :limit
    ");
    $stmt->bindValue(':branch_id', $branchId, PDO::PARAM_INT);
    $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}