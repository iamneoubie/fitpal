/**
 * FitPal Product Detail JavaScript
 * Version 4.4 - Fixed queue redirect to menu.php
 * 
 * Handles step navigation, ingredient customization, price calculation, and add-to-order.
 * 
 * @package FitPal
 * @version 4.4
 */

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        // ============================================
        // DOM REFERENCES
        // ============================================
        var stepMain = document.getElementById('stepMain');
        var stepCustomize = document.getElementById('stepCustomize');
        var customizeBtn = document.getElementById('customizeBtn');
        var backToMainBtn = document.getElementById('backToMainBtn');
        var cancelCustomizeBtn = document.getElementById('cancelCustomizeBtn');
        var applyCustomizeBtn = document.getElementById('applyCustomizeBtn');
        var addToOrderBtn = document.getElementById('addToOrderBtn');
        var addToCartBtn = document.getElementById('addToCartBtn');
        var quantityInput = document.getElementById('productQuantity');
        var mainTotalPrice = document.getElementById('mainTotalPrice');
        var customizeTotalPrice = document.getElementById('customizeTotalPrice');
        var basePriceDisplay = document.getElementById('productBasePrice');
        var customizationsData = document.getElementById('customizationsData');
        var totalPriceInput = document.getElementById('totalPriceInput');
        var redirectInput = document.querySelector('input[name="redirect"]');
        var form = document.getElementById('actionControlForm');

        // ============================================
        // STATE
        // ============================================
        var basePrice = 0;
        var quantity = 1;
        var currentCustomizations = [];

        // Get base price from the page
        var priceText = basePriceDisplay ? basePriceDisplay.textContent.trim() : '';
        var priceMatch = priceText.match(/[\d,.]+/);
        if (priceMatch) {
            basePrice = parseFloat(priceMatch[0].replace(/,/g, '')) || 0;
        }

        // ============================================
        // STEP NAVIGATION
        // ============================================
        function showCustomizeStep() {
            if (stepMain) stepMain.style.display = 'none';
            if (stepCustomize) {
                stepCustomize.style.display = 'block';
                stepCustomize.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            updateCustomizeTotal();
        }

        function showMainStep() {
            if (stepCustomize) stepCustomize.style.display = 'none';
            if (stepMain) stepMain.style.display = 'block';
            updateMainTotal();
        }

        if (customizeBtn) {
            customizeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                showCustomizeStep();
            });
        }

        if (backToMainBtn) {
            backToMainBtn.addEventListener('click', function(e) {
                e.preventDefault();
                showMainStep();
            });
        }

        if (cancelCustomizeBtn) {
            cancelCustomizeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                resetCustomizations();
                showMainStep();
            });
        }

        // ============================================
        // QUANTITY CONTROLS
        // ============================================
        var minusBtn = document.querySelector('.qty-minus');
        var plusBtn = document.querySelector('.qty-plus');

        if (minusBtn && plusBtn && quantityInput) {
            function updateQuantity(delta) {
                var val = parseInt(quantityInput.value, 10) || 1;
                var max = parseInt(quantityInput.max, 10) || 999;
                var newVal = val + delta;
                if (newVal < 1) newVal = 1;
                if (newVal > max) newVal = max;
                quantityInput.value = newVal;
                quantity = newVal;
                updateMainTotal();
                updateCustomizeTotal();
            }

            minusBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                updateQuantity(-1);
            });

            plusBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                updateQuantity(1);
            });

            quantityInput.addEventListener('change', function() {
                var val = parseInt(this.value, 10) || 1;
                var max = parseInt(this.max, 10) || 999;
                if (val < 1) val = 1;
                if (val > max) val = max;
                this.value = val;
                quantity = val;
                updateMainTotal();
                updateCustomizeTotal();
            });

            quantityInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.blur();
                }
            });
        }

        // ============================================
        // CHOICE (SELECT DROPDOWN) CUSTOMIZATION
        // ============================================
        document.querySelectorAll('.customization-select').forEach(function(select) {
            select.addEventListener('change', function() {
                updateCustomizeTotal();
            });
        });

        // ============================================
        // MODIFIER CUSTOMIZATION
        // ============================================
        document.querySelectorAll('.modifier-option').forEach(function(option) {
            var minusBtn = option.querySelector('.modifier-minus');
            var plusBtn = option.querySelector('.modifier-plus');
            var quantitySpan = option.querySelector('.modifier-quantity');

            if (minusBtn && plusBtn && quantitySpan) {
                var currentModifierQty = parseInt(quantitySpan.textContent, 10) || 0;

                function updateModifierQty(delta) {
                    var newQty = currentModifierQty + delta;
                    if (newQty < 0) newQty = 0;
                    if (newQty > 10) newQty = 10;
                    currentModifierQty = newQty;
                    quantitySpan.textContent = newQty;
                    option.classList.toggle('selected', newQty > 0);
                    updateCustomizeTotal();
                }

                minusBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    updateModifierQty(-1);
                });

                plusBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    updateModifierQty(1);
                });
            }
        });

        // ============================================
        // CHECKBOX CUSTOMIZATION
        // ============================================
        document.querySelectorAll('.checkbox-option input[type="checkbox"]').forEach(function(checkbox) {
            checkbox.addEventListener('change', function() {
                var parent = this.closest('.customization-option');
                parent.classList.toggle('selected', this.checked);
                updateCustomizeTotal();
            });
        });

        // ============================================
        // PRICE CALCULATION
        // ============================================
        function calculateTotalPrice() {
            var total = basePrice;

            // Check if we're in the customization view
            var isCustomizeVisible = stepCustomize && stepCustomize.style.display !== 'none';
            
            // Only apply customizations if the user is actively customizing
            if (isCustomizeVisible) {
                // 1. Select dropdown selections
                document.querySelectorAll('.customization-select').forEach(function(select) {
                    var selectedOption = select.options[select.selectedIndex];
                    if (selectedOption && selectedOption.value && selectedOption.value !== '') {
                        var modifier = parseFloat(selectedOption.dataset.priceModifier) || 0;
                        total += modifier;
                    }
                });

                // 2. Modifier quantities
                document.querySelectorAll('.modifier-option').forEach(function(option) {
                    var qtySpan = option.querySelector('.modifier-quantity');
                    if (qtySpan) {
                        var qty = parseInt(qtySpan.textContent, 10) || 0;
                        if (qty > 0) {
                            var priceModifier = parseFloat(option.dataset.priceModifier) || 0;
                            total += priceModifier * qty;
                        }
                    }
                });

                // 3. Checkbox selections
                document.querySelectorAll('.checkbox-option input[type="checkbox"]:checked').forEach(function(checkbox) {
                    var modifier = parseFloat(checkbox.dataset.priceModifier) || 0;
                    total += modifier;
                });
            }

            return total;
        }

        function updateMainTotal() {
            var total = calculateTotalPrice();
            var qty = parseInt(quantityInput ? quantityInput.value : 1, 10) || 1;
            var finalTotal = total * qty;

            if (mainTotalPrice) {
                mainTotalPrice.textContent = '₱' + finalTotal.toFixed(2);
            }
        }

        function updateCustomizeTotal() {
            var total = calculateTotalPrice();
            var qty = parseInt(quantityInput ? quantityInput.value : 1, 10) || 1;
            var finalTotal = total * qty;

            if (customizeTotalPrice) {
                customizeTotalPrice.textContent = '₱' + finalTotal.toFixed(2);
            }

            if (totalPriceInput) {
                totalPriceInput.value = finalTotal.toFixed(2);
            }
        }

        // ============================================
        // RESET CUSTOMIZATIONS
        // ============================================
        function resetCustomizations() {
            document.querySelectorAll('.customization-select').forEach(function(select) {
                var defaultOption = select.querySelector('option[selected]');
                if (defaultOption) {
                    select.value = defaultOption.value;
                } else if (select.options.length > 0) {
                    select.selectedIndex = 0;
                }
            });

            document.querySelectorAll('.modifier-option').forEach(function(option) {
                var qtySpan = option.querySelector('.modifier-quantity');
                if (qtySpan) {
                    qtySpan.textContent = '0';
                    option.classList.remove('selected');
                }
            });

            document.querySelectorAll('.checkbox-option input[type="checkbox"]').forEach(function(checkbox) {
                checkbox.checked = false;
                checkbox.closest('.checkbox-option').classList.remove('selected');
            });

            updateCustomizeTotal();
        }

        // ============================================
        // BUILD CUSTOMIZATIONS DATA
        // ============================================
        function buildCustomizationsData() {
            var customizations = [];

            // Select dropdowns
            document.querySelectorAll('.customization-select').forEach(function(select) {
                var group = select.closest('.customization-group');
                var selectedOption = select.options[select.selectedIndex];
                if (selectedOption && selectedOption.value) {
                    customizations.push({
                        component_id: group ? group.dataset.componentId : null,
                        ingredient_id: selectedOption.value,
                        selected_option: 'selected',
                        quantity: 1,
                        price_modifier: parseFloat(selectedOption.dataset.priceModifier) || 0
                    });
                }
            });

            // Modifiers
            document.querySelectorAll('.modifier-option').forEach(function(option) {
                var qtySpan = option.querySelector('.modifier-quantity');
                if (qtySpan) {
                    var qty = parseInt(qtySpan.textContent, 10) || 0;
                    if (qty > 0) {
                        var group = option.closest('.customization-group');
                        var ingredientId = option.dataset.ingredientId;
                        var priceModifier = parseFloat(option.dataset.priceModifier) || 0;

                        customizations.push({
                            component_id: group ? group.dataset.componentId : null,
                            ingredient_id: ingredientId,
                            selected_option: 'add',
                            quantity: qty,
                            price_modifier: priceModifier
                        });
                    }
                }
            });

            // Checkboxes
            document.querySelectorAll('.checkbox-option input[type="checkbox"]:checked').forEach(function(checkbox) {
                var option = checkbox.closest('.customization-option');
                var group = option.closest('.customization-group');
                customizations.push({
                    component_id: group ? group.dataset.componentId : null,
                    ingredient_id: checkbox.value,
                    selected_option: 'selected',
                    quantity: 1,
                    price_modifier: parseFloat(checkbox.dataset.priceModifier) || 0
                });
            });

            // Notes
            var globalNotes = document.getElementById('globalNotes');
            if (globalNotes && globalNotes.value.trim()) {
                customizations.push({
                    type: 'notes',
                    notes: globalNotes.value.trim()
                });
            }

            return customizations;
        }

        // ============================================
        // SET REDIRECT TO MENU.PHP
        // ============================================
        function setRedirectToMenu() {
            if (redirectInput) {
                redirectInput.value = 'menu.php';
            }
        }

        // ============================================
        // APPLY CUSTOMIZATIONS & ADD TO ORDER
        // ============================================
        function applyCustomizationsAndAdd() {
            currentCustomizations = buildCustomizationsData();
            if (customizationsData) {
                customizationsData.value = JSON.stringify(currentCustomizations);
            }

            var total = calculateTotalPrice();
            var qty = parseInt(quantityInput ? quantityInput.value : 1, 10) || 1;
            if (totalPriceInput) {
                totalPriceInput.value = (total * qty).toFixed(2);
            }

            // FIXED: Always redirect to menu.php
            setRedirectToMenu();

            updateMainTotal();
            showMainStep();

            if (form) {
                if (applyCustomizeBtn) {
                    applyCustomizeBtn.disabled = true;
                    applyCustomizeBtn.classList.add('loading');
                }
                form.submit();
            }
        }

        if (applyCustomizeBtn) {
            applyCustomizeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                applyCustomizationsAndAdd();
            });
        }

        // ============================================
        // MAIN BUTTON HANDLERS - FIXED: Redirect to menu.php
        // ============================================
        
        // Add to Order button
        if (addToOrderBtn && form) {
            addToOrderBtn.addEventListener('click', function(e) {
                e.preventDefault();
                
                // FIXED: Set redirect to menu.php
                setRedirectToMenu();

                if (customizationsData) {
                    var customData = buildCustomizationsData();
                    customizationsData.value = JSON.stringify(customData);
                }

                var total = calculateTotalPrice();
                var qty = parseInt(quantityInput ? quantityInput.value : 1, 10) || 1;
                if (totalPriceInput) {
                    totalPriceInput.value = (total * qty).toFixed(2);
                }

                addToOrderBtn.disabled = true;
                addToOrderBtn.classList.add('loading');
                addToOrderBtn.textContent = 'Adding...';

                setTimeout(function() {
                    form.submit();
                }, 300);
            });
        }

        // Add to Queue button (formerly "Add to Cart")
        if (addToCartBtn && form) {
            addToCartBtn.addEventListener('click', function(e) {
                e.preventDefault();
                
                // FIXED: Set redirect to menu.php
                setRedirectToMenu();

                if (customizationsData) {
                    var customData = buildCustomizationsData();
                    customizationsData.value = JSON.stringify(customData);
                }

                var total = calculateTotalPrice();
                var qty = parseInt(quantityInput ? quantityInput.value : 1, 10) || 1;
                if (totalPriceInput) {
                    totalPriceInput.value = (total * qty).toFixed(2);
                }

                addToCartBtn.disabled = true;
                addToCartBtn.classList.add('loading');
                addToCartBtn.textContent = 'Adding...';

                setTimeout(function() {
                    form.submit();
                }, 300);
            });
        }

        // ============================================
        // KEYBOARD SUPPORT
        // ============================================
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (stepCustomize && stepCustomize.style.display !== 'none') {
                    showMainStep();
                }
                var active = document.activeElement;
                if (active && active.closest('.customization-textarea')) {
                    active.blur();
                }
            }
        });

        // ============================================
        // INITIALIZE
        // ============================================
        document.querySelectorAll('.customization-option.selected').forEach(function(option) {
            var input = option.querySelector('input');
            if (input) {
                input.checked = true;
            }
        });

        // Set initial redirect to menu.php
        setRedirectToMenu();

        setTimeout(function() {
            updateMainTotal();
            updateCustomizeTotal();
        }, 100);

        document.querySelectorAll('.customization-textarea').forEach(function(input) {
            input.addEventListener('input', function() {
                // Update data when notes change
            });
        });

        console.log('Product Detail JS v4.4 - Fixed queue redirect to menu.php');
    });
})();