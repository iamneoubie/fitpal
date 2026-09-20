<?php
/**
 * FitPal Rider Registration Page
 *
 * Four-step rider application:
 *   Step 1 — Personal Information
 *   Step 2 — Vehicle Details
 *   Step 3 — Address & Emergency Contact
 *   Step 4 — Verification & Review (profile picture, license, terms)
 *
 * Visual layout mirrors the customer register card so both flows
 * feel like the same product:
 *   - max-width: 700px on .register-card
 *   - align-items: center on .register-page
 *   - 32px / 24px card padding
 *
 * @package FitPal
 * @version 3.2 — Card sizing matched to the customer register card.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['delivery_rider_id'])) {
    header('Location: dashboard.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/../includes/header.php';

$errorMessage = $_SESSION['rider_registration_error'] ?? '';
unset($_SESSION['rider_registration_error']);

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
?>

<div class="content register-page">
    <div class="container">
        <div class="register-card">

            <!-- Progress -->
            <div class="register-progress" role="progressbar" aria-valuenow="1" aria-valuemin="1" aria-valuemax="4"
                aria-label="Registration progress">
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
                    <span class="step-label">Verify</span>
                </div>
            </div>

            <div class="register-header">
                <p class="heading-2">Become a <span>FitPal Rider</span></p>
                <p class="text-muted" id="stepSubtitle">Step 1 of 4 — Personal Information</p>
            </div>

            <?php if (!empty($errorMessage)): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php endif; ?>

            <div id="registerError" class="alert alert-danger" style="display: none;" role="alert">
                <span id="errorMessage"></span>
            </div>

            <form method="POST" action="../backend/handlers/sign-up-handler.php" class="register-form" id="registerForm"
                enctype="multipart/form-data" novalidate autocomplete="on">

                <input type="hidden" name="csrf_token"
                    value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="current_step" id="currentStep" value="1">

                <!-- STEP 1 — Personal Information -->
                <div class="register-step" id="step1">

                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name" class="form-label">
                                First Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="first_name" name="first_name" class="form-control"
                                placeholder="Enter your first name" autocomplete="given-name" required>
                            <div class="form-error" id="firstNameError"></div>
                        </div>

                        <div class="form-group">
                            <label for="middle_name" class="form-label">
                                Middle Name <span class="text-muted">(Optional)</span>
                            </label>
                            <input type="text" id="middle_name" name="middle_name" class="form-control"
                                placeholder="Enter your middle name" autocomplete="additional-name">
                        </div>

                        <div class="form-group">
                            <label for="last_name" class="form-label">
                                Last Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="last_name" name="last_name" class="form-control"
                                placeholder="Enter your last name" autocomplete="family-name" required>
                            <div class="form-error" id="lastNameError"></div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="birthdate" class="form-label">
                                Birthdate <span class="text-danger">*</span>
                            </label>
                            <input type="date" id="birthdate" name="birthdate" class="form-control" required
                                max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>"
                                min="<?php echo date('Y-m-d', strtotime('-70 years')); ?>">
                            <div class="form-error" id="birthdateError"></div>
                            <div class="form-hint">Applicants must be 18–70 years old</div>
                        </div>

                        <div class="form-group">
                            <label for="gender" class="form-label">
                                Gender <span class="text-danger">*</span>
                            </label>
                            <select id="gender" name="gender" class="form-control" required>
                                <option value="">Select your gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
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
                                placeholder="Enter your email address" autocomplete="email" required>
                            <div class="form-error" id="emailError"></div>
                        </div>

                        <div class="form-group">
                            <label for="contact_number" class="form-label">
                                Contact Number <span class="text-danger">*</span>
                            </label>
                            <input type="tel" id="contact_number" name="contact_number" class="form-control"
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
                                placeholder="Choose a username" autocomplete="username" maxlength="20" required>
                            <div class="form-error" id="usernameError"></div>
                            <div class="form-hint">3–20 characters; letters, numbers, underscore</div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="password" class="form-label">
                                Password <span class="text-danger">*</span>
                            </label>
                            <div class="password-wrapper">
                                <input type="password" id="password" name="password" class="form-control"
                                    placeholder="Create a password" autocomplete="new-password" maxlength="20" required>
                                <button type="button" class="password-toggle" id="togglePassword" tabindex="-1"
                                    aria-label="Toggle password visibility">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/password-hide.svg"
                                        alt="Hide password" id="passwordIcon">
                                </button>
                            </div>
                            <div class="form-error" id="passwordError"></div>
                            <div class="form-hint">8-20 characters (letters and numbers only)</div>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password" class="form-label">
                                Confirm Password <span class="text-danger">*</span>
                            </label>
                            <div class="password-wrapper">
                                <input type="password" id="confirm_password" name="confirm_password"
                                    class="form-control" placeholder="Confirm your password" autocomplete="new-password"
                                    maxlength="20" required>
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

                <!-- STEP 2 — Vehicle Details -->
                <div class="register-step" id="step2" style="display: none;">

                    <div class="step-description">
                        <p>Tell us about the vehicle you'll use for deliveries.</p>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="vehicle_type" class="form-label">
                                Vehicle Type <span class="text-danger">*</span>
                            </label>
                            <select id="vehicle_type" name="vehicle_type" class="form-control" required>
                                <option value="">Select vehicle type</option>
                                <?php foreach ($vehicleOptions as $value => $label): ?>
                                <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-error" id="vehicleTypeError"></div>
                        </div>

                        <div class="form-group">
                            <label for="vehicle_plate" class="form-label">
                                Plate Number
                            </label>
                            <input type="text" id="vehicle_plate" name="vehicle_plate" class="form-control"
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

                    <div class="step-actions">
                        <button type="button" class="btn btn-outline btn-prev" data-prev="1">
                            Back
                        </button>
                        <button type="button" class="btn btn-primary btn-next" data-next="3">
                            Next Step
                        </button>
                    </div>
                </div>

                <!-- STEP 3 — Address & Emergency Contact -->
                <div class="register-step" id="step3" style="display: none;">

                    <div class="step-description">
                        <p>Where should we send delivery notices and important documents?</p>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="block" class="form-label">
                                Block / Street / Unit <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="block" name="block" class="form-control"
                                placeholder="e.g. 12-A Sunrise St., Unit 5B" autocomplete="address-line1" required>
                            <div class="form-error" id="blockError"></div>
                        </div>

                        <div class="form-group">
                            <label for="barangay" class="form-label">
                                Barangay <span class="text-muted">(Optional)</span>
                            </label>
                            <input type="text" id="barangay" name="barangay" class="form-control"
                                placeholder="e.g. Barangay San Antonio" autocomplete="address-line2">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="city" class="form-label">
                                City / Municipality <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="city" name="city" class="form-control" placeholder="e.g. Pasig"
                                autocomplete="address-level2" required>
                            <div class="form-error" id="cityError"></div>
                        </div>

                        <div class="form-group">
                            <label for="province" class="form-label">
                                Province <span class="text-muted">(Optional)</span>
                            </label>
                            <input type="text" id="province" name="province" class="form-control"
                                placeholder="e.g. Metro Manila" autocomplete="address-level1">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="region" class="form-label">
                                Region <span class="text-muted">(Optional)</span>
                            </label>
                            <input type="text" id="region" name="region" class="form-control" placeholder="e.g. NCR"
                                autocomplete="address-level1">
                        </div>

                        <div class="form-group">
                            <label for="postal_code" class="form-label">
                                Postal Code <span class="text-muted">(Optional)</span>
                            </label>
                            <input type="text" id="postal_code" name="postal_code" class="form-control"
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
                                class="form-control" placeholder="e.g. Maria" required>
                            <div class="form-error" id="emergencyFirstNameError"></div>
                        </div>

                        <div class="form-group">
                            <label for="emergency_middle_name" class="form-label">
                                Middle Name <span class="text-muted">(Optional)</span>
                            </label>
                            <input type="text" id="emergency_middle_name" name="emergency_middle_name"
                                class="form-control" placeholder="e.g. Santos">
                        </div>

                        <div class="form-group">
                            <label for="emergency_last_name" class="form-label">
                                Last Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="emergency_last_name" name="emergency_last_name" class="form-control"
                                placeholder="e.g. Dela Cruz" required>
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
                                <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>">
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

                <!-- STEP 4 — Verification & Review -->
                <div class="register-step" id="step4" style="display: none;">

                    <div class="step-description">
                        <p>Upload your verification photos and review your details before submitting.</p>
                    </div>

                    <div class="upload-pair">
                        <!-- Profile picture -->
                        <div class="form-group upload-pair-item">
                            <label class="form-label">
                                Profile Picture <span class="text-danger">*</span>
                            </label>

                            <div class="upload-dropzone upload-avatar" id="profileDropzone" tabindex="0" role="button"
                                aria-label="Upload profile picture">
                                <input type="file" id="profile_picture" name="profile_picture"
                                    accept="image/jpeg,image/png,image/webp" hidden>

                                <div class="upload-preview" id="profilePreview" hidden>
                                    <img src="" alt="Profile preview" id="profilePreviewImg">
                                    <button type="button" class="upload-remove" id="profileRemove"
                                        aria-label="Remove image">&times;</button>
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

                            <p class="form-hint">Clear, front-facing photo.</p>
                            <div class="form-error" id="profilePictureError"></div>
                        </div>

                        <!-- Driver's license -->
                        <div class="form-group upload-pair-item">
                            <label class="form-label">
                                Driver's License <span class="text-danger">*</span>
                            </label>

                            <div class="upload-dropzone upload-avatar" id="licenseDropzone" tabindex="0" role="button"
                                aria-label="Upload driver's license">
                                <input type="file" id="drivers_license" name="drivers_license"
                                    accept="image/jpeg,image/png,image/webp" hidden>

                                <div class="upload-preview" id="licensePreview" hidden>
                                    <img src="" alt="License preview" id="licensePreviewImg">
                                    <button type="button" class="upload-remove" id="licenseRemove"
                                        aria-label="Remove image">&times;</button>
                                </div>

                                <div class="upload-hint" id="licenseHint">
                                    <div class="upload-hint-icon" aria-hidden="true">
                                        <img src="<?php echo $assetBase; ?>assets/images/icons/id-card-line.svg" alt=""
                                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-user-line.svg'">
                                    </div>
                                    <p class="upload-hint-title">Upload license</p>
                                    <p class="upload-hint-text">JPG, PNG, WEBP &middot; Max 5&nbsp;MB</p>
                                </div>
                            </div>

                            <p class="form-hint">Must show name, number, and expiry.</p>
                            <div class="form-error" id="driversLicenseError"></div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="license_issue_date" class="form-label">
                                Issue Date <span class="text-muted">(Optional)</span>
                            </label>
                            <input type="date" id="license_issue_date" name="license_issue_date" class="form-control"
                                max="<?php echo date('Y-m-d'); ?>">
                            <div class="form-error" id="licenseIssueError"></div>
                        </div>

                        <div class="form-group">
                            <label for="license_expiry_date" class="form-label">
                                Expiry Date <span class="text-muted">(Optional)</span>
                            </label>
                            <input type="date" id="license_expiry_date" name="license_expiry_date" class="form-control"
                                min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                            <div class="form-error" id="licenseExpiryError"></div>
                            <div class="form-hint">Must be a future date</div>
                        </div>
                    </div>

                    <div class="section-divider">
                        <span>Review Your Information</span>
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
                            <p class="review-section-title">Vehicle</p>
                            <div class="review-row">
                                <span class="review-label">Type</span>
                                <span class="review-value" id="reviewVehicleType">—</span>
                            </div>
                            <div class="review-row">
                                <span class="review-label">Plate</span>
                                <span class="review-value" id="reviewVehiclePlate">—</span>
                            </div>
                            <div class="review-row">
                                <span class="review-label">Make / Model</span>
                                <span class="review-value" id="reviewVehicleMakeModel">—</span>
                            </div>
                        </div>

                        <div class="review-section">
                            <p class="review-section-title">Address</p>
                            <div class="review-row">
                                <span class="review-label">Full Address</span>
                                <span class="review-value" id="reviewAddress">—</span>
                            </div>
                        </div>

                        <div class="review-section">
                            <p class="review-section-title">Emergency Contact</p>
                            <div class="review-row">
                                <span class="review-label">Name</span>
                                <span class="review-value" id="reviewEmergencyName">—</span>
                            </div>
                            <div class="review-row">
                                <span class="review-label">Relationship</span>
                                <span class="review-value" id="reviewEmergencyRelationship">—</span>
                            </div>
                            <div class="review-row">
                                <span class="review-label">Mobile</span>
                                <span class="review-value" id="reviewEmergencyContact">—</span>
                            </div>
                        </div>
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
                        <button type="button" class="btn btn-outline btn-prev" data-prev="3">
                            Back
                        </button>
                        <button type="submit" class="btn btn-primary" id="registerBtn">
                            Submit Application
                        </button>
                    </div>
                </div>
            </form>

            <div class="register-footer">
                <p class="text-muted">
                    Already a FitPal rider?
                    <a href="sign-in.php">Sign in to your account</a>
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Notification modal -->
<div id="notifierModal" class="notifier hidden" role="dialog" aria-modal="true" aria-labelledby="notifierTitle"
    aria-describedby="notifierMessage">
    <div class="notifier-content">
        <div class="notifier-icon" aria-hidden="true">
            <img src="<?php echo $assetBase; ?>assets/images/icons/verified-fill.svg" alt=""
                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/mail.svg'">
        </div>
        <p class="heading-5" id="notifierTitle">Application Received</p>
        <p id="notifierMessage"></p>
        <button id="notifierCloseBtn" class="btn btn-primary" type="button">Continue to Sign In</button>
    </div>
</div>

<script src="../assets/ui/js/sign-up.js" defer></script>

<?php
require_once __DIR__ . '/../../shared/includes/footer.php';
?>