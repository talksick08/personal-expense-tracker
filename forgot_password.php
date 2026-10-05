<?php

session_start();

require_once "db.php";
require_once "otp_helper.php";

$message = "";
$message_type = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST["email"] ?? "");

    if ($email === "") {

        $message = "Please enter your email address.";
        $message_type = "error";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = "Please enter a valid email address.";
        $message_type = "error";

    } else {

        $stmt = $conn->prepare(
            "SELECT id, name, email FROM users WHERE email = ?"
        );

        if (!$stmt) {

            $message = "Something went wrong. Please try again.";
            $message_type = "error";

        } else {

            $stmt->bind_param("s", $email);

            if (!$stmt->execute()) {

                $message = "Unable to process your request.";
                $message_type = "error";

            } else {

                $result = $stmt->get_result();

                if ($result && $result->num_rows > 0) {

                    $user = $result->fetch_assoc();

                    try {
                        $otp = create_otp($conn, (int)$user["id"], "password_reset", 10);
                        $sent = send_otp_email($user["email"], $user["name"], $otp, "password_reset");

                        $_SESSION["otp_user_id"] = (int)$user["id"];
                        $_SESSION["otp_purpose"] = "password_reset";

                        if (!$sent) {
                            $_SESSION["otp_delivery_failed"] = true;
                        } else {
                            unset($_SESSION["otp_delivery_failed"]);
                        }

                        header("Location: verify_otp.php");
                        exit;
                    } catch (Throwable $e) {
                        $message = "Unable to generate verification code. Please try again.";
                        $message_type = "error";
                    }

                } else {

                    $message =
                        "No account was found with this email address.";

                    $message_type = "error";
                }
            }

            $stmt->close();
        }
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

    <title>Forgot Password | Personal Expense Tracker</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800;900&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="responsive_mobile.css?v=2.2">

    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {

            min-height: 100vh;

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            background:
                radial-gradient(
                    circle at top left,
                    #26364d 0%,
                    transparent 38%
                ),
                radial-gradient(
                    circle at bottom right,
                    #123b35 0%,
                    transparent 35%
                ),
                #070b12;

            color: #ffffff;

            display: flex;

            align-items: center;

            justify-content: center;

            padding: 25px;

        }


        .card {

            width: 100%;

            max-width: 470px;

            padding: 45px;

            background:
                rgba(16, 22, 32, 0.95);

            border:
                1px solid
                rgba(255, 255, 255, 0.09);

            border-radius: 25px;

            box-shadow:
                0 30px 80px
                rgba(0, 0, 0, 0.45);

        }


        .logo {
            width: 52px;
            height: 52px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 25px;
            background: linear-gradient(135deg, #0ea5e9 0%, #2563eb 52%, #4f46e5 100%);
            box-shadow: 
                0 6px 20px -2px rgba(37, 99, 235, 0.45),
                inset 0 1px 1px 0 rgba(255, 255, 255, 0.50),
                inset 0 -1px 2px 0 rgba(0, 0, 0, 0.25);
            color: #ffffff;
            position: relative;
            overflow: hidden;
        }

        .logo::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 48%;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.32) 0%, rgba(255, 255, 255, 0) 100%);
            border-radius: 15px 15px 20px 20px;
            pointer-events: none;
        }


        h1 {

            font-size: 32px;

            margin-bottom: 10px;

            letter-spacing: -0.5px;

        }


        .subtitle {

            color: #8793a5;

            font-size: 14px;

            line-height: 1.6;

            margin-bottom: 28px;

        }


        .message {

            padding: 13px 15px;

            border-radius: 12px;

            margin-bottom: 20px;

            font-size: 13px;

            line-height: 1.4;

        }


        .message.error {

            background:
                rgba(239, 68, 68, 0.10);

            border:
                1px solid
                rgba(239, 68, 68, 0.25);

            color: #fca5a5;

        }


        label {

            display: block;

            margin-bottom: 8px;

            color: #d4dae3;

            font-size: 13px;

            font-weight: 600;

        }


        input {

            width: 100%;

            height: 54px;

            padding: 0 16px;

            border-radius: 13px;

            border:
                1px solid #293445;

            background: #0d131d;

            color: white;

            outline: none;

            font-size: 14px;

            margin-bottom: 18px;

        }


        input::placeholder {

            color: #5e6a7b;

        }


        input:focus {

            border-color: #3b82f6;

            box-shadow:
                0 0 0 3px
                rgba(59, 130, 246, 0.10);

        }


        .reset-btn {

            width: 100%;

            height: 53px;

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

        }


        .reset-btn:hover {

            transform: translateY(-2px);

            box-shadow:
                0 12px 30px
                rgba(37, 99, 235, 0.25);

        }


        .back {

            display: block;

            text-align: center;

            margin-top: 22px;

            color: #60a5fa;

            text-decoration: none;

            font-size: 14px;

            font-weight: 600;

        }


        .back:hover {

            color: #93c5fd;

        }


        .security {

            text-align: center;

            margin-top: 25px;

            color: #596577;

            font-size: 11px;

        }


        @media (max-width: 520px) {

            .card {

                padding: 32px 24px;

            }

            h1 {

                font-size: 28px;

            }

        }

    </style>

</head>

<body>


<div class="card">

    <div class="logo">
        <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" style="position: relative; z-index: 1; filter: drop-shadow(0 1px 2px rgba(0,0,0,0.35));">
            <path d="M6 3h12M6 8h12M6 13l8.5 8M6 13h3c6.667 0 6.667-10 0-10"/>
        </svg>
    </div>


    <h1>
        Forgot password?
    </h1>


    <p class="subtitle">

        Enter the email address associated with
        your account and we'll help you reset
        your password.

    </p>


    <?php if ($message !== ""): ?>

        <div class="message <?php echo $message_type; ?>">

            <?php echo htmlspecialchars($message); ?>

        </div>

    <?php endif; ?>


    <form
        method="POST"
        action="forgot_password.php"
    >

        <label for="email">
            Email Address
        </label>

        <input
            type="email"
            id="email"
            name="email"
            placeholder="you@example.com"
            value="<?php echo htmlspecialchars($_POST["email"] ?? ""); ?>"
            required
        >


        <button
            type="submit"
            class="reset-btn"
        >
            Continue
        </button>

    </form>


    <a
        href="login.php"
        class="back"
    >
        ← Back to Login
    </a>


    <div class="security">
        Personal Expense Tracker
    </div>

</div>


</body>

</html>