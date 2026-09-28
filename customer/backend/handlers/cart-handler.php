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
 *   push_to_queue   → copy SELECTED cart rows into the session
 *                     order_queue, then tell the client to go to menu.php
 *
 * This handler contains NO SQL. All data access goes through
 * customer/backend/database/cart-queries.php.
 *
 * This file is NOT safe to require from a page — it runs a full
 * request dispatch at load time. Display helpers that pages need
 * (e.g. getCartCustomizationBreakdown) live in cart-queries.php.
 *
 * @package FitPal
 * @version 6.3 — push_to_queue now writes every field that
 *                createOrderFromQueue() requires onto each session
 *                queue line. The previous revision omitted
 *                restaurant_branch_id, which caused
 *                createOrderFromQueue() to drop every cart-sourced
 *                line and throw "Your order is empty." Every
 *                payment method (COD, Wallet, Online) failed
 *                identically because the failure happened before
 *                the payment branch was ever reached.
 *
 *                Specifically:
 *                  - New lines carry restaurant_branch_id,
 *                    base_price, and the raw customization_data
 *                    JSON, matching the shape queueEnrich()
 *                    produces.
 *                  - Merged lines refresh the same field set, so a
 *                    second push of the same cart row cannot leave
 *                    a stale price or drop a customization payload.
 *                  - The merge key is product + customization
 *                    signature, not product alone, so two cart
 *                    rows for the same product with different
 *                    customizations stay as two queue lines.
 *                  - No SQL added or moved. getCartRowsForQueue()
 *                    already selects rb.restaurant_branch_id and
 *                    di.images.
 *
 *                (6.2: CSRF validation reads customer_csrf_token
 *                instead of the shared csrf_token. 6.1: pure
 *                helpers moved to cart-queries.php.)
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isAjax = (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
);

/**
 * Terminate the request with an error response.
 */
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

/**
 * Terminate the request with a success response.
 *
 * @param array<string, mixed> $payload
 */
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

if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    cartFail('Please sign in to manage your cart.', $isAjax, '../../pages/sign-in.php');
}

$customerId = (int)$_SESSION['customer_id'];

// Per-role CSRF check. The customer role validates against its own
// session key, 'customer_csrf_token', never the shared 'csrf_token'.
// Another role in the same browser session could have unset or
// rotated the shared key on its own sign-in, which would otherwise
// invalidate the token this request was issued under. See general.md.
$givenToken = (string)($_POST['csrf_token'] ?? '');
$sessToken  = (string)($_SESSION['customer_csrf_token'] ?? '');

if ($sessToken === '' || $givenToken === '' || !hash_equals($sessToken, $givenToken)) {
    cartFail('Security validation failed. Please try again.', $isAjax);
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/cart-queries.php';

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
        case 'push_to_queue':
            handlePushToQueue($database_connection, $customerId, $isAjax);
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
} catch (Throwable $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Cart handler error: ' . $e->getMessage());
    cartFail('A system error occurred. Please try again.', $isAjax);
}

/* -----------------------------------------------------------------
 * PURE HELPERS (business logic, no DB access)
 * ----------------------------------------------------------------- */

/**
 * Compute the effective unit price for a cart line.
 *
 * Base price plus the sum of every selected ingredient's modifier
 * multiplied by its requested quantity. Quantities are clamped to the
 * ingredient's max_quantity from product_composition. Removed and
 * unknown ingredients contribute nothing.
 *
 * @param float $basePrice
 * @param array<int, array<string, mixed>> $customizations
 * @param array<int, array{ingredient_id:int, price_modifier:float, min_quantity:int, max_quantity:int}> $rules
 * @return float
 */
function computeServerUnitPrice(
    float $basePrice,
    array $customizations,
    array $rules
): float {
    $unitPrice = $basePrice;

    foreach ($customizations as $cust) {
        if (!is_array($cust)) continue;
        if (($cust['type'] ?? '') === 'notes') continue;

        $ingredientId = (int)($cust['ingredient_id'] ?? 0);
        if ($ingredientId <= 0)             continue;
        if (!isset($rules[$ingredientId]))  continue;

        $option = (string)($cust['selected_option'] ?? 'selected');
        if ($option === 'remove') continue;

        $requestedQty = (int)($cust['quantity'] ?? 0);
        if ($requestedQty <= 0) continue;

        $rule     = $rules[$ingredientId];
        $modifier = (float)$rule['price_modifier'];
        $maxQty   = (int)$rule['max_quantity'];

        if ($maxQty > 0 && $requestedQty > $maxQty) {
            $requestedQty = $maxQty;
        }

        $unitPrice += $modifier * $requestedQty;
    }

    return max(0.0, $unitPrice);
}

