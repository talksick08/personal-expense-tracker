<?php

/**
 * Admin User Management Portal
 * Personal Expense Tracker
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "auth.php";

// Strict Admin Access Control
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
            <p>You do not have administrative privileges to manage system user accounts.</p>
            <a href="dashboard.php">Return to Dashboard</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Generate CSRF token if needed
if (empty($_SESSION["admin_csrf"])) {
    $_SESSION["admin_csrf"] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION["admin_csrf"];

$message = "";
$message_type = "";

// Helper for CSRF check
function admin_csrf_ok(): bool
{
    return isset($_POST["csrf_token"])
        && isset($_SESSION["admin_csrf"])
        && hash_equals($_SESSION["admin_csrf"], $_POST["csrf_token"]);
}

// ==============================================================================
// POST ACTION HANDLERS
// ==============================================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!admin_csrf_ok()) {
        $message = "Security token expired or invalid. Please refresh the page.";
        $message_type = "error";
    } else {
        $action = $_POST["action"] ?? "";

        // ----------------------------------------------------------------------
        // ACTION: CREATE NEW USER
        // ----------------------------------------------------------------------
        if ($action === "create_user") {
            $name = trim($_POST["name"] ?? "");
            $email = trim($_POST["email"] ?? "");
            $phone = trim($_POST["phone"] ?? "");
            $password = $_POST["password"] ?? "";
            $role = isset($_POST["is_admin"]) ? 1 : 0;
            $verify_now = isset($_POST["email_verified"]) ? 1 : 0;

            if ($name === "" || $email === "" || $password === "") {
                $message = "Name, email, and password are required.";
                $message_type = "error";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $message = "Please enter a valid email address.";
                $message_type = "error";
            } elseif (strlen($password) < 6) {
                $message = "Password must be at least 6 characters long.";
                $message_type = "error";
            } else {
                // Check if email already exists
                $chk = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $chk->bind_param("s", $email);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    $message = "An account with this email address already exists.";
                    $message_type = "error";
                } else {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $verified_val = $verify_now ? date('Y-m-d H:i:s') : null;

                    $stmt = $conn->prepare("
                        INSERT INTO users (name, email, phone, password, is_admin, email_verified_at, two_factor_enabled)
                        VALUES (?, ?, ?, ?, ?, ?, 0)
                    ");
                    $stmt->bind_param("ssssis", $name, $email, $phone, $hashed_password, $role, $verified_val);

                    if ($stmt->execute()) {
                        $message = "User '{$name}' created successfully!";
                        $message_type = "success";
                    } else {
                        $message = "Failed to create user: " . $stmt->error;
                        $message_type = "error";
                    }
                    $stmt->close();
                }
                $chk->close();
            }
        }

        // ----------------------------------------------------------------------
        // ACTION: UPDATE EXISTING USER
        // ----------------------------------------------------------------------
        if ($action === "update_user") {
            $target_id = (int)($_POST["user_id"] ?? 0);
            $name = trim($_POST["name"] ?? "");
            $email = trim($_POST["email"] ?? "");
            $phone = trim($_POST["phone"] ?? "");
            $new_role = isset($_POST["is_admin"]) ? 1 : 0;
            $two_factor = isset($_POST["two_factor_enabled"]) ? 1 : 0;
            $is_verified = isset($_POST["email_verified"]) ? 1 : 0;

            if ($target_id <= 0) {
                $message = "Invalid user specified.";
                $message_type = "error";
            } elseif ($name === "" || $email === "") {
                $message = "User name and email cannot be empty.";
                $message_type = "error";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $message = "Please enter a valid email address.";
                $message_type = "error";
            } else {
                // Prevent admin from removing their own admin status
                if ($target_id === $user_id && $new_role === 0) {
                    $new_role = 1;
                }

                // Check email uniqueness against other users
                $chk = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
                $chk->bind_param("si", $email, $target_id);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    $message = "Another user is already using this email address.";
                    $message_type = "error";
                } else {
                    $verified_sql = $is_verified ? "COALESCE(email_verified_at, NOW())" : "NULL";

                    $stmt = $conn->prepare("
                        UPDATE users
                        SET name = ?,
                            email = ?,
                            phone = ?,
                            is_admin = ?,
                            two_factor_enabled = ?,
                            email_verified_at = {$verified_sql}
                        WHERE id = ?
                    ");
                    $stmt->bind_param("sssiii", $name, $email, $phone, $new_role, $two_factor, $target_id);

                    if ($stmt->execute()) {
                        $message = "User details updated successfully!";
                        $message_type = "success";
                    } else {
                        $message = "Failed to update user: " . $stmt->error;
                        $message_type = "error";
                    }
                    $stmt->close();
                }
                $chk->close();
            }
        }

        // ----------------------------------------------------------------------
        // ACTION: RESET USER PASSWORD
        // ----------------------------------------------------------------------
        if ($action === "reset_password") {
            $target_id = (int)($_POST["user_id"] ?? 0);
            $new_password = $_POST["new_password"] ?? "";

            if ($target_id <= 0) {
                $message = "Invalid user specified.";
                $message_type = "error";
            } elseif (strlen($new_password) < 6) {
                $message = "New password must be at least 6 characters.";
                $message_type = "error";
            } else {
                $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->bind_param("si", $hashed, $target_id);

                if ($stmt->execute()) {
                    $message = "Password for user ID #{$target_id} was successfully reset!";
                    $message_type = "success";
                } else {
                    $message = "Failed to reset password: " . $stmt->error;
                    $message_type = "error";
                }
                $stmt->close();
            }
        }

        // ----------------------------------------------------------------------
        // ACTION: DELETE USER
        // ----------------------------------------------------------------------
        if ($action === "delete_user") {
            $target_id = (int)($_POST["user_id"] ?? 0);

            if ($target_id <= 0) {
                $message = "Invalid user specified.";
                $message_type = "error";
            } elseif ($target_id === $user_id) {
                $message = "You cannot delete your own active administrator account.";
                $message_type = "error";
            } else {
                // Delete user and associated records inside a transaction
                $conn->begin_transaction();
                try {
                    // Fetch profile photo path to clean up file from disk
                    $p_stmt = $conn->prepare("SELECT profile_photo FROM users WHERE id = ?");
                    $p_stmt->bind_param("i", $target_id);
                    $p_stmt->execute();
                    $p_res = $p_stmt->get_result();
                    if ($p_row = $p_res->fetch_assoc()) {
                        if (!empty($p_row["profile_photo"])) {
                            $file_path = __DIR__ . "/" . ltrim($p_row["profile_photo"], "/");
                            if (is_file($file_path)) {
                                @unlink($file_path);
                            }
                        }
                    }
                    $p_stmt->close();

                    // Delete from all dependent child tables
                    $tables = [
                        "transactions",
                        "recurring_transactions",
                        "budgets",
                        "debts_loans",
                        "medical_expenses",
                        "financial_goals",
                        "financial_calendar",
                        "accounts",
                        "categories",
                        "otp_verifications"
                    ];

                    foreach ($tables as $tbl) {
                        $del = $conn->prepare("DELETE FROM {$tbl} WHERE user_id = ?");
                        if ($del) {
                            $del->bind_param("i", $target_id);
                            $del->execute();
                            $del->close();
                        }
                    }

                    // Delete user record
                    $u_del = $conn->prepare("DELETE FROM users WHERE id = ?");
                    $u_del->bind_param("i", $target_id);
                    $u_del->execute();
                    $u_del->close();

                    $conn->commit();
                    $message = "User account and all related records have been deleted.";
                    $message_type = "success";

                } catch (Throwable $e) {
                    $conn->rollback();
                    $message = "Failed to delete user: " . $e->getMessage();
                    $message_type = "error";
                }
            }
        }
    }
}

// ==============================================================================
// FETCH USERS & SUMMARY METRICS
// ==============================================================================

$users = [];
$total_users = 0;
$total_admins = 0;
$total_verified = 0;
$total_2fa = 0;

$query = "
    SELECT 
        u.id, u.name, u.email, u.phone, u.is_admin,
        u.two_factor_enabled, u.two_factor_method,
        u.email_verified_at, u.phone_verified_at,
        u.profile_photo, u.created_at,
        COUNT(t.id) AS transaction_count,
        COALESCE(SUM(CASE WHEN t.type = 'income' THEN t.amount ELSE 0 END), 0) AS total_income,
        COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END), 0) AS total_expense
    FROM users u
    LEFT JOIN transactions t ON u.id = t.user_id
    GROUP BY u.id
    ORDER BY u.id DESC
";

$res = $conn->query($query);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $users[] = $row;
        $total_users++;
        if (!empty($row["is_admin"])) {
            $total_admins++;
        }
        if (!empty($row["email_verified_at"])) {
            $total_verified++;
        }
        if (!empty($row["two_factor_enabled"])) {
            $total_2fa++;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users Manager (Admin) | Personal Expense Tracker</title>
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
            margin-bottom: 28px;
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
            letter-spacing: -0.5px;
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

        /* STATS GRID */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: #101c2d;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 22px;
            display: flex;
            align-items: center;
            gap: 18px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
        }

        .stat-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .stat-icon.blue { background: rgba(37, 99, 235, 0.18); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); }
        .stat-icon.purple { background: rgba(168, 85, 247, 0.18); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.3); }
        .stat-icon.green { background: rgba(16, 185, 129, 0.18); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .stat-icon.cyan { background: rgba(6, 182, 212, 0.18); color: #22d3ee; border: 1px solid rgba(6, 182, 212, 0.3); }

        .stat-info h3 {
            font-size: 26px;
            font-weight: 800;
            color: #ffffff;
            line-height: 1.1;
        }

        .stat-info p {
            font-size: 13px;
            color: #8da2bb;
            margin-top: 4px;
            font-weight: 600;
        }

        /* ALERTS */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 24px;
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

        /* TOOLBAR */
        .toolbar {
            background: #101c2d;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 18px 24px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .search-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            max-width: 480px;
        }

        .search-input {
            width: 100%;
            height: 44px;
            padding: 0 16px;
            border-radius: 10px;
            background: #090e17;
            border: 1px solid #1e2d42;
            color: #ffffff;
            font-size: 14px;
            outline: none;
            transition: all 0.2s;
        }

        .search-input:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.12);
        }

        .filter-select {
            height: 44px;
            padding: 0 16px;
            border-radius: 10px;
            background: #090e17;
            border: 1px solid #1e2d42;
            color: #cbd5e1;
            font-size: 13px;
            font-weight: 600;
            outline: none;
            cursor: pointer;
        }

        .btn-add {
            background: linear-gradient(135deg, #16b5f4, #168ce8);
            border: none;
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
            box-shadow: 0 6px 18px rgba(22, 140, 232, 0.25);
        }

        .btn-add:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(22, 140, 232, 0.35);
        }

        /* TABLE CONTAINER */
        .table-card {
            background: #101c2d;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
        }

        .user-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        .user-table th {
            background: #0d1726;
            padding: 16px 20px;
            font-size: 12px;
            font-weight: 700;
            color: #8da2bb;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }

        .user-table td {
            padding: 18px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            font-size: 14px;
            vertical-align: middle;
        }

        .user-table tr:last-child td {
            border-bottom: none;
        }

        .user-table tr:hover td {
            background: rgba(255, 255, 255, 0.02);
        }

        /* USER COLUMN */
        .user-cell {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .avatar {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 16px;
            color: #ffffff;
            background: linear-gradient(135deg, #3b82f6, #14b8a6);
            flex-shrink: 0;
            overflow: hidden;
        }

        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .user-meta .name {
            font-weight: 700;
            color: #ffffff;
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .badge-self {
            font-size: 10px;
            padding: 2px 7px;
            border-radius: 6px;
            background: rgba(34, 197, 94, 0.2);
            color: #4ade80;
            font-weight: 700;
        }

        .user-meta .joined {
            font-size: 12px;
            color: #64748b;
            margin-top: 3px;
        }

        /* BADGES */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.3px;
        }

        .badge.admin {
            background: rgba(32, 185, 245, 0.15);
            color: #38bdf8;
            border: 1px solid rgba(56, 189, 248, 0.3);
        }

        .badge.user {
            background: rgba(148, 163, 184, 0.12);
            color: #cbd5e1;
            border: 1px solid rgba(148, 163, 184, 0.25);
        }

        .pill-verified {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
        }

        .pill-unverified {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
        }

        .pill-2fa-on {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            background: rgba(59, 130, 246, 0.15);
            color: #60a5fa;
            margin-left: 4px;
        }

        .pill-2fa-off {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            background: rgba(100, 116, 139, 0.15);
            color: #94a3b8;
            margin-left: 4px;
        }

        /* ACTION BUTTONS */
        .actions-cell {
            display: flex;
            gap: 8px;
        }

        .btn-action {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(255, 255, 255, 0.05);
            color: #cbd5e1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
        }

        .btn-action:hover {
            background: rgba(32, 185, 245, 0.2);
            border-color: #38bdf8;
            color: #ffffff;
            transform: translateY(-1px);
        }

        .btn-action.delete:hover {
            background: rgba(239, 68, 68, 0.25);
            border-color: #f87171;
            color: #fca5a5;
        }

        .btn-action:disabled {
            opacity: 0.3;
            cursor: not-allowed;
            transform: none !important;
        }

        /* MODALS */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(3, 7, 18, 0.8);
            backdrop-filter: blur(5px);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-card {
            background: #101c2d;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 20px;
            width: 100%;
            max-width: 500px;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.5);
            overflow: hidden;
            animation: modalFadeIn 0.2s ease-out;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.96); }
            to { opacity: 1; transform: scale(1); }
        }

        .modal-header {
            padding: 22px 28px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-header h3 {
            font-size: 18px;
            font-weight: 700;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-close {
            background: transparent;
            border: none;
            color: #94a3b8;
            font-size: 22px;
            cursor: pointer;
            line-height: 1;
        }

        .modal-close:hover {
            color: #ffffff;
        }

        .modal-body {
            padding: 28px;
        }

        .modal-footer {
            padding: 18px 28px;
            background: #0c1524;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 700;
            color: #cbd5e1;
        }

        .form-control {
            width: 100%;
            height: 44px;
            padding: 0 14px;
            border-radius: 10px;
            background: #090e17;
            border: 1px solid #1e2d42;
            color: #ffffff;
            font-size: 14px;
            outline: none;
        }

        .form-control:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.12);
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #cbd5e1;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            user-select: none;
            margin-top: 6px;
        }

        .checkbox-label input {
            width: 16px;
            height: 16px;
            accent-color: #38bdf8;
        }

        .btn-cancel {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #cbd5e1;
            padding: 10px 18px;
            border-radius: 9px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-cancel:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            border: none;
            color: #ffffff;
            padding: 10px 20px;
            border-radius: 9px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(239, 68, 68, 0.3);
        }

        .btn-submit {
            background: linear-gradient(135deg, #16b5f4, #168ce8);
            border: none;
            color: #ffffff;
            padding: 10px 20px;
            border-radius: 9px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(22, 140, 232, 0.3);
        }

        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 900px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .toolbar {
                flex-direction: column;
                align-items: stretch;
            }
            .search-wrap {
                max-width: 100%;
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
                    <span>👥</span> User Management Portal
                    <span class="badge-admin">Admin Control</span>
                </h1>
                <p>Manage all registered user accounts, assign admin privileges, reset credentials, and monitor system activity.</p>
            </div>

            <button type="button" class="btn-add" onclick="openCreateModal()">
                <span>＋</span> Add New User
            </button>
        </div>

        <!-- Feedback Alert -->
        <?php if (!empty($message)): ?>
            <div class="alert <?php echo htmlspecialchars($message_type); ?>">
                <span><?php echo htmlspecialchars($message); ?></span>
                <span style="cursor: pointer;" onclick="this.parentElement.style.display='none';">&times;</span>
            </div>
        <?php endif; ?>

        <!-- Statistics Overview -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue">👥</div>
                <div class="stat-info">
                    <h3><?php echo $total_users; ?></h3>
                    <p>Total Registered Users</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon cyan">🛡️</div>
                <div class="stat-info">
                    <h3><?php echo $total_admins; ?></h3>
                    <p>System Administrators</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon green">✓</div>
                <div class="stat-info">
                    <h3><?php echo $total_verified; ?></h3>
                    <p>Verified Emails</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon purple">🔐</div>
                <div class="stat-info">
                    <h3><?php echo $total_2fa; ?></h3>
                    <p>2FA-Protected Accounts</p>
                </div>
            </div>
        </div>

        <!-- Search and Filter Toolbar -->
        <div class="toolbar">
            <div class="search-wrap">
                <input
                    type="text"
                    id="searchFilter"
                    class="search-input"
                    placeholder="🔍 Search users by name, email, or phone..."
                    onkeyup="filterUsersTable()"
                >
            </div>

            <div style="display: flex; gap: 10px; align-items: center;">
                <select id="roleFilter" class="filter-select" onchange="filterUsersTable()">
                    <option value="all">All Roles</option>
                    <option value="admin">Admins Only</option>
                    <option value="user">Regular Users</option>
                </select>

                <select id="statusFilter" class="filter-select" onchange="filterUsersTable()">
                    <option value="all">All Statuses</option>
                    <option value="verified">Verified Only</option>
                    <option value="unverified">Unverified</option>
                    <option value="2fa">2FA Enabled</option>
                </select>
            </div>
        </div>

        <!-- User Accounts Table -->
        <div class="table-card">
            <table class="user-table" id="usersTable">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Contact</th>
                        <th>Role</th>
                        <th>Security &amp; 2FA</th>
                        <th>Activity</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px; color: #64748b;">
                                No users found in database.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $u): ?>
                            <?php
                            $uid = (int)$u["id"];
                            $uname = htmlspecialchars($u["name"]);
                            $uemail = htmlspecialchars($u["email"]);
                            $uphone = htmlspecialchars($u["phone"] ?? "");
                            $uadmin = !empty($u["is_admin"]);
                            $u2fa = !empty($u["two_factor_enabled"]);
                            $uverified = !empty($u["email_verified_at"]);
                            $ucreated = date("M d, Y", strtotime($u["created_at"]));
                            $initial = strtoupper(substr($u["name"], 0, 1) ?: "U");
                            $is_current = ($uid === $user_id);
                            ?>
                            <tr
                                class="user-row"
                                data-name="<?php echo strtolower($uname); ?>"
                                data-email="<?php echo strtolower($uemail); ?>"
                                data-phone="<?php echo strtolower($uphone); ?>"
                                data-role="<?php echo $uadmin ? 'admin' : 'user'; ?>"
                                data-verified="<?php echo $uverified ? 'verified' : 'unverified'; ?>"
                                data-2fa="<?php echo $u2fa ? '2fa' : 'no2fa'; ?>"
                            >
                                <!-- User Info -->
                                <td>
                                    <div class="user-cell">
                                        <div class="avatar">
                                            <?php if (!empty($u["profile_photo"])): ?>
                                                <img src="<?php echo htmlspecialchars($u["profile_photo"]); ?>" alt="<?php echo $uname; ?>">
                                            <?php else: ?>
                                                <?php echo $initial; ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="user-meta">
                                            <div class="name">
                                                <?php echo $uname; ?>
                                                <?php if ($is_current): ?>
                                                    <span class="badge-self">You</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="joined">Joined <?php echo $ucreated; ?> &bull; ID #<?php echo $uid; ?></div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Contact Info -->
                                <td>
                                    <div style="font-weight: 600; color: #e2e8f0;"><?php echo $uemail; ?></div>
                                    <div style="font-size: 12px; color: #64748b; margin-top: 2px;">
                                        <?php echo $uphone !== "" ? $uphone : "No phone added"; ?>
                                    </div>
                                </td>

                                <!-- Role -->
                                <td>
                                    <?php if ($uadmin): ?>
                                        <span class="badge admin">🛡️ Admin</span>
                                    <?php else: ?>
                                        <span class="badge user">👤 User</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Security & Verification -->
                                <td>
                                    <div>
                                        <?php if ($uverified): ?>
                                            <span class="pill-verified">✓ Verified</span>
                                        <?php else: ?>
                                            <span class="pill-unverified">⚠️ Unverified</span>
                                        <?php endif; ?>

                                        <?php if ($u2fa): ?>
                                            <span class="pill-2fa-on">2FA On</span>
                                        <?php else: ?>
                                            <span class="pill-2fa-off">2FA Off</span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Activity Summary -->
                                <td>
                                    <div style="font-size: 13px; font-weight: 700; color: #e2e8f0;">
                                        <?php echo (int)$u["transaction_count"]; ?> Transactions
                                    </div>
                                    <div style="font-size: 11px; color: #64748b; margin-top: 2px;">
                                        In: ₹<?php echo number_format((float)$u["total_income"], 2); ?>
                                    </div>
                                </td>

                                <!-- Actions -->
                                <td>
                                    <div class="actions-cell" style="justify-content: flex-end;">
                                        <!-- Edit User -->
                                        <button
                                            type="button"
                                            class="btn-action"
                                            title="Edit user details & permissions"
                                            onclick='openEditModal(<?php echo json_encode([
                                                "id" => $uid,
                                                "name" => $u["name"],
                                                "email" => $u["email"],
                                                "phone" => $u["phone"] ?? "",
                                                "is_admin" => $uadmin ? 1 : 0,
                                                "two_factor_enabled" => $u2fa ? 1 : 0,
                                                "email_verified" => $uverified ? 1 : 0,
                                                "is_self" => $is_current
                                            ]); ?>)'
                                        >
                                            ✏️
                                        </button>

                                        <!-- Reset Password -->
                                        <button
                                            type="button"
                                            class="btn-action"
                                            title="Reset user password"
                                            onclick="openResetPasswordModal(<?php echo $uid; ?>, '<?php echo addslashes($uname); ?>')"
                                        >
                                            🔑
                                        </button>

                                        <!-- Delete User -->
                                        <button
                                            type="button"
                                            class="btn-action delete"
                                            title="<?php echo $is_current ? 'You cannot delete your own account' : 'Delete user account and all data'; ?>"
                                            <?php echo $is_current ? 'disabled' : ''; ?>
                                            onclick="openDeleteModal(<?php echo $uid; ?>, '<?php echo addslashes($uname); ?>', '<?php echo addslashes($uemail); ?>')"
                                        >
                                            🗑️
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main>
</div>

