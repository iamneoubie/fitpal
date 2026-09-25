<?php
/**
 * FitPal Rider Registration Handler
 *
 * Request flow:
 *   1. Method + CSRF guard.
 *   2. Collect and validate every scalar field.
 *   3. Validate the two uploads.
 *   4. Uniqueness probes (query layer).
 *   5. Move the two uploads to their final folders.
 *   6. createRiderAccount() writes the four core rows.
 *   7. insertRiderEmergencyContact() and insertRiderDocument()
 *      write the two child rows.
 *   8. Session flash + JSON success.
 *
 * All SQL lives in rider-queries.php. This file contains no
 * prepare() calls, no SQL strings, and no password hashing.
 *
 * Responds with JSON. The rider is NOT logged in after registration.
 *
 * @package FitPal
 * @version 4.0 — Full refactor. Every inline SQL statement and the
 *                password_hash() call were removed and replaced with
 *                calls into rider-queries.php:
 *                  - riderEmailExists / riderUsernameExists /
 *                    riderContactExists for the uniqueness probes.
 *                  - createRiderAccount for the four core inserts
 *                    (financial_account, delivery_rider,
 *                    delivery_rider_profile, delivery_rider_address),
 *                    including the password hash.
 *                  - insertRiderEmergencyContact and
 *                    insertRiderDocument, which already lived in the
 *                    query file and are unchanged.
 *
 *                The two upload files are now moved BEFORE the
 *                createRiderAccount() call, so filenames no longer
 *                embed the rider id (the id does not exist yet).
 *                Paths are stored as-is; the DB does not care about
 *                the filename shape. This removes the need for an
 *                extra UPDATE and keeps the transaction boundary
 *                entirely inside the query layer.
 *
 *                No behavioral change: same JSON responses, same
 *                field names on error, same redirect target, same
 *                CSRF validation, same sign-out-safe token rotation,
 *                same cleanup of moved files on failure.
 *
 *                (3.6: Removed the dead $_SESSION
 *                ['rider_pending_application'] write. 3.5: Owns its
 *                own CSRF bootstrap and validates against
 *                rider_csrf_token with isset() on both keys before
 *                hash_equals().)
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/rider-queries.php';

// Own the rider role's CSRF bootstrap. The helper is idempotent and
// stores the token under 'rider_csrf_token' — never the shared
// 'csrf_token' key.
require_once __DIR__ . '/../../includes/rider-csrf-token.php';

header('Content-Type: application/json');

/* --------------------------------------------------------------
 * HELPERS (request-layer only — no SQL, no DB access)
 * -------------------------------------------------------------- */

/**
 * Terminate with a JSON error payload.
 *
 * @return never
 */
function respondError(string $message, string $field = ''): void
{
    echo json_encode([
        'status'  => 'error',
        'message' => $message,
        'field'   => $field,
    ]);
    exit;
}

/**
 * Validate an uploaded file and return its extension + mime type.
 *
 * @param array  $file        $_FILES entry
 * @param int    $maxBytes
 * @param array<string,string> $allowedMime  map of mime => ext
 * @return array{ext:string, mime:string}
 * @throws RuntimeException
 */
function validateUpload(array $file, int $maxBytes, array $allowedMime): array
{
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new RuntimeException('Invalid upload payload.');
    }
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('No file uploaded.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed (code ' . $file['error'] . ').');
    }
    if (!isset($file['size']) || $file['size'] <= 0) {
        throw new RuntimeException('Uploaded file is empty.');
    }
    if ($file['size'] > $maxBytes) {
        throw new RuntimeException(
            'File size must be under ' . round($maxBytes / 1048576, 1) . ' MB.'
        );
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        throw new RuntimeException('Could not inspect file.');
    }
    $mime = (string)finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!isset($allowedMime[$mime])) {
        throw new RuntimeException('Unsupported file type. Use JPG, PNG, or WEBP.');
    }

    return ['ext' => $allowedMime[$mime], 'mime' => $mime];
}

/**
 * Move an uploaded file into its final folder and return the
 * project-root-relative path stored in the DB.
 *
 * The rider id is not available at call time (the caller moves the
 * files before createRiderAccount() runs), so the filename is built
 * from the basename + a timestamp + a random hex suffix. Uniqueness
 * is guaranteed by the random suffix.
 *
 * @throws RuntimeException
 */
function storeUpload(
    array $file,
    string $projectRoot,
    string $subfolder,
    string $basename,
    string $ext
): string {
    $dir = $projectRoot . '/shared/uploads/' . $subfolder;

    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create upload directory.');
    }

    $filename = $basename . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $fullPath = $dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
        throw new RuntimeException('Could not save file.');
    }

    return 'shared/uploads/' . $subfolder . '/' . $filename;
}

