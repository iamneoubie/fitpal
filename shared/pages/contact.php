<?php
/**
 * FitPal Contact Page
 * 
 * This is the public contact page that provides contact information and a form.
 * 
 * Usage: Direct access via web browser
 * 
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include shared header
require_once __DIR__ . '/../includes/header.php';

/**
 * Get the base path for assets based on current file location
 * 
 * @return string The asset base path
 */
function getContactAssetBase(): string {
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

// Contact information
$contactInfo = [
    'address' => '123 Health Street, San Miguel, Pasig City, Metro Manila, Philippines 1600',
    'phone' => '+63 (2) 8123 4567',
    'email' => 'support@fitpal.com',
    'hours' => 'Weekdays: 8:00 AM - 5:00 PM, Saturday: 8:00 AM - 12:00 PM'
];

// FAQ items
$faqItems = [
    [
        'question' => 'How do I create an account?',
        'answer' => 'Click the "Register" button in the navigation menu. Fill in your details, set your dietary preferences, and you are ready to start ordering.'
    ],
    [
        'question' => 'How do I find meals that fit my dietary needs?',
        'answer' => 'Use the dietary filters on the menu page. You can filter by vegan, keto, gluten-free, and other preferences.'
    ],
    [
        'question' => 'How do I add special instructions to my order?',
        'answer' => 'When placing an order, you can add special instructions in the checkout process. These are communicated directly to the kitchen.'
    ],
    [
        'question' => 'How do I track my order?',
        'answer' => 'Track your order in real-time from the "My Orders" page. You will see updates when the restaurant is preparing your food.'
    ]
];

// Handle form submission
$formSubmitted = false;
$formErrors = [];
$formSuccess = false;
$fullName = '';
$email = '';
$subject = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_contact'])) {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    
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
    
    if (empty($formErrors)) {
        error_log("Contact form submission from: {$fullName} ({$email}) - Subject: {$subject}");
        $formSuccess = true;
        $formSubmitted = true;
        $fullName = '';
        $email = '';
        $subject = '';
        $message = '';
    } else {
        $formSubmitted = true;
    }
}

// Get page-specific CSS
$pageCss = $assetBase . 'assets/css/contact.css';
?>
<!-- ============================================
    CONTENT WRAPPER
    ============================================ -->

