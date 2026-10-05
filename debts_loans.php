<?php
session_start();
require_once "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$message = "";
$error = "";

/* =========================
   DELETE DEBT / LOAN
========================= */
if (isset($_GET['delete'])) {

    $id = intval($_GET['delete']);

    $stmt = $conn->prepare(
        "DELETE FROM debts_loans WHERE id = ? AND user_id = ?"
    );

    $stmt->bind_param("ii", $id, $user_id);
    $stmt->execute();
    $stmt->close();

    header("Location: debts_loans.php?deleted=1");
    exit;
}

/* =========================
   EDIT MODE
========================= */
$edit_id = 0;
$edit_data = null;

if (isset($_GET['edit'])) {

    $edit_id = intval($_GET['edit']);

    $stmt = $conn->prepare(
        "SELECT * FROM debts_loans WHERE id = ? AND user_id = ?"
    );

    $stmt->bind_param("ii", $edit_id, $user_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $edit_data = $result->fetch_assoc();

    $stmt->close();
}

/* =========================
   ADD / UPDATE
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

    $name = trim($_POST['name']);
    $type = $_POST['type'];
    $principal_amount = floatval($_POST['principal_amount']);
    $outstanding_amount = floatval($_POST['outstanding_amount']);
    $interest_rate = floatval($_POST['interest_rate']);
    $emi_amount = floatval($_POST['emi_amount']);

    $due_date = !empty($_POST['due_date'])
        ? $_POST['due_date']
        : null;

    $total_installments = !empty($_POST['total_installments'])
        ? intval($_POST['total_installments'])
        : null;

    $remaining_installments = !empty($_POST['remaining_installments'])
        ? intval($_POST['remaining_installments'])
        : null;

    $notes = trim($_POST['notes']);

    if ($name === "" || $principal_amount <= 0) {

        $error = "Please enter a valid name and principal amount.";

    } else {

        if ($id > 0) {

            $stmt = $conn->prepare(
                "UPDATE debts_loans
                 SET name = ?,
                     type = ?,
                     principal_amount = ?,
                     outstanding_amount = ?,
                     interest_rate = ?,
                     emi_amount = ?,
                     due_date = ?,
                     total_installments = ?,
                     remaining_installments = ?,
                     notes = ?
                 WHERE id = ? AND user_id = ?"
            );

            $stmt->bind_param(
                "ssddddsiisii",
                $name,
                $type,
                $principal_amount,
                $outstanding_amount,
                $interest_rate,
                $emi_amount,
                $due_date,
                $total_installments,
                $remaining_installments,
                $notes,
                $id,
                $user_id
            );

            if ($stmt->execute()) {
                $message = "Debt / loan updated successfully.";
            } else {
                $error = "Unable to update the debt / loan.";
            }

            $stmt->close();

        } else {

            $stmt = $conn->prepare(
                "INSERT INTO debts_loans
                (
                    user_id,
                    name,
                    type,
                    principal_amount,
                    outstanding_amount,
                    interest_rate,
                    emi_amount,
                    due_date,
                    total_installments,
                    remaining_installments,
                    notes
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            $stmt->bind_param(
                "issddddsiis",
                $user_id,
                $name,
                $type,
                $principal_amount,
                $outstanding_amount,
                $interest_rate,
                $emi_amount,
                $due_date,
                $total_installments,
                $remaining_installments,
                $notes
            );

            if ($stmt->execute()) {
                $message = "Debt / loan added successfully.";
            } else {
                $error = "Unable to add the debt / loan.";
            }

            $stmt->close();
        }

        $edit_id = 0;
        $edit_data = null;
    }
}

/* =========================
   SUCCESS MESSAGE
========================= */
if (isset($_GET['deleted'])) {
    $message = "Debt / loan deleted successfully.";
}

/* =========================
   SUMMARY
========================= */

$total_debt = 0;
$total_outstanding = 0;
$total_emi = 0;
$total_loans = 0;

$stmt = $conn->prepare(
    "SELECT
        COALESCE(SUM(principal_amount), 0) AS total_debt,
        COALESCE(SUM(outstanding_amount), 0) AS total_outstanding,
        COALESCE(SUM(emi_amount), 0) AS total_emi,
        COUNT(*) AS total_loans
     FROM debts_loans
     WHERE user_id = ?"
);

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();
$summary = $result->fetch_assoc();

$total_debt = $summary['total_debt'];
$total_outstanding = $summary['total_outstanding'];
$total_emi = $summary['total_emi'];
$total_loans = $summary['total_loans'];

$stmt->close();

/* =========================
   FETCH ALL DEBTS
========================= */

$debts = [];

$stmt = $conn->prepare(
    "SELECT *
     FROM debts_loans
     WHERE user_id = ?
     ORDER BY
        CASE
            WHEN due_date IS NULL THEN 1
            ELSE 0
        END,
        due_date ASC,
        id DESC"
);

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $debts[] = $row;
}

