<?php
/**
 * Cart Database Queries
 *
 * @package FitPal
 * @version 2.0
 */

declare(strict_types=1);

/**
 * Get customer's cart items
 *
 * @param PDO $db Database connection
 * @param int $customerId Customer ID
 * @return array Cart items with product details
 */
function getCustomerCart(PDO $db, int $customerId): array
{
    $stmt = $db->prepare(
        "SELECT 
            c.cart_id, c.product_id, c.quantity, c.price, c.added_at,
            p.name AS product_name, p.description, p.stock AS product_stock,
            rb.branch_name,
            r.business_name AS restaurant_name,
            di.dietary_tags, di.allergens, di.images AS product_image
        FROM cart c
        JOIN product p ON c.product_id = p.product_id
        JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
        JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
        LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
        WHERE c.customer_id = ?
        ORDER BY c.added_at DESC"
    );
    $stmt->execute([$customerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get total number of items in cart
 *
 * @param PDO $db Database connection
 * @param int $customerId Customer ID
 * @return int Total quantity of all items
 */
function getCartItemCount(PDO $db, int $customerId): int
{
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(quantity), 0) FROM cart WHERE customer_id = ?"
    );
    $stmt->execute([$customerId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Get quantity of a specific product in cart
 *
 * @param PDO $db Database connection
 * @param int $customerId Customer ID
 * @param int $productId Product ID
 * @return int|false Quantity if in cart, false if not
 */
function getCartItemQuantity(PDO $db, int $customerId, int $productId): int|false
{
    $stmt = $db->prepare(
        "SELECT quantity FROM cart WHERE customer_id = ? AND product_id = ?"
    );
    $stmt->execute([$customerId, $productId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? (int) $result['quantity'] : false;
}

/**
 * Add item to cart with stock validation (ACID compliant)
 * Uses SELECT FOR UPDATE to lock the product row and prevent race conditions.
 *
 * @param PDO $db Database connection
 * @param int $customerId Customer ID
 * @param int $productId Product ID
 * @param int $quantity Quantity to add
 * @return bool True on success
 * @throws RuntimeException If product unavailable or stock insufficient
 */
function addToCart(PDO $db, int $customerId, int $productId, int $quantity): bool
{
    $db->beginTransaction();

    try {
        // Lock the product row to prevent race conditions
        $stmt = $db->prepare(
            "SELECT stock, is_active FROM product WHERE product_id = ? FOR UPDATE"
        );
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            throw new RuntimeException('Product not found.');
        }

        if (!$product['is_active']) {
            throw new RuntimeException('Product is not available.');
        }

        if ($product['stock'] < $quantity) {
            throw new RuntimeException(
                'Insufficient stock. Available: ' . $product['stock'] . 
                ', Requested: ' . $quantity
            );
        }

        // Insert or update cart atomically
        $stmt = $db->prepare(
            "INSERT INTO cart (customer_id, product_id, quantity, price)
             VALUES (?, ?, ?, (SELECT price FROM product WHERE product_id = ?))
             ON DUPLICATE KEY UPDATE quantity = quantity + ?"
        );
        $stmt->execute([$customerId, $productId, $quantity, $productId, $quantity]);

        $db->commit();
        return true;

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Update cart item quantity
 *
 * @param PDO $db Database connection
 * @param int $cartId Cart item ID
 * @param int $quantity New quantity
 * @return bool True on success
 */
function updateCartQuantity(PDO $db, int $cartId, int $quantity): bool
{
    $stmt = $db->prepare(
        "UPDATE cart SET quantity = ? WHERE cart_id = ?"
    );
    return $stmt->execute([$quantity, $cartId]);
}

/**
 * Remove item from cart
 *
 * @param PDO $db Database connection
 * @param int $cartId Cart item ID
 * @return bool True on success
 */
function removeFromCart(PDO $db, int $cartId): bool
{
    $stmt = $db->prepare("DELETE FROM cart WHERE cart_id = ?");
    return $stmt->execute([$cartId]);
}

/**
 * Clear entire cart
 *
 * @param PDO $db Database connection
 * @param int $customerId Customer ID
 * @return bool True on success
 */
function clearCart(PDO $db, int $customerId): bool
{
    $stmt = $db->prepare("DELETE FROM cart WHERE customer_id = ?");
    return $stmt->execute([$customerId]);
}