<?php

session_start();
require_once "db.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION["user_id"];


/*
|--------------------------------------------------------------------------
| CURRENT MONTH
|--------------------------------------------------------------------------
*/

$currentMonth = date("Y-m");
$currentMonthName = date("F Y");

$firstDay = date("Y-m-01");
$lastDay = date("Y-m-t");

$previousMonth = date(
    "Y-m",
    strtotime("-1 month")
);

$previousMonthName = date(
    "F Y",
    strtotime("-1 month")
);

$previousFirstDay = date(
    "Y-m-01",
    strtotime("-1 month")
);

$previousLastDay = date(
    "Y-m-t",
    strtotime("-1 month")
);


/*
|--------------------------------------------------------------------------
| CURRENT MONTH TOTAL EXPENSE
|--------------------------------------------------------------------------
*/

$currentExpense = 0;

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM transactions
    WHERE user_id = ?
      AND type = 'expense'
      AND transaction_date BETWEEN ? AND ?
");

if ($stmt) {

    $stmt->bind_param(
        "iss",
        $user_id,
        $firstDay,
        $lastDay
    );

    $stmt->execute();

    $stmt->bind_result(
        $currentExpense
    );

    $stmt->fetch();

    $stmt->close();
}


/*
|--------------------------------------------------------------------------
| CURRENT MONTH INCOME
|--------------------------------------------------------------------------
*/

$currentIncome = 0;

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM transactions
    WHERE user_id = ?
      AND type = 'income'
      AND transaction_date BETWEEN ? AND ?
");

if ($stmt) {

    $stmt->bind_param(
        "iss",
        $user_id,
        $firstDay,
        $lastDay
    );

    $stmt->execute();

    $stmt->bind_result(
        $currentIncome
    );

    $stmt->fetch();

    $stmt->close();
}


/*
|--------------------------------------------------------------------------
| PREVIOUS MONTH EXPENSE
|--------------------------------------------------------------------------
*/

$previousExpense = 0;

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM transactions
    WHERE user_id = ?
      AND type = 'expense'
      AND transaction_date BETWEEN ? AND ?
");

if ($stmt) {

    $stmt->bind_param(
        "iss",
        $user_id,
        $previousFirstDay,
        $previousLastDay
    );

    $stmt->execute();

    $stmt->bind_result(
        $previousExpense
    );

    $stmt->fetch();

    $stmt->close();
}


/*
|--------------------------------------------------------------------------
| CALCULATIONS
|--------------------------------------------------------------------------
*/

$netCashFlow =
    $currentIncome - $currentExpense;


if ($currentIncome > 0) {

    $savingsRate =
        ($netCashFlow / $currentIncome) * 100;

} else {

    $savingsRate = 0;
}


/*
|--------------------------------------------------------------------------
| MONTH-OVER-MONTH EXPENSE CHANGE
|--------------------------------------------------------------------------
*/

if ($previousExpense > 0) {

    $expenseChange =
        (
            ($currentExpense - $previousExpense)
            / $previousExpense
        ) * 100;

} else {

    $expenseChange =
        ($currentExpense > 0)
        ? 100
        : 0;
}


/*
|--------------------------------------------------------------------------
| CATEGORY-WISE SPENDING
|--------------------------------------------------------------------------
*/

$categoryData = [];

