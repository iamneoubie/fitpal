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
 * ---------------------------------------------------------------------
 * UPLOAD LAYOUT (v3.0)
 * ---------------------------------------------------------------------
 * Permit files are written to:
 *
 *     shared/uploads/restaurant/permits/<restaurant_account_id>/MM_DD_YYYY_<n>.<ext>
 *
 * The <n> counter is scoped to the account and the day. On the first
 * upload of a given day for a given account it is 0; on each
 * subsequent upload on the same day it increments. The counter is
 * derived by scanning the destination folder for files whose names
 * begin with today's MM_DD_YYYY prefix and picking the smallest
 * non-negative integer that is not already in use.
 *
 * This mirrors the rider role's per-account, per-day layout exactly
 * (see rider/backend/handlers/rider-handler.php and
 * rider/backend/handlers/sign-up-handler.php).
 *
 * ---------------------------------------------------------------------
 * CHICKEN-AND-EGG ORDERING
 * ---------------------------------------------------------------------
 * The per-account folder name depends on $ownerAccountId, which does
 * not exist until createRestaurantOwnerAccount() commits. So the
 * permit files are moved AFTER the owner account is created and
 * BEFORE the restaurant_permit rows are inserted.
 *
 * The full write order is:
 *
 *   1. createRestaurant()
 *   2. createRestaurantFinancialAccount()
 *   3. createRestaurantBranch()
 *   4. createRestaurantOwnerAccount()   → $ownerAccountId now exists
 *   5. storePermitFile() × N            → files land under
 *                                          permits/<ownerAccountId>/
 *   6. createRestaurantPermit() × N     → rows point at those paths
 *   7. COMMIT
 *
 * If any step after 5 fails, the moved files are unlinked and the
 * transaction is rolled back, so the account row never survives
 * without its permits.
 *
 * The previous revision created the owner account AFTER moving the
 * files, which is why it could not key the folder on the account id
 * — it did not have one yet. The reorder above is what makes the
 * per-account layout possible.
 *
 * ---------------------------------------------------------------------
 * PROFILE PICTURE PATH (RESERVED)
 * ---------------------------------------------------------------------
 * The schema has no profile_picture column on any restaurant table
 * (restaurant, restaurant_account, restaurant_branch). The rider role
 * has one on delivery_rider_profile; the restaurant role does not.
 *
 * The intended location for a restaurant profile picture, if the
 * schema is later extended, is:
 *
 *     shared/uploads/restaurant/profiles/<restaurant_account_id>/MM_DD_YYYY_<n>.<ext>
 *
 * That path is the same shape as the permit path, one level of
 * "kind" apart, and matches the rider role's profiles/documents
 * split. No code here writes to it, because there is no column to
 * record the resulting path.
 *
 * A helper, storeAccountUpload(), is provided below and is ready to
 * serve either kind ('permits' or 'profiles') once the schema has
 * somewhere to store the profile result.
 *
 * @package FitPal
 * @version 3.0 — Per-account, per-day permit layout:
 *                  - storePermitFile() replaced by
 *                    storeAccountUpload(), which takes the account id
 *                    and a kind ('permits' or 'profiles') and writes
 *                    under shared/uploads/restaurant/<kind>/<id>/.
 *                  - The MM_DD_YYYY_<n> counter logic is lifted
 *                    verbatim from the rider role's
 *                    buildRiderUploadFilename().
 *                  - createRestaurantOwnerAccount() is now called
 *                    BEFORE the permits are stored, so the account id
 *                    is available as the folder key.
 *                  - On failure, moved files are unlinked and the
 *                    transaction is rolled back.
 *                  - No other behavior changed. CSRF still validated
 *                    against restaurant_csrf_token; response shape
 *                    unchanged.
 *
 *                (2.0: own CSRF bootstrap, rotate on mismatch.
 *                1.2: restaurant_csrf_token. 1.0: initial.)
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

/**
 * Validate an uploaded image file and return its extension.
 *
 * @param array $file  $_FILES entry
 * @return string      Lowercase extension without a dot
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
 * Build the next MM_DD_YYYY_<n>.<ext> filename for a destination
 * folder.
 *
 * The counter is scoped to the folder and the day. Files from
 * previous days are excluded by the prefix match and never enter the
 * count, so the counter restarts at 0 on the next calendar day.
 *
 * This is the same shape the rider role uses in
 * rider/backend/handlers/rider-handler.php's buildRiderUploadFilename().
 *
 * @param string $uploadDir  Absolute path to the destination folder.
 * @param string $ext        Lowercase extension without a dot.
 * @return string            Filename, e.g. "09_28_2026_0.jpg".
 */
