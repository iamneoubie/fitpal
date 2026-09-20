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
 *   - logout row
 *
 * No inline SQL. All reads go through rider-queries.php.
 * $assetBase is defined by rider/includes/header.php, so the header
 * is required before any code that depends on it.
 *
 * @package FitPal
 * @version 3.0 — Full rebuild. Removed the broken vehicle card and
 *                malformed tab structure. Three tabs now: Personal,
 *                Vehicle, Address. Everything the page renders is
 *                a class defined in rider/assets/css/profile.css.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['delivery_rider_id'])) {
    header('Location: sign-in.php');
    exit;
}

require_once __DIR__ . '/../backend/database/rider-connect.php';
require_once __DIR__ . '/../backend/database/rider-queries.php';

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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

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
                <a href="dashboard.php" class="back-btn">
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
        <div class="profile-header-card">
            <div class="profile-header-left">
                <div class="profile-avatar-wrap">
                    <?php if ($profilePicUrl !== ''): ?>
                    <img src="<?php echo htmlspecialchars($profilePicUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile"
                        id="profileAvatarImg" class="profile-avatar-image"
                        onerror="this.onerror=null; this.style.display='none'; var el=document.getElementById('profileAvatarInitial'); if(el){el.style.display='flex';}">
                    <span class="profile-avatar-placeholder" id="profileAvatarInitial" style="display: none;">
                        <?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <?php else: ?>
                    <span class="profile-avatar-placeholder" id="profileAvatarInitial" style="display: flex;">
                        <?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <img src="" alt="Profile" id="profileAvatarImg" class="profile-avatar-image" style="display: none;">
                    <?php endif; ?>

                    <button type="button" class="profile-avatar-edit" id="uploadPictureBtn"
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
                <div class="profile-stat">
                    <span class="profile-stat-value"><?php echo number_format($rating, 1); ?></span>
                    <span class="profile-stat-label">Rating</span>
                </div>
                <div class="profile-stat">
                    <span class="profile-stat-value"><?php echo number_format($deliveries); ?></span>
                    <span class="profile-stat-label">Deliveries</span>
                </div>
                <button type="button" id="editProfileBtn" class="btn-edit">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/edit.svg" alt="" class="btn-icon">
                    Edit Profile
                </button>
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
                    <form id="riderProfileForm" method="POST" action="../backend/handlers/rider-handler.php">
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

                        <div class="profile-actions is-hidden" id="profileActions">
                            <button type="button" id="cancelEditBtn" class="btn btn-cancel">Cancel</button>
                            <button type="submit" class="btn btn-primary" id="saveProfileBtn">Save Changes</button>
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

        <!-- ============================================
             LOGOUT
             ============================================ -->
        <div class="logout-wrap">
            <a href="../backend/handlers/sign-out-handler.php" class="btn btn-cancel logout-btn">
                <img src="<?php echo $assetBase; ?>assets/images/icons/logoutsvg.svg" alt="" class="btn-icon" width="16"
                    height="16"
                    onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/cancel.svg'">
                <span>Sign Out</span>
            </a>
        </div>

    </div>
</div>

<script>
window.FITPAL_RIDER_PROFILE = {
    csrfToken: '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>',
    assetBase: '<?php echo $assetBase; ?>'
};
</script>
<script src="../assets/ui/js/profile.js" defer></script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>