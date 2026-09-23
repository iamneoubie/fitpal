<?php
/**
 * FitPal Rider Registration Page
 *
 * Five-step rider application:
 *   Step 1 — Personal Information
 *   Step 2 — Vehicle Details
 *   Step 3 — Address & Emergency Contact
 *   Step 4 — Verification Uploads (Formal Photo + Driver's License)
 *   Step 5 — Review & Submit
 *
 * The two required uploads live on Step 4 so the handler can read them
 * from the same request. They are presented side by side (photo left,
 * license right) in a two-column grid that collapses to a single
 * column on tablet and mobile:
 *   4A. Formal Photo      → $_FILES['profile_picture']
 *   4B. Driver's License  → $_FILES['drivers_license']
 *
 * License issue date and expiry date are both required. An admin
 * cannot verify a rider without knowing when the license expires.
 *
 * Step 5 shows the review summary, the terms checkbox, and the final
 * submit button.
 *
 * Visual layout mirrors the customer register card so both flows
 * feel like the same product:
 *   - max-width: 700px on .register-card
 *   - align-items: center on .register-page
 *   - 32px / 24px card padding
 *
 * @package FitPal
 * @version 6.3 — Dropped the page-local CSRF bootstrap. The rider
 *                role's token is now assigned unconditionally by
 *                includes/header.php via rider-csrf-token.php, so
 *                this page no longer generates $_SESSION
 *                ['rider_csrf_token'] inline. It simply includes
 *                the header and reads $csrfToken from it. Behavior
 *                is unchanged: the form's POST field stays named
 *                csrf_token, and sign-up-handler.php still validates
 *                against $_SESSION['rider_csrf_token'].
 *
 *                (6.2: Uses its own session key, rider_csrf_token,
 *                for the registration form so a sign-in by another
 *                role in the same browser session cannot invalidate
 *                the token this form was rendered with.)
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['delivery_rider_id'])) {
    header('Location: dashboard.php');
    exit;
}

// ===== HEADER (starts DB, assigns $csrfToken, renders <head> + <header>) =====
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
            <div class="register-progress" role="progressbar" aria-valuenow="1" aria-valuemin="1" aria-valuemax="5"
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
                    <span class="step-label">Uploads</span>
                </div>
                <div class="progress-line" id="progressLine4"></div>
                <div class="progress-step" data-step="5">
                    <span class="step-number">5</span>
                    <span class="step-label">Verify</span>
                </div>
            </div>

            <div class="register-header">
                <p class="heading-2">Become a <span>FitPal Rider</span></p>
                <p class="text-muted" id="stepSubtitle">Step 1 of 5 — Personal Information</p>
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
                    value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="current_step" id="currentStep" value="1">

                <!-- ============================================================
                     STEP 1 — Personal Information
                     ============================================================ -->
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

                <!-- ============================================================
                     STEP 2 — Vehicle Details
                     ============================================================ -->
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

                <!-- ============================================================
                     STEP 3 — Address & Emergency Contact
                     ============================================================ -->
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

                <!-- ============================================================
                     STEP 4 — Verification Uploads
                     ============================================================ -->
                <div class="register-step" id="step4" style="display: none;">

                    <div class="step-description">
                        <p>
                            We need two photos before you can start delivering: a formal
                            profile picture and your driver's license. Each has its own
                            requirements below.
                        </p>
                    </div>

                    <!-- Two uploads side by side (stacks on mobile) -->
                    <div class="upload-block-grid">

                        <!-- 4A — FORMAL PHOTO (LEFT) -->
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

                        <!-- 4B — DRIVER'S LICENSE (RIGHT) -->
                        <div class="upload-block" id="uploadBlockLicense">

                            <div class="upload-block-header">
                                <div class="upload-block-number" aria-hidden="true">2</div>
                                <div class="upload-block-heading">
                                    <h3 class="upload-block-title">
                                        Driver's License <span class="text-danger">*</span>
                                    </h3>
                                    <p class="upload-block-subtitle">
                                        Photo of your valid driver's license.
                                    </p>
                                </div>
                            </div>

                            <div class="upload-block-body">
                                <div class="upload-dropzone upload-avatar" id="licenseDropzone" tabindex="0"
                                    role="button" aria-label="Upload driver's license">
                                    <input type="file" id="drivers_license" name="drivers_license"
                                        accept="image/jpeg,image/png,image/webp" hidden>

                                    <div class="upload-preview" id="licensePreview" hidden>
                                        <img src="" alt="License preview" id="licensePreviewImg">
                                        <button type="button" class="upload-remove" id="licenseRemove"
                                            aria-label="Remove license photo">&times;</button>
                                    </div>

                                    <div class="upload-hint" id="licenseHint">
                                        <div class="upload-hint-icon" aria-hidden="true">
                                            <img src="<?php echo $assetBase; ?>assets/images/icons/id-card-line.svg"
                                                alt=""
                                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-user-line.svg'">
                                        </div>
                                        <p class="upload-hint-title">Upload license</p>
                                        <p class="upload-hint-text">JPG, PNG, WEBP &middot; Max 5&nbsp;MB</p>
                                    </div>
                                </div>

                                <ul class="upload-guidelines">
                                    <li>All four corners of the card visible</li>
                                    <li>Name, number, expiry must be readable</li>
                                    <li>No glare, blur, or partial crops</li>
                                </ul>

                                <div class="form-error" id="driversLicenseError"></div>
                            </div>
                        </div>
                    </div>

                    <!-- License dates: full-width row under the pair -->
                    <div class="form-row upload-block-dates">
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

                <!-- ============================================================
                     STEP 5 — Review & Submit
                     ============================================================ -->
                <div class="register-step" id="step5" style="display: none;">

                    <div class="step-description">
                        <p>
                            Review your information below. If everything looks correct,
                            accept the terms and submit your application.
                        </p>
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

                        <div class="review-section">
                            <p class="review-section-title">Verification Uploads</p>
                            <div class="review-row">
                                <span class="review-label">Formal Photo</span>
                                <span class="review-value" id="reviewProfilePhoto">—</span>
                            </div>
                            <div class="review-row">
                                <span class="review-label">Driver's License</span>
                                <span class="review-value" id="reviewLicensePhoto">—</span>
                            </div>
                            <div class="review-row">
                                <span class="review-label">License Validity</span>
                                <span class="review-value" id="reviewLicenseDates">—</span>
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
                        <button type="button" class="btn btn-outline btn-prev" data-prev="4">
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