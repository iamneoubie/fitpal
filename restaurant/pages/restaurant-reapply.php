<?php
/**
 * FitPal Restaurant Re-Application Page
 *
 * Five-step re-application wizard, mirroring the layout and the
 * navigation contract of restaurant/pages/sign-up.php.
 *
 *   Step 1  Personal Information
 *   Step 2  Business Information
 *   Step 3  Branch Information
 *   Step 4  Permits & Documents
 *   Step 5  Review & Submit
 *
 * ---------------------------------------------------------------------
 * ACCESS GATE
 * ---------------------------------------------------------------------
 * The page renders only when the signed-in owner's restaurant has
 * verification_status = 'denied'. Every other status redirects to
 * dashboard.php.
 *
 * ---------------------------------------------------------------------
 * BRANCH LOADING — INLINE RESOLUTION
 * ---------------------------------------------------------------------
 * The owner account's own branch_id is NULL by design; an owner may
 * have more than one branch. To prefill Step 3, the page resolves
 * the restaurant's primary branch directly:
 *
 *   SELECT restaurant_branch_id
 *     FROM restaurant_branch
 *    WHERE restaurant_id = :restaurant_id
 *      AND is_active = 1
 *    ORDER BY restaurant_branch_id ASC
 *    LIMIT 1
 *
 * That is exactly what restaurant/backend/database/product-queries.php's
 * getOwnerPrimaryBranchId() does. The query is inlined here so the
 * reapply page does not need to pull in the product layer — and its
 * transitive customer-role include — just for a two-line lookup.
 *
 * ---------------------------------------------------------------------
 * PERMIT SLOTS — LIVE FOLDER RESOLUTION
 * ---------------------------------------------------------------------
 * The permit slot previews are resolved from disk at render time,
 * not from the restaurant_permit row, so a slot that holds
 * image-1.jpg inside the per-restaurant folder always shows that
 * file even when the row is stale or the folder was migrated.
 *
 * The resolution order is:
 *
 *   1. Read every image-<n>.* file inside
 *      shared/uploads/restaurant/permits/<restaurant_id>/
 *      and key it by slot number.
 *
 *   2. If that folder holds nothing, fall back to the legacy
 *      per-account folder
 *      shared/uploads/restaurant/permits/<account_id>/
 *      and assign MM_DD_YYYY_<n>.<ext> files to slots in ascending
 *      <n> order.
 *
 *   3. Each present slot gets a project-root-relative URL prefix
 *      built from the request depth, so the browser can load the
 *      preview from /restaurant/pages/.
 *
 * ---------------------------------------------------------------------
 * REQUIRED FIELDS
 * ---------------------------------------------------------------------
 * The following fields are required and are marked with the red
 * asterisk in the markup and enforced by restaurant-reapply.js:
 *
 *   Step 1:  first_name, last_name, email, contact_number, username
 *   Step 2:  business_name, cuisine_type,
 *            business_description        (newly required)
 *            dietary_tags                (newly required)
 *   Step 3:  branch_name, block, city,
 *            barangay                    (newly required)
 *            province                    (newly required)
 *            region                      (newly required)
 *            postal_code                 (newly required)
 *   Step 4:  at least one permit slot
 *
 * ---------------------------------------------------------------------
 * FIRST-LETTER CAPITALIZATION
 * ---------------------------------------------------------------------
 * Business Description is a <textarea> that receives the
 * `.capitalize-field` class. The paired JS capitalizes the first
 * letter of the first word on input, matching the behavior of the
 * name fields and matching the sign-up page's Business Description
 * behavior exactly.
 *
 * The name-style fields on Step 1 and Step 3 use the existing
 * `.name-field` filter, which capitalizes each word AND restricts
 * the character set. Business Description does not use that filter
 * because description is free-form text and must accept punctuation.
 *
 * @package FitPal
 * @version 6.0 — Business Description is now required and carries
 *                .capitalize-field. Dietary Tags is now required.
 *                Barangay, Province, Region, and Postal Code on
 *                Step 3 are now required. Every newly required
 *                field carries the red asterisk and the `required`
 *                attribute.
 *
 *                (5.1: name/digits/username/email filter classes.
 *                5.0: permit slot previews read from disk. 4.0:
 *                primary branch resolution inlined. 3.0: status
 *                banner removed. 2.0: five-step wizard. 1.0:
 *                single-page form.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('restaurant');

if (empty($_SESSION['restaurant_account_id'])) {
    header('Location: sign-in.php');
    exit;
}

$accountId    = (int)$_SESSION['restaurant_account_id'];
$restaurantId = (int)($_SESSION['restaurant_id'] ?? 0);
$accountScope = (string)($_SESSION['restaurant_scope'] ?? '');

if ($accountScope !== 'owner') {
    header('Location: dashboard.php');
    exit;
}

if ($restaurantId <= 0) {
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . '/../backend/database/restaurant-connect.php';
require_once __DIR__ . '/../backend/database/restaurant-queries.php';

/* --------------------------------------------------------------
 * ACCESS GATE
 * -------------------------------------------------------------- */

