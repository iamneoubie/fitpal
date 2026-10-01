<?php
/**
 * FitPal Rider Profile Page
 *
 * Layout mirrors customer/pages/profile.php so both roles feel like
 * the same product:
 *   - page-title-header with back button + page title
 *   - profile-header-card (avatar + name + role + status + edit)
 *   - profile-tabs (Personal | Vehicle | Address)
 *   - profile-card with card-header + card-body
 *   - edit-in-place contact number
 *   - vehicle snapshot (read-only)
 *   - address snapshot (read-only, contact support to change)
 *
 * ---------------------------------------------------------------------
 * THE BALANCE THIS PAGE RENDERS
 * -----------------------------
 * The header card has no balance field of its own — the rider's
 * balance is shown on the dashboard and on the earnings page. The
 * two places a rider sees their balance both read from
 * financial_account.balance, the same account the shared
 * order-transaction layer debits and credits:
 *
 *   - Accept-time debit      → `payment` transaction, trigger
 *                              debits the balance by subtotal plus
 *                              delivery fee. A rider at a 0 balance
 *                              goes negative on purpose.
 *   - Delivery credit        → `deposit` transaction, trigger
 *                              credits the balance by the delivery
 *                              fee.
 *   - Withdrawal request     → `withdrawal` transaction with status
 *                              'pending'. The trigger does not move
 *                              the balance until an admin completes
 *                              the row.
 *
 * This page never writes to the balance. The value the other two
 * pages show is whatever the triggers last computed.
 *
 * ---------------------------------------------------------------------
 * Page flow
 * ---------------------------------------------------------------------
 *   1. The rider opens the page in view mode. The contact field is
 *      disabled, the Save/Cancel row is hidden, the Edit Profile
 *      button is visible, and the avatar edit button is hidden.
 *
 *   2. Clicking "Edit Profile" opens a confirmation modal. Confirming
 *      enters edit mode: the contact field enables, the avatar edit
 *      button appears, and the header card's right side swaps from
 *      the Edit button to Save Changes + Cancel.
 *
 *   3. Picking a file only produces a LOCAL PREVIEW. The upload is
 *      deferred until the rider presses Save Changes. Cancel reverts
 *      the preview to the server-rendered picture.
 *
 *   4. Save runs a single pipeline:
 *        a. POST action=update_profile to rider-handler.php.
 *        b. If a picture is pending, POST action=upload_picture to
 *           the same endpoint with the file as FormData.
 *      Both fetches use literal URLs so no DOM node is ever read to
 *      compute a request target.
 *
 *   5. Leaving the page while in edit mode with pending changes
 *      triggers an unsaved-changes modal with "Keep Editing" and
 *      "Save Changes".
 *
 * Save button contract
 * --------------------
 * The Save button is type="button", not type="submit". It is bound
 * in JS to the same handler the form's submit event runs. This page
 * never performs a native form submission. That removes the
 * possibility of the browser navigating away from the page to a URL
 * that was computed from a DOM property.
 *
 * @package FitPal
 * @version 4.2 — Docblock records the shared order-transaction layer
 *                as the writer of the ledger the rider's balance is
 *                read from on the dashboard and earnings pages. No
 *                markup, edit-mode lifecycle, address tab, or JS
 *                config change from the previous revision.
 *
 *                (4.1: Save button is now type="button". 4.0:
 *                unified rider profile with customer profile.)
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

// Header defines $assetBase and outputs <head> + <header>.
require_once __DIR__ . '/../includes/header.php';

$riderId = (int)$_SESSION['delivery_rider_id'];

$profile = getRiderProfile($database_connection, $riderId) ?: [];
$address = getRiderDefaultAddress($database_connection, $riderId);

$firstName  = (string)($profile['first_name'] ?? '');
$middleName = (string)($profile['middle_name'] ?? '');
$lastName   = (string)($profile['last_name'] ?? '');
$email      = (string)($profile['email'] ?? '');
$contact    = (string)($profile['contact_number'] ?? '');
$username   = (string)($profile['username'] ?? '');
$vehicle    = (string)($profile['vehicle_type'] ?? '');
$plate      = (string)($profile['vehicle_plate'] ?? '');
$status     = (string)($profile['verification_status'] ?? 'pending');
$rating     = (float)($profile['average_rating'] ?? 0);
$deliveries = (int)($profile['total_deliveries'] ?? 0);
$profilePic = (string)($profile['profile_picture'] ?? '');
$dateJoined = (string)($profile['date_created'] ?? '');

$fullName = trim($firstName . ' ' . $lastName);
$initial  = strtoupper(substr($firstName !== '' ? $firstName : 'R', 0, 1));

$statusLabel = match ($status) {
    'verified'  => 'Verified',
    'pending'   => 'Pending Verification',
    'denied'    => 'Denied',
    'suspended' => 'Suspended',
    default     => ucfirst($status),
};

$statusClass = match ($status) {
    'verified'  => 'badge-success',
    'pending'   => 'badge-warning',
    'denied'    => 'badge-danger',
    'suspended' => 'badge-secondary',
    default     => 'badge-secondary',
};

/*
 * Profile picture URL.
 *
 * The DB stores a project-root-relative path such as
 *   shared/uploads/rider-profiles/rider_12_profile_abc.jpg
 *
 * $assetBase from the header ends with 'shared/', so the URL to that
 * file is <projectRootUrl> + shared/uploads/... . The project root URL
 * is produced by trimming the trailing 'shared/' from $assetBase. All
 * of this is guarded so a missing $assetBase can never crash the page.
 */
