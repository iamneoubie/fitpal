<?php
/**
 * FitPal Restaurant Branch Lookup Handler
 *
 * Read-only JSON endpoint used by the branch sign-in comboboxes.
 *
 *   GET ?action=restaurants&q=<partial>
 *     → { status, restaurants: [{restaurant_id, business_name, cuisine_type, city}] }
 *
 *   GET ?action=branches&restaurant_id=<id>
 *     → { status, branches: [{branch_code, branch_name, city}] }
 *
 * No CSRF is required because the endpoint is read-only and only
 * exposes public-facing names of verified, active restaurants.
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/restaurant-queries.php';

$action = (string)($_GET['action'] ?? '');

try {
    if ($action === 'restaurants') {
        $q    = trim((string)($_GET['q'] ?? ''));
        $rows = searchRestaurantsByName($database_connection, $q, 8);

        $restaurants = array_map(static function (array $r): array {
            return [
                'restaurant_id' => (int)$r['restaurant_id'],
                'business_name' => (string)$r['business_name'],
                'cuisine_type'  => $r['cuisine_type'] !== null ? (string)$r['cuisine_type'] : null,
                'city'          => $r['city']         !== null ? (string)$r['city']         : null,
            ];
        }, $rows);

        echo json_encode(['status' => 'success', 'restaurants' => $restaurants]);
        exit;
    }

    if ($action === 'branches') {
        $rid  = (int)($_GET['restaurant_id'] ?? 0);
        $rows = searchBranchesByRestaurant($database_connection, $rid);

        $branches = array_map(static function (array $b): array {
            return [
                'branch_code' => (string)$b['branch_code'],
                'branch_name' => (string)$b['branch_name'],
                'city'        => $b['city'] !== null ? (string)$b['city'] : null,
            ];
        }, $rows);

        echo json_encode(['status' => 'success', 'branches' => $branches]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);

} catch (PDOException $e) {
    error_log('Branch lookup DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Lookup failed.']);
} catch (Throwable $e) {
    error_log('Branch lookup error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Lookup failed.']);
}