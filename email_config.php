<?php

/**
 * Admin Email & SMTP Configuration Management
 * Personal Expense Tracker
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "auth.php";
require_once "mailer.php";

// Enforce Admin Access
if (empty($is_admin)) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Access Denied | Expense Tracker</title>
        <style>
            body { font-family: Arial, sans-serif; background: #070b12; color: #fff; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; text-align: center; }
            .card { background: #101c2d; padding: 40px; border-radius: 16px; border: 1px solid rgba(255,255,255,0.1); max-width: 420px; }
            h1 { color: #f87171; font-size: 24px; margin-bottom: 12px; }
            p { color: #94a3b8; font-size: 14px; line-height: 1.6; margin-bottom: 24px; }
            a { display: inline-block; padding: 10px 24px; background: #2563eb; color: #fff; text-decoration: none; border-radius: 10px; font-weight: 600; }
        </style>
    </head>
    <body>
        <div class="card">
            <h1>Access Restricted</h1>
            <p>You do not have administrative privileges to manage system email and SMTP settings.</p>
            <a href="dashboard.php">Return to Dashboard</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$message = "";
$message_type = "";
$test_log = "";

// Handle AJAX test email request
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ajax_test_email"])) {
    header('Content-Type: application/json');
    $test_to = trim($_POST["test_to"] ?? "");

    if (empty($test_to) || !filter_var($test_to, FILTER_VALIDATE_EMAIL)) {
        echo json_encode([
            'success' => false,
            'message' => 'Please enter a valid recipient email address for testing.'
        ]);
        exit;
    }

    $settings = get_email_settings($conn);

    // Optionally allow testing unsaved form values
    if (!empty($_POST["smtp_host"])) {
        $settings['smtp_host'] = trim($_POST["smtp_host"]);
        $settings['smtp_port'] = (int)($_POST["smtp_port"] ?? 587);
        $settings['smtp_encryption'] = trim($_POST["smtp_encryption"] ?? "tls");
        $settings['smtp_username'] = trim($_POST["smtp_username"] ?? "");
        if (!empty($_POST["smtp_password"])) {
            $settings['smtp_password'] = $_POST["smtp_password"];
        }
        if (!empty($_POST["from_email"])) {
            $settings['from_email'] = trim($_POST["from_email"]);
        }
        if (!empty($_POST["from_name"])) {
            $settings['from_name'] = trim($_POST["from_name"]);
        }
    }

    $subject = "SMTP Test Email - Personal Expense Tracker";
    $badge = "System Diagnostic";
    $title = "SMTP Connection Successful!";
    $body_html = "Hello Administrator,<br><br>" .
        "This is a test message confirming that your <strong>SMTP configuration</strong> in Personal Expense Tracker is working properly.<br><br>" .
        "<table style=\"width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 13px; color: #cbd5e1;\">" .
        "<tr style=\"border-bottom: 1px solid rgba(255,255,255,0.08);\"><td style=\"padding: 8px 0; color: #64748b;\">SMTP Host:</td><td style=\"padding: 8px 0; font-weight: bold;\">" . htmlspecialchars($settings['smtp_host']) . "</td></tr>" .
        "<tr style=\"border-bottom: 1px solid rgba(255,255,255,0.08);\"><td style=\"padding: 8px 0; color: #64748b;\">Port / Encryption:</td><td style=\"padding: 8px 0;\">" . htmlspecialchars($settings['smtp_port']) . " / " . strtoupper(htmlspecialchars($settings['smtp_encryption'])) . "</td></tr>" .
        "<tr style=\"border-bottom: 1px solid rgba(255,255,255,0.08);\"><td style=\"padding: 8px 0; color: #64748b;\">From Email:</td><td style=\"padding: 8px 0;\">" . htmlspecialchars($settings['from_email'] ?: $settings['smtp_username']) . "</td></tr>" .
        "<tr><td style=\"padding: 8px 0; color: #64748b;\">Server Timestamp:</td><td style=\"padding: 8px 0;\">" . date('Y-m-d H:i:s T') . "</td></tr>" .
        "</table>";

    $highlight = "<div style=\"color: #10b981; font-weight: bold; font-size: 16px;\">✓ Email Services Ready for 2FA &amp; Alerts</div>";
    $footer = "Sent by Personal Expense Tracker Admin Diagnostic Tool.";

    $html = get_email_html_template($badge, $title, $body_html, $highlight, $footer);
    $text = "SMTP Test Successful! Your mail settings are configured properly.\nHost: {$settings['smtp_host']}\nPort: {$settings['smtp_port']}\nTimestamp: " . date('Y-m-d H:i:s');

    $result = smtp_send_mail($settings, $test_to, $subject, $html, $text, "System Admin");
    echo json_encode($result);
    exit;
}

// Handle Form Submissions (Standard POST)
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Action: Save Configuration
    if (isset($_POST["save_settings"])) {
        $smtp_enabled = isset($_POST["smtp_enabled"]) ? 1 : 0;
        $smtp_host = trim($_POST["smtp_host"] ?? "smtp.gmail.com");
        $smtp_port = (int)($_POST["smtp_port"] ?? 587);
        $smtp_encryption = trim($_POST["smtp_encryption"] ?? "tls");
        $smtp_username = trim($_POST["smtp_username"] ?? "");
        $smtp_password = $_POST["smtp_password"] ?? "";
        $from_email = trim($_POST["from_email"] ?? "");
        $from_name = trim($_POST["from_name"] ?? "Personal Expense Tracker");

        $save_data = [
            'smtp_enabled' => $smtp_enabled,
            'smtp_host' => $smtp_host,
            'smtp_port' => $smtp_port,
            'smtp_encryption' => $smtp_encryption,
            'smtp_username' => $smtp_username,
            'from_email' => $from_email,
            'from_name' => $from_name
        ];

        if ($smtp_password !== "") {
            $save_data['smtp_password'] = $smtp_password;
        }

        if (save_email_settings($conn, $save_data)) {
            $message = "Email configuration updated successfully!";
            $message_type = "success";
        } else {
            $message = "Unable to save email configuration. Please try again.";
            $message_type = "error";
        }
    }

    // Action: Send Synchronous Test Email
    if (isset($_POST["send_test_email"])) {
        $test_email = trim($_POST["test_email"] ?? "");

        if (empty($test_email) || !filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
            $message = "Please enter a valid recipient email address.";
            $message_type = "error";
        } else {
            $settings = get_email_settings($conn);
            $subject = "SMTP Test Email - Personal Expense Tracker";
            $badge = "System Diagnostic";
            $title = "SMTP Connection Successful!";
            $body_html = "Hello Administrator,<br><br>" .
                "This test email confirms that your <strong>SMTP configuration</strong> in Personal Expense Tracker is active and delivering messages successfully.<br><br>" .
                "<strong>Host:</strong> " . htmlspecialchars($settings['smtp_host']) . "<br>" .
                "<strong>Port:</strong> " . htmlspecialchars($settings['smtp_port']) . " (" . strtoupper(htmlspecialchars($settings['smtp_encryption'])) . ")<br>" .
                "<strong>Time:</strong> " . date('Y-m-d H:i:s');

            $highlight = "<span style=\"color: #10b981; font-weight: bold;\">✓ SMTP Connection Active</span>";
            $html = get_email_html_template($badge, $title, $body_html, $highlight, "Personal Expense Tracker Mailer");
            $text = "SMTP Test Email: connection successful to " . $settings['smtp_host'];

            $res = smtp_send_mail($settings, $test_email, $subject, $html, $text, "Admin");
            $test_log = $res['log'] ?? "";

            if (!empty($res['success'])) {
                $message = "Test email sent successfully to " . htmlspecialchars($test_email) . "!";
                $message_type = "success";
            } else {
                $message = "Failed to send test email: " . htmlspecialchars($res['message']);
                $message_type = "error";
            }
        }
    }
}

// Fetch Current Settings
$email_settings = get_email_settings($conn);
$has_saved_password = !empty($email_settings['smtp_password']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Configuration (Admin) | Personal Expense Tracker</title>
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
    <link rel="stylesheet" href="responsive_mobile.css">

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #090e17;
            color: #f1f5f9;
            min-height: 100vh;
        }

        .app-layout {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            margin-left: 250px;
            flex: 1;
            padding: 35px 40px;
            max-width: 1200px;
        }

        /* HEADER */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title h1 {
            font-size: 28px;
            font-weight: 800;
            letter-spacing: -0.5px;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .badge-admin {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 4px 10px;
            border-radius: 20px;
            background: rgba(32, 185, 245, 0.15);
            color: #38bdf8;
            border: 1px solid rgba(56, 189, 248, 0.3);
        }

        .page-title p {
            color: #8da2bb;
            font-size: 14px;
            margin-top: 6px;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 700;
            background: rgba(16, 28, 45, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .status-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }

        .status-indicator.active {
            background: #10b981;
            box-shadow: 0 0 10px #10b981;
        }

        .status-indicator.inactive {
            background: #ef4444;
            box-shadow: 0 0 10px #ef4444;
        }

        /* ALERTS */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .alert.success {
            background: rgba(16, 185, 129, 0.12);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #6ee7b7;
        }

        .alert.error {
            background: rgba(239, 68, 68, 0.12);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }

        /* CARDS */
        .card {
            background: #101c2d;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 18px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .card-header {
            margin-bottom: 25px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            padding-bottom: 15px;
        }

        .card-header h2 {
            font-size: 19px;
            font-weight: 700;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-header p {
            color: #7d92ab;
            font-size: 13px;
            margin-top: 4px;
        }

        /* PRESET BUTTONS */
        .presets-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }

        .preset-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.09);
            color: #cbd5e1;
            padding: 9px 14px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .preset-btn:hover {
            background: rgba(32, 185, 245, 0.15);
            border-color: #38bdf8;
            color: #ffffff;
            transform: translateY(-1px);
        }

        /* FORMS */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 700;
            color: #cbd5e1;
        }

        .form-control {
            width: 100%;
            height: 48px;
            padding: 0 16px;
            border-radius: 11px;
            background: #090e17;
            border: 1px solid #1e2d42;
            color: #ffffff;
            font-size: 14px;
            outline: none;
            transition: all 0.2s;
        }

        .form-control:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.12);
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%238da2bb' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 14px center;
            background-size: 16px;
            padding-right: 40px;
        }

        .password-wrapper {
            position: relative;
        }

        .password-wrapper input {
            padding-right: 45px;
        }

        .toggle-pw-btn {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: #64748b;
            font-size: 16px;
            cursor: pointer;
        }

        .toggle-pw-btn:hover {
            color: #ffffff;
        }

        .hint {
            font-size: 12px;
            color: #64748b;
            margin-top: 6px;
            line-height: 1.4;
        }

        .hint strong {
            color: #94a3b8;
        }

        /* TOGGLE SWITCH */
        .switch-group {
            display: flex;
            align-items: center;
            gap: 14px;
            background: #090e17;
            padding: 14px 18px;
            border-radius: 12px;
            border: 1px solid #1e2d42;
            margin-bottom: 24px;
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 48px;
            height: 26px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #1e293b;
            transition: .3s;
            border-radius: 34px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        input:checked + .slider:before {
            transform: translateX(22px);
        }

        .switch-label h4 {
            font-size: 14px;
            font-weight: 700;
            color: #ffffff;
        }

        .switch-label p {
            font-size: 12px;
            color: #64748b;
            margin-top: 2px;
        }

        /* BUTTONS */
        .btn-primary {
            background: linear-gradient(135deg, #16b5f4, #168ce8);
            border: none;
            color: #ffffff;
            font-weight: 700;
            font-size: 15px;
            padding: 14px 28px;
            border-radius: 11px;
            cursor: pointer;
            transition: all 0.25s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 8px 20px rgba(22, 140, 232, 0.25);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 25px rgba(22, 140, 232, 0.35);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #ffffff;
            font-weight: 700;
            font-size: 14px;
            padding: 12px 22px;
            border-radius: 11px;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.14);
            transform: translateY(-1px);
        }

        /* TEST EMAIL AREA */
        .test-email-container {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
            margin-top: 15px;
        }

        .test-email-container input {
            flex: 1;
            min-width: 250px;
        }

        .log-box {
            margin-top: 18px;
            background: #060911;
            border: 1px solid #1e293b;
            border-radius: 10px;
            padding: 16px;
            font-family: "Courier New", Courier, monospace;
            font-size: 12px;
            color: #a5f3fc;
            max-height: 250px;
            overflow-y: auto;
            white-space: pre-wrap;
            line-height: 1.5;
        }

        /* GUIDE CARDS */
        .guide-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 15px;
        }

        .guide-item {
            background: #090e17;
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 12px;
            padding: 18px;
        }

        .guide-item h4 {
            font-size: 14px;
            color: #38bdf8;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .guide-item p, .guide-item li {
            font-size: 12px;
            color: #94a3b8;
            line-height: 1.6;
        }

        .guide-item ol {
            padding-left: 20px;
            margin-top: 6px;
        }

        @media (max-width: 900px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            .form-grid, .guide-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="app-layout">

    <!-- Include standard application sidebar -->
    <?php include "sidebar.php"; ?>

    <main class="main-content">

        <!-- Top Header -->
        <div class="page-header">
            <div class="page-title">
                <h1>
                    <span>✉️</span> Email Configuration
                    <span class="badge-admin">Admin Control</span>
                </h1>
                <p>Configure and manage the global SMTP mail server used for sending 2FA codes, account verifications, and notifications.</p>
            </div>

            <div class="status-pill">
                <span class="status-indicator <?php echo !empty($email_settings['smtp_enabled']) ? 'active' : 'inactive'; ?>"></span>
                <span><?php echo !empty($email_settings['smtp_enabled']) ? 'SMTP Active' : 'SMTP Disabled'; ?></span>
            </div>
        </div>

        <!-- Notification Alerts -->
        <?php if (!empty($message)): ?>
            <div class="alert <?php echo htmlspecialchars($message_type); ?>">
                <span><?php echo htmlspecialchars($message); ?></span>
                <span style="cursor: pointer;" onclick="this.parentElement.style.display='none';">&times;</span>
            </div>
        <?php endif; ?>

        <!-- Live AJAX Alert Container -->
        <div id="ajaxAlert" style="display: none;" class="alert"></div>

        <!-- Quick Presets Card -->
        <div class="card" style="padding: 22px 30px;">
            <div style="font-size: 13px; font-weight: 700; color: #8da2bb; margin-bottom: 12px; text-transform: uppercase; letter-spacing: 0.8px;">
                ⚡ Fast Setup Presets (Click to autofill host, port &amp; encryption):
            </div>
            <div class="presets-row">
                <button type="button" class="preset-btn" onclick="applyPreset('gmail')">
                    <span>🔴</span> Google Gmail (App Password)
                </button>
                <button type="button" class="preset-btn" onclick="applyPreset('outlook')">
                    <span>🔵</span> Microsoft Outlook / Office 365
                </button>
                <button type="button" class="preset-btn" onclick="applyPreset('brevo')">
                    <span>🟢</span> Brevo (Sendinblue)
                </button>
                <button type="button" class="preset-btn" onclick="applyPreset('sendgrid')">
                    <span>🟣</span> SendGrid
                </button>
                <button type="button" class="preset-btn" onclick="applyPreset('custom_ssl')">
                    <span>🔒</span> Standard SSL (Port 465)
                </button>
                <button type="button" class="preset-btn" onclick="applyPreset('custom_tls')">
                    <span>🌐</span> Standard TLS (Port 587)
                </button>
            </div>
        </div>

        <!-- Main Configuration Form -->
        <form method="POST" action="email_config.php">
            <input type="hidden" name="save_settings" value="1">

            <div class="card">
                <div class="card-header">
                    <h2><span>⚙️</span> SMTP Server Settings</h2>
                    <p>Enter your outgoing mail server credentials. All credentials are securely stored locally in the database.</p>
                </div>

                <!-- Enable / Disable SMTP Switch -->
                <div class="switch-group">
                    <label class="switch">
                        <input type="checkbox" name="smtp_enabled" id="smtp_enabled" <?php echo !empty($email_settings['smtp_enabled']) ? 'checked' : ''; ?>>
                        <span class="slider"></span>
                    </label>
                    <div class="switch-label">
                        <h4>Enable Outgoing SMTP Mail Server</h4>
                        <p>When enabled, system emails (2FA OTP, verification codes) will be dispatched directly through your configured SMTP server.</p>
                    </div>
                </div>

                <div class="form-grid">
                    <!-- SMTP Host -->
                    <div class="form-group">
                        <label for="smtp_host">SMTP Host Server *</label>
                        <input
                            type="text"
                            class="form-control"
                            id="smtp_host"
                            name="smtp_host"
                            placeholder="e.g. smtp.gmail.com"
                            value="<?php echo htmlspecialchars($email_settings['smtp_host']); ?>"
                            required
                        >
                        <div class="hint">Outgoing mail server hostname (e.g. <code>smtp.gmail.com</code>).</div>
                    </div>

                    <!-- SMTP Port -->
                    <div class="form-group">
                        <label for="smtp_port">SMTP Port *</label>
                        <input
                            type="number"
                            class="form-control"
                            id="smtp_port"
                            name="smtp_port"
                            placeholder="587"
                            value="<?php echo htmlspecialchars($email_settings['smtp_port']); ?>"
                            required
                        >
                        <div class="hint">Common ports: <strong>587</strong> (TLS - Recommended), <strong>465</strong> (SSL), or <strong>25</strong>.</div>
                    </div>

                    <!-- Encryption Type -->
                    <div class="form-group">
                        <label for="smtp_encryption">Security &amp; Encryption *</label>
                        <select class="form-control" id="smtp_encryption" name="smtp_encryption" required>
                            <option value="tls" <?php echo ($email_settings['smtp_encryption'] === 'tls') ? 'selected' : ''; ?>>TLS / STARTTLS (Port 587 - Recommended)</option>
                            <option value="ssl" <?php echo ($email_settings['smtp_encryption'] === 'ssl') ? 'selected' : ''; ?>>SSL (Port 465)</option>
                            <option value="none" <?php echo ($email_settings['smtp_encryption'] === 'none') ? 'selected' : ''; ?>>None / Plain (Port 25)</option>
                        </select>
                        <div class="hint">Select the encryption handshake protocol expected by your mail server.</div>
                    </div>

                    <!-- SMTP Username -->
                    <div class="form-group">
                        <label for="smtp_username">SMTP Username / Email *</label>
                        <input
                            type="text"
                            class="form-control"
                            id="smtp_username"
                            name="smtp_username"
                            placeholder="e.g. your-email@gmail.com"
                            value="<?php echo htmlspecialchars($email_settings['smtp_username']); ?>"
                            autocomplete="off"
                        >
                        <div class="hint">The email address or username used to authenticate with your provider.</div>
                    </div>

                    <!-- SMTP Password -->
                    <div class="form-group">
                        <label for="smtp_password">
                            SMTP Password / App Password
                            <?php if ($has_saved_password): ?>
                                <span style="font-size: 11px; color: #10b981; font-weight: normal;">(✓ Password already saved)</span>
                            <?php endif; ?>
                        </label>
                        <div class="password-wrapper">
                            <input
                                type="password"
                                class="form-control"
                                id="smtp_password"
                                name="smtp_password"
                                placeholder="<?php echo $has_saved_password ? '•••••••••••••••• (Leave blank to keep current)' : 'Enter SMTP password or 16-character Google App Password'; ?>"
                                autocomplete="new-password"
                            >
                            <button type="button" class="toggle-pw-btn" onclick="togglePasswordVisibility('smtp_password')" title="Toggle view">👁️</button>
                        </div>
                        <div class="hint">For Gmail, generate a 16-character <strong>App Password</strong> in your Google Account security settings.</div>
                    </div>

                    <!-- Sender Email Address -->
                    <div class="form-group">
                        <label for="from_email">Sender "From" Email Address</label>
                        <input
                            type="email"
                            class="form-control"
                            id="from_email"
                            name="from_email"
                            placeholder="e.g. no-reply@yourdomain.com (defaults to SMTP username)"
                            value="<?php echo htmlspecialchars($email_settings['from_email']); ?>"
                        >
                        <div class="hint">Address that appears in recipient inboxes as the sender.</div>
                    </div>

                    <!-- Sender From Name -->
                    <div class="form-group full">
                        <label for="from_name">Sender "From" Display Name</label>
                        <input
                            type="text"
                            class="form-control"
                            id="from_name"
                            name="from_name"
                            placeholder="Personal Expense Tracker"
                            value="<?php echo htmlspecialchars($email_settings['from_name']); ?>"
                        >
                        <div class="hint">The human-readable sender name displayed in email clients.</div>
                    </div>
                </div>

                <div style="margin-top: 15px; display: flex; gap: 15px; align-items: center;">
                    <button type="submit" class="btn-primary">
                        💾 Save Email Configuration
                    </button>
                    <?php if (!empty($email_settings['updated_at'])): ?>
                        <span style="font-size: 12px; color: #64748b;">
                            Last updated: <?php echo htmlspecialchars($email_settings['updated_at']); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <!-- Test Email Sender Card -->
        <div class="card">
            <div class="card-header">
                <h2><span>🚀</span> Live Connection &amp; Email Delivery Tester</h2>
                <p>Send a real test email to verify that your SMTP host, port, credentials, and TLS handshake are functioning correctly.</p>
            </div>

            <div class="test-email-container">
                <input
                    type="email"
                    id="testRecipient"
                    class="form-control"
                    placeholder="Recipient email (e.g. your personal email address)"
                    value="<?php echo htmlspecialchars($user_email); ?>"
                >
                <button type="button" id="btnLiveTest" class="btn-secondary" onclick="sendLiveTestEmail()">
                    <span id="testBtnSpinner" style="display: none;">⏳</span>
                    <span id="testBtnText">📨 Send Live Test Email</span>
                </button>
            </div>

            <!-- Diagnostic Log Output -->
            <div id="diagnosticLogWrapper" style="<?php echo !empty($test_log) ? 'display: block;' : 'display: none;'; ?> margin-top: 20px;">
                <div style="font-size: 13px; font-weight: 700; color: #8da2bb; margin-bottom: 8px; display: flex; justify-content: space-between;">
                    <span>SMTP Handshake &amp; Communication Log:</span>
                    <span style="cursor: pointer; color: #38bdf8;" onclick="copyLog()">Copy Log</span>
                </div>
                <div id="diagnosticLog" class="log-box"><?php echo htmlspecialchars($test_log); ?></div>
            </div>
        </div>

        <!-- Setup Instructions & Troubleshooting Cards -->
        <div class="card">
            <div class="card-header">
                <h2><span>📖</span> Setup Guide &amp; Provider Instructions</h2>
                <p>Detailed guidance for connecting popular mail service providers.</p>
            </div>

            <div class="guide-grid">
                <div class="guide-item">
                    <h4><span>🔴</span> How to use Google Gmail</h4>
                    <p>Gmail requires generating a dedicated <strong>App Password</strong> because regular passwords are blocked by 2FA:</p>
                    <ol>
                        <li>Go to your <a href="https://myaccount.google.com/security" target="_blank" style="color: #38bdf8;">Google Account Security</a> settings.</li>
                        <li>Ensure <strong>2-Step Verification</strong> is turned ON.</li>
                        <li>Search for or navigate to <strong>App passwords</strong>.</li>
                        <li>Enter a name (e.g. "Expense Tracker") and click <strong>Create</strong>.</li>
                        <li>Copy the 16-character code and paste it into the <strong>SMTP Password</strong> field above.</li>
                        <li>Click <em>Google Gmail</em> in the presets above and save!</li>
                    </ol>
                </div>

                <div class="guide-item">
                    <h4><span>⚡</span> What Features Depend on This?</h4>
                    <p>Once saved, the following application features immediately utilize this SMTP connection:</p>
                    <ul style="padding-left: 20px; margin-top: 8px;">
                        <li><strong>Two-Factor Authentication (2FA)</strong>: Sends 6-digit security codes when users log in.</li>
                        <li><strong>Email Verification</strong>: Sends OTP codes when users verify their profile email in Settings.</li>
                        <li><strong>Password Resets</strong>: Delivers recovery credentials securely.</li>
                        <li><strong>Branded HTML Templates</strong>: All messages are formatted with clean responsive cards.</li>
                    </ul>
                </div>
            </div>
        </div>

    </main>
</div>

<script>
// Preset configurations
function applyPreset(provider) {
    const host = document.getElementById("smtp_host");
    const port = document.getElementById("smtp_port");
    const enc = document.getElementById("smtp_encryption");

    if (provider === "gmail") {
        host.value = "smtp.gmail.com";
        port.value = "587";
        enc.value = "tls";
    } else if (provider === "outlook") {
        host.value = "smtp.office365.com";
        port.value = "587";
        enc.value = "tls";
    } else if (provider === "brevo") {
        host.value = "smtp-relay.brevo.com";
        port.value = "587";
        enc.value = "tls";
    } else if (provider === "sendgrid") {
        host.value = "smtp.sendgrid.net";
        port.value = "587";
        enc.value = "tls";
        document.getElementById("smtp_username").value = "apikey";
    } else if (provider === "custom_ssl") {
        port.value = "465";
        enc.value = "ssl";
    } else if (provider === "custom_tls") {
        port.value = "587";
        enc.value = "tls";
    }

    // Flash border highlight
    [host, port, enc].forEach(el => {
        el.style.borderColor = "#38bdf8";
        setTimeout(() => { el.style.borderColor = "#1e2d42"; }, 1500);
    });
}

// Toggle password visibility
function togglePasswordVisibility(fieldId) {
    const input = document.getElementById(fieldId);
    input.type = (input.type === "password") ? "text" : "password";
}

// Copy diagnostic log
function copyLog() {
    const logText = document.getElementById("diagnosticLog").innerText;
    navigator.clipboard.writeText(logText).then(() => {
        alert("SMTP log copied to clipboard!");
    });
}

// Live AJAX test email sender
function sendLiveTestEmail() {
    const recipient = document.getElementById("testRecipient").value.trim();
    if (!recipient) {
        alert("Please enter a valid recipient email address.");
        return;
    }

    const alertBox = document.getElementById("ajaxAlert");
    const logWrapper = document.getElementById("diagnosticLogWrapper");
    const logBox = document.getElementById("diagnosticLog");
    const spinner = document.getElementById("testBtnSpinner");
    const btnText = document.getElementById("testBtnText");

    // Show loading state
    spinner.style.display = "inline";
    btnText.textContent = "Connecting to SMTP...";
    alertBox.style.display = "none";

    const formData = new FormData();
    formData.append("ajax_test_email", "1");
    formData.append("test_to", recipient);
    formData.append("smtp_host", document.getElementById("smtp_host").value);
    formData.append("smtp_port", document.getElementById("smtp_port").value);
    formData.append("smtp_encryption", document.getElementById("smtp_encryption").value);
    formData.append("smtp_username", document.getElementById("smtp_username").value);
    formData.append("smtp_password", document.getElementById("smtp_password").value);
    formData.append("from_email", document.getElementById("from_email").value);
    formData.append("from_name", document.getElementById("from_name").value);

    fetch("email_config.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        spinner.style.display = "none";
        btnText.textContent = "📨 Send Live Test Email";

        alertBox.style.display = "flex";
        alertBox.className = "alert " + (data.success ? "success" : "error");
        alertBox.innerHTML = `<span>${data.message}</span><span style="cursor: pointer;" onclick="this.parentElement.style.display='none';">&times;</span>`;

        if (data.log) {
            logWrapper.style.display = "block";
            logBox.innerText = data.log;
        }
    })
    .catch(err => {
        spinner.style.display = "none";
        btnText.textContent = "📨 Send Live Test Email";

        alertBox.style.display = "flex";
        alertBox.className = "alert error";
        alertBox.innerHTML = `<span>Request failed: ${err.message}</span><span style="cursor: pointer;" onclick="this.parentElement.style.display='none';">&times;</span>`;
    });
}
</script>

</body>
</html>