try {
    $gateStmt = $database_connection->prepare(
        "SELECT verification_status, is_active
           FROM restaurant
          WHERE restaurant_id = :restaurant_id
          LIMIT 1"
    );
    $gateStmt->execute([':restaurant_id' => $restaurantId]);
    $gateRow = $gateStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Restaurant re-apply gate load error: ' . $e->getMessage());
    header('Location: dashboard.php');
    exit;
}

if (!$gateRow) {
    header('Location: dashboard.php');
    exit;
}

$verificationStatus = (string)$gateRow['verification_status'];
$isRestaurantActive = ((int)$gateRow['is_active'] === 1);

if ($verificationStatus !== 'denied' || !$isRestaurantActive) {
    header('Location: dashboard.php');
    exit;
}

/* --------------------------------------------------------------
 * LOAD PROFILE
 * -------------------------------------------------------------- */

try {
    $profile = getRestaurantAccountProfile($database_connection, $accountId);
} catch (PDOException $e) {
    error_log('Restaurant re-apply profile load error: ' . $e->getMessage());
    header('Location: dashboard.php');
    exit;
}

if (!$profile) {
    header('Location: dashboard.php');
    exit;
}

/* --------------------------------------------------------------
 * RESOLVE PRIMARY BRANCH — INLINE
 * -------------------------------------------------------------- */

$primaryBranchId = 0;
$branchRow = null;

try {
    $primaryBranchStmt = $database_connection->prepare(
        "SELECT restaurant_branch_id
           FROM restaurant_branch
          WHERE restaurant_id = :restaurant_id
            AND is_active = 1
          ORDER BY restaurant_branch_id ASC
          LIMIT 1"
    );
    $primaryBranchStmt->execute([':restaurant_id' => $restaurantId]);
    $primaryBranchId = (int)($primaryBranchStmt->fetchColumn() ?: 0);
} catch (PDOException $e) {
    error_log('Restaurant re-apply primary branch resolution error: ' . $e->getMessage());
    $primaryBranchId = 0;
}

if ($primaryBranchId > 0) {
    try {
        $branchStmt = $database_connection->prepare(
            "SELECT
                restaurant_branch_id,
                branch_name,
                branch_code,
                block,
                barangay,
                city,
                province,
                region,
                postal_code,
                country,
                is_active
             FROM restaurant_branch
             WHERE restaurant_branch_id = :branch_id
               AND restaurant_id = :restaurant_id
             LIMIT 1"
        );
        $branchStmt->execute([
            ':branch_id'     => $primaryBranchId,
            ':restaurant_id' => $restaurantId,
        ]);
        $branchRow = $branchStmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Restaurant re-apply branch load error: ' . $e->getMessage());
        $branchRow = null;
    }
}

/* --------------------------------------------------------------
 * PRE-FILLED FIELD VALUES
 * -------------------------------------------------------------- */

$firstName     = (string)($profile['first_name']     ?? '');
$middleName    = (string)($profile['middle_name']    ?? '');
$lastName      = (string)($profile['last_name']      ?? '');
$email         = (string)($profile['email']          ?? '');
$contactNumber = (string)($profile['contact_number'] ?? '');
$username      = (string)($profile['username']       ?? '');

