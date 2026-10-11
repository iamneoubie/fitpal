<?php
/**
 * FitPal Restaurant Registration Handler
 *
 * Creates the following rows in a single DB transaction:
 *   1. restaurant                   (verification_status = 'pending')
 *   2. financial_account            (account_type = 'restaurant')
 *   3. restaurant_branch
 *   4. restaurant_account           (role = 'owner', branch_id = NULL)
 *   5. restaurant_permit × 1..N     (one row per uploaded permit)
 *
 * ---------------------------------------------------------------------
 * SELF-CONTAINED
 * ---------------------------------------------------------------------
 * This handler is a direct HTTP entry point. It does NOT require any
 * other handler file.
 *
 * A previous revision required restaurant-reapply-handler.php in
 * order to reuse its fitpal_validate_* functions. That was a bug.
 * restaurant-reapply-handler.php is itself an entry point: its
 * top-level code runs its own authentication check the moment it is
 * loaded, and returns 401 "Not authenticated" on any request that
 * has no restaurant session. A registration request has no
 * restaurant session — the user is registering precisely because
 * they do not have one — so requiring the reapply handler caused
 * the registration POST to short-circuit into a 401 before this
 * file's body ever ran.
 *
 * The validators this file needs are defined here, inlined, with
 * the same names and the same function_exists guards that the
 * reapply handler uses. If both files are ever loaded in the same
 * request, the guards make the second set of definitions a no-op.
 *
 * ---------------------------------------------------------------------
 * PERMIT STORAGE
 * ---------------------------------------------------------------------
 * Permit files are written to:
 *
 *     shared/uploads/restaurant/permits/<restaurant_id>/
 *         image-1.<ext>
 *         image-2.<ext>
 *         ...
 *
 * One restaurant_permit row is inserted per uploaded permit.
 * restaurant_permit.original_name is NOT NULL in the schema, so
 * every row carries a generated label of the form:
 *
 *     RESTAURANT-<id>-PERMIT-<YYYY-MM-DD>-<HH-MM-SS>
 *
 * with the slot number appended when more than one permit is
 * uploaded in the same second:
 *
 *     RESTAURANT-<id>-PERMIT-<YYYY-MM-DD>-<HH-MM-SS>-<slot>
 *
 * display_order is written as the slot number (1-based) so the
 * reader can order multiple permits deterministically.
 *
 * A previous revision called createRestaurantPermit() from the
 * query layer, which writes NULL for original_name by design for
 * the slot model. That call violated the NOT NULL constraint on
 * every request. This handler now owns its own INSERT so it can
 * supply a real original_name value on every row.
 *
 * ---------------------------------------------------------------------
 * WRITE ORDER
 * ---------------------------------------------------------------------
 *   1. createRestaurant()
 *   2. createRestaurantFinancialAccount()
 *   3. createRestaurantBranch()
 *   4. createRestaurantOwnerAccount()
 *   5. storePermitSlot() × N    → files land under
 *                                  permits/<restaurantId>/image-<n>.<ext>
 *   6. insertRestaurantPermitRow() × N → one row per uploaded permit
 *   7. COMMIT
 *
 * If any step after 5 fails, the written files are unlinked and the
 * transaction is rolled back.
 *
 * ---------------------------------------------------------------------
 * CSRF
 * ---------------------------------------------------------------------
 * Validated against the restaurant role's own session key,
 * restaurant_csrf_token. The shared key csrf_token is never read,
 * written, or cleared.
 *
 * @package FitPal
 * @version 7.0 — The handler owns its own restaurant_permit INSERT
 *                and writes a generated original_name for every
 *                row. The NULL original_name violation is gone.
 *
 *                (6.0: aligned the write side with the slot-model
 *                read side. 5.0: self-contained rewrite. 4.0:
 *                required the reapply handler. 3.0: per-account
 *                upload layout. 2.0: own CSRF bootstrap.
 *                1.0: initial.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('restaurant');

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/restaurant-queries.php';
require_once __DIR__ . '/../../includes/restaurant-csrf-token.php';

header('Content-Type: application/json');

/* =============================================================
 * CONSTANTS
 * ============================================================= */

