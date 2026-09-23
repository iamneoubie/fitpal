<?php
/**
 * FitPal Admin Sign-In Page
 *
 * @package FitPal
 * @version 3.8 — Replaced the inline admin_csrf_token generation
 *                block with a require_once on
 *                includes/admin-csrf-token.php followed by
 *                getAdminCsrfToken(). The helper is idempotent, so
 *                header.php's call later in the same request is a
 *                no-op. This makes the admin role's CSRF bootstrap
 *                a single source of truth, mirroring the customer
 *                role's sign-in.php pattern. Behavior is unchanged:
 *                the form's POST field stays named csrf_token, and
 *                sign-in-handler.php still validates against
 *                $_SESSION['admin_csrf_token'].
 *
 *                (3.7: Reworded the comment on the
 *                $_SESSION['created'] pin. Under the old shared
 *                csrf_token scheme the pin was load-bearing; now
 *                that admin uses its own admin_csrf_token, the
 *                header's rotation preserves session data, so the
 *                token survives regardless. The pin still serves a
 *                real purpose — it prevents rotation from firing
 *                while an authenticated user in another tab has the
 *                sign-in form open — but the reason has changed, so
 *                the comment now reflects that. No code change.
 *
 *                3.6: Uses its own session key, admin_csrf_token,
 *                instead of the shared csrf_token. The admin and
 *                customer roles run on the same PHP session (same
 *                cookie), so a single shared csrf_token meant that
 *                logging in as one role deleted the token the other
 *                role's sign-in page had already rendered into its
 *                form (both handlers unset csrf_token on success).
 *                Whichever role signed in first "used up" the shared
 *                token, leaving the other role's already-loaded page
 *                stuck with a stale token — its first submit failed
 *                validation, and only a reload (which re-generates
 *                the token) fixed it. Splitting the key per role
 *                removes the collision entirely.)
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['administrator_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Never let the browser or bfcache serve a stale copy of this form.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Pin the session's "created" marker to now. Under the per-role key
// scheme this is not load-bearing for the CSRF token — the header's
// rotation preserves session data, and the header's guarded $csrfToken
// init no longer clobbers the value assigned below. The pin remains
// useful for the edge case where an authenticated user in another tab
// would otherwise trigger a rotation that regenerates this session's
// ID while this form is open. Keeping it costs nothing and stabilises
// the sign-in experience for that case.
$_SESSION['created'] = time();

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