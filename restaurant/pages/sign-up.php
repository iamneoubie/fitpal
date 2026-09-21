<?php
/**
 * FitPal Restaurant Registration Page
 *
 * Two-step registration:
 *   Step 1 — Account Holder
 *   Step 2 — Business Details + up to 5 permit photos + terms
 *
 * @package FitPal
 * @version 1.1
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['restaurant_account_id'])) {
    header('Location: dashboard.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/../includes/header.php';

$errorMessage = $_SESSION['restaurant_registration_error'] ?? '';
unset($_SESSION['restaurant_registration_error']);
?>

<div class="content register-page">
    <div class="container">
        <div class="register-card">

            <div class="register-progress" role="progressbar" aria-valuenow="1" aria-valuemin="1" aria-valuemax="2"
                aria-label="Registration progress">
                <div class="progress-step active" data-step="1">
                    <span class="step-number">1</span>
                    <span class="step-label">Account</span>
                </div>
                <div class="progress-line" id="progressLine1"></div>
                <div class="progress-step" data-step="2">
                    <span class="step-number">2</span>
                    <span class="step-label">Business</span>
                </div>
            </div>

            <div class="register-header">
                <p class="heading-2">Register Your <span>Restaurant</span></p>
                <p class="text-muted" id="stepSubtitle">Step 1 of 2 — Account Holder</p>
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
                enctype="multipart/form-data" novalidate>

                <input type="hidden" name="csrf_token"
                    value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="current_step" id="currentStep" value="1">

                <!-- STEP 1 — Account Holder -->
                <div class="register-step" id="step1">

                    <div class="step-description">
                        <p>The primary account holder will have full owner-level access to the restaurant dashboard.</p>
                    </div>

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

                <!-- STEP 2 — Business Details -->
                <div class="register-step" id="step2" style="display: none;">

                    <div class="step-description">
                        <p>Tell us about your restaurant and its first branch. You can add more branches later.</p>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="business_name" class="form-label">
                                Business Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="business_name" name="business_name" class="form-control"
                                placeholder="e.g. Green Bowl Cafe" maxlength="100" required>
                            <div class="form-error" id="businessNameError"></div>
                        </div>

                        <div class="form-group">
                            <label for="cuisine_type" class="form-label">
                                Cuisine Type <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="cuisine_type" name="cuisine_type" class="form-control"
                                placeholder="e.g. Cafe, Asian Fusion, American" maxlength="50" required>
                            <div class="form-error" id="cuisineTypeError"></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="business_description" class="form-label">
                            Business Description <span class="text-muted">(Optional)</span>
                        </label>
                        <textarea id="business_description" name="business_description" class="form-control"
                            placeholder="Describe your restaurant, your specialty, and what makes it unique..."
                            maxlength="2000"></textarea>
                        <div class="form-hint">Max 2000 characters</div>
                    </div>

                    <div class="form-group">
                        <label for="dietary_tags" class="form-label">
                            Dietary Tags <span class="text-muted">(Optional)</span>
                        </label>
                        <input type="text" id="dietary_tags" name="dietary_tags" class="form-control"
                            placeholder="e.g. vegan,organic,gluten_free (comma-separated)" maxlength="200">
                        <div class="form-hint">Comma-separated. Example: vegan, gluten_free, halal</div>
                    </div>

                    <div class="section-divider">
                        <span>First Branch</span>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="branch_name" class="form-label">
                                Branch Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="branch_name" name="branch_name" class="form-control"
                                placeholder="e.g. Main Branch" maxlength="50" required>
                            <div class="form-error" id="branchNameError"></div>
                        </div>
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

                    <!-- PERMITS -->
                    <div class="section-divider">
                        <span>Permits &amp; Documents</span>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            Business Permits <span class="text-danger">*</span>
                        </label>
                        <p class="form-hint" style="margin-bottom: 10px;">
                            Upload photos of your business permits, DTI/SEC registration,
                            sanitary permits, or any document that verifies your restaurant
                            is authorized to sell food. At least 1, up to 5 photos.
                        </p>

                        <div class="permit-dropzone-grid" id="permitGrid">
                            <?php for ($i = 0; $i < 5; $i++): ?>
                            <div class="permit-slot" data-index="<?php echo $i; ?>">
                                <input type="file" name="permits[]" id="permit_<?php echo $i; ?>"
                                    accept="image/jpeg,image/png,image/webp" class="permit-input" hidden>

                                <div class="permit-slot-inner">
                                    <div class="permit-hint">
                                        <img src="<?php echo $assetBase; ?>assets/images/icons/image-upload-fill.svg"
                                            alt="" class="permit-hint-icon"
                                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/image-line.svg'">
                                        <span class="permit-hint-text">Add photo</span>
                                    </div>

                                    <div class="permit-preview" hidden>
                                        <img src="" alt="Permit preview" class="permit-preview-img">
                                        <button type="button" class="permit-remove"
                                            aria-label="Remove this permit">&times;</button>
                                    </div>
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>

                        <div class="form-error" id="permitsError"></div>
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
                        <button type="button" class="btn btn-outline btn-prev" data-prev="1">
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
                    Already registered?
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

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>