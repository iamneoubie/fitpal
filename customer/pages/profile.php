<?php
/**
 * FitPal Customer Profile Page
 *
 * Shows personal information and delivery addresses, with a
 * multi-step address modal for add/edit.
 *
 * ---------------------------------------------------------------------
 * SCOPE RULES APPLIED
 * ---------------------------------------------------------------------
 *  - No SQL. getCustomerProfile() and getCustomerAddresses() come from
 *    the query layer.
 *  - No inline CSS. profile.css is loaded via the customer header's
 *    $pageCssMap.
 *  - No inline style attributes. The hide/show flags for the action
 *    row and modals are managed by classes defined in profile.css.
 *  - formatAddress() and getAddressLabel() come from
 *    customer/backend/database/address-queries.php. They are NOT
 *    declared here.
 *  - formatCurrency() comes from customer-queries.php.
 * ---------------------------------------------------------------------
 *
 * @package FitPal
 * @version 4.2 — Bootstraps window.FITPAL_CSRF_TOKEN so profile.js has
 *                a reliable token source instead of querying the DOM
 *                for the first input[name="csrf_token"]. The token is
 *                still the customer role's own key, customer_csrf_token,
 *                inherited from header.php. No shared-key usage.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

// $csrfToken is provided by header.php (via includes/csrf_token.php),
// stored under the customer role's own session key 'customer_csrf_token'.

$customerId = (int)$_SESSION['customer_id'];

$profileData = getCustomerProfile($database_connection, $customerId) ?: null;
$addresses   = getCustomerAddresses($database_connection, $customerId);

$hasAddresses = !empty($addresses);
$fullName     = trim(($profileData['first_name'] ?? '') . ' ' . ($profileData['last_name'] ?? ''));
$balance      = (float)($profileData['balance'] ?? 0);
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

        <!-- Profile Header -->
        <div class="profile-header-card">
            <div class="profile-header-left">
                <div class="profile-avatar">
                    <div class="profile-avatar-placeholder">
                        <span><?php echo htmlspecialchars(strtoupper(substr($fullName, 0, 1) ?: 'U'), ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
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
                    <img src="<?php echo $assetBase; ?>assets/images/icons/edit.svg" alt="Edit" class="btn-icon">
                    Edit Profile
                </button>
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

        <!-- Tab Content: Personal Information -->
        <div class="profile-tab-content active" id="tab-personal">
            <div class="profile-card">
                <div class="card-header">
                    <h3>Personal Information</h3>
                </div>
                <div class="card-body">
                    <form id="profileForm" method="POST" action="../backend/handlers/profile-handler.php">
                        <input type="hidden" name="csrf_token"
                            value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="update_profile">

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

                        <div class="form-group">
                            <label for="contact_number" class="field-label">Contact Number</label>
                            <input type="tel" id="contact_number" name="contact_number" class="form-control"
                                value="<?php echo htmlspecialchars($profileData['contact_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                disabled>
                        </div>

                        <div class="form-group">
                            <label for="birthdate" class="field-label">Birthdate</label>
                            <input type="date" id="birthdate" name="birthdate" class="form-control"
                                value="<?php echo htmlspecialchars($profileData['birthdate'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                disabled>
                        </div>

                        <div class="form-group">
                            <label for="gender" class="field-label">Gender</label>
                            <select id="gender" name="gender" class="form-control" disabled>
                                <option value="">Select Gender</option>
                                <option value="Male"
                                    <?php echo ($profileData['gender'] ?? '') === 'Male' ? 'selected' : ''; ?>>Male
                                </option>
                                <option value="Female"
                                    <?php echo ($profileData['gender'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female
                                </option>
                                <option value="Other"
                                    <?php echo ($profileData['gender'] ?? '') === 'Other' ? 'selected' : ''; ?>>Other
                                </option>
                            </select>
                        </div>

                        <div class="profile-actions is-hidden" id="profileActions">
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                            <button type="button" id="cancelEditBtn" class="btn btn-cancel">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Tab Content: Delivery Addresses -->
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

<!-- Address Modal (Multi-Step) -->
<div id="addressModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="addressModalTitle">Add New Address</h3>
            <button type="button" class="modal-close" id="closeAddressModal">&times;</button>
        </div>

        <!-- Progress Steps -->
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

            <!-- Step 1: Location -->
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

            <!-- Step 2: Details -->
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

            <!-- Step 3: Confirm -->
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
</script>
<script src="../assets/ui/js/profile.js" defer></script>
<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>