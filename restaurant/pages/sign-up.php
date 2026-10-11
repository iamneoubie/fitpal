<?php
/**
 * FitPal Restaurant Registration Page
 *
 * Five-step registration:
 *   Step 1 — Account Holder
 *   Step 2 — Security
 *   Step 3 — Business Details
 *   Step 4 — Branch & Permits
 *   Step 5 — Review & Submit
 *
 * ---------------------------------------------------------------------
 * WHY FIVE STEPS
 * ---------------------------------------------------------------------
 * The previous revision packed the account fields, the password, the
 * business details, the branch address, the permit grid, and the
 * terms checkbox into a single Step 2. That step carried eleven text
 * inputs, five file slots, and a checkbox — enough to require
 * scrolling on every viewport and to make the form feel like one
 * undifferentiated block.
 *
 * The five-step layout matches restaurant/pages/restaurant-reapply.php
 * step for step:
 *
 *   1  Account Holder    first_name, middle_name, last_name, email,
 *                        contact_number, username
 *   2  Security          password, confirm_password
 *   3  Business Details  business_name, cuisine_type,
 *                        business_description, dietary_tags
 *   4  Branch & Permits  branch_name, block, barangay, city,
 *                        province, region, postal_code,
 *                        five permit slots, terms checkbox
 *   5  Review & Submit   read-only summary
 *
 * ---------------------------------------------------------------------
 * PER-ROLE SESSION MODEL
 * ---------------------------------------------------------------------
 * The page bootstraps the restaurant session before doing anything
 * else. The already-signed-in redirect checks
 * $_SESSION['restaurant_account_id'], a key only the restaurant
 * role writes into this session.
 *
 * ---------------------------------------------------------------------
 * CSRF
 * ---------------------------------------------------------------------
 * The page does not generate its own CSRF token. The restaurant
 * header assigns $csrfToken on every request by calling
 * getRestaurantCsrfToken() from
 * restaurant/includes/restaurant-csrf-token.php, which stores the
 * token under 'restaurant_csrf_token' inside the restaurant
 * session. The form on this page reads $csrfToken from the header;
 * sign-up-handler.php validates against the same key.
 *
 * ---------------------------------------------------------------------
 * HANDLER PATH
 * ---------------------------------------------------------------------
 * This page is served from restaurant/pages/. Every relative URL
 * on the page starts one directory up:
 *
 *     ../backend/handlers/sign-up-handler.php
 *     ../assets/ui/js/sign-up.js
 *
 * Both resolve from the browser URL
 * http://<host>/restaurant/pages/sign-up.php to:
 *
 *     http://<host>/restaurant/backend/handlers/sign-up-handler.php
 *     http://<host>/restaurant/assets/ui/js/sign-up.js
 *
 * ---------------------------------------------------------------------
 * REQUIRED FIELDS
 * ---------------------------------------------------------------------
 * Enforced by sign-up.js:
 *
 *   Step 1:  first_name, last_name, email, contact_number, username
 *   Step 2:  password, confirm_password
 *   Step 3:  business_name, cuisine_type, business_description,
 *            dietary_tags
 *   Step 4:  branch_name, block, barangay, city, province, region,
 *            postal_code, at least one permit, terms checkbox
 *
 * ---------------------------------------------------------------------
 * FIRST-LETTER CAPITALIZATION
 * ---------------------------------------------------------------------
 * Business Description carries `.capitalize-field`. The paired JS
 * uppercases the first letter of the first word on input.
 *
 * ---------------------------------------------------------------------
 * WHAT REGISTRATION CREATES
 * ---------------------------------------------------------------------
 * The form posts to ../backend/handlers/sign-up-handler.php, which
 * writes a single set of rows in one transaction:
 *
 *   1. restaurant
 *   2. financial_account
 *   3. restaurant_branch
 *   4. restaurant_account
 *   5. restaurant_permit × 1..N
 *
 * @package FitPal
 * @version 4.0 — Restructured into five steps, matching
 *                restaurant-reapply.php step for step. Step 2 now
 *                carries only the password pair. Step 3 carries the
 *                business details. Step 4 carries the branch
 *                address, the permit grid, and the terms checkbox.
 *                Step 5 is a read-only review. The handler and the
 *                POST field names are unchanged.
 *
 *                (3.0: business_description, dietary_tags,
 *                barangay, province, region, postal_code required.
 *                2.3: restored one-level prefix. 2.0: header owns
 *                CSRF. 1.2: restaurant role's own session key.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('restaurant');

if (!empty($_SESSION['restaurant_account_id'])) {
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';

// $assetBase and $csrfToken are provided by header.php.

$errorMessage = $_SESSION['restaurant_registration_error'] ?? '';
unset($_SESSION['restaurant_registration_error']);

$stepTitles = [
    1 => 'Step 1 of 5 — Account Holder',
    2 => 'Step 2 of 5 — Security',
    3 => 'Step 3 of 5 — Business Details',
    4 => 'Step 4 of 5 — Branch & Permits',
    5 => 'Step 5 of 5 — Review & Submit',
];
?>

<div class="content register-page">
    <div class="container">
        <div class="register-card">

            <div class="register-progress" role="progressbar" aria-valuenow="1" aria-valuemin="1" aria-valuemax="5"
                aria-label="Registration progress">
                <div class="progress-step active" data-step="1">
                    <span class="step-number">1</span>
                    <span class="step-label">Account</span>
                </div>
                <div class="progress-line" id="progressLine1"></div>
                <div class="progress-step" data-step="2">
                    <span class="step-number">2</span>
                    <span class="step-label">Security</span>
                </div>
                <div class="progress-line" id="progressLine2"></div>
                <div class="progress-step" data-step="3">
                    <span class="step-number">3</span>
                    <span class="step-label">Business</span>
                </div>
                <div class="progress-line" id="progressLine3"></div>
                <div class="progress-step" data-step="4">
                    <span class="step-number">4</span>
                    <span class="step-label">Branch</span>
                </div>
                <div class="progress-line" id="progressLine4"></div>
                <div class="progress-step" data-step="5">
                    <span class="step-number">5</span>
                    <span class="step-label">Review</span>
                </div>
            </div>

            <div class="register-header">
                <p class="heading-2">Register Your <span>Restaurant</span></p>
                <p class="text-muted" id="stepSubtitle">
                    <?php echo htmlspecialchars($stepTitles[1], ENT_QUOTES, 'UTF-8'); ?>
                </p>
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
                    value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="current_step" id="currentStep" value="1">

                <!-- ============================================================
                     STEP 1 — ACCOUNT HOLDER
                     ============================================================ -->
                <div class="register-step" id="step1">

                    <div class="step-description">
                        <p>The primary account holder will have full owner-level access to the restaurant dashboard.</p>
                    </div>

                    <div class="form-row form-row-3">
                        <div class="form-group">
                            <label for="first_name" class="form-label">
                                First Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="first_name" name="first_name" class="form-control name-field"
                                placeholder="Enter your first name" autocomplete="given-name" maxlength="50" required>
                            <div class="form-error" id="firstNameError"></div>
                        </div>

                        <div class="form-group">
                            <label for="middle_name" class="form-label">
                                Middle Name <span class="text-muted">(Optional)</span>
                            </label>
                            <input type="text" id="middle_name" name="middle_name" class="form-control name-field"
                                placeholder="Optional" autocomplete="additional-name" maxlength="50">
                        </div>

                        <div class="form-group">
                            <label for="last_name" class="form-label">
                                Last Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="last_name" name="last_name" class="form-control name-field"
                                placeholder="Enter your last name" autocomplete="family-name" maxlength="50" required>
                            <div class="form-error" id="lastNameError"></div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="email" class="form-label">
                                Email Address <span class="text-danger">*</span>
                            </label>
                            <input type="email" id="email" name="email" class="form-control email-field"
                                placeholder="Enter your email address" autocomplete="email" maxlength="100" required>
                            <div class="form-error" id="emailError"></div>
                        </div>

                        <div class="form-group">
                            <label for="contact_number" class="form-label">
                                Contact Number <span class="text-danger">*</span>
                            </label>
                            <input type="tel" id="contact_number" name="contact_number"
                                class="form-control digits-field" placeholder="09XX XXX XXXX" autocomplete="tel"
                                inputmode="numeric" maxlength="13" required>
                            <div class="form-error" id="contactError"></div>
                            <div class="form-hint">11 digits, starting with 09</div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="username" class="form-label">
                                Username <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="username" name="username" class="form-control username-field"
                                placeholder="Choose a username" autocomplete="username" maxlength="20" required>
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

                <!-- ============================================================
                     STEP 2 — SECURITY
                     ============================================================ -->
                <div class="register-step" id="step2" style="display: none;">

                    <div class="step-description">
                        <p>Choose a password for your account. You will use this
                            together with your email or username to sign in.</p>
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
                            <div class="form-hint">8–20 characters, letters and numbers only</div>
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
                        <button type="button" class="btn btn-outline btn-prev" data-prev="1">
                            Back
                        </button>
                        <button type="button" class="btn btn-primary btn-next" data-next="3">
                            Next Step
                        </button>
                    </div>
                </div>

                <!-- ============================================================
                     STEP 3 — BUSINESS DETAILS
                     ============================================================ -->
                <div class="register-step" id="step3" style="display: none;">

                    <div class="step-description">
                        <p>Tell us about your restaurant. This information appears
                            on the customer-facing menu.</p>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="business_name" class="form-label">
                                Business Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="business_name" name="business_name" class="form-control name-field"
                                placeholder="e.g. Green Bowl Cafe" maxlength="100" required>
                            <div class="form-error" id="businessNameError"></div>
                        </div>

                        <div class="form-group">
                            <label for="cuisine_type" class="form-label">
                                Cuisine Type <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="cuisine_type" name="cuisine_type" class="form-control name-field"
                                placeholder="e.g. Cafe, Asian Fusion, American" maxlength="50" required>
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
                            maxlength="2000" rows="5" required></textarea>
                        <div class="form-hint">Max 2000 characters</div>
                        <div class="form-error" id="businessDescriptionError"></div>
                    </div>

                    <div class="form-group">
                        <label for="dietary_tags" class="form-label">
                            Dietary Tags <span class="text-danger">*</span>
                        </label>
                        <input type="text" id="dietary_tags" name="dietary_tags" class="form-control"
                            placeholder="e.g. vegan,organic,gluten_free (comma-separated)" maxlength="200" required>
                        <div class="form-hint">Comma-separated. Example: vegan, gluten_free, halal</div>
                        <div class="form-error" id="dietaryTagsError"></div>
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
                     STEP 4 — BRANCH & PERMITS
                     ============================================================ -->
                <div class="register-step" id="step4" style="display: none;">

                    <div class="step-description">
                        <p>Add your first branch address and upload the permits
                            that verify your restaurant is authorized to sell food.</p>
                    </div>

                    <div class="section-divider">
                        <span>First Branch</span>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="branch_name" class="form-label">
                                Branch Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="branch_name" name="branch_name" class="form-control name-field"
                                placeholder="e.g. Main Branch" maxlength="50" required>
                            <div class="form-error" id="branchNameError"></div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="block" class="form-label">
                                Block / Street / Unit <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="block" name="block" class="form-control address-field"
                                placeholder="e.g. 12-A Sunrise St., Unit 5B" autocomplete="address-line1" required>
                            <div class="form-error" id="blockError"></div>
                        </div>

                        <div class="form-group">
                            <label for="barangay" class="form-label">
                                Barangay <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="barangay" name="barangay" class="form-control address-field"
                                placeholder="e.g. Barangay San Antonio" autocomplete="address-line2" required>
                            <div class="form-error" id="barangayError"></div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="city" class="form-label">
                                City / Municipality <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="city" name="city" class="form-control address-field"
                                placeholder="e.g. Pasig" autocomplete="address-level2" required>
                            <div class="form-error" id="cityError"></div>
                        </div>

                        <div class="form-group">
                            <label for="province" class="form-label">
                                Province <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="province" name="province" class="form-control address-field"
                                placeholder="e.g. Metro Manila" autocomplete="address-level1" required>
                            <div class="form-error" id="provinceError"></div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="region" class="form-label">
                                Region <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="region" name="region" class="form-control address-field"
                                placeholder="e.g. NCR" autocomplete="address-level1" required>
                            <div class="form-error" id="regionError"></div>
                        </div>

                        <div class="form-group">
                            <label for="postal_code" class="form-label">
                                Postal Code <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="postal_code" name="postal_code" class="form-control digits-field"
                                placeholder="e.g. 1605" autocomplete="postal-code" maxlength="10" inputmode="numeric"
                                required>
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
                        <button type="button" class="btn btn-outline btn-prev" data-prev="3">
                            Back
                        </button>
                        <button type="button" class="btn btn-primary btn-next" data-next="5">
                            Next Step
                        </button>
                    </div>
                </div>

                <!-- ============================================================
                     STEP 5 — REVIEW & SUBMIT
                     ============================================================ -->
                <div class="register-step" id="step5" style="display: none;">

                    <div class="step-description">
                        <p>Review your information below. If everything looks correct, submit
                            your application for review.</p>
                    </div>

                    <div class="review-summary" id="reviewSummary">
                        <div class="review-section">
                            <p class="review-section-title">Account Holder</p>
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
                                <span class="review-label">Uploaded</span>
                                <span class="review-value" id="reviewPermitStatus">—</span>
                            </div>
                        </div>
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