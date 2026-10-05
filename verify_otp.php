<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "db.php";
require_once "otp_helper.php";

/*
|--------------------------------------------------------------------------
| Check OTP session
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION["otp_user_id"]) ||
    !isset($_SESSION["otp_purpose"])
) {

    header("Location: login.php");
    exit;
}

$otp_user_id = (int)$_SESSION["otp_user_id"];

$otp_purpose = $_SESSION["otp_purpose"];

/*
|--------------------------------------------------------------------------
| Allow only supported OTP purposes
|--------------------------------------------------------------------------
*/

if (
    !in_array(
        $otp_purpose,
        [
            "email_verification",
            "two_factor",
            "password_reset"
        ],
        true
    )
) {

    unset(
        $_SESSION["otp_user_id"],
        $_SESSION["otp_purpose"]
    );

    header("Location: login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Load user
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        id,
        name,
        email
    FROM users
    WHERE id = ?
    LIMIT 1
");

if (!$stmt) {
    die("Unable to load user.");
}

$stmt->bind_param(
    "i",
    $otp_user_id
);

$stmt->execute();

$result = $stmt->get_result();

$user = $result->fetch_assoc();

$stmt->close();

if (!$user) {

    unset(
        $_SESSION["otp_user_id"],
        $_SESSION["otp_purpose"]
    );

    header("Location: login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Variables
|--------------------------------------------------------------------------
*/

$message = "";

$message_type = "";

$is_email_verification =
    ($otp_purpose === "email_verification");
$is_password_reset =
    ($otp_purpose === "password_reset");
$is_two_factor =
    ($otp_purpose === "two_factor");

/*
|--------------------------------------------------------------------------
| Verify OTP
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $otp = trim(
        $_POST["otp"] ?? ""
    );

    /*
    Validate OTP format
    */

    if (!preg_match(
        '/^\d{6}$/',
        $otp
    )) {

        $message =
            "Please enter a valid 6-digit OTP.";

        $message_type = "error";

    } else {

        $verification =
            verify_otp(
                $conn,
                $otp_user_id,
                $otp_purpose,
                $otp
            );

        /*
        |--------------------------------------------------------------------------
        | OTP successful
        |--------------------------------------------------------------------------
        */

        if ($verification["success"]) {

            /*
            |--------------------------------------------------------------------------
            | EMAIL VERIFICATION
            |--------------------------------------------------------------------------
            */

            if (
                $otp_purpose ===
                "email_verification"
            ) {

                $stmt = $conn->prepare("
                    UPDATE users
                    SET email_verified_at = NOW()
                    WHERE id = ?
                ");

                if ($stmt) {

                    $stmt->bind_param(
                        "i",
                        $otp_user_id
                    );

                    $stmt->execute();

                    $stmt->close();
                }

                /*
                Clear OTP session
                */

                unset(
                    $_SESSION["otp_user_id"],
                    $_SESSION["otp_purpose"],
                    $_SESSION["last_generated_otp"],
                    $_SESSION["last_otp_purpose"],
                    $_SESSION["last_otp_user_id"],
                    $_SESSION["last_otp_time"],
                    $_SESSION["otp_delivery_failed"],
                    $_SESSION["otp_delivery_error"]
                );

                /*
                Return to Settings
                */

                header(
                    "Location: settings.php?verified=1"
                );

                exit;
            }

            /*
            |--------------------------------------------------------------------------
            | PASSWORD RESET VERIFICATION
            |--------------------------------------------------------------------------
            */

            if (
                $otp_purpose ===
                "password_reset"
            ) {
                $_SESSION["reset_user_id"] = (int)$user["id"];
                $_SESSION["reset_email"] = $user["email"];
                $_SESSION["reset_verified"] = true;

                unset(
                    $_SESSION["otp_user_id"],
                    $_SESSION["otp_purpose"],
                    $_SESSION["last_generated_otp"],
                    $_SESSION["last_otp_purpose"],
                    $_SESSION["last_otp_user_id"],
                    $_SESSION["last_otp_time"],
                    $_SESSION["otp_delivery_failed"],
                    $_SESSION["otp_delivery_error"]
                );

                header("Location: reset_password.php");
                exit;
            }

            /*
            |--------------------------------------------------------------------------
            | TWO FACTOR AUTHENTICATION
            |--------------------------------------------------------------------------
            */

            if (
                $otp_purpose ===
                "two_factor"
            ) {

                /*
                Prevent session fixation.
                */

                session_regenerate_id(true);

                /*
                Create normal login session.
                */

                $_SESSION["user_id"] =
                    $user["id"];

                $_SESSION["user_name"] =
                    $user["name"];

                $_SESSION["user_email"] =
                    $user["email"];

                /*
                Clear OTP session.
                */

                unset(
                    $_SESSION["otp_user_id"],
                    $_SESSION["otp_purpose"],
                    $_SESSION["pending_2fa_user_id"],
                    $_SESSION["pending_2fa_remember"],
                    $_SESSION["last_generated_otp"],
                    $_SESSION["last_otp_purpose"],
                    $_SESSION["last_otp_user_id"],
                    $_SESSION["last_otp_time"],
                    $_SESSION["otp_delivery_failed"],
                    $_SESSION["otp_delivery_error"]
                );

                /*
                Go to dashboard.
                */

                header(
                    "Location: dashboard.php"
                );

                exit;
            }

        } else {

            /*
            OTP failed
            */

            $message =
                $verification["message"];

            $message_type =
                "error";
        }
    }
}

/*
|--------------------------------------------------------------------------
| Resend OTP
|--------------------------------------------------------------------------
*/

if (
    isset($_GET["resend"]) &&
    $_GET["resend"] === "1"
) {

    try {

        /*
        Generate a completely new OTP.
        */

        $otp = create_otp(
            $conn,
            $otp_user_id,
            $otp_purpose,
            5
        );

        /*
        Email verification
        */

        if (
            $otp_purpose === "email_verification" ||
            $otp_purpose === "two_factor" ||
            $otp_purpose === "password_reset"
        ) {

            $sent = send_otp_email(
                $user["email"],
                $user["name"] ?? "User",
                $otp,
                $otp_purpose
            );

            if ($sent) {
                unset($_SESSION["otp_delivery_failed"], $_SESSION["otp_delivery_error"]);

                $message =
                    "A new verification code has been sent to your email.";

                $message_type =
                    "success";

            } else {
                $_SESSION["otp_delivery_failed"] = true;

                $message =
                    "A new code was generated, but the email could not be delivered to your inbox. Please use the code shown below.";

                $message_type =
                    "warning";
            }

        } else {

            /*
            SMS is not configured yet.
            */

            $message =
                "SMS OTP delivery is not configured yet.";

            $message_type =
                "error";
        }

    } catch (Throwable $e) {

        $message =
            "Unable to generate a new OTP.";

        $message_type =
            "error";
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

    <title>
        <?= $is_email_verification
            ? "Verify Email"
            : ($is_password_reset ? "Reset Password" : "Two-Factor Authentication") ?>
        | ExpenseTracker
    </title>
    <link rel="stylesheet" href="responsive_mobile.css">

    <style>

        * {
            box-sizing: border-box;
        }

        body {

            margin: 0;

            min-height: 100vh;

            display: flex;

            align-items: center;

            justify-content: center;

            padding: 20px;

            background:
                linear-gradient(
                    135deg,
                    #eef8ff,
                    #f7fbff
                );

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            color: #172033;
        }

        .otp-card {

            width: 100%;

            max-width: 460px;

            background: #ffffff;

            border:
                1px solid #e4ebf3;

            border-radius: 20px;

            padding: 35px;

            box-shadow:
                0 15px 45px
                rgba(16, 28, 45, .10);
        }

        .icon {

            width: 65px;

            height: 65px;

            display: flex;

            align-items: center;

            justify-content: center;

            margin:
                0 auto 20px;

            border-radius: 18px;

            background:
                linear-gradient(
                    135deg,
                    #16b5f4,
                    #168ce8
                );

            color: #ffffff;

            font-size: 29px;
        }

        h1 {

            margin: 0;

            text-align: center;

            font-size: 25px;
        }

        .description {

            margin:
                10px 0 25px;

            text-align: center;

            color: #718096;

            font-size: 14px;

            line-height: 1.6;
        }

        .email {

            font-weight: 700;

            color: #168ce8;

            word-break: break-word;
        }

        .alert {

            padding: 13px 15px;

            border-radius: 10px;

            margin-bottom: 18px;

            font-size: 13px;

            font-weight: 600;
        }

        .alert.success {

            background: #eafaf1;

            border:
                1px solid #bce8d0;

            color: #16804b;
        }

        .alert.error {

            background: #fff0f0;

            border:
                1px solid #f3c2c2;

            color: #c0392b;
        }

        .alert.warning {

            background: #fffbeb;

            border:
                1px solid #fde68a;

            color: #b45309;
        }

        label {

            display: block;

            margin-bottom: 8px;

            font-size: 13px;

            font-weight: 700;
        }

        .otp-input {

            width: 100%;

            height: 58px;

            border:
                1px solid #dce3eb;

            border-radius: 12px;

            outline: none;

            text-align: center;

            font-size: 26px;

            font-weight: 800;

            letter-spacing: 8px;

            color: #172033;
        }

        .otp-input:focus {

            border-color: #168ce8;

            box-shadow:
                0 0 0 3px
                rgba(22, 140, 232, .12);
        }

        .verify-btn {

            width: 100%;

            height: 48px;

            margin-top: 18px;

            border: none;

            border-radius: 10px;

            background:
                linear-gradient(
                    135deg,
                    #16b5f4,
                    #168ce8
                );

            color: #ffffff;

            font-size: 14px;

            font-weight: 700;

            cursor: pointer;
        }

        .verify-btn:hover {

            transform:
                translateY(-1px);
        }

        .actions {

            display: flex;

            justify-content: center;

            gap: 15px;

            margin-top: 20px;

            flex-wrap: wrap;
        }

        .actions a {

            color: #168ce8;

            text-decoration: none;

            font-size: 13px;

            font-weight: 700;
        }

        .actions a:hover {

            text-decoration: underline;
        }

        .expiry {

            margin-top: 18px;

            text-align: center;

            color: #8491a5;

            font-size: 12px;
        }

        @media (max-width: 500px) {

            .otp-card {

                padding: 25px 20px;
            }

            .otp-input {

                letter-spacing: 5px;
            }
        }

    </style>

</head>

<body>

<div class="otp-card">

    <div class="icon">

        <?= $is_email_verification
            ? "📧"
            : ($is_password_reset ? "🔑" : "🛡️") ?>

    </div>

    <h1>

        <?= $is_email_verification
            ? "Verify Your Email"
            : ($is_password_reset ? "Reset Your Password" : "Two-Factor Authentication") ?>

    </h1>

    <p class="description">

        <?php if ($is_email_verification): ?>

            We sent a 6-digit verification code to

            <span class="email">

                <?= htmlspecialchars(
                    $user["email"]
                ) ?>

            </span>

        <?php elseif ($is_password_reset): ?>

            We sent a 6-digit password reset code to

            <span class="email">

                <?= htmlspecialchars(
                    $user["email"]
                ) ?>

            </span>. Enter it below to set a new password.

        <?php else: ?>

            Enter the 6-digit security
            code sent to

            <span class="email">

                <?= htmlspecialchars(
                    $user["email"]
                ) ?>

            </span>

        <?php endif; ?>

    </p>

    <?php if (!empty($_SESSION["otp_delivery_failed"])): ?>

        <div class="alert warning" style="margin-bottom: 20px; line-height: 1.5; text-align: left;">
            <div style="font-weight: 700; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">
                <span>⚠️</span> <span>Email Delivery Notice</span>
            </div>
            <div style="font-size: 13px;">The email server could not deliver the message to your inbox (the hosting environment may block outbound SMTP/mail).</div>
            <?php if (!empty($_SESSION["last_generated_otp"]) && !empty($_SESSION["last_otp_purpose"]) && $_SESSION["last_otp_purpose"] === $otp_purpose): ?>
                <div style="margin-top: 10px; padding: 10px 12px; background: rgba(0,0,0,0.04); border-radius: 8px;">
                    <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #78350f; font-weight: 700; margin-bottom: 4px;">Emergency Security Code</div>
                    <div style="display: inline-block; font-family: 'Courier New', Courier, monospace; font-size: 22px; font-weight: 800; letter-spacing: 6px; color: #0284c7; background: #e0f2fe; padding: 4px 12px; border-radius: 8px;"><?= htmlspecialchars($_SESSION["last_generated_otp"]) ?></div>
                </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>

    <?php if ($message !== ""): ?>

        <div
            class="alert
            <?= htmlspecialchars(
                $message_type
            ) ?>"
        >

            <?= htmlspecialchars(
                $message
            ) ?>

        </div>

    <?php endif; ?>

    <form method="POST">

        <label for="otp">
            Enter 6-digit OTP
        </label>

        <input
            class="otp-input"
            type="text"
            id="otp"
            name="otp"
            inputmode="numeric"
            autocomplete="one-time-code"
            maxlength="6"
            pattern="[0-9]{6}"
            placeholder="000000"
            required
            autofocus
        >

        <button
            type="submit"
            class="verify-btn"
        >
            Verify OTP
        </button>

    </form>

    <div class="actions">

        <a href="verify_otp.php?resend=1">
            Resend OTP
        </a>

        <?php if ($is_email_verification): ?>

            <a href="settings.php">
                Back to Settings
            </a>

        <?php elseif ($is_password_reset): ?>

            <a href="forgot_password.php">
                Back to Forgot Password
            </a>

        <?php else: ?>

            <a href="login.php">
                Back to Login
            </a>

        <?php endif; ?>

    </div>

    <div class="expiry">

        OTP expires in 5 minutes
        and can only be used once.

    </div>

</div>

<script>

const otpInput =
    document.getElementById("otp");

if (otpInput) {

    otpInput.addEventListener(
        "input",
        function () {

            this.value =
                this.value
                    .replace(/\D/g, "")
                    .slice(0, 6);
        }
    );
}

</script>

</body>

</html>