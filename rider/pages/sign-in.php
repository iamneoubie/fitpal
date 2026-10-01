<?php
/**
 * FitPal Rider Sign-In Page
 *
 * @package FitPal
 * @version 7.0 — Removed the $_SESSION['created'] = time() pin. That
 *                key belonged to the old global-rotation policy, which
 *                has been replaced by the per-role activity gate in
 *                shared/includes/session-activity.php. No other
 *                behavior changed: the form's POST field stays named
 *                csrf_token, and sign-in-handler.php still validates
 *                against $_SESSION['rider_csrf_token'].
 *
 *                (6.4: the header assigns $csrfToken
 *                unconditionally via rider-csrf-token.php. 6.3:
 *                require_once on the helper. 6.2: own session key,
 *                rider_csrf_token.)
 */

declare(strict_types=1);

// ===== SESSION =====
require_once __DIR__ . '/../../shared/includes/session-bootstrap.php';
fitpal_session_bootstrap('rider');

// ===== REDIRECT IF ALREADY LOGGED IN =====
if (!empty($_SESSION['delivery_rider_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Never let the browser or bfcache serve a stale copy of this form.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// ===== HEADER (starts DB, assigns $csrfToken, renders <head> + <header>) =====
require_once __DIR__ . '/../includes/header.php';

// ===== FLASH MESSAGES =====
$errorMessage = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);

$successMessage = $_SESSION['registration_success'] ?? '';
unset($_SESSION['registration_success']);

// ===== ILLUSTRATION PATHS =====
$riderIllustration = $assetBase . 'assets/images/rider-image/rider.png';
$heroFallback      = $assetBase . 'assets/images/showcase/hero-image.png';

// ===== PRESERVED IDENTIFIER =====
$identifierValue = htmlspecialchars((string)($_POST['identifier'] ?? ''), ENT_QUOTES, 'UTF-8');
?>

<div class="content rider-auth-content-wrap">
    <main class="rider-auth-page" role="main">
        <div class="rider-auth-card">

            <!-- Hero illustration: contained on a solid grey band -->
            <div class="rider-hero">
                <img src="<?php echo htmlspecialchars($riderIllustration, ENT_QUOTES, 'UTF-8'); ?>" alt=""
                    onerror="this.onerror=null; this.src='<?php echo htmlspecialchars($heroFallback, ENT_QUOTES, 'UTF-8'); ?>';">
            </div>

            <!-- Content -->
            <div class="rider-auth-content">

                <header class="rider-auth-header">
                    <h1 class="rider-auth-title">
                        Welcome <span>back</span>
                    </h1>
                    <p class="rider-auth-subtitle">Sign in to continue delivering with FitPal.</p>
                </header>

                <!-- Region strip -->
                <div class="rider-region-strip" role="note" aria-label="Operating region">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/location-fill.svg" alt=""
                        class="rider-region-icon" aria-hidden="true">
                    <span class="rider-region-text">Operating region</span>
                    <span class="rider-region-value">Philippines</span>
                </div>

                <?php if (!empty($successMessage)): ?>
                <div class="rider-alert rider-alert-success" role="alert" aria-live="polite">
                    <?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($errorMessage)): ?>
                <div class="rider-alert rider-alert-danger" role="alert" aria-live="assertive">
                    <?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="../backend/handlers/sign-in-handler.php" id="riderSignInForm" novalidate>

                    <input type="hidden" name="csrf_token"
                        value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="rider-form-group">
                        <label for="identifier" class="rider-form-label">Email or username</label>
                        <input type="text" id="identifier" name="identifier" class="rider-form-control"
                            placeholder="you@example.com" autocomplete="username"
                            value="<?php echo $identifierValue; ?>" required>
                        <p class="rider-form-error" id="identifierError" role="alert"></p>
                    </div>

                    <div class="rider-form-group">
                        <label for="password" class="rider-form-label">Password</label>
                        <div class="rider-password-wrapper">
                            <input type="password" id="password" name="password" class="rider-form-control"
                                placeholder="Enter your password" autocomplete="current-password" required>
                            <button type="button" class="rider-password-toggle" id="togglePassword" tabindex="-1"
                                aria-label="Show password" aria-pressed="false">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/password-hide.svg" alt=""
                                    id="passwordIcon">
                            </button>
                        </div>
                        <p class="rider-form-error" id="passwordError" role="alert"></p>
                    </div>

                    <button type="submit" class="rider-btn rider-btn-primary" id="submitBtn">
                        Sign in
                    </button>

                </form>

                <div class="rider-auth-divider" aria-hidden="true">
                    <span>New to FitPal?</span>
                </div>

                <a href="sign-up.php" class="rider-btn rider-btn-secondary">
                    Apply as a rider
                </a>

            </div>

            <footer class="rider-version">
                FitPal Rider &middot; v1.0.0
            </footer>
        </div>
    </main>
</div>

<script src="../assets/ui/js/sign-in.js" defer></script>

<?php
require_once __DIR__ . '/../../shared/includes/footer.php';
?>