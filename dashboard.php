<?php

require_once "auth.php";

/* =========================
   DASHBOARD TOTALS
========================= */

$total_income = 0;
$total_expenses = 0;
$total_balance = 0;

$stmt = $conn->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS income,
        COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expenses
    FROM transactions
    WHERE user_id = ?
");

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $total_income = (float)$row['income'];
    $total_expenses = (float)$row['expenses'];
}

$stmt->close();

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(balance), 0) AS total_balance
    FROM accounts
    WHERE user_id = ?
");

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $total_balance = (float)$row['total_balance'];
}

$stmt->close();

$net_cash_flow = $total_income - $total_expenses;

$savings_rate = 0;

if ($total_income > 0) {
    $savings_rate = ($net_cash_flow / $total_income) * 100;
}

/* =========================
   RECENT TRANSACTIONS
========================= */

$recent_transactions = [];

$stmt = $conn->prepare("
    SELECT
        id,
        type,
        amount,
        category,
        description,
        transaction_date
    FROM transactions
    WHERE user_id = ?
    ORDER BY transaction_date DESC, id DESC
    LIMIT 8
");

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $recent_transactions[] = $row;
}

$stmt->close();

/* =========================
   ACCOUNTS
========================= */

$accounts = [];

$stmt = $conn->prepare("
    SELECT
        id,
        name,
        type,
        balance
    FROM accounts
    WHERE user_id = ?
    ORDER BY balance DESC
");

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $accounts[] = $row;
}

$stmt->close();

/* =========================
   MONTHLY DATA
========================= */

$monthly_income = [];
$monthly_expenses = [];
$month_labels = [];

for ($i = 5; $i >= 0; $i--) {

    $month_timestamp = strtotime("-" . $i . " months");

    $month_start = date("Y-m-01", $month_timestamp);
    $month_end = date("Y-m-t", $month_timestamp);

    $month_labels[] = date("M", $month_timestamp);

    $income = 0;
    $expense = 0;

    $stmt = $conn->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS income,
            COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expense
        FROM transactions
        WHERE user_id = ?
        AND transaction_date BETWEEN ? AND ?
    ");

    $stmt->bind_param(
        "iss",
        $user_id,
        $month_start,
        $month_end
    );

    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $income = (float)$row['income'];
        $expense = (float)$row['expense'];
    }

    $stmt->close();

    $monthly_income[] = $income;
    $monthly_expenses[] = $expense;
}

/* =========================
   CATEGORY SPENDING
========================= */

$category_names = [];
$category_amounts = [];

$stmt = $conn->prepare("
    SELECT
        category,
        SUM(amount) AS total
    FROM transactions
    WHERE user_id = ?
    AND type = 'expense'
    GROUP BY category
    ORDER BY total DESC
    LIMIT 6
");

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $category_names[] = $row['category'];
    $category_amounts[] = (float)$row['total'];
}

