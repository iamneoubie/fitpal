/**
 * FitPal Contact Page JavaScript
 * Handles FAQ accordion toggle
 */

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        var faqItems = document.querySelectorAll('.faq-item');

        faqItems.forEach(function(item) {
            var button = item.querySelector('.faq-question-btn');

            button.addEventListener('click', function() {
                var isOpen = item.classList.contains('open');

                // Close all other FAQs
                document.querySelectorAll('.faq-item.open').forEach(function(other) {
                    if (other !== item) {
                        other.classList.remove('open');
                        other.querySelector('.faq-question-btn').setAttribute('aria-expanded', 'false');
                    }
                });

                // Toggle this FAQ
                if (isOpen) {
                    item.classList.remove('open');
                    button.setAttribute('aria-expanded', 'false');
                } else {
                    item.classList.add('open');
                    button.setAttribute('aria-expanded', 'true');
                }
            });
        });
    });
})();