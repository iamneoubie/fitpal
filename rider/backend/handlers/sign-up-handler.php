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
 * SHARED VALIDATION
 * ---------------------------------------------------------------------
 * Every field-level rule on this page is expressed exactly once, in
 * a helper function below. The insert branch and the reapply branch
 * call the same helpers, in the same order, against the same input.
 * A rule that lives in two places drifts when one is edited; these
 * rules do not live in two places.
 *
 * The helpers are:
 *
 *   validateNameField()        one name input, 2+ chars, name charset
 *   validateBirthdate()        Y-m-d, 18–70 years old
 *   validateGender()           one of Male, Female, Other
 *   validateEmail()            FILTER_VALIDATE_EMAIL
 *   validateContactNumber()    PH mobile, digits-only, 09 + 9 digits
 *   validateUsername()         3–20 chars, [A-Za-z0-9_]
 *   validatePassword()         insert path only
 *   validateVehicle()          vehicle_type, plate, year
 *   validateLicenseDates()     issue and expiry, non-bicycle only
 *   validateAddress()          block, barangay, city, province,
 *                              region, postal_code
 *   validateEmergency()        emergency contact name, relationship,
 *                              contact_number, and the
 *                              must-differ-from-own-number rule
 *   validateUploads()          the two file uploads
 *
 * Each helper returns void and terminates the request through
 * respondError() on the first rule that fails. The first failing
 * field is named in the response so the client can highlight it.
 *
 * ---------------------------------------------------------------------
 * VEHICLE FIELDS WITH NO SCHEMA COLUMN
 * ---------------------------------------------------------------------
 * vehicle_make, vehicle_model, and vehicle_year are collected by the
 * form and validated by sign-up.js and rider-reapply.js, but
 * delivery_rider_profile has no columns for them. This handler
 * therefore does not read those three POST keys at all.
 *
 * If the schema gains columns for them, the change is three lines
 * here plus the query-layer writes, and the client already sends
 * them.
 *
 * ---------------------------------------------------------------------
 * DOCUMENT ROW POLICY
 * ---------------------------------------------------------------------
 * A rider holds exactly one identity document on file, whatever its
 * id_type. There is no scenario in which a rider is simultaneously
 * holding a valid drivers_license and a valid national_id on the
 * same application.
 *
 * On re-application this handler therefore calls
 * updateRiderApplication() with $replaceDocument = true, which
 * deletes every document row for the rider before inserting the new
 * one. The previous revision carried a 'keep' mode that inserted a
 * new row and left every older row in place; that mode is gone,
 * because it produced the accumulating-rows bug the v15.0 query
 * layer fixes.
 *
 * ---------------------------------------------------------------------
 * REAPPLY DOES NOT TOUCH CREDENTIALS
 * ---------------------------------------------------------------------
 * The reapply branch never reads, validates, or verifies a password.
 * A rider who is re-applying keeps the password they already have.
 *
 * ---------------------------------------------------------------------
 * ALL SQL LIVES IN rider-assignment-queries.php
 * ---------------------------------------------------------------------
 * This file performs no SQL. Every write goes through a function in
 * rider-assignment-queries.php. The upload helpers below are
 * filesystem operations, not database operations.
 *
 * Responds with JSON. The rider is NOT logged in after registration.
 * A re-application keeps the rider's existing session.
 *
 * Upload layout
 * -------------
 *     shared/uploads/rider/profiles/<rider_id>/MM_DD_YYYY_<n>.<ext>
 *     shared/uploads/rider/documents/<rider_id>/MM_DD_YYYY_<n>.<ext>
 *
 * @package FitPal
 * @version 8.0 — Per-field validation extracted into shared helpers;
 *                both branches call the same set in the same order.
 *                The 'replace'-only document policy replaces the
 *                previous replace/keep choice. vehicle_make,
 *                vehicle_model, and vehicle_year are no longer read
 *                on either branch — there is no column for them.
 *                The "some field is missing" refusal now names the
 *                first missing field so the client can highlight it.
 *
 *                (7.0: reapply branch dropped all password reads.
 *                6.1: document mode set to 'keep'. 6.0: reapply
 *                branch added. 5.1: emergency-contact rule. 5.0:
 *                generalized rider document schema.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('rider');

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/rider-assignment-queries.php';

require_once __DIR__ . '/../../includes/rider-csrf-token.php';

header('Content-Type: application/json');

/* --------------------------------------------------------------
 * RESPONSE HELPERS
 * -------------------------------------------------------------- */

/**
 * Terminate with a JSON error payload.
 *
 * The $field argument is the POST field name the client should
 * highlight. It is '' when the refusal is not tied to a single
 * field.
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

/* --------------------------------------------------------------
 * UPLOAD HELPERS
 * -------------------------------------------------------------- */

/**
 * Validate one uploaded file and return its extension.
 *
 * @param array<string, mixed>  $file        $_FILES entry
 * @param int                   $maxBytes
 * @param array<string, string> $allowedMime  map of mime => ext
 * @return array{ext: string, mime: string}
 * @throws RuntimeException on any validation failure
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
 * @param array<string, mixed> $file
 * @param string $projectRoot
 * @param string $kind  'profiles' or 'documents'
 * @param int    $riderId
 * @param string $ext
 * @return string
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
 * FIELD VALIDATORS
 *
 * Each function validates one field or one logical group and either
 * returns normally or terminates through respondError(). The
 * $field argument passed to respondError() is the POST key the
 * client should highlight.
 * -------------------------------------------------------------- */

/**
 * Validate one name field: 2+ characters, letters, spaces, hyphens,
 * apostrophes only.
 *
 * @return void
 */
function validateNameField(string $value, string $field, string $label): void
{
    $pattern = "/^[A-Za-z\s\-']+$/u";

    if (strlen($value) < 2) {
        respondError($label . ' must be at least 2 characters.', $field);
    }
    if (!preg_match($pattern, $value)) {
        respondError($label . ' contains invalid characters.', $field);
    }
}

/**
 * Validate a Y-m-d birthdate and the 18–70 age range.
 *
 * @return void
 */
function validateBirthdate(string $value): void
{
    if ($value === '') {
        respondError('Please enter your date of birth.', 'birthdate');
    }

    try {
        $bd    = new DateTime($value);
        $today = new DateTime();
        $age   = $today->diff($bd)->y;

        if ($age < 18) {
            respondError('You must be at least 18 years old to register as a rider.', 'birthdate');
        }
        if ($age > 70) {
            respondError('Please enter a valid date of birth.', 'birthdate');
        }
    } catch (Exception $e) {
        respondError('Please enter a valid date of birth.', 'birthdate');
    }
}

/**
 * Validate the gender selection against the schema's CHECK values.
 *
 * @return void
 */
function validateGender(string $value): void
{
    if ($value === '') {
        respondError('Please select your gender.', 'gender');
    }
    if (!in_array($value, ['Male', 'Female', 'Other'], true)) {
        respondError('Invalid gender selection.', 'gender');
    }
}

/**
 * Validate an email address.
 *
 * @return void
 */
function validateEmail(string $value): void
{
    if ($value === '') {
        respondError('Please enter your email address.', 'email');
    }
    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
        respondError('Please enter a valid email address.', 'email');
    }
}