function buildAccountUploadFilename(string $uploadDir, string $ext): string
{
    $dayPrefix = date('m_d_Y');

    $existing = @scandir($uploadDir);
    if ($existing === false) {
        $existing = [];
    }

    $usedIndexes  = [];
    $prefixLength = strlen($dayPrefix) + 1; // include trailing '_'

    foreach ($existing as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (strpos($entry, $dayPrefix . '_') !== 0) {
            continue;
        }

        $rest   = substr($entry, $prefixLength);
        $dotPos = strpos($rest, '.');
        if ($dotPos === false) {
            continue;
        }

        $counterPart = substr($rest, 0, $dotPos);
        if ($counterPart === '' || !ctype_digit($counterPart)) {
            continue;
        }

        $usedIndexes[(int)$counterPart] = true;
    }

    $nextIndex = 0;
    while (isset($usedIndexes[$nextIndex])) {
        $nextIndex++;
    }

    return $dayPrefix . '_' . $nextIndex . '.' . $ext;
}

/**
 * Move an uploaded image into its final per-account folder and return
 * the project-root-relative path stored in the DB.
 *
 * Layout:
 *
 *     <projectRoot>/shared/uploads/restaurant/<kind>/<accountId>/
 *         MM_DD_YYYY_<n>.<ext>
 *
 * $kind is either 'permits' or 'profiles'. The per-account
 * subdirectory is created on demand with mkdir(..., 0755, true). The
 * recursive flag creates the intermediate `restaurant/` and `<kind>/`
 * segments the first time any restaurant uploads, and the per-account
 * leaf when that account first uploads.
 *
 * The per-account segment is a plain integer taken from a caller-
 * supplied argument, so it cannot contain traversal characters.
 * Every path segment below is either a literal or a caller-derived
 * integer — nothing from $_FILES or $_POST reaches the path.
 *
 * @param array  $file        $_FILES entry (already validated)
 * @param string $projectRoot Absolute path to the project root
 * @param string $kind        'permits' or 'profiles'
 * @param int    $accountId   restaurant_account_id (positive integer)
 * @param string $ext         Lowercase extension without a dot
 * @return string             Project-root-relative path
 * @throws RuntimeException
 */
function storeAccountUpload(
    array $file,
    string $projectRoot,
    string $kind,
    int $accountId,
    string $ext
): string {
    if (!in_array($kind, ['permits', 'profiles'], true)) {
        throw new RuntimeException('Invalid upload kind.');
    }
    if ($accountId <= 0) {
        throw new RuntimeException('Invalid account id for upload.');
    }

    $relativeDir = 'shared/uploads/restaurant/' . $kind . '/' . $accountId;
    $uploadDir   = $projectRoot . '/' . $relativeDir;

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Could not create upload directory.');
        }
    }

    $filename = buildAccountUploadFilename($uploadDir, $ext);

    $fullPath = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
        throw new RuntimeException('Could not save file.');
    }

    return $relativeDir . '/' . $filename;
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
 * PERMIT FILE VALIDATION (validation only — files not moved yet)
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
 * DATABASE TRANSACTION + FILE MOVES
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

    // 1. Restaurant row
    $restaurantId = createRestaurant($database_connection, [
        'business_name' => $businessName,
        'description'   => $businessDescription,
        'cuisine_type'  => $cuisineType,
        'dietary_tags'  => $dietaryTags,
    ]);

    // 2. Financial account for the branch
    $financialAccountId = createRestaurantFinancialAccount($database_connection);

    // 3. Branch
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

    // 4. Owner account — this is what gives us the folder key.
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
    $ownerAccountId = createRestaurantOwnerAccount($database_connection, $restaurantId, [
        'first_name'     => $firstName,
        'middle_name'    => $middleName,
        'last_name'      => $lastName,
        'email'          => $email,
        'contact_number' => (string)$cleanedContact,
        'username'       => $username,
        'password'       => $hashedPassword,
    ]);

    // 5. Move the permit files into the per-account folder.
    //    Layout: shared/uploads/restaurant/permits/<ownerAccountId>/
    foreach ($permitFiles as $p) {
        $path = storeAccountUpload(
            $p['file'],
            $projectRoot,
            'permits',
            $ownerAccountId,
            $p['ext']
        );
        $movedFiles[] = $projectRoot . '/' . $path;

        // 6. Record the permit row pointing at the moved file.
        createRestaurantPermit(
            $database_connection,
            $restaurantId,
            $path,
            (string)$p['file']['name'],
            count($movedFiles) - 1
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