<?php
/**
 * FitPal Customer Profile Page
 *
 * Shows personal information and delivery addresses, with a
 * multi-step address modal for add/edit, plus a profile picture
 * upload control in the header card.
 *
 * ---------------------------------------------------------------------
 * SCOPE RULES APPLIED
 * ---------------------------------------------------------------------
 *  - No SQL. getCustomerProfile() and getCustomerAddresses() come from
 *    the query layer.
 *  - No inline CSS. profile.css is loaded via the customer header's
 *    $pageCssMap.
 *  - No inline style attributes beyond the two `display:none` /
 *    `display:flex` toggles on the avatar image and its initial
 *    fallback. Every other show/hide is a class defined in profile.css.
 *  - formatAddress() and getAddressLabel() come from
 *    customer/backend/database/address-queries.php.
 *  - formatCurrency() comes from customer-queries.php.
 * ---------------------------------------------------------------------
 *
 * THE BALANCE THIS PAGE RENDERS
 * -----------------------------
 * The header card's "Balance:" line is read straight from
 * financial_account.balance via getCustomerProfile(). That account
 * is the same account the shared order-transaction layer debits and
 * credits:
 *
 *   - Wallet payments on order placement  → `payment` transaction,
 *                                            trigger debits the
 *                                            balance.
 *   - Refunds on cancellation (Wallet or
 *     Online)                             → `refund` transaction,
 *                                            trigger credits the
 *                                            balance.
 *   - Deposits                            → `deposit` transaction,
 *                                            trigger credits the
 *                                            balance.
 *
 * This page never writes to the balance. The number it shows is
 * whatever the triggers last computed, so a customer who cancels an
 * order and immediately reloads their profile sees the corrected
 * balance without any additional code here.
 *
 * Page flow
 * ---------
 *   1. The customer opens the page in view mode. Every field is
 *      disabled, the Save/Cancel row is hidden, the Edit Profile
 *      button is visible, and the avatar edit button is hidden.
 *
 *   2. Clicking "Edit Profile" opens a confirmation modal. Confirming
 *      enters edit mode: fields enable, the avatar edit button
 *      appears, and the header card's right side swaps from the Edit
 *      button to Save Changes + Cancel.
 *
 *   3. Picking a file only produces a LOCAL PREVIEW. The upload is
 *      deferred until the customer presses Save Changes. Cancel
 *      reverts the preview to the server-rendered picture.
 *
 *   4. Save is triggered by the Save Changes button, which is a
 *      type="button" control. Its click handler runs the save
 *      pipeline in profile.js: first a POST with action=update_profile
 *      for the text fields, then — when a new picture is pending —
 *      a separate POST with action=upload_picture. Cancel reverts
 *      the form fields and the avatar to the values the server
 *      rendered and exits edit mode without a reload.
 *
 *   5. Leaving the page while in edit mode with pending changes
 *      triggers an unsaved-changes modal with "Keep Editing" and
 *      "Save Changes".
 *
 * Save button contract
 * --------------------
 * The Save button is deliberately type="button", NOT type="submit".
 * The click handler in profile.js is the single entry point into the
 * save pipeline. No native form submission path exists, so the
 * browser can never POST to a URL derived from a DOM property.
 *
 * @package FitPal
 * @version 6.2 — Docblock records the shared order-transaction layer
 *                as the writer of the balance this page renders. No
 *                markup, form, or JS config change from the previous
 *                revision.
 *
 *                (6.1: Save button changed from type="submit"
 *                form="profileForm" to type="button". 6.0: profile
 *                picture change deferred behind Save Changes.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('customer');

if (!isset($_SESSION['customer_id']) || empty($_SESSION['customer_id'])) {
    header('Location: sign-in.php');
    exit;
}

// ---------------------------------------------------------------
// Return destination tracking
// ---------------------------------------------------------------
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

// $csrfToken is provided by header.php, stored under the customer
// role's own session key 'customer_csrf_token'.

$customerId = (int)$_SESSION['customer_id'];

$profileData = getCustomerProfile($database_connection, $customerId) ?: null;
$addresses   = getCustomerAddresses($database_connection, $customerId);

$hasAddresses = !empty($addresses);
$fullName     = trim(($profileData['first_name'] ?? '') . ' ' . ($profileData['last_name'] ?? ''));
$balance      = (float)($profileData['balance'] ?? 0);

// -----------------------------------------------------------------
// Resolve the profile picture URL.
// -----------------------------------------------------------------
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

        <!-- ============================================================
             PROFILE HEADER CARD
             ============================================================ -->
        <div class="profile-header-card" id="profileHeaderCard">

            <div class="profile-header-left">

                <!-- Avatar: image or initial, ring border, edit button. -->
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

        <!-- Tabs -->
        <div class="profile-tabs">
            <button type="button" id="tabBtnPersonal" class="profile-tab active" data-tab="personal">
                Personal Information
            </button>
            <button type="button" id="tabBtnAddresses" class="profile-tab" data-tab="addresses">
                Delivery Addresses
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

                        <div class="form-row">
                            <div class="form-group">
                                <label for="first_name" class="field-label">First Name</label>
                                <input type="text" id="first_name" name="first_name" class="form-control"
                                    value="<?php echo htmlspecialchars($profileData['first_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    disabled>
                            </div>

                            <div class="form-group">
                                <label for="middle_name" class="field-label">Middle Name</label>
                                <input type="text" id="middle_name" name="middle_name" class="form-control"
                                    value="<?php echo htmlspecialchars($profileData['middle_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    disabled>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="last_name" class="field-label">Last Name</label>
                                <input type="text" id="last_name" name="last_name" class="form-control"
                                    value="<?php echo htmlspecialchars($profileData['last_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    disabled>
                            </div>

                            <div class="form-group">
                                <label for="email" class="field-label">Email</label>
                                <input type="email" id="email" class="form-control"
                                    value="<?php echo htmlspecialchars($profileData['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    disabled>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="contact_number" class="field-label">Contact Number</label>
                                <input type="tel" id="contact_number" name="contact_number" class="form-control"
                                    value="<?php echo htmlspecialchars($profileData['contact_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="09XXXXXXXXX" maxlength="11" inputmode="numeric" disabled>
                            </div>

                            <div class="form-group">
                                <label for="birthdate" class="field-label">Birthdate</label>
                                <input type="date" id="birthdate" name="birthdate" class="form-control"
                                    value="<?php echo htmlspecialchars($profileData['birthdate'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    disabled>
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
                                </button>
                                <button type="button" class="btn btn-sm btn-delete delete-address"
                                    data-id="<?php echo (int)$addr['customer_address_id']; ?>"
                                    <?php echo $addr['is_default'] ? 'disabled' : ''; ?>>
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/trash.svg" alt="Delete"
                                        class="btn-icon">
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
                        <div class="empty-icon">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/location-fill.svg"
                                alt="No addresses">
                        </div>
                        <p class="text-muted">You don't have any delivery addresses yet.</p>
                        <p class="text-muted small">Add an address to start ordering!</p>
                    </div>
                    <?php endif; ?>
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

<!-- Address Modal (Multi-Step) -->
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

<!-- Delete Address Modal -->
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