$stmt->close();

function money($amount)
{
    return "₹" . number_format((float)$amount, 2);
}

function typeLabel($type)
{
    switch ($type) {
        case "loan":
            return "Loan";
        case "credit_card":
            return "Credit Card";
        case "emi":
            return "EMI";
        default:
            return "Other";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Debt & Loans - Expense Tracker</title>

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

        /* SIDEBAR */

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
            z-index: 100;
            box-shadow: 4px 0 18px rgba(0,0,0,.08);
        }

        .logo {
            font-size: 25px;
            font-weight: 800;
            padding: 28px 24px;
            border-bottom: 1px solid rgba(255,255,255,.06);
        }

        .logo span {
            color: #20b9f5;
        }

        .sidebar nav {
            padding: 22px 12px;
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
            transition: .2s;
        }

        .sidebar nav a:hover {
            background: rgba(32,185,245,.12);
            color: #fff;
        }

        .sidebar nav a.active {
            background: linear-gradient(135deg,#16b5f4,#168ce8);
            color: #fff;
            box-shadow: 0 7px 18px rgba(22,140,232,.25);
        }

        .nav-icon {
            width: 25px;
            margin-right: 10px;
            font-size: 17px;
        }

        /* MAIN */

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

        /* ALERTS */

        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 22px;
            font-size: 14px;
            font-weight: 600;
        }

        .success {
            background: #eaf8ef;
            border: 1px solid #bde5ca;
            color: #19703a;
        }

        .error {
            background: #fff0f0;
            border: 1px solid #f2c2c2;
            color: #b42318;
        }

        /* SUMMARY */

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 26px;
        }

        .summary-card {
            background: white;
            border: 1px solid #e6ebf2;
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 8px 25px rgba(24,39,75,.06);
        }

        .summary-label {
            color: #667085;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .5px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .summary-value {
            font-size: 27px;
            font-weight: 800;
            color: #172033;
        }

        .summary-card.outstanding .summary-value {
            color: #e04444;
        }

        .summary-card.emi .summary-value {
            color: #d97706;
        }

        .summary-card.count .summary-value {
            color: #168ce8;
        }

        /* CARD */

        .card {
            background: #fff;
            border: 1px solid #e6ebf2;
            border-radius: 18px;
            padding: 28px;
            margin-bottom: 24px;
            box-shadow: 0 8px 25px rgba(24,39,75,.06);
        }

        .card h2 {
            font-size: 22px;
            color: #172033;
            margin-bottom: 6px;
        }

        .card-subtitle {
            color: #718096;
            font-size: 14px;
            margin-bottom: 24px;
        }

        /* FORM */

        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        .form-group {
            width: 100%;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #344054;
            font-size: 14px;
            font-weight: 700;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            border: 1px solid #d7dee8;
            background: #fff;
            color: #172033;
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 14px;
            font-family: inherit;
            outline: none;
        }

        .form-group input,
        .form-group select {
            height: 46px;
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #18aef1;
            box-shadow: 0 0 0 3px rgba(24,174,241,.12);
        }

        .form-actions {
            margin-top: 22px;
            display: flex;
            gap: 12px;
        }

        .btn {
            border: none;
            border-radius: 10px;
            padding: 12px 20px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: linear-gradient(135deg,#16b5f4,#168ce8);
            color: white;
        }

        .btn-primary:hover {
            opacity: .9;
        }

        .btn-secondary {
            background: #eef2f7;
            color: #344054;
        }

        /* TABLE */

        .table-container {
            width: 100%;
            overflow-x: auto;
            border: 1px solid #e6ebf2;
            border-radius: 12px;
        }

        table {
            width: 100%;
            min-width: 1100px;
            border-collapse: collapse;
            background: #fff;
        }

        thead {
            background: #f7f9fc;
        }

        th {
            padding: 15px 16px;
            text-align: left;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: #667085;
            border-bottom: 1px solid #e6ebf2;
        }

        td {
            padding: 16px;
            font-size: 14px;
            color: #344054;
            border-bottom: 1px solid #edf0f4;
        }

        tr:last-child td {
            border-bottom: none;
        }

        .loan-name {
            font-weight: 800;
            color: #172033;
        }

        .type-badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            background: #eaf5ff;
            color: #1671b9;
            font-size: 12px;
            font-weight: 700;
        }

        .amount-main {
            font-weight: 800;
            color: #172033;
        }

        .outstanding {
            color: #e04444;
            font-weight: 800;
        }

        .emi {
            color: #d97706;
            font-weight: 700;
        }

        .actions {
            display: flex;
            gap: 8px;
        }

        .edit-btn,
        .delete-btn {
            padding: 8px 12px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 700;
        }

        .edit-btn {
            background: #eaf5ff;
            color: #1671b9;
        }

        .delete-btn {
            background: #fff0f0;
            color: #d04444;
        }

        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #718096;
        }

        .empty-icon {
            font-size: 40px;
            margin-bottom: 12px;
        }

        /* RESPONSIVE */

        @media (max-width: 1100px) {

            .summary-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .form-grid {
                grid-template-columns: repeat(2, 1fr);
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

            .summary-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 650px) {

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

            .summary-grid {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .page-header {
                display: block;
            }
        }


        .page-header .header-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 12px;
            padding: 8px 12px;
            border-radius: 999px;
            background: #eaf5ff;
            color: #1671b9;
            font-size: 12px;
            font-weight: 700;
        }

        .summary-card {
            transition: transform .2s ease, box-shadow .2s ease;
        }

        .summary-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(24,39,75,.09);
        }

        .card {
            transition: box-shadow .2s ease;
        }

        .table-container {
            box-shadow: 0 2px 10px rgba(24,39,75,.025);
        }

        .actions a {
            transition: .2s ease;
        }

        .actions a:hover {
            transform: translateY(-1px);
        }

        @media (max-width: 1100px) {
            .page-header h1 {
                font-size: 30px;
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
    <!-- SHARED DASHBOARD SIDEBAR -->
    <?php include "sidebar.php"; ?>

    <!-- MAIN CONTENT -->


    <main class="main-content">

        <div class="page-header">

            <div>
                <h1>Debt & Loans</h1>

                <p>
                    Track loans, credit cards, EMIs and outstanding balances.
                </p>
                <div class="header-badge">Debt Management</div>
            </div>

        </div>


        <?php if ($message): ?>

            <div class="alert success">
                <?= htmlspecialchars($message) ?>
            </div>

        <?php endif; ?>


        <?php if ($error): ?>

            <div class="alert error">
                <?= htmlspecialchars($error) ?>
            </div>

        <?php endif; ?>


        <!-- SUMMARY -->

        <div class="summary-grid">

            <div class="summary-card">

                <div class="summary-label">
                    Total Principal
                </div>

                <div class="summary-value">
                    <?= money($total_debt) ?>
                </div>

            </div>


            <div class="summary-card outstanding">

                <div class="summary-label">
                    Outstanding
                </div>

                <div class="summary-value">
                    <?= money($total_outstanding) ?>
                </div>

            </div>


            <div class="summary-card emi">

                <div class="summary-label">
                    Total Monthly EMI
                </div>

                <div class="summary-value">
                    <?= money($total_emi) ?>
                </div>

            </div>


            <div class="summary-card count">

                <div class="summary-label">
                    Active Debts
                </div>

                <div class="summary-value">
                    <?= number_format($total_loans) ?>
                </div>

            </div>

        </div>


        <!-- ADD / EDIT FORM -->

        <div class="card">

            <h2>
                <?= $edit_data ? "Edit Debt / Loan" : "Add Debt / Loan" ?>
            </h2>

            <div class="card-subtitle">
                Keep your borrowing and repayment information organized.
            </div>


            <form method="POST">

                <input
                    type="hidden"
                    name="id"
                    value="<?= $edit_data ? (int)$edit_data['id'] : 0 ?>"
                >


                <div class="form-grid">

                    <div class="form-group">

                        <label>Debt / Loan Name</label>

                        <input
                            type="text"
                            name="name"
                            placeholder="e.g. SBI Personal Loan"
                            value="<?= htmlspecialchars($edit_data['name'] ?? '') ?>"
                            required
                        >

                    </div>


                    <div class="form-group">

                        <label>Type</label>

                        <select name="type" required>

                            <option value="">Select type</option>

                            <option
                                value="loan"
                                <?= (($edit_data['type'] ?? '') === 'loan') ? 'selected' : '' ?>
                            >
                                Loan
                            </option>

                            <option
                                value="credit_card"
                                <?= (($edit_data['type'] ?? '') === 'credit_card') ? 'selected' : '' ?>
                            >
                                Credit Card
                            </option>

                            <option
                                value="emi"
                                <?= (($edit_data['type'] ?? '') === 'emi') ? 'selected' : '' ?>
                            >
                                EMI
                            </option>

                            <option
                                value="other"
                                <?= (($edit_data['type'] ?? '') === 'other') ? 'selected' : '' ?>
                            >
                                Other
                            </option>

                        </select>

                    </div>


                    <div class="form-group">

                        <label>Principal Amount</label>

                        <input
                            type="number"
                            name="principal_amount"
                            step="0.01"
                            min="0"
                            placeholder="e.g. 100000"
                            value="<?= htmlspecialchars($edit_data['principal_amount'] ?? '') ?>"
                            required
                        >

                    </div>


                    <div class="form-group">

                        <label>Outstanding Amount</label>

                        <input
                            type="number"
                            name="outstanding_amount"
                            step="0.01"
                            min="0"
                            placeholder="e.g. 75000"
                            value="<?= htmlspecialchars($edit_data['outstanding_amount'] ?? '') ?>"
                        >

                    </div>


                    <div class="form-group">

                        <label>Interest Rate (%)</label>

                        <input
                            type="number"
                            name="interest_rate"
                            step="0.01"
                            min="0"
                            placeholder="e.g. 10.5"
                            value="<?= htmlspecialchars($edit_data['interest_rate'] ?? '0') ?>"
                        >

                    </div>


                    <div class="form-group">

                        <label>Monthly EMI</label>

                        <input
                            type="number"
                            name="emi_amount"
                            step="0.01"
                            min="0"
                            placeholder="e.g. 5000"
                            value="<?= htmlspecialchars($edit_data['emi_amount'] ?? '0') ?>"
                        >

                    </div>


                    <div class="form-group">

                        <label>Due Date</label>

                        <input
                            type="date"
                            name="due_date"
                            value="<?= htmlspecialchars($edit_data['due_date'] ?? '') ?>"
                        >

                    </div>


                    <div class="form-group">

                        <label>Total Installments</label>

                        <input
                            type="number"
                            name="total_installments"
                            min="0"
                            placeholder="e.g. 24"
                            value="<?= htmlspecialchars($edit_data['total_installments'] ?? '') ?>"
                        >

                    </div>


                    <div class="form-group">

                        <label>Remaining Installments</label>

                        <input
                            type="number"
                            name="remaining_installments"
                            min="0"
                            placeholder="e.g. 18"
                            value="<?= htmlspecialchars($edit_data['remaining_installments'] ?? '') ?>"
                        >

                    </div>


                    <div class="form-group full">

                        <label>Notes</label>

                        <textarea
                            name="notes"
                            placeholder="Optional notes about this debt or loan..."
                        ><?= htmlspecialchars($edit_data['notes'] ?? '') ?></textarea>

                    </div>

                </div>


                <div class="form-actions">

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        <?= $edit_data ? "Update Debt / Loan" : "Add Debt / Loan" ?>
                    </button>


                    <?php if ($edit_data): ?>

                        <a
                            href="debts_loans.php"
                            class="btn btn-secondary"
                        >
                            Cancel
                        </a>

                    <?php endif; ?>

                </div>

            </form>

        </div>


        <!-- DEBT LIST -->

        <div class="card">

            <h2>Your Debts & Loans</h2>

            <div class="card-subtitle">
                Review all your current borrowing and repayment obligations.
            </div>


            <?php if (count($debts) > 0): ?>

                <div class="table-container">

                    <table>

                        <thead>

                            <tr>

                                <th>Name</th>

                                <th>Type</th>

                                <th>Principal</th>

                                <th>Outstanding</th>

                                <th>Interest</th>

                                <th>Monthly EMI</th>

                                <th>Due Date</th>

                                <th>Installments</th>

                                <th>Actions</th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php foreach ($debts as $debt): ?>

                            <tr>

                                <td>
                                    <div class="loan-name">
                                        <?= htmlspecialchars($debt['name']) ?>
                                    </div>
                                </td>


                                <td>

                                    <span class="type-badge">
                                        <?= htmlspecialchars(typeLabel($debt['type'])) ?>
                                    </span>

                                </td>


                                <td>

                                    <span class="amount-main">
                                        <?= money($debt['principal_amount']) ?>
                                    </span>

                                </td>


                                <td>

                                    <span class="outstanding">
                                        <?= money($debt['outstanding_amount']) ?>
                                    </span>

                                </td>


                                <td>

                                    <?= number_format((float)$debt['interest_rate'], 2) ?>%

                                </td>


                                <td>

                                    <span class="emi">
                                        <?= money($debt['emi_amount']) ?>
                                    </span>

                                </td>


                                <td>

                                    <?= !empty($debt['due_date'])
                                        ? date("d M Y", strtotime($debt['due_date']))
                                        : "—"
                                    ?>

                                </td>


                                <td>

                                    <?php

                                    if (
                                        $debt['remaining_installments'] !== null
                                        &&
                                        $debt['total_installments'] !== null
                                    ) {

                                        echo (int)$debt['remaining_installments']
                                            . " / "
                                            . (int)$debt['total_installments'];

                                    } else {

                                        echo "—";

                                    }

                                    ?>

                                </td>


                                <td>

                                    <div class="actions">

                                        <a
                                            href="debts_loans.php?edit=<?= (int)$debt['id'] ?>"
                                            class="edit-btn"
                                        >
                                            Edit
                                        </a>


                                        <a
                                            href="debts_loans.php?delete=<?= (int)$debt['id'] ?>"
                                            class="delete-btn"
                                            onclick="return confirm('Delete this debt / loan? This action cannot be undone.');"
                                        >
                                            Delete
                                        </a>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="empty-state">

                    <div class="empty-icon">💰</div>

                    <h3>No debts or loans yet</h3>

                    <p>
                        Add your first loan, EMI or credit card above.
                    </p>

                </div>

            <?php endif; ?>

        </div>

    </main>

</div>

</body>
</html>