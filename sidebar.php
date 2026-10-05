<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>

<link rel="stylesheet" href="dark_theme.css">
<link rel="stylesheet" href="responsive_mobile.css">

<style>

/* =====================================================
   SHARED DASHBOARD SIDEBAR
===================================================== */

* {
    box-sizing: border-box;
}

.sidebar {
    width: 250px;
    min-height: 100vh;

    background: #101c2d;
    color: white;

    position: fixed;
    left: 0;
    top: 0;
    bottom: 0;

    overflow-y: auto;

    z-index: 1000;

    box-shadow: 4px 0 18px rgba(0, 0, 0, .08);

    display: flex;
    flex-direction: column;
}


/* =====================================================
   LOGO
===================================================== */

.sidebar .logo {
    font-size: 25px;
    font-weight: 800;

    padding: 28px 24px;

    border-bottom: 1px solid rgba(255, 255, 255, .08);

    color: #ffffff;

    line-height: 1.2;
}

.sidebar .logo span {
    color: #20b9f5;
}


/* =====================================================
   NAVIGATION
===================================================== */

.sidebar nav {
    padding: 22px 12px;

    flex: 1;
}

.sidebar nav a {
    display: flex;

    align-items: center;

    min-height: 48px;

    padding: 12px 15px;

    margin-bottom: 6px;

    color: #c7d1df;

    text-decoration: none;

    font-size: 15px;

    font-weight: 600;

    border-radius: 10px;

    transition: .2s ease;
}


/* =====================================================
   NAV ICON
===================================================== */

.sidebar .nav-icon {
    width: 30px;

    min-width: 30px;

    font-size: 18px;

    line-height: 1;

    display: inline-flex;

    align-items: center;

    justify-content: flex-start;
}


/* =====================================================
   SECTION LABEL
===================================================== */

.sidebar .nav-section {
    color: #718096;

    font-size: 11px;

    font-weight: 800;

    letter-spacing: 1.2px;

    padding: 18px 15px 8px;

    text-transform: uppercase;
}


/* =====================================================
   HOVER
===================================================== */

.sidebar nav a:hover {
    background: rgba(32, 185, 245, .12);

    color: #ffffff;

    transform: translateX(2px);
}


/* =====================================================
   ACTIVE PAGE
===================================================== */

.sidebar nav a.active {
    background: linear-gradient(
        135deg,
        #16b5f4,
        #168ce8
    );

    color: #ffffff;

    box-shadow:
        0 7px 18px rgba(22, 140, 232, .25);
}


/* =====================================================
   SYSTEM AREA
===================================================== */

.sidebar .system-section {
    margin-top: 10px;

    padding-top: 10px;

    border-top: 1px solid rgba(255, 255, 255, .07);
}


/* =====================================================
   LOGOUT
===================================================== */

.sidebar .logout-link {
    margin-top: 18px;

    border-top: 1px solid rgba(255, 255, 255, .08);

    padding-top: 20px !important;

    color: #ff9c9c !important;

    border-radius: 0 0 10px 10px;
}

.sidebar .logout-link:hover {
    background: rgba(239, 68, 68, .12) !important;

    color: #ffb4b4 !important;

    transform: translateX(2px);
}

.sidebar-header-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}

.sidebar-header-logout-btn {
    display: none;
    align-items: center;
    gap: 5px;
    padding: 6px 12px;
    border-radius: 8px;
    background: rgba(239, 68, 68, 0.15);
    border: 1px solid rgba(239, 68, 68, 0.35);
    color: #fca5a5 !important;
    text-decoration: none;
    font-size: 12px;
    font-weight: 700;
    transition: all 0.2s ease;
}

.sidebar-header-logout-btn:hover {
    background: rgba(239, 68, 68, 0.28);
    color: #ffffff !important;
}

@media (max-width: 900px) {
    .sidebar-header-logout-btn {
        display: inline-flex;
    }
}

</style>

<!-- =====================================================
     MOBILE TOPBAR & BACKDROP
===================================================== -->

<header class="mobile-topbar" id="mobileTopbar">
    <button type="button" class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Toggle navigation menu" title="Open Menu">
        <span class="hamburger-box">
            <span class="hamburger-inner"></span>
        </span>
    </button>

    <a href="dashboard.php" class="mobile-brand">
        <span class="brand-currency">₹</span>
        <span class="brand-name">Expense<span>Tracker</span></span>
    </a>

    <div class="mobile-topbar-actions">
        <button type="button" class="mobile-quick-theme-btn" id="mobileQuickThemeBtn" aria-label="Toggle theme" onclick="window.toggleExpenseTheme()" title="Toggle Theme">
            <span id="mobileThemeIcon">🌙</span>
        </button>

        <a href="logout.php" class="mobile-quick-logout-btn" aria-label="Log Out" title="Log Out" onclick="return confirm('Are you sure you want to log out?');">
            <span class="mobile-logout-icon">🚪</span>
            <span class="mobile-logout-text">Logout</span>
        </a>
    </div>
