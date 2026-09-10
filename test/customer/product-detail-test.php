<?php
/**
 * FitPal Product Detail Test
 *
 * Two tiers of tests:
 *   (A) STRUCTURAL — file presence, token checks. Cheap, but weak.
 *   (B) LOGIC — DB-driven invariants that catch the bug classes
 *       that motivated this test suite:
 *         - dietary_information.calories != sum of defaults
 *         - min_quantity > default_quantity (UI cannot render default)
 *         - multiple defaults in one choice group
 *         - PHP not emitting data-* attributes JS reads
 *         - JS reading different attribute names than PHP writes
 *
 * Sections with 0 tests render as NEUTRAL, not as a pass.
 *
 * @package FitPal
 * @version 2.0
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
 * @param string $name
 * @param bool   $result
 * @param string $message
 * @param string $section
 * @param string $tier    'logic' or 'structural'
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
 * Register a section that had no tests (so it renders neutral, not green).
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
    // A single sentinel that is neither pass nor fail — renderSection
    // treats status "SKIP" as neutral and counts it separately.
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

    // A section with only skipped sentinels shows neutral.
    if ($total === 0) {
        $countClass = 'count-neutral';
        $countText  = $skipped > 0 ? 'skipped' : '0';
        $allPassed  = false;
    } elseif ($failed === 0) {
        $countClass = 'count-pass';
        $countText  = "{$passed}/{$total}";
        $allPassed  = true;
    } elseif ($failed <= 2) {
        $countClass = 'count-warning';
        $countText  = "{$passed}/{$total}";
        $allPassed  = false;
    } else {
        $countClass = 'count-fail';
        $countText  = "{$passed}/{$total}";
        $allPassed  = false;
    }

    // Sections with failures default to OPEN so reviewers cannot miss them.
    if ($failed > 0) {
        $defaultCollapsed = false;
    }

    $sectionId = 'section-' . preg_replace('/[^a-zA-Z0-9]/', '-', $sectionName);
    $contentId = 'content-' . preg_replace('/[^a-zA-Z0-9]/', '-', $sectionName);
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
<title>FitPal Product Detail Test</title>
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
    .live-test { padding: 10px 0; width: 100%; }
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
echo "<h1>FitPal Product Detail Test</h1>";
echo "<p>Structural checks plus DB-driven invariants for the product-detail feature.</p>";
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
        $database_host = 'localhost';
        $database_port = 3306;
        $database_name = 'fitpal_food_delivery';
        $database_username = 'root';
        $database_password = '';
        $database_charset = 'utf8mb4';

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
// SECTION A — STRUCTURAL: files + includes
// ============================================
$criticalFiles = [
    'product-detail.php'         => $customerPath . '/pages/product-detail.php',
    'product-detail.css'         => $customerPath . '/assets/css/product-detail.css',
    'product-detail.js'          => $customerPath . '/assets/ui/js/product-detail.js',
    'add-to-cart-handler.php'    => $customerPath . '/backend/handlers/add-to-cart-handler.php',
    'customer header.php'        => $customerPath . '/includes/header.php',
    'shared footer.php'          => $sharedPath   . '/includes/footer.php',
    'database-connect.php'       => $sharedPath   . '/backend/database/database-connect.php',
];
foreach ($criticalFiles as $label => $path) {
    $ok = file_exists($path);
    testResult($label, $ok, $ok ? 'Found' : 'Missing: ' . $path, 'Files', 'structural');
}

// ============================================
// SECTION B — DB SCHEMA
// ============================================
if ($db !== null) {
    // Required tables
    foreach (['product', 'product_composition', 'ingredient', 'dietary_information'] as $tbl) {
        $stmt = $db->query("SHOW TABLES LIKE " . $db->quote($tbl));
        $exists = $stmt->rowCount() > 0;
        testResult("Table exists: {$tbl}", $exists, $exists ? 'Present' : 'Missing', 'Schema', 'structural');
    }

    // Required columns on product_composition
    $requiredCompCols = [
        'composition_id', 'product_id', 'ingredient_id',
        'is_default', 'default_quantity', 'max_quantity',
        'price_modifier', 'display_order',
        'is_required', 'min_quantity', 'max_quantity_per_item',
    ];
    $stmt = $db->query("SHOW COLUMNS FROM product_composition");
    $haveCols = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    foreach ($requiredCompCols as $col) {
        $ok = in_array($col, $haveCols, true);
        testResult("product_composition.{$col}", $ok, $ok ? 'Present' : 'Missing', 'Schema', 'structural');
    }
}

// ============================================
// SECTION C — PHP CONTRACT
// Verify product-detail.php emits the data-* attributes JS reads.
// ============================================
$pdPath = $customerPath . '/pages/product-detail.php';
if (file_exists($pdPath)) {
    $pd = file_get_contents($pdPath);

    // Must NOT still reference the removed $liveQty <= $minQty pattern
    // that produced out-of-range modifier quantities.
    $hasLiveQtyBug = strpos($pd, '$liveQty') !== false
                  && strpos($pd, '$liveQty <= $minQty') !== false;
    testResult(
        'No legacy $liveQty clamp bug',
        !$hasLiveQtyBug,
        $hasLiveQtyBug
            ? 'Found $liveQty <= $minQty — will emit out-of-range modifier start values'
            : 'Clean',
        'PHP Contract'
    );

    // Must emit data-default-quantity on modifier-option
    $hasDefaultQtyAttr = strpos($pd, 'data-default-quantity') !== false;
    testResult(
        'Emits data-default-quantity',
        $hasDefaultQtyAttr,
        $hasDefaultQtyAttr ? 'Found' : 'Missing — JS delta math will be wrong',
        'PHP Contract'
    );

    // Must emit data-is-default on radios/checkboxes
    $hasIsDefault = strpos($pd, 'data-is-default') !== false;
    testResult(
        'Emits data-is-default',
        $hasIsDefault,
        $hasIsDefault ? 'Found' : 'Missing — JS cannot identify default input',
        'PHP Contract'
    );

    // Must emit data-min-qty and data-max-qty on modifiers
    $hasMinQty = strpos($pd, 'data-min-qty') !== false;
    $hasMaxQty = strpos($pd, 'data-max-qty') !== false;
    testResult('Emits data-min-qty', $hasMinQty, $hasMinQty ? 'Found' : 'Missing', 'PHP Contract');
    testResult('Emits data-max-qty', $hasMaxQty, $hasMaxQty ? 'Found' : 'Missing', 'PHP Contract');

    // Must emit data-base-price and data-base-calories on the root container
    $hasBasePriceRoot = strpos($pd, 'data-base-price') !== false;
    $hasBaseCalRoot   = strpos($pd, 'data-base-calories') !== false;
    testResult('Root data-base-price', $hasBasePriceRoot, $hasBasePriceRoot ? 'Found' : 'Missing', 'PHP Contract');
    testResult('Root data-base-calories', $hasBaseCalRoot, $hasBaseCalRoot ? 'Found' : 'Missing', 'PHP Contract');

    // Must emit data-component-kind so JS can dispatch on kind
    $hasComponentKind = strpos($pd, 'data-component-kind') !== false;
    testResult('data-component-kind', $hasComponentKind, $hasComponentKind ? 'Found' : 'Missing', 'PHP Contract');

    // Uses CSRF token
    $hasCsrf = strpos($pd, 'csrf_token') !== false;
    testResult('CSRF token present', $hasCsrf, $hasCsrf ? 'Found' : 'Missing', 'PHP Contract');

    // No second <!DOCTYPE> in the page (page contract violation)
    $doctypeCount = substr_count(strtolower($pd), '<!doctype');
    testResult(
        'No duplicate <!DOCTYPE>',
        $doctypeCount === 0,
        $doctypeCount === 0 ? 'Clean' : "Found {$doctypeCount} — page contract violation",
        'PHP Contract'
    );
}

// ============================================
// SECTION D — JS CONTRACT
// Verify product-detail.js reads the attributes PHP writes.
// ============================================
$pdJsPath = $customerPath . '/assets/ui/js/product-detail.js';
if (file_exists($pdJsPath)) {
    $js = file_get_contents($pdJsPath);

    // Must read data-is-default
    $readsIsDefault = strpos($js, 'data-is-default') !== false
                   || strpos($js, 'isDefault') !== false;
    testResult('JS reads data-is-default', $readsIsDefault, $readsIsDefault ? 'Found' : 'Missing', 'JS Contract');

    // Must read data-default-quantity
    $readsDefaultQty = strpos($js, 'dataset.defaultQuantity') !== false
                    || strpos($js, 'data-default-quantity') !== false;
    testResult('JS reads data-default-quantity', $readsDefaultQty, $readsDefaultQty ? 'Found' : 'Missing', 'JS Contract');

    // Must define calculateTotals (not the old calculateTotalPrice)
    $hasCalculateTotals = strpos($js, 'function calculateTotals') !== false
                       || strpos($js, 'calculateTotals =') !== false;
    testResult('JS defines calculateTotals', $hasCalculateTotals, $hasCalculateTotals ? 'Found' : 'Missing — old calculateTotalPrice?', 'JS Contract');

    // Must compute a delta, not re-add the full default
    $hasDelta = strpos($js, 'currentQty - defaultQty') !== false
             || strpos($js, 'selectedPrice - defaultPrice') !== false
             || strpos($js, 'sp - dp') !== false;
    testResult('JS uses delta math', $hasDelta, $hasDelta ? 'Found' : 'Missing — will double-count defaults', 'JS Contract');

    // Uses strict mode and const/let only
    $hasStrict = strpos($js, "'use strict'") !== false;
    testResult("'use strict'", $hasStrict, $hasStrict ? 'Found' : 'Missing', 'JS Contract');

    $hasVar = preg_match('/\bvar\s+\w/', $js) === 1;
    testResult('No var keyword', !$hasVar, $hasVar ? 'Found var declaration' : 'Clean', 'JS Contract');
}

// ============================================
// SECTION E — DB INVARIANTS
// These are the bugs that actually showed up in the UI.
// ============================================
if ($db !== null) {

    // E1. Every product_composition row must have min <= default <= max_quantity
    $stmt = $db->query(
        "SELECT
            pc.composition_id,
            p.name AS product_name,
            i.name AS ingredient_name,
            pc.min_quantity,
            pc.default_quantity,
            pc.max_quantity,
            pc.max_quantity_per_item,
            pc.is_required
         FROM product_composition pc
         JOIN product p ON p.product_id = pc.product_id
         JOIN ingredient i ON i.ingredient_id = pc.ingredient_id"
    );
    $badRangeRows = [];
    $requiredNonDefaultRows = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $min  = (int)$row['min_quantity'];
        $def  = (int)$row['default_quantity'];
        $max  = (int)$row['max_quantity'];
        $maxPer = (int)$row['max_quantity_per_item'];
        $req  = (bool)$row['is_required'];

        // Range on the quantity stepper
        if ($min > $def || $def > $max) {
            $badRangeRows[] = sprintf(
                '%s / %s: min=%d default=%d max=%d',
                $row['product_name'], $row['ingredient_name'],
                $min, $def, $max
            );
        }

        // For required modifiers with min >= 1, default must equal min
        if ($maxPer > 1 && $req && $min >= 1 && $def !== $min) {
            $requiredNonDefaultRows[] = sprintf(
                '%s / %s: required with min=%d but default=%d',
                $row['product_name'], $row['ingredient_name'], $min, $def
            );
        }
    }
    testResult(
        'min_quantity <= default_quantity <= max_quantity',
        empty($badRangeRows),
        empty($badRangeRows)
            ? 'All ' . $stmt->rowCount() . ' rows satisfy the range'
            : count($badRangeRows) . ' row(s) violate: ' . implode(' | ', array_slice($badRangeRows, 0, 3)),
        'DB Invariants'
    );
    testResult(
        'Required modifiers default to their minimum',
        empty($requiredNonDefaultRows),
        empty($requiredNonDefaultRows)
            ? 'Consistent'
            : implode(' | ', array_slice($requiredNonDefaultRows, 0, 3)),
        'DB Invariants'
    );

    // E2. Every choice group (same product + display_order, max_quantity_per_item=1)
    //     must have at most one is_default=1 row.
    $stmt = $db->query(
        "SELECT
            pc.product_id,
            pc.display_order,
            p.name AS product_name,
            SUM(CASE WHEN pc.is_default = 1 THEN 1 ELSE 0 END) AS default_count,
            COUNT(*) AS group_size
         FROM product_composition pc
         JOIN product p ON p.product_id = pc.product_id
         WHERE pc.max_quantity_per_item = 1
         GROUP BY pc.product_id, pc.display_order, p.name
         HAVING default_count > 1"
    );
    $multiDefaultGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    testResult(
        'At most one default per choice group',
        empty($multiDefaultGroups),
        empty($multiDefaultGroups)
            ? 'All choice groups have <= 1 default'
            : count($multiDefaultGroups) . ' group(s) with multiple defaults: '
              . implode(', ', array_map(fn($r) => "{$r['product_name']}#{$r['display_order']} ({$r['default_count']})", array_slice($multiDefaultGroups, 0, 3))),
        'DB Invariants'
    );

    // E3. For every customizable product:
    //       dietary_information.calories == SUM(default ingredient calories)
    //     This is the invariant that was broken for Vegetable Stir Fry.
    $stmt = $db->query(
        "SELECT
            p.product_id,
            p.name AS product_name,
            di.calories AS stored_calories,
            COALESCE((
                SELECT SUM(i.calories * GREATEST(pc.default_quantity, pc.min_quantity))
                FROM product_composition pc
                JOIN ingredient i ON i.ingredient_id = pc.ingredient_id
                WHERE pc.product_id = p.product_id AND pc.is_default = 1
            ), 0) AS derived_calories
         FROM product p
         JOIN dietary_information di ON di.dietary_information_id = p.dietary_information_id
         WHERE p.is_customizable = 1"
    );
    $calorieMismatches = [];
    $calorieRows = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $calorieRows++;
        $stored  = (int)$row['stored_calories'];
        $derived = (int)$row['derived_calories'];
        if ($stored !== $derived) {
            $calorieMismatches[] = sprintf(
                '%s: stored=%d derived=%d (diff %+d)',
                $row['product_name'], $stored, $derived, $stored - $derived
            );
        }
    }
    testResult(
        'dietary_information.calories == defaults sum',
        empty($calorieMismatches),
        empty($calorieMismatches)
            ? "All {$calorieRows} customizable product(s) agree"
            : count($calorieMismatches) . ' product(s) disagree: ' . implode(' | ', array_slice($calorieMismatches, 0, 3)),
        'DB Invariants'
    );

    // E4. Every customizable product has at least one default ingredient.
    $stmt = $db->query(
        "SELECT p.product_id, p.name
         FROM product p
         WHERE p.is_customizable = 1
           AND NOT EXISTS (
               SELECT 1 FROM product_composition pc
               WHERE pc.product_id = p.product_id AND pc.is_default = 1
           )"
    );
    $noDefaultProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    testResult(
        'Every customizable product has a default',
        empty($noDefaultProducts),
        empty($noDefaultProducts)
            ? 'All customizable products have at least one default'
            : count($noDefaultProducts) . ' product(s) missing defaults: '
              . implode(', ', array_column(array_slice($noDefaultProducts, 0, 3), 'name')),
        'DB Invariants'
    );

    // E5. base_price must be > 0 for every active product.
    $stmt = $db->query(
        "SELECT p.product_id, p.name, p.price, p.base_price
         FROM product p
         WHERE p.is_active = 1 AND (p.base_price IS NULL OR p.base_price <= 0)"
    );
    $badPrice = $stmt->fetchAll(PDO::FETCH_ASSOC);
    testResult(
        'All active products have base_price > 0',
        empty($badPrice),
        empty($badPrice)
            ? 'All active products priced'
            : count($badPrice) . ' with base_price <= 0: '
              . implode(', ', array_map(fn($r) => "{$r['name']}(price={$r['price']},base={$r['base_price']})", array_slice($badPrice, 0, 3))),
        'DB Invariants'
    );

    // E6. No product with a "Spinach-style" trap:
    //       is_required=0, min_quantity > 0, default_quantity < min_quantity
    //     Already covered by E1's range check, but the specific combination
    //     is worth calling out because it produced the UI symptom directly.
    $stmt = $db->query(
        "SELECT p.name AS product_name, i.name AS ingredient_name,
                pc.min_quantity, pc.default_quantity
         FROM product_composition pc
         JOIN product p ON p.product_id = pc.product_id
         JOIN ingredient i ON i.ingredient_id = pc.ingredient_id
         WHERE pc.is_required = 0
           AND pc.min_quantity > 0
           AND pc.default_quantity < pc.min_quantity"
    );
    $spinachTraps = $stmt->fetchAll(PDO::FETCH_ASSOC);
    testResult(
        'No optional modifier requires > default',
        empty($spinachTraps),
        empty($spinachTraps)
            ? 'Consistent'
            : count($spinachTraps) . ' trap(s): '
              . implode(' | ', array_map(fn($r) => "{$r['product_name']}/{$r['ingredient_name']} min={$r['min_quantity']} def={$r['default_quantity']}", array_slice($spinachTraps, 0, 3))),
        'DB Invariants'
    );
}

// ============================================
// SECTION F — PER-PRODUCT DEEP CHECK
// Renders product-detail.php's source and asserts that each product's
// defaults map to a numeric calorie value that will be the same in all
// three places (card, main step, customize step).
// ============================================
if ($db !== null) {
    $stmt = $db->query(
        "SELECT p.product_id, p.name
         FROM product p
         WHERE p.is_active = 1 AND p.is_customizable = 1
         ORDER BY p.product_id"
    );
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($products as $prod) {
        $pid = (int)$prod['product_id'];

        // Query composition + ingredient the same way PHP does.
        $cStmt = $db->prepare(
            "SELECT
                pc.ingredient_id, pc.is_default, pc.default_quantity,
                pc.min_quantity, pc.max_quantity, pc.max_quantity_per_item,
                pc.is_required, pc.display_order,
                i.name AS ingredient_name, i.calories AS ingredient_calories
             FROM product_composition pc
             JOIN ingredient i ON i.ingredient_id = pc.ingredient_id
             WHERE pc.product_id = :pid AND i.is_active = 1
             ORDER BY pc.display_order, i.name"
        );
        $cStmt->execute([':pid' => $pid]);
        $rows = $cStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            testResult(
                "#{$pid} {$prod['name']}",
                false,
                'Customizable product has no active composition rows',
                'Per-Product Check'
            );
            continue;
        }

        // Group by display_order, classify, and sum defaults — the
        // same algorithm product-detail.php uses.
        $byOrder = [];
        foreach ($rows as $r) {
            $byOrder[(int)$r['display_order']][] = $r;
        }

        $derivedCalories = 0;
        $problems = [];

        foreach ($byOrder as $order => $group) {
            $count        = count($group);
            $groupMaxPer  = 1;
            $groupReq     = false;
            $defaultCount = 0;

            foreach ($group as $r) {
                $groupMaxPer = max($groupMaxPer, (int)$r['max_quantity_per_item']);
                $groupReq    = $groupReq || (bool)$r['is_required'];
                $defaultCount += (int)$r['is_default'];
            }

            $firstMin = (int)$group[0]['min_quantity'];

            if ($groupMaxPer === 1) {
                if ($count === 1 && $groupReq && $firstMin === 1) {
                    // static
                    $qty = max((int)$group[0]['default_quantity'], (int)$group[0]['min_quantity'], 1);
                    $derivedCalories += (int)$group[0]['ingredient_calories'] * $qty;
                } else {
                    // choice
                    $chosen = null;
                    foreach ($group as $r) {
                        if ((int)$r['is_default'] === 1) { $chosen = $r; break; }
                    }
                    if ($chosen !== null) {
                        $derivedCalories += (int)$chosen['ingredient_calories'];
                    }
                }
            } elseif ($count === 1) {
                // modifier
                $qty = max((int)$group[0]['default_quantity'], (int)$group[0]['min_quantity']);
                $derivedCalories += (int)$group[0]['ingredient_calories'] * $qty;
            } else {
                // multi
                foreach ($group as $r) {
                    if ((int)$r['is_default'] === 1) {
                        $qty = max((int)$r['default_quantity'], (int)$r['min_quantity']);
                        $derivedCalories += (int)$r['ingredient_calories'] * $qty;
                    }
                }
            }

            if ($groupMaxPer === 1 && $defaultCount > 1) {
                $problems[] = "order={$order} has {$defaultCount} defaults";
            }
        }

        $sStmt = $db->prepare(
            "SELECT di.calories
             FROM product p
             JOIN dietary_information di ON di.dietary_information_id = p.dietary_information_id
             WHERE p.product_id = :pid"
        );
        $sStmt->execute([':pid' => $pid]);
        $stored = (int)($sStmt->fetchColumn() ?: 0);

        $ok = ($stored === $derivedCalories) && empty($problems);
        $msg = $ok
            ? "stored={$stored} kcal matches defaults sum"
            : "stored={$stored} vs derived={$derivedCalories}"
              . (empty($problems) ? '' : ' | ' . implode('; ', $problems));

        testResult("#{$pid} {$prod['name']}", $ok, $msg, 'Per-Product Check');
    }
}

// ============================================
// SECTION G — HANDLER CONTRACT
// The add-to-cart handler must accept what product-detail.php submits.
// ============================================
$handlerPath = $customerPath . '/backend/handlers/add-to-cart-handler.php';
if (file_exists($handlerPath)) {
    $h = file_get_contents($handlerPath);

    testResult(
        'Handler calls session_start',
        strpos($h, 'session_start') !== false,
        'Presence check',
        'Handler Contract'
    );
    testResult(
        'Handler validates CSRF',
        strpos($h, 'csrf_token') !== false,
        'Presence check',
        'Handler Contract'
    );
    testResult(
        'Handler reads customizations payload',
        strpos($h, 'customizations') !== false,
        'Presence check',
        'Handler Contract'
    );
    testResult(
        'Handler stores customization_data JSON',
        strpos($h, 'customization_data') !== false,
        'Presence check',
        'Handler Contract'
    );

    // Must not trust the client total_price without a fallback
    $trustsClient = strpos($h, "\$_POST['total_price']") !== false
                 && strpos($h, 'base_price') === false;
    testResult(
        'Handler does not blindly trust client price',
        !$trustsClient,
        $trustsClient
            ? 'Only reads $_POST[\'total_price\'] without server-side base_price fallback'
            : 'Server-side price logic present',
        'Handler Contract'
    );
}

// ============================================
// SECTION H — LIVE LINKS (discovered from DB)
// ============================================
$liveProducts = [];
if ($db !== null) {
    $stmt = $db->query(
        "SELECT p.product_id, p.name
         FROM product p
         WHERE p.is_active = 1
         ORDER BY p.is_customizable DESC, p.product_id
         LIMIT 5"
    );
    $liveProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
echo "<p><strong>Open product detail for products discovered in the DB:</strong></p>";
echo "<div class='btn-test-group'>";
if (!empty($liveProducts)) {
    foreach ($liveProducts as $lp) {
        $pid  = (int)$lp['product_id'];
        $name = htmlspecialchars($lp['name']);
        echo "<a href='" . htmlspecialchars($baseUrl) . "/customer/pages/product-detail.php?id={$pid}' target='_blank' class='btn-test'>#{$pid} {$name}</a>";
    }
} else {
    echo "<span style='color:#a0aec0;font-size:13px;'>No active products found in DB.</span>";
}
echo "</div>";
echo "<div class='btn-test-group' style='margin-top:15px;'>";
echo "<a href='" . htmlspecialchars($baseUrl) . "/customer/pages/menu.php' target='_blank' class='btn-test warning'>Menu</a>";
echo "<a href='" . htmlspecialchars($baseUrl) . "/customer/pages/dashboard.php' target='_blank' class='btn-test success'>Dashboard</a>";
echo "</div>";

echo "<div class='note-box'>";
echo "<p>What to verify manually on each product page:</p>";
echo "<ul>";
echo "<li>Main step: the kcal badge and total match the menu card</li>";
echo "<li>Customize step opens with the total unchanged</li>";
echo "<li>Every modifier stepper starts at a value between its min and max</li>";
echo "<li>Every required modifier's minus button is disabled at its minimum</li>";
echo "<li>Adding an optional ingredient raises both price and calories by the stated amount</li>";
echo "<li>Picking a different base/protein produces a total consistent with the option's stated delta</li>";
echo "</ul>";
echo "</div>";

echo "</div>";
echo "</div>";
echo "</div>";
echo "</div>";

// ============================================
// RENDER SECTIONS
// ============================================
renderSection('Database Tests',      'Database',         true);
renderSection('File Tests',          'Files',            true);
renderSection('Schema Tests',        'Schema',           true);
renderSection('PHP Contract Tests',  'PHP Contract',     false);
renderSection('JS Contract Tests',   'JS Contract',      false);
renderSection('DB Invariant Tests',  'DB Invariants',    false);
renderSection('Per-Product Check',   'Per-Product Check', false);
renderSection('Handler Contract',    'Handler Contract', true);

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