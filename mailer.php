<?php

/**
 * Mailer Helper & SMTP Engine for Personal Expense Tracker
 * 
 * Provides database-backed SMTP configuration, socket-based SMTP client,
 * diagnostic test mailer, and branded HTML templates.
 */

if (!defined('MAILER_INCLUDED')) {
    define('MAILER_INCLUDED', true);
}

/**
 * Retrieve email settings from database
 */
function get_email_settings(mysqli $conn): array
{
    require_once __DIR__ . "/env.php";

    $defaults = [
        'id' => 1,
        'smtp_enabled' => env('SMTP_ENABLED', true) ? 1 : 0,
        'smtp_host' => (string) env('SMTP_HOST', 'smtp.gmail.com'),
        'smtp_port' => (int) env('SMTP_PORT', 587),
        'smtp_encryption' => (string) env('SMTP_ENCRYPTION', 'tls'),
        'smtp_username' => (string) env('SMTP_USER', ''),
        'smtp_password' => (string) env('SMTP_PASS', ''),
        'from_email' => (string) env('FROM_EMAIL', ''),
        'from_name' => (string) env('FROM_NAME', 'Personal Expense Tracker'),
        'updated_at' => null
    ];

    $res = $conn->query("SELECT * FROM email_settings WHERE id = 1 LIMIT 1");
    if ($res && $row = $res->fetch_assoc()) {
        // Fallback to .env values if DB fields are empty
        foreach (['smtp_host', 'smtp_username', 'smtp_password', 'from_email'] as $k) {
            if (empty($row[$k]) && !empty($defaults[$k])) {
                $row[$k] = $defaults[$k];
            }
        }
        return array_merge($defaults, $row);
    }

    return $defaults;
}

/**
 * Save email settings to database
 */
