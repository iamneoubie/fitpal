<?php
/**
 * FitPal Cart Database Queries
 *
 * Feature file for the persistent `cart` table. It owns every query
 * against `cart`, plus the pure functions that operate on rows
 * returned from it.
 *
 * Why the pure functions live here and not in cart-handler.php:
 *
 *   Pages that render cart data (cart.php) need to call functions
 *   like getCartCustomizationBreakdown(). They cannot include
 *   cart-handler.php, because that file runs a full request
 *   dispatch at load time — the switch, the CSRF check, the
 *   cartFail()/exit path. Including it from a page would hijack
 *   the request.
 *
 *   This file only declares functions. It is safe to require from
 *   anywhere.
 *
 * No $_POST, no header(), no echo.
 *
 * @package FitPal
 * @version 6.1 — Pure cart-feature functions co-located here because
 *                this is the only cart file that is safe to include.
 */

declare(strict_types=1);

/* ---------------------------------------------------------------
 * READS
 * --------------------------------------------------------------- */

/**
 * Get cart items with product details for the cart page (non-paginated).
 *
 * @param PDO $db
 * @param int $customerId
 * @return array<int, array<string, mixed>>
 */
function getCartItemsWithProductDetails(PDO $db, int $customerId): array
{
    $stmt = $db->prepare(
        "SELECT
            c.cart_id,
            c.quantity,
            c.price,
            c.customization_data,
            p.product_id,
            p.name,
            p.price       AS current_price,
            p.stock,
            p.is_active,
            p.is_customizable,
            p.base_price,
            rb.restaurant_branch_id,
            rb.branch_name,
            r.restaurant_id,
            r.business_name,
            COALESCE(di.images, '') AS product_image
         FROM cart c
         JOIN product p ON c.product_id = p.product_id
         JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
         JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
         LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
         WHERE c.customer_id = :customer_id
         ORDER BY
            p.is_active DESC,
            p.stock > 0 DESC,
            c.added_at DESC"
    );
    $stmt->execute([':customer_id' => $customerId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    enrichCartItemsWithCustomizations($db, $items);

    return $items;
}

/**
 * Get one page of cart rows + total/total pages.
 *
 * @param PDO $db
 * @param int $customerId
 * @param int $page
 * @param int $perPage
 * @return array{
 *     items: array<int, array<string, mixed>>,
 *     total: int,
 *     totalPages: int,
 *     page: int,
 *     perPage: int
 * }
 */
function getCartItemsWithProductDetailsPaginated(
    PDO $db,
    int $customerId,
    int $page = 1,
    int $perPage = 5
): array {
    if ($page    < 1) $page    = 1;
    if ($perPage < 1) $perPage = 5;

    $countStmt = $db->prepare(
        "SELECT COUNT(*) FROM cart WHERE customer_id = :customer_id"
    );
    $countStmt->execute([':customer_id' => $customerId]);
    $total = (int)$countStmt->fetchColumn();

    $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
    if ($page > $totalPages) $page = $totalPages;

    $offset = ($page - 1) * $perPage;

    $stmt = $db->prepare(
        "SELECT
            c.cart_id,
            c.quantity,
            c.price,
            c.customization_data,
            p.product_id,
            p.name,
            p.price       AS current_price,
            p.stock,
            p.is_active,
            p.is_customizable,
            p.base_price,
            rb.restaurant_branch_id,
            rb.branch_name,
            r.restaurant_id,
            r.business_name,
            COALESCE(di.images, '') AS product_image
         FROM cart c
         JOIN product p ON c.product_id = p.product_id
         JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
         JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
         LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
         WHERE c.customer_id = :customer_id
         ORDER BY
            p.is_active DESC,
            p.stock > 0 DESC,
            c.added_at DESC
         LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue(':customer_id', $customerId, PDO::PARAM_INT);
    $stmt->bindValue(':limit',       $perPage,    PDO::PARAM_INT);
    $stmt->bindValue(':offset',      $offset,     PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    enrichCartItemsWithCustomizations($db, $items);

    return [
        'items'      => $items,
        'total'      => $total,
        'totalPages' => $totalPages,
        'page'       => $page,
        'perPage'    => $perPage,
    ];
}

/**
 * Totals for the whole cart (units + rows). Used for the empty-state
 * decision and the header badge.
 *
 * @param PDO $db
 * @param int $customerId
 * @return array{unitCount:int, rowCount:int}
 */
function getCartTotals(PDO $db, int $customerId): array
{
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(quantity), 0) AS unit_count,
                COUNT(*)                   AS row_count
           FROM cart
          WHERE customer_id = :customer_id"
    );
    $stmt->execute([':customer_id' => $customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['unit_count' => 0, 'row_count' => 0];
    return [
        'unitCount' => (int)$row['unit_count'],
        'rowCount'  => (int)$row['row_count'],
    ];
}

/**
 * Total unit count for the cart (used by the header badge and the
 * cart-handler's AJAX responses).
 *
 * @param PDO $db
 * @param int $customerId
 * @return int
 */
function getCartCount(PDO $db, int $customerId): int
{
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(quantity), 0) FROM cart WHERE customer_id = :customer_id"
    );
    $stmt->execute([':customer_id' => $customerId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Fetch a product's cart-relevant fields, with a row lock.
 *
 * Called inside the add flow so the stock and price are stable across
 * the read-check-write sequence. The lock is what prevents two
 * concurrent "add" requests from both seeing stale stock.
 *
 * @param PDO $db
 * @param int $productId
 * @return array<string, mixed>|false
 */
function getProductForCart(PDO $db, int $productId): array|false
{
    $stmt = $db->prepare(
        "SELECT product_id, name, stock, is_active, price, base_price
         FROM product
         WHERE product_id = :product_id
         FOR UPDATE"
    );
    $stmt->execute([':product_id' => $productId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Fetch a cart row plus the product's current stock, with locks on
 * both. Used by the quantity-update flow so the clamp against
 * `p.stock` cannot race against a concurrent stock change.
 *
 * @param PDO $db
 * @param int $cartId
 * @param int $customerId
 * @return array<string, mixed>|false
 */
function getCartItemForUpdate(PDO $db, int $cartId, int $customerId): array|false
{
    $stmt = $db->prepare(
        "SELECT c.cart_id, p.stock
         FROM cart c
         JOIN product p ON c.product_id = p.product_id
         WHERE c.cart_id = :cart_id
           AND c.customer_id = :customer_id
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([':cart_id' => $cartId, ':customer_id' => $customerId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Find an existing cart row for the same customer + product +
 * customization signature. When $customizationHash is null, matches
 * the oldest row for the product regardless of customization.
 *
 * @param PDO $db
 * @param int $customerId
 * @param int $productId
 * @param string|null $customizationHash
 * @return array<string, mixed>|false
 */
function getCartItemByProduct(
    PDO $db,
    int $customerId,
    int $productId,
    ?string $customizationHash = null
): array|false {
    if ($customizationHash === null) {
        $stmt = $db->prepare(
            "SELECT cart_id, quantity
             FROM cart
             WHERE customer_id = :customer_id
               AND product_id  = :product_id
             ORDER BY cart_id ASC
             LIMIT 1"
        );
        $stmt->execute([
            ':customer_id' => $customerId,
            ':product_id'  => $productId,
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    $stmt = $db->prepare(
        "SELECT cart_id, quantity
         FROM cart
         WHERE customer_id        = :customer_id
           AND product_id         = :product_id
           AND customization_hash = :customization_hash
         LIMIT 1"
    );
    $stmt->execute([
        ':customer_id'        => $customerId,
        ':product_id'         => $productId,
        ':customization_hash' => $customizationHash,
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Fetch the composition rules for a product — every ingredient that
 * can be selected or removed, with its price modifier and max quantity.
 *
 * Returns an array keyed by ingredient_id for O(1) lookup in the caller.
 *
 * @param PDO $db
 * @param int $productId
 * @return array<int, array{ingredient_id:int, price_modifier:float, min_quantity:int, max_quantity:int}>
 */
function getProductCompositionRules(PDO $db, int $productId): array
{
    $stmt = $db->prepare(
        "SELECT ingredient_id, price_modifier, min_quantity, max_quantity
           FROM product_composition
          WHERE product_id = :product_id"
    );
    $stmt->execute([':product_id' => $productId]);

    $rules = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rules[(int)$r['ingredient_id']] = [
            'ingredient_id'  => (int)$r['ingredient_id'],
            'price_modifier' => (float)$r['price_modifier'],
            'min_quantity'   => (int)$r['min_quantity'],
            'max_quantity'   => (int)$r['max_quantity'],
        ];
    }
    return $rules;
}

/**
 * Load the rows needed to copy selected cart lines into the session
 * order queue. Joins in the branch / restaurant / image metadata so
 * the caller can build a queue line without any further queries.
 *
 * Only rows that belong to $customerId are returned. If $selectedIds
 * is empty, returns an empty array.
 *
 * @param PDO $db
 * @param int $customerId
 * @param array<int, int> $selectedIds
 * @return array<int, array<string, mixed>>
 */
function getCartRowsForQueue(PDO $db, int $customerId, array $selectedIds): array
{
    if (empty($selectedIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));

    $stmt = $db->prepare(
        "SELECT
            c.cart_id,
            c.quantity,
            c.price,
            c.customization_data,
            p.product_id,
            p.name,
            p.stock,
            p.is_active,
            p.base_price,
            rb.restaurant_branch_id,
            rb.branch_name,
            r.business_name,
            COALESCE(di.images, '') AS product_image
         FROM cart c
         JOIN product p ON c.product_id = p.product_id
         JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
         JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
         LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
         WHERE c.customer_id = ?
           AND c.cart_id IN ($placeholders)"
    );
    $stmt->execute(array_merge([$customerId], $selectedIds));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ---------------------------------------------------------------
 * WRITES
 * --------------------------------------------------------------- */

/**
 * Insert a cart row and return its ID.
 *
 * @param PDO $db
 * @param int $customerId
 * @param int $productId
 * @param int $quantity
 * @param float $price
 * @param string|null $customizationData
 * @return int
 */
function insertCartItem(
    PDO $db,
    int $customerId,
    int $productId,
    int $quantity,
    float $price,
    ?string $customizationData
): int {
    $stmt = $db->prepare(
        "INSERT INTO cart
            (customer_id, product_id, quantity, price, added_at, customization_data)
         VALUES
            (:customer_id, :product_id, :quantity, :price, NOW(), :customization_data)"
    );
    $stmt->execute([
        ':customer_id'        => $customerId,
        ':product_id'         => $productId,
        ':quantity'           => $quantity,
        ':price'              => $price,
        ':customization_data' => $customizationData,
    ]);
    return (int)$db->lastInsertId();
}

/**
 * Update a cart row's quantity, price, and customization payload.
 *
 * @param PDO $db
 * @param int $cartId
 * @param int $quantity
 * @param float $price
 * @param string|null $customizationData
 * @return void
 */
function updateCartItem(
    PDO $db,
    int $cartId,
    int $quantity,
    float $price,
    ?string $customizationData
): void {
    $stmt = $db->prepare(
        "UPDATE cart
         SET quantity           = :quantity,
             price              = :price,
             customization_data = :customization_data
         WHERE cart_id = :cart_id"
    );
    $stmt->execute([
        ':quantity'           => $quantity,
        ':price'              => $price,
        ':customization_data' => $customizationData,
        ':cart_id'            => $cartId,
    ]);
}

/**
 * Update only the quantity of a cart row, scoped to the owner.
 *
 * @param PDO $db
 * @param int $cartId
 * @param int $customerId
 * @param int $quantity
 * @return void
 */
function updateCartItemQuantity(PDO $db, int $cartId, int $customerId, int $quantity): void
{
    $stmt = $db->prepare(
        "UPDATE cart SET quantity = :quantity
         WHERE cart_id = :cart_id AND customer_id = :customer_id"
    );
    $stmt->execute([
        ':quantity'    => $quantity,
        ':cart_id'     => $cartId,
        ':customer_id' => $customerId,
    ]);
}

/**
 * Delete a cart row, scoped to the owner.
 *
 * @param PDO $db
 * @param int $cartId
 * @param int $customerId
 * @return void
 */
function deleteCartItem(PDO $db, int $cartId, int $customerId): void
{
    $stmt = $db->prepare(
        "DELETE FROM cart WHERE cart_id = :cart_id AND customer_id = :customer_id"
    );
    $stmt->execute([':cart_id' => $cartId, ':customer_id' => $customerId]);
}

/**
 * Delete all cart rows for a customer.
 *
 * @param PDO $db
 * @param int $customerId
 * @return void
 */
function clearCart(PDO $db, int $customerId): void
{
    $stmt = $db->prepare("DELETE FROM cart WHERE customer_id = :customer_id");
    $stmt->execute([':customer_id' => $customerId]);
}

/* ---------------------------------------------------------------
 * ROW SHAPING (pure — no DB access)
 *
 * These functions operate on rows returned by the reads above.
 * They live here because this is the only cart file that is safe
 * to include from a page.
 * --------------------------------------------------------------- */

/**
 * Enrich an array of cart rows with their parsed customizations.
 * Modifies $items in place.
 *
 * Loads all ingredient names in one query and all composition rules
 * in one query per product set, so we don't N+1.
 *
 * @param PDO $db
 * @param array<int, array<string, mixed>> $items
 * @return void
 */
function enrichCartItemsWithCustomizations(PDO $db, array &$items): void
{
    if (empty($items)) return;

    // Parse all customization payloads first, collect ingredient IDs.
    $allIngredientIds = [];
    foreach ($items as &$item) {
        $raw = parseCartCustomizations($item['customization_data'] ?? null);
        foreach ($raw as $c) {
            $iid = (int)($c['ingredient_id'] ?? 0);
            if ($iid > 0) $allIngredientIds[$iid] = true;
        }
        $item['_raw_customizations'] = $raw;
    }
    unset($item);

    // Batch-load ingredient names once.
    $ingredientMeta = [];
    if (!empty($allIngredientIds)) {
        $ids = array_keys($allIngredientIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "SELECT ingredient_id, name
               FROM ingredient
              WHERE ingredient_id IN ($placeholders)"
        );
        $stmt->execute($ids);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ingredientMeta[(int)$row['ingredient_id']] = $row['name'];
        }
    }

    // Batch-load composition rules for every distinct product in the set.
    $productIds = [];
    foreach ($items as $item) {
        $pid = (int)($item['product_id'] ?? 0);
        if ($pid > 0) $productIds[$pid] = true;
    }
    $rulesByProduct = [];
    if (!empty($productIds)) {
        $pids = array_keys($productIds);
        $placeholders = implode(',', array_fill(0, count($pids), '?'));
        $ruleStmt = $db->prepare(
            "SELECT product_id, ingredient_id, price_modifier, max_quantity
               FROM product_composition
              WHERE product_id IN ($placeholders)"
        );
        $ruleStmt->execute($pids);
        while ($r = $ruleStmt->fetch(PDO::FETCH_ASSOC)) {
            $pid = (int)$r['product_id'];
            $iid = (int)$r['ingredient_id'];
            $rulesByProduct[$pid][$iid] = [
                'price_modifier' => (float)$r['price_modifier'],
                'max_quantity'   => (int)$r['max_quantity'],
            ];
        }
    }

    // Resolve per-item names + modifiers using the batched lookups.
    foreach ($items as &$item) {
        $productId = (int)($item['product_id'] ?? 0);
        $raw       = $item['_raw_customizations'];
        $rules     = $rulesByProduct[$productId] ?? [];
        $resolved  = [];

        foreach ($raw as $c) {
            $iid = (int)($c['ingredient_id'] ?? 0);
            if ($iid <= 0) continue;

            $name   = $ingredientMeta[$iid] ?? ('Ingredient #' . $iid);
            $option = (string)($c['selected_option'] ?? 'selected');
            $qty    = (int)($c['quantity'] ?? 1);
            if ($qty < 1) $qty = 1;

            $modifier = isset($rules[$iid])
                ? (float)$rules[$iid]['price_modifier']
                : 0.0;

            if ($option === 'remove') {
                $modifier = 0.0;
            }

            $resolved[] = [
                'ingredient_id'   => $iid,
                'ingredient_name' => $name,
                'selected_option' => $option,
                'quantity'        => $qty,
                'price_modifier'  => $modifier,
            ];
        }

        $item['customizations'] = $resolved;
        unset($item['_raw_customizations']);
        unset($item['customization_data']);
    }
    unset($item);
}

/**
 * Build the JSON payload stored in cart.customization_data.
 *
 * @param array<int, array<string, mixed>> $customizations
 * @return string|null
 */
function buildCartCustomizationPayload(array $customizations): ?string
{
    if (empty($customizations)) return null;
    return json_encode(['customizations' => $customizations]);
}

/**
 * Compute the customization hash stored in cart.customization_hash.
 *
 * Must match the algorithm used by the generated column in the schema
 * (SHA-256 of the JSON-encoded customizations array, or the empty
 * string when there are none).
 *
 * @param array<int, array<string, mixed>> $customizations
 * @return string
 */
function computeCartCustomizationHash(array $customizations): string
{
    $inner = $customizations === [] ? '' : json_encode($customizations);
    return hash('sha256', (string)$inner);
}

/**
 * Decode cart.customization_data into a normalized list.
 *
 * Accepts both shapes:
 *   {"customizations": [...]}
 *   [...]
 *
 * Drops notes entries and entries with no ingredient_id.
 *
 * @param string|null $json
 * @return array<int, array<string, mixed>>
 */
function parseCartCustomizations(?string $json): array
{
    if (empty($json)) return [];

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) return [];

    if (isset($decoded['customizations']) && is_array($decoded['customizations'])) {
        $decoded = $decoded['customizations'];
    }

    $out = [];
    foreach ($decoded as $cust) {
        if (!is_array($cust)) continue;
        if (isset($cust['type']) && $cust['type'] === 'notes') continue;
        if (empty($cust['ingredient_id'])) continue;
        $out[] = $cust;
    }
    return $out;
}

/**
 * Build a display-ready breakdown of a cart line's customization data.
 *
 * Uses the enriched `customizations` array produced by
 * enrichCartItemsWithCustomizations(). Returns the base price, the
 * list of modifications (additions and removals), the total modifier
 * amount, the derived unit price, and the line total.
 *
 * This is a display helper, not a query. It lives here because
 * cart.php needs to call it, and this file is the only cart file
 * that is safe to require from a page.
 *
 * @param array<string, mixed> $item
 * @return array{
 *     base_price: float,
 *     modifications: array<int, array{name:string, price:float, kind:string, quantity:int}>,
 *     modifier_total: float,
 *     unit_price: float,
 *     line_total: float
 * }
 */
function getCartCustomizationBreakdown(array $item): array
{
    $basePrice = (float)($item['base_price'] ?? 0);
    if ($basePrice <= 0) {
        $basePrice = (float)($item['price'] ?? 0);
    }

    $quantity = (int)($item['quantity'] ?? 1);
    $customs  = $item['customizations'] ?? [];

    $modifications = [];
    $modifierTotal = 0.0;

    foreach ($customs as $c) {
        if (!is_array($c)) continue;
        if (($c['type'] ?? '') === 'notes') continue;

        $name   = (string)($c['ingredient_name'] ?? $c['name'] ?? '');
        $option = (string)($c['selected_option'] ?? 'selected');
        $mod    = (float)($c['price_modifier'] ?? $c['price'] ?? 0);
        $qty    = (int)($c['quantity'] ?? 1);

        if ($name === '') continue;

        if ($option === 'remove') {
            $modifications[] = [
                'name'     => $name,
                'price'    => 0.0,
                'kind'     => 'remove',
                'quantity' => 1,
            ];
            continue;
        }

        if ($qty < 1) $qty = 1;
        $lineMod = $mod * $qty;
        $modifierTotal += $lineMod;

        $modifications[] = [
            'name'     => $name,
            'price'    => $lineMod,
            'kind'     => $lineMod < 0 ? 'remove' : 'add',
            'quantity' => $qty,
        ];
    }

    $unitPrice = $basePrice + $modifierTotal;
    if ($unitPrice < 0) $unitPrice = 0.0;

    return [
        'base_price'     => $basePrice,
        'modifications'  => $modifications,
        'modifier_total' => $modifierTotal,
        'unit_price'     => $unitPrice,
        'line_total'     => $unitPrice * $quantity,
    ];
}