const MAX_PERMITS         = 5;
const MAX_PERMIT_BYTES    = 5 * 1024 * 1024;
const ALLOWED_PERMIT_MIME = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

/* =============================================================
 * RESPONSE HELPER
 * ============================================================= */

function respondError(string $message, string $field = ''): void
{
    echo json_encode(['status' => 'error', 'message' => $message, 'field' => $field]);
    exit;
}

/* =============================================================
 * VALIDATORS
 * ============================================================= */

if (!function_exists('fitpal_validate_name_field')) {
    function fitpal_validate_name_field(string $value, string $field, string $label): array
    {
        $pattern = "/^[A-Za-z\s\-']+$/u";

        if (strlen($value) < 2) {
            return [$field => $label . ' must be at least 2 characters.'];
        }
        if (!preg_match($pattern, $value)) {
            return [$field => $label . ' contains invalid characters.'];
        }
        return [];
    }
}

if (!function_exists('fitpal_validate_contact_number')) {
    function fitpal_validate_contact_number(string $value, string $field, string $label): array
    {
        $cleaned = (string)preg_replace('/\D+/', '', $value);

        if ($cleaned === '') {
            return [$field => $label . ' is required.'];
        }
        if (!preg_match('/^09\d{9}$/', $cleaned)) {
            return [$field => $label . ' must be a valid PH mobile number (11 digits, starting with 09).'];
        }
        return [];
    }
}

if (!function_exists('fitpal_normalize_contact_number')) {
    function fitpal_normalize_contact_number(string $value): string
    {
        return (string)preg_replace('/\D+/', '', $value);
    }
}

if (!function_exists('fitpal_validate_username')) {
    function fitpal_validate_username(string $value, string $field = 'username'): array
    {
        if ($value === '') {
            return [$field => 'Username is required.'];
        }
        if (strlen($value) < 3 || strlen($value) > 20) {
            return [$field => 'Username must be 3–20 characters.'];
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $value)) {
            return [$field => 'Username can only contain letters, numbers, and underscores.'];
        }
        return [];
    }
}

if (!function_exists('fitpal_validate_email')) {
    function fitpal_validate_email(string $value, string $field = 'email'): array
    {
        if ($value === '') {
            return [$field => 'Email address is required.'];
        }
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return [$field => 'Please enter a valid email address.'];
        }
        return [];
    }
}

if (!function_exists('fitpal_validate_business_name')) {
    function fitpal_validate_business_name(string $value, string $field = 'business_name'): array
    {
        if (strlen($value) < 2) {
            return [$field => 'Business name must be at least 2 characters.'];
        }
        if (strlen($value) > 100) {
            return [$field => 'Business name must be at most 100 characters.'];
        }
        return [];
    }
}

if (!function_exists('fitpal_validate_cuisine_type')) {
    function fitpal_validate_cuisine_type(string $value, string $field = 'cuisine_type'): array
    {
        if (strlen($value) < 2) {
            return [$field => 'Cuisine type must be at least 2 characters.'];
        }
        if (strlen($value) > 50) {
            return [$field => 'Cuisine type must be at most 50 characters.'];
        }
        return [];
    }
}

if (!function_exists('fitpal_validate_business_description')) {
    function fitpal_validate_business_description(string $value, string $field = 'description'): array
    {
        if (strlen($value) > 2000) {
            return [$field => 'Description is too long (max 2000 characters).'];
        }
        return [];
    }
}

if (!function_exists('fitpal_validate_dietary_tags')) {
    function fitpal_validate_dietary_tags(string $value, string $field = 'dietary_tags'): array
    {
        if (strlen($value) > 200) {
            return [$field => 'Dietary tags are too long (max 200 characters).'];
        }
        return [];
    }
}

if (!function_exists('fitpal_validate_branch_name')) {
    function fitpal_validate_branch_name(string $value, string $field = 'branch_name'): array
    {
        if (strlen($value) < 2) {
            return [$field => 'Branch name must be at least 2 characters.'];
        }
        if (strlen($value) > 50) {
            return [$field => 'Branch name must be at most 50 characters.'];
        }
        return [];
    }
}

