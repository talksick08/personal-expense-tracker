<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Generate a fresh 6-digit OTP
|--------------------------------------------------------------------------
*/

function generate_otp(): string
{
    return str_pad(
        (string) random_int(0, 999999),
        6,
        "0",
        STR_PAD_LEFT
    );
}

/*
|--------------------------------------------------------------------------
| Delete previous unused OTPs
|--------------------------------------------------------------------------
*/

function clear_old_otps(
    mysqli $conn,
    int $user_id,
    string $purpose
): void {

    $stmt = $conn->prepare("
        DELETE FROM otp_verifications
        WHERE user_id = ?
        AND purpose = ?
        AND used_at IS NULL
    ");

    if (!$stmt) {
        throw new Exception("Unable to clear previous OTPs.");
    }

    $stmt->bind_param(
        "is",
        $user_id,
        $purpose
    );

    $stmt->execute();
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Create OTP
|--------------------------------------------------------------------------
*/

function create_otp(
    mysqli $conn,
    int $user_id,
    string $purpose,
    int $expiry_minutes = 5
): string {

    clear_old_otps(
        $conn,
        $user_id,
        $purpose
    );

    $otp = generate_otp();

    /*
    Store only the hash.
    The actual OTP is never stored directly.
    */

    $otp_hash = password_hash(
        $otp,
        PASSWORD_DEFAULT
    );

    $stmt = $conn->prepare("
        INSERT INTO otp_verifications
        (
            user_id,
            purpose,
            otp_hash,
            expires_at
        )
        VALUES
        (
            ?,
            ?,
            ?,
            DATE_ADD(
                NOW(),
                INTERVAL ? MINUTE
            )
        )
    ");

    if (!$stmt) {
        throw new Exception("Unable to create OTP.");
    }

    $stmt->bind_param(
        "issi",
        $user_id,
        $purpose,
        $otp_hash,
        $expiry_minutes
    );

    if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception("Unable to save OTP.");
    }

    $stmt->close();

    return $otp;
}

/*
|--------------------------------------------------------------------------
| Verify OTP
|--------------------------------------------------------------------------
*/

function verify_otp(
    mysqli $conn,
    int $user_id,
    string $purpose,
    string $otp
): array {

    $stmt = $conn->prepare("
        SELECT
            id,
            otp_hash,
            expires_at,
            attempts,
            used_at
        FROM otp_verifications
        WHERE user_id = ?
        AND purpose = ?
        AND used_at IS NULL
        ORDER BY id DESC
        LIMIT 1
    ");

    if (!$stmt) {
        return [
            "success" => false,
            "message" => "Unable to verify OTP."
        ];
    }

    $stmt->bind_param(
        "is",
        $user_id,
        $purpose
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $row = $result->fetch_assoc();

    $stmt->close();

    /*
    No OTP found
    */

    if (!$row) {

        return [
            "success" => false,
            "message" => "No active OTP found. Please request a new OTP."
        ];
    }

    /*
    Maximum 5 attempts
    */

    if ((int)$row["attempts"] >= 5) {

        return [
            "success" => false,
            "message" => "Too many incorrect attempts. Please request a new OTP."
        ];
    }

    /*
    Check expiration
    */

    if (strtotime($row["expires_at"]) < time()) {

        return [
            "success" => false,
            "message" => "This OTP has expired. Please request a new OTP."
        ];
    }

    /*
    Check OTP
    */

    if (!password_verify(
        $otp,
        $row["otp_hash"]
    )) {

        $update = $conn->prepare("
            UPDATE otp_verifications
            SET attempts = attempts + 1
            WHERE id = ?
        ");

        if ($update) {

            $otp_id = (int)$row["id"];

            $update->bind_param(
                "i",
                $otp_id
            );

            $update->execute();

            $update->close();
        }

        return [
            "success" => false,
            "message" => "Incorrect OTP."
        ];
    }

    /*
    OTP is correct.
    Mark it as used.
    */

    $update = $conn->prepare("
        UPDATE otp_verifications
        SET used_at = NOW()
        WHERE id = ?
    ");

    if ($update) {

        $otp_id = (int)$row["id"];

        $update->bind_param(
            "i",
            $otp_id
        );

        $update->execute();

        $update->close();
    }

    return [
        "success" => true,
        "message" => "OTP verified successfully."
    ];
}

/*
|--------------------------------------------------------------------------
| Send OTP Email
|--------------------------------------------------------------------------
|
| IMPORTANT:
| This uses PHP mail().
| On MAMP/local development, email delivery may not work
| until SMTP is configured.
|
*/

function send_otp_email(
    string $email,
    string $otp,
    string $purpose
): bool {

    if ($purpose === "email_verification") {

        $subject =
            "Verify your email - Personal Expense Tracker";

        $message =
            "Hello,\n\n" .
            "Your email verification OTP is:\n\n" .
            $otp . "\n\n" .
            "This OTP will expire in 5 minutes.\n\n" .
            "The OTP can only be used once.\n\n" .
            "If you did not request this verification code, " .
            "you can safely ignore this email.\n\n" .
            "Personal Expense Tracker";

    } else {

        $subject =
            "Your login verification code - Personal Expense Tracker";

        $message =
            "Hello,\n\n" .
            "Your two-factor authentication OTP is:\n\n" .
            $otp . "\n\n" .
            "This OTP will expire in 5 minutes.\n\n" .
            "The OTP can only be used once.\n\n" .
            "Personal Expense Tracker";
    }

    $headers =
        "From: no-reply@localhost\r\n" .
        "Reply-To: no-reply@localhost\r\n" .
        "Content-Type: text/plain; charset=UTF-8\r\n";

    return mail(
        $email,
        $subject,
        $message,
        $headers
    );
}