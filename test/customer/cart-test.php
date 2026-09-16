<?php
/**
 * FitPal Cart System Test
 *
 * Validates the cart pipeline end-to-end:
 *   menu product card → add-to-cart-handler.php → cart table
 *                    → get-queue-handler.php → queue panel UI
 *
 * Two tiers of tests:
 *   (A) STRUCTURAL — files, form actions, token checks.
 *   (B) LOGIC — DB invariants and PHP/JS contracts that catch the
 *       bug classes that actually surfaced during development:
 *         - form action not pointing at add-to-cart-handler.php
 *         - JS preventDefault() on .add-to-cart-form submit
 *         - queue-panel.js hijacking form submit via window.addToQueue
 *         - queue-panel.js reading from sessionStorage instead of the DB
 *         - handler trusting client total_price without server recompute
 *         - handler not using INSERT ... ON DUPLICATE KEY / row lock
 *         - get-queue-handler returning anything other than the cart table
 *
 * Sections with 0 tests render as NEUTRAL, not as a pass.
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// PATHS
// ============================================
$basePath     = realpath(__DIR__ . '/../..');
$customerPath = $basePath . '/customer';
$sharedPath   = $basePath . '/shared';

// ============================================
// TEST HARNESS
// ============================================
$sections = [];

/**
 * Record a test result.
 *
 * @param string      $name
 * @param bool        $result
 * @param string      $message
 * @param string      $section
 * @param string      $tier     'logic' or 'structural'
 * @return bool
 */
function testResult(string $name, bool $result, string $message = '', string $section = 'General', string $tier = 'logic'): bool
{
    global $sections;
    if (!isset($sections[$section])) {
        $sections[$section] = [];
    }
    $sections[$section][] = [
        'name'    => $name,
        'status'  => $result ? 'PASS' : 'FAIL',
        'message' => $message,
        'passed'  => $result,
        'tier'    => $tier,
    ];
    return $result;
}

/**
 * Register a section that has no runnable tests.
 *
 * @param string $section
 * @param string $reason
 */
function markSectionSkipped(string $section, string $reason): void
{
    global $sections;
    if (!isset($sections[$section])) {
        $sections[$section] = [];
    }
    $sections[$section][] = [
        'name'    => 'Section skipped',
        'status'  => 'SKIP',
        'message' => $reason,
        'passed'  => null,
        'tier'    => 'structural',
    ];
}

/**
 * Render one section with correct pass/fail/skip accounting.
 *
 * @param string $title
 * @param string $sectionName
 * @param bool   $defaultCollapsed
 */
function renderSection(string $title, string $sectionName, bool $defaultCollapsed = true): void
{
    global $sections;

    $tests = $sections[$sectionName] ?? [];
    $total = 0; $passed = 0; $failed = 0; $skipped = 0;

    foreach ($tests as $t) {
        if ($t['passed'] === null) {
            $skipped++;
        } else {
            $total++;
            if ($t['passed']) {
                $passed++;
            } else {
                $failed++;
            }
        }
    }

    if ($total === 0) {
        $countClass = 'count-neutral';
        $countText  = $skipped > 0 ? 'skipped' : '0';
    } elseif ($failed === 0) {
        $countClass = 'count-pass';
        $countText  = "{$passed}/{$total}";
    } elseif ($failed <= 2) {
        $countClass = 'count-warning';
        $countText  = "{$passed}/{$total}";
    } else {
        $countClass = 'count-fail';
        $countText  = "{$passed}/{$total}";
    }

    // Sections with failures default to OPEN so reviewers cannot miss them.
    if ($failed > 0) {
        $defaultCollapsed = false;
    }

    $sectionId      = 'section-' . preg_replace('/[^a-zA-Z0-9]/', '-', $sectionName);
    $contentId      = 'content-' . preg_replace('/[^a-zA-Z0-9]/', '-', $sectionName);
    $collapsedClass = $defaultCollapsed ? 'section-collapsed' : '';

    echo "<div class='test-section {$collapsedClass}' id='{$sectionId}'>";
    echo "<div class='section-header' data-target='{$contentId}'>";
    echo "<h2>" . htmlspecialchars($title) . " <span class='section-count {$countClass}'>({$countText})</span></h2>";
    echo "<button class='section-toggle' aria-expanded='" . ($defaultCollapsed ? 'false' : 'true') . "' aria-controls='{$contentId}' type='button'>";
    echo "<span class='toggle-icon'>" . ($defaultCollapsed ? '+' : '-') . "</span>";
    echo "</button>";
    echo "</div>";
    echo "<div class='test-grid' id='{$contentId}' style='display: " . ($defaultCollapsed ? 'none' : 'grid') . ";'>";

    if (empty($tests)) {
        echo "<div class='test-item' style='border-left-color: #edf2f0;'>";
        echo "<div class='test-name' style='color: #a0aec0;'>No tests in this section</div>";
        echo "</div>";
    } else {
        foreach ($tests as $test) {
            if ($test['passed'] === null) {
                echo "<div class='test-item skip'>";
                echo "<div class='test-name'>" . htmlspecialchars($test['name']) . "</div>";
                echo "<div class='test-status skip'>SKIP</div>";
                echo "<div class='test-message'>" . htmlspecialchars($test['message']) . "</div>";
                echo "</div>";
                continue;
            }
            $cls = $test['passed'] ? 'pass' : 'fail';
            $txt = $test['passed'] ? 'PASS' : 'FAIL';
            echo "<div class='test-item {$cls}'>";
            echo "<div class='test-name'>" . htmlspecialchars($test['name']) . "</div>";
            echo "<div class='test-status {$cls}'>{$txt}</div>";
            echo "<div class='test-message'>" . htmlspecialchars($test['message']) . "</div>";
            echo "</div>";
        }
    }

    echo "</div>";
    echo "</div>";
}

/**
 * Asset base relative to the current script location.
 *
 * @return string
 */
