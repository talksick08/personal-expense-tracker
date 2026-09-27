<?php

require_once "db.php";

$message = "";
$message_type = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $name = trim($_POST["name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($name === "" || $email === "" || $password === "") {

        $message = "Please fill in all fields.";
        $message_type = "error";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = "Please enter a valid email address.";
        $message_type = "error";

    } elseif (strlen($password) < 6) {

        $message = "Password must contain at least 6 characters.";
        $message_type = "error";

    } else {

        // Check whether email already exists
        $check = $conn->prepare(
            "SELECT id FROM users WHERE email = ?"
        );

        if (!$check) {

            $message = "Something went wrong. Please try again.";
            $message_type = "error";

        } else {

            $check->bind_param("s", $email);
            $check->execute();

            $result = $check->get_result();

            if ($result->num_rows > 0) {

                $message = "An account with this email already exists.";
                $message_type = "error";

            } else {

                $hashed_password =
                    password_hash($password, PASSWORD_DEFAULT);

                $stmt = $conn->prepare(
                    "INSERT INTO users (name, email, password)
                     VALUES (?, ?, ?)"
                );

                if (!$stmt) {

                    $message = "Unable to create account.";
                    $message_type = "error";

                } else {

                    $stmt->bind_param(
                        "sss",
                        $name,
                        $email,
                        $hashed_password
                    );

                    if ($stmt->execute()) {

                        $message = "Account created successfully!";
                        $message_type = "success";

                        // Clear form values after successful registration
                        $name = "";
                        $email = "";

                    } else {

                        $message = "Unable to create account. Please try again.";
                        $message_type = "error";
                    }

                    $stmt->close();
                }
            }

            $check->close();
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Create Account | Personal Expense Tracker</title>

    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            font-family: Arial, Helvetica, sans-serif;

            background:
                radial-gradient(circle at top left, #26364d 0%, transparent 38%),
                radial-gradient(circle at bottom right, #123b35 0%, transparent 35%),
                #070b12;

            color: #ffffff;

            display: flex;
            align-items: center;
            justify-content: center;

            padding: 30px;
        }

        .container {
            width: 100%;
            max-width: 1050px;
            min-height: 620px;

            display: grid;
            grid-template-columns: 1fr 1fr;

            background: rgba(16, 22, 32, 0.92);

            border: 1px solid rgba(255, 255, 255, 0.09);

            border-radius: 28px;

            overflow: hidden;

            box-shadow:
                0 30px 80px rgba(0, 0, 0, 0.45);
        }

        /* LEFT SIDE */

        .left {
            position: relative;

            padding: 55px;

            display: flex;
            flex-direction: column;
            justify-content: space-between;

            background:
                linear-gradient(
                    145deg,
                    rgba(29, 78, 216, 0.30),
                    rgba(16, 185, 129, 0.08)
                );
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;

            font-size: 19px;
            font-weight: 700;
        }

        .brand-icon {
            width: 42px;
            height: 42px;

            border-radius: 12px;

            display: flex;
            align-items: center;
            justify-content: center;

            background: linear-gradient(
                135deg,
                #3b82f6,
                #14b8a6
            );

            font-size: 21px;

            box-shadow:
                0 8px 25px rgba(59, 130, 246, 0.25);
        }

        .hero {
            max-width: 430px;
        }

        .hero h1 {
            font-size: 46px;
            line-height: 1.08;

            letter-spacing: -1.5px;

            margin-bottom: 22px;
        }

        .hero h1 span {
            background: linear-gradient(
                90deg,
                #60a5fa,
                #2dd4bf
            );

            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero p {
            color: #9ca8b8;

            font-size: 16px;
            line-height: 1.7;
        }

        .features {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .feature {
            padding: 9px 13px;

            border-radius: 30px;

            background: rgba(255, 255, 255, 0.055);

            border: 1px solid rgba(255, 255, 255, 0.07);

            color: #aeb9c8;

            font-size: 12px;
        }

        /* RIGHT SIDE */

        .right {
            padding: 55px;

            display: flex;
            align-items: center;
            justify-content: center;
        }

        .form-box {
            width: 100%;
            max-width: 390px;
        }

        .form-header {
            margin-bottom: 30px;
        }

        .form-header h2 {
            font-size: 32px;
            margin-bottom: 8px;
        }

        .form-header p {
            color: #8793a5;
            font-size: 14px;
        }

        .message {
            padding: 13px 15px;

            border-radius: 12px;

            margin-bottom: 20px;

            font-size: 13px;
            line-height: 1.4;
        }

        .message.error {
            background: rgba(239, 68, 68, 0.10);
            border: 1px solid rgba(239, 68, 68, 0.25);
            color: #fca5a5;
        }

        .message.success {
            background: rgba(34, 197, 94, 0.10);
            border: 1px solid rgba(34, 197, 94, 0.25);
            color: #86efac;
        }

        .input-group {
            margin-bottom: 18px;
        }

        .input-group label {
            display: block;

            margin-bottom: 8px;

            color: #d4dae3;

            font-size: 13px;
            font-weight: 600;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper input {
            width: 100%;

            height: 52px;

            padding: 0 45px 0 16px;

            border-radius: 13px;

            border: 1px solid #293445;

            background: #0d131d;

            color: #ffffff;

            outline: none;

            font-size: 14px;

            transition: 0.2s;
        }

        .input-wrapper input::placeholder {
            color: #5e6a7b;
        }

        .input-wrapper input:focus {
            border-color: #3b82f6;

            box-shadow:
                0 0 0 3px rgba(59, 130, 246, 0.10);
        }

        .eye {
            position: absolute;

            right: 14px;
            top: 50%;

            transform: translateY(-50%);

            border: none;

            background: transparent;

            color: #78869a;

            cursor: pointer;

            font-size: 17px;
        }

        .eye:hover {
            color: #ffffff;
        }

        .create-btn {
            width: 100%;

            height: 52px;

            margin-top: 8px;

            border: none;

            border-radius: 13px;

            background:
                linear-gradient(
                    90deg,
                    #2563eb,
                    #14b8a6
                );

            color: white;

            font-size: 15px;

            font-weight: 700;

            cursor: pointer;

            transition: 0.25s;

            box-shadow:
                0 10px 25px rgba(37, 99, 235, 0.20);
        }

        .create-btn:hover {
            transform: translateY(-2px);

            box-shadow:
                0 15px 30px rgba(37, 99, 235, 0.30);
        }

        .login-text {
            text-align: center;

            margin-top: 22px;

            color: #7f8b9d;

            font-size: 14px;
        }

        .login-text a {
            color: #60a5fa;

            text-decoration: none;

            font-weight: 700;
        }

        .login-text a:hover {
            color: #93c5fd;
        }

        .security {
            text-align: center;

            margin-top: 28px;

            color: #596577;

            font-size: 11px;
        }

        @media (max-width: 800px) {

            body {
                padding: 18px;
            }

            .container {
                grid-template-columns: 1fr;
                max-width: 500px;
            }

            .left {
                display: none;
            }

            .right {
                padding: 38px 25px;
            }
        }

    </style>

</head>

<body>

<div class="container">

    <div class="left">

        <div class="brand">

            <div class="brand-icon">
                ₹
            </div>

            Personal Expense Tracker

        </div>

        <div class="hero">

            <h1>
                Take control of
                <span>your money.</span>
            </h1>

            <p>
                Track your expenses, manage your budgets,
                monitor financial goals and understand where
                your money goes.
            </p>

        </div>

        <div class="features">

            <div class="feature">Expense Tracking</div>
            <div class="feature">Budget Management</div>
            <div class="feature">Financial Goals</div>

        </div>

    </div>


    <div class="right">

        <div class="form-box">

            <div class="form-header">

                <h2>Create account</h2>

                <p>
                    Start managing your finances smarter.
                </p>

            </div>


            <?php if ($message !== ""): ?>

                <div class="message <?php echo $message_type; ?>">

                    <?php echo htmlspecialchars($message); ?>

                </div>

            <?php endif; ?>


            <form method="POST" action="register.php">

                <div class="input-group">

                    <label for="name">
                        Full Name
                    </label>

                    <div class="input-wrapper">

                        <input
                            type="text"
                            id="name"
                            name="name"
                            placeholder="Enter your full name"
                            value="<?php echo htmlspecialchars($name ?? ""); ?>"
                            required
                        >

                    </div>

                </div>


                <div class="input-group">

                    <label for="email">
                        Email Address
                    </label>

                    <div class="input-wrapper">

                        <input
                            type="email"
                            id="email"
                            name="email"
                            placeholder="you@example.com"
                            value="<?php echo htmlspecialchars($email ?? ""); ?>"
                            required
                        >

                    </div>

                </div>


                <div class="input-group">

                    <label for="password">
                        Password
                    </label>

                    <div class="input-wrapper">

                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Minimum 6 characters"
                            required
                        >

                        <button
                            type="button"
                            class="eye"
                            onclick="togglePassword()"
                            aria-label="Show password"
                        >
                            ◉
                        </button>

                    </div>

                </div>


                <button
                    type="submit"
                    class="create-btn"
                >
                    Create Account
                </button>

            </form>


            <div class="login-text">

                Already have an account?

                <a href="login.php">
                    Sign in
                </a>

            </div>


            <div class="security">
                Your password is securely encrypted.
            </div>

        </div>

    </div>

</div>


<script>

function togglePassword() {

    const password =
        document.getElementById("password");

    const button =
        document.querySelector(".eye");

    if (password.type === "password") {

        password.type = "text";
        button.textContent = "◉";

    } else {

        password.type = "password";
        button.textContent = "◉";

    }

}

</script>

</body>

</html>