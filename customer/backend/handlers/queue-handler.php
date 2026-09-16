<?php
/**
 * FitPal Customer Queue Handler
 *
 * Session-based order queue. The queue lives in
 * $_SESSION['order_queue']; this handler reads/writes it and uses
 * the DB only to enrich items or commit into the persistent cart.
 *
 * @package FitPal
 * @version 3.1 — Commit no longer clears the session queue, so the
 *                menu page's queue panel survives a trip to checkout.
 *                The queue is cleared only by `clear` or by placing
 *                the order (place-order-handler.php).
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$raw    = file_get_contents('php://input');
$json   = ($raw !== '' && $raw !== false) ? json_decode($raw, true) : null;
$isJson = is_array($json);
$input  = $isJson ? $json : $_POST;

$isAjax = $isJson
    || (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    } else {
        header('Location: ../../pages/sign-in.php');
    }
    exit;
}

$customerId = (int)$_SESSION['customer_id'];

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/cart-queries.php';
require_once __DIR__ . '/../database/queue-queries.php';

$action = (string)($input['action'] ?? '');
if ($action === '' && ($input['queue_action'] ?? '') === 'queue') {
    $action = 'add';
}

$requiresCsrf = !in_array($action, ['get', ''], true);
if ($requiresCsrf) {
    $given = (string)($input['csrf_token'] ?? '');
    $sess  = (string)($_SESSION['csrf_token'] ?? '');
    if ($sess === '' || $given === '' || !hash_equals($sess, $given)) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
        } else {
            $_SESSION['queue_error'] = 'Security validation failed. Please try again.';
            header('Location: ../../pages/menu.php');
        }
        exit;
    }
}

// ---------------------------------------------------------------
// Session queue helpers
// ---------------------------------------------------------------

function queueGet(): array
{
    $q = $_SESSION['order_queue'] ?? [];
    return is_array($q) ? $q : [];
}

function queuePut(array $queue): void
{
    $_SESSION['order_queue'] = array_values($queue);
}

/**
 * Normalize incoming customizations to a JSON string (or null).
 * Also returns the decoded array for immediate use.
 *
 * @return array{0: ?string, 1: array<int, array<string, mixed>>}
 */
function queueNormalizeCustomizations(mixed $raw): array
{
    if ($raw === null || $raw === '' || $raw === []) {
        return [null, []];
    }

    $decoded = null;
    if (is_array($raw)) {
        $decoded = $raw;
    } elseif (is_string($raw)) {
        $attempt = json_decode($raw, true);
        if (is_array($attempt)) {
            $decoded = $attempt;
        }
    }

    if (!is_array($decoded) || empty($decoded)) {
        return [null, []];
    }

    return [json_encode($decoded), $decoded];
}

/**
 * Build a stable identity hash for a queue line. Two lines with the
 * same product but different customizations must NOT merge.
 */
function queueLineKey(int $productId, ?string $customizationJson): string
{
    return $productId . '::' . sha1((string)$customizationJson);
}

/**
 * Enrich a queued item with live product data from the database.
 *
 * Effective price is computed as:
 *     product.base_price
 *   + Σ(composition.price_modifier × requested_quantity)
 * for every composition row where the client indicated the ingredient
 * is present. The client sends {ingredient_id, quantity, selected_option}
 * and we ignore its price_modifier entirely — the server is the single
 * source of truth for money.
 */
