<?php
/**
 * FitPal Customer Registration Page
 *
 * Multi-step registration with dietary preferences, allergies, and fitness goals.
 * Requires agreement to Terms & Conditions / Privacy Policy before account creation.
 *
 * @package FitPal
 * @version 1.1
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/header.php';

// Redirect if already logged in
if (isset($_SESSION['customer_id']) && !empty($_SESSION['customer_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$assetBase = '../../shared/';

// Dietary preferences options
$dietaryOptions = [
    'vegan' => 'Vegan',
    'vegetarian' => 'Vegetarian',
    'keto' => 'Keto',
    'high_protein' => 'High Protein',
    'low_carb' => 'Low Carb',
    'gluten_free' => 'Gluten Free',
    'dairy_free' => 'Dairy Free',
    'pescatarian' => 'Pescatarian',
    'mediterranean' => 'Mediterranean',
    'none' => 'No Preference'
];

// Allergy options
$allergyOptions = [
    'nuts' => 'Nuts',
    'dairy' => 'Dairy',
    'eggs' => 'Eggs',
    'soy' => 'Soy',
    'wheat' => 'Wheat / Gluten',
    'shellfish' => 'Shellfish',
    'fish' => 'Fish',
    'peanuts' => 'Peanuts',
    'sesame' => 'Sesame',
    'none' => 'No Allergies'
];

// Fitness goals
$fitnessGoals = [
    'weight_loss' => 'Weight Loss',
    'muscle_gain' => 'Muscle Gain',
    'maintenance' => 'Maintenance / General Health'
];
?>
<div class="content register-page">
    <div class="register-container">
        <!-- Progress Steps -->
        <div class="register-progress">
            <div class="progress-step active" data-step="1">
                <span class="step-number">1</span>
                <span class="step-label">Personal Info</span>
            </div>
            <div class="progress-line"></div>
            <div class="progress-step" data-step="2">
                <span class="step-number">2</span>
                <span class="step-label">Dietary Preferences</span>
            </div>
            <div class="progress-line"></div>
            <div class="progress-step" data-step="3">
                <span class="step-number">3</span>
                <span class="step-label">Allergies</span>
            </div>
            <div class="progress-line"></div>
            <div class="progress-step" data-step="4">
                <span class="step-number">4</span>
                <span class="step-label">Fitness Goals</span>
            </div>
        </div>

        <div class="register-header">
            <p class="register-title">Create Your <span>Account</span></p>
            <p class="register-subtitle" id="stepSubtitle">Step 1 of 4: Personal Information</p>
        </div>

        <!-- Error Messages -->
        <div id="registerError" class="alert alert-danger" style="display: none;" role="alert">
            <span id="errorMessage"></span>
        </div>

        <!-- ============================================
        STEP 1: PERSONAL INFORMATION
        ============================================ -->
        <form method="POST" action="../backend/handlers/register_handler.php" class="register-form" id="registerForm"
            novalidate>
            <input type="hidden" name="csrf_token"
                value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="current_step" id="currentStep" value="1">
            <input type="hidden" name="total_steps" value="4">

            <!-- STEP 1: Personal Information -->
            <div class="register-step" id="step1">
                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                        <input type="text" id="first_name" name="first_name" class="form-control"
                            placeholder="Enter your first name" required pattern="[A-Za-z\s\-']+"
                            title="First name can only contain letters, spaces, hyphens, and apostrophes"
                            oninput="this.value = this.value.replace(/[^A-Za-z\s\-']/g, '')">
                        <div class="form-error" id="firstNameError"></div>
                    </div>

                    <div class="form-group">
                        <label for="middle_name" class="form-label">Middle Name <span
                                class="text-muted">(Optional)</span></label>
                        <input type="text" id="middle_name" name="middle_name" class="form-control"
                            placeholder="Enter your middle name" pattern="[A-Za-z\s\-']*"
                            title="Middle name can only contain letters, spaces, hyphens, and apostrophes"
                            oninput="this.value = this.value.replace(/[^A-Za-z\s\-']/g, '')">
                        <div class="form-error" id="middleNameError"></div>
                    </div>

                    <div class="form-group">
                        <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                        <input type="text" id="last_name" name="last_name" class="form-control"
                            placeholder="Enter your last name" required pattern="[A-Za-z\s\-']+"
                            title="Last name can only contain letters, spaces, hyphens, and apostrophes"
                            oninput="this.value = this.value.replace(/[^A-Za-z\s\-']/g, '')">
                        <div class="form-error" id="lastNameError"></div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="birthdate" class="form-label">Birthdate <span class="text-danger">*</span></label>
                        <input type="date" id="birthdate" name="birthdate" class="form-control" required
                            max="<?php echo date('Y-m-d', strtotime('-13 years')); ?>"
                            min="<?php echo date('Y-m-d', strtotime('-120 years')); ?>" placeholder="MM/DD/YYYY">
                        <div class="form-error" id="birthdateError"></div>
                        <div class="form-hint">Format: MM/DD/YYYY</div>
                    </div>

                    <div class="form-group">
                        <label for="gender" class="form-label">Gender <span class="text-danger">*</span></label>
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
                        <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                        <input type="email" id="email" name="email" class="form-control"
                            placeholder="Enter your email address" required autocomplete="email">
                        <div class="form-error" id="emailError"></div>
                    </div>

                    <div class="form-group">
                        <label for="contact_number" class="form-label">Contact Number <span
                                class="text-danger">*</span></label>
                        <input type="tel" id="contact_number" name="contact_number" class="form-control"
                            placeholder="09XX XXX XXXX" required pattern="[0-9\s\+]+"
                            title="Please enter a valid Philippine mobile number"
                            oninput="this.value = this.value.replace(/[^0-9\s\+]/g, '')">
                        <div class="form-error" id="contactError"></div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" id="username" name="username" class="form-control"
                            placeholder="Choose a username" required autocomplete="username"
                            pattern="[A-Za-z0-9_]{3,20}"
                            title="Username must be 3-20 characters and can only contain letters, numbers, and underscores"
                            oninput="this.value = this.value.replace(/[^A-Za-z0-9_]/g, '')">
                        <div class="form-error" id="usernameError"></div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                        <div class="password-wrapper">
                            <input type="password" id="password" name="password" class="form-control"
                                placeholder="Create a password" required autocomplete="new-password"
                                pattern="^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,16}$"
                                title="Password must be 8-16 characters with at least one uppercase letter, one lowercase letter, and one number">
                            <button type="button" class="password-toggle" id="togglePassword" tabindex="-1"
                                aria-label="Toggle password visibility">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/password-hide.svg"
                                    alt="Hide password" id="passwordIcon">
                            </button>
                        </div>
                        <div class="form-error" id="passwordError"></div>
                        <div class="form-hint">Password must be 8-16 characters with at least one uppercase letter, one
                            lowercase letter, and one number</div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password" class="form-label">Confirm Password <span
                                class="text-danger">*</span></label>
                        <div class="password-wrapper">
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                                placeholder="Confirm your password" required autocomplete="new-password">
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
                    <button type="button" class="btn btn-primary btn-next" data-next="2">Next Step &rarr;</button>
                </div>
            </div>

            <!-- STEP 2: Dietary Preferences -->
            <div class="register-step" id="step2" style="display: none;">
                <div class="step-description">
                    <p>Select your dietary preferences. You can select multiple options or skip this step.</p>
                </div>

                <div class="options-grid" id="dietaryOptions">
                    <?php foreach ($dietaryOptions as $value => $label): ?>
                    <div class="option-card" data-value="<?php echo $value; ?>">
                        <div class="option-check">
                            <input type="checkbox" id="diet_<?php echo $value; ?>" name="dietary_preferences[]"
                                value="<?php echo $value; ?>">
                            <label for="diet_<?php echo $value; ?>">
                                <span
                                    class="option-label"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
                            </label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="step-actions">
                    <button type="button" class="btn btn-secondary btn-prev" data-prev="1">&larr; Back</button>
                    <button type="button" class="btn btn-skip" id="skipDiet">Skip Step</button>
                    <button type="button" class="btn btn-primary btn-next" data-next="3" id="dietNext" disabled>Next
                        Step &rarr;</button>
                </div>
            </div>

            <!-- STEP 3: Allergies -->
            <div class="register-step" id="step3" style="display: none;">
                <div class="step-description">
                    <p>Select any allergies you have. This helps us recommend safe meals. You can select multiple
                        options or skip this step.</p>
                </div>

                <div class="options-grid" id="allergyOptions">
                    <?php foreach ($allergyOptions as $value => $label): ?>
                    <div class="option-card" data-value="<?php echo $value; ?>">
                        <div class="option-check">
                            <input type="checkbox" id="allergy_<?php echo $value; ?>" name="allergies[]"
                                value="<?php echo $value; ?>">
                            <label for="allergy_<?php echo $value; ?>">
                                <span
                                    class="option-label"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
                            </label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="step-actions">
                    <button type="button" class="btn btn-secondary btn-prev" data-prev="2">&larr; Back</button>
                    <button type="button" class="btn btn-skip" id="skipAllergies">Skip Step</button>
                    <button type="button" class="btn btn-primary btn-next" data-next="4" id="allergyNext" disabled>Next
                        Step &rarr;</button>
                </div>
            </div>

            <!-- STEP 4: Fitness Goals -->
            <div class="register-step" id="step4" style="display: none;">
                <div class="step-description">
                    <p>Tell us about your fitness goals. This information helps personalize your experience.</p>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="height" class="form-label">Height (cm) <span
                                class="text-muted">(Optional)</span></label>
                        <input type="number" id="height" name="height" class="form-control"
                            placeholder="Enter your height in cm" min="50" max="300" step="0.1">
                        <div class="form-error" id="heightError"></div>
                    </div>

                    <div class="form-group">
                        <label for="weight" class="form-label">Weight (kg) <span
                                class="text-muted">(Optional)</span></label>
                        <input type="number" id="weight" name="weight" class="form-control"
                            placeholder="Enter your weight in kg" min="10" max="500" step="0.1">
                        <div class="form-error" id="weightError"></div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="fitness_goal" class="form-label">Fitness Goal <span
                            class="text-muted">(Optional)</span></label>
                    <select id="fitness_goal" name="fitness_goal" class="form-control">
                        <option value="">Select your fitness goal</option>
                        <?php foreach ($fitnessGoals as $value => $label): ?>
                        <option value="<?php echo $value; ?>">
                            <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-error" id="fitnessGoalError"></div>
                </div>

                <!-- ============================================
                     TERMS & CONDITIONS / PRIVACY POLICY CHECKBOX
                     FIXED: Properly styled with custom checkbox
                     matching crooks-cart-collectives sign-up.php
                     ============================================ -->
                <div class="form-group terms-group" id="termsGroup">
                    <div class="checkbox-wrapper">
                        <input type="checkbox" id="terms" name="terms" required>
                        <span class="custom-checkbox"></span>
                        <label for="terms" class="terms-label">
                            I agree to the
                            <a href="<?php echo $assetBase; ?>pages/terms_conditions.php" target="_blank"
                                rel="noopener noreferrer">Terms and Conditions</a>
                            and
                            <a href="<?php echo $assetBase; ?>pages/privacy_policy.php" target="_blank"
                                rel="noopener noreferrer">Privacy Policy</a>.
                        </label>
                    </div>
                    <div class="form-error" id="termsError"></div>
                </div>

                <div class="step-actions">
                    <button type="button" class="btn btn-secondary btn-prev" data-prev="3">&larr; Back</button>
                    <button type="submit" class="btn btn-primary" id="registerBtn">Create Account</button>
                </div>
            </div>
        </form>

        <div class="register-footer">
            <p class="register-footer-text">
                Already have an account?
                <a href="<?php echo $assetBase; ?>../customer/pages/login.php">Sign In</a>
            </p>
        </div>
    </div>
</div>

<!-- Notification Modal -->
<div id="notifierModal" class="notifier hidden">
    <div class="notifier-content">
        <div class="notifier-icon">
            <img src="<?php echo $assetBase; ?>assets/images/icons/mail.svg" alt="Notification">
        </div>
        <p id="notifierMessage"></p>
        <button id="notifierCloseBtn" class="btn btn-primary">OK</button>
    </div>
</div>

<script src="<?php echo $assetBase; ?>assets/ui/js/header.js" defer></script>
<script src="../assets/ui/js/register.js" defer></script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>