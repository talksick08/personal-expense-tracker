<?php

require_once "auth.php";

$message = "";
$message_type = "success";


/* =========================================================
   ADD ACCOUNT
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_account"])) {

    $name = trim($_POST["name"] ?? "");
    $type = trim($_POST["type"] ?? "");
    $balance = floatval($_POST["balance"] ?? 0);


    if ($name === "" || $type === "") {

        $message = "Please fill in all required fields.";
        $message_type = "error";

    } else {

        $stmt = $conn->prepare(
            "INSERT INTO accounts
            (user_id, name, type, balance)
            VALUES (?, ?, ?, ?)"
        );


        if ($stmt) {

            $stmt->bind_param(
                "issd",
                $user_id,
                $name,
                $type,
                $balance
            );


            if ($stmt->execute()) {

                $message = "Account added successfully!";
                $message_type = "success";

            } else {

                $message = "Unable to add account.";
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
   UPDATE ACCOUNT
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_account"])) {

    $account_id = intval($_POST["account_id"] ?? 0);
    $name = trim($_POST["name"] ?? "");
    $type = trim($_POST["type"] ?? "");
    $balance = floatval($_POST["balance"] ?? 0);


    if ($account_id <= 0 || $name === "" || $type === "") {

        $message = "Please enter valid account details.";
        $message_type = "error";

    } else {

        $stmt = $conn->prepare(
            "UPDATE accounts
             SET name = ?, type = ?, balance = ?
             WHERE id = ? AND user_id = ?"
        );


        if ($stmt) {

            $stmt->bind_param(
                "ssdii",
                $name,
                $type,
                $balance,
                $account_id,
                $user_id
            );


            if ($stmt->execute()) {

                $message = "Account updated successfully!";
                $message_type = "success";

            } else {

                $message = "Unable to update account.";
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
   DELETE ACCOUNT
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_account"])) {

    $account_id = intval($_POST["account_id"] ?? 0);


    if ($account_id > 0) {

        $stmt = $conn->prepare(
            "DELETE FROM accounts
             WHERE id = ? AND user_id = ?"
        );


        if ($stmt) {

            $stmt->bind_param(
                "ii",
                $account_id,
                $user_id
            );


            if ($stmt->execute()) {

                if ($stmt->affected_rows > 0) {

                    $message = "Account deleted successfully!";
                    $message_type = "success";

                } else {

                    $message = "Account not found.";
                    $message_type = "error";
                }

            } else {

                $message =
                    "This account cannot be deleted because it may be connected to transactions.";

                $message_type = "error";
            }


            $stmt->close();
        }
    }
}


/* =========================================================
   GET ALL ACCOUNTS
========================================================= */

$stmt = $conn->prepare(
    "SELECT
        id,
        name,
        type,
        balance,
        created_at
     FROM accounts
     WHERE user_id = ?
     ORDER BY id DESC"
);


$stmt->bind_param(
    "i",
    $user_id
);

$stmt->execute();

$result = $stmt->get_result();

$accounts = [];


while ($row = $result->fetch_assoc()) {

    $accounts[] = $row;
}


$stmt->close();


/* =========================================================
   TOTAL BALANCE
========================================================= */

$total_balance = 0;


