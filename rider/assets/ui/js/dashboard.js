/**
 * FitPal Rider Dashboard JavaScript
 *
 * Handles:
 *   - Availability toggle (online/offline) for verified riders
 *   - Chart tooltip positioning
 *   - Toast notifications
 *
 * Availability rule
 * -----------------
 * The toggle only exists in the DOM for verified riders. This script
 * binds to #availabilityToggle and no-ops if the element is missing,
 * so a pending/denied/suspended rider gets the disabled button without
 * any client-side branching.
 *
 * @package FitPal
 * @version 3.0 — Binds only when the toggle is present. Icon paths
 *                updated to match the reimagined dashboard markup.
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ============================================
        // CONFIG
        // ============================================
        var CFG        = window.FITPAL_RIDER || {};
        var CSRF_TOKEN = CFG.csrfToken || '';
        var ASSET_BASE = CFG.assetBase || '../../shared/';

        var ICON_OFFLINE = ASSET_BASE + 'assets/images/icons/close-circle-line.svg';
        var ICON_ONLINE  = ASSET_BASE + 'assets/images/icons/add-line.svg';

        // ============================================
        // AVAILABILITY TOGGLE
        // Only present for verified riders.
        // ============================================
        var availabilityToggle = document.getElementById('availabilityToggle');

        if (availabilityToggle) {
            availabilityToggle.addEventListener('click', function () {
                var btn = this;
                var currentAvailable = btn.dataset.available === '1';
                var newAvailable     = !currentAvailable;

                btn.disabled = true;
                var originalHTML = btn.innerHTML;
                btn.innerHTML = '<span>Updating…</span>';

                var body = new URLSearchParams();
                body.append('csrf_token', CSRF_TOKEN);
                body.append('action', 'toggle_availability');
                body.append('is_available', newAvailable ? '1' : '0');

                fetch('../backend/handlers/rider-handler.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: body.toString(),
                    credentials: 'same-origin'
                })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data && data.status === 'success') {
                            btn.dataset.available = newAvailable ? '1' : '0';

                            if (newAvailable) {
                                btn.classList.remove('btn-primary');
                                btn.classList.add('btn-outline');
                                btn.innerHTML =
                                    '<img src="' + ICON_OFFLINE + '" alt="" class="btn-icon" width="16" height="16">' +
                                    '<span>Go Offline</span>';
                            } else {
                                btn.classList.remove('btn-outline');
                                btn.classList.add('btn-primary');
                                btn.innerHTML =
                                    '<img src="' + ICON_ONLINE + '" alt="" class="btn-icon" width="16" height="16">' +
                                    '<span>Go Online</span>';
                            }

                            var greetingText = document.querySelector('.rider-dashboard-greeting .text-muted');
                            if (greetingText) {
                                greetingText.textContent = newAvailable
                                    ? "You're online and ready to accept deliveries."
                                    : "You're currently offline. Go online to start accepting deliveries.";
                            }

                            showToast(
                                data.message || (newAvailable ? 'You are now online' : 'You are now offline'),
                                'success'
                            );
                        } else {
                            btn.innerHTML = originalHTML;
                            showToast(
                                (data && data.message) || 'Could not update availability',
                                'error'
                            );
                        }
                    })
                    .catch(function () {
                        btn.innerHTML = originalHTML;
                        showToast('Network error. Please try again.', 'error');
                    })
                    .finally(function () {
                        btn.disabled = false;
                    });
            });
        }

        // ============================================
        // CHART TOOLTIP
        // ============================================
        var chart   = document.querySelector('.rider-weekly-chart');
        var tooltip = document.getElementById('riderChartTooltip');

        if (chart && tooltip) {
            var bars      = chart.querySelectorAll('.rider-chart-bar');
            var activeBar = null;

            function showTooltipFor(bar) {
                var column = bar.closest('.rider-chart-column');
                if (!column) return;

                var day    = column.dataset.day || '';
                var amount = column.dataset.amount || '';

                tooltip.textContent = day + ' — ' + amount;
                tooltip.classList.add('is-visible');
                activeBar = bar;
                positionTooltip();
            }

            function hideTooltip() {
                tooltip.classList.remove('is-visible');
                activeBar = null;
            }

            function positionTooltip() {
                if (!activeBar) return;

                var chartRect   = chart.getBoundingClientRect();
                var barRect     = activeBar.getBoundingClientRect();
                var tooltipRect = tooltip.getBoundingClientRect();

                var left = barRect.left - chartRect.left
                         + (barRect.width / 2)
                         - (tooltipRect.width / 2);

                var top = barRect.top - chartRect.top
                        - tooltipRect.height
                        - 8;

                if (left < 4) left = 4;
                if (left + tooltipRect.width > chartRect.width - 4) {
                    left = chartRect.width - tooltipRect.width - 4;
                }

                if (top < 4) {
                    top = barRect.bottom - chartRect.top + 8;
                }

                tooltip.style.left = left + 'px';
                tooltip.style.top  = top  + 'px';
            }

            bars.forEach(function (bar) {
                bar.addEventListener('mouseenter', function () {
                    showTooltipFor(this);
                });
                bar.addEventListener('mouseleave', hideTooltip);
                bar.addEventListener('focus', function () {
                    showTooltipFor(this);
                });
                bar.addEventListener('blur', hideTooltip);
                bar.addEventListener('touchstart', function (e) {
                    e.preventDefault();
                    showTooltipFor(this);
                }, { passive: false });
            });

            document.addEventListener('touchstart', function (e) {
                if (!activeBar) return;
                if (e.target.closest('.rider-chart-bar')) return;
                hideTooltip();
            }, { passive: true });

            var ticking = false;
            function scheduleReposition() {
                if (!activeBar) return;
                if (ticking) return;
                ticking = true;
                requestAnimationFrame(function () {
                    positionTooltip();
                    ticking = false;
                });
            }

            window.addEventListener('resize', scheduleReposition, { passive: true });
            window.addEventListener('scroll', scheduleReposition, { passive: true });
        }

        // ============================================
        // TOAST
        // ============================================
        function showToast(message, type) {
            var toast = document.getElementById('riderToast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'riderToast';
                toast.style.cssText = [
                    'position:fixed', 'top:80px', 'right:20px',
                    'padding:12px 20px', 'border-radius:8px',
                    'font-size:14px', 'font-weight:500', 'z-index:9999',
                    'transform:translateX(120%)',
                    'transition:transform .3s cubic-bezier(.4,0,.2,1)',
                    'max-width:360px', 'box-shadow:0 4px 16px rgba(0,0,0,.15)'
                ].join(';');
                document.body.appendChild(toast);
            }

            var palette = {
                success: ['#d1fae5', '#065f46'],
                error:   ['#fee2e2', '#991b1b'],
                info:    ['#dbeafe', '#1e40af']
            };
            var colors = palette[type] || palette.info;
            toast.style.background = colors[0];
            toast.style.color      = colors[1];
            toast.textContent      = message;

            void toast.offsetWidth;
            toast.style.transform = 'translateX(0)';

            clearTimeout(toast._timer);
            toast._timer = setTimeout(function () {
                toast.style.transform = 'translateX(120%)';
            }, 2800);
        }
    });
})();