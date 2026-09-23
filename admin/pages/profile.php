<?php
/**
 * FitPal Admin Profile
 *
 * Two-tab layout: Personal Information and Security.
 *
 *   Personal Information — edit first / middle / last name and contact
 *   number. Email and role are shown but read-only because changing
 *   them requires a support ticket.
 *
 *   Security — change password with live client-side rule feedback.
 *
 * Both tabs post to admin-handler.php. The handler validates and
 * redirects back here with a flash message. All SQL lives in
 * admin-queries.php. This page contains no SQL and no inline JS.
 * The asset base travels to profile.js as a data-asset-base
 * attribute on the .profile-page container.
 *
 * @package FitPal
 * @version 3.3 — Version bump to match the CSRF consolidation in
 *                header.php v5.0 and includes/admin-csrf-token.php
 *                v1.0. No functional change: this page already reads
 *                $csrfToken from header.php and never generated the
 *                token itself. (3.2: Removed the local CSRF block
 *                that wrote to the shared 'csrf_token' session key.
 *                The admin role's token is now generated in
 *                header.php under 'admin_csrf_token' and exposed as
 *                $csrfToken, so both forms — Personal Information
 *                and Change Password — now carry the admin-scoped
 *                value. Form field name stays 'csrf_token';
 *                admin-handler.php validates against the matching
 *                session key.)
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['administrator_id'])) {
    header('Location: sign-in.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../backend/database/admin-queries.php';

$adminId = (int)$_SESSION['administrator_id'];
$profile = getAdminProfile($database_connection, $adminId) ?: [];

$fullName = adminName($profile);
$initial  = adminInitial($profile);
$roleLabel = adminRoleLabel((string)($profile['role'] ?? 'support'));
$lastLogin = formatAdminDate((string)($profile['last_login'] ?? ''));

// $csrfToken is provided by header.php (admin_csrf_token).
?>

<div class="content profile-page" data-asset-base="<?php echo htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8'); ?>">
    <div class="container">

        <!-- ============================================
             PAGE TITLE HEADER
             ============================================ -->
        <div class="page-title-header">
            <div class="page-title-header-top">
                <div>
                    <h1 class="heading-2">My <span>Profile</span></h1>
                    <p class="text-muted">Manage your administrator account and security settings.</p>
                </div>
                <a href="dashboard.php" class="back-btn">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-left-line.svg" alt=""
                        class="back-btn-icon" width="18" height="18">
                    <span>Dashboard</span>
                </a>
            </div>
        </div>

        <!-- ============================================
             FLASH MESSAGES
             ============================================ -->
        <?php if (!empty($_SESSION['admin_success'])): ?>
        <div class="alert alert-success" role="alert">
            <?php echo htmlspecialchars($_SESSION['admin_success'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['admin_success']); ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['admin_error'])): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo htmlspecialchars($_SESSION['admin_error'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['admin_error']); ?>
        </div>
        <?php endif; ?>

        <!-- ============================================
             PROFILE HEADER CARD
             ============================================ -->
        <div class="profile-header-card">
            <div class="profile-avatar-large" aria-hidden="true">
                <?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?>
            </div>

            <div class="profile-header-info">
                <p class="profile-name">
                    <?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <div class="profile-meta-row">
                    <span class="profile-role-badge">
                        <?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <span class="profile-email">
                        <?php echo htmlspecialchars((string)($profile['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
                <p class="profile-last-login">
                    Last login: <?php echo htmlspecialchars($lastLogin, ENT_QUOTES, 'UTF-8'); ?>
                </p>
            </div>
        </div>

        <!-- ============================================
             TABS
             ============================================ -->
        <div class="profile-tabs" role="tablist">
            <button type="button" class="profile-tab active" data-tab="personal" role="tab" aria-selected="true">
                <img src="<?php echo $assetBase; ?>assets/images/icons/file-user-line.svg" alt=""
                    class="profile-tab-icon" width="16" height="16">
                <span>Personal Information</span>
            </button>
            <button type="button" class="profile-tab" data-tab="security" role="tab" aria-selected="false">
                <img src="<?php echo $assetBase; ?>assets/images/icons/password-hide.svg" alt=""
                    class="profile-tab-icon" width="16" height="16">
                <span>Security</span>
            </button>
        </div>

        <!-- ============================================
             TAB: PERSONAL INFORMATION
             ============================================ -->
        <div class="profile-tab-content active" id="tab-personal" role="tabpanel">
            <div class="profile-card">
                <div class="profile-card-header">
                    <h2>Personal Information</h2>
                    <span class="heading-hint">Email and role are locked — contact support to change them.</span>
                </div>

                <div class="profile-card-body">
                    <form method="POST" action="../backend/handlers/admin-handler.php" id="profileInfoForm" novalidate>
                        <input type="hidden" name="csrf_token"
                            value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="update_profile">
                        <input type="hidden" name="redirect_to" value="profile.php">

                        <div class="form-row">
                            <div class="form-group">
                                <label class="field-label" for="first_name">First Name</label>
                                <input type="text" id="first_name" name="first_name" class="form-control"
                                    value="<?php echo htmlspecialchars((string)($profile['first_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    autocomplete="given-name" required minlength="2">
                                <p class="form-error" id="firstNameError" role="alert"></p>
                            </div>

                            <div class="form-group">
                                <label class="field-label" for="last_name">Last Name</label>
                                <input type="text" id="last_name" name="last_name" class="form-control"
                                    value="<?php echo htmlspecialchars((string)($profile['last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    autocomplete="family-name" required minlength="2">
                                <p class="form-error" id="lastNameError" role="alert"></p>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="field-label" for="middle_name">
                                    Middle Name
                                    <span class="field-optional">(optional)</span>
                                </label>
                                <input type="text" id="middle_name" name="middle_name" class="form-control"
                                    value="<?php echo htmlspecialchars((string)($profile['middle_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    autocomplete="additional-name">
                            </div>

                            <div class="form-group">
                                <label class="field-label" for="contact_number">
                                    Contact Number
                                    <span class="field-optional">(optional)</span>
                                </label>
                                <input type="tel" id="contact_number" name="contact_number" class="form-control"
                                    placeholder="09XXXXXXXXX"
                                    value="<?php echo htmlspecialchars((string)($profile['contact_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    autocomplete="tel" inputmode="numeric" maxlength="11">
                                <p class="form-error" id="contactError" role="alert"></p>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="field-label" for="email_readonly">Email</label>
                                <input type="email" id="email_readonly" class="form-control"
                                    value="<?php echo htmlspecialchars((string)($profile['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    disabled>
                            </div>

                            <div class="form-group">
                                <label class="field-label" for="role_readonly">Role</label>
                                <input type="text" id="role_readonly" class="form-control"
                                    value="<?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?>" disabled>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="reset" class="btn btn-outline btn-sm" id="resetInfoBtn">Reset</button>
                            <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ============================================
             TAB: SECURITY
             ============================================ -->
        <div class="profile-tab-content" id="tab-security" role="tabpanel">
            <div class="profile-card">
                <div class="profile-card-header">
                    <h2>Change Password</h2>
                    <span class="heading-hint">8–20 characters, letters and numbers only.</span>
                </div>

                <div class="profile-card-body">
                    <form method="POST" action="../backend/handlers/admin-handler.php" id="passwordForm" novalidate>
                        <input type="hidden" name="csrf_token"
                            value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="change_password">
                        <input type="hidden" name="redirect_to" value="profile.php">

                        <div class="form-row">
                            <div class="form-group form-group-full">
                                <label class="field-label" for="current_password">Current Password</label>
                                <div class="password-wrapper">
                                    <input type="password" id="current_password" name="current_password"
                                        class="form-control" autocomplete="current-password" required>
                                    <button type="button" class="password-toggle"
                                        data-toggle-password="current_password" tabindex="-1"
                                        aria-label="Show current password">
                                        <img src="<?php echo $assetBase; ?>assets/images/icons/password-hide.svg" alt=""
                                            data-password-icon="current_password">
                                    </button>
                                </div>
                                <p class="form-error" id="currentPasswordError" role="alert"></p>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="field-label" for="new_password">New Password</label>
                                <div class="password-wrapper">
                                    <input type="password" id="new_password" name="new_password" class="form-control"
                                        autocomplete="new-password" required minlength="8" maxlength="20">
                                    <button type="button" class="password-toggle" data-toggle-password="new_password"
                                        tabindex="-1" aria-label="Show new password">
                                        <img src="<?php echo $assetBase; ?>assets/images/icons/password-hide.svg" alt=""
                                            data-password-icon="new_password">
                                    </button>
                                </div>
                                <p class="form-error" id="newPasswordError" role="alert"></p>
                            </div>

                            <div class="form-group">
                                <label class="field-label" for="confirm_password">Confirm New Password</label>
                                <div class="password-wrapper">
                                    <input type="password" id="confirm_password" name="confirm_password"
                                        class="form-control" autocomplete="new-password" required minlength="8"
                                        maxlength="20">
                                    <button type="button" class="password-toggle"
                                        data-toggle-password="confirm_password" tabindex="-1"
                                        aria-label="Show confirm password">
                                        <img src="<?php echo $assetBase; ?>assets/images/icons/password-hide.svg" alt=""
                                            data-password-icon="confirm_password">
                                    </button>
                                </div>
                                <p class="form-error" id="confirmPasswordError" role="alert"></p>
                            </div>
                        </div>

                        <ul class="password-rules" id="passwordRules" aria-live="polite">
                            <li class="password-rule" data-rule="length">
                                <span class="password-rule-icon" aria-hidden="true"></span>
                                <span>8–20 characters</span>
                            </li>
                            <li class="password-rule" data-rule="alphanumeric">
                                <span class="password-rule-icon" aria-hidden="true"></span>
                                <span>Letters and numbers only</span>
                            </li>
                            <li class="password-rule" data-rule="has-letter">
                                <span class="password-rule-icon" aria-hidden="true"></span>
                                <span>Contains at least one letter</span>
                            </li>
                            <li class="password-rule" data-rule="has-number">
                                <span class="password-rule-icon" aria-hidden="true"></span>
                                <span>Contains at least one number</span>
                            </li>
                        </ul>

                        <div class="form-actions">
                            <button type="reset" class="btn btn-outline btn-sm">Clear</button>
                            <button type="submit" class="btn btn-primary btn-sm">Change Password</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="../assets/ui/js/profile.js" defer></script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>