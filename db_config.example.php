<?php
/**
 * InfinityFree / Shared Hosting Database Configuration
 * -----------------------------------------------------
 * If you are deploying to InfinityFree:
 * 1. Rename this file to `db_config.php` (or copy it to `db_config.php`)
 * 2. Fill in the values below from your InfinityFree Client Area -> "MySQL Details"
 * 3. Upload `db_config.php` inside the `htdocs/` folder on InfinityFree
 */

// Replace the placeholder values below with your actual InfinityFree MySQL details:
define("DB_HOST", "sqlXXX.infinityfree.com"); // e.g., sql101.infinityfree.com (NOT localhost)
define("DB_PORT", 3306);
define("DB_NAME", "if0_XXXXXXXX_personal_expense_tracker"); // e.g., if0_38123456_expense
define("DB_USER", "if0_XXXXXXXX");                         // e.g., if0_38123456
define("DB_PASS", "YourAccountPassword");                  // Your vPanel/account password
