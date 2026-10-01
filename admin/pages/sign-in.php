<?php
/**
 * FitPal Admin Sign-In Page
 *
 * @package FitPal
 * @version 4.0 — Removed the $_SESSION['created'] = time() pin. That
 *                key belonged to the old global-rotation policy, which
 *                has been replaced by the per-role activity gate in
 *                shared/includes/session-activity.php. The header no
 *                longer rotates the shared session ID from an
 *                authenticated page, so there is nothing for this
 *                page to pin. No other behavior changed: the form's
 *                POST field stays named csrf_token, and
 *                sign-in-handler.php still validates against
 *                $_SESSION['admin_csrf_token'].
 *
 *                (3.8: require_once on includes/admin-csrf-token.php
 *                followed by getAdminCsrfToken(). 3.7: reworded the
 *                comment on the $_SESSION['created'] pin. 3.6: own
 *                session key, admin_csrf_token.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('admin');
if (!empty($_SESSION['administrator_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Never let the browser or bfcache serve a stale copy of this form.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Single source of truth for the admin role's CSRF token. The helper
// generates it on first use and stores it under 'admin_csrf_token' —
// never the shared 'csrf_token' key.
require_once __DIR__ . '/../includes/admin-csrf-token.php';
$csrfToken = getAdminCsrfToken();

require_once __DIR__ . '/../includes/header.php';

$errorMessage = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);

$identifierValue = htmlspecialchars((string)($_POST['identifier'] ?? ''), ENT_QUOTES, 'UTF-8');
?>

<div class="content sign-in-page">
    <div class="container">
        <div class="sign-in-card">
            <div class="sign-in-header">
                <p class="heading-2">Admin <span>Sign In</span></p>
                <p class="text-muted">Access the FitPal control center</p>
            </div>

            <?php if ($errorMessage !== ''): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="../backend/handlers/sign-in-handler.php" class="sign-in-form" id="signInForm"
                novalidate>

                <input type="hidden" name="csrf_token"
                    value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="form-group">
                    <label for="identifier" class="form-label">Email or Username</label>
                    <input type="text" id="identifier" name="identifier" class="form-control"
                        placeholder="Enter your email or username" autocomplete="username" required
                        value="<?php echo $identifierValue; ?>">
                    <div class="form-error" id="identifierError"></div>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="password" name="password" class="form-control"
                            placeholder="Enter your password" autocomplete="current-password" required>
                        <button type="button" class="password-toggle" id="togglePassword"
                            aria-label="Toggle password visibility" tabindex="-1">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/password-hide.svg" alt=""
                                id="passwordIcon">
                        </button>
                    </div>
                    <div class="form-error" id="passwordError"></div>
                </div>

                <button type="submit" class="btn btn-primary btn-block" id="signInBtn">
                    Sign In
                </button>
            </form>

            <div class="sign-in-footer">
                <p class="text-muted">
                    Not an admin? <a href="<?php echo $assetBase; ?>../customer/pages/sign-in.php">Customer sign-in</a>
                </p>
            </div>
        </div>
    </div>
</div>

<script src="../assets/ui/js/sign-in.js" defer></script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>