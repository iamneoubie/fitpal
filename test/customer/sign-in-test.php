<?php
/**
 * FitPal Customer Sign-In System Test
 * Validates the customer sign-in flow
 *
 * @package FitPal
 * @version 2.8
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

function renderLiveFormSection($baseUrl, $defaultCollapsed = true) {
    global $sections;
    
    $sectionName = 'Live Form';
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
    $contentId = 'content-live-form';
    
    echo "<div class='test-section {$collapsedClass}' id='section-live-form'>";
    echo "<div class='section-header' data-target='{$contentId}'>";
    
    $countClass = 'count-neutral';
    if ($allPassed) {
        $countClass = 'count-pass';
    } elseif ($hasFailures && $failedTests <= 2) {
        $countClass = 'count-warning';
    } elseif ($hasFailures && $failedTests > 2) {
        $countClass = 'count-fail';
    }
    
    echo "<h2>Live Form Test <span class='section-count {$countClass}'>({$passedTests}/{$totalTests})</span></h2>";
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
    echo "<p><strong>Test the sign-in form:</strong></p>";
    echo "<p style='font-size:13px;color:#718096;margin:5px 0;'>URL: " . htmlspecialchars($baseUrl . '/customer/pages/sign-in.php') . "</p>";
    echo "<p style='font-size:13px;color:#718096;margin:5px 0 10px 0;'>Test credentials: test@example.com / admin123</p>";
    echo "<iframe src='" . $baseUrl . "/customer/pages/sign-in.php'></iframe>";
    echo "<div class='btn-test-group'>";
    echo "<a href='" . $baseUrl . "/customer/pages/sign-in.php' target='_blank' class='btn-test'>Open Sign-In</a>";
    echo "<a href='" . $baseUrl . "/customer/pages/sign-up.php' target='_blank' class='btn-test'>Open Sign-Up</a>";
    echo "<a href='" . $baseUrl . "/customer/pages/dashboard.php' target='_blank' class='btn-test'>Open Dashboard</a>";
    echo "<a href='" . $baseUrl . "/customer/backend/handlers/sign-in-handler.php' target='_blank' class='btn-test'>Test Handler</a>";
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
    <title>FitPal Sign-In System Test</title>
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
        .db-error-box { background: #fff5f5; border: 1px solid #feb2b2; border-radius: 8px; padding: 15px; margin: 10px 0; color: #c53030; width: 100%; }
        .db-error-box code { background: #fed7d7; padding: 2px 6px; border-radius: 4px; }
        .top-section { margin-bottom: 20px; width: 100%; }
        
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
    <h1>FitPal Sign-In System Test</h1>
    <p>Validates the customer sign-in flow</p>
    <p style='font-size:13px;opacity:0.6;margin-top:10px;'>
        Base Path: " . htmlspecialchars($basePath) . " | 
        Base URL: " . htmlspecialchars($baseUrl) . "
    </p>
</div>";

// ============================================
// RUN ALL TESTS FIRST
// ============================================

// Database Tests
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
            
            $stmt = $test_connection->query("SELECT 1 as test");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            testResult('Database Query', $result !== false, 'Query executed successfully', 'Database');
            
            $stmt = $test_connection->query("SHOW TABLES LIKE 'customer'");
            $hasCustomerTable = $stmt->rowCount() > 0;
            testResult('Customer Table', $hasCustomerTable, $hasCustomerTable ? 'Exists' : 'Missing', 'Database');
            
        } catch (PDOException $e) {
            testResult('Database Connection', false, 'PDO Error: ' . $e->getMessage(), 'Database');
        }
    } else {
        testResult('Database File Parsed', false, 'Connection code not found', 'Database');
    }
} else {
    testResult('Database File Exists', false, 'File not found: ' . $dbFile, 'Database');
}

// Session Tests
$sessionActive = session_status() === PHP_SESSION_ACTIVE;
testResult('Session Active', $sessionActive, $sessionActive ? 'Active' : 'Inactive', 'Session');

if ($sessionActive) {
    testResult('Session ID', session_id() !== '', 'Session ID exists', 'Session');
    
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    testResult('CSRF Token', !empty($_SESSION['csrf_token']), 'Token generated', 'Session');
}

// Core File Tests
$criticalFiles = [
    'Sign-In Page' => $customerPath . '/pages/sign-in.php',
    'Sign-In Handler' => $customerPath . '/backend/handlers/sign-in-handler.php',
    'Sign-In JS' => $customerPath . '/assets/ui/js/sign-in.js',
    'Sign-In CSS' => $customerPath . '/assets/css/sign-in.css',
    'Customer Header' => $customerPath . '/includes/header.php',
    'Customer Dashboard' => $customerPath . '/pages/dashboard.php',
    'Shared Header' => $sharedPath . '/includes/header.php',
    'Shared Footer' => $sharedPath . '/includes/footer.php',
    'Database Connect' => $sharedPath . '/backend/database/database-connect.php',
];

foreach ($criticalFiles as $name => $path) {
    $exists = file_exists($path);
    testResult($name, $exists, $exists ? 'Found' : 'Missing', 'Core Files');
}

// Live Form Tests
$signInPageExists = file_exists($customerPath . '/pages/sign-in.php');
testResult('Sign-In Page Accessible', $signInPageExists, $signInPageExists ? 'Page found' : 'Page missing', 'Live Form');

$signUpPageExists = file_exists($customerPath . '/pages/sign-up.php');
testResult('Sign-Up Page Accessible', $signUpPageExists, $signUpPageExists ? 'Page found' : 'Page missing', 'Live Form');

$dashboardExists = file_exists($customerPath . '/pages/dashboard.php');
testResult('Dashboard Page Accessible', $dashboardExists, $dashboardExists ? 'Page found' : 'Page missing', 'Live Form');

$handlerExists = file_exists($customerPath . '/backend/handlers/sign-in-handler.php');
testResult('Handler Accessible', $handlerExists, $handlerExists ? 'File found' : 'File missing', 'Live Form');

// Sign-In Page Structure
$signInPage = $customerPath . '/pages/sign-in.php';
if (file_exists($signInPage)) {
    $content = file_get_contents($signInPage);
    testResult('Page Readable', true, 'File loaded', 'Page Structure');
    
    preg_match('/<form[^>]*id=["\']signInForm["\'][^>]*>/i', $content, $formMatches);
    testResult('Form (signInForm)', !empty($formMatches), !empty($formMatches) ? 'Found' : 'Missing', 'Page Structure');
    
    preg_match('/<input[^>]*name=["\']csrf_token["\'][^>]*>/i', $content, $csrfMatches);
    testResult('CSRF Token Field', !empty($csrfMatches), !empty($csrfMatches) ? 'Found' : 'Missing', 'Page Structure');
    
    $expectedFields = ['identifier', 'password'];
    $foundFields = [];
    preg_match_all('/<input[^>]*name=["\']([^"\']+)["\'][^>]*>/i', $content, $fieldMatches);
    $foundFields = $fieldMatches[1] ?? [];
    $allFound = true;
    $missingFields = [];
    foreach ($expectedFields as $field) {
        if (!in_array($field, $foundFields)) {
            $allFound = false;
            $missingFields[] = $field;
        }
    }
    testResult('Required Fields', $allFound, $allFound ? 'All present' : 'Missing: ' . implode(', ', $missingFields), 'Page Structure');
    
    preg_match('/<div[^>]*id=["\']identifierError["\'][^>]*>/i', $content, $identifierErrorMatches);
    testResult('Identifier Error Container', !empty($identifierErrorMatches), !empty($identifierErrorMatches) ? 'Found' : 'Missing', 'Page Structure');
    
    preg_match('/<div[^>]*id=["\']passwordError["\'][^>]*>/i', $content, $passwordErrorMatches);
    testResult('Password Error Container', !empty($passwordErrorMatches), !empty($passwordErrorMatches) ? 'Found' : 'Missing', 'Page Structure');
    
    preg_match('/<button[^>]*id=["\']togglePassword["\'][^>]*>/i', $content, $toggleMatches);
    testResult('Password Toggle Button', !empty($toggleMatches), !empty($toggleMatches) ? 'Found' : 'Missing', 'Page Structure');
    
    preg_match('/<a[^>]*href=["\'][^"\']*sign-up\.php["\'][^>]*>/i', $content, $signUpLinkMatches);
    testResult('Sign-Up Link', !empty($signUpLinkMatches), !empty($signUpLinkMatches) ? 'Found' : 'Missing', 'Page Structure');
    
    $hasSignInJS = strpos($content, 'sign-in.js') !== false;
    testResult('sign-in.js Included', $hasSignInJS, $hasSignInJS ? 'Found' : 'Missing', 'Page Structure');
    
    preg_match('/<form[^>]*action=["\']([^"\']+)["\'][^>]*>/i', $content, $actionMatches);
    if (!empty($actionMatches[1])) {
        $formAction = $actionMatches[1];
        $isCorrect = strpos($formAction, 'sign-in-handler.php') !== false;
        testResult('Form Action', $isCorrect, $isCorrect ? 'Correct: ' . htmlspecialchars($formAction) : 'Check: ' . htmlspecialchars($formAction), 'Page Structure');
    }
} else {
    testResult('Sign-In Page', false, 'File not found', 'Page Structure');
}

// Handler Checks
$handlerPath = $customerPath . '/backend/handlers/sign-in-handler.php';
if (file_exists($handlerPath)) {
    $handlerContent = file_get_contents($handlerPath);
    testResult('Handler Readable', true, 'File loaded', 'Handler');
    
    $hasSessionStart = strpos($handlerContent, 'session_start') !== false;
    testResult('session_start()', $hasSessionStart, $hasSessionStart ? 'Found' : 'Missing', 'Handler');
    
    $hasDatabase = strpos($handlerContent, 'database-connect.php') !== false;
    testResult('Database Include', $hasDatabase, $hasDatabase ? 'Found' : 'Missing', 'Handler');
    
    $hasCsrfValidation = strpos($handlerContent, 'csrf_token') !== false;
    testResult('CSRF Validation', $hasCsrfValidation, $hasCsrfValidation ? 'Found' : 'Missing', 'Handler');
    
    $hasPasswordVerify = strpos($handlerContent, 'password_verify') !== false;
    testResult('password_verify()', $hasPasswordVerify, $hasPasswordVerify ? 'Found' : 'Missing', 'Handler');
    
    $hasSessionRegenerate = strpos($handlerContent, 'session_regenerate_id') !== false;
    testResult('Session Regenerate', $hasSessionRegenerate, $hasSessionRegenerate ? 'Found' : 'Missing', 'Handler');
    
    $hasSessionSet = strpos($handlerContent, 'customer_id') !== false && strpos($handlerContent, '$_SESSION') !== false;
    testResult('Session Variables Set', $hasSessionSet, $hasSessionSet ? 'Found' : 'Missing', 'Handler');
    
    $redirectCorrect = strpos($handlerContent, '../../pages/dashboard.php') !== false;
    testResult('Redirect to Dashboard', $redirectCorrect, $redirectCorrect ? 'Correct path' : 'Check redirect path', 'Handler');
    
    $errorRedirectCorrect = strpos($handlerContent, '../../pages/sign-in.php') !== false;
    testResult('Error Redirect to Sign-In', $errorRedirectCorrect, $errorRedirectCorrect ? 'Correct path' : 'Check error redirect path', 'Handler');
    
    $hasActiveCheck = strpos($handlerContent, 'is_active') !== false;
    testResult('Account Active Check', $hasActiveCheck, $hasActiveCheck ? 'Found' : 'Missing', 'Handler');
    
    $hasDevBypass = strpos($handlerContent, 'APP_ENV') !== false || strpos($handlerContent, 'development') !== false;
    testResult('Development Bypass', $hasDevBypass, $hasDevBypass ? 'Found' : 'Missing', 'Handler');
} else {
    testResult('Handler', false, 'File not found', 'Handler');
}

// JavaScript Checks
$jsPath = $customerPath . '/assets/ui/js/sign-in.js';
if (file_exists($jsPath)) {
    $jsContent = file_get_contents($jsPath);
    testResult('sign-in.js Readable', true, 'File loaded', 'JavaScript');
    
    $hasDOMContent = strpos($jsContent, 'DOMContentLoaded') !== false;
    testResult('DOMContentLoaded', $hasDOMContent, $hasDOMContent ? 'Found' : 'Missing', 'JavaScript');
    
    $hasFormRef = strpos($jsContent, 'signInForm') !== false;
    testResult('Form Reference', $hasFormRef, $hasFormRef ? 'Found' : 'Missing', 'JavaScript');
    
    $hasPasswordToggle = strpos($jsContent, 'togglePassword') !== false;
    testResult('Password Toggle', $hasPasswordToggle, $hasPasswordToggle ? 'Found' : 'Missing', 'JavaScript');
    
    $hasSubmit = strpos($jsContent, 'addEventListener') !== false && strpos($jsContent, 'submit') !== false;
    testResult('Form Submit Handler', $hasSubmit, $hasSubmit ? 'Found' : 'Missing', 'JavaScript');
    
    $hasFieldErrors = strpos($jsContent, 'identifierError') !== false && strpos($jsContent, 'passwordError') !== false;
    testResult('Field Error Handling', $hasFieldErrors, $hasFieldErrors ? 'Found' : 'Missing', 'JavaScript');
    
    $hasInputValidation = strpos($jsContent, 'input') !== false && strpos($jsContent, 'addEventListener') !== false;
    testResult('Input Validation', $hasInputValidation, $hasInputValidation ? 'Found' : 'Missing', 'JavaScript');
} else {
    testResult('sign-in.js', false, 'File not found', 'JavaScript');
}

// CSS Checks
$cssPath = $customerPath . '/assets/css/sign-in.css';
if (file_exists($cssPath)) {
    $cssContent = file_get_contents($cssPath);
    testResult('sign-in.css Readable', true, 'File loaded', 'CSS');
    
    $hasFormStyles = strpos($cssContent, 'sign-in-form') !== false || strpos($cssContent, 'sign-in') !== false;
    testResult('Form Styles', $hasFormStyles, $hasFormStyles ? 'Found' : 'Missing', 'CSS');
    
    $hasPasswordWrapper = strpos($cssContent, 'password-wrapper') !== false || strpos($cssContent, 'password-toggle') !== false;
    testResult('Password Toggle Styles', $hasPasswordWrapper, $hasPasswordWrapper ? 'Found' : 'Missing', 'CSS');
    
    $hasErrorStyles = strpos($cssContent, '.error') !== false;
    testResult('Error Styles', $hasErrorStyles, $hasErrorStyles ? 'Found' : 'Missing', 'CSS');
    
    $hasCardStyles = strpos($cssContent, 'sign-in-card') !== false || strpos($cssContent, 'card') !== false;
    testResult('Card Styles', $hasCardStyles, $hasCardStyles ? 'Found' : 'Missing', 'CSS');
    
    $hasButtonStyles = strpos($cssContent, 'btn') !== false;
    testResult('Button Styles', $hasButtonStyles, $hasButtonStyles ? 'Found' : 'Missing', 'CSS');
    
    $hasMediaQuery = strpos($cssContent, '@media') !== false;
    testResult('Responsive (Media Queries)', $hasMediaQuery, $hasMediaQuery ? 'Found' : 'Missing', 'CSS');
} else {
    testResult('sign-in.css', false, 'File not found', 'CSS');
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
// TOP SECTION: SUMMARY + LIVE FORM (Stacked vertically)
// ============================================
echo "<div class='top-section'>";

// Summary Cards
echo "<div class='test-summary'>";
echo "<div class='summary-card total'><div class='number'>{$totalTests}</div><div class='label'>Total Tests</div></div>";
echo "<div class='summary-card passed'><div class='number'>{$totalPassed}</div><div class='label'>Passed</div></div>";
echo "<div class='summary-card failed'><div class='number'>{$totalFailed}</div><div class='label'>Failed</div></div>";
echo "<div class='summary-card rate'><div class='number'>{$passRate}%</div><div class='label'>Pass Rate</div></div>";
echo "</div>";

// Live Form (with heading, pass count, and collapsible)
renderLiveFormSection($baseUrl, true);

echo "</div>";

// ============================================
// RENDER ALL SECTIONS (Collapsed by default)
// ============================================

renderSection('Database Tests', 'Database', true);
renderSection('Session Tests', 'Session', true);
renderSection('Core File Tests', 'Core Files', true);
renderSection('Sign-In Page Structure', 'Page Structure', true);
renderSection('Handler Checks', 'Handler', true);
renderSection('JavaScript Checks', 'JavaScript', true);
renderSection('CSS Checks', 'CSS', true);

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