<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once "db.php";

/* Check login */
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

/* Check transaction ID */
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: transactions.php");
    exit;
}

$transaction_id = (int) $_GET['id'];

/* Delete only the logged-in user's transaction */
$stmt = $conn->prepare(
    "DELETE FROM transactions
     WHERE id = ? AND user_id = ?"
);

if (!$stmt) {
    die("Database error: " . $conn->error);
}

$stmt->bind_param(
    "ii",
    $transaction_id,
    $user_id
);

if (!$stmt->execute()) {
    die("Delete failed: " . $stmt->error);
}

$stmt->close();

/* Go back to transactions */
header("Location: transactions.php");
exit;

?>