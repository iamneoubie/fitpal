<?php
/**
 * FitPal Product Queries
 *
 * Pure data-access layer for product, product_composition, and
 * dietary_information. No $_POST, no header(), no echo, no session
 * read, no URL prefixing.
 *
 * ---------------------------------------------------------------------
 * MULTI-IMAGE MODEL
 * ---------------------------------------------------------------------
 * dietary_information.images stores a project-root-relative FOLDER
 * path. On this deployment the column value omits the literal
 * `restaurant/` segment that the manifest folder actually carries:
 *
 *     column value:  shared/assets/images/manifest/products/<resto>/<slug>/
 *     disk folder:   shared/assets/images/manifest/products/restaurant/<resto>/<slug>/
 *
 * The reader resolves the column value against a candidate list of
 * roots and returns the first candidate that is a directory. The
 * absolute directory it found is exposed to callers as the
 * `image_base` field — a project-root-relative folder path that the
 * page appends a filename to, then prepends its own project-root
 * URL to.
 *
 * The page's URL is therefore built from the folder the reader
 * actually resolved, not from the raw column value. That is what
 * makes the browser request match a real file on disk.
 *
 * ---------------------------------------------------------------------
 * CANDIDATE ORDER
 * ---------------------------------------------------------------------
 * For a column value `$folder`, resolveProductImageDir() tries:
 *
 *   1. $projectRoot . '/' . $folder
 *         The value as stored. Correct when the column already names
 *         a real on-disk folder.
 *
 *   2. $projectRoot . '/shared/assets/images/manifest/products/restaurant/' . $tail
 *         The manifest root that actually exists. $tail is the last
 *         two path segments of $folder. Covers the seed data's
 *         `<resto>/<slug>` shape.
 *
 *   3. $projectRoot . '/shared/uploads/restaurant/' . $tail
 *         The future runtime upload root. Covers a future
 *         `<branch>/<product_id>` shape.
 *
 * Adding a fourth root is a change to one array.
 *
 * ---------------------------------------------------------------------
 * RETURN SHAPE
 * ---------------------------------------------------------------------
 * Filenames are BARE. Folder paths are project-root-relative. The
 * page concatenates:
 *
 *     $assetBase (minus trailing 'shared/') . $image_base . $filename
 *
 * exactly the way customer/pages/profile.php builds the avatar URL
 * from customer_profile.profile_picture.
 *
 * ---------------------------------------------------------------------
 * FALLBACK
 * ---------------------------------------------------------------------
 * A column value whose tail does not resolve under any root yields
 * an empty `image_base`, an empty `primary_image`, and an empty
 * `product_images` array. Every caller in the tree already falls
 * back to shared/assets/images/icons/restaurant.svg in that case.
 *
 * @package FitPal
 * @version 8.0 — Adds getProductImageBasePath() and exposes it to
 *                callers as the `image_base` field on every read
 *                shape. The pages now build the browser URL from
 *                the folder the reader actually resolved, instead
 *                of from the raw column value. This is what makes
 *                the browser request match a file on disk when the
 *                column omits the `restaurant/` segment.
 *
 *                (7.0: candidate-list resolution. 6.2: depth
 *                correction. 6.0: multi-image read support.
 *                5.2: reviews reader added.)
 */

declare(strict_types=1);

/* =============================================================
 * SINGLE PRODUCT
 * ============================================================= */

/**
 * Get a single product by ID with full details, customization
 * rules, and the ordered list of its image filenames.
 *
 * Return shape adds three image-related keys:
 *   image           raw dietary_information.images value
 *   image_base      project-root-relative folder the reader
 *                   resolved, or '' when nothing matched
 *   product_images  ordered list of bare filenames inside that
 *                   folder, or []
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
    if (!$product) {
        return null;
    }

    $folder = (string)($product['product_image'] ?? '');

    $product['image_base']     = getProductImageBasePath($folder);
    $product['product_images'] = getProductImageFilenames($folder);

    return $product;
}

/* =============================================================
 * CUSTOMIZATION RULES
 * ============================================================= */

/**
 * Fetch customization components for a product and group them into
 * presentation-ready shapes (static / choice / modifier / multi).
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

/* =============================================================
 * MENU LISTING
 * ============================================================= */

/**
 * Get menu data with pagination.
 *
 * Each product in the response carries:
 *   image          raw dietary_information.images value (unchanged)
 *   image_base     project-root-relative folder the reader resolved
 *   primary_image  first image-*.{ext} filename inside that folder
 *
 * The page builds the browser URL from image_base, not from image.
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

        $folder = (string)($product['product_image'] ?? '');

        $restaurants[$restId]['branches'][$branchId]['products'][] = [
            'id' => (int)$product['id'],
            'name' => $product['name'],
            'description' => $product['description'] ?? '',
            'price' => (float)$product['price'],
            'stock' => (int)$product['stock'],
            'image' => $folder,
            'image_base' => getProductImageBasePath($folder),
            'primary_image' => getProductPrimaryFilename($folder),
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

/* =============================================================
 * FILTER DATA
 * ============================================================= */

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