$businessName        = (string)($profile['business_name']          ?? '');
$cuisineType         = (string)($profile['cuisine_type']           ?? '');
$businessDescription = (string)($profile['restaurant_description'] ?? '');
$dietaryTags         = (string)($profile['dietary_tags']           ?? '');

$branchName = $branchRow ? (string)($branchRow['branch_name'] ?? '') : '';
$block      = $branchRow ? (string)($branchRow['block']       ?? '') : '';
$barangay   = $branchRow ? (string)($branchRow['barangay']    ?? '') : '';
$city       = $branchRow ? (string)($branchRow['city']        ?? '') : '';
$province   = $branchRow ? (string)($branchRow['province']    ?? '') : '';
$region     = $branchRow ? (string)($branchRow['region']      ?? '') : '';
$postalCode = $branchRow ? (string)($branchRow['postal_code'] ?? '') : '';

/* --------------------------------------------------------------
 * HEADER
 * -------------------------------------------------------------- */

require_once __DIR__ . '/../includes/header.php';

/* --------------------------------------------------------------
 * PROJECT-ROOT URL — DERIVED AFTER THE HEADER
 * -------------------------------------------------------------- */

$projectRootUrl = '';
if (isset($assetBase) && is_string($assetBase) && $assetBase !== '') {
    $trimmed = preg_replace('#shared/$#', '', $assetBase);
    if (is_string($trimmed)) {
        $projectRootUrl = $trimmed;
    }
}

/* --------------------------------------------------------------
 * PERMIT SLOT RESOLUTION — READ FROM DISK
 * -------------------------------------------------------------- */

if (!defined('FITPAL_REAPPLY_MAX_SLOTS')) {
    define('FITPAL_REAPPLY_MAX_SLOTS', 5);
}

if (!defined('FITPAL_REAPPLY_ALLOWED_EXT')) {
    define('FITPAL_REAPPLY_ALLOWED_EXT', ['jpg', 'jpeg', 'png', 'webp']);
}

if (!function_exists('reapplyPermitProjectRoot')) {
    function reapplyPermitProjectRoot(): string
    {
        $root = realpath(__DIR__ . '/../..'); // fitpal/
        return $root === false ? '' : $root;
    }
}

if (!function_exists('reapplyPermitListSlots')) {
    /**
     * @return array<int, array{slot:int, filename:string, url:string, folder:string}>
     */
    function reapplyPermitListSlots(string $absoluteFolder, string $relativeFolder): array
    {
        if ($absoluteFolder === '' || !is_dir($absoluteFolder)) {
            return [];
        }

        $entries = @scandir($absoluteFolder);
        if ($entries === false) {
            return [];
        }

        $allowedExt = FITPAL_REAPPLY_ALLOWED_EXT;
        $bySlot = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (!preg_match('/^image-(\d+)\.([A-Za-z0-9]+)$/', $entry, $m)) {
                continue;
            }

            $slot = (int)$m[1];
            if ($slot < 1 || $slot > FITPAL_REAPPLY_MAX_SLOTS) {
                continue;
            }

            $ext = strtolower($m[2]);
            if (!in_array($ext, $allowedExt, true)) {
                continue;
            }

            $path = $absoluteFolder . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($path)) {
                continue;
            }

            if (!isset($bySlot[$slot])) {
                $bySlot[$slot] = $entry;
            }
        }

        ksort($bySlot, SORT_NUMERIC);

        $out = [];
        foreach ($bySlot as $slot => $filename) {
            $out[$slot] = [
                'slot'     => $slot,
                'filename' => $filename,
                'url'      => $relativeFolder . $filename,
                'folder'   => $relativeFolder,
            ];
        }

        return $out;
    }
}