function queueEnrich(PDO $db, array $item): ?array
{
    $productId = (int)($item['product_id'] ?? 0);
    $quantity  = (int)($item['quantity'] ?? 0);
    if ($productId <= 0 || $quantity <= 0) {
        return null;
    }

    $p = getProductForQueue($db, $productId);
    if (!$p) {
        return null;
    }

    $maxStock = (int)$p['stock'];
    if ($quantity > $maxStock) $quantity = $maxStock;
    if ($quantity <= 0) return null;

    $customizations = [];
    if (!empty($item['customization_data'])) {
        $decoded = is_string($item['customization_data'])
            ? json_decode($item['customization_data'], true)
            : $item['customization_data'];
        if (is_array($decoded)) {
            $customizations = $decoded;
        }
    }

    $rules = [];
    $ruleStmt = $db->prepare(
        "SELECT ingredient_id, price_modifier, min_quantity, max_quantity,
                is_required, is_default, default_quantity
           FROM product_composition
          WHERE product_id = :product_id"
    );
    $ruleStmt->execute([':product_id' => $productId]);
    while ($r = $ruleStmt->fetch(PDO::FETCH_ASSOC)) {
        $rules[(int)$r['ingredient_id']] = $r;
    }

    $basePrice = (float)($p['base_price'] ?? 0);
    if ($basePrice <= 0) {
        $basePrice = (float)$p['price'];
    }

    $unitPrice     = $basePrice;
    $caloriesDelta = 0;

    foreach ($customizations as $cust) {
        if (!is_array($cust)) continue;
        if (($cust['type'] ?? '') === 'notes') continue;

        $ingredientId = (int)($cust['ingredient_id'] ?? 0);
        if ($ingredientId <= 0) continue;
        if (!isset($rules[$ingredientId])) continue;

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

    if ($unitPrice < 0) {
        $unitPrice = 0.0;
    }

    return [
        'product_id'           => (int)$p['product_id'],
        'name'                 => (string)$p['name'],
        'price'                => round($unitPrice, 2),
        'base_price'           => $basePrice,
        'quantity'             => $quantity,
        'image'                => (string)$p['product_image'],
        'stock'                => $maxStock,
        'restaurant_name'      => (string)$p['restaurant_name'],
        'branch_name'          => (string)$p['branch_name'],
        'restaurant_branch_id' => (int)$p['restaurant_branch_id'],
        'is_customizable'      => (bool)$p['is_customizable'],
        'customization_data'   => $item['customization_data'] ?? null,
    ];
}

function queueRespond(array $payload, bool $isAjax, string $redirect = '../../pages/menu.php'): never
{
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    }
    if (($payload['status'] ?? '') === 'success') {
        if (!empty($payload['message'])) {
            $_SESSION['queue_success'] = $payload['message'];
        }
    } else {
        $_SESSION['queue_error'] = $payload['message'] ?? 'Something went wrong.';
    }
    header('Location: ' . $redirect);
    exit;
}

