<?php
/**
 * FitPal Shared Footer
 * 
 * This is the global footer component used across all roles and the public landing page.
 * 
 * Usage: require_once __DIR__ . '/shared/includes/footer.php';
 * 
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

// ===== PATH DETECTION =====
/**
 * Get the base path for assets based on current file location
 * 
 * @return string The asset base path
 */
function getFooterAssetBase(): string {
    $scriptPath = $_SERVER['SCRIPT_NAME'];
    $dirPath = dirname($scriptPath);
    $segments = array_filter(explode('/', $dirPath));
    $depth = count($segments);
    
    if ($depth <= 0) {
        return './shared/';
    }
    
    return str_repeat('../', $depth) . 'shared/';
}

$assetBase = getFooterAssetBase();
?>
</main> <!-- Close main-content from header.php -->

<footer class="footer" role="contentinfo">
    <div class="footer-upper">
        <div class="footer-brand">
            <a href="<?php echo $assetBase; ?>../index.php" class="footer-logo">
                <span class="footer-logo-text">Fit<span>Pal</span></span>
            </a>
            <p class="footer-description">
                Order healthy meals and track your nutrition with confidence.
            </p>
        </div>
        <div class="footer-social">
            <a href="https://www.facebook.com/" target="_blank" rel="noopener noreferrer" aria-label="Facebook">
                <img src="<?php echo $assetBase; ?>assets/images/icons/facebook.svg" alt="Facebook">
            </a>
            <a href="https://www.instagram.com/" target="_blank" rel="noopener noreferrer" aria-label="Instagram">
                <img src="<?php echo $assetBase; ?>assets/images/icons/instagram.svg" alt="Instagram">
            </a>
            <a href="https://twitter.com/" target="_blank" rel="noopener noreferrer" aria-label="Twitter">
                <img src="<?php echo $assetBase; ?>assets/images/icons/twitter.svg" alt="Twitter">
            </a>
            <a href="https://youtube.com/" target="_blank" rel="noopener noreferrer" aria-label="YouTube">
                <img src="<?php echo $assetBase; ?>assets/images/icons/youtube.svg" alt="YouTube">
            </a>
        </div>
    </div>
    <div class="footer-lower">
        <div class="footer-links">
            <a href="<?php echo $assetBase; ?>pages/privacy_policy.php">Privacy Policy</a>
            <a href="<?php echo $assetBase; ?>pages/terms_conditions.php">Terms & Conditions</a>
            <a href="<?php echo $assetBase; ?>pages/contact.php">Contact</a>
        </div>
        <div class="footer-copyright">
            &copy; <?php echo date('Y'); ?> FitPal. All rights reserved.
        </div>
    </div>
</footer>

<!-- Footer CSS -->
<link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/footer.css">

</body>

</html>