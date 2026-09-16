<?php
/**
 * FitPal Customer Queue Handler
 *
 * Session-based order queue. This is the staging area on menu.php,
 * NOT the database cart.
 *
 * Actions:
 *   get     → return the current queue
 *   add     → add or merge one item into the queue
 *   update  → set quantity of one item
 *   remove  → drop one item
 *   clear   → empty the queue
 *   sync    → replace the queue with the client's version
 *   commit  → copy the queue into the DB cart, then clear it
 *
 * Accepts JSON bodies (AJAX) and form POST (redirect).
 *
 * @package FitPal
 * @version 1.1
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------------------------------------------------------------
// INPUT
//
// php://input is only readable for JSON and urlencoded bodies.
// For multipart/form-data (FormData + fetch), php://input is
// EMPTY. So we read it defensively and fall back to $_POST.
// ---------------------------------------------------------------
$raw    = file_get_contents('php://input');
$json   = ($raw !== '' && $raw !== false) ? json_decode($raw, true) : null;
$isJson = is_array($json);
$input  = $isJson ? $json : $_POST;

// A request is "AJAX" if:
//   - it sent a JSON body, OR
//   - it explicitly set X-Requested-With: XMLHttpRequest
//
// We intentionally do NOT treat form POSTs as AJAX, because
// product-detail.php's "Add to Order" button posts via a real
// form navigation and expects a 302 redirect back to menu.php.
$isAjax = $isJson
    || (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

// ---------------------------------------------------------------
// AUTH
// ---------------------------------------------------------------
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

// ---------------------------------------------------------------
// ACTION RESOLUTION
//
// Resolve the action BEFORE the CSRF check, so we can decide
// whether CSRF is even required.
//
// `get` is read-only and returns only the caller's own queue,
// which is already gated by the session auth above. It does not
// need a CSRF token, and demanding one is what was breaking the
// initial panel load on menu.php.
//
// Every mutating action (add/update/remove/clear/sync/commit)
// requires a valid CSRF token.
// ---------------------------------------------------------------
$action = (string)($input['action'] ?? '');

// Backwards-compat: product-detail.php's "Add to Order" form
// sends queue_action=queue instead of action=add.
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
// HELPERS
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
 * Normalize a `customizations` payload into a JSON string or null.
 *
 * Accepts:
 *   - already-decoded array (from JSON bodies)
 *   - JSON string (from FormData)
 *   - empty / missing (returns null)
 */
function queueNormalizeCustomizations(mixed $raw): ?string
{
    if ($raw === null || $raw === '' || $raw === []) {
        return null;
    }

    if (is_array($raw)) {
        return empty($raw) ? null : json_encode($raw);
    }

    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && !empty($decoded)) {
            return json_encode($decoded);
        }
        return null;
    }

    return null;
}