<!-- ==============================================================================
     MODAL: CREATE NEW USER
     ============================================================================== -->
<div id="createModal" class="modal-overlay">
    <div class="modal-card">
        <form method="POST" action="admin_users.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="create_user">

            <div class="modal-header">
                <h3><span>＋</span> Create New User Account</h3>
                <button type="button" class="modal-close" onclick="closeModal('createModal')">&times;</button>
            </div>

            <div class="modal-body">
                <div class="form-group">
                    <label for="create_name">Full Name *</label>
                    <input type="text" class="form-control" id="create_name" name="name" placeholder="e.g. John Doe" required>
                </div>

                <div class="form-group">
                    <label for="create_email">Email Address *</label>
                    <input type="email" class="form-control" id="create_email" name="email" placeholder="e.g. user@example.com" required>
                </div>

                <div class="form-group">
                    <label for="create_phone">Phone Number (Optional)</label>
                    <input type="text" class="form-control" id="create_phone" name="phone" placeholder="e.g. +91 9876543210">
                </div>

                <div class="form-group">
                    <label for="create_password">Password *</label>
                    <input type="password" class="form-control" id="create_password" name="password" placeholder="Minimum 6 characters" required>
                </div>

                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid rgba(255,255,255,0.08);">
                    <label class="checkbox-label">
                        <input type="checkbox" name="is_admin" value="1">
                        <span>Grant Administrator Privileges</span>
                    </label>

                    <label class="checkbox-label" style="margin-top: 10px;">
                        <input type="checkbox" name="email_verified" value="1" checked>
                        <span>Mark Email as Verified Immediately</span>
                    </label>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('createModal')">Cancel</button>
                <button type="submit" class="btn-submit">Create Account</button>
            </div>
        </form>
    </div>
