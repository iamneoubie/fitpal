(function () {
    'use strict';

    const CLOCK_ID = 'custom-titlebar-clock';
    let clockContainer = null;
    let timeLabel = null;
    let profileLabel = null;
    
    // Cache the profile info so we only fetch it when the workspace changes
    let cachedProfileInfo = 'Default - No Folder';
    let lastWorkspaceKey = '';

    // --- HELPER FUNCTIONS ---

    function formatTime(date) {
        const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        const months = ['January', 'February', 'March', 'April', 'May', 'June',
                        'July', 'August', 'September', 'October', 'November', 'December'];
        const dddd = days[date.getDay()];
        const mmmm = months[date.getMonth()];
        const dd = String(date.getDate()).padStart(2, '0');
        let hh = date.getHours();
        const ampm = hh >= 12 ? 'PM' : 'AM';
        hh = hh % 12 || 12;
        const hhStr = String(hh).padStart(2, '0');
        const mm = String(date.getMinutes()).padStart(2, '0');
        return `${dddd} - ${mmmm} ${dd}, ${date.getFullYear()} - ${hhStr}:${mm} ${ampm}`;
    }

    function formatClipboard(date) {
        const MM = String(date.getMonth() + 1).padStart(2, '0');
        const DD = String(date.getDate()).padStart(2, '0');
        const YYYY = date.getFullYear();
        const HH = String(date.getHours()).padStart(2, '0');
        const mm = String(date.getMinutes()).padStart(2, '0');
        return `${MM}.${DD}.${YYYY}.${HH}.${mm}`;
    }

    async function copyToClipboard(text) {
        try {
            if (typeof require === 'function') {
                const electron = require('electron');
                if (electron && electron.clipboard) {
                    electron.clipboard.writeText(text);
                    return true;
                }
            }
        } catch (err) {}
        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(text);
                return true;
            }
        } catch (err) {}
        try {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            ta.setAttribute('readonly', '');
            document.body.appendChild(ta);
            ta.select();
            ta.setSelectionRange(0, ta.value.length);
            const ok = document.execCommand('copy');
            document.body.removeChild(ta);
            return ok;
        } catch (err) { return false; }
    }

    // --- PROFILE INFO PARSING (FIXED & OPTIMIZED) ---
    // We parse the DOM title, but only when the workspace changes.
    function getProfileInfo() {
        try {
            // Try to get the raw title from the DOM (this is what worked in your screenshot)
            const titleEl = document.querySelector('.window-title');
            if (titleEl && titleEl.textContent) {
                const rawTitle = titleEl.textContent.trim();
                
                // Only recalculate if the title actually changed
                if (rawTitle !== lastWorkspaceKey) {
                    lastWorkspaceKey = rawTitle;
                    
                    // VS Code title usually looks like: "Profile - Folder - VS Code"
                    // Or sometimes: "Profile - Folder"
                    // We split by " - " and take the first two parts.
                    const parts = rawTitle.split(' - ');
                    
                    if (parts.length >= 2) {
                        // Clean up "Profile:" prefix if it exists
                        let profileName = parts[0].replace(/^Profile:\s*/i, '').trim();
                        let folderName = parts[1].trim();
                        
                        cachedProfileInfo = `${profileName} - ${folderName}`;
                    } else {
                        // Fallback if the format is unexpected
                        cachedProfileInfo = rawTitle;
                    }
                }
            }
        } catch (err) {
            // Silent fail, use cached value
        }
        return cachedProfileInfo;
    }

    // --- DOM INJECTION ---
    
    function injectClock() {
        if (document.getElementById(CLOCK_ID)) return true;

        const center = document.querySelector('.titlebar-center')
                    || document.querySelector('.window-title')?.parentElement
                    || document.querySelector('.titlebar');
        if (!center) return false;

        // Hide default title
        document.querySelectorAll('.window-title').forEach(el => el.style.display = 'none');

        // Create container
        clockContainer = document.createElement('div');
        clockContainer.id = CLOCK_ID;
        clockContainer.className = 'custom-titlebar-clock';

        // Create labels
        timeLabel = document.createElement('span');
        timeLabel.className = 'custom-titlebar-clock-text';
        timeLabel.textContent = formatTime(new Date());
        
        profileLabel = document.createElement('span');
        profileLabel.className = 'custom-titlebar-profile-info';
        // Initialize with parsed info
        profileLabel.textContent = getProfileInfo();

        clockContainer.appendChild(timeLabel);
        clockContainer.appendChild(profileLabel);

        const title = center.querySelector('.window-title');
        if (title && title.parentNode === center) {
            center.insertBefore(clockContainer, title);
        } else {
            center.appendChild(clockContainer);
        }

        // Click to copy
        clockContainer.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            copyToClipboard(formatClipboard(new Date()));
        });

        return true;
    }

    // --- PANEL SWITCHER ---
    function forcePanelDefault() {
        const activeTab = document.querySelector('.panel-switcher-container .action-label.checked');
        if (activeTab && activeTab.textContent.trim() === 'Tasks') {
            const phpTab = Array.from(document.querySelectorAll('.panel-switcher-container .action-label'))
                .find(tab => tab.textContent.trim() === 'PHP Server');
            if (phpTab) phpTab.click();
        }
    }

    // --- INITIALIZATION & TIMERS ---

    // 1. Clock Timer: Runs every second, only updates text strings.
    setInterval(() => {
        if (timeLabel) {
            timeLabel.textContent = formatTime(new Date());
        }
        // Check if profile info needs update (this only re-parses the DOM if the title changed)
        const currentInfo = getProfileInfo();
        if (profileLabel && profileLabel.textContent !== currentInfo) {
            profileLabel.textContent = currentInfo;
        }
    }, 1000);

    // 2. Scoped Mutation Observer
    // Watches ONLY the titlebar and panel switcher. Does NOT fire when typing in the editor.
    const observer = new MutationObserver((mutations) => {
        let shouldInject = false;
        let shouldSwitchPanel = false;

        for (const mutation of mutations) {
            if (mutation.target.closest('.titlebar-center, .titlebar')) {
                shouldInject = true;
            }
            if (mutation.target.closest('.panel-switcher-container')) {
                shouldSwitchPanel = true;
            }
        }

        if (shouldInject) injectClock();
        if (shouldSwitchPanel) forcePanelDefault();
    });

    // 3. Wait for the UI to load, then start observing
    const initObserver = setInterval(() => {
        const titlebar = document.querySelector('.titlebar');
        if (titlebar) {
            clearInterval(initObserver);
            
            injectClock(); // Inject immediately
            
            // Observe only the necessary parts of the UI
            observer.observe(titlebar, { childList: true, subtree: true });
            
            const panelSwitcher = document.querySelector('.panel-switcher-container');
            if (panelSwitcher) {
                observer.observe(panelSwitcher, { childList: true, subtree: true });
            }
        }
    }, 500);

    // Fallback
    window.addEventListener('focus', forcePanelDefault);
    setTimeout(forcePanelDefault, 1000);
})();