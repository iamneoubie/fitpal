<?php
/**
 * FitPal Rider Registration Handler
 *
 * Two request modes, chosen by the posted `mode` field:
 *
 *   mode = ''           First-time registration.
 *   mode = 'reapply'    Re-application by a signed-in rider whose
 *                       verification_status is 'denied'.
 *
 * ---------------------------------------------------------------------
 * DOCUMENT-ROW POLICY
 * ---------------------------------------------------------------------
 * delivery_rider_document has no UNIQUE on delivery_rider_id, so a
 * rider may legally hold several rows. On re-application the caller
 * must choose one of two policies and pass it to
 * updateRiderApplication() as its $documentMode argument:
 *
 *   'replace'  Delete the rider's existing document rows for the
 *              posted id_type, then insert the new one. One row
 *              per rider per document type after the call.
 *
 *   'keep'     Insert a new document row and leave earlier rows in
 *              place.
 *
 * The policy is read from RIDER_REAPPLY_DOCUMENT_MODE, declared
 * below. The current value is 'keep': the rider's rejected
 * document stays in the table as history, and each re-application
 * adds a new row. Nothing on the rider side reads the document
 * table today, so this choice has no visible effect on the rider;
 * it only matters to a future admin-side viewer that lists a
 * rider's documents, and 'keep' preserves more information for
 * that viewer.
 *
 * Setting the constant to any value other than 'replace' or 'keep'
 * disables re-application entirely: the reapply branch refuses the
 * request before reading any other field. An empty string disables
 * the feature.
 *
 * ---------------------------------------------------------------------
 * REQUEST MODE
 * ---------------------------------------------------------------------
 * The mode is an explicit posted field, not inferred from session
 * state. A reapply POST that omits mode=reapply falls into the
 * insert path and is refused by the uniqueness probes with the
 * existing "already registered" message — a loud failure rather
 * than a silent wrong-path write.
 *
 * ---------------------------------------------------------------------
 * ALL SQL LIVES IN rider-assignment-queries.php
 * ---------------------------------------------------------------------
 * This file contains no prepare() calls, no SQL strings, and no
 * password hashing. Every write is a call into the query layer.
 *
 * Responds with JSON. The rider is NOT logged in after registration.
 * A re-application keeps the rider's existing session.
 *
 * Phone-number normalization
 * --------------------------
 * Both the rider's own contact_number and the emergency contact's
 * contact_number are reduced to their digit-only form via
 * preg_replace('/\D+/', '', ...) — the same rule the client-side
 * normalizeDigits() uses in reapply.js. This lets a caller who
 * posts "0917-123-4567" be accepted as the valid number it is.
 *
 * Emergency contact must differ from the rider's own number
 * ---------------------------------------------------------
 * After both numbers pass their format checks, an equality check
 * compares their digit-only forms. A match is refused with
 * field='emergency_contact'.
 *
 * Upload layout
 * -------------
 *     shared/uploads/rider/profiles/<rider_id>/MM_DD_YYYY_<n>.<ext>
 *     shared/uploads/rider/documents/<rider_id>/MM_DD_YYYY_<n>.<ext>
 *
 * @package FitPal
 * @version 6.1 — RIDER_REAPPLY_DOCUMENT_MODE set to 'keep' so the
 *                reapply branch can run. The constant was declared
 *                as '' in v6.0, which caused every reapply POST to
 *                be refused with "Re-application is not available
 *                right now." No other behaviour changed from v6.0.
 *
 *                (6.0: reapply branch added. 5.1: two changes for
 *                the emergency-contact rule. 5.0: rewritten for the
 *                generalized rider document schema and the
 *                per-rider upload layout.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('rider');

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/rider-assignment-queries.php';

// Own the rider role's CSRF bootstrap. The helper is idempotent and
// stores the token under 'rider_csrf_token' — never the shared
// 'csrf_token' key.
require_once __DIR__ . '/../../includes/rider-csrf-token.php';

header('Content-Type: application/json');

/* --------------------------------------------------------------
 * DOCUMENT-ROW POLICY FOR RE-APPLICATION
 *
 *   'keep'     preserve the rejected document; add a new row
 *   'replace'  delete the rejected document; insert the new one
 *
 * Any other value (including '') disables the reapply branch.
 * First-time registration never reads this constant.
 * -------------------------------------------------------------- */
const RIDER_REAPPLY_DOCUMENT_MODE = 'keep';

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
    $prefixLength = strlen($dayPrefix) + 1;

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
 * REQUEST MODE DISPATCH
 * -------------------------------------------------------------- */

$requestMode = (string)($_POST['mode'] ?? '');

if ($requestMode !== '' && $requestMode !== 'reapply') {
    respondError('Invalid request mode.');
}

/* --------------------------------------------------------------
 * SHARED REQUEST GUARDS
 * -------------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Invalid request method.');
}

if (
    !isset($_POST['csrf_token'], $_SESSION['rider_csrf_token']) ||
    !hash_equals((string)$_SESSION['rider_csrf_token'], (string)$_POST['csrf_token'])
) {
    unset($_SESSION['rider_csrf_token']);

    respondError('Security validation failed. Please refresh the page and try again.');
}

/* ==============================================================
 * REAPPLY BRANCH
 * ============================================================== */

