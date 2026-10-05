<?php

require_once "auth.php";

$message = "";
$message_type = "";

if (isset($_GET['deleted']) && $_GET['deleted'] === '1') {
    $message = "Transaction deleted successfully.";
    $message_type = "success";
}

if (isset($_GET['updated']) && $_GET['updated'] === '1') {
    $message = "Transaction updated successfully.";
    $message_type = "success";
}


/* =========================================================
   ADD TRANSACTION
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_transaction"])) {

    $type = $_POST["type"] ?? "";
    $amount = $_POST["amount"] ?? "";
    $category = trim($_POST["category"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $transaction_date = $_POST["transaction_date"] ?? "";

    $account_id = !empty($_POST["account_id"])
        ? (int)$_POST["account_id"]
        : null;


    if (!in_array($type, ["income", "expense"], true)) {

        $message = "Invalid transaction type.";
        $message_type = "error";

    } elseif (!is_numeric($amount) || (float)$amount <= 0) {

        $message = "Please enter a valid amount.";
        $message_type = "error";

    } elseif ($category === "" || $transaction_date === "") {

        $message = "Please fill all required fields.";
        $message_type = "error";

    } else {

        /* Verify account belongs to current user */

        if ($account_id !== null) {

            $check = $conn->prepare(
                "SELECT id
                 FROM accounts
                 WHERE id = ?
                 AND user_id = ?"
            );

            if ($check) {

                $check->bind_param(
                    "ii",
                    $account_id,
                    $user_id
                );

                $check->execute();

                $check_result = $check->get_result();

                if ($check_result->num_rows === 0) {
                    $account_id = null;
                }

                $check->close();
            }
        }


        $stmt = $conn->prepare(
            "INSERT INTO transactions
            (
                user_id,
                type,
                amount,
                category,
                description,
                account_id,
                transaction_date
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)"
        );


        if ($stmt) {

            $stmt->bind_param(
                "isdssis",
                $user_id,
                $type,
                $amount,
                $category,
                $description,
                $account_id,
                $transaction_date
            );


            if ($stmt->execute()) {

                $message = "Transaction added successfully.";
                $message_type = "success";

            } else {

                $message = "Unable to add transaction.";
                $message_type = "error";
            }


            $stmt->close();

        } else {

            $message = "Something went wrong. Please try again.";
            $message_type = "error";
        }
    }
}


/* =========================================================
   GET USER ACCOUNTS
========================================================= */

$accounts = [];

$account_stmt = $conn->prepare(
    "SELECT id, name, type
     FROM accounts
     WHERE user_id = ?
     ORDER BY name ASC"
);


if ($account_stmt) {

    $account_stmt->bind_param(
        "i",
        $user_id
    );

    $account_stmt->execute();

    $accounts_result = $account_stmt->get_result();


    while ($account = $accounts_result->fetch_assoc()) {

        $accounts[] = $account;
    }


    $account_stmt->close();
}


/* =========================================================
   SEARCH & FILTER
========================================================= */

$search = trim($_GET["search"] ?? "");

$filter_type = $_GET["type"] ?? "";

$from_date = $_GET["from_date"] ?? "";

$to_date = $_GET["to_date"] ?? "";


$sql = "
    SELECT
        t.*,
        a.name AS account_name
    FROM transactions t
    LEFT JOIN accounts a
        ON t.account_id = a.id
    WHERE t.user_id = ?
";


$params = [$user_id];

$types = "i";


/* SEARCH */

if ($search !== "") {

    $sql .= "
        AND (
            t.category LIKE ?
            OR t.description LIKE ?
            OR a.name LIKE ?
        )
    ";

    $search_value = "%" . $search . "%";

    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;

    $types .= "sss";
}


/* TRANSACTION TYPE */

if (
    $filter_type === "income" ||
    $filter_type === "expense"
) {

    $sql .= " AND t.type = ?";

    $params[] = $filter_type;

    $types .= "s";
}


/* FROM DATE */

if ($from_date !== "") {

    $sql .= " AND t.transaction_date >= ?";

    $params[] = $from_date;

    $types .= "s";
}


/* TO DATE */

if ($to_date !== "") {

    $sql .= " AND t.transaction_date <= ?";

    $params[] = $to_date;

    $types .= "s";
}


$sql .= "
    ORDER BY
        t.transaction_date DESC,
        t.id DESC