/**
 * Normalize and validate a Philippine mobile number.
 *
 * Accepts "0917 123 4567", "0917-123-4567", "09171234567". Rejects
 * anything that does not reduce to 09 followed by 9 digits.
 *
 * Returns the digit-only form, or terminates through respondError().
 */
function validateContactNumber(string $value, string $field, string $label): string
{
    $cleaned = (string)preg_replace('/\D+/', '', $value);

    if ($cleaned === '') {
        respondError('Please enter ' . $label . '.', $field);
    }
    if (!preg_match('/^09\d{9}$/', $cleaned)) {
        respondError(
            $label . ' must be a valid PH mobile number (11 digits, starting with 09).',
            $field
        );
    }

    return $cleaned;
}

/**
 * Validate a username.
 *
 * @return void
 */
function validateUsername(string $value): void
{
    if ($value === '') {
        respondError('Please choose a username.', 'username');
    }
    if (strlen($value) < 3 || strlen($value) > 20) {
        respondError('Username must be 3–20 characters.', 'username');
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $value)) {
        respondError('Username can only contain letters, numbers, and underscores.', 'username');
    }
}

/**
 * Validate a password. Insert path only.
 *
 * @return void
 */
function validatePassword(string $password, string $confirm): void
{
    if (strlen($password) < 8 || strlen($password) > 20) {
        respondError('Password must be 8–20 characters.', 'password');
    }
    if (!preg_match('/^[A-Za-z0-9]+$/', $password)) {
        respondError('Password can only contain letters and numbers.', 'password');
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        respondError('Password must contain at least one letter and one number.', 'password');
    }
    if ($password !== $confirm) {
        respondError('Passwords do not match.', 'confirm_password');
    }
}