</div>

<!-- ==============================================================================
     MODAL: EDIT USER DETAILS
     ============================================================================== -->
<div id="editModal" class="modal-overlay">
    <div class="modal-card">
        <form method="POST" action="admin_users.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="update_user">
            <input type="hidden" name="user_id" id="edit_user_id">

            <div class="modal-header">
                <h3><span>✏️</span> Edit User Account</h3>
                <button type="button" class="modal-close" onclick="closeModal('editModal')">&times;</button>
            </div>

            <div class="modal-body">
                <div class="form-group">
                    <label for="edit_name">Full Name *</label>
                    <input type="text" class="form-control" id="edit_name" name="name" required>
                </div>

                <div class="form-group">
                    <label for="edit_email">Email Address *</label>
                    <input type="email" class="form-control" id="edit_email" name="email" required>
                </div>

                <div class="form-group">
                    <label for="edit_phone">Phone Number</label>
                    <input type="text" class="form-control" id="edit_phone" name="phone">
                </div>

                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid rgba(255,255,255,0.08);">
                    <label class="checkbox-label" id="edit_admin_wrap">
                        <input type="checkbox" name="is_admin" id="edit_is_admin" value="1">
                        <span>Administrator Privileges</span>
                    </label>
                    <div id="self_admin_notice" style="display: none; font-size: 11px; color: #94a3b8; margin-left: 26px; margin-top: 2px;">
                        (You cannot remove your own admin status)
                    </div>

                    <label class="checkbox-label" style="margin-top: 12px;">
                        <input type="checkbox" name="email_verified" id="edit_email_verified" value="1">
                        <span>Email Address Verified</span>
                    </label>

                    <label class="checkbox-label" style="margin-top: 12px;">
                        <input type="checkbox" name="two_factor_enabled" id="edit_two_factor_enabled" value="1">
                        <span>Two-Factor Authentication (2FA) Active</span>
                    </label>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn-submit">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ==============================================================================
     MODAL: RESET USER PASSWORD
     ============================================================================== -->
