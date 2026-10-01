<?php
/**
 * FitPal Product Queries
 *
 * Pure data-access layer for product, product_composition, and
 * dietary_information. No $_POST, no header(), no echo.
 *
 * @package FitPal
 * @version 5.4 — No behavioural change. The docblock on
 *                getProductReviews() now states that callers who
 *                want client-side paging should pass a limit large
 *                enough to cover the page's expected total (the
 *                product detail page passes 50).
 *
 *                (5.3: getProductReviews decodes the comment
 *                envelope and joins customer_profile. 5.2: added
 *                getProductReviews(). 5.1: removed stale
 *                `max_quantity_per_item` reference.)
 */

declare(strict_types=1);

/**
 * Get a single product by ID with full details and customization rules.
 *
 * @param PDO $db
 * @param int $productId
 * @return array<string, mixed>|null
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
            COALESCE(NULLIF(p.base_price, 0), p.price, 0) AS base_price,
            COALESCE(di.dietary_tags, '') AS dietary_tags,
            COALESCE(di.allergens, '')    AS allergens,
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
    return $product ?: null;
}

/**
 * Fetch customization components for a product and group them into
 * presentation-ready shapes (static / choice / choice / modifier / multi).
 *
 * @param PDO $db
 * @param int $productId
 * @return array<int, array<string, mixed>>
 */
function getProductComponentsGrouped(PDO $db, int $productId): array
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
            i.name AS ingredient_name,
            i.unit_price,
            i.calories AS ingredient_calories,
            i.dietary_tags,
            i.allergens,
            i.is_active
         FROM product_composition pc
         JOIN ingredient i ON pc.ingredient_id = i.ingredient_id
         WHERE pc.product_id = :product_id
           AND i.is_active = 1
         ORDER BY pc.display_order ASC, i.name ASC"
    );
    $stmt->execute([':product_id' => $productId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        return [];
    }

    $byOrder = [];
    foreach ($rows as $r) {
        $order = (int)$r['display_order'];
        $byOrder[$order][] = $r;
    }

    $components = [];

    foreach ($byOrder as $order => $groupRows) {
        $count = count($groupRows);

        $groupRequired   = false;
        $groupMaxPerItem = 1;
        $defaultCount    = 0;

        foreach ($groupRows as $r) {
            if ((bool)$r['is_required']) {
                $groupRequired = true;
            }
            $maxPer = (int)($r['max_quantity'] ?? 1);
            if ($maxPer > $groupMaxPerItem) {
                $groupMaxPerItem = $maxPer;
            }
            if ((bool)$r['is_default']) {
                $defaultCount++;
            }
        }

        $ingredients = [];
        foreach ($groupRows as $r) {
            $ingredients[] = [
                'id'               => (int)$r['ingredient_id'],
                'name'             => $r['ingredient_name'],
                'price_modifier'   => (float)($r['price_modifier'] ?? 0),
                'is_default'       => (bool)$r['is_default'],
                'calories'         => (int)($r['ingredient_calories'] ?? 0),
                'max_quantity'     => (int)($r['max_quantity'] ?? 1),
                'default_quantity' => (int)($r['default_quantity'] ?? 0),
                'min_quantity'     => (int)($r['min_quantity'] ?? 0),
            ];
        }

        $sortIngredients = static function (array $a, array $b): int {
            if ($a['is_default'] !== $b['is_default']) {
                return $a['is_default'] ? -1 : 1;
            }
            return strcmp($a['name'], $b['name']);
        };

        if ($groupMaxPerItem === 1) {
            if ($count === 1 && $groupRequired && (int)$ingredients[0]['min_quantity'] === 1) {
                $components[] = [
                    'kind'          => 'static',
                    'label'         => ucwords(str_replace('_', ' ', $ingredients[0]['name'])),
                    'display_order' => $order,
                    'ingredient'    => $ingredients[0],
                ];
            } else {
                usort($ingredients, $sortIngredients);
                $components[] = [
                    'kind'          => 'choice',
                    'label'         => ucwords(str_replace('_', ' ', $ingredients[0]['name']))
                                        . ($count > 1 ? ' Choice' : ''),
                    'display_order' => $order,
                    'is_required'   => $groupRequired,
                    'has_default'   => $defaultCount > 0,
                    'ingredients'   => $ingredients,
                ];
            }
        } elseif ($count === 1) {
            $components[] = [
                'kind'          => 'modifier',
                'label'         => ucwords(str_replace('_', ' ', $ingredients[0]['name'])),
                'display_order' => $order,
                'is_required'   => $groupRequired,
                'ingredient'    => $ingredients[0],
            ];
        } else {
            usort($ingredients, $sortIngredients);
            $components[] = [
                'kind'           => 'multi',
                'label'          => ucwords(str_replace('_', ' ', $ingredients[0]['name'])) . ' (multiple)',
                'display_order'  => $order,
                'is_required'    => $groupRequired,
                'max_selections' => $groupMaxPerItem,
                'ingredients'    => $ingredients,
            ];
        }
    }

    usort($components, static fn($a, $b) => $a['display_order'] <=> $b['display_order']);

    return $components;
}

