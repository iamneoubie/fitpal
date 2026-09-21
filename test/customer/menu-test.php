<?php
/**
 * FitPal Menu Page Probe Test
 * Validates menu data directly from database
 *
 * @package FitPal
 * @version 2.4
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$basePath = realpath(__DIR__ . '/../..');
$customerPath = $basePath . '/customer';
$sharedPath = $basePath . '/shared';

$passed = 0;
$failed = 0;

$sections = [];

function testResult($name, $result, $message = '', $section = 'General') {
    global $sections, $passed, $failed;
    $status = $result ? 'PASS' : 'FAIL';
    if ($result) $passed++; else $failed++;
    
    if (!isset($sections[$section])) {
        $sections[$section] = [];
    }
    $sections[$section][] = [
        'name' => $name,
        'status' => $status,
        'message' => $message,
        'passed' => $result
    ];
    return $result;
}

function renderSection($title, $sectionName, $defaultCollapsed = true) {
    global $sections;
    
    $totalTests = isset($sections[$sectionName]) ? count($sections[$sectionName]) : 0;
    $passedTests = 0;
    if (isset($sections[$sectionName])) {
        foreach ($sections[$sectionName] as $test) {
            if ($test['passed']) $passedTests++;
        }
    }
    
    $failedTests = $totalTests - $passedTests;
    $allPassed = ($totalTests > 0 && $failedTests === 0);
    $hasFailures = ($failedTests > 0);
    
    $sectionId = 'section-' . preg_replace('/[^a-zA-Z0-9]/', '-', $sectionName);
    $contentId = 'content-' . preg_replace('/[^a-zA-Z0-9]/', '-', $sectionName);
    
    $collapsedClass = $defaultCollapsed ? 'section-collapsed' : '';
    
    echo "<div class='test-section {$collapsedClass}' id='{$sectionId}'>";
    echo "<div class='section-header' data-target='{$contentId}'>";
    
    $countClass = 'count-neutral';
    if ($allPassed) {
        $countClass = 'count-pass';
    } elseif ($hasFailures && $failedTests <= 2) {
        $countClass = 'count-warning';
    } elseif ($hasFailures && $failedTests > 2) {
        $countClass = 'count-fail';
    }
    
    echo "<h2>{$title} <span class='section-count {$countClass}'>({$passedTests}/{$totalTests})</span></h2>";
    echo "<button class='section-toggle' aria-expanded='" . ($defaultCollapsed ? 'false' : 'true') . "' aria-controls='{$contentId}' type='button'>";
    echo "<span class='toggle-icon'>" . ($defaultCollapsed ? '+' : '-') . "</span>";
    echo "</button>";
    echo "</div>";
    echo "<div class='test-grid' id='{$contentId}' style='display: " . ($defaultCollapsed ? 'none' : 'grid') . ";'>";
    
    if (isset($sections[$sectionName]) && !empty($sections[$sectionName])) {
        foreach ($sections[$sectionName] as $test) {
            $statusClass = $test['passed'] ? 'pass' : 'fail';
            $statusText = $test['passed'] ? 'PASS' : 'FAIL';
            
            echo "<div class='test-item {$statusClass}'>";
            echo "<div class='test-name'>" . htmlspecialchars($test['name']) . "</div>";
            echo "<div class='test-status {$statusClass}'>" . $statusText . "</div>";
            echo "<div class='test-message'>" . htmlspecialchars($test['message']) . "</div>";
            echo "</div>";
        }
    } else {
        echo "<div class='test-item' style='border-left-color: #edf2f0;'>";
        echo "<div class='test-name' style='color: #a0aec0;'>No tests in this section</div>";
        echo "</div>";
    }
    
    echo "</div>";
    echo "</div>";
}

function renderLiveMenuSection($baseUrl, $defaultCollapsed = true) {
    global $sections;
    
    $sectionName = 'Live Menu';
    $totalTests = isset($sections[$sectionName]) ? count($sections[$sectionName]) : 0;
    $passedTests = 0;
    if (isset($sections[$sectionName])) {
        foreach ($sections[$sectionName] as $test) {
            if ($test['passed']) $passedTests++;
        }
    }
    
    $failedTests = $totalTests - $passedTests;
    $allPassed = ($totalTests > 0 && $failedTests === 0);
    $hasFailures = ($failedTests > 0);
    
    $collapsedClass = $defaultCollapsed ? 'section-collapsed' : '';
    $contentId = 'content-live-menu';
    
    echo "<div class='test-section {$collapsedClass}' id='section-live-menu'>";
    echo "<div class='section-header' data-target='{$contentId}'>";
    
    $countClass = 'count-neutral';
    if ($allPassed) {
        $countClass = 'count-pass';
    } elseif ($hasFailures && $failedTests <= 2) {
        $countClass = 'count-warning';
    } elseif ($hasFailures && $failedTests > 2) {
        $countClass = 'count-fail';
    }
    
    echo "<h2>Live Menu Test <span class='section-count {$countClass}'>({$passedTests}/{$totalTests})</span></h2>";
    echo "<button class='section-toggle' aria-expanded='" . ($defaultCollapsed ? 'false' : 'true') . "' aria-controls='{$contentId}' type='button'>";
    echo "<span class='toggle-icon'>" . ($defaultCollapsed ? '+' : '-') . "</span>";
    echo "</button>";
    echo "</div>";
    echo "<div class='test-grid' id='{$contentId}' style='display: " . ($defaultCollapsed ? 'none' : 'grid') . ";'>";
    
    if (isset($sections[$sectionName]) && !empty($sections[$sectionName])) {
        foreach ($sections[$sectionName] as $test) {
            $statusClass = $test['passed'] ? 'pass' : 'fail';
            $statusText = $test['passed'] ? 'PASS' : 'FAIL';
            
            echo "<div class='test-item {$statusClass}'>";
            echo "<div class='test-name'>" . htmlspecialchars($test['name']) . "</div>";
            echo "<div class='test-status {$statusClass}'>" . $statusText . "</div>";
            echo "<div class='test-message'>" . htmlspecialchars($test['message']) . "</div>";
            echo "</div>";
        }
    }
    
    echo "<div class='live-test'>";
    echo "<p><strong>View the menu page:</strong></p>";
    echo "<p style='font-size:13px;color:#718096;margin:5px 0;'>URL: " . htmlspecialchars($baseUrl . '/customer/pages/menu.php') . "</p>";
    echo "<iframe src='" . $baseUrl . "/customer/pages/menu.php'></iframe>";
    echo "<div class='btn-test-group'>";
    echo "<a href='" . $baseUrl . "/customer/pages/menu.php' target='_blank' class='btn-test'>Open Menu</a>";
    echo "<a href='" . $baseUrl . "/customer/pages/sign-in.php' target='_blank' class='btn-test'>Sign In</a>";
    echo "</div>";
    echo "</div>";
    
    echo "</div>";
    echo "</div>";
}

function getAssetBase() {
    $scriptPath = $_SERVER['SCRIPT_NAME'];
    $dirPath = dirname($scriptPath);
    $segments = array_filter(explode('/', $dirPath));
    $depth = count($segments);
    if ($depth <= 0) return './shared/';
    return str_repeat('../', $depth) . 'shared/';
}

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$baseUrl = $protocol . '://' . $host;

$assetBase = getAssetBase();

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>FitPal Menu Probe Test</title>
    <link rel='stylesheet' href='{$assetBase}assets/css/global.css'>
    <style>
        /* ===== Exact same base styles as sign-in/sign-up tests ===== */
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { width: 100%; overflow-x: hidden; }
        body { background: #f4f6f9; padding: 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .test-container { max-width: 100%; padding: 0; margin: 0; }
        .test-header { background: linear-gradient(135deg, #2d3748, #1a202c); color: white; padding: 30px; border-radius: 12px; margin-bottom: 30px; width: 100%; }
        .test-header h1 { margin: 0; font-size: 28px; }
        .test-header p { margin: 10px 0 0 0; opacity: 0.8; }
        .test-summary { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 20px; width: 100%; }
        .summary-card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center; }
        .summary-card .number { font-size: 32px; font-weight: 700; }
        .summary-card .label { color: #718096; font-size: 14px; margin-top: 5px; }
        .summary-card.total .number { color: #2d3748; }
        .summary-card.passed .number { color: #48bb78; }
        .summary-card.failed .number { color: #fc8181; }
        .summary-card.rate .number { color: #4299e1; }
        .test-grid { display: grid; gap: 10px; padding: 15px; width: 100%; }
        .test-item { background: white; border-radius: 8px; padding: 12px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); border-left: 4px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; width: 100%; }
        .test-item.pass { border-left-color: #48bb78; }
        .test-item.fail { border-left-color: #fc8181; }
        .test-item .test-name { font-weight: 600; font-size: 14px; flex: 1; min-width: 180px; }
        .test-item .test-status { display: inline-block; padding: 2px 14px; border-radius: 20px; font-size: 11px; font-weight: 700; letter-spacing: 0.5px; margin: 0 10px; }
        .test-item .test-status.pass { background: #c6f6d5; color: #22543d; }
        .test-item .test-status.fail { background: #fed7d7; color: #9b2c2c; }
        .test-item .test-message { color: #718096; font-size: 13px; min-width: 100px; text-align: right; }
        .test-section { background: white; border-radius: 10px; margin-top: 15px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); overflow: hidden; width: 100%; }
        .section-header { display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; cursor: pointer; background: #f7fafc; border-bottom: 1px solid #e2e8f0; user-select: none; width: 100%; }
        .section-header:hover { background: #edf2f7; }
        .section-header h2 { margin: 0; font-size: 18px; color: #2d3748; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .section-count { font-size: 14px; font-weight: 600; padding: 2px 12px; border-radius: 20px; }
        .count-pass { background: #c6f6d5; color: #22543d; }
        .count-warning { background: #fefcbf; color: #975a16; }
        .count-fail { background: #fed7d7; color: #9b2c2c; }
        .count-neutral { background: #edf2f7; color: #718096; }
        .section-toggle { background: none; border: none; font-size: 20px; cursor: pointer; color: #718096; padding: 0 10px; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 6px; flex-shrink: 0; }
        .section-toggle:hover { background: #e2e8f0; }
        .toggle-icon { display: inline-block; transition: transform 0.2s ease; }
        .section-collapsed .toggle-icon { transform: rotate(0deg); }
        .live-test { padding: 10px 0; width: 100%; }
        .live-test iframe { width: 100%; height: 500px; border: 1px solid #e2e8f0; border-radius: 6px; margin-top: 10px; display: block; }
        .btn-test { display: inline-block; padding: 8px 16px; background: #4299e1; color: white; border-radius: 6px; text-decoration: none; font-size: 13px; margin-top: 10px; }
        .btn-test:hover { background: #3182ce; }
        .btn-test-group { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
        .top-section { margin-bottom: 20px; width: 100%; }
        
        /* ===== Extra styles for the raw data table (kept from original) ===== */
        .data-table { width: 100%; border-collapse: collapse; font-size: 13px; margin: 10px 0; }
        .data-table th { background: #edf2f7; padding: 8px 12px; text-align: left; font-weight: 600; }
        .data-table td { padding: 8px 12px; border-bottom: 1px solid #e2e8f0; }
        .data-table tr:hover { background: #f7fafc; }
        .status-ok { color: #48bb78; font-weight: 600; }
        .status-fail { color: #fc8181; font-weight: 600; }
        .db-error-box { background: #fff5f5; border: 1px solid #feb2b2; border-radius: 8px; padding: 15px; margin: 10px 0; color: #c53030; width: 100%; }
        
        /* ===== Responsive ===== */
        @media (max-width: 640px) {
            body { padding: 10px; }
            .test-header { padding: 20px; }
            .test-header h1 { font-size: 22px; }
            .test-summary { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .summary-card { padding: 15px; }
            .summary-card .number { font-size: 24px; }
            .section-header { padding: 12px 15px; flex-wrap: wrap; }
            .section-header h2 { font-size: 16px; }
            .section-count { font-size: 12px; padding: 2px 10px; }
            .test-item { padding: 10px 15px; flex-direction: column; align-items: flex-start; gap: 5px; }
            .test-item .test-name { font-size: 13px; min-width: auto; width: 100%; }
            .test-item .test-status { margin: 0 10px 0 0; }
            .test-item .test-message { text-align: left; width: 100%; min-width: auto; }
            .test-grid { padding: 10px; }
            .live-test iframe { height: 350px; }
            .btn-test-group { gap: 8px; }
            .btn-test { font-size: 12px; padding: 6px 12px; }
            .data-table { font-size: 11px; }
            .data-table th, .data-table td { padding: 4px 8px; }
        }
        
        @media (max-width: 400px) {
            .test-summary { grid-template-columns: 1fr 1fr; gap: 8px; }
            .summary-card { padding: 10px; }
            .summary-card .number { font-size: 20px; }
            .section-header h2 { font-size: 14px; }
            .section-count { font-size: 11px; padding: 1px 8px; }
            .test-item .test-name { font-size: 12px; }
            .live-test iframe { height: 280px; }
        }
    </style>
</head>
<body>
<div class='test-container'>";

echo "
<div class='test-header'>
    <h1>FitPal Menu Probe Test</h1>
    <p>Validates menu data directly from database</p>
    <p style='font-size:13px;opacity:0.6;margin-top:10px;'>
        Base Path: " . htmlspecialchars($basePath) . " | 
        Base URL: " . htmlspecialchars($baseUrl) . "
    </p>
</div>";

// ============================================
// PROBE THE DATABASE DIRECTLY (no HTTP calls)
// ============================================

require_once $sharedPath . '/backend/database/database-connect.php';

$dbData = [];

if (isset($database_connection) && $database_connection instanceof PDO) {
    try {
        // 1. Count all products
        $stmt = $database_connection->query("SELECT COUNT(*) as total FROM product");
        $dbData['total_products'] = (int)$stmt->fetchColumn();
        
        // 2. Count active products
        $stmt = $database_connection->query("SELECT COUNT(*) as total FROM product WHERE is_active = 1");
        $dbData['active_products'] = (int)$stmt->fetchColumn();
        
        // 3. Count active branches
        $stmt = $database_connection->query("SELECT COUNT(*) as total FROM restaurant_branch WHERE is_active = 1");
        $dbData['active_branches'] = (int)$stmt->fetchColumn();
        
        // 4. Count active restaurants
        $stmt = $database_connection->query("SELECT COUNT(*) as total FROM restaurant WHERE is_active = 1");
        $dbData['active_restaurants'] = (int)$stmt->fetchColumn();
        
        // 5. Products with NULL branch
        $stmt = $database_connection->query("SELECT COUNT(*) as total FROM product WHERE restaurant_branch_id IS NULL");
        $dbData['null_branch_products'] = (int)$stmt->fetchColumn();
        
        // 6. Products that would appear in menu
        $stmt = $database_connection->query(
            "SELECT COUNT(*) as total 
             FROM product p
             JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
             JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
             WHERE p.is_active = 1 AND rb.is_active = 1 AND r.is_active = 1"
        );
        $dbData['fully_active_products'] = (int)$stmt->fetchColumn();
        
        // 7. Sample products
        $stmt = $database_connection->query(
            "SELECT p.product_id, p.name, p.is_active, p.restaurant_branch_id,
                    rb.branch_name, rb.is_active as branch_active,
                    r.business_name, r.is_active as restaurant_active
             FROM product p
             LEFT JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
             LEFT JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
             LIMIT 5"
        );
        $dbData['sample_products'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        testResult('Database Connected', true, 'Connection established', 'Database');
        testResult('Active Products Exist', $dbData['active_products'] > 0, $dbData['active_products'] . ' active products', 'Database');
        testResult('Active Branches Exist', $dbData['active_branches'] > 0, $dbData['active_branches'] . ' active branches', 'Database');
        testResult('Active Restaurants Exist', $dbData['active_restaurants'] > 0, $dbData['active_restaurants'] . ' active restaurants', 'Database');
        testResult('No NULL Branch Products', $dbData['null_branch_products'] === 0, $dbData['null_branch_products'] . ' products with NULL branch', 'Database');
        testResult('Fully Active Products (menu-ready)', $dbData['fully_active_products'] > 0, $dbData['fully_active_products'] . ' products ready for menu', 'Database');
        
    } catch (PDOException $e) {
        testResult('Database Query', false, 'Error: ' . $e->getMessage(), 'Database');
        $dbData['error'] = $e->getMessage();
    }
} else {
    testResult('Database Connection', false, 'Could not connect to database', 'Database');
}

// ============================================
// CHECK FILES EXIST
// ============================================

$filesToCheck = [
    'Menu Page' => $customerPath . '/pages/menu.php',
    'Product Queries' => $customerPath . '/backend/database/product-queries.php',
    'Menu CSS' => $customerPath . '/assets/css/menu.css',
];

foreach ($filesToCheck as $name => $path) {
    $exists = file_exists($path);
    testResult($name, $exists, $exists ? 'Found' : 'Missing', 'Files');
}

// ============================================
// LIVE MENU TESTS - MOVED HERE BEFORE renderLiveMenuSection()
// ============================================

$menuPageExists = file_exists($customerPath . '/pages/menu.php');
testResult('Menu Page Accessible', $menuPageExists, $menuPageExists ? 'Page found' : 'Page missing', 'Live Menu');

$productQueriesExists = file_exists($customerPath . '/backend/database/product-queries.php');
testResult('Product Queries Included', $productQueriesExists, $productQueriesExists ? 'File found' : 'File missing', 'Live Menu');

$menuCssExists = file_exists($customerPath . '/assets/css/menu.css');
testResult('Menu CSS Exists', $menuCssExists, $menuCssExists ? 'Stylesheet found' : 'Stylesheet missing', 'Live Menu');

// ============================================
// CALCULATE TOTALS
// ============================================
$totalTests = 0;
$totalPassed = 0;
$totalFailed = 0;

foreach ($sections as $section) {
    foreach ($section as $test) {
        $totalTests++;
        if ($test['passed']) $totalPassed++;
        else $totalFailed++;
    }
}

$passRate = $totalTests > 0 ? round(($totalPassed / $totalTests) * 100) : 0;

// ============================================
// TOP SECTION: SUMMARY + LIVE MENU (Stacked vertically)
// ============================================
echo "<div class='top-section'>";

// Summary Cards
echo "<div class='test-summary'>";
echo "<div class='summary-card total'><div class='number'>{$totalTests}</div><div class='label'>Total Tests</div></div>";
echo "<div class='summary-card passed'><div class='number'>{$totalPassed}</div><div class='label'>Passed</div></div>";
echo "<div class='summary-card failed'><div class='number'>{$totalFailed}</div><div class='label'>Failed</div></div>";
echo "<div class='summary-card rate'><div class='number'>{$passRate}%</div><div class='label'>Pass Rate</div></div>";
echo "</div>";

// Live Menu (with heading, pass count, and collapsible)
renderLiveMenuSection($baseUrl, true);

echo "</div>";

// ============================================
// RENDER ALL SECTIONS (Collapsed by default)
// ============================================

renderSection('Database Tests', 'Database', true);
renderSection('File Tests', 'Files', true);

// ============================================
// RAW DATA DISPLAY
// ============================================
echo "<div class='test-section' id='section-raw-data'>";
echo "<div class='section-header' data-target='content-raw-data'>";
echo "<h2>Raw Data <span class='section-count count-neutral'>(View Only)</span></h2>";
echo "<button class='section-toggle' aria-expanded='false' aria-controls='content-raw-data' type='button'>";
echo "<span class='toggle-icon'>+</span>";
echo "</button>";
echo "</div>";
echo "<div class='test-grid' id='content-raw-data' style='display: none;'>";

if (!empty($dbData)) {
    echo "<h3 style='margin: 10px 0 5px 0; font-size: 16px;'>Database Summary</h3>";
    echo "<table class='data-table'>";
    echo "<tr><th>Metric</th><th>Value</th><th>Status</th></tr>";
    
    $metrics = [
        'Total Products' => $dbData['total_products'] ?? 'N/A',
        'Active Products' => $dbData['active_products'] ?? 'N/A',
        'Active Branches' => $dbData['active_branches'] ?? 'N/A',
        'Active Restaurants' => $dbData['active_restaurants'] ?? 'N/A',
        'Products with NULL Branch' => $dbData['null_branch_products'] ?? 'N/A',
        'Fully Active Products (menu-ready)' => $dbData['fully_active_products'] ?? 'N/A',
    ];
    
    foreach ($metrics as $label => $value) {
        $statusClass = 'status-ok';
        $statusText = 'OK';
        
        if (strpos($label, 'NULL') !== false && $value > 0) {
            $statusClass = 'status-fail';
            $statusText = 'ISSUE';
        } elseif (strpos($label, 'Fully') !== false && $value == 0) {
            $statusClass = 'status-fail';
            $statusText = 'ISSUE';
        } elseif ($label === 'Active Products' && $value == 0) {
            $statusClass = 'status-fail';
            $statusText = 'ISSUE';
        } elseif ($label === 'Active Branches' && $value == 0) {
            $statusClass = 'status-fail';
            $statusText = 'ISSUE';
        } elseif ($label === 'Active Restaurants' && $value == 0) {
            $statusClass = 'status-fail';
            $statusText = 'ISSUE';
        }
        
        echo "<tr>";
        echo "<td>" . htmlspecialchars($label) . "</td>";
        echo "<td>" . htmlspecialchars((string)$value) . "</td>";
        echo "<td class='{$statusClass}'>" . $statusText . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    if (!empty($dbData['sample_products'])) {
        echo "<h3 style='margin: 15px 0 5px 0; font-size: 16px;'>Sample Products</h3>";
        echo "<table class='data-table'>";
        echo "<tr><th>ID</th><th>Name</th><th>Active</th><th>Branch</th><th>Branch Active</th><th>Restaurant</th><th>Restaurant Active</th></tr>";
        foreach ($dbData['sample_products'] as $p) {
            $pActive = $p['is_active'] ? 'Yes' : 'No';
            $bActive = isset($p['branch_active']) ? ($p['branch_active'] ? 'Yes' : 'No') : 'N/A';
            $rActive = isset($p['restaurant_active']) ? ($p['restaurant_active'] ? 'Yes' : 'No') : 'N/A';
            echo "<tr>";
            echo "<td>" . ($p['product_id'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($p['name'] ?? 'N/A') . "</td>";
            echo "<td>" . $pActive . "</td>";
            echo "<td>" . htmlspecialchars($p['branch_name'] ?? 'NULL') . "</td>";
            echo "<td>" . $bActive . "</td>";
            echo "<td>" . htmlspecialchars($p['business_name'] ?? 'NULL') . "</td>";
            echo "<td>" . $rActive . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
}

echo "</div>";
echo "</div>";

// ============================================
// COLLAPSIBLE SECTIONS JAVASCRIPT (exactly as in sign-in test)
// ============================================
echo "
<script>
(function() {
    'use strict';

    var sectionHeaders = document.querySelectorAll('.section-header');

    sectionHeaders.forEach(function(header) {
        var targetId = header.getAttribute('data-target');
        var content = document.getElementById(targetId);
        var toggleBtn = header.querySelector('.section-toggle');
        var icon = toggleBtn ? toggleBtn.querySelector('.toggle-icon') : null;

        if (!content || !toggleBtn || !icon) return;

        header.addEventListener('click', function(e) {
            if (e.target.closest('.section-toggle')) return;
            toggleSection(header, content, toggleBtn, icon);
        });

        toggleBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            toggleSection(header, content, toggleBtn, icon);
        });

        header.setAttribute('role', 'button');
        header.setAttribute('tabindex', '0');
        header.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                toggleSection(header, content, toggleBtn, icon);
            }
        });
    });

    function toggleSection(header, content, toggleBtn, icon) {
        var isCollapsed = header.classList.contains('section-collapsed');

        if (isCollapsed) {
            header.classList.remove('section-collapsed');
            toggleBtn.setAttribute('aria-expanded', 'true');
            content.style.display = 'grid';
            icon.textContent = '-';
        } else {
            header.classList.add('section-collapsed');
            toggleBtn.setAttribute('aria-expanded', 'false');
            content.style.display = 'none';
            icon.textContent = '+';
        }
    }
})();
</script>
";

echo "
</div>
</body>
</html>";