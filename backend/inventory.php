<?php
// Support Center Hardware Inventory Hub (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/inventory_init.php';

require_page_access('inventory');

// Auto initialize inventory tables & default catalog
init_inventory_tables();

$pdo = get_db_connection();
$tech = get_logged_tech();
$tech_name = $tech ? $tech['fullname'] : 'Support Tech';

$msg = isset($_GET['msg']) ? sanitize($_GET['msg']) : '';
$msg_type = 'success';
$msg_text = '';

if ($msg === 'item_added') {
    $msg_text = 'Hardware item successfully added to inventory.';
} elseif ($msg === 'quantity_adjusted') {
    $msg_text = 'Stock quantity successfully updated and logged in movement audit.';
} elseif ($msg === 'pullout_success') {
    $item_name = isset($_GET['item']) ? sanitize($_GET['item']) : 'Hardware Item';
    $client_name = isset($_GET['client']) ? sanitize($_GET['client']) : 'Client';
    $msg_text = 'Hardware Pull-Out recorded successfully! Processed ' . $item_name . ' for ' . $client_name . ' and automatically updated stock & client service notes.';
} elseif ($msg === 'item_updated') {
    $msg_text = 'Item details successfully updated.';
} elseif ($msg === 'item_deleted') {
    $msg_text = 'Inventory item removed successfully.';
} elseif ($msg === 'synced') {
    $count = isset($_GET['count']) ? intval($_GET['count']) : 0;
    $msg_text = 'Synced with Client Portal Hardware catalog! ' . $count . ' new item(s) imported.';
} elseif ($msg === 'error') {
    $msg_type = 'error';
    $msg_text = isset($_GET['err_msg']) ? sanitize($_GET['err_msg']) : 'An error occurred during the operation.';
}

