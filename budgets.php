<?php

session_start();
require_once "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Create default categories if the user has none
|--------------------------------------------------------------------------
*/

$category_check = $conn->prepare(
    "SELECT COUNT(*) AS total FROM categories WHERE user_id = ?"
);

$category_check->bind_param("i", $user_id);
$category_check->execute();

$category_result = $category_check->get_result();
$category_count = $category_result->fetch_assoc()['total'];

$category_check->close();

if ($category_count == 0) {

    $default_categories = [
        "Food",
        "Groceries",
        "Transport",
        "Shopping",
        "Bills",
        "Rent",
        "Entertainment",
        "Health",
        "Education",
        "Travel",
        "Other"
    ];

    $insert_category = $conn->prepare(
        "INSERT INTO categories (user_id, name) VALUES (?, ?)"
    );

    foreach ($default_categories as $category_name) {
        $insert_category->bind_param("is", $user_id, $category_name);
        $insert_category->execute();
    }

    $insert_category->close();
}


/*
|--------------------------------------------------------------------------
| Create Budget
|--------------------------------------------------------------------------
*/

$message = "";
$message_type = "";

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST['create_budget'])
) {

    $category_id = intval($_POST['category_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $period = $_POST['period'] ?? 'monthly';
    $start_date = $_POST['start_date'] ?? '';
    $end_date = !empty($_POST['end_date'])
        ? $_POST['end_date']
        : null;

    $rollover_enabled = isset($_POST['rollover_enabled'])
        ? 1
        : 0;


    if (
        $category_id <= 0
        || $amount <= 0
        || empty($start_date)
    ) {

        $message = "Please fill in all required fields.";
        $message_type = "error";

    } else {

        $stmt = $conn->prepare(
            "INSERT INTO budgets
            (
                user_id,
                category_id,
                amount,
                period,
                start_date,
                end_date,
                rollover_enabled
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)"
        );

        if ($stmt) {

            $stmt->bind_param(
                "iidsssi",
                $user_id,
                $category_id,
                $amount,
                $period,
                $start_date,
                $end_date,
                $rollover_enabled
            );

            if ($stmt->execute()) {

                $message = "Budget created successfully.";
                $message_type = "success";

            } else {

                $message = "Unable to create budget.";
                $message_type = "error";
            }

            $stmt->close();

        } else {

            $message = "Unable to prepare budget request.";
            $message_type = "error";
        }
    }
}


/*
|--------------------------------------------------------------------------
| Delete Budget
|--------------------------------------------------------------------------
*/

if (isset($_GET['delete'])) {

    $budget_id = intval($_GET['delete']);

    if ($budget_id > 0) {

        $delete_stmt = $conn->prepare(
            "DELETE FROM budgets
             WHERE id = ?
             AND user_id = ?"
        );

        if ($delete_stmt) {

            $delete_stmt->bind_param(
                "ii",
                $budget_id,
                $user_id
            );

            $delete_stmt->execute();
            $delete_stmt->close();
        }
    }

    header("Location: budgets.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Get Categories
|--------------------------------------------------------------------------
*/

$categories = [];

$category_stmt = $conn->prepare(
    "SELECT
        id,
        name
     FROM categories
     WHERE user_id = ?
     ORDER BY name ASC"
);

$category_stmt->bind_param(
    "i",
    $user_id
);

$category_stmt->execute();

$category_result = $category_stmt->get_result();

while ($row = $category_result->fetch_assoc()) {

    $categories[] = $row;
}

$category_stmt->close();


/*
|--------------------------------------------------------------------------
| Current Month
|--------------------------------------------------------------------------
*/

$month_start = date("Y-m-01");
$month_end = date("Y-m-t");


/*
|--------------------------------------------------------------------------
| Budget Summary
|--------------------------------------------------------------------------
*/

$total_budget = 0;
$total_spent = 0;

$summary_stmt = $conn->prepare(
    "SELECT
        COALESCE(SUM(amount), 0) AS total_budget
     FROM budgets
     WHERE user_id = ?
     AND start_date <= ?
     AND (
         end_date IS NULL
         OR end_date >= ?
     )"
);

$summary_stmt->bind_param(
    "iss",
    $user_id,
    $month_end,
    $month_start
);

$summary_stmt->execute();

$summary_result = $summary_stmt->get_result();

if ($summary_row = $summary_result->fetch_assoc()) {

    $total_budget = (float)$summary_row['total_budget'];
}

$summary_stmt->close();


/*
|--------------------------------------------------------------------------
| Total Expenses This Month
|--------------------------------------------------------------------------
*/

$expense_stmt = $conn->prepare(
    "SELECT
        COALESCE(SUM(amount), 0) AS total_spent
     FROM transactions
     WHERE user_id = ?
     AND type = 'expense'
     AND transaction_date BETWEEN ? AND ?"
);

$expense_stmt->bind_param(
    "iss",
    $user_id,
    $month_start,
    $month_end
);

$expense_stmt->execute();

$expense_result = $expense_stmt->get_result();

if ($expense_row = $expense_result->fetch_assoc()) {

    $total_spent = (float)$expense_row['total_spent'];
}

$expense_stmt->close();


/*
|--------------------------------------------------------------------------
| Summary Calculations
|--------------------------------------------------------------------------
*/

$remaining = $total_budget - $total_spent;

if ($total_budget > 0) {

    $progress = (
        $total_spent
        / $total_budget
    ) * 100;

} else {

    $progress = 0;
}

if ($progress > 100) {
    $progress = 100;
}


/*
|--------------------------------------------------------------------------
| Get Budgets
|--------------------------------------------------------------------------
*/

$budgets = [];

$budget_stmt = $conn->prepare(
    "SELECT
        b.id,
        b.amount,
        b.period,
        b.start_date,
        b.end_date,
        b.rollover_enabled,
        c.name AS category_name

     FROM budgets b

     LEFT JOIN categories c
        ON b.category_id = c.id

     WHERE b.user_id = ?

     ORDER BY
        b.start_date DESC,
        b.id DESC"
);

$budget_stmt->bind_param(
    "i",
    $user_id
);

$budget_stmt->execute();

$budget_result = $budget_stmt->get_result();


while ($row = $budget_result->fetch_assoc()) {

    $budget_amount = (float)$row['amount'];

    $category_name = $row['category_name'];

    $spent = 0;


    /*
    |--------------------------------------------------------------------------
    | Calculate spending for this budget
    |--------------------------------------------------------------------------
    */

    $spent_stmt = $conn->prepare(
        "SELECT
            COALESCE(SUM(amount), 0) AS spent

         FROM transactions

         WHERE user_id = ?
         AND type = 'expense'
         AND category = ?
         AND transaction_date >= ?

         AND (
             ? IS NULL
             OR transaction_date <= ?
         )"
    );

    $spent_stmt->bind_param(
        "issss",
        $user_id,
        $category_name,
        $row['start_date'],
        $row['end_date'],
        $row['end_date']
    );

    $spent_stmt->execute();

    $spent_result = $spent_stmt->get_result();

    if ($spent_row = $spent_result->fetch_assoc()) {

        $spent = (float)$spent_row['spent'];
    }

    $spent_stmt->close();


    /*
    |--------------------------------------------------------------------------
    | Budget Calculations
    |--------------------------------------------------------------------------
    */

    $budget_remaining =
        $budget_amount - $spent;


    if ($budget_amount > 0) {

        $budget_progress =
            ($spent / $budget_amount) * 100;

    } else {

        $budget_progress = 0;
    }


    if ($budget_progress > 100) {
        $budget_progress = 100;
    }


    $budgets[] = [

        'id' =>
            $row['id'],

        'amount' =>
            $budget_amount,

        'period' =>
            $row['period'],

        'start_date' =>
            $row['start_date'],

        'end_date' =>
            $row['end_date'],

        'rollover_enabled' =>
            $row['rollover_enabled'],

        'category_name' =>
            $category_name,

        'spent' =>
            $spent,

        'remaining' =>
            $budget_remaining,

        'progress' =>
            $budget_progress
    ];
}

$budget_stmt->close();

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
        Budgets - Personal Expense Tracker
    </title>

    <script>
        (function() {
            var theme = localStorage.getItem('expenseTrackerTheme') || 'system';
            var isDark = theme === 'dark' || (theme === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
            if (isDark) {
                document.documentElement.classList.add('dark-mode');
            }
        })();
    </script>
    <link rel="stylesheet" href="dark_theme.css">
    <link rel="stylesheet" href="responsive_mobile.css">

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


/* =========================================================
   PAGE HEADER
========================================================= */

.page-header {

    display: flex;

    justify-content:
        space-between;

    align-items:
        center;

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


/* =========================================================
   DATE BADGE
========================================================= */

.date-badge {

    background: #ffffff;

    border:
        1px solid #e6ebf2;

    padding:
        12px 18px;

    border-radius: 10px;

    color: #667085;

    font-size: 14px;

    font-weight: 600;

    box-shadow:
        0 5px 18px
        rgba(24,39,75,.05);
}


/* =========================================================
   ALERT
========================================================= */

.alert {

    padding:
        14px 18px;

    border-radius: 10px;

    margin-bottom: 22px;

    font-size: 14px;

    font-weight: 600;
}


.alert.success {

    background: #eaf8ef;

    border:
        1px solid #bde5ca;

    color: #19703a;
}


.alert.error {

    background: #fff0f0;

    border:
        1px solid #f2c2c2;

    color: #b42318;
}


/* =========================================================
   SUMMARY
========================================================= */

.summary-grid {

    display: grid;

    grid-template-columns:
        repeat(3, 1fr);

    gap: 20px;

    margin-bottom: 25px;
}


.summary-card {

    background: #ffffff;

    border:
        1px solid #e6ebf2;

    border-radius: 18px;

    padding: 24px;

    box-shadow:
        0 8px 25px
        rgba(24,39,75,.06);

    position: relative;

    overflow: hidden;
}


.summary-card::after {

    content: "";

    position: absolute;

    width: 70px;

    height: 70px;

    border-radius: 50%;

    right: -25px;

    top: -25px;

    background:
        rgba(32,185,245,.08);
}


.summary-title {

    color: #718096;

    font-size: 13px;

    font-weight: 700;

    text-transform:
        uppercase;

    letter-spacing: .5px;

    margin-bottom: 15px;
}


.summary-value {

    font-size: 27px;

    font-weight: 800;

    color: #172033;
}


.summary-value.blue {
    color: #168ce8;
}


.summary-value.red {
    color: #e04444;
}


.summary-value.green {
    color: #159447;
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

    margin-bottom: 24px;

    box-shadow:
        0 8px 25px
        rgba(24,39,75,.06);
}


.card-header {

    display: flex;

    justify-content:
        space-between;

    align-items:
        center;

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


/* =========================================================
   FORM
========================================================= */

.form-grid {

    display: grid;

    grid-template-columns:
        repeat(4, 1fr);

    gap: 18px;
}


.form-group label {

    display: block;

    margin-bottom: 8px;

    color: #344054;

    font-size: 13px;

    font-weight: 700;
}


.form-group input,
.form-group select {

    width: 100%;

    height: 46px;

    border:
        1px solid #d7dee8;

    background: #ffffff;

    color: #172033;

    border-radius: 10px;

    padding:
        10px 13px;

    font-size: 14px;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    outline: none;

    transition: .2s;
}


.form-group input:focus,
.form-group select:focus {

    border-color: #18aef1;

    box-shadow:
        0 0 0 3px
        rgba(24,174,241,.12);
}


/* =========================================================
   CHECKBOX
========================================================= */

.checkbox-group {

    display: flex;

    align-items: center;

    gap: 8px;

    padding-top: 31px;
}


.checkbox-group input {

    width: 17px;

    height: 17px;

    accent-color: #168ce8;
}


.checkbox-group label {

    color: #344054;

    font-size: 14px;

    font-weight: 600;
}


/* =========================================================
   PRIMARY BUTTON
========================================================= */

.btn-primary {

    height: 46px;

    border: none;

    border-radius: 10px;

    background:
        linear-gradient(
            135deg,
            #16b5f4,
            #168ce8
        );

    color: #ffffff;

    padding:
        0 22px;

    font-size: 14px;

    font-weight: 700;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    cursor: pointer;

    margin-top: 29px;

    transition: .2s;
}


.btn-primary:hover {

    opacity: .92;

    transform:
        translateY(-1px);
}


/* =========================================================
   BUDGET GRID
========================================================= */

.budget-grid {

    display: grid;

    grid-template-columns:
        repeat(2, 1fr);

    gap: 20px;
}


/* =========================================================
   BUDGET CARD
========================================================= */

.budget-card {

    border:
        1px solid #edf0f4;

    border-radius: 15px;

    padding: 22px;

    background: #fbfcfe;

    transition: .2s;
}


.budget-card:hover {

    box-shadow:
        0 8px 22px
        rgba(24,39,75,.08);

    transform:
        translateY(-1px);
}


.budget-top {

    display: flex;

    justify-content:
        space-between;

    align-items:
        center;

    margin-bottom: 18px;
}


.category-name {

    font-size: 18px;

    font-weight: 800;

    color: #263246;
}


.period {

    font-size: 11px;

    color: #667085;

    background: #eef3f8;

    padding:
        6px 10px;

    border-radius: 20px;

    font-weight: 700;
}


/* =========================================================
   BUDGET NUMBERS
========================================================= */

.budget-numbers {

    display: flex;

    justify-content:
        space-between;

    margin-bottom: 12px;
}


.budget-numbers span {

    font-size: 12px;

    color: #8993a4;
}


.budget-numbers strong {

    color: #263246;

    font-size: 13px;
}


/* =========================================================
   PROGRESS
========================================================= */

.progress-track {

    width: 100%;

    height: 8px;

    background: #edf1f5;

    border-radius: 10px;

    overflow: hidden;

    margin-bottom: 15px;
}


.progress-bar {

    height: 100%;

    border-radius: 10px;

    background:
        linear-gradient(
            90deg,
            #16b5f4,
            #168ce8
        );

    transition: width .3s ease;
}


.progress-bar.warning {

    background: #f59e0b;
}


.progress-bar.danger {

    background: #ef6464;
}


/* =========================================================
   BUDGET FOOTER
========================================================= */

.budget-footer {

    display: flex;

    justify-content:
        space-between;

    align-items:
        center;
}


.remaining {

    font-size: 13px;

    font-weight: 700;
}


.remaining.good {

    color: #159447;
}


.remaining.bad {

    color: #e04444;
}


.delete-link {

    color: #e04444;

    text-decoration: none;

    font-size: 12px;

    font-weight: 700;

    padding:
        6px 9px;

    border-radius: 7px;

    transition: .2s;
}


.delete-link:hover {

    background:
        rgba(224,68,68,.08);

    text-decoration: none;
}


/* =========================================================
   EMPTY STATE
========================================================= */

.empty-state {

    text-align: center;

    padding:
        45px 20px;

    color: #8993a4;

    font-size: 14px;
}


.empty-icon {

    font-size: 35px;

    margin-bottom: 10px;
}


.empty-state h3 {

    color: #263246;

    font-size: 18px;

    margin-bottom: 7px;
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1100px) {

    .summary-grid {

        grid-template-columns:
            repeat(2, 1fr);
    }

    .form-grid {

        grid-template-columns:
            repeat(2, 1fr);
    }

    .budget-grid {

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
}


@media (max-width: 700px) {

    .summary-grid {

        grid-template-columns: 1fr;
    }

    .form-grid {

        grid-template-columns: 1fr;
    }

    .page-header {

        align-items:
            flex-start;

        flex-direction:
            column;

        gap: 15px;
    }
}


@media (max-width: 650px) {

    .main-content {

        margin-left: 0;

        width: 100%;

        padding: 20px;
    }

    .card {

        padding: 20px;
    }

    .page-header h1 {

        font-size: 30px;
    }
}


@media (max-width: 450px) {

    .main-content {

        padding: 15px;
    }

    .card {

        padding: 18px;
    }

    .summary-card {

        padding: 20px;
    }

    .summary-value {

        font-size: 23px;
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


    <!-- =====================================================
         SHARED DASHBOARD SIDEBAR
    ====================================================== -->

    <?php include "sidebar.php"; ?>


    <!-- =====================================================
         MAIN CONTENT
    ====================================================== -->

    <main class="main-content">


        <!-- PAGE HEADER -->

        <div class="page-header">

            <div>

                <h1>
                    Budgets
                </h1>

                <p>
                    Plan your spending and stay within your limits.
                </p>

            </div>


            <div class="date-badge">

                📅
                <?php echo date("d M Y"); ?>

            </div>

        </div>


        <!-- ALERT -->

        <?php if (!empty($message)): ?>

            <div class="alert <?php echo htmlspecialchars($message_type); ?>">

                <?php
                echo htmlspecialchars($message);
                ?>

            </div>

        <?php endif; ?>


        <!-- =================================================
             SUMMARY CARDS
        ================================================== -->

        <div class="summary-grid">


            <!-- TOTAL BUDGET -->

            <div class="summary-card">

                <div class="summary-title">

                    Total Budget

                </div>

                <div class="summary-value blue">

                    ₹<?php
                    echo number_format(
                        $total_budget,
                        2
                    );
                    ?>

                </div>

            </div>


            <!-- TOTAL SPENT -->

            <div class="summary-card">

                <div class="summary-title">

                    Total Spent

                </div>

                <div class="summary-value red">

                    ₹<?php
                    echo number_format(
                        $total_spent,
                        2
                    );
                    ?>

                </div>

            </div>


            <!-- REMAINING -->

            <div class="summary-card">

                <div class="summary-title">

                    Remaining

                </div>

                <div
                    class="summary-value
                    <?php
                    echo $remaining >= 0
                        ? 'green'
                        : 'red';
                    ?>"
                >

                    ₹<?php
                    echo number_format(
                        $remaining,
                        2
                    );
                    ?>

                </div>

            </div>


        </div>


        <!-- =================================================
             CREATE BUDGET
        ================================================== -->

        <div class="card">


            <div class="card-header">

                <div>

                    <h2>
                        Create Budget
                    </h2>

                    <p>
                        Set a spending limit for a category.
                    </p>

                </div>

            </div>


            <form method="POST">


                <div class="form-grid">


                    <!-- CATEGORY -->

                    <div class="form-group">

                        <label>
                            Category
                        </label>

                        <select
                            name="category_id"
                            required
                        >

                            <option value="">
                                Select category
                            </option>


                            <?php foreach ($categories as $category): ?>

                                <option
                                    value="<?php
                                    echo $category['id'];
                                    ?>"
                                >

                                    <?php
                                    echo htmlspecialchars(
                                        $category['name']
                                    );
                                    ?>

                                </option>

                            <?php endforeach; ?>


                        </select>

                    </div>


                    <!-- AMOUNT -->

                    <div class="form-group">

                        <label>
                            Budget Amount
                        </label>

                        <input
                            type="number"
                            name="amount"
                            step="0.01"
                            min="1"
                            placeholder="e.g. 5000"
                            required
                        >

                    </div>


                    <!-- PERIOD -->

                    <div class="form-group">

                        <label>
                            Budget Period
                        </label>

                        <select
                            name="period"
                        >

                            <option value="monthly">
                                Monthly
                            </option>

                            <option value="weekly">
                                Weekly
                            </option>

                            <option value="yearly">
                                Yearly
                            </option>

                        </select>

                    </div>


                    <!-- START DATE -->

                    <div class="form-group">

                        <label>
                            Start Date
                        </label>

                        <input
                            type="date"
                            name="start_date"
                            value="<?php
                            echo date('Y-m-01');
                            ?>"
                            required
                        >

                    </div>


                    <!-- END DATE -->

                    <div class="form-group">

                        <label>
                            End Date
                            <small>
                                (optional)
                            </small>
                        </label>

                        <input
                            type="date"
                            name="end_date"
                        >

                    </div>


                    <!-- ROLLOVER -->

                    <div class="checkbox-group">

                        <input
                            type="checkbox"
                            name="rollover_enabled"
                            id="rollover"
                        >

                        <label
                            for="rollover"
                        >
                            Enable budget rollover
                        </label>

                    </div>


                    <!-- BUTTON -->

                    <div>

                        <button
                            type="submit"
                            name="create_budget"
                            class="btn-primary"
                        >

                            + Create Budget

                        </button>

                    </div>


                </div>

            </form>


        </div>


        <!-- =================================================
             YOUR BUDGETS
        ================================================== -->

        <div class="card">


            <div class="card-header">

                <div>

                    <h2>
                        Your Budgets
                    </h2>

                    <p>
                        Category-wise spending limits and progress.
                    </p>

                </div>

            </div>


            <?php if (count($budgets) > 0): ?>


                <div class="budget-grid">


                    <?php foreach ($budgets as $budget): ?>


                        <?php

                        if (
                            $budget['progress'] >= 100
                        ) {

                            $progress_class =
                                "danger";

                        } elseif (
                            $budget['progress'] >= 80
                        ) {

                            $progress_class =
                                "warning";

                        } else {

                            $progress_class =
                                "";
                        }

                        ?>


                        <div class="budget-card">


                            <!-- TOP -->

                            <div class="budget-top">


                                <div class="category-name">

                                    <?php
                                    echo htmlspecialchars(
                                        $budget['category_name']
                                    );
                                    ?>

                                </div>


                                <div class="period">

                                    <?php
                                    echo ucfirst(
                                        htmlspecialchars(
                                            $budget['period']
                                        )
                                    );
                                    ?>

                                </div>


                            </div>


                            <!-- NUMBERS -->

                            <div class="budget-numbers">


                                <span>

                                    Spent:

                                    <strong>

                                        ₹<?php
                                        echo number_format(
                                            $budget['spent'],
                                            2
                                        );
                                        ?>

                                    </strong>

                                </span>


                                <span>

                                    Budget:

                                    <strong>

                                        ₹<?php
                                        echo number_format(
                                            $budget['amount'],
                                            2
                                        );
                                        ?>

                                    </strong>

                                </span>


                            </div>


                            <!-- PROGRESS -->

                            <div class="progress-track">


                                <div
                                    class="progress-bar
                                    <?php
                                    echo $progress_class;
                                    ?>"
                                    style="
                                        width:
                                        <?php
                                        echo $budget['progress'];
                                        ?>%;
                                    "
                                ></div>


                            </div>


                            <!-- FOOTER -->

                            <div class="budget-footer">


                                <div
                                    class="remaining
                                    <?php
                                    echo $budget['remaining'] >= 0
                                        ? 'good'
                                        : 'bad';
                                    ?>"
                                >


                                    <?php
                                    if (
                                        $budget['remaining'] >= 0
                                    ):
                                    ?>

                                        ₹<?php
                                        echo number_format(
                                            $budget['remaining'],
                                            2
                                        );
                                        ?>

                                        remaining

                                    <?php else: ?>

                                        ₹<?php
                                        echo number_format(
                                            abs(
                                                $budget['remaining']
                                            ),
                                            2
                                        );
                                        ?>

                                        over budget

                                    <?php endif; ?>


                                </div>


                                <a
                                    href="budgets.php?delete=<?php echo (int)$budget['id']; ?>"
                                    class="delete-link"
                                    onclick="
                                        return confirm(
                                            'Are you sure you want to delete this budget?'
                                        );
                                    "
                                >

                                    Delete

                                </a>


                            </div>


                        </div>


                    <?php endforeach; ?>


                </div>


            <?php else: ?>


                <!-- EMPTY -->

                <div class="empty-state">

                    <div class="empty-icon">
                        📊
                    </div>

                    <h3>
                        No budgets created yet
                    </h3>

                    <p>
                        Create your first budget
                        using the form above.
                    </p>

                </div>


            <?php endif; ?>


        </div>


    </main>


</div>


</body>

</html>