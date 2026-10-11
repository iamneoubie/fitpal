<?php
/**
 * FitPal Customer Profile Handler
 *
 * Runs on the customer session (PHPSESSID_CUSTOMER), separate from
 * every other role's session.
 *
 * Actions:
 *   update_profile          — save the customer's editable personal fields
 *   update_dietary_profile  — save dietary preferences and allergies
 *   update_fitness_profile  — save height, weight, and fitness goal
 *   upload_picture          — receive a new profile picture and store its path
 *   check_sign_out          — read-only pre-flight for the sign-out guard
 *
 * ---------------------------------------------------------------------
 * EDITABLE PERSONAL FIELDS
 * ---------------------------------------------------------------------
 * The personal form now submits every field on the Personal tab.
 * The handler writes:
 *
 *   customer.first_name, middle_name, last_name
 *   customer.birthdate, gender, contact_number
 *
 * Email is NOT writable. It doubles as the customer's login
 * identifier, and the markup keeps it permanently disabled with
 * data-readonly="1". The handler ignores it even if a determined
 * client crafts a POST body that includes it.
 *
 * ---------------------------------------------------------------------
 * THE {field}_submitted MARKER
 * ---------------------------------------------------------------------
 * Every editable form carries a hidden `{field}_submitted=1`
 * marker alongside its `action` field. The markers tell the handler
 * which column a request speaks for:
 *
 *   profile_submitted=1   → write the personal fields
 *   dietary_submitted=1   → write customer_profile.dietary_preferences
 *   allergy_submitted=1   → write customer_profile.allergies
 *   fitness_submitted=1   → write customer_profile.height_cm, weight_kg,
 *                           fitness_goal
 *
 * The marker matters most for the dietary and allergy columns,
 * because an HTML checkbox group with every box unchecked produces
 * no `{field}[]` entries at all on the wire. Without the marker,
 * "customer unchecked everything" and "this form was not part of
 * the request" look identical, and the handler cannot tell whether
 * to clear a column or preserve it. With the marker, the two are
 * distinguishable.
 *
 * ---------------------------------------------------------------------
 * SCOPE RULES APPLIED
 * ---------------------------------------------------------------------
 *   - Every SQL statement lives in this handler's own file or in
 *     customer-queries.php. This file contains no queries against
 *     tables owned by another role.
 *   - CSRF is validated against customer_csrf_token, the customer
 *     context's own session key.
 *   - JSON responses: { status: 'success'|'error', message: string }
 *     Business-rule failures are HTTP 200. Only the auth failure
 *     returns a non-200 status (401), and the CSRF failure returns
 *     403 so a debugging client can tell them apart.
 *
 * @package FitPal
 * @version 4.0 — handleUpdateProfile() now writes every editable
 *                personal field (name, birthdate, gender, contact
 *                number). Email remains read-only.
 *
 *                (3.0: marker-based dispatch for dietary, allergy,
 *                and fitness writes. 2.2: adds
 *                update_fitness_profile. 2.1: adds
 *                update_dietary_profile. 2.0: per-role session
 *                migration.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('customer');

ob_start();

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['customer_id'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../../../shared/backend/database/database-connect.php';
require_once __DIR__ . '/../database/customer-queries.php';

$givenToken = (string)($_POST['csrf_token'] ?? '');
$sessToken  = (string)($_SESSION['customer_csrf_token'] ?? '');

if ($sessToken === '' || $givenToken === '' || !hash_equals($sessToken, $givenToken)) {
    unset($_SESSION['customer_csrf_token']);

    ob_end_clean();
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Security validation failed']);
    exit;
}

$customerId = (int)$_SESSION['customer_id'];
$action     = (string)($_POST['action'] ?? '');

$response = ['status' => 'error', 'message' => 'Invalid action: ' . $action];

try {
    switch ($action) {

        case 'update_profile':
            $response = handleUpdateProfile($database_connection, $customerId);
            break;

        case 'update_dietary_profile':
            $response = handleUpdateDietaryProfile($database_connection, $customerId);
            break;

        case 'update_fitness_profile':
            $response = handleUpdateFitnessProfile($database_connection, $customerId);
            break;

        case 'upload_picture':
            $response = handleUploadPicture($database_connection, $customerId);
            break;

        case 'check_sign_out':
            $response = handleCheckSignOut($database_connection, $customerId);
            break;

        // Unknown action falls through with the default $response.
    }
} catch (PDOException $e) {
    error_log('Customer profile handler DB error: ' . $e->getMessage());
    $response = ['status' => 'error', 'message' => 'A system error occurred. Please try again.'];
} catch (Throwable $e) {
    error_log('Customer profile handler error: ' . $e->getMessage());
    $response = ['status' => 'error', 'message' => 'A system error occurred. Please try again.'];
}

ob_end_clean();
echo json_encode($response);
exit;

/* =============================================================
 * HANDLERS
 * ============================================================= */