<div id="resetPasswordModal" class="modal-overlay">
    <div class="modal-card">
        <form method="POST" action="admin_users.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="reset_user_id">

            <div class="modal-header">
                <h3><span>🔑</span> Reset User Password</h3>
                <button type="button" class="modal-close" onclick="closeModal('resetPasswordModal')">&times;</button>
            </div>

            <div class="modal-body">
                <p style="font-size: 14px; color: #94a3b8; margin-bottom: 18px; line-height: 1.5;">
                    Set a new password for <strong id="reset_user_name" style="color: #38bdf8;"></strong>. The user will be able to log in with this password immediately.
                </p>

                <div class="form-group">
                    <label for="new_password">New Password *</label>
                    <input type="password" class="form-control" id="new_password" name="new_password" placeholder="Minimum 6 characters" required>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('resetPasswordModal')">Cancel</button>
                <button type="submit" class="btn-submit">Update Password</button>
            </div>
        </form>
    </div>
</div>

<!-- ==============================================================================
     MODAL: DELETE USER CONFIRMATION
     ============================================================================== -->
<div id="deleteModal" class="modal-overlay">
    <div class="modal-card">
        <form method="POST" action="admin_users.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="delete_user">
            <input type="hidden" name="user_id" id="delete_user_id">

            <div class="modal-header">
                <h3 style="color: #f87171;"><span>⚠️</span> Delete User Account</h3>
                <button type="button" class="modal-close" onclick="closeModal('deleteModal')">&times;</button>
            </div>

            <div class="modal-body">
                <p style="font-size: 14px; color: #cbd5e1; line-height: 1.6;">
                    Are you sure you want to permanently delete the account for <strong id="delete_user_name" style="color: #ffffff;"></strong> (<span id="delete_user_email" style="color: #38bdf8;"></span>)?
                </p>
                <div style="margin-top: 14px; padding: 12px 14px; background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.25); border-radius: 10px; font-size: 12px; color: #fca5a5; line-height: 1.5;">
                    <strong>Warning:</strong> This action cannot be undone. All transactions, budgets, financial goals, accounts, and profile data belonging to this user will be deleted permanently.
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('deleteModal')">Cancel</button>
                <button type="submit" class="btn-danger">Permanently Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
