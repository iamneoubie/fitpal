/**
 * FitPal Global Date Input Formatter
 *
 * Progressively enhances every <input type="date"> on the page into
 * a visible MM/DD/YYYY text field backed by a custom calendar
 * overlay.
 *
 * The native input is NEVER removed or replaced:
 *   - Its `name`, `id`, `required`, `min`, `max`, `disabled` are
 *     preserved so existing form submission and validation still work.
 *   - Its `.value` stays in canonical YYYY-MM-DD, so any handler that
 *     reads $_POST['field'] receives exactly what it received before.
 *
 * ---------------------------------------------------------------------
 * v2.6 CHANGES
 * ---------------------------------------------------------------------
 * 1. Disabled state is polled, not observed
 *    v2.5 relied on a MutationObserver watching the native input's
 *    `disabled` attribute. profile.js toggles the disabled state
 *    via `input.disabled = false`, and the property-to-attribute
 *    reflection is not consistent across browsers when a page
 *    flips the property. The observer therefore missed the
 *    transition into edit mode and the toggle stayed disabled.
 *
 *    v2.6 replaces the observer with a light polling loop. Every
 *    200ms the enhanced field checks whether the native input's
 *    disabled state differs from what the visible input and toggle
 *    button currently reflect, and reconciles if it does. The
 *    loop is a single comparison per field per tick and stops
 *    after a page has been idle in the same state for 30 seconds
 *    so it does not run forever.
 *
 * 2. No behaviour change anywhere else
 *    The commit model, the calendar, the year grid, the toggle
 *    click handler, the typing formatter, the outside-click
 *    handler, and the read-from-visible fallback are byte-
 *    identical to v2.5.
 *
 * ---------------------------------------------------------------------
 * v2.5 — retained
 * ---------------------------------------------------------------------
 * Disabled fields really are disabled. Native value is read through
 * the visible field when empty. No read-only day cells.
 *
 * ---------------------------------------------------------------------
 * v2.4 — removed
 * ---------------------------------------------------------------------
 *
 * ---------------------------------------------------------------------
 * v2.3 — retained
 * ---------------------------------------------------------------------
 * Native input hidden state is inline-styled.
 *
 * ---------------------------------------------------------------------
 * v2.2 — retained
 * ---------------------------------------------------------------------
 * Toggle button stretched between wrapper top and bottom.
 *
 * ---------------------------------------------------------------------
 * v1.9 — retained
 * ---------------------------------------------------------------------
 * Calendar opens only from the toggle button.
 *
 * ---------------------------------------------------------------------
 * v1.8 — retained
 * ---------------------------------------------------------------------
 * Year pick keeps the calendar open.
 *
 * ---------------------------------------------------------------------
 * v1.7 — retained
 * ---------------------------------------------------------------------
 * Visible input no longer inherits the native input's classes.
 *
 * ---------------------------------------------------------------------
 * v1.6 — retained
 * ---------------------------------------------------------------------
 * No auto-open on focus.
 *
 * ---------------------------------------------------------------------
 * v1.5 — retained
 * ---------------------------------------------------------------------
 * Empty-field lock fix and year navigation via the decade grid.
 *
 * ---------------------------------------------------------------------
 * v1.4 — retained
 * ---------------------------------------------------------------------
 * positionCalendar() uses viewport coordinates.
 *
 * @package FitPal
 * @version 2.6
 */