</header>

<div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>

<!-- =====================================================
     SHARED SIDEBAR
===================================================== -->

<aside class="sidebar" id="appSidebar">

    <!-- LOGO & MOBILE CLOSE BUTTON -->
    <div class="sidebar-header-row">
        <div class="logo">
            Expense<span>Tracker</span>
        </div>
        <div class="sidebar-header-actions">
            <a href="logout.php" class="sidebar-header-logout-btn" title="Log Out" onclick="return confirm('Are you sure you want to log out?');">
                <span>🚪 Logout</span>
            </a>
            <button type="button" class="sidebar-close-btn" id="sidebarCloseBtn" aria-label="Close navigation menu" title="Close Menu">&times;</button>
        </div>
    </div>


    <!-- NAVIGATION -->

    <nav>

        <!-- MAIN -->

        <div class="nav-section">
            Main
        </div>


        <!-- DASHBOARD -->

        <a
            href="dashboard.php"
            class="<?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>"
        >
            <span class="nav-icon">🏠</span>
            Dashboard
        </a>


        <!-- TRANSACTIONS -->

        <a
            href="transactions.php"
            class="<?php echo $current_page === 'transactions.php' ? 'active' : ''; ?>"
        >
            <span class="nav-icon">💳</span>
            Transactions
        </a>


        <!-- ACCOUNTS -->

        <a
            href="accounts.php"
            class="<?php echo $current_page === 'accounts.php' ? 'active' : ''; ?>"
        >
            <span class="nav-icon">🏦</span>
            Accounts
        </a>


        <!-- BUDGETS -->

        <a
            href="budgets.php"
            class="<?php echo $current_page === 'budgets.php' ? 'active' : ''; ?>"
        >
            <span class="nav-icon">📊</span>
            Budgets
        </a>


        <!-- PLANNING -->

        <div class="nav-section">
            Planning
        </div>


        <!-- FINANCIAL GOALS -->

        <a
            href="financial_goals.php"
            class="<?php echo $current_page === 'financial_goals.php' ? 'active' : ''; ?>"
        >
            <span class="nav-icon">🎯</span>
            Financial Goals
        </a>


        <!-- SPENDING INSIGHTS -->

        <a
            href="spending_insights.php"
            class="<?php echo $current_page === 'spending_insights.php' ? 'active' : ''; ?>"
        >
            <span class="nav-icon">💡</span>
            Spending Insights
        </a>


        <!-- FINANCIAL CALENDAR -->

        <a
            href="financial_calendar.php"
            class="<?php echo $current_page === 'financial_calendar.php' ? 'active' : ''; ?>"
        >
            <span class="nav-icon">📅</span>
            Financial Calendar
        </a>


        <!-- DEBTS & LOANS -->

        <a
            href="debts_loans.php"
            class="<?php echo $current_page === 'debts_loans.php' ? 'active' : ''; ?>"
        >
            <span class="nav-icon">💰</span>
            Debt &amp; Loans
        </a>


        <!-- MEDICAL EXPENSES -->

        <a
            href="medical_expenses.php"
            class="<?php echo $current_page === 'medical_expenses.php' ? 'active' : ''; ?>"
        >
            <span class="nav-icon">🏥</span>
            Medical Expenses
        </a>


        <!-- SYSTEM -->

        <div class="system-section">

            <div class="nav-section">
                System
            </div>


            <!-- SETTINGS -->

            <a
                href="settings.php"
                class="<?php echo $current_page === 'settings.php' ? 'active' : ''; ?>"
            >
                <span class="nav-icon">⚙️</span>
                Settings
            </a>

            <?php if (!empty($is_admin)): ?>
            <!-- USER MANAGEMENT (ADMIN) -->
            <a
                href="admin_users.php"
                class="<?php echo $current_page === 'admin_users.php' ? 'active' : ''; ?>"
            >
                <span class="nav-icon">👥</span>
                Users Manager
                <span style="margin-left: auto; font-size: 10px; font-weight: 700; background: rgba(32, 185, 245, 0.2); color: #38bdf8; padding: 2px 7px; border-radius: 6px; text-transform: uppercase;">Admin</span>
            </a>

            <!-- EMAIL CONFIGURATION (ADMIN) -->
            <a
                href="email_config.php"
                class="<?php echo $current_page === 'email_config.php' ? 'active' : ''; ?>"
            >
                <span class="nav-icon">✉️</span>
                Email Settings
                <span style="margin-left: auto; font-size: 10px; font-weight: 700; background: rgba(32, 185, 245, 0.2); color: #38bdf8; padding: 2px 7px; border-radius: 6px; text-transform: uppercase;">Admin</span>
            </a>
            <?php endif; ?>


            <!-- HELP & SUPPORT -->

            <a
                href="help_support.php"
                class="<?php echo $current_page === 'help_support.php' ? 'active' : ''; ?>"
            >
                <span class="nav-icon">❓</span>
                Help &amp; Support
            </a>

        </div>

        <!-- THEME SWITCHER -->
        <div class="sidebar-theme-panel">
            <div class="sidebar-theme-header">
                <span class="sidebar-theme-title">Appearance</span>
                <span class="sidebar-theme-badge" id="sidebarThemeModeText">Dark</span>
            </div>
            <div class="sidebar-theme-group" role="radiogroup" aria-label="Appearance Mode">
                <button type="button" class="sidebar-theme-btn" data-theme="light" title="Light Mode" onclick="window.setExpenseTheme('light')">
                    <span class="theme-icon">☀️</span>
                    <span class="theme-lbl">Light</span>
                </button>
                <button type="button" class="sidebar-theme-btn" data-theme="dark" title="Dark Mode" onclick="window.setExpenseTheme('dark')">
                    <span class="theme-icon">🌙</span>
                    <span class="theme-lbl">Dark</span>
                </button>
                <button type="button" class="sidebar-theme-btn" data-theme="system" title="System Theme (Follows OS)" onclick="window.setExpenseTheme('system')">
                    <span class="theme-icon">💻</span>
                    <span class="theme-lbl">Auto</span>
                </button>
            </div>
        </div>

        <!-- LOGOUT -->
        <a
            href="logout.php"
            class="logout-link"
        >
            <span class="nav-icon">↪</span>
            Logout
        </a>

    </nav>

