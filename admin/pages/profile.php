<?php
/**
 * FitPal Admin — Profile
 *
 * Read-only display of the current administrator's account details.
 * Editing is out of scope for v1 — contact the super admin to change
 * role or permissions.
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

$fullName  = trim(
    ($profile['first_name'] ?? '') . ' ' .
    ($profile['middle_name'] ?? '') . ' ' .
    ($profile['last_name'] ?? '')
);
$fullName  = preg_replace('/\s+/', ' ', $fullName);
$roleSlug  = (string)($profile['role'] ?? 'support');
$roleLabel = getAdminRoleLabel($roleSlug);
$initial   = strtoupper(substr((string)($profile['first_name'] ?? 'A'), 0, 1));

$formatDate = static function (?string $value): string {
    if ($value === null || $value === '') return '—';
    $ts = strtotime($value);
    return $ts ? date('M d, Y g:i A', $ts) : $value;
};
?>

<div class="content admin-profile-page">
    <div class="container">

        <header class="admin-page-header">
            <div>
                <h1 class="heading-2">My <span>Profile</span></h1>
                <p class="text-muted">Your administrator account details.</p>
            </div>
        </header>

        <div class="profile-header-card">
            <div class="profile-header-left">
                <div class="profile-avatar-placeholder">
                    <span><?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="profile-name-role">
                    <p class="profile-full-name">
                        <?php echo htmlspecialchars($fullName ?: 'Administrator', ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <span
                        class="profile-role-badge"><?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            </div>
        </div>

        <div class="profile-card">
            <div class="card-header">
                <h3>Account Information</h3>
            </div>
            <div class="card-body">
                <div class="detail-row">
                    <span class="detail-label">Name</span>
                    <span
                        class="detail-value"><?php echo htmlspecialchars($fullName ?: '—', ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Username</span>
                    <span
                        class="detail-value"><?php echo htmlspecialchars((string)($profile['username'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Email</span>
                    <span
                        class="detail-value"><?php echo htmlspecialchars((string)($profile['email'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Contact</span>
                    <span
                        class="detail-value"><?php echo htmlspecialchars((string)($profile['contact_number'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Role</span>
                    <span class="detail-value"><?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Status</span>
                    <span class="detail-value">
                        <?php if ((int)($profile['is_active'] ?? 0) === 1): ?>
                        <span class="badge badge-success">Active</span>
                        <?php else: ?>
                        <span class="badge badge-secondary">Inactive</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Created</span>
                    <span
                        class="detail-value"><?php echo htmlspecialchars($formatDate($profile['date_created'] ?? null), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Last Login</span>
                    <span
                        class="detail-value"><?php echo htmlspecialchars($formatDate($profile['last_login'] ?? null), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            </div>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>