<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "auth.php";
require_once "otp_helper.php";

$message = "";
$message_type = "";

$display_name = $user_name;
$display_email = $user_email;

$user = [
    "name" => $display_name,
    "email" => $display_email,
    "phone" => "",
    "email_verified_at" => null,
    "phone_verified_at" => null,
    "two_factor_enabled" => 0,
    "two_factor_method" => "email",
    "profile_photo" => ""
];

/*
|--------------------------------------------------------------------------
| Load current user
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare(
    "SELECT
        id, name, email, phone,
        email_verified_at, phone_verified_at,
        two_factor_enabled, two_factor_method,
        profile_photo
     FROM users
     WHERE id = ?
     LIMIT 1"
);

if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $user = array_merge($user, $row);
        $display_name = $user["name"];
        $display_email = $user["email"];
    }

    $stmt->close();
}

$_SESSION["user_name"] = $display_name;
$_SESSION["user_email"] = $display_email;

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/
if (empty($_SESSION["settings_csrf"])) {
    $_SESSION["settings_csrf"] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION["settings_csrf"];

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function settings_redirect_message(string $type, string $message): void
{
    $_SESSION["settings_message"] = $message;
    $_SESSION["settings_message_type"] = $type;
    header("Location: settings.php");
    exit;
}

function settings_csrf_ok(): bool
{
    return isset($_POST["csrf_token"])
        && isset($_SESSION["settings_csrf"])
        && hash_equals($_SESSION["settings_csrf"], $_POST["csrf_token"]);
}

if (isset($_SESSION["settings_message"])) {
    $message = $_SESSION["settings_message"];
    $message_type = $_SESSION["settings_message_type"] ?? "success";
    unset($_SESSION["settings_message"], $_SESSION["settings_message_type"]);
}

/*
|--------------------------------------------------------------------------
| Handle POST actions
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!settings_csrf_ok()) {
        settings_redirect_message("error", "Security check failed. Please try again.");
    }

    $action = $_POST["action"] ?? "";

    /*
    |--------------------------------------------------------------------------
    | Update profile
    |--------------------------------------------------------------------------
    */
    if ($action === "update_profile") {

        $new_name = trim($_POST["name"] ?? "");

        if ($new_name === "") {
            settings_redirect_message("error", "Please enter your name.");
        }

        if (mb_strlen($new_name) < 2 || mb_strlen($new_name) > 100) {
            settings_redirect_message("error", "Name must contain 2 to 100 characters.");
        }

        $stmt = $conn->prepare(
            "UPDATE users SET name = ? WHERE id = ?"
        );

        if (!$stmt) {
            settings_redirect_message("error", "Unable to update your profile.");
        }

        $stmt->bind_param("si", $new_name, $user_id);

        if ($stmt->execute()) {
            $_SESSION["user_name"] = $new_name;
            settings_redirect_message("success", "Profile updated successfully.");
        }

        $stmt->close();

        settings_redirect_message("error", "Unable to update your profile.");
    }

    /*
    |--------------------------------------------------------------------------
    | Save phone number
    |--------------------------------------------------------------------------
    */
    if ($action === "save_phone") {

        $phone = trim($_POST["phone"] ?? "");

        if ($phone === "") {
            settings_redirect_message("error", "Please enter your mobile number.");
        }

        if (!preg_match('/^\+?[0-9][0-9\s\-]{7,19}$/', $phone)) {
            settings_redirect_message("error", "Please enter a valid mobile number.");
        }

        $normalized_phone = preg_replace('/[\s\-]/', '', $phone);

        $stmt = $conn->prepare(
            "SELECT id FROM users WHERE phone = ? AND id <> ? LIMIT 1"
        );

        if ($stmt) {
            $stmt->bind_param("si", $normalized_phone, $user_id);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($exists) {
                settings_redirect_message("error", "This mobile number is already linked to another account.");
            }
        }

        $stmt = $conn->prepare(
            "UPDATE users
             SET phone = ?, phone_verified_at = NULL
             WHERE id = ?"
        );

        if (!$stmt) {
            settings_redirect_message("error", "Unable to save your mobile number.");
        }

        $stmt->bind_param("si", $normalized_phone, $user_id);

        if ($stmt->execute()) {
            $stmt->close();
            settings_redirect_message("success", "Mobile number saved. Verify it with OTP.");
        }

        $stmt->close();
        settings_redirect_message("error", "Unable to save your mobile number.");
    }

    /*
    |--------------------------------------------------------------------------
    | Send email verification OTP
    |--------------------------------------------------------------------------
    */
    if ($action === "send_email_verification") {

        if (!empty($user["email_verified_at"])) {
            settings_redirect_message("success", "Your email address is already verified.");
        }

        try {
            $otp = create_otp($conn, $user_id, "email_verification", 5);

            if (send_otp_email($display_email, $otp, "email_verification")) {
                $_SESSION["otp_user_id"] = $user_id;
                $_SESSION["otp_purpose"] = "email_verification";

                header("Location: verify_otp.php");
                exit;
            }

            settings_redirect_message(
                "error",
                "The OTP was generated, but the email could not be sent. Configure SMTP/email delivery first."
            );

        } catch (Throwable $e) {
            settings_redirect_message("error", "Unable to generate the email verification OTP.");
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Change password
    |--------------------------------------------------------------------------
    */
    if ($action === "change_password") {

        $current_password = $_POST["current_password"] ?? "";
        $new_password = $_POST["new_password"] ?? "";
        $confirm_password = $_POST["confirm_password"] ?? "";

        if ($current_password === "" || $new_password === "" || $confirm_password === "") {
            settings_redirect_message("error", "Please fill in all password fields.");
        }

        if (!password_verify($current_password, $user["password"] ?? "")) {
            $password_stmt = $conn->prepare(
                "SELECT password FROM users WHERE id = ? LIMIT 1"
            );

            if (!$password_stmt) {
                settings_redirect_message("error", "Unable to verify your current password.");
            }

            $password_stmt->bind_param("i", $user_id);
            $password_stmt->execute();
            $password_row = $password_stmt->get_result()->fetch_assoc();
            $password_stmt->close();

            if (!$password_row || !password_verify($current_password, $password_row["password"])) {
                settings_redirect_message("error", "Current password is incorrect.");
            }

            $current_hash = $password_row["password"];
        } else {
            $current_hash = $user["password"];
        }

        if (strlen($new_password) < 8) {
            settings_redirect_message("error", "New password must contain at least 8 characters.");
        }

        if (!preg_match('/[A-Z]/', $new_password) ||
            !preg_match('/[a-z]/', $new_password) ||
            !preg_match('/[0-9]/', $new_password)) {
            settings_redirect_message(
                "error",
                "New password must contain an uppercase letter, lowercase letter, and number."
            );
        }

        if ($new_password !== $confirm_password) {
            settings_redirect_message("error", "New password and confirmation do not match.");
        }

        if (password_verify($new_password, $current_hash)) {
            settings_redirect_message("error", "New password must be different from your current password.");
        }

        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare(
            "UPDATE users SET password = ? WHERE id = ?"
        );

        if (!$stmt) {
            settings_redirect_message("error", "Unable to change your password.");
        }

        $stmt->bind_param("si", $new_hash, $user_id);

        if ($stmt->execute()) {
            $stmt->close();

            // Force the user to authenticate again after a password change.
            session_unset();
            session_destroy();

            session_start();
            $_SESSION["settings_message"] = "Password changed successfully. Please sign in again.";
            $_SESSION["settings_message_type"] = "success";

            header("Location: login.php");
            exit;
        }

        $stmt->close();
        settings_redirect_message("error", "Unable to change your password.");
    }

    /*
    |--------------------------------------------------------------------------
    | Enable / disable 2FA
    |--------------------------------------------------------------------------
    */
    if ($action === "toggle_2fa") {

        $enable = isset($_POST["enable_2fa"]) && $_POST["enable_2fa"] === "1";
        $method = $_POST["two_factor_method"] ?? "email";

        if (!in_array($method, ["email", "sms"], true)) {
            $method = "email";
        }

        if ($enable) {

            if (empty($user["email_verified_at"])) {
                settings_redirect_message(
                    "error",
                    "Verify your email address before enabling two-factor authentication."
                );
            }

            if ($method === "sms" && empty($user["phone_verified_at"])) {
                settings_redirect_message(
                    "error",
                    "Verify your mobile number before using SMS two-factor authentication."
                );
            }

            $stmt = $conn->prepare(
                "UPDATE users
                 SET two_factor_enabled = 1,
                     two_factor_method = ?
                 WHERE id = ?"
            );

            if (!$stmt) {
                settings_redirect_message("error", "Unable to enable two-factor authentication.");
            }

            $stmt->bind_param("si", $method, $user_id);

            if ($stmt->execute()) {
                $stmt->close();
                settings_redirect_message("success", "Two-factor authentication enabled.");
            }

            $stmt->close();
            settings_redirect_message("error", "Unable to enable two-factor authentication.");

        } else {

            $stmt = $conn->prepare(
                "UPDATE users
                 SET two_factor_enabled = 0
                 WHERE id = ?"
            );

            if (!$stmt) {
                settings_redirect_message("error", "Unable to disable two-factor authentication.");
            }

            $stmt->bind_param("i", $user_id);

            if ($stmt->execute()) {
                $stmt->close();
                settings_redirect_message("success", "Two-factor authentication disabled.");
            }

            $stmt->close();
            settings_redirect_message("error", "Unable to disable two-factor authentication.");
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Profile photo upload
    |--------------------------------------------------------------------------
    */
    if ($action === "upload_photo") {

        if (
            !isset($_FILES["profile_photo"]) ||
            $_FILES["profile_photo"]["error"] !== UPLOAD_ERR_OK
        ) {
            settings_redirect_message("error", "Please select a profile photo.");
        }

        $file = $_FILES["profile_photo"];

        if ($file["size"] > 5 * 1024 * 1024) {
            settings_redirect_message("error", "Profile photo must be 5 MB or smaller.");
        }

        $image_info = @getimagesize($file["tmp_name"]);

        if ($image_info === false) {
            settings_redirect_message("error", "The selected file is not a valid image.");
        }

        $allowed_types = [
            IMAGETYPE_JPEG => "jpg",
            IMAGETYPE_PNG => "png",
            IMAGETYPE_WEBP => "webp"
        ];

        $image_type = $image_info[2];

        if (!isset($allowed_types[$image_type])) {
            settings_redirect_message(
                "error",
                "Only JPG, PNG, and WEBP images are supported."
            );
        }

        $upload_dir = __DIR__ . "/uploads/profile";

        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                settings_redirect_message("error", "Unable to create the profile photo folder.");
            }
        }

        $extension = $allowed_types[$image_type];

        $filename = "user_" . $user_id . "_" . bin2hex(random_bytes(12)) . "." . $extension;
        $destination = $upload_dir . "/" . $filename;

        if (!move_uploaded_file($file["tmp_name"], $destination)) {
            settings_redirect_message("error", "Unable to save your profile photo.");
        }

        // Delete the old local photo if it belongs to this application.
        if (!empty($user["profile_photo"])) {
            $old_path = __DIR__ . "/" . ltrim($user["profile_photo"], "/");

            if (is_file($old_path) && strpos(realpath($old_path) ?: "", realpath($upload_dir) ?: "__no__") === 0) {
                @unlink($old_path);
            }
        }

        $relative_path = "uploads/profile/" . $filename;

        $stmt = $conn->prepare(
            "UPDATE users SET profile_photo = ? WHERE id = ?"
        );

        if (!$stmt) {
            @unlink($destination);
            settings_redirect_message("error", "Unable to save your profile photo.");
        }

        $stmt->bind_param("si", $relative_path, $user_id);

        if ($stmt->execute()) {
            $stmt->close();
            settings_redirect_message("success", "Profile photo updated successfully.");
        }

        $stmt->close();
        @unlink($destination);

        settings_redirect_message("error", "Unable to save your profile photo.");
    }

    /*
    |--------------------------------------------------------------------------
    | Remove profile photo
    |--------------------------------------------------------------------------
    */
    if ($action === "remove_photo") {

        if (!empty($user["profile_photo"])) {

            $upload_dir = __DIR__ . "/uploads/profile";
            $old_path = __DIR__ . "/" . ltrim($user["profile_photo"], "/");

            if (
                is_file($old_path) &&
                strpos(realpath($old_path) ?: "", realpath($upload_dir) ?: "__no__") === 0
            ) {
                @unlink($old_path);
            }
        }

        $stmt = $conn->prepare(
            "UPDATE users SET profile_photo = NULL WHERE id = ?"
        );

        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();
        }

        settings_redirect_message("success", "Profile photo removed.");
    }
}