// ---------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------
try {
    switch ($action) {

        case 'get': {
            $q = queueGet();
            queueRespond(['status' => 'success', 'queue' => $q, 'count' => count($q)], true);
        }

        case 'add': {
            $productId  = (int)($input['product_id'] ?? 0);
            $quantity   = max(1, (int)($input['quantity'] ?? 1));
            [$customJson, $parsedCustomizations] = queueNormalizeCustomizations(
                $input['customizations'] ?? null
            );

            if ($productId <= 0) {
                queueRespond(['status' => 'error', 'message' => 'Invalid product selected.'], $isAjax);
            }

            $enriched = queueEnrich($database_connection, [
                'product_id'         => $productId,
                'quantity'           => $quantity,
                'customization_data' => $customJson,
            ]);

            if ($enriched === null) {
                queueRespond(['status' => 'error', 'message' => 'That product is not available.'], $isAjax);
            }

            $enriched['customizations'] = $parsedCustomizations;
            $enriched['line_key']       = queueLineKey($productId, $customJson);

            $queue = queueGet();
            $found = false;

            foreach ($queue as &$row) {
                $rowKey = $row['line_key']
                    ?? queueLineKey(
                        (int)$row['product_id'],
                        is_string($row['customization_data'] ?? null)
                            ? $row['customization_data']
                            : (isset($row['customization_data'])
                                ? json_encode($row['customization_data'])
                                : null)
                    );

                if ($rowKey === $enriched['line_key']) {
                    $row['quantity'] = min(
                        (int)$row['quantity'] + $quantity,
                        (int)$enriched['stock']
                    );
                    $rebuilt = queueEnrich($database_connection, [
                        'product_id'         => $productId,
                        'quantity'           => (int)$row['quantity'],
                        'customization_data' => $customJson,
                    ]);
                    if ($rebuilt !== null) {
                        $rebuilt['customizations'] = $parsedCustomizations;
                        $rebuilt['line_key']       = $enriched['line_key'];
                        $row = $rebuilt;
                    }
                    $found = true;
                    break;
                }
            }
            unset($row);

            if (!$found) {
                $queue[] = $enriched;
            }

            queuePut($queue);

            queueRespond([
                'status'  => 'success',
                'queue'   => $queue,
                'count'   => count($queue),
                'message' => $enriched['name'] . ' added to order',
            ], $isAjax);
        }

        case 'update': {
            $lineKey  = (string)($input['line_key'] ?? '');
            $index    = isset($input['index']) ? (int)$input['index'] : -1;
            $quantity = (int)($input['quantity'] ?? 0);

            $queue = queueGet();
            $targetIndex = -1;

            if ($lineKey !== '') {
                foreach ($queue as $i => $row) {
                    $rowKey = $row['line_key']
                        ?? queueLineKey(
                            (int)$row['product_id'],
                            is_string($row['customization_data'] ?? null)
                                ? $row['customization_data']
                                : (isset($row['customization_data'])
                                    ? json_encode($row['customization_data'])
                                    : null)
                        );
                    if ($rowKey === $lineKey) {
                        $targetIndex = $i;
                        break;
                    }
                }
            } elseif ($index >= 0 && $index < count($queue)) {
                $targetIndex = $index;
            }

            if ($targetIndex >= 0) {
                if ($quantity <= 0) {
                    array_splice($queue, $targetIndex, 1);
                } else {
                    $max = (int)($queue[$targetIndex]['stock'] ?? 999);
                    $queue[$targetIndex]['quantity'] = min($quantity, $max);
                }
            }

            queuePut($queue);
            queueRespond(['status' => 'success', 'queue' => $queue, 'count' => count($queue)], $isAjax);
        }

        case 'remove': {
            $lineKey = (string)($input['line_key'] ?? '');
            $index   = isset($input['index']) ? (int)$input['index'] : -1;

            $queue = queueGet();
            $targetIndex = -1;

            if ($lineKey !== '') {
                foreach ($queue as $i => $row) {
                    $rowKey = $row['line_key']
                        ?? queueLineKey(
                            (int)$row['product_id'],
                            is_string($row['customization_data'] ?? null)
                                ? $row['customization_data']
                                : (isset($row['customization_data'])
                                    ? json_encode($row['customization_data'])
                                    : null)
                        );
                    if ($rowKey === $lineKey) {
                        $targetIndex = $i;
                        break;
                    }
                }
            } elseif ($index >= 0 && $index < count($queue)) {
                $targetIndex = $index;
            }

            if ($targetIndex >= 0) {
                array_splice($queue, $targetIndex, 1);
            }

            queuePut($queue);
            queueRespond(['status' => 'success', 'queue' => $queue, 'count' => count($queue)], $isAjax);
        }

        case 'clear': {
            queuePut([]);
            queueRespond(['status' => 'success', 'queue' => [], 'count' => 0], $isAjax);
        }

        case 'sync': {
            $incoming = $input['queue'] ?? [];
            if (!is_array($incoming)) $incoming = [];

            $new = [];
            foreach ($incoming as $item) {
                if (!is_array($item)) continue;

                $productId = (int)($item['product_id'] ?? 0);
                $quantity  = max(1, (int)($item['quantity'] ?? 1));
                [$customJson] = queueNormalizeCustomizations(
                    $item['customization_data'] ?? null
                );

                $enriched = queueEnrich($database_connection, [
                    'product_id'         => $productId,
                    'quantity'           => $quantity,
                    'customization_data' => $customJson,
                ]);

                if ($enriched !== null) {
                    $new[] = $enriched;
                }
            }
            queuePut($new);
            queueRespond(['status' => 'success', 'queue' => $new, 'count' => count($new)], $isAjax);
        }

        case 'commit': {
            $queue = queueGet();
            if (empty($queue)) {
                queueRespond(['status' => 'error', 'message' => 'Your order is empty.'], $isAjax);
            }

            $database_connection->beginTransaction();

            try {
                // The session queue is the authoritative list of what the
                // user wants to check out right now. Replace the cart
                // wholesale so repeat commits are idempotent: the cart
                // always mirrors the queue exactly as of the last commit.
                clearCart($database_connection, $customerId);

                foreach ($queue as $item) {
                    $productId = (int)$item['product_id'];
                    $qty       = (int)$item['quantity'];
                    $unitPrice = (float)($item['price'] ?? 0);

                    insertCartItem(
                        $database_connection,
                        $customerId,
                        $productId,
                        $qty,
                        $unitPrice,
                        is_string($item['customization_data'] ?? null)
                            ? $item['customization_data']
                            : (isset($item['customization_data'])
                                ? json_encode($item['customization_data'])
                                : null)
                    );
                }

                $database_connection->commit();

                // IMPORTANT: do NOT queuePut([]) here. The session queue
                // stays populated so the menu page's queue panel remains
                // visible if the user navigates back from checkout. The
                // queue is cleared only by `clear` (Cancel Order) or by
                // place-order-handler.php (successful order placement).

                queueRespond([
                    'status'  => 'success',
                    'message' => 'Order ready for checkout',
                    'queue'   => $queue,
                    'count'   => count($queue),
                ], $isAjax);

            } catch (Throwable $e) {
                if ($database_connection->inTransaction()) {
                    $database_connection->rollBack();
                }
                throw $e;
            }
        }

        default:
            queueRespond(['status' => 'error', 'message' => 'Invalid action: ' . $action], $isAjax);
    }

} catch (PDOException $e) {
    if ($database_connection->inTransaction()) $database_connection->rollBack();
    error_log('Queue handler DB error: ' . $e->getMessage());
    queueRespond(['status' => 'error', 'message' => 'A system error occurred. Please try again.'], $isAjax);
} catch (Throwable $e) {
    if ($database_connection->inTransaction()) $database_connection->rollBack();
    error_log('Queue handler error: ' . $e->getMessage());
    queueRespond(['status' => 'error', 'message' => 'A system error occurred. Please try again.'], $isAjax);
}