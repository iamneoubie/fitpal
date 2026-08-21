<?php
/**
 * FitPal Contact Page
 * 
 * This is the public contact page that provides contact information,
 * a contact form, and location details for the platform.
 * 
 * Usage: Direct access via web browser or included via router
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include shared header
require_once __DIR__ . '/../includes/header.php';

// Get the base path for assets
function getContactAssetBase() {
    $scriptPath = $_SERVER['SCRIPT_NAME'];
    $dirPath = dirname($scriptPath);
    $segments = array_filter(explode('/', $dirPath));
    $depth = count($segments);
    
    if ($depth <= 0) {
        return './shared/';
    }
    
    return str_repeat('../', $depth) . 'shared/';
}

$assetBase = getContactAssetBase();

// Get the shared asset base
function getSharedAssetBase() {
    $scriptPath = $_SERVER['SCRIPT_NAME'];
    $dirPath = dirname($scriptPath);
    $segments = array_filter(explode('/', $dirPath));
    $depth = count($segments);
    
    if ($depth <= 0) {
        return './shared/';
    }
    
    return str_repeat('../', $depth) . 'shared/';
}

$sharedAssetBase = getSharedAssetBase();

// Handle form submission
$formSubmitted = false;
$formErrors = [];
$formSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_contact'])) {
    // CSRF protection would go here in production
    
    // Get form data
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    
    // Validate form data
    if (empty($fullName)) {
        $formErrors['full_name'] = 'Full name is required.';
    } elseif (strlen($fullName) < 2) {
        $formErrors['full_name'] = 'Full name must be at least 2 characters.';
    }
    
    if (empty($email)) {
        $formErrors['email'] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $formErrors['email'] = 'Please enter a valid email address.';
    }
    
    if (empty($subject)) {
        $formErrors['subject'] = 'Subject is required.';
    } elseif (strlen($subject) < 3) {
        $formErrors['subject'] = 'Subject must be at least 3 characters.';
    }
    
    if (empty($message)) {
        $formErrors['message'] = 'Message is required.';
    } elseif (strlen($message) < 10) {
        $formErrors['message'] = 'Message must be at least 10 characters.';
    }
    
    // If no errors, process the form
    if (empty($formErrors)) {
        // In production, this would send an email or save to database
        // For now, we'll just show a success message
        
        // Log the submission
        error_log("Contact form submission from: {$fullName} ({$email}) - Subject: {$subject}");
        
        $formSuccess = true;
        $formSubmitted = true;
        
        // Clear form data after success
        $fullName = '';
        $email = '';
        $subject = '';
        $message = '';
    } else {
        $formSubmitted = true;
    }
}

// Contact information
$contactInfo = [
    'address' => [
        'street' => '123 Health Street',
        'barangay' => 'San Miguel',
        'city' => 'Pasig City',
        'province' => 'Metro Manila',
        'country' => 'Philippines',
        'postal_code' => '1600'
    ],
    'phone' => '+63 (2) 8123 4567',
    'email' => 'support@fitpal.com',
    'hours' => [
        'weekdays' => '8:00 AM - 5:00 PM',
        'saturday' => '8:00 AM - 12:00 PM',
        'sunday' => 'Closed'
    ]
];

// Social media links
$socialLinks = [
    'facebook' => 'https://facebook.com/fitpal',
    'instagram' => 'https://instagram.com/fitpal',
    'twitter' => 'https://twitter.com/fitpal',
    'youtube' => 'https://youtube.com/fitpal'
];

// FAQ items for accordion
$faqItems = [
    [
        'question' => 'How do I create an account?',
        'answer' => 'You can create an account by clicking the "Register" button in the navigation menu. Fill in your details, set your dietary preferences, and you are ready to start ordering healthy meals.'
    ],
    [
        'question' => 'How do I find meals that fit my dietary needs?',
        'answer' => 'Use the dietary filters on the menu page. You can filter by vegan, keto, gluten-free, and other dietary preferences. You can also set your allergies in your profile for automatic filtering.'
    ],
    [
        'question' => 'How do I add special instructions to my order?',
        'answer' => 'When placing an order, you can add special instructions in the checkout process. These instructions are communicated directly to the restaurant kitchen and the delivery rider.'
    ],
    [
        'question' => 'How do I track my order?',
        'answer' => 'You can track your order in real-time from the "My Orders" page. You will see updates when the restaurant is preparing your food and when the rider is delivering it.'
    ],
    [
        'question' => 'What payment methods are accepted?',
        'answer' => 'We accept Cash on Delivery (COD), Wallet payments, and Online payments. You can add funds to your wallet using the payment QR code provided.'
    ],
    [
        'question' => 'How do I become a restaurant partner?',
        'answer' => 'To become a restaurant partner, please contact our support team through this form or email us at partners@fitpal.com. We will guide you through the onboarding process.'
    ]
];
?>

<!-- ============================================
    CONTACT PAGE CSS (Page-specific styles)
    ============================================ -->
<link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/contact.css">
<link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/footer.css">

<!-- ============================================
    CONTACT PAGE CONTENT
    ============================================ -->

<!-- Hero Section -->
<section class="contact-hero">
    <div class="container">
        <div class="contact-hero-content">
            <h1 class="contact-hero-title">
                Get in <span>Touch</span>
            </h1>
            <p class="contact-hero-subtitle">
                Have questions, feedback, or partnership inquiries? Reach out to us!
            </p>
        </div>
    </div>
</section>

<!-- Contact Information Section -->
<section class="contact-info-section">
    <div class="container">
        <div class="contact-info-grid">
            <div class="contact-info-card">
                <div class="contact-info-icon">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/location.svg" alt="Location">
                </div>
                <h3>Our Address</h3>
                <p>
                    <?php echo htmlspecialchars($contactInfo['address']['street']); ?><br>
                    <?php echo htmlspecialchars($contactInfo['address']['barangay']); ?>,
                    <?php echo htmlspecialchars($contactInfo['address']['city']); ?><br>
                    <?php echo htmlspecialchars($contactInfo['address']['province']); ?>,
                    <?php echo htmlspecialchars($contactInfo['address']['country']); ?>
                    <?php echo htmlspecialchars($contactInfo['address']['postal_code']); ?>
                </p>
            </div>

            <div class="contact-info-card">
                <div class="contact-info-icon">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/phone.svg" alt="Phone">
                </div>
                <h3>Phone Number</h3>
                <p>
                    <a href="tel:<?php echo str_replace([' ', '(', ')', '+'], '', $contactInfo['phone']); ?>">
                        <?php echo htmlspecialchars($contactInfo['phone']); ?>
                    </a>
                </p>
            </div>

            <div class="contact-info-card">
                <div class="contact-info-icon">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/email.svg" alt="Email">
                </div>
                <h3>Email Address</h3>
                <p>
                    <a href="mailto:<?php echo htmlspecialchars($contactInfo['email']); ?>">
                        <?php echo htmlspecialchars($contactInfo['email']); ?>
                    </a>
                </p>
            </div>

            <div class="contact-info-card">
                <div class="contact-info-icon">
                    <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/clock.svg" alt="Hours">
                </div>
                <h3>Business Hours</h3>
                <p>
                    <strong>Weekdays:</strong> <?php echo htmlspecialchars($contactInfo['hours']['weekdays']); ?><br>
                    <strong>Saturday:</strong> <?php echo htmlspecialchars($contactInfo['hours']['saturday']); ?><br>
                    <strong>Sunday:</strong> <?php echo htmlspecialchars($contactInfo['hours']['sunday']); ?>
                </p>
            </div>
        </div>
    </div>
</section>

<!-- Contact Form and Map Section -->
<section class="contact-form-section">
    <div class="container">
        <div class="contact-form-grid">
            <!-- Contact Form -->
            <div class="contact-form-container">
                <h2>Send Us a <span>Message</span></h2>
                <p class="contact-form-subtitle">
                    Fill out the form below and we will get back to you as soon as possible.
                </p>

                <?php if ($formSuccess): ?>
                <div class="alert alert-success">
                    <strong>Success!</strong> Your message has been sent successfully. We will get back to you within 24
                    hours.
                </div>
                <?php endif; ?>

                <?php if ($formSubmitted && !empty($formErrors)): ?>
                <div class="alert alert-danger">
                    <strong>Please fix the following errors:</strong>
                    <ul>
                        <?php foreach ($formErrors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>" class="contact-form" id="contactForm"
                    novalidate>
                    <input type="hidden" name="submit_contact" value="1">

                    <div class="form-row">
                        <div class="form-group">
                            <label for="full_name">Full Name <span class="required">*</span></label>
                            <input type="text" id="full_name" name="full_name"
                                value="<?php echo htmlspecialchars($fullName ?? ''); ?>"
                                class="form-control <?php echo isset($formErrors['full_name']) ? 'error' : ''; ?>"
                                placeholder="Enter your full name" required>
                            <?php if (isset($formErrors['full_name'])): ?>
                            <div class="form-error"><?php echo htmlspecialchars($formErrors['full_name']); ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address <span class="required">*</span></label>
                            <input type="email" id="email" name="email"
                                value="<?php echo htmlspecialchars($email ?? ''); ?>"
                                class="form-control <?php echo isset($formErrors['email']) ? 'error' : ''; ?>"
                                placeholder="Enter your email address" required>
                            <?php if (isset($formErrors['email'])): ?>
                            <div class="form-error"><?php echo htmlspecialchars($formErrors['email']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="subject">Subject <span class="required">*</span></label>
                        <input type="text" id="subject" name="subject"
                            value="<?php echo htmlspecialchars($subject ?? ''); ?>"
                            class="form-control <?php echo isset($formErrors['subject']) ? 'error' : ''; ?>"
                            placeholder="What is your message about?" required>
                        <?php if (isset($formErrors['subject'])): ?>
                        <div class="form-error"><?php echo htmlspecialchars($formErrors['subject']); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="message">Message <span class="required">*</span></label>
                        <textarea id="message" name="message" rows="6"
                            class="form-control <?php echo isset($formErrors['message']) ? 'error' : ''; ?>"
                            placeholder="Write your message here..."
                            required><?php echo htmlspecialchars($message ?? ''); ?></textarea>
                        <?php if (isset($formErrors['message'])): ?>
                        <div class="form-error"><?php echo htmlspecialchars($formErrors['message']); ?></div>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-primary btn-submit">
                        Send Message
                    </button>
                </form>
            </div>

            <!-- Map and Additional Info -->
            <div class="contact-side-info">
                <div class="contact-map-container">
                    <div class="contact-map-placeholder">
                        <div class="map-icon">
                            <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/map.svg" alt="Map">
                        </div>
                        <p>Interactive Map</p>
                        <span class="map-hint">(Map integration coming soon)</span>
                    </div>
                </div>

                <div class="contact-social-links">
                    <h3>Connect With Us</h3>
                    <div class="social-links-grid">
                        <a href="<?php echo htmlspecialchars($socialLinks['facebook']); ?>" target="_blank"
                            rel="noopener noreferrer" class="social-link facebook">
                            <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/facebook.svg" alt="Facebook">
                            <span>Facebook</span>
                        </a>
                        <a href="<?php echo htmlspecialchars($socialLinks['instagram']); ?>" target="_blank"
                            rel="noopener noreferrer" class="social-link instagram">
                            <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/instagram.svg" alt="Instagram">
                            <span>Instagram</span>
                        </a>
                        <a href="<?php echo htmlspecialchars($socialLinks['twitter']); ?>" target="_blank"
                            rel="noopener noreferrer" class="social-link twitter">
                            <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/twitter.svg" alt="Twitter">
                            <span>Twitter</span>
                        </a>
                        <a href="<?php echo htmlspecialchars($socialLinks['youtube']); ?>" target="_blank"
                            rel="noopener noreferrer" class="social-link youtube">
                            <img src="<?php echo $sharedAssetBase; ?>assets/images/icons/youtube.svg" alt="YouTube">
                            <span>YouTube</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FAQ Section -->
<section class="faq-section">
    <div class="container">
        <div class="section-header">
            <h2 class="section-title">Frequently Asked <span>Questions</span></h2>
            <p class="section-subtitle">Find answers to common questions</p>
        </div>
        <div class="faq-grid">
            <?php foreach ($faqItems as $index => $faq): ?>
            <div class="faq-item">
                <div class="faq-question" id="faq-question-<?php echo $index; ?>">
                    <h3><?php echo htmlspecialchars($faq['question']); ?></h3>
                    <button class="faq-toggle" aria-expanded="false" aria-controls="faq-answer-<?php echo $index; ?>">
                        <span class="faq-toggle-icon"></span>
                    </button>
                </div>
                <div class="faq-answer" id="faq-answer-<?php echo $index; ?>" role="region">
                    <p><?php echo htmlspecialchars($faq['answer']); ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- CTA Section -->
<section class="contact-cta">
    <div class="container">
        <div class="contact-cta-content">
            <h2>Want to partner with us?</h2>
            <p>Restaurants, riders, and businesses - we would love to collaborate with you!</p>
            <div class="contact-cta-actions">
                <a href="mailto:partners@fitpal.com" class="btn btn-primary btn-lg">
                    Partner With Us
                </a>
                <a href="<?php echo $assetBase; ?>../restaurant/pages/register.php" class="btn btn-outline btn-lg">
                    Register Restaurant
                </a>
            </div>
        </div>
    </div>
</section>

<!-- ============================================
    CONTACT PAGE JAVASCRIPT
    ============================================ -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    'use strict';

    // ============================================
    // FAQ Accordion Functionality
    // ============================================
    const faqToggles = document.querySelectorAll('.faq-toggle');

    faqToggles.forEach(function(toggle) {
        toggle.addEventListener('click', function() {
            const faqItem = this.closest('.faq-item');
            const faqAnswer = faqItem.querySelector('.faq-answer');
            const isExpanded = this.getAttribute('aria-expanded') === 'true';

            // Close other open FAQs
            document.querySelectorAll('.faq-item.open').forEach(function(item) {
                if (item !== faqItem) {
                    const otherToggle = item.querySelector('.faq-toggle');
                    const otherAnswer = item.querySelector('.faq-answer');
                    otherToggle.setAttribute('aria-expanded', 'false');
                    otherAnswer.style.maxHeight = '0';
                    otherAnswer.style.opacity = '0';
                    item.classList.remove('open');
                }
            });

            // Toggle this FAQ
            if (isExpanded) {
                this.setAttribute('aria-expanded', 'false');
                faqAnswer.style.maxHeight = '0';
                faqAnswer.style.opacity = '0';
                faqItem.classList.remove('open');
            } else {
                this.setAttribute('aria-expanded', 'true');
                faqAnswer.style.maxHeight = faqAnswer.scrollHeight + 'px';
                faqAnswer.style.opacity = '1';
                faqItem.classList.add('open');
            }
        });
    });

    // ============================================
    // Form Validation
    // ============================================
    const contactForm = document.getElementById('contactForm');

    if (contactForm) {
        contactForm.addEventListener('submit', function(e) {
            let hasErrors = false;
            const requiredFields = this.querySelectorAll('[required]');

            requiredFields.forEach(function(field) {
                if (!field.value.trim()) {
                    field.classList.add('error');
                    hasErrors = true;
                } else {
                    field.classList.remove('error');
                }

                // Email validation
                if (field.type === 'email' && field.value.trim()) {
                    const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!emailPattern.test(field.value.trim())) {
                        field.classList.add('error');
                        hasErrors = true;
                    }
                }
            });

            if (hasErrors) {
                e.preventDefault();
                // Scroll to first error
                const firstError = document.querySelector('.form-control.error');
                if (firstError) {
                    firstError.focus();
                }
            }
        });

        // Remove error state on input
        contactForm.querySelectorAll('.form-control').forEach(function(field) {
            field.addEventListener('input', function() {
                this.classList.remove('error');
            });
        });
    }

    console.log('Contact page JavaScript loaded');
});
</script>

<?php
// Include shared footer
require_once __DIR__ . '/../includes/footer.php';
?>