if (!function_exists('fitpal_validate_address_field')) {
    function fitpal_validate_address_field(
        string $value,
        string $field,
        string $label,
        bool $required
    ): array {
        if ($required && $value === '') {
            return [$field => $label . ' is required.'];
        }
        if (strlen($value) > 120) {
            return [$field => $label . ' is too long (max 120 characters).'];
        }
        return [];
    }
}

if (!function_exists('fitpal_validate_postal_code')) {
    function fitpal_validate_postal_code(string $value, string $field = 'postal_code'): array
    {
        if ($value === '') {
            return [];
        }
        if (!preg_match('/^[0-9]{3,10}$/', $value)) {
            return [$field => 'Postal code must be numeric (3–10 digits).'];
        }
        return [];
    }
}

/* =============================================================
 * PERMIT FILE HELPERS
 * ============================================================= */

/**
 * Absolute path to a restaurant's permit folder.
 *
 *     shared/uploads/restaurant/permits/<restaurant_id>/
 */
function permitAbsoluteFolder(int $restaurantId): string
{
    $projectRoot = realpath(__DIR__ . '/../../..');
    if ($projectRoot === false) {
        throw new RuntimeException('Server storage path unavailable.');
    }
    return $projectRoot . '/shared/uploads/restaurant/permits/' . $restaurantId;
}

/**
 * Project-root-relative path to a restaurant's permit folder,
 * ending with a slash.
 */
function permitRelativeFolder(int $restaurantId): string
{
    return 'shared/uploads/restaurant/permits/' . $restaurantId . '/';
}

/**
 * Validate one uploaded permit file and return its extension.
 *
 * @throws RuntimeException
 */
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

/**
 * Move one uploaded permit file into the restaurant's permit folder
 * as image-<slot>.<ext>.
 *
 * @return string Absolute path that was written.
 * @throws RuntimeException
 */
function storePermitSlot(
    array $file,
    int $restaurantId,
    int $slot,
    string $ext
): string {
    if ($slot < 1 || $slot > MAX_PERMITS) {
        throw new RuntimeException('Invalid permit slot.');
    }

    $folder = permitAbsoluteFolder($restaurantId);

    if (!is_dir($folder)) {
        if (!mkdir($folder, 0755, true) && !is_dir($folder)) {
            throw new RuntimeException('Could not create the permit folder.');
        }
    }

    foreach (glob($folder . '/image-' . $slot . '.*') ?: [] as $existing) {
        if (is_file($existing)) {
            @unlink($existing);
        }
    }

    $target = $folder . '/image-' . $slot . '.' . $ext;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('Could not save permit file.');
    }

    @chmod($target, 0644);

    return $target;
}

/**
 * Build the generated original_name for a permit row.
 *
 * Format:
 *
 *     RESTAURANT-<id>-PERMIT-<YYYY-MM-DD>-<HH-MM-SS>
 *
 * When more than one permit belongs to the same second, the slot
 * number is appended so the names are unique within the batch:
 *
 *     RESTAURANT-<id>-PERMIT-<YYYY-MM-DD>-<HH-MM-SS>-<slot>
 *
 * @param int $restaurantId
 * @param int $slot       1-based slot number within the upload batch.
 * @param int $totalSlots Total number of slots in this batch.
 * @param int $timestamp  Unix timestamp to stamp into the name.
 * @return string
 */
function buildPermitOriginalName(
    int $restaurantId,
    int $slot,
    int $totalSlots,
    int $timestamp
): string {
    $datePart = date('Y-m-d', $timestamp);
    $timePart = date('H-i-s', $timestamp);

    $base = sprintf(
        'RESTAURANT-%d-PERMIT-%s-%s',
        $restaurantId,
        $datePart,
        $timePart
    );

    if ($totalSlots > 1) {
        $base .= '-' . $slot;
    }

    return $base;
}

/**
 * Insert one restaurant_permit row.
 *
 * The handler owns this INSERT because the schema declares
 * original_name as NOT NULL, and the query layer's
 * createRestaurantPermit() writes NULL by design for the slot
 * model. This function supplies a real original_name for every row.
 *
 * @return int  The permit_id of the new row.
 * @throws PDOException
 */