function save_email_settings(mysqli $conn, array $data): bool
{
    $smtp_enabled = !empty($data['smtp_enabled']) ? 1 : 0;
    $smtp_host = trim($data['smtp_host'] ?? 'smtp.gmail.com');
    $smtp_port = (int) ($data['smtp_port'] ?? 587);
    $smtp_encryption = strtolower(trim($data['smtp_encryption'] ?? 'tls'));
    if (!in_array($smtp_encryption, ['tls', 'ssl', 'none'])) {
        $smtp_encryption = 'tls';
    }
    $smtp_username = trim($data['smtp_username'] ?? '');
    $from_email = trim($data['from_email'] ?? '');
    $from_name = trim($data['from_name'] ?? 'Personal Expense Tracker');

    // If password is provided, update it; otherwise preserve current password
    $has_new_password = isset($data['smtp_password']) && $data['smtp_password'] !== '';
    $smtp_password = $data['smtp_password'] ?? '';

    if ($has_new_password) {
        $stmt = $conn->prepare("
            INSERT INTO email_settings 
                (id, smtp_enabled, smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password, from_email, from_name)
            VALUES 
                (1, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                smtp_enabled = VALUES(smtp_enabled),
                smtp_host = VALUES(smtp_host),
                smtp_port = VALUES(smtp_port),
                smtp_encryption = VALUES(smtp_encryption),
                smtp_username = VALUES(smtp_username),
                smtp_password = VALUES(smtp_password),
                from_email = VALUES(from_email),
                from_name = VALUES(from_name)
        ");
        if (!$stmt) return false;
        $stmt->bind_param(
            "isisssss",
            $smtp_enabled,
            $smtp_host,
            $smtp_port,
            $smtp_encryption,
            $smtp_username,
            $smtp_password,
            $from_email,
            $from_name
        );
    } else {
        $stmt = $conn->prepare("
            INSERT INTO email_settings 
                (id, smtp_enabled, smtp_host, smtp_port, smtp_encryption, smtp_username, from_email, from_name)
            VALUES 
                (1, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                smtp_enabled = VALUES(smtp_enabled),
                smtp_host = VALUES(smtp_host),
                smtp_port = VALUES(smtp_port),
                smtp_encryption = VALUES(smtp_encryption),
                smtp_username = VALUES(smtp_username),
                from_email = VALUES(from_email),
                from_name = VALUES(from_name)
        ");
        if (!$stmt) return false;
        $stmt->bind_param(
            "isissss",
            $smtp_enabled,
            $smtp_host,
            $smtp_port,
            $smtp_encryption,
            $smtp_username,
            $from_email,
            $from_name
        );
    }

    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Read SMTP response lines from socket
 */
function smtp_read_response($socket, array &$log = []): array
{
    $response = '';
    $code = 0;

    while (!feof($socket)) {
        $line = fgets($socket, 1024);
        if ($line === false) {
            break;
        }
        $response .= $line;
        $log[] = 'S: ' . trim($line);

        if (preg_match('/^(\d{3})([ -])(.*)$/', $line, $matches)) {
            $code = (int) $matches[1];
            if ($matches[2] === ' ') {
                break;
            }
        } else {
            break;
        }
    }

    return ['code' => $code, 'text' => $response];
}

/**
 * Send an SMTP command to socket
 */
function smtp_send_command($socket, string $cmd, array &$log = [], bool $is_secret = false): void
{
    if ($is_secret) {
        $log[] = 'C: [PROTECTED_CREDENTIALS]';
    } else {
        $log[] = 'C: ' . trim($cmd);
    }
    fwrite($socket, $cmd . "\r\n");
}

/**
 * Pure PHP SMTP Mail Sender via Stream Sockets
 * 
 * Supports STARTTLS (587), direct SSL (465), plain (25), AUTH LOGIN,
 * and multipart HTML+Text MIME emails.
 */
function smtp_send_mail(
    array $config,
    string $to_email,
    string $subject,
    string $html_content,
    string $text_content = '',
    string $to_name = ''
): array {
    $log = [];

    $host = trim($config['smtp_host'] ?? '');
    $port = (int) ($config['smtp_port'] ?? 587);
    $encryption = strtolower(trim($config['smtp_encryption'] ?? 'tls'));
    $username = trim($config['smtp_username'] ?? '');
    $password = $config['smtp_password'] ?? '';
    $from_email = trim($config['from_email'] ?? '');
    if ($from_email === '' && $username !== '') {
        $from_email = $username;
    }
    if ($from_email === '') {
        $from_email = 'no-reply@localhost';
    }
    $from_name = trim($config['from_name'] ?? 'Personal Expense Tracker');

    if (empty($host)) {
        return [
            'success' => false,
            'message' => 'SMTP Host is not configured.',
            'log' => implode("\n", $log)
        ];
    }

    $socket_host = $host;
    if ($encryption === 'ssl') {
        $socket_host = 'ssl://' . $host;
    } else {
        $socket_host = 'tcp://' . $host;
    }

    $log[] = "Connecting to {$socket_host}:{$port}...";

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ]);

    $errno = 0;
    $errstr = '';
    $timeout = 15;

    $socket = @stream_socket_client(
        "{$socket_host}:{$port}",
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        $log[] = "Connection failed: ({$errno}) {$errstr}";
        return [
            'success' => false,
            'message' => "Unable to connect to {$host}:{$port} ({$errstr})",
            'log' => implode("\n", $log)
        ];
    }

    stream_set_timeout($socket, $timeout);

    // Initial greeting
    $res = smtp_read_response($socket, $log);
    if ($res['code'] !== 220) {
        fclose($socket);
        return [
            'success' => false,
            'message' => 'Invalid greeting from mail server: ' . trim($res['text']),
            'log' => implode("\n", $log)
        ];
    }

    // EHLO
    smtp_send_command($socket, "EHLO localhost", $log);
    $res = smtp_read_response($socket, $log);
    if ($res['code'] !== 250) {
        smtp_send_command($socket, "HELO localhost", $log);
        $res = smtp_read_response($socket, $log);
    }

    // STARTTLS if requested
    if ($encryption === 'tls') {
        smtp_send_command($socket, "STARTTLS", $log);
        $res = smtp_read_response($socket, $log);

        if ($res['code'] !== 220) {
            fclose($socket);
            return [
                'success' => false,
                'message' => 'STARTTLS failed: ' . trim($res['text']),
                'log' => implode("\n", $log)
            ];
        }

        $crypto_method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $crypto_method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }

        $tls_ok = @stream_socket_enable_crypto($socket, true, $crypto_method);
        if (!$tls_ok) {
            fclose($socket);
            return [
                'success' => false,
                'message' => 'TLS cryptographic handshake failed with SMTP server.',
                'log' => implode("\n", $log)
            ];
        }

        $log[] = "TLS encryption established successfully.";

        // Resend EHLO after TLS handshake
        smtp_send_command($socket, "EHLO localhost", $log);
        $res = smtp_read_response($socket, $log);
    }

    // AUTH LOGIN if credentials given
    if (!empty($username) && !empty($password)) {
        smtp_send_command($socket, "AUTH LOGIN", $log);
        $res = smtp_read_response($socket, $log);

        if ($res['code'] !== 334) {
            fclose($socket);
            return [
                'success' => false,
                'message' => 'AUTH LOGIN rejected by server: ' . trim($res['text']),
                'log' => implode("\n", $log)
            ];
        }

        // Send username
        smtp_send_command($socket, base64_encode($username), $log, true);
        $res = smtp_read_response($socket, $log);
        if ($res['code'] !== 334) {
            fclose($socket);
            return [
                'success' => false,
                'message' => 'Username rejected: ' . trim($res['text']),
                'log' => implode("\n", $log)
            ];
        }

        // Send password
        smtp_send_command($socket, base64_encode($password), $log, true);
        $res = smtp_read_response($socket, $log);
        if ($res['code'] !== 235) {
            fclose($socket);
            return [
                'success' => false,
                'message' => 'Authentication failed (Incorrect email or App Password): ' . trim($res['text']),
                'log' => implode("\n", $log)
            ];
        }

        $log[] = "SMTP Authentication successful.";
    }

    // MAIL FROM
    smtp_send_command($socket, "MAIL FROM:<{$from_email}>", $log);
    $res = smtp_read_response($socket, $log);
    if ($res['code'] !== 250) {
        fclose($socket);
        return [
            'success' => false,
            'message' => 'MAIL FROM rejected: ' . trim($res['text']),
            'log' => implode("\n", $log)
        ];
    }

    // RCPT TO
    smtp_send_command($socket, "RCPT TO:<{$to_email}>", $log);
    $res = smtp_read_response($socket, $log);
    if ($res['code'] !== 250 && $res['code'] !== 251) {
        fclose($socket);
        return [
            'success' => false,
            'message' => 'Recipient rejected: ' . trim($res['text']),
            'log' => implode("\n", $log)
        ];
    }

    // DATA
    smtp_send_command($socket, "DATA", $log);
    $res = smtp_read_response($socket, $log);
    if ($res['code'] !== 354) {
        fclose($socket);
        return [
            'success' => false,
            'message' => 'DATA command rejected: ' . trim($res['text']),
            'log' => implode("\n", $log)
        ];
    }

    // Build MIME message
    $boundary = '=_boundary_' . md5((string) microtime(true));
    $date = date('r');
    $msg_id = '<' . md5(uniqid((string) microtime(true), true)) . '@' . ($host ?: 'localhost') . '>';

    $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encoded_from_name = '=?UTF-8?B?' . base64_encode($from_name) . '?=';
    $encoded_to_name = !empty($to_name) ? '=?UTF-8?B?' . base64_encode($to_name) . '?= ' : '';

    if (empty($text_content)) {
        $text_content = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $html_content));
    }

    $headers = [
        "Date: {$date}",
        "Message-ID: {$msg_id}",
        "MIME-Version: 1.0",
        "From: {$encoded_from_name} <{$from_email}>",
        "To: {$encoded_to_name}<{$to_email}>",
        "Reply-To: {$encoded_from_name} <{$from_email}>",
        "Subject: {$encoded_subject}",
        "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
        "X-Mailer: PersonalExpenseTracker-Mailer/2.0"
    ];

    $body = implode("\r\n", $headers) . "\r\n\r\n";

    // Plain text part
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($text_content)) . "\r\n";

    // HTML part
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($html_content)) . "\r\n";

    $body .= "--{$boundary}--\r\n";
    $body .= ".";

    fwrite($socket, $body . "\r\n");
    $log[] = "Message payload sent (" . strlen($body) . " bytes).";

    $res = smtp_read_response($socket, $log);
    if ($res['code'] !== 250) {
        fclose($socket);
        return [
            'success' => false,
            'message' => 'Failed to deliver message: ' . trim($res['text']),
            'log' => implode("\n", $log)
        ];
    }

    // QUIT
    smtp_send_command($socket, "QUIT", $log);
    smtp_read_response($socket, $log);
    fclose($socket);

    $log[] = "Email sent successfully to {$to_email}.";

    return [
        'success' => true,
        'message' => 'Email sent successfully via SMTP!',
        'log' => implode("\n", $log)
    ];
}

