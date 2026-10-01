<?php
/**
 * FitPal Customer Sign-In Page
 *
 * @package FitPal
 * @version 2.0 — Removed the $_SESSION['created'] = time() pin. That
 *                key belonged to the old global-rotation policy, which
 *                has been replaced by the per-role activity gate in
 *                shared/includes/session-activity.php. No other
 *                behavior changed: the form's POST field stays named
 *                csrf_token, and sign-in-handler.php still validates
 *                against $_SESSION['customer_csrf_token'].
 *
 *                (1.3: require_once on includes/customer-csrf-token.php
 *                followed by getCustomerCsrfToken(). 1.2: own session
 *                key, customer_csrf_token.)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('customer');

// Redirect if already logged in
if (isset($_SESSION['customer_id']) && !empty($_SESSION['customer_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Never let the browser or bfcache serve a stale copy of this form.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Single source of truth for the customer role's CSRF token. The
// helper generates it on first use and stores it under
// 'customer_csrf_token' — never the shared 'csrf_token' key.
require_once __DIR__ . '/../includes/customer-csrf-token.php';
$csrfToken = getCustomerCsrfToken();

require_once __DIR__ . '/../includes/header.php';

// $assetBase is provided by header.php

// Consume one-time flash messages
$errorMessage   = $_SESSION['login_error'] ?? '';
$successMessage = $_SESSION['registration_success'] ?? '';
unset($_SESSION['login_error'], $_SESSION['registration_success']);
?>

<div class="content sign-in-page">
    <div class="container">
        <div class="sign-in-card">
            <div class="sign-in-header">
                <p class="heading-2">Welcome <span>Back</span></p>
                <p class="text-muted">Sign in to your customer account</p>
            </div>

            <?php if (!empty($successMessage)): ?>
            <div class="alert alert-success" role="alert">
                <?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($errorMessage)): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="../backend/handlers/sign-in-handler.php" class="sign-in-form" id="signInForm">

                <input type="hidden" name="csrf_token"
                    value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="form-group">
                    <label for="identifier" class="form-label">Email or Username</label>
                    <input type="text" id="identifier" name="identifier" class="form-control"
                        placeholder="Enter your email or username" autocomplete="username" required
                        value="<?php echo htmlspecialchars($_POST['identifier'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
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

                <button type="submit" class="btn btn-primary btn-block btn-lg" id="signInBtn">
                    Sign In
                </button>

                <div class="sign-in-footer">
                    <p class="text-muted">
                        Don't have an account? <a href="sign-up.php">Sign up here</a>
                    </p>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
window.FITPAL_CSRF_TOKEN = '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>';
</script>
<script src="../assets/ui/js/sign-in.js" defer></script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>