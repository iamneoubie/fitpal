<?php
/**
 * FitPal Customer Profile Page
 *
 * Shows personal information, delivery addresses, dietary
 * preferences, allergies, and fitness goals as five separate tabs.
 *
 * ---------------------------------------------------------------------
 * TABS
 * ---------------------------------------------------------------------
 * Five tabs:
 *
 *   personal    Personal Information (contact number, birthdate, etc.)
 *   addresses   Delivery Addresses (add / edit / delete)
 *   dietary     Dietary Preferences (checkbox card grid)
 *   allergies   Allergies (checkbox card grid)
 *   fitness     Fitness Goals (height, weight, goal select)
 *
 * ---------------------------------------------------------------------
 * EDITABLE FIELDS
 * ---------------------------------------------------------------------
 * Every field on the Personal tab is editable except email, which
 * remains read-only because it is the customer's login identifier.
 * The name fields, contact number, birthdate, and gender all become
 * editable when the customer enters edit mode.
 *
 * The save pipeline submits the personal form first, then the
 * dietary, allergy, and fitness forms, then the picture upload.
 *
 * ---------------------------------------------------------------------
 * SUBMIT MARKERS
 * ---------------------------------------------------------------------
 * Every editable form carries a hidden `{field}_submitted=1`
 * marker alongside its `action` field. The markers let the handler
 * tell "the form was submitted with every checkbox unchecked" apart
 * from "the form was never part of this request". Without them, an
 * all-unchecked save is indistinguishable from a not-submitted
 * request, and the handler cannot decide whether to clear a column
 * or preserve it.
 *
 * ---------------------------------------------------------------------
 * EMPTY STATES
 * ---------------------------------------------------------------------
 * The addresses, dietary, and allergy tabs render a proper empty
 * state when there is no data, using the same shape as the cart and
 * orders pages.
 *
 * @package FitPal
 * @version 7.5 — The Personal tab now enables every field in edit
 *                mode except email. Dietary empty state uses
 *                equalizer-line.svg; allergy empty state uses
 *                list-settings-fill.svg.
 *
 *                (7.4: submit markers. 7.3: empty states. 7.2:
 *                Fitness tab. 7.1: four tabs. 7.0: three tabs.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('customer');

if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    header('Location: sign-in.php');
    exit;
}

const PROFILE_RETURN_DESTINATIONS = [
    'checkout'  => 'checkout.php',
    'menu'      => 'menu.php',
    'orders'    => 'orders.php',
    'cart'      => 'cart.php',
    'wallet'    => 'wallet.php',
    'dashboard' => 'dashboard.php',
];

$returnSlug = isset($_GET['from']) ? strtolower(trim((string)$_GET['from'])) : '';
if ($returnSlug !== '' && isset(PROFILE_RETURN_DESTINATIONS[$returnSlug])) {
    $_SESSION['profile_return_slug'] = $returnSlug;
}

$returnSlug  = $_SESSION['profile_return_slug'] ?? '';
$returnHref  = $returnSlug !== '' ? PROFILE_RETURN_DESTINATIONS[$returnSlug] : '';
$returnLabel = $returnSlug !== '' ? ucfirst($returnSlug) : '';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../backend/database/customer-queries.php';
require_once __DIR__ . '/../backend/database/address-queries.php';

$customerId = (int)$_SESSION['customer_id'];

$profileData = getCustomerProfile($database_connection, $customerId) ?: null;
$addresses   = getCustomerAddresses($database_connection, $customerId);

$hasAddresses = !empty($addresses);
$fullName     = trim(($profileData['first_name'] ?? '') . ' ' . ($profileData['last_name'] ?? ''));
$balance      = (float)($profileData['balance'] ?? 0);

$currentDietaryRaw = (string)($profileData['dietary_preferences'] ?? '');
$currentAllergyRaw = (string)($profileData['allergies'] ?? '');

$currentDietary = [];
if (trim($currentDietaryRaw) !== '') {
    foreach (explode(',', $currentDietaryRaw) as $item) {
        $trimmed = trim($item);
        if ($trimmed !== '' && strtolower($trimmed) !== 'none') {
            $currentDietary[] = $trimmed;
        }
    }
}

$currentAllergies = [];
if (trim($currentAllergyRaw) !== '') {
    foreach (explode(',', $currentAllergyRaw) as $item) {
        $trimmed = trim($item);
        if ($trimmed !== '' && strtolower($trimmed) !== 'none') {
            $currentAllergies[] = $trimmed;
        }
    }
}

$currentFitnessGoal = (string)($profileData['fitness_goal'] ?? '');
$currentHeight      = $profileData['height_cm'] ?? '';
$currentWeight      = $profileData['weight_kg'] ?? '';

$dietaryOptions = [
    'vegan'         => 'Vegan',
    'vegetarian'    => 'Vegetarian',
    'keto'          => 'Keto',
    'high_protein'  => 'High Protein',
    'low_carb'      => 'Low Carb',
    'gluten_free'   => 'Gluten Free',
    'dairy_free'    => 'Dairy Free',
    'pescatarian'   => 'Pescatarian',
    'mediterranean' => 'Mediterranean',
    'halal'         => 'Halal',
];

$allergyOptions = [
    'nuts'      => 'Nuts',
    'dairy'     => 'Dairy',
    'eggs'      => 'Eggs',
    'soy'       => 'Soy',
    'wheat'     => 'Wheat / Gluten',
    'shellfish' => 'Shellfish',
    'fish'      => 'Fish',
    'peanuts'   => 'Peanuts',
    'sesame'    => 'Sesame',
];

$fitnessGoalOptions = [
    'weight_loss' => 'Weight Loss',
    'muscle_gain' => 'Muscle Gain',
    'maintenance' => 'Maintenance / General Health',
];

$dietarySummaryLabels = [];
foreach ($currentDietary as $value) {
    $dietarySummaryLabels[] = $dietaryOptions[$value] ?? ucwords(str_replace('_', ' ', $value));
}

$allergySummaryLabels = [];
foreach ($currentAllergies as $value) {
    $allergySummaryLabels[] = $allergyOptions[$value] ?? ucwords(str_replace('_', ' ', $value));
}

$fitnessGoalLabel = $fitnessGoalOptions[$currentFitnessGoal]
    ?? ($currentFitnessGoal !== '' ? ucwords(str_replace('_', ' ', $currentFitnessGoal)) : '');

$hasDietary   = !empty($dietarySummaryLabels);
$hasAllergies = !empty($allergySummaryLabels);

$profilePicPath = (string)($profileData['profile_picture'] ?? '');
$profilePicUrl  = '';

if ($profilePicPath !== '' && is_string($assetBase) && $assetBase !== '') {
    $projectRootUrl = preg_replace('#shared/$#', '', $assetBase);
    if (is_string($projectRootUrl)) {
        $profilePicUrl = $projectRootUrl . $profilePicPath;
    }
}

$initial = strtoupper(substr($fullName !== '' ? $fullName : 'U', 0, 1));
if ($initial === '') {
    $initial = 'U';
}
?>

<div class="content profile-page">
    <div class="container">
        <div class="page-title-header">
            <div class="page-title-header-top">
                <button type="button" id="profileBackBtn" class="back-btn"
                    data-fallback-href="<?php echo $returnHref !== '' ? htmlspecialchars($returnHref, ENT_QUOTES, 'UTF-8') : ''; ?>">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-left-line.svg" alt="Back"
                        class="back-btn-icon" width="20" height="20">
                    <span>Back<?php echo $returnLabel !== '' ? ' to ' . htmlspecialchars($returnLabel, ENT_QUOTES, 'UTF-8') : ''; ?></span>
                </button>
                <h1>My Profile</h1>
            </div>
        </div>

        <?php if (isset($_SESSION['profile_success'])): ?>
        <div class="alert alert-success" role="alert">
            <?php echo htmlspecialchars($_SESSION['profile_success'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['profile_success']); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['profile_error'])): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo htmlspecialchars($_SESSION['profile_error'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['profile_error']); ?>
        </div>
        <?php endif; ?>

        <div class="profile-header-card" id="profileHeaderCard">

            <div class="profile-header-left">

                <div class="profile-avatar-wrap">

                    <?php if ($profilePicUrl !== ''): ?>
                    <img src="<?php echo htmlspecialchars($profilePicUrl, ENT_QUOTES, 'UTF-8'); ?>"
                        alt="Profile picture" id="profileAvatarImg" class="profile-avatar-image"
                        onerror="this.onerror=null; this.style.display='none'; var el=document.getElementById('profileAvatarInitial'); if(el){el.style.display='flex';}">
                    <span class="profile-avatar-placeholder" id="profileAvatarInitial" style="display: none;">
                        <?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <?php else: ?>
                    <span class="profile-avatar-placeholder" id="profileAvatarInitial" style="display: flex;">
                        <?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <img src="" alt="Profile picture" id="profileAvatarImg" class="profile-avatar-image"
                        style="display: none;">
                    <?php endif; ?>

                    <button type="button" class="profile-avatar-edit is-hidden" id="uploadPictureBtn"
                        aria-label="Change profile picture">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/edit.svg" alt="">
                    </button>

                    <input type="file" id="profilePictureInput" accept="image/jpeg,image/png,image/webp" hidden>
                </div>

                <div class="profile-name-role">
                    <p class="profile-full-name">
                        <?php echo htmlspecialchars($fullName ?: 'Customer', ENT_QUOTES, 'UTF-8'); ?></p>
                    <span class="profile-role-badge">Customer</span>
                    <span class="profile-balance">Balance: <?php echo formatCurrency($balance); ?></span>
                </div>
            </div>

            <div class="profile-header-right">

                <button type="button" id="editProfileBtn" class="btn btn-edit">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/edit.svg" alt="" class="btn-icon">
                    <span>Edit Profile</span>
                </button>

                <div class="profile-edit-actions is-hidden" id="profileEditActions">
                    <button type="button" id="cancelEditBtn" class="btn btn-cancel">
                        Cancel
                    </button>
                    <button type="button" id="saveProfileBtn" class="btn btn-primary">
                        Save Changes
                    </button>
                </div>

            </div>
        </div>

        <div class="profile-tabs">
            <button type="button" id="tabBtnPersonal" class="profile-tab active" data-tab="personal">
                Personal
            </button>
            <button type="button" id="tabBtnAddresses" class="profile-tab" data-tab="addresses">
                Addresses
            </button>
            <button type="button" id="tabBtnDietary" class="profile-tab" data-tab="dietary">
                Dietary
            </button>
            <button type="button" id="tabBtnAllergies" class="profile-tab" data-tab="allergies">
                Allergies
            </button>
            <button type="button" id="tabBtnFitness" class="profile-tab" data-tab="fitness">
                Fitness
            </button>
        </div>

        <!-- ============================================================
             TAB: PERSONAL INFORMATION
             ============================================================ -->
        <div class="profile-tab-content active" id="tab-personal">
            <div class="profile-card">
                <div class="card-header">
                    <h3>Personal Information</h3>
                </div>
                <div class="card-body">
                    <form id="profileForm" method="POST" action="../backend/handlers/profile-handler.php"
                        enctype="multipart/form-data" novalidate>
                        <input type="hidden" name="csrf_token"
                            value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="update_profile">
                        <input type="hidden" name="profile_submitted" value="1">

                        <div class="form-row">
                            <div class="form-group">
                                <label for="first_name" class="field-label">First Name</label>
                                <input type="text" id="first_name" name="first_name" class="form-control"
                                    value="<?php echo htmlspecialchars($profileData['first_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    maxlength="50" autocomplete="given-name" disabled>
                            </div>

                            <div class="form-group">
                                <label for="middle_name" class="field-label">Middle Name</label>
                                <input type="text" id="middle_name" name="middle_name" class="form-control"
                                    value="<?php echo htmlspecialchars($profileData['middle_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    maxlength="50" autocomplete="additional-name" disabled>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="last_name" class="field-label">Last Name</label>
                                <input type="text" id="last_name" name="last_name" class="form-control"
                                    value="<?php echo htmlspecialchars($profileData['last_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    maxlength="50" autocomplete="family-name" disabled>
                            </div>

                            <div class="form-group">
                                <label for="email" class="field-label">
                                    Email
                                    <span class="field-label-note">(cannot be changed)</span>
                                </label>
                                <input type="email" id="email" name="email" class="form-control"
                                    value="<?php echo htmlspecialchars($profileData['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    autocomplete="email" disabled>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="contact_number" class="field-label">Contact Number</label>
                                <input type="tel" id="contact_number" name="contact_number" class="form-control"
                                    value="<?php echo htmlspecialchars($profileData['contact_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="09XXXXXXXXX" maxlength="11" inputmode="numeric" autocomplete="tel"
                                    disabled>
                            </div>

                            <div class="form-group">
                                <label for="birthdate" class="field-label">Birthdate</label>
                                <input type="date" id="birthdate" name="birthdate" class="form-control"
                                    value="<?php echo htmlspecialchars($profileData['birthdate'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    autocomplete="bday" disabled>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="gender" class="field-label">Gender</label>
                                <select id="gender" name="gender" class="form-control" disabled>
                                    <option value="">Select Gender</option>
                                    <option value="Male"
                                        <?php echo ($profileData['gender'] ?? '') === 'Male' ? 'selected' : ''; ?>>Male
                                    </option>
                                    <option value="Female"
                                        <?php echo ($profileData['gender'] ?? '') === 'Female' ? 'selected' : ''; ?>>
                                        Female
                                    </option>
                                    <option value="Other"
                                        <?php echo ($profileData['gender'] ?? '') === 'Other' ? 'selected' : ''; ?>>
                                        Other
                                    </option>
                                </select>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ============================================================
             TAB: DELIVERY ADDRESSES
             ============================================================ -->
        <div class="profile-tab-content" id="tab-addresses">
            <div class="profile-card address-card">
                <div class="card-header">
                    <h3>Delivery Addresses</h3>
                    <button type="button" id="addAddressBtn" class="btn btn-primary btn-sm">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/add-circle-empty.svg" alt=""
                            class="btn-icon">
                        Add Address
                    </button>
                </div>
                <div class="card-body">
                    <?php if ($hasAddresses): ?>
                    <?php foreach ($addresses as $addr): ?>
                    <div class="address-item <?php echo $addr['is_default'] ? 'default' : ''; ?>">
                        <div class="address-item-header">
                            <div class="address-item-label">
                                <?php if ($addr['is_default']): ?>
                                <span class="badge badge-primary">Default</span>
                                <?php endif; ?>
                                <?php if (!empty($addr['label'])): ?>
                                <span class="address-label">
                                    <?php echo htmlspecialchars($addr['label'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <div class="address-item-actions">
                                <button type="button" class="btn btn-sm btn-edit edit-address"
                                    data-id="<?php echo (int)$addr['customer_address_id']; ?>">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/edit.svg" alt="Edit"
                                        class="btn-icon">
                                    <span>Edit</span>
                                </button>
                                <button type="button" class="btn btn-sm btn-delete delete-address"
                                    data-id="<?php echo (int)$addr['customer_address_id']; ?>"
                                    <?php echo $addr['is_default'] ? 'disabled' : ''; ?>>
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/trash.svg" alt="Delete"
                                        class="btn-icon">
                                    <span>Delete</span>
                                </button>
                            </div>
                        </div>
                        <div class="address-item-body">
                            <p><?php echo htmlspecialchars(formatAddress($addr), ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-content">
                            <div class="empty-state-icon">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/location-fill.svg"
                                    alt="No addresses">
                            </div>
                            <p class="heading-4">No Delivery Addresses</p>
                            <p class="text-muted">
                                Add a delivery address so we know where to send your orders.
                            </p>
                            <button type="button" class="btn btn-primary empty-state-action" id="addAddressEmptyBtn">
                                Add Address
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ============================================================
             TAB: DIETARY PREFERENCES
             ============================================================ -->
        <div class="profile-tab-content" id="tab-dietary">
            <div class="profile-card">
                <div class="card-header">
                    <h3>Dietary Preferences</h3>
                </div>
                <div class="card-body">

                    <div class="preference-summary" id="dietarySummary">
                        <?php if (!$hasDietary): ?>
                        <div class="empty-state empty-state-inline">
                            <div class="empty-state-content">
                                <div class="empty-state-icon">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/equalizer-line.svg"
                                        alt="No dietary preferences">
                                </div>
                                <p class="heading-4">No Dietary Preferences</p>
                                <p class="text-muted">
                                    Tell us how you eat and we'll prioritise matching dishes on the menu.
                                </p>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="preference-chips">
                            <?php foreach ($dietarySummaryLabels as $label): ?>
                            <span class="preference-chip">
                                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <form id="dietaryForm" method="POST" action="../backend/handlers/profile-handler.php" novalidate
                        class="preference-edit is-hidden">
                        <input type="hidden" name="csrf_token"
                            value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="update_dietary_profile">
                        <input type="hidden" name="dietary_submitted" value="1">

                        <p class="form-hint">
                            Select any dietary preferences you follow. The menu will prioritise matching
                            dishes when you browse. Leave all unchecked to clear your preferences.
                        </p>

                        <div class="options-grid" id="profileDietaryOptions">
                            <?php foreach ($dietaryOptions as $value => $label):
                                $isChecked = in_array($value, $currentDietary, true);
                            ?>
                            <label class="filter-check"
                                for="profile_diet_<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="checkbox"
                                    id="profile_diet_<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"
                                    name="dietary_preferences[]"
                                    value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"
                                    <?php echo $isChecked ? 'checked' : ''; ?>>
                                <span><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ============================================================
             TAB: ALLERGIES
             ============================================================ -->
        <div class="profile-tab-content" id="tab-allergies">
            <div class="profile-card">
                <div class="card-header">
                    <h3>Allergies</h3>
                </div>
                <div class="card-body">

                    <div class="preference-summary" id="allergySummary">
                        <?php if (!$hasAllergies): ?>
                        <div class="empty-state empty-state-inline">
                            <div class="empty-state-content">
                                <div class="empty-state-icon">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/list-settings-fill.svg"
                                        alt="No allergies">
                                </div>
                                <p class="heading-4">No Allergies</p>
                                <p class="text-muted">
                                    You haven't listed any allergies. Add them here and we'll filter
                                    matching dishes out of the menu.
                                </p>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="preference-chips">
                            <?php foreach ($allergySummaryLabels as $label): ?>
                            <span class="preference-chip preference-chip-danger">
                                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <form id="allergyForm" method="POST" action="../backend/handlers/profile-handler.php" novalidate
                        class="preference-edit is-hidden">
                        <input type="hidden" name="csrf_token"
                            value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="update_dietary_profile">
                        <input type="hidden" name="allergy_submitted" value="1">

                        <p class="form-hint">
                            Select any allergies you have. Dishes that contain an excluded allergen
                            will be filtered out of the menu. Leave all unchecked to clear your
                            allergy list.
                        </p>

                        <div class="options-grid" id="profileAllergyOptions">
                            <?php foreach ($allergyOptions as $value => $label):
                                $isChecked = in_array($value, $currentAllergies, true);
                            ?>
                            <label class="filter-check filter-check-danger"
                                for="profile_allergy_<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="checkbox"
                                    id="profile_allergy_<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"
                                    name="allergies[]"
                                    value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"
                                    <?php echo $isChecked ? 'checked' : ''; ?>>
                                <span><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ============================================================
             TAB: FITNESS GOALS
             ============================================================ -->
        <div class="profile-tab-content" id="tab-fitness">
            <div class="profile-card">
                <div class="card-header">
                    <h3>Fitness Goals</h3>
                </div>
                <div class="card-body">
                    <form id="fitnessForm" method="POST" action="../backend/handlers/profile-handler.php" novalidate>
                        <input type="hidden" name="csrf_token"
                            value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="update_fitness_profile">
                        <input type="hidden" name="fitness_submitted" value="1">

                        <p class="form-hint">
                            Tell us about your fitness goals. This information helps personalise the
                            meals we recommend.
                        </p>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="height" class="field-label">Height (cm)</label>
                                <input type="number" id="height" name="height" class="form-control"
                                    value="<?php echo htmlspecialchars((string)$currentHeight, ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="e.g. 175" min="50" max="300" step="0.1" inputmode="decimal" disabled>
                            </div>

                            <div class="form-group">
                                <label for="weight" class="field-label">Weight (kg)</label>
                                <input type="number" id="weight" name="weight" class="form-control"
                                    value="<?php echo htmlspecialchars((string)$currentWeight, ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="e.g. 70" min="10" max="500" step="0.1" inputmode="decimal" disabled>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group form-group-full">
                                <label for="fitness_goal" class="field-label">Fitness Goal</label>
                                <select id="fitness_goal" name="fitness_goal" class="form-control" disabled>
                                    <option value="">Select your goal</option>
                                    <?php foreach ($fitnessGoalOptions as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"
                                        <?php echo $currentFitnessGoal === $value ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="confirmEditModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="confirmEditModalTitle">
    <div class="modal-overlay" data-modal-dismiss></div>
    <div class="modal-content confirm-modal-content">
        <div class="modal-icon confirm-modal-icon">
            <img src="<?php echo $assetBase; ?>assets/images/icons/edit.svg" alt="">
        </div>
        <h3 id="confirmEditModalTitle">Edit Profile?</h3>
        <p class="text-muted">
            You are about to edit your profile. Changes you make are only saved once you press
            <strong>Save Changes</strong>.
        </p>
        <div class="modal-footer">
            <button type="button" class="btn btn-cancel" data-modal-dismiss>Not Now</button>
            <button type="button" class="btn btn-primary" id="confirmEditProceed">Continue</button>
        </div>
    </div>
</div>

<div id="unsavedChangesModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="unsavedChangesTitle">
    <div class="modal-overlay" data-modal-dismiss></div>
    <div class="modal-content confirm-modal-content">
        <div class="modal-icon confirm-modal-icon">
            <img src="<?php echo $assetBase; ?>assets/images/icons/error-warning-line.svg" alt="">
        </div>
        <h3 id="unsavedChangesTitle">Unsaved Changes</h3>
        <p class="text-muted">
            You have unsaved changes on your profile. What would you like to do?
        </p>
        <div class="modal-footer">
            <button type="button" class="btn btn-cancel" id="unsavedStayBtn">Keep Editing</button>
            <button type="button" class="btn btn-primary" id="unsavedSaveBtn">Save Changes</button>
        </div>
    </div>
</div>

<div id="addressModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="addressModalTitle">Add New Address</h3>
            <button type="button" class="modal-close" id="closeAddressModal">&times;</button>
        </div>

        <div class="modal-progress">
            <div class="progress-step active" data-step="1">
                <span class="step-number">1</span>
                <span class="step-label">Location</span>
            </div>
            <div class="progress-line"></div>
            <div class="progress-step" data-step="2">
                <span class="step-number">2</span>
                <span class="step-label">Details</span>
            </div>
            <div class="progress-line"></div>
            <div class="progress-step" data-step="3">
                <span class="step-number">3</span>
                <span class="step-label">Confirm</span>
            </div>
        </div>

        <form id="addressForm" method="POST" action="../backend/handlers/address-handler.php">
            <input type="hidden" name="csrf_token"
                value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="add_address">
            <input type="hidden" name="address_id" id="addressId" value="">

            <div class="modal-step" id="modalStep1">
                <div class="form-group">
                    <label for="address_label" class="field-label">Label (Optional)</label>
                    <select id="address_label" name="label" class="form-control">
                        <option value="">Select a label</option>
                        <option value="Home">Home</option>
                        <option value="Office">Office</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="block" class="field-label">Block / Street / House Number</label>
                    <input type="text" id="block" name="block" class="form-control" required
                        placeholder="Block 5, Lot 12, Unit 101">
                </div>
                <div class="form-group">
                    <label for="barangay" class="field-label">Barangay</label>
                    <input type="text" id="barangay" name="barangay" class="form-control" placeholder="San Miguel">
                </div>

                <div class="step-actions">
                    <button type="button" class="btn btn-primary btn-next-step" data-next="2">Next Step</button>
                </div>
            </div>

            <div class="modal-step" id="modalStep2">
                <div class="form-group">
                    <label for="city" class="field-label">City</label>
                    <input type="text" id="city" name="city" class="form-control" required placeholder="Pasig">
                </div>
                <div class="form-group">
                    <label for="province" class="field-label">Province</label>
                    <input type="text" id="province" name="province" class="form-control" placeholder="Metro Manila">
                </div>
                <div class="form-group">
                    <label for="region" class="field-label">Region</label>
                    <input type="text" id="region" name="region" class="form-control" placeholder="NCR">
                </div>

                <div class="step-actions">
                    <button type="button" class="btn btn-cancel btn-prev-step" data-prev="1">Back</button>
                    <button type="button" class="btn btn-primary btn-next-step" data-next="3">Next Step</button>
                </div>
            </div>

            <div class="modal-step" id="modalStep3">
                <div class="form-group">
                    <label for="postal_code" class="field-label">Postal Code</label>
                    <input type="text" id="postal_code" name="postal_code" class="form-control" placeholder="1234"
                        maxlength="10">
                </div>
                <div class="form-group">
                    <label for="country" class="field-label">Country</label>
                    <select id="country" name="country" class="form-control">
                        <option value="Philippines" selected>Philippines</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="address-summary">
                    <p class="summary-label">Address Preview:</p>
                    <p class="summary-text" id="addressPreviewText">Fill in all fields to see preview</p>
                </div>

                <div class="step-actions">
                    <button type="button" class="btn btn-cancel btn-prev-step" data-prev="2">Back</button>
                    <button type="submit" class="btn btn-primary" id="saveAddressBtn">Save Address</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div id="deleteAddressModal" class="modal">
    <div class="modal-content">
        <div class="modal-icon">
            <img src="<?php echo $assetBase; ?>assets/images/icons/trash.svg" alt="Warning">
        </div>
        <h3>Delete Address</h3>
        <p class="text-muted">Are you sure you want to delete this address? This action cannot be undone.</p>
        <div class="modal-footer">
            <button type="button" class="btn btn-cancel" id="cancelDeleteModal">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirmDeleteModal">Delete</button>
        </div>
    </div>
</div>

<script>
window.FITPAL_CSRF_TOKEN = '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>';

window.FITPAL_CUSTOMER_PROFILE = {
    csrfToken: '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>',
    assetBase: '<?php echo $assetBase; ?>',
    hasPicture: <?php echo $profilePicUrl !== '' ? 'true' : 'false'; ?>,
    initialAvatarSrc: '<?php echo htmlspecialchars($profilePicUrl, ENT_QUOTES, 'UTF-8'); ?>',
    updateEndpoint: '../backend/handlers/profile-handler.php',
    uploadEndpoint: '../backend/handlers/profile-handler.php'
};
</script>
<script src="../assets/ui/js/profile.js" defer></script>
<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>