$profilePicUrl = '';

if ($profilePic !== '' && is_string($assetBase) && $assetBase !== '') {
    $projectRootUrl = preg_replace('#shared/$#', '', $assetBase);
    if (!is_string($projectRootUrl)) {
        $projectRootUrl = '';
    }
    $profilePicUrl = $projectRootUrl . $profilePic;
}

// $csrfToken is provided by header.php (rider_csrf_token).

/**
 * "Joined March 2026" style caption. Returns an em-dash if the
 * timestamp cannot be parsed.
 */
function formatJoinedDate(string $date): string
{
    $ts = strtotime($date);
    return $ts !== false ? date('F Y', $ts) : '—';
}

/**
 * Build the address display string from the rider address row.
 * delivery_rider_address.label was dropped in the current schema,
 * so no label is prefixed here.
 */
function formatRiderAddress(array $address): string
{
    $parts = array_filter([
        $address['block']       ?? '',
        $address['barangay']    ?? '',
        $address['city']        ?? '',
        $address['province']    ?? '',
        $address['region']      ?? '',
        $address['postal_code'] ?? '',
        $address['country']     ?? '',
    ]);
    return implode(', ', $parts);
}

$addressText = $address ? formatRiderAddress($address) : '';

$vehicleLabel = $vehicle !== '' ? ucfirst($vehicle) : 'Not recorded';
$plateLabel   = $plate !== '' ? $plate : 'No plate recorded';
?>

