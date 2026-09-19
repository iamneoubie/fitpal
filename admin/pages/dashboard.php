<?php
/**
 * FitPal Admin Dashboard
 *
 * Minimal landing page for the administrator role. Shows the admin's
 * name, role, and a set of platform-wide stat cards. All SQL lives in
 * admin-queries.php.
 *
 * @package FitPal
 * @version 1.0
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

$firstName = (string)($profile['first_name'] ?? 'Admin');
$fullName  = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));
$roleSlug  = (string)($profile['role'] ?? 'support');
$roleLabel = getAdminRoleLabel($roleSlug);
$lastLogin = (string)($profile['last_login'] ?? '');

$lastLoginDisplay = '—';
if ($lastLogin !== '') {
    $ts = strtotime($lastLogin);
    if ($ts !== false) {
        $lastLoginDisplay = date('M d, Y g:i A', $ts);
    }
}
?>

<div class="content admin-dashboard-page">
    <div class="container">

        <header class="admin-dashboard-header">
            <div>
                <h1 class="heading-2">
                    Welcome back, <span><?php echo htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'); ?></span>
                </h1>
                <p class="text-muted">Here's your administrator overview.</p>
            </div>
            <div class="admin-dashboard-actions">
                <span class="badge badge-primary">
                    <?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
        </header>

        <section class="admin-stats-grid">
            <a href="users.php" class="admin-stat-card">
                <div class="admin-stat-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/people-team.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/user-profile-circle.svg'">
                </div>
                <div class="admin-stat-info">
                    <p class="admin-stat-number">Users</p>
                    <p class="admin-stat-label">Manage Customers</p>
                </div>
            </a>

            <a href="restaurants.php" class="admin-stat-card">
                <div class="admin-stat-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/restaurant.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/community-general.svg'">
                </div>
                <div class="admin-stat-info">
                    <p class="admin-stat-number">Restaurants</p>
                    <p class="admin-stat-label">Verification & Branches</p>
                </div>
            </a>

            <a href="riders.php" class="admin-stat-card">
                <div class="admin-stat-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/package.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/order.svg'">
                </div>
                <div class="admin-stat-info">
                    <p class="admin-stat-number">Riders</p>
                    <p class="admin-stat-label">Manage Fleet</p>
                </div>
            </a>

            <a href="profile.php" class="admin-stat-card">
                <div class="admin-stat-icon">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/user-profile-circle.svg" alt=""
                        onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/profile.svg'">
                </div>
                <div class="admin-stat-info">
                    <p class="admin-stat-number">Profile</p>
                    <p class="admin-stat-label">Account Settings</p>
                </div>
            </a>
        </section>

        <section class="admin-card">
            <div class="admin-card-header">
                <h2 class="heading-5">Account</h2>
            </div>
            <div class="admin-card-body">
                <p class="admin-account-line">
                    Signed in as
                    <strong><?php echo htmlspecialchars($fullName ?: 'Administrator', ENT_QUOTES, 'UTF-8'); ?></strong>
                </p>
                <p class="text-muted admin-account-hint">
                    Role: <?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?>
                    &middot; Last login: <?php echo htmlspecialchars($lastLoginDisplay, ENT_QUOTES, 'UTF-8'); ?>
                </p>
            </div>
        </section>

    </div>
</div>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>