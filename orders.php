<?php
// Client Portal - Order Hardware Materials & Supplies (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db_init.php';

require_login();
$client = get_logged_client();
$accountnum = $client['accountnum'];

$pdo = get_db_connection();

$error_msg = '';
$success_msg = '';

if (isset($_GET['placed']) && $_GET['placed'] === '1') {
    $ord_no = isset($_GET['order_no']) ? sanitize($_GET['order_no']) : '';
    $success_msg = "Your hardware material order" . (!empty($ord_no) ? " (<strong>" . $ord_no . "</strong>)" : "") . " has been submitted successfully! Our technical team will review and process your delivery.";
}

// Handle Order Placement POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';

    if ($action === 'place_hardware_order') {
        $item_id          = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
        $quantity         = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
        $contact_person   = isset($_POST['contact_person']) ? trim($_POST['contact_person']) : '';
        $contact_number   = isset($_POST['contact_number']) ? trim($_POST['contact_number']) : '';
        $delivery_address = isset($_POST['delivery_address']) ? trim($_POST['delivery_address']) : '';
        $payment_method   = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : 'Charge to POS Account Billing';
        $notes            = isset($_POST['notes']) ? trim($_POST['notes']) : '';

        if ($quantity < 1) {
            $quantity = 1;
        }

        if ($item_id <= 0 || empty($contact_person) || empty($contact_number) || empty($delivery_address)) {
            $error_msg = "Please select a hardware item and provide all required delivery details.";
        } else {
            try {
                // Fetch Item info and Pricing from support_inventory_items
                $stmt_item = $pdo->prepare("SELECT id, item_code, name, cost_price, selling_price, unit_price, quantity, status FROM support_inventory_items WHERE id = :id LIMIT 1");
                $stmt_item->execute(array(':id' => $item_id));
                $item_data = $stmt_item->fetch();

                if (!$item_data) {
                    $error_msg = "The selected hardware item was not found in the inventory catalog.";
                } else {
                    // Determine unit price (Selling price set in inventory, fallback to unit_price or cost_price)
                    $unit_price = 0.00;
                    if (isset($item_data['selling_price']) && floatval($item_data['selling_price']) > 0) {
                        $unit_price = floatval($item_data['selling_price']);
                    } elseif (isset($item_data['unit_price']) && floatval($item_data['unit_price']) > 0) {
                        $unit_price = floatval($item_data['unit_price']);
                    } elseif (isset($item_data['cost_price']) && floatval($item_data['cost_price']) > 0) {
                        $unit_price = floatval($item_data['cost_price']);
                    }

                    $total_amount = $unit_price * $quantity;
                    $order_number = 'ORD-' . date('Y') . '-' . sprintf('%05d', rand(1, 99999));
                    $now = date('Y-m-d H:i:s');

                    $stmt_ins = $pdo->prepare("INSERT INTO client_hardware_orders 
                        (order_number, accountnum, tradename, clientname, contact_person, contact_number, delivery_address, item_id, item_code, item_name, quantity, unit_price, total_amount, notes, status, payment_method, created_at, updated_at) 
                        VALUES (:ord_no, :acct, :trade, :cname, :c_person, :c_num, :address, :i_id, :i_code, :i_name, :qty, :u_price, :tot, :notes, 'Pending', :pay_method, :now, :now)");

                    $stmt_ins->execute(array(
                        ':ord_no'     => $order_number,
                        ':acct'       => $accountnum,
                        ':trade'      => $client['tradename'],
                        ':cname'      => isset($client['clientname']) ? $client['clientname'] : $client['tradename'],
                        ':c_person'   => $contact_person,
                        ':c_num'      => $contact_number,
                        ':address'    => $delivery_address,
                        ':i_id'       => $item_data['id'],
                        ':i_code'     => $item_data['item_code'],
                        ':i_name'     => $item_data['name'],
                        ':qty'        => $quantity,
                        ':u_price'    => $unit_price,
                        ':tot'        => $total_amount,
                        ':notes'      => $notes,
                        ':pay_method' => $payment_method,
                        ':now'        => $now
                    ));

                    header("Location: orders.php?placed=1&order_no=" . urlencode($order_number) . "&tab=history");
                    exit;
                }
            } catch (PDOException $e) {
                error_log("Order placement error: " . $e->getMessage());
                $error_msg = "Database error placing order: " . $e->getMessage();
            }
        }
    }
}