// ----------------------------------------------------
// Handle POST Actions
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 1. Pull Out Item (Hardware Pull-out from client or to client with auto service note)
    if ($action === 'pull_out_item') {
        $my_tier = get_logged_tech_access_tier();
        if ($my_tier === 1) {
            header("Location: inventory?msg=error&err_msg=" . urlencode("Access Denied: Level 1 (View Only) accounts cannot perform hardware pull outs."));
            exit;
        }
        if ($my_tier === 2) {
            $action_code = isset($_POST['action_access_code']) ? trim($_POST['action_access_code']) : '';
            $uid = $tech ? intval($tech['id']) : 0;
            if (!verify_user_access_code($uid, $action_code)) {
                header("Location: inventory?msg=error&err_msg=" . urlencode("Access Denied: Invalid Security Access Code. Level 2 accounts require a valid access code to confirm pull outs."));
                exit;
            }
        }

        $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
        $pullout_direction = isset($_POST['pullout_direction']) ? trim($_POST['pullout_direction']) : 'from_client';
        $amount = isset($_POST['amount']) ? intval($_POST['amount']) : 1;
        if ($amount < 1) $amount = 1;
        $accountnum = isset($_POST['accountnum']) ? trim($_POST['accountnum']) : '';
        $client_name = isset($_POST['client_name']) ? trim($_POST['client_name']) : '';
        $address = isset($_POST['address']) ? trim($_POST['address']) : '';
        $reason = isset($_POST['reason']) ? trim($_POST['reason']) : 'Defective Unit / Pull-out for Diagnostics';
        $custom_notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
        $serial_number = isset($_POST['serial_number']) ? trim($_POST['serial_number']) : '';
        $condition_status = isset($_POST['condition_status']) ? trim($_POST['condition_status']) : 'Defective / For Diagnostics';
        $restock_item = isset($_POST['restock_item']) ? intval($_POST['restock_item']) : 0;
        $auto_technote = isset($_POST['auto_technote']) ? intval($_POST['auto_technote']) : 1;

        if ($item_id <= 0) {
            header("Location: inventory?msg=error&err_msg=" . urlencode("Please select a valid hardware item."));
            exit;
        }
        if (empty($accountnum)) {
            header("Location: inventory?msg=error&err_msg=" . urlencode("Please select or specify the client account for this pull out."));
            exit;
        }

        try {
            $stmt_cur = $pdo->prepare("SELECT id, item_code, name, quantity FROM support_inventory_items WHERE id = :id LIMIT 1");
            $stmt_cur->execute(array(':id' => $item_id));
            $item_data = $stmt_cur->fetch();

            if (!$item_data) {
                header("Location: inventory?msg=error&err_msg=" . urlencode("Hardware item not found in inventory."));
                exit;
            }

            $prev_qty = intval($item_data['quantity']);
            $new_qty = $prev_qty;
            $qty_change = 0;
            $now = date('Y-m-d H:i:s');
            $today = date('Y-m-d');

            // Look up client details from bucket_client if needed
            $stmt_c = $pdo->prepare("SELECT tradename, clientname, address FROM bucket_client WHERE accountnum = :acct LIMIT 1");
            $stmt_c->execute(array(':acct' => $accountnum));
            $c_row = $stmt_c->fetch();
            if ($c_row) {
                if (empty($client_name)) {
                    $client_name = !empty($c_row['tradename']) ? $c_row['tradename'] : $c_row['clientname'];
                }
                if (empty($address)) {
                    $address = $c_row['address'];
                }
            }
            if (empty($client_name)) {
                $client_name = 'Account #' . $accountnum;
            }

            if ($pullout_direction === 'to_client') {
                $qty_change = -$amount;
                $new_qty = max(0, $prev_qty - $amount);
                $change_label = 'Pull Out (To Client)';
                $stmt_up = $pdo->prepare("UPDATE support_inventory_items SET quantity = :qty, updated_at = :now WHERE id = :id");
                $stmt_up->execute(array(':qty' => $new_qty, ':now' => $now, ':id' => $item_id));
            } else {
                $change_label = 'Pull Out (From Client)';
                if ($restock_item === 1) {
                    $qty_change = $amount;
                    $new_qty = $prev_qty + $amount;
                    $stmt_up = $pdo->prepare("UPDATE support_inventory_items SET quantity = :qty, updated_at = :now WHERE id = :id");
                    $stmt_up->execute(array(':qty' => $new_qty, ':now' => $now, ':id' => $item_id));
                } else {
                    $qty_change = 0;
                }
            }

            // Build compiled notes for inventory movement log
            $log_notes_parts = array();
            $log_notes_parts[] = "Reason: " . $reason;
            if (!empty($serial_number)) {
                $log_notes_parts[] = "S/N: " . $serial_number;
            }
            if (!empty($condition_status)) {
                $log_notes_parts[] = "Condition: " . $condition_status;
            }
            if (!empty($custom_notes)) {
                $log_notes_parts[] = "Notes: " . $custom_notes;
            }
            $compiled_notes = implode(' | ', $log_notes_parts);

            // 1. Insert Inventory Movement Log
            $stmt_log = $pdo->prepare("INSERT INTO support_inventory_logs 
                (item_id, tech_name, change_type, quantity_change, previous_quantity, new_quantity, accountnum, client_name, notes, created_at) 
                VALUES (:item_id, :tech, :type, :change, :prev, :new, :acct, :client, :notes, :now)");
            $stmt_log->execute(array(
                ':item_id' => $item_id,
                ':tech' => $tech_name,
                ':type' => $change_label,
                ':change' => $qty_change,
                ':prev' => $prev_qty,
                ':new' => $new_qty,
                ':acct' => $accountnum,
                ':client' => $client_name,
                ':notes' => $compiled_notes,
                ':now' => $now
            ));

            // 2. Automatically insert into bucket_technotes (Client Service Notes)
            if ($auto_technote === 1) {
                $tech_reason = "[Hardware Pull-Out] " . $item_data['item_code'] . " - " . $item_data['name'] . " (Qty: " . $amount . ")";
                $tech_cause = "Pull-Out Reason: " . $reason;
                $tech_resso_parts = array();
                $tech_resso_parts[] = ($pullout_direction === 'to_client') ? "Hardware deployed / released to client by " . $tech_name . "." : "Hardware pulled out from client by " . $tech_name . ".";
                if (!empty($serial_number)) {
                    $tech_resso_parts[] = "Serial Number: " . $serial_number . ".";
                }
                if (!empty($condition_status)) {
                    $tech_resso_parts[] = "Condition: " . $condition_status . ".";
                }
                if (!empty($custom_notes)) {
                    $tech_resso_parts[] = "Remarks: " . $custom_notes;
                }
                $tech_resso = implode(" ", $tech_resso_parts);
                $tech_status = (strpos(strtolower($condition_status), 'defective') !== false || strpos(strtolower($reason), 'defective') !== false || strpos(strtolower($reason), 'repair') !== false) ? 'For Repair' : 'Completed';

                $stmt_tn = $pdo->prepare("INSERT INTO bucket_technotes 
                    (accountnum, xdate, clientname, address, techname, reasonoftech, causeoftheissue, resso, status) 
                    VALUES (:acct, :xdate, :cname, :addr, :tname, :reason, :cause, :resso, :status)");
                $stmt_tn->execute(array(
                    ':acct' => $accountnum,
                    ':xdate' => $today,
                    ':cname' => $client_name,
                    ':addr' => !empty($address) ? $address : 'N/A',
                    ':tname' => $tech_name,
                    ':reason' => $tech_reason,
                    ':cause' => $tech_cause,
                    ':resso' => $tech_resso,
                    ':status' => $tech_status
                ));
            }

            header("Location: inventory?msg=pullout_success&item=" . urlencode($item_data['name']) . "&client=" . urlencode($client_name));
            exit;
        } catch (PDOException $e) {
            header("Location: inventory?msg=error&err_msg=" . urlencode($e->getMessage()));
            exit;
        }
    }

    // 2. Add New Item
    if ($action === 'add_item') {
        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        $item_code = isset($_POST['item_code']) ? trim($_POST['item_code']) : '';
        $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 0;
        $min_threshold = isset($_POST['min_threshold']) ? intval($_POST['min_threshold']) : 5;
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';

        if (empty($name)) {
            header("Location: inventory?msg=error&err_msg=" . urlencode("Item name is required."));
            exit;
        }

        if (empty($item_code)) {
            $item_code = 'HW-' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $name), 0, 4)) . '-' . rand(100, 999);
        }

        try {
            $now = date('Y-m-d H:i:s');
            $stmt_check = $pdo->prepare("SELECT id FROM support_inventory_items WHERE item_code = :code LIMIT 1");
            $stmt_check->execute(array(':code' => $item_code));
            if ($stmt_check->fetch()) {
                $item_code .= '-' . rand(10, 99);
            }

            $stmt_in = $pdo->prepare("INSERT INTO support_inventory_items 
                (item_code, name, category, description, image_path, quantity, min_threshold, unit_price, location, status, created_at, updated_at) 
                VALUES (:code, :name, 'Hardware', :description, NULL, :qty, :min, 0.00, 'Main Storage', 'Active', :now, :now)");
            $stmt_in->execute(array(
                ':code' => $item_code,
                ':name' => $name,
                ':description' => $description,
                ':qty' => $quantity,
                ':min' => $min_threshold,
                ':now' => $now
            ));
            $item_id = $pdo->lastInsertId();

            // Log Initial stock
            $stmt_log = $pdo->prepare("INSERT INTO support_inventory_logs 
                (item_id, tech_name, change_type, quantity_change, previous_quantity, new_quantity, notes, created_at) 
                VALUES (:item_id, :tech, 'Initial Stock', :qty, 0, :qty, 'Added new inventory item', :now)");
            $stmt_log->execute(array(
                ':item_id' => $item_id,
                ':tech' => $tech_name,
                ':qty' => $quantity,
                ':now' => $now
            ));

            header("Location: inventory?msg=item_added");
            exit;
        } catch (PDOException $e) {
            header("Location: inventory?msg=error&err_msg=" . urlencode($e->getMessage()));
            exit;
        }
    }

    // 2. Adjust Quantity (Stock In / Stock Out / Set)
    elseif ($action === 'adjust_quantity') {
        $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
        $adj_type = isset($_POST['adjustment_type']) ? $_POST['adjustment_type'] : 'add';
        $amount = isset($_POST['amount']) ? intval($_POST['amount']) : 0;
        $reason = isset($_POST['reason']) ? trim($_POST['reason']) : 'Manual Adjustment';
        $custom_notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
        $accountnum = isset($_POST['accountnum']) ? trim($_POST['accountnum']) : '';
        $client_name = isset($_POST['client_name']) ? trim($_POST['client_name']) : '';

        if ($item_id <= 0) {
            header("Location: inventory?msg=error&err_msg=" . urlencode("Invalid item selected."));
            exit;
        }

        try {
            $stmt_cur = $pdo->prepare("SELECT id, name, quantity FROM support_inventory_items WHERE id = :id LIMIT 1");
            $stmt_cur->execute(array(':id' => $item_id));
            $item_data = $stmt_cur->fetch();

            if (!$item_data) {
                header("Location: inventory?msg=error&err_msg=" . urlencode("Item not found."));
                exit;
            }

            $prev_qty = intval($item_data['quantity']);
            $new_qty = $prev_qty;
            $qty_change = 0;
            $change_label = 'Manual Adjustment';

            if ($adj_type === 'add') {
                $qty_change = max(1, $amount);
                $new_qty = $prev_qty + $qty_change;
                $change_label = 'Stock In';
            } elseif ($adj_type === 'subtract') {
                $qty_change = -max(1, $amount);
                $new_qty = max(0, $prev_qty + $qty_change);
                $change_label = 'Stock Out';
            } elseif ($adj_type === 'set') {
                $new_qty = max(0, $amount);
                $qty_change = $new_qty - $prev_qty;
                $change_label = 'Stock Set';
            }

            $now = date('Y-m-d H:i:s');
            $stmt_up = $pdo->prepare("UPDATE support_inventory_items SET quantity = :qty, updated_at = :now WHERE id = :id");
            $stmt_up->execute(array(':qty' => $new_qty, ':now' => $now, ':id' => $item_id));

            $full_notes = $reason;
            if (!empty($custom_notes)) {
                $full_notes .= ' - ' . $custom_notes;
            }

            $stmt_log = $pdo->prepare("INSERT INTO support_inventory_logs 
                (item_id, tech_name, change_type, quantity_change, previous_quantity, new_quantity, accountnum, client_name, notes, created_at) 
                VALUES (:item_id, :tech, :type, :change, :prev, :new, :acct, :client, :notes, :now)");
            $stmt_log->execute(array(
                ':item_id' => $item_id,
                ':tech' => $tech_name,
                ':type' => $change_label,
                ':change' => $qty_change,
                ':prev' => $prev_qty,
                ':new' => $new_qty,
                ':acct' => $accountnum,
                ':client' => $client_name,
                ':notes' => $full_notes,
                ':now' => $now
            ));

            header("Location: inventory?msg=quantity_adjusted");
            exit;
        } catch (PDOException $e) {
            header("Location: inventory?msg=error&err_msg=" . urlencode($e->getMessage()));
            exit;
        }
    }

    // 3. Edit Item Details
    elseif ($action === 'edit_item') {
        $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        $item_code = isset($_POST['item_code']) ? trim($_POST['item_code']) : '';
        $min_threshold = isset($_POST['min_threshold']) ? intval($_POST['min_threshold']) : 5;
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        $status = isset($_POST['status']) ? trim($_POST['status']) : 'Active';

        if ($item_id <= 0 || empty($name)) {
            header("Location: inventory?msg=error&err_msg=" . urlencode("Invalid item parameters."));
            exit;
        }

        try {
            $now = date('Y-m-d H:i:s');
            $stmt_up = $pdo->prepare("UPDATE support_inventory_items SET 
                item_code = :code, name = :name, min_threshold = :min, 
                description = :description, 
                status = :status, updated_at = :now WHERE id = :id");
            $stmt_up->execute(array(
                ':code' => $item_code,
                ':name' => $name,
                ':min' => $min_threshold,
                ':description' => $description,
                ':status' => $status,
                ':now' => $now,
                ':id' => $item_id
            ));

            header("Location: inventory?msg=item_updated");
            exit;
        } catch (PDOException $e) {
            header("Location: inventory?msg=error&err_msg=" . urlencode($e->getMessage()));
            exit;
        }
    }

    // 4. Delete Item
    elseif ($action === 'delete_item') {
        $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
        if ($item_id > 0) {
            try {
                $stmt_del = $pdo->prepare("DELETE FROM support_inventory_items WHERE id = :id");
                $stmt_del->execute(array(':id' => $item_id));

                $stmt_del_logs = $pdo->prepare("DELETE FROM support_inventory_logs WHERE item_id = :id");
                $stmt_del_logs->execute(array(':id' => $item_id));

                header("Location: inventory?msg=item_deleted");
                exit;
            } catch (PDOException $e) {
                header("Location: inventory?msg=error&err_msg=" . urlencode($e->getMessage()));
                exit;
            }
        }
    }

    // 5. Sync Portal Hardware
    elseif ($action === 'sync_portal_hardware') {
        $synced = seed_portal_hardware_inventory();
        header("Location: inventory?msg=synced&count=" . $synced);
        exit;
    }
}

// ----------------------------------------------------
// Filters and Query Setup
// ----------------------------------------------------
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$stock_status = isset($_GET['stock_status']) ? trim($_GET['stock_status']) : '';
$sort_by = isset($_GET['sort']) ? trim($_GET['sort']) : 'name_asc';

$where_clauses = array("1=1");
$params = array();

if (!empty($search)) {
    $where_clauses[] = "(name LIKE :s1 OR item_code LIKE :s2 OR description LIKE :s3)";
    $params[':s1'] = "%" . $search . "%";
    $params[':s2'] = "%" . $search . "%";
    $params[':s3'] = "%" . $search . "%";
}

if ($stock_status === 'low') {
    $where_clauses[] = "quantity <= min_threshold AND quantity > 0";
} elseif ($stock_status === 'out') {
    $where_clauses[] = "quantity = 0";
} elseif ($stock_status === 'in_stock') {
    $where_clauses[] = "quantity > min_threshold";
}

$order_by = "name ASC";
if ($sort_by === 'qty_asc') {
    $order_by = "quantity ASC";
} elseif ($sort_by === 'qty_desc') {
    $order_by = "quantity DESC";
} elseif ($sort_by === 'price_desc') {
    $order_by = "unit_price DESC";
} elseif ($sort_by === 'price_asc') {
    $order_by = "unit_price ASC";
} elseif ($sort_by === 'newest') {
    $order_by = "created_at DESC";
}

$where_sql = implode(" AND ", $where_clauses);
$stmt_items = $pdo->prepare("SELECT * FROM support_inventory_items WHERE $where_sql ORDER BY $order_by");
$stmt_items->execute($params);
$items = $stmt_items->fetchAll();

// KPI Stats
$total_skus = intval($pdo->query("SELECT COUNT(*) FROM support_inventory_items")->fetchColumn());
$total_units = intval($pdo->query("SELECT SUM(quantity) FROM support_inventory_items")->fetchColumn());
$low_stock_count = intval($pdo->query("SELECT COUNT(*) FROM support_inventory_items WHERE quantity <= min_threshold AND quantity > 0")->fetchColumn());
$out_of_stock_count = intval($pdo->query("SELECT COUNT(*) FROM support_inventory_items WHERE quantity = 0")->fetchColumn());
$total_val_row = $pdo->query("SELECT SUM(quantity * unit_price) FROM support_inventory_items")->fetchColumn();
$total_inventory_value = floatval($total_val_row ? $total_val_row : 0);

// Fetch all inventory items for quick dropdown selection in Pull Out Modal
$stmt_all_inv = $pdo->query("SELECT id, item_code, name, quantity, min_threshold, status FROM support_inventory_items WHERE status = 'Active' ORDER BY name ASC");
$all_inventory_items = $stmt_all_inv ? $stmt_all_inv->fetchAll() : array();

// Fetch Clients for Tagging in Pull-outs and Adjustments
$stmt_clients = $pdo->query("SELECT accountnum, tradename, clientname, address FROM bucket_client ORDER BY tradename ASC");
$clients_list = $stmt_clients ? $stmt_clients->fetchAll() : array();

// Fetch Recent 25 Inventory Movement Logs
$stmt_recent_logs = $pdo->query("SELECT l.*, i.name as item_name, i.item_code, i.image_path 
    FROM support_inventory_logs l 
    LEFT JOIN support_inventory_items i ON l.item_id = i.id 
    ORDER BY l.created_at DESC LIMIT 25");
$recent_logs = $stmt_recent_logs->fetchAll();

$portal_catalog = get_portal_hardware_catalog();

$preset_pullout_client = isset($_GET['pullout_client']) ? sanitize($_GET['pullout_client']) : '';
$preset_pullout_item = isset($_GET['pullout_item']) ? intval($_GET['pullout_item']) : 0;

$active_page = 'inventory';
$page_title = 'Hardware Inventory Hub';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hardware Inventory Hub - RNZ Admin</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#FFF5ED',
                            100: '#FFE8D5',
                            500: '#FA5915',
                            600: '#EB3E0B',
                            700: '#C32C0B',
                        }
                    }
                }
            }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 antialiased min-h-screen">

