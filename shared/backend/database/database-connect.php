<?php
/**
 * FitPal Database Connection
 * 
 * Centralized database connection using PDO.
 * All roles use this single connection instance.
 * 
 * Usage:
 *   require_once __DIR__ . '/shared/backend/database/database_connect.php';
 *   $statement = $database_connection->prepare("SELECT * FROM users WHERE id = ?");
 *   $statement->execute([$user_id]);
 * 
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

/**
 * Database configuration
 */
$database_host = 'localhost';
$database_port = 3306;
$database_name = 'fitpal_food_delivery';
$database_username = 'root';
$database_password = '';
$database_charset = 'utf8mb4';

// Allow environment variable overrides
if (getenv('DB_HOST') !== false) {
    $database_host = getenv('DB_HOST');
}
if (getenv('DB_PORT') !== false) {
    $database_port = (int)getenv('DB_PORT');
}
if (getenv('DB_NAME') !== false) {
    $database_name = getenv('DB_NAME');
}
if (getenv('DB_USER') !== false) {
    $database_username = getenv('DB_USER');
}
if (getenv('DB_PASS') !== false) {
    $database_password = getenv('DB_PASS');
}
if (getenv('DB_CHARSET') !== false) {
    $database_charset = getenv('DB_CHARSET');
}

// Build the Data Source Name string
$data_source_name = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $database_host,
    $database_port,
    $database_name,
    $database_charset
);

// PDO connection options
$connection_options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_PERSISTENT => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$database_charset}"
];

try {
    // Create the database connection
    $database_connection = new PDO(
        $data_source_name,
        $database_username,
        $database_password,
        $connection_options
    );
} catch (PDOException $exception) {
    error_log('Database Connection Error: ' . $exception->getMessage());

    $error_message = "We're having trouble connecting right now. Please try again in a moment.";
    $detailed_error = $exception->getMessage();

    if (getenv('APP_ENV') === 'development') {
        $error_message = 'Database Connection Failed: ' . $detailed_error;
    }

    http_response_code(500);

    $scriptPath = $_SERVER['SCRIPT_NAME'];
    $dirPath = dirname($scriptPath);
    $segments = array_filter(explode('/', $dirPath));
    $depth = count($segments);
    $assetBase = $depth <= 0 ? './shared/' : str_repeat('../', $depth) . 'shared/';
    ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FitPal - Service Unavailable</title>
    <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/global.css">
    <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/database_connect.css">
</head>

<body>
    <div class="error-page">
        <div class="error-card">
            <div class="error-status">500</div>
            <div class="error-content">
                <div class="error-icon">
                    <div class="db-stack"><span></span><span></span><span></span></div>
                </div>
                <div class="error-text">
                    <p class="error-title">We're <span>Having Trouble</span></p>
                    <p class="error-message"><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <?php if (getenv('APP_ENV') === 'development'): ?>
                <div class="error-debug">
                    <p class="debug-label">Error Details</p>
                    <code><?php echo htmlspecialchars($detailed_error, ENT_QUOTES, 'UTF-8'); ?></code>
                </div>
                <?php endif; ?>
                <div class="error-action">
                    <a href="javascript:location.reload()" class="btn-retry">
                        <span class="retry-icon">&#8635;</span> Retry
                    </a>
                </div>
                <div class="error-help">
                    <p>If this keeps happening, please try again shortly.</p>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
<?php
    exit;
}

// Set the timezone for the database connection
try {
    $timezone_offset = '+08:00';
    $timezone_statement = $database_connection->prepare("SET time_zone = ?");
    $timezone_statement->execute([$timezone_offset]);
} catch (PDOException $exception) {
    // Timezone setting is optional - don't fail if it doesn't work
    error_log('Timezone setting warning: ' . $exception->getMessage());
}

// The connection is now available as $database_connection
// Include this file in any script that needs database access
?>