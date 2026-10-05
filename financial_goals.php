<?php

session_start();
require_once "db.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION["user_id"];

$message = "";
$error = "";


/*
|--------------------------------------------------------------------------
| ADD FINANCIAL GOAL
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["add_goal"])
) {

    $name = trim($_POST["name"] ?? "");
    $target_amount = trim($_POST["target_amount"] ?? "");
    $saved_amount = trim($_POST["saved_amount"] ?? "");
    $target_date = trim($_POST["target_date"] ?? "");
    $monthly_contribution = trim(
        $_POST["monthly_contribution"] ?? ""
    );
    $notes = trim($_POST["notes"] ?? "");


    if (
        $name === ""
        || $target_amount === ""
        || $target_date === ""
    ) {

        $error =
            "Please enter the goal name, target amount and target date.";

    } else {

        $target_amount = (float)$target_amount;

        $saved_amount =
            ($saved_amount === "")
            ? 0
            : (float)$saved_amount;

        $monthly_contribution =
            ($monthly_contribution === "")
            ? 0
            : (float)$monthly_contribution;


        if ($target_amount <= 0) {

            $error =
                "Target amount must be greater than zero.";

        } elseif ($saved_amount < 0) {

            $error =
                "Saved amount cannot be negative.";

        } elseif ($saved_amount > $target_amount) {

            $error =
                "Saved amount cannot be greater than the target amount.";

        } elseif ($monthly_contribution < 0) {

            $error =
                "Monthly contribution cannot be negative.";

        } else {

            /*
             * current_amount is kept synchronized
             * with saved_amount.
             */

            $current_amount = $saved_amount;


            $stmt = $conn->prepare("
                INSERT INTO financial_goals
                (
                    user_id,
                    name,
                    target_amount,
                    saved_amount,
                    current_amount,
                    target_date,
                    monthly_contribution,
                    notes
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");


            if ($stmt) {

                /*
                 * i = user_id
                 * s = name
                 * d = target_amount
                 * d = saved_amount
                 * d = current_amount
                 * s = target_date
                 * d = monthly_contribution
                 * s = notes
                 */

                $stmt->bind_param(
                    "isdddsds",
                    $user_id,
                    $name,
                    $target_amount,
                    $saved_amount,
                    $current_amount,
                    $target_date,
                    $monthly_contribution,
                    $notes
                );


                if ($stmt->execute()) {

                    $message =
                        "Financial goal added successfully.";

                } else {

                    $error =
                        "Unable to add goal: "
                        . $stmt->error;
                }


                $stmt->close();

            } else {

                $error =
                    "Database error: "
                    . $conn->error;
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| UPDATE SAVED AMOUNT
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["update_progress"])
) {

    $goal_id =
        (int)($_POST["goal_id"] ?? 0);

    $new_saved_amount =
        (float)($_POST["new_saved_amount"] ?? 0);


    if ($goal_id <= 0) {

        $error =
            "Invalid financial goal.";

    } elseif ($new_saved_amount < 0) {

        $error =
            "Saved amount cannot be negative.";

    } else {

        $stmt = $conn->prepare("
            SELECT target_amount
            FROM financial_goals
            WHERE id = ?
            AND user_id = ?
        ");


        if ($stmt) {

            $stmt->bind_param(
                "ii",
                $goal_id,
                $user_id
            );

            $stmt->execute();

            $result =
                $stmt->get_result();

            $goal =
                $result->fetch_assoc();

            $stmt->close();


            if (!$goal) {

                $error =
                    "Financial goal not found.";

            } elseif (
                $new_saved_amount
                > (float)$goal["target_amount"]
            ) {

                $error =
                    "Saved amount cannot be greater than the target amount.";

            } else {

                $update = $conn->prepare("
                    UPDATE financial_goals
                    SET
                        saved_amount = ?,
                        current_amount = ?
                    WHERE id = ?
                    AND user_id = ?
                ");


                if ($update) {

                    $update->bind_param(
                        "ddii",
                        $new_saved_amount,
                        $new_saved_amount,
                        $goal_id,
                        $user_id
                    );


                    if ($update->execute()) {

                        $message =
                            "Goal progress updated successfully.";

                    } else {

                        $error =
                            "Unable to update progress: "
                            . $update->error;
                    }


                    $update->close();

                } else {

                    $error =
                        "Database error: "
                        . $conn->error;
                }
            }

        } else {

            $error =
                "Database error: "
                . $conn->error;
        }
    }
}


/*
|--------------------------------------------------------------------------
| DELETE FINANCIAL GOAL
|--------------------------------------------------------------------------
*/

if (isset($_GET["delete"])) {

    $goal_id =
        (int)$_GET["delete"];


    if ($goal_id > 0) {

        $stmt = $conn->prepare("
            DELETE FROM financial_goals
            WHERE id = ?
            AND user_id = ?
        ");


        if ($stmt) {

            $stmt->bind_param(
                "ii",
                $goal_id,
                $user_id
            );

            $stmt->execute();

            $stmt->close();
        }
    }


    header("Location: financial_goals.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| GET FINANCIAL GOALS
|--------------------------------------------------------------------------
*/

$goals = [];

$stmt = $conn->prepare("
    SELECT
        id,
        name,
        target_amount,
        saved_amount,
        current_amount,
        target_date,
        monthly_contribution,
        notes,
        created_at
    FROM financial_goals
    WHERE user_id = ?
    ORDER BY target_date ASC
");


if ($stmt) {

    $stmt->bind_param(
        "i",
        $user_id
    );

    $stmt->execute();

    $result =
        $stmt->get_result();


    while ($row = $result->fetch_assoc()) {

        $goals[] = $row;
    }


    $stmt->close();
}


/*
|--------------------------------------------------------------------------
| STATISTICS
|--------------------------------------------------------------------------
*/

$total_goals =
    count($goals);

$total_target = 0;
$total_saved = 0;

$completed_goals = 0;
$active_goals = 0;


foreach ($goals as $goal) {

    $target =
        (float)$goal["target_amount"];

    $saved =
        (float)$goal["saved_amount"];


    $total_target += $target;

    $total_saved += $saved;


    if (
        $target > 0
        && $saved >= $target
    ) {

        $completed_goals++;

    } else {

        $active_goals++;
    }
}


$overall_progress = 0;


if ($total_target > 0) {

    $overall_progress =
        ($total_saved / $total_target) * 100;
}


$overall_progress =
    min(
        100,
        max(
            0,
            $overall_progress
        )
    );

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
        Financial Goals - Personal Expense Tracker
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
   ALERTS
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
   STATISTICS
========================================================= */

.stats-grid {

    display: grid;

    grid-template-columns:
        repeat(4, 1fr);

    gap: 20px;

    margin-bottom: 25px;
}


.stat-card {

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


.stat-card::after {

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


.stat-label {

    color: #718096;

    font-size: 13px;

    font-weight: 700;

    text-transform:
        uppercase;

    letter-spacing: .5px;

    margin-bottom: 12px;
}


.stat-value {

    font-size: 28px;

    font-weight: 800;

    color: #172033;
}


.stat-blue {
    color: #168ce8;
}


.stat-green {
    color: #159447;
}


.stat-purple {
    color: #7957d5;
}


.stat-orange {
    color: #e58a18;
}


/* =========================================================
   CARDS
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

    margin-bottom: 24px;
}


.card-header h2 {

    font-size: 20px;

    color: #172033;

    margin-bottom: 5px;
}


.card-header p {

    color: #8993a4;

    font-size: 13px;
}


/* =========================================================
   FORM
========================================================= */

.form-grid {

    display: grid;

    grid-template-columns:
        repeat(2, 1fr);

    gap: 20px;
}


.form-group label {

    display: block;

    margin-bottom: 8px;

    color: #344054;

    font-size: 14px;

    font-weight: 700;
}


.form-group input,
.form-group textarea {

    width: 100%;

    border:
        1px solid #d7dee8;

    background: #ffffff;

    color: #172033;

    border-radius: 10px;

    padding:
        12px 14px;

    font-size: 14px;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    outline: none;

    transition: .2s;
}


.form-group input {

    height: 46px;
}


.form-group textarea {

    min-height: 90px;

    resize: vertical;
}


.form-group input:focus,
.form-group textarea:focus {

    border-color: #18aef1;

    box-shadow:
        0 0 0 3px
        rgba(24,174,241,.12);
}


.full-width {

    grid-column:
        1 / -1;
}


.btn-primary {

    display: inline-flex;

    align-items: center;

    justify-content: center;

    background:
        linear-gradient(
            135deg,
            #16b5f4,
            #168ce8
        );

    color: #ffffff;

    border: none;

    border-radius: 10px;

    padding:
        13px 22px;

    font-size: 14px;

    font-weight: 700;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    cursor: pointer;

    text-decoration: none;

    margin-top: 20px;

    transition: .2s ease;
}


.btn-primary:hover {

    opacity: .92;

    transform:
        translateY(-1px);
}


/* =========================================================
   OVERALL PROGRESS
========================================================= */

.progress-header {

    display: flex;

    justify-content:
        space-between;

    align-items:
        center;

    margin-bottom: 9px;
}


.progress-header span {

    font-size: 13px;

    font-weight: 700;

    color: #667085;
}


.progress-track {

    width: 100%;

    height: 10px;

    background: #edf1f5;

    border-radius: 20px;

    overflow: hidden;
}


.progress-fill {

    height: 100%;

    background:
        linear-gradient(
            90deg,
            #16b5f4,
            #168ce8
        );

    border-radius: 20px;

    transition:
        width .3s ease;
}


/* =========================================================
   GOALS
========================================================= */

.goals-grid {

    display: grid;

    grid-template-columns:
        repeat(2, 1fr);

    gap: 20px;
}


.goal-card {

    border:
        1px solid #e6ebf2;

    border-radius: 16px;

    padding: 22px;

    background: #fbfcfe;

    transition: .2s ease;
}


.goal-card:hover {

    box-shadow:
        0 8px 22px
        rgba(24,39,75,.08);

    transform:
        translateY(-1px);
}


.goal-card.completed-goal {

    border-color:
        #c8e9d3;

    background:
        #fbfffc;
}


.goal-top {

    display: flex;

    justify-content:
        space-between;

    align-items:
        flex-start;

    gap: 15px;

    margin-bottom: 18px;
}


.goal-name {

    font-size: 18px;

    font-weight: 800;

    color: #172033;
}


.goal-date {

    margin-top: 6px;

    color: #718096;

    font-size: 13px;
}


.goal-status {

    padding:
        6px 10px;

    border-radius: 20px;

    background: #eaf6ff;

    color: #168ce8;

    font-size: 11px;

    font-weight: 800;

    white-space: nowrap;
}


.goal-status.completed {

    background: #eaf8ef;

    color: #159447;
}


/* =========================================================
   GOAL AMOUNTS
========================================================= */

.goal-amounts {

    display: flex;

    justify-content:
        space-between;

    align-items:
        center;

    margin-bottom: 9px;
}


.saved-amount {

    font-size: 20px;

    font-weight: 800;

    color: #168ce8;
}


.target-amount {

    color: #718096;

    font-size: 13px;

    font-weight: 600;
}


/* =========================================================
   GOAL PROGRESS
========================================================= */

.goal-progress-track {

    width: 100%;

    height: 9px;

    background: #e9eef4;

    border-radius: 20px;

    overflow: hidden;
}


.goal-progress-fill {

    height: 100%;

    background:
        linear-gradient(
            90deg,
            #16b5f4,
            #168ce8
        );

    border-radius: 20px;
}


.goal-card.completed-goal
.goal-progress-fill {

    background:
        linear-gradient(
            90deg,
            #20c66b,
            #159447
        );
}


.goal-percent {

    text-align: right;

    margin-top: 7px;

    font-size: 12px;

    font-weight: 800;

    color: #667085;
}


/* =========================================================
   DESCRIPTION
========================================================= */

.goal-description {

    margin-top: 14px;

    padding-top: 14px;

    border-top:
        1px solid #e8edf3;

    color: #718096;

    font-size: 13px;

    line-height: 1.5;
}


/* =========================================================
   GOAL ACTIONS
========================================================= */

.goal-actions {

    display: flex;

    justify-content:
        space-between;

    align-items:
        center;

    gap: 10px;

    margin-top: 18px;

    padding-top: 14px;

    border-top:
        1px solid #e8edf3;
}


.update-form {

    display: flex;

    gap: 8px;

    flex: 1;
}


.update-form input {

    min-width: 0;

    flex: 1;

    height: 38px;

    border:
        1px solid #d7dee8;

    border-radius: 8px;

    padding:
        8px 10px;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    outline: none;
}


.update-form input:focus {

    border-color: #18aef1;

    box-shadow:
        0 0 0 3px
        rgba(24,174,241,.10);
}


.update-btn {

    border: none;

    background: #168ce8;

    color: #ffffff;

    border-radius: 8px;

    padding:
        8px 12px;

    font-size: 12px;

    font-weight: 700;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    cursor: pointer;

    transition: .2s;
}


.update-btn:hover {

    background: #117bcf;
}


.delete-link {

    color: #e04444;

    text-decoration: none;

    font-size: 12px;

    font-weight: 700;

    white-space: nowrap;
}


.delete-link:hover {

    text-decoration: underline;
}


/* =========================================================
   EMPTY STATE
========================================================= */

.empty-goals {

    text-align: center;

    padding:
        55px 20px;

    color: #718096;
}


.empty-icon {

    font-size: 46px;

    margin-bottom: 12px;
}


.empty-goals h3 {

    margin-bottom: 6px;

    color: #344054;

    font-size: 18px;
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1150px) {

    .stats-grid {

        grid-template-columns:
            repeat(2, 1fr);
    }

    .goals-grid {

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

    .form-grid {

        grid-template-columns: 1fr;
    }

    .full-width {

        grid-column: auto;
    }
}


@media (max-width: 650px) {

    .main-content {

        margin-left: 0;

        width: 100%;

        padding: 20px;
    }

    .page-header {

        display: block;
    }

    .date-badge {

        display: inline-block;

        margin-top: 15px;
    }

    .stats-grid {

        grid-template-columns: 1fr;
    }

    .goal-actions {

        flex-direction: column;

        align-items: stretch;
    }

    .update-form {

        width: 100%;
    }

    .delete-link {

        text-align: center;
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

        font-size: 24px;
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


        <!-- =================================================
             HEADER
        ================================================== -->

        <div class="page-header">

            <div>

                <h1>
                    Financial Goals
                </h1>

                <p>
                    Set savings targets and track your financial progress.
                </p>

            </div>


            <div class="date-badge">

                📅
                <?php
                echo date("d M Y");
                ?>

            </div>

        </div>


        <!-- =================================================
             ALERTS
        ================================================== -->

        <?php if ($message !== ""): ?>

            <div class="alert success">

                <?php
                echo htmlspecialchars($message);
                ?>

            </div>

        <?php endif; ?>


        <?php if ($error !== ""): ?>

            <div class="alert error">

                <?php
                echo htmlspecialchars($error);
                ?>

            </div>

        <?php endif; ?>


        <!-- =================================================
             STATISTICS
        ================================================== -->

        <div class="stats-grid">


            <!-- TOTAL GOALS -->

            <div class="stat-card">

                <div class="stat-label">
                    Total Goals
                </div>

                <div class="stat-value stat-blue">

                    <?php
                    echo $total_goals;
                    ?>

                </div>

            </div>


            <!-- ACTIVE GOALS -->

            <div class="stat-card">

                <div class="stat-label">
                    Active Goals
                </div>

                <div class="stat-value stat-orange">

                    <?php
                    echo $active_goals;
                    ?>

                </div>

            </div>


            <!-- COMPLETED GOALS -->

            <div class="stat-card">

                <div class="stat-label">
                    Completed Goals
                </div>

                <div class="stat-value stat-green">

                    <?php
                    echo $completed_goals;
                    ?>

                </div>

            </div>


            <!-- TOTAL SAVED -->

            <div class="stat-card">

                <div class="stat-label">
                    Total Saved
                </div>

                <div class="stat-value stat-purple">

                    ₹<?php
                    echo number_format(
                        $total_saved,
                        2
                    );
                    ?>

                </div>

            </div>


        </div>


        <!-- =================================================
             OVERALL PROGRESS
        ================================================== -->

        <div class="card">


            <div class="card-header">

                <h2>
                    Overall Goal Progress
                </h2>

                <p>
                    Your total savings compared with all goal targets.
                </p>

            </div>


            <div class="progress-header">

                <span>

                    ₹<?php
                    echo number_format(
                        $total_saved,
                        2
                    );
                    ?>

                    saved

                </span>


                <span>

                    <?php
                    echo number_format(
                        $overall_progress,
                        1
                    );
                    ?>%

                </span>

            </div>


            <div class="progress-track">

                <div
                    class="progress-fill"
                    style="
                        width:
                        <?php
                        echo $overall_progress;
                        ?>%;
                    "
                ></div>

            </div>


        </div>


        <!-- =================================================
             ADD FINANCIAL GOAL
        ================================================== -->

        <div class="card">


            <div class="card-header">

                <h2>
                    Add Financial Goal
                </h2>

                <p>
                    Create a savings target and track your progress.
                </p>

            </div>


            <form method="POST">


                <div class="form-grid">


                    <!-- GOAL NAME -->

                    <div class="form-group">

                        <label>
                            Goal Name
                        </label>

                        <input
                            type="text"
                            name="name"
                            placeholder="e.g. New Laptop"
                            required
                        >

                    </div>


                    <!-- TARGET AMOUNT -->

                    <div class="form-group">

                        <label>
                            Target Amount
                        </label>

                        <input
                            type="number"
                            name="target_amount"
                            step="0.01"
                            min="1"
                            placeholder="e.g. 80000"
                            required
                        >

                    </div>


                    <!-- SAVED AMOUNT -->

                    <div class="form-group">

                        <label>
                            Current Saved Amount
                        </label>

                        <input
                            type="number"
                            name="saved_amount"
                            step="0.01"
                            min="0"
                            placeholder="e.g. 15000"
                        >

                    </div>


                    <!-- TARGET DATE -->

                    <div class="form-group">

                        <label>
                            Target Date
                        </label>

                        <input
                            type="date"
                            name="target_date"
                            required
                        >

                    </div>


                    <!-- MONTHLY CONTRIBUTION -->

                    <div class="form-group">

                        <label>
                            Monthly Contribution
                        </label>

                        <input
                            type="number"
                            name="monthly_contribution"
                            step="0.01"
                            min="0"
                            placeholder="e.g. 5000"
                        >

                    </div>


                    <!-- NOTES -->

                    <div class="form-group full-width">

                        <label>
                            Notes
                        </label>

                        <textarea
                            name="notes"
                            placeholder="Add details about this financial goal..."
                        ></textarea>

                    </div>


                </div>


                <button
                    type="submit"
                    name="add_goal"
                    class="btn-primary"
                >

                    + Add Financial Goal

                </button>


            </form>


        </div>


        <!-- =================================================
             FINANCIAL GOALS LIST
        ================================================== -->

        <div class="card">


            <div class="card-header">

                <h2>
                    Your Financial Goals
                </h2>

                <p>
                    Track your savings progress goal by goal.
                </p>

            </div>


            <?php if (empty($goals)): ?>


                <!-- EMPTY STATE -->

                <div class="empty-goals">

                    <div class="empty-icon">
                        🎯
                    </div>

                    <h3>
                        No financial goals yet
                    </h3>

                    <p>
                        Create your first savings goal using the form above.
                    </p>

                </div>


            <?php else: ?>


                <div class="goals-grid">


                    <?php foreach ($goals as $goal): ?>


                        <?php

                        $target =
                            (float)$goal["target_amount"];

                        $saved =
                            (float)$goal["saved_amount"];


                        $progress = 0;


                        if ($target > 0) {

                            $progress =
                                ($saved / $target) * 100;
                        }


                        $progress =
                            min(
                                100,
                                max(
                                    0,
                                    $progress
                                )
                            );


                        $completed =
                            $saved >= $target;

                        ?>


                        <div
                            class="
                                goal-card
                                <?php
                                echo $completed
                                    ? 'completed-goal'
                                    : '';
                                ?>
                            "
                        >


                            <!-- GOAL HEADER -->

                            <div class="goal-top">


                                <div>

                                    <div class="goal-name">

                                        <?php
                                        echo htmlspecialchars(
                                            $goal["name"]
                                        );
                                        ?>

                                    </div>


                                    <div class="goal-date">

                                        📅 Target:

                                        <?php

                                        if (
                                            !empty(
                                                $goal["target_date"]
                                            )
                                        ) {

                                            echo date(
                                                "d M Y",
                                                strtotime(
                                                    $goal["target_date"]
                                                )
                                            );

                                        } else {

                                            echo "Not set";
                                        }

                                        ?>

                                    </div>

                                </div>


                                <div
                                    class="
                                        goal-status
                                        <?php
                                        echo $completed
                                            ? 'completed'
                                            : '';
                                        ?>
                                    "
                                >

                                    <?php
                                    echo $completed
                                        ? "Completed"
                                        : "In Progress";
                                    ?>

                                </div>


                            </div>


                            <!-- AMOUNTS -->

                            <div class="goal-amounts">


                                <div class="saved-amount">

                                    ₹<?php
                                    echo number_format(
                                        $saved,
                                        2
                                    );
                                    ?>

                                </div>


                                <div class="target-amount">

                                    of ₹<?php
                                    echo number_format(
                                        $target,
                                        2
                                    );
                                    ?>

                                </div>


                            </div>


                            <!-- PROGRESS -->

                            <div class="goal-progress-track">

                                <div
                                    class="goal-progress-fill"
                                    style="
                                        width:
                                        <?php
                                        echo $progress;
                                        ?>%;
                                    "
                                ></div>

                            </div>


                            <div class="goal-percent">

                                <?php
                                echo number_format(
                                    $progress,
                                    1
                                );
                                ?>% complete

                            </div>


                            <!-- NOTES -->

                            <?php
                            if (
                                !empty(
                                    $goal["notes"]
                                )
                            ):
                            ?>

                                <div class="goal-description">

                                    <?php
                                    echo nl2br(
                                        htmlspecialchars(
                                            $goal["notes"]
                                        )
                                    );
                                    ?>

                                </div>

                            <?php endif; ?>


                            <!-- MONTHLY CONTRIBUTION -->

                            <?php
                            if (
                                !empty(
                                    $goal["monthly_contribution"]
                                )
                            ):
                            ?>

                                <div
                                    class="goal-description"
                                    style="
                                        border-top:none;
                                        padding-top:0;
                                        margin-top:8px;
                                    "
                                >

                                    💰 Monthly contribution:

                                    <strong>

                                        ₹<?php

                                        echo number_format(
                                            (float)$goal[
                                                "monthly_contribution"
                                            ],
                                            2
                                        );

                                        ?>

                                    </strong>

                                </div>

                            <?php endif; ?>


                            <!-- ACTIONS -->

                            <div class="goal-actions">


                                <form
                                    method="POST"
                                    class="update-form"
                                >


                                    <input
                                        type="hidden"
                                        name="goal_id"
                                        value="<?php
                                        echo (int)$goal["id"];
                                        ?>"
                                    >


                                    <input
                                        type="number"
                                        name="new_saved_amount"
                                        min="0"
                                        max="<?php
                                        echo htmlspecialchars(
                                            $target
                                        );
                                        ?>"
                                        step="0.01"
                                        placeholder="Update saved amount"
                                        required
                                    >


                                    <button
                                        type="submit"
                                        name="update_progress"
                                        class="update-btn"
                                    >
                                        Update
                                    </button>


                                </form>


                                <a
                                    href="financial_goals.php?delete=<?php echo (int)$goal["id"]; ?>"
                                    class="delete-link"
                                    onclick="
                                        return confirm(
                                            'Delete this financial goal?'
                                        );
                                    "
                                >
                                    Delete
                                </a>


                            </div>


                        </div>


                    <?php endforeach; ?>


                </div>


            <?php endif; ?>


        </div>


    </main>


</div>


</body>

</html>