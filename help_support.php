<?php

/**
 * Help & Support Center
 * Personal Expense Tracker
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "auth.php";
require_once "mailer.php";

// Ensure support_tickets table exists
$conn->query("
    CREATE TABLE IF NOT EXISTS support_tickets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        user_name VARCHAR(191) NOT NULL,
        user_email VARCHAR(191) NOT NULL,
        category VARCHAR(50) NOT NULL DEFAULT 'General',
        subject VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        priority ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
        status ENUM('open', 'in_progress', 'resolved') NOT NULL DEFAULT 'open',
        admin_notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (user_id),
        INDEX (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$feedback_msg = "";
$feedback_type = "";

// Handle POST actions
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "submit_ticket") {
        $category = trim($_POST["category"] ?? "General");
        $subject = trim($_POST["subject"] ?? "");
        $message = trim($_POST["message"] ?? "");
        $priority = in_array($_POST["priority"] ?? "", ["low", "medium", "high"]) ? $_POST["priority"] : "medium";

        if (empty($subject) || empty($message)) {
            $feedback_msg = "Please provide both a subject and a message for your inquiry.";
            $feedback_type = "error";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO support_tickets 
                    (user_id, user_name, user_email, category, subject, message, priority, status)
                VALUES 
                    (?, ?, ?, ?, ?, ?, ?, 'open')
            ");

            if ($stmt) {
                $stmt->bind_param("issssss", $user_id, $user_name, $user_email, $category, $subject, $message, $priority);
                if ($stmt->execute()) {
                    $ticket_id = $stmt->insert_id;
                    $feedback_msg = "Support ticket #{$ticket_id} submitted successfully! We will review your request.";
                    $feedback_type = "success";

                    // Attempt email notification to admins
                    $admin_email_to = env("ADMIN_EMAIL", "admin@expense.com");
                    $email_html = "
                        <div style='font-family: Arial, sans-serif; background: #090e17; color: #f1f5f9; padding: 24px; border-radius: 10px;'>
                            <h2 style='color: #38bdf8; margin-top: 0;'>New Support Inquiry #{$ticket_id}</h2>
                            <p style='color: #cbd5e1;'>A user has submitted a new support inquiry on Personal Expense Tracker.</p>
                            <table style='width: 100%; border-collapse: collapse; margin-top: 15px; color: #e2e8f0;'>
                                <tr><td style='padding: 8px 0; color: #94a3b8; width: 120px;'><strong>User:</strong></td><td>" . htmlspecialchars($user_name) . " (" . htmlspecialchars($user_email) . ")</td></tr>
                                <tr><td style='padding: 8px 0; color: #94a3b8;'><strong>Category:</strong></td><td>" . htmlspecialchars($category) . "</td></tr>
                                <tr><td style='padding: 8px 0; color: #94a3b8;'><strong>Priority:</strong></td><td style='text-transform: uppercase; font-weight: bold;'>" . htmlspecialchars($priority) . "</td></tr>
                                <tr><td style='padding: 8px 0; color: #94a3b8;'><strong>Subject:</strong></td><td>" . htmlspecialchars($subject) . "</td></tr>
                            </table>
                            <div style='background: #101c2d; padding: 15px; border-radius: 8px; margin-top: 15px; border: 1px solid #1e2d42; color: #f1f5f9;'>
                                " . nl2br(htmlspecialchars($message)) . "
                            </div>
                        </div>
                    ";
                    @send_app_mail($conn, $admin_email_to, "New Support Ticket #{$ticket_id}: {$subject}", $email_html, $message, "Admin Team");
                } else {
                    $feedback_msg = "Database error saving your inquiry: " . $stmt->error;
                    $feedback_type = "error";
                }
                $stmt->close();
            }
        }
    } elseif ($action === "update_ticket_status" && !empty($is_admin)) {
        $ticket_id = (int) ($_POST["ticket_id"] ?? 0);
        $new_status = in_array($_POST["status"] ?? "", ["open", "in_progress", "resolved"]) ? $_POST["status"] : "open";
        $admin_notes = trim($_POST["admin_notes"] ?? "");

        $stmt = $conn->prepare("UPDATE support_tickets SET status = ?, admin_notes = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("ssi", $new_status, $admin_notes, $ticket_id);
            if ($stmt->execute()) {
                $feedback_msg = "Ticket #{$ticket_id} updated to status: " . strtoupper($new_status);
                $feedback_type = "success";
            } else {
                $feedback_msg = "Failed to update ticket: " . $stmt->error;
                $feedback_type = "error";
            }
            $stmt->close();
        }
    }
}

// Fetch tickets
$user_tickets = [];
if (!empty($is_admin)) {
    $res = $conn->query("SELECT * FROM support_tickets ORDER BY id DESC LIMIT 50");
    if ($res) {
        $user_tickets = $res->fetch_all(MYSQLI_ASSOC);
    }
} else {
    $stmt = $conn->prepare("SELECT * FROM support_tickets WHERE user_id = ? ORDER BY id DESC LIMIT 20");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $user_tickets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

// Email settings status
$email_settings = get_email_settings($conn);
$smtp_active = !empty($email_settings['smtp_enabled']) && !empty($email_settings['smtp_host']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Help &amp; Support Center | Personal Expense Tracker</title>
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
            max-width: 1400px;
        }

        /* HEADER */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title h1 {
            font-size: 28px;
            font-weight: 800;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-title p {
            font-size: 14px;
            color: #94a3b8;
            margin-top: 6px;
        }

        .badge-role {
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
            background: rgba(32, 185, 245, 0.15);
            color: #38bdf8;
            border: 1px solid rgba(56, 189, 248, 0.3);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* ALERTS */
        .alert {
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .alert.success {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.35);
            color: #34d399;
        }

        .alert.error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.35);
            color: #f87171;
        }

        /* SEARCH BAR */
        .search-hero {
            background: linear-gradient(135deg, #101c2d 0%, #13243a 100%);
            border: 1px solid #1e2d42;
            border-radius: 16px;
            padding: 28px 32px;
            margin-bottom: 28px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .search-hero h2 {
            font-size: 20px;
            font-weight: 700;
            color: #ffffff;
        }

        .search-hero p {
            font-size: 13.5px;
            color: #94a3b8;
        }

        .search-box {
            position: relative;
            max-width: 700px;
            width: 100%;
        }

        .search-box input {
            width: 100%;
            height: 48px;
            padding: 0 18px 0 46px;
            background: #090e17;
            border: 1px solid #1e2d42;
            border-radius: 12px;
            color: #ffffff;
            font-size: 14px;
            outline: none;
            transition: all 0.2s;
        }

        .search-box input:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.15);
        }

        .search-box .icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 17px;
            color: #64748b;
        }

        /* NAVIGATION TABS */
        .tabs-nav {
            display: flex;
            gap: 8px;
            border-bottom: 1px solid #1e2d42;
            margin-bottom: 28px;
            overflow-x: auto;
            padding-bottom: 2px;
        }

        .tab-btn {
            background: none;
            border: none;
            color: #94a3b8;
            font-size: 14px;
            font-weight: 600;
            padding: 12px 18px;
            border-radius: 8px 8px 0 0;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            border-bottom: 2px solid transparent;
        }

        .tab-btn:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.03);
        }

        .tab-btn.active {
            color: #38bdf8;
            border-bottom: 2px solid #38bdf8;
            background: rgba(56, 189, 248, 0.05);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* FEATURE CARDS GRID */
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .guide-card {
            background: #101c2d;
            border: 1px solid #1e2d42;
            border-radius: 14px;
            padding: 24px;
            transition: transform 0.2s, border-color 0.2s;
            display: flex;
            flex-direction: column;
        }

        .guide-card:hover {
            transform: translateY(-2px);
            border-color: rgba(56, 189, 248, 0.4);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }

        .guide-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: rgba(56, 189, 248, 0.12);
            border: 1px solid rgba(56, 189, 248, 0.25);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 16px;
        }

        .guide-card h3 {
            font-size: 16px;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 8px;
        }

        .guide-card p {
            font-size: 13.5px;
            color: #94a3b8;
            line-height: 1.5;
            margin-bottom: 16px;
            flex: 1;
        }

        .guide-steps {
            background: #090e17;
            border: 1px solid #162436;
            border-radius: 8px;
            padding: 12px 14px;
            font-size: 12.5px;
            color: #cbd5e1;
        }

        .guide-steps ol {
            padding-left: 18px;
            line-height: 1.6;
        }

        /* FAQ ACCORDION */
        .faq-categories {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .cat-chip {
            padding: 6px 14px;
            border-radius: 20px;
            background: #101c2d;
            border: 1px solid #1e2d42;
            color: #94a3b8;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .cat-chip.active, .cat-chip:hover {
            background: #16b5f4;
            color: #ffffff;
            border-color: #16b5f4;
        }

        .faq-item {
            background: #101c2d;
            border: 1px solid #1e2d42;
            border-radius: 12px;
            margin-bottom: 12px;
            overflow: hidden;
            transition: border-color 0.2s;
        }

        .faq-item:hover {
            border-color: rgba(56, 189, 248, 0.35);
        }

        .faq-question {
            padding: 18px 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            user-select: none;
            font-size: 15px;
            font-weight: 600;
            color: #f1f5f9;
        }

        .faq-question .indicator {
            font-size: 14px;
            color: #38bdf8;
            transition: transform 0.2s;
        }

        .faq-item.open .faq-question .indicator {
            transform: rotate(180deg);
        }

        .faq-answer {
            display: none;
            padding: 0 22px 18px 22px;
            font-size: 13.5px;
            line-height: 1.6;
            color: #94a3b8;
            border-top: 1px solid rgba(255, 255, 255, 0.04);
            margin-top: 4px;
            padding-top: 14px;
        }

        .faq-item.open .faq-answer {
            display: block;
        }

        /* SUPPORT FORM & TICKETS */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }

        .card-surface {
            background: #101c2d;
            border: 1px solid #1e2d42;
            border-radius: 14px;
            padding: 26px;
        }

        .card-surface h3 {
            font-size: 17px;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-surface p.desc {
            font-size: 13px;
            color: #94a3b8;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-size: 12.5px;
            font-weight: 600;
            color: #cbd5e1;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .form-control {
            width: 100%;
            padding: 10px 14px;
            background: #090e17;
            border: 1px solid #1e2d42;
            border-radius: 9px;
            color: #ffffff;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }

        .form-control:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.15);
        }

        textarea.form-control {
            min-height: 110px;
            resize: vertical;
        }

        .btn-submit {
            background: linear-gradient(135deg, #16b5f4, #168ce8);
            border: none;
            color: #ffffff;
            font-weight: 700;
            font-size: 14px;
            padding: 12px 24px;
            border-radius: 9px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 14px rgba(22, 140, 232, 0.3);
            transition: all 0.2s;
        }

        .btn-submit:hover {
            opacity: 0.95;
            transform: translateY(-1px);
        }

        /* TICKETS TABLE */
        .table-responsive {
            overflow-x: auto;
            border: 1px solid #1e2d42;
            border-radius: 12px;
            background: #101c2d;
        }

        table.tickets-tbl {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            text-align: left;
        }

        table.tickets-tbl th {
            background: #0b1320;
            padding: 12px 16px;
            color: #94a3b8;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.05em;
            border-bottom: 1px solid #1e2d42;
        }

        table.tickets-tbl td {
            padding: 14px 16px;
            border-bottom: 1px solid #162436;
            color: #cbd5e1;
        }

        table.tickets-tbl tr:last-child td {
            border-bottom: none;
        }

        .status-badge {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 12px;
            text-transform: uppercase;
        }

        .status-open {
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .status-in_progress {
            background: rgba(56, 189, 248, 0.15);
            color: #38bdf8;
            border: 1px solid rgba(56, 189, 248, 0.3);
        }

        .status-resolved {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        /* SYSTEM STATUS WIDGET */
        .diag-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 16px;
        }

        .diag-item {
            background: #090e17;
            border: 1px solid #1a293f;
            border-radius: 10px;
            padding: 14px 18px;
        }

        .diag-item span.label {
            font-size: 11px;
            text-transform: uppercase;
            font-weight: 700;
            color: #64748b;
            display: block;
            margin-bottom: 4px;
        }

        .diag-item strong.val {
            font-size: 14px;
            color: #f1f5f9;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .dot-green {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #10b981;
            display: inline-block;
        }

        .dot-amber {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #f59e0b;
            display: inline-block;
        }

        @media (max-width: 900px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="app-layout">

    <!-- Sidebar -->
    <?php include "sidebar.php"; ?>

    <main class="main-content">

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title">
                <h1>
                    <span>❓</span> Help &amp; Support Center
                    <span class="badge-role"><?php echo !empty($is_admin) ? "Admin View" : "User Support"; ?></span>
                </h1>
                <p>Browse documentation, find answers to common questions, or contact our support team directly.</p>
            </div>
        </div>

        <!-- Feedback Alert -->
        <?php if (!empty($feedback_msg)): ?>
            <div class="alert <?php echo htmlspecialchars($feedback_type); ?>">
                <span><?php echo htmlspecialchars($feedback_msg); ?></span>
                <span style="cursor: pointer; font-size: 18px;" onclick="this.parentElement.style.display='none';">&times;</span>
            </div>
        <?php endif; ?>

        <!-- Search Hero Banner -->
        <div class="search-hero">
            <div>
                <h2>How can we help you today?</h2>
                <p>Type keywords to filter topics, guides, FAQs, and troubleshooting advice.</p>
            </div>
            <div class="search-box">
                <span class="icon">🔍</span>
                <input type="text" id="liveSearchInput" placeholder="Search guides, budgets, transactions, SMTP configuration..." oninput="handleSearch(this.value)">
            </div>
        </div>

        <!-- Tabs Navigation -->
        <div class="tabs-nav">
            <button class="tab-btn active" onclick="switchTab('guides')">📖 Guides &amp; Walkthroughs</button>
            <button class="tab-btn" onclick="switchTab('faqs')">❓ Frequently Asked Questions</button>
            <button class="tab-btn" onclick="switchTab('support')">✉️ Contact &amp; Inquiries (<?php echo count($user_tickets); ?>)</button>
            <button class="tab-btn" onclick="switchTab('diagnostics')">⚡ System Diagnostics</button>
        </div>

        <!-- TAB 1: GUIDES & WALKTHROUGHS -->
        <div id="tab-guides" class="tab-content active">
            <div class="cards-grid">

                <!-- 1. ACCOUNTS -->
                <div class="guide-card searchable-card">
                    <div class="guide-icon">💳</div>
                    <h3>Accounts &amp; Wallets Management</h3>
                    <p>Track cash, checking, savings, and credit cards in one central overview with automatic balance updates.</p>
                    <div class="guide-steps">
                        <ol>
                            <li>Navigate to <strong>Accounts</strong> via the left sidebar.</li>
                            <li>Click <strong>+ Add Account</strong> and choose Bank, Cash, or Credit Card.</li>
                            <li>Set your starting balance and initial currency.</li>
                            <li>Balances adjust automatically whenever transactions are recorded.</li>
                        </ol>
                    </div>
                </div>

                <!-- 2. TRANSACTIONS -->
                <div class="guide-card searchable-card">
                    <div class="guide-icon">💸</div>
                    <h3>Logging Income &amp; Expenses</h3>
                    <p>Categorize your daily spending, attach receipt notes, and monitor cash flow trends.</p>
                    <div class="guide-steps">
                        <ol>
                            <li>Visit <strong>Transactions</strong> or use Quick Add on Dashboard.</li>
                            <li>Choose <em>Expense</em> or <em>Income</em> and enter the amount.</li>
                            <li>Select the target category (e.g., Food, Utilities, Salary).</li>
                            <li>Assign the financial account linked to this payment.</li>
                        </ol>
                    </div>
                </div>

                <!-- 3. BUDGETS -->
                <div class="guide-card searchable-card">
                    <div class="guide-icon">🎯</div>
                    <h3>Setting Monthly Budgets</h3>
                    <p>Prevent overspending by defining monthly category spending limits and tracking progress meters.</p>
                    <div class="guide-steps">
                        <ol>
                            <li>Open <strong>Budgets</strong> from the navigation sidebar.</li>
                            <li>Set target monthly limits for chosen categories.</li>
                            <li>Monitor color-coded progress bars (Normal, Warning, Over Budget).</li>
                            <li>Review overspending alerts at the end of each billing cycle.</li>
                        </ol>
                    </div>
                </div>

                <!-- 4. GOALS -->
                <div class="guide-card searchable-card">
                    <div class="guide-icon">🏆</div>
                    <h3>Financial Goals &amp; Savings</h3>
                    <p>Plan ahead for vacations, emergency funds, or major investments with visual milestone meters.</p>
                    <div class="guide-steps">
                        <ol>
                            <li>Navigate to <strong>Financial Goals</strong>.</li>
                            <li>Set a goal name, target amount, and target completion date.</li>
                            <li>Log deposits toward your goals over time.</li>
                            <li>Watch your percentage completion reach 100%.</li>
                        </ol>
                    </div>
                </div>

                <!-- 5. SECURITY & 2FA -->
                <div class="guide-card searchable-card">
                    <div class="guide-icon">🔒</div>
                    <h3>Password Reset &amp; OTP Security</h3>
                    <p>Secure account verification using one-time password (OTP) verification delivered directly via email.</p>
                    <div class="guide-steps">
                        <ol>
                            <li>Use <strong>Forgot Password</strong> on the login screen.</li>
                            <li>Enter your registered email address to receive a 6-digit OTP code.</li>
                            <li>Verify the OTP code within 15 minutes of issuance.</li>
                            <li>Set a new strong password to regain immediate account access.</li>
                        </ol>
                    </div>
                </div>

                <!-- 6. ADMIN & SMTP -->
                <div class="guide-card searchable-card">
                    <div class="guide-icon">✉️</div>
                    <h3>Admin SMTP Configuration</h3>
                    <p>Configure automated transactional emails using pure PHP sockets supporting TLS, SSL, and custom SMTP servers.</p>
                    <div class="guide-steps">
                        <ol>
                            <li>Admins can visit <strong>Email Settings</strong> in the sidebar.</li>
                            <li>Select preset: Gmail, Outlook, Yahoo, or Custom SMTP.</li>
                            <li>For Gmail, generate an <em>App Password</em> in your Google Account.</li>
                            <li>Use the built-in <strong>Send Test Email</strong> tool to confirm connectivity.</li>
                        </ol>
                    </div>
                </div>

            </div>
        </div>

        <!-- TAB 2: FAQS -->
        <div id="tab-faqs" class="tab-content">
            <div class="faq-categories">
                <button class="cat-chip active" onclick="filterFaq('all', this)">All Questions</button>
                <button class="cat-chip" onclick="filterFaq('transactions', this)">Transactions &amp; Accounts</button>
                <button class="cat-chip" onclick="filterFaq('budgets', this)">Budgets &amp; Goals</button>
                <button class="cat-chip" onclick="filterFaq('security', this)">Security &amp; OTP</button>
                <button class="cat-chip" onclick="filterFaq('admin', this)">System &amp; Admin</button>
            </div>

            <div class="faq-list">

                <!-- FAQ 1 -->
                <div class="faq-item searchable-card" data-category="transactions">
                    <div class="faq-question" onclick="toggleFaq(this)">
                        <span>Why can't I delete an account that has transactions?</span>
                        <span class="indicator">▼</span>
                    </div>
                    <div class="faq-answer">
                        To maintain ledger integrity and ensure financial reports match historical bank statements, the database enforces strict foreign key constraints. If you wish to delete an account, first reassign or delete its linked transactions.
                    </div>
                </div>

                <!-- FAQ 2 -->
                <div class="faq-item searchable-card" data-category="transactions">
                    <div class="faq-question" onclick="toggleFaq(this)">
                        <span>How are account balances calculated when I add a transaction?</span>
                        <span class="indicator">▼</span>
                    </div>
                    <div class="faq-answer">
                        When an <strong>Income</strong> transaction is recorded, the selected account's balance increases by that amount. When an <strong>Expense</strong> transaction is logged, the selected account's balance decreases automatically.
                    </div>
                </div>

                <!-- FAQ 3 -->
                <div class="faq-item searchable-card" data-category="budgets">
                    <div class="faq-question" onclick="toggleFaq(this)">
                        <span>Do budgets reset automatically each month?</span>
                        <span class="indicator">▼</span>
                    </div>
                    <div class="faq-answer">
                        Yes! Budgets calculate your category expenditure for the active calendar month (e.g. from the 1st of the current month to today). On the 1st of each new month, current spending resets to zero while your budget limits carry over automatically.
                    </div>
                </div>

                <!-- FAQ 4 -->
                <div class="faq-item searchable-card" data-category="security">
                    <div class="faq-question" onclick="toggleFaq(this)">
                        <span>What should I do if I don't receive my 6-digit password reset OTP?</span>
                        <span class="indicator">▼</span>
                    </div>
                    <div class="faq-answer">
                        Check your email's Spam or Junk folder. If the email is not present, ask your system administrator to verify SMTP settings in <strong>Email Settings</strong>. You can also request a new OTP code after the countdown expires.
                    </div>
                </div>

                <!-- FAQ 5 -->
                <div class="faq-item searchable-card" data-category="security">
                    <div class="faq-question" onclick="toggleFaq(this)">
                        <span>Are passwords stored securely?</span>
                        <span class="indicator">▼</span>
                    </div>
                    <div class="faq-answer">
                        Yes. All user passwords are encrypted using PHP's industry-standard <code>password_hash()</code> with strong cryptographic salt and the <code>PASSWORD_DEFAULT</code> algorithm (bcrypt).
                    </div>
                </div>

                <!-- FAQ 6 -->
                <div class="faq-item searchable-card" data-category="admin">
                    <div class="faq-question" onclick="toggleFaq(this)">
                        <span>How do administrators manage users and assign roles?</span>
                        <span class="indicator">▼</span>
                    </div>
                    <div class="faq-answer">
                        Logged-in administrators will find the <strong>Users Manager</strong> tab in the sidebar navigation. From there, you can view all registered members, promote/demote administrator rights, reset passwords, or delete accounts with cascading transaction cleanups.
                    </div>
                </div>

                <!-- FAQ 7 -->
                <div class="faq-item searchable-card" data-category="admin">
                    <div class="faq-question" onclick="toggleFaq(this)">
                        <span>Where are environment variables and database credentials stored?</span>
                        <span class="indicator">▼</span>
                    </div>
                    <div class="faq-answer">
                        Environment variables are defined in the <code>.env</code> file in the application root directory. It configures <code>DB_HOST</code>, <code>DB_USER</code>, <code>DB_PASS</code>, <code>DB_NAME</code>, and default SMTP parameters.
                    </div>
                </div>

            </div>
        </div>

        <!-- TAB 3: CONTACT & INQUIRIES -->
        <div id="tab-support" class="tab-content">
            <div class="form-grid">

                <!-- Submit Ticket Form -->
                <div class="card-surface">
                    <h3><span>✉️</span> Submit a Support Inquiry</h3>
                    <p class="desc">Have a question, feedback, or encountered a bug? Submit a ticket and our administration team will look into it.</p>

                    <form method="POST" action="help_support.php">
                        <input type="hidden" name="action" value="submit_ticket">

                        <div class="form-group">
                            <label>Inquiry Category</label>
                            <select name="category" class="form-control" required>
                                <option value="General">General Question</option>
                                <option value="Transactions">Transactions &amp; Accounts</option>
                                <option value="Budgets">Budgets &amp; Goals</option>
                                <option value="Security">Account Security &amp; 2FA</option>
                                <option value="Bug Report">Bug Report</option>
                                <option value="Feature Request">Feature Request</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Priority</label>
                            <select name="priority" class="form-control">
                                <option value="low">Low - General query</option>
                                <option value="medium" selected>Medium - Normal assistance</option>
                                <option value="high">High - Urgent / Blocking issue</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Subject</label>
                            <input type="text" name="subject" class="form-control" placeholder="Brief summary of your inquiry" required>
                        </div>

                        <div class="form-group">
                            <label>Message Details</label>
                            <textarea name="message" class="form-control" placeholder="Explain what happened or what you need assistance with..." required></textarea>
                        </div>

                        <button type="submit" class="btn-submit">
                            <span>🚀</span> Submit Inquiry
                        </button>
                    </form>
                </div>

                <!-- Ticket History / Queue -->
                <div class="card-surface">
                    <h3><span>🎫</span> <?php echo !empty($is_admin) ? "Recent Tickets Queue (Admin)" : "My Submitted Inquiries"; ?></h3>
                    <p class="desc"><?php echo !empty($is_admin) ? "Review and respond to inquiries submitted by users." : "Track the status of your submitted support inquiries."; ?></p>

                    <?php if (empty($user_tickets)): ?>
                        <div style="text-align: center; padding: 40px 20px; color: #64748b;">
                            <div style="font-size: 32px; margin-bottom: 10px;">📬</div>
                            <p>No support inquiries found.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="tickets-tbl">
                                <thead>
                                    <tr>
                                        <th>Ref</th>
                                        <?php if (!empty($is_admin)): ?><th>User</th><?php endif; ?>
                                        <th>Subject</th>
                                        <th>Category</th>
                                        <th>Status</th>
                                        <?php if (!empty($is_admin)): ?><th>Action</th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($user_tickets as $ticket): ?>
                                        <tr>
                                            <td><strong>#<?php echo (int) $ticket['id']; ?></strong></td>
                                            <?php if (!empty($is_admin)): ?>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($ticket['user_name']); ?></strong><br>
                                                    <small style="color: #64748b;"><?php echo htmlspecialchars($ticket['user_email']); ?></small>
                                                </td>
                                            <?php endif; ?>
                                            <td>
                                                <strong><?php echo htmlspecialchars($ticket['subject']); ?></strong>
                                                <div style="font-size: 11px; color: #94a3b8; margin-top: 3px;">
                                                    <?php echo htmlspecialchars(substr($ticket['message'], 0, 75)) . (strlen($ticket['message']) > 75 ? '...' : ''); ?>
                                                </div>
                                            </td>
                                            <td><span style="font-size: 11.5px; color: #94a3b8;"><?php echo htmlspecialchars($ticket['category']); ?></span></td>
                                            <td>
                                                <span class="status-badge status-<?php echo htmlspecialchars($ticket['status']); ?>">
                                                    <?php echo str_replace('_', ' ', htmlspecialchars($ticket['status'])); ?>
                                                </span>
                                            </td>
                                            <?php if (!empty($is_admin)): ?>
                                                <td>
                                                    <form method="POST" action="help_support.php" style="display: flex; gap: 6px; align-items: center;">
                                                        <input type="hidden" name="action" value="update_ticket_status">
                                                        <input type="hidden" name="ticket_id" value="<?php echo (int) $ticket['id']; ?>">
                                                        <select name="status" class="form-control" style="padding: 4px 8px; font-size: 12px; height: 32px;" onchange="this.form.submit()">
                                                            <option value="open" <?php echo $ticket['status'] === 'open' ? 'selected' : ''; ?>>Open</option>
                                                            <option value="in_progress" <?php echo $ticket['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                                            <option value="resolved" <?php echo $ticket['status'] === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                                        </select>
                                                    </form>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>

        <!-- TAB 4: DIAGNOSTICS -->
        <div id="tab-diagnostics" class="tab-content">
            <div class="card-surface">
                <h3><span>⚡</span> System Environment &amp; Health Diagnostics</h3>
                <p class="desc">Live diagnostics of your server environment, database connectivity, and mail transport.</p>

                <div class="diag-grid">
                    <div class="diag-item">
                        <span class="label">PHP Runtime</span>
                        <strong class="val"><span class="dot-green"></span> PHP <?php echo phpversion(); ?></strong>
                    </div>

                    <div class="diag-item">
                        <span class="label">Database Connection</span>
                        <strong class="val"><span class="dot-green"></span> MySQL Online (utf8mb4)</strong>
                    </div>

                    <div class="diag-item">
                        <span class="label">SMTP Mailer Engine</span>
                        <strong class="val">
                            <?php if ($smtp_active): ?>
                                <span class="dot-green"></span> Configured (<?php echo htmlspecialchars($email_settings['smtp_host']); ?>:<?php echo (int)$email_settings['smtp_port']; ?>)
                            <?php else: ?>
                                <span class="dot-amber"></span> Pending Admin Setup
                            <?php endif; ?>
                        </strong>
                    </div>

                    <div class="diag-item">
                        <span class="label">Socket Extension</span>
                        <strong class="val">
                            <?php if (extension_loaded('sockets') || function_exists('fsockopen')): ?>
                                <span class="dot-green"></span> Stream Sockets Active
                            <?php else: ?>
                                <span class="dot-amber"></span> Fallback Available
                            <?php endif; ?>
                        </strong>
                    </div>

                    <div class="diag-item">
                        <span class="label">Current User Session</span>
                        <strong class="val">
                            <span class="dot-green"></span> <?php echo htmlspecialchars($user_name); ?> (ID #<?php echo $user_id; ?>)
                        </strong>
                    </div>

                    <div class="diag-item">
                        <span class="label">Access Role</span>
                        <strong class="val" style="color: <?php echo !empty($is_admin) ? '#38bdf8' : '#cbd5e1'; ?>">
                            <?php echo !empty($is_admin) ? '👑 Administrator' : '👤 Standard Member'; ?>
                        </strong>
                    </div>
                </div>

                <?php if (!empty($is_admin)): ?>
                    <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid #1e2d42; display: flex; gap: 14px; flex-wrap: wrap;">
                        <a href="email_config.php" class="btn-submit" style="text-decoration: none;">
                            <span>✉️</span> Open Email Settings
                        </a>
                        <a href="admin_users.php" class="btn-submit" style="text-decoration: none; background: #1e2d42;">
                            <span>👥</span> Open Users Manager
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </main>

</div>

<script>
    function switchTab(tabName) {
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));

        const targetBtn = event.currentTarget || event.target;
        if (targetBtn) targetBtn.classList.add('active');

        const targetContent = document.getElementById('tab-' + tabName);
        if (targetContent) {
            targetContent.classList.add('active');
        }
    }

    function toggleFaq(element) {
        const item = element.closest('.faq-item');
        if (item) {
            item.classList.toggle('open');
        }
    }

    function filterFaq(category, btnElement) {
        document.querySelectorAll('.cat-chip').forEach(chip => chip.classList.remove('active'));
        btnElement.classList.add('active');

        const faqItems = document.querySelectorAll('.faq-item');
        faqItems.forEach(item => {
            if (category === 'all' || item.getAttribute('data-category') === category) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
            }
        });
    }

    function handleSearch(query) {
        query = query.toLowerCase().trim();
        const cards = document.querySelectorAll('.searchable-card');
        
        cards.forEach(card => {
            const text = card.textContent.toLowerCase();
            if (query === '' || text.includes(query)) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    }
</script>

</body>
</html>
