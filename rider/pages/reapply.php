<?php
/**
 * FitPal Rider Re-Application Page
 *
 * (unchanged docblock head)
 *
 * @package FitPal
 * @version 2.2 — Step 1's password row gains a third field: the
 *                rider's Current Password. The server now requires
 *                it whenever a new password is posted, so the page
 *                collects it in the same row as the new password
 *                and its confirmation. The "New Password" and
 *                "Confirm New Password" labels drop their
 *                "(Optional)" note, because leaving all three blank
 *                is what keeps the current password; filling any
 *                of them requires all three.
 *
 *                (2.1: shorter labels, longer hint. 2.0: five-step
 *                wizard. 1.1: emits its own <link> to
 *                reapply.css. 1.0: initial re-application page.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('rider');

if (empty($_SESSION['delivery_rider_id'])) {
    header('Location: sign-in.php');
    exit;
}

require_once __DIR__ . '/../backend/database/rider-connect.php';
require_once __DIR__ . '/../backend/database/rider-assignment-queries.php';

$riderId = (int)$_SESSION['delivery_rider_id'];

$profile = getRiderProfile($database_connection, $riderId);

if (!$profile) {
    $_SESSION['rider_error'] = 'Your account could not be found. Please sign in again.';
    header('Location: dashboard.php');
    exit;
}

$status = (string)($profile['verification_status'] ?? 'pending');

if ($status !== 'denied') {
    $_SESSION['rider_error'] = 'Your account is not eligible for re-application right now.';
    header('Location: dashboard.php');
    exit;
}

$address   = getRiderDefaultAddress($database_connection, $riderId);
$emergency = getRiderEmergencyContact($database_connection, $riderId);
$document  = getRiderLatestDocument($database_connection, $riderId);

$firstName     = (string)($profile['first_name']     ?? '');
$middleName    = (string)($profile['middle_name']    ?? '');
$lastName      = (string)($profile['last_name']      ?? '');
$email         = (string)($profile['email']          ?? '');
$contact       = (string)($profile['contact_number'] ?? '');
$username      = (string)($profile['username']       ?? '');
$vehicleType   = (string)($profile['vehicle_type']   ?? '');
$vehiclePlate  = (string)($profile['vehicle_plate']  ?? '');
$birthdate     = (string)($profile['birthdate']      ?? '');
$gender        = (string)($profile['gender']         ?? '');

$addressBlock    = (string)($address['block']       ?? '');
$addressBarangay = (string)($address['barangay']    ?? '');
$addressCity     = (string)($address['city']        ?? '');
$addressProvince = (string)($address['province']    ?? '');
$addressRegion   = (string)($address['region']      ?? '');
$addressPostal   = (string)($address['postal_code'] ?? '');

$ecFirstName    = (string)($emergency['first_name']     ?? '');
$ecMiddleName   = (string)($emergency['middle_name']    ?? '');
$ecLastName     = (string)($emergency['last_name']      ?? '');
$ecContact      = (string)($emergency['contact_number'] ?? '');
$ecRelationship = (string)($emergency['relationship']   ?? '');

$vehicleOptions = [
    'motorcycle' => 'Motorcycle',
    'scooter'    => 'Scooter',
    'car'        => 'Car',
    'van'        => 'Van',
    'bicycle'    => 'Bicycle',
];

$relationshipOptions = [
    'Parent'   => 'Parent',
    'Spouse'   => 'Spouse',
    'Sibling'  => 'Sibling',
    'Relative' => 'Relative',
    'Friend'   => 'Friend',
    'Other'    => 'Other',
];

$isBicycle = ($vehicleType === 'bicycle');

require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="../assets/css/reapply.css">

<div class="content register-page"
    data-vehicle-type="<?php echo htmlspecialchars($vehicleType, ENT_QUOTES, 'UTF-8'); ?>">
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
                    <span class="step-label">Vehicle</span>
                </div>
                <div class="progress-line" id="progressLine2"></div>
                <div class="progress-step" data-step="3">
                    <span class="step-number">3</span>
                    <span class="step-label">Address</span>
                </div>
                <div class="progress-line" id="progressLine3"></div>
                <div class="progress-step" data-step="4">
                    <span class="step-number">4</span>
                    <span class="step-label">Uploads</span>
                </div>
                <div class="progress-line" id="progressLine4"></div>
                <div class="progress-step" data-step="5">
                    <span class="step-number">5</span>
                    <span class="step-label">Verify</span>
                </div>
            </div>

            <div class="register-header">
                <p class="heading-2">Update Your <span>Application</span></p>
                <p class="text-muted" id="stepSubtitle">Step 1 of 5 — Personal Information</p>
            </div>

            <div id="registerError" class="alert alert-danger" style="display: none;" role="alert">
                <span id="errorMessage"></span>
            </div>

            <form method="POST" action="../backend/handlers/sign-up-handler.php" class="register-form" id="registerForm"
                enctype="multipart/form-data" novalidate>

                <input type="hidden" name="csrf_token"
                    value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="mode" value="reapply">
                <input type="hidden" name="current_step" id="currentStep" value="1">

                <div class="register-step" id="step1">

                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name" class="form-label">
                                First Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="first_name" name="first_name" class="form-control"
                                value="<?php echo htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'); ?>"
                                autocomplete="given-name" required>
                            <div class="form-error" id="firstNameError"></div>
                        </div>

                        <div class="form-group">
                            <label for="middle_name" class="form-label">
                                Middle Name <span class="text-muted">(Optional)</span>
                            </label>
                            <input type="text" id="middle_name" name="middle_name" class="form-control"
                                value="<?php echo htmlspecialchars($middleName, ENT_QUOTES, 'UTF-8'); ?>"
                                autocomplete="additional-name">
                        </div>

                        <div class="form-group">
                            <label for="last_name" class="form-label">
                                Last Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="last_name" name="last_name" class="form-control"
                                value="<?php echo htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8'); ?>"
                                autocomplete="family-name" required>
                            <div class="form-error" id="lastNameError"></div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="birthdate" class="form-label">
                                Birthdate <span class="text-danger">*</span>
                            </label>
                            <input type="date" id="birthdate" name="birthdate" class="form-control"
                                value="<?php echo htmlspecialchars($birthdate, ENT_QUOTES, 'UTF-8'); ?>"
                                max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>"
                                min="<?php echo date('Y-m-d', strtotime('-70 years')); ?>" required>
                            <div class="form-error" id="birthdateError"></div>
                            <div class="form-hint">Applicants must be 18–70 years old</div>
                        </div>

                        <div class="form-group">
                            <label for="gender" class="form-label">
                                Gender <span class="text-danger">*</span>
                            </label>
                            <select id="gender" name="gender" class="form-control" required>
                                <option value="">Select your gender</option>
                                <option value="Male" <?php echo $gender === 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo $gender === 'Female' ? 'selected' : ''; ?>>Female
                                </option>
                                <option value="Other" <?php echo $gender === 'Other' ? 'selected' : ''; ?>>Other
                                </option>
                            </select>
                            <div class="form-error" id="genderError"></div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="email" class="form-label">
                                Email Address <span class="text-danger">*</span>
                            </label>
                            <input type="email" id="email" name="email" class="form-control"
                                value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>"
                                autocomplete="email" required>
                            <div class="form-error" id="emailError"></div>
                        </div>

                        <div class="form-group">
                            <label for="contact_number" class="form-label">
                                Contact Number <span class="text-danger">*</span>
                            </label>
                            <input type="tel" id="contact_number" name="contact_number" class="form-control"
                                value="<?php echo htmlspecialchars($contact, ENT_QUOTES, 'UTF-8'); ?>"
                                placeholder="09XX XXX XXXX" autocomplete="tel" inputmode="numeric" maxlength="13"
                                required>
                            <div class="form-error" id="contactError"></div>
                            <div class="form-hint">11 digits, starting with 09</div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="username" class="form-label">
                                Username <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="username" name="username" class="form-control"
                                value="<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>"
                                autocomplete="username" maxlength="20" required>
                            <div class="form-error" id="usernameError"></div>
                            <div class="form-hint">3–20 characters; letters, numbers, underscore</div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="current_password" class="form-label">
                                Current Password
                            </label>
                            <div class="password-wrapper">
                                <input type="password" id="current_password" name="current_password"
                                    class="form-control" placeholder="Enter your current password"
                                    autocomplete="current-password" maxlength="20">
                                <button type="button" class="password-toggle" id="toggleCurrentPassword" tabindex="-1"
                                    aria-label="Toggle current password visibility">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/password-hide.svg"
                                        alt="Hide password" id="currentPasswordIcon">
                                </button>
                            </div>
                            <div class="form-error" id="currentPasswordError"></div>
                            <div class="form-hint">
                                Required only if you change your password below.
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="password" class="form-label">
                                New Password
                            </label>
                            <div class="password-wrapper">
                                <input type="password" id="password" name="password" class="form-control"
                                    placeholder="Enter a new password" autocomplete="new-password" maxlength="20">
                                <button type="button" class="password-toggle" id="togglePassword" tabindex="-1"
                                    aria-label="Toggle password visibility">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/password-hide.svg"
                                        alt="Hide password" id="passwordIcon">
                                </button>
                            </div>
                            <div class="form-error" id="passwordError"></div>
                            <div class="form-hint">
                                Leave all three password fields blank to keep your current password.
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password" class="form-label">
                                Confirm New Password
                            </label>
                            <div class="password-wrapper">
                                <input type="password" id="confirm_password" name="confirm_password"
                                    class="form-control" placeholder="Confirm the new password"
                                    autocomplete="new-password" maxlength="20">
                                <button type="button" class="password-toggle" id="toggleConfirmPassword" tabindex="-1"
                                    aria-label="Toggle confirm password visibility">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/password-hide.svg"
                                        alt="Hide password" id="confirmPasswordIcon">
                                </button>
                            </div>
                            <div class="form-error" id="confirmError"></div>
                        </div>
                    </div>

                    <div class="step-actions">
                        <button type="button" class="btn btn-primary btn-next" data-next="2">
                            Next Step
                        </button>
                    </div>
                </div>

                <div class="register-step" id="step2" style="display: none;">

                    <div class="step-description">
                        <p>Update your vehicle details if they have changed.</p>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="vehicle_type" class="form-label">
                                Vehicle Type <span class="text-danger">*</span>
                            </label>
                            <select id="vehicle_type" name="vehicle_type" class="form-control" required>
                                <option value="">Select vehicle type</option>
                                <?php foreach ($vehicleOptions as $value => $label): ?>
                                <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"
                                    <?php echo $vehicleType === $value ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-error" id="vehicleTypeError"></div>
                        </div>
                    </div>

                    <div id="vehicleMotorOnlyFields">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="vehicle_plate" class="form-label">
                                    Plate Number
                                </label>
                                <input type="text" id="vehicle_plate" name="vehicle_plate" class="form-control"
                                    value="<?php echo htmlspecialchars($vehiclePlate, ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="e.g. ABC 1234" autocomplete="off" maxlength="10">
                                <div class="form-error" id="vehiclePlateError"></div>
                                <div class="form-hint">Required for motor vehicles</div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="vehicle_make" class="form-label">
                                    Make / Brand <span class="text-muted">(Optional)</span>
                                </label>
                                <input type="text" id="vehicle_make" name="vehicle_make" class="form-control"
                                    placeholder="e.g. Honda" autocomplete="off" maxlength="40">
                            </div>

                            <div class="form-group">
                                <label for="vehicle_model" class="form-label">
                                    Model <span class="text-muted">(Optional)</span>
                                </label>
                                <input type="text" id="vehicle_model" name="vehicle_model" class="form-control"
                                    placeholder="e.g. Click 125i" autocomplete="off" maxlength="40">
                            </div>

                            <div class="form-group">
                                <label for="vehicle_year" class="form-label">
                                    Year <span class="text-muted">(Optional)</span>
                                </label>
                                <input type="number" id="vehicle_year" name="vehicle_year" class="form-control"
                                    placeholder="e.g. 2022" min="1980" max="<?php echo (int)date('Y') + 1; ?>"
                                    autocomplete="off" inputmode="numeric">
                                <div class="form-error" id="vehicleYearError"></div>
                            </div>
                        </div>
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

                <div class="register-step" id="step3" style="display: none;">

                    <div class="step-description">
                        <p>Update your address and emergency contact if they have changed.</p>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="block" class="form-label">
                                Block / Street / Unit <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="block" name="block" class="form-control"
                                value="<?php echo htmlspecialchars($addressBlock, ENT_QUOTES, 'UTF-8'); ?>"
                                placeholder="e.g. 12-A Sunrise St., Unit 5B" autocomplete="address-line1" required>
                            <div class="form-error" id="blockError"></div>
                        </div>

                        <div class="form-group">
                            <label for="barangay" class="form-label">
                                Barangay <span class="text-muted">(Optional)</span>
                            </label>
                            <input type="text" id="barangay" name="barangay" class="form-control"
                                value="<?php echo htmlspecialchars($addressBarangay, ENT_QUOTES, 'UTF-8'); ?>"
                                placeholder="e.g. Barangay San Antonio" autocomplete="address-line2">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="city" class="form-label">
                                City / Municipality <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="city" name="city" class="form-control"
                                value="<?php echo htmlspecialchars($addressCity, ENT_QUOTES, 'UTF-8'); ?>"
                                placeholder="e.g. Pasig" autocomplete="address-level2" required>
                            <div class="form-error" id="cityError"></div>
                        </div>

                        <div class="form-group">
                            <label for="province" class="form-label">
                                Province <span class="text-muted">(Optional)</span>
                            </label>
                            <input type="text" id="province" name="province" class="form-control"
                                value="<?php echo htmlspecialchars($addressProvince, ENT_QUOTES, 'UTF-8'); ?>"
                                placeholder="e.g. Metro Manila" autocomplete="address-level1">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="region" class="form-label">
                                Region <span class="text-muted">(Optional)</span>
                            </label>
                            <input type="text" id="region" name="region" class="form-control"
                                value="<?php echo htmlspecialchars($addressRegion, ENT_QUOTES, 'UTF-8'); ?>"
                                placeholder="e.g. NCR" autocomplete="address-level1">
                        </div>

                        <div class="form-group">
                            <label for="postal_code" class="form-label">
                                Postal Code <span class="text-muted">(Optional)</span>
                            </label>
                            <input type="text" id="postal_code" name="postal_code" class="form-control"
                                value="<?php echo htmlspecialchars($addressPostal, ENT_QUOTES, 'UTF-8'); ?>"
                                placeholder="e.g. 1605" autocomplete="postal-code" maxlength="10" inputmode="numeric">
                            <div class="form-error" id="postalError"></div>
                        </div>
                    </div>

                    <div class="section-divider">
                        <span>Emergency Contact</span>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="emergency_first_name" class="form-label">
                                First Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="emergency_first_name" name="emergency_first_name"
                                class="form-control"
                                value="<?php echo htmlspecialchars($ecFirstName, ENT_QUOTES, 'UTF-8'); ?>" required>
                            <div class="form-error" id="emergencyFirstNameError"></div>
                        </div>

                        <div class="form-group">
                            <label for="emergency_middle_name" class="form-label">
                                Middle Name <span class="text-muted">(Optional)</span>
                            </label>
                            <input type="text" id="emergency_middle_name" name="emergency_middle_name"
                                class="form-control"
                                value="<?php echo htmlspecialchars($ecMiddleName, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="emergency_last_name" class="form-label">
                                Last Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="emergency_last_name" name="emergency_last_name" class="form-control"
                                value="<?php echo htmlspecialchars($ecLastName, ENT_QUOTES, 'UTF-8'); ?>" required>
                            <div class="form-error" id="emergencyLastNameError"></div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="emergency_relationship" class="form-label">
                                Relationship <span class="text-danger">*</span>
                            </label>
                            <select id="emergency_relationship" name="emergency_relationship" class="form-control"
                                required>
                                <option value="">Select relationship</option>
                                <?php foreach ($relationshipOptions as $value => $label): ?>
                                <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"
                                    <?php echo $ecRelationship === $value ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-error" id="emergencyRelationshipError"></div>
                        </div>

                        <div class="form-group">
                            <label for="emergency_contact" class="form-label">
                                Mobile Number <span class="text-danger">*</span>
                            </label>
                            <input type="tel" id="emergency_contact" name="emergency_contact" class="form-control"
                                value="<?php echo htmlspecialchars($ecContact, ENT_QUOTES, 'UTF-8'); ?>"
                                placeholder="09XX XXX XXXX" inputmode="numeric" maxlength="13" required>
                            <div class="form-error" id="emergencyContactError"></div>
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

                <div class="register-step" id="step4" style="display: none;">

                    <div class="step-description">
                        <p>
                            Both files must be re-uploaded, even if the current ones are still
                            accurate.
                        </p>
                    </div>

                    <div class="upload-block-grid">

                        <div class="upload-block" id="uploadBlockPhoto">
                            <div class="upload-block-header">
                                <div class="upload-block-number" aria-hidden="true">1</div>
                                <div class="upload-block-heading">
                                    <h3 class="upload-block-title">
                                        Formal Photo <span class="text-danger">*</span>
                                    </h3>
                                    <p class="upload-block-subtitle">
                                        Clear, front-facing photo of your face.
                                    </p>
                                </div>
                            </div>

                            <div class="upload-block-body">
                                <div class="upload-dropzone upload-avatar" id="profileDropzone" tabindex="0"
                                    role="button" aria-label="Upload formal photo">
                                    <input type="file" id="profile_picture" name="profile_picture"
                                        accept="image/jpeg,image/png,image/webp" hidden>

                                    <div class="upload-preview" id="profilePreview" hidden>
                                        <img src="" alt="Formal photo preview" id="profilePreviewImg">
                                        <button type="button" class="upload-remove" id="profileRemove"
                                            aria-label="Remove photo">&times;</button>
                                    </div>

                                    <div class="upload-hint" id="profileHint">
                                        <div class="upload-hint-icon" aria-hidden="true">
                                            <img src="<?php echo $assetBase; ?>assets/images/icons/image-upload-fill.svg"
                                                alt=""
                                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/image-line.svg'">
                                        </div>
                                        <p class="upload-hint-title">Upload photo</p>
                                        <p class="upload-hint-text">JPG, PNG, WEBP &middot; Max 2&nbsp;MB</p>
                                    </div>
                                </div>

                                <ul class="upload-guidelines">
                                    <li>Front-facing, well-lit, no filters</li>
                                    <li>No hats, sunglasses, or face coverings</li>
                                    <li>Neutral background recommended</li>
                                </ul>

                                <div class="form-error" id="profilePictureError"></div>
                            </div>
                        </div>

                        <div class="upload-block" id="uploadBlockLicense">
                            <div class="upload-block-header">
                                <div class="upload-block-number" aria-hidden="true">2</div>
                                <div class="upload-block-heading">
                                    <div class="upload-block-heading-drivers-license">
                                        <h3 class="upload-block-title">
                                            Driver's License <span class="text-danger">*</span>
                                        </h3>
                                        <p class="upload-block-subtitle">
                                            Photo of your valid driver's license.
                                        </p>
                                    </div>
                                    <div class="upload-block-heading-government-id">
                                        <h3 class="upload-block-title">
                                            Valid Government ID <span class="text-danger">*</span>
                                        </h3>
                                        <p class="upload-block-subtitle">
                                            Photo of a valid government-issued ID.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="upload-block-body">
                                <div class="upload-dropzone upload-avatar" id="licenseDropzone" tabindex="0"
                                    role="button" aria-label="Upload identity document">
                                    <input type="file" id="drivers_license" name="drivers_license"
                                        accept="image/jpeg,image/png,image/webp" hidden>

                                    <div class="upload-preview" id="licensePreview" hidden>
                                        <img src="" alt="ID preview" id="licensePreviewImg">
                                        <button type="button" class="upload-remove" id="licenseRemove"
                                            aria-label="Remove ID photo">&times;</button>
                                    </div>

                                    <div class="upload-hint" id="licenseHint">
                                        <div class="upload-hint-icon" aria-hidden="true">
                                            <img src="<?php echo $assetBase; ?>assets/images/icons/id-card-line.svg"
                                                alt=""
                                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-user-line.svg'">
                                        </div>
                                        <p class="upload-hint-title">Upload ID</p>
                                        <p class="upload-hint-text">JPG, PNG, WEBP &middot; Max 5&nbsp;MB</p>
                                    </div>
                                </div>

                                <ul class="upload-guidelines upload-guidelines-drivers-license">
                                    <li>All four corners of the card visible</li>
                                    <li>Name, number, expiry must be readable</li>
                                    <li>No glare, blur, or partial crops</li>
                                </ul>

                                <ul class="upload-guidelines upload-guidelines-government-id">
                                    <li>All four corners of the card visible</li>
                                    <li>Name, ID number must be readable</li>
                                    <li>No glare, blur, or partial crops</li>
                                </ul>

                                <div class="form-error" id="driversLicenseError"></div>
                            </div>
                        </div>
                    </div>

                    <div class="form-row upload-block-dates" id="licenseDatesRow">
                        <div class="form-group">
                            <label for="license_issue_date" class="form-label">
                                License Issue Date <span class="text-danger">*</span>
                            </label>
                            <input type="date" id="license_issue_date" name="license_issue_date" class="form-control"
                                max="<?php echo date('Y-m-d'); ?>" required>
                            <div class="form-error" id="licenseIssueError"></div>
                        </div>

                        <div class="form-group">
                            <label for="license_expiry_date" class="form-label">
                                License Expiry Date <span class="text-danger">*</span>
                            </label>
                            <input type="date" id="license_expiry_date" name="license_expiry_date" class="form-control"
                                min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required>
                            <div class="form-error" id="licenseExpiryError"></div>
                            <div class="form-hint">Must be a future date</div>
                        </div>
                    </div>

                    <div class="step-actions">
                        <button type="button" class="btn btn-outline btn-prev" data-prev="3">
                            Back
                        </button>
                        <button type="button" class="btn btn-primary btn-next" data-next="5">
                            Next Step
                        </button>
                    </div>
                </div>

                <div class="register-step" id="step5" style="display: none;">

                    <div class="step-description">
                        <p>
                            One last check. Review everything, re-accept the terms, and submit your
                            updated application.
                        </p>
                    </div>

                    <div class="form-group terms-group" id="termsGroup">
                        <div class="checkbox-wrapper">
                            <input type="checkbox" id="terms" name="terms" value="1" required>
                            <span class="custom-checkbox" aria-hidden="true"></span>
                            <label for="terms" class="terms-label">
                                I confirm the information above is accurate and I agree to the
                                <a href="<?php echo $assetBase; ?>pages/terms-conditions.php" target="_blank"
                                    rel="noopener noreferrer">Terms and Conditions</a>
                                and
                                <a href="<?php echo $assetBase; ?>pages/privacy-policy.php" target="_blank"
                                    rel="noopener noreferrer">Privacy Policy</a>.
                            </label>
                        </div>
                        <div class="form-error" id="termsError"></div>
                    </div>

                    <div class="step-actions">
                        <button type="button" class="btn btn-outline btn-prev" data-prev="4">
                            Back
                        </button>
                        <button type="submit" class="btn btn-primary" id="registerBtn">
                            Submit Updated Application
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

<script src="../assets/ui/js/reapply.js" defer></script>

<?php
require_once __DIR__ . '/../../shared/includes/footer.php';
?>