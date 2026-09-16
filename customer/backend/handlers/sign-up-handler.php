<?php
/**
 * FitPal Customer Registration Handler
 *
 * @package FitPal
 * @version 2.0
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/customer-queries.php';

header('Content-Type: application/json');

function respondError(string $message, string $field = ''): never
{
    echo json_encode(['status' => 'error', 'message' => $message, 'field' => $field]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Invalid request method.');
}

if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    respondError('Security validation failed. Please refresh the page and try again.');
}

$firstName         = trim($_POST['first_name'] ?? '');
$middleName        = trim($_POST['middle_name'] ?? '');
$lastName          = trim($_POST['last_name'] ?? '');
$birthdate         = trim($_POST['birthdate'] ?? '');
$gender            = trim($_POST['gender'] ?? '');
$email             = trim($_POST['email'] ?? '');
$contactNumber     = trim($_POST['contact_number'] ?? '');
$username          = trim($_POST['username'] ?? '');
$password          = $_POST['password'] ?? '';
$confirmPassword   = $_POST['confirm_password'] ?? '';
$terms             = $_POST['terms'] ?? '';
$dietaryPreferences = $_POST['dietary_preferences'] ?? [];
$allergies         = $_POST['allergies'] ?? [];
$fitnessGoal       = trim($_POST['fitness_goal'] ?? '');
$height            = trim((string)($_POST['height'] ?? ''));
$weight            = trim((string)($_POST['weight'] ?? ''));

// ---- Required fields ----
if ($firstName === '' || $lastName === '' || $birthdate === '' || $gender === '' ||
    $email === '' || $contactNumber === '' || $username === '' || $password === '') {
    respondError('All required fields must be filled out.');
}

// ---- Name validation ----
$namePattern = '/^[A-Za-z\s\-\']+$/u';

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

// ---- Gender ----
if (!in_array($gender, ['Male', 'Female', 'Other'], true)) {
    respondError('Invalid gender selection.', 'gender');
}

// ---- Email ----
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respondError('Please enter a valid email address.', 'email');
}

// ---- Contact ----
$cleanedContact = preg_replace('/\s+/', '', $contactNumber);
if (!preg_match('/^09\d{9}$/', $cleanedContact)) {
    respondError('Enter a valid Philippine mobile number (11 digits, starting with 09).', 'contact_number');
}

// ---- Username ----
if (strlen($username) < 3)  respondError('Username must be at least 3 characters.', 'username');
if (strlen($username) > 20) respondError('Username must be no more than 20 characters.', 'username');
if (!preg_match('/^[A-Za-z0-9_]+$/', $username)) {
    respondError('Username can only contain letters, numbers, and underscores.', 'username');
}

// ---- Password ----
if (strlen($password) < 8)  respondError('Password must be at least 8 characters.', 'password');
if (strlen($password) > 20) respondError('Password must be no more than 20 characters.', 'password');
if (!preg_match('/^[A-Za-z0-9]+$/', $password)) {
    respondError('Password can only contain letters and numbers.', 'password');
}
if (!preg_match('/[A-Za-z]/', $password)) respondError('Password must contain at least one letter.', 'password');
if (!preg_match('/[0-9]/', $password))    respondError('Password must contain at least one number.', 'password');
if ($password !== $confirmPassword)       respondError('Passwords do not match.', 'confirm_password');

// ---- Age ----
try {
    $bd    = new DateTime($birthdate);
    $today = new DateTime();
    $age   = $today->diff($bd)->y;
    if ($age < 13)  respondError('You must be at least 13 years old to register.', 'birthdate');
    if ($age > 120) respondError('Please enter a valid birthdate.', 'birthdate');
} catch (Exception) {
    respondError('Please enter a valid birthdate.', 'birthdate');
}

// ---- Terms ----
if (empty($terms)) {
    respondError('You must agree to the Terms and Conditions and Privacy Policy.', 'terms');
}

// ---- Database ----
try {
    if (emailExists($database_connection, $email)) {
        respondError('This email address is already registered.', 'email');
    }
    if (usernameExists($database_connection, $username)) {
        respondError('This username is already taken.', 'username');
    }
    if (contactExists($database_connection, $cleanedContact)) {
        respondError('This contact number is already registered.', 'contact_number');
    }

    $database_connection->beginTransaction();

    $financialAccountId = createFinancialAccount($database_connection);

    $hashed = password_hash($password, PASSWORD_BCRYPT);
    $customerId = createCustomer($database_connection, [
        'first_name'     => $firstName,
        'middle_name'    => $middleName !== '' ? $middleName : null,
        'last_name'      => $lastName,
        'birthdate'      => $birthdate,
        'gender'         => $gender,
        'email'          => $email,
        'contact_number' => $cleanedContact,
        'username'       => $username,
        'password'       => $hashed,
    ]);

    $dietaryText   = !empty($dietaryPreferences)
        ? implode(',', array_map('trim', (array)$dietaryPreferences))
        : null;
    $allergiesText = !empty($allergies)
        ? implode(',', array_map('trim', (array)$allergies))
        : null;

    $heightValue = ($height !== '' && is_numeric($height)) ? (float)$height : null;
    $weightValue = ($weight !== '' && is_numeric($weight)) ? (float)$weight : null;

    $allowedGoals     = ['weight_loss', 'muscle_gain', 'maintenance'];
    $fitnessGoalValue = in_array($fitnessGoal, $allowedGoals, true) ? $fitnessGoal : null;

    createCustomerProfile($database_connection, $customerId, $financialAccountId, [
        'dietary_preferences' => $dietaryText,
        'allergies'           => $allergiesText,
        'fitness_goal'        => $fitnessGoalValue,
        'height_cm'           => $heightValue,
        'weight_kg'           => $weightValue,
    ]);

    $database_connection->commit();

    $_SESSION = [];
    $_SESSION['registration_success'] = 'Account created successfully! Please sign in.';

    echo json_encode([
        'status'   => 'success',
        'message'  => 'Account created successfully! Please sign in.',
        'redirect' => 'sign-in.php',
    ]);
    exit;

} catch (PDOException $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Customer registration error: ' . $e->getMessage());

    if (str_contains($e->getMessage(), 'Duplicate entry')) {
        if (str_contains($e->getMessage(), 'email'))          respondError('This email address is already registered.', 'email');
        if (str_contains($e->getMessage(), 'username'))       respondError('This username is already taken.', 'username');
        if (str_contains($e->getMessage(), 'contact_number')) respondError('This contact number is already registered.', 'contact_number');
    }
    respondError('An unexpected error occurred. Please try again later.');
}