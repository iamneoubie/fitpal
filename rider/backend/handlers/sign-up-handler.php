<?php
/**
 * FitPal Rider Registration Handler
 *
 * Request flow:
 *   1. Method + CSRF guard.
 *   2. Collect and validate every scalar field.
 *   3. Determine the correct identity document type from the
 *      vehicle the applicant selected:
 *        - bicycle             → 'national_id'
 *        - anything else       → 'drivers_license'
 *      License issue/expiry dates are only required for the
 *      'drivers_license' branch. A bicycle applicant cannot legally
 *      hold a driver's license for their vehicle, so both dates are
 *      accepted as empty and are stored as NULL.
 *   4. Validate the two uploads.
 *   5. Uniqueness probes (query layer).
 *   6. Move the two uploads into their final per-rider folders,
 *      using the shared MM_DD_YYYY_<n>.<ext> naming scheme.
 *   7. createRiderAccount() writes the four core rows.
 *   8. insertRiderEmergencyContact() and insertRiderDocument()
 *      write the two child rows.
 *   9. Session flash + JSON success.
 *
 * All SQL lives in rider-queries.php. This file contains no
 * prepare() calls, no SQL strings, and no password hashing.
 *
 * Responds with JSON. The rider is NOT logged in after registration.
 *
 * Phone-number normalization
 * --------------------------
 * Both the rider's own contact_number and the emergency contact's
 * contact_number are reduced to their digit-only form via
 * preg_replace('/\D+/', '', ...) — the same rule the client-side
 * normalizeDigits() uses in sign-up.js.
 *
 * The previous revision used preg_replace('/\s+/', '', ...) which
 * strips only whitespace. A phone like "0917-123-4567" survived
 * with its hyphens intact and was then rejected by the
 * ^09\d{9}$ format check — even though the same digits without
 * hyphens would have been accepted. Switching to /\D+/ closes that
 * gap and lets the two numbers be compared as plain digit strings.
 *
 * Emergency contact must differ from the rider's own number
 * ---------------------------------------------------------
 * A new comparison runs after both numbers pass their format
 * checks: if the digit-only forms are equal and non-empty, the
 * handler refuses the request with field='emergency_contact'. This
 * is the server-side counterpart to validateStep3() in sign-up.js.
 * The client layer is a UX nicety; this layer is the one that
 * cannot be bypassed by a caller posting directly to the endpoint.
 *
 * Upload layout
 * -------------
 * Both files are written under shared/uploads/rider/, matching the
 * profile-handler.php layout used by the customer role:
 *
 *     shared/uploads/rider/profiles/<rider_id>/MM_DD_YYYY_<n>.<ext>
 *     shared/uploads/rider/documents/<rider_id>/MM_DD_YYYY_<n>.<ext>
 *
 * The <n> counter is scoped to the rider and the day. On the first
 * upload of a given day it is 0; on each subsequent upload on the
 * same day it increments. The counter is derived by scanning the
 * destination folder for files whose names begin with today's
 * MM_DD_YYYY prefix and picking the smallest non-negative integer
 * that is not already in use.
 *
 * Chicken-and-egg
 * ---------------
 * The per-rider folder name depends on $deliveryRiderId, which does
 * not exist until createRiderAccount() commits. So the two uploads
 * are moved AFTER createRiderAccount() and BEFORE the two child
 * inserts. If any of the child inserts fail, the moved files are
 * unlinked and the rider row is left in place with no document —
 * an admin can re-request the document, whereas a rider with no
 * account at all cannot be recovered without an admin action.
 *
 * To keep the window small, the rider is created first, the files
 * are moved next, and both child rows are inserted immediately
 * after. Failure at any step in that sequence unlinks whatever
 * files were moved in this request.
 *
 * @package FitPal
 * @version 5.1 — Two changes for the emergency-contact rule:
 *
 *                - Both $cleanedContact and $cleanedEcContact now
 *                  strip every non-digit character (/\D+/) instead
 *                  of only whitespace (/\s+/). This makes the
 *                  server's normalization match the client's
 *                  normalizeDigits() and lets a caller who posts
 *                  "0917-123-4567" directly to the endpoint be
 *                  accepted as the valid number it is, rather than
 *                  rejected on a formatting technicality.
 *
 *                - A new equality check between the two digit-only
 *                  numbers runs right after the emergency contact
 *                  passes its format check. A match is refused with
 *                  field='emergency_contact', matching the client's
 *                  error slot so the message lands on the right
 *                  input.
 *
 *                No other behavior changed from v5.0. The upload
 *                layout, the id_type mapping, the license-date
 *                rules, the CSRF contract, the JSON response shape,
 *                and the redirect target are unchanged.
 *
 *                (5.0: rewritten for the generalized rider document
 *                schema and the per-rider upload layout. 4.0: full
 *                refactor — every inline SQL statement and the
 *                password_hash() call were removed and replaced with
 *                calls into rider-queries.php. 3.6: removed the dead
 *                $_SESSION['rider_pending_application'] write.
 *                3.5: owns its own CSRF bootstrap and validates
 *                against rider_csrf_token.)
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
 * Move an uploaded file into its final per-rider folder and return
 * the project-root-relative path stored in the DB.
 *
 * Layout:
 *     <projectRoot>/shared/uploads/rider/<kind>/<riderId>/
 *         MM_DD_YYYY_<n>.<ext>
 *
 * The per-rider subdirectory is created on demand with
 * mkdir(..., 0755, true). The recursive flag creates the
 * intermediate `rider/` and `<kind>/` segments the first time any
 * rider uploads, and the per-rider leaf when that rider first
 * uploads.
 *
 * The counter is derived by scanning the destination folder for
 * files whose names begin with today's MM_DD_YYYY prefix and
 * picking the smallest non-negative integer that is not already in
 * use. Files from previous days are excluded by the prefix match
 * and never enter the count, so the counter restarts at 0 on the
 * next calendar day naturally.
 *
 * The per-rider segment is a plain integer taken from a caller-
 * supplied argument, so it cannot contain traversal characters.
 * Every path segment below is either a literal or a caller-derived
 * integer — nothing from $_FILES or $_POST reaches the path.
 *
 * @param array  $file      $_FILES entry (already validated)
 * @param string $projectRoot Absolute path to the project root
 * @param string $kind      'profiles' or 'documents'
 * @param int    $riderId   The rider's id (positive integer)
 * @param string $ext       Lowercase extension without a dot
 * @return string           Project-root-relative path
 * @throws RuntimeException
 */
