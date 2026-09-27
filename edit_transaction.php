<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: transactions.php");
    exit;
}

$transaction_id = (int)$_GET['id'];

$message = "";
$message_type = "";


/* =========================
   GET TRANSACTION
========================= */

$stmt = $conn->prepare(
    "SELECT *
     FROM transactions
     WHERE id = ? AND user_id = ?"
);

$stmt->bind_param("ii", $transaction_id, $user_id);

$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();

    header("Location: transactions.php");
    exit;
}

$transaction = $result->fetch_assoc();

$stmt->close();


/* =========================
   GET ACCOUNTS
========================= */

$account_stmt = $conn->prepare(
    "SELECT id, name, type
     FROM accounts
     WHERE user_id = ?
     ORDER BY name ASC"
);

$account_stmt->bind_param("i", $user_id);

$account_stmt->execute();

$accounts_result = $account_stmt->get_result();

$accounts = [];

while ($account = $accounts_result->fetch_assoc()) {
    $accounts[] = $account;
}

$account_stmt->close();


/* =========================
   UPDATE TRANSACTION
========================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

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

    } elseif (!is_numeric($amount) || $amount <= 0) {

        $message = "Please enter a valid amount.";
        $message_type = "error";

    } elseif ($category === "" || $transaction_date === "") {

        $message = "Please fill all required fields.";
        $message_type = "error";

    } else {

        /* Verify account */

        if ($account_id !== null) {

            $check = $conn->prepare(
                "SELECT id
                 FROM accounts
                 WHERE id = ? AND user_id = ?"
            );

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


        /* Update transaction */

        $update = $conn->prepare(
            "UPDATE transactions
             SET
                type = ?,
                amount = ?,
                category = ?,
                description = ?,
                account_id = ?,
                transaction_date = ?
             WHERE id = ? AND user_id = ?"
        );

        $update->bind_param(
            "sdssisis",
            $type,
            $amount,
            $category,
            $description,
            $account_id,
            $transaction_date,
            $transaction_id,
            $user_id
        );


        if ($update->execute()) {

            $update->close();

            header("Location: transactions.php");
            exit;

        } else {

            $message = "Unable to update transaction.";
            $message_type = "error";
        }

        $update->close();
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Edit Transaction | Expense Tracker</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    background: #f5f7fb;
    color: #1f2937;
}

.main {
    max-width: 900px;
    margin: 50px auto;
    padding: 20px;
}

.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
}

.topbar h1 {
    margin: 0;
    font-size: 32px;
}

.back-btn {
    background: white;
    border: 1px solid #e2e6ec;
    padding: 12px 18px;
    border-radius: 10px;
    text-decoration: none;
    color: #374151;
}

.card {
    background: white;
    padding: 30px;
    border-radius: 18px;
    box-shadow: 0 5px 25px rgba(0,0,0,0.05);
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.field label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
}

.field input,
.field select {
    width: 100%;
    padding: 14px;
    border: 1px solid #dfe3ea;
    border-radius: 10px;
    font-size: 15px;
}

.full {
    grid-column: 1 / -1;
}

.save-btn {
    margin-top: 25px;
    background: #27a9e8;
    color: white;
    border: none;
    padding: 14px 25px;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
}

.cancel-btn {
    display: inline-block;
    margin-left: 10px;
    padding: 13px 20px;
    border-radius: 10px;
    text-decoration: none;
    background: #f1f3f5;
    color: #374151;
}

.alert {
    padding: 15px;
    border-radius: 10px;
    margin-bottom: 20px;
}

.success {
    background: #eaf8ef;
    color: #218044;
}

.error {
    background: #fdecec;
    color: #b42318;
}

@media(max-width:700px) {

    .form-grid {
        grid-template-columns: 1fr;
    }

    .full {
        grid-column: auto;
    }

    .topbar {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }

}

</style>

</head>

<body>

<div class="main">

    <div class="topbar">

        <h1>Edit Transaction</h1>

        <a href="transactions.php" class="back-btn">
            ← Transactions
        </a>

    </div>


    <?php if ($message !== ""): ?>

        <div class="alert <?php echo $message_type; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>

    <?php endif; ?>


    <div class="card">

        <h2>Update Transaction</h2>

        <p style="color:#8b95a7;">
            Modify the details of this transaction.
        </p>


        <form method="POST">

            <div class="form-grid">


                <div class="field">

                    <label>Transaction Type</label>

                    <select name="type" required>

                        <option
                            value="expense"
                            <?php
                            echo $transaction["type"] === "expense"
                                ? "selected"
                                : "";
                            ?>
                        >
                            Expense
                        </option>

                        <option
                            value="income"
                            <?php
                            echo $transaction["type"] === "income"
                                ? "selected"
                                : "";
                            ?>
                        >
                            Income
                        </option>

                    </select>

                </div>


                <div class="field">

                    <label>Amount</label>

                    <input
                        type="number"
                        name="amount"
                        step="0.01"
                        min="0.01"
                        value="<?php echo htmlspecialchars($transaction["amount"]); ?>"
                        required
                    >

                </div>


                <div class="field">

                    <label>Category</label>

                    <input
                        type="text"
                        name="category"
                        value="<?php echo htmlspecialchars($transaction["category"]); ?>"
                        required
                    >

                </div>


                <div class="field">

                    <label>Account</label>

                    <select name="account_id">

                        <option value="">
                            No Account
                        </option>

                        <?php foreach ($accounts as $account): ?>

                            <option
                                value="<?php echo $account["id"]; ?>"
                                <?php
                                echo (
                                    $transaction["account_id"] == $account["id"]
                                )
                                    ? "selected"
                                    : "";
                                ?>
                            >

                                <?php
                                echo htmlspecialchars($account["name"]);
                                echo " (" . htmlspecialchars($account["type"]) . ")";
                                ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="field">

                    <label>Date</label>

                    <input
                        type="date"
                        name="transaction_date"
                        value="<?php echo htmlspecialchars($transaction["transaction_date"]); ?>"
                        required
                    >

                </div>


                <div class="field">

                    <label>Description</label>

                    <input
                        type="text"
                        name="description"
                        value="<?php echo htmlspecialchars($transaction["description"] ?? ""); ?>"
                        placeholder="Add a note..."
                    >

                </div>

            </div>


            <button type="submit" class="save-btn">
                Save Changes
            </button>

            <a href="transactions.php" class="cancel-btn">
                Cancel
            </a>

        </form>

    </div>

</div>

</body>

</html>