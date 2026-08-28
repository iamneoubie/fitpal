/**
 * FitPal Product Detail JavaScript
 * 
 * Handles quantity controls and add-to-cart functionality
 * on the product detail page.
 *
 * @package FitPal
 * @version 1.0
 */

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {

        // ============================================
        // QUANTITY CONTROLS
        // ============================================
        var quantityControls = document.querySelectorAll('.quantity-control-large');

        quantityControls.forEach(function(control) {
            var minusBtn = control.querySelector('.qty-minus');
            var plusBtn = control.querySelector('.qty-plus');
            var input = control.querySelector('.qty-input-large');

            if (!minusBtn || !plusBtn || !input) return;

            function updateValue(delta) {
                var val = parseInt(input.value, 10) || 1;
                val += delta;
                var max = parseInt(input.max, 10) || 999;
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

            input.addEventListener('change', function() {
                var val = parseInt(this.value, 10) || 1;
                var max = parseInt(this.max, 10) || 999;
                if (val < 1) val = 1;
                if (val > max) val = max;
                this.value = val;
            });

            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    var form = this.closest('.add-to-cart-form');
                    if (form) {
                        var submitBtn = form.querySelector('.add-to-cart-btn');
                        if (submitBtn) submitBtn.click();
                    }
                }
            });
        });

        // ============================================
        // ADD-TO-CART FORM HANDLING
        // ============================================
        document.querySelectorAll('.add-to-cart-form').forEach(function(form) {
            form.addEventListener('submit', function(e) {
                var submitBtn = this.querySelector('.add-to-cart-btn');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Adding...';

                    setTimeout(function() {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Add to Cart';
                    }, 5000);
                }
            });
        });

        // ============================================
        // FLASH MESSAGE AUTO-DISMISS
        // ============================================
        var alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            setTimeout(function() {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(function() {
                    alert.style.display = 'none';
                }, 500);
            }, 5000);
        });

    });
})();