function storeUploadForRider(
    array $file,
    string $projectRoot,
    string $kind,
    int $riderId,
    string $ext
): string {
    $relativeDir = 'shared/uploads/rider/' . $kind . '/' . $riderId;
    $uploadDir   = $projectRoot . '/' . $relativeDir;

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Could not create upload directory.');
        }
    }

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

    $filename = $dayPrefix . '_' . $nextIndex . '.' . $ext;
    $fullPath = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
        throw new RuntimeException('Could not save file.');
    }

    return $relativeDir . '/' . $filename;
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

// Strip every non-digit character so "0917-123-4567", "0917 123
// 4567", and "09171234567" all normalize to the same string. This
// matches the client-side normalizeDigits() rule in sign-up.js, so
// the two layers agree on what "the same number" means and neither
// rejects a number the other accepts on a formatting technicality.
$cleanedContact = preg_replace('/\D+/', '', $contactNumber);
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
 * values are dropped after validation.
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

/* --------------------------------------------------------------
 * IDENTITY DOCUMENT TYPE
 *
 * A bicycle rider cannot legally hold a driver's license for their
 * vehicle, so they must submit a different government-issued ID.
 * Every other vehicle type maps to a driver's license.
 * -------------------------------------------------------------- */

$isBicycle = ($vehicleType === 'bicycle');
$idType    = $isBicycle ? 'national_id' : 'drivers_license';

// Issue date and expiry date are only required when the applicant
// is submitting a driver's license. A bicycle applicant's national
// ID does not carry either date on its face, so both fields are
// accepted empty and stored as NULL.
$issueDate  = '';
$expiryDate = '';

