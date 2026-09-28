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
   ADD MEDICAL EXPENSE
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['add_medical_expense'])) {

    $expense_type = $_POST['expense_type'] ?? '';
    $provider = trim($_POST['provider'] ?? '');
    $amount = $_POST['amount'] ?? '';
    $expense_date = $_POST['expense_date'] ?? '';
    $insurance_claimable = isset($_POST['insurance_claimable']) ? 1 : 0;
    $reimbursement_amount = $_POST['reimbursement_amount'] ?? 0;
    $notes = trim($_POST['notes'] ?? '');

    if ($expense_type === '' || $amount === '' || $expense_date === '') {
        $error = "Please fill in all required fields.";
    } elseif (!is_numeric($amount) || $amount <= 0) {
        $error = "Please enter a valid amount.";
    } else {

        $stmt = $conn->prepare("
            INSERT INTO medical_expenses
            (
                user_id,
                transaction_id,
                expense_type,
                provider,
                amount,
                expense_date,
                insurance_claimable,
                reimbursement_amount,
                notes
            )
            VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "issdsids",
            $user_id,
            $expense_type,
            $provider,
            $amount,
            $expense_date,
            $insurance_claimable,
            $reimbursement_amount,
            $notes
        );

        if ($stmt->execute()) {
            $message = "Medical expense added successfully.";
        } else {
            $error = "Unable to add medical expense.";
        }

        $stmt->close();
    }
}

