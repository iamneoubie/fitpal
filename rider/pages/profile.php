<?php
/**
 * FitPal Rider Profile Page
 *
 * Profile info + picture upload + vehicle + address + logout.
 *
 * @package FitPal
 * @version 1.1 — Header include moved after all DB reads.
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

// Profile picture: stored as a path relative to project root
$profilePicUrl = '';
if ($profilePic !== '') {
    // assetBase ends with 'shared/'. Strip it to get back to project root.
    $projectRootUrl = preg_replace('#shared/$#', '', $assetBase);
    $profilePicUrl  = $projectRootUrl . $profilePic;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function formatJoinedDate(string $date): string
{
    $ts = strtotime($date);
    return $ts !== false ? date('F Y', $ts) : '—';
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="content rider-profile-page">
    <div class="container">

        <header class="rider-page-header">
            <div>
                <h1 class="heading-2">My <span>Profile</span></h1>
                <p class="text-muted">Manage your personal information and account settings</p>
            </div>
        </header>

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

        <section class="rider-profile-hero">
            <div class="rider-profile-avatar-wrap">
                <div class="rider-profile-avatar" id="profileAvatarPreview">
                    <?php if ($profilePicUrl !== ''): ?>
                    <img src="<?php echo htmlspecialchars($profilePicUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile"
                        id="profileAvatarImg"
                        onerror="this.onerror=null; this.style.display='none'; document.getElementById('profileAvatarInitial').style.display='flex';">
                    <span class="rider-profile-avatar-initial" id="profileAvatarInitial" style="display: none;">
                        <?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <?php else: ?>
                    <span class="rider-profile-avatar-initial" id="profileAvatarInitial" style="display: flex;">
                        <?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <img src="" alt="Profile" id="profileAvatarImg" style="display: none;">
                    <?php endif; ?>
                </div>
                <button type="button" class="rider-profile-avatar-edit" id="uploadPictureBtn"
                    aria-label="Change profile picture">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/edit.svg" alt="Edit">
                </button>
                <input type="file" id="profilePictureInput" accept="image/jpeg,image/png,image/webp,image/gif"
                    style="display: none;">
            </div>

            <div class="rider-profile-hero-info">
                <h2 class="rider-profile-name">
                    <?php echo htmlspecialchars($fullName ?: 'Rider', ENT_QUOTES, 'UTF-8'); ?>
                </h2>
                <p class="rider-profile-username">
                    @<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <div class="rider-profile-badges">
                    <span class="badge <?php echo $statusClass; ?>">
                        <?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <?php if ($dateJoined !== ''): ?>
                    <span class="rider-profile-joined">
                        Joined <?php echo htmlspecialchars(formatJoinedDate($dateJoined), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="rider-profile-hero-stats">
                <div class="rider-profile-hero-stat">
                    <span class="rider-profile-hero-stat-value"><?php echo number_format($rating, 1); ?></span>
                    <span class="rider-profile-hero-stat-label">Rating</span>
                </div>
                <div class="rider-profile-hero-stat">
                    <span class="rider-profile-hero-stat-value"><?php echo number_format($deliveries); ?></span>
                    <span class="rider-profile-hero-stat-label">Deliveries</span>
                </div>
            </div>
        </section>

        <section class="rider-card">
            <div class="rider-card-header">
                <h2 class="heading-5">Personal Information</h2>
                <button type="button" class="rider-card-link" id="editProfileBtn">Edit</button>
            </div>
            <div class="rider-card-body">
                <form id="riderProfileForm" method="POST" action="../backend/handlers/rider-handler.php">
                    <input type="hidden" name="csrf_token"
                        value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="update_profile">

                    <div class="rider-form-grid">
                        <div class="rider-form-group">
                            <label class="rider-form-label">First Name</label>
                            <input type="text" name="first_name" class="rider-form-control"
                                value="<?php echo htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'); ?>" disabled>
                        </div>
                        <div class="rider-form-group">
                            <label class="rider-form-label">Middle Name</label>
                            <input type="text" name="middle_name" class="rider-form-control"
                                value="<?php echo htmlspecialchars($middleName, ENT_QUOTES, 'UTF-8'); ?>" disabled>
                        </div>
                        <div class="rider-form-group">
                            <label class="rider-form-label">Last Name</label>
                            <input type="text" name="last_name" class="rider-form-control"
                                value="<?php echo htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8'); ?>" disabled>
                        </div>
                        <div class="rider-form-group">
                            <label class="rider-form-label">Email</label>
                            <input type="email" name="email" class="rider-form-control"
                                value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" disabled>
                        </div>
                        <div class="rider-form-group">
                            <label class="rider-form-label">Contact Number</label>
                            <input type="tel" name="contact_number" id="contactInput" class="rider-form-control"
                                value="<?php echo htmlspecialchars($contact, ENT_QUOTES, 'UTF-8'); ?>" disabled
                                placeholder="09XXXXXXXXX">
                        </div>
                        <div class="rider-form-group">
                            <label class="rider-form-label">Username</label>
                            <input type="text" name="username" class="rider-form-control"
                                value="<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>" disabled>
                        </div>
                    </div>

                    <div class="rider-form-actions" id="profileFormActions" style="display: none;">
                        <button type="button" class="btn btn-secondary btn-sm" id="cancelEditBtn">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm" id="saveProfileBtn">Save Changes</button>
                    </div>
                </form>
            </div>
        </section>

        <section class="rider-card">
            <div class="rider-card-header">
                <h2 class="heading-5">Vehicle Information</h2>
            </div>
            <div class="rider-card-body">
                <div class="rider-vehicle-display">
                    <div class="rider-vehicle-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt="Vehicle"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/community-general.svg'">
                    </div>
                    <div class="rider-vehicle-info">
                        <p class="rider-vehicle-name">
                            <?php echo htmlspecialchars(ucfirst($vehicle ?: '—'), ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                        <p class="rider-vehicle-plate">
                            <?php echo $plate !== '' ? htmlspecialchars($plate, ENT_QUOTES, 'UTF-8') : 'No plate recorded'; ?>
                        </p>
                    </div>
                </div>
                <p class="rider-vehicle-note">
                    Vehicle information cannot be edited directly. Contact support to make changes.
                </p>
            </div>
        </section>

        <?php if ($address): ?>
        <section class="rider-card">
            <div class="rider-card-header">
                <h2 class="heading-5">Address</h2>
            </div>
            <div class="rider-card-body">
                <div class="rider-address-display">
                    <div class="rider-address-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/location-fill.svg" alt="Address">
                    </div>
                    <div class="rider-address-info">
                        <p class="rider-address-label">
                            <?php echo htmlspecialchars($address['label'] ?? 'Home', ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                        <p class="rider-address-text">
                            <?php
                            $parts = array_filter([
                                $address['block']       ?? '',
                                $address['barangay']    ?? '',
                                $address['city']        ?? '',
                                $address['province']    ?? '',
                                $address['region']      ?? '',
                                $address['postal_code'] ?? '',
                                $address['country']     ?? '',
                            ]);
                            echo htmlspecialchars(implode(', ', $parts), ENT_QUOTES, 'UTF-8');
                            ?>
                        </p>
                    </div>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <div class="rider-logout-wrap">
            <a href="../backend/handlers/sign-out-handler.php" class="btn btn-outline btn-sm rider-logout-btn">
                <img src="<?php echo $assetBase; ?>assets/images/icons/logout-box-r-line.svg" alt="" class="btn-icon"
                    width="16" height="16"
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