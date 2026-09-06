<?php
/**
 * FitPal Product Detail Page Test
 * Validates the product detail page, handlers, and price calculation
 *
 * @package FitPal
 * @version 1.0
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
    <title>FitPal Product Detail Test</title>
    <link rel='stylesheet' href='{$assetBase}assets/css/global.css'>
    <style>
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
        .btn-test.danger { background: #e53e3e; }
        .btn-test.danger:hover { background: #c53030; }
        .btn-test.success { background: #48bb78; }
        .btn-test.success:hover { background: #38a169; }
        .btn-test.warning { background: #ed8936; }
        .btn-test.warning:hover { background: #dd6b20; }
        .db-error-box { background: #fff5f5; border: 1px solid #feb2b2; border-radius: 8px; padding: 15px; margin: 10px 0; color: #c53030; width: 100%; }
        .db-error-box code { background: #fed7d7; padding: 2px 6px; border-radius: 4px; }
        .top-section { margin-bottom: 20px; width: 100%; }
        .product-price-test { background: #f0fff4; border: 2px solid #48bb78; border-radius: 8px; padding: 15px; margin: 10px 0; }
        .product-price-test.fail { background: #fff5f5; border-color: #fc8181; }
        .product-price-test .price-display { font-size: 24px; font-weight: 700; color: #2d3748; }
        .product-price-test .price-display.zero { color: #e53e3e; }
        .product-price-test .price-detail { font-size: 13px; color: #718096; margin-top: 5px; }
        
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
    <h1>FitPal Product Detail Test</h1>
    <p>Validates product detail page, handlers, and price calculation</p>
    <p style='font-size:13px;opacity:0.6;margin-top:10px;'>
        Base Path: " . htmlspecialchars($basePath) . " | 
        Base URL: " . htmlspecialchars($baseUrl) . "
    </p>
</div>";

// ============================================
// RUN ALL TESTS
// ============================================

// ============================================
// 1. DATABASE TESTS
// ============================================
$dbFile = $sharedPath . '/backend/database/database-connect.php';

if (file_exists($dbFile)) {
    testResult('Database File Exists', true, 'File found', 'Database');
    
    $content = file_get_contents($dbFile);
    if (strpos($content, '$database_connection') !== false) {
        testResult('Database File Parsed', true, 'Connection code found', 'Database');
        
        $database_host = 'localhost';
        $database_port = 3306;
        $database_name = 'fitpal_food_delivery';
        $database_username = 'root';
        $database_password = '';
        $database_charset = 'utf8mb4';
        
        try {
            $data_source_name = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $database_host,
                $database_port,
                $database_name,
                $database_charset
            );
            
            $test_connection = new PDO(
                $data_source_name,
                $database_username,
                $database_password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            
            testResult('Database Connection', true, 'PDO connection established', 'Database');
            
            // Check product table structure
            $stmt = $test_connection->query("SHOW TABLES LIKE 'product'");
            $hasProductTable = $stmt->rowCount() > 0;
            testResult('Product Table Exists', $hasProductTable, $hasProductTable ? 'Exists' : 'Missing', 'Database');
            
            if ($hasProductTable) {
                // Check for required columns
                $stmt = $test_connection->query("SHOW COLUMNS FROM product");
                $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                $requiredColumns = ['product_id', 'name', 'price', 'base_price', 'stock', 'is_active', 'is_customizable'];
                $missingColumns = [];
                foreach ($requiredColumns as $col) {
                    if (!in_array($col, $columns)) {
                        $missingColumns[] = $col;
                    }
                }
                testResult('Required Columns', empty($missingColumns), 
                    empty($missingColumns) ? 'All present' : 'Missing: ' . implode(', ', $missingColumns), 
                    'Database');
                
                // Check customization table
                $stmt = $test_connection->query("SHOW TABLES LIKE 'product_composition'");
                $hasCompTable = $stmt->rowCount() > 0;
                testResult('Product Composition Table', $hasCompTable, $hasCompTable ? 'Exists' : 'Missing', 'Database');
                
                if ($hasCompTable) {
                    $stmt = $test_connection->query("SHOW COLUMNS FROM product_composition");
                    $compColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    $requiredCompCols = ['composition_id', 'product_id', 'ingredient_id', 'price_modifier', 'is_default'];
                    $missingCompCols = [];
                    foreach ($requiredCompCols as $col) {
                        if (!in_array($col, $compColumns)) {
                            $missingCompCols[] = $col;
                        }
                    }
                    testResult('Composition Required Columns', empty($missingCompCols),
                        empty($missingCompCols) ? 'All present' : 'Missing: ' . implode(', ', $missingCompCols),
                        'Database');
                }
                
                // Check for products with zero price
                $stmt = $test_connection->query("SELECT COUNT(*) as count FROM product WHERE price = 0 OR base_price = 0 OR (price IS NULL AND base_price IS NULL)");
                $zeroPriceCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                testResult('Zero Price Products', $zeroPriceCount == 0, 
                    $zeroPriceCount == 0 ? 'None found' : $zeroPriceCount . ' product(s) have zero or NULL price',
                    'Database');
                
                // Get sample products for testing
                $stmt = $test_connection->query("SELECT product_id, name, price, base_price FROM product WHERE is_active = 1 LIMIT 5");
                $sampleProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($sampleProducts)) {
                    $sampleMsg = count($sampleProducts) . ' sample products found';
                    testResult('Sample Products', true, $sampleMsg, 'Database');
                    
                    // Check each sample product's price
                    foreach ($sampleProducts as $idx => $prod) {
                        $price = (float)($prod['base_price'] ?? $prod['price'] ?? 0);
                        $status = $price > 0;
                        testResult('Product #' . $prod['product_id'] . ' Price', $status,
                            $status ? '₱' . number_format($price, 2) : '⚠️ Price is ZERO!',
                            'Price Check');
                    }
                } else {
                    testResult('Sample Products', false, 'No active products found', 'Database');
                }
            }
            
        } catch (PDOException $e) {
            testResult('Database Connection', false, 'PDO Error: ' . $e->getMessage(), 'Database');
        }
    } else {
        testResult('Database File Parsed', false, 'Connection code not found', 'Database');
    }
} else {
    testResult('Database File Exists', false, 'File not found: ' . $dbFile, 'Database');
}

// ============================================
// 2. CORE FILE TESTS
// ============================================
$criticalFiles = [
    'Product Detail Page' => $customerPath . '/pages/product-detail.php',
    'Product Detail CSS' => $customerPath . '/assets/css/product-detail.css',
    'Product Detail JS' => $customerPath . '/assets/ui/js/product-detail.js',
    'Add to Cart Handler' => $customerPath . '/backend/handlers/add-to-cart-handler.php',
    'Get Product Customizations' => $customerPath . '/backend/handlers/get-product-customizations.php',
    'Product Queries' => $customerPath . '/backend/database/product-queries.php',
    'Customer Header' => $customerPath . '/includes/header.php',
    'Shared Header' => $sharedPath . '/includes/header.php',
    'Shared Footer' => $sharedPath . '/includes/footer.php',
    'Database Connect' => $sharedPath . '/backend/database/database-connect.php',
];

foreach ($criticalFiles as $name => $path) {
    $exists = file_exists($path);
    testResult($name, $exists, $exists ? 'Found' : 'Missing', 'Core Files');
}

// ============================================
// 3. PRODUCT DETAIL PAGE STRUCTURE
// ============================================
$productDetailPath = $customerPath . '/pages/product-detail.php';
if (file_exists($productDetailPath)) {
    $content = file_get_contents($productDetailPath);
    testResult('Page Readable', true, 'File loaded', 'Page Structure');
    
    // Check for key HTML elements
    $hasProductCard = strpos($content, 'product-detail-card') !== false;
    testResult('Product Card Container', $hasProductCard, $hasProductCard ? 'Found' : 'Missing', 'Page Structure');
    
    $hasProductImage = strpos($content, 'product-image') !== false;
    testResult('Product Image', $hasProductImage, $hasProductImage ? 'Found' : 'Missing', 'Page Structure');
    
    $hasProductInfo = strpos($content, 'product-info-section') !== false;
    testResult('Product Info Section', $hasProductInfo, $hasProductInfo ? 'Found' : 'Missing', 'Page Structure');
    
    $hasPriceSection = strpos($content, 'product-price-section') !== false;
    testResult('Price Section', $hasPriceSection, $hasPriceSection ? 'Found' : 'Missing', 'Page Structure');
    
    $hasDescription = strpos($content, 'product-description') !== false;
    testResult('Description Section', $hasDescription, $hasDescription ? 'Found' : 'Missing', 'Page Structure');
    
    $hasQuickInfo = strpos($content, 'product-quick-info') !== false;
    testResult('Quick Info', $hasQuickInfo, $hasQuickInfo ? 'Found' : 'Missing', 'Page Structure');
    
    $hasActionControl = strpos($content, 'action-control') !== false;
    testResult('Action Controls', $hasActionControl, $hasActionControl ? 'Found' : 'Missing', 'Page Structure');
    
    $hasQuantityControl = strpos($content, 'quantity-control') !== false;
    testResult('Quantity Control', $hasQuantityControl, $hasQuantityControl ? 'Found' : 'Missing', 'Page Structure');
    
    $hasAddToCart = strpos($content, 'add-to-cart-btn') !== false;
    testResult('Add to Cart Button', $hasAddToCart, $hasAddToCart ? 'Found' : 'Missing', 'Page Structure');
    
    $hasAddToOrder = strpos($content, 'add-to-order-btn') !== false;
    testResult('Add to Order Button', $hasAddToOrder, $hasAddToOrder ? 'Found' : 'Missing', 'Page Structure');
    
    $hasCustomizeBtn = strpos($content, 'customize-btn') !== false;
    testResult('Customize Button', $hasCustomizeBtn, $hasCustomizeBtn ? 'Found' : 'Missing', 'Page Structure');
    
    $hasCustomizationStep = strpos($content, 'stepCustomize') !== false;
    testResult('Customization Step', $hasCustomizationStep, $hasCustomizationStep ? 'Found' : 'Missing', 'Page Structure');
    
    $hasTotalPrice = strpos($content, 'totalPriceInput') !== false || strpos($content, 'mainTotalPrice') !== false;
    testResult('Total Price Elements', $hasTotalPrice, $hasTotalPrice ? 'Found' : 'Missing', 'Page Structure');
    
    $hasRelatedProducts = strpos($content, 'related-products') !== false || strpos($content, 'related-grid') !== false;
    testResult('Related Products', $hasRelatedProducts, $hasRelatedProducts ? 'Found' : 'Missing', 'Page Structure');
    
    // Check for product-detail.js inclusion
    $hasProductJS = strpos($content, 'product-detail.js') !== false;
    testResult('product-detail.js Included', $hasProductJS, $hasProductJS ? 'Found' : 'Missing', 'Page Structure');
    
    // Check for product-detail.css inclusion
    $hasProductCSS = strpos($content, 'product-detail.css') !== false;
    testResult('product-detail.css Included', $hasProductCSS, $hasProductCSS ? 'Found' : 'Missing', 'Page Structure');
    
    // Check price handling
    $hasBasePriceVar = strpos($content, '$basePrice') !== false;
    testResult('Base Price Variable', $hasBasePriceVar, $hasBasePriceVar ? 'Found' : 'Missing', 'Page Structure');
    
    $hasFormattedPrice = strpos($content, '$formattedPrice') !== false;
    testResult('Formatted Price', $hasFormattedPrice, $hasFormattedPrice ? 'Found' : 'Missing', 'Page Structure');
    
    $hasProductIdParam = strpos($content, '$_GET[\'id\']') !== false;
    testResult('Product ID from GET', $hasProductIdParam, $hasProductIdParam ? 'Found' : 'Missing', 'Page Structure');
    
    $hasCsrfToken = strpos($content, 'csrf_token') !== false;
    testResult('CSRF Token', $hasCsrfToken, $hasCsrfToken ? 'Found' : 'Missing', 'Page Structure');
    
    // Check for product validation
    $hasProductCheck = strpos($content, 'productExists') !== false || strpos($content, 'if (!$product)') !== false;
    testResult('Product Validation', $hasProductCheck, $hasProductCheck ? 'Found' : 'Missing', 'Page Structure');
}

// ============================================
// 4. CSS CHECKS
// ============================================
$cssPath = $customerPath . '/assets/css/product-detail.css';
if (file_exists($cssPath)) {
    $cssContent = file_get_contents($cssPath);
    testResult('product-detail.css Readable', true, 'File loaded', 'CSS');
    
    $hasActionRow = strpos($cssContent, 'action-row') !== false;
    testResult('Action Row Styles', $hasActionRow, $hasActionRow ? 'Found' : 'Missing', 'CSS');
    
    $hasActionCol = strpos($cssContent, 'action-col') !== false;
    testResult('Action Column Styles', $hasActionCol, $hasActionCol ? 'Found' : 'Missing', 'CSS');
    
    $hasQuantityControl = strpos($cssContent, 'quantity-control') !== false;
    testResult('Quantity Control Styles', $hasQuantityControl, $hasQuantityControl ? 'Found' : 'Missing', 'CSS');
    
    $hasTotalPrice = strpos($cssContent, 'action-total-price') !== false;
    testResult('Total Price Styles', $hasTotalPrice, $hasTotalPrice ? 'Found' : 'Missing', 'CSS');
    
    $hasActionBtn = strpos($cssContent, 'action-btn') !== false;
    testResult('Action Button Styles', $hasActionBtn, $hasActionBtn ? 'Found' : 'Missing', 'CSS');
    
    $hasCustomizeBtn = strpos($cssContent, 'customize-btn') !== false;
    testResult('Customize Button Styles', $hasCustomizeBtn, $hasCustomizeBtn ? 'Found' : 'Missing', 'CSS');
    
    $hasResponsive = strpos($cssContent, '@media') !== false;
    testResult('Responsive (Media Queries)', $hasResponsive, $hasResponsive ? 'Found' : 'Missing', 'CSS');
    
    // Check for 50/50 split implementation
    $hasFiftyFifty = strpos($cssContent, '50%') !== false;
    testResult('50/50 Split Layout', $hasFiftyFifty, $hasFiftyFifty ? 'Found' : 'Missing', 'CSS');
    
    $hasFlexbox = strpos($cssContent, 'flex:') !== false;
    testResult('Flexbox Implementation', $hasFlexbox, $hasFlexbox ? 'Found' : 'Missing', 'CSS');
    
    // Check for proper containment
    $hasOverflowHidden = strpos($cssContent, 'overflow: hidden') !== false;
    testResult('Overflow Handling', $hasOverflowHidden, $hasOverflowHidden ? 'Found' : 'Missing', 'CSS');
}

// ============================================
// 5. JAVASCRIPT CHECKS
// ============================================
$jsPath = $customerPath . '/assets/ui/js/product-detail.js';
if (file_exists($jsPath)) {
    $jsContent = file_get_contents($jsPath);
    testResult('product-detail.js Readable', true, 'File loaded', 'JavaScript');
    
    $hasDOMContent = strpos($jsContent, 'DOMContentLoaded') !== false;
    testResult('DOMContentLoaded', $hasDOMContent, $hasDOMContent ? 'Found' : 'Missing', 'JavaScript');
    
    $hasPriceCalculation = strpos($jsContent, 'calculateTotalPrice') !== false;
    testResult('Price Calculation Function', $hasPriceCalculation, $hasPriceCalculation ? 'Found' : 'Missing', 'JavaScript');
    
    $hasBasePrice = strpos($jsContent, 'basePrice') !== false;
    testResult('Base Price Variable', $hasBasePrice, $hasBasePrice ? 'Found' : 'Missing', 'JavaScript');
    
    $hasQuantityControl = strpos($jsContent, 'productQuantity') !== false;
    testResult('Quantity Control', $hasQuantityControl, $hasQuantityControl ? 'Found' : 'Missing', 'JavaScript');
    
    $hasUpdateTotal = strpos($jsContent, 'updateMainTotal') !== false || strpos($jsContent, 'updateCustomizeTotal') !== false;
    testResult('Update Total Functions', $hasUpdateTotal, $hasUpdateTotal ? 'Found' : 'Missing', 'JavaScript');
    
    $hasCustomizeStep = strpos($jsContent, 'stepCustomize') !== false;
    testResult('Customization Step Handling', $hasCustomizeStep, $hasCustomizeStep ? 'Found' : 'Missing', 'JavaScript');
    
    $hasModifierControls = strpos($jsContent, 'modifier') !== false;
    testResult('Modifier Controls', $hasModifierControls, $hasModifierControls ? 'Found' : 'Missing', 'JavaScript');
    
    $hasSelectControls = strpos($jsContent, 'customization-select') !== false;
    testResult('Select Controls', $hasSelectControls, $hasSelectControls ? 'Found' : 'Missing', 'JavaScript');
    
    $hasCheckboxControls = strpos($jsContent, 'checkbox') !== false;
    testResult('Checkbox Controls', $hasCheckboxControls, $hasCheckboxControls ? 'Found' : 'Missing', 'JavaScript');
    
    $hasFormSubmit = strpos($jsContent, 'submit') !== false;
    testResult('Form Submit Handling', $hasFormSubmit, $hasFormSubmit ? 'Found' : 'Missing', 'JavaScript');
    
    $hasCustomizationsData = strpos($jsContent, 'customizationsData') !== false;
    testResult('Customizations Data', $hasCustomizationsData, $hasCustomizationsData ? 'Found' : 'Missing', 'JavaScript');
    
    $hasStepNavigation = strpos($jsContent, 'showCustomizeStep') !== false || strpos($jsContent, 'showMainStep') !== false;
    testResult('Step Navigation', $hasStepNavigation, $hasStepNavigation ? 'Found' : 'Missing', 'JavaScript');
}

// ============================================
// 6. HANDLER CHECKS
// ============================================
$handlerPath = $customerPath . '/backend/handlers/add-to-cart-handler.php';
if (file_exists($handlerPath)) {
    $handlerContent = file_get_contents($handlerPath);
    testResult('Handler Readable', true, 'File loaded', 'Handlers');
    
    $hasSessionStart = strpos($handlerContent, 'session_start') !== false;
    testResult('session_start()', $hasSessionStart, $hasSessionStart ? 'Found' : 'Missing', 'Handlers');
    
    $hasDatabase = strpos($handlerContent, 'database-connect.php') !== false;
    testResult('Database Include', $hasDatabase, $hasDatabase ? 'Found' : 'Missing', 'Handlers');
    
    $hasCsrfValidation = strpos($handlerContent, 'csrf_token') !== false;
    testResult('CSRF Validation', $hasCsrfValidation, $hasCsrfValidation ? 'Found' : 'Missing', 'Handlers');
    
    $hasProductId = strpos($handlerContent, 'product_id') !== false;
    testResult('Product ID Handling', $hasProductId, $hasProductId ? 'Found' : 'Missing', 'Handlers');
    
    $hasQuantity = strpos($handlerContent, 'quantity') !== false;
    testResult('Quantity Handling', $hasQuantity, $hasQuantity ? 'Found' : 'Missing', 'Handlers');
    
    $hasTotalPrice = strpos($handlerContent, 'total_price') !== false;
    testResult('Total Price Handling', $hasTotalPrice, $hasTotalPrice ? 'Found' : 'Missing', 'Handlers');
    
    $hasCustomizations = strpos($handlerContent, 'customizations') !== false;
    testResult('Customizations Handling', $hasCustomizations, $hasCustomizations ? 'Found' : 'Missing', 'Handlers');
    
    $hasCartQueries = strpos($handlerContent, 'cart-queries.php') !== false;
    testResult('Cart Queries Include', $hasCartQueries, $hasCartQueries ? 'Found' : 'Missing', 'Handlers');
    
    $hasJsonResponse = strpos($handlerContent, 'json') !== false || strpos($handlerContent, 'JSON') !== false;
    testResult('JSON Response', $hasJsonResponse, $hasJsonResponse ? 'Found' : 'Missing', 'Handlers');
    
    $hasErrorHandling = strpos($handlerContent, 'try') !== false || strpos($handlerContent, 'catch') !== false;
    testResult('Error Handling', $hasErrorHandling, $hasErrorHandling ? 'Found' : 'Missing', 'Handlers');
}

// Check customization handler
$customHandlerPath = $customerPath . '/backend/handlers/get-product-customizations.php';
if (file_exists($customHandlerPath)) {
    $customContent = file_get_contents($customHandlerPath);
    testResult('Customization Handler Readable', true, 'File loaded', 'Handlers');
    
    $hasProductId = strpos($customContent, 'product_id') !== false;
    testResult('Product ID in Customization Handler', $hasProductId, $hasProductId ? 'Found' : 'Missing', 'Handlers');
    
    $hasCompositionQuery = strpos($customContent, 'product_composition') !== false;
    testResult('Composition Query', $hasCompositionQuery, $hasCompositionQuery ? 'Found' : 'Missing', 'Handlers');
    
    $hasIngredients = strpos($customContent, 'ingredient') !== false;
    testResult('Ingredient Data', $hasIngredients, $hasIngredients ? 'Found' : 'Missing', 'Handlers');
}

// ============================================
// 7. QUERY CHECKS
// ============================================
$queriesPath = $customerPath . '/backend/database/product-queries.php';
if (file_exists($queriesPath)) {
    $queryContent = file_get_contents($queriesPath);
    testResult('product-queries.php Readable', true, 'File loaded', 'Queries');
    
    $hasGetProduct = strpos($queryContent, 'function getProduct') !== false;
    testResult('getProduct Function', $hasGetProduct, $hasGetProduct ? 'Found' : 'Missing', 'Queries');
    
    $hasGetProducts = strpos($queryContent, 'function getProducts') !== false;
    testResult('getProducts Function', $hasGetProducts, $hasGetProducts ? 'Found' : 'Missing', 'Queries');
    
    $hasCustomizations = strpos($queryContent, 'function getProductCustomizations') !== false;
    testResult('getProductCustomizations Function', $hasCustomizations, $hasCustomizations ? 'Found' : 'Missing', 'Queries');
    
    $hasPriceColumn = strpos($queryContent, 'base_price') !== false || strpos($queryContent, 'price') !== false;
    testResult('Price Column Queries', $hasPriceColumn, $hasPriceColumn ? 'Found' : 'Missing', 'Queries');
}

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
// TOP SECTION: SUMMARY + LIVE FORM
// ============================================
echo "<div class='top-section'>";

// Summary Cards
echo "<div class='test-summary'>";
echo "<div class='summary-card total'><div class='number'>{$totalTests}</div><div class='label'>Total Tests</div></div>";
echo "<div class='summary-card passed'><div class='number'>{$totalPassed}</div><div class='label'>Passed</div></div>";
echo "<div class='summary-card failed'><div class='number'>{$totalFailed}</div><div class='label'>Failed</div></div>";
echo "<div class='summary-card rate'><div class='number'>{$passRate}%</div><div class='label'>Pass Rate</div></div>";
echo "</div>";

// Live Form Section
echo "<div class='test-section' id='section-live'>";
echo "<div class='section-header' data-target='content-live'>";
echo "<h2>Live Test <span class='section-count count-neutral'>(Live)</span></h2>";
echo "<button class='section-toggle' aria-expanded='false' aria-controls='content-live' type='button'>";
echo "<span class='toggle-icon'>+</span>";
echo "</button>";
echo "</div>";
echo "<div class='test-grid' id='content-live' style='display: none;'>";

echo "<div class='live-test'>";
echo "<p><strong>Test the product detail page:</strong></p>";
echo "<p style='font-size:13px;color:#718096;margin:5px 0;'>Test with product IDs: 21, 22, 30</p>";
echo "<div class='btn-test-group'>";
echo "<a href='" . $baseUrl . "/customer/pages/product-detail.php?id=21' target='_blank' class='btn-test'>Product #21</a>";
echo "<a href='" . $baseUrl . "/customer/pages/product-detail.php?id=22' target='_blank' class='btn-test'>Product #22</a>";
echo "<a href='" . $baseUrl . "/customer/pages/product-detail.php?id=30' target='_blank' class='btn-test'>Product #30</a>";
echo "<a href='" . $baseUrl . "/customer/pages/menu.php' target='_blank' class='btn-test warning'>Menu</a>";
echo "<a href='" . $baseUrl . "/customer/pages/dashboard.php' target='_blank' class='btn-test success'>Dashboard</a>";
echo "</div>";
echo "<p style='font-size:13px;color:#718096;margin:15px 0 5px 0;'><strong>Handler Tests:</strong></p>";
echo "<div class='btn-test-group'>";
echo "<a href='" . $baseUrl . "/customer/backend/handlers/add-to-cart-handler.php' target='_blank' class='btn-test danger'>Add to Cart Handler</a>";
echo "<a href='" . $baseUrl . "/customer/backend/handlers/get-product-customizations.php' target='_blank' class='btn-test'>Get Customizations</a>";
echo "<a href='" . $baseUrl . "/customer/backend/handlers/place-order-handler.php' target='_blank' class='btn-test warning'>Place Order</a>";
echo "</div>";

// Add a note about price issues
echo "<div style='margin-top:15px;padding:15px;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;'>";
echo "<p style='font-weight:600;color:#92400e;'>⚠️ Price Check:</p>";
echo "<p style='font-size:13px;color:#78350f;margin-top:5px;'>If you see ₱0.00, check that:</p>";
echo "<ul style='font-size:13px;color:#78350f;margin-top:5px;padding-left:20px;'>";
echo "<li>The product has 'price' or 'base_price' set in the database</li>";
echo "<li>The 'base_price' column exists in the product table</li>";
echo "<li>The COALESCE in the query is working properly</li>";
echo "<li>Customization components with price modifiers are not causing issues</li>";
echo "</ul>";
echo "</div>";

echo "</div>";
echo "</div>";
echo "</div>";

echo "</div>";

// ============================================
// RENDER ALL SECTIONS
// ============================================

renderSection('Database Tests', 'Database', true);
renderSection('Core File Tests', 'Core Files', true);
renderSection('Page Structure Tests', 'Page Structure', true);
renderSection('CSS Tests', 'CSS', true);
renderSection('JavaScript Tests', 'JavaScript', true);
renderSection('Handler Tests', 'Handlers', true);
renderSection('Query Tests', 'Queries', true);
renderSection('Price Check', 'Price Check', true);

// ============================================
// COLLAPSIBLE SECTIONS JAVASCRIPT
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