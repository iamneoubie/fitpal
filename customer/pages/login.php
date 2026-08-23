<?php
/**
 * FitPal Customer Login Page
 * 
 * Clean login page with proper modal feedback system
 * Mobile-optimized with large touch targets
 * 
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/header.php';

// Redirect if already logged in
if (isset($_SESSION['customer_id']) && !empty($_SESSION['customer_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$assetBase = '../../shared/';
$redirect = $_GET['redirect'] ?? '';

// Check for error messages from login handler
$errorMessage = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);
?>
<div class="content login-page">
    <div class="login-container">
        <div class="login-header">
            <p class="login-title">Welcome <span>Back</span></p>
            <p class="login-subtitle">Sign in to your customer account</p>
        </div>

        <form method="POST" action="../backend/handlers/login_handler.php" class="login-form" id="loginForm" novalidate>
            <input type="hidden" name="csrf_token"
                value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
            <?php if (!empty($redirect)): ?>
            <input type="hidden" name="redirect"
                value="<?php echo htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8'); ?>">
            <?php endif; ?>

            <div id="loginError" class="alert alert-danger"
                style="<?php echo empty($errorMessage) ? 'display: none;' : 'display: block;'; ?>" role="alert">
                <span id="errorMessage"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>

            <div class="form-group">
                <label for="identifier" class="form-label">Email or Username</label>
                <input type="text" id="identifier" name="identifier" class="form-control"
                    placeholder="Enter your email or username" autocomplete="username" required
                    value="<?php echo htmlspecialchars($_POST['identifier'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <div id="identifierError" class="form-error" style="display: none;"></div>
            </div>

            <div class="form-group">
                <label for="password" class="form-label">Password</label>
                <div class="password-wrapper">
                    <input type="password" id="password" name="password" class="form-control"
                        placeholder="Enter your password" autocomplete="current-password" required>
                    <button type="button" class="password-toggle" id="togglePassword"
                        aria-label="Toggle password visibility" tabindex="-1">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/password-hide.svg" alt="Hide password"
                            id="passwordIcon">
                    </button>
                </div>
                <div id="passwordError" class="form-error" style="display: none;"></div>
            </div>

            <button type="submit" class="btn btn-primary btn-block btn-lg" id="loginBtn">Sign In</button>

            <div class="login-footer">
                <p class="login-footer-text">
                    Don't have an account?
                    <a href="<?php echo $assetBase; ?>../customer/pages/register.php">Register here</a>
                </p>
                <p class="login-footer-text">
                    <a href="<?php echo $assetBase; ?>../customer/pages/forgot-password.php" class="forgot-link">Forgot
                        password?</a>
                </p>
            </div>
        </form>
    </div>
</div>

<!-- Notification Modal -->
<div id="notifierModal" class="notifier hidden">
    <div class="notifier-content">
        <div class="notifier-icon">
            <img src="<?php echo $assetBase; ?>assets/images/icons/mail.svg" alt="Notification">
        </div>
        <p id="notifierMessage"></p>
        <button id="notifierCloseBtn" class="btn btn-primary">OK</button>
    </div>
</div>

<script src="<?php echo $assetBase; ?>assets/ui/js/header.js" defer></script>
<script src="../assets/ui/js/login.js" defer></script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>