<?php
/**
 * FitPal Cart Database Queries
 *
 * Pure data-access layer for the persistent `cart` table.
 * This is the single source of truth for all cart-related
 * SQL — nothing cart-related lives in branch-queries.php.
 *
 * No $_POST, no header(), no echo.
 *
 * @package FitPal
 * @version 5.1 — Customization enrichment uses ingredient.name
 */

declare(strict_types=1);

/**
 * Get customer's cart items with customizations parsed from JSON.
 */
function getCustomerCart(PDO $db, int $customerId): array
{
    $stmt = $db->prepare(
        "SELECT
            c.cart_id,
            c.product_id,
            c.quantity,
            c.price,
            c.added_at,
            c.customization_data,
            p.name AS product_name,
            p.description,
            p.stock AS product_stock,
            p.is_customizable,
            p.customization_type,
            p.base_price,
            rb.branch_name,
            rb.restaurant_branch_id,
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
         ORDER BY c.added_at DESC"
    );
    $stmt->execute([':customer_id' => $customerId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    enrichCartItemsWithCustomizations($db, $items);

    return $items;
}

/**
 * Get cart items with product details for the cart page (non-paginated).
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
 * Enrich an array of cart rows with their parsed customizations.
 * Modifies $items in place.
 *
 * Loads all ingredient metadata in one query so we don't N+1.
 */
function enrichCartItemsWithCustomizations(PDO $db, array &$items): void
{
    if (empty($items)) return;

    // Parse all customization payloads first
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

    // Batch-load ingredient metadata once. NOTE: column is `name`.
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

    // Resolve per-item names + modifiers using product_composition
    foreach ($items as &$item) {
        $productId = (int)($item['product_id'] ?? 0);
        $raw       = $item['_raw_customizations'];
        $resolved  = [];

        if ($productId > 0 && !empty($raw)) {
            $ruleStmt = $db->prepare(
                "SELECT ingredient_id, price_modifier, max_quantity
                   FROM product_composition
                  WHERE product_id = :product_id"
            );
            $ruleStmt->execute([':product_id' => $productId]);
            $rules = [];
            while ($r = $ruleStmt->fetch(PDO::FETCH_ASSOC)) {
                $rules[(int)$r['ingredient_id']] = $r;
            }

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
        }

        $item['customizations'] = $resolved;
        unset($item['_raw_customizations']);
        unset($item['customization_data']);
    }
    unset($item);
}

/**
 * Build a display-ready breakdown of a cart line's customization data.
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

/* ---------------------------------------------------------------
 * Everything below is unchanged from the previous version.
 * --------------------------------------------------------------- */

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

function getCartGroupedByBranch(PDO $db, int $customerId): array
{
    $stmt = $db->prepare(
        "SELECT
            c.cart_id,
            c.product_id,
            c.quantity,
            c.price,
            c.customization_data,
            p.name AS product_name,
            p.restaurant_branch_id,
            p.is_customizable,
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
        $branchId = (int)$item['restaurant_branch_id'];
        if (!isset($grouped[$branchId])) {
            $grouped[$branchId] = [
                'branch_id'       => $branchId,
                'branch_name'     => $item['branch_name'],
                'restaurant_id'   => (int)$item['restaurant_id'],
                'restaurant_name' => $item['restaurant_name'],
                'items'           => [],
                'subtotal'        => 0.0,
            ];
        }

        $customizations = parseCartCustomizations($item['customization_data'] ?? null);

        $grouped[$branchId]['items'][] = [
            'cart_id'         => (int)$item['cart_id'],
            'product_id'      => (int)$item['product_id'],
            'product_name'    => $item['product_name'],
            'quantity'        => (int)$item['quantity'],
            'price'           => (float)$item['price'],
            'total'           => (float)$item['price'] * (int)$item['quantity'],
            'is_customizable' => (bool)($item['is_customizable'] ?? false),
            'customizations'  => $customizations,
        ];
        $grouped[$branchId]['subtotal'] += (float)$item['price'] * (int)$item['quantity'];
    }

    return $grouped;
}

function getCartBranchIds(PDO $db, int $customerId): array
{
    $stmt = $db->prepare(
        "SELECT DISTINCT p.restaurant_branch_id
         FROM cart c
         JOIN product p ON c.product_id = p.product_id
         WHERE c.customer_id = :customer_id"
    );
    $stmt->execute([':customer_id' => $customerId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function getCartCount(PDO $db, int $customerId): int
{
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(quantity), 0) FROM cart WHERE customer_id = :customer_id"
    );
    $stmt->execute([':customer_id' => $customerId]);
    return (int)$stmt->fetchColumn();
}

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
         WHERE customer_id         = :customer_id
           AND product_id          = :product_id
           AND customization_hash  = :customization_hash
         LIMIT 1"
    );
    $stmt->execute([
        ':customer_id'        => $customerId,
        ':product_id'         => $productId,
        ':customization_hash' => $customizationHash,
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getCartItemForUpdate(PDO $db, int $cartId, int $customerId): array|false
{
    $stmt = $db->prepare(
        "SELECT c.cart_id, p.stock
         FROM cart c
         JOIN product p ON c.product_id = p.product_id
         WHERE c.cart_id = :cart_id AND c.customer_id = :customer_id
         LIMIT 1"
    );
    $stmt->execute([':cart_id' => $cartId, ':customer_id' => $customerId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function buildCartCustomizationPayload(array $customizations): ?string
{
    if (empty($customizations)) return null;
    return json_encode(['customizations' => $customizations]);
}

function computeCartCustomizationHash(array $customizations): string
{
    $inner = $customizations === [] ? '' : json_encode($customizations);
    return hash('sha256', (string)$inner);
}

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

function deleteCartItem(PDO $db, int $cartId, int $customerId): void
{
    $stmt = $db->prepare(
        "DELETE FROM cart WHERE cart_id = :cart_id AND customer_id = :customer_id"
    );
    $stmt->execute([':cart_id' => $cartId, ':customer_id' => $customerId]);
}

function clearCart(PDO $db, int $customerId): void
{
    $stmt = $db->prepare("DELETE FROM cart WHERE customer_id = :customer_id");
    $stmt->execute([':customer_id' => $customerId]);
}

function getCartItemsForUpdate(PDO $db, int $customerId): array
{
    $stmt = $db->prepare(
        "SELECT cart_id, product_id, quantity
         FROM cart
         WHERE customer_id = :customer_id
         FOR UPDATE"
    );
    $stmt->execute([':customer_id' => $customerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

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