$stmt = $conn->prepare("
    SELECT
        category,
        SUM(amount) AS total
    FROM transactions
    WHERE user_id = ?
      AND type = 'expense'
      AND transaction_date BETWEEN ? AND ?
    GROUP BY category
    ORDER BY total DESC
");

if ($stmt) {

    $stmt->bind_param(
        "iss",
        $user_id,
        $firstDay,
        $lastDay
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $categoryData[] = $row;
    }

    $stmt->close();
}


/*
|--------------------------------------------------------------------------
| HIGHEST SPENDING CATEGORY
|--------------------------------------------------------------------------
*/

$highestCategory =
    "No spending yet";

$highestCategoryAmount = 0;


if (!empty($categoryData)) {

    $highestCategory =
        $categoryData[0]["category"];

    $highestCategoryAmount =
        (float)$categoryData[0]["total"];
}


/*
|--------------------------------------------------------------------------
| TOP 5 TRANSACTIONS
|--------------------------------------------------------------------------
*/

$topTransactions = [];

$stmt = $conn->prepare("
    SELECT
        category,
        description,
        amount,
        transaction_date
    FROM transactions
    WHERE user_id = ?
      AND type = 'expense'
      AND transaction_date BETWEEN ? AND ?
    ORDER BY amount DESC
    LIMIT 5
");

if ($stmt) {

    $stmt->bind_param(
        "iss",
        $user_id,
        $firstDay,
        $lastDay
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $topTransactions[] = $row;
    }

    $stmt->close();
}


/*
|--------------------------------------------------------------------------
| MONTHLY TREND - LAST 6 MONTHS
|--------------------------------------------------------------------------
*/

$monthlyTrend = [];

for ($i = 5; $i >= 0; $i--) {

    $monthDate =
        strtotime("-$i months");

    $monthStart =
        date(
            "Y-m-01",
            $monthDate
        );

    $monthEnd =
        date(
            "Y-m-t",
            $monthDate
        );

    $label =
        date(
            "M",
            $monthDate
        );

    $expense = 0;

    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM transactions
        WHERE user_id = ?
          AND type = 'expense'
          AND transaction_date BETWEEN ? AND ?
    ");

    if ($stmt) {

        $stmt->bind_param(
            "iss",
            $user_id,
            $monthStart,
            $monthEnd
        );

        $stmt->execute();

        $stmt->bind_result(
            $expense
        );

        $stmt->fetch();

        $stmt->close();
    }

    $monthlyTrend[] = [
        "label" => $label,
        "amount" => (float)$expense
    ];
}


/*
|--------------------------------------------------------------------------
| INSIGHT MESSAGE
|--------------------------------------------------------------------------
*/

if ($currentExpense == 0) {

    $insightTitle =
        "No spending recorded";

    $insightText =
        "Add some expense transactions to start receiving spending insights.";

} elseif ($savingsRate >= 30) {

    $insightTitle =
        "Excellent savings";

    $insightText =
        "You're currently saving a healthy portion of your income this month. Keep maintaining this discipline.";

} elseif ($savingsRate >= 15) {

    $insightTitle =
        "Good financial progress";

    $insightText =
        "Your savings rate is positive. Look for opportunities to reduce unnecessary spending and improve it further.";

} elseif ($savingsRate >= 0) {

    $insightTitle =
        "Watch your spending";

    $insightText =
        "Your expenses are taking a significant share of your income. Review your top spending categories.";

} else {

    $insightTitle =
        "Spending is above income";

    $insightText =
        "Your expenses currently exceed your income for this month. Consider reviewing non-essential expenses.";
}


/*
|--------------------------------------------------------------------------
| MONEY HELPER
|--------------------------------------------------------------------------
*/

function money($amount)
{
    return "₹" . number_format(
        (float)$amount,
        2
    );
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Spending Insights - Personal Expense Tracker
    </title>


<style>

/* =========================================================
   RESET
========================================================= */

* {

    margin: 0;

    padding: 0;

    box-sizing: border-box;
}


/* =========================================================
   BODY
========================================================= */

body {

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    background: #f4f7fb;

    color: #172033;
}


/* =========================================================
   LAYOUT
========================================================= */

.app-layout {

    display: flex;

    min-height: 100vh;
}


/* =========================================================
   MAIN CONTENT
========================================================= */

.main-content {

    margin-left: 250px;

    width:
        calc(100% - 250px);

    min-height: 100vh;

    padding: 40px;
}


.page-container {

    max-width: 1400px;

    margin: 0 auto;
}


/* =========================================================
   PAGE HEADER
========================================================= */

.page-header {

    display: flex;

    justify-content:
        space-between;

    align-items:
        center;

    gap: 20px;

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


.month-badge {

    background: #ffffff;

    border:
        1px solid #e6ebf2;

    padding:
        12px 18px;

    border-radius: 12px;

    color: #667085;

    font-weight: 600;

    white-space: nowrap;
}


/* =========================================================
   KPI GRID
========================================================= */

.kpi-grid {

    display: grid;

    grid-template-columns:
        repeat(4, 1fr);

    gap: 20px;

    margin-bottom: 24px;
}


.kpi-card {

    background: #ffffff;

    border:
        1px solid #e6ebf2;

    border-radius: 18px;

    padding: 24px;

    box-shadow:
        0 8px 25px
        rgba(24,39,75,.06);

    transition:
        transform .2s ease,
        box-shadow .2s ease;
}


.kpi-card:hover {

    transform:
        translateY(-2px);

    box-shadow:
        0 12px 30px
        rgba(24,39,75,.09);
}


.kpi-top {

    display: flex;

    justify-content:
        space-between;

    align-items:
        center;

    margin-bottom: 15px;
}


.kpi-title {

    color: #718096;

    font-size: 12px;

    font-weight: 700;

    text-transform:
        uppercase;

    letter-spacing: .5px;
}


.kpi-icon {

    width: 40px;

    height: 40px;

    border-radius: 11px;

    display: flex;

    justify-content:
        center;

    align-items:
        center;

    background: #edf8ff;

    font-size: 19px;
}


.kpi-value {

    font-size: 28px;

    font-weight: 800;

    color: #172033;

    margin-bottom: 8px;
}


.kpi-sub {

    font-size: 12px;

    color: #8994a5;

    line-height: 1.5;
}


.green {

    color: #159447;
}


.red {

    color: #e04444;
}


.blue {

    color: #168ce8;
}


/* =========================================================
   CONTENT GRID
========================================================= */

.content-grid {

    display: grid;

    grid-template-columns:
        1.45fr 1fr;

    gap: 24px;

    margin-bottom: 24px;
}


/* =========================================================
   CARD
========================================================= */

.card {

    background: #ffffff;

    border:
        1px solid #e6ebf2;

    border-radius: 18px;

    padding: 26px;

    box-shadow:
        0 8px 25px
        rgba(24,39,75,.06);
}


.card-header {

    display: flex;

    justify-content:
        space-between;

    align-items:
        flex-start;

    margin-bottom: 25px;
}


.card-title {

    font-size: 20px;

    font-weight: 800;

    color: #172033;
}


.card-description {

    color: #8792a3;

    font-size: 13px;

    margin-top: 4px;
}


/* =========================================================
   CATEGORY BARS
========================================================= */

.category-item {

    margin-bottom: 20px;
}


.category-item:last-child {

    margin-bottom: 0;
}


.category-header {

    display: flex;

    justify-content:
        space-between;

    margin-bottom: 8px;
}


.category-name {

    font-size: 14px;

    font-weight: 700;

    color: #344054;
}


.category-amount {

    font-size: 14px;

    font-weight: 800;

    color: #172033;
}


.progress-track {

    width: 100%;

    height: 9px;

    background: #edf1f6;

    border-radius: 20px;

    overflow: hidden;
}


.progress-fill {

    height: 100%;

    background:
        linear-gradient(
            90deg,
            #19b5f1,
            #168ce8
        );

    border-radius: 20px;

    transition:
        width .3s ease;
}


/* =========================================================
   MONTHLY CHART
========================================================= */

.chart {

    display: flex;

    align-items:
        flex-end;

    justify-content:
        space-between;

    height: 250px;

    padding:
        20px 5px 0;

    gap: 14px;
}


.chart-column {

    flex: 1;

    height: 100%;

    display: flex;

    flex-direction: column;

    justify-content:
        flex-end;

    align-items:
        center;

    min-width: 0;
}


.bar-wrapper {

    width: 100%;

    max-width: 42px;

    height: 190px;

    display: flex;

    align-items:
        flex-end;
}


.bar {

    width: 100%;

    background:
        linear-gradient(
            180deg,
            #20b9f5,
            #168ce8
        );

    border-radius:
        8px 8px 3px 3px;

    min-height: 4px;

    transition:
        .3s ease;
}


.bar:hover {

    opacity: .8;
}


.chart-label {

    font-size: 12px;

    color: #7a8596;

    margin-top: 9px;

    font-weight: 700;
}


.chart-value {

    font-size: 10px;

    color: #8b95a4;

    margin-bottom: 6px;

    text-align: center;

    white-space: nowrap;
}


/* =========================================================
   INSIGHT CARD
========================================================= */

.insight-card {

    background:
        linear-gradient(
            135deg,
            #102238,
            #173a5d
        );

    color: #ffffff;

    border: none;
}


.insight-card .card-title {

    color: #ffffff;
}


.insight-icon {

    font-size: 32px;

    margin-bottom: 16px;
}


.insight-text {

    color: #c8d7e8;

    line-height: 1.7;

    font-size: 14px;
}


.insight-bottom {

    margin-top: 22px;

    padding-top: 18px;

    border-top:
        1px solid
        rgba(255,255,255,.12);

    font-size: 13px;

    color: #a9bfd6;

    line-height: 1.6;
}


/* =========================================================
   TOP TRANSACTIONS
========================================================= */

.transaction-list {

    display: flex;

    flex-direction: column;

    gap: 10px;
}


.transaction-row {

    display: flex;

    justify-content:
        space-between;

    align-items:
        center;

    padding: 14px;

    border:
        1px solid #edf0f4;

    border-radius: 12px;

    transition:
        .2s ease;
}


.transaction-row:hover {

    background: #f8fafc;

    transform:
        translateX(2px);
}


.transaction-left {

    display: flex;

    align-items:
        center;

    gap: 12px;

    min-width: 0;
}


.transaction-icon {

    width: 40px;

    height: 40px;

    min-width: 40px;

    border-radius: 10px;

    background: #fff1f1;

    display: flex;

    align-items:
        center;

    justify-content:
        center;
}


.transaction-info {

    min-width: 0;
}


.transaction-category {

    font-size: 14px;

    font-weight: 700;

    color: #344054;

    white-space: nowrap;

    overflow: hidden;

    text-overflow: ellipsis;
}


.transaction-description {

    font-size: 12px;

    color: #8994a5;

    margin-top: 2px;

    white-space: nowrap;

    overflow: hidden;

    text-overflow: ellipsis;

    max-width: 240px;
}


.transaction-right {

    text-align: right;

    margin-left: 10px;
}


.transaction-amount {

    font-size: 14px;

    font-weight: 800;

    color: #e04444;

    white-space: nowrap;
}


.transaction-date {

    font-size: 11px;

    color: #9aa3b1;

    margin-top: 3px;
}


/* =========================================================
   EMPTY STATE
========================================================= */

.empty {

    text-align: center;

    padding:
        40px 15px;

    color: #8994a5;
}


.empty-icon {

    font-size: 35px;

    margin-bottom: 10px;
}


.empty p {

    font-size: 14px;
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1200px) {

    .kpi-grid {

        grid-template-columns:
            repeat(2, 1fr);
    }

    .content-grid {

        grid-template-columns: 1fr;
    }
}


@media (max-width: 850px) {

    .main-content {

        margin-left: 210px;

        width:
            calc(100% - 210px);

        padding: 25px;
    }

    .page-header h1 {

        font-size: 30px;
    }
}


@media (max-width: 650px) {

    .main-content {

        margin-left: 0;

        width: 100%;

        padding: 20px;
    }

    .app-layout {

        display: block;
    }

    .kpi-grid {

        grid-template-columns: 1fr;
    }

    .page-header {

        display: block;
    }

    .month-badge {

        display: inline-block;

        margin-top: 15px;
    }

    .card {

        padding: 20px;
    }

    .chart {

        gap: 7px;
    }

    .chart-value {

        font-size: 9px;
    }
}


@media (max-width: 450px) {

    .main-content {

        padding: 15px;
    }

    .page-header h1 {

        font-size: 27px;
    }

    .kpi-value {

        font-size: 24px;
    }

    .transaction-row {

        align-items:
            flex-start;
    }

    .transaction-description {

        max-width: 150px;
    }

}

</style>

</head>


<body>


<div class="app-layout">


    <!-- =====================================================
         SAME DASHBOARD SIDEBAR
    ====================================================== -->

    <?php include "sidebar.php"; ?>


    <!-- =====================================================
         MAIN CONTENT
    ====================================================== -->

    <main class="main-content">


        <div class="page-container">


            <!-- =================================================
                 PAGE HEADER
            ================================================== -->

            <div class="page-header">

                <div>

                    <h1>
                        Spending Insights
                    </h1>

                    <p>
                        Understand where your money is going and improve your financial habits.
                    </p>

                </div>


                <div class="month-badge">

                    📅
                    <?php
                    echo htmlspecialchars(
                        $currentMonthName
                    );
                    ?>

                </div>

            </div>


            <!-- =================================================
                 KPI CARDS
            ================================================== -->

            <div class="kpi-grid">


                <!-- MONTHLY SPENDING -->

                <div class="kpi-card">

                    <div class="kpi-top">

                        <div class="kpi-title">
                            Monthly Spending
                        </div>

                        <div class="kpi-icon">
                            💸
                        </div>

                    </div>


                    <div class="kpi-value">

                        <?php
                        echo money(
                            $currentExpense
                        );
                        ?>

                    </div>


                    <div class="kpi-sub">

                        <?php if ($expenseChange > 0): ?>

                            <span class="red">

                                ↑
                                <?php
                                echo number_format(
                                    abs($expenseChange),
                                    1
                                );
                                ?>%

                            </span>

                            vs
                            <?php
                            echo htmlspecialchars(
                                $previousMonthName
                            );
                            ?>

                        <?php elseif ($expenseChange < 0): ?>

                            <span class="green">

                                ↓
                                <?php
                                echo number_format(
                                    abs($expenseChange),
                                    1
                                );
                                ?>%

                            </span>

                            vs
                            <?php
                            echo htmlspecialchars(
                                $previousMonthName
                            );
                            ?>

                        <?php else: ?>

                            No change vs previous month

                        <?php endif; ?>

                    </div>

                </div>


                <!-- MONTHLY INCOME -->

                <div class="kpi-card">

                    <div class="kpi-top">

                        <div class="kpi-title">
                            Monthly Income
                        </div>

                        <div class="kpi-icon">
                            💰
                        </div>

                    </div>


                    <div class="kpi-value green">

                        <?php
                        echo money(
                            $currentIncome
                        );
                        ?>

                    </div>


                    <div class="kpi-sub">

                        Total income recorded this month

                    </div>

                </div>


                <!-- NET CASH FLOW -->

                <div class="kpi-card">

                    <div class="kpi-top">

                        <div class="kpi-title">
                            Net Cash Flow
                        </div>

                        <div class="kpi-icon">
                            📈
                        </div>

                    </div>


                    <div
                        class="
                            kpi-value
                            <?php
                            echo $netCashFlow >= 0
                                ? "green"
                                : "red";
                            ?>
                        "
                    >

                        <?php
                        echo money(
                            $netCashFlow
                        );
                        ?>

                    </div>


                    <div class="kpi-sub">

                        Income minus expenses

                    </div>

                </div>


                <!-- SAVINGS RATE -->

                <div class="kpi-card">

                    <div class="kpi-top">

                        <div class="kpi-title">
                            Savings Rate
                        </div>

                        <div class="kpi-icon">
                            🎯
                        </div>

                    </div>


                    <div class="kpi-value blue">

                        <?php
                        echo number_format(
                            $savingsRate,
                            1
                        );
                        ?>%

                    </div>


                    <div class="kpi-sub">

                        Based on this month's income

                    </div>

                </div>


            </div>


            <!-- =================================================
                 CATEGORY + MONTHLY TREND
            ================================================== -->

            <div class="content-grid">


                <!-- CATEGORY SPENDING -->

                <div class="card">


                    <div class="card-header">

                        <div>

                            <div class="card-title">
                                Spending by Category
                            </div>

                            <div class="card-description">
                                See which categories consume most of your money.
                            </div>

                        </div>

                    </div>


                    <?php if (empty($categoryData)): ?>


                        <div class="empty">

                            <div class="empty-icon">
                                📊
                            </div>

                            <p>
                                No expense data available for this month.
                            </p>

                        </div>


                    <?php else: ?>


                        <?php foreach (
                            $categoryData
                            as $category
                        ): ?>


                            <?php

                            $percentage =
                                $currentExpense > 0
                                ? (
                                    $category["total"]
                                    / $currentExpense
                                ) * 100
                                : 0;

                            ?>


                            <div class="category-item">


                                <div class="category-header">

                                    <div class="category-name">

                                        <?php
                                        echo htmlspecialchars(
                                            $category["category"]
                                        );
                                        ?>

                                    </div>


                                    <div class="category-amount">

                                        <?php
                                        echo money(
                                            $category["total"]
                                        );
                                        ?>

                                    </div>

                                </div>


                                <div class="progress-track">

                                    <div
                                        class="progress-fill"
                                        style="
                                            width:
                                            <?php
                                            echo min(
                                                100,
                                                $percentage
                                            );
                                            ?>%;
                                        "
                                    ></div>

                                </div>


                            </div>


                        <?php endforeach; ?>


                    <?php endif; ?>


                </div>


                <!-- SIX MONTH TREND -->

                <div class="card">


                    <div class="card-header">

                        <div>

                            <div class="card-title">
                                6-Month Spending Trend
                            </div>

                            <div class="card-description">
                                Monthly expense movement.
                            </div>

                        </div>

                    </div>


                    <?php

                    $maxTrend = 0;


                    foreach (
                        $monthlyTrend
                        as $month
                    ) {

                        if (
                            $month["amount"]
                            > $maxTrend
                        ) {

                            $maxTrend =
                                $month["amount"];
                        }
                    }

                    ?>


                    <div class="chart">


                        <?php foreach (
                            $monthlyTrend
                            as $month
                        ): ?>


                            <?php

                            if ($maxTrend > 0) {

                                $height =
                                    (
                                        $month["amount"]
                                        / $maxTrend
                                    ) * 100;

                            } else {

                                $height = 3;
                            }

                            ?>


                            <div class="chart-column">


                                <div class="chart-value">

                                    <?php

                                    echo
                                        $month["amount"] > 0
                                        ? money(
                                            $month["amount"]
                                        )
                                        : "₹0";

                                    ?>

                                </div>


                                <div class="bar-wrapper">

                                    <div
                                        class="bar"
                                        style="
                                            height:
                                            <?php
                                            echo max(
                                                3,
                                                $height
                                            );
                                            ?>%;
                                        "
                                        title="<?php
                                        echo money(
                                            $month["amount"]
                                        );
                                        ?>"
                                    ></div>

                                </div>


                                <div class="chart-label">

                                    <?php
                                    echo htmlspecialchars(
                                        $month["label"]
                                    );
                                    ?>

                                </div>


                            </div>


                        <?php endforeach; ?>


                    </div>


                </div>


            </div>


            <!-- =================================================
                 INSIGHT + TOP EXPENSES
            ================================================== -->

            <div class="content-grid">


                <!-- SMART INSIGHT -->

                <div class="card insight-card">


                    <div class="insight-icon">
                        💡
                    </div>


                    <div class="card-title">

                        <?php
                        echo htmlspecialchars(
                            $insightTitle
                        );
                        ?>

                    </div>


                    <p
                        class="insight-text"
                        style="margin-top:12px;"
                    >

                        <?php
                        echo htmlspecialchars(
                            $insightText
                        );
                        ?>

                    </p>


                    <div class="insight-bottom">

                        <strong>
                            Highest spending category:
                        </strong>

                        <?php
                        echo htmlspecialchars(
                            $highestCategory
                        );
                        ?>


                        <?php if (
                            $highestCategoryAmount > 0
                        ): ?>

                            —
                            <?php
                            echo money(
                                $highestCategoryAmount
                            );
                            ?>

                        <?php endif; ?>

                    </div>


                </div>


                <!-- TOP EXPENSES -->

                <div class="card">


                    <div class="card-header">

                        <div>

                            <div class="card-title">
                                Top Expenses
                            </div>

                            <div class="card-description">
                                Your largest expenses this month.
                            </div>

                        </div>

                    </div>


                    <?php if (
                        empty($topTransactions)
                    ): ?>


                        <div class="empty">

                            <div class="empty-icon">
                                💳
                            </div>

                            <p>
                                No expenses recorded this month.
                            </p>

                        </div>


                    <?php else: ?>


                        <div class="transaction-list">


                            <?php foreach (
                                $topTransactions
                                as $transaction
                            ): ?>


                                <div class="transaction-row">


                                    <div class="transaction-left">


                                        <div class="transaction-icon">
                                            💸
                                        </div>


                                        <div class="transaction-info">


                                            <div class="transaction-category">

                                                <?php
                                                echo htmlspecialchars(
                                                    $transaction["category"]
                                                );
                                                ?>

                                            </div>


                                            <div class="transaction-description">

                                                <?php

                                                echo
                                                    !empty(
                                                        $transaction[
                                                            "description"
                                                        ]
                                                    )
                                                    ? htmlspecialchars(
                                                        $transaction[
                                                            "description"
                                                        ]
                                                    )
                                                    : "Expense";

                                                ?>

                                            </div>


                                        </div>


                                    </div>


                                    <div class="transaction-right">


                                        <div class="transaction-amount">

                                            -
                                            <?php
                                            echo money(
                                                $transaction["amount"]
                                            );
                                            ?>

                                        </div>


                                        <div class="transaction-date">

                                            <?php

                                            echo date(
                                                "d M",
                                                strtotime(
                                                    $transaction[
                                                        "transaction_date"
                                                    ]
                                                )
                                            );

                                            ?>

                                        </div>


                                    </div>


                                </div>


                            <?php endforeach; ?>


                        </div>


                    <?php endif; ?>


                </div>


            </div>


        </div>


    </main>


</div>


</body>

</html>