/**
 * Validate the vehicle block: vehicle_type, plate, year.
 *
 * Plate and year are required for every non-bicycle vehicle and are
 * skipped for a bicycle. Make and model are NOT validated here:
 * delivery_rider_profile has no columns for them. See the file
 * header.
 *
 * @return void
 */
function validateVehicle(
    string $vehicleType,
    string $vehiclePlate,
    string $vehicleYear
): void {
    $allowed = ['motorcycle', 'scooter', 'car', 'van', 'bicycle'];

    if ($vehicleType === '') {
        respondError('Please select your vehicle type.', 'vehicle_type');
    }
    if (!in_array($vehicleType, $allowed, true)) {
        respondError('Invalid vehicle type.', 'vehicle_type');
    }

    $isBicycle = ($vehicleType === 'bicycle');

    if (!$isBicycle) {
        if ($vehiclePlate === '') {
            respondError('Plate number is required for motor vehicles.', 'vehicle_plate');
        }
        if (strlen(str_replace([' ', '-'], '', $vehiclePlate)) < 4) {
            respondError('Plate number looks too short.', 'vehicle_plate');
        }

        if ($vehicleYear === '') {
            respondError('Vehicle year is required for motor vehicles.', 'vehicle_year');
        }
        if (!ctype_digit($vehicleYear)) {
            respondError('Vehicle year must be numeric.', 'vehicle_year');
        }
        $y       = (int)$vehicleYear;
        $maxYear = (int)date('Y') + 1;
        if ($y < 1980 || $y > $maxYear) {
            respondError('Please enter a valid vehicle year.', 'vehicle_year');
        }
    }
}

/**
 * Validate the two license-date inputs and normalize them to Y-m-d.
 *
 * For a bicycle the two inputs do not apply and both are returned
 * empty.
 *
 * @return array{issue: string, expiry: string}
 */
function validateLicenseDates(
    string $vehicleType,
    string $issueRaw,
    string $expiryRaw
): array {
    $result = ['issue' => '', 'expiry' => ''];

    if ($vehicleType === 'bicycle') {
        return $result;
    }

    if ($issueRaw === '') {
        respondError('Please enter the license issue date.', 'license_issue_date');
    }
    if ($expiryRaw === '') {
        respondError('Please enter the license expiry date.', 'license_expiry_date');
    }

    try {
        $issue = new DateTime($issueRaw);
    } catch (Exception $e) {
        respondError('Invalid license issue date.', 'license_issue_date');
    }

    if ($issue > new DateTime()) {
        respondError('License issue date cannot be in the future.', 'license_issue_date');
    }

    try {
        $expiry = new DateTime($expiryRaw);
    } catch (Exception $e) {
        respondError('Invalid license expiry date.', 'license_expiry_date');
    }

    if ($expiry <= new DateTime()) {
        respondError('License expiry date must be in the future.', 'license_expiry_date');
    }
    if ($expiry <= $issue) {
        respondError('Expiry date must be after the issue date.', 'license_expiry_date');
    }

    $result['issue']  = $issue->format('Y-m-d');
    $result['expiry'] = $expiry->format('Y-m-d');

    return $result;
}

/**
 * Validate the six required address fields.
 *
 * @return void
 */