$stmt->close();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Dashboard - Personal Expense Tracker</title>

    <script>
        (function() {
            var theme = localStorage.getItem('expenseTrackerTheme') || 'system';
            var isDark = theme === 'dark' || (theme === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
            if (isDark) {
                document.documentElement.classList.add('dark-mode');
            }
        })();
    </script>
    <link rel="stylesheet" href="dark_theme.css?v=2.2">
    <link rel="stylesheet" href="responsive_mobile.css?v=2.2">

    <style>

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #f4f7fb;
            color: #172033;
        }

        .app-layout {
            display: flex;
            min-height: 100vh;
        }

        /* =========================
           MAIN CONTENT
        ========================= */

        .main-content {
            margin-left: 250px;
            width: calc(100% - 250px);
            min-height: 100vh;
            padding: 40px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .page-header h1 {
            font-size: 34px;
            font-weight: 800;
            color: #162033;
            margin-bottom: 6px;
        }

        .page-header p {
            color: #718096;
            font-size: 15px;
        }

        .date-badge {
            background: #fff;
            border: 1px solid #e6ebf2;
            padding: 12px 18px;
            border-radius: 10px;
            color: #667085;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 5px 18px rgba(24,39,75,.05);
        }

        /* =========================
           KPI CARDS
        ========================= */

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: #fff;
            border: 1px solid #e6ebf2;
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 8px 25px rgba(24,39,75,.06);
            position: relative;
            overflow: hidden;
        }

        .stat-card::after {
            content: "";
            position: absolute;
            width: 75px;
            height: 75px;
            border-radius: 50%;
            right: -25px;
            top: -25px;
            background: rgba(32,185,245,.08);
        }

        .stat-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
        }

        .stat-title {
            color: #718096;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            background: #eef8ff;
        }

        .stat-value {
            font-size: 27px;
            font-weight: 800;
            color: #172033;
        }

        .stat-subtitle {
            margin-top: 8px;
            color: #8a94a6;
            font-size: 12px;
        }

        .income .stat-icon {
            background: #eaf8ef;
        }

        .expense .stat-icon {
            background: #fff0f0;
        }

        .balance .stat-icon {
            background: #eef5ff;
        }

        .savings .stat-icon {
            background: #f3efff;
        }

        /* =========================
           CONTENT GRID
        ========================= */

        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }

        .card {
            background: #fff;
            border: 1px solid #e6ebf2;
            border-radius: 18px;
            padding: 26px;
            box-shadow: 0 8px 25px rgba(24,39,75,.06);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 22px;
        }

        .card-header h2 {
            font-size: 20px;
            color: #172033;
        }

        .card-header p {
            color: #8993a4;
            font-size: 13px;
            margin-top: 4px;
        }

        .view-all {
            color: #168ce8;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
        }

        .view-all:hover {
            text-decoration: underline;
        }

        /* =========================
           CHART
        ========================= */

        .chart-container {
            width: 100%;
            height: 300px;
            position: relative;
        }

        .chart {
            height: 100%;
            display: flex;
            align-items: flex-end;
            gap: 18px;
            padding: 20px 5px 35px;
            border-bottom: 1px solid #e6ebf2;
        }

        .chart-column {
            flex: 1;
            height: 100%;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            gap: 5px;
            position: relative;
        }

        .bar {
            width: 18px;
            min-height: 3px;
            border-radius: 6px 6px 2px 2px;
            transition: .2s;
        }

        .income-bar {
            background: #25b76b;
        }

        .expense-bar {
            background: #ef6464;
        }

        .bar:hover {
            opacity: .75;
        }

        .month-label {
            position: absolute;
            bottom: -27px;
            font-size: 11px;
            color: #8a94a6;
            font-weight: 600;
        }

        .chart-legend {
            display: flex;
            gap: 20px;
            margin-top: 18px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 12px;
            color: #667085;
        }

        .legend-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
        }

        .legend-income {
            background: #25b76b;
        }

        .legend-expense {
            background: #ef6464;
        }

        /* =========================
           ACCOUNTS
        ========================= */

        .account-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .account-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px;
            border: 1px solid #edf0f4;
            border-radius: 12px;
            background: #fbfcfe;
        }

        .account-left {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .account-icon {
            width: 42px;
            height: 42px;
            border-radius: 11px;
            background: #eef7ff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
            flex-shrink: 0;
        }

        .account-name {
            font-size: 14px;
            font-weight: 700;
            color: #263246;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .account-type {
            color: #8993a4;
            font-size: 11px;
            margin-top: 3px;
        }

        .account-balance {
            font-size: 14px;
            font-weight: 800;
            color: #172033;
            white-space: nowrap;
        }

        /* =========================
           TRANSACTIONS
        ========================= */

        .transaction-list {
            display: flex;
            flex-direction: column;
        }

        .transaction-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 0;
            border-bottom: 1px solid #edf0f4;
        }

        .transaction-item:last-child {
            border-bottom: none;
        }

        .transaction-left {
            display: flex;
            align-items: center;
            gap: 13px;
            min-width: 0;
        }

        .transaction-icon {
            width: 42px;
            height: 42px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .transaction-icon.income {
            background: #eaf8ef;
        }

        .transaction-icon.expense {
            background: #fff0f0;
        }

        .transaction-info {
            min-width: 0;
        }

        .transaction-category {
            font-size: 14px;
            font-weight: 700;
            color: #263246;
        }

        .transaction-description {
            color: #8993a4;
            font-size: 12px;
            margin-top: 3px;
            max-width: 350px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .transaction-date {
            color: #9aa3b2;
            font-size: 11px;
            margin-top: 3px;
        }

        .transaction-amount {
            font-size: 14px;
            font-weight: 800;
            white-space: nowrap;
        }

        .income-text {
            color: #159447;
        }

        .expense-text {
            color: #e04444;
        }

        /* =========================
           CATEGORY SECTION
        ========================= */

        .category-list {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .category-row {
            width: 100%;
        }

        .category-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 7px;
            font-size: 13px;
        }

        .category-name {
            color: #344054;
            font-weight: 600;
        }

        .category-value {
            color: #667085;
            font-weight: 700;
        }

        .progress {
            height: 8px;
            background: #edf1f5;
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg,#16b5f4,#168ce8);
            border-radius: 10px;
        }

        /* =========================
           EMPTY STATE
        ========================= */

        .empty-state {
            text-align: center;
            padding: 35px 15px;
            color: #8993a4;
            font-size: 14px;
        }

        .empty-icon {
            font-size: 35px;
            margin-bottom: 10px;
        }

        /* =========================
           RESPONSIVE
        ========================= */

        @media (max-width: 1100px) {

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .dashboard-grid {
                grid-template-columns: 1fr;
            }

        }

        @media (max-width: 850px) {

            .sidebar {
                width: 210px;
            }

            .main-content {
                margin-left: 210px;
                width: calc(100% - 210px);
                padding: 25px;
            }

        }

        @media (max-width: 700px) {

            .sidebar {
                position: relative;
                width: 100%;
                min-height: auto;
            }

            .app-layout {
                display: block;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 20px;
            }

            .page-header {
                align-items: flex-start;
                flex-direction: column;
                gap: 15px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

        }

        @media (max-width: 450px) {

            .main-content {
                padding: 15px;
            }

            .card {
                padding: 18px;
            }

            .page-header h1 {
                font-size: 28px;
            }

            .stat-value {
                font-size: 23px;
            }

            .chart {
                gap: 8px;
            }

            .bar {
                width: 12px;
            }

        }


        /* =========================
           DASHBOARD V2 POLISH
        ========================= */

        .page-header {
            position: relative;
        }

        .page-header > div:first-child::after {
            content: "";
            display: block;
            width: 46px;
            height: 3px;
            margin-top: 13px;
            border-radius: 10px;
            background: linear-gradient(90deg, #16b5f4, #168ce8);
        }

        .date-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }

        .stat-card,
        .card,
        .account-item,
        .transaction-item {
            transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 14px 32px rgba(24,39,75,.10);
            border-color: #dbe5f0;
        }

        .card {
            overflow: hidden;
        }

        .card:hover {
            box-shadow: 0 12px 30px rgba(24,39,75,.08);
        }

        .account-item:hover {
            transform: translateY(-1px);
            border-color: #dbe7f2;
            box-shadow: 0 5px 16px rgba(24,39,75,.05);
        }

        .transaction-item {
            padding-left: 8px;
            padding-right: 8px;
            border-radius: 10px;
        }

        .transaction-item:hover {
            background: #fafcff;
        }

        .transaction-icon,
        .account-icon,
        .stat-icon {
            transition: transform .2s ease;
        }

        .transaction-item:hover .transaction-icon,
        .account-item:hover .account-icon {
            transform: scale(1.05);
        }

        .view-all {
            white-space: nowrap;
            transition: .2s ease;
        }

        .view-all:hover {
            text-decoration: none;
            transform: translateX(2px);
        }

        .chart-container {
            overflow-x: auto;
        }

        .chart {
            min-width: 430px;
        }

        .bar {
            cursor: default;
        }

        .progress-bar {
            transition: width .5s ease;
        }

        .dashboard-section-label {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 4px 0 14px;
            color: #667085;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .8px;
        }

        .dashboard-section-label::before {
            content: "";
            width: 22px;
            height: 2px;
            border-radius: 10px;
            background: #18aef1;
        }

        .balance-highlight {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 20px;
            padding: 22px 24px;
            background: linear-gradient(135deg, #f0f7ff 0%, #e6f4fe 100%);
            border: 1px solid #d0e7f9;
            border-radius: 14px;
        }

        .balance-amount {
            font-size: 38px;
            font-weight: 800;
            color: #0f172a !important;
            line-height: 1.1;
            letter-spacing: -0.5px;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .balance-label {
            margin-top: 8px;
            color: #64748b;
            font-size: 13px;
            font-weight: 500;
        }

        .balance-action {
            flex-shrink: 0;
        }

        @media (max-width: 700px) {
            .balance-highlight {
                display: block;
                padding: 18px 16px;
            }

            .balance-action {
                margin-top: 16px;
            }

            .balance-amount {
                font-size: 28px;
            }
        }

        /* =====================================================
           HEADER ACTIONS & THEME SWITCHER
        ===================================================== */

        .header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .theme-switcher {
            display: inline-flex;
            align-items: center;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 4px;
            gap: 3px;
            box-shadow: 0 4px 14px rgba(24, 39, 75, 0.05);
            transition: all 0.25s ease;
        }

        .theme-btn {
            border: none;
            background: transparent;
            color: #64748b;
            padding: 8px 14px;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            user-select: none;
            line-height: 1;
        }

        .theme-btn:hover {
            color: #172033;
            background: #f1f5f9;
        }

        .theme-btn.active {
            background: linear-gradient(135deg, #16b5f4, #168ce8);
            color: #ffffff !important;
            font-weight: 700;
            box-shadow: 0 3px 10px rgba(22, 140, 232, 0.35);
        }

        .theme-icon {
            font-size: 14px;
            line-height: 1;
        }

        /* =====================================================
           DARK THEME SYSTEM
        ===================================================== */

        body.dark-mode {
            background: #090e17;
            color: #f1f5f9;
        }

        body.dark-mode .page-header h1 {
            color: #ffffff;
        }

        body.dark-mode .page-header p {
            color: #94a3b8;
        }

        body.dark-mode .date-badge {
            background: #101c2d;
            border-color: #1e2d42;
            color: #94a3b8;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.25);
        }

        body.dark-mode .theme-switcher {
            background: #101c2d;
            border-color: #1e2d42;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.3);
        }

        body.dark-mode .theme-btn {
            color: #94a3b8;
        }

        body.dark-mode .theme-btn:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.06);
        }

        body.dark-mode .theme-btn.active {
            background: linear-gradient(135deg, #16b5f4, #168ce8);
            color: #ffffff !important;
        }

        body.dark-mode .stat-card {
            background: #101c2d;
            border-color: #1e2d42;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.25);
        }

        body.dark-mode .stat-title {
            color: #94a3b8;
        }

        body.dark-mode .stat-value {
            color: #ffffff;
        }

        body.dark-mode .stat-subtitle {
            color: #64748b;
        }

        body.dark-mode .income .stat-icon {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
        }

        body.dark-mode .expense .stat-icon {
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
        }

        body.dark-mode .balance .stat-icon {
            background: rgba(56, 189, 248, 0.15);
            color: #38bdf8;
        }

        body.dark-mode .savings .stat-icon {
            background: rgba(168, 85, 247, 0.15);
            color: #c084fc;
        }

        body.dark-mode .card {
            background: #101c2d;
            border-color: #1e2d42;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.25);
        }

        body.dark-mode .card-header h2 {
            color: #ffffff;
        }

        body.dark-mode .card-header p {
            color: #94a3b8;
        }

        body.dark-mode .view-all {
            color: #38bdf8;
        }

        body.dark-mode .chart {
            border-bottom-color: #1e2d42;
        }

        body.dark-mode .month-label {
            color: #94a3b8;
        }

        body.dark-mode .legend-item {
            color: #cbd5e1;
        }

        body.dark-mode .account-item {
            background: #090e17;
            border-color: #1a293f;
        }

        body.dark-mode .account-name {
            color: #f1f5f9;
        }

        body.dark-mode .account-type {
            color: #8993a4;
        }

        body.dark-mode .account-balance {
            color: #38bdf8;
        }

        body.dark-mode .account-icon {
            background: rgba(56, 189, 248, 0.12);
            color: #38bdf8;
        }

        body.dark-mode .transaction-item {
            border-bottom-color: #162436;
        }

        body.dark-mode .transaction-category {
            color: #f1f5f9;
        }

        body.dark-mode .transaction-description {
            color: #8993a4;
        }

        body.dark-mode .transaction-date {
            color: #64748b;
        }

        body.dark-mode .transaction-icon.income {
            background: rgba(16, 185, 129, 0.15);
        }

        body.dark-mode .transaction-icon.expense {
            background: rgba(239, 68, 68, 0.15);
        }

        body.dark-mode .category-row {
            color: #f1f5f9;
        }

        body.dark-mode .category-name {
            color: #f1f5f9;
        }

        body.dark-mode .category-progress-bg {
            background: #162436;
        }

        body.dark-mode .category-info span {
            color: #94a3b8;
        }

        body.dark-mode .dashboard-section-label {
            color: #64748b;
        }

        body.dark-mode .empty-state {
            color: #64748b;
        }

        body.dark-mode .balance-highlight {
            background: linear-gradient(135deg, #0b1523 0%, #112236 100%) !important;
            border: 1px solid #1e3552 !important;
        }

        body.dark-mode .balance-amount {
            color: #38bdf8 !important;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        body.dark-mode .balance-label {
            color: #94a3b8 !important;
        }

        @media (max-width: 900px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }

            .header-actions {
                width: 100%;
                justify-content: space-between;
            }
        }

        @media (max-width: 550px) {
            .theme-btn .theme-label {
                display: none;
            }
            .theme-btn {
                padding: 8px 10px;
            }
        }

    </style>

</head>

<body>

<script>
    if (document.documentElement.classList.contains('dark-mode')) {
        document.body.classList.add('dark-mode');
    }
</script>

<div class="app-layout">

    <?php include "sidebar.php"; ?>


    <!-- =========================
         MAIN CONTENT
    ========================= -->

    <main class="main-content">

        <div class="page-header">

            <div>
                <h1>Dashboard</h1>
                <p>Welcome back! Here's your financial overview.</p>
            </div>

            <div class="header-actions">

                <!-- Theme Switcher: Light / Dark / System -->
                <div class="theme-switcher" id="themeSwitcher" role="radiogroup" aria-label="Theme Selection">
                    <button type="button" class="theme-btn" data-theme="light" title="Light Theme" onclick="setAppTheme('light')">
                        <span class="theme-icon">☀️</span>
                        <span class="theme-label">Light</span>
                    </button>
                    <button type="button" class="theme-btn" data-theme="dark" title="Dark Theme" onclick="setAppTheme('dark')">
                        <span class="theme-icon">🌙</span>
                        <span class="theme-label">Dark</span>
                    </button>
                    <button type="button" class="theme-btn" data-theme="system" title="System Theme (Follows OS)" onclick="setAppTheme('system')">
                        <span class="theme-icon">💻</span>
                        <span class="theme-label">System</span>
                    </button>
                </div>

                <div class="date-badge">
                    📅 <?php echo date("d M Y"); ?>
                </div>

            </div>

        </div>


        <!-- =========================
             KPI CARDS
        ========================= -->

        <div class="stats-grid">

            <div class="stat-card income">

                <div class="stat-top">

                    <div class="stat-title">
                        Total Income
                    </div>

                    <div class="stat-icon">
                        ↗
                    </div>

                </div>

                <div class="stat-value">
                    ₹<?php echo number_format($total_income, 2); ?>
                </div>

                <div class="stat-subtitle">
                    All recorded income
                </div>

            </div>


            <div class="stat-card expense">

                <div class="stat-top">

                    <div class="stat-title">
                        Total Expenses
                    </div>

                    <div class="stat-icon">
                        ↘
                    </div>

                </div>

                <div class="stat-value">
                    ₹<?php echo number_format($total_expenses, 2); ?>
                </div>

                <div class="stat-subtitle">
                    All recorded expenses
                </div>

            </div>


            <div class="stat-card balance">

                <div class="stat-top">

                    <div class="stat-title">
                        Net Cash Flow
                    </div>

                    <div class="stat-icon">
                        💰
                    </div>

                </div>

                <div class="stat-value">
                    ₹<?php echo number_format($net_cash_flow, 2); ?>
                </div>

                <div class="stat-subtitle">
                    Income minus expenses
                </div>

            </div>


            <div class="stat-card savings">

                <div class="stat-top">

                    <div class="stat-title">
                        Savings Rate
                    </div>

                    <div class="stat-icon">
                        📈
                    </div>

                </div>

                <div class="stat-value">
                    <?php echo number_format($savings_rate, 1); ?>%
                </div>

                <div class="stat-subtitle">
                    Based on recorded income
                </div>

            </div>

        </div>


        <div class="dashboard-section-label">Financial Overview</div>

        <!-- =========================
             CHART + ACCOUNTS
        ========================= -->

        <div class="dashboard-grid">

            <div class="card">

                <div class="card-header">

                    <div>
                        <h2>Income vs Expenses</h2>
                        <p>Last 6 months</p>
                    </div>

                    <a href="transactions.php" class="view-all">
                        View Transactions →
                    </a>

                </div>


                <div class="chart-container">

                    <?php

                    $max_value = max(
                        max($monthly_income ?: [0]),
                        max($monthly_expenses ?: [0])
                    );

                    if ($max_value <= 0) {
                        $max_value = 1;
                    }

                    ?>

                    <div class="chart">

                        <?php for ($i = 0; $i < 6; $i++): ?>

                            <?php

                            $income_height =
                                ($monthly_income[$i] / $max_value) * 85;

                            $expense_height =
                                ($monthly_expenses[$i] / $max_value) * 85;

                            ?>

                            <div class="chart-column">

                                <div
                                    class="bar income-bar"
                                    style="height: <?php echo max(3, $income_height); ?>%;"
                                    title="Income: ₹<?php echo number_format($monthly_income[$i], 2); ?>"
                                ></div>

                                <div
                                    class="bar expense-bar"
                                    style="height: <?php echo max(3, $expense_height); ?>%;"
                                    title="Expenses: ₹<?php echo number_format($monthly_expenses[$i], 2); ?>"
                                ></div>

                                <div class="month-label">
                                    <?php echo htmlspecialchars($month_labels[$i]); ?>
                                </div>

                            </div>

                        <?php endfor; ?>

                    </div>

                    <div class="chart-legend">

                        <div class="legend-item">
                            <span class="legend-dot legend-income"></span>
                            Income
                        </div>

                        <div class="legend-item">
                            <span class="legend-dot legend-expense"></span>
                            Expenses
                        </div>

                    </div>

                </div>

            </div>


            <!-- ACCOUNTS -->

            <div class="card">

                <div class="card-header">

                    <div>
                        <h2>Accounts</h2>
                        <p>Total balance</p>
                    </div>

                    <a href="accounts.php" class="view-all">
                        Manage →
                    </a>

                </div>


                <div class="account-list">

                    <?php if (count($accounts) > 0): ?>

                        <?php foreach ($accounts as $account): ?>

                            <div class="account-item">

                                <div class="account-left">

                                    <div class="account-icon">
                                        <?php
                                        $account_type = strtolower($account['type']);

                                        if (strpos($account_type, 'bank') !== false) {
                                            echo "🏦";
                                        } elseif (strpos($account_type, 'cash') !== false) {
                                            echo "💵";
                                        } elseif (
                                            strpos($account_type, 'wallet') !== false ||
                                            strpos($account_type, 'upi') !== false
                                        ) {
                                            echo "📱";
                                        } elseif (strpos($account_type, 'credit') !== false) {
                                            echo "💳";
                                        } else {
                                            echo "💰";
                                        }
                                        ?>
                                    </div>

                                    <div>

                                        <div class="account-name">
                                            <?php echo htmlspecialchars($account['name']); ?>
                                        </div>

                                        <div class="account-type">
                                            <?php echo htmlspecialchars($account['type']); ?>
                                        </div>

                                    </div>

                                </div>

                                <div class="account-balance">
                                    ₹<?php echo number_format((float)$account['balance'], 2); ?>
                                </div>

                            </div>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <div class="empty-state">

                            <div class="empty-icon">🏦</div>

                            No accounts added yet.

                            <br><br>

                            <a href="accounts.php" class="view-all">
                                Add an account →
                            </a>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>


        <div class="dashboard-section-label">Activity & Spending</div>

        <!-- =========================
             RECENT TRANSACTIONS + CATEGORIES
        ========================= -->

        <div class="dashboard-grid">

            <!-- RECENT TRANSACTIONS -->

            <div class="card">

                <div class="card-header">

                    <div>
                        <h2>Recent Transactions</h2>
                        <p>Your latest financial activity</p>
                    </div>

                    <a href="transactions.php" class="view-all">
                        View All →
                    </a>

                </div>


                <div class="transaction-list">

                    <?php if (count($recent_transactions) > 0): ?>

                        <?php foreach ($recent_transactions as $transaction): ?>

                            <?php
                            $is_income = strtolower($transaction['type']) === 'income';
                            ?>

                            <div class="transaction-item">

                                <div class="transaction-left">

                                    <div class="transaction-icon <?php echo $is_income ? 'income' : 'expense'; ?>">
                                        <?php echo $is_income ? '↗' : '↘'; ?>
                                    </div>

                                    <div class="transaction-info">

                                        <div class="transaction-category">
                                            <?php echo htmlspecialchars($transaction['category']); ?>
                                        </div>

                                        <div class="transaction-description">

                                            <?php

                                            if (!empty($transaction['description'])) {
                                                echo htmlspecialchars($transaction['description']);
                                            } else {
                                                echo "No description";
                                            }

                                            ?>

                                        </div>

                                        <div class="transaction-date">

                                            <?php

                                            echo date(
                                                "d M Y",
                                                strtotime($transaction['transaction_date'])
                                            );

                                            ?>

                                        </div>

                                    </div>

                                </div>


                                <div class="transaction-amount <?php echo $is_income ? 'income-text' : 'expense-text'; ?>">

                                    <?php echo $is_income ? '+' : '-'; ?>

                                    ₹<?php echo number_format((float)$transaction['amount'], 2); ?>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <div class="empty-state">

                            <div class="empty-icon">🧾</div>

                            No transactions recorded yet.

                            <br><br>

                            <a href="transactions.php" class="view-all">
                                Add your first transaction →
                            </a>

                        </div>

                    <?php endif; ?>

                </div>

            </div>


            <!-- SPENDING BY CATEGORY -->

            <div class="card">

                <div class="card-header">

                    <div>
                        <h2>Top Spending</h2>
                        <p>Highest expense categories</p>
                    </div>

                </div>


                <?php if (count($category_names) > 0): ?>

                    <?php

                    $largest_category = max($category_amounts);

                    if ($largest_category <= 0) {
                        $largest_category = 1;
                    }

                    ?>

                    <div class="category-list">

                        <?php for ($i = 0; $i < count($category_names); $i++): ?>

                            <?php

                            $percentage =
                                ($category_amounts[$i] / $largest_category) * 100;

                            ?>

                            <div class="category-row">

                                <div class="category-info">

                                    <span class="category-name">
                                        <?php echo htmlspecialchars($category_names[$i]); ?>
                                    </span>

                                    <span class="category-value">
                                        ₹<?php echo number_format($category_amounts[$i], 2); ?>
                                    </span>

                                </div>

                                <div class="progress">

                                    <div
                                        class="progress-bar"
                                        style="width: <?php echo min(100, $percentage); ?>%;"
                                    ></div>

                                </div>

                            </div>

                        <?php endfor; ?>

                    </div>

                <?php else: ?>

                    <div class="empty-state">

                        <div class="empty-icon">📊</div>

                        No expense data available yet.

                    </div>

                <?php endif; ?>

            </div>

        </div>


        <!-- =========================
             TOTAL ACCOUNT BALANCE
        ========================= -->

        <div class="card">

            <div class="card-header">

                <div>
                    <h2>Total Account Balance</h2>
                    <p>Combined balance across all your accounts</p>
                </div>

                <a href="accounts.php" class="view-all">
                    Manage Accounts →
                </a>

            </div>

            <div class="balance-highlight">
                <div>
                    <div class="balance-amount">
                        ₹<?php echo number_format($total_balance, 2); ?>
                    </div>
                    <div class="balance-label">
                        Combined balance across all your accounts
                    </div>
                </div>

                <div class="balance-action">
                    <a href="accounts.php" class="view-all">Open Accounts →</a>
                </div>
            </div>

        </div>

    </main>

</div>

<script>
    // Delegate theme switching to universal theme manager in theme.js
    function setAppTheme(theme) {
        if (typeof window.setExpenseTheme === 'function') {
            window.setExpenseTheme(theme);
        } else {
            localStorage.setItem('expenseTrackerTheme', theme);
            if (theme === 'dark' || (theme === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.body.classList.add('dark-mode');
                document.documentElement.classList.add('dark-mode');
            } else {
                document.body.classList.remove('dark-mode');
                document.documentElement.classList.remove('dark-mode');
            }
        }
    }
</script>

</body>

</html>