/* -----------------------------------------------------------------
 * ACTION HANDLERS
 * ----------------------------------------------------------------- */

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

    // Lock the product row. Everything below reads from this snapshot.
    $product = getProductForCart($db, $productId);
    if (!$product) {
        throw new RuntimeException('Product not found.');
    }
    if (!$product['is_active']) {
        throw new RuntimeException('Product is not available.');
    }

    $rules = getProductCompositionRules($db, $productId);

    $basePrice = (float)($product['base_price'] ?? 0);
    if ($basePrice <= 0) {
        $basePrice = (float)$product['price'];
    }

    $serverUnitPrice = computeServerUnitPrice($basePrice, $customizations, $rules);

    // Log client/server total mismatch. The server price is authoritative.
    if ($clientTotal > 0) {
        $expectedTotal = $serverUnitPrice * $quantity;
        if (abs($expectedTotal - $clientTotal) > 0.01) {
            error_log(sprintf(
                'Cart price mismatch for product %d: client=%.2f server=%.2f',
                $productId,
                $clientTotal,
                $expectedTotal
            ));
        }
    }

    $customizationPayload = buildCartCustomizationPayload($customizations);
    $customizationHash    = computeCartCustomizationHash($customizations);

    $existing    = getCartItemByProduct($db, $customerId, $productId, $customizationHash);
    $existingQty = $existing ? (int)$existing['quantity'] : 0;
    $newQuantity = $existingQty + $quantity;

    if ((int)$product['stock'] < $newQuantity) {
        throw new RuntimeException(
            'Insufficient stock. Available: ' . $product['stock'] .
            ', In cart: ' . $existingQty .
            ', Requested: ' . $newQuantity
        );
    }

    if ($existing) {
        updateCartItem(
            $db,
            (int)$existing['cart_id'],
            $newQuantity,
            $serverUnitPrice,
            $customizationPayload
        );
    } else {
        insertCartItem(
            $db,
            $customerId,
            $productId,
            $quantity,
            $serverUnitPrice,
            $customizationPayload
        );
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

    $row = getCartItemForUpdate($db, $cartId, $customerId);
    if (!$row) {
        throw new RuntimeException('Cart item not found.');
    }

    $maxStock = (int)$row['stock'];
    if ($quantity < 1)         $quantity = 1;
    if ($quantity > $maxStock) $quantity = $maxStock;

    updateCartItemQuantity($db, $cartId, $customerId, $quantity);

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

    deleteCartItem($db, $cartId, $customerId);

    cartSuccess(['message' => 'Item removed'], $isAjax, '../../pages/cart.php');
}

function handleClear(PDO $db, int $customerId, bool $isAjax): void
{
    clearCart($db, $customerId);

    cartSuccess(['message' => 'Cart cleared', 'cart_count' => 0], $isAjax, '../../pages/cart.php');
}

function handleGetCount(PDO $db, int $customerId): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'success', 'count' => getCartCount($db, $customerId)]);
    exit;
}

/**
 * Push only the SELECTED cart rows into the session order_queue.
 *
 * Expects $_POST['cart_ids'] to be an array of cart_id integers.
 * Rows that are inactive or out of stock are skipped even if selected.
 *
 * Session queue line shape
 * ------------------------
 * Every line written here carries the same fields that
 * queue-handler.php's queueEnrich() produces, because
 * createOrderFromQueue() in order-queries.php reads those fields and
 * silently drops any line that is missing them. In particular,
 * `restaurant_branch_id` is mandatory: without it,
 * createOrderFromQueue() skips the line, the whole queue can drain
 * to zero, and the customer is bounced back to checkout with
 * "Your order is empty." regardless of which payment method was
 * chosen.
 *
 * Fields written on a new line:
 *   line_key               product + customization signature
 *   product_id             int
 *   name                   string
 *   price                  float  effective unit price (server-authoritative)
 *   base_price             float  product base price
 *   quantity               int    capped at product stock
 *   image                  string project-relative or absolute path
 *   stock                  int
 *   restaurant_branch_id   int    required by createOrderFromQueue()
 *   restaurant_name        string
 *   branch_name            string
 *   customization_data     string|null  raw JSON from cart.customization_data
 *
 * Merge rule
 * ----------
 * A line merges with an existing queue line only when the
 * customization payload is identical. Two cart rows for the same
 * product with different customizations are two distinct queue
 * lines, matching the cart table's own
 * unique_cart_item (customer_id, product_id, customization_hash)
 * constraint. When a merge happens, every mutable field is
 * refreshed from the cart row so a stale price or a dropped
 * customization cannot survive the merge.
 */
