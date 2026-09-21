/**
 * FitPal Restaurant Sign-In JavaScript
 *
 * Owner tab  : single-step identifier + password.
 * Branch tab : two-phase flow.
 *   Phase 1 — restaurant combobox + branch combobox, then Next.
 *   Phase 2 — identifier + password, then submit.
 *
 * The branch_code selected in Phase 1 is copied into the Phase 2
 * form's hidden input so sign-in-handler.php receives it unchanged.
 *
 * @package FitPal
 * @version 2.0
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        var ASSET_ICON = '../../shared/assets/images/icons/';

        /* ============================================
           TAB SWITCHING
           ============================================ */
        var tabs = document.querySelectorAll('.sign-in-tab');
        var panels = {
            owner:  document.getElementById('panel-owner'),
            branch: document.getElementById('panel-branch')
        };

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var target = this.getAttribute('data-tab');
                if (!panels[target]) return;

                tabs.forEach(function (t) {
                    var isActive = t === tab;
                    t.classList.toggle('active', isActive);
                    t.setAttribute('aria-selected', isActive ? 'true' : 'false');
                });

                Object.keys(panels).forEach(function (key) {
                    panels[key].classList.toggle('active', key === target);
                });

                var firstField = panels[target].querySelector(
                    'input:not([type="hidden"]):not([disabled]), select'
                );
                if (firstField) setTimeout(function () { firstField.focus(); }, 60);
            });
        });

        /* ============================================
           PASSWORD TOGGLES
           ============================================ */
        function wirePasswordToggle(toggleId, inputId, iconId) {
            var btn   = document.getElementById(toggleId);
            var input = document.getElementById(inputId);
            var icon  = document.getElementById(iconId);
            if (!btn || !input || !icon) return;

            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
                var iconFile = isPassword ? 'password-unhide.svg' : 'password-hide.svg';
                icon.src = ASSET_ICON + iconFile;
                icon.alt = isPassword ? 'Hide password' : 'Show password';
                this.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            });
        }

        wirePasswordToggle('ownerTogglePassword', 'owner_password', 'ownerPasswordIcon');
        wirePasswordToggle('branchTogglePassword', 'branch_password', 'branchPasswordIcon');

        /* ============================================
           HELPERS
           ============================================ */
        function showFieldError(input, errorEl, message) {
            if (input) input.classList.add('error');
            if (errorEl) { errorEl.textContent = message; errorEl.style.display = 'block'; }
        }
        function clearFieldError(input, errorEl) {
            if (input) input.classList.remove('error');
            if (errorEl) { errorEl.textContent = ''; errorEl.style.display = 'none'; }
        }

        /* ============================================
           OWNER FORM
           ============================================ */
        var ownerForm       = document.getElementById('signInFormOwner');
        var ownerIdentifier = document.getElementById('owner_identifier');
        var ownerPassword   = document.getElementById('owner_password');
        var ownerBtn        = document.getElementById('ownerSignInBtn');
        var ownerIdError    = document.getElementById('ownerIdentifierError');
        var ownerPwError    = document.getElementById('ownerPasswordError');

        if (ownerForm) {
            ownerForm.addEventListener('submit', function (e) {
                var ok = true;
                clearFieldError(ownerIdentifier, ownerIdError);
                clearFieldError(ownerPassword, ownerPwError);

                if (!ownerIdentifier.value.trim()) {
                    showFieldError(ownerIdentifier, ownerIdError, 'Please enter your email or username.');
                    ok = false;
                }
                if (!ownerPassword.value) {
                    showFieldError(ownerPassword, ownerPwError, 'Please enter your password.');
                    ok = false;
                }
                if (!ok) { e.preventDefault(); return; }

                ownerBtn.disabled = true;
                ownerBtn.textContent = 'Signing in...';
            });

            ownerIdentifier.addEventListener('input', function () {
                if (this.value.trim()) clearFieldError(this, ownerIdError);
            });
            ownerPassword.addEventListener('input', function () {
                if (this.value) clearFieldError(this, ownerPwError);
            });
        }

        /* ============================================
           BRANCH TAB — TWO-PHASE FLOW
           ============================================ */

        var phase1     = document.getElementById('branchPhase1');
        var phase2     = document.getElementById('branchPhase2');
        var phase1Next = document.getElementById('branchPhase1Next');
        var phase2Back = document.getElementById('branchPhase2Back');
        var changeBtn  = document.getElementById('branchChangeBtn');

        var restaurantInput = document.getElementById('restaurant_search');
        var restaurantId    = document.getElementById('restaurant_id');
        var restaurantList  = document.getElementById('restaurantSuggestions');
        var restaurantClear = document.getElementById('restaurantClear');
        var restaurantError = document.getElementById('restaurantError');

        var branchInput  = document.getElementById('branch_search');
        var branchCode   = document.getElementById('branch_code');
        var branchList   = document.getElementById('branchSuggestions');
        var branchClear  = document.getElementById('branchClear');
        var branchError  = document.getElementById('branchError');
        var branchSubmit = document.getElementById('branch_code_submit');

        var summaryName = document.getElementById('branchSummaryName');
        var summaryCode = document.getElementById('branchSummaryCode');

        var branchForm       = document.getElementById('signInFormBranch');
        var branchIdentifier = document.getElementById('branch_identifier');
        var branchPassword   = document.getElementById('branch_password');
        var branchBtn        = document.getElementById('branchSignInBtn');
        var branchIdError    = document.getElementById('branchIdentifierError');
        var branchPwError    = document.getElementById('branchPasswordError');

        if (!restaurantInput || !branchInput) return;

        /* ---------- State ---------- */
        var restaurants      = [];
        var branches         = [];
        var activeRestaurant = null;
        var activeBranch     = null;
        var restHighlight    = -1;
        var branchHighlight  = -1;
        var restFetchTimer   = null;
        var restLastQuery    = null;

        function debounce(fn, ms) {
            return function () {
                var args = arguments;
                var ctx  = this;
                clearTimeout(restFetchTimer);
                restFetchTimer = setTimeout(function () {
                    fn.apply(ctx, args);
                }, ms);
            };
        }

        /* ---------- Fetch restaurants ---------- */
        function fetchRestaurants(query, cb) {
            var url = '../backend/handlers/branch-lookup-handler.php'
                    + '?action=restaurants&q=' + encodeURIComponent(query || '');

            fetch(url, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.status === 'success') {
                        cb(null, data.restaurants || []);
                    } else {
                        cb(new Error('lookup failed'), []);
                    }
                })
                .catch(function (err) { cb(err, []); });
        }

        /* ---------- Fetch branches ---------- */
        function fetchBranches(rid, cb) {
            var url = '../backend/handlers/branch-lookup-handler.php'
                    + '?action=branches&restaurant_id=' + encodeURIComponent(String(rid));

            fetch(url, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.status === 'success') {
                        cb(null, data.branches || []);
                    } else {
                        cb(new Error('lookup failed'), []);
                    }
                })
                .catch(function (err) { cb(err, []); });
        }

        /* ---------- Render restaurant list ---------- */
        function renderRestaurantList(items) {
            restaurantList.innerHTML = '';
            restHighlight = -1;

            if (!items.length) {
                var empty = document.createElement('li');
                empty.className = 'combo-empty';
                empty.textContent = 'No matching restaurants';
                restaurantList.appendChild(empty);
                restaurantList.hidden = false;
                restaurantInput.setAttribute('aria-expanded', 'true');
                return;
            }

            items.forEach(function (item, idx) {
                var li = document.createElement('li');
                li.className = 'combo-option';
                li.setAttribute('role', 'option');
                li.setAttribute('data-index', String(idx));
                li.setAttribute('data-id', String(item.restaurant_id));

                var name = document.createElement('span');
                name.className = 'combo-option-name';
                name.textContent = item.business_name;

                var meta = document.createElement('span');
                meta.className = 'combo-option-meta';
                meta.textContent = [item.cuisine_type, item.city]
                    .filter(Boolean).join(' · ') || '';

                li.appendChild(name);
                if (meta.textContent) li.appendChild(meta);

                li.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    selectRestaurant(item);
                });

                restaurantList.appendChild(li);
            });

            restaurantList.hidden = false;
            restaurantInput.setAttribute('aria-expanded', 'true');
        }

        /* ---------- Render branch list ---------- */
        function renderBranchList(items) {
            branchList.innerHTML = '';
            branchHighlight = -1;

            if (!items.length) {
                var empty = document.createElement('li');
                empty.className = 'combo-empty';
                empty.textContent = 'No branches available for this restaurant';
                branchList.appendChild(empty);
                branchList.hidden = false;
                branchInput.setAttribute('aria-expanded', 'true');
                return;
            }

            items.forEach(function (item, idx) {
                var li = document.createElement('li');
                li.className = 'combo-option';
                li.setAttribute('role', 'option');
                li.setAttribute('data-index', String(idx));
                li.setAttribute('data-code', item.branch_code);

                var name = document.createElement('span');
                name.className = 'combo-option-name';
                name.textContent = item.branch_name;

                var meta = document.createElement('span');
                meta.className = 'combo-option-meta';
                meta.textContent = [item.city, item.branch_code]
                    .filter(Boolean).join(' · ');

                li.appendChild(name);
                if (meta.textContent) li.appendChild(meta);

                li.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    selectBranch(item);
                });

                branchList.appendChild(li);
            });

            branchList.hidden = false;
            branchInput.setAttribute('aria-expanded', 'true');
        }

        /* ---------- Selection ---------- */
        function selectRestaurant(item) {
            activeRestaurant = item;
            activeBranch = null;

            restaurantInput.value = item.business_name;
            restaurantId.value    = String(item.restaurant_id);
            restaurantClear.hidden = false;

            closeRestaurantList();
            clearFieldError(restaurantInput, restaurantError);

            branchInput.value = '';
            branchCode.value  = '';
            branchClear.hidden = true;
            branchInput.disabled = false;
            branchInput.placeholder = 'Start typing a branch name…';

            fetchBranches(item.restaurant_id, function (err, items) {
                if (err) {
                    showFieldError(branchInput, branchError, 'Could not load branches. Please try again.');
                    return;
                }
                branches = items;
                if (items.length) {
                    renderBranchList(items);
                }
            });

            updatePhase1NextState();
        }

        function selectBranch(item) {
            activeBranch = item;
            branchInput.value = item.branch_name;
            branchCode.value  = item.branch_code;
            branchClear.hidden = false;

            closeBranchList();
            clearFieldError(branchInput, branchError);

            updatePhase1NextState();
        }

        function updatePhase1NextState() {
            var ready = !!(activeRestaurant && activeBranch);
            if (phase1Next) phase1Next.disabled = !ready;
        }

        /* ---------- List open/close ---------- */
        function closeRestaurantList() {
            restaurantList.hidden = true;
            restaurantInput.setAttribute('aria-expanded', 'false');
            restHighlight = -1;
        }
        function closeBranchList() {
            branchList.hidden = true;
            branchInput.setAttribute('aria-expanded', 'false');
            branchHighlight = -1;
        }

        /* ---------- Restaurant input events ---------- */
        var debouncedFetchRestaurants = debounce(function (query) {
            if (query === restLastQuery) return;
            restLastQuery = query;
            fetchRestaurants(query, function (err, items) {
                if (err) return;
                restaurants = items;
                renderRestaurantList(items);
            });
        }, 200);

        restaurantInput.addEventListener('input', function () {
            var val = this.value.trim();

            if (activeRestaurant && val !== activeRestaurant.business_name) {
                activeRestaurant = null;
                restaurantId.value = '';
                restaurantClear.hidden = true;

                activeBranch = null;
                branchInput.value = '';
                branchCode.value  = '';
                branchClear.hidden = true;
                branchInput.disabled = true;
                branchInput.placeholder = 'Select a restaurant first';
                updatePhase1NextState();
            }

            restLastQuery = null;
            debouncedFetchRestaurants(val);
        });

        restaurantInput.addEventListener('focus', function () {
            if (restaurantInput.disabled) return;
            if (restaurantInput.value.trim() === '') {
                restLastQuery = null;
                debouncedFetchRestaurants('');
            } else if (restaurantList.hidden) {
                restLastQuery = null;
                debouncedFetchRestaurants(restaurantInput.value.trim());
            }
        });

        restaurantInput.addEventListener('blur', function () {
            setTimeout(closeRestaurantList, 150);
        });

        restaurantInput.addEventListener('keydown', function (e) {
            handleComboKeys(e, restaurantList, 'restaurant');
        });

        if (restaurantClear) {
            restaurantClear.addEventListener('click', function () {
                activeRestaurant = null;
                activeBranch = null;
                restaurantInput.value = '';
                restaurantId.value = '';
                this.hidden = true;

                branchInput.value = '';
                branchCode.value  = '';
                branchClear.hidden = true;
                branchInput.disabled = true;
                branchInput.placeholder = 'Select a restaurant first';

                updatePhase1NextState();
                restaurantInput.focus();
            });
        }

        /* ---------- Branch input events ---------- */
        branchInput.addEventListener('input', function () {
            if (activeBranch && this.value.trim() !== activeBranch.branch_name) {
                activeBranch = null;
                branchCode.value = '';
                branchClear.hidden = true;
                updatePhase1NextState();
            }

            if (this.disabled) return;

            var q = this.value.trim().toLowerCase();
            var filtered = branches.filter(function (b) {
                return b.branch_name.toLowerCase().indexOf(q) !== -1;
            });
            renderBranchList(filtered);
        });

        branchInput.addEventListener('focus', function () {
            if (this.disabled) return;
            if (branches.length && (branchList.hidden || branchList.children.length === 0)) {
                renderBranchList(branches);
            }
        });

        branchInput.addEventListener('blur', function () {
            setTimeout(closeBranchList, 150);
        });

        branchInput.addEventListener('keydown', function (e) {
            handleComboKeys(e, branchList, 'branch');
        });

        if (branchClear) {
            branchClear.addEventListener('click', function () {
                activeBranch = null;
                branchInput.value = '';
                branchCode.value  = '';
                this.hidden = true;
                updatePhase1NextState();
                branchInput.focus();
                if (branches.length) renderBranchList(branches);
            });
        }

        /* ---------- Keyboard navigation ---------- */
        function handleComboKeys(e, listEl, kind) {
            var isRestaurant = (kind === 'restaurant');
            var items        = isRestaurant ? restaurants : branches;
            var options      = listEl.querySelectorAll('.combo-option');

            if (e.key === 'ArrowDown') {
                if (listEl.hidden && items.length) {
                    if (isRestaurant) renderRestaurantList(items);
                    else renderBranchList(items);
                    e.preventDefault();
                    return;
                }
                if (!options.length) return;
                e.preventDefault();
                var cur  = isRestaurant ? restHighlight : branchHighlight;
                var next = cur + 1;
                if (next >= options.length) next = 0;
                setHighlight(options, next, isRestaurant);
            }
            else if (e.key === 'ArrowUp') {
                if (!options.length) return;
                e.preventDefault();
                var cur2 = isRestaurant ? restHighlight : branchHighlight;
                var prev = cur2 - 1;
                if (prev < 0) prev = options.length - 1;
                setHighlight(options, prev, isRestaurant);
            }
            else if (e.key === 'Enter') {
                var idx = isRestaurant ? restHighlight : branchHighlight;
                if (idx >= 0 && options[idx]) {
                    e.preventDefault();
                    var item = isRestaurant ? restaurants[idx] : branches[idx];
                    if (isRestaurant) selectRestaurant(item);
                    else selectBranch(item);
                }
            }
            else if (e.key === 'Escape') {
                if (!listEl.hidden) {
                    e.preventDefault();
                    if (isRestaurant) closeRestaurantList();
                    else closeBranchList();
                }
            }
        }

        function setHighlight(options, index, isRestaurant) {
            options.forEach(function (o, i) {
                o.classList.toggle('is-highlighted', i === index);
            });
            if (isRestaurant) restHighlight = index;
            else branchHighlight = index;
        }

        /* ---------- Phase transition ---------- */
        function goToPhase2() {
            if (!activeRestaurant || !activeBranch) return;

            summaryName.textContent = activeRestaurant.business_name;
            summaryCode.textContent = activeBranch.branch_name + ' · ' + activeBranch.branch_code;
            branchSubmit.value = activeBranch.branch_code;

            phase1.hidden = true;
            phase2.hidden = false;

            setTimeout(function () {
                if (branchIdentifier) branchIdentifier.focus();
            }, 60);
        }

        function goToPhase1() {
            phase2.hidden = true;
            phase1.hidden = false;
            setTimeout(function () {
                if (restaurantInput && !restaurantInput.disabled) restaurantInput.focus();
            }, 60);
        }

        if (phase1Next) phase1Next.addEventListener('click', goToPhase2);
        if (phase2Back) phase2Back.addEventListener('click', goToPhase1);
        if (changeBtn)  changeBtn.addEventListener('click', goToPhase1);

        /* ---------- Branch form submit ---------- */
        if (branchForm) {
            branchForm.addEventListener('submit', function (e) {
                var ok = true;
                clearFieldError(branchIdentifier, branchIdError);
                clearFieldError(branchPassword, branchPwError);

                if (!branchSubmit.value) {
                    e.preventDefault();
                    goToPhase1();
                    showFieldError(restaurantInput, restaurantError,
                        'Please select a restaurant and branch.');
                    return;
                }
                if (!branchIdentifier.value.trim()) {
                    showFieldError(branchIdentifier, branchIdError,
                        'Please enter your email or username.');
                    ok = false;
                }
                if (!branchPassword.value) {
                    showFieldError(branchPassword, branchPwError,
                        'Please enter your password.');
                    ok = false;
                }
                if (!ok) { e.preventDefault(); return; }

                branchBtn.disabled = true;
                branchBtn.textContent = 'Signing in...';
            });

            branchIdentifier.addEventListener('input', function () {
                if (this.value.trim()) clearFieldError(this, branchIdError);
            });
            branchPassword.addEventListener('input', function () {
                if (this.value) clearFieldError(this, branchPwError);
            });
        }
    });
})();