<?php
/**
 * FitPal Restaurant Registration Handler
 *
 * Creates the following rows in a single DB transaction:
 *   1. restaurant                   (verification_status = 'pending')
 *   2. financial_account            (account_type = 'restaurant')
 *   3. restaurant_branch
 *   4. restaurant_account           (role = 'owner', branch_id = NULL)
 *   5. restaurant_permit × 1..5     (permit photos)
 *
 * @package FitPal
 * @version 2.0 — Owns its own CSRF bootstrap and rotates the
 *                restaurant token on mismatch.
 *
 *                require_once on includes/restaurant-csrf-token.php
 *                makes this handler the authoritative reader of
 *                'restaurant_csrf_token' rather than an incidental
 *                one that only worked because the page which
 *                rendered the form had already called
 *                getRestaurantCsrfToken().
 *
 *                On the CSRF-mismatch branch the restaurant's token
 *                is now unset before responding. Without that
 *                rotation, getRestaurantCsrfToken() on the next
 *                render of sign-up.php saw the key still set and
 *                returned the same stale value, so a user who hit a
 *                mismatch was stuck re-submitting the dead token
 *                until the session was cleared manually.
 *
 *                Uses isset() on both keys before hash_equals() so an
 *                unset session key can never be coerced to an empty
 *                string and pass validation against an empty POST
 *                value.
 *
 *                Only the restaurant's own key is touched. The shared
 *                'csrf_token' key is never read, written, or cleared
 *                by this file.
 *
 *                (1.2: Validated against restaurant_csrf_token (own
 *                key) instead of the shared csrf_token, matching the
 *                sign-in handler and the restaurant sign-up.php form.)
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/restaurant-queries.php';

// Own the restaurant role's CSRF bootstrap. The helper is idempotent
// and stores the token under 'restaurant_csrf_token' — never the
// shared 'csrf_token' key.
require_once __DIR__ . '/../../includes/restaurant-csrf-token.php';

header('Content-Type: application/json');

const MAX_PERMITS         = 5;
const MAX_PERMIT_BYTES    = 5 * 1024 * 1024;
const ALLOWED_PERMIT_MIME = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

function respondError(string $message, string $field = ''): void
{
    echo json_encode(['status' => 'error', 'message' => $message, 'field' => $field]);
    exit;
}

function validatePermitFile(array $file): string
{
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new RuntimeException('Invalid file payload.');
    }
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Empty slot.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed (code ' . $file['error'] . ').');
    }
    if (($file['size'] ?? 0) <= 0) {
        throw new RuntimeException('Uploaded file is empty.');
    }
    if ($file['size'] > MAX_PERMIT_BYTES) {
        throw new RuntimeException(
            'Each permit must be under ' . round(MAX_PERMIT_BYTES / 1048576, 1) . ' MB.'
        );
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        throw new RuntimeException('Could not inspect file.');
    }
    $mime = (string)finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!isset(ALLOWED_PERMIT_MIME[$mime])) {
        throw new RuntimeException('Unsupported permit type. Use JPG, PNG, or WEBP.');
    }
    return ALLOWED_PERMIT_MIME[$mime];
}

function storePermitFile(array $file, string $projectRoot, string $ext, int $index): string
{
    $dir = $projectRoot . '/shared/uploads/restaurant-permits';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create upload directory.');
    }

    $filename = 'permit_' . time() . '_' . $index . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $fullPath = $dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
        throw new RuntimeException('Could not save permit file.');
    }

    return 'shared/uploads/restaurant-permits/' . $filename;
}

/* -------------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Invalid request method.');
}

if (
    !isset($_POST['csrf_token'], $_SESSION['restaurant_csrf_token']) ||
    !hash_equals((string)$_SESSION['restaurant_csrf_token'], (string)$_POST['csrf_token'])
) {
    // Rotate the restaurant's own token so the next render generates
    // a fresh one. Only the restaurant's key is cleared — never the
    // shared 'csrf_token' key.
    unset($_SESSION['restaurant_csrf_token']);

    respondError('Security validation failed. Please refresh the page and try again.');
}

/* --------------------------------------------------------------
 * COLLECT INPUT
 * -------------------------------------------------------------- */

$firstName       = trim((string)($_POST['first_name'] ?? ''));
$middleName      = trim((string)($_POST['middle_name'] ?? ''));
$lastName        = trim((string)($_POST['last_name'] ?? ''));
$email           = trim((string)($_POST['email'] ?? ''));
$contactNumber   = trim((string)($_POST['contact_number'] ?? ''));
$username        = trim((string)($_POST['username'] ?? ''));
$password        = (string)($_POST['password'] ?? '');
$confirmPassword = (string)($_POST['confirm_password'] ?? '');