/**
 * Validate a name field.
 *
 * Accepts letters (any case), spaces, hyphens, and straight
 * apostrophes. Auto-capitalizes the first letter of each word.
 * Returns the normalized string, or null when the field should
 * fall back to "no change" (empty submission).
 *
 * @param mixed $raw
 * @param int   $minLength
 * @param int   $maxLength
 * @return array{value: string|null, error: string|null}
 */
function normalizeNameField(mixed $raw, int $minLength, int $maxLength): array
{
    if (!is_string($raw) && !is_numeric($raw)) {
        return ['value' => null, 'error' => null];
    }

    $trimmed = trim((string)$raw);

    if ($trimmed === '') {
        return ['value' => null, 'error' => null];
    }

    if (strlen($trimmed) < $minLength) {
        return [
            'value' => null,
            'error' => 'Must be at least ' . $minLength . ' characters.',
        ];
    }

    if (strlen($trimmed) > $maxLength) {
        return [
            'value' => null,
            'error' => 'Must be no more than ' . $maxLength . ' characters.',
        ];
    }

    if (!preg_match("/^[A-Za-z\s\-']+$/u", $trimmed)) {
        return [
            'value' => null,
            'error' => "Contains invalid characters. Use only letters, spaces, hyphens, and apostrophes.",
        ];
    }

    $capitalized = preg_replace_callback(
        "/\b\w/u",
        static fn(array $m): string => mb_strtoupper($m[0]),
        $trimmed
    );

    return ['value' => $capitalized ?? $trimmed, 'error' => null];
}

/**
 * Save the customer's editable personal fields.
 *
 * Writes first_name, middle_name, last_name, birthdate, gender, and
 * contact_number to the customer table. Email is never written — it
 * is the customer's login identifier and stays locked.
 *
 * The form carries `profile_submitted=1`. When that marker is
 * absent, the action refuses with an explanatory message. That is
 * defensive: a request that reaches this action without the marker
 * is not from the profile form.
 *
 * @param PDO $db
 * @param int $customerId
 * @return array{status:string, message:string}
 */
