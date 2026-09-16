<?php
/**
 * FitPal Customer Cart Handler
 *
 * Persistent cart (cart table). Separate from the session order queue.
 *
 * Actions:
 *   add             → insert or merge into cart
 *   update_quantity → change qty on a cart row
 *   remove_item     → delete a cart row
 *   clear           → delete all rows for this customer
 *   get_count       → return total unit count
 *
 * Accepts POST with `action`. For backwards compatibility, accepts
 * `queue_action=cart` as an alias for `action=add`.
 *
 * Responds with JSON when X-Requested-With: XMLHttpRequest.
 *
 * @package FitPal
 * @version 3.1
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isAjax = (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
);

function cartFail(string $message, bool $isAjax, string $redirect = '../../pages/menu.php'): never
{
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => $message]);
        exit;
    }
    $_SESSION['cart_error'] = $message;
    header('Location: ' . $redirect);
    exit;
}

function cartSuccess(array $payload, bool $isAjax, string $redirect = '../../pages/menu.php'): never
{
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['status' => 'success'], $payload));
        exit;
    }
    if (!empty($payload['message'])) {
        $_SESSION['cart_success'] = $payload['message'];
    }
    header('Location: ' . $redirect);
    exit;
}

// =====================================================
// AUTH
// =====================================================
if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    cartFail('Please sign in to manage your cart.', $isAjax, '../../pages/sign-in.php');
}

$customerId = (int)$_SESSION['customer_id'];

// =====================================================
// CSRF
// =====================================================
$givenToken = (string)($_POST['csrf_token'] ?? '');
$sessToken  = (string)($_SESSION['csrf_token'] ?? '');

if ($sessToken === '' || $givenToken === '' || !hash_equals($sessToken, $givenToken)) {
    cartFail('Security validation failed. Please try again.', $isAjax);
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';

// =====================================================
// ACTION RESOLUTION
//
// The form on product-detail.php sends `action=add` directly, so
// the first branch below catches it. The `queue_action=cart` alias
// is kept for older markup that may still be cached in a browser.
// =====================================================
$action = (string)($_POST['action'] ?? '');

if ($action === '') {
    $queueAction = (string)($_POST['queue_action'] ?? '');
    if ($queueAction === 'cart') {
        $action = 'add';
    }
}

try {
    switch ($action) {
        case 'add':
            handleAdd($database_connection, $customerId, $isAjax);
            break;
        case 'update_quantity':
            handleUpdateQuantity($database_connection, $customerId, $isAjax);
            break;
        case 'remove_item':
            handleRemoveItem($database_connection, $customerId, $isAjax);
            break;
        case 'clear':
            handleClear($database_connection, $customerId, $isAjax);
            break;
        case 'get_count':
            handleGetCount($database_connection, $customerId);
            break;
        default:
            cartFail('Invalid action.', $isAjax);
    }
} catch (RuntimeException $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    cartFail($e->getMessage(), $isAjax);
} catch (PDOException $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Cart handler database error: ' . $e->getMessage());
    cartFail('A system error occurred. Please try again.', $isAjax);
}

// =====================================================
// IMPLEMENTATIONS
// =====================================================

function handleAdd(PDO $db, int $customerId, bool $isAjax): void
{
    $productId          = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    $quantity           = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
    $clientTotal        = isset($_POST['total_price']) ? (float)$_POST['total_price'] : 0.0;
    $customizationsJson = (string)($_POST['customizations'] ?? '');

    if ($quantity < 1) {
        throw new RuntimeException('Quantity must be at least 1.');
    }
    if ($productId <= 0) {
        throw new RuntimeException('Invalid product selected.');
    }

    $customizations = [];
    if ($customizationsJson !== '') {
        $decoded = json_decode($customizationsJson, true);
        if (is_array($decoded)) {
            $customizations = $decoded;
        }
    }

    $db->beginTransaction();

    $stmt = $db->prepare(
        "SELECT product_id, name, stock, is_active, price, base_price
         FROM product
         WHERE product_id = :product_id
         FOR UPDATE"
    );
    $stmt->execute([':product_id' => $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        throw new RuntimeException('Product not found.');
    }
    if (!$product['is_active']) {
        throw new RuntimeException('Product is not available.');
    }

    $checkStmt = $db->prepare(
        "SELECT cart_id, quantity FROM cart
         WHERE customer_id = :customer_id AND product_id = :product_id"
    );
    $checkStmt->execute([
        ':customer_id' => $customerId,
        ':product_id'  => $productId,
    ]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    $newQuantity = $existing ? ((int)$existing['quantity'] + $quantity) : $quantity;

    if ((int)$product['stock'] < $newQuantity) {
        throw new RuntimeException(
            'Insufficient stock. Available: ' . $product['stock'] .
            ', In cart: ' . ($existing ? (int)$existing['quantity'] : 0) .
            ', Requested: ' . $newQuantity
        );
    }

    $basePrice = (float)($product['base_price'] ?? 0);
    if ($basePrice <= 0) {
        $basePrice = (float)$product['price'];
    }

    $serverUnitPrice = $basePrice;
    foreach ($customizations as $cust) {
        if (!is_array($cust)) continue;
        if (!isset($cust['price_modifier'], $cust['quantity'])) continue;
        if (($cust['selected_option'] ?? '') === 'remove') continue;
        if ((int)($cust['ingredient_id'] ?? 0) === 0) continue;
        if (($cust['type'] ?? '') === 'notes') continue;
        $serverUnitPrice += (float)$cust['price_modifier'] * (int)$cust['quantity'];
    }
    if ($serverUnitPrice < 0) {
        $serverUnitPrice = 0.0;
    }

    if ($clientTotal > 0) {
        $expectedTotal = $serverUnitPrice * $quantity;
        if (abs($expectedTotal - $clientTotal) > 0.01) {
            error_log(sprintf(
                'Cart price mismatch for product %d: client=%.2f server=%.2f',
                $productId, $clientTotal, $expectedTotal
            ));
        }
    }

    $customizationData = !empty($customizations) ? json_encode($customizations) : null;

    if ($existing) {
        $updateStmt = $db->prepare(
            "UPDATE cart
             SET quantity = :quantity,
                 price = :price,
                 customization_data = :customization_data
             WHERE cart_id = :cart_id"
        );
        $updateStmt->execute([
            ':quantity'           => $newQuantity,
            ':price'              => $serverUnitPrice,
            ':customization_data' => $customizationData,
            ':cart_id'            => $existing['cart_id'],
        ]);
    } else {
        $insertStmt = $db->prepare(
            "INSERT INTO cart
                (customer_id, product_id, quantity, price, added_at, customization_data)
             VALUES
                (:customer_id, :product_id, :quantity, :price, NOW(), :customization_data)"
        );
        $insertStmt->execute([
            ':customer_id'        => $customerId,
            ':product_id'         => $productId,
            ':quantity'           => $quantity,
            ':price'              => $serverUnitPrice,
            ':customization_data' => $customizationData,
        ]);
    }

    $cartCount = getCartCount($db, $customerId);
    $db->commit();

    cartSuccess(
        [
            'message'    => 'Added to cart',
            'cart_count' => $cartCount,
        ],
        $isAjax,
        '../../pages/product-detail.php?id=' . $productId
    );
}

function handleUpdateQuantity(PDO $db, int $customerId, bool $isAjax): void
{
    $cartId   = isset($_POST['cart_id']) ? (int)$_POST['cart_id'] : 0;
    $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;

    if ($cartId <= 0) {
        throw new RuntimeException('Invalid cart item.');
    }

    $stmt = $db->prepare(
        "SELECT c.cart_id, p.stock
         FROM cart c
         JOIN product p ON c.product_id = p.product_id
         WHERE c.cart_id = :cart_id AND c.customer_id = :customer_id
         LIMIT 1"
    );
    $stmt->execute([':cart_id' => $cartId, ':customer_id' => $customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new RuntimeException('Cart item not found.');
    }

    $maxStock = (int)$row['stock'];
    if ($quantity < 1)         $quantity = 1;
    if ($quantity > $maxStock) $quantity = $maxStock;

    $updateStmt = $db->prepare(
        "UPDATE cart SET quantity = :quantity
         WHERE cart_id = :cart_id AND customer_id = :customer_id"
    );
    $updateStmt->execute([
        ':quantity'    => $quantity,
        ':cart_id'     => $cartId,
        ':customer_id' => $customerId,
    ]);

    cartSuccess(
        ['message' => 'Quantity updated', 'quantity' => $quantity],
        $isAjax,
        '../../pages/cart.php'
    );
}

function handleRemoveItem(PDO $db, int $customerId, bool $isAjax): void
{
    $cartId = isset($_POST['cart_id']) ? (int)$_POST['cart_id'] : 0;
    if ($cartId <= 0) {
        throw new RuntimeException('Invalid cart item.');
    }

    $deleteStmt = $db->prepare(
        "DELETE FROM cart WHERE cart_id = :cart_id AND customer_id = :customer_id"
    );
    $deleteStmt->execute([':cart_id' => $cartId, ':customer_id' => $customerId]);

    cartSuccess(['message' => 'Item removed'], $isAjax, '../../pages/cart.php');
}

function handleClear(PDO $db, int $customerId, bool $isAjax): void
{
    $deleteStmt = $db->prepare("DELETE FROM cart WHERE customer_id = :customer_id");
    $deleteStmt->execute([':customer_id' => $customerId]);

    cartSuccess(['message' => 'Cart cleared', 'cart_count' => 0], $isAjax, '../../pages/cart.php');
}

function handleGetCount(PDO $db, int $customerId): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'success', 'count' => getCartCount($db, $customerId)]);
    exit;
}

function getCartCount(PDO $db, int $customerId): int
{
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(quantity), 0) FROM cart WHERE customer_id = :customer_id"
    );
    $stmt->execute([':customer_id' => $customerId]);
    return (int)$stmt->fetchColumn();
}