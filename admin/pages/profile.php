<?php
/**
 * FitPal Admin Profile Page
 *
 * Read-only view of the current administrator's account.
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
$profile = [];

try {
    $profile = getAdministratorProfile($database_connection, $adminId) ?: [];
} catch (PDOException $e) {
    error_log('Admin profile error: ' . $e->getMessage());
}

$fullName = trim(
    ($profile['first_name'] ?? '') . ' ' .
    (!empty($profile['middle_name']) ? $profile['middle_name'] . ' ' : '') .
    ($profile['last_name'] ?? '')
);

$roleLabel = match ((string)($profile['role'] ?? '')) {
    'super_admin' => 'Super Administrator',
    'manager'     => 'Manager',
    'support'     => 'Support',
    default       => 'Administrator',
};
?>

<div class="content admin-page">
    <div class="container">

        <div class="page-title-header">
            <p class="heading-2">My <span>Profile</span></p>
            <p class="text-muted">Your administrator account details.</p>
        </div>

        <div class="admin-profile-card">
            <div class="profile-header-row">
                <div class="profile-avatar-large">
                    <?php echo htmlspecialchars(
                        strtoupper(substr($profile['first_name'] ?? 'A', 0, 1)),
                        ENT_QUOTES, 'UTF-8'
                    ); ?>
                </div>
                <div class="profile-header-info">
                    <p class="profile-name">
                        <?php echo htmlspecialchars($fullName ?: 'Administrator', ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <span class="profile-role-badge">
                        <?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
            </div>

            <div class="profile-fields">
                <div class="profile-field">
                    <span class="field-label">Email</span>
                    <span class="field-value">
                        <?php echo htmlspecialchars($profile['email'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
                <div class="profile-field">
                    <span class="field-label">Username</span>
                    <span class="field-value">
                        <?php echo htmlspecialchars($profile['username'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
                <div class="profile-field">
                    <span class="field-label">Contact</span>
                    <span class="field-value">
                        <?php echo htmlspecialchars($profile['contact_number'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
                <div class="profile-field">
                    <span class="field-label">Account Created</span>
                    <span class="field-value muted">
                        <?php echo htmlspecialchars(formatAdminDate($profile['date_created'] ?? null), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
                <div class="profile-field">
                    <span class="field-label">Last Login</span>
                    <span class="field-value muted">
                        <?php echo htmlspecialchars(formatAdminDate($profile['last_login'] ?? null), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
                <div class="profile-field">
                    <span class="field-label">Account Status</span>
                    <span class="field-value">
                        <?php if ((int)($profile['is_active'] ?? 0) === 1): ?>
                        <span class="admin-badge badge-success">Active</span>
                        <?php else: ?>
                        <span class="admin-badge badge-secondary">Inactive</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>

            <div class="profile-actions">
                <a href="dashboard.php" class="btn btn-primary">Back to Dashboard</a>
            </div>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>