function handleUpdateProfile(PDO $db, int $customerId): array
{
    if (empty($_POST['profile_submitted'])) {
        return [
            'status'  => 'error',
            'message' => 'No profile data was submitted.',
        ];
    }

    // Load current values so we only write fields the request
    // actually carries.
    $current = getCustomerProfile($db, $customerId);
    if (!$current) {
        return ['status' => 'error', 'message' => 'Customer profile not found.'];
    }

    // ---- Name fields ----
    $firstNameResult  = ['value' => (string)($current['first_name'] ?? ''),  'error' => null];
    $middleNameResult = ['value' => (string)($current['middle_name'] ?? ''), 'error' => null];
    $lastNameResult   = ['value' => (string)($current['last_name'] ?? ''),   'error' => null];

    if (array_key_exists('first_name', $_POST)) {
        $firstNameResult = normalizeNameField($_POST['first_name'], 2, 50);
        if ($firstNameResult['error'] !== null) {
            return ['status' => 'error', 'message' => 'First name: ' . $firstNameResult['error']];
        }
        if ($firstNameResult['value'] === null || $firstNameResult['value'] === '') {
            return ['status' => 'error', 'message' => 'First name is required.'];
        }
    }

    if (array_key_exists('middle_name', $_POST)) {
        $middleNameResult = normalizeNameField($_POST['middle_name'], 1, 50);
        if ($middleNameResult['error'] !== null) {
            return ['status' => 'error', 'message' => 'Middle name: ' . $middleNameResult['error']];
        }
    }

    if (array_key_exists('last_name', $_POST)) {
        $lastNameResult = normalizeNameField($_POST['last_name'], 2, 50);
        if ($lastNameResult['error'] !== null) {
            return ['status' => 'error', 'message' => 'Last name: ' . $lastNameResult['error']];
        }
        if ($lastNameResult['value'] === null || $lastNameResult['value'] === '') {
            return ['status' => 'error', 'message' => 'Last name is required.'];
        }
    }

    // ---- Birthdate ----
    $birthdateValue = $current['birthdate'] ?? null;

    if (array_key_exists('birthdate', $_POST)) {
        $rawBirthdate = trim((string)($_POST['birthdate'] ?? ''));
        if ($rawBirthdate === '') {
            $birthdateValue = null;
        } else {
            $parsed = DateTime::createFromFormat('Y-m-d', $rawBirthdate);
            if (!$parsed || $parsed->format('Y-m-d') !== $rawBirthdate) {
                return ['status' => 'error', 'message' => 'Invalid birthdate format.'];
            }

            $today    = new DateTime('today');
            $age      = (int)$today->diff($parsed)->y;

            if ($age < 13) {
                return ['status' => 'error', 'message' => 'You must be at least 13 years old.'];
            }
            if ($age > 120) {
                return ['status' => 'error', 'message' => 'Please enter a valid birthdate.'];
            }

            $birthdateValue = $rawBirthdate;
        }
    }

    // ---- Gender ----
    $genderValue = $current['gender'] ?? null;
    $allowedGenders = ['Male', 'Female', 'Other'];

    if (array_key_exists('gender', $_POST)) {
        $rawGender = trim((string)($_POST['gender'] ?? ''));
        if ($rawGender === '') {
            $genderValue = null;
        } elseif (in_array($rawGender, $allowedGenders, true)) {
            $genderValue = $rawGender;
        } else {
            return ['status' => 'error', 'message' => 'Invalid gender selection.'];
        }
    }

    // ---- Contact number ----
    $contactValue = $current['contact_number'] ?? null;

    if (array_key_exists('contact_number', $_POST)) {
        $rawContact = trim((string)($_POST['contact_number'] ?? ''));
        if ($rawContact === '') {
            $contactValue = null;
        } else {
            $cleaned = preg_replace('/\s+/', '', $rawContact);
            if (!preg_match('/^09\d{9}$/', (string)$cleaned)) {
                return [
                    'status'  => 'error',
                    'message' => 'Invalid contact number. Use 09XXXXXXXXX (11 digits).',
                ];
            }
            $contactValue = $cleaned;
        }
    }

    // ---- Write ----
    $stmt = $db->prepare(
        "UPDATE customer
            SET first_name     = :first_name,
                middle_name    = :middle_name,
                last_name      = :last_name,
                birthdate      = :birthdate,
                gender         = :gender,
                contact_number = :contact_number
          WHERE customer_id    = :customer_id"
    );
    $stmt->execute([
        ':first_name'     => $firstNameResult['value'],
        ':middle_name'    => $middleNameResult['value'] !== '' ? $middleNameResult['value'] : null,
        ':last_name'      => $lastNameResult['value'],
        ':birthdate'      => $birthdateValue,
        ':gender'         => $genderValue,
        ':contact_number' => $contactValue,
        ':customer_id'    => $customerId,
    ]);

    // Refresh the session-cached display name so the header
    // renders the new name on the next page load.
    unset($_SESSION['customer_name']);

    return [
        'status'  => 'success',
        'message' => 'Profile updated successfully.',
    ];
}

/**
 * Save the customer's dietary preferences and allergies.
 *
 * The dietary form and the allergy form are submitted back to back
 * by the save pipeline, and both carry the same action. The
 * `dietary_submitted` and `allergy_submitted` markers tell the
 * handler which column this request speaks for.
 *
 * @param PDO $db
 * @param int $customerId
 * @return array{status:string, message:string}
 */
function handleUpdateDietaryProfile(PDO $db, int $customerId): array
{
    $dietarySubmitted = !empty($_POST['dietary_submitted']);
    $allergySubmitted = !empty($_POST['allergy_submitted']);

    if (!$dietarySubmitted && !$allergySubmitted) {
        return [
            'status'  => 'error',
            'message' => 'No dietary or allergy data was submitted.',
        ];
    }

    $cleanItems = static function (mixed $raw): array {
        if (!is_array($raw)) {
            $raw = $raw === null || $raw === '' ? [] : [$raw];
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_string($item) && !is_numeric($item)) {
                continue;
            }
            $trimmed = trim((string)$item);
            if ($trimmed === '') {
                continue;
            }
            if (!in_array($trimmed, $out, true)) {
                $out[] = $trimmed;
            }
        }
        return $out;
    };

    $current = getCustomerDietaryProfile($db, $customerId) ?: [];
    $currentDietaryStr = (string)($current['dietary_preferences'] ?? '');
    $currentAllergyStr = (string)($current['allergies'] ?? '');

    if ($dietarySubmitted) {
        $dietaryItems  = $cleanItems($_POST['dietary_preferences'] ?? []);
        $dietaryString = !empty($dietaryItems) ? implode(',', $dietaryItems) : null;
    } else {
        $dietaryString = $currentDietaryStr !== '' ? $currentDietaryStr : null;
    }

    if ($allergySubmitted) {
        $allergyItems  = $cleanItems($_POST['allergies'] ?? []);
        $allergyString = !empty($allergyItems) ? implode(',', $allergyItems) : null;
    } else {
        $allergyString = $currentAllergyStr !== '' ? $currentAllergyStr : null;
    }

    $stmt = $db->prepare(
        "UPDATE customer_profile
            SET dietary_preferences = :dietary,
                allergies           = :allergies
          WHERE customer_id = :customer_id"
    );
    $stmt->execute([
        ':dietary'     => $dietaryString,
        ':allergies'   => $allergyString,
        ':customer_id' => $customerId,
    ]);

    unset($_SESSION['customer_dietary_preferences']);
    unset($_SESSION['customer_allergies']);

    return [
        'status'  => 'success',
        'message' => 'Dietary preferences and allergies updated.',
    ];
}