/**
 * Main application mail sender
 * Checks database settings; uses SMTP if enabled, otherwise falls back to mail()
 */
function send_app_mail(
    mysqli $conn,
    string $to_email,
    string $subject,
    string $html_content,
    string $text_content = '',
    string $to_name = ''
): array {
    $settings = get_email_settings($conn);

    if (!empty($settings['smtp_enabled']) && !empty($settings['smtp_host'])) {
        return smtp_send_mail($settings, $to_email, $subject, $html_content, $text_content, $to_name);
    }

    // Fallback to PHP mail()
    $from_email = !empty($settings['from_email']) ? $settings['from_email'] : 'no-reply@localhost';
    $from_name = !empty($settings['from_name']) ? $settings['from_name'] : 'Personal Expense Tracker';

    $encoded_from = '=?UTF-8?B?' . base64_encode($from_name) . '?= <' . $from_email . '>';
    $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $encoded_from,
        'Reply-To: ' . $encoded_from,
        'X-Mailer: PHP/' . phpversion()
    ];

    $ok = @mail($to_email, $encoded_subject, $html_content, implode("\r\n", $headers));

    return [
        'success' => (bool) $ok,
        'message' => $ok ? 'Email sent via PHP mail().' : 'PHP mail() failed. Please configure SMTP credentials.',
        'log' => $ok ? 'Delivered using mail()' : 'Failed delivering using PHP mail()'
    ];
}