(function () {
    'use strict';

    // ---------------------------------------------------------------
    // CONFIGURATION
    // ---------------------------------------------------------------

    const DISPLAY_FORMAT = {
        MDY: 'MM/DD/YYYY',
        DMY: 'DD/MM/YYYY',
        YMD: 'YYYY-MM-DD'
    };

    const WEEKDAY_LABELS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    const MONTH_LABELS = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
    ];

    const DECADE_SIZE = 12;

    // How often to reconcile the disabled state against the native
    // input. Short enough that entering edit mode feels immediate,
    // long enough that the loop costs essentially nothing.
    const DISABLED_POLL_INTERVAL_MS = 200;

    // How long to keep polling with no observed change before
    // stopping. A page that never changes the state stops polling
    // after this many milliseconds of stability.
    const DISABLED_POLL_QUIET_MS = 30000;

    const CALENDAR_ICON =
        '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">'
        + '<path fill="currentColor" d="M7 2v2H5.5A2.5 2.5 0 0 0 3 6.5v12A2.5 2.5 0 0 0 5.5 21h13a2.5 2.5 0 0 0 2.5-2.5v-12A2.5 2.5 0 0 0 18.5 4H17V2h-2v2H9V2H7zm11.5 8v8.5h-13V10h13zM5.5 6h13v2h-13V6z"/>'
        + '</svg>';

    let currentFormat = DISPLAY_FORMAT.MDY;

    const ENHANCED_ATTR = 'data-fitpal-date-enhanced';
    const EXTRA_CLASS_ATTR = 'data-fitpal-date-class';

    // ---------------------------------------------------------------
    // NATIVE INPUT INLINE HIDING
    // ---------------------------------------------------------------

    function hideNativeInput(input) {
        const s = input.style;

        s.setProperty('display', 'none', 'important');
        s.setProperty('position', 'absolute', 'important');
        s.setProperty('top', '0', 'important');
        s.setProperty('left', '0', 'important');
        s.setProperty('right', '0', 'important');
        s.setProperty('bottom', '0', 'important');
        s.setProperty('width', '0', 'important');
        s.setProperty('height', '0', 'important');
        s.setProperty('margin', '0', 'important');
        s.setProperty('padding', '0', 'important');
        s.setProperty('border', '0', 'important');

        s.setProperty('opacity', '0', 'important');
        s.setProperty('visibility', 'hidden', 'important');
        s.setProperty('pointer-events', 'none', 'important');

        s.setProperty('z-index', '-1', 'important');
        s.setProperty('-webkit-appearance', 'none', 'important');
        s.setProperty('appearance', 'none', 'important');

        s.setProperty('background', 'transparent', 'important');
    }

    function protectVisibleInput(input) {
        const s = input.style;

        s.setProperty('background-image', 'none', 'important');
        s.setProperty('background-color', '#ffffff', 'important');
    }

    // ---------------------------------------------------------------
    // UTILITIES
    // ---------------------------------------------------------------

    function parseCanonical(value) {
        if (!value || typeof value !== 'string') return null;
        const m = value.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!m) return null;
        const y = parseInt(m[1], 10);
        const mo = parseInt(m[2], 10) - 1;
        const d = parseInt(m[3], 10);
        const dt = new Date(Date.UTC(y, mo, d));
        if (
            dt.getUTCFullYear() !== y ||
            dt.getUTCMonth() !== mo ||
            dt.getUTCDate() !== d
        ) {
            return null;
        }
        return dt;
    }

    function toCanonical(dt) {
        if (!dt) return '';
        const y = String(dt.getUTCFullYear()).padStart(4, '0');
        const m = String(dt.getUTCMonth() + 1).padStart(2, '0');
        const d = String(dt.getUTCDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    function toDisplay(dt) {
        if (!dt) return '';
        const y = dt.getUTCFullYear();
        const m = String(dt.getUTCMonth() + 1).padStart(2, '0');
        const d = String(dt.getUTCDate()).padStart(2, '0');

        switch (currentFormat) {
            case DISPLAY_FORMAT.DMY: return `${d}/${m}/${y}`;
            case DISPLAY_FORMAT.YMD: return `${y}-${m}-${d}`;
            case DISPLAY_FORMAT.MDY:
            default:                 return `${m}/${d}/${y}`;
        }
    }

    function parseDisplay(text) {
        if (!text) return null;
        const trimmed = text.trim();

        let m, y, mo, d;

        if (currentFormat === DISPLAY_FORMAT.DMY) {
            m = trimmed.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
            if (!m) return null;
            d  = parseInt(m[1], 10);
            mo = parseInt(m[2], 10) - 1;
            y  = parseInt(m[3], 10);
        } else if (currentFormat === DISPLAY_FORMAT.YMD) {
            m = trimmed.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
            if (!m) return null;
            y  = parseInt(m[1], 10);
            mo = parseInt(m[2], 10) - 1;
            d  = parseInt(m[3], 10);
        } else {
            m = trimmed.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
            if (!m) return null;
            mo = parseInt(m[1], 10) - 1;
            d  = parseInt(m[2], 10);
            y  = parseInt(m[3], 10);
        }

        const dt = new Date(Date.UTC(y, mo, d));
        if (
            dt.getUTCFullYear() !== y ||
            dt.getUTCMonth() !== mo ||
            dt.getUTCDate() !== d
        ) {
            return null;
        }
        return dt;
    }

    function seedDate() {
        const n = new Date();
        return new Date(Date.UTC(n.getFullYear(), n.getMonth(), n.getDate()));
    }

    function formatAsTyped(raw) {
        const digits = String(raw || '').replace(/\D/g, '');

        if (currentFormat === DISPLAY_FORMAT.YMD) {
            let out = '';
            for (let i = 0; i < digits.length && i < 8; i++) {
                if (i === 4 || i === 6) out += '-';
                out += digits[i];
            }
            return out;
        }

        let out = '';
        for (let i = 0; i < digits.length && i < 8; i++) {
            if (i === 2 || i === 4) out += '/';
            out += digits[i];
        }
        return out;
    }

    function withYear(source, year) {
        const mo = source.getUTCMonth();
        const d  = source.getUTCDate();

        let candidate = new Date(Date.UTC(year, mo, d));
        if (candidate.getUTCMonth() !== mo) {
            candidate = new Date(Date.UTC(year, mo + 1, 0));
        }
        return candidate;
    }

    // ---------------------------------------------------------------
    // CALENDAR RENDERING
    // ---------------------------------------------------------------

    function createCalendar() {
        const cal = document.createElement('div');
        cal.className = 'fitpal-date-calendar';
        cal.setAttribute('role', 'dialog');
        cal.setAttribute('aria-modal', 'true');
        cal.setAttribute('aria-label', 'Choose a date');
        cal.hidden = true;

        cal.innerHTML = ''
            + '<div class="fitpal-date-cal-header">'
            +   '<button type="button" class="fitpal-date-cal-prev" aria-label="Previous">&lsaquo;</button>'
            +   '<button type="button" class="fitpal-date-cal-title" aria-live="polite"></button>'
            +   '<button type="button" class="fitpal-date-cal-next" aria-label="Next">&rsaquo;</button>'
            + '</div>'
            + '<div class="fitpal-date-cal-weekdays"></div>'
            + '<div class="fitpal-date-cal-grid" role="grid"></div>';

        const weekdays = cal.querySelector('.fitpal-date-cal-weekdays');
        if (weekdays) {
            WEEKDAY_LABELS.forEach(function (label) {
                const el = document.createElement('span');
                el.textContent = label;
                weekdays.appendChild(el);
            });
        }

        cal.addEventListener('mousedown', function (e) {
            if (e.target.closest('button')) e.preventDefault();
        });

        return cal;
    }

    function renderMonth(cal, year, month, selected, cursor) {
        cal.classList.remove('is-year-view');

        const title = cal.querySelector('.fitpal-date-cal-title');
        if (title) title.textContent = MONTH_LABELS[month] + ' ' + year;

        const grid = cal.querySelector('.fitpal-date-cal-grid');
        if (!grid) return;

        grid.innerHTML = '';
        grid.dataset.view = 'month';

        const firstOfMonth = new Date(Date.UTC(year, month, 1));
        const startWeekday = firstOfMonth.getUTCDay();
        const daysInMonth = new Date(Date.UTC(year, month + 1, 0)).getUTCDate();

        for (let i = 0; i < startWeekday; i++) {
            const blank = document.createElement('span');
            blank.className = 'fitpal-date-cal-blank';
            grid.appendChild(blank);
        }

        for (let day = 1; day <= daysInMonth; day++) {
            const cellDate = new Date(Date.UTC(year, month, day));

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'fitpal-date-cal-day';
            btn.textContent = String(day);
            btn.dataset.date = toCanonical(cellDate);

            const isSelected = selected && toCanonical(selected) === toCanonical(cellDate);
            const isCursor   = cursor   && toCanonical(cursor)   === toCanonical(cellDate);

            if (isSelected) btn.classList.add('is-selected');
            if (isCursor)   btn.classList.add('is-cursor');

            btn.setAttribute('aria-label', cellDate.toDateString());
            grid.appendChild(btn);
        }
    }

    function renderYearGrid(cal, year, selected) {
        cal.classList.add('is-year-view');

        const startYear = year - (year % DECADE_SIZE);
        const endYear = startYear + DECADE_SIZE - 1;

        const title = cal.querySelector('.fitpal-date-cal-title');
        if (title) title.textContent = startYear + ' – ' + endYear;

        const grid = cal.querySelector('.fitpal-date-cal-grid');
        if (!grid) return;

        grid.innerHTML = '';
        grid.dataset.view = 'year';

        for (let i = 0; i < DECADE_SIZE; i++) {
            const cellYear = startYear + i;

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'fitpal-date-cal-year';
            btn.textContent = String(cellYear);
            btn.dataset.year = String(cellYear);

            const isSelected = selected && selected.getUTCFullYear() === cellYear;

            if (isSelected) btn.classList.add('is-selected');

            btn.setAttribute('aria-label', String(cellYear));
            grid.appendChild(btn);
        }
    }

    // ---------------------------------------------------------------
    // ENHANCEMENT
    // ---------------------------------------------------------------

    function enhance(nativeInput) {
        if (!nativeInput || nativeInput.getAttribute(ENHANCED_ATTR) === '1') {
            return null;
        }

        nativeInput.setAttribute(ENHANCED_ATTR, '1');

        hideNativeInput(nativeInput);

        const visible = document.createElement('input');
        visible.type = 'text';
        visible.className = 'fitpal-date-visible';

        const extraClass = (nativeInput.getAttribute(EXTRA_CLASS_ATTR) || '').trim();
        if (extraClass !== '') {
            visible.className += ' ' + extraClass;
        }

        visible.autocomplete = 'off';
        visible.spellcheck = false;
        visible.setAttribute('inputmode', 'numeric');
        visible.placeholder = currentFormat;
        if (nativeInput.id) {
            visible.id = nativeInput.id + '-visible';
        }

        ['aria-label', 'aria-labelledby'].forEach(function (attr) {
            const v = nativeInput.getAttribute(attr);
            if (v) visible.setAttribute(attr, v);
        });

        protectVisibleInput(visible);

        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'fitpal-date-toggle';
        toggle.innerHTML = CALENDAR_ICON;
        toggle.setAttribute('aria-label', 'Open calendar');
        toggle.setAttribute('aria-haspopup', 'dialog');
        toggle.setAttribute('aria-expanded', 'false');

        const wrapper = document.createElement('span');
        wrapper.className = 'fitpal-date-wrapper';
        if (!nativeInput.parentNode) return null;
        nativeInput.parentNode.insertBefore(wrapper, nativeInput);
        wrapper.appendChild(nativeInput);
        wrapper.appendChild(visible);
        wrapper.appendChild(toggle);

        requestAnimationFrame(function () {
            const rect = visible.getBoundingClientRect();
            const h = rect.height;
            if (h > 0) {
                wrapper.style.minHeight = h + 'px';
            }
        });

        // -----------------------------------------------------------
        // DISABLED STATE MIRROR
        // -----------------------------------------------------------

        function applyDisabledState() {
            const disabled = !!nativeInput.disabled;

            if (visible.disabled !== disabled) {
                visible.disabled = disabled;
            }

            if (disabled) {
                if (visible.getAttribute('aria-disabled') !== 'true') {
                    visible.setAttribute('aria-disabled', 'true');
                }
            } else if (visible.hasAttribute('aria-disabled')) {
                visible.removeAttribute('aria-disabled');
            }

            if (toggle.disabled !== disabled) {
                toggle.disabled = disabled;
            }

            if (disabled) {
                if (toggle.getAttribute('aria-disabled') !== 'true') {
                    toggle.setAttribute('aria-disabled', 'true');
                }
            } else if (toggle.hasAttribute('aria-disabled')) {
                toggle.removeAttribute('aria-disabled');
            }
        }

        applyDisabledState();

        const cal = createCalendar();
        document.body.appendChild(cal);

        const seed = seedDate();

        const state = {
            open: false,
            viewYear: seed.getUTCFullYear(),
            viewMonth: seed.getUTCMonth(),
            selected: null,
            cursor: seed,
            syncing: false,
            view: 'month'
        };

        function readFromNative() {
            let dt = parseCanonical(nativeInput.value);

            if (!dt) {
                const visibleText = visible.value.trim();
                if (visibleText !== '') {
                    dt = parseDisplay(visibleText);
                }
            }

            state.selected = dt;
            if (dt) {
                state.cursor = dt;
                state.viewYear = dt.getUTCFullYear();
                state.viewMonth = dt.getUTCMonth();
            } else {
                const fallback = seedDate();
                state.cursor = fallback;
                state.viewYear = fallback.getUTCFullYear();
                state.viewMonth = fallback.getUTCMonth();
            }
            state.syncing = true;
            visible.value = dt ? toDisplay(dt) : '';
            state.syncing = false;
        }

        function writeToNative(dt) {
            if (nativeInput.disabled) return;

            nativeInput.value = dt ? toCanonical(dt) : '';
            state.syncing = true;
            visible.value = dt ? toDisplay(dt) : '';
            state.syncing = false;

            nativeInput.dispatchEvent(new Event('input', { bubbles: true }));
            nativeInput.dispatchEvent(new Event('change', { bubbles: true }));
            visible.dispatchEvent(new Event('input', { bubbles: true }));
            visible.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function repaint() {
            if (state.view === 'year') {
                renderYearGrid(cal, state.viewYear, state.selected);
            } else {
                renderMonth(
                    cal,
                    state.viewYear,
                    state.viewMonth,
                    state.selected,
                    state.cursor
                );
            }
        }

        function positionCalendar() {
            const rect = visible.getBoundingClientRect();

            let top  = rect.bottom + 4;
            let left = rect.left;

            const calHeight = cal.offsetHeight || 320;
            if (top + calHeight > window.innerHeight && rect.top > calHeight) {
                top = rect.top - calHeight - 4;
            }

            const calWidth = cal.offsetWidth || 280;
            if (left + calWidth > window.innerWidth - 8) {
                left = window.innerWidth - calWidth - 8;
            }
            if (left < 8) left = 8;

            cal.style.top  = top  + 'px';
            cal.style.left = left + 'px';
        }

        function onDocMouseDown(e) {
            if (cal.contains(e.target)) return;
            if (wrapper.contains(e.target)) return;
            closeCalendar();
        }

        function onDocKeyDown(e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                closeCalendar();
                visible.focus();
                return;
            }

            if (!state.open) return;
            if (state.view !== 'month') return;

            const current = state.cursor || seedDate();
            const key = e.key;

            if (key === 'ArrowLeft') {
                state.cursor = new Date(current.getTime() - 86400000);
            } else if (key === 'ArrowRight') {
                state.cursor = new Date(current.getTime() + 86400000);
            } else if (key === 'ArrowUp') {
                state.cursor = new Date(current.getTime() - 7 * 86400000);
            } else if (key === 'ArrowDown') {
                state.cursor = new Date(current.getTime() + 7 * 86400000);
            } else if (key === 'Enter') {
                e.preventDefault();
                const dt = state.cursor;
                state.selected = dt;
                writeToNative(dt);
                repaint();
                return;
            } else if (key === 'PageUp') {
                state.viewMonth--;
                if (state.viewMonth < 0) { state.viewMonth = 11; state.viewYear--; }
            } else if (key === 'PageDown') {
                state.viewMonth++;
                if (state.viewMonth > 11) { state.viewMonth = 0; state.viewYear++; }
            } else {
                return;
            }

            e.preventDefault();

            state.viewYear = state.cursor.getUTCFullYear();
            state.viewMonth = state.cursor.getUTCMonth();

            repaint();
        }

        function openCalendar() {
            if (state.open || nativeInput.disabled) return;
            state.open = true;

            readFromNative();
            state.view = 'month';
            repaint();

            cal.hidden = false;
            void cal.offsetWidth;
            cal.classList.add('is-open');
            toggle.setAttribute('aria-expanded', 'true');
            positionCalendar();

            document.addEventListener('mousedown', onDocMouseDown);
            document.addEventListener('keydown', onDocKeyDown, true);
            window.addEventListener('resize', positionCalendar);
        }

        function closeCalendar() {
            if (!state.open) return;
            state.open = false;
            state.view = 'month';

            cal.classList.remove('is-open');
            cal.classList.remove('is-year-view');
            cal.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');

            document.removeEventListener('mousedown', onDocMouseDown);
            document.removeEventListener('keydown', onDocKeyDown, true);
            window.removeEventListener('resize', positionCalendar);
        }

        // ---- Visible field: typing ----

        visible.addEventListener('input', function () {
            if (state.syncing) return;
            if (nativeInput.disabled) return;

            const el = visible;
            const before = el.value;
            const caretBefore = el.selectionStart ?? before.length;

            const formatted = formatAsTyped(before);

            if (formatted !== before) {
                el.value = formatted;

                const digitsBefore = before
                    .slice(0, caretBefore)
                    .replace(/\D/g, '').length;

                let newCaret = 0;
                let seen = 0;
                while (newCaret < formatted.length && seen < digitsBefore) {
                    if (/\d/.test(formatted[newCaret])) seen++;
                    newCaret++;
                }
                el.setSelectionRange(newCaret, newCaret);
            }

            const parsed = parseDisplay(el.value);
            if (parsed) {
                nativeInput.value = toCanonical(parsed);
                state.selected = parsed;
                state.cursor = parsed;
                state.viewYear = parsed.getUTCFullYear();
                state.viewMonth = parsed.getUTCMonth();

                nativeInput.dispatchEvent(new Event('input', { bubbles: true }));
                nativeInput.dispatchEvent(new Event('change', { bubbles: true }));

                if (state.open) repaint();
            } else if (el.value === '') {
                nativeInput.value = '';
                state.selected = null;
                nativeInput.dispatchEvent(new Event('input', { bubbles: true }));
                nativeInput.dispatchEvent(new Event('change', { bubbles: true }));

                if (state.open) repaint();
            }
        });

        visible.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                visible.blur();
                return;
            }

            if (e.key === 'Backspace') {
                const el = visible;
                const start = el.selectionStart ?? 0;
                const end = el.selectionEnd ?? 0;

                if (start === end && start > 0) {
                    const charBefore = el.value[start - 1];
                    if (charBefore === '/' || charBefore === '-') {
                        e.preventDefault();
                        const next = el.value.slice(0, start - 2) + el.value.slice(start);
                        el.value = next;
                        el.setSelectionRange(Math.max(0, start - 2), Math.max(0, start - 2));
                        el.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                }
            }
        });

        visible.addEventListener('blur', function () {
            if (nativeInput.disabled) return;

            const typed = visible.value.trim();

            if (typed === '') {
                writeToNative(null);
                return;
            }

            const dt = parseDisplay(typed);
            if (!dt) {
                readFromNative();
                return;
            }

            writeToNative(dt);
        });

        // ---- Toggle button: the only way to open the calendar ----

        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            if (nativeInput.disabled) return;

            if (state.open) {
                closeCalendar();
            } else {
                openCalendar();
            }
        });

        // ---- Header navigation ----
        const prevBtn = cal.querySelector('.fitpal-date-cal-prev');
        const nextBtn = cal.querySelector('.fitpal-date-cal-next');
        const titleBtn = cal.querySelector('.fitpal-date-cal-title');

        if (prevBtn) {
            prevBtn.addEventListener('click', function (e) {
                e.preventDefault();
                if (state.view === 'year') {
                    state.viewYear -= DECADE_SIZE;
                } else {
                    state.viewMonth--;
                    if (state.viewMonth < 0) { state.viewMonth = 11; state.viewYear--; }
                }
                repaint();
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', function (e) {
                e.preventDefault();
                if (state.view === 'year') {
                    state.viewYear += DECADE_SIZE;
                } else {
                    state.viewMonth++;
                    if (state.viewMonth > 11) { state.viewMonth = 0; state.viewYear++; }
                }
                repaint();
            });
        }

        if (titleBtn) {
            titleBtn.addEventListener('click', function (e) {
                e.preventDefault();
                state.view = (state.view === 'month') ? 'year' : 'month';
                repaint();
            });
        }

        // ---- Grid clicks: day and year ----

        const grid = cal.querySelector('.fitpal-date-cal-grid');
        if (grid) {
            grid.addEventListener('click', function (e) {
                const dayBtn = e.target.closest('.fitpal-date-cal-day');
                if (dayBtn) {
                    const dt = parseCanonical(dayBtn.dataset.date);
                    if (!dt) return;
                    state.selected = dt;
                    state.cursor = dt;
                    state.viewYear = dt.getUTCFullYear();
                    state.viewMonth = dt.getUTCMonth();
                    writeToNative(dt);
                    repaint();
                    return;
                }

                const yearBtn = e.target.closest('.fitpal-date-cal-year');
                if (yearBtn) {
                    const y = parseInt(yearBtn.dataset.year, 10);
                    if (!isFinite(y)) return;

                    if (state.selected) {
                        const moved = withYear(state.selected, y);
                        state.selected = moved;
                        state.cursor = moved;
                        state.viewMonth = moved.getUTCMonth();
                        writeToNative(moved);
                    }

                    state.viewYear = y;
                    state.view = 'month';
                    repaint();
                }
            });
        }

        // -----------------------------------------------------------
        // DISABLED-STATE POLL
        //
        // profile.js flips the native input's disabled property
        // when the customer enters and leaves edit mode. A
        // MutationObserver is not reliable for that transition in
        // every browser, so the enhanced field polls the native
        // input on a short interval and reconciles the visible
        // input and the toggle button when the state differs.
        //
        // The loop stops on its own after the field has been in
        // the same state for DISABLED_POLL_QUIET_MS, so a page
        // that never changes the state stops polling.
        // -----------------------------------------------------------

        let lastKnownDisabled = !!nativeInput.disabled;
        let lastChangeAt = Date.now();

        const disabledPollTimer = setInterval(function () {
            const nowDisabled = !!nativeInput.disabled;

            if (nowDisabled !== lastKnownDisabled) {
                lastKnownDisabled = nowDisabled;
                lastChangeAt = Date.now();
                applyDisabledState();

                if (nowDisabled && state.open) {
                    closeCalendar();
                }
                return;
            }

            // No change. Stop if we have been stable long enough.
            if (Date.now() - lastChangeAt > DISABLED_POLL_QUIET_MS) {
                clearInterval(disabledPollTimer);
            }
        }, DISABLED_POLL_INTERVAL_MS);

        readFromNative();

        return {
            native: nativeInput,
            visible: visible,
            toggle: toggle,
            calendar: cal,
            open: openCalendar,
            close: closeCalendar,
            refresh: readFromNative
        };
    }

    // ---------------------------------------------------------------
    // SCAN + OBSERVE
    // ---------------------------------------------------------------

    function enhanceAll(root) {
        const scope = root || document;
        let list;
        try {
            list = scope.querySelectorAll('input[type="date"]:not([' + ENHANCED_ATTR + '="1"])');
        } catch (e) {
            console.error('[fitpal-date-format] query failed:', e);
            return;
        }

        list.forEach(function (el) {
            if (el.closest('template')) return;
            try {
                enhance(el);
            } catch (e) {
                console.error('[fitpal-date-format] enhance() failed for:', el, e);
            }
        });
    }

    // ---------------------------------------------------------------
    // PUBLIC API
    // ---------------------------------------------------------------

    window.FitPalDateFormat = {
        enhance: function (el) {
            try {
                return enhance(el);
            } catch (e) {
                console.error('[fitpal-date-format] enhance() failed:', e);
                return null;
            }
        },
        enhanceAll: enhanceAll,
        getFormat: function () { return currentFormat; },
        setFormat: function (fmt) {
            if (!Object.values(DISPLAY_FORMAT).includes(fmt)) return;
            currentFormat = fmt;
            document.querySelectorAll('.fitpal-date-visible').forEach(function (el) {
                const native = el.previousElementSibling;
                if (native && native.tagName === 'INPUT' && native.type === 'date') {
                    const dt = parseCanonical(native.value);
                    el.value = dt ? toDisplay(dt) : '';
                    el.placeholder = currentFormat;
                }
            });
        }
    };

    // ---------------------------------------------------------------
    // BOOTSTRAP
    // ---------------------------------------------------------------

    function boot() {
        try {
            enhanceAll(document);
        } catch (e) {
            console.error('[fitpal-date-format] boot() failed:', e);
            return;
        }

        try {
            const observer = new MutationObserver(function (mutations) {
                for (const mutation of mutations) {
                    for (const node of mutation.addedNodes) {
                        if (node.nodeType !== Node.ELEMENT_NODE) continue;
                        if (node.matches && node.matches('input[type="date"]')) {
                            if (!node.closest('template')) {
                                try { enhance(node); }
                                catch (e) { console.error('[fitpal-date-format] observer enhance failed:', e); }
                            }
                        }
                        if (node.querySelectorAll) {
                            enhanceAll(node);
                        }
                    }
                }
            });

            observer.observe(document.body, { childList: true, subtree: true });
        } catch (e) {
            console.error('[fitpal-date-format] observer setup failed:', e);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();