/**
 * Save the customer's fitness profile.
 *
 * @param PDO $db
 * @param int $customerId
 * @return array{status:string, message:string}
 */
function handleUpdateFitnessProfile(PDO $db, int $customerId): array
{
    $submitted = !empty($_POST['fitness_submitted']);

    if (!$submitted) {
        return [
            'status'  => 'error',
            'message' => 'No fitness data was submitted.',
        ];
    }

    $current = getCustomerProfile($db, $customerId) ?: [];
    $currentHeight = $current['height_cm'] ?? null;
    $currentWeight = $current['weight_kg'] ?? null;
    $currentGoal   = (string)($current['fitness_goal'] ?? '');

    // ---- Height ----
    if (array_key_exists('height', $_POST)) {
        $rawHeight = trim((string)($_POST['height'] ?? ''));
        if ($rawHeight === '') {
            $heightValue = null;
        } else {
            if (!is_numeric($rawHeight)) {
                return ['status' => 'error', 'message' => 'Height must be a number.'];
            }
            $heightValue = (float)$rawHeight;
            if ($heightValue < 50 || $heightValue > 300) {
                return ['status' => 'error', 'message' => 'Height must be between 50 and 300 cm.'];
            }
        }
    } else {
        $heightValue = $currentHeight !== null ? (float)$currentHeight : null;
    }

    // ---- Weight ----
    if (array_key_exists('weight', $_POST)) {
        $rawWeight = trim((string)($_POST['weight'] ?? ''));
        if ($rawWeight === '') {
            $weightValue = null;
        } else {
            if (!is_numeric($rawWeight)) {
                return ['status' => 'error', 'message' => 'Weight must be a number.'];
            }
            $weightValue = (float)$rawWeight;
            if ($weightValue < 10 || $weightValue > 500) {
                return ['status' => 'error', 'message' => 'Weight must be between 10 and 500 kg.'];
            }
        }
    } else {
        $weightValue = $currentWeight !== null ? (float)$currentWeight : null;
    }

    // ---- Goal ----
    $allowedGoals = ['weight_loss', 'muscle_gain', 'maintenance'];

    if (array_key_exists('fitness_goal', $_POST)) {
        $rawGoal = trim((string)($_POST['fitness_goal'] ?? ''));
        if ($rawGoal === '') {
            $goalValue = null;
        } elseif (in_array($rawGoal, $allowedGoals, true)) {
            $goalValue = $rawGoal;
        } else {
            return ['status' => 'error', 'message' => 'Invalid fitness goal selected.'];
        }
    } else {
        $goalValue = in_array($currentGoal, $allowedGoals, true) ? $currentGoal : null;
    }

    $stmt = $db->prepare(
        "UPDATE customer_profile
            SET height_cm    = :height,
                weight_kg    = :weight,
                fitness_goal = :goal
          WHERE customer_id = :customer_id"
    );
    $stmt->execute([
        ':height'      => $heightValue,
        ':weight'      => $weightValue,
        ':goal'        => $goalValue,
        ':customer_id' => $customerId,
    ]);

    return [
        'status'  => 'success',
        'message' => 'Fitness profile updated.',
    ];
}

/**
 * Read-only pre-flight for the customer sign-out guard.
 *
 * @param PDO $db
 * @param int $customerId
 * @return array{status:string, can_sign_out:bool, active_orders:int, queue_count:int}
 */
