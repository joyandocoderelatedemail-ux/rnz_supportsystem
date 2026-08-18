<?php
// Support Center Admin Dashboard (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

if (!is_tech_logged_in()) {
    require_once __DIR__ . '/login.php';
    exit;
}

// Auto initialize inventory tables
init_inventory_tables();

$total_tickets = 0;
$pending_tickets = 0;
$in_progress_tickets = 0;
$resolved_tickets = 0;
$inv_total_items = 0;
$inv_total_units = 0;
$inv_low_stock = 0;
$low_inv_items = array();
$recent_tickets = array();

try {
    $pdo = get_db_connection();

    // KPI Stats Queries
    $total_tickets = intval($pdo->query("SELECT COUNT(*) FROM client_support_tickets")->fetchColumn());
    $pending_tickets = intval($pdo->query("SELECT COUNT(*) FROM client_support_tickets WHERE status = 'Pending'")->fetchColumn());
    $in_progress_tickets = intval($pdo->query("SELECT COUNT(*) FROM client_support_tickets WHERE status = 'In Progress'")->fetchColumn());
    $resolved_tickets = intval($pdo->query("SELECT COUNT(*) FROM client_support_tickets WHERE status IN ('Resolved', 'Closed')")->fetchColumn());

    // Inventory Summary Metrics
    $inv_total_items = intval($pdo->query("SELECT COUNT(*) FROM support_inventory_items")->fetchColumn());
    $inv_total_units = intval($pdo->query("SELECT SUM(quantity) FROM support_inventory_items")->fetchColumn());
    $inv_low_stock = intval($pdo->query("SELECT COUNT(*) FROM support_inventory_items WHERE quantity <= min_threshold")->fetchColumn());

    // Low Stock / Featured Hardware Items
    $stmt_low_inv = $pdo->query("SELECT * FROM support_inventory_items ORDER BY quantity ASC, min_threshold DESC LIMIT 4");
    $low_inv_items = $stmt_low_inv ? $stmt_low_inv->fetchAll() : array();

    // Recent Incoming Tickets
    $stmt_tickets = $pdo->query("SELECT t.*, c.tradename, c.clientname 
        FROM client_support_tickets t 
        LEFT JOIN bucket_client c ON t.accountnum = c.accountnum 
        ORDER BY t.created_at DESC LIMIT 8");
    $recent_tickets = $stmt_tickets ? $stmt_tickets->fetchAll() : array();
} catch (PDOException $e) {
    error_log("Backend dashboard query error: " . $e->getMessage());
}

$active_page = 'dashboard';
$page_title = 'Support Center Overview';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Center Overview - RNZ Admin</title>
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

        <main class="p-4 sm:p-6 md:p-8 pb-24 md:pb-8 space-y-6 sm:space-y-8 max-w-7xl w-full mx-auto">

            <!-- KPI Metric Stats Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                
                <!-- Pending Tickets Card -->
                <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-amber-600 uppercase tracking-wider">Pending Action</span>
                        <h3 class="text-3xl font-extrabold text-slate-900 font-mono"><?php echo number_format($pending_tickets); ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center font-bold">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                </div>

                <!-- In Progress Card -->
                <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-blue-600 uppercase tracking-wider">In Progress</span>
                        <h3 class="text-3xl font-extrabold text-slate-900 font-mono"><?php echo number_format($in_progress_tickets); ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center font-bold">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                    </div>
                </div>

                <!-- Total Resolved Card -->
                <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-emerald-600 uppercase tracking-wider">Resolved Tickets</span>
                        <h3 class="text-3xl font-extrabold text-slate-900 font-mono"><?php echo number_format($resolved_tickets); ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                    </div>
                </div>

                <!-- Hardware Inventory Units Card -->
                <a href="inventory.php" class="bg-white hover:bg-slate-50/80 rounded-3xl p-6 border border-slate-200 hover:border-[#EB3E0B]/40 shadow-sm flex items-center justify-between transition-all group">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-[#EB3E0B] uppercase tracking-wider">Hardware Stock</span>
                        <h3 class="text-3xl font-extrabold text-slate-900 font-mono group-hover:text-[#EB3E0B] transition-colors"><?php echo number_format($inv_total_units); ?></h3>
                        <p class="text-[11px] text-slate-400">
                            <?php if ($inv_low_stock > 0): ?>
                                <span class="text-amber-600 font-bold"><?php echo $inv_low_stock; ?> low stock alert</span>
                            <?php else: ?>
                                <span><?php echo $inv_total_items; ?> catalog items</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-[#FFE8D5] text-[#EB3E0B] flex items-center justify-center font-bold">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                    </div>
                </a>

            </div>

            <!-- Hardware Inventory Quick Overview & Low Stock Alert Grid -->
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-extrabold text-slate-900 flex items-center gap-2">
                            <span>Hardware Inventory Quick Watch</span>
                            <?php if ($inv_low_stock > 0): ?>
                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 border border-amber-300">
                                    <?php echo $inv_low_stock; ?> Low Stock Alert
                                </span>
                            <?php endif; ?>
                        </h3>
                        <p class="text-xs text-slate-500 mt-0.5">Critical stock levels for POS printers, scanners, monitors and peripherals.</p>
                    </div>
                    <a href="inventory.php" class="text-xs font-bold text-[#EB3E0B] hover:text-[#C32C0B] flex items-center space-x-1">
                        <span>Open Full Inventory Hub</span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </a>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5">
                    <?php if (empty($low_inv_items)): ?>
                        <div class="col-span-4 py-4 text-center text-xs text-slate-400">
                            No hardware items found in inventory.
                        </div>
                    <?php else: ?>
                        <?php foreach ($low_inv_items as $litem): 
                            $lqty = intval($litem['quantity']);
                            $lmin = intval($litem['min_threshold']);
                            $limg = !empty($litem['image_path']) ? '../' . ltrim($litem['image_path'], '/') : '../hardware_photos/system_unit.jpg';
                            
                            if ($lqty == 0) {
                                $lbadge = 'bg-rose-100 text-rose-800 border-rose-300';
                                $llabel = 'Out of Stock';
                            } elseif ($lqty <= $lmin) {
                                $lbadge = 'bg-amber-100 text-amber-800 border-amber-300';
                                $llabel = 'Low: ' . $lqty . ' left';
                            } else {
                                $lbadge = 'bg-emerald-100 text-emerald-800 border-emerald-300';
                                $llabel = $lqty . ' in stock';
                            }
                        ?>
                            <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200/80 flex items-center space-x-3 hover:border-slate-300 transition-colors">
                                <div class="w-10 h-10 rounded-xl bg-white border border-slate-200 p-1 flex items-center justify-center shrink-0 overflow-hidden">
                                    <img src="<?php echo sanitize($limg); ?>" alt="" class="w-full h-full object-contain" onerror="this.src='../hardware_photos/system_unit.jpg'">
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h4 class="text-xs font-extrabold text-slate-900 truncate" title="<?php echo sanitize($litem['name']); ?>">
                                        <?php echo sanitize($litem['name']); ?>
                                    </h4>
                                    <div class="flex items-center justify-between mt-1">
                                        <span class="text-[10px] font-mono text-slate-400"><?php echo sanitize($litem['item_code']); ?></span>
                                        <span class="text-[9px] font-bold px-1.5 py-0.5 rounded-full border <?php echo $lbadge; ?>">
                                            <?php echo $llabel; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Incoming Support Tickets Queue -->
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden space-y-4">
                <div class="p-6 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-extrabold text-slate-900">Incoming Client Support Tickets</h3>
                        <p class="text-xs text-slate-500 mt-0.5">Real-time queue of client tickets submitted via the client portal.</p>
                    </div>
                    <a href="tickets.php" class="text-xs font-bold text-[#EB3E0B] hover:text-[#C32C0B] flex items-center space-x-1">
                        <span>View All Tickets</span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </a>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-100 text-slate-500 font-bold uppercase tracking-wider text-[11px]">
                                <th class="py-3.5 px-6">Ticket #</th>
                                <th class="py-3.5 px-6">Trade Name</th>
                                <th class="py-3.5 px-6">Subject / Summary</th>
                                <th class="py-3.5 px-6">Status</th>
                                <th class="py-3.5 px-6">Created Date</th>
                                <th class="py-3.5 px-6 text-right">Manage</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium">
                            <?php if (empty($recent_tickets)): ?>
                                <tr>
                                    <td colspan="6" class="py-8 text-center text-slate-400">
                                        No client support tickets found.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recent_tickets as $t): 
                                    $client_display = !empty($t['tradename']) ? $t['tradename'] : (!empty($t['clientname']) ? $t['clientname'] : 'Acct: ' . $t['accountnum']);
                                    $badge_class = get_status_badge_class($t['status']);
                                ?>
                                    <tr class="hover:bg-slate-50/80 transition-colors">
                                        <td class="py-4 px-6 font-mono font-bold text-[#EB3E0B]">
                                            <?php echo sanitize($t['ticket_number']); ?>
                                        </td>
                                        <td class="py-4 px-6">
                                            <div class="font-bold text-slate-900"><?php echo sanitize($client_display); ?></div>
                                        </td>
                                        <td class="py-4 px-6 max-w-xs truncate font-semibold text-slate-800">
                                            <?php echo sanitize($t['subject']); ?>
                                        </td>
                                        <td class="py-4 px-6">
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold border <?php echo $badge_class; ?>">
                                                <?php echo sanitize($t['status']); ?>
                                            </span>
                                        </td>
                                        <td class="py-4 px-6 text-slate-500">
                                            <?php echo format_date($t['created_at']); ?>
                                        </td>
                                        <td class="py-4 px-6 text-right">
                                            <a href="ticket_detail.php?id=<?php echo $t['id']; ?>" onclick="markNotificationClicked('ticket_<?php echo $t['id']; ?>', <?php echo (intval($t['id']) * 100000); ?>)" class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-slate-100 hover:bg-[#EB3E0B] text-slate-500 hover:text-white transition-colors" title="Manage Ticket">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                </svg>
                                            </a>
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

<?php include __DIR__ . '/includes/footer.php'; ?>