<div class="flex min-h-screen">
    <!-- Admin Sidebar Navigation -->
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col min-w-0">
        <!-- Top Admin Header -->
        <?php include __DIR__ . '/includes/header.php'; ?>

        <main class="p-6 sm:p-8 space-y-8 max-w-7xl w-full mx-auto">

            <!-- Toast / Status Alert Banner -->
            <?php if (!empty($msg_text)): ?>
                <div class="p-4 rounded-2xl flex items-center justify-between border shadow-sm transition-all <?php echo ($msg_type === 'error') ? 'bg-rose-50 border-rose-200 text-rose-800' : 'bg-emerald-50 border-emerald-200 text-emerald-800'; ?>">
                    <div class="flex items-center space-x-3">
                        <div class="w-8 h-8 rounded-xl flex items-center justify-center font-bold <?php echo ($msg_type === 'error') ? 'bg-rose-100 text-rose-600' : 'bg-emerald-100 text-emerald-600'; ?>">
                            <?php if ($msg_type === 'error'): ?>
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <?php else: ?>
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs sm:text-sm font-semibold"><?php echo sanitize($msg_text); ?></p>
                    </div>
                    <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-slate-600 p-1 rounded-lg">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            <?php endif; ?>

            <!-- Page Title & Header Actions -->
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                        <span>Hardware Inventory</span>
                        <span class="text-xs font-mono font-bold px-3 py-1 bg-slate-200 text-slate-700 rounded-full">
                            <?php echo number_format($total_skus); ?> Items
                        </span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-500 mt-1">
                        Manage thermal printers, scanners, monitors, cash drawers, and POS peripherals.
                    </p>
                </div>

                <!-- Top Quick Action Buttons -->
                <div class="flex items-center flex-wrap gap-2.5">
                    <!-- Sync / Seed Catalog Button -->
                    <form method="POST" action="" onsubmit="return confirm('Sync with Client Portal Hardware catalog to ensure all 15 core devices are populated?');" class="inline">
                        <input type="hidden" name="action" value="sync_portal_hardware">
                        <button type="submit" class="bg-white hover:bg-slate-50 text-slate-700 font-bold text-xs sm:text-sm px-4 py-2.5 rounded-2xl border border-slate-200 shadow-sm flex items-center space-x-2 transition-all hover:border-slate-300">
                            <svg class="w-4 h-4 text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                            <span>Sync Portal Hardware</span>
                        </button>
                    </form>

                    <!-- View Movement Log Button -->
                    <button onclick="openMovementLogModal()" class="bg-white hover:bg-slate-50 text-slate-700 font-bold text-xs sm:text-sm px-4 py-2.5 rounded-2xl border border-slate-200 shadow-sm flex items-center space-x-2 transition-all hover:border-slate-300">
                        <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>Stock Movement History</span>
                    </button>

                    <!-- Pull Out Items Button -->
                    <button onclick="openPullOutModal()" class="bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-white font-bold text-xs sm:text-sm px-4 py-2.5 rounded-2xl shadow-sm shadow-amber-500/30 flex items-center space-x-2 transition-all active:scale-95">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                        </svg>
                        <span>Pull Out Items</span>
                    </button>

                    <!-- Add New Item Button -->
                    <button onclick="openAddItemModal()" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs sm:text-sm px-5 py-2.5 rounded-2xl shadow-sm shadow-[#EB3E0B]/30 flex items-center space-x-2 transition-all active:scale-95">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                        </svg>
                        <span>Add New Item</span>
                    </button>
                </div>
            </div>

            <!-- KPI Metric Cards Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-5">
                <!-- Total In Stock Units -->
                <div class="bg-white rounded-3xl p-5 sm:p-6 border border-slate-200 shadow-sm flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Total Units in Stock</span>
                        <h3 class="text-3xl font-extrabold text-slate-900 font-mono"><?php echo number_format($total_units); ?></h3>
                        <p class="text-[11px] text-slate-400">Across <?php echo number_format($total_skus); ?> catalog items</p>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center font-bold">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                    </div>
                </div>

                <!-- Low Stock Alert -->
                <a href="inventory.php?stock_status=low" class="bg-white hover:bg-amber-50/40 rounded-3xl p-5 sm:p-6 border border-slate-200 hover:border-amber-300 shadow-sm flex items-center justify-between transition-all group">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-amber-600 uppercase tracking-wider">Low Stock Warning</span>
                        <h3 class="text-3xl font-extrabold text-amber-600 font-mono"><?php echo number_format($low_stock_count); ?></h3>
                        <p class="text-[11px] text-slate-400 group-hover:text-amber-700">At or below threshold level</p>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center font-bold">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                    </div>
                </a>

                <!-- Out of Stock -->
                <a href="inventory.php?stock_status=out" class="bg-white hover:bg-rose-50/40 rounded-3xl p-5 sm:p-6 border border-slate-200 hover:border-rose-300 shadow-sm flex items-center justify-between transition-all group">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-rose-600 uppercase tracking-wider">Out of Stock</span>
                        <h3 class="text-3xl font-extrabold text-rose-600 font-mono"><?php echo number_format($out_of_stock_count); ?></h3>
                        <p class="text-[11px] text-slate-400 group-hover:text-rose-700">Needs immediate replenishment</p>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-rose-50 text-rose-600 flex items-center justify-center font-bold">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                        </svg>
                    </div>
                </a>

                <!-- Total Valuation -->
                <div class="bg-white rounded-3xl p-5 sm:p-6 border border-slate-200 shadow-sm flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-emerald-600 uppercase tracking-wider">Inventory Valuation</span>
                        <h3 class="text-2xl sm:text-3xl font-extrabold text-emerald-700 font-mono truncate">₱<?php echo number_format($total_inventory_value, 2); ?></h3>
                        <p class="text-[11px] text-slate-400">Total estimated asset value</p>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Filters & Search Bar -->
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-5 sm:p-6 space-y-4">
                <form method="GET" action="" class="grid grid-cols-1 sm:grid-cols-12 gap-3">
                    <!-- Search Input -->
                    <div class="sm:col-span-6 relative">
                        <input type="text" name="search" value="<?php echo sanitize($search); ?>" placeholder="Search by hardware name, SKU, or description..." class="w-full bg-slate-50 text-slate-800 text-xs pl-10 pr-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none transition-all placeholder-slate-400">
                        <svg class="w-4 h-4 text-slate-400 absolute left-3.5 top-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </div>

                    <!-- Stock Status Filter -->
                    <div class="sm:col-span-4">
                        <select name="stock_status" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none transition-all font-semibold">
                            <option value="" <?php echo empty($stock_status) ? 'selected' : ''; ?>>All Stock Levels</option>
                            <option value="in_stock" <?php echo ($stock_status === 'in_stock') ? 'selected' : ''; ?>>In Stock (Sufficient)</option>
                            <option value="low" <?php echo ($stock_status === 'low') ? 'selected' : ''; ?>>Low Stock Warning</option>
                            <option value="out" <?php echo ($stock_status === 'out') ? 'selected' : ''; ?>>Out of Stock</option>
                        </select>
                    </div>

                    <!-- Sort and Filter Submit Button -->
                    <div class="sm:col-span-2 flex items-center gap-2">
                        <button type="submit" class="w-full bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs py-3 px-4 rounded-2xl transition-all shadow-sm">
                            Filter
                        </button>
                        <?php if (!empty($search) || !empty($stock_status)): ?>
                            <a href="inventory.php" class="p-3 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-2xl text-xs font-bold transition-all" title="Reset Filters">
                                &times;
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Inventory Items Table & Cards -->
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden space-y-4">
                <div class="p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                        <h3 class="text-lg font-extrabold text-slate-900">Inventory Items List</h3>
                        <p class="text-xs text-slate-500 mt-0.5">Manage quantities, thresholds, pricing and stock adjustments.</p>
                    </div>
                    <div class="text-xs text-slate-500 font-medium">
                        Showing <strong class="text-slate-900"><?php echo count($items); ?></strong> item(s)
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-100 text-slate-500 font-bold uppercase tracking-wider text-[11px]">
                                <th class="py-3.5 px-6">Hardware Item & Code</th>
                                <th class="py-3.5 px-6">Description / Remarks</th>
                                <th class="py-3.5 px-6 text-center">In Stock / Threshold</th>
                                <th class="py-3.5 px-6 text-center">Quick Adjust</th>
                                <th class="py-3.5 px-6 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium">
                            <?php if (empty($items)): ?>
                                <tr>
                                    <td colspan="5" class="py-12 text-center text-slate-400 space-y-3">
                                        <div class="w-12 h-12 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mx-auto">
                                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                        </div>
                                        <p class="font-bold text-slate-600">No hardware inventory items found.</p>
                                        <p class="text-xs">Try clearing search filters or click "Sync Portal Hardware" to import standard items.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($items as $item): 
                                    $qty = intval($item['quantity']);
                                    $min = intval($item['min_threshold']);
                                    
                                    // Status Badge styling
                                    if ($qty == 0) {
                                        $stock_badge = 'bg-rose-100 text-rose-800 border-rose-300';
                                        $stock_label = 'Out of Stock';
                                    } elseif ($qty <= $min) {
                                        $stock_badge = 'bg-amber-100 text-amber-800 border-amber-300';
                                        $stock_label = 'Low Stock (' . $qty . ')';
                                    } else {
                                        $stock_badge = 'bg-emerald-100 text-emerald-800 border-emerald-300';
                                        $stock_label = 'In Stock';
                                    }
                                ?>
                                    <tr class="hover:bg-slate-50/80 transition-colors">
                                        <!-- Item & Code -->
                                        <td class="py-4 px-6">
                                            <div class="min-w-0">
                                                <h4 class="font-extrabold text-slate-900 text-sm truncate max-w-xs" title="<?php echo sanitize($item['name']); ?>">
                                                    <?php echo sanitize($item['name']); ?>
                                                </h4>
                                                <div class="flex items-center space-x-2 mt-0.5">
                                                    <span class="font-mono font-bold text-xs text-[#EB3E0B]">
                                                        <?php echo sanitize($item['item_code']); ?>
                                                    </span>
                                                    <?php if ($item['status'] !== 'Active'): ?>
                                                        <span class="text-[10px] px-1.5 py-0.5 rounded bg-slate-200 text-slate-600 font-bold">
                                                            <?php echo sanitize($item['status']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Description / Remarks -->
                                        <td class="py-4 px-6 text-slate-600 text-xs max-w-xs">
                                            <?php if (!empty($item['description'])): ?>
                                                <p class="leading-relaxed text-slate-700 line-clamp-2" title="<?php echo sanitize($item['description']); ?>">
                                                    <?php echo sanitize($item['description']); ?>
                                                </p>
                                            <?php else: ?>
                                                <span class="text-slate-400 italic text-[11px]">No remarks</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Current Quantity & Threshold -->
                                        <td class="py-4 px-6 text-center">
                                            <div class="inline-flex flex-col items-center">
                                                <span class="text-base font-extrabold font-mono text-slate-900">
                                                    <?php echo number_format($qty); ?>
                                                </span>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold border mt-0.5 <?php echo $stock_badge; ?>">
                                                    <?php echo $stock_label; ?>
                                                </span>
                                                <span class="text-[10px] text-slate-400 font-mono mt-0.5">Min: <?php echo $min; ?></span>
                                            </div>
                                        </td>

                                        <!-- Quick Adjust (+1 / -1 buttons) -->
                                        <td class="py-4 px-6 text-center">
                                            <div class="inline-flex items-center space-x-1.5">
                                                <!-- Quick -1 -->
                                                <form method="POST" action="" class="inline">
                                                    <input type="hidden" name="action" value="adjust_quantity">
                                                    <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                                    <input type="hidden" name="adjustment_type" value="subtract">
                                                    <input type="hidden" name="amount" value="1">
                                                    <input type="hidden" name="reason" value="Quick -1 Stock Out">
                                                    <button type="submit" <?php echo ($qty <= 0) ? 'disabled' : ''; ?> class="w-7 h-7 rounded-xl bg-slate-100 hover:bg-rose-100 text-slate-600 hover:text-rose-700 font-bold flex items-center justify-center transition-colors disabled:opacity-40" title="Quick Subtract 1">
                                                        -
                                                    </button>
                                                </form>

                                                <!-- Open Detailed Adjust Modal -->
                                                <button onclick='openAdjustModal(<?php echo htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8'); ?>)' class="px-2.5 py-1 rounded-xl bg-slate-100 hover:bg-[#EB3E0B] text-slate-700 hover:text-white font-bold text-[11px] transition-colors" title="Adjust Stock Quantity">
                                                    Adjust
                                                </button>

                                                <!-- Open Pull Out Modal for this item -->
                                                <button onclick='openPullOutModalForItem(<?php echo htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8'); ?>)' class="px-2.5 py-1 rounded-xl bg-amber-50 hover:bg-amber-500 text-amber-800 hover:text-white border border-amber-200 font-bold text-[11px] transition-colors" title="Pull Out this Hardware Item">
                                                    Pull Out
                                                </button>

                                                <!-- Quick +1 -->
                                                <form method="POST" action="" class="inline">
                                                    <input type="hidden" name="action" value="adjust_quantity">
                                                    <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                                    <input type="hidden" name="adjustment_type" value="add">
                                                    <input type="hidden" name="amount" value="1">
                                                    <input type="hidden" name="reason" value="Quick +1 Stock In">
                                                    <button type="submit" class="w-7 h-7 rounded-xl bg-slate-100 hover:bg-emerald-100 text-slate-600 hover:text-emerald-700 font-bold flex items-center justify-center transition-colors" title="Quick Add 1">
                                                        +
                                                    </button>
                                                </form>
                                            </div>
                                         </td>

                                         <!-- Edit / Actions -->
                                         <td class="py-4 px-6 text-right">
                                             <div class="flex items-center justify-end space-x-1.5">
                                                 <!-- Edit Button -->
                                                 <button onclick='openEditModal(<?php echo json_encode($item); ?>)' class="w-8 h-8 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 hover:text-slate-900 flex items-center justify-center transition-colors" title="Edit Item">
                                                     <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                                 </button>

                                                 <!-- Delete Button -->
                                                 <form method="POST" action="" onsubmit="return confirm('Are you sure you want to delete this inventory item (<?php echo addslashes($item['name']); ?>)?');" class="inline">
                                                     <input type="hidden" name="action" value="delete_item">
                                                     <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                                     <button type="submit" class="w-8 h-8 rounded-xl bg-slate-100 hover:bg-rose-100 text-slate-500 hover:text-rose-600 flex items-center justify-center transition-colors" title="Delete Item">
                                                         <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                     </button>
                                                 </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: PULL OUT HARDWARE ITEM -->