function handleCheckSignOut(PDO $db, int $customerId): array
{
    $activeStatuses = [
        'pending',
        'preparing',
        'rider_pending',
        'picking_up',
        'delivering',
    ];
    $placeholders = implode(',', array_fill(0, count($activeStatuses), '?'));

    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM orders
          WHERE customer_id = ?
            AND order_status IN ($placeholders)"
    );
    $stmt->execute(array_merge([$customerId], $activeStatuses));
    $activeOrders = (int)$stmt->fetchColumn();

    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(quantity), 0) FROM cart WHERE customer_id = ?"
    );
    $stmt->execute([$customerId]);
    $cartUnits = (int)$stmt->fetchColumn();

    $sessionUnits = 0;
    if (!empty($_SESSION['order_queue']) && is_array($_SESSION['order_queue'])) {
        foreach ($_SESSION['order_queue'] as $line) {
            $sessionUnits += (int)($line['quantity'] ?? 0);
        }
    }

    $queueCount = $cartUnits + $sessionUnits;

    return [
        'status'        => 'success',
        'can_sign_out'  => ($activeOrders === 0),
        'active_orders' => $activeOrders,
        'queue_count'   => $queueCount,
    ];
}

/**
 * Receive an uploaded profile picture, move it into place, store
 * the resulting project-root-relative path, and sync the session.
 *
 * @param PDO $db
 * @param int $customerId
 * @return array{status:string, message:string, path?:string, url?:string}
 */
function handleUploadPicture(PDO $db, int $customerId): array
{
    if (empty($_FILES['profile_picture']) || !is_array($_FILES['profile_picture'])) {
        return ['status' => 'error', 'message' => 'No file uploaded.'];
    }

    $file = $_FILES['profile_picture'];

    if (!isset($file['error']) || is_array($file['error'])) {
        return ['status' => 'error', 'message' => 'Invalid upload payload.'];
    }

    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['status' => 'error', 'message' => 'No file uploaded.'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [
            'status'  => 'error',
            'message' => 'Upload failed (code ' . (int)$file['error'] . ').',
        ];
    }

    if (!isset($file['size']) || (int)$file['size'] <= 0) {
        return ['status' => 'error', 'message' => 'Uploaded file is empty.'];
    }

    if ((int)$file['size'] > 2 * 1024 * 1024) {
        return ['status' => 'error', 'message' => 'File must be under 2 MB.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        return ['status' => 'error', 'message' => 'Could not inspect the uploaded file.'];
    }

    $mime = (string)finfo_file($finfo, (string)$file['tmp_name']);
    finfo_close($finfo);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowed[$mime])) {
        return [
            'status'  => 'error',
            'message' => 'Unsupported file type. Use JPG, PNG, or WEBP.',
        ];
    }

    $ext = $allowed[$mime];

    $projectRoot = realpath(__DIR__ . '/../../..');
    if ($projectRoot === false) {
        return ['status' => 'error', 'message' => 'Server storage path unavailable.'];
    }

    $relativeDir = 'shared/uploads/customer/profiles/' . $customerId;
    $uploadDir   = $projectRoot . '/' . $relativeDir;

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return ['status' => 'error', 'message' => 'Could not create upload directory.'];
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

    $fullPath     = $uploadDir . '/' . $filename;
    $relativePath = $relativeDir . '/' . $filename;

    if (!move_uploaded_file((string)$file['tmp_name'], $fullPath)) {
        return ['status' => 'error', 'message' => 'Could not save the uploaded file.'];
    }

    try {
        updateCustomerProfilePicture($db, $customerId, $relativePath);
    } catch (Throwable $e) {
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
        throw $e;
    }

    $_SESSION['customer_profile_picture'] = $relativePath;

    $url = '';

    $projectRootFs  = $projectRoot;
    $documentRootFs = isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT'])
        ? realpath($_SERVER['DOCUMENT_ROOT'])
        : false;

    if ($projectRootFs !== false && $documentRootFs !== false) {
        $projectRootFs  = str_replace('\\', '/', $projectRootFs);
        $documentRootFs = rtrim(str_replace('\\', '/', $documentRootFs), '/');

        $urlPrefix = '';

        if ($projectRootFs === $documentRootFs) {
            $urlPrefix = '';
        } elseif (strpos($projectRootFs, $documentRootFs . '/') === 0) {
            $urlPrefix = substr($projectRootFs, strlen($documentRootFs));
        } else {
            $urlPrefix = '';
        }

        $url = $urlPrefix . '/' . ltrim($relativePath, '/');
    }

    return [
        'status'  => 'success',
        'message' => 'Profile picture updated.',
        'path'    => $relativePath,
        'url'     => $url,
    ];
}