<div class="content profile-page">
    <div class="container">

        <!-- ============================================
             PAGE TITLE HEADER
             ============================================ -->
        <div class="page-title-header">
            <div class="page-title-header-top">
                <a href="dashboard.php" class="back-btn" id="profileBackBtn" data-fallback-href="dashboard.php">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-left-line.svg" alt="Back"
                        class="back-btn-icon" width="20" height="20">
                    <span>Back to Dashboard</span>
                </a>
                <h1>My Profile</h1>
            </div>
        </div>

        <!-- ============================================
             FLASH MESSAGES
             ============================================ -->
        <?php if (isset($_SESSION['rider_success'])): ?>
        <div class="alert alert-success" role="alert">
            <?php echo htmlspecialchars($_SESSION['rider_success'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['rider_success']); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['rider_error'])): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo htmlspecialchars($_SESSION['rider_error'], ENT_QUOTES, 'UTF-8'); ?>
            <?php unset($_SESSION['rider_error']); ?>
        </div>
        <?php endif; ?>

        <!-- ============================================
             PROFILE HEADER CARD
             ============================================ -->
        <div class="profile-header-card" id="profileHeaderCard">
            <div class="profile-header-left">

                <!-- Avatar wrap: image or initial + edit button -->
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
                        <?php echo htmlspecialchars($fullName ?: 'Rider', ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <span class="profile-role-badge">
                        Rider<?php if ($username !== ''): ?> &middot;
                        @<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
                    </span>
                    <span class="profile-meta-line">
                        <span class="badge <?php echo $statusClass; ?>">
                            <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <?php if ($dateJoined !== ''): ?>
                        <span class="profile-joined">
                            Joined <?php echo htmlspecialchars(formatJoinedDate($dateJoined), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>

            <div class="profile-header-right">

                <!-- View state: Edit Profile -->
                <button type="button" id="editProfileBtn" class="btn btn-edit">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/edit.svg" alt="" class="btn-icon">
                    <span>Edit Profile</span>
                </button>

                <!-- Edit state: Cancel + Save Changes. -->
                <div class="profile-edit-actions is-hidden" id="profileEditActions">
                    <button type="button" id="cancelEditBtn" class="btn btn-cancel">Cancel</button>
                    <button type="button" id="saveProfileBtn" class="btn btn-primary">
                        Save Changes
                    </button>
                </div>

            </div>
        </div>

        <!-- ============================================
             TABS
             ============================================ -->
        <div class="profile-tabs" role="tablist">
            <button type="button" class="profile-tab active" data-tab="personal" role="tab" aria-selected="true">
                Personal Information
            </button>
            <button type="button" class="profile-tab" data-tab="vehicle" role="tab" aria-selected="false">
                Vehicle
            </button>
            <button type="button" class="profile-tab" data-tab="address" role="tab" aria-selected="false">
                Address
            </button>
        </div>

        <!-- ============================================
             TAB: PERSONAL INFORMATION
             ============================================ -->
        <div class="profile-tab-content active" id="tab-personal">
            <div class="profile-card">
                <div class="card-header">
                    <h3>Personal Information</h3>
                </div>
                <div class="card-body">
                    <form id="riderProfileForm" method="POST" action="../backend/handlers/rider-handler.php"
                        enctype="multipart/form-data" novalidate>
                        <input type="hidden" name="csrf_token"
                            value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="update_profile">

                        <div class="form-row">
                            <div class="form-group">
                                <label for="first_name" class="field-label">First Name</label>
                                <input type="text" id="first_name" name="first_name" class="form-control"
                                    value="<?php echo htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'); ?>" disabled>
                            </div>

                            <div class="form-group">
                                <label for="middle_name" class="field-label">Middle Name</label>
                                <input type="text" id="middle_name" name="middle_name" class="form-control"
                                    value="<?php echo htmlspecialchars($middleName, ENT_QUOTES, 'UTF-8'); ?>" disabled>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="last_name" class="field-label">Last Name</label>
                                <input type="text" id="last_name" name="last_name" class="form-control"
                                    value="<?php echo htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8'); ?>" disabled>
                            </div>

                            <div class="form-group">
                                <label for="username" class="field-label">Username</label>
                                <input type="text" id="username" class="form-control"
                                    value="<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>" disabled>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="email" class="field-label">Email</label>
                                <input type="email" id="email" class="form-control"
                                    value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" disabled>
                            </div>

                            <div class="form-group">
                                <label for="contact_number" class="field-label">Contact Number</label>
                                <input type="tel" id="contact_number" name="contact_number" class="form-control"
                                    value="<?php echo htmlspecialchars($contact, ENT_QUOTES, 'UTF-8'); ?>" disabled
                                    placeholder="09XXXXXXXXX" maxlength="11" inputmode="numeric">
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ============================================
             TAB: VEHICLE
             ============================================ -->
        <div class="profile-tab-content" id="tab-vehicle">
            <div class="profile-card">
                <div class="card-header">
                    <h3>Vehicle Information</h3>
                </div>
                <div class="card-body">
                    <div class="info-grid">
                        <div class="info-row">
                            <span class="info-label">Vehicle Type</span>
                            <span class="info-value">
                                <?php echo htmlspecialchars($vehicleLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Plate Number</span>
                            <span class="info-value">
                                <?php echo htmlspecialchars($plateLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                    </div>

                    <p class="info-note">
                        Vehicle information cannot be edited directly from this page.
                        Contact support to make changes.
                    </p>
                </div>
            </div>
        </div>

        <!-- ============================================
             TAB: ADDRESS
             ============================================ -->
        <div class="profile-tab-content" id="tab-address">
            <div class="profile-card">
                <div class="card-header">
                    <h3>Primary Address</h3>
                </div>
                <div class="card-body">
                    <?php if ($address): ?>
                    <div class="address-item default">
                        <div class="address-item-header">
                            <div class="address-item-label">
                                <span class="badge badge-primary">Default</span>
                                <span class="address-label">Primary Address</span>
                            </div>
                        </div>
                        <div class="address-item-body">
                            <p><?php echo htmlspecialchars($addressText, ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                    </div>
                    <p class="info-note">
                        To change your address, please contact support.
                    </p>
                    <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/location-fill.svg"
                                alt="No addresses">
                        </div>
                        <p class="empty-title">No address on file yet</p>
                        <p class="empty-text">Contact support to add your primary address.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================
     EDIT-CONFIRM MODAL
     ============================================ -->
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

<!-- ============================================
     UNSAVED-CHANGES MODAL
     ============================================ -->
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
window.FITPAL_CSRF_TOKEN = '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>';

window.FITPAL_RIDER_PROFILE = {
    csrfToken: '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>',
    assetBase: '<?php echo $assetBase; ?>',
    hasPicture: <?php echo $profilePicUrl !== '' ? 'true' : 'false'; ?>,
    initialAvatarSrc: '<?php echo htmlspecialchars($profilePicUrl, ENT_QUOTES, 'UTF-8'); ?>',
    updateEndpoint: '../backend/handlers/rider-handler.php',
    uploadEndpoint: '../backend/handlers/rider-handler.php'
};
</script>
<script src="../assets/ui/js/profile.js" defer></script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>