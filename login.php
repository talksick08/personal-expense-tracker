<?php

/*
|--------------------------------------------------------------------------
| Session configuration
|--------------------------------------------------------------------------
*/

$remember_me = isset($_POST["remember"]);

if ($remember_me) {

    session_set_cookie_params([
        "lifetime" => 60 * 60 * 24 * 30,
        "path" => "/",
        "secure" => false,
        "httponly" => true,
        "samesite" => "Lax"
    ]);

} else {

    session_set_cookie_params([
        "lifetime" => 0,
        "path" => "/",
        "secure" => false,
        "httponly" => true,
        "samesite" => "Lax"
    ]);
}

session_start();

require_once "db.php";
require_once "otp_helper.php";

$message = "";


/*
|--------------------------------------------------------------------------
| Already logged in
|--------------------------------------------------------------------------
*/

if (isset($_SESSION["user_id"])) {

    header("Location: dashboard.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Login
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if (
        empty($email) ||
        empty($password)
    ) {

        $message =
            "Please enter your email and password.";

    } elseif (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {

        $message =
            "Please enter a valid email address.";

    } else {

        $stmt = $conn->prepare(
            "SELECT
                id,
                name,
                email,
                password,
                two_factor_enabled,
                two_factor_method
             FROM users
             WHERE email = ?
             LIMIT 1"
        );

        if (!$stmt) {

            $message =
                "Something went wrong. Please try again.";

        } else {

            $stmt->bind_param(
                "s",
                $email
            );

            $stmt->execute();

            $result =
                $stmt->get_result();

            $user =
                $result->fetch_assoc();

            $stmt->close();


            /*
            |--------------------------------------------------------------------------
            | Verify password
            |--------------------------------------------------------------------------
            */

            if (
                $user &&
                password_verify(
                    $password,
                    $user["password"]
                )
            ) {


                /*
                |--------------------------------------------------------------------------
                | TWO-FACTOR ENABLED
                |--------------------------------------------------------------------------
                */

                if (
                    (int) $user["two_factor_enabled"] === 1
                ) {

                    /*
                    |--------------------------------------------------------------------------
                    | Do NOT create the real login session yet.
                    |--------------------------------------------------------------------------
                    */

                    $_SESSION["pending_2fa_user_id"] =
                        (int) $user["id"];

                    $_SESSION["pending_2fa_remember"] =
                        $remember_me;


                    /*
                    |--------------------------------------------------------------------------
                    | Current implementation:
                    | Email OTP
                    |--------------------------------------------------------------------------
                    */

                    if (
                        $user["two_factor_method"] === "email"
                    ) {

                        try {

                            $otp = create_otp(
                                $conn,
                                (int) $user["id"],
                                "two_factor",
                                5
                            );

                            $sent = send_otp_email(
                                $user["email"],
                                $user["name"],
                                $otp,
                                "two_factor"
                            );

                            if (!$sent) {

                                unset(
                                    $_SESSION["pending_2fa_user_id"],
                                    $_SESSION["pending_2fa_remember"]
                                );

                                $message =
                                    "Unable to send the verification code. " .
                                    "Please check your email configuration.";

                            } else {

                                $_SESSION["otp_user_id"] =
                                    (int) $user["id"];

                                $_SESSION["otp_purpose"] =
                                    "two_factor";

                                header(
                                    "Location: verify_otp.php"
                                );

                                exit;
                            }

                        } catch (Exception $e) {

                            unset(
                                $_SESSION["pending_2fa_user_id"],
                                $_SESSION["pending_2fa_remember"]
                            );

                            $message =
                                "Unable to create the verification code.";
                        }

                    } else {

                        $message =
                            "SMS 2FA is not configured yet.";
                    }


                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | Normal login when 2FA is disabled
                    |--------------------------------------------------------------------------
                    */

                    session_regenerate_id(true);

                    $_SESSION["user_id"] =
                        $user["id"];

                    $_SESSION["user_name"] =
                        $user["name"];

                    $_SESSION["user_email"] =
                        $user["email"];


                    /*
                    |--------------------------------------------------------------------------
                    | Remember Me
                    |--------------------------------------------------------------------------
                    */

                    if ($remember_me) {

                        setcookie(
                            session_name(),
                            session_id(),
                            [
                                "expires" =>
                                    time() +
                                    (60 * 60 * 24 * 30),

                                "path" => "/",

                                "secure" => false,

                                "httponly" => true,

                                "samesite" => "Lax"
                            ]
                        );
                    }


                    header(
                        "Location: dashboard.php"
                    );

                    exit;
                }

            } else {

                $message =
                    "Invalid email or password.";
            }
        }
    }
}

?>