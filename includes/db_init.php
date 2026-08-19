<?php
// Automatic Database Table Initialization for Client Support Portal & Hardware Logs
require_once __DIR__ . '/config.php';

function init_client_portal_tables() {
    $pdo = get_db_connection();
    if (!$pdo) {
        return false;
    }
    
    try {
        // 1. Create client_support_tickets table
    $sql1 = "CREATE TABLE IF NOT EXISTS `client_support_tickets` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `ticket_number` VARCHAR(50) NOT NULL UNIQUE,
        `accountnum` VARCHAR(100) NOT NULL,
        `clientname` VARCHAR(100) NOT NULL,
        `tradename` VARCHAR(100) NOT NULL,
        `subject` VARCHAR(255) NOT NULL,
        `category` VARCHAR(100) NOT NULL DEFAULT 'General Support',
        `priority` VARCHAR(50) NOT NULL DEFAULT 'Medium',
        `issue_description` TEXT NOT NULL,
        `attachment_path` TEXT NULL,
        `status` VARCHAR(50) NOT NULL DEFAULT 'Pending',
        `assigned_tech` VARCHAR(100) NOT NULL DEFAULT 'Unassigned',
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `accountnum` (`accountnum`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

    // 2. Create client_ticket_replies table
    $sql2 = "CREATE TABLE IF NOT EXISTS `client_ticket_replies` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `ticket_id` INT(11) NOT NULL,
        `sender_type` VARCHAR(20) NOT NULL,
        `sender_name` VARCHAR(100) NOT NULL,
        `message` TEXT NOT NULL,
        `attachment_path` TEXT NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `ticket_id` (`ticket_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

    // 3. Create hardware_troubleshooting_logs table
    $sql3 = "CREATE TABLE IF NOT EXISTS `hardware_troubleshooting_logs` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `accountnum` VARCHAR(100) NULL,
        `session_id` VARCHAR(100) NOT NULL,
        `ip_address` VARCHAR(50) NOT NULL,
        `hardware_selected` VARCHAR(100) NOT NULL,
        `issue_selected` VARCHAR(255) NULL,
        `question_id` VARCHAR(50) NULL,
        `selected_answer` VARCHAR(255) NULL,
        `custom_answer` TEXT NULL,
        `step_viewed` INT(11) DEFAULT 0,
        `step_completed` INT(11) DEFAULT 0,
        `time_started` DATETIME NOT NULL,
        `time_completed` DATETIME NULL,
        `resolution_status` VARCHAR(50) NOT NULL DEFAULT 'In Progress',
        `browser` VARCHAR(255) NULL,
        `operating_system` VARCHAR(255) NULL,
        `device_type` VARCHAR(100) NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `accountnum` (`accountnum`),
        KEY `hardware_selected` (`hardware_selected`),
        KEY `resolution_status` (`resolution_status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

    // 5. Create client_maintenance_requests table
    $sql4 = "CREATE TABLE IF NOT EXISTS `client_maintenance_requests` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `request_number` VARCHAR(50) NOT NULL UNIQUE,
        `accountnum` VARCHAR(100) NOT NULL,
        `tradename` VARCHAR(150) NOT NULL,
        `preferred_date` DATE NOT NULL,
        `preferred_time` VARCHAR(50) NOT NULL,
        `units_count` INT(11) NOT NULL DEFAULT 1,
        `location_address` TEXT NOT NULL,
        `contact_person` VARCHAR(150) NOT NULL,
        `contact_number` VARCHAR(50) NOT NULL,
        `additional_notes` TEXT NULL,
        `status` VARCHAR(50) NOT NULL DEFAULT 'Pending',
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `accountnum` (`accountnum`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

    // 6. Create support_inventory_items table
    $sql5 = "CREATE TABLE IF NOT EXISTS `support_inventory_items` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `item_code` VARCHAR(50) NOT NULL UNIQUE,
        `name` VARCHAR(150) NOT NULL,
        `category` VARCHAR(100) NOT NULL DEFAULT 'Hardware',
        `description` TEXT NULL,
        `image_path` VARCHAR(255) NULL,
        `quantity` INT(11) NOT NULL DEFAULT 0,
        `min_threshold` INT(11) NOT NULL DEFAULT 5,
        `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `location` VARCHAR(100) NULL DEFAULT 'Main Storage',
        `status` VARCHAR(50) NOT NULL DEFAULT 'Active',
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `category` (`category`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

    // 7. Create support_inventory_logs table
    $sql6 = "CREATE TABLE IF NOT EXISTS `support_inventory_logs` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `item_id` INT(11) NOT NULL,
        `tech_name` VARCHAR(100) NOT NULL,
        `change_type` VARCHAR(50) NOT NULL,
        `quantity_change` INT(11) NOT NULL,
        `previous_quantity` INT(11) NOT NULL,
        `new_quantity` INT(11) NOT NULL,
        `accountnum` VARCHAR(100) NULL,
        `client_name` VARCHAR(150) NULL,
        `notes` TEXT NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `item_id` (`item_id`),
        KEY `created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

        // Additional legacy tables if missing
        $sql7 = "CREATE TABLE IF NOT EXISTS `bucket_client` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `accountnum` VARCHAR(100) NOT NULL UNIQUE,
            `type` VARCHAR(50) DEFAULT 'Standard',
            `clientname` VARCHAR(150) NOT NULL,
            `tradename` VARCHAR(150) NOT NULL,
            `address` TEXT NULL,
            `contactnum` VARCHAR(100) NULL,
            `emailaddress` VARCHAR(150) NULL,
            `monthlyretainersfee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `outstandingbalance` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `warranty_status` VARCHAR(50) NOT NULL DEFAULT 'Inactive',
            `warranty_expiry` DATE NULL,
            `warranty_notes` TEXT NULL,
            `warranty_coverage_type` VARCHAR(50) NOT NULL DEFAULT 'Both',
            PRIMARY KEY (`id`),
            KEY `accountnum` (`accountnum`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

        $sql8 = "CREATE TABLE IF NOT EXISTS `bucket_technotes` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `accountnum` VARCHAR(100) NOT NULL,
            `xdate` DATE NOT NULL,
            `clientname` VARCHAR(150) NOT NULL,
            `address` TEXT NULL,
            `techname` VARCHAR(100) NOT NULL,
            `reasonoftech` TEXT NOT NULL,
            `causeoftheissue` TEXT NULL,
            `resso` TEXT NULL,
            `status` VARCHAR(50) NOT NULL DEFAULT 'Done',
            PRIMARY KEY (`id`),
            KEY `accountnum` (`accountnum`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

        $sql9 = "CREATE TABLE IF NOT EXISTS `bucket_workorder` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `accountnum` VARCHAR(100) NOT NULL,
            `xdate` DATE NOT NULL,
            `clientname` VARCHAR(150) NOT NULL,
            `address` TEXT NULL,
            `natureofwork` TEXT NOT NULL,
            `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `status` VARCHAR(50) NOT NULL DEFAULT 'Unpaid',
            `ornum` VARCHAR(100) NULL,
            PRIMARY KEY (`id`),
            KEY `accountnum` (`accountnum`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

        $sql10 = "CREATE TABLE IF NOT EXISTS `user` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `user` VARCHAR(100) NOT NULL UNIQUE,
            `pass` VARCHAR(255) NOT NULL,
            `fname` VARCHAR(100) NULL,
            `lname` VARCHAR(100) NULL,
            `emailadd` VARCHAR(150) NULL,
            `accesslevel` VARCHAR(50) NOT NULL DEFAULT 'technician',
            PRIMARY KEY (`id`),
            KEY `user` (`user`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

        $pdo->exec($sql1);
        $pdo->exec($sql2);
        $pdo->exec($sql3);
        $pdo->exec($sql4);
        $pdo->exec($sql5);
        $pdo->exec($sql6);
        $pdo->exec($sql7);
        $pdo->exec($sql8);
        $pdo->exec($sql9);
        $pdo->exec($sql10);

        // Ensure bucket_client has warranty fields
        try {
            $check_col = $pdo->query("SHOW COLUMNS FROM `bucket_client` LIKE 'warranty_status'");
            if ($check_col && $check_col->rowCount() == 0) {
                $pdo->exec("ALTER TABLE `bucket_client` ADD `warranty_status` VARCHAR(50) NOT NULL DEFAULT 'Inactive', ADD `warranty_expiry` DATE NULL, ADD `warranty_notes` TEXT NULL");
            }
            $check_cov = $pdo->query("SHOW COLUMNS FROM `bucket_client` LIKE 'warranty_coverage_type'");
            if ($check_cov && $check_cov->rowCount() == 0) {
                $pdo->exec("ALTER TABLE `bucket_client` ADD `warranty_coverage_type` VARCHAR(50) NOT NULL DEFAULT 'Both'");
            }

            // Ensure ticket & reply tables have attachment_path as TEXT
            $check_t_att = $pdo->query("SHOW COLUMNS FROM `client_support_tickets` LIKE 'attachment_path'");
            if ($check_t_att && $check_t_att->rowCount() == 0) {
                $pdo->exec("ALTER TABLE `client_support_tickets` ADD `attachment_path` TEXT NULL AFTER `issue_description`");
            } else {
                $pdo->exec("ALTER TABLE `client_support_tickets` MODIFY COLUMN `attachment_path` TEXT NULL");
            }

            $check_r_att = $pdo->query("SHOW COLUMNS FROM `client_ticket_replies` LIKE 'attachment_path'");
            if ($check_r_att && $check_r_att->rowCount() == 0) {
                $pdo->exec("ALTER TABLE `client_ticket_replies` ADD `attachment_path` TEXT NULL AFTER `message`");
            } else {
                $pdo->exec("ALTER TABLE `client_ticket_replies` MODIFY COLUMN `attachment_path` TEXT NULL");
            }
        } catch (PDOException $e_col) {
            // Non-blocking
        }
        return true;
    } catch (PDOException $e) {
        error_log("Table initialization error: " . $e->getMessage());
        return false;
    }
}

// Auto run on include
init_client_portal_tables();
?>