// Fetch Active Catalog Items for ordering
$stmt_catalog = $pdo->query("SELECT * FROM support_inventory_items WHERE status = 'Active' ORDER BY name ASC");
$catalog_items = $stmt_catalog ? $stmt_catalog->fetchAll() : array();

// Fetch Client Orders
$stmt_my_orders = $pdo->prepare("SELECT * FROM client_hardware_orders WHERE accountnum = :acct ORDER BY id DESC");
$stmt_my_orders->execute(array(':acct' => $accountnum));
$my_orders = $stmt_my_orders ? $stmt_my_orders->fetchAll() : array();

$pending_orders_count = 0;
foreach ($my_orders as $ord) {
    if ($ord['status'] === 'Pending') {
        $pending_orders_count++;
    }
}

$current_tab = isset($_GET['tab']) && $_GET['tab'] === 'history' ? 'history' : 'catalog';

$active_page = 'orders';
$page_title = 'Order Hardware & Materials';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo sanitize($page_title); ?> - RNZ Support</title>
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
<body class="bg-[#FFF5ED]/40 text-[#430D07] min-h-screen flex">

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="flex-1 flex flex-col min-w-0">
        <?php include __DIR__ . '/includes/header.php'; ?>

        <main class="p-4 sm:p-6 lg:p-8 pb-24 md:pb-8 max-w-7xl mx-auto w-full space-y-6">

            <!-- Flash Alerts -->
            <?php if (!empty($success_msg)): ?>
                <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs sm:text-sm font-bold flex items-center justify-between animate-in fade-in duration-200">
                    <div class="flex items-center space-x-2">
                        <svg class="w-5 h-5 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span><?php echo $success_msg; ?></span>
                    </div>
                    <button type="button" onclick="this.parentElement.remove()" class="text-emerald-500 hover:text-emerald-700 font-extrabold text-lg leading-none">&times;</button>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_msg)): ?>
                <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-xs sm:text-sm font-bold flex items-center justify-between animate-in fade-in duration-200">
                    <div class="flex items-center space-x-2">
                        <svg class="w-5 h-5 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        <span><?php echo sanitize($error_msg); ?></span>
                    </div>
                    <button type="button" onclick="this.parentElement.remove()" class="text-rose-500 hover:text-rose-700 font-extrabold text-lg leading-none">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Hero Header Banner -->
            <div class="bg-gradient-to-br from-[#EB3E0B] via-[#FA5915] to-[#FC884D] rounded-3xl p-6 sm:p-8 text-white shadow-xl shadow-[#EB3E0B]/15 flex flex-col md:flex-row md:items-center justify-between gap-6 relative overflow-hidden">
                <div class="space-y-2 max-w-xl relative z-10">
                    <div class="inline-flex items-center space-x-2 bg-white/20 backdrop-blur-md px-3 py-1 rounded-full text-xs font-bold tracking-wide">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                        <span>Official RNZ Hardware & Consumables</span>
                    </div>
                    <h2 class="text-2xl sm:text-3xl font-extrabold tracking-tight">Order Hardware Materials</h2>
                    <p class="text-xs sm:text-sm text-white/90 leading-relaxed">
                        Request POS hardware units, thermal rolls, receipt printers, barcode scanners, and replacement accessories directly to your branch.
                    </p>
                </div>
                <div class="relative z-10 flex flex-wrap items-center gap-3">
                    <button type="button" onclick="openOrderModal()" class="px-6 py-3.5 rounded-2xl bg-white text-[#EB3E0B] font-extrabold text-xs sm:text-sm shadow-md hover:bg-[#FFF5ED] transition-all flex items-center space-x-2 active:scale-95">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                        <span>Place New Order</span>
                    </button>
                </div>
                <!-- Background decoration SVG -->
                <div class="absolute -right-8 -bottom-10 opacity-15 pointer-events-none">
                    <svg class="w-64 h-64 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                </div>
            </div>

            <!-- Tab Navigation (Catalog vs. My Orders) -->
            <div class="flex items-center space-x-2 border-b border-[#FECDAA]/60 pb-2">
                <button type="button" onclick="switchTab('catalog')" id="tabBtnCatalog" class="px-5 py-2.5 rounded-2xl text-xs sm:text-sm font-bold transition-all flex items-center space-x-2 <?php echo ($current_tab === 'catalog') ? 'bg-[#EB3E0B] text-white shadow-sm shadow-[#EB3E0B]/20' : 'bg-white text-[#7C2112] hover:bg-[#FFE8D5] border border-[#FECDAA]/60'; ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                    <span>Hardware Catalog</span>
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-mono <?php echo ($current_tab === 'catalog') ? 'bg-white/20 text-white' : 'bg-[#FFE8D5] text-[#9A2512]'; ?>"><?php echo count($catalog_items); ?></span>
                </button>

                <button type="button" onclick="switchTab('history')" id="tabBtnHistory" class="px-5 py-2.5 rounded-2xl text-xs sm:text-sm font-bold transition-all flex items-center space-x-2 <?php echo ($current_tab === 'history') ? 'bg-[#EB3E0B] text-white shadow-sm shadow-[#EB3E0B]/20' : 'bg-white text-[#7C2112] hover:bg-[#FFE8D5] border border-[#FECDAA]/60'; ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    <span>My Order History</span>
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-mono <?php echo ($current_tab === 'history') ? 'bg-white/20 text-white' : 'bg-[#FFE8D5] text-[#9A2512]'; ?>"><?php echo count($my_orders); ?></span>
                    <?php if ($pending_orders_count > 0): ?>
                        <span class="w-2 h-2 rounded-full bg-amber-400 animate-pulse" title="<?php echo $pending_orders_count; ?> pending order(s)"></span>
                    <?php endif; ?>
                </button>
            </div>

            <!-- ========================================================================= -->
            <!-- TAB 1: HARDWARE CATALOG GRID -->
            <!-- ========================================================================= -->
            <div id="tabContentCatalog" class="<?php echo ($current_tab === 'catalog') ? 'block' : 'hidden'; ?> space-y-6">
                <?php if (empty($catalog_items)): ?>
                    <div class="bg-white rounded-3xl p-12 text-center border border-[#FECDAA] space-y-3">
                        <div class="w-16 h-16 rounded-full bg-[#FFE8D5] text-[#FA5915] flex items-center justify-center mx-auto">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                        </div>
                        <h3 class="font-extrabold text-lg text-[#430D07]">No hardware catalog items available.</h3>
                        <p class="text-xs text-[#7C2112]">Hardware inventory items will appear here once registered by technical support.</p>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
                        <?php foreach ($catalog_items as $item): 
                            $qty = intval($item['quantity']);
                            $min = intval($item['min_threshold']);
                            
                            // Unit Price (inventory selling price / unit price)
                            $price = 0.00;
                            if (isset($item['selling_price']) && floatval($item['selling_price']) > 0) {
                                $price = floatval($item['selling_price']);
                            } elseif (isset($item['unit_price']) && floatval($item['unit_price']) > 0) {
                                $price = floatval($item['unit_price']);
                            } elseif (isset($item['cost_price']) && floatval($item['cost_price']) > 0) {
                                $price = floatval($item['cost_price']);
                            }

                            $img_src = !empty($item['image_path']) ? $item['image_path'] : 'hardware_photos/system_unit.jpg';
                        ?>
                            <div class="bg-white rounded-3xl border border-[#FECDAA]/70 overflow-hidden shadow-sm hover:shadow-xl hover:border-[#EB3E0B]/50 transition-all flex flex-col justify-between group">
                                <!-- Card Header & Photo -->
                                <div>
                                    <div class="h-44 bg-[#FFF5ED] relative overflow-hidden flex items-center justify-center p-4">
                                        <img src="<?php echo sanitize($img_src); ?>" alt="<?php echo sanitize($item['name']); ?>" class="max-h-full max-w-full object-contain group-hover:scale-105 transition-transform duration-300" onerror="this.src='hardware_photos/system_unit.jpg'">
                                        
                                        <!-- Stock Badge -->
                                        <div class="absolute top-3 left-3">
                                            <?php if ($qty > 0): ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">
                                                    ● In Stock (<?php echo $qty; ?>)
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-200">
                                                    Pre-Order / Request
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- SKU Badge -->
                                        <div class="absolute top-3 right-3">
                                            <span class="font-mono text-[10px] font-bold px-2 py-1 rounded-xl bg-white/90 text-[#EB3E0B] shadow-sm border border-[#FECDAA]/50">
                                                <?php echo sanitize($item['item_code']); ?>
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Content -->
                                    <div class="p-5 space-y-2.5">
                                        <span class="text-[10px] font-bold text-[#9A2512] uppercase tracking-wider block">
                                            <?php echo sanitize(isset($item['category']) ? $item['category'] : 'Hardware Material'); ?>
                                        </span>
                                        <h3 class="font-extrabold text-base text-[#430D07] leading-snug line-clamp-1" title="<?php echo sanitize($item['name']); ?>">
                                            <?php echo sanitize($item['name']); ?>
                                        </h3>
                                        <p class="text-xs text-[#7C2112]/80 leading-relaxed line-clamp-2 min-h-[36px]" title="<?php echo sanitize($item['description']); ?>">
                                            <?php echo !empty($item['description']) ? sanitize($item['description']) : 'Standard POS hardware peripheral and accessories.'; ?>
                                        </p>
                                    </div>
                                </div>

                                <!-- Card Footer & Pricing -->
                                <div class="p-5 pt-0 border-t border-[#FFE8D5] mt-2 space-y-3">
                                    <div class="flex items-baseline justify-between pt-3">
                                        <span class="text-xs font-semibold text-[#9A2512]">Price:</span>
                                        <span class="text-xl font-extrabold font-mono text-[#EB3E0B]">
                                            ₱<?php echo number_format($price, 2); ?>
                                        </span>
                                    </div>

                                    <button type="button" onclick='openOrderModalForItem(<?php echo htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8'); ?>)' class="w-full py-3 rounded-2xl bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-extrabold text-xs flex items-center justify-center space-x-2 shadow-sm shadow-[#EB3E0B]/25 transition-all active:scale-95">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                                        <span>Order Material</span>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ========================================================================= -->
            <!-- TAB 2: MY ORDERS HISTORY -->
            <!-- ========================================================================= -->
            <div id="tabContentHistory" class="<?php echo ($current_tab === 'history') ? 'block' : 'hidden'; ?> space-y-6">
                <div class="bg-white rounded-3xl border border-[#FECDAA]/70 shadow-sm overflow-hidden space-y-4">
                    <div class="p-6 border-b border-[#FFE8D5] flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                        <div>
                            <h3 class="text-lg font-extrabold text-[#430D07]">Your Hardware Orders</h3>
                            <p class="text-xs text-[#7C2112]">Track status and fulfillment of your requested hardware supplies.</p>
                        </div>
                        <div class="text-xs text-[#9A2512] font-semibold">
                            Total: <strong class="text-[#430D07] font-mono"><?php echo count($my_orders); ?></strong> Order(s)
                        </div>
                    </div>

                    <?php if (empty($my_orders)): ?>
                        <div class="p-12 text-center text-[#7C2112] space-y-3">
                            <div class="w-16 h-16 rounded-full bg-[#FFE8D5] text-[#FA5915] flex items-center justify-center mx-auto">
                                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                            </div>
                            <h4 class="font-extrabold text-base text-[#430D07]">No past hardware orders found</h4>
                            <p class="text-xs max-w-sm mx-auto">You haven't placed any hardware material orders yet. Click below to browse available equipment.</p>
                            <button type="button" onclick="switchTab('catalog')" class="px-5 py-2.5 rounded-2xl bg-[#EB3E0B] text-white font-bold text-xs shadow-sm hover:bg-[#C32C0B] transition-all inline-block mt-2">
                                Browse Hardware Catalog
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse text-xs">
                                <thead>
                                    <tr class="bg-[#FFF5ED] border-b border-[#FFE8D5] text-[#9A2512] font-bold uppercase tracking-wider text-[11px]">
                                        <th class="py-3.5 px-6">Order Details</th>
                                        <th class="py-3.5 px-6">Hardware Item</th>
                                        <th class="py-3.5 px-6 text-center">Qty</th>
                                        <th class="py-3.5 px-6">Pricing</th>
                                        <th class="py-3.5 px-6">Delivery Address</th>
                                        <th class="py-3.5 px-6 text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#FFE8D5] font-medium">
                                    <?php foreach ($my_orders as $ord): 
                                        $status = $ord['status'];
                                        if ($status === 'Pending') {
                                            $st_badge = 'bg-amber-100 text-amber-900 border-amber-300';
                                        } elseif ($status === 'Approved' || $status === 'Processing') {
                                            $st_badge = 'bg-blue-100 text-blue-900 border-blue-300';
                                        } elseif ($status === 'Out for Delivery') {
                                            $st_badge = 'bg-purple-100 text-purple-900 border-purple-300';
                                        } elseif ($status === 'Completed') {
                                            $st_badge = 'bg-emerald-100 text-emerald-900 border-emerald-300';
                                        } else {
                                            $st_badge = 'bg-rose-100 text-rose-900 border-rose-300';
                                        }
                                    ?>
                                        <tr class="hover:bg-[#FFF5ED]/50 transition-colors">
                                            <!-- Order Number & Date -->
                                            <td class="py-4 px-6">
                                                <div class="font-mono font-extrabold text-[#EB3E0B] text-xs">
                                                    <?php echo sanitize($ord['order_number']); ?>
                                                </div>
                                                <span class="text-[11px] text-[#7C2112]/70 block mt-0.5">
                                                    <?php echo format_date($ord['created_at']); ?>
                                                </span>
                                            </td>

                                            <!-- Hardware Item -->
                                            <td class="py-4 px-6">
                                                <div class="font-extrabold text-[#430D07] text-xs">
                                                    <?php echo sanitize($ord['item_name']); ?>
                                                </div>
                                                <span class="font-mono text-[10px] text-[#9A2512] font-semibold">
                                                    SKU: <?php echo sanitize($ord['item_code']); ?>
                                                </span>
                                                <?php if (!empty($ord['notes'])): ?>
                                                    <p class="text-[11px] text-[#7C2112]/80 italic mt-1 bg-[#FFF5ED] p-2 rounded-xl border border-[#FECDAA]/50 max-w-xs">
                                                        "<?php echo sanitize($ord['notes']); ?>"
                                                    </p>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Quantity -->
                                            <td class="py-4 px-6 text-center">
                                                <span class="font-mono font-extrabold text-sm text-[#430D07] bg-[#FFE8D5] px-2.5 py-1 rounded-xl">
                                                    <?php echo number_format($ord['quantity']); ?>
                                                </span>
                                            </td>

                                            <!-- Pricing -->
                                            <td class="py-4 px-6">
                                                <div class="space-y-0.5">
                                                    <div class="text-xs font-bold text-[#EB3E0B] font-mono">
                                                        ₱<?php echo number_format($ord['total_amount'], 2); ?>
                                                    </div>
                                                    <span class="text-[10px] text-[#7C2112] block">
                                                        @ ₱<?php echo number_format($ord['unit_price'], 2); ?> / unit
                                                    </span>
                                                    <span class="text-[9px] text-slate-500 block font-medium">
                                                        <?php echo sanitize($ord['payment_method']); ?>
                                                    </span>
                                                </div>
                                            </td>

                                            <!-- Delivery Address -->
                                            <td class="py-4 px-6 text-xs text-[#7C2112] max-w-xs">
                                                <p class="font-semibold text-[#430D07]"><?php echo sanitize($ord['contact_person']); ?> (<?php echo sanitize($ord['contact_number']); ?>)</p>
                                                <p class="truncate text-[11px] text-[#7C2112]/80 mt-0.5" title="<?php echo sanitize($ord['delivery_address']); ?>">
                                                    <?php echo sanitize($ord['delivery_address']); ?>
                                                </p>
                                            </td>

                                            <!-- Status -->
                                            <td class="py-4 px-6 text-center">
                                                <span class="inline-flex items-center px-3 py-1 rounded-full text-[11px] font-extrabold border <?php echo $st_badge; ?>">
                                                    <?php echo sanitize($ord['status']); ?>
                                                </span>
                                                <?php if (!empty($ord['admin_remarks'])): ?>
                                                    <span class="block text-[10px] text-slate-500 italic mt-1 max-w-[140px] mx-auto truncate" title="<?php echo sanitize($ord['admin_remarks']); ?>">
                                                        Dispatch: <?php echo sanitize($ord['admin_remarks']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </main>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL: PLACE HARDWARE ORDER -->
    <!-- ========================================================================= -->
    <div id="orderModal" class="fixed inset-0 z-50 bg-[#430D07]/60 backdrop-blur-sm hidden items-center justify-center p-4 overflow-y-auto">
        <div class="bg-white rounded-3xl shadow-2xl border border-[#FECDAA] max-w-xl w-full p-6 sm:p-8 space-y-6 my-8 animate-in fade-in zoom-in duration-150">
            <div class="flex items-center justify-between border-b border-[#FFE8D5] pb-4">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-2xl bg-[#EB3E0B] text-white flex items-center justify-center font-bold shadow-md shadow-[#EB3E0B]/25">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                    </div>
                    <div>
                        <h3 class="font-extrabold text-lg text-[#430D07]">Order Hardware Material</h3>
                        <p class="text-xs text-[#7C2112]">Prices are synchronized directly with support inventory pricing.</p>
                    </div>
                </div>
                <button type="button" onclick="closeOrderModal()" class="text-[#9A2512] hover:text-[#430D07] p-2 rounded-full hover:bg-[#FFE8D5]/60">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <form method="POST" action="" class="space-y-4">
                <input type="hidden" name="action" value="place_hardware_order">

                <!-- 1. Hardware Item Selector -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-[#430D07]">Select Hardware Item <span class="text-[#EB3E0B]">*</span></label>
                    <select name="item_id" id="modal_item_id" required onchange="calculateOrderTotal()" class="w-full bg-[#FFF5ED] text-[#430D07] text-xs px-4 py-3 rounded-2xl border border-[#FECDAA] focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-bold">
                        <option value="">-- Choose Equipment / Material --</option>
                        <?php foreach ($catalog_items as $cat_item): 
                            $price = 0.00;
                            if (isset($cat_item['selling_price']) && floatval($cat_item['selling_price']) > 0) {
                                $price = floatval($cat_item['selling_price']);
                            } elseif (isset($cat_item['unit_price']) && floatval($cat_item['unit_price']) > 0) {
                                $price = floatval($cat_item['unit_price']);
                            } elseif (isset($cat_item['cost_price']) && floatval($cat_item['cost_price']) > 0) {
                                $price = floatval($cat_item['cost_price']);
                            }
                        ?>
                            <option value="<?php echo $cat_item['id']; ?>" 
                                    data-price="<?php echo $price; ?>"
                                    data-code="<?php echo sanitize($cat_item['item_code']); ?>"
                                    data-name="<?php echo sanitize($cat_item['name']); ?>">
                                <?php echo sanitize($cat_item['name'] . ' (' . $cat_item['item_code'] . ') — ₱' . number_format($price, 2)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 2. Quantity & Total Amount Preview Box -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 items-center">
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-[#430D07]">Quantity <span class="text-[#EB3E0B]">*</span></label>
                        <input type="number" name="quantity" id="modal_quantity" min="1" max="99" value="1" required oninput="calculateOrderTotal()" class="w-full bg-[#FFF5ED] text-[#430D07] text-sm font-extrabold px-4 py-2.5 rounded-2xl border border-[#FECDAA] focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-mono">
                    </div>
                    
                    <div class="p-3 bg-[#FFE8D5]/80 border border-[#FECDAA] rounded-2xl flex flex-col justify-center">
                        <span class="text-[10px] font-bold text-[#9A2512] uppercase tracking-wider">Estimated Total</span>
                        <span id="modal_total_display" class="text-xl font-extrabold font-mono text-[#EB3E0B]">₱0.00</span>
                    </div>
                </div>

                <!-- 3. Contact Details Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-[#430D07]">Contact Person <span class="text-[#EB3E0B]">*</span></label>
                        <input type="text" name="contact_person" required value="<?php echo sanitize(isset($client['clientname']) ? $client['clientname'] : $client['tradename']); ?>" class="w-full bg-[#FFF5ED] text-[#430D07] text-xs px-4 py-2.5 rounded-2xl border border-[#FECDAA] focus:border-[#EB3E0B] focus:bg-white focus:outline-none">
                    </div>
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-[#430D07]">Contact Number <span class="text-[#EB3E0B]">*</span></label>
                        <input type="text" name="contact_number" required value="<?php echo sanitize(isset($client['contactnum']) ? $client['contactnum'] : ''); ?>" placeholder="e.g. 0917-123-4567" class="w-full bg-[#FFF5ED] text-[#430D07] text-xs px-4 py-2.5 rounded-2xl border border-[#FECDAA] focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-mono">
                    </div>
                </div>

                <!-- 4. Delivery Address -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-[#430D07]">Store / Delivery Address <span class="text-[#EB3E0B]">*</span></label>
                    <textarea name="delivery_address" rows="2" required placeholder="Complete store branch address for courier delivery or tech drop-off..." class="w-full bg-[#FFF5ED] text-[#430D07] text-xs p-3 rounded-2xl border border-[#FECDAA] focus:border-[#EB3E0B] focus:bg-white focus:outline-none"><?php echo sanitize(isset($client['address']) ? $client['address'] : ''); ?></textarea>
                </div>

                <!-- 5. Payment Terms Selection -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-[#430D07]">Payment Method</label>
                    <select name="payment_method" class="w-full bg-[#FFF5ED] text-[#430D07] text-xs px-4 py-2.5 rounded-2xl border border-[#FECDAA] focus:border-[#EB3E0B] focus:bg-white focus:outline-none font-medium">
                        <option value="Charge to POS Account Billing">Charge to Monthly POS Retainer / Account Billing</option>
                        <option value="Cash on Delivery (COD) / Tech Handover">Cash on Delivery (COD) / Tech Handover</option>
                        <option value="Bank Transfer / GCash Payment">Bank Transfer / Online GCash Payment</option>
                    </select>
                </div>

                <!-- 6. Special Notes -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-[#430D07]">Order Instructions / Notes</label>
                    <input type="text" name="notes" placeholder="e.g., Please deliver with 5 extra roll papers; urgent replacement for Cashier 2" class="w-full bg-[#FFF5ED] text-[#430D07] text-xs px-4 py-2.5 rounded-2xl border border-[#FECDAA] focus:border-[#EB3E0B] focus:bg-white focus:outline-none">
                </div>

                <div class="pt-4 border-t border-[#FFE8D5] flex items-center justify-end space-x-3">
                    <button type="button" onclick="closeOrderModal()" class="px-5 py-2.5 rounded-2xl bg-[#FFE8D5] text-[#7C2112] font-bold text-xs hover:bg-[#FECDAA] transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="px-6 py-2.5 rounded-2xl bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-extrabold text-xs shadow-md shadow-[#EB3E0B]/30 transition-all active:scale-95 flex items-center space-x-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span>Confirm & Place Order</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php include __DIR__ . '/includes/footer.php'; ?>

    <script>
    function switchTab(tab) {
        var catalogSec = document.getElementById('tabContentCatalog');
        var historySec = document.getElementById('tabContentHistory');
        var btnCatalog = document.getElementById('tabBtnCatalog');
        var btnHistory = document.getElementById('tabBtnHistory');

        if (tab === 'history') {
            if (catalogSec) catalogSec.classList.add('hidden');
            if (historySec) historySec.classList.remove('hidden');
            
            if (btnHistory) {
                btnHistory.className = 'px-5 py-2.5 rounded-2xl text-xs sm:text-sm font-bold transition-all flex items-center space-x-2 bg-[#EB3E0B] text-white shadow-sm shadow-[#EB3E0B]/20';
            }
            if (btnCatalog) {
                btnCatalog.className = 'px-5 py-2.5 rounded-2xl text-xs sm:text-sm font-bold transition-all flex items-center space-x-2 bg-white text-[#7C2112] hover:bg-[#FFE8D5] border border-[#FECDAA]/60';
            }
        } else {
            if (historySec) historySec.classList.add('hidden');
            if (catalogSec) catalogSec.classList.remove('hidden');
            
            if (btnCatalog) {
                btnCatalog.className = 'px-5 py-2.5 rounded-2xl text-xs sm:text-sm font-bold transition-all flex items-center space-x-2 bg-[#EB3E0B] text-white shadow-sm shadow-[#EB3E0B]/20';
            }
            if (btnHistory) {
                btnHistory.className = 'px-5 py-2.5 rounded-2xl text-xs sm:text-sm font-bold transition-all flex items-center space-x-2 bg-white text-[#7C2112] hover:bg-[#FFE8D5] border border-[#FECDAA]/60';
            }
        }
    }

    function calculateOrderTotal() {
        var sel = document.getElementById('modal_item_id');
        var qtyInput = document.getElementById('modal_quantity');
        var display = document.getElementById('modal_total_display');
        
        var opt = sel ? sel.options[sel.selectedIndex] : null;
        var unitPrice = (opt && opt.value) ? parseFloat(opt.getAttribute('data-price') || 0) : 0;
        var qty = qtyInput ? parseInt(qtyInput.value) || 1 : 1;
        if (qty < 1) qty = 1;

        var total = unitPrice * qty;
        if (display) {
            display.textContent = '₱' + total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }
    }

    function openOrderModal() {
        var m = document.getElementById('orderModal');
        if (m) {
            m.classList.remove('hidden');
            m.classList.add('flex');
            calculateOrderTotal();
        }
    }

    function openOrderModalForItem(item) {
        if (!item) return;
        var sel = document.getElementById('modal_item_id');
        if (sel) {
            sel.value = item.id;
        }
        var qtyInput = document.getElementById('modal_quantity');
        if (qtyInput) {
            qtyInput.value = 1;
        }
        openOrderModal();
    }

    function closeOrderModal() {
        var m = document.getElementById('orderModal');
        if (m) {
            m.classList.add('hidden');
            m.classList.remove('flex');
        }
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeOrderModal();
        }
    });
    </script>
</body>
</html>