/* =============================================================
 * RELATED PRODUCTS
 * ============================================================= */

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
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $folder = (string)($row['product_image'] ?? '');
        $row['image'] = $folder;
        $row['image_base'] = getProductImageBasePath($folder);
        $row['primary_image'] = getProductPrimaryFilename($folder);
    }
    unset($row);

    return $rows;
}

/* =============================================================
 * REVIEWS
 * ============================================================= */

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

/* =============================================================
 * MULTI-IMAGE READ HELPERS
 *
 * Pure. No SQL. No session. No URL prefixing. The page that
 * renders an <img> prepends its own project-root URL, exactly the
 * way customer/pages/profile.php resolves profile_picture.
 * ============================================================= */

/**
 * Return the ordered list of candidate absolute directories for a
 * given dietary_information.images value.
 *
 * @param string $folderPath
 * @return array<int, string>
 */
function getProductImageCandidateDirs(string $folderPath): array
{
    $folderPath = trim($folderPath);
    if ($folderPath === '') {
        return [];
    }

    $folderPath = rtrim(str_replace('\\', '/', $folderPath), '/') . '/';

    $projectRoot = dirname(__DIR__, 3);

    $candidates = [];

    // Candidate 1: the value as stored.
    $candidates[] = $projectRoot . '/' . ltrim($folderPath, '/');

    // Extract the trailing two path segments.
    $trimmed = trim($folderPath, '/');
    $parts   = explode('/', $trimmed);
    $parts   = array_values(array_filter($parts, static fn($p) => $p !== ''));

    if (count($parts) >= 2) {
        $tail = $parts[count($parts) - 2] . '/' . $parts[count($parts) - 1] . '/';

        // Candidate 2: manifest layout with the extra `restaurant/` segment.
        $candidates[] = $projectRoot
            . '/shared/assets/images/manifest/products/restaurant/'
            . $tail;

        // Candidate 3: runtime upload root.
        $candidates[] = $projectRoot . '/shared/uploads/restaurant/' . $tail;
    }

    return $candidates;
}

/**
 * Return the first candidate directory that exists on disk, as an
 * absolute filesystem path, or '' when none exist.
 *
 * @param string $folderPath
 * @return string
 */
function resolveProductImageDir(string $folderPath): string
{
    foreach (getProductImageCandidateDirs($folderPath) as $candidate) {
        if (is_dir($candidate)) {
            return $candidate;
        }
    }
    return '';
}

/**
 * Return the project-root-relative folder path the reader resolved
 * for a column value, or '' when nothing matched.
 *
 * The returned path always begins with `shared/` and always ends
 * with `/`. The page prepends its own project-root URL (obtained
 * by trimming the trailing `shared/` off $assetBase) and appends a
 * bare filename to build the browser URL.
 *
 * This is the value that makes the browser request match the folder
 * that actually exists on disk, regardless of whether the column
 * value carried the full on-disk prefix.
 *
 * @param string $folderPath
 * @return string
 */
function getProductImageBasePath(string $folderPath): string
{
    $abs = resolveProductImageDir($folderPath);
    if ($abs === '') {
        return '';
    }

    $projectRoot = dirname(__DIR__, 3);

    // Convert the absolute directory back to a project-root-relative
    // path with forward slashes and a trailing slash.
    $normalized = str_replace('\\', '/', $abs);
    $root       = str_replace('\\', '/', $projectRoot);

    if (stripos($normalized, $root) === 0) {
        $normalized = substr($normalized, strlen($root));
    }

    $normalized = ltrim($normalized, '/');
    if ($normalized === '') {
        return '';
    }

    return rtrim($normalized, '/') . '/';
}

/**
 * Return the ordered list of image-*.{ext} filenames inside the
 * folder the reader resolved for a column value.
 *
 * Filenames are BARE. Ordering is 1..5, regardless of extension.
 *
 * @param string $folderPath
 * @return array<int, string>
 */
function getProductImageFilenames(string $folderPath): array
{
    $dir = resolveProductImageDir($folderPath);
    if ($dir === '') {
        return [];
    }

    $filenames = [];

    for ($index = 1; $index <= 5; $index++) {
        $matches = glob($dir . '/image-' . $index . '.*');

        if (empty($matches)) {
            continue;
        }

        $filenames[] = basename($matches[0]);
    }

    return $filenames;
}

/**
 * Return only the FIRST image-*.{ext} filename inside the folder the
 * reader resolved for a column value, or '' when nothing matched.
 *
 * @param string $folderPath
 * @return string
 */
function getProductPrimaryFilename(string $folderPath): string
{
    $dir = resolveProductImageDir($folderPath);
    if ($dir === '') {
        return '';
    }

    $matches = glob($dir . '/image-1.*');
    if (empty($matches)) {
        return '';
    }

    return basename($matches[0]);
}