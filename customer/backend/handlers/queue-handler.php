<?php
/**
 * FitPal Customer Queue Handler
 *
 * The session-based order queue is the ONLY staging system for
 * customer orders. Runs on the customer session
 * (PHPSESSID_CUSTOMER), separate from every other role's session.
 *
 * The queue lives in $_SESSION['order_queue'] and is mirrored into
 * the `orders` + `queue_item` + `customization_instance` tables at
 * the moment of order placement by place-order-handler.php.
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
 * ---------------------------------------------------------------------
 * QUEUE LINE SHAPE (v8.0.0)
 * ---------------------------------------------------------------------
 * Every line this handler writes is produced by queueEnrich(),
 * which attaches both:
 *
 *   customization_data   raw JSON, read by createOrderFromQueue()
 *   customizations       enriched display array, read by the queue
 *                        panel to render its "Customized" dropdown
 *
 * Before v8.0.0, the add action overwrote the enriched array with
 * the raw client array after calling queueEnrich(). The raw array
 * has no ingredient_name, so the queue panel's render dropped every
 * entry. Removing that override is the fix: queueEnrich()'s
 * enriched array is what the panel needs.
 *
 * ---------------------------------------------------------------------
 * PER-ROLE SESSION MODEL
 * ---------------------------------------------------------------------
 * The handler bootstraps the customer session before doing
 * anything else. The auth guard reads $_SESSION['customer_id'], the
 * CSRF check reads $_SESSION['customer_csrf_token'], and every
 * queue read and write touches $_SESSION['order_queue'] — all
 * inside the customer session, guaranteed to be the customer's own.
 *
 * @package FitPal
 * @version 8.0.0 — The add action no longer overwrites the enriched
 *                  `customizations` array with the raw client
 *                  array. queueEnrich() produces the enriched array
 *                  itself, and the queue panel reads exactly what
 *                  queueEnrich() returned.
 *
 *                  (7.0.0: queue shape contract. 6.0.0: per-role
 *                  session migration.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('customer');

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
    $sess  = (string)($_SESSION['customer_csrf_token'] ?? '');

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

/* -----------------------------------------------------------------
 * SESSION QUEUE HELPERS (request-layer concerns — stay here)
 * ----------------------------------------------------------------- */

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

function queueLineKey(int $productId, ?string $customizationJson): string
{
    return $productId . '::' . sha1((string)$customizationJson);
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

try {
    switch ($action) {

        case 'get': {
            $q = queueGet();
            queueRespond(['status' => 'success', 'queue' => $q, 'count' => count($q)], true);
        }

        case 'add': {
            $productId  = (int)($input['product_id'] ?? 0);
            $quantity   = max(1, (int)($input['quantity'] ?? 1));
            [$customJson] = queueNormalizeCustomizations(
                $input['customizations'] ?? null
            );

            if ($productId <= 0) {
                queueRespond(['status' => 'error', 'message' => 'Invalid product selected.'], $isAjax);
            }

            // queueEnrich() now produces BOTH the raw
            // customization_data and the enriched customizations
            // array the panel reads. No caller attaches its own
            // array.
            $enriched = queueEnrich($database_connection, [
                'product_id'         => $productId,
                'quantity'           => $quantity,
                'customization_data' => $customJson,
            ]);

            if ($enriched === null) {
                queueRespond(['status' => 'error', 'message' => 'That product is not available.'], $isAjax);
            }

            $enriched['line_key'] = queueLineKey($productId, $customJson);

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
                    $mergedQty = min(
                        (int)$row['quantity'] + $quantity,
                        (int)$enriched['stock']
                    );

                    $rebuilt = queueEnrich($database_connection, [
                        'product_id'         => $productId,
                        'quantity'           => $mergedQty,
                        'customization_data' => $customJson,
                    ]);

                    if ($rebuilt !== null) {
                        $rebuilt['line_key'] = $enriched['line_key'];
                        $row = $rebuilt;
                    } else {
                        $row['quantity'] = $mergedQty;
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

                // Re-enrich from the database. queueEnrich() produces
                // the enriched `customizations` array as well as the
                // raw customization_data, so the panel keeps its
                // dropdown across syncs.
                $enriched = queueEnrich($database_connection, [
                    'product_id'         => $productId,
                    'quantity'           => $quantity,
                    'customization_data' => $customJson,
                ]);

                if ($enriched !== null) {
                    // Preserve the client's line_key when one was
                    // supplied. Sync is a re-enrich, not a fresh add;
                    // the identity of the line does not change.
                    if (isset($item['line_key']) && is_string($item['line_key']) && $item['line_key'] !== '') {
                        $enriched['line_key'] = $item['line_key'];
                    } else {
                        $enriched['line_key'] = queueLineKey($productId, $customJson);
                    }

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