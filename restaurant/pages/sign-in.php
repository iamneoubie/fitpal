<?php
/**
 * FitPal Restaurant Sign-In Page
 *
 * Two tabs:
 *   Owner  — single-step identifier + password.
 *   Branch — two-phase flow:
 *     Phase 1 — restaurant combobox + branch combobox.
 *     Phase 2 — identifier + password.
 *
 * The branch_code selected in Phase 1 is stored on a hidden input
 * inside the Phase 2 form and posted to sign-in-handler.php, which
 * is unchanged and still expects `branch_code`, `identifier`,
 * `password`, `role_scope=branch`.
 *
 * @package FitPal
 * @version 2.0 — Branch tab split into two phases with comboboxes.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['restaurant_account_id'])) {
    header('Location: dashboard.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../backend/database/restaurant-queries.php';

// $assetBase provided by header.php

$errorMessage   = $_SESSION['login_error'] ?? '';
$successMessage = $_SESSION['registration_success'] ?? '';
unset($_SESSION['login_error'], $_SESSION['registration_success']);

$branches = [];
try {
    $branches = getBranchesWithAccounts($database_connection);
} catch (PDOException $e) {
    error_log('Branch list error: ' . $e->getMessage());
}

$activeTab = $_SESSION['login_scope'] ?? 'owner';
if (!in_array($activeTab, ['owner', 'branch'], true)) {
    $activeTab = 'owner';
}
unset($_SESSION['login_scope']);
?>

<div class="content sign-in-page">
    <div class="container">
        <div class="sign-in-card">
            <div class="sign-in-header">
                <p class="heading-2">Restaurant <span>Sign In</span></p>
                <p class="text-muted">Choose how you're signing in</p>
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

            <div class="sign-in-tabs" role="tablist">
                <button type="button" class="sign-in-tab <?php echo $activeTab === 'owner' ? 'active' : ''; ?>"
                    data-tab="owner" role="tab"
                    aria-selected="<?php echo $activeTab === 'owner' ? 'true' : 'false'; ?>">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/building.svg" alt=""
                        class="sign-in-tab-icon">
                    <span>Owner</span>
                </button>
                <button type="button" class="sign-in-tab <?php echo $activeTab === 'branch' ? 'active' : ''; ?>"
                    data-tab="branch" role="tab"
                    aria-selected="<?php echo $activeTab === 'branch' ? 'true' : 'false'; ?>">
                    <img src="<?php echo $assetBase; ?>assets/images/icons/location-fill.svg" alt=""
                        class="sign-in-tab-icon">
                    <span>Branch</span>
                </button>
            </div>

            <!-- ============================================
                 OWNER PANEL
                 ============================================ -->
            <div class="sign-in-panel <?php echo $activeTab === 'owner' ? 'active' : ''; ?>" id="panel-owner"
                role="tabpanel">
                <form method="POST" action="../backend/handlers/sign-in-handler.php" class="sign-in-form"
                    id="signInFormOwner" novalidate>

                    <input type="hidden" name="csrf_token"
                        value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="role_scope" value="owner">

                    <div class="form-group">
                        <label for="owner_identifier" class="form-label">Email or Username</label>
                        <input type="text" id="owner_identifier" name="identifier" class="form-control"
                            placeholder="Enter your email or username" autocomplete="username" required>
                        <div class="form-error" id="ownerIdentifierError"></div>
                    </div>

                    <div class="form-group">
                        <label for="owner_password" class="form-label">Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="owner_password" name="password" class="form-control"
                                placeholder="Enter your password" autocomplete="current-password" required>
                            <button type="button" class="password-toggle" id="ownerTogglePassword"
                                aria-label="Toggle password visibility" tabindex="-1">
                                <img src="<?php echo $assetBase; ?>assets/images/icons/password-hide.svg" alt=""
                                    id="ownerPasswordIcon">
                            </button>
                        </div>
                        <div class="form-error" id="ownerPasswordError"></div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block" id="ownerSignInBtn">
                        Sign In as Owner
                    </button>
                </form>
            </div>

            <!-- ============================================
                 BRANCH PANEL — TWO PHASES
                 ============================================ -->
            <div class="sign-in-panel <?php echo $activeTab === 'branch' ? 'active' : ''; ?>" id="panel-branch"
                role="tabpanel">

                <?php if (empty($branches)): ?>
                <div class="alert alert-danger" role="alert">
                    No branch accounts are available yet. Please contact your restaurant owner.
                </div>
                <?php endif; ?>

                <!-- ---------- PHASE 1 ---------- -->
                <div class="branch-phase" id="branchPhase1">

                    <p class="branch-phase-label">
                        <span class="branch-phase-step">1</span>
                        Select your restaurant and branch
                    </p>

                    <div class="form-group">
                        <label for="restaurant_search" class="form-label">Restaurant</label>
                        <div class="combo" id="restaurantCombo">
                            <input type="text" id="restaurant_search" class="form-control combo-input"
                                placeholder="Start typing a restaurant name…" autocomplete="off" role="combobox"
                                aria-expanded="false" aria-autocomplete="list" aria-controls="restaurantSuggestions"
                                <?php echo empty($branches) ? 'disabled' : ''; ?>>
                            <input type="hidden" id="restaurant_id" value="">
                            <button type="button" class="combo-clear" id="restaurantClear" aria-label="Clear restaurant"
                                hidden>&times;</button>
                            <ul class="combo-list" id="restaurantSuggestions" role="listbox" hidden></ul>
                        </div>
                        <div class="form-error" id="restaurantError"></div>
                    </div>

                    <div class="form-group">
                        <label for="branch_search" class="form-label">Branch</label>
                        <div class="combo" id="branchCombo">
                            <input type="text" id="branch_search" class="form-control combo-input"
                                placeholder="Select a restaurant first" autocomplete="off" role="combobox"
                                aria-expanded="false" aria-autocomplete="list" aria-controls="branchSuggestions"
                                disabled>
                            <input type="hidden" id="branch_code" value="">
                            <button type="button" class="combo-clear" id="branchClear" aria-label="Clear branch"
                                hidden>&times;</button>
                            <ul class="combo-list" id="branchSuggestions" role="listbox" hidden></ul>
                        </div>
                        <div class="form-error" id="branchError"></div>
                    </div>

                    <button type="button" class="btn btn-primary btn-block" id="branchPhase1Next" disabled
                        <?php echo empty($branches) ? 'disabled' : ''; ?>>
                        Next
                    </button>
                </div>

                <!-- ---------- PHASE 2 ---------- -->
                <div class="branch-phase" id="branchPhase2" hidden>

                    <p class="branch-phase-label">
                        <span class="branch-phase-step">2</span>
                        Sign in
                    </p>

                    <div class="branch-summary" id="branchSummary">
                        <span class="branch-summary-icon" aria-hidden="true">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/building.svg" alt="">
                        </span>
                        <span class="branch-summary-text">
                            <span class="branch-summary-name" id="branchSummaryName">—</span>
                            <span class="branch-summary-code" id="branchSummaryCode">—</span>
                        </span>
                        <button type="button" class="branch-summary-change" id="branchChangeBtn">
                            Change
                        </button>
                    </div>

                    <form method="POST" action="../backend/handlers/sign-in-handler.php" class="sign-in-form"
                        id="signInFormBranch" novalidate>

                        <input type="hidden" name="csrf_token"
                            value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="role_scope" value="branch">
                        <input type="hidden" name="branch_code" id="branch_code_submit" value="">

                        <div class="form-group">
                            <label for="branch_identifier" class="form-label">Email or Username</label>
                            <input type="text" id="branch_identifier" name="identifier" class="form-control"
                                placeholder="Enter your email or username" autocomplete="username" required>
                            <div class="form-error" id="branchIdentifierError"></div>
                        </div>

                        <div class="form-group">
                            <label for="branch_password" class="form-label">Password</label>
                            <div class="password-wrapper">
                                <input type="password" id="branch_password" name="password" class="form-control"
                                    placeholder="Enter your password" autocomplete="current-password" required>
                                <button type="button" class="password-toggle" id="branchTogglePassword"
                                    aria-label="Toggle password visibility" tabindex="-1">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/password-hide.svg" alt=""
                                        id="branchPasswordIcon">
                                </button>
                            </div>
                            <div class="form-error" id="branchPasswordError"></div>
                        </div>

                        <div class="branch-phase-actions">
                            <button type="button" class="btn btn-outline" id="branchPhase2Back">
                                Back
                            </button>
                            <button type="submit" class="btn btn-primary" id="branchSignInBtn">
                                Sign In
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="sign-in-footer">
                <p class="text-muted">
                    Want to join FitPal? <a href="sign-up.php">Register your restaurant</a>
                </p>
                <p class="text-muted">
                    Not a restaurant?
                    <a href="<?php echo $assetBase; ?>../customer/pages/sign-in.php">Customer sign-in</a>
                </p>
            </div>
        </div>
    </div>
</div>

<script src="../assets/ui/js/sign-in.js" defer></script>

<?php require_once __DIR__ . '/../../shared/includes/footer.php'; ?>