function getAssetBase(): string
{
    $scriptPath = $_SERVER['SCRIPT_NAME'];
    $dirPath    = dirname($scriptPath);
    $segments   = array_filter(explode('/', $dirPath));
    $depth      = count($segments);
    if ($depth <= 0) {
        return './shared/';
    }
    return str_repeat('../', $depth) . 'shared/';
}

$protocol  = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'];
$baseUrl   = $protocol . '://' . $host;
$assetBase = getAssetBase();

// ============================================
// HEAD
// ============================================
echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FitPal Cart System Test</title>
<link rel="stylesheet" href="{$assetBase}assets/css/global.css">
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
    .test-item.skip { border-left-color: #cbd5e0; opacity: 0.75; }
    .test-item .test-name { font-weight: 600; font-size: 14px; flex: 1; min-width: 180px; }
    .test-item .test-status { display: inline-block; padding: 2px 14px; border-radius: 20px; font-size: 11px; font-weight: 700; letter-spacing: 0.5px; margin: 0 10px; }
    .test-item .test-status.pass { background: #c6f6d5; color: #22543d; }
    .test-item .test-status.fail { background: #fed7d7; color: #9b2c2c; }
    .test-item .test-status.skip { background: #edf2f7; color: #718096; }
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
    .top-section { margin-bottom: 20px; width: 100%; }
    .note-box { margin-top: 15px; padding: 15px; background: #fffbeb; border: 1px solid #fcd34d; border-radius: 8px; }
    .note-box p { font-size: 13px; color: #78350f; margin: 5px 0 0 0; }
    .note-box p:first-child { font-weight: 600; color: #92400e; margin-top: 0; }
    .note-box ul { font-size: 13px; color: #78350f; margin: 5px 0 0 0; padding-left: 20px; }
    .data-table { width: 100%; border-collapse: collapse; font-size: 13px; margin: 10px 0; }
    .data-table th { background: #edf2f7; padding: 8px 12px; text-align: left; font-weight: 600; }
    .data-table td { padding: 8px 12px; border-bottom: 1px solid #e2e8f0; }
    .data-table tr:hover { background: #f7fafc; }
    .status-ok { color: #48bb78; font-weight: 600; }
    .status-fail { color: #fc8181; font-weight: 600; }
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
</style>
</head>
<body>
<div class="test-container">
HTML;

// ============================================
// HEADER
// ============================================
echo "<div class='test-header'>";
echo "<h1>FitPal Cart System Test</h1>";
echo "<p>Validates menu → add-to-cart → cart table → queue panel pipeline.</p>";
echo "<p style='font-size:13px;opacity:0.6;margin-top:10px;'>Base Path: " . htmlspecialchars((string)$basePath) . " | Base URL: " . htmlspecialchars($baseUrl) . "</p>";
echo "</div>";

// ============================================
// DB CONNECTION
// ============================================
$dbFile = $sharedPath . '/backend/database/database-connect.php';
$db = null;

if (file_exists($dbFile)) {
    testResult('Database file exists', true, 'File found', 'Database', 'structural');
    try {
        $database_host     = 'localhost';
        $database_port     = 3306;
        $database_name     = 'fitpal_food_delivery';
        $database_username = 'root';
        $database_password = '';
        $database_charset  = 'utf8mb4';

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $database_host, $database_port, $database_name, $database_charset
        );
        $db = new PDO($dsn, $database_username, $database_password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        testResult('Database connection', true, 'PDO connection established', 'Database', 'structural');
    } catch (PDOException $e) {
        testResult('Database connection', false, 'PDO error: ' . $e->getMessage(), 'Database', 'structural');
        $db = null;
    }
} else {
    testResult('Database file exists', false, 'Missing: ' . $dbFile, 'Database', 'structural');
}

// ============================================
// SECTION A — FILES
// ============================================
$criticalFiles = [
    'menu.php'                    => $customerPath . '/pages/menu.php',
    'checkout.php'                => $customerPath . '/pages/checkout.php',
    'add-to-cart-handler.php'     => $customerPath . '/backend/handlers/add-to-cart-handler.php',
    'get-queue-handler.php'       => $customerPath . '/backend/handlers/get-queue-handler.php',
    'sync-queue-handler.php'      => $customerPath . '/backend/handlers/sync-queue-handler.php',
    'place-order-handler.php'     => $customerPath . '/backend/handlers/place-order-handler.php',
    'cart-queries.php'            => $customerPath . '/backend/database/cart-queries.php',
    'menu.js'                     => $customerPath . '/assets/ui/js/menu.js',
    'queue-panel.js'              => $customerPath . '/assets/ui/js/queue-panel.js',
    'queue-panel.css'             => $customerPath . '/assets/css/queue-panel.css',
    'menu-product.css'            => $customerPath . '/assets/css/menu-product.css',
    'customer header.php'         => $customerPath . '/includes/header.php',
    'shared footer.php'           => $sharedPath   . '/includes/footer.php',
    'database-connect.php'        => $sharedPath   . '/backend/database/database-connect.php',
];

foreach ($criticalFiles as $label => $path) {
    $ok = file_exists($path);
    testResult($label, $ok, $ok ? 'Found' : 'Missing: ' . $path, 'Files', 'structural');
}

// ============================================
// SECTION B — DB SCHEMA (cart)
// ============================================
if ($db !== null) {
    // cart table
    $stmt = $db->query("SHOW TABLES LIKE 'cart'");
    $hasCart = $stmt->rowCount() > 0;
    testResult('Table exists: cart', $hasCart, $hasCart ? 'Present' : 'Missing', 'Schema', 'structural');

    if ($hasCart) {
        // Required columns
        $requiredCols = [
            'cart_id', 'customer_id', 'product_id',
            'quantity', 'price', 'added_at', 'customization_data',
        ];
        $stmt = $db->query("SHOW COLUMNS FROM cart");
        $haveCols = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
        foreach ($requiredCols as $col) {
            $ok = in_array($col, $haveCols, true);
            testResult("cart.{$col}", $ok, $ok ? 'Present' : 'Missing', 'Schema', 'structural');
        }

        // Unique key on (customer_id, product_id) keeps the upsert path honest
        $stmt = $db->query("SHOW INDEX FROM cart WHERE Key_name = 'unique_cart_item'");
        $hasUnique = $stmt->rowCount() > 0;
        testResult(
            'Unique key: (customer_id, product_id)',
            $hasUnique,
            $hasUnique
                ? 'Present — prevents duplicate rows for the same product'
                : 'Missing — handler must implement upsert manually',
            'Schema'
        );

        // FK: cart.customer_id → customer
        $stmt = $db->query(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'cart'
               AND COLUMN_NAME = 'customer_id'
               AND REFERENCED_TABLE_NAME = 'customer'"
        );
        $hasFkCustomer = (int)$stmt->fetchColumn() > 0;
        testResult('FK: cart.customer_id → customer', $hasFkCustomer, $hasFkCustomer ? 'Present' : 'Missing', 'Schema', 'structural');

        // FK: cart.product_id → product
        $stmt = $db->query(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'cart'
               AND COLUMN_NAME = 'product_id'
               AND REFERENCED_TABLE_NAME = 'product'"
        );
        $hasFkProduct = (int)$stmt->fetchColumn() > 0;
        testResult('FK: cart.product_id → product', $hasFkProduct, $hasFkProduct ? 'Present' : 'Missing', 'Schema', 'structural');

        // CHECK: quantity > 0
        $stmt = $db->query(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'cart'
               AND CONSTRAINT_TYPE = 'CHECK'"
        );
        $hasChecks = (int)$stmt->fetchColumn() > 0;
        testResult('cart has CHECK constraints', $hasChecks, $hasChecks ? 'Present' : 'None found (optional)', 'Schema');
    }
}

// ============================================
// SECTION C — DB INVARIANTS (live cart data)
// ============================================
if ($db !== null) {
    // C1. No orphan cart rows (customer_id no longer in customer)
    $stmt = $db->query(
        "SELECT COUNT(*) FROM cart c
         LEFT JOIN customer cu ON c.customer_id = cu.customer_id
         WHERE cu.customer_id IS NULL"
    );
    $orphanCustomers = (int)$stmt->fetchColumn();
    testResult(
        'No orphan cart.customer_id',
        $orphanCustomers === 0,
        $orphanCustomers === 0 ? 'Clean' : "{$orphanCustomers} orphan row(s)",
        'DB Invariants'
    );

    // C2. No cart rows pointing at missing products
    $stmt = $db->query(
        "SELECT COUNT(*) FROM cart c
         LEFT JOIN product p ON c.product_id = p.product_id
         WHERE p.product_id IS NULL"
    );
    $orphanProducts = (int)$stmt->fetchColumn();
    testResult(
        'No orphan cart.product_id',
        $orphanProducts === 0,
        $orphanProducts === 0 ? 'Clean' : "{$orphanProducts} orphan row(s)",
        'DB Invariants'
    );

    // C3. quantity must be > 0
    $stmt = $db->query("SELECT COUNT(*) FROM cart WHERE quantity <= 0");
    $badQty = (int)$stmt->fetchColumn();
    testResult(
        'All cart.quantity > 0',
        $badQty === 0,
        $badQty === 0 ? 'Clean' : "{$badQty} row(s) with quantity <= 0",
        'DB Invariants'
    );

    // C4. price must be >= 0
    $stmt = $db->query("SELECT COUNT(*) FROM cart WHERE price < 0");
    $badPrice = (int)$stmt->fetchColumn();
    testResult(
        'All cart.price >= 0',
        $badPrice === 0,
        $badPrice === 0 ? 'Clean' : "{$badPrice} row(s) with negative price",
        'DB Invariants'
    );

    // C5. customization_data must be NULL or valid JSON
    $stmt = $db->query(
        "SELECT COUNT(*) FROM cart
         WHERE customization_data IS NOT NULL
           AND JSON_VALID(customization_data) = 0"
    );
    $badJson = (int)$stmt->fetchColumn();
    testResult(
        'customization_data is NULL or valid JSON',
        $badJson === 0,
        $badJson === 0 ? 'Clean' : "{$badJson} row(s) with invalid JSON",
        'DB Invariants'
    );

    // C6. No duplicate (customer_id, product_id) pairs
    $stmt = $db->query(
        "SELECT customer_id, product_id, COUNT(*) AS cnt
         FROM cart
         GROUP BY customer_id, product_id
         HAVING cnt > 1
         LIMIT 5"
    );
    $dupes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    testResult(
        'No duplicate (customer_id, product_id)',
        empty($dupes),
        empty($dupes)
            ? 'Clean'
            : count($dupes) . ' duplicate pair(s): '
              . implode(', ', array_map(
                    fn($r) => "cust={$r['customer_id']}/prod={$r['product_id']} x{$r['cnt']}",
                    $dupes
                )),
        'DB Invariants'
    );

    // C7. cart price should not exceed product.base_price + sane modifier cap.
    //     This is a soft invariant: if the client is allowed to set price,
    //     it should still be bounded. Cap = base_price + 10x.
    $stmt = $db->query(
        "SELECT c.cart_id, c.price, p.base_price, p.price AS product_price, p.name
         FROM cart c
         JOIN product p ON c.product_id = p.product_id
         WHERE p.base_price IS NOT NULL
           AND c.price > GREATEST(p.base_price, p.price) * 10"
    );
    $suspicious = $stmt->fetchAll(PDO::FETCH_ASSOC);
    testResult(
        'No cart price wildly exceeds product price',
        empty($suspicious),
        empty($suspicious)
            ? 'Clean (no row > 10x base price)'
            : count($suspicious) . ' suspicious row(s): '
              . implode(', ', array_map(
                    fn($r) => "cart={$r['cart_id']} {$r['name']} price={$r['price']} base={$r['base_price']}",
                    array_slice($suspicious, 0, 3)
                )),
        'DB Invariants'
    );
}

// ============================================
// SECTION D — PHP CONTRACT (menu.php)
// ============================================
$menuPath = $customerPath . '/pages/menu.php';
if (file_exists($menuPath)) {
    $menu = file_get_contents($menuPath);

    // Must include add-to-cart-handler.php as the form action
    preg_match_all(
        '/<form[^>]*class=["\'][^"\']*add-to-cart-form[^"\']*["\'][^>]*action=["\']([^"\']+)["\']/i',
        $menu,
        $actionFirst
    );
    preg_match_all(
        '/<form[^>]*action=["\']([^"\']+)["\'][^>]*class=["\'][^"\']*add-to-cart-form[^"\']*["\']/i',
        $menu,
        $actionLast
    );
    $actions = array_merge($actionFirst[1] ?? [], $actionLast[1] ?? []);

    $allPointToHandler = true;
    $actionList = [];
    foreach ($actions as $a) {
        $actionList[] = $a;
        if (strpos($a, 'add-to-cart-handler.php') === false) {
            $allPointToHandler = false;
        }
    }

    testResult(
        'add-to-cart-form action → add-to-cart-handler.php',
        $allPointToHandler && !empty($actions),
        empty($actions)
            ? 'No .add-to-cart-form found with an action attribute'
            : 'Actions: ' . implode(', ', array_unique($actionList)),
        'PHP Contract'
    );

    // Must render a hidden CSRF field inside the form
    $hasFormCsrf = (bool)preg_match(
        '/<form[^>]*add-to-cart-form[^>]*>.*?name=["\']csrf_token["\']/si',
        $menu
    );
    testResult(
        'Form contains csrf_token input',
        $hasFormCsrf,
        $hasFormCsrf ? 'Found' : 'Missing — handler will reject with security validation failed',
        'PHP Contract'
    );

    // Must render a hidden product_id input
    $hasProductId = (bool)preg_match(
        '/<form[^>]*add-to-cart-form[^>]*>.*?name=["\']product_id["\']/si',
        $menu
    );
    testResult(
        'Form contains product_id input',
        $hasProductId,
        $hasProductId ? 'Found' : 'Missing — handler cannot identify product',
        'PHP Contract'
    );

    // Must render a quantity input
    $hasQuantity = (bool)preg_match(
        '/<form[^>]*add-to-cart-form[^>]*>.*?name=["\']quantity["\']/si',
        $menu
    );
    testResult(
        'Form contains quantity input',
        $hasQuantity,
        $hasQuantity ? 'Found' : 'Missing',
        'PHP Contract'
    );

    // Must render the queue panel container
    $hasPanel = strpos($menu, 'queuePanelWrapper') !== false;
    testResult(
        'Renders queuePanelWrapper container',
        $hasPanel,
        $hasPanel ? 'Found' : 'Missing — panel has no mount point',
        'PHP Contract'
    );

    // Must include queue-panel.js
    $hasPanelJs = strpos($menu, 'queue-panel.js') !== false;
    testResult(
        'Includes queue-panel.js',
        $hasPanelJs,
        $hasPanelJs ? 'Found' : 'Missing',
        'PHP Contract'
    );

    // Must NOT render a second <!DOCTYPE>
    $doctypeCount = substr_count(strtolower($menu), '<!doctype');
    testResult(
        'No duplicate <!DOCTYPE>',
        $doctypeCount === 0,
        $doctypeCount === 0 ? 'Clean' : "Found {$doctypeCount} — page contract violation",
        'PHP Contract',
        'structural'
    );
}

// ============================================
// SECTION E — JS CONTRACT (menu.js)
// ============================================
$menuJsPath = $customerPath . '/assets/ui/js/menu.js';
if (file_exists($menuJsPath)) {
    $menuJs = file_get_contents($menuJsPath);

    // Must NOT preventDefault on .add-to-cart-form submit
    $hasPreventOnCartForm = preg_match(
        '/add-to-cart-form[\s\S]{0,400}?preventDefault/',
        $menuJs
    ) === 1;

    testResult(
        'menu.js does NOT preventDefault on cart form submit',
        !$hasPreventOnCartForm,
        $hasPreventOnCartForm
            ? 'Found preventDefault near add-to-cart-form — form POST will be blocked'
            : 'Clean',
        'JS Contract'
    );

    // Must NOT call window.addToQueue from a submit handler
    $hasHijack = preg_match(
        '/add-to-cart-form[\s\S]{0,600}?window\.addToQueue/',
        $menuJs
    ) === 1;
    testResult(
        'menu.js does NOT call window.addToQueue from submit',
        !$hasHijack,
        $hasHijack
            ? 'Found window.addToQueue near add-to-cart-form — form is hijacked'
            : 'Clean',
        'JS Contract'
    );

    // Must still attach a submit listener for loading state (no preventDefault)
    $hasSubmitListener = strpos($menuJs, "addEventListener('submit'") !== false
                      || strpos($menuJs, 'addEventListener("submit"') !== false;
    testResult(
        'menu.js attaches a submit listener',
        $hasSubmitListener,
        $hasSubmitListener ? 'Found (loading state only)' : 'Missing',
        'JS Contract'
    );

    // Quantity controls should be present
    $hasQtyControls = strpos($menuJs, 'qty-plus') !== false
                   && strpos($menuJs, 'qty-minus') !== false;
    testResult(
        'menu.js binds qty +/- buttons',
        $hasQtyControls,
        $hasQtyControls ? 'Found' : 'Missing',
        'JS Contract'
    );

    // Uses strict mode
    $hasStrict = strpos($menuJs, "'use strict'") !== false
              || strpos($menuJs, '"use strict"') !== false;
    testResult("'use strict'", $hasStrict, $hasStrict ? 'Found' : 'Missing', 'JS Contract', 'structural');
}

// ============================================
// SECTION F — JS CONTRACT (queue-panel.js)
// ============================================
$panelJsPath = $customerPath . '/assets/ui/js/queue-panel.js';
if (file_exists($panelJsPath)) {
    $panelJs = file_get_contents($panelJsPath);

    // Must NOT hijack .add-to-cart-form submissions
    $hasSubmitHijack = preg_match(
        '/document\.addEventListener\(\s*[\'"]submit[\'"][\s\S]{0,600}?add-to-cart-form/',
        $panelJs
    ) === 1;
    testResult(
        'queue-panel.js does NOT hijack add-to-cart submits',
        !$hasSubmitHijack,
        $hasSubmitHijack
            ? 'Found document.submit handler intercepting .add-to-cart-form'
            : 'Clean',
        'JS Contract'
    );

    // Must NOT read/write sessionStorage as source of truth
    $usesSessionStorage = strpos($panelJs, 'sessionStorage.getItem') !== false
                       || strpos($panelJs, 'sessionStorage.setItem') !== false;
    testResult(
        'queue-panel.js does NOT use sessionStorage',
        !$usesSessionStorage,
        $usesSessionStorage
            ? 'Found sessionStorage access — panel should read the DB instead'
            : 'Clean',
        'JS Contract'
    );

    // Must hit get-queue-handler.php to fetch cart state
    $callsGetQueue = strpos($panelJs, 'get-queue-handler.php') !== false;
    testResult(
        'queue-panel.js calls get-queue-handler.php',
        $callsGetQueue,
        $callsGetQueue ? 'Found' : 'Missing — panel has no data source',
        'JS Contract'
    );

    // Must hit sync-queue-handler.php for mutations
    $callsSyncQueue = strpos($panelJs, 'sync-queue-handler.php') !== false;
    testResult(
        'queue-panel.js calls sync-queue-handler.php',
        $callsSyncQueue,
        $callsSyncQueue ? 'Found' : 'Missing — panel cannot persist removes/qty changes',
        'JS Contract'
    );

    // Must define loadQueueFromServer
    $hasLoader = strpos($panelJs, 'loadQueueFromServer') !== false;
    testResult(
        'queue-panel.js defines loadQueueFromServer',
        $hasLoader,
        $hasLoader ? 'Found' : 'Missing',
        'JS Contract'
    );

    // Uses strict mode
    $hasStrict = strpos($panelJs, "'use strict'") !== false
              || strpos($panelJs, '"use strict"') !== false;
    testResult("'use strict'", $hasStrict, $hasStrict ? 'Found' : 'Missing', 'JS Contract', 'structural');
}

// ============================================
// SECTION G — HANDLER CONTRACT (add-to-cart-handler.php)
// ============================================
$addHandlerPath = $customerPath . '/backend/handlers/add-to-cart-handler.php';
if (file_exists($addHandlerPath)) {
    $h = file_get_contents($addHandlerPath);

    // Auth
    $hasAuth = strpos($h, "customer_id") !== false
            && strpos($h, '$_SESSION') !== false;
    testResult('Auth check present', $hasAuth, $hasAuth ? 'Found' : 'Missing', 'Handler Contract');

    // CSRF
    $hasCsrf = strpos($h, 'csrf_token') !== false;
    testResult('CSRF check present', $hasCsrf, $hasCsrf ? 'Found' : 'Missing', 'Handler Contract');

    // Transaction
    $hasTx = strpos($h, 'beginTransaction') !== false;
    testResult('Uses a transaction', $hasTx, $hasTx ? 'Found' : 'Missing', 'Handler Contract');

    // Row lock on product
    $locksProduct = preg_match(
        '/FROM\s+product[\s\S]{0,200}?FOR\s+UPDATE/i',
        $h
    ) === 1;
    testResult(
        'Locks product row (FOR UPDATE)',
        $locksProduct,
        $locksProduct
            ? 'Found'
            : 'Missing — concurrent adds may oversell',
        'Handler Contract'
    );

    // Reads existing cart row
    $readsExisting = preg_match(
        '/FROM\s+cart[\s\S]{0,200}?(WHERE|customer_id)/i',
        $h
    ) === 1;
    testResult(
        'Reads existing cart row',
        $readsExisting,
        $readsExisting
            ? 'Found'
            : 'Missing — handler may insert duplicate rows',
        'Handler Contract'
    );

    // Either UPDATE for existing + INSERT for new, or INSERT ... ON DUPLICATE KEY
    $hasUpdate = stripos($h, 'UPDATE cart') !== false;
    $hasUpsert = stripos($h, 'ON DUPLICATE KEY UPDATE') !== false;
    testResult(
        'Has update-or-insert path',
        $hasUpdate || $hasUpsert,
        $hasUpdate
            ? 'Found UPDATE cart'
            : ($hasUpsert ? 'Found ON DUPLICATE KEY UPDATE' : 'Missing — cannot re-add'),
        'Handler Contract'
    );

    // Must NOT blindly trust client total_price
    $trustsClientTotal = preg_match(
        '/\$_POST\s*\[\s*[\'"]total_price[\'"]\s*\]/',
        $h
    ) === 1;
    testResult(
        'Does not trust client total_price',
        !$trustsClientTotal,
        $trustsClientTotal
            ? 'Found $_POST["total_price"] usage — client controls the price'
            : 'Clean (server-side pricing)',
        'Handler Contract'
    );

    // Uses base_price as the source of truth
    $usesBasePrice = strpos($h, 'base_price') !== false;
    testResult(
        'Uses product.base_price for pricing',
        $usesBasePrice,
        $usesBasePrice
            ? 'Found'
            : 'Missing — pricing may drift from admin-set base',
        'Handler Contract'
    );

    // Handles both success and error via session flash + redirect
    $hasSuccessFlash = strpos($h, 'cart_success') !== false
                    || strpos($h, 'queue_success') !== false;
    $hasErrorFlash   = strpos($h, 'cart_error') !== false
                    || strpos($h, 'queue_error') !== false;
    testResult(
        'Sets success flash',
        $hasSuccessFlash,
        $hasSuccessFlash ? 'Found' : 'Missing',
        'Handler Contract'
    );
    testResult(
        'Sets error flash',
        $hasErrorFlash,
        $hasErrorFlash ? 'Found' : 'Missing',
        'Handler Contract'
    );

    // Redirects after handling (no silent exit)
    $redirects = strpos($h, "header('Location:") !== false
              || strpos($h, 'header("Location:') !== false;
    testResult(
        'Redirects after handling',
        $redirects,
        $redirects ? 'Found' : 'Missing',
        'Handler Contract'
    );
}

// ============================================
// SECTION H — HANDLER CONTRACT (get-queue-handler.php)
// ============================================
$getHandlerPath = $customerPath . '/backend/handlers/get-queue-handler.php';
if (file_exists($getHandlerPath)) {
    $g = file_get_contents($getHandlerPath);

    // Must read from the cart table
    $readsCart = preg_match('/FROM\s+cart\b/i', $g) === 1;
    testResult(
        'Reads from cart table',
        $readsCart,
        $readsCart
            ? 'Found'
            : 'Missing — handler may read the wrong source',
        'Handler Contract'
    );

    // Must scope by customer
    $scopesByCustomer = stripos($g, 'customer_id') !== false;
    testResult(
        'Scopes by customer_id',
        $scopesByCustomer,
        $scopesByCustomer ? 'Found' : 'Missing — leaks other users carts',
        'Handler Contract'
    );

    // Must return JSON
    $returnsJson = stripos($g, 'application/json') !== false;
    testResult(
        'Returns JSON content-type',
        $returnsJson,
        $returnsJson ? 'Found' : 'Missing',
        'Handler Contract'
    );

    // Must return a "queue" array in the JSON shape (the panel expects it)
    $returnsQueue = strpos($g, "'queue'") !== false
                 || strpos($g, '"queue"') !== false;
    testResult(
        'Response includes "queue" array',
        $returnsQueue,
        $returnsQueue ? 'Found' : 'Missing — panel reads data.queue',
        'Handler Contract'
    );
}

// ============================================
// SECTION I — HANDLER CONTRACT (sync-queue-handler.php)
// ============================================
$syncHandlerPath = $customerPath . '/backend/handlers/sync-queue-handler.php';
if (file_exists($syncHandlerPath)) {
    $s = file_get_contents($syncHandlerPath);

    // Must use a transaction
    $hasTx = strpos($s, 'beginTransaction') !== false;
    testResult(
        'Uses a transaction',
        $hasTx,
        $hasTx ? 'Found' : 'Missing — partial syncs possible',
        'Handler Contract'
    );

    // Must delete cart rows not present in the incoming queue
    $hasDelete = stripos($s, 'DELETE FROM cart') !== false;
    testResult(
        'Deletes cart rows not in payload',
        $hasDelete,
        $hasDelete ? 'Found' : 'Missing — stale rows may linger',
        'Handler Contract'
    );

    // Must NOT trust client-sent price without re-reading product
    $trustsClientPrice = preg_match(
        '/\$_POST[\s\S]{0,200}?price|json_decode[\s\S]{0,200}?\$item\[[\'"]price[\'"]\]/',
        $s
    ) === 1;
    $reReadsProduct = preg_match(
        '/FROM\s+product[\s\S]{0,200}?(base_price|price)/i',
        $s
    ) === 1;
    testResult(
        'Re-reads product price from DB',
        $reReadsProduct,
        $reReadsProduct
            ? 'Found'
            : 'Missing — syncing trusts client-supplied price',
        'Handler Contract'
    );
    testResult(
        'Does not blindly trust client price',
        !$trustsClientPrice || $reReadsProduct,
        $trustsClientPrice
            ? ($reReadsProduct ? 'Client price present but overridden by DB' : 'Only client price trusted')
            : 'Clean',
        'Handler Contract'
    );

    // CSRF
    $hasCsrf = strpos($s, 'csrf_token') !== false;
    testResult('CSRF check present', $hasCsrf, $hasCsrf ? 'Found' : 'Missing', 'Handler Contract');
}

// ============================================
// SECTION J — HANDLER CONTRACT (place-order-handler.php)
// ============================================
$placeHandlerPath = $customerPath . '/backend/handlers/place-order-handler.php';
if (file_exists($placeHandlerPath)) {
    $p = file_get_contents($placeHandlerPath);

    // Must read cart via cart-queries (single source of truth)
    $usesCartQueries = stripos($p, 'cart-queries') !== false
                    || stripos($p, 'getCartGroupedByBranch') !== false;
    testResult(
        'Uses cart-queries helper',
        $usesCartQueries,
        $usesCartQueries ? 'Found' : 'Missing — duplicated cart read logic',
        'Handler Contract'
    );

    // Must NOT insert into the GENERATED column total_amount
    $insertsTotal = preg_match(
        '/INSERT\s+INTO\s+orders[\s\S]{0,400}?total_amount/i',
        $p
    ) === 1;
    testResult(
        'Does not insert into GENERATED total_amount',
        !$insertsTotal,
        $insertsTotal
            ? 'Found total_amount in INSERT — generated column will error'
            : 'Clean',
        'Handler Contract'
    );

    // Uses a transaction
    $hasTx = strpos($p, 'beginTransaction') !== false;
    testResult(
        'Uses a transaction',
        $hasTx,
        $hasTx ? 'Found' : 'Missing — partial orders possible',
        'Handler Contract'
    );

    // Locks product stock
    $locksProduct = preg_match(
        '/FROM\s+product[\s\S]{0,300}?FOR\s+UPDATE/i',
        $p
    ) === 1;
    testResult(
        'Locks product rows (FOR UPDATE)',
        $locksProduct,
        $locksProduct
            ? 'Found'
            : 'Missing — stock race conditions possible',
        'Handler Contract'
    );

    // Decrements stock
    $decrements = stripos($p, 'SET stock = stock -') !== false
               || stripos($p, 'UPDATE product') !== false;
    testResult(
        'Decrements product stock',
        $decrements,
        $decrements ? 'Found' : 'Missing',
        'Handler Contract'
    );

    // Clears cart after order
    $clearsCart = stripos($p, 'DELETE FROM cart') !== false;
    testResult(
        'Clears cart after order',
        $clearsCart,
        $clearsCart ? 'Found' : 'Missing',
        'Handler Contract'
    );

    // CSRF
    $hasCsrf = strpos($p, 'csrf_token') !== false;
    testResult('CSRF check present', $hasCsrf, $hasCsrf ? 'Found' : 'Missing', 'Handler Contract');
}

// ============================================
// SECTION K — ROUND-TRIP SMOKE TEST (read-only)
// Confirms the cart table responds to a normal SELECT scoped by customer.
// We do not mutate anything here — only verify the query shape works.
// ============================================
if ($db !== null) {
    try {
        $stmt = $db->query(
            "SELECT c.cart_id, c.product_id, c.quantity, c.price, c.customization_data,
                    p.name AS product_name, p.base_price, p.stock
             FROM cart c
             JOIN product p ON c.product_id = p.product_id
             ORDER BY c.added_at DESC
             LIMIT 5"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        testResult(
            'Cart join query executes',
            true,
            count($rows) . ' row(s) returned',
            'Round-Trip'
        );

        $allValidJson = true;
        foreach ($rows as $r) {
            if ($r['customization_data'] !== null
                && json_decode((string)$r['customization_data'], true) === null
                && json_last_error() !== JSON_ERROR_NONE) {
                $allValidJson = false;
                break;
            }
        }
        testResult(
            'Returned customization_data parses as JSON',
            $allValidJson,
            $allValidJson ? 'All rows valid' : 'At least one row is not valid JSON',
            'Round-Trip'
        );
    } catch (PDOException $e) {
        testResult(
            'Cart join query executes',
            false,
            'PDO error: ' . $e->getMessage(),
            'Round-Trip'
        );
    }

    // Live cart summary
    try {
        $stmt = $db->query(
            "SELECT
                COUNT(*)                   AS total_rows,
                COUNT(DISTINCT customer_id) AS distinct_customers,
                COALESCE(SUM(quantity), 0) AS total_units,
                COALESCE(SUM(quantity * price), 0) AS total_value
             FROM cart"
        );
        $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        testResult(
            'Cart summary computes',
            true,
            sprintf(
                'rows=%d customers=%d units=%d value=₱%.2f',
                (int)($summary['total_rows'] ?? 0),
                (int)($summary['distinct_customers'] ?? 0),
                (int)($summary['total_units'] ?? 0),
                (float)($summary['total_value'] ?? 0)
            ),
            'Round-Trip'
        );
    } catch (PDOException $e) {
        testResult(
            'Cart summary computes',
            false,
            'PDO error: ' . $e->getMessage(),
            'Round-Trip'
        );
    }
}

// ============================================
// TOTALS
// ============================================
$totalTests = 0; $totalPassed = 0; $totalFailed = 0; $totalSkipped = 0;

foreach ($sections as $tests) {
    foreach ($tests as $t) {
        if ($t['passed'] === null) {
            $totalSkipped++;
            continue;
        }
        $totalTests++;
        if ($t['passed']) {
            $totalPassed++;
        } else {
            $totalFailed++;
        }
    }
}
$passRate = $totalTests > 0 ? round(($totalPassed / $totalTests) * 100) : 0;

// ============================================
// SUMMARY + LIVE SECTION
// ============================================
echo "<div class='top-section'>";
echo "<div class='test-summary'>";
echo "<div class='summary-card total'><div class='number'>{$totalTests}</div><div class='label'>Total Tests</div></div>";
echo "<div class='summary-card passed'><div class='number'>{$totalPassed}</div><div class='label'>Passed</div></div>";
echo "<div class='summary-card failed'><div class='number'>{$totalFailed}</div><div class='label'>Failed</div></div>";
echo "<div class='summary-card rate'><div class='number'>{$passRate}%</div><div class='label'>Pass Rate</div></div>";
echo "</div>";

echo "<div class='test-section' id='section-live'>";
echo "<div class='section-header' data-target='content-live'>";
echo "<h2>Live Test <span class='section-count count-neutral'>(Live)</span></h2>";
echo "<button class='section-toggle' aria-expanded='false' aria-controls='content-live' type='button'><span class='toggle-icon'>+</span></button>";
echo "</div>";
echo "<div class='test-grid' id='content-live' style='display: none;'>";
echo "<div class='live-test'>";
echo "<p><strong>Exercise the cart pipeline in order:</strong></p>";
echo "<div class='btn-test-group'>";
echo "<a href='" . htmlspecialchars($baseUrl) . "/customer/pages/sign-in.php' target='_blank' class='btn-test'>1. Sign In</a>";
echo "<a href='" . htmlspecialchars($baseUrl) . "/customer/pages/menu.php' target='_blank' class='btn-test'>2. Menu</a>";
echo "<a href='" . htmlspecialchars($baseUrl) . "/customer/backend/handlers/get-queue-handler.php' target='_blank' class='btn-test warning'>3. Inspect Cart JSON</a>";
echo "<a href='" . htmlspecialchars($baseUrl) . "/customer/pages/checkout.php' target='_blank' class='btn-test success'>4. Checkout</a>";
echo "</div>";

echo "<div class='note-box'>";
echo "<p>What to verify manually:</p>";
echo "<ul>";
echo "<li><strong>DB write:</strong> after clicking Add, run <code>SELECT * FROM cart;</code> and confirm a row exists for the logged-in customer_id</li>";
echo "<li><strong>No double-post:</strong> only one POST should appear in the Network tab when you click Add once</li>";
echo "<li><strong>No hijack:</strong> the Network tab must show a POST to <code>/customer/backend/handlers/add-to-cart-handler.php</code>, not a sessionStorage write</li>";
echo "<li><strong>Re-add same product:</strong> quantity should increase on the existing row (not create a duplicate)</li>";
echo "<li><strong>Panel sync:</strong> the queue panel should show the item on next page load — it reads the same cart table</li>";
echo "<li><strong>Remove from panel:</strong> the row should disappear from <code>cart</code> after the panel's remove action posts to <code>sync-queue-handler.php</code></li>";
echo "<li><strong>Checkout:</strong> placing an order should clear the cart and create a row in <code>orders</code> + <code>queue_item</code></li>";
echo "</ul>";
echo "</div>";

echo "<p style='margin-top:15px;'><strong>Live cart JSON snapshot:</strong></p>";
echo "<p style='font-size:13px;color:#718096;margin:5px 0;'>GET " . htmlspecialchars($baseUrl . '/customer/backend/handlers/get-queue-handler.php') . "</p>";
echo "</div>";
echo "</div>";
echo "</div>";
echo "</div>";

// ============================================
// RENDER SECTIONS
// ============================================
renderSection('Database Tests',        'Database',          true);
renderSection('File Tests',            'Files',             true);
renderSection('Schema Tests',          'Schema',            true);
renderSection('DB Invariant Tests',    'DB Invariants',     false);
renderSection('PHP Contract Tests',    'PHP Contract',      false);
renderSection('JS Contract Tests',     'JS Contract',       false);
renderSection('Handler Contract',      'Handler Contract',  false);
renderSection('Round-Trip Tests',      'Round-Trip',        false);

// ============================================
// RAW DATA
// ============================================
echo "<div class='test-section' id='section-raw-data'>";
echo "<div class='section-header' data-target='content-raw-data'>";
echo "<h2>Raw Data <span class='section-count count-neutral'>(View Only)</span></h2>";
echo "<button class='section-toggle' aria-expanded='false' aria-controls='content-raw-data' type='button'>";
echo "<span class='toggle-icon'>+</span>";
echo "</button>";
echo "</div>";
echo "<div class='test-grid' id='content-raw-data' style='display: none;'>";

if ($db !== null) {
    // Cart summary
    try {
        $stmt = $db->query(
            "SELECT
                COUNT(*)                             AS total_rows,
                COUNT(DISTINCT customer_id)          AS distinct_customers,
                COUNT(DISTINCT product_id)           AS distinct_products,
                COALESCE(SUM(quantity), 0)           AS total_units,
                COALESCE(SUM(quantity * price), 0)   AS total_value
             FROM cart"
        );
        $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        echo "<h3 style='margin: 10px 0 5px 0; font-size: 16px;'>Cart Summary</h3>";
        echo "<table class='data-table'>";
        echo "<tr><th>Metric</th><th>Value</th><th>Status</th></tr>";

        $rows = [
            'Total cart rows'        => [(int)($summary['total_rows'] ?? 0), 'ok'],
            'Distinct customers'     => [(int)($summary['distinct_customers'] ?? 0), 'ok'],
            'Distinct products'      => [(int)($summary['distinct_products'] ?? 0), 'ok'],
            'Total units in carts'   => [(int)($summary['total_units'] ?? 0), 'ok'],
            'Total cart value'       => ['₱' . number_format((float)($summary['total_value'] ?? 0), 2), 'ok'],
        ];
        foreach ($rows as $label => [$val, $status]) {
            $statusClass = $status === 'ok' ? 'status-ok' : 'status-fail';
            $statusText  = $status === 'ok' ? 'OK' : 'ISSUE';
            echo "<tr>";
            echo "<td>" . htmlspecialchars($label) . "</td>";
            echo "<td>" . htmlspecialchars((string)$val) . "</td>";
            echo "<td class='{$statusClass}'>" . $statusText . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } catch (PDOException $e) {
        echo "<p style='color:#c53030;font-size:13px;'>Summary query failed: "
            . htmlspecialchars($e->getMessage()) . "</p>";
    }

    // Recent cart rows
    try {
        $stmt = $db->query(
            "SELECT c.cart_id, c.customer_id, c.product_id, c.quantity, c.price,
                    c.added_at, p.name AS product_name, p.base_price
             FROM cart c
             JOIN product p ON c.product_id = p.product_id
             ORDER BY c.added_at DESC
             LIMIT 10"
        );
        $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo "<h3 style='margin: 15px 0 5px 0; font-size: 16px;'>Most Recent Cart Rows</h3>";
        if (empty($recent)) {
            echo "<p style='color:#718096;font-size:13px;'>Cart is empty.</p>";
        } else {
            echo "<table class='data-table'>";
            echo "<tr>";
            echo "<th>cart_id</th><th>customer_id</th><th>product_id</th>";
            echo "<th>product</th><th>qty</th><th>price</th><th>base_price</th><th>added_at</th>";
            echo "</tr>";
            foreach ($recent as $r) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars((string)$r['cart_id']) . "</td>";
                echo "<td>" . htmlspecialchars((string)$r['customer_id']) . "</td>";
                echo "<td>" . htmlspecialchars((string)$r['product_id']) . "</td>";
                echo "<td>" . htmlspecialchars((string)$r['product_name']) . "</td>";
                echo "<td>" . htmlspecialchars((string)$r['quantity']) . "</td>";
                echo "<td>₱" . htmlspecialchars(number_format((float)$r['price'], 2)) . "</td>";
                echo "<td>₱" . htmlspecialchars(number_format((float)$r['base_price'], 2)) . "</td>";
                echo "<td>" . htmlspecialchars((string)$r['added_at']) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
    } catch (PDOException $e) {
        echo "<p style='color:#c53030;font-size:13px;'>Recent rows query failed: "
            . htmlspecialchars($e->getMessage()) . "</p>";
    }
} else {
    echo "<p style='color:#c53030;font-size:13px;'>Database connection unavailable.</p>";
}

echo "</div>";
echo "</div>";

// ============================================
// COLLAPSIBLE SECTIONS
// ============================================
echo <<<JS
<script>
(function() {
    'use strict';
    var sectionHeaders = document.querySelectorAll('.section-header');

    sectionHeaders.forEach(function(header) {
        var targetId  = header.getAttribute('data-target');
        var content   = document.getElementById(targetId);
        var toggleBtn = header.querySelector('.section-toggle');
        var icon      = toggleBtn ? toggleBtn.querySelector('.toggle-icon') : null;
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
        var collapsed = header.classList.contains('section-collapsed');
        if (collapsed) {
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
JS;

echo "</div></body></html>";