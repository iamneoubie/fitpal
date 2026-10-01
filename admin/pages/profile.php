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
 * Edit lifecycle
 * --------------
 * The page opens in VIEW mode: every Personal Information field is
 * disabled, the Save/Cancel row is hidden, the Edit Profile button
 * is visible, and the avatar edit button is hidden.
 *
 * Pressing Edit Profile opens a confirmation modal. Confirming enters
 * EDIT mode: fields enable, the avatar edit button appears, and the
 * header card's right side swaps from the Edit button to a
 * Save Changes + Cancel row.
 *
 * The Save button is type="button", NOT type="submit". Its click
 * handler runs the save pipeline in profile.js:
 *   1. POST action=update_profile for the text fields.
 *   2. POST action=upload_picture (separate request) when a new
 *      picture is pending.
 *   3. Reload the page on success.
 *
 * Cancel reverts the form fields and the avatar to the values the
 * server rendered and exits edit mode without a reload.
 *
 * Leaving the page (via the back button or a header nav link) while
 * in edit mode with pending changes triggers an unsaved-changes
 * modal with "Keep Editing" and "Save Changes".
 *
 * Save-failure banner
 * -------------------
 * The page has no toast utility. When the save pipeline fails — for
 * example, because the handler endpoint returns a non-JSON 404 from
 * the development server — the pipeline writes the error message
 * into the .js-save-banner element below the header card. That
 * element is hidden while empty and shown as an alert when it has
 * content. This replaces the previous silent-reset behavior where
 * the Save button simply re-enabled itself with no feedback.
 *
 * Security tab
 * ------------
 * The password form uses a native submit and redirect with a flash,
 * because a password change is a discrete operation that does not
 * share state with the profile picture upload. It is intentionally
 * outside the edit-mode lifecycle.
 *
 * @package FitPal
 * @version 4.1 — Adds a .js-save-banner region between the header
 *                card and the tabs. profile.js writes a visible
 *                error into it when the save pipeline fails, so a
 *                404 or non-JSON response from the handler is no
 *                longer silent. Markup and behavior are otherwise
 *                identical to 4.0.
 *
 *                (4.0: Adds the edit-mode lifecycle, save/cancel in
 *                the header card, the edit-confirm modal, the
 *                unsaved-changes modal, and the deferred picture
 *                upload pipeline, mirroring the customer role.
 *                3.3: Version bump to match the CSRF consolidation
 *                in header.php v5.0.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('admin');

if (empty($_SESSION['administrator_id'])) {
    header('Location: sign-in.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../backend/database/admin-queries.php';

$adminId = (int)$_SESSION['administrator_id'];
$profile = getAdminProfile($database_connection, $adminId) ?: [];

$fullName  = adminName($profile);
$initial   = adminInitial($profile);
$roleLabel = adminRoleLabel((string)($profile['role'] ?? 'support'));
$lastLogin = formatAdminDate((string)($profile['last_login'] ?? ''));

// Resolve the profile picture URL.
$profilePicPath = (string)($profile['profile_picture'] ?? '');
$profilePicUrl  = '';

if ($profilePicPath !== '' && is_string($assetBase) && $assetBase !== '') {
    $projectRootUrl = preg_replace('#shared/$#', '', $assetBase);
    if (is_string($projectRootUrl)) {
        $profilePicUrl = $projectRootUrl . $profilePicPath;
    }
}

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

        <!-- ============================================
             SAVE-FAILURE BANNER
             Written by profile.js when the save pipeline fails.
             Empty by default; the CSS hides it while empty.
             ============================================ -->
        <div class="alert alert-danger js-save-banner" id="saveBanner" role="alert" hidden></div>

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
                    <form id="profileForm" method="POST" action="../backend/handlers/admin-handler.php" novalidate>
                        <input type="hidden" name="csrf_token"
                            value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="update_profile">
                        <input type="hidden" name="redirect_to" value="profile.php">

                        <div class="form-row">
                            <div class="form-group">
                                <label class="field-label" for="first_name">First Name</label>
                                <input type="text" id="first_name" name="first_name" class="form-control"
                                    value="<?php echo htmlspecialchars((string)($profile['first_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    autocomplete="given-name" disabled>
                                <p class="form-error" id="firstNameError" role="alert"></p>
                            </div>

                            <div class="form-group">
                                <label class="field-label" for="last_name">Last Name</label>
                                <input type="text" id="last_name" name="last_name" class="form-control"
                                    value="<?php echo htmlspecialchars((string)($profile['last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    autocomplete="family-name" disabled>
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
                                    autocomplete="additional-name" disabled>
                            </div>

                            <div class="form-group">
                                <label class="field-label" for="contact_number">
                                    Contact Number
                                    <span class="field-optional">(optional)</span>
                                </label>
                                <input type="tel" id="contact_number" name="contact_number" class="form-control"
                                    placeholder="09XXXXXXXXX"
                                    value="<?php echo htmlspecialchars((string)($profile['contact_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    autocomplete="tel" inputmode="numeric" maxlength="11" disabled>
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

<!-- ============================================================
     EDIT-CONFIRM MODAL
     ============================================================ -->
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

<!-- ============================================================
     UNSAVED-CHANGES MODAL
     ============================================================ -->
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

<script>
window.FITPAL_ADMIN_PROFILE = {
    csrfToken: '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>',
    assetBase: '<?php echo htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8'); ?>',
    updateEndpoint: '../backend/handlers/admin-handler.php',
    uploadEndpoint: '../backend/handlers/admin-handler.php',
    initialAvatarSrc: '<?php echo htmlspecialchars($profilePicUrl, ENT_QUOTES, 'UTF-8'); ?>'
};
</script>
<script src="../assets/ui/js/profile.js" defer></script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>