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
 * @version 3.2 — getCartItemsWithProductDetails returns parsed customizations
 */

declare(strict_types=1);

/**
 * Get customer's cart items with customizations parsed from JSON.
 *
 * @param PDO $db
 * @param int $customerId
 * @return array<int, array<string, mixed>>
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

    foreach ($items as &$item) {
        $item['customizations'] = parseCartCustomizations($item['customization_data'] ?? null);
        unset($item['customization_data']);
    }
    unset($item);

    return $items;
}

/**
 * Get cart items with product details for the cart page (includes
 * current price, is_active flag, and stock for availability checks),
 * with customization_data already parsed into a structured array.
 *
 * The returned rows do NOT contain a raw `customization_data` column.
 * Callers should read `$item['customizations']`.
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

    foreach ($items as &$item) {
        $item['customizations'] = parseCartCustomizations($item['customization_data'] ?? null);
        unset($item['customization_data']);
    }
    unset($item);

    return $items;
}

/**
 * Get cart items grouped by branch with customizations parsed.
 *
 * @param PDO $db
 * @param int $customerId
 * @return array<int, array<string, mixed>>
 */
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

/**
 * Get all distinct branch IDs represented in a customer's cart.
 *
 * @param PDO $db
 * @param int $customerId
 * @return array<int, int>
 */
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

/**
 * Get the total quantity of items in a customer's cart.
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
 * Lock and load a product row for cart insertion.
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
 * Get a specific cart row for a customer+product.
 *
 * @param PDO $db
 * @param int $customerId
 * @param int $productId
 * @return array<string, mixed>|false
 */
function getCartItemByProduct(PDO $db, int $customerId, int $productId): array|false
{
    $stmt = $db->prepare(
        "SELECT cart_id, quantity FROM cart
         WHERE customer_id = :customer_id AND product_id = :product_id"
    );
    $stmt->execute([
        ':customer_id' => $customerId,
        ':product_id'  => $productId,
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get a specific cart row by cart_id scoped to a customer, joined
 * with its product stock (used by update_quantity).
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
         WHERE c.cart_id = :cart_id AND c.customer_id = :customer_id
         LIMIT 1"
    );
    $stmt->execute([':cart_id' => $cartId, ':customer_id' => $customerId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Insert a new cart row.
 *
 * @param PDO $db
 * @param int $customerId
 * @param int $productId
 * @param int $quantity
 * @param float $price
 * @param string|null $customizationData
 * @return int New cart_id
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
 * Update an existing cart row's quantity, price, and customizations.
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
 * Update only the quantity of a cart row (scoped to customer).
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
 * Delete a single cart row (scoped to customer).
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

/**
 * Lock and return all cart rows for a customer (used by queue commit).
 *
 * @param PDO $db
 * @param int $customerId
 * @return array<int, array<string, mixed>>
 */
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

/**
 * Parse the customization_data JSON from a cart row into a normalized
 * array. Notes entries are filtered out; ingredient entries are kept.
 *
 * @param string|null $json
 * @return array<int, array<string, mixed>>
 */
function parseCartCustomizations(?string $json): array
{
    if (empty($json)) {
        return [];
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $out = [];
    foreach ($decoded as $cust) {
        if (!is_array($cust)) {
            continue;
        }
        if (isset($cust['type']) && $cust['type'] === 'notes') {
            continue;
        }
        if (empty($cust['ingredient_id'])) {
            continue;
        }
        $out[] = $cust;
    }
    return $out;
}