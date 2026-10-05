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

function ensure_otp_table(mysqli $conn): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $sql = "CREATE TABLE IF NOT EXISTS otp_verifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        purpose VARCHAR(50) NOT NULL,
        otp_hash VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        attempts INT NOT NULL DEFAULT 0,
        used_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_purpose (user_id, purpose, used_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    @$conn->query($sql);
    $checked = true;
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

    ensure_otp_table($conn);

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

    // Store in session as emergency fallback if host blocks outbound mail/SMTP
    $_SESSION["last_generated_otp"] = $otp;
    $_SESSION["last_otp_purpose"] = $purpose;
    $_SESSION["last_otp_user_id"] = $user_id;
    $_SESSION["last_otp_time"] = time();

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

    ensure_otp_table($conn);

    $stmt = $conn->prepare("
        SELECT
            id,
            otp_hash,
            expires_at,
            TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS seconds_left,
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
    Check expiration (timezone-safe comparison using MySQL internal timestamp difference)
    */

    $is_expired = false;
    if (isset($row["seconds_left"]) && $row["seconds_left"] !== null) {
        $is_expired = ((int)$row["seconds_left"] <= 0);
    } else {
        $is_expired = (strtotime($row["expires_at"]) < time());
    }

    if ($is_expired) {

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
| Uses the central mailer engine (SMTP configured in admin settings,
| with automatic fallback to PHP mail()). Supports both 3-argument
| and 4-argument calls.
|
*/

require_once __DIR__ . "/mailer.php";

function send_otp_email(
    string $email,
    $param2,
    $param3 = null,
    $param4 = null
): bool {
    global $conn;

    if (!isset($conn) || !($conn instanceof mysqli)) {
        require __DIR__ . "/db.php";
    }

    // Flexible arguments support:
    // 4 args: ($email, $name, $otp, $purpose)
    // 3 args: ($email, $otp, $purpose)
    if ($param4 !== null) {
        $recipient_name = trim((string) $param2) ?: 'User';
        $otp = (string) $param3;
        $purpose = (string) $param4;
    } else {
        $recipient_name = 'User';
        $otp = (string) $param2;
        $purpose = (string) ($param3 ?? 'verification');
    }

    if ($purpose === "email_verification") {
        $subject = "Verify your email - Personal Expense Tracker";
        $badge = "Email Verification";
        $title = "Verify Your Email Address";
        $message_html = "Hello <strong>" . htmlspecialchars($recipient_name) . "</strong>,<br><br>" .
            "Thank you for using Personal Expense Tracker. Please enter the verification code below to verify your email address.";
        $footer_note = "This verification code will expire in <strong>5 minutes</strong> and can only be used once. If you did not request this, you can safely ignore this email.";

        $text_message = "Hello {$recipient_name},\n\n" .
            "Your email verification code is: {$otp}\n\n" .
            "This OTP will expire in 5 minutes.\n\n" .
            "Personal Expense Tracker";
    } elseif ($purpose === "password_reset") {
        $subject = "Reset your password - Personal Expense Tracker";
        $badge = "Password Reset";
        $title = "Password Reset Verification Code";
        $message_html = "Hello <strong>" . htmlspecialchars($recipient_name) . "</strong>,<br><br>" .
            "We received a request to reset the password for your Personal Expense Tracker account. Please enter the verification code below to set a new password.";
        $footer_note = "This code will expire in <strong>10 minutes</strong>. If you did not make this request, you can safely ignore this email; your password will remain unchanged.";

        $text_message = "Hello {$recipient_name},\n\n" .
            "Your password reset verification code is: {$otp}\n\n" .
            "This OTP will expire in 10 minutes.\n\n" .
            "Personal Expense Tracker";
    } else {
        $subject = "Your login verification code - Personal Expense Tracker";
        $badge = "Security Verification";
        $title = "Two-Factor Authentication Code";
        $message_html = "Hello <strong>" . htmlspecialchars($recipient_name) . "</strong>,<br><br>" .
            "A sign-in attempt was detected for your account. Please enter the 6-digit security code below to complete your login.";
        $footer_note = "This security code will expire in <strong>5 minutes</strong> and can only be used once. If you did not attempt this login, please change your password immediately.";

        $text_message = "Hello {$recipient_name},\n\n" .
            "Your two-factor authentication code is: {$otp}\n\n" .
            "This OTP will expire in 5 minutes.\n\n" .
            "Personal Expense Tracker";
    }

    $highlight_box = "
        <div style=\"font-size: 13px; text-transform: uppercase; letter-spacing: 1.5px; color: #94a3b8; margin-bottom: 8px; font-weight: 600;\">One-Time Security Code</div>
        <div style=\"font-size: 34px; font-weight: 800; letter-spacing: 10px; color: #38bdf8; font-family: 'Courier New', Courier, monospace;\">" . htmlspecialchars($otp) . "</div>
        <div style=\"font-size: 12px; color: #64748b; margin-top: 6px;\">Valid for 5 minutes</div>
    ";

    $html_body = get_email_html_template(
        $badge,
        $title,
        $message_html,
        $highlight_box,
        $footer_note
    );

    $result = send_app_mail(
        $conn,
        $email,
        $subject,
        $html_body,
        $text_message,
        $recipient_name
    );

    if (empty($result['success'])) {
        $_SESSION["otp_delivery_failed"] = true;
        $_SESSION["otp_delivery_error"] = $result['message'] ?? 'Email delivery failed';
    } else {
        unset($_SESSION["otp_delivery_failed"], $_SESSION["otp_delivery_error"]);
    }

    return !empty($result['success']);
}