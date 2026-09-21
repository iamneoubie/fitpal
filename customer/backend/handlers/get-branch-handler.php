<?php
/**
 * FitPal Get Branch Handler
 *
 * AJAX endpoint to get branch details and products.
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Authentication check
if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/branch-queries.php';

// Get branch ID from request
$branchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;

if ($branchId <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid branch ID']);
    exit;
}

try {
    $branch = getBranchWithProducts($database_connection, $branchId);

    if (!$branch) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Branch not found']);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode($branch);
    exit;

} catch (PDOException $e) {
    error_log('Get branch error: ' . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database error occurred']);
    exit;
}   