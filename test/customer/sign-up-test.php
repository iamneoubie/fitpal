<?php
/**
 * FitPal Customer Sign-Up System Test
 *
 * Structural test of the customer multi-step registration flow.
 *
 * @package FitPal
 * @version 3.2
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$basePath     = realpath(__DIR__ . '/../..');
$customerPath = $basePath . '/customer';
$sharedPath   = $basePath . '/shared';

$passed   = 0;
$failed   = 0;
$sections = [];

function testResult(string $name, bool $result, string $message = '', string $section = 'General'): bool
{
    global $sections, $passed, $failed;
    $status = $result ? 'PASS' : 'FAIL';
    if ($result) $passed++; else $failed++;

    if (!isset($sections[$section])) {
        $sections[$section] = [];
    }
    $sections[$section][] = [
        'name'    => $name,
        'status'  => $status,
        'message' => $message,
        'passed'  => $result,
    ];
    return $result;
}

function stripPhpComments(string $source): string
{
    $source = preg_replace('#/\*.*?\*/#s', '', $source) ?? $source;
    $source = preg_replace('#^\s*//.*$#m', '', $source) ?? $source;
    return $source;
}

function stripJsComments(string $source): string
{
    $source = preg_replace('#/\*.*?\*/#s', '', $source) ?? $source;
    $source = preg_replace('#^\s*//.*$#m', '', $source) ?? $source;
    return $source;
}

function extractInputNames(string $html): array
{
    $names = [];
    if (preg_match_all('/\bname=["\']([^"\']+)["\']/i', $html, $m)) {
        $names = array_values(array_unique($m[1]));
    }
    return $names;
}

function fileDefinesFunction(string $source, string $fnName): bool
{
    $stripped = stripPhpComments($source);
    return (bool)preg_match(
        '/\bfunction\s+' . preg_quote($fnName, '/') . '\s*\(/',
        $stripped
    );
}

function fileCallsFunction(string $source, string $fnName): bool
{
    $stripped = stripPhpComments($source);
    return (bool)preg_match(
        '/\b' . preg_quote($fnName, '/') . '\s*\(/',
        $stripped
    );
}

function renderSection(string $title, string $sectionName, bool $defaultCollapsed = true): void
{
    global $sections;

    $totalTests  = isset($sections[$sectionName]) ? count($sections[$sectionName]) : 0;
    $passedTests = 0;
    if (isset($sections[$sectionName])) {
        foreach ($sections[$sectionName] as $test) {
            if ($test['passed']) $passedTests++;
        }
    }

    $failedTests = $totalTests - $passedTests;
    $allPassed   = ($totalTests > 0 && $failedTests === 0);
    $hasFailures = ($failedTests > 0);

    $sectionId = 'section-' . preg_replace('/[^a-zA-Z0-9]/', '-', $sectionName);
    $contentId = 'content-' . preg_replace('/[^a-zA-Z0-9]/', '-', $sectionName);

    $collapsedClass = $defaultCollapsed ? 'section-collapsed' : '';

    echo '<div class="test-section ' . $collapsedClass . '" id="' . $sectionId . '">';
    echo '<div class="section-header" data-target="' . $contentId . '">';

    $countClass = 'count-neutral';
    if ($allPassed) {
        $countClass = 'count-pass';
    } elseif ($hasFailures && $failedTests <= 2) {
        $countClass = 'count-warning';
    } elseif ($hasFailures && $failedTests > 2) {
        $countClass = 'count-fail';
    }

    echo '<h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
       . ' <span class="section-count ' . $countClass . '">('
       . $passedTests . '/' . $totalTests
       . ')</span></h2>';

    echo '<button class="section-toggle" aria-expanded="'
       . ($defaultCollapsed ? 'false' : 'true')
       . '" aria-controls="' . $contentId . '" type="button">';
    echo '<span class="toggle-icon">' . ($defaultCollapsed ? '+' : '-') . '</span>';
    echo '</button>';
    echo '</div>';
    echo '<div class="test-grid" id="' . $contentId . '" style="display: '
       . ($defaultCollapsed ? 'none' : 'grid') . ';">';

    if (isset($sections[$sectionName]) && !empty($sections[$sectionName])) {
        foreach ($sections[$sectionName] as $test) {
            $statusClass = $test['passed'] ? 'pass' : 'fail';
            $statusText  = $test['passed'] ? 'PASS' : 'FAIL';

            echo '<div class="test-item ' . $statusClass . '">';
            echo '<div class="test-name">' . htmlspecialchars($test['name'], ENT_QUOTES, 'UTF-8') . '</div>';
            echo '<div class="test-status ' . $statusClass . '">' . $statusText . '</div>';
            echo '<div class="test-message">' . htmlspecialchars($test['message'], ENT_QUOTES, 'UTF-8') . '</div>';
            echo '</div>';
        }
    }

    echo '</div>';
    echo '</div>';
}

