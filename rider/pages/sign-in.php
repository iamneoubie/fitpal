<?php
/**
 * FitPal Rider Sign-In Page
 *
 * Uses the shared rider header + footer. All icons and images come
 * from the shared assets folder so nothing loads from an external
 * host. The country selector lists Asian countries only.
 *
 * @package FitPal
 * @version 4.0 — Asian-only country list; real <select> element;
 *                aligns flash-message keys with the customer role.
 */

declare(strict_types=1);

// ===== HEADER (starts session, loads DB, renders <head> + <header>) =====
require_once __DIR__ . '/../includes/header.php';

// Defensive: if the header ever fails to redirect a logged-in rider.
if (!empty($_SESSION['delivery_rider_id'])) {
    header('Location: dashboard.php');
    exit;
}

// ===== CSRF =====
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ===== FLASH MESSAGES =====
$errorMessage = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);

$successMessage = $_SESSION['registration_success'] ?? '';
unset($_SESSION['registration_success']);

// ===== COUNTRY LIST (Asia only) =====
$asianCountries = [
    'PH' => 'Philippines',
    'SG' => 'Singapore',
    'MY' => 'Malaysia',
    'ID' => 'Indonesia',
    'TH' => 'Thailand',
    'VN' => 'Vietnam',
    'JP' => 'Japan',
    'KR' => 'South Korea',
    'CN' => 'China',
    'HK' => 'Hong Kong',
    'TW' => 'Taiwan',
    'IN' => 'India',
    'BD' => 'Bangladesh',
    'PK' => 'Pakistan',
    'LK' => 'Sri Lanka',
    'NP' => 'Nepal',
    'MM' => 'Myanmar',
    'KH' => 'Cambodia',
    'LA' => 'Laos',
    'BN' => 'Brunei',
    'AE' => 'United Arab Emirates',
    'SA' => 'Saudi Arabia',
    'QA' => 'Qatar',
    'KW' => 'Kuwait',
    'BH' => 'Bahrain',
    'OM' => 'Oman',
    'TR' => 'Turkey',
];

// Remember the previously chosen country across failed submissions.
$selectedCountry = $_POST['country'] ?? 'PH';
if (!isset($asianCountries[$selectedCountry])) {
    $selectedCountry = 'PH';
}

// ===== ILLUSTRATION PATH =====
$riderIllustration = $assetBase . 'assets/images/rider-image/rider.png';
$heroFallback      = $assetBase . 'assets/images/showcase/hero-image.png';
?>

<div class="content rider-auth-content-wrap">
    <main class="rider-auth-page" role="main">
        <div class="rider-auth-card">

            <!-- Illustration -->
            <div class="rider-illustration">
                <img src="<?php echo htmlspecialchars($riderIllustration, ENT_QUOTES, 'UTF-8'); ?>"
                    alt="Rider on a bicycle"
                    onerror="this.onerror=null; this.src='<?php echo htmlspecialchars($heroFallback, ENT_QUOTES, 'UTF-8'); ?>';">
            </div>

            <!-- Content -->
            <div class="rider-auth-content">
                <h1 class="rider-auth-title">
                    Welcome to<br>
                    <span>FitPal</span> rider app
                </h1>

                <?php if (!empty($successMessage)): ?>
                <div class="rider-alert rider-alert-success" role="alert">
                    <?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($errorMessage)): ?>
                <div class="rider-alert rider-alert-danger" role="alert">
                    <?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="../backend/handlers/sign-in-handler.php" id="riderSignInForm" novalidate>

                    <input type="hidden" name="csrf_token"
                        value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

                    <!-- Country selector (Asian countries only) -->
                    <div class="rider-country-selector">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/location-fill.svg" alt=""
                            class="rider-country-icon" aria-hidden="true">
                        <select id="country" name="country" class="rider-country-select" aria-label="Select country">
                            <?php foreach ($asianCountries as $code => $name): ?>
                            <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>"
                                <?php echo $code === $selectedCountry ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <img src="<?php echo $assetBase; ?>assets/images/icons/arrow-drop-down-line.svg" alt=""
                            class="rider-chevron-icon" aria-hidden="true">
                    </div>

                    <div class="rider-form-group">
                        <label for="identifier" class="sr-only">Email or Username</label>
                        <input type="text" id="identifier" name="identifier" class="rider-form-control"
                            placeholder="Email or username" autocomplete="username"
                            value="<?php echo htmlspecialchars($_POST['identifier'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            required>
                    </div>

                    <div class="rider-form-group">
                        <label for="password" class="sr-only">Password</label>
                        <input type="password" id="password" name="password" class="rider-form-control"
                            placeholder="Password" autocomplete="current-password" required>
                    </div>

                    <button type="submit" class="rider-btn rider-btn-dark">Sign in</button>
                    <a href="sign-up.php" class="rider-btn rider-btn-light">Apply now</a>
                </form>
            </div>

            <div class="rider-version">v1.0.0</div>
        </div>
    </main>
</div>

<script src="../assets/ui/js/sign-in.js" defer></script>

<?php
require_once __DIR__ . '/../../shared/includes/footer.php';
?>