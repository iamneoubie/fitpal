/**
 * FitPal Product Detail JavaScript
 * Version 5.4 - Required ingredients start at min_quantity
 *
 * @package FitPal
 * @version 5.4
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
        var mainCaloriesTotal = document.getElementById('mainCaloriesTotal');
        var customizeTotalPrice = document.getElementById('customizeTotalPrice');
        var customizeTotalCalories = document.getElementById('customizeTotalCalories');
        var basePriceDisplay = document.getElementById('productBasePrice');
        var customizationsData = document.getElementById('customizationsData');
        var totalPriceInput = document.getElementById('totalPriceInput');
        var totalCaloriesInput = document.getElementById('totalCaloriesInput');
        var redirectInput = document.querySelector('input[name="redirect"]');
        var form = document.getElementById('actionControlForm');
        var pageContainer = document.getElementById('productDetailPage');

        // ============================================
        // STATE
        // ============================================
        var basePrice = parseFloat(pageContainer.dataset.basePrice) || 0;
        var baseCalories = parseInt(pageContainer.dataset.baseCalories) || 0;
        var quantity = 1;
        var currentCustomizations = [];
        var isFirstLoad = true;

        // ============================================
        // STEP NAVIGATION
        // ============================================
        function showCustomizeStep() {
            if (stepMain) stepMain.style.display = 'none';
            if (stepCustomize) {
                stepCustomize.style.display = 'block';
                stepCustomize.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            if (isFirstLoad) {
                resetToBase();
                isFirstLoad = false;
            }
            updateCustomizeTotals();
        }

        function showMainStep() {
            if (stepCustomize) stepCustomize.style.display = 'none';
            if (stepMain) stepMain.style.display = 'block';
            updateMainTotals();
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
                resetToBase();
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
                updateMainTotals();
                updateCustomizeTotals();
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
                updateMainTotals();
                updateCustomizeTotals();
            });

            quantityInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.blur();
                }
            });
        }

        // ============================================
        // CUSTOMIZATION EVENT BINDING
        // ============================================

        // ---- Radio Buttons ----
        document.querySelectorAll('.customization-radio-group input[type="radio"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                var parentLabel = this.closest('.radio-option');
                var siblings = parentLabel.parentElement.querySelectorAll('.radio-option');
                siblings.forEach(function(sib) {
                    sib.classList.remove('selected');
                });
                parentLabel.classList.add('selected');
                updateCustomizeTotals();
            });
        });

        // ---- Select Dropdowns (fallback if any) ----
        document.querySelectorAll('.customization-select').forEach(function(select) {
            select.addEventListener('change', function() {
                updateCustomizeTotals();
            });
        });

        // ---- Modifier ----
        document.querySelectorAll('.modifier-option').forEach(function(option) {
            var minusBtn = option.querySelector('.modifier-minus');
            var plusBtn = option.querySelector('.modifier-plus');
            var quantitySpan = option.querySelector('.modifier-quantity');
            var minQty = parseInt(option.dataset.minQty, 10) || 0;
            var maxQty = parseInt(option.dataset.maxQty, 10) || 10;

            if (minusBtn && plusBtn && quantitySpan) {
                var currentModifierQty = parseInt(quantitySpan.textContent, 10) || 0;

                function updateModifierQty(delta) {
                    var newQty = currentModifierQty + delta;
                    if (newQty < minQty) newQty = minQty;
                    if (newQty > maxQty) newQty = maxQty;
                    currentModifierQty = newQty;
                    quantitySpan.textContent = newQty;
                    option.classList.toggle('selected', newQty > 0);
                    
                    minusBtn.disabled = (newQty <= minQty);
                    plusBtn.disabled = (newQty >= maxQty);
                    
                    updateCustomizeTotals();
                }

                minusBtn.disabled = (currentModifierQty <= minQty);
                plusBtn.disabled = (currentModifierQty >= maxQty);

                minusBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var currentQty = parseInt(quantitySpan.textContent, 10) || 0;
                    if (currentQty > minQty) {
                        updateModifierQty(-1);
                    }
                });

                plusBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var currentQty = parseInt(quantitySpan.textContent, 10) || 0;
                    if (currentQty < maxQty) {
                        updateModifierQty(1);
                    }
                });
            }
        });

        // ---- Checkbox ----
        document.querySelectorAll('.checkbox-option input[type="checkbox"]').forEach(function(checkbox) {
            checkbox.addEventListener('change', function() {
                var parent = this.closest('.customization-option');
                parent.classList.toggle('selected', this.checked);
                updateCustomizeTotals();
            });
        });

        // ============================================
        // RESET FUNCTIONS
        // ============================================

        // Reset to base: required ingredients at min_quantity, optional at 0
        function resetToBase() {
            // Modifiers - respect min_quantity
            document.querySelectorAll('.modifier-option').forEach(function(option) {
                var qtySpan = option.querySelector('.modifier-quantity');
                var minusBtn = option.querySelector('.modifier-minus');
                var plusBtn = option.querySelector('.modifier-plus');
                var minQty = parseInt(option.dataset.minQty, 10) || 0;
                var maxQty = parseInt(option.dataset.maxQty, 10) || 10;
                
                if (qtySpan) {
                    // REQUIRED: Set to min_quantity (1 for required, 0 for optional)
                    var defaultQty = minQty;
                    qtySpan.textContent = defaultQty;
                    option.classList.toggle('selected', defaultQty > 0);
                    
                    if (minusBtn) minusBtn.disabled = (defaultQty <= minQty);
                    if (plusBtn) plusBtn.disabled = (defaultQty >= maxQty);
                }
            });

            // Radio groups - select default option
            document.querySelectorAll('.customization-radio-group').forEach(function(group) {
                var defaultRadio = group.querySelector('input[type="radio"][checked]');
                if (defaultRadio) {
                    defaultRadio.checked = true;
                    var parentLabel = defaultRadio.closest('.radio-option');
                    var siblings = group.querySelectorAll('.radio-option');
                    siblings.forEach(function(sib) { sib.classList.remove('selected'); });
                    parentLabel.classList.add('selected');
                } else {
                    var firstRadio = group.querySelector('input[type="radio"]');
                    if (firstRadio) {
                        firstRadio.checked = true;
                        var parentLabel = firstRadio.closest('.radio-option');
                        var siblings = group.querySelectorAll('.radio-option');
                        siblings.forEach(function(sib) { sib.classList.remove('selected'); });
                        parentLabel.classList.add('selected');
                    }
                }
            });

            // Checkboxes - uncheck all
            document.querySelectorAll('.checkbox-option input[type="checkbox"]').forEach(function(checkbox) {
                checkbox.checked = false;
                checkbox.closest('.checkbox-option').classList.remove('selected');
            });

            updateCustomizeTotals();
        }

        // Legacy reset (kept for compatibility)
        function resetCustomizations() {
            resetToBase();
        }

        // ============================================
        // CALCULATION ENGINE (Price + Calories)
        // ============================================
        function calculateTotals() {
            var totalPrice = basePrice;
            var totalCalories = 0;

            // Always include static ingredients (single-choice) regardless of view
            document.querySelectorAll('.static-ingredient input[type="hidden"]').forEach(function(hidden) {
                var priceMod = parseFloat(hidden.dataset.priceModifier) || 0;
                var calMod = parseInt(hidden.dataset.calories, 10) || 0;
                totalPrice += priceMod;
                totalCalories += calMod;
            });

            // Only apply interactive customizations if the customization step is visible
            var isCustomizeVisible = stepCustomize && stepCustomize.style.display !== 'none';
            if (!isCustomizeVisible) {
                return { price: totalPrice, calories: totalCalories };
            }

            // 1. Radio selections (choice groups)
            document.querySelectorAll('.customization-radio-group input[type="radio"]:checked').forEach(function(radio) {
                if (radio.value) {
                    var priceMod = parseFloat(radio.dataset.priceModifier) || 0;
                    var calMod = parseInt(radio.dataset.calories, 10) || 0;
                    totalPrice += priceMod;
                    totalCalories += calMod;
                }
            });

            // 2. Select dropdowns (if any)
            document.querySelectorAll('.customization-select').forEach(function(select) {
                var selectedOption = select.options[select.selectedIndex];
                if (selectedOption && selectedOption.value && selectedOption.value !== '') {
                    var priceMod = parseFloat(selectedOption.dataset.priceModifier) || 0;
                    totalPrice += priceMod;
                }
            });

            // 3. Modifier quantities
            document.querySelectorAll('.modifier-option').forEach(function(option) {
                var qtySpan = option.querySelector('.modifier-quantity');
                if (qtySpan) {
                    var qty = parseInt(qtySpan.textContent, 10) || 0;
                    if (qty > 0) {
                        var priceMod = parseFloat(option.dataset.priceModifier) || 0;
                        var calMod = parseInt(option.dataset.calories, 10) || 0;
                        totalPrice += priceMod * qty;
                        totalCalories += calMod * qty;
                    }
                }
            });

            // 4. Checkbox selections
            document.querySelectorAll('.checkbox-option input[type="checkbox"]:checked').forEach(function(checkbox) {
                var priceMod = parseFloat(checkbox.dataset.priceModifier) || 0;
                var calMod = parseInt(checkbox.dataset.calories, 10) || 0;
                totalPrice += priceMod;
                totalCalories += calMod;
            });

            return { price: totalPrice, calories: totalCalories };
        }

        function updateMainTotals() {
            var totals = calculateTotals();
            var qty = parseInt(quantityInput ? quantityInput.value : 1, 10) || 1;
            var finalPrice = totals.price * qty;
            var finalCalories = totals.calories * qty;

            if (mainTotalPrice) mainTotalPrice.textContent = '₱' + finalPrice.toFixed(2);
            if (mainCaloriesTotal) mainCaloriesTotal.textContent = finalCalories + ' kcal';
        }

        function updateCustomizeTotals() {
            var totals = calculateTotals();
            var qty = parseInt(quantityInput ? quantityInput.value : 1, 10) || 1;
            var finalPrice = totals.price * qty;
            var finalCalories = totals.calories * qty;

            if (customizeTotalPrice) customizeTotalPrice.textContent = '₱' + finalPrice.toFixed(2);
            if (customizeTotalCalories) customizeTotalCalories.textContent = finalCalories + ' kcal';
            if (totalPriceInput) totalPriceInput.value = finalPrice.toFixed(2);
            if (totalCaloriesInput) totalCaloriesInput.value = finalCalories.toString();
        }

        // ============================================
        // BUILD CUSTOMIZATIONS DATA
        // ============================================
        function buildCustomizationsData() {
            var customizations = [];

            // Static ingredients (hidden)
            document.querySelectorAll('.static-ingredient input[type="hidden"]').forEach(function(hidden) {
                customizations.push({
                    ingredient_id: hidden.value,
                    selected_option: 'selected',
                    quantity: 1,
                    price_modifier: parseFloat(hidden.dataset.priceModifier) || 0,
                    calories: parseInt(hidden.dataset.calories, 10) || 0
                });
            });

            // Radio selections
            document.querySelectorAll('.customization-radio-group input[type="radio"]:checked').forEach(function(radio) {
                if (radio.value) {
                    var group = radio.closest('.customization-group');
                    customizations.push({
                        component_id: group ? group.dataset.componentId : null,
                        ingredient_id: radio.value,
                        selected_option: 'selected',
                        quantity: 1,
                        price_modifier: parseFloat(radio.dataset.priceModifier) || 0,
                        calories: parseInt(radio.dataset.calories, 10) || 0
                    });
                }
            });

            // Select dropdowns (if any)
            document.querySelectorAll('.customization-select').forEach(function(select) {
                var group = select.closest('.customization-group');
                var selectedOption = select.options[select.selectedIndex];
                if (selectedOption && selectedOption.value) {
                    customizations.push({
                        component_id: group ? group.dataset.componentId : null,
                        ingredient_id: selectedOption.value,
                        selected_option: 'selected',
                        quantity: 1,
                        price_modifier: parseFloat(selectedOption.dataset.priceModifier) || 0,
                        calories: 0
                    });
                }
            });

            // Modifiers - include all ingredients (including those with quantity 0)
            document.querySelectorAll('.modifier-option').forEach(function(option) {
                var qtySpan = option.querySelector('.modifier-quantity');
                if (qtySpan) {
                    var qty = parseInt(qtySpan.textContent, 10) || 0;
                    var group = option.closest('.customization-group');
                    customizations.push({
                        component_id: group ? group.dataset.componentId : null,
                        ingredient_id: option.dataset.ingredientId,
                        selected_option: qty > 0 ? 'add' : 'remove',
                        quantity: qty,
                        price_modifier: parseFloat(option.dataset.priceModifier) || 0,
                        calories: parseInt(option.dataset.calories, 10) || 0
                    });
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
                    price_modifier: parseFloat(checkbox.dataset.priceModifier) || 0,
                    calories: parseInt(checkbox.dataset.calories, 10) || 0
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
        // APPLY CUSTOMIZATIONS & SUBMIT
        // ============================================
        function setRedirectToMenu() {
            if (redirectInput) {
                redirectInput.value = 'menu.php';
            }
        }

        function applyCustomizationsAndAdd() {
            currentCustomizations = buildCustomizationsData();
            if (customizationsData) {
                customizationsData.value = JSON.stringify(currentCustomizations);
            }

            var totals = calculateTotals();
            var qty = parseInt(quantityInput ? quantityInput.value : 1, 10) || 1;
            var finalPrice = totals.price * qty;
            var finalCalories = totals.calories * qty;

            if (totalPriceInput) {
                totalPriceInput.value = finalPrice.toFixed(2);
            }
            if (totalCaloriesInput) {
                totalCaloriesInput.value = finalCalories.toString();
            }

            setRedirectToMenu();
            updateMainTotals();
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
        // ADD TO ORDER / CART BUTTONS (Main view)
        // ============================================
        function handleMainSubmit(button) {
            return function(e) {
                e.preventDefault();

                var customData = buildCustomizationsData();
                if (customizationsData) {
                    customizationsData.value = JSON.stringify(customData);
                }

                var totals = calculateTotals();
                var qty = parseInt(quantityInput ? quantityInput.value : 1, 10) || 1;
                var finalPrice = totals.price * qty;
                var finalCalories = totals.calories * qty;

                if (totalPriceInput) {
                    totalPriceInput.value = finalPrice.toFixed(2);
                }
                if (totalCaloriesInput) {
                    totalCaloriesInput.value = finalCalories.toString();
                }

                setRedirectToMenu();

                button.disabled = true;
                button.classList.add('loading');
                button.textContent = 'Adding...';

                setTimeout(function() {
                    form.submit();
                }, 300);
            };
        }

        if (addToOrderBtn && form) {
            addToOrderBtn.addEventListener('click', handleMainSubmit(addToOrderBtn));
        }

        if (addToCartBtn && form) {
            addToCartBtn.addEventListener('click', handleMainSubmit(addToCartBtn));
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
        // INITIALISE
        // ============================================
        document.querySelectorAll('.customization-radio-group input[type="radio"]:checked').forEach(function(radio) {
            var parentLabel = radio.closest('.radio-option');
            parentLabel.classList.add('selected');
        });

        setRedirectToMenu();

        // Initialize with base values (required ingredients at min_quantity)
        resetToBase();

        setTimeout(function() {
            updateMainTotals();
            updateCustomizeTotals();
        }, 100);

        console.log('Product Detail JS v5.4 - Required ingredients start at min_quantity');
    });
})();