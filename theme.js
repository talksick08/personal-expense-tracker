/**
 * Universal Theme Manager for Personal Expense Tracker
 * Handles dark, light, and system theme modes with full persistence across all pages and tabs.
 */
(function() {
    var STORAGE_KEY = 'expenseTrackerTheme';

    function getSavedTheme() {
        return localStorage.getItem(STORAGE_KEY) || 'system';
    }

    function isSystemDark() {
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    }

    function resolveThemeIsDark(theme) {
        if (theme === 'dark') return true;
        if (theme === 'light') return false;
        return isSystemDark();
    }

    function updateDOMTheme(isDark, theme) {
        if (isDark) {
            document.documentElement.classList.add('dark-mode');
            if (document.body) document.body.classList.add('dark-mode');
        } else {
            document.documentElement.classList.remove('dark-mode');
            if (document.body) document.body.classList.remove('dark-mode');
        }

        // Sync all sidebar toggle buttons
        document.querySelectorAll('.sidebar-theme-btn').forEach(function(btn) {
            var btnTheme = btn.getAttribute('data-theme');
            if (btnTheme === theme) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });

        // Sync sidebar theme label if present
        var sidebarLabel = document.getElementById('sidebarThemeModeText');
        if (sidebarLabel) {
            sidebarLabel.textContent = theme === 'dark' ? 'Dark' : (theme === 'light' ? 'Light' : 'Auto');
        }

        // Sync dashboard theme buttons if present
        document.querySelectorAll('.theme-btn').forEach(function(btn) {
            var btnTheme = btn.getAttribute('data-theme');
            if (btnTheme === theme) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });

        // Sync settings select dropdown if present
        var settingsSelect = document.getElementById('themeSelect');
        if (settingsSelect && settingsSelect.value !== theme) {
            settingsSelect.value = theme;
        }

        // Sync edit transaction toggle if present
        var editToggle = document.getElementById('editPageThemeToggle');
        if (editToggle) {
            editToggle.setAttribute('title', isDark ? 'Switch to Light Mode' : 'Switch to Dark Mode');
            editToggle.innerHTML = isDark ? '☀️ Light' : '🌙 Dark';
        }
    }

    function applyExpenseTheme(theme) {
        if (!theme) theme = getSavedTheme();
        var isDark = resolveThemeIsDark(theme);
        updateDOMTheme(isDark, theme);
    }

    function setExpenseTheme(theme) {
        if (theme !== 'dark' && theme !== 'light' && theme !== 'system') {
            theme = 'system';
        }
        localStorage.setItem(STORAGE_KEY, theme);
        try {
            document.cookie = STORAGE_KEY + "=" + encodeURIComponent(theme) + "; path=/; max-age=31536000; SameSite=Lax";
        } catch (e) {}

        applyExpenseTheme(theme);

        // Broadcast to other windows / components
        try {
            window.dispatchEvent(new CustomEvent('expenseTrackerThemeChanged', { detail: { theme: theme } }));
        } catch (e) {}
    }

    function toggleExpenseTheme() {
        var current = getSavedTheme();
        var isDark = resolveThemeIsDark(current);
        setExpenseTheme(isDark ? 'light' : 'dark');
    }

    // Expose global functions on window
    window.applyExpenseTheme = applyExpenseTheme;
    window.setExpenseTheme = setExpenseTheme;
    window.toggleExpenseTheme = toggleExpenseTheme;
    window.setAppTheme = setExpenseTheme;   // Backwards compatibility with dashboard.php
    window.applyAppTheme = applyExpenseTheme; // Backwards compatibility with dashboard.php
    window.applyTheme = applyExpenseTheme;    // Backwards compatibility with settings.php

    // Execute immediately to avoid flash
    applyExpenseTheme(getSavedTheme());

    // Execute again on DOMContentLoaded to update elements that weren't parsed yet
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            applyExpenseTheme(getSavedTheme());
        });
    } else {
        applyExpenseTheme(getSavedTheme());
    }

    // Listen for OS system theme changes
    if (window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function() {
            if (getSavedTheme() === 'system') {
                applyExpenseTheme('system');
            }
        });
    }

    // Cross-tab synchronization
    window.addEventListener('storage', function(e) {
        if (e.key === STORAGE_KEY) {
            applyExpenseTheme(e.newValue || 'system');
        }
    });

    // Custom event synchronization
    window.addEventListener('expenseTrackerThemeChanged', function(e) {
        if (e.detail && e.detail.theme) {
            applyExpenseTheme(e.detail.theme);
        }
    });
})();