// Open / Close Modals
function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = "flex";
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = "none";
}

// Close modal on escape key
document.addEventListener("keydown", function(e) {
    if (e.key === "Escape") {
        document.querySelectorAll(".modal-overlay").forEach(m => m.style.display = "none");
    }
});

function openCreateModal() {
    openModal("createModal");
}

function openEditModal(user) {
    document.getElementById("edit_user_id").value = user.id;
    document.getElementById("edit_name").value = user.name;
    document.getElementById("edit_email").value = user.email;
    document.getElementById("edit_phone").value = user.phone || "";
    document.getElementById("edit_is_admin").checked = !!user.is_admin;
    document.getElementById("edit_two_factor_enabled").checked = !!user.two_factor_enabled;
    document.getElementById("edit_email_verified").checked = !!user.email_verified;

    const selfNotice = document.getElementById("self_admin_notice");
    const adminCheckbox = document.getElementById("edit_is_admin");
    if (user.is_self) {
        adminCheckbox.disabled = true;
        selfNotice.style.display = "block";
    } else {
        adminCheckbox.disabled = false;
        selfNotice.style.display = "none";
    }

    openModal("editModal");
}

function openResetPasswordModal(id, name) {
    document.getElementById("reset_user_id").value = id;
    document.getElementById("reset_user_name").textContent = name;
    document.getElementById("new_password").value = "";
    openModal("resetPasswordModal");
}