function renderLiveFormSection(string $baseUrl, bool $defaultCollapsed = true): void
{
    $contentId = 'content-live-form';

    echo '<div class="test-section ' . ($defaultCollapsed ? 'section-collapsed' : '') . '" id="section-live-form">';
    echo '<div class="section-header" data-target="' . $contentId . '">';
    echo '<h2>Live Form <span class="section-count count-neutral">(0/0)</span></h2>';
    echo '<button class="section-toggle" aria-expanded="'
       . ($defaultCollapsed ? 'false' : 'true')
       . '" aria-controls="' . $contentId . '" type="button">';
    echo '<span class="toggle-icon">' . ($defaultCollapsed ? '+' : '-') . '</span>';
    echo '</button>';
    echo '</div>';
    echo '<div class="test-grid" id="' . $contentId . '" style="display: '
       . ($defaultCollapsed ? 'none' : 'grid') . ';">';

    echo '<div class="live-test">';
    echo '<p><strong>Test the sign-up form:</strong></p>';
    echo '<p style="font-size:13px;color:#718096;margin:5px 0;">URL: '
       . htmlspecialchars($baseUrl . '/customer/pages/sign-up.php', ENT_QUOTES, 'UTF-8')
       . '</p>';
    echo '<iframe src="' . htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') . '/customer/pages/sign-up.php"></iframe>';
    echo '<div class="btn-test-group">';
    echo '<a href="' . htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8')
       . '/customer/pages/sign-up.php" target="_blank" class="btn-test">Open Sign-Up</a>';
    echo '<a href="' . htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8')
       . '/customer/pages/sign-in.php" target="_blank" class="btn-test">Open Sign-In</a>';
    echo '</div>';
    echo '</div>';

    echo '</div>';
    echo '</div>';
}

function getAssetBase(): string
{
    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
    $dirPath    = dirname($scriptPath);
    $segments   = array_filter(explode('/', $dirPath));
    $depth      = count($segments);
    if ($depth <= 0) return './shared/';
    return str_repeat('../', $depth) . 'shared/';
}

// ------------------------------------------------------------------
// RUN THE TESTS
// ------------------------------------------------------------------

$protocol  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl   = $protocol . '://' . $host;
$assetBase = getAssetBase();

// ---------------------------------------------------------------
// DATABASE
// ---------------------------------------------------------------
$dbFile = $sharedPath . '/backend/database/database-connect.php';