foreach ($accounts as $account) {

    $total_balance += floatval(
        $account["balance"]
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
        Accounts | Expense Tracker
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

        .main-content {

            margin-left: 250px;

            width: calc(100% - 250px);

            min-height: 100vh;

            padding: 40px;

            background: #f4f7fb;
        }


        /* =====================================================
           PAGE HEADER
        ===================================================== */

        .page-header {

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 20px;

            margin-bottom: 30px;
        }


        .page-header h1 {

            color: #162033;

            font-size: 34px;

            font-weight: 800;

            line-height: 1.2;

            margin-bottom: 6px;
        }


        .page-header p {

            color: #718096;

            font-size: 15px;
        }


        .page-kicker {

            color: #53a9d5;

            font-size: 12px;

            font-weight: 800;

            letter-spacing: 1.2px;

            margin-bottom: 5px;
        }


        /* =====================================================
           BUTTONS
        ===================================================== */

        .btn-secondary {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            gap: 7px;

            min-height: 43px;

            padding: 0 17px;

            background: #ffffff;

            border: 1px solid #e6ebf2;

            border-radius: 10px;

            color: #344054;

            text-decoration: none;

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            font-size: 13px;

            font-weight: 700;

            cursor: pointer;

            box-shadow:
                0 5px 18px
                rgba(24,39,75,.05);

            transition: .2s ease;
        }


        .btn-secondary:hover {

            transform: translateY(-1px);

            border-color: #cbd5e1;

            background: #fbfdff;
        }


        .btn-primary {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            min-height: 45px;

            padding: 0 20px;

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

            font-size: 13px;

            font-weight: 700;

            cursor: pointer;

            box-shadow:
                0 7px 18px
                rgba(22,140,232,.20);

            transition: .2s ease;
        }


        .btn-primary:hover {

            transform: translateY(-1px);

            box-shadow:
                0 10px 22px
                rgba(22,140,232,.27);
        }


        /* =====================================================
           ALERT
        ===================================================== */

        .alert {

            padding: 14px 17px;

            margin-bottom: 24px;

            border-radius: 11px;

            background: #eaf8ef;

            border: 1px solid #cdebd8;

            color: #218044;

            font-size: 14px;

            font-weight: 600;
        }


        .alert.error {

            background: #fff1f1;

            border-color: #ffd0d0;

            color: #c53030;
        }


        /* =====================================================
           TOTAL BALANCE
        ===================================================== */

        .account-summary {

            position: relative;

            overflow: hidden;

            background:
                linear-gradient(
                    135deg,
                    #10243d,
                    #174c72
                );

            color: #ffffff;

            border-radius: 20px;

            padding: 30px;

            margin-bottom: 25px;

            box-shadow:
                0 12px 30px
                rgba(16,36,61,.18);
        }


        .account-summary::after {

            content: "";

            position: absolute;

            width: 170px;

            height: 170px;

            border-radius: 50%;

            right: -55px;

            top: -65px;

            background:
                rgba(32,185,245,.10);
        }


        .account-summary-label {

            position: relative;

            z-index: 1;

            font-size: 13px;

            text-transform: uppercase;

            letter-spacing: 1px;

            opacity: .75;

            margin-bottom: 8px;
        }


        .account-summary-value {

            position: relative;

            z-index: 1;

            font-size: 34px;

            font-weight: 800;

            margin-bottom: 5px;
        }


        .account-summary-text {

            position: relative;

            z-index: 1;

            font-size: 14px;

            opacity: .75;
        }


        /* =====================================================
           SECTION CARD
        ===================================================== */

        .card {

            background: #ffffff;

            border: 1px solid #e6ebf2;

            border-radius: 18px;

            padding: 28px;

            margin-bottom: 25px;

            box-shadow:
                0 8px 25px
                rgba(24,39,75,.06);
        }


        .section-header {

            display: flex;

            align-items: center;

            justify-content: space-between;

            margin-bottom: 22px;
        }


        .section-header h2 {

            color: #172033;

            font-size: 21px;

            font-weight: 800;

            margin-bottom: 5px;
        }


        .section-header p {

            color: #718096;

            font-size: 14px;
        }


        /* =====================================================
           ACCOUNT CARDS
        ===================================================== */

        .accounts-grid {

            display: grid;

            grid-template-columns:
                repeat(
                    auto-fit,
                    minmax(230px, 1fr)
                );

            gap: 18px;

            margin-bottom: 28px;
        }


        .account-card {

            background: #ffffff;

            border: 1px solid #e6ebf2;

            border-radius: 17px;

            padding: 22px;

            box-shadow:
                0 7px 22px
                rgba(24,39,75,.06);

            transition: .2s ease;
        }


        .account-card:hover {

            transform: translateY(-2px);

            box-shadow:
                0 12px 28px
                rgba(24,39,75,.10);
        }


        .account-card-top {

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 10px;

            margin-bottom: 18px;
        }


        .account-icon {

            width: 44px;

            height: 44px;

            border-radius: 12px;

            display: flex;

            align-items: center;

            justify-content: center;

            background: #edf7ff;

            color: #1489d1;

            font-size: 20px;

            font-weight: 800;
        }


        .account-type {

            font-size: 12px;

            color: #718096;

            background: #f4f7fb;

            padding: 5px 9px;

            border-radius: 20px;

            text-transform: capitalize;
        }


        .account-name {

            font-size: 17px;

            font-weight: 750;

            color: #172033;

            margin-bottom: 6px;

            word-break: break-word;
        }


        .account-balance {

            font-size: 25px;

            font-weight: 800;

            color: #172033;

            margin-bottom: 16px;
        }


        .account-actions {

            display: flex;

            gap: 8px;
        }


        .account-actions button {

            border: none;

            cursor: pointer;

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            font-size: 12px;

            font-weight: 700;

            padding: 8px 12px;

            border-radius: 8px;

            transition: .2s ease;
        }


        .edit-account-btn {

            background: #edf6ff;

            color: #1478c9;
        }


        .edit-account-btn:hover {

            background: #dcefff;
        }


        .delete-account-btn {

            background: #fff0f0;

            color: #d63d3d;
        }


        .delete-account-btn:hover {

            background: #ffe2e2;
        }


        /* =====================================================
           EMPTY STATE
        ===================================================== */

        .empty-state {

            text-align: center;

            padding: 45px 20px;

            color: #718096;
        }


        .empty-state-icon {

            font-size: 42px;

            margin-bottom: 10px;
        }


        .empty-state h3 {

            color: #172033;

            margin-bottom: 5px;

            font-size: 18px;
        }


        .empty-state p {

            font-size: 14px;
        }


        /* =====================================================
           FORM
        ===================================================== */

        .form-grid {

            display: grid;

            grid-template-columns:
                repeat(3, 1fr);

            gap: 20px;

            margin-bottom: 22px;
        }


        .form-group {

            min-width: 0;
        }


        .form-group label {

            display: block;

            font-size: 13px;

            font-weight: 700;

            color: #344054;

            margin-bottom: 7px;
        }


        .form-group input,
        .form-group select {

            width: 100%;

            height: 46px;

            padding: 0 13px;

            background: #ffffff;

            border: 1px solid #d7dee8;

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


        .form-group input::placeholder {

            color: #a0a8b5;
        }


        .form-group input:focus,
        .form-group select:focus {

            border-color: #18aef1;

            box-shadow:
                0 0 0 3px
                rgba(24,174,241,.12);
        }


        /* =====================================================
           MODAL
        ===================================================== */

        .modal-overlay {

            display: none;

            position: fixed;

            inset: 0;

            background:
                rgba(15,23,42,.55);

            z-index: 2000;

            align-items: center;

            justify-content: center;

            padding: 20px;
        }


        .modal-overlay.show {

            display: flex;
        }


        .modal {

            width: 100%;

            max-width: 500px;

            max-height: 90vh;

            overflow-y: auto;

            background: #ffffff;

            border-radius: 18px;

            padding: 28px;

            box-shadow:
                0 25px 60px
                rgba(0,0,0,.20);
        }


        .modal-header {

            display: flex;

            justify-content: space-between;

            align-items: flex-start;

            gap: 15px;

            margin-bottom: 22px;
        }


        .modal-header h2 {

            margin: 0 0 4px;

            color: #172033;

            font-size: 21px;

            font-weight: 800;
        }


        .modal-header p {

            color: #718096;

            font-size: 14px;

            margin-top: 4px;
        }


        .close-modal {

            border: none;

            background: #f3f5f8;

            width: 35px;

            height: 35px;

            border-radius: 50%;

            cursor: pointer;

            font-size: 20px;

            color: #667085;

            flex-shrink: 0;
        }


        .close-modal:hover {

            background: #e9edf2;
        }


        .account-form-group {

            margin-bottom: 18px;
        }


        .account-form-group label {

            display: block;

            font-size: 13px;

            font-weight: 700;

            color: #344054;

            margin-bottom: 7px;
        }


        .account-form-group input,
        .account-form-group select {

            width: 100%;

            height: 46px;

            padding: 0 13px;

            border: 1px solid #d7dee8;

            border-radius: 10px;

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            font-size: 14px;

            color: #172033;

            outline: none;
        }


        .account-form-group input:focus,
        .account-form-group select:focus {

            border-color: #18aef1;

            box-shadow:
                0 0 0 3px
                rgba(24,174,241,.12);
        }


        .modal-actions {

            display: flex;

            gap: 10px;

            margin-top: 22px;
        }


        .modal-actions button {

            flex: 1;
        }


        /* =====================================================
           RESPONSIVE
        ===================================================== */

        @media (max-width: 1100px) {

            .form-grid {

                grid-template-columns:
                    repeat(2, 1fr);
            }
        }


        @media (max-width: 850px) {

            .main-content {

                margin-left: 210px;

                width:
                    calc(100% - 210px);

                padding: 28px;
            }
        }


        @media (max-width: 700px) {

            .main-content {

                margin-left: 0;

                width: 100%;

                padding: 22px 18px 40px;
            }


            .page-header {

                flex-direction: column;

                align-items: flex-start;
            }


            .form-grid {

                grid-template-columns: 1fr;
            }


            .account-summary {

                padding: 22px;
            }


            .account-summary-value {

                font-size: 28px;
            }


            .modal {

                padding: 22px;
            }
        }


        @media (max-width: 450px) {

            .main-content {

                padding: 18px 14px 35px;
            }


            .card {

                padding: 20px;
            }


            .account-card {

                padding: 18px;
            }


            .account-balance {

                font-size: 22px;
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
    ===================================================== -->

    <?php include "sidebar.php"; ?>


    <!-- =====================================================
         MAIN CONTENT
    ===================================================== -->

    <main class="main-content">


        <!-- =================================================
             PAGE HEADER
        ================================================= -->

        <div class="page-header">

            <div>

                <div class="page-kicker">
                    MONEY MANAGEMENT
                </div>

                <h1>
                    Accounts
                </h1>

                <p>
                    Manage your bank accounts, cash and payment methods.
                </p>

            </div>


            <a
                href="dashboard.php"
                class="btn-secondary"
            >
                ← Dashboard
            </a>

        </div>


        <!-- =================================================
             MESSAGE
        ================================================= -->

        <?php if ($message !== ""): ?>

            <div
                class="
                    alert
                    <?php
                    echo $message_type === "error"
                        ? "error"
                        : "";
                    ?>
                "
            >

                <?php
                echo htmlspecialchars($message);
                ?>

            </div>

        <?php endif; ?>


        <!-- =================================================
             TOTAL BALANCE
        ================================================= -->

        <div class="account-summary">

            <div class="account-summary-label">
                Total Account Balance
            </div>


            <div class="account-summary-value">

                ₹<?php
                echo number_format(
                    $total_balance,
                    2
                );
                ?>

            </div>


            <div class="account-summary-text">
                Combined balance across your accounts
            </div>

        </div>


        <!-- =================================================
             ACCOUNT CARDS
        ================================================= -->

        <?php if (count($accounts) > 0): ?>

            <div class="accounts-grid">


                <?php foreach ($accounts as $account): ?>


                    <?php

                    $icon = "₹";

                    $account_type =
                        strtolower(
                            $account["type"]
                        );


                    if (
                        strpos(
                            $account_type,
                            "bank"
                        ) !== false
                    ) {

                        $icon = "🏦";

                    } elseif (
                        strpos(
                            $account_type,
                            "cash"
                        ) !== false
                    ) {

                        $icon = "💵";

                    } elseif (
                        strpos(
                            $account_type,
                            "upi"
                        ) !== false
                    ) {

                        $icon = "📱";

                    } elseif (
                        strpos(
                            $account_type,
                            "credit"
                        ) !== false ||
                        strpos(
                            $account_type,
                            "card"
                        ) !== false
                    ) {

                        $icon = "💳";

                    } elseif (
                        strpos(
                            $account_type,
                            "wallet"
                        ) !== false
                    ) {

                        $icon = "👛";
                    }

                    ?>


                    <div class="account-card">


                        <div class="account-card-top">


                            <div class="account-icon">

                                <?php
                                echo $icon;
                                ?>

                            </div>


                            <div class="account-type">

                                <?php
                                echo htmlspecialchars(
                                    $account["type"]
                                );
                                ?>

                            </div>


                        </div>


                        <div class="account-name">

                            <?php
                            echo htmlspecialchars(
                                $account["name"]
                            );
                            ?>

                        </div>


                        <div class="account-balance">

                            ₹<?php
                            echo number_format(
                                floatval(
                                    $account["balance"]
                                ),
                                2
                            );
                            ?>

                        </div>


                        <div class="account-actions">


                            <button
                                type="button"
                                class="edit-account-btn"
                                onclick="openEditModal(
                                    <?php
                                    echo (int)$account["id"];
                                    ?>,
                                    <?php
                                    echo htmlspecialchars(
                                        json_encode(
                                            $account["name"]
                                        ),
                                        ENT_QUOTES,
                                        "UTF-8"
                                    );
                                    ?>,
                                    <?php
                                    echo htmlspecialchars(
                                        json_encode(
                                            $account["type"]
                                        ),
                                        ENT_QUOTES,
                                        "UTF-8"
                                    );
                                    ?>,
                                    <?php
                                    echo htmlspecialchars(
                                        json_encode(
                                            $account["balance"]
                                        ),
                                        ENT_QUOTES,
                                        "UTF-8"
                                    );
                                    ?>
                                )"
                            >
                                Edit
                            </button>


                            <form
                                method="POST"
                                style="display:inline;"
                                onsubmit="
                                    return confirm(
                                        'Are you sure you want to delete this account?'
                                    );
                                "
                            >

                                <input
                                    type="hidden"
                                    name="account_id"
                                    value="<?php
                                    echo (int)$account["id"];
                                    ?>"
                                >


                                <button
                                    type="submit"
                                    name="delete_account"
                                    class="delete-account-btn"
                                >
                                    Delete
                                </button>

                            </form>


                        </div>


                    </div>


                <?php endforeach; ?>


            </div>


        <?php else: ?>


            <div class="card">

                <div class="empty-state">

                    <div class="empty-state-icon">
                        💳
                    </div>

                    <h3>
                        No accounts yet
                    </h3>

                    <p>
                        Add your first bank account, cash wallet or payment method.
                    </p>

                </div>

            </div>


        <?php endif; ?>


        <!-- =================================================
             ADD ACCOUNT
        ================================================= -->

        <div class="card">


            <div class="section-header">

                <div>

                    <h2>
                        Add Account
                    </h2>

                    <p>
                        Add a bank account, cash wallet, UPI account or card.
                    </p>

                </div>

            </div>


            <form method="POST">


                <div class="form-grid">


                    <!-- ACCOUNT NAME -->

                    <div class="form-group">

                        <label>
                            Account Name
                        </label>

                        <input
                            type="text"
                            name="name"
                            placeholder="e.g. SBI Bank"
                            required
                        >

                    </div>


                    <!-- ACCOUNT TYPE -->

                    <div class="form-group">

                        <label>
                            Account Type
                        </label>

                        <select
                            name="type"
                            required
                        >

                            <option value="">
                                Select Account Type
                            </option>

                            <option value="Bank">
                                Bank Account
                            </option>

                            <option value="Cash">
                                Cash
                            </option>

                            <option value="UPI">
                                UPI
                            </option>

                            <option value="Wallet">
                                Digital Wallet
                            </option>

                            <option value="Credit Card">
                                Credit Card
                            </option>

                            <option value="Debit Card">
                                Debit Card
                            </option>

                            <option value="Prepaid Card">
                                Prepaid Card
                            </option>

                            <option value="Other">
                                Other
                            </option>

                        </select>

                    </div>


                    <!-- BALANCE -->

                    <div class="form-group">

                        <label>
                            Current / Opening Balance
                        </label>

                        <input
                            type="number"
                            name="balance"
                            step="0.01"
                            value="0"
                            placeholder="0.00"
                            required
                        >

                    </div>


                </div>


                <button
                    type="submit"
                    name="add_account"
                    class="btn-primary"
                >
                    + Add Account
                </button>


            </form>


        </div>


    </main>


</div>


<!-- =========================================================
     EDIT ACCOUNT MODAL
========================================================= -->

<div
    id="editModal"
    class="modal-overlay"
>


    <div class="modal">


        <div class="modal-header">


            <div>

                <h2>
                    Edit Account
                </h2>

                <p>
                    Update your account details.
                </p>

            </div>


            <button
                type="button"
                class="close-modal"
                onclick="closeEditModal()"
            >
                ×
            </button>


        </div>


        <form method="POST">


            <input
                type="hidden"
                name="account_id"
                id="edit_account_id"
            >


            <!-- ACCOUNT NAME -->

            <div class="account-form-group">

                <label>
                    Account Name
                </label>

                <input
                    type="text"
                    name="name"
                    id="edit_name"
                    required
                >

            </div>


            <!-- ACCOUNT TYPE -->

            <div class="account-form-group">

                <label>
                    Account Type
                </label>

                <select
                    name="type"
                    id="edit_type"
                    required
                >

                    <option value="Bank">
                        Bank Account
                    </option>

                    <option value="Cash">
                        Cash
                    </option>

                    <option value="UPI">
                        UPI
                    </option>

                    <option value="Wallet">
                        Digital Wallet
                    </option>

                    <option value="Credit Card">
                        Credit Card
                    </option>

                    <option value="Debit Card">
                        Debit Card
                    </option>

                    <option value="Prepaid Card">
                        Prepaid Card
                    </option>

                    <option value="Other">
                        Other
                    </option>

                </select>

            </div>


            <!-- BALANCE -->

            <div class="account-form-group">

                <label>
                    Balance
                </label>

                <input
                    type="number"
                    name="balance"
                    id="edit_balance"
                    step="0.01"
                    required
                >

            </div>


            <!-- ACTIONS -->

            <div class="modal-actions">

                <button
                    type="button"
                    class="btn-secondary"
                    onclick="closeEditModal()"
                >
                    Cancel
                </button>


                <button
                    type="submit"
                    name="update_account"
                    class="btn-primary"
                >
                    Save Changes
                </button>

            </div>


        </form>


    </div>


</div>


<!-- =========================================================
     JAVASCRIPT
========================================================= -->

<script>


function openEditModal(
    id,
    name,
    type,
    balance
) {

    document.getElementById(
        "edit_account_id"
    ).value = id;


    document.getElementById(
        "edit_name"
    ).value = name;


    document.getElementById(
        "edit_type"
    ).value = type;


    document.getElementById(
        "edit_balance"
    ).value = balance;


    document
        .getElementById("editModal")
        .classList
        .add("show");
}


function closeEditModal() {

    document
        .getElementById("editModal")
        .classList
        .remove("show");
}


/* =====================================================
   CLOSE WHEN CLICKING OUTSIDE
===================================================== */

document
    .getElementById("editModal")
    .addEventListener(
        "click",
        function(event) {

            if (event.target === this) {

                closeEditModal();
            }
        }
    );


/* =====================================================
   CLOSE WITH ESCAPE
===================================================== */

document.addEventListener(
    "keydown",
    function(event) {

        if (event.key === "Escape") {

            closeEditModal();
        }
    }
);

</script>


</body>

</html>