if ($requestMode === 'reapply') {

    /* ----------------------------------------------------------
     * 0. DOCUMENT-ROW POLICY GATE
     * ---------------------------------------------------------- */
    if (RIDER_REAPPLY_DOCUMENT_MODE !== 'replace'
        && RIDER_REAPPLY_DOCUMENT_MODE !== 'keep'
    ) {
        respondError(
            'Re-application is not available right now. Please contact support.'
        );
    }

    /* ----------------------------------------------------------
     * 1. SESSION AND STATUS GUARD
     * ---------------------------------------------------------- */
    $riderId = (int)($_SESSION['delivery_rider_id'] ?? 0);
    if ($riderId <= 0) {
        respondError('You must be signed in to re-apply.');
    }

    try {
        $existingProfile = getRiderProfile($database_connection, $riderId);
    } catch (PDOException $e) {
        error_log('Re-apply lookup error: ' . $e->getMessage());
        respondError('An unexpected error occurred. Please try again later.');
    }

    if (!$existingProfile) {
        respondError('Your account could not be found. Please sign in again.');
    }

    if ((string)($existingProfile['verification_status'] ?? '') !== 'denied') {
        respondError(
            'Your account is not eligible for re-application right now.'
        );
    }

    /* ----------------------------------------------------------
     * 2. COLLECT INPUT
     * ---------------------------------------------------------- */
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

    /* ----------------------------------------------------------
     * 3. REQUIRED FIELD CHECK
     * ---------------------------------------------------------- */
    if (
        $firstName === '' || $lastName === '' || $birthdate === '' ||
        $gender === '' || $email === '' || $contactNumber === '' ||
        $username === '' || $vehicleType === '' ||
        $block === '' || $city === '' ||
        $ecFirstName === '' || $ecLastName === '' ||
        $ecRelationship === '' || $ecContact === ''
    ) {
        respondError('Please fill in all required fields.');
    }

    /* ----------------------------------------------------------
     * 4. FIELD VALIDATION
     * ---------------------------------------------------------- */
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

    if ($password !== '') {
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

    $isBicycle = ($vehicleType === 'bicycle');
    $idType    = $isBicycle ? 'national_id' : 'drivers_license';

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

    $cleanedEcContact = preg_replace('/\D+/', '', $ecContact);
    if (!preg_match('/^09\d{9}$/', (string)$cleanedEcContact)) {
        respondError('Emergency contact must be a valid PH mobile number (09XXXXXXXXX).', 'emergency_contact');
    }

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

    /* ----------------------------------------------------------
     * 5. FILE UPLOAD VALIDATION
     * ---------------------------------------------------------- */
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

    /* ----------------------------------------------------------
     * 6. UNIQUENESS PROBES — EXCLUDING THE RIDER'S OWN ROW
     * ---------------------------------------------------------- */
    try {
        if (riderEmailExistsForOther($database_connection, $email, $riderId)) {
            respondError('This email address is already registered to another account.', 'email');
        }
        if (riderUsernameExistsForOther($database_connection, $username, $riderId)) {
            respondError('This username is already taken.', 'username');
        }
        if (riderContactExistsForOther($database_connection, (string)$cleanedContact, $riderId)) {
            respondError('This mobile number is already registered to another account.', 'contact_number');
        }
    } catch (PDOException $e) {
        error_log('Re-apply lookup error: ' . $e->getMessage());
        respondError('An unexpected error occurred. Please try again later.');
    }

    /* ----------------------------------------------------------
     * 7. MOVE THE TWO UPLOADS
     * ---------------------------------------------------------- */
    $projectRoot = realpath(__DIR__ . '/../../..');
    if ($projectRoot === false) {
        respondError('Server storage path unavailable.');
    }

    $movedFiles = [];

    try {
        $profilePath = storeUploadForRider(
            $profileFile,
            $projectRoot,
            'profiles',
            $riderId,
            $profileMeta['ext']
        );
        $movedFiles[] = $projectRoot . '/' . $profilePath;

        $licensePath = storeUploadForRider(
            $licenseFile,
            $projectRoot,
            'documents',
            $riderId,
            $licenseMeta['ext']
        );
        $movedFiles[] = $projectRoot . '/' . $licensePath;

    } catch (RuntimeException $e) {
        foreach ($movedFiles as $p) {
            if (is_file($p)) @unlink($p);
        }
        error_log('Re-apply upload error: ' . $e->getMessage());
        respondError($e->getMessage());
    }

    /* ----------------------------------------------------------
     * 8. UPDATE IN PLACE
     * ---------------------------------------------------------- */
    $database_connection->beginTransaction();

    try {
        updateRiderApplication(
            $database_connection,
            $riderId,
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
            ],
            [
                'first_name'     => $ecFirstName,
                'middle_name'    => $ecMiddleName,
                'last_name'      => $ecLastName,
                'contact_number' => $cleanedEcContact,
                'relationship'   => $ecRelationship,
                'address'        => '',
            ],
            [
                'id_type'     => $idType,
                'id_path'     => $licensePath,
                'issue_date'  => $issueDate,
                'expiry_date' => $expiryDate,
            ],
            RIDER_REAPPLY_DOCUMENT_MODE
        );

        $database_connection->commit();

    } catch (PDOException $e) {
        if ($database_connection->inTransaction()) {
            $database_connection->rollBack();
        }

        foreach ($movedFiles as $p) {
            if (is_file($p)) @unlink($p);
        }

        error_log('Re-apply DB error: ' . $e->getMessage());

        $message = $e->getMessage();
        if (stripos($message, 'Duplicate entry') !== false) {
            if (stripos($message, 'email') !== false) {
                respondError('This email address is already registered to another account.', 'email');
            }
            if (stripos($message, 'username') !== false) {
                respondError('This username is already taken.', 'username');
            }
            if (stripos($message, 'contact_number') !== false) {
                respondError('This mobile number is already registered to another account.', 'contact_number');
            }
        }
        respondError('An unexpected error occurred. Please try again later.');

    } catch (InvalidArgumentException | RuntimeException $e) {
        if ($database_connection->inTransaction()) {
            $database_connection->rollBack();
        }

        foreach ($movedFiles as $p) {
            if (is_file($p)) @unlink($p);
        }

        error_log('Re-apply error: ' . $e->getMessage());
        respondError('An unexpected error occurred. Please try again later.');

    } catch (Throwable $e) {
        if ($database_connection->inTransaction()) {
            $database_connection->rollBack();
        }

        foreach ($movedFiles as $p) {
            if (is_file($p)) @unlink($p);
        }

        error_log('Re-apply error: ' . $e->getMessage());
        respondError('An unexpected error occurred. Please try again later.');
    }

    /* ----------------------------------------------------------
     * 9. SUCCESS
     * ---------------------------------------------------------- */
    unset($_SESSION['rider_csrf_token']);

    $_SESSION['rider_success'] =
        'Your updated application has been submitted. It is now pending review.';

    echo json_encode([
        'status'   => 'success',
        'message'  => 'Your updated application has been submitted. It is now pending review.',
        'redirect' => 'dashboard.php',
    ]);
    exit;
}