$businessName        = trim((string)($_POST['business_name'] ?? ''));
$cuisineType         = trim((string)($_POST['cuisine_type'] ?? ''));
$businessDescription = trim((string)($_POST['business_description'] ?? ''));
$dietaryTags         = trim((string)($_POST['dietary_tags'] ?? ''));

$branchName = trim((string)($_POST['branch_name'] ?? ''));
$block      = trim((string)($_POST['block'] ?? ''));
$barangay   = trim((string)($_POST['barangay'] ?? ''));
$city       = trim((string)($_POST['city'] ?? ''));
$province   = trim((string)($_POST['province'] ?? ''));
$region     = trim((string)($_POST['region'] ?? ''));
$postalCode = trim((string)($_POST['postal_code'] ?? ''));

$terms = $_POST['terms'] ?? '';

/* --------------------------------------------------------------
 * REQUIRED + FIELD VALIDATION
 * -------------------------------------------------------------- */

if (
    $firstName === '' || $lastName === '' || $email === '' ||
    $contactNumber === '' || $username === '' || $password === '' ||
    $businessName === '' || $cuisineType === '' ||
    $branchName === '' || $block === '' || $city === ''
) {
    respondError('Please fill in all required fields.');
}

$namePattern = "/^[A-Za-z\s\-']+$/u";
if (strlen($firstName) < 2 || !preg_match($namePattern, $firstName)) {
    respondError('First name contains invalid characters.', 'first_name');
}
if (strlen($lastName) < 2 || !preg_match($namePattern, $lastName)) {
    respondError('Last name contains invalid characters.', 'last_name');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respondError('Please enter a valid email address.', 'email');
}

$cleanedContact = preg_replace('/\s+/', '', $contactNumber);
if (!preg_match('/^09\d{9}$/', (string)$cleanedContact)) {
    respondError('Enter a valid PH mobile number (11 digits, starting with 09).', 'contact_number');
}

if (strlen($username) < 3 || strlen($username) > 20) {
    respondError('Username must be 3–20 characters.', 'username');
}
if (!preg_match('/^[A-Za-z0-9_]+$/', $username)) {
    respondError('Username can only contain letters, numbers, and underscores.', 'username');
}

if (strlen($password) < 8 || strlen($password) > 20) {
    respondError('Password must be 8–20 characters.', 'password');
}
if (!preg_match('/^[A-Za-z0-9]+$/', $password)) {
    respondError('Password can only contain letters and numbers.', 'password');
}
if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
    respondError('Password must contain at least one letter and one number.', 'password');
}
if ($password !== $confirmPassword) {
    respondError('Passwords do not match.', 'confirm_password');
}

if (strlen($businessName) < 2) {
    respondError('Business name must be at least 2 characters.', 'business_name');
}
if (strlen($cuisineType) < 2) {
    respondError('Cuisine type is required.', 'cuisine_type');
}
if (strlen($businessDescription) > 2000) {
    respondError('Business description is too long.', 'business_description');
}
if (strlen($branchName) < 2) {
    respondError('Branch name is required.', 'branch_name');
}
if ($block === '') {
    respondError('Block / Street / Unit is required.', 'block');
}
if ($city === '') {
    respondError('City or municipality is required.', 'city');
}
if ($postalCode !== '' && !preg_match('/^[0-9]{3,10}$/', $postalCode)) {
    respondError('Postal code must be numeric.', 'postal_code');
}
if (empty($terms)) {
    respondError('You must agree to the Terms and Conditions and Privacy Policy.', 'terms');
}

/* --------------------------------------------------------------
 * PERMIT FILE VALIDATION
 * -------------------------------------------------------------- */

$permitFiles = [];

if (isset($_FILES['permits']) && is_array($_FILES['permits']['name'])) {
    $count = count($_FILES['permits']['name']);
    if ($count > MAX_PERMITS) {
        respondError('You can upload at most ' . MAX_PERMITS . ' permits.', 'permits');
    }

    for ($i = 0; $i < $count; $i++) {
        $err = $_FILES['permits']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $file = [
            'name'     => (string)$_FILES['permits']['name'][$i],
            'type'     => (string)$_FILES['permits']['type'][$i],
            'tmp_name' => (string)$_FILES['permits']['tmp_name'][$i],
            'error'    => $err,
            'size'     => (int)($_FILES['permits']['size'][$i] ?? 0),
        ];

        try {
            $ext = validatePermitFile($file);
        } catch (RuntimeException $e) {
            respondError('Permit ' . ($i + 1) . ': ' . $e->getMessage(), 'permits');
        }

        $permitFiles[] = ['file' => $file, 'ext' => $ext];
    }
}

