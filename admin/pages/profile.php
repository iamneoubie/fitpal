<?php
/**
 * FitPal Admin Profile
 *
 * @package FitPal
 * @version 2.0
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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
?>

<div class="content admin-list-page">
    <div class="container">

        <header class="admin-page-header">
            <div class="admin-page-header-left">
                <h1 class="heading-2">My <span>Profile</span></h1>
                <p class="text-muted">Manage your administrator account.</p>
            </div>
            <div class="admin-page-header-actions">
                <a href="dashboard.php" class="btn btn-outline btn-sm">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-left-line.svg" alt="" class="btn-icon"
                        width="16" height="16" style="filter: none;">
                    <span>Dashboard</span>
                </a>
            </div>
        </header>

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

        <!-- Profile summary -->
        <div class="admin-table-card" style="padding: 24px; margin-bottom: 20px;">
            <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
                <div class="admin-cell-avatar" style="width:64px; height:64px; font-size:24px;">
                    <?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div style="flex:1; min-width:0;">
                    <p class="admin-cell-title" style="font-size: var(--font-size-xl);">
                        <?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <p class="admin-cell-subtitle">
                        <?php echo htmlspecialchars(adminRoleLabel((string)($profile['role'] ?? 'support')), ENT_QUOTES, 'UTF-8'); ?>
                        &middot;
                        <?php echo htmlspecialchars((string)($profile['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <p class="admin-cell-subtitle" style="margin-top:4px;">
                        Last login:
                        <?php echo htmlspecialchars(formatAdminDate((string)($profile['last_login'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Update profile -->
        <div class="admin-table-card" style="padding: 24px; margin-bottom: 20px;">
            <h3 class="admin-section-heading" style="margin-top:0;">Personal Information</h3>
            <form method="POST" action="../backend/handlers/admin-handler.php">
                <input type="hidden" name="csrf_token"
                    value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="update_profile">
                <input type="hidden" name="redirect_to" value="profile.php">

                <div class="admin-detail-grid">
                    <div class="admin-detail-item">
                        <label class="admin-detail-label" for="first_name">First Name</label>
                        <input type="text" id="first_name" name="first_name" class="admin-search-input"
                            value="<?php echo htmlspecialchars((string)($profile['first_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                            required minlength="2">
                    </div>
                    <div class="admin-detail-item">
                        <label class="admin-detail-label" for="middle_name">Middle Name</label>
                        <input type="text" id="middle_name" name="middle_name" class="admin-search-input"
                            value="<?php echo htmlspecialchars((string)($profile['middle_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="admin-detail-item">
                        <label class="admin-detail-label" for="last_name">Last Name</label>
                        <input type="text" id="last_name" name="last_name" class="admin-search-input"
                            value="<?php echo htmlspecialchars((string)($profile['last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                            required minlength="2">
                    </div>
                    <div class="admin-detail-item">
                        <label class="admin-detail-label" for="contact_number">Contact Number</label>
                        <input type="tel" id="contact_number" name="contact_number" class="admin-search-input"
                            placeholder="09XXXXXXXXX"
                            value="<?php echo htmlspecialchars((string)($profile['contact_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="admin-detail-item">
                        <label class="admin-detail-label">Email (read-only)</label>
                        <input type="email" class="admin-search-input"
                            value="<?php echo htmlspecialchars((string)($profile['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                            disabled>
                    </div>
                    <div class="admin-detail-item">
                        <label class="admin-detail-label">Role (read-only)</label>
                        <input type="text" class="admin-search-input"
                            value="<?php echo htmlspecialchars(adminRoleLabel((string)($profile['role'] ?? 'support')), ENT_QUOTES, 'UTF-8'); ?>"
                            disabled>
                    </div>
                </div>

                <div style="margin-top: 16px; display: flex; justify-content: flex-end;">
                    <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
                </div>
            </form>
        </div>

        <!-- Change password -->
        <div class="admin-table-card" style="padding: 24px;">
            <h3 class="admin-section-heading" style="margin-top:0;">Change Password</h3>
            <form method="POST" action="../backend/handlers/admin-handler.php">
                <input type="hidden" name="csrf_token"
                    value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="redirect_to" value="profile.php">

                <div class="admin-detail-grid">
                    <div class="admin-detail-item admin-detail-item-full">
                        <label class="admin-detail-label" for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password" class="admin-search-input"
                            required autocomplete="current-password">
                    </div>
                    <div class="admin-detail-item">
                        <label class="admin-detail-label" for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" class="admin-search-input" required
                            minlength="8" maxlength="20" autocomplete="new-password">
                    </div>
                    <div class="admin-detail-item">
                        <label class="admin-detail-label" for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="admin-search-input"
                            required minlength="8" maxlength="20" autocomplete="new-password">
                    </div>
                </div>

                <div style="margin-top: 16px; display: flex; justify-content: flex-end;">
                    <button type="submit" class="btn btn-primary btn-sm">Change Password</button>
                </div>
            </form>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>