<?php
/**
 * FitPal Customer Database Queries
 *
 * Customer-specific database operations.
 * All queries use prepared statements.
 *
 * @package FitPal
 * @version 1.5
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

/**
 * Get all restaurants with their branches and active products,
 * including dietary information and images.
 *
 * @param PDO $connection
 * @return array Array of restaurants, each with 'branches' containing 'products'.
 */
function getMenuData(PDO $connection): array {
    $sql = "
        SELECT
            r.restaurant_id,
            r.business_name,
            rb.restaurant_branch_id AS branch_id,
            rb.branch_name,
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
        FROM restaurant r
        JOIN restaurant_branch rb ON r.restaurant_id = rb.restaurant_id
        JOIN product p ON rb.restaurant_branch_id = p.restaurant_branch_id
        LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
        WHERE r.is_active = 1
          AND rb.is_active = 1
          AND p.is_active = 1
        ORDER BY r.business_name, rb.branch_name, p.name
    ";

    $stmt = $connection->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Organize data into nested structure
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
        // Add product with image field
        $restaurants[$restId]['branches'][$branchId]['products'][] = [
            'id' => $row['product_id'],
            'name' => $row['product_name'],
            'description' => $row['description'],
            'price' => (float)$row['price'],
            'stock' => (int)$row['stock'],
            'dietary_tags' => $row['dietary_tags'] ? explode(',', $row['dietary_tags']) : [],
            'allergens' => $row['allergens'] ? explode(',', $row['allergens']) : [],
            'calories' => $row['calories'],
            'protein' => $row['protein'],
            'carbs' => $row['carbs'],
            'fat' => $row['fat'],
            'image' => $row['product_image']
        ];
    }

    // Re-index branches as simple arrays
    foreach ($restaurants as &$rest) {
        $rest['branches'] = array_values($rest['branches']);
    }
    return array_values($restaurants);
}

/**
 * Get paginated restaurant data with products and filters.
 * Uses positional parameters (?) to avoid parameter binding issues.
 *
 * @param PDO $connection
 * @param int $page Current page number (1-based)
 * @param int $perPage Items per page (default 10)
 * @param array $selectedTags Dietary tags to filter by
 * @param string $search Search term
 * @return array Associative array with:
 *               - 'restaurants': array of restaurants with branches and products
 *               - 'totalProducts': total number of products matching filters
 *               - 'totalPages': total number of pages
 */