/**
 * Generate a beautifully styled, responsive email template
 */
function get_email_html_template(
    string $badge_text,
    string $title,
    string $message_html,
    string $highlight_box = '',
    string $footer_note = ''
): string {
    $highlight_section = '';
    if (!empty($highlight_box)) {
        $highlight_section = "
        <div style=\"margin: 25px 0; padding: 20px; background: #0f172a; border: 1px solid #1e293b; border-radius: 12px; text-align: center;\">
            {$highlight_box}
        </div>";
    }

    $year = date('Y');

    return "<!DOCTYPE html>
<html>
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>" . htmlspecialchars($title) . "</title>
</head>
<body style=\"margin: 0; padding: 0; background-color: #0b1120; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #f1f5f9;\">
    <table role=\"presentation\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\" style=\"background-color: #0b1120; padding: 30px 15px;\">
        <tr>
            <td align=\"center\">
                <table role=\"presentation\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\" style=\"max-width: 520px; background: #131c2e; border: 1px solid rgba(255,255,255,0.08); border-radius: 18px; overflow: hidden; box-shadow: 0 20px 50px rgba(0,0,0,0.5);\">
                    <!-- Header -->
                    <tr>
                        <td style=\"padding: 35px 35px 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.06); background: linear-gradient(135deg, rgba(37,99,235,0.15), rgba(20,184,166,0.10));\">
                            <div style=\"display: inline-block; width: 44px; height: 44px; line-height: 44px; border-radius: 12px; background: linear-gradient(135deg, #3b82f6, #14b8a6); color: #ffffff; font-size: 22px; font-weight: bold; margin-bottom: 12px;\">₹</div>
                            <h2 style=\"margin: 0; font-size: 20px; font-weight: 700; color: #ffffff;\">Personal Expense Tracker</h2>
                            " . (!empty($badge_text) ? "<span style=\"display: inline-block; margin-top: 8px; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; background: rgba(59,130,246,0.2); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3);\">{$badge_text}</span>" : "") . "
                        </td>
                    </tr>
                    <!-- Body Content -->
                    <tr>
                        <td style=\"padding: 35px;\">
                            <h1 style=\"margin: 0 0 16px; font-size: 22px; font-weight: 700; color: #ffffff; line-height: 1.3;\">" . htmlspecialchars($title) . "</h1>
                            <div style=\"font-size: 15px; line-height: 1.6; color: #94a3b8;\">
                                {$message_html}
                            </div>
                            {$highlight_section}
                            " . (!empty($footer_note) ? "<p style=\"margin: 20px 0 0; font-size: 13px; color: #64748b; line-height: 1.5;\">{$footer_note}</p>" : "") . "
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style=\"padding: 20px 35px; background: #0c1322; border-top: 1px solid rgba(255,255,255,0.06); text-align: center;\">
                            <p style=\"margin: 0; font-size: 12px; color: #475569;\">
                                &copy; {$year} Personal Expense Tracker &bull; All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>";
}