<!-- ========================================================================= -->
<div id="pullOutModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 max-w-xl w-full p-6 sm:p-8 space-y-6 my-8 animate-in fade-in zoom-in duration-150">
        <div class="flex items-center justify-between border-b border-slate-100 pb-4">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-2xl bg-amber-500 text-white flex items-center justify-center font-bold shadow-md shadow-amber-500/25">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                    </svg>
                </div>
                <div>
                    <h3 class="font-extrabold text-lg text-slate-900">Pull Out Hardware Item</h3>
                    <p class="text-xs text-slate-500">Record hardware retrieval or deployment & sync with client service history.</p>
                </div>
            </div>
            <button type="button" onclick="closePullOutModal()" class="text-slate-400 hover:text-slate-600 p-2 rounded-full hover:bg-slate-100">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4">
            <input type="hidden" name="action" value="pull_out_item">

            <!-- 1. Pull Out Direction Toggle -->
            <div class="space-y-1.5">
                <label class="text-xs font-bold text-slate-700">Pull-Out Type / Movement Direction <span class="text-[#EB3E0B]">*</span></label>
                <div class="grid grid-cols-2 gap-2.5">
                    <label class="border-2 border-slate-200 rounded-2xl p-3 flex items-center space-x-3 cursor-pointer has-[:checked]:border-amber-500 has-[:checked]:bg-amber-50/50 has-[:checked]:text-amber-950 transition-all">
                        <input type="radio" name="pullout_direction" value="from_client" checked onchange="togglePulloutDirection(this.value)" class="text-amber-600 focus:ring-0">
                        <div>
                            <span class="block text-xs font-bold text-slate-900">🔄 From Client to Office</span>
                            <span class="block text-[10px] text-slate-500">Defective, repair, warranty pull-out</span>
                        </div>
                    </label>
                    <label class="border-2 border-slate-200 rounded-2xl p-3 flex items-center space-x-3 cursor-pointer has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50/50 has-[:checked]:text-emerald-950 transition-all">
                        <input type="radio" name="pullout_direction" value="to_client" onchange="togglePulloutDirection(this.value)" class="text-emerald-600 focus:ring-0">
                        <div>
                            <span class="block text-xs font-bold text-slate-900">📦 Deploy to Client</span>
                            <span class="block text-[10px] text-slate-500">Release & deduct from warehouse</span>
                        </div>
                    </label>
                </div>
            </div>

            <!-- 2. Hardware Item Selector -->
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-700">Hardware Item <span class="text-[#EB3E0B]">*</span></label>
                <select name="item_id" id="pullout_item_id" required onchange="updatePullOutItem(this)" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-amber-500 focus:bg-white focus:outline-none font-semibold">
                    <option value="">-- Choose Hardware Item --</option>
                    <?php foreach ($all_inventory_items as $inv_item): ?>
                        <option value="<?php echo $inv_item['id']; ?>" data-name="<?php echo sanitize($inv_item['name']); ?>" data-code="<?php echo sanitize($inv_item['item_code']); ?>" data-qty="<?php echo $inv_item['quantity']; ?>">
                            <?php echo sanitize($inv_item['name'] . ' (' . $inv_item['item_code'] . ') — Stock: ' . $inv_item['quantity'] . ' units'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div id="pullout_item_info" class="hidden text-[11px] font-mono text-slate-500 pt-1 flex items-center justify-between">
                    <span>Selected: <strong id="pullout_item_name_text" class="text-slate-800 font-sans"></strong></span>
                    <span>In Stock: <strong id="pullout_item_qty_text" class="text-amber-700"></strong> units</span>
                </div>
            </div>

            <!-- 3. Client Selection -->
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-700">To / From Which Client Account <span class="text-[#EB3E0B]">*</span></label>
                <select name="accountnum" id="pullout_accountnum" required onchange="updatePullOutClient(this)" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-amber-500 focus:bg-white focus:outline-none font-semibold">
                    <option value="">-- Choose Client Account --</option>
                    <?php foreach ($clients_list as $c): 
                        $display_title = !empty($c['tradename']) ? $c['tradename'] : $c['clientname'];
                    ?>
                        <option value="<?php echo sanitize($c['accountnum']); ?>" 
                                data-tradename="<?php echo sanitize($display_title); ?>"
                                data-address="<?php echo sanitize(isset($c['address']) ? $c['address'] : ''); ?>">
                            <?php echo sanitize($display_title . ' (Account #' . $c['accountnum'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="client_name" id="pullout_client_name" value="">
                <input type="hidden" name="address" id="pullout_client_address" value="">
            </div>

            <!-- 4. Quantity and Serial Number Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700">Quantity <span class="text-[#EB3E0B]">*</span></label>
                    <input type="number" name="amount" id="pullout_amount" min="1" value="1" required class="w-full bg-slate-50 text-slate-800 text-sm font-extrabold px-4 py-2.5 rounded-2xl border border-slate-200 focus:border-amber-500 focus:bg-white focus:outline-none font-mono">
                </div>
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700">Serial Number / Tracking #</label>
                    <input type="text" name="serial_number" placeholder="e.g., SN-99482104" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-2.5 rounded-2xl border border-slate-200 focus:border-amber-500 focus:bg-white focus:outline-none font-mono">
                </div>
            </div>

            <!-- 5. Reason for Pull-Out -->
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-700">Reason for Pull-Out <span class="text-[#EB3E0B]">*</span></label>
                <select name="reason" required class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-amber-500 focus:bg-white focus:outline-none font-medium">
                    <option value="Defective Unit / Pull-out for Diagnostics & Repair">Defective Unit / Pull-out for Diagnostics & Repair</option>
                    <option value="Warranty Replacement / Unit Exchange">Warranty Replacement / Unit Exchange</option>
                    <option value="Hardware Upgrade / Migration to New POS Model">Hardware Upgrade / Migration to New POS Model</option>
                    <option value="Store Closure / Subscription Termination">Store Closure / Subscription Termination</option>
                    <option value="Retrieval of Demo / Backup / Loaner Unit">Retrieval of Demo / Backup / Loaner Unit</option>
                    <option value="Preventive Maintenance Bench Testing">Preventive Maintenance Bench Testing</option>
                    <option value="Customer Sale / Deployment Release">Customer Sale / Deployment Release</option>
                    <option value="Other">Other / Custom Reason</option>
                </select>
            </div>

            <!-- 6. Item Condition / Diagnostic Status -->
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-700">Item Condition / Hardware Status</label>
                <select name="condition_status" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-2.5 rounded-2xl border border-slate-200 focus:border-amber-500 focus:bg-white focus:outline-none font-medium">
                    <option value="Defective / Needs Repair">Defective / Needs Repair</option>
                    <option value="Good / Functional (Working Unit)">Good / Functional (Working Unit)</option>
                    <option value="Damaged / Beyond Repair (Scrap)">Damaged / Beyond Repair (Scrap)</option>
                    <option value="For Diagnostic / Bench Testing">For Diagnostic / Bench Testing</option>
                </select>
            </div>

            <!-- 7. Restock Option (Only if from client) -->
            <div id="restock_option_box" class="p-3 bg-amber-50/70 border border-amber-200 rounded-2xl space-y-1">
                <label class="flex items-center space-x-2.5 cursor-pointer text-xs font-bold text-amber-900">
                    <input type="checkbox" name="restock_item" value="1" class="rounded text-amber-600 focus:ring-0">
                    <span>Add pulled-out unit back to available warehouse inventory stock (+Qty)</span>
                </label>
                <p class="text-[10px] text-amber-700 ml-6">Check this if the item is functional/repaired and ready for immediate deployment.</p>
            </div>

            <!-- 8. Auto Sync to Client Service Notes -->
            <div class="p-3 bg-slate-50 border border-slate-200 rounded-2xl space-y-1">
                <label class="flex items-center space-x-2.5 cursor-pointer text-xs font-bold text-slate-800">
                    <input type="checkbox" name="auto_technote" value="1" checked class="rounded text-[#EB3E0B] focus:ring-0">
                    <span>Automatically create a Technical Service Note in Client Account (<code class="text-[10px] font-mono text-[#EB3E0B]">bucket_technotes</code>)</span>
                </label>
                <p class="text-[10px] text-slate-500 ml-6">Automatically creates a full log in the client's history with reason, technician, and hardware specifications.</p>
            </div>

            <!-- 9. Additional Notes / Diagnostics Remarks -->
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-700">Remarks / Diagnostic Notes / Ticket Reference</label>
                <textarea name="notes" rows="2" placeholder="e.g., Client reported paper feed jam; bringing unit to office for roller gear replacement. Ticket # RNZ-2026-0012" class="w-full bg-slate-50 text-slate-800 text-xs p-3 rounded-2xl border border-slate-200 focus:border-amber-500 focus:bg-white focus:outline-none"></textarea>
            </div>

            <!-- 10. Level 2 Access Code Prompt (if needed) -->
            <?php 
                $my_tier = get_logged_tech_access_tier();
                if ($my_tier === 2): 
            ?>
                <div class="p-3.5 bg-amber-50 border border-amber-200 rounded-2xl space-y-1.5">
                    <label class="text-xs font-bold text-amber-900 flex items-center space-x-1.5">
                        <svg class="w-4 h-4 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        <span>Security Access Code Required (Level 2 Account)</span>
                    </label>
                    <input type="password" name="action_access_code" required placeholder="Enter your 4-digit security access code" class="w-full bg-white text-slate-800 text-xs px-3.5 py-2.5 rounded-xl border border-amber-300 focus:border-amber-500 focus:outline-none font-mono">
                </div>
            <?php endif; ?>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-end space-x-3">
                <button type="button" onclick="closePullOutModal()" class="px-5 py-2.5 rounded-2xl bg-slate-100 text-slate-600 font-bold text-xs hover:bg-slate-200 transition-colors">
                    Cancel
                </button>
                <button type="submit" class="px-6 py-2.5 rounded-2xl bg-gradient-to-r from-amber-500 to-amber-600 text-white font-bold text-xs hover:from-amber-600 hover:to-amber-700 shadow-md shadow-amber-500/30 transition-all">
                    Confirm & Record Pull-Out
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: ADD NEW ITEM -->
<!-- ========================================================================= -->
<div id="addItemModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 max-w-xl w-full p-6 sm:p-8 space-y-6 my-8 animate-in fade-in zoom-in duration-150">
        <div class="flex items-center justify-between border-b border-slate-100 pb-4">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-2xl bg-[#EB3E0B] text-white flex items-center justify-center font-bold">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                </div>
                <div>
                    <h3 class="font-extrabold text-lg text-slate-900">Add New Inventory Item</h3>
                    <p class="text-xs text-slate-500">Register new hardware, peripherals, or spare parts.</p>
                </div>
            </div>
            <button type="button" onclick="closeAddItemModal()" class="text-slate-400 hover:text-slate-600 p-2 rounded-full hover:bg-slate-100">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4">
            <input type="hidden" name="action" value="add_item">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <!-- Item Name -->
                <div class="sm:col-span-2 space-y-1">
                    <label class="text-xs font-bold text-slate-700">Item Name <span class="text-[#EB3E0B]">*</span></label>
                    <input type="text" name="name" required placeholder="e.g., Thermal Receipt Printer 80mm" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none">
                </div>

                <!-- Item SKU / Code -->
                <div class="sm:col-span-2 space-y-1">
                    <label class="text-xs font-bold text-slate-700">SKU / Item Code</label>
                    <input type="text" name="item_code" placeholder="e.g., HW-PRN-80 (Auto if blank)" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-mono uppercase">
                </div>

                <!-- Initial Quantity -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700">Initial Quantity</label>
                    <input type="number" name="quantity" min="0" value="10" required class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-mono">
                </div>

                <!-- Minimum Threshold -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700">Min Alert Threshold</label>
                    <input type="number" name="min_threshold" min="0" value="3" required class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-mono">
                </div>

                <!-- Description -->
                <div class="sm:col-span-2 space-y-1">
                    <label class="text-xs font-bold text-slate-700">Description / Specs</label>
                    <textarea name="description" rows="2" placeholder="Hardware specifications, model, interface ports..." class="w-full bg-slate-50 text-slate-800 text-xs p-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none"></textarea>
                </div>
            </div>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-end space-x-3">
                <button type="button" onclick="closeAddItemModal()" class="px-5 py-2.5 rounded-2xl bg-slate-100 text-slate-600 font-bold text-xs hover:bg-slate-200 transition-colors">
                    Cancel
                </button>
                <button type="submit" class="px-6 py-2.5 rounded-2xl bg-[#EB3E0B] text-white font-bold text-xs hover:bg-[#C32C0B] shadow-sm shadow-[#EB3E0B]/30 transition-all">
                    Save Hardware Item
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: ADJUST QUANTITY -->
<!-- ========================================================================= -->
<div id="adjustModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 max-w-lg w-full p-6 sm:p-8 space-y-6 animate-in fade-in zoom-in duration-150">
        <div class="flex items-center justify-between border-b border-slate-100 pb-4">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-2xl bg-blue-500 text-white flex items-center justify-center font-bold">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                </div>
                <div>
                    <h3 class="font-extrabold text-lg text-slate-900">Adjust Stock Quantity</h3>
                    <p class="text-xs text-slate-500" id="adj_item_name_display">Item Name</p>
                </div>
            </div>
            <button type="button" onclick="closeAdjustModal()" class="text-slate-400 hover:text-slate-600 p-2 rounded-full hover:bg-slate-100">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4">
            <input type="hidden" name="action" value="adjust_quantity">
            <input type="hidden" name="item_id" id="adj_item_id" value="0">

            <!-- Current Stock Display Box -->
            <div class="bg-slate-50 border border-slate-200 rounded-2xl p-4 flex items-center justify-between">
                <div>
                    <span class="text-xs text-slate-500 font-semibold">Current In Stock:</span>
                    <h4 class="text-2xl font-extrabold font-mono text-slate-900" id="adj_current_qty">0</h4>
                </div>
                <div class="text-right">
                    <span class="text-xs text-slate-500 font-semibold">SKU Code:</span>
                    <p class="text-xs font-mono font-bold text-[#EB3E0B]" id="adj_item_code">HW-000</p>
                </div>
            </div>

            <!-- Adjustment Type -->
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-700">Adjustment Type <span class="text-[#EB3E0B]">*</span></label>
                <div class="grid grid-cols-3 gap-2">
                    <label class="border border-slate-200 rounded-2xl p-3 flex items-center justify-center space-x-2 cursor-pointer has-[:checked]:bg-emerald-50 has-[:checked]:border-emerald-300 has-[:checked]:text-emerald-800 text-xs font-bold text-slate-700 transition-all">
                        <input type="radio" name="adjustment_type" value="add" checked class="text-emerald-600 focus:ring-0">
                        <span>+ Stock In</span>
                    </label>
                    <label class="border border-slate-200 rounded-2xl p-3 flex items-center justify-center space-x-2 cursor-pointer has-[:checked]:bg-rose-50 has-[:checked]:border-rose-300 has-[:checked]:text-rose-800 text-xs font-bold text-slate-700 transition-all">
                        <input type="radio" name="adjustment_type" value="subtract" class="text-rose-600 focus:ring-0">
                        <span>- Stock Out</span>
                    </label>
                    <label class="border border-slate-200 rounded-2xl p-3 flex items-center justify-center space-x-2 cursor-pointer has-[:checked]:bg-blue-50 has-[:checked]:border-blue-300 has-[:checked]:text-blue-800 text-xs font-bold text-slate-700 transition-all">
                        <input type="radio" name="adjustment_type" value="set" class="text-blue-600 focus:ring-0">
                        <span>= Set Exact</span>
                    </label>
                </div>
            </div>

            <!-- Quantity Amount -->
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-700">Quantity Amount <span class="text-[#EB3E0B]">*</span></label>
                <input type="number" name="amount" min="1" value="1" required class="w-full bg-slate-50 text-slate-800 text-base font-extrabold px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-mono">
            </div>

            <!-- Reason Selector -->
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-700">Reason / Movement Category</label>
                <select name="reason" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-medium">
                    <option value="Received Supplier Shipment">Received Supplier Shipment (+ In)</option>
                    <option value="Customer POS Deployment / Sale">Customer POS Deployment / Sale (- Out)</option>
                    <option value="Replacement for Warranty Ticket">Replacement for Warranty Ticket (- Out)</option>
                    <option value="Defective / RMA Return">Defective / Damaged RMA Return</option>
                    <option value="Physical Inventory Count Audit">Physical Inventory Count Audit</option>
                    <option value="Store Transfer">Store / Branch Transfer</option>
                    <option value="Other">Other Adjustment</option>
                </select>
            </div>

            <!-- Client Association (Optional) -->
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-700">Associated Client Account (Optional)</label>
                <select name="accountnum" onchange="updateClientName(this)" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none">
                    <option value="">None / General Stock</option>
                    <?php foreach ($clients_list as $c): ?>
                        <option value="<?php echo sanitize($c['accountnum']); ?>" data-tradename="<?php echo sanitize($c['tradename']); ?>">
                            <?php echo sanitize($c['tradename'] . ' (Acct: ' . $c['accountnum'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="client_name" id="adj_client_name" value="">
            </div>

            <!-- Additional Notes -->
            <div class="space-y-1">
                <label class="text-xs font-bold text-slate-700">Additional Notes / Ticket #</label>
                <input type="text" name="notes" placeholder="e.g., Ticket # RNZ-2026-00045, PO # 8841" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none">
            </div>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-end space-x-3">
                <button type="button" onclick="closeAdjustModal()" class="px-5 py-2.5 rounded-2xl bg-slate-100 text-slate-600 font-bold text-xs hover:bg-slate-200 transition-colors">
                    Cancel
                </button>
                <button type="submit" class="px-6 py-2.5 rounded-2xl bg-blue-600 text-white font-bold text-xs hover:bg-blue-700 shadow-sm shadow-blue-600/30 transition-all">
                    Apply Stock Adjustment
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: EDIT ITEM -->
<!-- ========================================================================= -->
<div id="editModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 max-w-xl w-full p-6 sm:p-8 space-y-6 my-8 animate-in fade-in zoom-in duration-150">
        <div class="flex items-center justify-between border-b border-slate-100 pb-4">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-2xl bg-slate-800 text-white flex items-center justify-center font-bold">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                </div>
                <div>
                    <h3 class="font-extrabold text-lg text-slate-900">Edit Hardware Item</h3>
                    <p class="text-xs text-slate-500">Update specifications, pricing, thresholds or status.</p>
                </div>
            </div>
            <button type="button" onclick="closeEditModal()" class="text-slate-400 hover:text-slate-600 p-2 rounded-full hover:bg-slate-100">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4">
            <input type="hidden" name="action" value="edit_item">
            <input type="hidden" name="item_id" id="edit_item_id" value="0">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <!-- Item Name -->
                <div class="sm:col-span-2 space-y-1">
                    <label class="text-xs font-bold text-slate-700">Item Name <span class="text-[#EB3E0B]">*</span></label>
                    <input type="text" name="name" id="edit_name" required class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none">
                </div>

                <!-- Item SKU / Code -->
                <div class="sm:col-span-2 space-y-1">
                    <label class="text-xs font-bold text-slate-700">SKU / Item Code</label>
                    <input type="text" name="item_code" id="edit_item_code" required class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-mono uppercase">
                </div>

                <!-- Minimum Threshold -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700">Min Alert Threshold</label>
                    <input type="number" name="min_threshold" id="edit_min_threshold" min="0" required class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-mono">
                </div>

                <!-- Status -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-700">Item Status</label>
                    <select name="status" id="edit_status" class="w-full bg-slate-50 text-slate-800 text-xs px-4 py-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-semibold">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                        <option value="Discontinued">Discontinued</option>
                    </select>
                </div>

                <!-- Description -->
                <div class="sm:col-span-2 space-y-1">
                    <label class="text-xs font-bold text-slate-700">Description / Specs</label>
                    <textarea name="description" id="edit_description" rows="2" class="w-full bg-slate-50 text-slate-800 text-xs p-3 rounded-2xl border border-slate-200 focus:border-[#EB3E0B] focus:bg-white focus:outline-none"></textarea>
                </div>
            </div>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-end space-x-3">
                <button type="button" onclick="closeEditModal()" class="px-5 py-2.5 rounded-2xl bg-slate-100 text-slate-600 font-bold text-xs hover:bg-slate-200 transition-colors">
                    Cancel
                </button>
                <button type="submit" class="px-6 py-2.5 rounded-2xl bg-slate-900 text-white font-bold text-xs hover:bg-slate-800 transition-all">
                    Update Details
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: STOCK MOVEMENT AUDIT LOG -->
<!-- ========================================================================= -->
<div id="movementLogModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 max-w-3xl w-full p-6 sm:p-8 space-y-6 my-8 animate-in fade-in zoom-in duration-150">
        <div class="flex items-center justify-between border-b border-slate-100 pb-4">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center font-bold">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div>
                    <h3 class="font-extrabold text-lg text-slate-900">Stock Movement Audit History</h3>
                    <p class="text-xs text-slate-500">Live ledger of all stock in, stock out, client allocations and adjustments.</p>
                </div>
            </div>
            <button type="button" onclick="closeMovementLogModal()" class="text-slate-400 hover:text-slate-600 p-2 rounded-full hover:bg-slate-100">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="overflow-x-auto max-h-96 pr-1">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-100 text-slate-500 font-bold uppercase tracking-wider text-[10px] sticky top-0">
                        <th class="py-2.5 px-4">Date / Time</th>
                        <th class="py-2.5 px-4">Hardware Item</th>
                        <th class="py-2.5 px-4 text-center">Change Type</th>
                        <th class="py-2.5 px-4 text-center">Qty Change</th>
                        <th class="py-2.5 px-4">Recorded By</th>
                        <th class="py-2.5 px-4">Client / Reason Notes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php if (empty($recent_logs)): ?>
                        <tr>
                            <td colspan="6" class="py-8 text-center text-slate-400">
                                No stock movements recorded yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recent_logs as $log): 
                            $chg = intval($log['quantity_change']);
                            if ($chg > 0) {
                                $chg_badge = 'bg-emerald-50 text-emerald-700 font-bold';
                                $chg_str = '+' . $chg;
                            } elseif ($chg < 0) {
                                $chg_badge = 'bg-rose-50 text-rose-700 font-bold';
                                $chg_str = strval($chg);
                            } else {
                                $chg_badge = 'bg-slate-100 text-slate-700 font-bold';
                                $chg_str = '0';
                            }
                        ?>
                            <tr class="hover:bg-slate-50/80">
                                <td class="py-3 px-4 text-slate-500 whitespace-nowrap">
                                    <?php echo format_date($log['created_at']); ?>
                                </td>
                                <td class="py-3 px-4 font-bold text-slate-900">
                                    <?php echo sanitize($log['item_name']); ?>
                                    <span class="block text-[10px] font-mono text-slate-400"><?php echo sanitize($log['item_code']); ?></span>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <?php 
                                        $type_str = sanitize($log['change_type']);
                                        if (strpos(strtolower($log['change_type']), 'pull out') !== false || strpos(strtolower($log['change_type']), 'pull-out') !== false) {
                                            $t_badge = 'bg-amber-100 text-amber-900 border border-amber-300 font-bold';
                                        } elseif ($chg > 0) {
                                            $t_badge = 'bg-emerald-100 text-emerald-800 border border-emerald-300 font-bold';
                                        } elseif ($chg < 0) {
                                            $t_badge = 'bg-rose-100 text-rose-800 border border-rose-300 font-bold';
                                        } else {
                                            $t_badge = 'bg-slate-100 text-slate-700 border border-slate-200 font-bold';
                                        }
                                    ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] <?php echo $t_badge; ?>">
                                        <?php echo $type_str; ?>
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-mono <?php echo $chg_badge; ?>">
                                        <?php echo $chg_str; ?>
                                    </span>
                                    <span class="block text-[9px] text-slate-400 font-mono">(<?php echo $log['previous_quantity']; ?> &rarr; <?php echo $log['new_quantity']; ?>)</span>
                                </td>
                                <td class="py-3 px-4 font-semibold text-slate-800">
                                    <?php echo sanitize($log['tech_name']); ?>
                                </td>
                                <td class="py-3 px-4 text-slate-600 max-w-xs truncate">
                                    <?php if (!empty($log['client_name'])): ?>
                                        <strong class="text-slate-900 block"><?php echo sanitize($log['client_name']); ?></strong>
                                    <?php endif; ?>
                                    <span class="text-xs"><?php echo sanitize($log['notes']); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="pt-4 border-t border-slate-100 flex items-center justify-end">
            <button type="button" onclick="closeMovementLogModal()" class="px-5 py-2.5 rounded-2xl bg-slate-900 text-white font-bold text-xs hover:bg-slate-800 transition-colors">
                Close
            </button>
        </div>
    </div>
</div>

<script>
// Pull Out Modal Handlers
function openPullOutModal(presetItemId, presetAcct) {
    if (presetItemId) {
        var selItem = document.getElementById('pullout_item_id');
        if (selItem) {
            selItem.value = presetItemId;
            updatePullOutItem(selItem);
        }
    }
    if (presetAcct) {
        var selClient = document.getElementById('pullout_accountnum');
        if (selClient) {
            selClient.value = presetAcct;
            updatePullOutClient(selClient);
        }
    }
    var m = document.getElementById('pullOutModal');
    if (m) {
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
}

function openPullOutModalForItem(item) {
    if (!item) return;
    openPullOutModal(item.id, null);
}

function closePullOutModal() {
    var m = document.getElementById('pullOutModal');
    if (m) {
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}

function togglePulloutDirection(val) {
    var restockBox = document.getElementById('restock_option_box');
    if (restockBox) {
        if (val === 'to_client') {
            restockBox.classList.add('hidden');
        } else {
            restockBox.classList.remove('hidden');
        }
    }
}

function updatePullOutItem(sel) {
    var opt = sel.options[sel.selectedIndex];
    var infoBox = document.getElementById('pullout_item_info');
    if (opt && opt.value) {
        var name = opt.getAttribute('data-name') || '';
        var qty = opt.getAttribute('data-qty') || '0';
        document.getElementById('pullout_item_name_text').textContent = name;
        document.getElementById('pullout_item_qty_text').textContent = qty;
        if (infoBox) infoBox.classList.remove('hidden');
    } else {
        if (infoBox) infoBox.classList.add('hidden');
    }
}

function updatePullOutClient(sel) {
    var opt = sel.options[sel.selectedIndex];
    var tradename = opt ? (opt.getAttribute('data-tradename') || '') : '';
    var address = opt ? (opt.getAttribute('data-address') || '') : '';
    document.getElementById('pullout_client_name').value = tradename;
    document.getElementById('pullout_client_address').value = address;
}

// Modal Handlers
function openAddItemModal() {
    var m = document.getElementById('addItemModal');
    if (m) {
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
}
function closeAddItemModal() {
    var m = document.getElementById('addItemModal');
    if (m) {
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}

function openAdjustModal(item) {
    if (!item) return;
    document.getElementById('adj_item_id').value = item.id;
    document.getElementById('adj_item_name_display').textContent = item.name;
    document.getElementById('adj_current_qty').textContent = item.quantity;
    document.getElementById('adj_item_code').textContent = item.item_code;
    
    var m = document.getElementById('adjustModal');
    if (m) {
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
}
function closeAdjustModal() {
    var m = document.getElementById('adjustModal');
    if (m) {
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}

function updateClientName(sel) {
    var opt = sel.options[sel.selectedIndex];
    var tradename = opt ? (opt.getAttribute('data-tradename') || '') : '';
    document.getElementById('adj_client_name').value = tradename;
}

function openEditModal(item) {
    if (!item) return;
    document.getElementById('edit_item_id').value = item.id;
    document.getElementById('edit_name').value = item.name || '';
    document.getElementById('edit_item_code').value = item.item_code || '';
    document.getElementById('edit_min_threshold').value = item.min_threshold || 5;
    document.getElementById('edit_status').value = item.status || 'Active';
    document.getElementById('edit_description').value = item.description || '';

    var m = document.getElementById('editModal');
    if (m) {
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
}
function closeEditModal() {
    var m = document.getElementById('editModal');
    if (m) {
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}

function openMovementLogModal() {
    var m = document.getElementById('movementLogModal');
    if (m) {
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
}
function closeMovementLogModal() {
    var m = document.getElementById('movementLogModal');
    if (m) {
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}

// Auto open modal if URL params present
window.addEventListener('DOMContentLoaded', function() {
    var presetClient = '<?php echo addslashes($preset_pullout_client); ?>';
    var presetItem = '<?php echo intval($preset_pullout_item); ?>';
    if (presetClient !== '' || parseInt(presetItem) > 0) {
        openPullOutModal(parseInt(presetItem) > 0 ? presetItem : null, presetClient !== '' ? presetClient : null);
    }
});

// Close modals on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closePullOutModal();
        closeAddItemModal();
        closeAdjustModal();
        closeEditModal();
        closeMovementLogModal();
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
