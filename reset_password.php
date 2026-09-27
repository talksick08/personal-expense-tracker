<?php

session_start();
require_once "db.php";

$message = "";
$success = "";

if (!isset($_SESSION["reset_user_id"])) {

    header("Location: forgot_password.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if (empty($password) || empty($confirm_password)) {

        $message = "Please fill in both password fields.";

    } elseif (strlen($password) < 6) {

        $message = "Password must contain at least 6 characters.";

    } elseif ($password !== $confirm_password) {

        $message = "Passwords do not match.";

    } else {

        $user_id = $_SESSION["reset_user_id"];

        $hashed_password =
            password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare(
            "UPDATE users SET password = ? WHERE id = ?"
        );

        $stmt->bind_param(
            "si",
            $hashed_password,
            $user_id
        );

        if ($stmt->execute()) {

            unset($_SESSION["reset_user_id"]);
            unset($_SESSION["reset_email"]);

            header("Location: login.php");
            exit;

        } else {

            $message =
                "Unable to reset the password. Please try again.";
        }

        $stmt->close();
    }
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

<title>Reset Password | Personal Expense Tracker</title>

<style>

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {

    min-height: 100vh;

    display: flex;

    align-items: center;

    justify-content: center;

    padding: 20px;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    background:
        radial-gradient(
            circle at top left,
            #1e3a8a,
            transparent 35%
        ),
        radial-gradient(
            circle at bottom right,
            #312e81,
            transparent 35%
        ),
        #070b16;

    color: white;
}

.card {

    width: 100%;

    max-width: 430px;

    padding: 42px;

    border-radius: 24px;

    background:
        rgba(15, 23, 42, 0.88);

    border:
        1px solid
        rgba(255,255,255,0.1);

    box-shadow:
        0 30px 70px
        rgba(0,0,0,0.45);
}

.icon {

    width: 55px;
    height: 55px;

    margin-bottom: 22px;

    border-radius: 16px;

    display: flex;

    align-items: center;

    justify-content: center;

    background:
        linear-gradient(
            135deg,
            #2563eb,
            #4f46e5
        );

    font-size: 24px;
}

h1 {

    font-size: 30px;

    margin-bottom: 10px;
}

.subtitle {

    color: #94a3b8;

    font-size: 14px;

    line-height: 1.6;

    margin-bottom: 28px;
}

.message {

    padding: 12px;

    border-radius: 10px;

    margin-bottom: 18px;

    background:
        rgba(239,68,68,0.1);

    border:
        1px solid
        rgba(239,68,68,0.25);

    color: #fca5a5;

    font-size: 13px;
}

label {

    display: block;

    margin-bottom: 8px;

    font-size: 13px;

    font-weight: 600;
}

input {

    width: 100%;

    height: 52px;

    padding: 0 15px;

    border-radius: 12px;

    border:
        1px solid
        rgba(148,163,184,0.18);

    outline: none;

    background:
        rgba(255,255,255,0.05);

    color: white;

    font-size: 14px;

    margin-bottom: 18px;
}

input:focus {

    border-color: #3b82f6;

    box-shadow:
        0 0 0 4px
        rgba(59,130,246,0.1);
}

button {

    width: 100%;

    height: 52px;

    border: none;

    border-radius: 12px;

    background:
        linear-gradient(
            135deg,
            #2563eb,
            #4f46e5
        );

    color: white;

    font-weight: 700;

    cursor: pointer;
}

button:hover {

    transform:
        translateY(-1px);
}

.back {

    display: block;

    text-align: center;

    margin-top: 22px;

    color: #60a5fa;

    text-decoration: none;

    font-size: 13px;
}

</style>

</head>

<body>

<div class="card">

    <div class="icon">
        🔐
    </div>

    <h1>
        Reset password
    </h1>

    <p class="subtitle">
        Create a new password for your account.
    </p>

    <?php if (!empty($message)): ?>

        <div class="message">

            <?php
            echo htmlspecialchars($message);
            ?>

        </div>

    <?php endif; ?>


    <form method="POST">

        <label for="password">
            New password
        </label>

        <input
            type="password"
            id="password"
            name="password"
            placeholder="Minimum 6 characters"
            minlength="6"
            required
        >


        <label for="confirm_password">
            Confirm password
        </label>

        <input
            type="password"
            id="confirm_password"
            name="confirm_password"
            placeholder="Enter password again"
            minlength="6"
            required
        >


        <button type="submit">
            Reset Password
        </button>

    </form>


    <a
        href="login.php"
        class="back"
    >
        ← Back to Login
    </a>

</div>

</body>

</html>