/* ==============================================================
 * INSERT PATH (first-time registration)
 *
 * Byte-identical to v5.1 apart from the dispatch that got here.
 * ============================================================== */

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

$isBicycle = ($vehicleType === 'bicycle');
$idType    = $isBicycle ? 'national_id' : 'drivers_license';

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

$cleanedEcContact = preg_replace('/\D+/', '', $ecContact);
if (!preg_match('/^09\d{9}$/', (string)$cleanedEcContact)) {
    respondError('Emergency contact must be a valid PH mobile number (09XXXXXXXXX).', 'emergency_contact');
}

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

    updateRiderProfilePicture($database_connection, $deliveryRiderId, $profilePath);

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

unset($_SESSION['rider_csrf_token']);

$_SESSION['registration_success'] = 'Rider application submitted. Please sign in to continue.';

echo json_encode([
    'status'   => 'success',
    'message'  => 'Your rider application has been submitted. Please sign in to continue.',
    'redirect' => 'sign-in.php',
]);
exit;

    /* ----------------------------------------------------------
     * 4. FIELD VALIDATION
     *
     * Byte-identical rules to the insert path, with two
     * differences:
     *
     *   - password is optional on reapply. If provided, it must
     *     pass the same rules as the insert path, must match
     *     confirm_password, AND the posted current_password must
     *     verify against the rider's stored bcrypt hash. If not,
     *     the change is refused with field='current_password'.
     *   - the terms checkbox is required in both modes.
     * ---------------------------------------------------------- */
    $currentPassword = (string)($_POST['current_password'] ?? '');

    // ... every existing validation rule, unchanged, up to and
    // including the password rules block. Then:

    // ----------------------------------------------------------
    // 4a. PASSWORD-CHANGE VERIFICATION
    //
    // A non-empty new password is only accepted when the rider
    // also posts the current password and it verifies against
    // the stored hash. An empty new password means "leave the
    // existing hash alone"; in that case current_password is
    // ignored.
    // ----------------------------------------------------------
    if ($password !== '') {

        if ($currentPassword === '') {
            respondError(
                'Please enter your current password to change it.',
                'current_password'
            );
        }

        try {
            $storedHash = getRiderPasswordHash($database_connection, $riderId);
        } catch (PDOException $e) {
            error_log('Re-apply password lookup error: ' . $e->getMessage());
            respondError('An unexpected error occurred. Please try again later.');
        }

        if ($storedHash === false || $storedHash === '') {
            respondError(
                'Your account could not be found. Please sign in again.',
                'current_password'
            );
        }

        if (!password_verify($currentPassword, $storedHash)) {
            respondError(
                'Your current password is incorrect.',
                'current_password'
            );
        }
    }