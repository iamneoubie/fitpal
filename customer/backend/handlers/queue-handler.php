<?php
/**
 * FitPal Customer Queue Handler
 *
 * The session-based order queue is the ONLY staging system for
 * customer orders. There is no persistent cart. The queue lives in
 * $_SESSION['order_queue'] and is mirrored into the `orders` +
 * `queue_item` + `customization_instance` tables at the moment of
 * order placement by place-order-handler.php.
 *
 * Actions:
 *   get    → return current queue
 *   add    → enrich product via DB, merge or append to queue
 *   update → change qty on a queue line (or remove when qty ≤ 0)
 *   remove → delete a queue line
 *   clear  → wipe the entire queue
 *   sync   → re-enrich every line (used after edits)
 *
 * The queue is cleared only by:
 *   - the `clear` action (user cancels the order from the panel)
 *   - place-order-handler.php after a successful order creation
 *
 * This handler contains NO SQL. All data access goes through
 * customer/backend/database/queue-queries.php.
 *
 * This file is NOT safe to require from a page — it runs a full
 * request dispatch at load time. Pure helpers that pages need
 * (queueEnrich, getProductForQueue) live in queue-queries.php.
 *
 * @package FitPal
 * @version 5.0 — queueEnrich moved to queue-queries.php; handler is
 *                now SQL-free.
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
// Session queue helpers (request-layer concerns — stay here)
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
 * Terminate the request with a response payload.
 *
 * AJAX callers get JSON. Non-AJAX callers get a session flash plus a
 * redirect back to the menu.
 */
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

        default:
            queueRespond(['status' => 'error', 'message' => 'Invalid action: ' . $action], $isAjax);
    }

} catch (PDOException $e) {
    error_log('Queue handler DB error: ' . $e->getMessage());
    queueRespond(['status' => 'error', 'message' => 'A system error occurred. Please try again.'], $isAjax);
} catch (Throwable $e) {
    error_log('Queue handler error: ' . $e->getMessage());
    queueRespond(['status' => 'error', 'message' => 'A system error occurred. Please try again.'], $isAjax);
}