if (!$isBicycle) {
    if ($licenseIssue === '') {
        respondError('Please enter the license issue date.', 'license_issue_date');
    }
    if ($licenseExpiry === '') {
        respondError('Please enter the license expiry date.', 'license_expiry_date');
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
} else {
    // Bicycle path: if the applicant happened to fill in dates
    // (e.g. from a cached form), drop them. The document type does
    // not accept them, and insertRiderDocument() stores NULL.
    $issueDate  = '';
    $expiryDate = '';
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

// Same normalization rule as the rider's own number above. This
// lets the two digit-only strings be compared directly, and lets a
// caller who posts a formatted number be accepted on its digits.
$cleanedEcContact = preg_replace('/\D+/', '', $ecContact);
if (!preg_match('/^09\d{9}$/', (string)$cleanedEcContact)) {
    respondError('Emergency contact must be a valid PH mobile number (09XXXXXXXXX).', 'emergency_contact');
}

// The emergency contact number must differ from the rider's own
// number. A rider's emergency contact is by definition a different
// person, so the two phone numbers cannot be the same. Both sides
// are digit-only at this point, so the comparison is stable across
// any formatting the client or a direct caller might have used.
//
// The check is only meaningful when both numbers are non-empty.
// Both have already passed their ^09\d{9}$ format checks above, so
// by this point both are guaranteed to be eleven digits — the
// empty-string guard is defensive, not load-bearing.
if (
    $cleanedContact !== '' &&
    $cleanedEcContact !== '' &&
    $cleanedContact === $cleanedEcContact
) {
    respondError(
        'Emergency contact number must be different from your own contact number.',
        'emergency_contact'
    );
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
    // Error field name stays 'drivers_license' because that is the
    // name of the input in the sign-up form. The user-facing
    // message reflects the actual document type expected for the
    // selected vehicle.
    $idFieldError = $isBicycle
        ? 'Please upload a photo of a valid government-issued ID.'
        : "Please upload a photo of your driver's license.";
    respondError($idFieldError, 'drivers_license');
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
 * PERSISTENCE — account first, then uploads, then children
 *
 * The per-rider upload folders are named after the rider id, so
 * they cannot be created until the rider row exists. The order is:
 *
 *   1. createRiderAccount()      — the rider row and its three
 *                                  sibling rows commit here.
 *   2. storeUploadForRider() x2  — the two files land under the
 *                                  new per-rider folders.
 *   3. insertRiderEmergencyContact()
 *   4. insertRiderDocument()     — with id_type, id_path, and the
 *                                  (possibly empty) dates.
 *
 * Any failure at 2, 3, or 4 unlinks whatever files this request
 * moved. The rider row from step 1 is left in place: an admin can
 * re-request the missing document, whereas a rider with no account
 * at all cannot be recovered without an admin intervention.
 * -------------------------------------------------------------- */

$projectRoot = realpath(__DIR__ . '/../../..');
if ($projectRoot === false) {
    respondError('Server storage path unavailable.');
}

$movedFiles = [];

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
            // Placeholder — the real path is written after the two
            // uploads have been moved, in the UPDATE below.
            'profile_picture' => '',
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

    // ---- Move the two uploads into their per-rider folders ----

    $profilePath = storeUploadForRider(
        $profileFile,
        $projectRoot,
        'profiles',
        $deliveryRiderId,
        $profileMeta['ext']
    );
    $movedFiles[] = $projectRoot . '/' . $profilePath;

    $licensePath = storeUploadForRider(
        $licenseFile,
        $projectRoot,
        'documents',
        $deliveryRiderId,
        $licenseMeta['ext']
    );
    $movedFiles[] = $projectRoot . '/' . $licensePath;

    // ---- Persist the profile picture path ----
    //
    // createRiderAccount() inserted the profile row with an empty
    // profile_picture because the folder did not exist yet. Now
    // that the file is on disk, this UPDATE records the real path.
    updateRiderProfilePicture($database_connection, $deliveryRiderId, $profilePath);

    // ---- Child rows ----

    insertRiderEmergencyContact($database_connection, $deliveryRiderId, [
        'first_name'     => $ecFirstName,
        'middle_name'    => $ecMiddleName,
        'last_name'      => $ecLastName,
        'contact_number' => $cleanedEcContact,
        'relationship'   => $ecRelationship,
        'address'        => '',
    ]);

    insertRiderDocument($database_connection, $deliveryRiderId, [
        'id_type'     => $idType,
        'id_path'     => $licensePath,
        'issue_date'  => $issueDate,
        'expiry_date' => $expiryDate,
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

} catch (RuntimeException $e) {
    foreach ($movedFiles as $p) {
        if (is_file($p)) @unlink($p);
    }

    error_log('Rider registration upload error: ' . $e->getMessage());
    respondError($e->getMessage());

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