/* =========================
   DELETE MEDICAL EXPENSE
========================= */
if (isset($_GET['delete'])) {

    $delete_id = intval($_GET['delete']);

    if ($delete_id > 0) {

        $stmt = $conn->prepare("
            DELETE FROM medical_expenses
            WHERE id = ? AND user_id = ?
        ");

        $stmt->bind_param("ii", $delete_id, $user_id);
        $stmt->execute();
        $stmt->close();

        header("Location: medical_expenses.php?deleted=1");
        exit;
    }
}

if (isset($_GET['deleted'])) {
    $message = "Medical expense deleted successfully.";
}

/* =========================
   SUMMARY
========================= */

$total_expenses = 0;
$total_claimable = 0;
$total_reimbursement = 0;
$total_records = 0;

$stmt = $conn->prepare("
    SELECT
        COALESCE(SUM(amount), 0),
        COALESCE(SUM(CASE WHEN insurance_claimable = 1 THEN amount ELSE 0 END), 0),
        COALESCE(SUM(reimbursement_amount), 0),
        COUNT(*)
    FROM medical_expenses
    WHERE user_id = ?
");

$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result(
    $total_expenses,
    $total_claimable,
    $total_reimbursement,
    $total_records
);
$stmt->fetch();
$stmt->close();

/* =========================
   MEDICAL EXPENSE HISTORY
========================= */

$expenses = [];

$stmt = $conn->prepare("
    SELECT
        id,
        expense_type,
        provider,
        amount,
        expense_date,
        insurance_claimable,
        reimbursement_amount,
        notes
    FROM medical_expenses
    WHERE user_id = ?
    ORDER BY expense_date DESC, id DESC
");

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $expenses[] = $row;
}

$stmt->close();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Medical Expenses - Expense Tracker</title>

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
           SIDEBAR
        ========================= */

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
            color: white;
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
            color: white;
        }

        .sidebar nav a.active {
            background: linear-gradient(135deg,#16b5f4,#168ce8);
            color: white;
            box-shadow: 0 7px 18px rgba(22,140,232,.25);
        }

        .nav-icon {
            width: 30px;
            font-size: 17px;
        }

        /* =========================
           MAIN
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
            margin-bottom: 28px;
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
            background: white;
            border: 1px solid #e6ebf2;
            padding: 12px 18px;
            border-radius: 12px;
            color: #667085;
            font-size: 14px;
            font-weight: 600;
        }

        /* =========================
           ALERTS
        ========================= */

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

        .danger-alert {
            background: #fff0f0;
            border: 1px solid #f2c3c3;
            color: #b42318;
        }

        /* =========================
           SUMMARY
        ========================= */

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 24px;
        }

        .summary-card {
            background: white;
            border: 1px solid #e6ebf2;
            border-radius: 18px;
            padding: 23px;
            box-shadow: 0 8px 25px rgba(24,39,75,.06);
        }

        .summary-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .summary-label {
            color: #667085;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .summary-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
            background: #eef8ff;
        }

        .summary-value {
            font-size: 25px;
            font-weight: 800;
            color: #172033;
        }

        .summary-note {
            margin-top: 7px;
            font-size: 12px;
            color: #98a2b3;
        }

        .red {
            color: #e04444;
        }

        .green {
            color: #159447;
        }

        .blue {
            color: #168ce8;
        }

        .orange {
            color: #e58a18;
        }

        /* =========================
           CONTENT GRID
        ========================= */

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1.6fr;
            gap: 24px;
            align-items: start;
        }

        .card {
            background: white;
            border: 1px solid #e6ebf2;
            border-radius: 18px;
            padding: 28px;
            box-shadow: 0 8px 25px rgba(24,39,75,.06);
            margin-bottom: 24px;
        }

        .card h2 {
            font-size: 21px;
            color: #172033;
            margin-bottom: 6px;
        }

        .card-subtitle {
            color: #8a94a6;
            font-size: 13px;
            margin-bottom: 24px;
        }

        /* =========================
           FORM
        ========================= */

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0,1fr));
            gap: 18px;
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
            font-size: 13px;
            font-weight: 700;
        }

        .required {
            color: #e04444;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            border: 1px solid #d7dee8;
            background: white;
            color: #172033;
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 14px;
            font-family: inherit;
            outline: none;
            transition: .2s;
        }

        .form-group input,
        .form-group select {
            height: 46px;
        }

        .form-group textarea {
            min-height: 95px;
            resize: vertical;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #18aef1;
            box-shadow: 0 0 0 3px rgba(24,174,241,.12);
        }

        .checkbox-box {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f8fafc;
            border: 1px solid #e6ebf2;
            border-radius: 10px;
            padding: 12px 14px;
            min-height: 46px;
        }

        .checkbox-box input {
            width: 18px;
            height: 18px;
        }

        .checkbox-box label {
            margin: 0;
            font-size: 13px;
            cursor: pointer;
        }

        .btn-primary {
            border: none;
            background: linear-gradient(135deg,#16b5f4,#168ce8);
            color: white;
            padding: 13px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 7px 18px rgba(22,140,232,.22);
            transition: .2s;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
        }

        .form-actions {
            grid-column: 1 / -1;
            margin-top: 4px;
        }

        /* =========================
           TABLE
        ========================= */

        .table-container {
            width: 100%;
            overflow-x: auto;
            border: 1px solid #e6ebf2;
            border-radius: 12px;
        }

        table {
            width: 100%;
            min-width: 850px;
            border-collapse: collapse;
            background: white;
        }

        thead {
            background: #f7f9fc;
        }

        th {
            padding: 14px 15px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: #667085;
            border-bottom: 1px solid #e6ebf2;
        }

        td {
            padding: 15px;
            font-size: 13px;
            color: #344054;
            border-bottom: 1px solid #edf0f4;
        }

        tbody tr:hover {
            background: #fafcff;
        }

        .type-badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            background: #eef8ff;
            color: #168ce8;
            font-size: 11px;
            font-weight: 700;
            text-transform: capitalize;
        }

        .claim-yes {
            color: #159447;
            font-weight: 700;
        }

        .claim-no {
            color: #98a2b3;
            font-weight: 600;
        }

        .amount {
            font-weight: 800;
            color: #172033;
        }

        .delete-btn {
            display: inline-block;
            padding: 7px 11px;
            border-radius: 7px;
            background: #fff0f0;
            color: #d92d20;
            text-decoration: none;
            font-size: 11px;
            font-weight: 700;
        }

        .delete-btn:hover {
            background: #ffe0e0;
        }

        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #98a2b3;
        }

        .empty-icon {
            font-size: 42px;
            margin-bottom: 12px;
        }

        /* =========================
           RESPONSIVE
        ========================= */

        @media (max-width: 1200px) {

            .summary-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .content-grid {
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

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-group.full,
            .form-actions {
                grid-column: auto;
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

            .page-header {
                display: block;
            }

            .date-badge {
                display: inline-block;
                margin-top: 15px;
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

        .delete-btn {
            transition: .2s ease;
        }

        .delete-btn:hover {
            transform: translateY(-1px);
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



    <!-- =========================
         MAIN CONTENT
    ========================= -->

    <main class="main-content">

        <div class="page-header">

            <div>
                <h1>Medical Expenses</h1>
                <p>Track healthcare costs, insurance claims and reimbursements.</p>
                <div class="header-badge">Healthcare Finance</div>
            </div>

            <div class="date-badge">
                📅 <?php echo date("d M Y"); ?>
            </div>

        </div>


        <!-- ALERT -->

        <?php if ($message !== ""): ?>

            <div class="alert success">
                ✓ <?php echo htmlspecialchars($message); ?>
            </div>

        <?php endif; ?>


        <?php if ($error !== ""): ?>

            <div class="alert danger-alert">
                ⚠ <?php echo htmlspecialchars($error); ?>
            </div>

        <?php endif; ?>


        <!-- =========================
             SUMMARY CARDS
        ========================= -->

        <div class="summary-grid">

            <div class="summary-card">

                <div class="summary-top">
                    <div class="summary-label">Total Medical Expenses</div>
                    <div class="summary-icon">🏥</div>
                </div>

                <div class="summary-value red">
                    ₹<?php echo number_format($total_expenses, 2); ?>
                </div>

                <div class="summary-note">
                    Total healthcare spending
                </div>

            </div>


            <div class="summary-card">

                <div class="summary-top">
                    <div class="summary-label">Insurance Claimable</div>
                    <div class="summary-icon">🛡️</div>
                </div>

                <div class="summary-value blue">
                    ₹<?php echo number_format($total_claimable, 2); ?>
                </div>

                <div class="summary-note">
                    Expenses marked for insurance
                </div>

            </div>


            <div class="summary-card">

                <div class="summary-top">
                    <div class="summary-label">Reimbursement</div>
                    <div class="summary-icon">💰</div>
                </div>

                <div class="summary-value green">
                    ₹<?php echo number_format($total_reimbursement, 2); ?>
                </div>

                <div class="summary-note">
                    Reimbursement amount recorded
                </div>

            </div>


            <div class="summary-card">

                <div class="summary-top">
                    <div class="summary-label">Medical Records</div>
                    <div class="summary-icon">📋</div>
                </div>

                <div class="summary-value orange">
                    <?php echo $total_records; ?>
                </div>

                <div class="summary-note">
                    Total medical expense entries
                </div>

            </div>

        </div>


        <!-- =========================
             ADD + HISTORY
        ========================= -->

        <div class="content-grid">


            <!-- ADD EXPENSE -->

            <div class="card">

                <h2>Add Medical Expense</h2>

                <div class="card-subtitle">
                    Record doctor visits, medicines, tests, hospital bills and insurance-related expenses.
                </div>

                <form method="POST">

                    <div class="form-grid">


                        <div class="form-group">

                            <label>
                                Expense Type <span class="required">*</span>
                            </label>

                            <select name="expense_type" required>

                                <option value="">Select expense type</option>

                                <option value="doctor">Doctor</option>

                                <option value="medicine">Medicine</option>

                                <option value="test">Medical Test</option>

                                <option value="hospital">Hospital</option>

                                <option value="insurance">Insurance</option>

                                <option value="other">Other</option>

                            </select>

                        </div>


                        <div class="form-group">

                            <label>
                                Provider / Hospital
                            </label>

                            <input
                                type="text"
                                name="provider"
                                placeholder="e.g. Apollo Hospital"
                            >

                        </div>


                        <div class="form-group">

                            <label>
                                Amount <span class="required">*</span>
                            </label>

                            <input
                                type="number"
                                name="amount"
                                step="0.01"
                                min="0"
                                placeholder="e.g. 1500"
                                required
                            >

                        </div>


                        <div class="form-group">

                            <label>
                                Expense Date <span class="required">*</span>
                            </label>

                            <input
                                type="date"
                                name="expense_date"
                                value="<?php echo date('Y-m-d'); ?>"
                                required
                            >

                        </div>


                        <div class="form-group full">

                            <div class="checkbox-box">

                                <input
                                    type="checkbox"
                                    id="insurance_claimable"
                                    name="insurance_claimable"
                                    value="1"
                                >

                                <label for="insurance_claimable">
                                    This expense is claimable through insurance
                                </label>

                            </div>

                        </div>


                        <div class="form-group">

                            <label>
                                Reimbursement Amount
                            </label>

                            <input
                                type="number"
                                name="reimbursement_amount"
                                step="0.01"
                                min="0"
                                value="0"
                                placeholder="e.g. 1000"
                            >

                        </div>


                        <div class="form-group full">

                            <label>
                                Notes
                            </label>

                            <textarea
                                name="notes"
                                placeholder="Add prescription details, diagnosis notes, claim information, etc."
                            ></textarea>

                        </div>


                        <div class="form-actions">

                            <button
                                type="submit"
                                name="add_medical_expense"
                                class="btn-primary"
                            >
                                + Add Medical Expense
                            </button>

                        </div>

                    </div>

                </form>

            </div>


            <!-- RECENT EXPENSES -->

            <div class="card">

                <h2>Medical Expense History</h2>

                <div class="card-subtitle">
                    Your recorded healthcare expenses and reimbursement details.
                </div>


                <?php if (count($expenses) === 0): ?>

                    <div class="empty-state">

                        <div class="empty-icon">🏥</div>

                        <strong>No medical expenses yet</strong>

                        <p>Add your first medical expense using the form.</p>

                    </div>

                <?php else: ?>

                    <div class="table-container">

                        <table>

                            <thead>

                                <tr>

                                    <th>Date</th>

                                    <th>Type</th>

                                    <th>Provider</th>

                                    <th>Amount</th>

                                    <th>Insurance</th>

                                    <th>Reimbursement</th>

                                    <th>Action</th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($expenses as $expense): ?>

                                    <tr>

                                        <td>
                                            <?php
                                            echo date(
                                                "d M Y",
                                                strtotime($expense['expense_date'])
                                            );
                                            ?>
                                        </td>

                                        <td>

                                            <span class="type-badge">
                                                <?php
                                                echo htmlspecialchars(
                                                    ucfirst($expense['expense_type'])
                                                );
                                                ?>
                                            </span>

                                        </td>

                                        <td>
                                            <?php
                                            echo $expense['provider']
                                                ? htmlspecialchars($expense['provider'])
                                                : "—";
                                            ?>
                                        </td>

                                        <td class="amount">
                                            ₹<?php
                                            echo number_format(
                                                $expense['amount'],
                                                2
                                            );
                                            ?>
                                        </td>

                                        <td>

                                            <?php if ($expense['insurance_claimable']): ?>

                                                <span class="claim-yes">
                                                    ✓ Claimable
                                                </span>

                                            <?php else: ?>

                                                <span class="claim-no">
                                                    No
                                                </span>

                                            <?php endif; ?>

                                        </td>

                                        <td class="green">

                                            ₹<?php
                                            echo number_format(
                                                $expense['reimbursement_amount'],
                                                2
                                            );
                                            ?>

                                        </td>

                                        <td>

                                            <a
                                                href="medical_expenses.php?delete=<?php echo $expense['id']; ?>"
                                                class="delete-btn"
                                                onclick="return confirm('Delete this medical expense? This action cannot be undone.');"
                                            >
                                                Delete
                                            </a>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </main>

</div>

</body>

</html>