<?php
// Support Center - Client Hardware Orders Hub (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/inventory_init.php';

require_tech_login();
require_page_access('orders');

init_inventory_tables();
$pdo = get_db_connection();

$tech = get_logged_tech();
$tech_name = $tech ? $tech['fullname'] : 'Support Tech';
$my_tier = get_logged_tech_access_tier();

$flash_msg = '';
$flash_err = '';

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'status_updated') {
        $flash_msg = "Order status and fulfillment details updated successfully!";
    } elseif ($_GET['msg'] === 'order_created') {
        $flash_msg = "New hardware material order created successfully!";
    } elseif ($_GET['msg'] === 'order_deleted') {
        $flash_msg = "Hardware order record has been removed.";
    } elseif ($_GET['msg'] === 'error') {
        $flash_err = isset($_GET['err_msg']) ? sanitize($_GET['err_msg']) : "An unexpected error occurred.";
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';

    // Check Access Tier & Code for write actions
    if ($action === 'update_order_status' || $action === 'create_order' || $action === 'delete_order') {
        if ($my_tier === 1) {
            header("Location: orders.php?msg=error&err_msg=" . urlencode("Access Denied: Your account has Level 1 (View Only) permissions."));
            exit;
        }

        if ($my_tier === 2) {
            $input_code = isset($_POST['action_access_code']) ? trim($_POST['action_access_code']) : '';
            if (!verify_tech_access_code($input_code)) {
                header("Location: orders.php?msg=error&err_msg=" . urlencode("Invalid Security Access Code. Action aborted."));
                exit;
            }
        }
    }

    // 1. Update Order Status & Fulfillment
    if ($action === 'update_order_status') {
        $order_id      = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $new_status    = isset($_POST['status']) ? trim($_POST['status']) : 'Pending';
        $admin_remarks = isset($_POST['admin_remarks']) ? trim($_POST['admin_remarks']) : '';
        $deduct_stock  = isset($_POST['deduct_stock']) && $_POST['deduct_stock'] == '1' ? true : false;

        if ($order_id <= 0) {
            header("Location: orders.php?msg=error&err_msg=" . urlencode("Invalid order ID."));
            exit;
        }

        try {
            $stmt_cur = $pdo->prepare("SELECT * FROM client_hardware_orders WHERE id = :id LIMIT 1");
            $stmt_cur->execute(array(':id' => $order_id));
            $order_data = $stmt_cur->fetch();

            if (!$order_data) {
                header("Location: orders.php?msg=error&err_msg=" . urlencode("Order not found."));
                exit;
            }

            $now = date('Y-m-d H:i:s');

            // Optional stock deduction if enabled
            if ($deduct_stock && intval($order_data['item_id']) > 0) {
                $item_id = intval($order_data['item_id']);
                $qty_ordered = intval($order_data['quantity']);

                $stmt_item = $pdo->prepare("SELECT id, name, quantity FROM support_inventory_items WHERE id = :id LIMIT 1");
                $stmt_item->execute(array(':id' => $item_id));
                $inv_item = $stmt_item->fetch();

                if ($inv_item) {
                    $prev_qty = intval($inv_item['quantity']);
                    $new_qty = max(0, $prev_qty - $qty_ordered);

                    // Update inventory item quantity
                    $stmt_up_inv = $pdo->prepare("UPDATE support_inventory_items SET quantity = :nqty, updated_at = :now WHERE id = :id");
                    $stmt_up_inv->execute(array(':nqty' => $new_qty, ':now' => $now, ':id' => $item_id));

                    // Log inventory stock out
                    $stmt_log = $pdo->prepare("INSERT INTO support_inventory_logs 
                        (item_id, tech_name, change_type, quantity_change, previous_quantity, new_quantity, accountnum, client_name, notes, created_at) 
                        VALUES (:i_id, :tech, 'Order Fulfillment', :q_change, :prev, :new_q, :acct, :cname, :notes, :now)");
                    
                    $stmt_log->execute(array(
                        ':i_id'     => $item_id,
                        ':tech'     => $tech_name,
                        ':q_change' => -$qty_ordered,
                        ':prev'     => $prev_qty,
                        ':new_q'    => $new_qty,
                        ':acct'     => $order_data['accountnum'],
                        ':cname'    => $order_data['tradename'],
                        ':notes'    => "Fulfilled Order #" . $order_data['order_number'] . " (" . $order_data['item_name'] . ")",
                        ':now'      => $now
                    ));
                }
            }

            // Update order status
            $stmt_up = $pdo->prepare("UPDATE client_hardware_orders SET 
                status = :st, admin_remarks = :remarks, fulfilled_by = :f_by, updated_at = :now 
                WHERE id = :id");
            
            $stmt_up->execute(array(
                ':st'      => $new_status,
                ':remarks' => $admin_remarks,
                ':f_by'    => $tech_name,
                ':now'     => $now,
                ':id'      => $order_id
            ));

            header("Location: orders.php?msg=status_updated");
            exit;
        } catch (PDOException $e) {
            header("Location: orders.php?msg=error&err_msg=" . urlencode($e->getMessage()));
            exit;
        }
    }

    // 2. Create Order on behalf of Client
    elseif ($action === 'create_order') {
        $accountnum       = isset($_POST['accountnum']) ? trim($_POST['accountnum']) : '';
        $item_id          = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
        $quantity         = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
        $contact_person   = isset($_POST['contact_person']) ? trim($_POST['contact_person']) : '';
        $contact_number   = isset($_POST['contact_number']) ? trim($_POST['contact_number']) : '';
        $delivery_address = isset($_POST['delivery_address']) ? trim($_POST['delivery_address']) : '';
        $payment_method   = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : 'Charge to POS Account Billing';
        $order_status     = isset($_POST['status']) ? trim($_POST['status']) : 'Pending';
        $notes            = isset($_POST['notes']) ? trim($_POST['notes']) : '';
        $admin_remarks    = isset($_POST['admin_remarks']) ? trim($_POST['admin_remarks']) : '';

        if (empty($accountnum) || $item_id <= 0 || $quantity < 1) {
            header("Location: orders.php?msg=error&err_msg=" . urlencode("Client account, hardware item, and valid quantity are required."));
            exit;
        }

        try {
            // Fetch Client info
            $stmt_cl = $pdo->prepare("SELECT accountnum, tradename, clientname, address, contactnum FROM bucket_client WHERE accountnum = :acct LIMIT 1");
            $stmt_cl->execute(array(':acct' => $accountnum));
            $client_info = $stmt_cl->fetch();

            $tradename = $client_info ? (!empty($client_info['tradename']) ? $client_info['tradename'] : $client_info['clientname']) : 'Client Acct #' . $accountnum;
            $clientname = $client_info ? $client_info['clientname'] : $tradename;

            if (empty($contact_person)) {
                $contact_person = $tradename;
            }
            if (empty($delivery_address) && $client_info) {
                $delivery_address = $client_info['address'];
            }
            if (empty($contact_number) && $client_info) {
                $contact_number = $client_info['contactnum'];
            }

            // Fetch Item info and Pricing
            $stmt_it = $pdo->prepare("SELECT id, item_code, name, cost_price, selling_price, unit_price FROM support_inventory_items WHERE id = :id LIMIT 1");
            $stmt_it->execute(array(':id' => $item_id));
            $item_info = $stmt_it->fetch();

            if (!$item_info) {
                header("Location: orders.php?msg=error&err_msg=" . urlencode("Selected hardware item was not found."));
                exit;
            }

            $unit_price = 0.00;
            if (isset($item_info['selling_price']) && floatval($item_info['selling_price']) > 0) {
                $unit_price = floatval($item_info['selling_price']);
            } elseif (isset($item_info['unit_price']) && floatval($item_info['unit_price']) > 0) {
                $unit_price = floatval($item_info['unit_price']);
            } elseif (isset($item_info['cost_price']) && floatval($item_info['cost_price']) > 0) {
                $unit_price = floatval($item_info['cost_price']);
            }

            $total_amount = $unit_price * $quantity;
            $order_number = 'ORD-' . date('Y') . '-' . sprintf('%05d', rand(1, 99999));
            $now = date('Y-m-d H:i:s');

            $stmt_ins = $pdo->prepare("INSERT INTO client_hardware_orders 
                (order_number, accountnum, tradename, clientname, contact_person, contact_number, delivery_address, item_id, item_code, item_name, quantity, unit_price, total_amount, notes, status, payment_method, admin_remarks, fulfilled_by, created_at, updated_at) 
                VALUES (:ord_no, :acct, :trade, :cname, :c_person, :c_num, :address, :i_id, :i_code, :i_name, :qty, :u_price, :tot, :notes, :st, :pay_method, :remarks, :f_by, :now, :now)");

            $stmt_ins->execute(array(
                ':ord_no'     => $order_number,
                ':acct'       => $accountnum,
                ':trade'      => $tradename,
                ':cname'      => $clientname,
                ':c_person'   => $contact_person,
                ':c_num'      => $contact_number,
                ':address'    => $delivery_address,
                ':i_id'       => $item_info['id'],
                ':i_code'     => $item_info['item_code'],
                ':i_name'     => $item_info['name'],
                ':qty'        => $quantity,
                ':u_price'    => $unit_price,
                ':tot'        => $total_amount,
                ':notes'      => $notes,
                ':st'         => $order_status,
                ':pay_method' => $payment_method,
                ':remarks'    => $admin_remarks,
                ':f_by'       => $tech_name,
                ':now'        => $now
            ));

            header("Location: orders.php?msg=order_created");
            exit;
        } catch (PDOException $e) {
            header("Location: orders.php?msg=error&err_msg=" . urlencode($e->getMessage()));
            exit;
        }
    }

    // 3. Delete Order
    elseif ($action === 'delete_order') {
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        if ($order_id > 0) {
            try {
                $stmt_del = $pdo->prepare("DELETE FROM client_hardware_orders WHERE id = :id");
                $stmt_del->execute(array(':id' => $order_id));
                header("Location: orders.php?msg=order_deleted");
                exit;
            } catch (PDOException $e) {
                header("Location: orders.php?msg=error&err_msg=" . urlencode($e->getMessage()));
                exit;
            }
        }
    }
}

// Search and Filtering Parameters
$search       = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter= isset($_GET['status']) ? trim($_GET['status']) : '';
$sort_by      = isset($_GET['sort']) ? trim($_GET['sort']) : 'newest';

$where_clauses = array("1=1");
$params = array();

if (!empty($search)) {
    $where_clauses[] = "(order_number LIKE :s1 OR tradename LIKE :s2 OR accountnum LIKE :s3 OR item_name LIKE :s4 OR item_code LIKE :s5)";
    $params[':s1'] = "%" . $search . "%";
    $params[':s2'] = "%" . $search . "%";
    $params[':s3'] = "%" . $search . "%";
    $params[':s4'] = "%" . $search . "%";
    $params[':s5'] = "%" . $search . "%";
}

if (!empty($status_filter)) {
    $where_clauses[] = "status = :st_filter";
    $params[':st_filter'] = $status_filter;
}

$order_by = "id DESC";
if ($sort_by === 'oldest') {
    $order_by = "id ASC";
} elseif ($sort_by === 'amount_desc') {
    $order_by = "total_amount DESC";
} elseif ($sort_by === 'amount_asc') {
    $order_by = "total_amount ASC";
} elseif ($sort_by === 'client_asc') {
    $order_by = "tradename ASC";
}

$where_sql = implode(" AND ", $where_clauses);
$stmt_orders = $pdo->prepare("SELECT * FROM client_hardware_orders WHERE $where_sql ORDER BY $order_by");
$stmt_orders->execute($params);
$orders_list = $stmt_orders->fetchAll();

// KPI Stats
$total_orders_count     = intval($pdo->query("SELECT COUNT(*) FROM client_hardware_orders")->fetchColumn());
$pending_orders_count   = intval($pdo->query("SELECT COUNT(*) FROM client_hardware_orders WHERE status = 'Pending'")->fetchColumn());
$in_transit_count       = intval($pdo->query("SELECT COUNT(*) FROM client_hardware_orders WHERE status IN ('Approved', 'Processing', 'Out for Delivery')")->fetchColumn());
$completed_orders_count = intval($pdo->query("SELECT COUNT(*) FROM client_hardware_orders WHERE status = 'Completed'")->fetchColumn());
$total_order_revenue    = floatval($pdo->query("SELECT SUM(total_amount) FROM client_hardware_orders WHERE status != 'Cancelled'")->fetchColumn());

// Fetch active catalog items for admin order creation modal
$stmt_all_inv = $pdo->query("SELECT id, item_code, name, quantity, cost_price, selling_price, unit_price, status FROM support_inventory_items WHERE status = 'Active' ORDER BY name ASC");
$all_inventory_items = $stmt_all_inv ? $stmt_all_inv->fetchAll() : array();

// Fetch clients for dropdown
$stmt_clients = $pdo->query("SELECT accountnum, tradename, clientname, address, contactnum FROM bucket_client ORDER BY tradename ASC");
$all_clients = $stmt_clients ? $stmt_clients->fetchAll() : array();

$active_page = 'orders';
$page_title = 'Hardware Orders Hub';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo sanitize($page_title); ?> - Support Center</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#FFF5ED',
                            100: '#FFE8D5',
                            200: '#FECDAA',
                            300: '#FEAA73',
                            400: '#FC884D',
                            500: '#FA5915',
                            600: '#EB3E0B',
                            700: '#C32C0B',
                            800: '#9A2512',
                            900: '#7C2112',
                            950: '#430D07',
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
<body class="bg-slate-900 text-slate-100 min-h-screen flex">

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="flex-1 flex flex-col min-w-0 bg-slate-950">
        <?php include __DIR__ . '/includes/header.php'; ?>

        <main class="p-4 sm:p-6 lg:p-8 pb-24 md:pb-8 max-w-7xl mx-auto w-full space-y-6">

            <!-- Flash Notifications -->
            <?php if (!empty($flash_msg)): ?>
                <div class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-xs sm:text-sm font-bold flex items-center justify-between animate-in fade-in duration-200">
                    <div class="flex items-center space-x-2">
                        <svg class="w-5 h-5 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span><?php echo $flash_msg; ?></span>
                    </div>
                    <button type="button" onclick="this.parentElement.remove()" class="text-emerald-400 hover:text-emerald-200 font-extrabold text-lg leading-none">&times;</button>
                </div>
            <?php endif; ?>

            <?php if (!empty($flash_err)): ?>
                <div class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs sm:text-sm font-bold flex items-center justify-between animate-in fade-in duration-200">
                    <div class="flex items-center space-x-2">
                        <svg class="w-5 h-5 text-rose-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        <span><?php echo $flash_err; ?></span>
                    </div>
                    <button type="button" onclick="this.parentElement.remove()" class="text-rose-400 hover:text-rose-200 font-extrabold text-lg leading-none">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Page Header & Action Buttons -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="space-y-1">
                    <div class="flex items-center space-x-2.5">
                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-[#EB3E0B]/20 text-[#FEAA73] border border-[#EB3E0B]/30 uppercase tracking-wide">
                            Fulfillment & Logistics
                        </span>
                        <?php if ($pending_orders_count > 0): ?>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30 animate-pulse">
                                <?php echo $pending_orders_count; ?> Pending Approval
                            </span>
                        <?php endif; ?>
                    </div>
                    <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">Client Hardware Orders</h1>
                    <p class="text-xs sm:text-sm text-slate-400">Review client supply orders, dispatch materials, and track fulfillment status.</p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <?php if ($my_tier >= 2): ?>
                        <button type="button" onclick="openNewOrderModal()" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-extrabold text-xs sm:text-sm px-5 py-3 rounded-2xl shadow-sm shadow-[#EB3E0B]/30 flex items-center space-x-2 transition-all active:scale-95">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                            <span>Create Client Order</span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- KPI Metric Cards Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-5">
                <!-- Total Orders -->
                <div class="bg-slate-900/90 rounded-3xl p-5 sm:p-6 border border-slate-800 shadow-sm flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Orders</span>
                        <h3 class="text-3xl font-extrabold text-white font-mono"><?php echo number_format($total_orders_count); ?></h3>
                        <p class="text-[11px] text-slate-500">All registered material requests</p>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-blue-500/10 text-blue-400 border border-blue-500/20 flex items-center justify-center font-bold">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                    </div>
                </div>

                <!-- Pending Orders -->
                <a href="orders.php?status=Pending" class="bg-slate-900/90 hover:bg-slate-850 rounded-3xl p-5 sm:p-6 border border-slate-800 hover:border-amber-500/50 shadow-sm flex items-center justify-between transition-all group">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-amber-400 uppercase tracking-wider">Pending Orders</span>
                        <h3 class="text-3xl font-extrabold text-amber-400 font-mono"><?php echo number_format($pending_orders_count); ?></h3>
                        <p class="text-[11px] text-slate-500 group-hover:text-amber-300/80">Requires technical review</p>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-amber-500/10 text-amber-400 border border-amber-500/20 flex items-center justify-center font-bold">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                </a>

                <!-- In Fulfillment / Transit -->
                <a href="orders.php?status=Processing" class="bg-slate-900/90 hover:bg-slate-850 rounded-3xl p-5 sm:p-6 border border-slate-800 hover:border-indigo-500/50 shadow-sm flex items-center justify-between transition-all group">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-indigo-400 uppercase tracking-wider">In Transit / Dispatch</span>
                        <h3 class="text-3xl font-extrabold text-indigo-400 font-mono"><?php echo number_format($in_transit_count); ?></h3>
                        <p class="text-[11px] text-slate-500 group-hover:text-indigo-300/80">Approved or out for delivery</p>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 flex items-center justify-center font-bold">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h2m-8 0a2 2 0 100-4 2 2 0 000 4zm10 0a2 2 0 100-4 2 2 0 000 4z"/></svg>
                    </div>
                </a>

                <!-- Total Revenue / Valuation -->
                <div class="bg-slate-900/90 rounded-3xl p-5 sm:p-6 border border-slate-800 shadow-sm flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-emerald-400 uppercase tracking-wider">Total Orders Value</span>
                        <h3 class="text-2xl sm:text-3xl font-extrabold text-emerald-400 font-mono truncate">₱<?php echo number_format($total_order_revenue, 2); ?></h3>
                        <p class="text-[11px] text-slate-500">Gross materials requested</p>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 flex items-center justify-center font-bold">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                </div>
            </div>

            <!-- Filters & Search Bar -->
            <div class="bg-slate-900/90 rounded-3xl border border-slate-800 shadow-sm p-5 sm:p-6 space-y-4">
                <form method="GET" action="" class="grid grid-cols-1 sm:grid-cols-12 gap-3">
                    <!-- Search Input -->
                    <div class="sm:col-span-5 relative">
                        <input type="text" name="search" value="<?php echo sanitize($search); ?>" placeholder="Search by Order #, Client Name, Acct #, or Material SKU..." class="w-full bg-slate-950 text-slate-100 text-xs pl-10 pr-4 py-3 rounded-2xl border border-slate-800 focus:border-[#EB3E0B] focus:bg-slate-900 focus:outline-none transition-all placeholder-slate-500">
                        <svg class="w-4 h-4 text-slate-500 absolute left-3.5 top-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </div>

                    <!-- Status Filter -->
                    <div class="sm:col-span-3">
                        <select name="status" class="w-full bg-slate-950 text-slate-200 text-xs px-4 py-3 rounded-2xl border border-slate-800 focus:border-[#EB3E0B] focus:bg-slate-900 focus:outline-none transition-all font-semibold">
                            <option value="" <?php echo empty($status_filter) ? 'selected' : ''; ?>>All Order Statuses</option>
                            <option value="Pending" <?php echo ($status_filter === 'Pending') ? 'selected' : ''; ?>>Pending (<?php echo $pending_orders_count; ?>)</option>
                            <option value="Approved" <?php echo ($status_filter === 'Approved') ? 'selected' : ''; ?>>Approved</option>
                            <option value="Processing" <?php echo ($status_filter === 'Processing') ? 'selected' : ''; ?>>Processing</option>
                            <option value="Out for Delivery" <?php echo ($status_filter === 'Out for Delivery') ? 'selected' : ''; ?>>Out for Delivery</option>
                            <option value="Completed" <?php echo ($status_filter === 'Completed') ? 'selected' : ''; ?>>Completed (Fulfilled)</option>
                            <option value="Cancelled" <?php echo ($status_filter === 'Cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>

                    <!-- Sort Filter -->
                    <div class="sm:col-span-3">
                        <select name="sort" class="w-full bg-slate-950 text-slate-200 text-xs px-4 py-3 rounded-2xl border border-slate-800 focus:border-[#EB3E0B] focus:bg-slate-900 focus:outline-none transition-all font-semibold">
                            <option value="newest" <?php if ($sort_by === 'newest') echo 'selected'; ?>>Newest Orders First</option>
                            <option value="oldest" <?php if ($sort_by === 'oldest') echo 'selected'; ?>>Oldest Orders First</option>
                            <option value="amount_desc" <?php if ($sort_by === 'amount_desc') echo 'selected'; ?>>Highest Amount</option>
                            <option value="amount_asc" <?php if ($sort_by === 'amount_asc') echo 'selected'; ?>>Lowest Amount</option>
                            <option value="client_asc" <?php if ($sort_by === 'client_asc') echo 'selected'; ?>>Client Name (A - Z)</option>
                        </select>
                    </div>

                    <!-- Submit & Reset -->
                    <div class="sm:col-span-1 flex items-center gap-1.5">
                        <button type="submit" class="w-full bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs py-3 px-3 rounded-2xl transition-all shadow-sm">
                            Go
                        </button>
                        <?php if (!empty($search) || !empty($status_filter) || $sort_by !== 'newest'): ?>
                            <a href="orders.php" class="p-3 bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white rounded-2xl text-xs font-bold transition-all flex items-center justify-center shrink-0" title="Reset Filters">
                                &times;
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Orders Table List -->
            <div class="bg-slate-900/90 rounded-3xl border border-slate-800 shadow-sm overflow-hidden space-y-4">
                <div class="p-6 border-b border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                        <h3 class="text-lg font-extrabold text-white">Registered Orders List</h3>
                        <p class="text-xs text-slate-400 mt-0.5">Manage customer deliveries, fulfillment approval, and inventory sync.</p>
                    </div>
                    <div class="text-xs text-slate-400 font-medium">
                        Showing <strong class="text-white font-mono"><?php echo count($orders_list); ?></strong> order(s)
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="bg-slate-950/60 border-b border-slate-800 text-slate-400 font-bold uppercase tracking-wider text-[11px]">
                                <th class="py-3.5 px-6">Order # & Date</th>
                                <th class="py-3.5 px-6">Client Account</th>
                                <th class="py-3.5 px-6">Hardware Item</th>
                                <th class="py-3.5 px-6 text-center">Qty</th>
                                <th class="py-3.5 px-6">Pricing</th>
                                <th class="py-3.5 px-6">Delivery Info</th>
                                <th class="py-3.5 px-6 text-center">Status</th>
                                <th class="py-3.5 px-6 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/80 font-medium">
                            <?php if (empty($orders_list)): ?>
                                <tr>
                                    <td colspan="8" class="py-12 text-center text-slate-500 space-y-3">
                                        <div class="w-12 h-12 rounded-full bg-slate-800 text-slate-400 flex items-center justify-center mx-auto">
                                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                                        </div>
                                        <p class="font-bold text-slate-300">No hardware orders found.</p>
                                        <p class="text-xs">Try clearing search filters or click "Create Client Order" to create a new order.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($orders_list as $ord): 
                                    $status = $ord['status'];
                                    if ($status === 'Pending') {
                                        $badge_cls = 'bg-amber-500/15 text-amber-300 border-amber-500/30';
                                    } elseif ($status === 'Approved' || $status === 'Processing') {
                                        $badge_cls = 'bg-blue-500/15 text-blue-300 border-blue-500/30';
                                    } elseif ($status === 'Out for Delivery') {
                                        $badge_cls = 'bg-purple-500/15 text-purple-300 border-purple-500/30';
                                    } elseif ($status === 'Completed') {
                                        $badge_cls = 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30';
                                    } else {
                                        $badge_cls = 'bg-rose-500/15 text-rose-300 border-rose-500/30';
                                    }
                                ?>
                                    <tr class="hover:bg-slate-850/60 transition-colors">
                                        <!-- Order # & Date -->
                                        <td class="py-4 px-6">
                                            <span class="font-mono font-extrabold text-[#FEAA73] text-xs block">
                                                <?php echo sanitize($ord['order_number']); ?>
                                            </span>
                                            <span class="text-[11px] text-slate-400 block mt-0.5">
                                                <?php echo format_date($ord['created_at']); ?>
                                            </span>
                                        </td>

                                        <!-- Client Account -->
                                        <td class="py-4 px-6">
                                            <div class="font-extrabold text-white text-xs">
                                                <?php echo sanitize($ord['tradename']); ?>
                                            </div>
                                            <div class="flex items-center space-x-2 mt-0.5">
                                                <a href="accounts.php?accountnum=<?php echo urlencode($ord['accountnum']); ?>" class="font-mono text-[10px] text-[#EB3E0B] hover:underline font-bold">
                                                    Acct # <?php echo sanitize($ord['accountnum']); ?>
                                                </a>
                                            </div>
                                        </td>

                                        <!-- Hardware Item -->
                                        <td class="py-4 px-6">
                                            <div class="font-bold text-slate-200 text-xs">
                                                <?php echo sanitize($ord['item_name']); ?>
                                            </div>
                                            <span class="font-mono text-[10px] text-slate-400 font-semibold block">
                                                SKU: <?php echo sanitize($ord['item_code']); ?>
                                            </span>
                                            <?php if (!empty($ord['notes'])): ?>
                                                <p class="text-[11px] text-slate-400 italic mt-1 bg-slate-950 p-2 rounded-xl border border-slate-800 max-w-xs">
                                                    "<?php echo sanitize($ord['notes']); ?>"
                                                </p>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Quantity -->
                                        <td class="py-4 px-6 text-center">
                                            <span class="font-mono font-extrabold text-sm text-white bg-slate-800 px-2.5 py-1 rounded-xl">
                                                <?php echo number_format($ord['quantity']); ?>
                                            </span>
                                        </td>

                                        <!-- Pricing -->
                                        <td class="py-4 px-6 min-w-[140px]">
                                            <div class="space-y-0.5">
                                                <div class="text-xs font-extrabold text-emerald-400 font-mono">
                                                    ₱<?php echo number_format($ord['total_amount'], 2); ?>
                                                </div>
                                                <span class="text-[10px] text-slate-400 block font-mono">
                                                    @ ₱<?php echo number_format($ord['unit_price'], 2); ?> / unit
                                                </span>
                                                <span class="text-[9px] text-slate-500 block">
                                                    <?php echo sanitize($ord['payment_method']); ?>
                                                </span>
                                            </div>
                                        </td>

                                        <!-- Delivery Info -->
                                        <td class="py-4 px-6 text-xs text-slate-300 max-w-xs">
                                            <p class="font-bold text-white"><?php echo sanitize($ord['contact_person']); ?> (<?php echo sanitize($ord['contact_number']); ?>)</p>
                                            <p class="truncate text-[11px] text-slate-400 mt-0.5" title="<?php echo sanitize($ord['delivery_address']); ?>">
                                                <?php echo sanitize($ord['delivery_address']); ?>
                                            </p>
                                        </td>

                                        <!-- Status -->
                                        <td class="py-4 px-6 text-center">
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-[11px] font-extrabold border <?php echo $badge_cls; ?>">
                                                <?php echo sanitize($ord['status']); ?>
                                            </span>
                                            <?php if (!empty($ord['admin_remarks'])): ?>
                                                <span class="block text-[10px] text-slate-400 italic mt-1 max-w-[140px] mx-auto truncate" title="<?php echo sanitize($ord['admin_remarks']); ?>">
                                                    Note: <?php echo sanitize($ord['admin_remarks']); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($ord['fulfilled_by'])): ?>
                                                <span class="block text-[9px] text-slate-500 mt-0.5">
                                                    By: <?php echo sanitize($ord['fulfilled_by']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Actions -->
                                        <td class="py-4 px-6 text-right">
                                            <?php if ($my_tier >= 2): ?>
                                                <div class="flex items-center justify-end space-x-1.5">
                                                    <button type="button" onclick='openStatusModal(<?php echo htmlspecialchars(json_encode($ord), ENT_QUOTES, 'UTF-8'); ?>)' class="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-[#EB3E0B] text-slate-200 hover:text-white font-bold text-[11px] transition-colors shadow-sm" title="Update Status & Fulfillment">
                                                        Update
                                                    </button>

                                                    <form method="POST" action="" onsubmit="return confirm('Are you sure you want to delete order <?php echo addslashes($ord['order_number']); ?>?');" class="inline">
                                                        <input type="hidden" name="action" value="delete_order">
                                                        <input type="hidden" name="order_id" value="<?php echo $ord['id']; ?>">
                                                        <button type="submit" class="w-8 h-8 rounded-xl bg-slate-800 hover:bg-rose-500/20 text-slate-400 hover:text-rose-400 flex items-center justify-center transition-colors" title="Delete Order">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                        </button>
                                                    </form>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-xs font-semibold text-slate-500">🔒 View Only</span>
                                            <?php endif; ?>
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

    <!-- ========================================================================= -->
    <!-- MODAL: UPDATE ORDER STATUS & DISPATCH -->
    <!-- ========================================================================= -->
    <div id="statusModal" class="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-sm hidden items-center justify-center p-4 overflow-y-auto">
        <div class="bg-slate-900 rounded-3xl shadow-2xl border border-slate-800 max-w-xl w-full p-6 sm:p-8 space-y-6 my-8 animate-in fade-in zoom-in duration-150 text-slate-100">
            <div class="flex items-center justify-between border-b border-slate-800 pb-4">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-2xl bg-[#EB3E0B] text-white flex items-center justify-center font-bold shadow-md shadow-[#EB3E0B]/30">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    </div>
                    <div>
                        <h3 class="font-extrabold text-lg text-white">Update Order Fulfillment</h3>
                        <p class="text-xs text-slate-400" id="modal_order_num_display">Order Number</p>
                    </div>
                </div>
                <button type="button" onclick="closeStatusModal()" class="text-slate-400 hover:text-white p-2 rounded-full hover:bg-slate-800">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <form method="POST" action="" class="space-y-4">
                <input type="hidden" name="action" value="update_order_status">
                <input type="hidden" name="order_id" id="modal_order_id" value="0">

                <!-- Order Info Preview Box -->
                <div class="p-4 bg-slate-950 rounded-2xl border border-slate-800 space-y-2 text-xs">
                    <div class="flex justify-between items-center">
                        <span class="text-slate-400">Client:</span>
                        <strong id="modal_client_display" class="text-white font-sans"></strong>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-slate-400">Item & Qty:</span>
                        <strong id="modal_item_display" class="text-[#FEAA73] font-mono"></strong>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-slate-400">Total Amount:</span>
                        <strong id="modal_amount_display" class="text-emerald-400 font-mono text-sm"></strong>
                    </div>
                </div>

                <!-- Status Selector -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-300">Order Status <span class="text-[#EB3E0B]">*</span></label>
                    <select name="status" id="modal_status_select" required class="w-full bg-slate-950 text-slate-100 text-xs px-4 py-3 rounded-2xl border border-slate-800 focus:border-[#EB3E0B] focus:outline-none font-bold">
                        <option value="Pending">Pending (Awaiting Technical Review)</option>
                        <option value="Approved">Approved (Preparing Material)</option>
                        <option value="Processing">Processing / Bench Testing</option>
                        <option value="Out for Delivery">Out for Delivery (Courier / Tech Handover)</option>
                        <option value="Completed">Completed (Fulfilled & Delivered)</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>

                <!-- Dispatch / Fulfillment Remarks -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-300">Dispatch / Technician Notes</label>
                    <textarea name="admin_remarks" id="modal_remarks_input" rows="2" placeholder="e.g. Dispatched via Tech John on service van; or Waybill # LBC-984210" class="w-full bg-slate-950 text-slate-100 text-xs p-3 rounded-2xl border border-slate-800 focus:border-[#EB3E0B] focus:outline-none"></textarea>
                </div>

                <!-- Auto Deduct Stock Checkbox -->
                <div class="p-3 bg-slate-950 border border-slate-800 rounded-2xl space-y-1">
                    <label class="flex items-center space-x-2.5 cursor-pointer text-xs font-bold text-slate-200">
                        <input type="checkbox" name="deduct_stock" value="1" class="rounded text-[#EB3E0B] focus:ring-0">
                        <span>Deduct ordered quantity directly from Hardware Inventory (-Qty)</span>
                    </label>
                    <p class="text-[10px] text-slate-400 ml-6">Automatically creates a movement log in inventory hub tagged to this client account.</p>
                </div>

                <!-- Level 2 Access Code -->
                <?php if ($my_tier === 2): ?>
                    <div class="p-3.5 bg-amber-500/10 border border-amber-500/30 rounded-2xl space-y-1.5">
                        <label class="text-xs font-bold text-amber-300 flex items-center space-x-1.5">
                            <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                            <span>Security Access Code Required (Level 2 Account)</span>
                        </label>
                        <input type="password" name="action_access_code" required placeholder="Enter 4-digit security code" class="w-full bg-slate-950 text-slate-100 text-xs px-3.5 py-2.5 rounded-xl border border-slate-800 focus:border-amber-500 focus:outline-none font-mono">
                    </div>
                <?php endif; ?>

                <div class="pt-4 border-t border-slate-800 flex items-center justify-end space-x-3">
                    <button type="button" onclick="closeStatusModal()" class="px-5 py-2.5 rounded-2xl bg-slate-800 text-slate-300 font-bold text-xs hover:bg-slate-700 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="px-6 py-2.5 rounded-2xl bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-extrabold text-xs shadow-md shadow-[#EB3E0B]/30 transition-all active:scale-95">
                        Save Order Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL: CREATE ORDER ON BEHALF OF CLIENT -->
    <!-- ========================================================================= -->
    <div id="newOrderModal" class="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-sm hidden items-center justify-center p-4 overflow-y-auto">
        <div class="bg-slate-900 rounded-3xl shadow-2xl border border-slate-800 max-w-xl w-full p-6 sm:p-8 space-y-6 my-8 animate-in fade-in zoom-in duration-150 text-slate-100">
            <div class="flex items-center justify-between border-b border-slate-800 pb-4">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-2xl bg-[#EB3E0B] text-white flex items-center justify-center font-bold shadow-md shadow-[#EB3E0B]/30">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    </div>
                    <div>
                        <h3 class="font-extrabold text-lg text-white">Create Client Hardware Order</h3>
                        <p class="text-xs text-slate-400">Order materials on behalf of a store client.</p>
                    </div>
                </div>
                <button type="button" onclick="closeNewOrderModal()" class="text-slate-400 hover:text-white p-2 rounded-full hover:bg-slate-800">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <form method="POST" action="" class="space-y-4">
                <input type="hidden" name="action" value="create_order">

                <!-- 1. Client Selection -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-300">Client Account <span class="text-[#EB3E0B]">*</span></label>
                    <select name="accountnum" required onchange="handleAdminClientSelect(this)" class="w-full bg-slate-950 text-slate-100 text-xs px-4 py-3 rounded-2xl border border-slate-800 focus:border-[#EB3E0B] focus:outline-none font-bold">
                        <option value="">-- Choose Client --</option>
                        <?php foreach ($all_clients as $cl): 
                            $disp = !empty($cl['tradename']) ? $cl['tradename'] : $cl['clientname'];
                        ?>
                            <option value="<?php echo sanitize($cl['accountnum']); ?>"
                                    data-person="<?php echo sanitize($disp); ?>"
                                    data-phone="<?php echo sanitize(isset($cl['contactnum']) ? $cl['contactnum'] : ''); ?>"
                                    data-address="<?php echo sanitize(isset($cl['address']) ? $cl['address'] : ''); ?>">
                                <?php echo sanitize($disp . ' (' . $cl['accountnum'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 2. Hardware Item -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-300">Hardware Material <span class="text-[#EB3E0B]">*</span></label>
                    <select name="item_id" id="admin_order_item_id" required onchange="calculateAdminOrderTotal()" class="w-full bg-slate-950 text-slate-100 text-xs px-4 py-3 rounded-2xl border border-slate-800 focus:border-[#EB3E0B] focus:outline-none font-bold">
                        <option value="">-- Choose Material --</option>
                        <?php foreach ($all_inventory_items as $ai): 
                            $price = 0.00;
                            if (isset($ai['selling_price']) && floatval($ai['selling_price']) > 0) {
                                $price = floatval($ai['selling_price']);
                            } elseif (isset($ai['unit_price']) && floatval($ai['unit_price']) > 0) {
                                $price = floatval($ai['unit_price']);
                            } elseif (isset($ai['cost_price']) && floatval($ai['cost_price']) > 0) {
                                $price = floatval($ai['cost_price']);
                            }
                        ?>
                            <option value="<?php echo $ai['id']; ?>" data-price="<?php echo $price; ?>">
                                <?php echo sanitize($ai['name'] . ' (' . $ai['item_code'] . ') — ₱' . number_format($price, 2) . ' [' . $ai['quantity'] . ' in stock]'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 3. Quantity & Pricing preview -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 items-center">
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-300">Quantity <span class="text-[#EB3E0B]">*</span></label>
                        <input type="number" name="quantity" id="admin_order_quantity" min="1" value="1" required oninput="calculateAdminOrderTotal()" class="w-full bg-slate-950 text-slate-100 text-sm font-extrabold px-4 py-2.5 rounded-2xl border border-slate-800 focus:border-[#EB3E0B] focus:outline-none font-mono">
                    </div>
                    <div class="p-3 bg-slate-950 border border-slate-800 rounded-2xl flex flex-col justify-center">
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Estimated Total</span>
                        <span id="admin_total_display" class="text-xl font-extrabold font-mono text-emerald-400">₱0.00</span>
                    </div>
                </div>

                <!-- 4. Contact Details -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-300">Contact Person</label>
                        <input type="text" name="contact_person" id="admin_contact_person" class="w-full bg-slate-950 text-slate-100 text-xs px-4 py-2.5 rounded-2xl border border-slate-800 focus:border-[#EB3E0B] focus:outline-none">
                    </div>
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-300">Contact Phone #</label>
                        <input type="text" name="contact_number" id="admin_contact_number" class="w-full bg-slate-950 text-slate-100 text-xs px-4 py-2.5 rounded-2xl border border-slate-800 focus:border-[#EB3E0B] focus:outline-none font-mono">
                    </div>
                </div>

                <!-- 5. Delivery Address -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-300">Delivery Address</label>
                    <textarea name="delivery_address" id="admin_delivery_address" rows="2" class="w-full bg-slate-950 text-slate-100 text-xs p-3 rounded-2xl border border-slate-800 focus:border-[#EB3E0B] focus:outline-none"></textarea>
                </div>

                <!-- 6. Initial Status & Payment Method -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-300">Initial Status</label>
                        <select name="status" class="w-full bg-slate-950 text-slate-100 text-xs px-4 py-2.5 rounded-2xl border border-slate-800 focus:border-[#EB3E0B] focus:outline-none font-medium">
                            <option value="Approved">Approved</option>
                            <option value="Pending">Pending</option>
                            <option value="Processing">Processing</option>
                            <option value="Out for Delivery">Out for Delivery</option>
                            <option value="Completed">Completed</option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-300">Payment Terms</label>
                        <select name="payment_method" class="w-full bg-slate-950 text-slate-100 text-xs px-4 py-2.5 rounded-2xl border border-slate-800 focus:border-[#EB3E0B] focus:outline-none font-medium">
                            <option value="Charge to POS Account Billing">Charge to Monthly Account Billing</option>
                            <option value="Cash on Delivery (COD) / Tech Handover">Cash on Delivery (COD)</option>
                            <option value="Bank Transfer / GCash Payment">Bank Transfer / Online GCash</option>
                        </select>
                    </div>
                </div>

                <!-- 7. Notes -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-300">Dispatch / Admin Notes</label>
                    <input type="text" name="admin_remarks" placeholder="e.g. Prepared for tomorrow morning route" class="w-full bg-slate-950 text-slate-100 text-xs px-4 py-2.5 rounded-2xl border border-slate-800 focus:border-[#EB3E0B] focus:outline-none">
                </div>

                <!-- Level 2 Access Code -->
                <?php if ($my_tier === 2): ?>
                    <div class="p-3.5 bg-amber-500/10 border border-amber-500/30 rounded-2xl space-y-1.5">
                        <label class="text-xs font-bold text-amber-300 flex items-center space-x-1.5">
                            <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                            <span>Security Access Code Required (Level 2 Account)</span>
                        </label>
                        <input type="password" name="action_access_code" required placeholder="Enter 4-digit security code" class="w-full bg-slate-950 text-slate-100 text-xs px-3.5 py-2.5 rounded-xl border border-slate-800 focus:border-amber-500 focus:outline-none font-mono">
                    </div>
                <?php endif; ?>

                <div class="pt-4 border-t border-slate-800 flex items-center justify-end space-x-3">
                    <button type="button" onclick="closeNewOrderModal()" class="px-5 py-2.5 rounded-2xl bg-slate-800 text-slate-300 font-bold text-xs hover:bg-slate-700 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="px-6 py-2.5 rounded-2xl bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-extrabold text-xs shadow-md shadow-[#EB3E0B]/30 transition-all active:scale-95">
                        Create Order
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php include __DIR__ . '/includes/footer.php'; ?>

    <script>
    function openStatusModal(order) {
        if (!order) return;
        document.getElementById('modal_order_id').value = order.id;
        document.getElementById('modal_order_num_display').textContent = order.order_number + ' • ' + order.created_at;
        document.getElementById('modal_client_display').textContent = order.tradename + ' (Acct: ' + order.accountnum + ')';
        document.getElementById('modal_item_display').textContent = order.item_name + ' × ' + order.quantity;
        document.getElementById('modal_amount_display').textContent = '₱' + parseFloat(order.total_amount || 0).toLocaleString('en-US', {minimumFractionDigits: 2});
        document.getElementById('modal_status_select').value = order.status || 'Pending';
        document.getElementById('modal_remarks_input').value = order.admin_remarks || '';

        var m = document.getElementById('statusModal');
        if (m) {
            m.classList.remove('hidden');
            m.classList.add('flex');
        }
    }

    function closeStatusModal() {
        var m = document.getElementById('statusModal');
        if (m) {
            m.classList.add('hidden');
            m.classList.remove('flex');
        }
    }

    function openNewOrderModal() {
        var m = document.getElementById('newOrderModal');
        if (m) {
            m.classList.remove('hidden');
            m.classList.add('flex');
            calculateAdminOrderTotal();
        }
    }

    function closeNewOrderModal() {
        var m = document.getElementById('newOrderModal');
        if (m) {
            m.classList.add('hidden');
            m.classList.remove('flex');
        }
    }

    function handleAdminClientSelect(sel) {
        var opt = sel.options[sel.selectedIndex];
        if (opt && opt.value) {
            document.getElementById('admin_contact_person').value = opt.getAttribute('data-person') || '';
            document.getElementById('admin_contact_number').value = opt.getAttribute('data-phone') || '';
            document.getElementById('admin_delivery_address').value = opt.getAttribute('data-address') || '';
        }
    }

    function calculateAdminOrderTotal() {
        var sel = document.getElementById('admin_order_item_id');
        var qtyInput = document.getElementById('admin_order_quantity');
        var display = document.getElementById('admin_total_display');

        var opt = sel ? sel.options[sel.selectedIndex] : null;
        var unitPrice = (opt && opt.value) ? parseFloat(opt.getAttribute('data-price') || 0) : 0;
        var qty = qtyInput ? parseInt(qtyInput.value) || 1 : 1;
        if (qty < 1) qty = 1;

        var total = unitPrice * qty;
        if (display) {
            display.textContent = '₱' + total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeStatusModal();
            closeNewOrderModal();
        }
    });
    </script>
</body>
</html>
