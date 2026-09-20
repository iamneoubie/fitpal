/**
 * FitPal Admin Dashboard JavaScript
 *
 * - Chart tooltip on hover/focus/tap
 * - Modal open/close with body scroll lock
 * - Escape key closes the active modal
 *
 * @package FitPal
 * @version 2.0
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ============================================
        // CHART TOOLTIP
        // ============================================
        var chart = document.querySelector('.admin-weekly-chart');
        var tooltip = document.getElementById('adminChartTooltip');

        if (chart && tooltip) {
            var bars = chart.querySelectorAll('.admin-chart-bar');
            var activeBar = null;

            function showTooltipFor(bar) {
                var column = bar.closest('.admin-chart-column');
                if (!column) return;
                var day = column.dataset.day || '';
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
                var chartRect = chart.getBoundingClientRect();
                var barRect = activeBar.getBoundingClientRect();
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
                tooltip.style.top = top + 'px';
            }

            bars.forEach(function (bar) {
                bar.addEventListener('mouseenter', function () { showTooltipFor(this); });
                bar.addEventListener('mouseleave', hideTooltip);
                bar.addEventListener('focus', function () { showTooltipFor(this); });
                bar.addEventListener('blur', hideTooltip);
                bar.addEventListener('touchstart', function (e) {
                    e.preventDefault();
                    showTooltipFor(this);
                }, { passive: false });
            });

            document.addEventListener('touchstart', function (e) {
                if (!activeBar) return;
                if (e.target.closest('.admin-chart-bar')) return;
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
        // MODAL OPEN / CLOSE
        // ============================================
        function lockScroll() {
            document.body.style.overflow = 'hidden';
        }

        function unlockScroll() {
            document.body.style.overflow = '';
        }

        function openModal(modal) {
            if (!modal) return;
            lockScroll();
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            var focusTarget = modal.querySelector('[data-autofocus]');
            if (focusTarget && typeof focusTarget.focus === 'function') {
                setTimeout(function () { focusTarget.focus(); }, 80);
            }
        }

        function closeModal(modal) {
            if (!modal) return;
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            if (!document.querySelector('.admin-modal.is-open')) {
                unlockScroll();
            }
        }

        document.addEventListener('click', function (e) {
            var opener = e.target.closest('[data-open-modal]');
            if (opener) {
                e.preventDefault();
                var modalId = opener.getAttribute('data-open-modal');
                openModal(document.getElementById(modalId));
                return;
            }

            var closer = e.target.closest('[data-close-modal]');
            if (closer) {
                e.preventDefault();
                closeModal(closer.closest('.admin-modal'));
                return;
            }

            if (e.target.classList.contains('admin-modal-backdrop')) {
                closeModal(e.target.closest('.admin-modal'));
                return;
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            var open = document.querySelector('.admin-modal.is-open');
            if (open) closeModal(open);
        });

        // ============================================
        // TAB SWITCHING INSIDE MODALS
        // ============================================
        document.addEventListener('click', function (e) {
            var tab = e.target.closest('.admin-modal-tab');
            if (!tab) return;
            e.preventDefault();

            var tabsContainer = tab.closest('.admin-modal-tabs');
            if (!tabsContainer) return;

            var panelGroup = tabsContainer.parentElement;
            var targetId = tab.getAttribute('data-tab-target');

            tabsContainer.querySelectorAll('.admin-modal-tab').forEach(function (t) {
                t.classList.toggle('active', t === tab);
            });

            panelGroup.querySelectorAll('.admin-modal-tab-panel').forEach(function (p) {
                p.classList.toggle('active', p.id === targetId);
            });
        });
    });
})();