";


$stmt = $conn->prepare($sql);

$result = null;


if ($stmt) {

    $stmt->bind_param(
        $types,
        ...$params
    );

    $stmt->execute();

    $result = $stmt->get_result();
}


/* =========================================================
   CALCULATE TOTALS
========================================================= */

$total_income = 0;

$total_expenses = 0;

$total_transactions = 0;


if ($result) {

    while ($row = $result->fetch_assoc()) {

        $total_transactions++;

        if ($row["type"] === "income") {

            $total_income += (float)$row["amount"];

        } else {

            $total_expenses += (float)$row["amount"];
        }
    }


    /*
       Run query again so the result can be used
       for the transaction table.
    */

    $stmt->execute();

    $result = $stmt->get_result();
}


$balance = $total_income - $total_expenses;

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Transactions - Expense Tracker</title>

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

        /* =====================================================
           RESET
        ===================================================== */

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }


        /* =====================================================
           BODY
        ===================================================== */

        body {

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            background: #f4f7fb;

            color: #172033;
        }


        /* =====================================================
           APP LAYOUT
        ===================================================== */

        .app-layout {

            display: flex;

            min-height: 100vh;
        }


        /* =====================================================
           MAIN CONTENT
        ===================================================== */

        .transaction-main {

            margin-left: 250px;

            width: calc(100% - 250px);

            min-height: 100vh;

            background: #f4f7fb;
        }


        .transaction-page {

            width: 100%;

            max-width: 1500px;

            margin: 0 auto;

            padding: 40px;
        }


        /* =====================================================
           PAGE HEADER
        ===================================================== */

        .transaction-header {

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 20px;

            margin-bottom: 30px;
        }


        .transaction-header h1 {

            color: #162033;

            font-size: 34px;

            font-weight: 800;

            line-height: 1.2;

            margin-bottom: 6px;
        }


        .transaction-header p {

            color: #718096;

            font-size: 15px;
        }


        .dashboard-link {

            display: inline-flex;

            align-items: center;

            gap: 7px;

            padding: 12px 18px;

            background: #ffffff;

            border: 1px solid #e6ebf2;

            border-radius: 10px;

            color: #344054;

            text-decoration: none;

            font-size: 13px;

            font-weight: 600;

            box-shadow:
                0 5px 18px
                rgba(24,39,75,.05);

            transition: .2s ease;
        }


        .dashboard-link:hover {

            transform: translateY(-1px);

            border-color: #cbd5e1;
        }


        /* =====================================================
           ALERTS
        ===================================================== */

        .transaction-alert {

            padding: 14px 17px;

            margin-bottom: 24px;

            border-radius: 11px;

            font-size: 14px;

            font-weight: 600;
        }


        .transaction-alert.success {

            background: #eaf8ef;

            border: 1px solid #cdebd8;

            color: #218044;
        }


        .transaction-alert.error {

            background: #fff1f1;

            border: 1px solid #ffd0d0;

            color: #c53030;
        }


        /* =====================================================
           SUMMARY CARDS
        ===================================================== */

        .transaction-summary {

            display: grid;

            grid-template-columns:
                repeat(3, 1fr);

            gap: 20px;

            margin-bottom: 25px;
        }


        .transaction-summary-card {

            background: #ffffff;

            border: 1px solid #e6ebf2;

            border-radius: 18px;

            padding: 24px;

            min-height: 140px;

            box-shadow:
                0 8px 25px
                rgba(24,39,75,.06);
        }


        .summary-label {

            display: block;

            margin-bottom: 10px;

            color: #7d8796;

            font-size: 11px;

            font-weight: 800;

            text-transform: uppercase;

            letter-spacing: .8px;
        }


        .summary-value {

            font-size: 29px;

            font-weight: 800;

            line-height: 1.2;
        }


        .summary-income {
            color: #159447;
        }


        .summary-expense {
            color: #d94b4b;
        }


        .summary-balance {
            color: #172033;
        }


        /* =====================================================
           CONTENT CARDS
        ===================================================== */

        .transaction-card {

            background: #ffffff;

            border: 1px solid #e6ebf2;

            border-radius: 18px;

            padding: 28px;

            margin-bottom: 25px;

            box-shadow:
                0 8px 25px
                rgba(24,39,75,.06);
        }


        .transaction-card-title {

            margin-bottom: 6px;

            color: #172033;

            font-size: 21px;

            font-weight: 800;
        }


        .transaction-card-description {

            margin-bottom: 24px;

            color: #718096;

            font-size: 14px;
        }


        /* =====================================================
           ADD TRANSACTION FORM
        ===================================================== */

        .transaction-form-grid {

            display: grid;

            grid-template-columns:
                repeat(2, 1fr);

            gap: 20px;
        }


        .transaction-field {
            min-width: 0;
        }


        .transaction-field label {

            display: block;

            margin-bottom: 8px;

            color: #475467;

            font-size: 13px;

            font-weight: 700;
        }


        .transaction-field input,
        .transaction-field select {

            width: 100%;

            min-height: 47px;

            padding: 12px 14px;

            background: #ffffff;

            border: 1px solid #dfe5ed;

            border-radius: 10px;

            color: #172033;

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            font-size: 14px;

            outline: none;

            transition: .2s ease;
        }


        .transaction-field input::placeholder {
            color: #a0a8b5;
        }


        .transaction-field input:focus,
        .transaction-field select:focus {

            border-color: #20b9f5;

            box-shadow:
                0 0 0 3px
                rgba(32,185,245,.10);
        }


        .add-transaction-btn {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            min-height: 47px;

            margin-top: 22px;

            padding: 0 22px;

            border: none;

            border-radius: 10px;

            background:
                linear-gradient(
                    135deg,
                    #20b9f5,
                    #168ce8
                );

            color: #ffffff;

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            font-size: 14px;

            font-weight: 700;

            cursor: pointer;

            box-shadow:
                0 7px 18px
                rgba(22,140,232,.20);

            transition: .2s ease;
        }


        .add-transaction-btn:hover {

            transform: translateY(-1px);

            box-shadow:
                0 10px 22px
                rgba(22,140,232,.27);
        }


        /* =====================================================
           FILTER
        ===================================================== */

        .transaction-filter-grid {

            display: grid;

            grid-template-columns:
                2fr
                1fr
                1fr
                1fr;

            gap: 15px;

            align-items: end;
        }


        .transaction-filter-field label {

            display: block;

            margin-bottom: 8px;

            color: #475467;

            font-size: 13px;

            font-weight: 700;
        }


        .transaction-filter-field input,
        .transaction-filter-field select {

            width: 100%;

            min-height: 45px;

            padding: 11px 13px;

            background: #ffffff;

            border: 1px solid #dfe5ed;

            border-radius: 10px;

            color: #172033;

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            font-size: 13px;

            outline: none;
        }


        .transaction-filter-field input:focus,
        .transaction-filter-field select:focus {

            border-color: #20b9f5;

            box-shadow:
                0 0 0 3px
                rgba(32,185,245,.10);
        }


        .filter-actions {

            display: flex;

            align-items: center;

            gap: 14px;

            margin-top: 18px;
        }


        .apply-filter-btn {

            min-height: 43px;

            padding: 0 18px;

            border: none;

            border-radius: 9px;

            background: #172a46;

            color: #ffffff;

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            font-size: 13px;

            font-weight: 700;

            cursor: pointer;
        }


        .clear-filter-link {

            color: #168bc6;

            text-decoration: none;

            font-size: 13px;

            font-weight: 700;
        }


        /* =====================================================
           TABLE
        ===================================================== */

        .transaction-table-wrapper {

            width: 100%;

            overflow-x: auto;

            border: 1px solid #e7ebf1;

            border-radius: 13px;
        }


        .transaction-table {

            width: 100%;

            min-width: 950px;

            border-collapse: collapse;

            font-family:
                Arial,
                Helvetica,
                sans-serif;
        }


        .transaction-table th {

            padding: 14px 15px;

            background: #f8fafc;

            border-bottom: 1px solid #e7ebf1;

            color: #7b8494;

            text-align: left;

            font-size: 11px;

            font-weight: 800;

            letter-spacing: .5px;
        }


        .transaction-table td {

            padding: 15px;

            border-bottom: 1px solid #f0f2f5;

            color: #344054;

            font-size: 13px;

            vertical-align: middle;
        }


        .transaction-table tbody tr:last-child td {
            border-bottom: none;
        }


        .transaction-table tbody tr:hover {
            background: #fbfdff;
        }


        /* =====================================================
           TYPE BADGES
        ===================================================== */

        .transaction-type-badge {

            display: inline-flex;

            align-items: center;

            padding: 5px 9px;

            border-radius: 999px;

            font-size: 11px;

            font-weight: 700;
        }


        .transaction-type-income {

            background: #eaf8ef;

            color: #159447;
        }


        .transaction-type-expense {

            background: #fff0f0;

            color: #d94b4b;
        }


        /* =====================================================
           AMOUNTS
        ===================================================== */

        .transaction-amount-income {

            color: #159447;

            font-weight: 800;
        }


        .transaction-amount-expense {

            color: #d94b4b;

            font-weight: 800;
        }


        /* =====================================================
           ACTIONS
        ===================================================== */

        .transaction-actions {

            display: flex;

            align-items: center;

            gap: 7px;
        }


        .transaction-edit {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            padding: 7px 10px;

            background: #e9f5ff;

            color: #168bc6;

            border-radius: 8px;

            text-decoration: none;

            font-size: 12px;

            font-weight: 700;
        }


        .transaction-delete {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            padding: 7px 10px;

            background: #fff0f0;

            color: #c84b4b;

            border-radius: 8px;

            text-decoration: none;

            font-size: 12px;

            font-weight: 700;
        }


        /* =====================================================
           EMPTY STATE
        ===================================================== */

        .transaction-empty {

            padding: 50px 20px !important;

            text-align: center;

            color: #718096 !important;

            font-size: 14px !important;
        }


        /* =====================================================
           RESPONSIVE
        ===================================================== */

        @media (max-width: 1100px) {

            .transaction-filter-grid {

                grid-template-columns:
                    repeat(2, 1fr);
            }
        }


        @media (max-width: 900px) {

            .transaction-main {

                margin-left: 210px;

                width:
                    calc(100% - 210px);
            }


            .transaction-summary {

                grid-template-columns: 1fr;
            }


            .transaction-form-grid {

                grid-template-columns: 1fr;
            }
        }


        @media (max-width: 700px) {

            .transaction-main {

                margin-left: 0;

                width: 100%;
            }


            .transaction-page {

                padding: 22px 18px 40px;
            }


            .transaction-header {

                flex-direction: column;

                align-items: flex-start;
            }


            .transaction-filter-grid {

                grid-template-columns: 1fr;
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
         DASHBOARD SIDEBAR
    ===================================================== -->

    <?php include "sidebar.php"; ?>


    <!-- =====================================================
         MAIN CONTENT
    ===================================================== -->

    <main class="transaction-main">


        <div class="transaction-page">


            <!-- =================================================
                 HEADER
            ================================================= -->

            <div class="transaction-header">

                <div>

                    <h1>
                        Transactions
                    </h1>

                    <p>
                        Track and manage your income and expenses.
                    </p>

                </div>


                <a
                    href="dashboard.php"
                    class="dashboard-link"
                >
                    ← Dashboard
                </a>

            </div>


            <!-- =================================================
                 ALERT
            ================================================= -->

            <?php if ($message !== ""): ?>

                <div
                    class="
                        transaction-alert
                        <?php echo htmlspecialchars($message_type); ?>
                    "
                >

                    <?php
                    echo htmlspecialchars($message);
                    ?>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 SUMMARY
            ================================================= -->

            <div class="transaction-summary">


                <div class="transaction-summary-card">

                    <span class="summary-label">
                        Total Income
                    </span>

                    <div class="
                        summary-value
                        summary-income
                    ">

                        ₹<?php
                        echo number_format(
                            $total_income,
                            2
                        );
                        ?>

                    </div>

                </div>


                <div class="transaction-summary-card">

                    <span class="summary-label">
                        Total Expenses
                    </span>

                    <div class="
                        summary-value
                        summary-expense
                    ">

                        ₹<?php
                        echo number_format(
                            $total_expenses,
                            2
                        );
                        ?>

                    </div>

                </div>


                <div class="transaction-summary-card">

                    <span class="summary-label">
                        Balance
                    </span>

                    <div class="
                        summary-value
                        summary-balance
                    ">

                        ₹<?php
                        echo number_format(
                            $balance,
                            2
                        );
                        ?>

                    </div>

                </div>


            </div>


            <!-- =================================================
                 ADD TRANSACTION
            ================================================= -->

            <section class="transaction-card">


                <h2 class="transaction-card-title">
                    Add Transaction
                </h2>


                <p class="transaction-card-description">
                    Record a new income or expense.
                </p>


                <form method="POST">


                    <div class="transaction-form-grid">


                        <!-- TYPE -->

                        <div class="transaction-field">

                            <label>
                                Transaction Type
                            </label>

                            <select
                                name="type"
                                required
                            >

                                <option value="expense">
                                    Expense
                                </option>

                                <option value="income">
                                    Income
                                </option>

                            </select>

                        </div>


                        <!-- AMOUNT -->

                        <div class="transaction-field">

                            <label>
                                Amount
                            </label>

                            <input
                                type="number"
                                name="amount"
                                step="0.01"
                                min="0.01"
                                placeholder="₹ 0.00"
                                required
                            >

                        </div>


                        <!-- CATEGORY -->

                        <div class="transaction-field">

                            <label>
                                Category
                            </label>

                            <input
                                type="text"
                                name="category"
                                placeholder="Food, Travel, Salary..."
                                required
                            >

                        </div>


                        <!-- ACCOUNT -->

                        <div class="transaction-field">

                            <label>
                                Account
                            </label>

                            <select name="account_id">

                                <option value="">
                                    No Account
                                </option>


                                <?php foreach ($accounts as $account): ?>

                                    <option
                                        value="<?php echo (int)$account["id"]; ?>"
                                    >

                                        <?php
                                        echo htmlspecialchars(
                                            $account["name"]
                                        );

                                        echo " (";

                                        echo htmlspecialchars(
                                            $account["type"]
                                        );

                                        echo ")";
                                        ?>

                                    </option>

                                <?php endforeach; ?>


                            </select>

                        </div>


                        <!-- DATE -->

                        <div class="transaction-field">

                            <label>
                                Date
                            </label>

                            <input
                                type="date"
                                name="transaction_date"
                                value="<?php echo date("Y-m-d"); ?>"
                                required
                            >

                        </div>


                        <!-- DESCRIPTION -->

                        <div class="transaction-field">

                            <label>
                                Description
                            </label>

                            <input
                                type="text"
                                name="description"
                                placeholder="Add a note about this transaction..."
                            >

                        </div>


                    </div>


                    <button
                        type="submit"
                        name="add_transaction"
                        class="add-transaction-btn"
                    >

                        + Add Transaction

                    </button>


                </form>

            </section>


            <!-- =================================================
                 SEARCH & FILTER
            ================================================= -->

            <section class="transaction-card">


                <h2 class="transaction-card-title">
                    Search &amp; Filter
                </h2>


                <p class="transaction-card-description">
                    Find transactions quickly.
                </p>


                <form method="GET">


                    <div class="transaction-filter-grid">


                        <!-- SEARCH -->

                        <div class="transaction-filter-field">

                            <label>
                                Search
                            </label>

                            <input
                                type="text"
                                name="search"
                                placeholder="Category, description, account..."
                                value="<?php
                                echo htmlspecialchars($search);
                                ?>"
                            >

                        </div>


                        <!-- TYPE -->

                        <div class="transaction-filter-field">

                            <label>
                                Type
                            </label>

                            <select name="type">

                                <option value="">
                                    All
                                </option>

                                <option
                                    value="expense"
                                    <?php
                                    echo $filter_type === "expense"
                                        ? "selected"
                                        : "";
                                    ?>
                                >
                                    Expense
                                </option>

                                <option
                                    value="income"
                                    <?php
                                    echo $filter_type === "income"
                                        ? "selected"
                                        : "";
                                    ?>
                                >
                                    Income
                                </option>

                            </select>

                        </div>


                        <!-- FROM DATE -->

                        <div class="transaction-filter-field">

                            <label>
                                From Date
                            </label>

                            <input
                                type="date"
                                name="from_date"
                                value="<?php
                                echo htmlspecialchars($from_date);
                                ?>"
                            >

                        </div>


                        <!-- TO DATE -->

                        <div class="transaction-filter-field">

                            <label>
                                To Date
                            </label>

                            <input
                                type="date"
                                name="to_date"
                                value="<?php
                                echo htmlspecialchars($to_date);
                                ?>"
                            >

                        </div>


                    </div>


                    <div class="filter-actions">

                        <button
                            type="submit"
                            class="apply-filter-btn"
                        >
                            Apply Filters
                        </button>


                        <a
                            href="transactions.php"
                            class="clear-filter-link"
                        >
                            Clear Filters
                        </a>

                    </div>


                </form>

            </section>


            <!-- =================================================
                 TRANSACTION HISTORY
            ================================================= -->

            <section class="transaction-card">


                <h2 class="transaction-card-title">
                    Transaction History
                </h2>


                <p class="transaction-card-description">
                    Your latest financial activity.
                </p>

                <div class="mobile-table-hint">
                    <span>👉</span> Swipe horizontally to view full table & actions
                </div>

                <div class="transaction-table-wrapper">


                    <table class="transaction-table">


                        <thead>

                            <tr>

                                <th>
                                    DATE
                                </th>

                                <th>
                                    TYPE
                                </th>

                                <th>
                                    AMOUNT
                                </th>

                                <th>
                                    CATEGORY
                                </th>

                                <th>
                                    ACCOUNT
                                </th>

                                <th>
                                    DESCRIPTION
                                </th>

                                <th>
                                    ACTION
                                </th>

                            </tr>

                        </thead>


                        <tbody>


                        <?php if ($result && $result->num_rows > 0): ?>


                            <?php while ($row = $result->fetch_assoc()): ?>


                                <tr>


                                    <!-- DATE -->

                                    <td>

                                        <?php

                                        echo htmlspecialchars(
                                            date(
                                                "d M Y",
                                                strtotime(
                                                    $row["transaction_date"]
                                                )
                                            )
                                        );

                                        ?>

                                    </td>


                                    <!-- TYPE -->

                                    <td>

                                        <?php if ($row["type"] === "income"): ?>

                                            <span
                                                class="
                                                    transaction-type-badge
                                                    transaction-type-income
                                                "
                                            >
                                                ↑ Income
                                            </span>

                                        <?php else: ?>

                                            <span
                                                class="
                                                    transaction-type-badge
                                                    transaction-type-expense
                                                "
                                            >
                                                ↓ Expense
                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <!-- AMOUNT -->

                                    <td>

                                        <?php if ($row["type"] === "income"): ?>

                                            <span
                                                class="
                                                    transaction-amount-income
                                                "
                                            >

                                                +₹<?php
                                                echo number_format(
                                                    (float)$row["amount"],
                                                    2
                                                );
                                                ?>

                                            </span>

                                        <?php else: ?>

                                            <span
                                                class="
                                                    transaction-amount-expense
                                                "
                                            >

                                                -₹<?php
                                                echo number_format(
                                                    (float)$row["amount"],
                                                    2
                                                );
                                                ?>

                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <!-- CATEGORY -->

                                    <td>

                                        <?php

                                        echo htmlspecialchars(
                                            $row["category"]
                                        );

                                        ?>

                                    </td>


                                    <!-- ACCOUNT -->

                                    <td>

                                        <?php

                                        if (!empty($row["account_name"])) {

                                            echo htmlspecialchars(
                                                $row["account_name"]
                                            );

                                        } else {

                                            echo "Not selected";
                                        }

                                        ?>

                                    </td>


                                    <!-- DESCRIPTION -->

                                    <td>

                                        <?php

                                        if (
                                            !empty(
                                                $row["description"]
                                            )
                                        ) {

                                            echo htmlspecialchars(
                                                $row["description"]
                                            );

                                        } else {

                                            echo "—";
                                        }

                                        ?>

                                    </td>


                                    <!-- ACTION -->

                                    <td>

                                        <div class="transaction-actions">


                                            <a
                                                href="edit_transaction.php?id=<?php echo (int)$row["id"]; ?>"
                                                class="transaction-edit"
                                            >
                                                Edit
                                            </a>


                                            <a
                                                href="delete_transaction.php?id=<?php echo (int)$row["id"]; ?>"
                                                class="transaction-delete"
                                                onclick="
                                                    return confirm(
                                                        'Are you sure you want to delete this transaction?'
                                                    );
                                                "
                                            >
                                                Delete
                                            </a>


                                        </div>

                                    </td>


                                </tr>


                            <?php endwhile; ?>


                        <?php else: ?>


                            <tr>

                                <td
                                    colspan="7"
                                    class="transaction-empty"
                                >

                                    No transactions found.

                                </td>

                            </tr>


                        <?php endif; ?>


                        </tbody>

                    </table>

                </div>


            </section>


        </div>


    </main>


</div>


</body>

</html>