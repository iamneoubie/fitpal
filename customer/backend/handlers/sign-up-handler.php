<?php
/**
 * FitPal Customer Registration Handler
 *
 * Validates and processes the multi-step registration form.
 * Responds with JSON for fetch()-based submission from sign-up.js.
 *
 * @package FitPal
 * @version 1.6
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';

header('Content-Type: application/json');

/**
 * Emit a JSON error response and halt execution.
 *
 * @param string $message Human-readable error text
 * @param string $field   Input field name to highlight client-side
 * @return never
 */
function respondError(string $message, string $field = ''): never {
    echo json_encode(['status' => 'error', 'message' => $message, 'field' => $field]);
    exit;
}

// ===== REQUEST =====
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Invalid request method.');
}

// ===== CSRF =====
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    respondError('Security validation failed. Please refresh the page and try again.');
}

// ===== COLLECT INPUT =====
$firstName      = trim($_POST['first_name']      ?? '');
$middleName     = trim($_POST['middle_name']     ?? '');
$lastName       = trim($_POST['last_name']       ?? '');
$birthdate      = trim($_POST['birthdate']       ?? '');
$gender         = trim($_POST['gender']          ?? '');
$email          = trim($_POST['email']           ?? '');
$contactNumber  = trim($_POST['contact_number']  ?? '');
$username       = trim($_POST['username']        ?? '');
$password       = $_POST['password']             ?? '';
$confirmPassword = $_POST['confirm_password']    ?? '';
$terms          = $_POST['terms']                ?? '';
$dietaryPreferences = $_POST['dietary_preferences'] ?? [];
$allergies      = $_POST['allergies']            ?? [];
$fitnessGoal    = trim($_POST['fitness_goal']    ?? '');
$height         = trim((string)($_POST['height'] ?? ''));
$weight         = trim((string)($_POST['weight'] ?? ''));

// ===== REQUIRED FIELDS =====
if ($firstName === '' || $lastName === '' || $birthdate === '' || $gender === '' ||
    $email === '' || $contactNumber === '' || $username === '' || $password === '') {
    respondError('All required fields must be filled out.');
}

// ===== NAME VALIDATION =====
// Pattern uses a literal straight apostrophe (U+0027), not the curly variant.
$namePattern = '/^[A-Za-z\s\-\']+$/u';

if (strlen($firstName) < 2) {
    respondError('First name must be at least 2 characters.', 'first_name');
}
if (!preg_match($namePattern, $firstName)) {
    respondError('First name can only contain letters, spaces, hyphens, and apostrophes.', 'first_name');
}

if (strlen($lastName) < 2) {
    respondError('Last name must be at least 2 characters.', 'last_name');
}
if (!preg_match($namePattern, $lastName)) {
    respondError('Last name can only contain letters, spaces, hyphens, and apostrophes.', 'last_name');
}

// ===== GENDER =====
if (!in_array($gender, ['Male', 'Female', 'Other'], true)) {
    respondError('Invalid gender selection.', 'gender');
}

// ===== EMAIL =====
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respondError('Please enter a valid email address.', 'email');
}