function validateAddress(
    string $block,
    string $barangay,
    string $city,
    string $province,
    string $region,
    string $postalCode
): void {
    if ($block === '') {
        respondError('Block / Street / Unit is required.', 'block');
    }
    if ($barangay === '') {
        respondError('Barangay is required.', 'barangay');
    }
    if ($city === '') {
        respondError('City or municipality is required.', 'city');
    }
    if ($province === '') {
        respondError('Province is required.', 'province');
    }
    if ($region === '') {
        respondError('Region is required.', 'region');
    }
    if ($postalCode === '') {
        respondError('Postal code is required.', 'postal_code');
    }
    if (!preg_match('/^[0-9]{3,10}$/', $postalCode)) {
        respondError('Postal code must be numeric (3–10 digits).', 'postal_code');
    }
}

/**
 * Validate the emergency-contact block and enforce the rule that
 * the emergency contact's number differs from the rider's own.
 *
 * Returns the digit-only emergency contact number.
 */
function validateEmergency(
    string $ecFirstName,
    string $ecLastName,
    string $ecRelationship,
    string $ecContactRaw,
    string $ownContactDigits
): string {
    validateNameField($ecFirstName, 'emergency_first_name', 'Emergency contact first name');
    validateNameField($ecLastName,  'emergency_last_name',  'Emergency contact last name');

    $allowed = ['Parent', 'Spouse', 'Sibling', 'Relative', 'Friend', 'Other'];
    if ($ecRelationship === '') {
        respondError('Please select a relationship.', 'emergency_relationship');
    }
    if (!in_array($ecRelationship, $allowed, true)) {
        respondError('Invalid emergency contact relationship.', 'emergency_relationship');
    }

    $cleanedEc = validateContactNumber(
        $ecContactRaw,
        'emergency_contact',
        'Emergency contact number'
    );

    if ($ownContactDigits !== '' && $cleanedEc === $ownContactDigits) {
        respondError(
            'Emergency contact number must be different from your own contact number.',
            'emergency_contact'
        );
    }

    return $cleanedEc;
}

/**
 * Validate that both required uploads are present and well-formed.
 *
 * Returns [profileMeta, licenseMeta], each an array with 'ext' and
 * 'mime'.
 *
 * @return array{0: array{ext:string,mime:string}, 1: array{ext:string,mime:string}}
 */