if (empty($permitFiles)) {
    respondError('Please upload at least one permit photo.', 'permits');
}

/* --------------------------------------------------------------
 * DATABASE TRANSACTION
 * -------------------------------------------------------------- */

$movedFiles = [];

try {
    if (restaurantEmailExists($database_connection, $email)) {
        respondError('This email address is already registered.', 'email');
    }
    if (restaurantUsernameExists($database_connection, $username)) {
        respondError('This username is already taken.', 'username');
    }
    if (restaurantContactExists($database_connection, (string)$cleanedContact)) {
        respondError('This mobile number is already registered.', 'contact_number');
    }
    if (restaurantBusinessNameExists($database_connection, $businessName)) {
        respondError('This business name is already registered.', 'business_name');
    }

    $projectRoot = realpath(__DIR__ . '/../../..');
    if ($projectRoot === false) {
        respondError('Server storage path unavailable.');
    }

    $database_connection->beginTransaction();

    $restaurantId = createRestaurant($database_connection, [
        'business_name' => $businessName,
        'description'   => $businessDescription,
        'cuisine_type'  => $cuisineType,
        'dietary_tags'  => $dietaryTags,
    ]);

    $financialAccountId = createRestaurantFinancialAccount($database_connection);

    $branchCode = generateBranchCode($database_connection, $businessName);
    createRestaurantBranch(
        $database_connection,
        $restaurantId,
        $financialAccountId,
        [
            'branch_name' => $branchName,
            'branch_code' => $branchCode,
            'block'       => $block,
            'barangay'    => $barangay,
            'city'        => $city,
            'province'    => $province,
            'region'      => $region,
            'postal_code' => $postalCode,
        ]
    );

    $hashed = password_hash($password, PASSWORD_BCRYPT);
    createRestaurantOwnerAccount($database_connection, $restaurantId, [
        'first_name'     => $firstName,
        'middle_name'    => $middleName,
        'last_name'      => $lastName,
        'email'          => $email,
        'contact_number' => (string)$cleanedContact,
        'username'       => $username,
        'password'       => $hashed,
    ]);

    foreach ($permitFiles as $index => $p) {
        $path = storePermitFile($p['file'], $projectRoot, $p['ext'], $index);
        $movedFiles[] = $projectRoot . '/' . $path;

        createRestaurantPermit(
            $database_connection,
            $restaurantId,
            $path,
            (string)$p['file']['name'],
            $index
        );
    }

    $database_connection->commit();

    // Only clear restaurant's own token. Do not touch the shared
    // 'csrf_token' key or any other role's token — another role in
    // this same browser session may still be relying on it.
    unset($_SESSION['restaurant_csrf_token']);

    $_SESSION['registration_success'] =
        'Restaurant application submitted with ' . count($permitFiles) . ' permit(s). ' .
        'Please sign in after verification.';

    echo json_encode([
        'status'   => 'success',
        'message'  => 'Your restaurant has been registered. An admin will review your permits before activation.',
        'redirect' => 'sign-in.php',
    ]);
    exit;

} catch (PDOException $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    foreach ($movedFiles as $p) {
        if (is_file($p)) @unlink($p);
    }
    error_log('Restaurant registration DB error: ' . $e->getMessage());

    $message = $e->getMessage();
    if (stripos($message, 'Duplicate entry') !== false) {
        if (stripos($message, 'email') !== false)          respondError('This email address is already registered.', 'email');
        if (stripos($message, 'username') !== false)       respondError('This username is already taken.', 'username');
        if (stripos($message, 'contact_number') !== false) respondError('This mobile number is already registered.', 'contact_number');
        if (stripos($message, 'business_name') !== false)  respondError('This business name is already registered.', 'business_name');
    }
    respondError('An unexpected error occurred. Please try again later.');

} catch (Throwable $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    foreach ($movedFiles as $p) {
        if (is_file($p)) @unlink($p);
    }
    error_log('Restaurant registration error: ' . $e->getMessage());
    respondError('An unexpected error occurred. Please try again later.');
}