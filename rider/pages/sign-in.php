<?php
/**
 * FitPal Rider Sign-In Page
 *
 * Uses the shared rider header + footer. All icons and images come
 * from the shared assets folder so nothing loads from an external
 * host.
 *
 * A password visibility toggle is present on the password field,
 * matching the customer sign-in page.
 *
 * IMPORTANT — ORDER OF OPERATIONS
 * -------------------------------
 * The redirect for an already-logged-in rider MUST happen before the
 * header is included. The header emits <!DOCTYPE html> and starts
 * buffering output, at which point any subsequent header() call in
 * this file would fail with the "headers already sent" warning.
 *
 * @package FitPal
 * @version 6.1 — Hero band now uses a solid grey background with the
 *                illustration contained (not cropped). Removed the
 *                unused .rider-hero-overlay element. DOM contract
 *                with sign-in.js and sign-in-handler.php unchanged.
 */

declare(strict_types=1);

// ===== SESSION =====
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ===== REDIRECT IF ALREADY LOGGED IN =====
if (!empty($_SESSION['delivery_rider_id'])) {
    header('Location: dashboard.php');
    exit;
}

// ===== HEADER (starts DB, renders <head> + <header>) =====
require_once __DIR__ . '/../includes/header.php';

// ===== CSRF =====
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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
                        value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

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