function queueEnrich(PDO $db, array $item): ?array
{
    $productId = (int)($item['product_id'] ?? 0);
    $quantity  = (int)($item['quantity'] ?? 0);
    if ($productId <= 0 || $quantity <= 0) {
        return null;
    }

    $stmt = $db->prepare(
        "SELECT
            p.product_id,
            p.name,
            p.price,
            p.stock,
            p.is_active,
            p.is_customizable,
            p.restaurant_branch_id,
            rb.branch_name,
            r.business_name AS restaurant_name,
            COALESCE(di.images, '') AS product_image
         FROM product p
         JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
         JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
         LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
         WHERE p.product_id = :product_id AND p.is_active = 1"
    );
    $stmt->execute([':product_id' => $productId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) {
        return null;
    }

    $maxStock = (int)$p['stock'];
    if ($quantity > $maxStock) {
        $quantity = $maxStock;
    }
    if ($quantity <= 0) {
        return null;
    }

    return [
        'product_id'           => (int)$p['product_id'],
        'name'                 => (string)$p['name'],
        'price'                => (float)$p['price'],
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
// DISPATCH
// ---------------------------------------------------------------
try {
    switch ($action) {

        // -------------------------------------------------------
        // get
        // -------------------------------------------------------
        case 'get': {
            $q = queueGet();
            queueRespond([
                'status' => 'success',
                'queue'  => $q,
                'count'  => count($q),
            ], true);
        }

        // -------------------------------------------------------
        // add
        // -------------------------------------------------------
        case 'add': {
            $productId  = (int)($input['product_id'] ?? 0);
            $quantity   = max(1, (int)($input['quantity'] ?? 1));
            $customJson = queueNormalizeCustomizations($input['customizations'] ?? null);

            if ($productId <= 0) {
                queueRespond([
                    'status'  => 'error',
                    'message' => 'Invalid product selected.',
                ], $isAjax);
            }

            $enriched = queueEnrich($database_connection, [
                'product_id'         => $productId,
                'quantity'           => $quantity,
                'customization_data' => $customJson,
            ]);

            if ($enriched === null) {
                queueRespond([
                    'status'  => 'error',
                    'message' => 'That product is not available.',
                ], $isAjax);
            }

            $queue = queueGet();
            $found = false;
            foreach ($queue as &$row) {
                if ((int)$row['product_id'] === $productId) {
                    $row['quantity'] = min(
                        (int)$row['quantity'] + $quantity,
                        (int)$enriched['stock']
                    );
                    if ($customJson !== null) {
                        $row['customization_data'] = $customJson;
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

        // -------------------------------------------------------
        // update
        // -------------------------------------------------------
        case 'update': {
            $productId = (int)($input['product_id'] ?? 0);
            $quantity  = (int)($input['quantity'] ?? 0);

            $queue = queueGet();
            foreach ($queue as $i => $row) {
                if ((int)$row['product_id'] === $productId) {
                    if ($quantity <= 0) {
                        array_splice($queue, $i, 1);
                    } else {
                        $max = (int)($row['stock'] ?? 999);
                        $queue[$i]['quantity'] = min($quantity, $max);
                    }
                    break;
                }
            }
            queuePut($queue);

            queueRespond([
                'status' => 'success',
                'queue'  => $queue,
                'count'  => count($queue),
            ], $isAjax);
        }

        // -------------------------------------------------------
        // remove
        // -------------------------------------------------------
        case 'remove': {
            $productId = (int)($input['product_id'] ?? 0);

            $queue = queueGet();
            foreach ($queue as $i => $row) {
                if ((int)$row['product_id'] === $productId) {
                    array_splice($queue, $i, 1);
                    break;
                }
            }
            queuePut($queue);

            queueRespond([
                'status' => 'success',
                'queue'  => $queue,
                'count'  => count($queue),
            ], $isAjax);
        }

        // -------------------------------------------------------
        // clear
        // -------------------------------------------------------
        case 'clear': {
            queuePut([]);
            queueRespond([
                'status' => 'success',
                'queue'  => [],
                'count'  => 0,
            ], $isAjax);
        }

        // -------------------------------------------------------
        // sync
        // -------------------------------------------------------
        case 'sync': {
            $incoming = $input['queue'] ?? [];
            if (!is_array($incoming)) {
                $incoming = [];
            }

            $new = [];
            foreach ($incoming as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $enriched = queueEnrich($database_connection, $item);
                if ($enriched !== null) {
                    $new[] = $enriched;
                }
            }
            queuePut($new);

            queueRespond([
                'status' => 'success',
                'queue'  => $new,
                'count'  => count($new),
            ], $isAjax);
        }

        // -------------------------------------------------------
        // commit
        // -------------------------------------------------------
        case 'commit': {
            $queue = queueGet();
            if (empty($queue)) {
                queueRespond([
                    'status'  => 'error',
                    'message' => 'Your order is empty.',
                ], $isAjax);
            }

            $database_connection->beginTransaction();

            try {
                $lockStmt = $database_connection->prepare(
                    "SELECT cart_id, product_id, quantity
                     FROM cart
                     WHERE customer_id = :cid
                     FOR UPDATE"
                );
                $lockStmt->execute([':cid' => $customerId]);

                $existing = [];
                while ($row = $lockStmt->fetch(PDO::FETCH_ASSOC)) {
                    $existing[(int)$row['product_id']] = $row;
                }

                $updateStmt = $database_connection->prepare(
                    "UPDATE cart SET quantity = :qty WHERE cart_id = :cid"
                );
                $insertStmt = $database_connection->prepare(
                    "INSERT INTO cart
                        (customer_id, product_id, quantity, price, added_at, customization_data)
                     VALUES
                        (:cust, :prod, :qty, :price, NOW(), :custom)"
                );

                foreach ($queue as $item) {
                    $productId = (int)$item['product_id'];
                    $qty       = (int)$item['quantity'];

                    if (isset($existing[$productId])) {
                        $merged = (int)$existing[$productId]['quantity'] + $qty;
                        $updateStmt->execute([
                            ':qty' => $merged,
                            ':cid' => $existing[$productId]['cart_id'],
                        ]);
                    } else {
                        $insertStmt->execute([
                            ':cust'   => $customerId,
                            ':prod'   => $productId,
                            ':qty'    => $qty,
                            ':price'  => (float)$item['price'],
                            ':custom' => $item['customization_data'] ?? null,
                        ]);
                    }
                }

                $database_connection->commit();
                queuePut([]);

                queueRespond([
                    'status'  => 'success',
                    'message' => 'Order ready for checkout',
                ], $isAjax);
            } catch (Throwable $e) {
                if ($database_connection->inTransaction()) {
                    $database_connection->rollBack();
                }
                throw $e;
            }
        }

        default:
            queueRespond([
                'status'  => 'error',
                'message' => 'Invalid action: ' . $action,
            ], $isAjax);
    }
} catch (PDOException $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Queue handler DB error: ' . $e->getMessage());
    queueRespond([
        'status'  => 'error',
        'message' => 'A system error occurred. Please try again.',
    ], $isAjax);
} catch (Throwable $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Queue handler error: ' . $e->getMessage());
    queueRespond([
        'status'  => 'error',
        'message' => 'A system error occurred. Please try again.',
    ], $isAjax);
}