function handlePushToQueue(PDO $db, int $customerId, bool $isAjax): void
{
    $rawIds = $_POST['cart_ids'] ?? [];
    if (!is_array($rawIds)) {
        $rawIds = [$rawIds];
    }
    $selectedIds = array_values(array_unique(array_filter(
        array_map('intval', $rawIds),
        fn($id) => $id > 0
    )));

    if (empty($selectedIds)) {
        throw new RuntimeException('Select at least one item to add to your order.');
    }

    $rows = getCartRowsForQueue($db, $customerId, $selectedIds);
    if (empty($rows)) {
        throw new RuntimeException('No matching cart items were found.');
    }

    if (!isset($_SESSION['order_queue']) || !is_array($_SESSION['order_queue'])) {
        $_SESSION['order_queue'] = [];
    }

    // Index existing queue lines by their full signature (product_id
    // plus the raw customization JSON the cart row carries) so a
    // merge only fires when the customization actually matches.
    // Indexing by product_id alone would collapse two cart rows for
    // the same product with different customizations into one queue
    // line, discarding the second customization set.
    $indexBySignature = [];
    foreach ($_SESSION['order_queue'] as $i => $line) {
        $signature = queueLineSignatureFromLine($line);
        if ($signature !== '') {
            $indexBySignature[$signature] = $i;
        }
    }

    $addedCount   = 0;
    $skippedCount = 0;

    foreach ($rows as $row) {
        $isActive = ((int)($row['is_active'] ?? 0) === 1);
        $stock    = (int)($row['stock'] ?? 0);

        if (!$isActive || $stock <= 0) {
            $skippedCount++;
            continue;
        }

        $productId = (int)($row['product_id'] ?? 0);
        $branchId  = (int)($row['restaurant_branch_id'] ?? 0);
        $quantity  = (int)($row['quantity'] ?? 0);

        // A cart row that somehow lost its branch reference cannot
        // become a valid queue line. Skip it rather than write a
        // line createOrderFromQueue() will silently drop.
        if ($productId <= 0 || $branchId <= 0 || $quantity <= 0) {
            $skippedCount++;
            continue;
        }

        $lineKey = 'p::' . $productId . '::' . sha1((string)($row['customization_data'] ?? ''));

        $newLine = [
            'line_key'             => $lineKey,
            'product_id'           => $productId,
            'name'                 => (string)($row['name'] ?? ''),
            'price'                => (float)($row['price'] ?? 0),
            'base_price'           => (float)($row['base_price'] ?? 0),
            'quantity'             => $quantity,
            'image'                => (string)($row['product_image'] ?? ''),
            'stock'                => $stock,
            'restaurant_branch_id' => $branchId,
            'restaurant_name'      => (string)($row['business_name'] ?? ''),
            'branch_name'          => (string)($row['branch_name'] ?? ''),
            'customization_data'   => $row['customization_data'] ?? null,
        ];

        $signature = queueLineSignatureFromLine($newLine);

        if ($signature !== '' && isset($indexBySignature[$signature])) {
            $idx = $indexBySignature[$signature];

            $currentQty = (int)($_SESSION['order_queue'][$idx]['quantity'] ?? 0);
            $mergedQty  = $currentQty + $quantity;
            if ($mergedQty > $stock) {
                $mergedQty = $stock;
            }

            // Refresh every mutable field from the cart row. The
            // queue line must reflect the current price, image, and
            // names, not whatever it carried on the first push.
            $_SESSION['order_queue'][$idx]['quantity']             = $mergedQty;
            $_SESSION['order_queue'][$idx]['price']                = $newLine['price'];
            $_SESSION['order_queue'][$idx]['base_price']           = $newLine['base_price'];
            $_SESSION['order_queue'][$idx]['stock']                = $stock;
            $_SESSION['order_queue'][$idx]['image']                = $newLine['image'];
            $_SESSION['order_queue'][$idx]['name']                 = $newLine['name'];
            $_SESSION['order_queue'][$idx]['restaurant_branch_id'] = $branchId;
            $_SESSION['order_queue'][$idx]['restaurant_name']      = $newLine['restaurant_name'];
            $_SESSION['order_queue'][$idx]['branch_name']          = $newLine['branch_name'];
            $_SESSION['order_queue'][$idx]['customization_data']   = $newLine['customization_data'];
        } else {
            $_SESSION['order_queue'][] = $newLine;
            $indexBySignature[$signature] = count($_SESSION['order_queue']) - 1;
        }

        $addedCount++;
    }

    if ($addedCount === 0) {
        throw new RuntimeException('None of the selected items are currently available.');
    }

    cartSuccess(
        [
            'message'  => 'Added to order',
            'added'    => $addedCount,
            'skipped'  => $skippedCount,
            'redirect' => 'menu.php',
        ],
        $isAjax,
        '../../pages/menu.php'
    );
}

/**
 * Build the merge signature for a queue line.
 *
 * The signature is the product id joined with the SHA-1 of the raw
 * customization JSON. Two lines with the same product but different
 * customization payloads therefore have different signatures and
 * never merge. Two lines with the same product and the same
 * customization payload always merge.
 *
 * The `line_key` field, when present on the line, is preferred —
 * it is the same value computed by handlePushToQueue() and by
 * queue-handler.php's add action.
 *
 * Returns '' when the line has no usable product id, which makes
 * the caller treat the line as unindexable rather than merging it
 * into an unrelated bucket.
 *
 * @param array<string, mixed> $line
 * @return string
 */
function queueLineSignatureFromLine(array $line): string
{
    if (isset($line['line_key']) && is_string($line['line_key']) && $line['line_key'] !== '') {
        return $line['line_key'];
    }

    $productId = (int)($line['product_id'] ?? 0);
    if ($productId <= 0) {
        return '';
    }

    $customRaw = $line['customization_data'] ?? null;
    $customStr = is_string($customRaw)
        ? $customRaw
        : (is_array($customRaw) ? json_encode($customRaw) : '');

    return 'p::' . $productId . '::' . sha1((string)$customStr);
}