function getMenuDataPaginated(PDO $connection, int $page, int $perPage = 10, array $selectedTags = [], string $search = ''): array {
    $offset = ($page - 1) * $perPage;
    
    // Build the FROM and WHERE clauses
    $fromClause = "
        FROM product p
        JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
        JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
        LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
        WHERE r.is_active = 1
          AND rb.is_active = 1
          AND p.is_active = 1
    ";
    
    $whereConditions = [];
    $params = [];
    
    // Apply dietary tag filter (product must have at least one of the selected tags)
    if (!empty($selectedTags)) {
        $tagConditions = [];
        foreach ($selectedTags as $tag) {
            $tagConditions[] = "FIND_IN_SET(?, di.dietary_tags) > 0";
            $params[] = trim($tag);
        }
        $whereConditions[] = '(' . implode(' OR ', $tagConditions) . ')';
    }
    
    // Apply search filter (product name or restaurant name)
    if (!empty($search)) {
        $searchTerm = '%' . trim($search) . '%';
        $whereConditions[] = "(p.name LIKE ? OR r.business_name LIKE ?)";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    // Build WHERE clause
    $whereClause = '';
    if (!empty($whereConditions)) {
        $whereClause = ' AND ' . implode(' AND ', $whereConditions);
    }
    
    // Count query
    $countSql = "SELECT COUNT(DISTINCT p.product_id) AS total " . $fromClause . $whereClause;
    $stmt = $connection->prepare($countSql);
    $stmt->execute($params);
    $totalProducts = (int)$stmt->fetchColumn();
    $totalPages = ($perPage > 0) ? ceil($totalProducts / $perPage) : 1;
    
    // Data query
    $dataSql = "
        SELECT
            r.restaurant_id,
            r.business_name,
            rb.restaurant_branch_id AS branch_id,
            rb.branch_name,
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
        " . $fromClause . $whereClause . "
        ORDER BY r.business_name, rb.branch_name, p.name
        LIMIT ? OFFSET ?
    ";
    
    // Add limit and offset to parameters
    $params[] = $perPage;
    $params[] = $offset;
    
    $stmt = $connection->prepare($dataSql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Organize data into nested structure
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
        // Add product with all fields
        $restaurants[$restId]['branches'][$branchId]['products'][] = [
            'id' => $row['product_id'],
            'name' => $row['product_name'],
            'description' => $row['description'],
            'price' => (float)$row['price'],
            'stock' => (int)$row['stock'],
            'dietary_tags' => $row['dietary_tags'] ? explode(',', $row['dietary_tags']) : [],
            'allergens' => $row['allergens'] ? explode(',', $row['allergens']) : [],
            'calories' => $row['calories'],
            'protein' => $row['protein'],
            'carbs' => $row['carbs'],
            'fat' => $row['fat'],
            'image' => $row['product_image']
        ];
    }
    
    // Re-index branches as simple arrays
    foreach ($restaurants as &$rest) {
        $rest['branches'] = array_values($rest['branches']);
    }
    
    return [
        'restaurants' => array_values($restaurants),
        'totalProducts' => $totalProducts,
        'totalPages' => $totalPages
    ];
}

/**
 * Get all distinct dietary tags from all active products.
 * Uses a number table to split comma-separated tags.
 *
 * @param PDO $connection
 * @return array Array of unique dietary tag strings.
 */
function getDistinctDietaryTags(PDO $connection): array {
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
        WHERE di.dietary_tags IS NOT NULL
          AND di.dietary_tags != ''
          AND r.is_active = 1
          AND rb.is_active = 1
          AND p.is_active = 1
        HAVING tag IS NOT NULL AND tag != ''
        ORDER BY tag
    ";
    $stmt = $connection->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Get a single product by ID with all its details.
 *
 * @param PDO $connection
 * @param int $productId
 * @return array|false Product data or false if not found
 */
function getProductById(PDO $connection, int $productId): array|false {
    $sql = "
        SELECT
            p.product_id,
            p.name AS product_name,
            p.description,
            p.price,
            p.stock,
            p.is_active,
            rb.restaurant_branch_id AS branch_id,
            rb.branch_name,
            r.restaurant_id,
            r.business_name AS restaurant_name,
            di.dietary_tags,
            di.allergens,
            di.calories,
            di.protein,
            di.carbs,
            di.fat,
            di.images AS product_image
        FROM product p
        JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
        JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
        LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
        WHERE p.product_id = :product_id
          AND p.is_active = 1
          AND rb.is_active = 1
          AND r.is_active = 1
        LIMIT 1
    ";
    $stmt = $connection->prepare($sql);
    $stmt->execute([':product_id' => $productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) {
        return false;
    }
    
    return [
        'id' => $row['product_id'],
        'name' => $row['product_name'],
        'description' => $row['description'],
        'price' => (float)$row['price'],
        'stock' => (int)$row['stock'],
        'is_active' => (bool)$row['is_active'],
        'branch_id' => $row['branch_id'],
        'branch_name' => $row['branch_name'],
        'restaurant_id' => $row['restaurant_id'],
        'restaurant_name' => $row['restaurant_name'],
        'dietary_tags' => $row['dietary_tags'] ? explode(',', $row['dietary_tags']) : [],
        'allergens' => $row['allergens'] ? explode(',', $row['allergens']) : [],
        'calories' => $row['calories'],
        'protein' => $row['protein'],
        'carbs' => $row['carbs'],
        'fat' => $row['fat'],
        'image' => $row['product_image']
    ];
}

/**
 * Get products by dietary tag with pagination.
 *
 * @param PDO $connection
 * @param string $tag Dietary tag to filter by
 * @param int $page Current page number
 * @param int $perPage Items per page
 * @return array Associative array with 'products' and 'total'
 */
function getProductsByDietaryTag(PDO $connection, string $tag, int $page = 1, int $perPage = 10): array {
    $offset = ($page - 1) * $perPage;
    
    $countSql = "
        SELECT COUNT(DISTINCT p.product_id) AS total
        FROM product p
        JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
        JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
        LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
        WHERE r.is_active = 1
          AND rb.is_active = 1
          AND p.is_active = 1
          AND FIND_IN_SET(?, di.dietary_tags) > 0
    ";
    
    $dataSql = "
        SELECT
            p.product_id,
            p.name AS product_name,
            p.description,
            p.price,
            p.stock,
            r.business_name AS restaurant_name,
            rb.branch_name,
            di.dietary_tags,
            di.allergens,
            di.calories,
            di.images AS product_image
        FROM product p
        JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
        JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
        LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
        WHERE r.is_active = 1
          AND rb.is_active = 1
          AND p.is_active = 1
          AND FIND_IN_SET(?, di.dietary_tags) > 0
        ORDER BY r.business_name, p.name
        LIMIT ? OFFSET ?
    ";
    
    // Get total count
    $stmt = $connection->prepare($countSql);
    $stmt->execute([$tag]);
    $total = (int)$stmt->fetchColumn();
    
    // Fetch data
    $stmt = $connection->prepare($dataSql);
    $stmt->execute([$tag, $perPage, $offset]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $products = [];
    foreach ($rows as $row) {
        $products[] = [
            'id' => $row['product_id'],
            'name' => $row['product_name'],
            'description' => $row['description'],
            'price' => (float)$row['price'],
            'stock' => (int)$row['stock'],
            'restaurant_name' => $row['restaurant_name'],
            'branch_name' => $row['branch_name'],
            'dietary_tags' => $row['dietary_tags'] ? explode(',', $row['dietary_tags']) : [],
            'allergens' => $row['allergens'] ? explode(',', $row['allergens']) : [],
            'calories' => $row['calories'],
            'image' => $row['product_image']
        ];
    }
    
    return [
        'products' => $products,
        'total' => $total,
        'totalPages' => ceil($total / $perPage)
    ];
}

/**
 * Get the current cart items for a customer.
 *
 * @param PDO $connection
 * @param int $customerId
 * @return array Array of cart items with product details
 */
function getCustomerCart(PDO $connection, int $customerId): array {
    $sql = "
        SELECT
            c.cart_id,
            c.product_id,
            c.quantity,
            c.price,
            c.added_at,
            p.name AS product_name,
            p.description,
            p.stock AS product_stock,
            p.is_active,
            rb.branch_name,
            r.business_name AS restaurant_name,
            di.dietary_tags,
            di.allergens,
            di.images AS product_image
        FROM cart c
        JOIN product p ON c.product_id = p.product_id
        JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
        JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
        LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
        WHERE c.customer_id = :customer_id
          AND p.is_active = 1
          AND rb.is_active = 1
          AND r.is_active = 1
        ORDER BY c.added_at DESC
    ";
    $stmt = $connection->prepare($sql);
    $stmt->execute([':customer_id' => $customerId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $cartItems = [];
    foreach ($rows as $row) {
        $cartItems[] = [
            'cart_id' => $row['cart_id'],
            'product_id' => $row['product_id'],
            'quantity' => (int)$row['quantity'],
            'price' => (float)$row['price'],
            'subtotal' => (float)$row['quantity'] * (float)$row['price'],
            'added_at' => $row['added_at'],
            'product_name' => $row['product_name'],
            'description' => $row['description'],
            'product_stock' => (int)$row['product_stock'],
            'is_active' => (bool)$row['is_active'],
            'branch_name' => $row['branch_name'],
            'restaurant_name' => $row['restaurant_name'],
            'dietary_tags' => $row['dietary_tags'] ? explode(',', $row['dietary_tags']) : [],
            'allergens' => $row['allergens'] ? explode(',', $row['allergens']) : [],
            'image' => $row['product_image']
        ];
    }
    
    return $cartItems;
}

/**
 * Get the total number of items in the customer's cart.
 *
 * @param PDO $connection
 * @param int $customerId
 * @return int Total quantity of items in cart
 */
function getCartItemCount(PDO $connection, int $customerId): int {
    $sql = "
        SELECT COALESCE(SUM(quantity), 0) AS total
        FROM cart
        WHERE customer_id = :customer_id
    ";
    $stmt = $connection->prepare($sql);
    $stmt->execute([':customer_id' => $customerId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Check if a product is already in the customer's cart.
 *
 * @param PDO $connection
 * @param int $customerId
 * @param int $productId
 * @return int|false The cart item quantity if found, false otherwise
 */
function getCartItemQuantity(PDO $connection, int $customerId, int $productId): int|false {
    $sql = "
        SELECT quantity
        FROM cart
        WHERE customer_id = :customer_id
          AND product_id = :product_id
        LIMIT 1
    ";
    $stmt = $connection->prepare($sql);
    $stmt->execute([
        ':customer_id' => $customerId,
        ':product_id' => $productId
    ]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? (int)$result['quantity'] : false;
}

/**
 * Get restaurants that offer products matching the customer's dietary preferences.
 *
 * @param PDO $connection
 * @param int $customerId
 * @return array Array of restaurant IDs and names
 */
function getRecommendedRestaurants(PDO $connection, int $customerId): array {
    $sql = "
        SELECT DISTINCT
            r.restaurant_id,
            r.business_name
        FROM restaurant r
        JOIN restaurant_branch rb ON r.restaurant_id = rb.restaurant_id
        JOIN product p ON rb.restaurant_branch_id = p.restaurant_branch_id
        JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
        JOIN customer_profile cp ON cp.customer_id = :customer_id
        WHERE r.is_active = 1
          AND rb.is_active = 1
          AND p.is_active = 1
          AND (
              cp.dietary_preferences IS NULL
              OR cp.dietary_preferences = ''
              OR FIND_IN_SET(
                  TRIM(SUBSTRING_INDEX(di.dietary_tags, ',', 1)),
                  REPLACE(cp.dietary_preferences, ',', ',')
              ) > 0
          )
        ORDER BY r.business_name
        LIMIT 20
    ";
    $stmt = $connection->prepare($sql);
    $stmt->execute([':customer_id' => $customerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}