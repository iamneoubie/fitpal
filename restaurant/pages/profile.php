<?php
/**
 * FitPal Restaurant Profile Page
 *
 * Layouts:
 *   Owner        : Personal | Business | Branch
 *   Branch/Staff : Personal | Branch
 *
 * Contact number is editable for everyone. Business info and branch
 * address are editable only for owners. Names, email, username, and
 * business name are read-only — changing them requires a support
 * request.
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['restaurant_account_id'])) {
    header('Location: sign-in.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../backend/database/restaurant-queries.php';

$accountId = (int)$_SESSION['restaurant_account_id'];
$profile = getRestaurantAccountProfile($database_connection, $accountId) ?: [];

$accountName = trim(
    ($profile['first_name'] ?? '') . ' ' .
    (!empty($profile['middle_name']) ? $profile['middle_name'] . ' ' : '') .
    ($profile['last_name'] ?? '')
);
$accountInitial = strtoupper(substr((string)($profile['first_name'] ?? 'R'), 0, 1));
$role = (string)($profile['role'] ?? 'owner');

$roleLabel = match ($role) {
    'owner'   => 'Owner',
    'partner' => 'Partner',
    'manager' => 'Branch Manager',
    'staff'   => 'Staff',
    'cashier' => 'Cashier',
    'kitchen' => 'Kitchen',
    default   => ucfirst($role),
};

$isOwner = in_array($role, ['owner', 'partner'], true);

$verification = (string)($profile['verification_status'] ?? 'pending');
$verificationClass = match ($verification) {
    'verified'  => 'badge-success',
    'pending'   => 'badge-warning',
    'denied'    => 'badge-danger',
    'suspended' => 'badge-secondary',
    default     => 'badge-secondary',
};

$verificationLabel = match ($verification) {
    'verified'  => 'Verified',
    'pending'   => 'Pending Verification',
    'denied'    => 'Denied',
    'suspended' => 'Suspended',
    default     => ucfirst($verification),
};

$branchId  = !empty($profile['branch_id']) ? (int)$profile['branch_id'] : 0;
$hasBranch = $branchId > 0 && !empty($profile['branch_name']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function formatRestaurantAddress(array $p): string
{
    $parts = array_filter([
        $p['block']       ?? '',
        $p['barangay']    ?? '',
        $p['city']        ?? '',
        $p['province']    ?? '',
        $p['region']      ?? '',
        $p['postal_code'] ?? '',
        $p['country']     ?? '',
    ]);
    return implode(', ', $parts) ?: '—';
}
?>

<div class="content restaurant-profile-page">
    <div class="container">

        <div class="page-title-header">
            <div class="page-title-header-top">
                <a href="dashboard.php" class="back-btn">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-left-line.svg" alt="Back"
                        class="back-btn-icon" width="20" height="20">
                    <span>Back to Dashboard</span>
                </a>
                <h1>My Profile</h1>
            </div>
        </div>

        <?php if (!empty($_SESSION['restaurant_success'])): ?>
        <div class="alert alert-success" role="alert">
            <?php echo htmlspecialchars($_SESSION['restaurant_success'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['restaurant_success']); ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['restaurant_error'])): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo htmlspecialchars($_SESSION['restaurant_error'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['restaurant_error']); ?>
        </div>
        <?php endif; ?>

        <div class="profile-header-card">
            <div class="profile-header-left">
                <div class="profile-avatar">
                    <span class="profile-avatar-initial">
                        <?php echo htmlspecialchars($accountInitial, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
                <div class="profile-name-role">
                    <p class="profile-full-name">
                        <?php echo htmlspecialchars($accountName !== '' ? $accountName : 'Account', ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <span
                        class="profile-role-badge"><?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="profile-meta-line">
                        <span class="badge <?php echo $verificationClass; ?>">
                            <?php echo htmlspecialchars($verificationLabel, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <span class="profile-joined">
                            <?php echo htmlspecialchars((string)($profile['business_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </span>
                </div>
            </div>
        </div>

        <div class="profile-tabs" role="tablist">
            <button type="button" class="profile-tab active" data-tab="personal" role="tab" aria-selected="true">
                Personal
            </button>
            <?php if ($isOwner): ?>
            <button type="button" class="profile-tab" data-tab="business" role="tab" aria-selected="false">
                Business
            </button>
            <?php endif; ?>
            <button type="button" class="profile-tab" data-tab="branch" role="tab" aria-selected="false">
                <?php echo $hasBranch ? 'Branch' : 'Address'; ?>
            </button>
        </div>

        <!-- ============================================
             TAB: PERSONAL
             ============================================ -->
        <div class="profile-tab-content active" id="tab-personal">
            <div class="profile-card">
                <div class="card-header">
                    <h3>Personal Information</h3>
                </div>
                <div class="card-body">

                    <form class="profile-form" id="contactForm">
                        <input type="hidden" name="csrf_token"
                            value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="update_contact">

                        <div class="form-row">
                            <div class="form-group">
                                <label class="field-label">First Name</label>
                                <input type="text" class="form-control" disabled
                                    value="<?php echo htmlspecialchars((string)($profile['first_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="form-group">
                                <label class="field-label">Middle Name</label>
                                <input type="text" class="form-control" disabled
                                    value="<?php echo htmlspecialchars((string)($profile['middle_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="form-group">
                                <label class="field-label">Last Name</label>
                                <input type="text" class="form-control" disabled
                                    value="<?php echo htmlspecialchars((string)($profile['last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="field-label">Email</label>
                                <input type="email" class="form-control" disabled
                                    value="<?php echo htmlspecialchars((string)($profile['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="form-group">
                                <label class="field-label">Username</label>
                                <input type="text" class="form-control" disabled
                                    value="<?php echo htmlspecialchars((string)($profile['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="field-label" for="contact_number">Contact Number</label>
                                <input type="tel" id="contact_number" name="contact_number" class="form-control"
                                    placeholder="09XXXXXXXXX" inputmode="numeric" maxlength="11"
                                    value="<?php echo htmlspecialchars((string)($profile['contact_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="form-error" id="contactError"></div>
                            </div>
                            <div class="form-group">
                                <label class="field-label">Role</label>
                                <input type="text" class="form-control" disabled
                                    value="<?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>

                        <div class="profile-actions">
                            <button type="submit" class="btn btn-primary">
                                <span>Save Contact</span>
                            </button>
                        </div>
                    </form>

                    <p class="info-note">
                        Names, email, and username cannot be changed from this page. Contact support for updates.
                    </p>
                </div>
            </div>

            <div class="profile-card">
                <div class="card-header">
                    <h3>Change Password</h3>
                </div>
                <div class="card-body">
                    <form class="profile-form" id="passwordForm">
                        <input type="hidden" name="csrf_token"
                            value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="change_password">

                        <div class="form-group">
                            <label class="field-label" for="current_password">Current Password</label>
                            <div class="password-wrapper">
                                <input type="password" id="current_password" name="current_password"
                                    class="form-control" autocomplete="current-password" required>
                                <button type="button" class="password-toggle" data-toggle-pw="current_password"
                                    tabindex="-1" aria-label="Toggle password visibility">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/password-hide.svg" alt="">
                                </button>
                            </div>
                            <div class="form-error" id="currentPasswordError"></div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="field-label" for="new_password">New Password</label>
                                <div class="password-wrapper">
                                    <input type="password" id="new_password" name="new_password" class="form-control"
                                        autocomplete="new-password" maxlength="20" required>
                                    <button type="button" class="password-toggle" data-toggle-pw="new_password"
                                        tabindex="-1" aria-label="Toggle password visibility">
                                        <img src="<?php echo $assetBase; ?>assets/images/icons/password-hide.svg"
                                            alt="">
                                    </button>
                                </div>
                                <div class="form-hint">8–20 characters, letters and numbers only</div>
                                <div class="form-error" id="newPasswordError"></div>
                            </div>
                            <div class="form-group">
                                <label class="field-label" for="confirm_password">Confirm New Password</label>
                                <div class="password-wrapper">
                                    <input type="password" id="confirm_password" name="confirm_password"
                                        class="form-control" autocomplete="new-password" maxlength="20" required>
                                    <button type="button" class="password-toggle" data-toggle-pw="confirm_password"
                                        tabindex="-1" aria-label="Toggle password visibility">
                                        <img src="<?php echo $assetBase; ?>assets/images/icons/password-hide.svg"
                                            alt="">
                                    </button>
                                </div>
                                <div class="form-error" id="confirmPasswordError"></div>
                            </div>
                        </div>

                        <div class="profile-actions">
                            <button type="submit" class="btn btn-primary">
                                <span>Change Password</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ============================================
             TAB: BUSINESS (owner only)
             ============================================ -->
        <?php if ($isOwner): ?>
        <div class="profile-tab-content" id="tab-business">
            <div class="profile-card">
                <div class="card-header">
                    <h3>Business Information</h3>
                </div>
                <div class="card-body">
                    <form class="profile-form" id="businessForm">
                        <input type="hidden" name="csrf_token"
                            value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="update_business">

                        <div class="form-group">
                            <label class="field-label">Business Name</label>
                            <input type="text" class="form-control" disabled
                                value="<?php echo htmlspecialchars((string)($profile['business_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-hint">Contact support to change the business name.</div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="field-label" for="cuisine_type">Cuisine Type</label>
                                <input type="text" id="cuisine_type" name="cuisine_type" class="form-control"
                                    maxlength="50"
                                    value="<?php echo htmlspecialchars((string)($profile['cuisine_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="form-error" id="cuisineTypeError"></div>
                            </div>
                            <div class="form-group">
                                <label class="field-label" for="dietary_tags">Dietary Tags</label>
                                <input type="text" id="dietary_tags" name="dietary_tags" class="form-control"
                                    maxlength="200" placeholder="vegan,gluten_free,halal"
                                    value="<?php echo htmlspecialchars((string)($profile['dietary_tags'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="form-hint">Comma-separated</div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="field-label" for="description">Description</label>
                            <textarea id="description" name="description" class="form-control" maxlength="2000"
                                rows="5"><?php echo htmlspecialchars((string)($profile['restaurant_description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <div class="form-error" id="descriptionError"></div>
                        </div>

                        <div class="profile-actions">
                            <button type="submit" class="btn btn-primary">
                                <span>Save Business Info</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================
             TAB: BRANCH / ADDRESS
             ============================================ -->
        <div class="profile-tab-content" id="tab-branch">
            <div class="profile-card">
                <div class="card-header">
                    <h3><?php echo $hasBranch ? 'Branch Information' : 'Primary Address'; ?></h3>
                </div>
                <div class="card-body">
                    <?php if ($hasBranch): ?>
                    <div class="info-grid">
                        <div class="info-row">
                            <span class="info-label">Branch Name</span>
                            <span class="info-value">
                                <?php echo htmlspecialchars((string)$profile['branch_name'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Branch Code</span>
                            <span class="info-value">
                                <?php echo htmlspecialchars((string)$profile['branch_code'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                    </div>

                    <?php if ($isOwner): ?>
                    <form class="profile-form" id="branchForm">
                        <input type="hidden" name="csrf_token"
                            value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="update_branch">

                        <div class="form-row">
                            <div class="form-group">
                                <label class="field-label" for="block">Block / Street / Unit</label>
                                <input type="text" id="block" name="block" class="form-control"
                                    value="<?php echo htmlspecialchars((string)($profile['block'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="form-error" id="blockError"></div>
                            </div>
                            <div class="form-group">
                                <label class="field-label" for="barangay">Barangay</label>
                                <input type="text" id="barangay" name="barangay" class="form-control"
                                    value="<?php echo htmlspecialchars((string)($profile['barangay'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="field-label" for="city">City</label>
                                <input type="text" id="city" name="city" class="form-control"
                                    value="<?php echo htmlspecialchars((string)($profile['city'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="form-error" id="cityError"></div>
                            </div>
                            <div class="form-group">
                                <label class="field-label" for="province">Province</label>
                                <input type="text" id="province" name="province" class="form-control"
                                    value="<?php echo htmlspecialchars((string)($profile['province'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="field-label" for="region">Region</label>
                                <input type="text" id="region" name="region" class="form-control"
                                    value="<?php echo htmlspecialchars((string)($profile['region'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="form-group">
                                <label class="field-label" for="postal_code">Postal Code</label>
                                <input type="text" id="postal_code" name="postal_code" class="form-control"
                                    maxlength="10" inputmode="numeric"
                                    value="<?php echo htmlspecialchars((string)($profile['postal_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="form-error" id="postalCodeError"></div>
                            </div>
                        </div>

                        <div class="profile-actions">
                            <button type="submit" class="btn btn-primary">
                                <span>Save Address</span>
                            </button>
                        </div>
                    </form>
                    <?php else: ?>
                    <div class="address-item default">
                        <p class="address-text">
                            <?php echo htmlspecialchars(formatRestaurantAddress($profile), ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                    </div>
                    <p class="info-note">
                        To change the branch address, contact your owner.
                    </p>
                    <?php endif; ?>

                    <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/location-fill.svg" alt="No branch">
                        </div>
                        <p class="empty-title">No branch on file yet</p>
                        <p class="empty-text">
                            <?php if ($isOwner): ?>
                            Add a branch to start managing its menu.
                            <?php else: ?>
                            Contact your owner to assign you to a branch.
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
window.FITPAL_RESTAURANT_PROFILE = {
    csrfToken: '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>',
    assetBase: '<?php echo $assetBase; ?>',
    scope: '<?php echo htmlspecialchars((string)($_SESSION['restaurant_scope'] ?? 'owner'), ENT_QUOTES, 'UTF-8'); ?>'
};
</script>
<script src="../assets/ui/js/profile.js" defer></script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>