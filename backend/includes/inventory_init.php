<?php
// Support Center Inventory Database Initialization & Hardware Sync (PHP 5.6 Compatible)
require_once __DIR__ . '/config.php';

function init_inventory_tables() {
    $pdo = get_db_connection();
    if (!$pdo) {
        return false;
    }

    $sql1 = "CREATE TABLE IF NOT EXISTS `support_inventory_items` (
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

    $sql2 = "CREATE TABLE IF NOT EXISTS `support_inventory_logs` (
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

    $sql3 = "CREATE TABLE IF NOT EXISTS `support_system_meta` (
        `meta_key` VARCHAR(50) NOT NULL UNIQUE,
        `meta_value` VARCHAR(255) NOT NULL,
        PRIMARY KEY (`meta_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

    try {
        $pdo->exec($sql1);
        $pdo->exec($sql2);
        $pdo->exec($sql3);

        // Check if initial seeding has already run once
        $seeded_stmt = $pdo->query("SELECT `meta_value` FROM `support_system_meta` WHERE `meta_key` = 'inventory_seeded' LIMIT 1");
        $is_seeded = $seeded_stmt ? $seeded_stmt->fetchColumn() : false;
        if (!$is_seeded) {
            // First install only: seed catalog and mark as seeded
            seed_portal_hardware_inventory();
            $pdo->exec("INSERT INTO `support_system_meta` (`meta_key`, `meta_value`) VALUES ('inventory_seeded', '1') ON DUPLICATE KEY UPDATE `meta_value` = '1'");
        }
        return true;
    } catch (PDOException $e) {
        error_log("Inventory table init error: " . $e->getMessage());
        return false;
    }
}

function get_portal_hardware_catalog() {
    return array(
        'thermal-printer' => array(
            'code' => 'HW-PRN-80',
            'name' => 'Thermal Receipt Printer',
            'category' => 'Printers & POS',
            'description' => 'Direct thermal receipt printer for POS checkout terminals (58mm / 80mm roll, USB/Serial interface).',
            'image' => 'hardware_photos/thermal_printer.png',
            'qty' => 12,
            'min' => 4,
            'price' => 3500.00
        ),
        'dot-matrix-printer' => array(
            'code' => 'HW-PRN-DOT',
            'name' => 'Dot Matrix Impact Printer',
            'category' => 'Printers & POS',
            'description' => 'Impact dot-matrix receipt and invoice printer for continuous multi-part carbonless forms.',
            'image' => 'hardware_photos/dot_matrix_printer.jpg',
            'qty' => 5,
            'min' => 2,
            'price' => 8500.00
        ),
        'sticker-printer' => array(
            'code' => 'HW-PRN-STK',
            'name' => 'Barcode Sticker / Label Printer',
            'category' => 'Printers & POS',
            'description' => 'Direct thermal barcode sticker and product price tag label printer.',
            'image' => 'hardware_photos/stickerprinter.webp',
            'qty' => 8,
            'min' => 3,
            'price' => 4200.00
        ),
        'barcode-scanner' => array(
            'code' => 'HW-SCN-1D2D',
            'name' => '1D/2D Handheld Barcode Scanner',
            'category' => 'Scanners & Readers',
            'description' => 'High speed 1D laser & 2D QR barcode scanner with USB interface and stand.',
            'image' => 'hardware_photos/barcode_scanner.jpg',
            'qty' => 18,
            'min' => 5,
            'price' => 1800.00
        ),
        'customer-display' => array(
            'code' => 'HW-DSP-VFD',
            'name' => 'Customer Pole Display (VFD)',
            'category' => 'Displays & Monitors',
            'description' => 'Vacuum Fluorescent Display (VFD) 2-line pole screen showing item price and customer change.',
            'image' => 'hardware_photos/customer_display.jpg',
            'qty' => 6,
            'min' => 2,
            'price' => 2200.00
        ),
        'mouse' => array(
            'code' => 'HW-ACC-MSE',
            'name' => 'Optical USB Mouse',
            'category' => 'Peripherals & Cables',
            'description' => 'Standard wired optical USB mouse with scroll wheel.',
            'image' => 'hardware_photos/mouse.jpg',
            'qty' => 25,
            'min' => 8,
            'price' => 250.00
        ),
        'keyboard' => array(
            'code' => 'HW-ACC-KBD',
            'name' => 'Standard POS USB Keyboard',
            'category' => 'Peripherals & Cables',
            'description' => 'Full-size 104-key USB keyboard with dedicated numeric keypad for cashiers.',
            'image' => 'hardware_photos/keyboard.jpg',
            'qty' => 20,
            'min' => 6,
            'price' => 450.00
        ),
        'monitor' => array(
            'code' => 'HW-MON-LED',
            'name' => 'POS Display Monitor',
            'category' => 'Displays & Monitors',
            'description' => '15.6" - 19" HD LED / Touchscreen cashier display monitor (VGA/HDMI).',
            'image' => 'hardware_photos/monitor.png',
            'qty' => 9,
            'min' => 3,
            'price' => 5500.00
        ),
        'cash-drawer' => array(
            'code' => 'HW-CSH-RJ11',
            'name' => 'Heavy Duty POS Cash Drawer',
            'category' => 'Cash Management',
            'description' => 'Metallic 4/5-bill 8-coin cash drawer with RJ11 automatic printer kick-out sensor and manual key lock.',
            'image' => 'hardware_photos/cash_drawer.jpg',
            'qty' => 10,
            'min' => 3,
            'price' => 2800.00
        ),
        'rfid-reader' => array(
            'code' => 'HW-SCN-RFID',
            'name' => 'RFID Card Reader',
            'category' => 'Scanners & Readers',
            'description' => '13.56MHz / 125KHz USB contactless smart card / keyfob RFID reader module.',
            'image' => 'hardware_photos/rfid_reader.jpeg',
            'qty' => 14,
            'min' => 4,
            'price' => 1500.00
        ),
        'system-unit' => array(
            'code' => 'HW-SYS-POS',
            'name' => 'POS System Unit Chassis / PC Server',
            'category' => 'System Units & Power',
            'description' => 'Compact desktop / server PC unit running RNZ POS software with multi-port serial & USB.',
            'image' => 'hardware_photos/system_unit.jpg',
            'qty' => 6,
            'min' => 2,
            'price' => 18500.00
        ),
        'ups' => array(
            'code' => 'HW-PWR-UPS',
            'name' => 'Uninterruptible Power Supply (UPS)',
            'category' => 'System Units & Power',
            'description' => '650VA - 1000VA UPS battery backup with surge protection for POS checkout counters.',
            'image' => 'hardware_photos/ups.jpg',
            'qty' => 8,
            'min' => 3,
            'price' => 2900.00
        ),
        'router' => array(
            'code' => 'HW-NET-RTR',
            'name' => 'Wi-Fi / Ethernet Network Router',
            'category' => 'Networking',
            'description' => 'Dual-band Wi-Fi router with 4x Gigabit LAN ports for POS local network and database sharing.',
            'image' => 'hardware_photos/router.jpg',
            'qty' => 7,
            'min' => 2,
            'price' => 2100.00
        ),
        'lan-cable' => array(
            'code' => 'HW-NET-LAN',
            'name' => 'Cat6 RJ45 Network LAN Cable',
            'category' => 'Networking',
            'description' => 'Molded Cat6 UTP RJ45 Ethernet patch cord (3m - 10m).',
            'image' => 'hardware_photos/hardwarelan_cable.jpg',
            'qty' => 35,
            'min' => 10,
            'price' => 150.00
        ),
        'wifi-dongle' => array(
            'code' => 'HW-NET-WIFI',
            'name' => 'USB Wireless Wi-Fi Dongle Adapter',
            'category' => 'Networking',
            'description' => 'High-gain USB wireless Wi-Fi 802.11n/ac antenna adapter for POS terminal internet connectivity.',
            'image' => 'hardware_photos/wifi_dongle.jpg',
            'qty' => 28,
            'min' => 8,
            'price' => 350.00
        )
    );
}

function seed_portal_hardware_inventory() {
    $pdo = get_db_connection();
    if (!$pdo) {
        return 0;
    }

    $catalog = get_portal_hardware_catalog();
    $now = date('Y-m-d H:i:s');
    $inserted_count = 0;

    $stmt_check = $pdo->prepare("SELECT id FROM support_inventory_items WHERE item_code = :code OR name = :name LIMIT 1");
    $stmt_insert = $pdo->prepare("INSERT INTO support_inventory_items 
        (item_code, name, category, description, image_path, quantity, min_threshold, unit_price, location, status, created_at, updated_at) 
        VALUES (:code, :name, :category, :description, :image_path, :qty, :min, :price, 'Main Storage', 'Active', :now, :now)");

    $stmt_log = $pdo->prepare("INSERT INTO support_inventory_logs 
        (item_id, tech_name, change_type, quantity_change, previous_quantity, new_quantity, notes, created_at) 
        VALUES (:item_id, 'System Init', 'Initial Stock', :qty, 0, :qty, 'Seeded from Client Portal Hardware Catalog', :now)");

    foreach ($catalog as $key => $item) {
        $stmt_check->execute(array(':code' => $item['code'], ':name' => $item['name']));
        $existing = $stmt_check->fetch();
        if (!$existing) {
            $stmt_insert->execute(array(
                ':code' => $item['code'],
                ':name' => $item['name'],
                ':category' => $item['category'],
                ':description' => $item['description'],
                ':image_path' => $item['image'],
                ':qty' => $item['qty'],
                ':min' => $item['min'],
                ':price' => $item['price'],
                ':now' => $now
            ));
            $item_id = $pdo->lastInsertId();
            $stmt_log->execute(array(
                ':item_id' => $item_id,
                ':qty' => $item['qty'],
                ':now' => $now
            ));
            $inserted_count++;
        }
    }

    return $inserted_count;
}
?>