function openDeleteModal(id, name, email) {
    document.getElementById("delete_user_id").value = id;
    document.getElementById("delete_user_name").textContent = name;
    document.getElementById("delete_user_email").textContent = email;
    openModal("deleteModal");
}

// Live Search & Filter
function filterUsersTable() {
    const searchQuery = document.getElementById("searchFilter").value.toLowerCase().trim();
    const roleFilter = document.getElementById("roleFilter").value;
    const statusFilter = document.getElementById("statusFilter").value;

    const rows = document.querySelectorAll(".user-row");

    rows.forEach(row => {
        const name = row.getAttribute("data-name") || "";
        const email = row.getAttribute("data-email") || "";
        const phone = row.getAttribute("data-phone") || "";
        const role = row.getAttribute("data-role") || "";
        const verified = row.getAttribute("data-verified") || "";
        const twoFa = row.getAttribute("data-2fa") || "";

        const matchesSearch = searchQuery === "" ||
            name.includes(searchQuery) ||
            email.includes(searchQuery) ||
            phone.includes(searchQuery);

        const matchesRole = roleFilter === "all" || role === roleFilter;

        let matchesStatus = true;
        if (statusFilter === "verified") {
            matchesStatus = (verified === "verified");
        } else if (statusFilter === "unverified") {
            matchesStatus = (verified === "unverified");
        } else if (statusFilter === "2fa") {
            matchesStatus = (twoFa === "2fa");
        }

        if (matchesSearch && matchesRole && matchesStatus) {
            row.style.display = "";
        } else {
            row.style.display = "none";
        }
    });
}
</script>

</body>
</html>