$profile_photo = trim((string)($user["profile_photo"] ?? ""));

if ($profile_photo !== "") {
    $profile_photo_url = $profile_photo;
} else {
    $profile_photo_url = "";
}

$initial = strtoupper(
    substr(trim($display_name), 0, 1)
);

$initial = $initial ?: "U";

$email_verified = !empty($user["email_verified_at"]);
$phone_verified = !empty($user["phone_verified_at"]);
$two_factor_enabled = (int)$user["two_factor_enabled"] === 1;
$two_factor_method = $user["two_factor_method"] ?: "email";

$completion = 55;

if ($email_verified) {
    $completion += 10;
}

if (!empty($user["phone"])) {
    $completion += 10;
}

if ($phone_verified) {
    $completion += 10;
}

if ($two_factor_enabled) {
    $completion += 10;
}

$completion = min($completion, 100);

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Settings | ExpenseTracker</title>

    <script>
        (function() {
            var theme = localStorage.getItem('expenseTrackerTheme') || 'system';
            var isDark = theme === 'dark' || (theme === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
            if (isDark) {
                document.documentElement.classList.add('dark-mode');
            }
        })();
    </script>
    <link rel="stylesheet" href="dark_theme.css">

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f5f7fb;
            color: #172033;
            font-family: Arial, Helvetica, sans-serif;
            transition: background .25s ease, color .25s ease;
        }

        body.dark-mode {
            background: #0b1220;
            color: #e5edf7;
        }

        .settings-main {
            margin-left: 250px;
            min-height: 100vh;
            padding: 40px;
        }

        .settings-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 30px;
        }

        .settings-title h1 {
            margin: 0 0 7px;
            font-size: 30px;
            font-weight: 800;
            color: #101c2d;
        }

        body.dark-mode .settings-title h1 {
            color: #f4f7fb;
        }

        .settings-title p {
            margin: 0;
            color: #718096;
            font-size: 15px;
        }

        .settings-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 15px;
            background: #eaf7ff;
            color: #168ce8;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
        }

        .alert {
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 22px;
            font-size: 14px;
            font-weight: 600;
        }

        .alert.success {
            background: #eafaf1;
            color: #16804b;
            border: 1px solid #bce8d0;
        }

        .alert.error {
            background: #fff0f0;
            color: #c0392b;
            border: 1px solid #f3c2c2;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.5fr) minmax(300px, .9fr);
            gap: 24px;
            align-items: start;
        }

        .settings-card {
            background: #ffffff;
            border: 1px solid #e7edf4;
            border-radius: 16px;
            box-shadow: 0 8px 25px rgba(16, 28, 45, .05);
            overflow: hidden;
            margin-bottom: 24px;
            transition: background .25s ease, border-color .25s ease;
        }

        body.dark-mode .settings-card {
            background: #111b2e;
            border-color: #26344a;
            box-shadow: 0 8px 25px rgba(0,0,0,.18);
        }

        .card-header {
            padding: 22px 24px;
            border-bottom: 1px solid #edf1f5;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        body.dark-mode .card-header {
            border-color: #26344a;
        }

        .card-icon {
            width: 42px;
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 11px;
            background: #eaf7ff;
            font-size: 20px;
        }

        .card-header h2 {
            margin: 0 0 4px;
            font-size: 17px;
            color: #172033;
        }

        body.dark-mode .card-header h2,
        body.dark-mode .setting-info strong,
        body.dark-mode .security-left strong,
        body.dark-mode .profile-info h3 {
            color: #f4f7fb;
        }

        .card-header p {
            margin: 0;
            font-size: 13px;
            color: #8491a5;
        }

        .card-body {
            padding: 24px;
        }

        .profile-top {
            display: flex;
            align-items: center;
            gap: 18px;
            margin-bottom: 26px;
        }

        .profile-photo-wrap {
            position: relative;
            flex-shrink: 0;
        }

        .profile-photo {
            width: 82px;
            height: 82px;
            border-radius: 50%;
            background: linear-gradient(135deg, #16b5f4, #168ce8);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 29px;
            font-weight: 800;
            box-shadow: 0 8px 20px rgba(22, 140, 232, .22);
            overflow: hidden;
        }

        .profile-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .photo-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }

        .small-btn {
            border: 1px solid #dce3eb;
            background: #ffffff;
            color: #344054;
            padding: 8px 11px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
        }

        body.dark-mode .small-btn,
        body.dark-mode .form-group input,
        body.dark-mode .form-group select,
        body.dark-mode .setting-select {
            background: #0c1526;
            color: #e5edf7;
            border-color: #33445d;
        }

        .small-btn.danger {
            color: #c0392b;
            border-color: #efb5b5;
            background: #fff6f6;
        }

        .profile-info h3 {
            margin: 0 0 5px;
            font-size: 20px;
            color: #172033;
        }

        .profile-info p {
            margin: 0;
            color: #718096;
            font-size: 14px;
        }

        .verified {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-top: 8px;
            padding: 5px 9px;
            background: #eafaf1;
            color: #16804b;
            border-radius: 7px;
            font-size: 11px;
            font-weight: 700;
        }

        .completion-box {
            background: linear-gradient(135deg, #f0f9ff, #f8fbff);
            border: 1px solid #d8edf9;
            border-radius: 13px;
            padding: 18px;
            margin-bottom: 20px;
        }

        .completion-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .completion-top strong {
            font-size: 13px;
        }

        .completion-top span {
            font-size: 12px;
            color: #168ce8;
            font-weight: 700;
        }

        .progress {
            height: 7px;
            background: #dfeaf2;
            border-radius: 20px;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #16b5f4, #168ce8);
            border-radius: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group:last-child {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #344054;
            font-size: 13px;
            font-weight: 700;
        }

        body.dark-mode .form-group label {
            color: #dce6f3;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            height: 46px;
            padding: 0 14px;
            border: 1px solid #dce3eb;
            border-radius: 10px;
            background: #ffffff;
            color: #172033;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 14px;
            outline: none;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: #20b9f5;
            box-shadow: 0 0 0 3px rgba(32, 185, 245, .12);
        }

        .primary-btn {
            border: none;
            background: linear-gradient(135deg, #16b5f4, #168ce8);
            color: #ffffff;
            padding: 12px 19px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 7px 18px rgba(22, 140, 232, .20);
        }

        .primary-btn:hover {
            transform: translateY(-1px);
        }

        .setting-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding: 17px 0;
            border-bottom: 1px solid #edf1f5;
        }

        body.dark-mode .setting-row {
            border-color: #26344a;
        }

        .setting-row:first-child {
            padding-top: 0;
        }

        .setting-row:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .setting-info strong {
            display: block;
            margin-bottom: 4px;
            font-size: 14px;
            color: #172033;
        }

        .setting-info span {
            display: block;
            color: #8491a5;
            font-size: 12px;
            line-height: 1.5;
        }

        .setting-select {
            min-width: 140px;
            height: 40px;
            padding: 0 10px;
            border: 1px solid #dce3eb;
            border-radius: 9px;
            background: #ffffff;
            color: #172033;
            font-family: Arial, Helvetica, sans-serif;
            outline: none;
        }

        .switch {
            position: relative;
            width: 46px;
            height: 25px;
            flex-shrink: 0;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            inset: 0;
            background: #d8dee8;
            border-radius: 20px;
            cursor: pointer;
            transition: .2s ease;
        }

        .slider:before {
            content: "";
            position: absolute;
            width: 19px;
            height: 19px;
            left: 3px;
            top: 3px;
            background: white;
            border-radius: 50%;
            box-shadow: 0 2px 5px rgba(0,0,0,.15);
            transition: .2s ease;
        }

        .switch input:checked + .slider {
            background: #168ce8;
        }

        .switch input:checked + .slider:before {
            transform: translateX(21px);
        }

        .security-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding: 16px;
            background: #f8fafc;
            border: 1px solid #edf1f5;
            border-radius: 11px;
            margin-bottom: 10px;
        }

        body.dark-mode .security-item {
            background: #0c1526;
            border-color: #26344a;
        }

        .security-left {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .security-icon {
            width: 38px;
            height: 38px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            background: #ffffff;
            border: 1px solid #e5eaf0;
            font-size: 17px;
            flex-shrink: 0;
        }

        body.dark-mode .security-icon {
            background: #111b2e;
            border-color: #33445d;
        }

        .security-left strong {
            display: block;
            font-size: 13px;
            color: #172033;
            margin-bottom: 3px;
        }

        .security-left span {
            display: block;
            font-size: 11px;
            color: #8491a5;
        }

        .status {
            padding: 6px 9px;
            border-radius: 7px;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
        }

        .status.ok {
            background: #eafaf1;
            color: #16804b;
        }

        .status.warn {
            background: #fff6df;
            color: #9a6700;
        }

        .security-form {
            padding: 14px 16px 6px;
            border-top: 1px solid #edf1f5;
            margin-top: 14px;
        }

        body.dark-mode .security-form {
            border-color: #26344a;
        }

        .security-form.hidden {
            display: none;
        }

        .inline-form {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .inline-form input {
            flex: 1;
            min-width: 180px;
            height: 40px;
            padding: 0 12px;
            border: 1px solid #dce3eb;
            border-radius: 9px;
            outline: none;
        }

        .password-card {
            margin-top: 14px;
            padding-top: 18px;
            border-top: 1px solid #edf1f5;
        }

        body.dark-mode .password-card {
            border-color: #26344a;
        }

        .twofa-panel {
            margin-top: 12px;
            padding: 14px;
            background: #f8fafc;
            border-radius: 11px;
            border: 1px solid #edf1f5;
        }

        body.dark-mode .twofa-panel {
            background: #0c1526;
            border-color: #26344a;
        }

        .danger-card {
            border-color: #f0d5d5;
        }

        .danger-card .card-icon {
            background: #fff0f0;
        }

        .danger-title {
            color: #c0392b !important;
        }

        .danger-text {
            color: #8491a5;
            font-size: 13px;
            line-height: 1.6;
            margin: 0;
        }

        .danger-button {
            margin-top: 16px;
            padding: 10px 15px;
            border: 1px solid #efb5b5;
            background: #fff6f6;
            color: #c0392b;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }

        @media (max-width: 1050px) {
            .settings-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 700px) {
            .settings-main {
                margin-left: 0;
                padding: 25px 18px;
            }

            .settings-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .profile-top {
                align-items: flex-start;
                flex-direction: column;
            }

            .setting-row,
            .security-item {
                align-items: flex-start;
                flex-direction: column;
            }

            .setting-select {
                width: 100%;
            }
        }

    </style>

</head>

<body>
<script>
    if (document.documentElement.classList.contains('dark-mode')) {
        document.body.classList.add('dark-mode');
    }
</script>

<?php include "sidebar.php"; ?>

<main class="settings-main">

    <div class="settings-header">

        <div class="settings-title">
            <h1>Settings</h1>
            <p>Personalize and protect your ExpenseTracker account.</p>
        </div>

        <div class="settings-badge">
            ⚙ Account Settings
        </div>

    </div>

    <?php if ($message !== ""): ?>

        <div class="alert <?= htmlspecialchars($message_type) ?>">
            <?= htmlspecialchars($message) ?>
        </div>

    <?php endif; ?>

    <?php if (!empty($is_admin)): ?>
        <div style="background: linear-gradient(135deg, rgba(22, 181, 244, 0.15), rgba(22, 140, 232, 0.08)); border: 1px solid rgba(56, 189, 248, 0.3); border-radius: 16px; padding: 18px 24px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;">
            <div style="display: flex; align-items: center; gap: 14px;">
                <div style="width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, #16b5f4, #168ce8); display: flex; align-items: center; justify-content: center; font-size: 20px;">🛡️</div>
                <div>
                    <h3 style="margin: 0; font-size: 15px; font-weight: 700; color: #ffffff;">Administrator Control Hub</h3>
                    <p style="margin: 3px 0 0; font-size: 13px; color: #8da2bb;">Manage user profiles, permissions, passwords, and global email/SMTP delivery servers.</p>
                </div>
            </div>
            <div style="display: flex; gap: 10px; align-items: center;">
                <a href="admin_users.php" style="background: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.15); color: #ffffff; text-decoration: none; padding: 10px 18px; border-radius: 10px; font-size: 13px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;">
                    👥 Manage Users
                </a>
                <a href="email_config.php" style="background: linear-gradient(135deg, #16b5f4, #168ce8); color: #ffffff; text-decoration: none; padding: 10px 18px; border-radius: 10px; font-size: 13px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 4px 12px rgba(22,140,232,0.25);">
                    ✉️ Email Config
                </a>
            </div>
        </div>
    <?php endif; ?>

    <div class="settings-grid">

        <div>

            <!-- PROFILE -->

            <section class="settings-card">

                <div class="card-header">

                    <div class="card-icon">👤</div>

                    <div>
                        <h2>Profile & Personalization</h2>
                        <p>Manage your personal information and profile photo.</p>
                    </div>

                </div>

                <div class="card-body">

                    <div class="profile-top">

                        <div class="profile-photo-wrap">

                            <div class="profile-photo">

                                <?php if ($profile_photo_url !== ""): ?>

                                    <img
                                        src="<?= htmlspecialchars($profile_photo_url) ?>"
                                        alt="Profile photo"
                                    >

                                <?php else: ?>

                                    <?= htmlspecialchars($initial) ?>

                                <?php endif; ?>

                            </div>

                            <div class="photo-actions">

                                <form method="POST" enctype="multipart/form-data">

                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="action" value="upload_photo">

                                    <label class="small-btn" for="profilePhotoInput">
                                        Upload Photo
                                    </label>

                                    <input
                                        id="profilePhotoInput"
                                        type="file"
                                        name="profile_photo"
                                        accept="image/jpeg,image/png,image/webp"
                                        style="display:none"
                                        onchange="this.form.submit()"
                                    >

                                </form>

                                <?php if ($profile_photo_url !== ""): ?>

                                    <form method="POST">

                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="action" value="remove_photo">

                                        <button
                                            type="submit"
                                            class="small-btn danger"
                                        >
                                            Remove
                                        </button>

                                    </form>

                                <?php endif; ?>

                            </div>

                        </div>


                        <div class="profile-info">

                            <h3>
                                <?= htmlspecialchars($display_name) ?>
                            </h3>

                            <p>
                                <?= htmlspecialchars($display_email) ?>
                            </p>

                            <span class="verified">
                                ✓ Account Active
                            </span>

                        </div>

                    </div>


                    <div class="completion-box">

                        <div class="completion-top">

                            <strong>Profile completion</strong>

                            <span><?= $completion ?>%</span>

                        </div>

                        <div class="progress">

                            <div
                                class="progress-bar"
                                style="width: <?= $completion ?>%;"
                            ></div>

                        </div>

                    </div>


                    <form method="POST">

                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="action" value="update_profile">

                        <div class="form-group">

                            <label for="name">Full Name</label>

                            <input
                                type="text"
                                id="name"
                                name="name"
                                value="<?= htmlspecialchars($display_name) ?>"
                                maxlength="100"
                                autocomplete="name"
                                required
                            >

                        </div>


                        <div class="form-group">

                            <label for="email">Email Address</label>

                            <input
                                type="email"
                                id="email"
                                value="<?= htmlspecialchars($display_email) ?>"
                                readonly
                            >

                        </div>


                        <button
                            type="submit"
                            class="primary-btn"
                        >
                            Save Profile
                        </button>

                    </form>

                </div>

            </section>


            <!-- APPEARANCE -->

            <section class="settings-card">

                <div class="card-header">

                    <div class="card-icon">🎨</div>

                    <div>
                        <h2>Appearance</h2>
                        <p>Customize how ExpenseTracker looks.</p>
                    </div>

                </div>

                <div class="card-body">

                    <div class="setting-row">

                        <div class="setting-info">
                            <strong>Theme</strong>
                            <span>Choose light, dark, or system appearance.</span>
                        </div>

                        <select class="setting-select" id="themeSelect">

                            <option value="light">☀ Light</option>
                            <option value="dark">🌙 Dark</option>
                            <option value="system">💻 System</option>

                        </select>

                    </div>


                    <div class="setting-row">

                        <div class="setting-info">
                            <strong>Compact Interface</strong>
                            <span>Reduce spacing between cards and controls.</span>
                        </div>

                        <label class="switch">

                            <input type="checkbox" id="compactMode">

                            <span class="slider"></span>

                        </label>

                    </div>


                    <div class="setting-row">

                        <div class="setting-info">
                            <strong>Animations</strong>
                            <span>Enable interface animations and transitions.</span>
                        </div>

                        <label class="switch">

                            <input
                                type="checkbox"
                                id="animations"
                                checked
                            >

                            <span class="slider"></span>

                        </label>

                    </div>

                </div>

            </section>


            <!-- FINANCIAL -->

            <section class="settings-card">

                <div class="card-header">

                    <div class="card-icon">💰</div>

                    <div>
                        <h2>Financial Preferences</h2>
                        <p>Set your preferred financial display options.</p>
                    </div>

                </div>

                <div class="card-body">

                    <div class="setting-row">

                        <div class="setting-info">
                            <strong>Currency</strong>
                            <span>Currency used throughout the application.</span>
                        </div>

                        <select class="setting-select">

                            <option selected>₹ INR</option>
                            <option>$ USD</option>
                            <option>€ EUR</option>
                            <option>£ GBP</option>

                        </select>

                    </div>


                    <div class="setting-row">

                        <div class="setting-info">
                            <strong>Date Format</strong>
                            <span>Choose how dates are displayed.</span>
                        </div>

                        <select class="setting-select">

                            <option selected>DD/MM/YYYY</option>
                            <option>MM/DD/YYYY</option>
                            <option>YYYY-MM-DD</option>

                        </select>

                    </div>

                </div>

            </section>


            <!-- NOTIFICATIONS -->

            <section class="settings-card">

                <div class="card-header">

                    <div class="card-icon">🔔</div>

                    <div>
                        <h2>Notifications</h2>
                        <p>Control financial alerts and reminders.</p>
                    </div>

                </div>

                <div class="card-body">

                    <div class="setting-row">

                        <div class="setting-info">
                            <strong>Budget Alerts</strong>
                            <span>Get notified when you approach your budget limit.</span>
                        </div>

                        <label class="switch">
                            <input type="checkbox" checked>
                            <span class="slider"></span>
                        </label>

                    </div>


                    <div class="setting-row">

                        <div class="setting-info">
                            <strong>Financial Goal Reminders</strong>
                            <span>Receive reminders about your savings goals.</span>
                        </div>

                        <label class="switch">
                            <input type="checkbox" checked>
                            <span class="slider"></span>
                        </label>

                    </div>


                    <div class="setting-row">

                        <div class="setting-info">
                            <strong>Monthly Summary</strong>
                            <span>Receive a summary of your monthly finances.</span>
                        </div>

                        <label class="switch">
                            <input type="checkbox" checked>
                            <span class="slider"></span>
                        </label>

                    </div>

                </div>

            </section>

        </div>


        <div>

            <!-- SECURITY -->

            <section class="settings-card">

                <div class="card-header">

                    <div class="card-icon">🔐</div>

                    <div>
                        <h2>Security</h2>
                        <p>Protect your account and authentication.</p>
                    </div>

                </div>

                <div class="card-body">


                    <!-- EMAIL -->

                    <div class="security-item">

                        <div class="security-left">

                            <div class="security-icon">📧</div>

                            <div>

                                <strong>Email Verification</strong>

                                <span>
                                    <?= $email_verified
                                        ? "Your email address is verified."
                                        : "Verify your email address with a fresh OTP." ?>
                                </span>

                            </div>

                        </div>

                        <span class="status <?= $email_verified ? "ok" : "warn" ?>">

                            <?= $email_verified ? "VERIFIED" : "NOT VERIFIED" ?>

                        </span>

                    </div>

                    <?php if (!$email_verified): ?>

                        <form method="POST" class="security-form">

                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="action" value="send_email_verification">

                            <button type="submit" class="primary-btn">
                                Send Email OTP
                            </button>

                        </form>

                    <?php endif; ?>


                    <!-- MOBILE -->

                    <div class="security-item">

                        <div class="security-left">

                            <div class="security-icon">📱</div>

                            <div>

                                <strong>Mobile Verification</strong>

                                <span>
                                    <?= $phone_verified
                                        ? "Mobile number verified."
                                        : "Add and verify your mobile number with OTP." ?>
                                </span>

                            </div>

                        </div>

                        <span class="status <?= $phone_verified ? "ok" : "warn" ?>">

                            <?= $phone_verified ? "VERIFIED" : "NOT VERIFIED" ?>

                        </span>

                    </div>


                    <form method="POST" class="security-form">

                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="action" value="save_phone">

                        <div class="form-group">

                            <label for="phone">Mobile Number</label>

                            <input
                                type="tel"
                                id="phone"
                                name="phone"
                                value="<?= htmlspecialchars($user["phone"] ?? "") ?>"
                                placeholder="+91XXXXXXXXXX"
                                autocomplete="tel"
                            >

                        </div>

                        <button type="submit" class="primary-btn">
                            Save Mobile Number
                        </button>

                        <?php if (!empty($user["phone"]) && !$phone_verified): ?>

                            <p style="font-size:12px;color:#8491a5;margin:12px 0 0;">
                                SMS OTP delivery requires an SMS provider/API. The database and verification workflow are ready, but no SMS provider is configured yet.
                            </p>

                        <?php endif; ?>

                    </form>


                    <!-- PASSWORD -->

                    <div class="security-item" style="margin-top:10px;">

                        <div class="security-left">

                            <div class="security-icon">🔑</div>

                            <div>
                                <strong>Change Password</strong>
                                <span>Update your account password securely.</span>
                            </div>

                        </div>

                        <span class="status ok">AVAILABLE</span>

                    </div>


                    <div class="password-card">

                        <form method="POST">

                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="action" value="change_password">

                            <div class="form-group">

                                <label for="current_password">
                                    Current Password
                                </label>

                                <input
                                    type="password"
                                    id="current_password"
                                    name="current_password"
                                    autocomplete="current-password"
                                    required
                                >

                            </div>


                            <div class="form-group">

                                <label for="new_password">
                                    New Password
                                </label>

                                <input
                                    type="password"
                                    id="new_password"
                                    name="new_password"
                                    minlength="8"
                                    autocomplete="new-password"
                                    required
                                >

                            </div>


                            <div class="form-group">

                                <label for="confirm_password">
                                    Confirm New Password
                                </label>

                                <input
                                    type="password"
                                    id="confirm_password"
                                    name="confirm_password"
                                    minlength="8"
                                    autocomplete="new-password"
                                    required
                                >

                            </div>


                            <button type="submit" class="primary-btn">
                                Update Password
                            </button>

                        </form>

                    </div>


                    <!-- 2FA -->

                    <div class="security-item" style="margin-top:22px;">

                        <div class="security-left">

                            <div class="security-icon">🛡️</div>

                            <div>

                                <strong>Two-Factor Authentication</strong>

                                <span>
                                    <?= $two_factor_enabled
                                        ? "2FA is enabled using " . strtoupper($two_factor_method) . "."
                                        : "Add an extra layer of account protection." ?>
                                </span>

                            </div>

                        </div>

                        <span class="status <?= $two_factor_enabled ? "ok" : "warn" ?>">

                            <?= $two_factor_enabled ? "ENABLED" : "DISABLED" ?>

                        </span>

                    </div>


                    <div class="twofa-panel">

                        <form method="POST">

                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="action" value="toggle_2fa">

                            <div class="setting-row">

                                <div class="setting-info">

                                    <strong>Enable 2FA</strong>

                                    <span>
                                        A fresh OTP will be required when you sign in.
                                    </span>

                                </div>

                                <label class="switch">

                                    <input
                                        type="checkbox"
                                        name="enable_2fa"
                                        value="1"
                                        <?= $two_factor_enabled ? "checked" : "" ?>
                                        onchange="this.form.submit()"
                                    >

                                    <span class="slider"></span>

                                </label>

                            </div>


                            <div class="form-group" style="margin-top:18px;">

                                <label for="two_factor_method">
                                    Verification Method
                                </label>

                                <select
                                    id="two_factor_method"
                                    name="two_factor_method"
                                    onchange="this.form.submit()"
                                >

                                    <option
                                        value="email"
                                        <?= $two_factor_method === "email" ? "selected" : "" ?>
                                    >
                                        Email OTP
                                    </option>

                                    <option
                                        value="sms"
                                        <?= $two_factor_method === "sms" ? "selected" : "" ?>
                                    >
                                        SMS OTP
                                    </option>

                                </select>

                            </div>

                        </form>

                        <?php if (!$email_verified): ?>

                            <p style="font-size:12px;color:#9a6700;line-height:1.5;">
                                Verify your email before enabling email-based 2FA.
                            </p>

                        <?php elseif ($two_factor_method === "sms" && !$phone_verified): ?>

                            <p style="font-size:12px;color:#9a6700;line-height:1.5;">
                                Verify your mobile number before enabling SMS-based 2FA.
                            </p>

                        <?php endif; ?>

                    </div>

                </div>

            </section>


            <!-- DASHBOARD -->

            <section class="settings-card">

                <div class="card-header">

                    <div class="card-icon">📊</div>

                    <div>
                        <h2>Dashboard</h2>
                        <p>Customize your dashboard experience.</p>
                    </div>

                </div>

                <div class="card-body">

                    <div class="setting-row">

                        <div class="setting-info">
                            <strong>Financial Overview</strong>
                            <span>Show your income, expenses and balance.</span>
                        </div>

                        <label class="switch">
                            <input type="checkbox" checked>
                            <span class="slider"></span>
                        </label>

                    </div>


                    <div class="setting-row">

                        <div class="setting-info">
                            <strong>Recent Transactions</strong>
                            <span>Display recent financial activity.</span>
                        </div>

                        <label class="switch">
                            <input type="checkbox" checked>
                            <span class="slider"></span>
                        </label>

                    </div>


                    <div class="setting-row">

                        <div class="setting-info">
                            <strong>Spending Categories</strong>
                            <span>Show category spending information.</span>
                        </div>

                        <label class="switch">
                            <input type="checkbox" checked>
                            <span class="slider"></span>
                        </label>

                    </div>

                </div>

            </section>


            <!-- DATA -->

            <section class="settings-card">

                <div class="card-header">

                    <div class="card-icon">💾</div>

                    <div>
                        <h2>Data & Privacy</h2>
                        <p>Manage your financial information.</p>
                    </div>

                </div>

                <div class="card-body">

                    <div class="security-item">

                        <div class="security-left">

                            <div class="security-icon">📄</div>

                            <div>
                                <strong>Export Financial Data</strong>
                                <span>Download your financial records.</span>
                            </div>

                        </div>

                        <span class="status warn">COMING SOON</span>

                    </div>


                    <div class="security-item">

                        <div class="security-left">

                            <div class="security-icon">🔒</div>

                            <div>
                                <strong>Privacy</strong>
                                <span>Review how your account data is handled.</span>
                            </div>

                        </div>

                        <span class="status warn">COMING SOON</span>

                    </div>

                </div>

            </section>


            <!-- DANGER -->

            <section class="settings-card danger-card">

                <div class="card-header">

                    <div class="card-icon">⚠️</div>

                    <div>
                        <h2 class="danger-title">Danger Zone</h2>
                        <p>Permanent account actions.</p>
                    </div>

                </div>

                <div class="card-body">

                    <p class="danger-text">
                        Account deletion is permanent and will remove your account
                        and associated financial information. We will add the secure
                        deletion workflow later.
                    </p>

                    <button
                        type="button"
                        class="danger-button"
                        onclick="alert('Account deletion will be added after the security system is completed.')"
                    >
                        Delete Account
                    </button>

                </div>

            </section>

        </div>

    </div>

</main>


<script>

const themeSelect = document.getElementById("themeSelect");

const savedTheme =
    localStorage.getItem("expenseTrackerTheme") || "light";

themeSelect.value = savedTheme;


function applyTheme(theme) {

    let dark = false;

    if (theme === "dark") {
        dark = true;
    }

    if (theme === "system") {
        dark = window.matchMedia(
            "(prefers-color-scheme: dark)"
        ).matches;
    }

    document.body.classList.toggle(
        "dark-mode",
        dark
    );
}


applyTheme(savedTheme);


themeSelect.addEventListener(
    "change",
    function () {
        if (typeof window.setExpenseTheme === "function") {
            window.setExpenseTheme(this.value);
        } else {
            localStorage.setItem("expenseTrackerTheme", this.value);
            applyTheme(this.value);
        }
    }
);


window.matchMedia(
    "(prefers-color-scheme: dark)"
).addEventListener(
    "change",
    function () {

        if (
            localStorage.getItem("expenseTrackerTheme") === "system"
        ) {
            applyTheme("system");
        }

    }
);


const profilePhotoInput =
    document.getElementById("profilePhotoInput");

if (profilePhotoInput) {

    profilePhotoInput.addEventListener(
        "change",
        function () {

            if (
                this.files &&
                this.files[0] &&
                this.files[0].size > 5 * 1024 * 1024
            ) {

                alert(
                    "Profile photo must be 5 MB or smaller."
                );

                this.value = "";
            }

        }
    );
}

</script>

</body>
</html>