function insertRestaurantPermitRow(
    PDO $db,
    int $restaurantId,
    string $folderPath,
    string $originalName,
    int $displayOrder
): int {
    $stmt = $db->prepare(
        "INSERT INTO restaurant_permit
            (restaurant_id, file_path, original_name, display_order)
         VALUES
            (:restaurant_id, :file_path, :original_name, :display_order)"
    );
    $stmt->execute([
        ':restaurant_id' => $restaurantId,
        ':file_path'     => $folderPath,
        ':original_name' => $originalName,
        ':display_order' => $displayOrder,
    ]);
    return (int)$db->lastInsertId();
}

/* =============================================================
 * REQUEST METHOD
 * ============================================================= */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Invalid request method.');
}

/* =============================================================
 * CSRF
 * ============================================================= */

if (
    !isset($_POST['csrf_token'], $_SESSION['restaurant_csrf_token']) ||
    !hash_equals((string)$_SESSION['restaurant_csrf_token'], (string)$_POST['csrf_token'])
) {
    unset($_SESSION['restaurant_csrf_token']);

    respondError('Security validation failed. Please refresh the page and try again.');
}

/* =============================================================
 * COLLECT INPUT
 * ============================================================= */

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

/* =============================================================
 * REQUIRED FIELD CHECK
 * ============================================================= */

if (
    $firstName === '' || $lastName === '' || $email === '' ||
    $contactNumber === '' || $username === '' || $password === '' ||
    $businessName === '' || $cuisineType === '' ||
    $branchName === '' || $block === '' || $city === ''
) {
    if ($firstName === '')      respondError('First name is required.', 'first_name');
    if ($lastName === '')       respondError('Last name is required.', 'last_name');
    if ($email === '')          respondError('Email address is required.', 'email');
    if ($contactNumber === '')  respondError('Contact number is required.', 'contact_number');
    if ($username === '')       respondError('Username is required.', 'username');
    if ($password === '')       respondError('Password is required.', 'password');
    if ($businessName === '')   respondError('Business name is required.', 'business_name');
    if ($cuisineType === '')    respondError('Cuisine type is required.', 'cuisine_type');
    if ($branchName === '')     respondError('Branch name is required.', 'branch_name');
    if ($block === '')          respondError('Block / Street / Unit is required.', 'block');
    if ($city === '')           respondError('City / Municipality is required.', 'city');

    respondError('Please fill in all required fields.');
}

/* =============================================================
 * FIELD VALIDATION
 * ============================================================= */

$errors = [];

$errors += fitpal_validate_name_field($firstName, 'first_name', 'First name');
$errors += fitpal_validate_name_field($lastName,  'last_name',  'Last name');

if ($middleName !== '') {
    $errors += fitpal_validate_name_field($middleName, 'middle_name', 'Middle name');
}

$errors += fitpal_validate_email($email);
$errors += fitpal_validate_contact_number($contactNumber, 'contact_number', 'Contact number');
$errors += fitpal_validate_username($username);

$errors += fitpal_validate_business_name($businessName);
$errors += fitpal_validate_cuisine_type($cuisineType);
$errors += fitpal_validate_business_description($businessDescription, 'business_description');
$errors += fitpal_validate_dietary_tags($dietaryTags);

$errors += fitpal_validate_branch_name($branchName);
$errors += fitpal_validate_address_field($block,    'block',    'Block / Street / Unit', true);
$errors += fitpal_validate_address_field($barangay, 'barangay', 'Barangay',              false);
$errors += fitpal_validate_address_field($city,     'city',     'City / Municipality',   true);
$errors += fitpal_validate_address_field($province, 'province', 'Province',              false);
$errors += fitpal_validate_address_field($region,   'region',   'Region',                false);
$errors += fitpal_validate_postal_code($postalCode);