if (!function_exists('reapplyPermitListLegacySlots')) {
    /**
     * @return array<int, array{slot:int, filename:string, url:string, folder:string}>
     */
    function reapplyPermitListLegacySlots(string $absoluteFolder, string $relativeFolder): array
    {
        if ($absoluteFolder === '' || !is_dir($absoluteFolder)) {
            return [];
        }

        $entries = @scandir($absoluteFolder);
        if ($entries === false) {
            return [];
        }

        $allowedExt = FITPAL_REAPPLY_ALLOWED_EXT;
        $legacy = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (!preg_match('/^(\d{2}_\d{2}_\d{4})_(\d+)\.([A-Za-z0-9]+)$/', $entry, $m)) {
                continue;
            }

            $ext = strtolower($m[3]);
            if (!in_array($ext, $allowedExt, true)) {
                continue;
            }

            $path = $absoluteFolder . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($path)) {
                continue;
            }

            $legacy[] = [
                'filename' => $entry,
                'index'    => (int)$m[2],
            ];
        }

        if (empty($legacy)) {
            return [];
        }

        usort($legacy, static function (array $a, array $b): int {
            return $a['index'] <=> $b['index'];
        });

        $out = [];
        $slot = 1;
        foreach ($legacy as $item) {
            if ($slot > FITPAL_REAPPLY_MAX_SLOTS) {
                break;
            }
            $out[$slot] = [
                'slot'     => $slot,
                'filename' => $item['filename'],
                'url'      => $relativeFolder . $item['filename'],
                'folder'   => $relativeFolder,
            ];
            $slot++;
        }

        return $out;
    }
}

$permitRoot = reapplyPermitProjectRoot();

$restaurantPermitRelFolder = 'shared/uploads/restaurant/permits/' . $restaurantId . '/';
$restaurantPermitAbsFolder = $permitRoot !== ''
    ? $permitRoot . '/' . $restaurantPermitRelFolder
    : '';

$accountPermitRelFolder = 'shared/uploads/restaurant/permits/' . $accountId . '/';
$accountPermitAbsFolder = $permitRoot !== ''
    ? $permitRoot . '/' . $accountPermitRelFolder
    : '';

$permitSlots = reapplyPermitListSlots(
    $restaurantPermitAbsFolder,
    $restaurantPermitRelFolder
);

if (empty($permitSlots)) {
    $permitSlots = reapplyPermitListLegacySlots(
        $accountPermitAbsFolder,
        $accountPermitRelFolder
    );
}

/* --------------------------------------------------------------
 * BUILD THE FIVE-SLOT MAP
 * -------------------------------------------------------------- */

$slots = [];

for ($i = 1; $i <= FITPAL_REAPPLY_MAX_SLOTS; $i++) {
    $hasPermit = isset($permitSlots[$i]);

    $previewUrl = '';
    $folder     = '';
    $filename   = '';

    if ($hasPermit) {
        $filename = $permitSlots[$i]['filename'];
        $folder   = $permitSlots[$i]['folder'];
        $previewUrl = $projectRootUrl . $folder . $filename;
    }

    $slots[$i] = [
        'slot'        => $i,
        'has_permit'  => $hasPermit,
        'filename'    => $filename,
        'folder'      => $folder,
        'preview_url' => $previewUrl,
        'permit_id'   => 0,
    ];
}

$stepTitles = [
    1 => 'Step 1 of 5 — Personal Information',
    2 => 'Step 2 of 5 — Business Information',
    3 => 'Step 3 of 5 — Branch Information',
    4 => 'Step 4 of 5 — Permits & Documents',
    5 => 'Step 5 of 5 — Review & Submit',
];
?>
<link rel="stylesheet" href="../assets/css/restaurant-reapply.css">

