<?php
/**
 * FitPal Product Queries
 *
 * Pure data-access layer for product, product_composition, and
 * dietary_information. No $_POST, no header(), no echo.
 *
 * @package FitPal
 * @version 5.1 — Removed stale `max_quantity_per_item` column reference
 *                (not present in current schema; use `max_quantity`).
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
 * presentation-ready shapes (static / choice / modifier / multi).
 *
 * Rows are grouped by `display_order`; the kind is derived from the
 * shape of each group. Per-ingredient maximums come from
 * `product_composition.max_quantity`.
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