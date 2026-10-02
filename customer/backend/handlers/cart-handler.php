<?php
/**
 * FitPal Customer Cart Handler
 *
 * Persistent cart (cart table). Runs on the customer session
 * (PHPSESSID_CUSTOMER), which is separate from every other role's
 * session.
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
 * ---------------------------------------------------------------------
 * QUEUE SHAPE (v9.1.0)
 * ---------------------------------------------------------------------
 * push_to_queue writes session queue lines. Every writer of
 * $_SESSION['order_queue'] must produce a line with the same shape,
 * or the queue panel renders inconsistently. The shape is documented
 * in customer/backend/database/queue-queries.php and produced by
 * queueEnrich().
 *
 * Before v9.1.0, this handler built the queue line by hand. That
 * line carried `customization_data` (raw JSON) but no
 * `customizations` (the enriched display array the queue panel
 * reads), so a cart-pushed line never rendered its "Customized"
 * dropdown even when the cart row was customized.
 *
 * This revision routes each cart row through queueEnrich(), which
 * produces the enriched display array alongside the raw JSON. The
 * cart-pushed line now has the same shape as a line added through
 * the menu.
 *
 * ---------------------------------------------------------------------
 * PER-ROLE SESSION MODEL
 * ---------------------------------------------------------------------
 * The handler bootstraps the customer session before doing anything
 * else. The auth guard reads $_SESSION['customer_id'] and the CSRF
 * check reads $_SESSION['customer_csrf_token'], both inside the
 * customer session and guaranteed to be the customer's own.
 *
 * @package FitPal
 * @version 9.1.0 — push_to_queue routes each cart row through
 *                  queueEnrich() so the queue line carries the same
 *                  enriched `customizations` array every other
 *                  writer produces. The queue panel now renders the
 *                  "Customized" dropdown on cart-pushed lines.
 *
 *                  (9.0.0: push_to_queue resolves the product image
 *                  into a browser-loadable URL. 8.0.0: queue shape
 *                  contract. 7.0.0: per-role session migration.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('customer');

$isAjax = (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
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

$givenToken = (string)($_POST['csrf_token'] ?? '');
$sessToken  = (string)($_SESSION['customer_csrf_token'] ?? '');

if (
    $sessToken === ''
    || $givenToken === ''
    || !hash_equals($sessToken, $givenToken)
) {
    cartFail('Security validation failed. Please try again.', $isAjax);
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/cart-queries.php';

// queue-queries.php declares queueEnrich() and the image-resolution
// helpers. It is safe to include: it only declares functions, it
// does not dispatch a request.
require_once __DIR__ . '/../database/queue-queries.php';

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
 * Each cart row is routed through queueEnrich(), which:
 *   - validates the product is still active and in stock
 *   - computes a fresh effective unit price from the current
 *     composition rules (the cart's stored price is not trusted as
 *     money)
 *   - resolves the product image into a browser-loadable URL
 *   - builds the enriched `customizations` array the queue panel
 *     reads
 *   - preserves the raw customization_data JSON for
 *     createOrderFromQueue()
 *
 * The returned line already carries every field a session queue
 * line needs. Only `line_key` is added here.
 *
 * Merge rule
 * ----------
 * A line merges with an existing queue line only when the
 * customization payload is identical. Two cart rows for the same
 * product with different customizations are two distinct queue
 * lines, matching the cart table's own
 * unique_cart_item (customer_id, product_id, customization_hash)
 * constraint. When a merge happens, every mutable field is
 * refreshed from the fresh enriched line so a stale price or a
 * dropped customization cannot survive the merge.
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

    // Index existing queue lines by their signature so a merge only
    // fires when the customization payload actually matches.
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
        $productId = (int)($row['product_id'] ?? 0);
        $quantity  = (int)($row['quantity'] ?? 0);

        if ($productId <= 0 || $quantity <= 0) {
            $skippedCount++;
            continue;
        }

        // Route the cart row through queueEnrich(). The enrich call
        // validates active/in-stock, computes the effective price,
        // resolves the image URL, and builds the enriched
        // customizations array. It returns null when the product is
        // no longer available.
        $enriched = queueEnrich($db, [
            'product_id'         => $productId,
            'quantity'           => $quantity,
            'customization_data' => $row['customization_data'] ?? null,
        ]);

        if ($enriched === null) {
            $skippedCount++;
            continue;
        }

        // Line key matches the signature queue-handler.php's add
        // action computes for the same product + customization pair,
        // so a cart-pushed line merges with a line already added
        // through the menu instead of sitting alongside it.
        $rawCustomData = (string)($row['customization_data'] ?? '');
        $lineKey       = 'p::' . $productId . '::' . sha1($rawCustomData);

        $enriched['line_key'] = $lineKey;

        $signature = queueLineSignatureFromLine($enriched);

        if ($signature !== '' && isset($indexBySignature[$signature])) {
            $idx = $indexBySignature[$signature];

            $currentQty = (int)($_SESSION['order_queue'][$idx]['quantity'] ?? 0);
            $mergedQty  = $currentQty + $quantity;
            $stock      = (int)($enriched['stock'] ?? 999);
            if ($mergedQty > $stock) {
                $mergedQty = $stock;
            }

            // Refresh every mutable field from the fresh enriched
            // line. The queue line must reflect the current price,
            // image, names, and customizations, not whatever it
            // carried on the first push.
            $_SESSION['order_queue'][$idx] = array_merge(
                $_SESSION['order_queue'][$idx],
                $enriched,
                ['quantity' => $mergedQty]
            );
        } else {
            $_SESSION['order_queue'][] = $enriched;
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