<div class="content register-page restaurant-reapply-page" id="restaurantReapplyPage"
    data-handler-url="../backend/handlers/restaurant-reapply-handler.php"
    data-csrf-token="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"
    data-asset-base="<?php echo htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8'); ?>" data-total-steps="5">

    <div class="container">
        <div class="register-card">

            <div class="register-progress" role="progressbar" aria-valuenow="1" aria-valuemin="1" aria-valuemax="5"
                aria-label="Re-application progress">
                <div class="progress-step active" data-step="1">
                    <span class="step-number">1</span>
                    <span class="step-label">Personal</span>
                </div>
                <div class="progress-line" id="progressLine1"></div>
                <div class="progress-step" data-step="2">
                    <span class="step-number">2</span>
                    <span class="step-label">Business</span>
                </div>
                <div class="progress-line" id="progressLine2"></div>
                <div class="progress-step" data-step="3">
                    <span class="step-number">3</span>
                    <span class="step-label">Branch</span>
                </div>
                <div class="progress-line" id="progressLine3"></div>
                <div class="progress-step" data-step="4">
                    <span class="step-number">4</span>
                    <span class="step-label">Permits</span>
                </div>
                <div class="progress-line" id="progressLine4"></div>
                <div class="progress-step" data-step="5">
                    <span class="step-number">5</span>
                    <span class="step-label">Review</span>
                </div>
            </div>

            <div class="register-header">
                <p class="heading-2">Re-apply Your <span>Restaurant</span></p>
                <p class="text-muted" id="stepSubtitle">
                    <?php echo htmlspecialchars($stepTitles[1], ENT_QUOTES, 'UTF-8'); ?>
                </p>
            </div>

            <div id="registerError" class="alert alert-danger" style="display: none;" role="alert">
                <span id="errorMessage"></span>
            </div>

            <form method="POST" action="../backend/handlers/restaurant-reapply-handler.php" class="register-form"
                id="restaurantReapplyForm" enctype="multipart/form-data" novalidate>

                <input type="hidden" name="csrf_token"
                    value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="current_step" id="currentStep" value="1">

                <!-- STEP 1 -->
                <div class="register-step" id="step1">

                    <div class="step-description">
                        <p>The primary account holder's identity on file. Contact support if you
                            need to change your legal name.</p>
                    </div>

                    <div class="form-row form-row-3">
                        <div class="form-group">
                            <label for="first_name" class="form-label">
                                First Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="first_name" name="first_name" class="form-control name-field"
                                placeholder="Enter your first name" autocomplete="given-name" maxlength="50" required
                                value="<?php echo htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-error" id="firstNameError"></div>
                        </div>

                        <div class="form-group">
                            <label for="middle_name" class="form-label">
                                Middle Name <span class="text-muted">(Optional)</span>
                            </label>
                            <input type="text" id="middle_name" name="middle_name" class="form-control name-field"
                                placeholder="Optional" autocomplete="additional-name" maxlength="50"
                                value="<?php echo htmlspecialchars($middleName, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-error" id="middleNameError"></div>
                        </div>

                        <div class="form-group">
                            <label for="last_name" class="form-label">
                                Last Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="last_name" name="last_name" class="form-control name-field"
                                placeholder="Enter your last name" autocomplete="family-name" maxlength="50" required
                                value="<?php echo htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-error" id="lastNameError"></div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="email" class="form-label">
                                Email Address <span class="text-danger">*</span>
                            </label>
                            <input type="email" id="email" name="email" class="form-control email-field"
                                placeholder="Enter your email address" autocomplete="email" maxlength="100" required
                                value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-error" id="emailError"></div>
                        </div>

                        <div class="form-group">
                            <label for="contact_number" class="form-label">
                                Contact Number <span class="text-danger">*</span>
                            </label>
                            <input type="tel" id="contact_number" name="contact_number"
                                class="form-control digits-field" placeholder="09XX XXX XXXX" autocomplete="tel"
                                inputmode="numeric" maxlength="13" required
                                value="<?php echo htmlspecialchars($contactNumber, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-error" id="contactNumberError"></div>
                            <div class="form-hint">11 digits, starting with 09</div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="username" class="form-label">
                                Username <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="username" name="username" class="form-control username-field"
                                placeholder="Choose a username" autocomplete="username" maxlength="20" required
                                value="<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-error" id="usernameError"></div>
                            <div class="form-hint">3–20 characters; letters, numbers, underscore</div>
                        </div>
                    </div>

                    <div class="step-actions">
                        <button type="button" class="btn btn-primary btn-next" data-next="2">
                            Next Step
                        </button>
                    </div>
                </div>

                <!-- STEP 2 -->
                <div class="register-step" id="step2" style="display: none;">

                    <div class="step-description">
                        <p>Update your restaurant's public-facing information if it has changed.</p>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="business_name" class="form-label">
                                Business Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="business_name" name="business_name" class="form-control name-field"
                                placeholder="e.g. Green Bowl Cafe" maxlength="100" required
                                value="<?php echo htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-error" id="businessNameError"></div>
                        </div>

                        <div class="form-group">
                            <label for="cuisine_type" class="form-label">
                                Cuisine Type <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="cuisine_type" name="cuisine_type" class="form-control name-field"
                                placeholder="e.g. Cafe, Asian Fusion, American" maxlength="50" required
                                value="<?php echo htmlspecialchars($cuisineType, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-error" id="cuisineTypeError"></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="business_description" class="form-label">
                            Business Description <span class="text-danger">*</span>
                        </label>
                        <textarea id="business_description" name="business_description"
                            class="form-control capitalize-field"
                            placeholder="Describe your restaurant, your specialty, and what makes it unique..."
                            maxlength="2000" required
                            rows="5"><?php echo htmlspecialchars($businessDescription, ENT_QUOTES, 'UTF-8'); ?></textarea>
                        <div class="form-hint">Max 2000 characters</div>
                        <div class="form-error" id="businessDescriptionError"></div>
                    </div>

                    <div class="form-group">
                        <label for="dietary_tags" class="form-label">
                            Dietary Tags <span class="text-danger">*</span>
                        </label>
                        <input type="text" id="dietary_tags" name="dietary_tags" class="form-control"
                            placeholder="e.g. vegan,organic,gluten_free (comma-separated)" maxlength="200" required
                            value="<?php echo htmlspecialchars($dietaryTags, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="form-error" id="dietaryTagsError"></div>
                        <div class="form-hint">Comma-separated. Example: vegan, gluten_free, halal</div>
                    </div>

                    <div class="step-actions">
                        <button type="button" class="btn btn-outline btn-prev" data-prev="1">
                            Back
                        </button>
                        <button type="button" class="btn btn-primary btn-next" data-next="3">
                            Next Step
                        </button>
                    </div>
                </div>

                <!-- STEP 3 -->
                <div class="register-step" id="step3" style="display: none;">

                    <div class="step-description">
                        <p>Update your branch name and address if they have changed.</p>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="branch_name" class="form-label">
                                Branch Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="branch_name" name="branch_name" class="form-control name-field"
                                placeholder="e.g. Main Branch" maxlength="50" required
                                value="<?php echo htmlspecialchars($branchName, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-error" id="branchNameError"></div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="block" class="form-label">
                                Block / Street / Unit <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="block" name="block" class="form-control name-field"
                                placeholder="e.g. 12-A Sunrise St., Unit 5B" autocomplete="address-line1" required
                                value="<?php echo htmlspecialchars($block, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-error" id="blockError"></div>
                        </div>

                        <div class="form-group">
                            <label for="barangay" class="form-label">
                                Barangay <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="barangay" name="barangay" class="form-control name-field"
                                placeholder="e.g. Barangay San Antonio" autocomplete="address-line2" required
                                value="<?php echo htmlspecialchars($barangay, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-error" id="barangayError"></div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="city" class="form-label">
                                City / Municipality <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="city" name="city" class="form-control name-field"
                                placeholder="e.g. Pasig" autocomplete="address-level2" required
                                value="<?php echo htmlspecialchars($city, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-error" id="cityError"></div>
                        </div>

                        <div class="form-group">
                            <label for="province" class="form-label">
                                Province <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="province" name="province" class="form-control name-field"
                                placeholder="e.g. Metro Manila" autocomplete="address-level1" required
                                value="<?php echo htmlspecialchars($province, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-error" id="provinceError"></div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="region" class="form-label">
                                Region <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="region" name="region" class="form-control name-field"
                                placeholder="e.g. NCR" autocomplete="address-level1" required
                                value="<?php echo htmlspecialchars($region, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-error" id="regionError"></div>
                        </div>

                        <div class="form-group">
                            <label for="postal_code" class="form-label">
                                Postal Code <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="postal_code" name="postal_code" class="form-control digits-field"
                                placeholder="e.g. 1605" autocomplete="postal-code" maxlength="10" inputmode="numeric"
                                required value="<?php echo htmlspecialchars($postalCode, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-error" id="postalCodeError"></div>
                        </div>
                    </div>

                    <div class="step-actions">
                        <button type="button" class="btn btn-outline btn-prev" data-prev="2">
                            Back
                        </button>
                        <button type="button" class="btn btn-primary btn-next" data-next="4">
                            Next Step
                        </button>
                    </div>
                </div>

                <!-- STEP 4 -->
                <div class="register-step" id="step4" style="display: none;">

                    <div class="step-description">
                        <p>Your current permits are shown below. Replace any that have changed,
                            remove any that no longer apply, or add a new one by filling an empty
                            slot. At least one permit must remain on file.</p>
                    </div>

                    <div class="permit-slot-grid" id="permitSlotGrid">
                        <?php foreach ($slots as $slotNumber => $slot):

                            $slotIndex    = (int)$slot['slot'];
                            $hasPermit    = (bool)$slot['has_permit'];
                            $previewUrl   = (string)$slot['preview_url'];
                            $filename     = (string)$slot['filename'];

                            $slotClass = 'permit-slot';
                            if ($hasPermit) {
                                $slotClass .= ' has-permit';
                            }
                        ?>
                        <div class="<?php echo $slotClass; ?>" data-slot="<?php echo $slotIndex; ?>" data-permit-id="0"
                            data-has-permit="<?php echo $hasPermit ? '1' : '0'; ?>"
                            data-existing-url="<?php echo htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8'); ?>"
                            data-existing-filename="<?php echo htmlspecialchars($filename, ENT_QUOTES, 'UTF-8'); ?>"
                            tabindex="0" role="button"
                            aria-label="Permit slot <?php echo $slotIndex; ?><?php echo $hasPermit ? ' (filled)' : ' (empty)'; ?>">

                            <div class="permit-slot-frame">

                                <img class="permit-slot-preview"
                                    src="<?php echo $hasPermit ? htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8') : ''; ?>"
                                    alt="" loading="lazy" style="<?php echo $hasPermit ? '' : 'display: none;'; ?>"
                                    onerror="this.onerror=null; this.style.display='none'; var p=this.parentElement; if(p){ p.classList.remove('has-permit'); p.classList.add('is-broken'); var e=p.querySelector('.permit-slot-empty'); if(e){ e.style.display='flex'; } var lbl=p.querySelector('.permit-slot-empty-label'); if(lbl){ lbl.textContent='Preview unavailable'; } }">

                                <div class="permit-slot-empty"
                                    style="<?php echo $hasPermit ? 'display: none;' : ''; ?>">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/image-upload-fill.svg" alt=""
                                        class="permit-slot-empty-icon" width="24" height="24"
                                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/image-line.svg'">
                                    <span class="permit-slot-empty-label">Add permit</span>
                                </div>

                                <span class="permit-slot-badge permit-slot-badge-index">
                                    <?php echo $slotIndex; ?>
                                </span>

                                <span class="permit-slot-badge permit-slot-badge-new" hidden>New</span>

                                <button type="button" class="permit-slot-remove" aria-label="Remove this permit"
                                    <?php echo $hasPermit ? '' : 'hidden'; ?>>
                                    &times;
                                </button>

                                <span class="permit-slot-removal-banner" hidden>Will be removed</span>

                                <label class="permit-slot-hit" for="permit_file_<?php echo $slotIndex; ?>"
                                    aria-label="Choose a permit image for slot <?php echo $slotIndex; ?>"></label>

                                <input type="file" id="permit_file_<?php echo $slotIndex; ?>"
                                    name="permit_file[<?php echo $slotIndex; ?>]" class="permit-slot-input"
                                    accept="image/jpeg,image/png,image/webp" hidden
                                    data-slot="<?php echo $slotIndex; ?>">

                                <input type="hidden" name="permit_op[<?php echo $slotIndex; ?>]" class="permit-slot-op"
                                    value="keep" data-slot="<?php echo $slotIndex; ?>">

                                <input type="hidden" name="permit_id[<?php echo $slotIndex; ?>]" class="permit-slot-id"
                                    value="0" data-slot="<?php echo $slotIndex; ?>">
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="permit-footer">
                        <div class="permit-footer-info">
                            <span class="permit-footer-count" id="permitFooterCount">
                                Loading permits…
                            </span>
                            <span class="permit-footer-hint">
                                Slot 1 is the primary permit used for verification.
                            </span>
                        </div>
                    </div>

                    <div class="form-error" id="permitsError"></div>

                    <div class="step-actions">
                        <button type="button" class="btn btn-outline btn-prev" data-prev="3">
                            Back
                        </button>
                        <button type="button" class="btn btn-primary btn-next" data-next="5">
                            Next Step
                        </button>
                    </div>
                </div>

                <!-- STEP 5 -->
                <div class="register-step" id="step5" style="display: none;">

                    <div class="step-description">
                        <p>Review your information below. If everything looks correct, submit
                            your updated application for review.</p>
                    </div>

                    <div class="review-summary" id="reviewSummary">
                        <div class="review-section">
                            <p class="review-section-title">Personal</p>
                            <div class="review-row">
                                <span class="review-label">Name</span>
                                <span class="review-value" id="reviewName">—</span>
                            </div>
                            <div class="review-row">
                                <span class="review-label">Email</span>
                                <span class="review-value" id="reviewEmail">—</span>
                            </div>
                            <div class="review-row">
                                <span class="review-label">Mobile</span>
                                <span class="review-value" id="reviewContact">—</span>
                            </div>
                            <div class="review-row">
                                <span class="review-label">Username</span>
                                <span class="review-value" id="reviewUsername">—</span>
                            </div>
                        </div>

                        <div class="review-section">
                            <p class="review-section-title">Business</p>
                            <div class="review-row">
                                <span class="review-label">Name</span>
                                <span class="review-value" id="reviewBusinessName">—</span>
                            </div>
                            <div class="review-row">
                                <span class="review-label">Cuisine</span>
                                <span class="review-value" id="reviewCuisineType">—</span>
                            </div>
                            <div class="review-row">
                                <span class="review-label">Dietary Tags</span>
                                <span class="review-value" id="reviewDietaryTags">—</span>
                            </div>
                        </div>

                        <div class="review-section">
                            <p class="review-section-title">Branch</p>
                            <div class="review-row">
                                <span class="review-label">Name</span>
                                <span class="review-value" id="reviewBranchName">—</span>
                            </div>
                            <div class="review-row">
                                <span class="review-label">Address</span>
                                <span class="review-value" id="reviewBranchAddress">—</span>
                            </div>
                        </div>

                        <div class="review-section">
                            <p class="review-section-title">Permits</p>
                            <div class="review-row">
                                <span class="review-label">Status</span>
                                <span class="review-value" id="reviewPermitStatus">—</span>
                            </div>
                        </div>
                    </div>

                    <div class="step-actions">
                        <button type="button" class="btn btn-outline btn-prev" data-prev="4">
                            Back
                        </button>
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <span id="submitBtnLabel">Submit Application</span>
                        </button>
                    </div>
                </div>
            </form>

            <div class="register-footer">
                <p class="text-muted">
                    Changed your mind?
                    <a href="dashboard.php">Return to the dashboard</a>
                </p>
            </div>
        </div>
    </div>
</div>

<div id="reapplyNotifier" class="reapply-notifier hidden" role="dialog" aria-modal="true"
    aria-labelledby="reapplyNotifierTitle" aria-describedby="reapplyNotifierMessage">
    <div class="reapply-notifier-content">
        <div class="reapply-notifier-icon" aria-hidden="true">
            <img src="<?php echo $assetBase; ?>assets/images/icons/information-fill.svg" alt=""
                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/mail.svg'">
        </div>
        <h3 class="reapply-notifier-title" id="reapplyNotifierTitle">Notice</h3>
        <p class="reapply-notifier-message" id="reapplyNotifierMessage"></p>
        <button type="button" class="reapply-notifier-btn" id="reapplyNotifierBtn">OK</button>
    </div>
</div>

<script src="../assets/ui/js/restaurant-reapply.js" defer></script>
<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>