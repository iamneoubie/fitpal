/**
 * FitPal Menu Page JavaScript
 *
 * Handles quantity controls and add-to-cart feedback.
 *
 * @package FitPal
 * @version 2.0
 */

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {

        // ============================================
        // DOM References
        // ============================================
        const productCards = document.querySelectorAll('.product-card');
        const searchInput = document.getElementById('menuSearch');

        // ============================================
        // Quantity Controls
        // ============================================

        document.querySelectorAll('.quantity-control').forEach(function(control) {
            const minusBtn = control.querySelector('.qty-minus');
            const plusBtn = control.querySelector('.qty-plus');
            const input = control.querySelector('.qty-input');

            if (!minusBtn || !plusBtn || !input) return;

            function updateValue(delta) {
                let val = parseInt(input.value, 10) || 1;
                val += delta;
                const max = parseInt(input.max, 10) || 999;
                if (val < 1) val = 1;
                if (val > max) val = max;
                input.value = val;
            }

            minusBtn.addEventListener('click', function(e) {
                e.preventDefault();
                updateValue(-1);
            });

            plusBtn.addEventListener('click', function(e) {
                e.preventDefault();
                updateValue(1);
            });

            // Ensure input stays within bounds
            input.addEventListener('change', function() {
                let val = parseInt(this.value, 10) || 1;
                const max = parseInt(this.max, 10) || 999;
                if (val < 1) val = 1;
                if (val > max) val = max;
                this.value = val;
            });

            // Prevent manual entry of non-numeric characters
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    let val = parseInt(this.value, 10) || 1;
                    const max = parseInt(this.max, 10) || 999;
                    if (val < 1) val = 1;
                    if (val > max) val = max;
                    this.value = val;
                    // Find and submit the parent form
                    const form = this.closest('.add-to-cart-form');
                    if (form) {
                        const submitBtn = form.querySelector('.add-btn');
                        if (submitBtn) submitBtn.click();
                    }
                }
            });
        });

        // ============================================
        // Add-to-Cart Form Handling
        // ============================================

        document.querySelectorAll('.add-to-cart-form').forEach(function(form) {
            form.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('.add-btn');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Adding...';
                    // Re-enable after a timeout in case of slow response
                    setTimeout(function() {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Add';
                    }, 5000);
                }
            });
        });

        // ============================================
        // Auto-submit filter form when checkbox changes
        // (handled by inline onchange in PHP)
        // ============================================

        // ============================================
        // Smooth scroll to product after filter change
        // ============================================

        // If coming from a filter change, scroll to the top of results
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('search') || urlParams.has('tags')) {
            const menuSection = document.querySelector('.restaurant-list');
            if (menuSection) {
                // Small delay to allow page to render
                setTimeout(function() {
                    const headerOffset = 80;
                    const elementPosition = menuSection.getBoundingClientRect().top;
                    const offsetPosition = elementPosition + window.pageYOffset - headerOffset;
                    window.scrollTo({
                        top: offsetPosition,
                        behavior: 'smooth'
                    });
                }, 300);
            }
        }

        console.log('Menu JS initialized - Mobile-first with pagination');

    });
})();