/* --------------------------------------------------------------
 * REQUEST GUARDS
 * -------------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Invalid request method.');
}

if (
    !isset($_POST['csrf_token'], $_SESSION['rider_csrf_token']) ||
    !hash_equals((string)$_SESSION['rider_csrf_token'], (string)$_POST['csrf_token'])
) {
    // Rotate the rider's own token so the next render generates a
    // fresh one. Only the rider's key is cleared — never the shared
    // 'csrf_token' key.
    unset($_SESSION['rider_csrf_token']);

    respondError('Security validation failed. Please refresh the page and try again.');
}

/* --------------------------------------------------------------
 * COLLECT INPUT
 * -------------------------------------------------------------- */

$firstName       = trim((string)($_POST['first_name'] ?? ''));
$middleName      = trim((string)($_POST['middle_name'] ?? ''));
$lastName        = trim((string)($_POST['last_name'] ?? ''));
$birthdate       = trim((string)($_POST['birthdate'] ?? ''));
$gender          = trim((string)($_POST['gender'] ?? ''));
$email           = trim((string)($_POST['email'] ?? ''));
$contactNumber   = trim((string)($_POST['contact_number'] ?? ''));
$username        = trim((string)($_POST['username'] ?? ''));
$password        = (string)($_POST['password'] ?? '');
$confirmPassword = (string)($_POST['confirm_password'] ?? '');
$terms           = $_POST['terms'] ?? '';

$vehicleType  = trim((string)($_POST['vehicle_type'] ?? ''));
$vehiclePlate = trim((string)($_POST['vehicle_plate'] ?? ''));
$vehicleMake  = trim((string)($_POST['vehicle_make'] ?? ''));
$vehicleModel = trim((string)($_POST['vehicle_model'] ?? ''));
$vehicleYear  = trim((string)($_POST['vehicle_year'] ?? ''));

$licenseIssue  = trim((string)($_POST['license_issue_date'] ?? ''));
$licenseExpiry = trim((string)($_POST['license_expiry_date'] ?? ''));

$block      = trim((string)($_POST['block'] ?? ''));
$barangay   = trim((string)($_POST['barangay'] ?? ''));
$city       = trim((string)($_POST['city'] ?? ''));
$province   = trim((string)($_POST['province'] ?? ''));
$region     = trim((string)($_POST['region'] ?? ''));
$postalCode = trim((string)($_POST['postal_code'] ?? ''));

$ecFirstName    = trim((string)($_POST['emergency_first_name'] ?? ''));
$ecMiddleName   = trim((string)($_POST['emergency_middle_name'] ?? ''));
$ecLastName     = trim((string)($_POST['emergency_last_name'] ?? ''));
$ecRelationship = trim((string)($_POST['emergency_relationship'] ?? ''));
$ecContact      = trim((string)($_POST['emergency_contact'] ?? ''));

/* --------------------------------------------------------------
 * REQUIRED FIELD CHECK
 * -------------------------------------------------------------- */

if (
    $firstName === '' || $lastName === '' || $birthdate === '' ||
    $gender === '' || $email === '' || $contactNumber === '' ||
    $username === '' || $password === '' || $vehicleType === '' ||
    $block === '' || $city === '' ||
    $ecFirstName === '' || $ecLastName === '' ||
    $ecRelationship === '' || $ecContact === ''
) {
    respondError('Please fill in all required fields.');
}

if ($licenseIssue === '') {
    respondError('Please enter the license issue date.', 'license_issue_date');
}
if ($licenseExpiry === '') {
    respondError('Please enter the license expiry date.', 'license_expiry_date');
}

/* --------------------------------------------------------------
 * FIELD VALIDATION
 * -------------------------------------------------------------- */

$namePattern = "/^[A-Za-z\s\-']+$/u";

