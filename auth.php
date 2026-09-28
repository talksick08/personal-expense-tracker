<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "db.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$user_id = (int) $_SESSION["user_id"];
$user_name = $_SESSION["user_name"] ?? "User";
$user_email = $_SESSION["user_email"] ?? "";

$is_admin = false;
if (isset($_SESSION["is_admin"])) {
    $is_admin = (bool) $_SESSION["is_admin"];
} else {
    $stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($r = $res->fetch_assoc()) {
            $is_admin = ((int) ($r["is_admin"] ?? 0) === 1);
            $_SESSION["is_admin"] = $is_admin ? 1 : 0;
        }
        $stmt->close();
    }
}

?>