<div class="content">

    <!-- Hero Section -->
    <section class="contact-hero" aria-labelledby="contact-hero-title">
        <div class="container">
            <div class="contact-hero-content">
                <p class="contact-hero-title" id="contact-hero-title">
                    Get in <span>Touch</span>
                </p>
                <p class="contact-hero-subtitle">
                    Have questions or feedback? Reach out to us!
                </p>
            </div>
        </div>
    </section>

    <!-- Contact Information Section -->
    <section class="contact-info-section" aria-labelledby="contact-info-title">
        <div class="container">
            <p class="sr-only" id="contact-info-title">Contact Information</p>
            <div class="contact-info-grid">
                <div class="contact-info-card">
                    <div class="contact-info-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/location.svg" alt="Location">
                    </div>
                    <p class="heading-6">Our Address</p>
                    <p><?php echo htmlspecialchars($contactInfo['address'], ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <div class="contact-info-card">
                    <div class="contact-info-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/phone.svg" alt="Phone">
                    </div>
                    <p class="heading-6">Phone Number</p>
                    <p>
                        <a href="tel:<?php echo str_replace([' ', '(', ')', '+'], '', $contactInfo['phone']); ?>">
                            <?php echo htmlspecialchars($contactInfo['phone'], ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    </p>
                </div>
                <div class="contact-info-card">
                    <div class="contact-info-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/email.svg" alt="Email">
                    </div>
                    <p class="heading-6">Email Address</p>
                    <p>
                        <a href="mailto:<?php echo htmlspecialchars($contactInfo['email'], ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($contactInfo['email'], ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    </p>
                </div>
                <div class="contact-info-card">
                    <div class="contact-info-icon">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/clock.svg" alt="Hours">
                    </div>
                    <p class="heading-6">Business Hours</p>
                    <p><?php echo htmlspecialchars($contactInfo['hours'], ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact Form Section -->
    <section class="contact-form-section" aria-labelledby="contact-form-title">
        <div class="container">
            <div class="contact-form-grid">
                <div class="contact-form-container">
                    <p class="heading-3" id="contact-form-title">Send Us a <span>Message</span></p>
                    <p class="contact-form-subtitle">
                        Fill out the form below and we will get back to you as soon as possible.
                    </p>

                    <?php if ($formSuccess): ?>
                    <div class="alert alert-success" role="alert">
                        <strong>Success!</strong> Your message has been sent successfully.
                    </div>
                    <?php endif; ?>

                    <?php if ($formSubmitted && !empty($formErrors)): ?>
                    <div class="alert alert-danger" role="alert">
                        <strong>Please fix the following errors:</strong>
                        <ul>
                            <?php foreach ($formErrors as $error): ?>
                            <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <form method="POST"
                        action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>"
                        class="contact-form" id="contactForm" novalidate>
                        <input type="hidden" name="submit_contact" value="1">

                        <div class="form-row">
                            <div class="form-group">
                                <label for="full_name" class="form-label">
                                    Full Name <span class="text-danger">*</span>
                                </label>
                                <input type="text" id="full_name" name="full_name"
                                    value="<?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>"
                                    class="form-control <?php echo isset($formErrors['full_name']) ? 'error' : ''; ?>"
                                    placeholder="Enter your full name" required>
                                <?php if (isset($formErrors['full_name'])): ?>
                                <div class="form-error">
                                    <?php echo htmlspecialchars($formErrors['full_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label for="email" class="form-label">
                                    Email Address <span class="text-danger">*</span>
                                </label>
                                <input type="email" id="email" name="email"
                                    value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>"
                                    class="form-control <?php echo isset($formErrors['email']) ? 'error' : ''; ?>"
                                    placeholder="Enter your email address" required>
                                <?php if (isset($formErrors['email'])): ?>
                                <div class="form-error">
                                    <?php echo htmlspecialchars($formErrors['email'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="subject" class="form-label">
                                Subject <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="subject" name="subject"
                                value="<?php echo htmlspecialchars($subject, ENT_QUOTES, 'UTF-8'); ?>"
                                class="form-control <?php echo isset($formErrors['subject']) ? 'error' : ''; ?>"
                                placeholder="What is your message about?" required>
                            <?php if (isset($formErrors['subject'])): ?>
                            <div class="form-error">
                                <?php echo htmlspecialchars($formErrors['subject'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="message" class="form-label">
                                Message <span class="text-danger">*</span>
                            </label>
                            <textarea id="message" name="message" rows="6"
                                class="form-control <?php echo isset($formErrors['message']) ? 'error' : ''; ?>"
                                placeholder="Write your message here..."
                                required><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <?php if (isset($formErrors['message'])): ?>
                            <div class="form-error">
                                <?php echo htmlspecialchars($formErrors['message'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php endif; ?>
                        </div>

                        <button type="submit" class="btn btn-primary btn-submit">
                            Send Message
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ Section -->
    <section class="faq-section" aria-labelledby="faq-title">
        <div class="container">
            <div class="section-header">
                <p class="section-title" id="faq-title">Frequently Asked <span>Questions</span></p>
                <p class="section-subtitle">Find answers to common questions</p>
            </div>
            <div class="faq-grid">
                <?php foreach ($faqItems as $index => $faq): ?>
                <div class="faq-item">
                    <div class="faq-question" id="faq-question-<?php echo $index; ?>">
                        <p class="heading-6"><?php echo htmlspecialchars($faq['question'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <button class="faq-toggle" aria-expanded="false"
                            aria-controls="faq-answer-<?php echo $index; ?>" type="button">
                            <span class="faq-toggle-icon"></span>
                            <span class="sr-only">Toggle answer</span>
                        </button>
                    </div>
                    <div class="faq-answer" id="faq-answer-<?php echo $index; ?>" role="region">
                        <p><?php echo htmlspecialchars($faq['answer'], ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="contact-cta" aria-labelledby="cta-title">
        <div class="container">
            <div class="contact-cta-content">
                <p class="heading-2" id="cta-title">Want to join as a restaurant?</p>
                <p class="contact-cta-description">Register your restaurant and showcase your healthy menu options.</p>
                <div class="contact-cta-actions">
                    <a href="<?php echo $assetBase; ?>../restaurant/pages/register.php" class="btn btn-primary btn-lg">
                        Register Restaurant
                    </a>
                    <a href="<?php echo $assetBase; ?>../customer/pages/login.php" class="btn btn-outline btn-lg">
                        Sign In
                    </a>
                </div>
            </div>
        </div>
    </section>

</div>

<?php
// Include shared footer
require_once __DIR__ . '/../includes/footer.php';
?>