if (strlen($firstName) < 2 || !preg_match($namePattern, $firstName)) {
    respondError('First name contains invalid characters.', 'first_name');
}
if (strlen($lastName) < 2 || !preg_match($namePattern, $lastName)) {
    respondError('Last name contains invalid characters.', 'last_name');
}
if (!in_array($gender, ['Male', 'Female', 'Other'], true)) {
    respondError('Invalid gender selection.', 'gender');
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

try {
    $bd    = new DateTime($birthdate);
    $today = new DateTime();
    $age   = $today->diff($bd)->y;
    if ($age < 18) respondError('You must be at least 18 years old to register as a rider.', 'birthdate');
    if ($age > 70) respondError('Please enter a valid date of birth.', 'birthdate');
} catch (Exception $e) {
    respondError('Please enter a valid date of birth.', 'birthdate');
}

$allowedVehicles = ['motorcycle', 'scooter', 'car', 'van', 'bicycle'];
if (!in_array($vehicleType, $allowedVehicles, true)) {
    respondError('Invalid vehicle type.', 'vehicle_type');
}
if ($vehicleType !== 'bicycle' && $vehiclePlate === '') {
    respondError('Plate number is required for motor vehicles.', 'vehicle_plate');
}
if ($vehiclePlate !== '') {
    $vehiclePlate = strtoupper($vehiclePlate);
    if (strlen(str_replace([' ', '-'], '', $vehiclePlate)) < 4) {
        respondError('Plate number looks too short.', 'vehicle_plate');
    }
}

/*
 * Vehicle year, make, and model are validated for shape only. The
 * delivery_rider_profile schema has no columns for them, so the
 * values are dropped after validation. If/when the schema gains
 * those columns, pass them through the profile array into
 * createRiderAccount().
 */
if ($vehicleYear !== '') {
    if (!ctype_digit($vehicleYear)) {
        respondError('Vehicle year must be numeric.', 'vehicle_year');
    }
    $y = (int)$vehicleYear;
    $maxYear = (int)date('Y') + 1;
    if ($y < 1980 || $y > $maxYear) {
        respondError('Please enter a valid vehicle year.', 'vehicle_year');
    }
}
if ($vehicleMake !== '' && preg_match('/[\x00-\x1F\x7F]/', $vehicleMake)) {
    respondError('Vehicle make contains invalid characters.', 'vehicle_make');
}
if ($vehicleModel !== '' && preg_match('/[\x00-\x1F\x7F]/', $vehicleModel)) {
    respondError('Vehicle model contains invalid characters.', 'vehicle_model');
}

try {
    $issue = new DateTime($licenseIssue);
    if ($issue > new DateTime()) {
        respondError('License issue date cannot be in the future.', 'license_issue_date');
    }
    $issueDate = $issue->format('Y-m-d');
} catch (Exception $e) {
    respondError('Invalid license issue date.', 'license_issue_date');
}

try {
    $expiry = new DateTime($licenseExpiry);
    if ($expiry <= new DateTime()) {
        respondError('License expiry date must be in the future.', 'license_expiry_date');
    }
    if ($expiry <= new DateTime($issueDate)) {
        respondError('Expiry date must be after the issue date.', 'license_expiry_date');
    }
    $expiryDate = $expiry->format('Y-m-d');
} catch (Exception $e) {
    respondError('Invalid license expiry date.', 'license_expiry_date');
}

if ($postalCode !== '' && !preg_match('/^[0-9]{3,10}$/', $postalCode)) {
    respondError('Postal code must be numeric.', 'postal_code');
}

if (strlen($ecFirstName) < 2 || !preg_match($namePattern, $ecFirstName)) {
    respondError('Emergency contact first name contains invalid characters.', 'emergency_first_name');
}
if (strlen($ecLastName) < 2 || !preg_match($namePattern, $ecLastName)) {
    respondError('Emergency contact last name contains invalid characters.', 'emergency_last_name');
}

$allowedRelationships = ['Parent', 'Spouse', 'Sibling', 'Relative', 'Friend', 'Other'];
if (!in_array($ecRelationship, $allowedRelationships, true)) {
    respondError('Invalid emergency contact relationship.', 'emergency_relationship');
}

$cleanedEcContact = preg_replace('/\s+/', '', $ecContact);
if (!preg_match('/^09\d{9}$/', (string)$cleanedEcContact)) {
    respondError('Emergency contact must be a valid PH mobile number (09XXXXXXXXX).', 'emergency_contact');
}

if (empty($terms)) {
    respondError('You must agree to the Terms and Conditions and Privacy Policy.', 'terms');
}

/* --------------------------------------------------------------
 * FILE UPLOAD VALIDATION
 * -------------------------------------------------------------- */

$allowedImages = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

$profileFile = $_FILES['profile_picture'] ?? null;
$licenseFile = $_FILES['drivers_license'] ?? null;

if (!$profileFile || ($profileFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    respondError('Please upload a formal profile picture.', 'profile_picture');
}
if (!$licenseFile || ($licenseFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    respondError("Please upload a photo of your driver's license.", 'drivers_license');
}

try {
    $profileMeta = validateUpload($profileFile, 2 * 1024 * 1024, $allowedImages);
} catch (RuntimeException $e) {
    respondError($e->getMessage(), 'profile_picture');
}

try {
    $licenseMeta = validateUpload($licenseFile, 5 * 1024 * 1024, $allowedImages);
} catch (RuntimeException $e) {
    respondError($e->getMessage(), 'drivers_license');
}

/* --------------------------------------------------------------
 * UNIQUENESS PROBES (query layer)
 * -------------------------------------------------------------- */

try {
    if (riderEmailExists($database_connection, $email)) {
        respondError('This email address is already registered.', 'email');
    }
    if (riderUsernameExists($database_connection, $username)) {
        respondError('This username is already taken.', 'username');
    }
    if (riderContactExists($database_connection, (string)$cleanedContact)) {
        respondError('This mobile number is already registered.', 'contact_number');
    }
} catch (PDOException $e) {
    error_log('Rider registration lookup error: ' . $e->getMessage());
    respondError('An unexpected error occurred. Please try again later.');
}

/* --------------------------------------------------------------
 * MOVE UPLOADS
 *
 * Files are moved before createRiderAccount() runs, so the
 * filenames cannot embed the rider id. The random hex suffix
 * guarantees uniqueness. If the DB insert later fails, the catch
 * blocks below unlink whatever was moved.
 * -------------------------------------------------------------- */

$projectRoot = realpath(__DIR__ . '/../../..');
if ($projectRoot === false) {
    respondError('Server storage path unavailable.');
}

$movedFiles  = [];
$profilePath = '';
$licensePath = '';

try {
    $profilePath = storeUpload(
        $profileFile,
        $projectRoot,
        'rider-profiles',
        'rider_profile',
        $profileMeta['ext']
    );
    $movedFiles[] = $projectRoot . '/' . $profilePath;

    $licensePath = storeUpload(
        $licenseFile,
        $projectRoot,
        'rider-documents',
        'rider_license',
        $licenseMeta['ext']
    );
    $movedFiles[] = $projectRoot . '/' . $licensePath;

} catch (RuntimeException $e) {
    foreach ($movedFiles as $p) {
        if (is_file($p)) @unlink($p);
    }
    respondError($e->getMessage());
}

/* --------------------------------------------------------------
 * PERSISTENCE (query layer)
 *
 * createRiderAccount() runs the four core inserts inside its own
 * transaction. The two child inserts (emergency contact and
 * document) happen after it commits. This file does not call
 * prepare() and does not open or commit transactions of its own.
 * -------------------------------------------------------------- */

try {
    $created = createRiderAccount(
        $database_connection,
        [
            'first_name'     => $firstName,
            'middle_name'    => $middleName,
            'last_name'      => $lastName,
            'birthdate'      => $birthdate,
            'gender'         => $gender,
            'email'          => $email,
            'contact_number' => (string)$cleanedContact,
            'username'       => $username,
            'password'       => $password,
        ],
        [
            'profile_picture' => $profilePath,
            'vehicle_type'    => $vehicleType,
            'vehicle_plate'   => $vehiclePlate,
        ],
        [
            'block'       => $block,
            'barangay'    => $barangay,
            'city'        => $city,
            'province'    => $province,
            'region'      => $region,
            'postal_code' => $postalCode,
        ]
    );

    $deliveryRiderId = (int)$created['delivery_rider_id'];

    insertRiderEmergencyContact($database_connection, $deliveryRiderId, [
        'first_name'     => $ecFirstName,
        'middle_name'    => $ecMiddleName,
        'last_name'      => $ecLastName,
        'contact_number' => $cleanedEcContact,
        'relationship'   => $ecRelationship,
        'address'        => '',
    ]);

    insertRiderDocument($database_connection, $deliveryRiderId, [
        'drivers_license' => $licensePath,
        'issue_date'      => $issueDate,
        'expiry_date'     => $expiryDate,
    ]);

} catch (PDOException $e) {
    foreach ($movedFiles as $p) {
        if (is_file($p)) @unlink($p);
    }

    error_log('Rider registration DB error: ' . $e->getMessage());

    $message = $e->getMessage();
    if (stripos($message, 'Duplicate entry') !== false) {
        if (stripos($message, 'email') !== false) {
            respondError('This email address is already registered.', 'email');
        }
        if (stripos($message, 'username') !== false) {
            respondError('This username is already taken.', 'username');
        }
        if (stripos($message, 'contact_number') !== false) {
            respondError('This mobile number is already registered.', 'contact_number');
        }
    }
    respondError('An unexpected error occurred. Please try again later.');

} catch (Throwable $e) {
    foreach ($movedFiles as $p) {
        if (is_file($p)) @unlink($p);
    }

    error_log('Rider registration error: ' . $e->getMessage());
    respondError('An unexpected error occurred. Please try again later.');
}

/* --------------------------------------------------------------
 * SUCCESS
 * -------------------------------------------------------------- */

// Only clear rider's own token. Do not touch the shared
// 'csrf_token' key or any other role's token — another role in
// this same browser session may still be relying on it.
unset($_SESSION['rider_csrf_token']);

$_SESSION['registration_success'] = 'Rider application submitted. Please sign in to continue.';

echo json_encode([
    'status'   => 'success',
    'message'  => 'Your rider application has been submitted. Please sign in to continue.',
    'redirect' => 'sign-in.php',
]);
exit;