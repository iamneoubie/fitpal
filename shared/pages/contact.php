<?php
/**
 * FitPal Contact Page
 *
 * Public contact page. Belongs to the public context and runs on
 * its own PHP session (PHPSESSID_PUBLIC), separate from every
 * authenticated role's session.
 *
 * ---------------------------------------------------------------------
 * PAGE STRUCTURE
 * ---------------------------------------------------------------------
 *   .content
 *     .contact-hero              gradient intro band
 *       .container               inner 1200px column
 *     .contact-methods           white band, four method cards
 *       .container               inner 1200px column
 *     .contact-split             gray band, two cards side by side
 *       .container               inner 1200px column, 2-col grid
 *         .contact-form-section  "Send Us a Message" card (left)
 *         .faq-section           "Frequently Asked Questions" (right)
 *     .contact-cta               gradient closing band
 *       .container               inner 1200px column
 *
 * Every band that renders content wraps its content in a
 * .container. The .container class (defined in global.css) is
 * the same 1200px / centered / side-padded column the header
 * uses, so everything on this page aligns to the same X bounds
 * as the header's logo and nav.
 *
 * ---------------------------------------------------------------------
 * THE FORM IS A SIMULATION
 * ---------------------------------------------------------------------
 * This is a demonstration project. There is no contact mailbox,
 * no support table, and no SMTP. The form therefore:
 *
 *   - Validates the input on the server via a page-local POST
 *     handler written at the top of this file, before any output.
 *   - On success, renders a success alert and clears the fields.
 *   - Does NOT send email, does NOT write to any table, does NOT
 *     call any external handler file.
 *
 * The form card body always renders a demonstration notice
 * explaining this. When a submission has just happened, the
 * outcome alert (success or error) renders directly below the
 * notice.
 *
 * The form's `action=""` attribute is deliberate. It posts back
 * to the same URL, which re-enters this file and runs the
 * page-local handler. Do not change it to point at an external
 * handler; no such handler exists for this page.
 *
 * ---------------------------------------------------------------------
 * PER-ROLE SESSION MODEL (Option B)
 * ---------------------------------------------------------------------
 * The page bootstraps the public session before doing anything
 * else. The CSRF token lives under 'public_csrf_token' inside
 * PHPSESSID_PUBLIC. The POST field name stays generic
 * ('csrf_token') per §10 of instructions/general.md.
 *
 * ---------------------------------------------------------------------
 * FILE SEPARATION (§5)
 * ---------------------------------------------------------------------
 *   - No inline CSS. contact.css is loaded by the shared header
 *     via its $pageCssMap.
 *   - No inline JS. contact.js is loaded at the bottom via a
 *     single <script src="..."> tag.
 *   - No SQL. No handler file. The whole form round-trip lives
 *     on this page.
 *
 * ---------------------------------------------------------------------
 *
 * @package FitPal
 * @version 7.2 — Wrapped the split's two cards in a .container so
 *                they align to the same 1200px maximum width the
 *                header, method strip, and CTA use. Without this
 *                wrapper, the previous revision's two cards
 *                extended past the header's width.
 *
 *                (7.1: reordered notices. 7.0: split layout.
 *                6.0: from-scratch rethink. 4.0: per-role session
 *                migration. 3.0: public session bootstrap. 1.0:
 *                initial.)
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// SESSION BOOTSTRAP
// ---------------------------------------------------------------------

require_once __DIR__ . '/../includes/session-bootstrap.php';
fitpal_session_bootstrap('public');

// ---------------------------------------------------------------------
// CSRF — public context
// ---------------------------------------------------------------------

require_once __DIR__ . '/../includes/public-csrf-token.php';
$csrfToken     = getPublicCsrfToken();
$csrfAvailable = ($csrfToken !== '');

// ---------------------------------------------------------------------
// PAGE-LOCAL FORM HANDLER
//
// Runs before any output. On validation failure, preserves the
// submitted values so the form re-renders with them. On success,
// clears the values, logs the attempt, and rotates the public
// CSRF token.
//
// This is a SIMULATION. No email is sent, no row is written, no
// external handler is called.
// ---------------------------------------------------------------------

$formSubmitted = false;
$formSuccess   = false;
$formErrors    = [];

$fullName = '';
$email    = '';
$subject  = '';
$message  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_contact'])) {
    $formSubmitted = true;

    $givenToken = (string)($_POST['csrf_token'] ?? '');
    $sessToken  = (string)($_SESSION['public_csrf_token'] ?? '');

    if (
        $sessToken === ''
        || $givenToken === ''
        || !hash_equals($sessToken, $givenToken)
    ) {
        unset($_SESSION['public_csrf_token']);
        $csrfToken     = getPublicCsrfToken();
        $csrfAvailable = ($csrfToken !== '');

        $formErrors['general'] = 'Security validation failed. Please try again.';
    } else {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $email    = trim((string)($_POST['email']     ?? ''));
        $subject  = trim((string)($_POST['subject']   ?? ''));
        $message  = trim((string)($_POST['message']   ?? ''));

        if ($fullName === '') {
            $formErrors['full_name'] = 'Full name is required.';
        } elseif (mb_strlen($fullName) < 2) {
            $formErrors['full_name'] = 'Full name must be at least 2 characters.';
        }

        if ($email === '') {
            $formErrors['email'] = 'Email address is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $formErrors['email'] = 'Please enter a valid email address.';
        }

        if ($subject === '') {
            $formErrors['subject'] = 'Subject is required.';
        } elseif (mb_strlen($subject) < 3) {
            $formErrors['subject'] = 'Subject must be at least 3 characters.';
        }

        if ($message === '') {
            $formErrors['message'] = 'Message is required.';
        } elseif (mb_strlen($message) < 10) {
            $formErrors['message'] = 'Message must be at least 10 characters.';
        }

        if (empty($formErrors)) {
            error_log(sprintf(
                'Contact form simulation: "%s" <%s> — subject "%s"',
                $fullName,
                $email,
                $subject
            ));

            $formSuccess = true;
            $fullName    = '';
            $email       = '';
            $subject     = '';
            $message     = '';

            unset($_SESSION['public_csrf_token']);
            $csrfToken     = getPublicCsrfToken();
            $csrfAvailable = ($csrfToken !== '');
        }
    }
}

// ---------------------------------------------------------------------
// STATIC PAGE CONTENT
// ---------------------------------------------------------------------

$contactMethods = [
    [
        'label'  => 'Visit Us',
        'icon'   => 'location-fill.svg',
        'lines'  => [
            '123 Health Street',
            'San Miguel, Pasig City',
            'Metro Manila 1600',
        ],
        'href'   => '',
    ],
    [
        'label'  => 'Call Us',
        'icon'   => 'phone-fill.svg',
        'lines'  => ['+63 (2) 8123 4567'],
        'href'   => 'tel:+63281234567',
    ],
    [
        'label'  => 'Email Us',
        'icon'   => 'mail.svg',
        'lines'  => ['support@fitpal.com'],
        'href'   => 'mailto:support@fitpal.com',
    ],
    [
        'label'  => 'Office Hours',
        'icon'   => 'time-update.svg',
        'lines'  => [
            'Mon – Fri: 8:00 AM – 5:00 PM',
            'Saturday: 8:00 AM – 12:00 PM',
        ],
        'href'   => '',
    ],
];

$faqItems = [
    [
        'question' => 'How do I create an account?',
        'answer'   => 'Click the “Register” button in the navigation menu. Fill in your details, set your dietary preferences, and you are ready to start ordering.',
    ],
    [
        'question' => 'How do I find meals that fit my dietary needs?',
        'answer'   => 'Use the dietary filters on the menu page. You can filter by vegan, keto, gluten-free, and other preferences.',
    ],
    [
        'question' => 'How do I add special instructions to my order?',
        'answer'   => 'When placing an order, you can add special instructions in the checkout process. These are communicated directly to the kitchen.',
    ],
    [
        'question' => 'How do I track my order?',
        'answer'   => 'Track your order in real time from the “My Orders” page. You will see updates when the restaurant is preparing your food and when a rider is on the way.',
    ],
];

// ---------------------------------------------------------------------
// HEADER
// ---------------------------------------------------------------------

require_once __DIR__ . '/../includes/header.php';
?>

<div class="content">

    <!-- ============================================================
         HERO
         ============================================================ -->
    <section class="contact-hero" aria-labelledby="contact-hero-title">
        <div class="container">
            <div class="contact-hero-inner">
                <p class="contact-hero-title" id="contact-hero-title">
                    Get in <span>Touch</span>
                </p>
                <p class="contact-hero-subtitle">
                    Have a question, a suggestion, or feedback?
                    We would love to hear from you.
                </p>
            </div>
        </div>
    </section>

    <!-- ============================================================
         CONTACT METHODS
         ============================================================ -->
    <section class="contact-methods" aria-labelledby="contact-methods-title">
        <div class="container">
            <p class="sr-only" id="contact-methods-title">Contact Methods</p>

            <div class="methods-grid">
                <?php foreach ($contactMethods as $method): ?>
                <div class="method-card">
                    <div class="method-icon" aria-hidden="true">
                        <img src="<?php echo $assetBase; ?>assets/images/icons/<?php echo htmlspecialchars($method['icon'], ENT_QUOTES, 'UTF-8'); ?>"
                            alt="" width="24" height="24"
                            onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                    </div>

                    <p class="method-label">
                        <?php echo htmlspecialchars($method['label'], ENT_QUOTES, 'UTF-8'); ?>
                    </p>

                    <div class="method-lines">
                        <?php if ($method['href'] !== ''): ?>
                        <a href="<?php echo htmlspecialchars($method['href'], ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars(implode(' ', $method['lines']), ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                        <?php else: ?>
                        <?php foreach ($method['lines'] as $line): ?>
                        <p><?php echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ============================================================
         SPLIT: FORM (left) + FAQ (right)
         ============================================================
         The .container inside the band is what keeps the two
         cards inside the same 1200px column the header, method
         strip, and CTA all use. The grid that actually splits
         them is on the .container. -->
    <div class="contact-split">
        <div class="container contact-split-grid">

            <!-- ---- Form card ---- -->
            <section class="contact-form-section" aria-labelledby="contact-form-title">
                <div class="form-card">

                    <header class="form-card-head">
                        <div class="form-card-icon" aria-hidden="true">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/mail.svg" alt="" width="22"
                                height="22"
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                        </div>
                        <div class="form-card-text">
                            <p class="form-card-title" id="contact-form-title">
                                Send Us a <span>Message</span>
                            </p>
                            <p class="form-card-subtitle">
                                Fill out the form and we will get back to you.
                            </p>
                        </div>
                    </header>

                    <div class="form-card-body">

                        <div class="form-notice" role="note">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/information-fill.svg" alt=""
                                width="18" height="18" class="form-notice-icon"
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                            <p class="form-notice-text">
                                <strong>Demonstration notice.</strong>
                                This form is a simulation. Submitting it validates your input and
                                shows a confirmation, but no message is sent and nothing is stored.
                            </p>
                        </div>

                        <?php if (!$csrfAvailable): ?>

                        <div class="alert alert-danger" role="alert">
                            <strong>Configuration error.</strong>
                            The contact form is temporarily unavailable. Please try again later.
                        </div>

                        <?php else: ?>

                        <?php if ($formSuccess): ?>
                        <div class="alert alert-success" role="alert">
                            <strong>Thank you!</strong>
                            Your message was received (simulation only — nothing was sent).
                        </div>
                        <?php endif; ?>

                        <?php if ($formSubmitted && !empty($formErrors)): ?>
                        <div class="alert alert-danger" role="alert">
                            <strong>Please fix the following:</strong>
                            <ul>
                                <?php foreach ($formErrors as $error): ?>
                                <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>

                        <form method="POST" action="" class="contact-form" id="contactForm" novalidate>

                            <input type="hidden" name="csrf_token"
                                value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="submit_contact" value="1">

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="full_name" class="form-label">Full Name</label>
                                    <input type="text" id="full_name" name="full_name"
                                        value="<?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>"
                                        class="form-control <?php echo isset($formErrors['full_name']) ? 'error' : ''; ?>"
                                        placeholder="Enter your full name" autocomplete="name" required>
                                    <?php if (isset($formErrors['full_name'])): ?>
                                    <div class="form-error">
                                        <?php echo htmlspecialchars($formErrors['full_name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <div class="form-group">
                                    <label for="email" class="form-label">Email Address</label>
                                    <input type="email" id="email" name="email"
                                        value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>"
                                        class="form-control <?php echo isset($formErrors['email']) ? 'error' : ''; ?>"
                                        placeholder="Enter your email address" autocomplete="email" required>
                                    <?php if (isset($formErrors['email'])): ?>
                                    <div class="form-error">
                                        <?php echo htmlspecialchars($formErrors['email'], ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="subject" class="form-label">Subject</label>
                                <input type="text" id="subject" name="subject"
                                    value="<?php echo htmlspecialchars($subject, ENT_QUOTES, 'UTF-8'); ?>"
                                    class="form-control <?php echo isset($formErrors['subject']) ? 'error' : ''; ?>"
                                    placeholder="What is your message about?" required>
                                <?php if (isset($formErrors['subject'])): ?>
                                <div class="form-error">
                                    <?php echo htmlspecialchars($formErrors['subject'], ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label for="message" class="form-label">Message</label>
                                <textarea id="message" name="message" rows="6"
                                    class="form-control <?php echo isset($formErrors['message']) ? 'error' : ''; ?>"
                                    placeholder="Write your message here..."
                                    required><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></textarea>
                                <?php if (isset($formErrors['message'])): ?>
                                <div class="form-error">
                                    <?php echo htmlspecialchars($formErrors['message'], ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <?php endif; ?>
                            </div>

                            <button type="submit" class="btn btn-primary btn-submit">
                                Send Message
                            </button>

                        </form>

                        <?php endif; ?>
                    </div>

                </div>
            </section>

            <!-- ---- FAQ card ---- -->
            <section class="faq-section" aria-labelledby="faq-title">
                <div class="faq-card">

                    <header class="faq-card-head">
                        <div class="faq-card-icon" aria-hidden="true">
                            <img src="<?php echo $assetBase; ?>assets/images/icons/question-fill.svg" alt="" width="22"
                                height="22"
                                onerror="this.onerror=null; this.src='<?php echo $assetBase; ?>assets/images/icons/file-warning-fill.svg'">
                        </div>
                        <div class="faq-card-text">
                            <p class="faq-card-title" id="faq-title">
                                Frequently Asked <span>Questions</span>
                            </p>
                            <p class="faq-card-subtitle">
                                Quick answers to the questions we hear most often.
                            </p>
                        </div>
                    </header>

                    <div class="faq-card-body">
                        <?php foreach ($faqItems as $index => $faq): ?>
                        <div class="faq-item">
                            <button type="button" class="faq-question" aria-expanded="false"
                                aria-controls="faq-answer-<?php echo (int)$index; ?>">
                                <span class="faq-question-text">
                                    <?php echo htmlspecialchars($faq['question'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <span class="faq-toggle" aria-hidden="true">
                                    <img src="<?php echo $assetBase; ?>assets/images/icons/add-line.svg" alt=""
                                        width="16" height="16" class="faq-toggle-icon"
                                        onerror="this.onerror=null; this.style.display='none';">
                                </span>
                            </button>
                            <div class="faq-answer" id="faq-answer-<?php echo (int)$index; ?>" hidden>
                                <p><?php echo htmlspecialchars($faq['answer'], ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                </div>
            </section>

        </div>
    </div>

    <!-- ============================================================
         CTA
         ============================================================ -->
    <section class="contact-cta" aria-labelledby="contact-cta-title">
        <div class="container">
            <div class="contact-cta-inner">
                <p class="contact-cta-title" id="contact-cta-title">
                    Want to join as a restaurant?
                </p>
                <p class="contact-cta-subtitle">
                    Register your restaurant and showcase your healthy menu
                    options to our community.
                </p>
                <div class="contact-cta-actions">
                    <a href="<?php echo $assetBase; ?>../restaurant/pages/sign-up.php" class="btn btn-primary btn-lg">
                        Register Restaurant
                    </a>
                    <a href="<?php echo $assetBase; ?>../customer/pages/sign-in.php" class="btn btn-outline btn-lg">
                        Sign In
                    </a>
                </div>
            </div>
        </div>
    </section>

</div>

<script src="<?php echo $assetBase; ?>assets/ui/js/contact.js" defer></script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>