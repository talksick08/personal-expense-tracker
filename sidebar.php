<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>

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


/* =====================================================
   MOBILE / TABLET
===================================================== */

@media (max-width: 850px) {

    .sidebar {
        width: 210px;
    }

}


/* =====================================================
   MOBILE
===================================================== */

@media (max-width: 700px) {

    .sidebar {
        position: relative;

        width: 100%;

        min-height: auto;

        height: auto;
    }

    .sidebar nav {
        padding: 15px 12px;
    }

    .sidebar nav a {
        min-height: 46px;
    }

}

</style>


<!-- =====================================================
     SHARED SIDEBAR
===================================================== -->

<aside class="sidebar">

    <!-- LOGO -->

    <div class="logo">
        Expense<span>Tracker</span>
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


            <!-- HELP & SUPPORT -->

            <a
                href="help_support.php"
                class="<?php echo $current_page === 'help_support.php' ? 'active' : ''; ?>"
            >
                <span class="nav-icon">❓</span>
                Help &amp; Support
            </a>

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