function validateUploads(
    ?array $profileFile,
    ?array $licenseFile,
    bool $isBicycle
): array {
    $allowedImages = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    if (!$profileFile || ($profileFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        respondError('Please upload a formal profile picture.', 'profile_picture');
    }
    if (!$licenseFile || ($licenseFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $message = $isBicycle
            ? 'Please upload a photo of a valid government-issued ID.'
            : "Please upload a photo of your driver's license.";
        respondError($message, 'drivers_license');
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

    return [$profileMeta, $licenseMeta];
}

/**
 * Name the first empty field in a fixed ordered list, or return ''
 * when every field is present.
 *
 * The order is the order the wizard presents the fields, so the
 * "some field is missing" refusal names the earliest one the rider
 * would have reached.
 *
 * @param array<string, array{0: string, 1: string}> $required
 *        Map of POST key => [value, human label].
 * @return string  The POST key of the first empty field, or ''.
 */
function firstMissingField(array $required): string
{
    foreach ($required as $key => $pair) {
        if ((string)$pair[0] === '') {
            return $key;
        }
    }
    return '';
}

/* --------------------------------------------------------------
 * REQUEST DISPATCH
 * -------------------------------------------------------------- */

$requestMode = (string)($_POST['mode'] ?? '');

if ($requestMode !== '' && $requestMode !== 'reapply') {
    respondError('Invalid request mode.');
}

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

/* --------------------------------------------------------------
 * COLLECT INPUT
 *
 * The same set of POST keys is read on both branches, with the
 * password keys read only on the insert path. vehicle_make,
 * vehicle_model, and vehicle_year are deliberately NOT read: there
 * is no column for them. See the file header.
 * -------------------------------------------------------------- */

$firstName       = trim((string)($_POST['first_name']       ?? ''));
$middleName      = trim((string)($_POST['middle_name']      ?? ''));
$lastName        = trim((string)($_POST['last_name']        ?? ''));
$birthdate       = trim((string)($_POST['birthdate']        ?? ''));
$gender          = trim((string)($_POST['gender']           ?? ''));
$email           = trim((string)($_POST['email']            ?? ''));
$contactNumber   = trim((string)($_POST['contact_number']   ?? ''));
$username        = trim((string)($_POST['username']         ?? ''));
$terms           = $_POST['terms'] ?? '';

$vehicleType  = trim((string)($_POST['vehicle_type']  ?? ''));
$vehiclePlate = trim((string)($_POST['vehicle_plate'] ?? ''));
$vehicleYear  = trim((string)($_POST['vehicle_year']  ?? ''));

$licenseIssueRaw  = trim((string)($_POST['license_issue_date']  ?? ''));
$licenseExpiryRaw = trim((string)($_POST['license_expiry_date'] ?? ''));

$block      = trim((string)($_POST['block']       ?? ''));
$barangay   = trim((string)($_POST['barangay']    ?? ''));
$city       = trim((string)($_POST['city']        ?? ''));
$province   = trim((string)($_POST['province']    ?? ''));
$region     = trim((string)($_POST['region']      ?? ''));
$postalCode = trim((string)($_POST['postal_code'] ?? ''));

$ecFirstName    = trim((string)($_POST['emergency_first_name']     ?? ''));
$ecMiddleName   = trim((string)($_POST['emergency_middle_name']    ?? ''));
$ecLastName     = trim((string)($_POST['emergency_last_name']      ?? ''));
$ecRelationship = trim((string)($_POST['emergency_relationship']   ?? ''));
$ecContact      = trim((string)($_POST['emergency_contact']        ?? ''));

$profileFile = $_FILES['profile_picture'] ?? null;
$licenseFile = $_FILES['drivers_license'] ?? null;

/* --------------------------------------------------------------
 * REQUIRED-FIELD CHECK
 *
 * Runs before any format rule so the refusal names the earliest
 * missing field rather than the first field to fail a format check.
 * -------------------------------------------------------------- */

$requiredMap = [
    'first_name'      => [$firstName,     'First Name'],
    'last_name'       => [$lastName,      'Last Name'],
    'birthdate'       => [$birthdate,     'Birthdate'],
    'gender'          => [$gender,        'Gender'],
    'email'           => [$email,         'Email Address'],
    'contact_number'  => [$contactNumber, 'Contact Number'],
    'username'        => [$username,      'Username'],
    'vehicle_type'    => [$vehicleType,   'Vehicle Type'],
    'block'           => [$block,         'Block / Street / Unit'],
    'barangay'        => [$barangay,      'Barangay'],
    'city'            => [$city,          'City / Municipality'],
    'province'        => [$province,      'Province'],
    'region'          => [$region,        'Region'],
    'postal_code'     => [$postalCode,    'Postal Code'],
];

if ($requestMode === '') {
    // Insert path also requires a password.
    $password        = (string)($_POST['password']         ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($password === '') {
        respondError('Please create a password.', 'password');
    }
} else {
    $password        = '';
    $confirmPassword = '';
}

$requiredMap['emergency_first_name'] = [$ecFirstName,    'Emergency Contact First Name'];
$requiredMap['emergency_last_name']  = [$ecLastName,     'Emergency Contact Last Name'];
$requiredMap['emergency_relationship'] = [$ecRelationship, 'Relationship'];
$requiredMap['emergency_contact']    = [$ecContact,      'Emergency Contact Number'];

$missingField = firstMissingField($requiredMap);

if ($missingField !== '') {
    $label = $requiredMap[$missingField][1];
    respondError($label . ' is required.', $missingField);
}

/* --------------------------------------------------------------
 * FIELD VALIDATION
 *
 * Both branches run the same set, in the same order.
 * -------------------------------------------------------------- */

validateNameField($firstName, 'first_name', 'First name');
validateNameField($lastName,  'last_name',  'Last name');

if ($middleName !== '') {
    validateNameField($middleName, 'middle_name', 'Middle name');
}

validateBirthdate($birthdate);
validateGender($gender);
validateEmail($email);

$cleanedContact = validateContactNumber($contactNumber, 'contact_number', 'Contact number');

validateUsername($username);

if ($requestMode === '') {
    validatePassword($password, $confirmPassword);
}

validateVehicle($vehicleType, $vehiclePlate, $vehicleYear);

$isBicycle = ($vehicleType === 'bicycle');

$licenseDates = validateLicenseDates($vehicleType, $licenseIssueRaw, $licenseExpiryRaw);

validateAddress($block, $barangay, $city, $province, $region, $postalCode);

$cleanedEcContact = validateEmergency(
    $ecFirstName,
    $ecLastName,
    $ecRelationship,
    $ecContact,
    $cleanedContact
);

if (empty($terms)) {
    respondError('You must agree to the Terms and Conditions and Privacy Policy.', 'terms');
}

[$profileMeta, $licenseMeta] = validateUploads($profileFile, $licenseFile, $isBicycle);

/* --------------------------------------------------------------
 * VEHICLE NORMALIZATION
 * -------------------------------------------------------------- */

$vehiclePlate = $vehiclePlate !== '' ? strtoupper($vehiclePlate) : '';

/* --------------------------------------------------------------
 * DOCUMENT TYPE
 * -------------------------------------------------------------- */

$idType = $isBicycle ? 'national_id' : 'drivers_license';

/* ==============================================================
 * REAPPLY BRANCH
 * ============================================================== */

if ($requestMode === 'reapply') {

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
        respondError('Your account is not eligible for re-application right now.');
    }

    try {
        if (riderEmailExistsForOther($database_connection, $email, $riderId)) {
            respondError('This email address is already registered to another account.', 'email');
        }
        if (riderUsernameExistsForOther($database_connection, $username, $riderId)) {
            respondError('This username is already taken.', 'username');
        }
        if (riderContactExistsForOther($database_connection, $cleanedContact, $riderId)) {
            respondError('This mobile number is already registered to another account.', 'contact_number');
        }
    } catch (PDOException $e) {
        error_log('Re-apply lookup error: ' . $e->getMessage());
        respondError('An unexpected error occurred. Please try again later.');
    }

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
                'contact_number' => $cleanedContact,
                'username'       => $username,
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
                'issue_date'  => $licenseDates['issue'],
                'expiry_date' => $licenseDates['expiry'],
            ],
            true   // $replaceDocument — one document row per rider
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
        respondDuplicateEntry($e->getMessage(), 'another account');

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
 * INSERT PATH — first-time registration
 * ============================================================== */

try {
    if (riderEmailExists($database_connection, $email)) {
        respondError('This email address is already registered.', 'email');
    }
    if (riderUsernameExists($database_connection, $username)) {
        respondError('This username is already taken.', 'username');
    }
    if (riderContactExists($database_connection, $cleanedContact)) {
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
            'contact_number' => $cleanedContact,
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
        'issue_date'  => $licenseDates['issue'],
        'expiry_date' => $licenseDates['expiry'],
    ]);

} catch (PDOException $e) {
    foreach ($movedFiles as $p) {
        if (is_file($p)) @unlink($p);
    }
    error_log('Rider registration DB error: ' . $e->getMessage());
    respondDuplicateEntry($e->getMessage(), '');

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

/* --------------------------------------------------------------
 * SHARED DB-ERROR ROUTER
 * -------------------------------------------------------------- */

/**
 * Map a PDO "Duplicate entry" message onto the field that caused it,
 * or fall through to the generic refusal.
 *
 * @param string $message       The PDO exception message.
 * @param string $targetPhrase  'another account' for the reapply
 *                              probes, '' for first-time register.
 * @return never
 */
function respondDuplicateEntry(string $message, string $targetPhrase): void
{
    $suffix = $targetPhrase !== '' ? ' to ' . $targetPhrase : '';

    if (stripos($message, 'Duplicate entry') !== false) {
        if (stripos($message, 'email') !== false) {
            respondError('This email address is already registered' . $suffix . '.', 'email');
        }
        if (stripos($message, 'username') !== false) {
            respondError('This username is already taken.', 'username');
        }
        if (stripos($message, 'contact_number') !== false) {
            respondError('This mobile number is already registered' . $suffix . '.', 'contact_number');
        }
    }

    respondError('An unexpected error occurred. Please try again later.');
}