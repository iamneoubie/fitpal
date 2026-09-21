<?php
/**
 * FitPal Admin Sign-In Page
 *
 * @package FitPal
 * @version 3.0 — Removed the inline <script> block. The sign-in JS
 *                lives entirely in admin/assets/ui/js/sign-in.js.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['administrator_id'])) {
    header('Location: dashboard.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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
                    value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

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