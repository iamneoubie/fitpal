<?php
/**
 * FitPal Rider Registration Handler
 *
 * Creates the following rows in a single database transaction:
 *   1. financial_account          (account_type = 'rider')
 *   2. delivery_rider             (account credentials)
 *   3. delivery_rider_profile     (vehicle + verification_status = 'pending')
 *   4. delivery_rider_address     (default address)
 *
 * Emergency-contact fields are validated and stored on the session
 * under 'rider_emergency_contact' so admin tooling can retrieve them
 * later without a schema change. If you later add dedicated columns
 * to delivery_rider_profile, move them there.
 *
 * Responds with JSON so sign-up.js can show the success modal and
 * redirect on acknowledgement. Validation failures return HTTP 200
 * with {status:'error', message, field} — the client reads the body,
 * not the status code.
 *
 * The rider is NOT logged in after registration — they must sign in
 * afterward, matching the customer flow.
 *
 * @package FitPal
 * @version 2.0 — Adds emergency contact, vehicle make/model/year.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/rider-queries.php';

header('Content-Type: application/json');

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

// ===== REQUEST METHOD =====
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Invalid request method.');
}

// ===== CSRF =====
if (
    !isset($_POST['csrf_token']) ||
    !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$_POST['csrf_token'])
) {
    respondError('Security validation failed. Please refresh the page and try again.');
}

// ===== COLLECT INPUT =====
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

$vehicleType   = trim((string)($_POST['vehicle_type'] ?? ''));
$vehiclePlate  = trim((string)($_POST['vehicle_plate'] ?? ''));
$vehicleMake   = trim((string)($_POST['vehicle_make'] ?? ''));
$vehicleModel  = trim((string)($_POST['vehicle_model'] ?? ''));
$vehicleYear   = trim((string)($_POST['vehicle_year'] ?? ''));

$addressLabel         = trim((string)($_POST['address_label'] ?? ''));
$block                = trim((string)($_POST['block'] ?? ''));
$barangay             = trim((string)($_POST['barangay'] ?? ''));
$city                 = trim((string)($_POST['city'] ?? ''));
$province             = trim((string)($_POST['province'] ?? ''));
$region               = trim((string)($_POST['region'] ?? ''));
$postalCode           = trim((string)($_POST['postal_code'] ?? ''));

$emergencyName         = trim((string)($_POST['emergency_name'] ?? ''));
$emergencyRelationship = trim((string)($_POST['emergency_relationship'] ?? ''));
$emergencyContact      = trim((string)($_POST['emergency_contact'] ?? ''));

// ===== REQUIRED FIELDS =====
if (
    $firstName === '' || $lastName === '' || $birthdate === '' ||
    $gender === '' || $email === '' || $contactNumber === '' ||
    $username === '' || $password === '' || $vehicleType === '' ||
    $block === '' || $city === '' ||
    $emergencyName === '' || $emergencyRelationship === '' ||
    $emergencyContact === ''
) {
    respondError('Please fill in all required fields.');
}

// ===== NAME VALIDATION =====
$namePattern = "/^[A-Za-z\s\-']+$/u";

if (strlen($firstName) < 2) {
    respondError('First name must be at least 2 characters.', 'first_name');
}
if (!preg_match($namePattern, $firstName)) {
    respondError('First name contains invalid characters.', 'first_name');
}
if (strlen($lastName) < 2) {
    respondError('Last name must be at least 2 characters.', 'last_name');
}
if (!preg_match($namePattern, $lastName)) {
    respondError('Last name contains invalid characters.', 'last_name');
}

// ===== GENDER =====
if (!in_array($gender, ['Male', 'Female', 'Other'], true)) {
    respondError('Invalid gender selection.', 'gender');
}

// ===== EMAIL =====
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respondError('Please enter a valid email address.', 'email');
}

// ===== CONTACT =====
$cleanedContact = preg_replace('/\s+/', '', $contactNumber);
if (!preg_match('/^09\d{9}$/', (string)$cleanedContact)) {
    respondError('Enter a valid PH mobile number (11 digits, starting with 09).', 'contact_number');
}

// ===== USERNAME =====
if (strlen($username) < 3) {
    respondError('Username must be at least 3 characters.', 'username');
}
if (strlen($username) > 20) {
    respondError('Username must be no more than 20 characters.', 'username');
}
if (!preg_match('/^[A-Za-z0-9_]+$/', $username)) {
    respondError('Username can only contain letters, numbers, and underscores.', 'username');
}

// ===== PASSWORD =====
if (strlen($password) < 8) {
    respondError('Password must be at least 8 characters.', 'password');
}
if (strlen($password) > 20) {
    respondError('Password must be no more than 20 characters.', 'password');
}
if (!preg_match('/^[A-Za-z0-9]+$/', $password)) {
    respondError('Password can only contain letters and numbers.', 'password');
}
if (!preg_match('/[A-Za-z]/', $password)) {
    respondError('Password must contain at least one letter.', 'password');
}
if (!preg_match('/[0-9]/', $password)) {
    respondError('Password must contain at least one number.', 'password');
}
if ($password !== $confirmPassword) {
    respondError('Passwords do not match.', 'confirm_password');
}

// ===== AGE =====
try {
    $bd    = new DateTime($birthdate);
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

// ===== VEHICLE =====
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

$vehicleYearValue = null;
if ($vehicleYear !== '') {
    if (!ctype_digit($vehicleYear)) {
        respondError('Vehicle year must be numeric.', 'vehicle_year');
    }
    $y = (int)$vehicleYear;
    $maxYear = (int)date('Y') + 1;
    if ($y < 1980 || $y > $maxYear) {
        respondError('Please enter a valid vehicle year.', 'vehicle_year');
    }
    $vehicleYearValue = $y;
}

// ===== ADDRESS =====
$allowedLabels = ['Home', 'Base', 'Other'];
$addressLabel  = in_array($addressLabel, $allowedLabels, true) ? $addressLabel : 'Base';

if ($postalCode !== '' && !preg_match('/^[0-9]{3,10}$/', $postalCode)) {
    respondError('Postal code must be numeric.', 'postal_code');
}

// ===== EMERGENCY CONTACT =====
if (strlen($emergencyName) < 2) {
    respondError('Emergency contact name is required.', 'emergency_name');
}
$allowedRelationships = ['Parent', 'Spouse', 'Sibling', 'Relative', 'Friend', 'Other'];
if (!in_array($emergencyRelationship, $allowedRelationships, true)) {
    respondError('Invalid emergency contact relationship.', 'emergency_relationship');
}
$cleanedEmergencyContact = preg_replace('/\s+/', '', $emergencyContact);
if (!preg_match('/^09\d{9}$/', (string)$cleanedEmergencyContact)) {
    respondError('Emergency contact must be a valid PH mobile number (09XXXXXXXXX).', 'emergency_contact');
}

// ===== TERMS =====
if (empty($terms)) {
    respondError('You must agree to the Terms and Conditions and Privacy Policy.', 'terms');
}

// =====================================================================
// DATABASE TRANSACTION
// =====================================================================

try {
    // ---- Uniqueness checks ----
    $checkEmail = $database_connection->prepare(
        "SELECT 1 FROM delivery_rider WHERE email = :email LIMIT 1"
    );
    $checkEmail->execute([':email' => $email]);
    if ($checkEmail->fetchColumn() !== false) {
        respondError('This email address is already registered.', 'email');
    }

    $checkUsername = $database_connection->prepare(
        "SELECT 1 FROM delivery_rider WHERE username = :username LIMIT 1"
    );
    $checkUsername->execute([':username' => $username]);
    if ($checkUsername->fetchColumn() !== false) {
        respondError('This username is already taken.', 'username');
    }

    $checkContact = $database_connection->prepare(
        "SELECT 1 FROM delivery_rider WHERE contact_number = :contact LIMIT 1"
    );
    $checkContact->execute([':contact' => $cleanedContact]);
    if ($checkContact->fetchColumn() !== false) {
        respondError('This mobile number is already registered.', 'contact_number');
    }

    // ---- Begin transaction ----
    $database_connection->beginTransaction();

    // ---- 1. Financial account ----
    $faStmt = $database_connection->prepare(
        "INSERT INTO financial_account (balance, account_type)
         VALUES (0.00, 'rider')"
    );
    $faStmt->execute();
    $financialAccountId = (int)$database_connection->lastInsertId();

    // ---- 2. Delivery rider ----
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    $riderStmt = $database_connection->prepare(
        "INSERT INTO delivery_rider
            (first_name, middle_name, last_name, birthdate, gender,
             email, contact_number, username, password, is_active)
         VALUES
            (:first_name, :middle_name, :last_name, :birthdate, :gender,
             :email, :contact_number, :username, :password, 1)"
    );
    $riderStmt->execute([
        ':first_name'     => $firstName,
        ':middle_name'    => $middleName !== '' ? $middleName : null,
        ':last_name'      => $lastName,
        ':birthdate'      => $birthdate,
        ':gender'         => $gender,
        ':email'          => $email,
        ':contact_number' => $cleanedContact,
        ':username'       => $username,
        ':password'       => $hashedPassword,
    ]);
    $deliveryRiderId = (int)$database_connection->lastInsertId();

    // ---- 3. Rider profile ----
    $profileStmt = $database_connection->prepare(
        "INSERT INTO delivery_rider_profile
            (delivery_rider_id, financial_account_id, vehicle_type,
             vehicle_plate, verification_status, average_rating,
             total_deliveries, is_available)
         VALUES
            (:rider_id, :financial_account_id, :vehicle_type,
             :vehicle_plate, 'pending', 0.0, 0, 0)"
    );
    $profileStmt->execute([
        ':rider_id'             => $deliveryRiderId,
        ':financial_account_id' => $financialAccountId,
        ':vehicle_type'         => $vehicleType,
        ':vehicle_plate'        => $vehiclePlate !== '' ? $vehiclePlate : null,
    ]);

    // ---- 4. Rider address ----
    $addressStmt = $database_connection->prepare(
        "INSERT INTO delivery_rider_address
            (delivery_rider_id, label, block, barangay, city,
             province, region, postal_code, country, is_default)
         VALUES
            (:rider_id, :label, :block, :barangay, :city,
             :province, :region, :postal_code, 'Philippines', 1)"
    );
    $addressStmt->execute([
        ':rider_id'    => $deliveryRiderId,
        ':label'       => $addressLabel,
        ':block'       => $block,
        ':barangay'    => $barangay !== '' ? $barangay : null,
        ':city'        => $city,
        ':province'    => $province !== '' ? $province : null,
        ':region'      => $region   !== '' ? $region   : null,
        ':postal_code' => $postalCode !== '' ? $postalCode : null,
    ]);

    // ---- 5. Stash extended application fields on the session ----
    // These are not persisted to the database today because the schema
    // does not have dedicated columns for them. They are captured here
    // so that when those columns are added, they can be migrated
    // without asking riders to re-enter their details.
    $_SESSION['rider_pending_application'] = [
        'delivery_rider_id'      => $deliveryRiderId,
        'vehicle_make'           => $vehicleMake   !== '' ? $vehicleMake  : null,
        'vehicle_model'          => $vehicleModel  !== '' ? $vehicleModel : null,
        'vehicle_year'           => $vehicleYearValue,
        'emergency_contact_name' => $emergencyName,
        'emergency_relationship' => $emergencyRelationship,
        'emergency_contact'      => $cleanedEmergencyContact,
    ];

    // ---- Commit ----
    $database_connection->commit();

    // ---- Flash success for the sign-in page ----
    $_SESSION['registration_success'] = 'Rider application submitted. Please sign in to continue.';

    echo json_encode([
        'status'   => 'success',
        'message'  => 'Your rider application has been submitted. Please sign in to continue.',
        'redirect' => 'sign-in.php',
    ]);
    exit;

} catch (PDOException $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Rider registration error: ' . $e->getMessage());

    if (str_contains($e->getMessage(), 'Duplicate entry')) {
        if (str_contains($e->getMessage(), 'email')) {
            respondError('This email address is already registered.', 'email');
        }
        if (str_contains($e->getMessage(), 'username')) {
            respondError('This username is already taken.', 'username');
        }
        if (str_contains($e->getMessage(), 'contact_number')) {
            respondError('This mobile number is already registered.', 'contact_number');
        }
    }
    respondError('An unexpected error occurred. Please try again later.');
} catch (Throwable $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Rider registration error: ' . $e->getMessage());
    respondError('An unexpected error occurred. Please try again later.');
}