/**
 * FitPal Contact Page JavaScript
 *
 * This file owns exactly one behaviour: the FAQ accordion on
 * shared/pages/contact.php.
 *
 * ---------------------------------------------------------------------
 * WHAT THIS FILE DOES NOT DO
 * ---------------------------------------------------------------------
 * The contact form on this page is a server-rendered POST that
 * submits back to the page itself. It requires no JavaScript.
 * This file therefore does NOT:
 *
 *   - intercept the contact form's submit event
 *   - run any fetch() call against any endpoint
 *   - validate form fields client-side
 *   - reference any handler file
 *
 * Every submission path on this page is a normal browser POST
 * handled by contact.php itself, which performs the validation
 * and renders the outcome (success alert, error alert, or the
 * untouched form) server-side.
 *
 * ---------------------------------------------------------------------
 * FAQ ACCORDION
 * ---------------------------------------------------------------------
 * Each .faq-item contains a .faq-question button whose click
 * toggles the item's .open class and flips the .faq-answer's
 * `hidden` attribute. Opening one item closes every other open
 * item, so only one answer is visible at a time.
 *
 * The button's aria-expanded attribute is kept in sync with the
 * visual state. No inline CSS is written; the .open class is
 * defined in contact.css.
 *
 * @package FitPal
 * @version 4.0 — Rewritten against the from-scratch contact.php.
 *                Every selector in this file matches an element
 *                the page actually renders. The answer toggle now
 *                flips the element's `hidden` attribute in sync
 *                with the `.open` class.
 *
 *                (3.0: scoped the file to the FAQ accordion only.
 *                2.0: delegated listener on the FAQ container.
 *                1.0: initial.)
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        var faqItems = document.querySelectorAll('.faq-item');
        if (!faqItems.length) {
            return;
        }

        /**
         * Close every FAQ item except the one passed in. Every
         * item it closes has its button aria-expanded reset and
         * its answer element hidden.
         *
         * @param {HTMLElement} keepOpen
         */
        function closeOthers(keepOpen) {
            faqItems.forEach(function (item) {
                if (item === keepOpen) return;
                if (!item.classList.contains('open')) return;

                item.classList.remove('open');

                var button = item.querySelector('.faq-question');
                if (button) {
                    button.setAttribute('aria-expanded', 'false');
                }

                var answer = item.querySelector('.faq-answer');
                if (answer) {
                    answer.hidden = true;
                }
            });
        }

        faqItems.forEach(function (item) {
            var button = item.querySelector('.faq-question');
            var answer = item.querySelector('.faq-answer');
            if (!button) return;

            button.addEventListener('click', function () {
                var isOpen = item.classList.contains('open');

                if (isOpen) {
                    item.classList.remove('open');
                    button.setAttribute('aria-expanded', 'false');
                    if (answer) answer.hidden = true;
                    return;
                }

                closeOthers(item);

                item.classList.add('open');
                button.setAttribute('aria-expanded', 'true');
                if (answer) answer.hidden = false;
            });
        });
    });
})();