</aside>

<script src="theme.js"></script>

<script>
(function() {
    function setupMobileNav() {
        var sidebar = document.getElementById('appSidebar');
        var menuBtn = document.getElementById('mobileMenuBtn');
        var closeBtn = document.getElementById('sidebarCloseBtn');
        var backdrop = document.getElementById('sidebarBackdrop');

        if (!sidebar) return;

        function openSidebar() {
            sidebar.classList.add('mobile-open');
            if (backdrop) backdrop.classList.add('active');
            document.body.classList.add('mobile-nav-lock');
        }

        function closeSidebar() {
            sidebar.classList.remove('mobile-open');
            if (backdrop) backdrop.classList.remove('active');
            document.body.classList.remove('mobile-nav-lock');
        }

        function toggleSidebar() {
            if (sidebar.classList.contains('mobile-open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        }

        if (menuBtn) {
            menuBtn.removeEventListener('click', toggleSidebar);
            menuBtn.addEventListener('click', toggleSidebar);
        }

        if (closeBtn) {
            closeBtn.removeEventListener('click', closeSidebar);
            closeBtn.addEventListener('click', closeSidebar);
        }

        if (backdrop) {
            backdrop.removeEventListener('click', closeSidebar);
            backdrop.addEventListener('click', closeSidebar);
        }

        // Close on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && sidebar.classList.contains('mobile-open')) {
                closeSidebar();
            }
        });

        // Close on clicking any link in sidebar when on mobile
        var links = sidebar.querySelectorAll('nav a');
        links.forEach(function(link) {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 900) {
                    closeSidebar();
                }
            });
        });

        // Handle touch swipe left on sidebar to close
        var startX = 0;
        sidebar.addEventListener('touchstart', function(e) {
            if (e.touches && e.touches[0]) {
                startX = e.touches[0].clientX;
            }
        }, { passive: true });

        sidebar.addEventListener('touchend', function(e) {
            if (e.changedTouches && e.changedTouches[0]) {
                var diffX = startX - e.changedTouches[0].clientX;
                if (diffX > 60) {
                    closeSidebar();
                }
            }
        }, { passive: true });

        // Auto-close if screen expands beyond 900px
        window.addEventListener('resize', function() {
            if (window.innerWidth > 900) {
                closeSidebar();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupMobileNav);
    } else {
        setupMobileNav();
    }
})();
</script>