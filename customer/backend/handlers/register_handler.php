<?php
/**
 * FitPal Customer Registration Handler
 *
 * Validates and processes the multi-step registration form,
 * including the required Terms & Conditions / Privacy Policy agreement.
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../shared/backend/database/database_connect.php';

header('Content-Type: application/json');

/**
 * Send a JSON error response and stop execution.
 */
function respondError(string $message, string $field = ''): void {
    echo json_encode(['status' => 'error', 'message' => $message, 'field' => $field]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('invalid-request');
}

// ===== CSRF VALIDATION =====
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    respondError('Security validation failed. Please refresh the page and try again.');
}

// ===== COLLECT INPUT =====
$firstName = trim($_POST['first_name'] ?? '');
$middleName = trim($_POST['middle_name'] ?? '');
$lastName = trim($_POST['last_name'] ?? '');
$birthdate = trim($_POST['birthdate'] ?? '');
$gender = trim($_POST['gender'] ?? '');
$email = trim($_POST['email'] ?? '');
$contactNumber = trim($_POST['contact_number'] ?? '');
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';
$terms = $_POST['terms'] ?? '';
$dietaryPreferences = $_POST['dietary_preferences'] ?? [];
$allergies = $_POST['allergies'] ?? [];
$fitnessGoal = trim($_POST['fitness_goal'] ?? '');
$height = trim((string)($_POST['height'] ?? ''));
$weight = trim((string)($_POST['weight'] ?? ''));

// ===== REQUIRED FIELD VALIDATION (Step 1) =====
if ($firstName === '' || $lastName === '' || $birthdate === '' || $gender === '' ||
    $email === '' || $contactNumber === '' || $username === '' || $password === '') {
    respondError('missing-field');
}

// ===== FIXED: Enforce Terms & Conditions / Privacy Policy agreement =====
// Previously this field did not exist on the form at all.
if (empty($terms)) {
    respondError('You must agree to the Terms and Conditions and Privacy Policy.', 'terms');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respondError('invalid-email', 'email');
}

if (strlen($username) < 3 || strlen($username) > 20 || !preg_match('/^[A-Za-z0-9_]+$/', $username)) {
    respondError('username-invalid', 'username');
}

if (strlen($password) < 6 || strlen($password) > 20 || !preg_match('/^[A-Za-z0-9]+$/', $password)) {
    respondError('password-invalid', 'password');
}

if ($password !== $confirmPassword) {
    respondError('passwords-mismatch', 'confirm_password');
}

if (!in_array($gender, ['Male', 'Female', 'Other'], true)) {
    respondError('invalid-gender', 'gender');
}

// ===== AGE VALIDATION (13+) =====
try {
    $birthDateObj = new DateTime($birthdate);
    $today = new DateTime();
    $age = $today->diff($birthDateObj)->y;
    if ($age < 13) {
        respondError('age-restriction', 'birthdate');
    }
    if ($age > 120) {
        respondError('invalid-birthdate', 'birthdate');
    }
} catch (Exception $e) {
    respondError('invalid-birthdate', 'birthdate');
}

try {
    // ===== DUPLICATE CHECK =====
    $checkStmt = $database_connection->prepare(
        "SELECT customer_id FROM customer
         WHERE email = :email OR username = :username OR contact_number = :contact
         LIMIT 1"
    );
    $checkStmt->execute([
        ':email' => $email,
        ':username' => $username,
        ':contact' => $contactNumber
    ]);
    if ($checkStmt->fetch(PDO::FETCH_ASSOC)) {
        respondError('duplicate-account');
    }

    $database_connection->beginTransaction();

    // ===== CREATE FINANCIAL ACCOUNT =====
    $accountStmt = $database_connection->prepare(
        "INSERT INTO financial_account (balance, account_type) VALUES (0.00, 'customer')"
    );
    $accountStmt->execute();
    $financialAccountId = (int)$database_connection->lastInsertId();

    // ===== CREATE CUSTOMER =====
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
    $customerStmt = $database_connection->prepare(
        "INSERT INTO customer (
            first_name, middle_name, last_name, birthdate, gender,
            email, contact_number, username, password, is_active
        ) VALUES (
            :first_name, :middle_name, :last_name, :birthdate, :gender,
            :email, :contact_number, :username, :password, 1
        )"
    );
    $customerStmt->execute([
        ':first_name' => $firstName,
        ':middle_name' => $middleName !== '' ? $middleName : null,
        ':last_name' => $lastName,
        ':birthdate' => $birthdate,
        ':gender' => $gender,
        ':email' => $email,
        ':contact_number' => $contactNumber,
        ':username' => $username,
        ':password' => $hashedPassword
    ]);
    $customerId = (int)$database_connection->lastInsertId();

    // ===== CREATE CUSTOMER PROFILE =====
    $dietaryText = !empty($dietaryPreferences)
        ? implode(',', array_map('trim', (array)$dietaryPreferences))
        : null;
    $allergiesText = !empty($allergies)
        ? implode(',', array_map('trim', (array)$allergies))
        : null;

    $heightValue = ($height !== '' && is_numeric($height)) ? (float)$height : null;
    $weightValue = ($weight !== '' && is_numeric($weight)) ? (float)$weight : null;

    // DB constraint only allows these three values for fitness_goal
    $allowedGoals = ['weight_loss', 'muscle_gain', 'maintenance'];
    $fitnessGoalValue = in_array($fitnessGoal, $allowedGoals, true) ? $fitnessGoal : null;

    $profileStmt = $database_connection->prepare(
        "INSERT INTO customer_profile (
            customer_id, financial_account_id, dietary_preferences, allergies,
            fitness_goal, height_cm, weight_kg
        ) VALUES (
            :customer_id, :financial_account_id, :dietary_preferences, :allergies,
            :fitness_goal, :height_cm, :weight_kg
        )"
    );
    $profileStmt->execute([
        ':customer_id' => $customerId,
        ':financial_account_id' => $financialAccountId,
        ':dietary_preferences' => $dietaryText,
        ':allergies' => $allergiesText,
        ':fitness_goal' => $fitnessGoalValue,
        ':height_cm' => $heightValue,
        ':weight_kg' => $weightValue
    ]);

    $database_connection->commit();

    // ===== LOG IN THE NEW CUSTOMER =====
    session_regenerate_id(true);
    $_SESSION['customer_id'] = $customerId;
    $_SESSION['user_role'] = 'customer';
    $_SESSION['user_name'] = $firstName . ' ' . $lastName;
    $_SESSION['user_email'] = $email;
    $_SESSION['user_username'] = $username;
    unset($_SESSION['csrf_token']);

    echo json_encode([
        'status' => 'success',
        'message' => 'Account created successfully! Redirecting...',
        'redirect' => 'dashboard.php'
    ]);
    exit;

} catch (PDOException $e) {
    if ($database_connection->inTransaction()) {
        $database_connection->rollBack();
    }
    error_log('Customer registration error: ' . $e->getMessage());
    respondError('An unexpected error occurred. Please try again later.');
}