if (file_exists($dbFile)) {
    testResult('Database File Exists', true, 'File found', 'Database');

    $content  = file_get_contents($dbFile);
    $stripped = stripPhpComments($content);

    testResult(
        'Database Uses PDO',
        (bool)preg_match('/new\s+PDO\s*\(/', $stripped),
        preg_match('/new\s+PDO\s*\(/', $stripped) ? 'new PDO() found' : 'No PDO instantiation',
        'Database'
    );

    testResult(
        'Database Assigns $database_connection',
        (bool)preg_match('/\$database_connection\s*=\s*new\s+PDO/', $stripped),
        'Assignment found',
        'Database'
    );

    try {
        $dsn = 'mysql:host=localhost;port=3306;dbname=fitpal_food_delivery;charset=utf8mb4';
        $test_connection = new PDO($dsn, 'root', '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        testResult('Live Database Connection', true, 'PDO connection established', 'Database');

        $tables = ['customer', 'customer_profile', 'financial_account'];
        foreach ($tables as $t) {
            $stmt   = $test_connection->query('SHOW TABLES LIKE ' . $test_connection->quote($t));
            $exists = $stmt->rowCount() > 0;
            testResult("Table: {$t}", $exists, $exists ? 'Exists' : 'Missing', 'Database');
        }
    } catch (PDOException $e) {
        testResult('Live Database Connection', false, 'PDO Error: ' . $e->getMessage(), 'Database');
    }
} else {
    testResult('Database File Exists', false, 'File not found: ' . $dbFile, 'Database');
}

// ---------------------------------------------------------------
// SESSION
// ---------------------------------------------------------------
$sessionActive = session_status() === PHP_SESSION_ACTIVE;
testResult('Session Active', $sessionActive, $sessionActive ? 'Active' : 'Inactive', 'Session');

if ($sessionActive) {
    testResult('Session ID', session_id() !== '', 'Session ID exists', 'Session');

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    testResult(
        'CSRF Token',
        !empty($_SESSION['csrf_token']) && strlen($_SESSION['csrf_token']) === 64,
        'Token is 64 hex characters',
        'Session'
    );
}

// ---------------------------------------------------------------
// CORE FILES
// ---------------------------------------------------------------
$criticalFiles = [
    'Sign-Up Page'      => $customerPath . '/pages/sign-up.php',
    'Sign-Up Handler'   => $customerPath . '/backend/handlers/sign-up-handler.php',
    'Sign-Up JS'        => $customerPath . '/assets/ui/js/sign-up.js',
    'Sign-Up CSS'       => $customerPath . '/assets/css/sign-up.css',
    'Customer Header'   => $customerPath . '/includes/header.php',
    'Sign-In Handler'   => $customerPath . '/backend/handlers/sign-in-handler.php',
    'Shared Header'     => $sharedPath . '/includes/header.php',
    'Shared Footer'     => $sharedPath . '/includes/footer.php',
    'Database Connect'  => $sharedPath . '/backend/database/database-connect.php',
];

foreach ($criticalFiles as $name => $path) {
    $exists = file_exists($path);
    testResult($name, $exists, $exists ? 'Found' : 'Missing', 'Core Files');
}

// ---------------------------------------------------------------
// SIGN-UP PAGE STRUCTURE
// ---------------------------------------------------------------
$signUpPage = $customerPath . '/pages/sign-up.php';

if (file_exists($signUpPage)) {
    $pageContent = file_get_contents($signUpPage);

    $formBlock = null;
    if (preg_match(
        '/<form[^>]*\bid=["\']registerForm["\'][^>]*>(.*?)<\/form>/is',
        $pageContent,
        $formMatch
    )) {
        $formBlock = $formMatch[1];
    }

    testResult(
        'Form (registerForm) Present',
        $formBlock !== null,
        $formBlock !== null ? 'Form element found' : 'Form element missing',
        'Page Structure'
    );

    if ($formBlock !== null) {
        $hasCsrf = (bool)preg_match(
            '/<input[^>]*\bname=["\']csrf_token["\'][^>]*>/i',
            $formBlock
        );
        testResult(
            'CSRF Token Field Inside Form',
            $hasCsrf,
            $hasCsrf ? 'Present inside form' : 'Missing from form',
            'Page Structure'
        );

        $hasCorrectAction = (bool)preg_match(
            '/action=["\'][^"\']*sign-up-handler\.php["\']/i',
            $pageContent
        );
        testResult(
            'Form Action → sign-up-handler.php',
            $hasCorrectAction,
            $hasCorrectAction ? 'Correct' : 'Wrong or missing action',
            'Page Structure'
        );

        $expectedFields = [
            'first_name', 'last_name', 'birthdate', 'gender',
            'email', 'contact_number', 'username', 'password',
            'confirm_password', 'terms',
        ];

        $presentNames = extractInputNames($formBlock);
        $missing = [];
        foreach ($expectedFields as $field) {
            if (!in_array($field, $presentNames, true)) {
                $missing[] = $field;
            }
        }
        testResult(
            'Required Fields Inside Form',
            empty($missing),
            empty($missing) ? 'All present' : 'Missing: ' . implode(', ', $missing),
            'Page Structure'
        );

        preg_match_all(
            '/class=["\'][^"\']*\bregister-step\b[^"\']*["\']/i',
            $pageContent,
            $stepMatches
        );
        $stepCount = count($stepMatches[0] ?? []);
        testResult(
            'Four Register Steps',
            $stepCount === 4,
            "Found {$stepCount} steps",
            'Page Structure'
        );

        $stepIdsFound = [];
        for ($i = 1; $i <= 4; $i++) {
            if (preg_match('/\bid=["\']step' . $i . '["\']/i', $pageContent)) {
                $stepIdsFound[] = $i;
            }
        }
        testResult(
            'Step IDs step1..step4',
            count($stepIdsFound) === 4,
            count($stepIdsFound) === 4
                ? 'All present'
                : 'Missing: ' . implode(',', array_diff([1, 2, 3, 4], $stepIdsFound)),
            'Page Structure'
        );
    }

    testResult(
        'Dietary Options Container',
        (bool)preg_match('/\bid=["\']dietaryOptions["\']/i', $pageContent),
        preg_match('/\bid=["\']dietaryOptions["\']/i', $pageContent) ? 'Found' : 'Missing',
        'Page Structure'
    );

    testResult(
        'Allergy Options Container',
        (bool)preg_match('/\bid=["\']allergyOptions["\']/i', $pageContent),
        preg_match('/\bid=["\']allergyOptions["\']/i', $pageContent) ? 'Found' : 'Missing',
        'Page Structure'
    );

    testResult(
        'Fitness Goal Select',
        (bool)preg_match('/<select[^>]*\bid=["\']fitness_goal["\']/i', $pageContent),
        preg_match('/<select[^>]*\bid=["\']fitness_goal["\']/i', $pageContent) ? 'Found' : 'Missing',
        'Page Structure'
    );

    testResult(
        'Notifier Modal',
        (bool)preg_match('/\bid=["\']notifierModal["\']/i', $pageContent),
        preg_match('/\bid=["\']notifierModal["\']/i', $pageContent) ? 'Found' : 'Missing',
        'Page Structure'
    );

    testResult(
        'sign-up.js Loaded',
        (bool)preg_match('/<script[^>]*src=["\'][^"\']*sign-up\.js["\']/i', $pageContent),
        'Script tag present',
        'Page Structure'
    );


} else {
    testResult('Sign-Up Page', false, 'File not found', 'Page Structure');
}

// ---------------------------------------------------------------
// SIGN-UP HANDLER
// ---------------------------------------------------------------
$handlerPath = $customerPath . '/backend/handlers/sign-up-handler.php';

if (file_exists($handlerPath)) {
    $handlerContent  = file_get_contents($handlerPath);
    $handlerStripped = stripPhpComments($handlerContent);

    testResult(
        'session_start() Called',
        (bool)preg_match('/\bsession_start\s*\(\s*\)/', $handlerStripped),
        preg_match('/\bsession_start\s*\(\s*\)/', $handlerStripped) ? 'Found' : 'Missing',
        'Handler'
    );

    testResult(
        'Database Required',
        (bool)preg_match('/require_once[^;]*database-connect\.php/', $handlerStripped),
        'require_once database-connect.php found',
        'Handler'
    );

    testResult(
        'customer-queries.php Required',
        (bool)preg_match('/require_once[^;]*customer-queries\.php/', $handlerStripped),
        'Query layer required',
        'Handler'
    );

    $requiredCalls = [
        'emailExists'            => 'calls emailExists()',
        'usernameExists'         => 'calls usernameExists()',
        'contactExists'          => 'calls contactExists()',
        'createFinancialAccount' => 'calls createFinancialAccount()',
        'createCustomer'         => 'calls createCustomer()',
        'createCustomerProfile'  => 'calls createCustomerProfile()',
    ];

    foreach ($requiredCalls as $fn => $desc) {
        testResult(
            "Handler Calls {$fn}()",
            fileCallsFunction($handlerStripped, $fn),
            $desc,
            'Handler'
        );
    }

    testResult(
        'Defines respondError()',
        fileDefinesFunction($handlerStripped, 'respondError'),
        'respondError helper defined',
        'Handler'
    );

    testResult(
        'Uses beginTransaction',
        (bool)preg_match('/->beginTransaction\s*\(/', $handlerStripped),
        'Transaction started',
        'Handler'
    );

    testResult(
        'Uses commit',
        (bool)preg_match('/->commit\s*\(/', $handlerStripped),
        'Transaction committed',
        'Handler'
    );

    testResult(
        'Uses rollBack',
        (bool)preg_match('/->rollBack\s*\(/', $handlerStripped),
        'Rollback on error',
        'Handler'
    );

    testResult(
        'Uses password_hash',
        (bool)preg_match('/\bpassword_hash\s*\(/', $handlerStripped),
        'Password hashed',
        'Handler'
    );

    testResult(
        'Uses FILTER_VALIDATE_EMAIL',
        (bool)preg_match('/FILTER_VALIDATE_EMAIL/', $handlerStripped),
        'Email validated',
        'Handler'
    );

    // Accept either a PHP header redirect or a JSON redirect key.
    $hasHeaderRedirect = (bool)preg_match(
        '/header\s*\(\s*[\'"]Location:\s*[^\'"]*sign-in\.php[\'"]\s*\)/',
        $handlerStripped
    );
    $hasJsonRedirect = (bool)preg_match(
        '/[\'"]redirect[\'"]\s*=>\s*[\'"]sign-in\.php[\'"]/',
        $handlerStripped
    );

    testResult(
        'Redirects to sign-in.php',
        $hasHeaderRedirect || $hasJsonRedirect,
        $hasHeaderRedirect
            ? 'Location header present'
            : ($hasJsonRedirect ? 'JSON redirect key present' : 'No redirect target found'),
        'Handler'
    );

    testResult(
        'Returns JSON Response',
        (bool)preg_match('/header\s*\(\s*[\'"]Content-Type:\s*application\/json/i', $handlerStripped),
        'JSON Content-Type set',
        'Handler'
    );
} else {
    testResult('Handler', false, 'File not found', 'Handler');
}

// ---------------------------------------------------------------
// SIGN-UP JAVASCRIPT
// ---------------------------------------------------------------
$jsPath = $customerPath . '/assets/ui/js/sign-up.js';

if (file_exists($jsPath)) {
    $jsContent  = file_get_contents($jsPath);
    $jsStripped = stripJsComments($jsContent);

    testResult(
        'DOMContentLoaded Bound',
        (bool)preg_match('/addEventListener\s*\(\s*[\'"]DOMContentLoaded[\'"]/', $jsStripped),
        'DOMContentLoaded listener registered',
        'JavaScript'
    );

    $expectedFunctions = ['goToStep', 'validateStep', 'validateStep4', 'showFieldError', 'showNotification'];
    foreach ($expectedFunctions as $fn) {
        testResult(
            "Defines {$fn}()",
            (bool)preg_match('/function\s+' . preg_quote($fn, '/') . '\s*\(/', $jsStripped),
            "function {$fn}() defined",
            'JavaScript'
        );
    }

    testResult(
        'Password Toggle Bound',
        (bool)preg_match('/togglePassword/', $jsStripped)
        && (bool)preg_match('/addEventListener\s*\(\s*[\'"]click[\'"]/', $jsStripped),
        'Click listener on toggle present',
        'JavaScript'
    );

    testResult(
        'Submit Listener Registered',
        (bool)preg_match('/addEventListener\s*\(\s*[\'"]submit[\'"]/', $jsStripped),
        'submit listener present',
        'JavaScript'
    );

    testResult(
        'Uses fetch()',
        (bool)preg_match('/\bfetch\s*\(/', $jsStripped),
        'fetch() call present',
        'JavaScript'
    );

    testResult(
        'Uses FormData',
        (bool)preg_match('/new\s+FormData\s*\(/', $jsStripped),
        'FormData instantiated',
        'JavaScript'
    );

    testResult(
        'Option Cards Setup',
        (bool)preg_match('/option-card/', $jsStripped)
        && (bool)preg_match('/dietaryOptions|allergyOptions/', $jsStripped),
        'Option card containers wired',
        'JavaScript'
    );

    testResult(
        'Notifier Modal Reference',
        (bool)preg_match('/notifierModal/', $jsStripped),
        'Modal id referenced',
        'JavaScript'
    );

    testResult(
        'Fetch to form.action',
        (bool)preg_match('/fetch\s*\(\s*form\.action/', $jsStripped),
        'Submits to form.action',
        'JavaScript'
    );
} else {
    testResult('sign-up.js', false, 'File not found', 'JavaScript');
}

// ---------------------------------------------------------------
// SIGN-UP CSS
// ---------------------------------------------------------------
$cssPath = $customerPath . '/assets/css/sign-up.css';

if (file_exists($cssPath)) {
    $cssContent = file_get_contents($cssPath);

    $expectedSelectors = [
        'register-step',
        'register-progress',
        'option-card',
        'form-error',
        '.error',
        '@media',
    ];

    foreach ($expectedSelectors as $selector) {
        testResult(
            "Selector: {$selector}",
            strpos($cssContent, $selector) !== false,
            strpos($cssContent, $selector) !== false ? 'Found' : 'Missing',
            'CSS'
        );
    }
} else {
    testResult('sign-up.css', false, 'File not found', 'CSS');
}

// ------------------------------------------------------------------
// CALCULATE TOTALS
// ------------------------------------------------------------------
$totalTests  = 0;
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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FitPal Sign-Up System Test</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>assets/css/global.css">
    <style>
    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }

    html,
    body {
        width: 100%;
        overflow-x: hidden;
    }

    body {
        background: #f4f6f9;
        padding: 20px;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }

    .test-container {
        max-width: 100%;
        padding: 0;
        margin: 0;
    }

    .test-header {
        background: linear-gradient(135deg, #2d3748, #1a202c);
        color: white;
        padding: 30px;
        border-radius: 12px;
        margin-bottom: 30px;
        width: 100%;
    }

    .test-header h1 {
        margin: 0;
        font-size: 28px;
    }

    .test-header p {
        margin: 10px 0 0 0;
        opacity: 0.8;
    }

    .test-summary {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 15px;
        margin-bottom: 20px;
        width: 100%;
    }

    .summary-card {
        background: white;
        padding: 20px;
        border-radius: 10px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        text-align: center;
    }

    .summary-card .number {
        font-size: 32px;
        font-weight: 700;
    }

    .summary-card .label {
        color: #718096;
        font-size: 14px;
        margin-top: 5px;
    }

    .summary-card.total .number {
        color: #2d3748;
    }

    .summary-card.passed .number {
        color: #48bb78;
    }

    .summary-card.failed .number {
        color: #fc8181;
    }

    .summary-card.rate .number {
        color: #4299e1;
    }

    .test-grid {
        display: grid;
        gap: 10px;
        padding: 15px;
        width: 100%;
    }

    .test-item {
        background: white;
        border-radius: 8px;
        padding: 12px 20px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        border-left: 4px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        width: 100%;
    }

    .test-item.pass {
        border-left-color: #48bb78;
    }

    .test-item.fail {
        border-left-color: #fc8181;
    }

    .test-item .test-name {
        font-weight: 600;
        font-size: 14px;
        flex: 1;
        min-width: 180px;
    }

    .test-item .test-status {
        display: inline-block;
        padding: 2px 14px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.5px;
        margin: 0 10px;
    }

    .test-item .test-status.pass {
        background: #c6f6d5;
        color: #22543d;
    }

    .test-item .test-status.fail {
        background: #fed7d7;
        color: #9b2c2c;
    }

    .test-item .test-message {
        color: #718096;
        font-size: 13px;
        min-width: 100px;
        text-align: right;
    }

    .test-section {
        background: white;
        border-radius: 10px;
        margin-top: 15px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        overflow: hidden;
        width: 100%;
    }

    .section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px 20px;
        cursor: pointer;
        background: #f7fafc;
        border-bottom: 1px solid #e2e8f0;
        user-select: none;
        width: 100%;
    }

    .section-header:hover {
        background: #edf2f7;
    }

    .section-header h2 {
        margin: 0;
        font-size: 18px;
        color: #2d3748;
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    .section-count {
        font-size: 14px;
        font-weight: 600;
        padding: 2px 12px;
        border-radius: 20px;
    }

    .count-pass {
        background: #c6f6d5;
        color: #22543d;
    }

    .count-warning {
        background: #fefcbf;
        color: #975a16;
    }

    .count-fail {
        background: #fed7d7;
        color: #9b2c2c;
    }

    .count-neutral {
        background: #edf2f7;
        color: #718096;
    }

    .section-toggle {
        background: none;
        border: none;
        font-size: 20px;
        cursor: pointer;
        color: #718096;
        padding: 0 10px;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 6px;
        flex-shrink: 0;
    }

    .section-toggle:hover {
        background: #e2e8f0;
    }

    .toggle-icon {
        display: inline-block;
        transition: transform 0.2s ease;
    }

    .section-collapsed .toggle-icon {
        transform: rotate(0deg);
    }

    .live-test {
        padding: 10px 0;
        width: 100%;
    }

    .live-test iframe {
        width: 100%;
        height: 500px;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        margin-top: 10px;
        display: block;
    }

    .btn-test {
        display: inline-block;
        padding: 8px 16px;
        background: #4299e1;
        color: white;
        border-radius: 6px;
        text-decoration: none;
        font-size: 13px;
        margin-top: 10px;
    }

    .btn-test:hover {
        background: #3182ce;
    }

    .btn-test-group {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 10px;
    }

    .top-section {
        margin-bottom: 20px;
        width: 100%;
    }

    @media (max-width: 640px) {
        body {
            padding: 10px;
        }

        .test-header {
            padding: 20px;
        }

        .test-header h1 {
            font-size: 22px;
        }

        .test-summary {
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }

        .summary-card {
            padding: 15px;
        }

        .summary-card .number {
            font-size: 24px;
        }

        .section-header {
            padding: 12px 15px;
            flex-wrap: wrap;
        }

        .section-header h2 {
            font-size: 16px;
        }

        .section-count {
            font-size: 12px;
            padding: 2px 10px;
        }

        .test-item {
            padding: 10px 15px;
            flex-direction: column;
            align-items: flex-start;
            gap: 5px;
        }

        .test-item .test-name {
            font-size: 13px;
            min-width: auto;
            width: 100%;
        }

        .test-item .test-status {
            margin: 0 10px 0 0;
        }

        .test-item .test-message {
            text-align: left;
            width: 100%;
            min-width: auto;
        }

        .test-grid {
            padding: 10px;
        }

        .live-test iframe {
            height: 350px;
        }
    }
    </style>
</head>

<body>
    <div class="test-container">

        <div class="test-header">
            <h1>FitPal Sign-Up System Test</h1>
            <p>Structural validation of the customer multi-step registration flow</p>
            <p style="font-size:13px;opacity:0.6;margin-top:10px;">
                Base Path: <?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?> |
                Base URL: <?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>

        <div class="top-section">
            <div class="test-summary">
                <div class="summary-card total">
                    <div class="number"><?= $totalTests ?></div>
                    <div class="label">Total Tests</div>
                </div>
                <div class="summary-card passed">
                    <div class="number"><?= $totalPassed ?></div>
                    <div class="label">Passed</div>
                </div>
                <div class="summary-card failed">
                    <div class="number"><?= $totalFailed ?></div>
                    <div class="label">Failed</div>
                </div>
                <div class="summary-card rate">
                    <div class="number"><?= $passRate ?>%</div>
                    <div class="label">Pass Rate</div>
                </div>
            </div>

            <?php renderLiveFormSection($baseUrl, true); ?>
        </div>

        <?php
    renderSection('Database Tests', 'Database', true);
    renderSection('Session Tests', 'Session', true);
    renderSection('Core File Tests', 'Core Files', true);
    renderSection('Sign-Up Page Structure', 'Page Structure', true);
    renderSection('Handler Checks', 'Handler', true);
    renderSection('JavaScript Checks', 'JavaScript', true);
    renderSection('CSS Checks', 'CSS', true);
    ?>

    </div>

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

            function toggle() {
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

            header.addEventListener('click', function(e) {
                if (e.target.closest('.section-toggle')) return;
                toggle();
            });
            toggleBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                toggle();
            });
            header.setAttribute('role', 'button');
            header.setAttribute('tabindex', '0');
            header.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    toggle();
                }
            });
        });
    })();
    </script>
</body>

</html>