/**
 * Get menu data with pagination.
 *
 * @param PDO $db
 * @param int $page
 * @param int $perPage
 * @param array $selectedTags
 * @param string $search
 * @param int $restaurantId
 * @param float $minPrice
 * @param float $maxPrice
 * @param array $excludeAllergens
 * @return array<string, mixed>
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

    if (!empty($search)) {
        $hasSearch = true;
        $searchTerm = '%' . trim($search) . '%';
        $conditions[] = "(p.name LIKE ? OR r.business_name LIKE ? OR p.description LIKE ?)";
        $bindValues[] = $searchTerm;
        $bindValues[] = $searchTerm;
        $bindValues[] = $searchTerm;
    }

    if ($restaurantId > 0) {
        $conditions[] = "r.restaurant_id = ?";
        $bindValues[] = $restaurantId;
    }

    if ($minPrice > 0) {
        $conditions[] = "p.price >= ?";
        $bindValues[] = $minPrice;
    }
    if ($maxPrice > 0) {
        $conditions[] = "p.price <= ?";
        $bindValues[] = $maxPrice;
    }

    if (!empty($selectedTags)) {
        $tagConditions = [];
        foreach ($selectedTags as $tag) {
            $tagConditions[] = "FIND_IN_SET(?, REPLACE(COALESCE(di.dietary_tags, ''), ' ', '')) > 0";
            $bindValues[] = trim($tag);
        }
        $conditions[] = "(" . implode(' OR ', $tagConditions) . ")";
    }

    if (!empty($excludeAllergens)) {
        $allergenConditions = [];
        foreach ($excludeAllergens as $allergen) {
            $allergen = trim($allergen);
            if ($allergen === '' || $allergen === 'none') {
                continue;
            }
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
    unset($rest);

    return [
        'restaurants' => array_values($restaurants),
        'totalProducts' => $totalProducts,
        'totalPages' => max(1, (int)ceil($totalProducts / $perPage)),
        'currentPage' => $page,
        'perPage' => $perPage,
        'searchTerm' => $search
    ];
}

/**
 * Get all distinct allergens from dietary_information.
 *
 * @param PDO $db
 * @return array<int, array<string, mixed>>
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

/**
 * Get all active restaurants for filter dropdowns.
 *
 * @param PDO $db
 * @return array<int, array<string, mixed>>
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
 * Get related products for a product detail page.
 *
 * @param PDO $db
 * @param int $productId
 * @param int $branchId
 * @param int $limit
 * @return array<int, array<string, mixed>>
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

/**
 * Decode a feedback_content JSON envelope and return the comment
 * for a specific subject, or an empty string when no comment
 * exists for that subject.
 *
 * @param string|null $content
 * @param string      $subjectKey  e.g. "product:45"
 * @return string
 */
function productDecodeCommentForSubject(?string $content, string $subjectKey): string
{
    if ($content === null || trim($content) === '') {
        return '';
    }

    $decoded = json_decode($content, true);
    if (!is_array($decoded)) {
        return '';
    }

    $comments = $decoded['comments'] ?? null;
    if (!is_array($comments)) {
        return '';
    }

    if (!isset($comments[$subjectKey])) {
        return '';
    }

    $text = $comments[$subjectKey];
    if (!is_string($text)) {
        return '';
    }

    return trim($text);
}

/**
 * Get customer reviews for a product.
 *
 * The path from a product to a review goes through queue_item:
 *
 *     product → queue_item → rating → feedback → customer
 *
 * A `rating` row with rating_type='product' anchors on the queue_item
 * the customer actually received. That queue_item carries the
 * product_id. The `feedback` row that the rating belongs to carries
 * the comment envelope and the author. The `customer` and
 * `customer_profile` rows carry the name and picture to display.
 *
 * The comment the customer wrote for THIS subject is stored inside
 * the envelope as JSON keyed by "product:<queue_item_id>". This
 * function selects the rating row's queue_item_id, decodes the
 * envelope, and returns the string at that key. A review that
 * carried no comment for this product comes back with comment = ''.
 *
 * Paging
 * ------
 * This function returns a flat list. The caller controls how many
 * rows come back through $limit. The product detail page passes a
 * limit large enough to carry the full review set for one product,
 * then splits that list client-side into pages of 5 for the "Load
 * More" button. A product with 12 reviews therefore makes one
 * request of 50 rows, renders 5, and stores the remaining 7 in a
 * data attribute for the client to reveal on demand.
 *
 * @param PDO $db
 * @param int $productId
 * @param int $limit
 * @return array<int, array{
 *     comment:string,
 *     date_posted:string,
 *     score:int,
 *     first_name:string,
 *     last_name:string,
 *     profile_picture:string
 * }>
 */
function getProductReviews(PDO $db, int $productId, int $limit = 50): array
{
    if ($productId <= 0 || $limit <= 0) {
        return [];
    }

    $stmt = $db->prepare(
        "SELECT
            r.queue_item_id,
            f.feedback_content,
            f.date_posted,
            r.score,
            c.first_name,
            c.last_name,
            cp.profile_picture
         FROM rating r
         JOIN feedback f ON r.feedback_id = f.feedback_id
         JOIN customer c ON f.feedback_from_id = c.customer_id
         LEFT JOIN customer_profile cp ON c.customer_id = cp.customer_id
         JOIN queue_item qi ON r.queue_item_id = qi.queue_item_id
         WHERE
            qi.product_id = :product_id
            AND r.rating_type = 'product'
            AND f.feedback_from_type = 'customer'
         ORDER BY f.date_posted DESC
         LIMIT :limit"
    );
    $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $row) {
        $queueItemId = (int)($row['queue_item_id'] ?? 0);
        if ($queueItemId <= 0) {
            continue;
        }

        $subjectKey = 'product:' . $queueItemId;

        $comment = productDecodeCommentForSubject(
            $row['feedback_content'] !== null ? (string)$row['feedback_content'] : null,
            $subjectKey
        );

        $out[] = [
            'comment'         => $comment,
            'date_posted'     => (string)($row['date_posted']     ?? ''),
            'score'           => (int)   ($row['score']           ?? 0),
            'first_name'      => (string)($row['first_name']      ?? ''),
            'last_name'       => (string)($row['last_name']       ?? ''),
            'profile_picture' => (string)($row['profile_picture'] ?? ''),
        ];
    }
    return $out;
}