if (strlen($password) < 8 || strlen($password) > 20) {
    $errors['password'] = 'Password must be 8–20 characters.';
} elseif (!preg_match('/^[A-Za-z0-9]+$/', $password)) {
    $errors['password'] = 'Password can only contain letters and numbers.';
} elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
    $errors['password'] = 'Password must contain at least one letter and one number.';
} elseif ($password !== $confirmPassword) {
    $errors['confirm_password'] = 'Passwords do not match.';
}

if (!empty($errors)) {
    $firstField = array_key_first($errors);

    echo json_encode([
        'status'  => 'error',
        'field'   => $firstField,
        'errors'  => $errors,
        'message' => $errors[$firstField],
    ]);
    exit;
}

$cleanedContact = fitpal_normalize_contact_number($contactNumber);

/* =============================================================
 * TERMS
 * ============================================================= */

if (empty($terms)) {
    respondError('You must agree to the Terms and Conditions and Privacy Policy.', 'terms');
}

/* =============================================================
 * PERMIT FILE VALIDATION
 * ============================================================= */

$permitSlots = [];

if (isset($_FILES['permits']) && is_array($_FILES['permits']['name'])) {
    $count = count($_FILES['permits']['name']);
    if ($count > MAX_PERMITS) {
        respondError('You can upload at most ' . MAX_PERMITS . ' permits.', 'permits');
    }

    $slotCounter = 0;
    for ($i = 0; $i < $count; $i++) {
        $err = $_FILES['permits']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $slotCounter++;
        if ($slotCounter > MAX_PERMITS) {
            break;
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
            respondError('Permit ' . $slotCounter . ': ' . $e->getMessage(), 'permits');
        }

        $permitSlots[] = [
            'slot' => $slotCounter,
            'file' => $file,
            'ext'  => $ext,
        ];
    }
}

if (empty($permitSlots)) {
    respondError('Please upload at least one permit photo.', 'permits');
}

/* =============================================================
 * TRANSACTION
 * ============================================================= */

$writtenFiles = [];

try {
    if (restaurantEmailExists($database_connection, $email)) {
        respondError('This email address is already registered.', 'email');
    }
    if (restaurantUsernameExists($database_connection, $username)) {
        respondError('This username is already taken.', 'username');
    }
    if (restaurantContactExists($database_connection, $cleanedContact)) {
        respondError('This mobile number is already registered.', 'contact_number');
    }
    if (restaurantBusinessNameExists($database_connection, $businessName)) {
        respondError('This business name is already registered.', 'business_name');
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

    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
    createRestaurantOwnerAccount($database_connection, $restaurantId, [
        'first_name'     => $firstName,
        'middle_name'    => $middleName,
        'last_name'      => $lastName,
        'email'          => $email,
        'contact_number' => $cleanedContact,
        'username'       => $username,
        'password'       => $hashedPassword,
    ]);

    // Move the permit files into the restaurant-keyed permit folder.
    foreach ($permitSlots as $slot) {
        $written = storePermitSlot(
            $slot['file'],
            $restaurantId,
            (int)$slot['slot'],
            $slot['ext']
        );
        $writtenFiles[] = $written;
    }

    // Insert one restaurant_permit row per uploaded permit, with a
    // generated non-null original_name.
    $folderPath   = permitRelativeFolder($restaurantId);
    $totalSlots   = count($permitSlots);
    $batchStamp   = time();

    foreach ($permitSlots as $slot) {
        $originalName = buildPermitOriginalName(
            $restaurantId,
            (int)$slot['slot'],
            $totalSlots,
            $batchStamp
        );

        insertRestaurantPermitRow(
            $database_connection,
            $restaurantId,
            $folderPath,
            $originalName,
            (int)$slot['slot']
        );
    }

    $database_connection->commit();

    unset($_SESSION['restaurant_csrf_token']);

    $_SESSION['registration_success'] =
        'Restaurant application submitted with ' . count($permitSlots) . ' permit(s). ' .
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
    foreach ($writtenFiles as $p) {
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
    foreach ($writtenFiles as $p) {
        if (is_file($p)) @unlink($p);
    }
    error_log('Restaurant registration error: ' . $e->getMessage());
    respondError('An unexpected error occurred. Please try again later.');
}