// ===== CONTACT NUMBER (Philippine mobile - exactly 11 digits, starts with 09) =====
$cleanedContact = preg_replace('/\s+/', '', $contactNumber);
if (!preg_match('/^09\d{9}$/', $cleanedContact)) {
    respondError('Please enter a valid Philippine mobile number (11 digits, starting with 09).', 'contact_number');
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

// ===== AGE (13+) =====
try {
    $birthDateObj = new DateTime($birthdate);
    $today        = new DateTime();
    $age          = $today->diff($birthDateObj)->y;
    if ($age < 13)  respondError('You must be at least 13 years old to register.', 'birthdate');
    if ($age > 120) respondError('Please enter a valid birthdate.', 'birthdate');
} catch (Exception) {
    respondError('Please enter a valid birthdate.', 'birthdate');
}

// ===== TERMS =====
if (empty($terms)) {
    respondError('You must agree to the Terms and Conditions and Privacy Policy.', 'terms');
}

// ===== DATABASE — DUPLICATE CHECKS =====
try {
    $checkEmail = $database_connection->prepare(
        "SELECT customer_id FROM customer WHERE email = :email LIMIT 1"
    );
    $checkEmail->execute([':email' => $email]);
    if ($checkEmail->fetch()) {
        respondError('This email address is already registered.', 'email');
    }

    $checkUsername = $database_connection->prepare(
        "SELECT customer_id FROM customer WHERE username = :username LIMIT 1"
    );
    $checkUsername->execute([':username' => $username]);
    if ($checkUsername->fetch()) {
        respondError('This username is already taken.', 'username');
    }

    $checkContact = $database_connection->prepare(
        "SELECT customer_id FROM customer WHERE contact_number = :contact LIMIT 1"
    );
    $checkContact->execute([':contact' => $cleanedContact]);
    if ($checkContact->fetch()) {
        respondError('This contact number is already registered.', 'contact_number');
    }

    // ===== TRANSACTION =====
    $database_connection->beginTransaction();

    // Financial account
    $database_connection->prepare(
        "INSERT INTO financial_account (balance, account_type) VALUES (0.00, 'customer')"
    )->execute();
    $financialAccountId = (int) $database_connection->lastInsertId();

    // Customer record
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    $database_connection->prepare(
        "INSERT INTO customer
            (first_name, middle_name, last_name, birthdate, gender,
             email, contact_number, username, password, is_active)
         VALUES
            (:first_name, :middle_name, :last_name, :birthdate, :gender,
             :email, :contact_number, :username, :password, 1)"
    )->execute([
        ':first_name'      => $firstName,
        ':middle_name'     => $middleName !== '' ? $middleName : null,
        ':last_name'       => $lastName,
        ':birthdate'       => $birthdate,
        ':gender'          => $gender,
        ':email'           => $email,
        ':contact_number'  => $cleanedContact,
        ':username'        => $username,
        ':password'        => $hashedPassword,
    ]);
    $customerId = (int) $database_connection->lastInsertId();

    // Customer profile
    $dietaryText  = !empty($dietaryPreferences)
        ? implode(',', array_map('trim', (array) $dietaryPreferences))
        : null;

    $allergiesText = !empty($allergies)
        ? implode(',', array_map('trim', (array) $allergies))
        : null;

    $heightValue  = ($height !== '' && is_numeric($height)) ? (float) $height : null;
    $weightValue  = ($weight !== '' && is_numeric($weight)) ? (float) $weight : null;

    $allowedGoals      = ['weight_loss', 'muscle_gain', 'maintenance'];
    $fitnessGoalValue  = in_array($fitnessGoal, $allowedGoals, true) ? $fitnessGoal : null;

    $database_connection->prepare(
        "INSERT INTO customer_profile
            (customer_id, financial_account_id, dietary_preferences, allergies,
             fitness_goal, height_cm, weight_kg)
         VALUES
            (:customer_id, :financial_account_id, :dietary_preferences, :allergies,
             :fitness_goal, :height_cm, :weight_kg)"
    )->execute([
        ':customer_id'          => $customerId,
        ':financial_account_id' => $financialAccountId,
        ':dietary_preferences'  => $dietaryText,
        ':allergies'            => $allergiesText,
        ':fitness_goal'         => $fitnessGoalValue,
        ':height_cm'            => $heightValue,
        ':weight_kg'            => $weightValue,
    ]);

    $database_connection->commit();

    // Clear session data and set success flash
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
        if (str_contains($e->getMessage(), 'email')) {
            respondError('This email address is already registered.', 'email');
        }
        if (str_contains($e->getMessage(), 'username')) {
            respondError('This username is already taken.', 'username');
        }
        if (str_contains($e->getMessage(), 'contact_number')) {
            respondError('This contact number is already registered.', 'contact_number');
        }
    }

    respondError('An unexpected error occurred. Please try again later.');
}