<?php
// Hardware Pull-Out & Deployment Reports for Support Center (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/inventory_init.php';

// Gated on the inventory permission: this reports on inventory movement, so anyone
// who can already see pull-outs in inventory.php can read the report.
require_page_access('inventory');
init_inventory_tables();

$pdo = get_db_connection();

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------
$today = date('Y-m-d');
$default_from = date('Y-m-d', strtotime('-30 days'));

$f_from      = isset($_GET['from']) && $_GET['from'] !== '' ? trim($_GET['from']) : $default_from;
$f_to        = isset($_GET['to']) && $_GET['to'] !== '' ? trim($_GET['to']) : $today;
$f_direction = isset($_GET['direction']) ? trim($_GET['direction']) : 'all';
$f_account   = isset($_GET['account']) ? trim($_GET['account']) : '';
$f_item      = isset($_GET['item']) ? intval($_GET['item']) : 0;
$f_tech      = isset($_GET['tech']) ? trim($_GET['tech']) : '';
$f_search    = isset($_GET['q']) ? trim($_GET['q']) : '';

// Guard against a reversed range silently returning nothing
if (strtotime($f_from) > strtotime($f_to)) {
    $swap = $f_from;
    $f_from = $f_to;
    $f_to = $swap;
}

$where = array("(l.change_type LIKE 'Pull Out%' OR l.change_type LIKE 'Pull-Out%')");
$params = array();

$where[] = "l.created_at >= :from_dt";
$params[':from_dt'] = $f_from . ' 00:00:00';
$where[] = "l.created_at <= :to_dt";
$params[':to_dt'] = $f_to . ' 23:59:59';

if ($f_direction === 'to_client') {
    $where[] = "l.change_type LIKE '%To Client%'";
} elseif ($f_direction === 'from_client') {
    $where[] = "l.change_type LIKE '%From Client%'";
}

if (!empty($f_account)) {
    $where[] = "l.accountnum = :acct";
    $params[':acct'] = $f_account;
}

if ($f_item > 0) {
    $where[] = "l.item_id = :iid";
    $params[':iid'] = $f_item;
}

if (!empty($f_tech)) {
    $where[] = "l.tech_name = :tech";
    $params[':tech'] = $f_tech;
}

if (!empty($f_search)) {
    $where[] = "(l.notes LIKE :q OR l.client_name LIKE :q OR i.name LIKE :q OR i.item_code LIKE :q)";
    $params[':q'] = '%' . $f_search . '%';
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

$sql = "SELECT l.*, i.name AS item_name, i.item_code, i.category, c.tradename, c.clientname
    FROM support_inventory_logs l
    LEFT JOIN support_inventory_items i ON l.item_id = i.id
    LEFT JOIN bucket_client c ON l.accountnum = c.accountnum
    $where_sql
    ORDER BY l.created_at DESC, l.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ---------------------------------------------------------------------------
// CSV export of the current filtered view (must run before any HTML output)
// ---------------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = 'pullout-report-' . $f_from . '-to-' . $f_to . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    // BOM so Excel opens the peso sign and accented names correctly
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array('Date / Time', 'Direction', 'Item Code', 'Hardware Item', 'Quantity',
        'Account #', 'Client', 'Technician', 'Stock Before', 'Stock After', 'Reason & Remarks'));

    foreach ($rows as $r) {
        $dir = (strpos($r['change_type'], 'To Client') !== false) ? 'Deployed to Client' : 'Pulled from Client';
        $cl = !empty($r['tradename']) ? $r['tradename'] : (!empty($r['client_name']) ? $r['client_name'] : '');
        fputcsv($out, array(
            $r['created_at'],
            $dir,
            isset($r['item_code']) ? $r['item_code'] : '',
            isset($r['item_name']) ? $r['item_name'] : '',
            abs(intval($r['quantity_change'])),
            $r['accountnum'],
            $cl,
            $r['tech_name'],
            intval($r['previous_quantity']),
            intval($r['new_quantity']),
            $r['notes']
        ));
    }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------------------
// Summary figures for the filtered result set
// ---------------------------------------------------------------------------
$total_records   = count($rows);
$units_to_client = 0;
$units_from_client = 0;
$units_restocked = 0;
$accounts_seen   = array();
$items_seen      = array();
$per_item        = array();
$per_client      = array();

foreach ($rows as $r) {
    $is_to_client = (strpos($r['change_type'], 'To Client') !== false);
    // Deployments log a negative change; pull-outs log +qty only when restocked
    $units = abs(intval($r['quantity_change']));

    if ($is_to_client) {
        $units_to_client += $units;
    } else {
        $units_from_client += 1;
        if (intval($r['quantity_change']) > 0) {
            $units_restocked += $units;
        }
    }

    if (!empty($r['accountnum'])) {
        $accounts_seen[$r['accountnum']] = true;
        $label = !empty($r['tradename']) ? $r['tradename'] : (!empty($r['client_name']) ? $r['client_name'] : $r['accountnum']);
        if (!isset($per_client[$label])) {
            $per_client[$label] = 0;
        }
        $per_client[$label]++;
    }

    if (!empty($r['item_id'])) {
        $items_seen[$r['item_id']] = true;
        $iname = !empty($r['item_name']) ? $r['item_name'] : 'Item #' . $r['item_id'];
        if (!isset($per_item[$iname])) {
            $per_item[$iname] = 0;
        }
        $per_item[$iname]++;
    }
}

arsort($per_item);
arsort($per_client);
$top_items   = array_slice($per_item, 0, 5, true);
$top_clients = array_slice($per_client, 0, 5, true);

// ---------------------------------------------------------------------------
// Filter dropdown sources
// ---------------------------------------------------------------------------
$stmt_items = $pdo->query("SELECT DISTINCT i.id, i.name, i.item_code
    FROM support_inventory_logs l
    INNER JOIN support_inventory_items i ON l.item_id = i.id
    WHERE l.change_type LIKE 'Pull Out%' OR l.change_type LIKE 'Pull-Out%'
    ORDER BY i.name ASC");
$filter_items = $stmt_items ? $stmt_items->fetchAll() : array();

$stmt_accts = $pdo->query("SELECT DISTINCT l.accountnum, c.tradename, c.clientname
    FROM support_inventory_logs l
    LEFT JOIN bucket_client c ON l.accountnum = c.accountnum
    WHERE (l.change_type LIKE 'Pull Out%' OR l.change_type LIKE 'Pull-Out%') AND l.accountnum IS NOT NULL AND l.accountnum <> ''
    ORDER BY c.tradename ASC");
$filter_accounts = $stmt_accts ? $stmt_accts->fetchAll() : array();

$stmt_techs = $pdo->query("SELECT DISTINCT tech_name FROM support_inventory_logs
    WHERE (change_type LIKE 'Pull Out%' OR change_type LIKE 'Pull-Out%') AND tech_name <> ''
    ORDER BY tech_name ASC");
$filter_techs = $stmt_techs ? $stmt_techs->fetchAll() : array();

$has_filters = ($f_direction !== 'all' || !empty($f_account) || $f_item > 0 || !empty($f_tech) || !empty($f_search)
    || $f_from !== $default_from || $f_to !== $today);

$my_tier = get_logged_tech_access_tier();
$active_page = 'pullout_reports';
$page_title = 'Pull-Out Reports';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo sanitize($page_title); ?> - Support Center</title>
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

        @media print {
            /* Strip the app chrome so the report prints as a clean document */
            aside, header, nav, .no-print { display: none !important; }
            body { background: #fff !important; }
            main { padding: 0 !important; max-width: none !important; }
            .print-only { display: block !important; }
            .print-card { border: 1px solid #cbd5e1 !important; box-shadow: none !important; break-inside: avoid; }
            table { font-size: 10px !important; }
            a[href]:after { content: none !important; }
        }
        .print-only { display: none; }
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

        <main class="p-6 sm:p-8 space-y-6 max-w-7xl w-full mx-auto">

            <!-- Print-only document heading -->
            <div class="print-only mb-4">
                <h1 style="font-size:18px;font-weight:800;margin:0;">RNZ Support Center &mdash; Hardware Pull-Out Report</h1>
                <p style="font-size:11px;margin:4px 0 0;">
                    Period: <?php echo format_date_only($f_from); ?> to <?php echo format_date_only($f_to); ?>
                    &nbsp;|&nbsp; Generated: <?php echo format_date(date('Y-m-d H:i:s')); ?>
                    &nbsp;|&nbsp; Records: <?php echo $total_records; ?>
                </p>
            </div>

            <!-- Page Heading -->
            <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-sm print-card">
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                    <div>
                        <div class="inline-flex items-center space-x-2 bg-[#FFE8D5] px-3 py-1 rounded-full text-[#EB3E0B] text-xs font-bold uppercase tracking-wider mb-2 no-print">
                            <span>Inventory Reporting</span>
                        </div>
                        <h2 class="text-xl sm:text-2xl font-extrabold text-slate-900">Hardware Pull-Out &amp; Deployment Report</h2>
                        <p class="text-xs text-slate-500 mt-1">
                            Every hardware movement between the warehouse and client sites, drawn from the inventory movement log.
                        </p>
                    </div>

                    <div class="flex items-center gap-2.5 shrink-0 no-print">
                        <button type="button" onclick="window.print()" class="bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs px-5 py-2.5 rounded-full shadow-sm transition-all active:scale-95 flex items-center space-x-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                            </svg>
                            <span>Print / PDF</span>
                        </button>
                        <a href="pullout_reports.php?<?php echo http_build_query(array_merge($_GET, array('export' => 'csv'))); ?>" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs px-5 py-2.5 rounded-full shadow-sm transition-all active:scale-95 flex items-center space-x-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                            </svg>
                            <span>Export CSV</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm no-print">
                <form method="GET" action="pullout_reports.php" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Date From</label>
                            <input type="date" name="from" value="<?php echo sanitize($f_from); ?>" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Date To</label>
                            <input type="date" name="to" value="<?php echo sanitize($f_to); ?>" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Direction</label>
                            <select name="direction" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                                <option value="all"<?php echo ($f_direction === 'all') ? ' selected' : ''; ?>>All Movements</option>
                                <option value="to_client"<?php echo ($f_direction === 'to_client') ? ' selected' : ''; ?>>Deployed to Client</option>
                                <option value="from_client"<?php echo ($f_direction === 'from_client') ? ' selected' : ''; ?>>Pulled from Client</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Technician</label>
                            <select name="tech" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                                <option value="">All Technicians</option>
                                <?php foreach ($filter_techs as $ft): ?>
                                    <option value="<?php echo sanitize($ft['tech_name']); ?>"<?php echo ($f_tech === $ft['tech_name']) ? ' selected' : ''; ?>><?php echo sanitize($ft['tech_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Client Account</label>
                            <select name="account" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                                <option value="">All Clients</option>
                                <?php foreach ($filter_accounts as $fa): ?>
                                    <?php $fa_label = !empty($fa['tradename']) ? $fa['tradename'] : (!empty($fa['clientname']) ? $fa['clientname'] : 'Account #' . $fa['accountnum']); ?>
                                    <option value="<?php echo sanitize($fa['accountnum']); ?>"<?php echo ($f_account === $fa['accountnum']) ? ' selected' : ''; ?>>
                                        <?php echo sanitize($fa_label . ' (' . $fa['accountnum'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Hardware Item</label>
                            <select name="item" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                                <option value="0">All Items</option>
                                <?php foreach ($filter_items as $fi): ?>
                                    <option value="<?php echo intval($fi['id']); ?>"<?php echo ($f_item === intval($fi['id'])) ? ' selected' : ''; ?>>
                                        <?php echo sanitize($fi['name'] . ' (' . $fi['item_code'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sm:col-span-2">
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Search Remarks / Serial / Item</label>
                            <input type="text" name="q" value="<?php echo sanitize($f_search); ?>" placeholder="e.g. SN-99482104, defective, warranty" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2.5 pt-2 border-t border-slate-100">
                        <?php if ($has_filters): ?>
                            <a href="pullout_reports.php" class="px-5 py-2.5 rounded-full text-xs font-bold text-slate-600 hover:bg-slate-100 transition-colors">Reset Filters</a>
                        <?php endif; ?>
                        <button type="submit" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs px-6 py-2.5 rounded-full shadow-md transition-all active:scale-95">
                            Apply Filters
                        </button>
                    </div>
                </form>
            </div>

            <!-- Summary Tiles -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white rounded-3xl p-5 border border-slate-200 shadow-sm print-card">
                    <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Total Movements</p>
                    <h3 class="text-3xl font-extrabold text-slate-900 font-mono mt-1"><?php echo number_format($total_records); ?></h3>
                    <p class="text-[11px] text-slate-400 mt-0.5">in selected period</p>
                </div>

                <div class="bg-white rounded-3xl p-5 border border-slate-200 shadow-sm print-card">
                    <p class="text-[10px] font-bold text-emerald-700 uppercase tracking-wider">Units Deployed</p>
                    <h3 class="text-3xl font-extrabold text-emerald-700 font-mono mt-1"><?php echo number_format($units_to_client); ?></h3>
                    <p class="text-[11px] text-slate-400 mt-0.5">released to clients</p>
                </div>

                <div class="bg-white rounded-3xl p-5 border border-slate-200 shadow-sm print-card">
                    <p class="text-[10px] font-bold text-amber-700 uppercase tracking-wider">Pull-Outs Recorded</p>
                    <h3 class="text-3xl font-extrabold text-amber-700 font-mono mt-1"><?php echo number_format($units_from_client); ?></h3>
                    <p class="text-[11px] text-slate-400 mt-0.5"><?php echo number_format($units_restocked); ?> unit(s) restocked</p>
                </div>

                <div class="bg-white rounded-3xl p-5 border border-slate-200 shadow-sm print-card">
                    <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Clients Involved</p>
                    <h3 class="text-3xl font-extrabold text-slate-900 font-mono mt-1"><?php echo number_format(count($accounts_seen)); ?></h3>
                    <p class="text-[11px] text-slate-400 mt-0.5"><?php echo number_format(count($items_seen)); ?> distinct item(s)</p>
                </div>
            </div>

            <!-- Breakdowns -->
            <?php if ($total_records > 0): ?>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm print-card">
                        <h3 class="text-sm font-extrabold text-slate-900 mb-3">Most Moved Hardware</h3>
                        <div class="space-y-2">
                            <?php foreach ($top_items as $iname => $icount): ?>
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-xs font-bold text-slate-700 truncate"><?php echo sanitize($iname); ?></span>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <div class="w-24 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                                            <div class="h-full bg-[#EB3E0B] rounded-full" style="width: <?php echo ($total_records > 0) ? round(($icount / $total_records) * 100) : 0; ?>%"></div>
                                        </div>
                                        <span class="text-xs font-mono font-bold text-slate-900 w-6 text-right"><?php echo $icount; ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm print-card">
                        <h3 class="text-sm font-extrabold text-slate-900 mb-3">Most Active Clients</h3>
                        <div class="space-y-2">
                            <?php foreach ($top_clients as $cname => $ccount): ?>
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-xs font-bold text-slate-700 truncate"><?php echo sanitize($cname); ?></span>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <div class="w-24 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                                            <div class="h-full bg-slate-700 rounded-full" style="width: <?php echo ($total_records > 0) ? round(($ccount / $total_records) * 100) : 0; ?>%"></div>
                                        </div>
                                        <span class="text-xs font-mono font-bold text-slate-900 w-6 text-right"><?php echo $ccount; ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Movement Table -->
            <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-sm print-card">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="text-base font-extrabold text-slate-900">Movement Records</h3>
                        <p class="text-xs text-slate-500 mt-0.5">
                            <?php echo format_date_only($f_from); ?> &rarr; <?php echo format_date_only($f_to); ?>
                            &middot; <?php echo number_format($total_records); ?> record(s)
                        </p>
                    </div>
                </div>

                <?php if (empty($rows)): ?>
                    <div class="text-center py-12 space-y-2">
                        <p class="font-bold text-sm text-slate-600">No pull-out records in this period</p>
                        <p class="text-xs text-slate-400">Try widening the date range or clearing the filters.</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-slate-50 border-b border-slate-100 text-slate-500 font-bold uppercase tracking-wider text-[11px]">
                                    <th class="py-3 px-4">Date / Time</th>
                                    <th class="py-3 px-4">Direction</th>
                                    <th class="py-3 px-4">Hardware Item</th>
                                    <th class="py-3 px-4 text-center">Qty</th>
                                    <th class="py-3 px-4">Client Account</th>
                                    <th class="py-3 px-4">Technician</th>
                                    <th class="py-3 px-4 text-center">Stock</th>
                                    <th class="py-3 px-4">Reason &amp; Remarks</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 font-medium">
                                <?php foreach ($rows as $r): ?>
                                    <?php
                                    $is_to_client = (strpos($r['change_type'], 'To Client') !== false);
                                    $client_label = !empty($r['tradename']) ? $r['tradename'] : (!empty($r['client_name']) ? $r['client_name'] : '');
                                    ?>
                                    <tr class="hover:bg-slate-50/80 transition-colors">
                                        <td class="py-3 px-4 text-slate-500 whitespace-nowrap"><?php echo format_date($r['created_at']); ?></td>
                                        <td class="py-3 px-4">
                                            <?php if ($is_to_client): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-emerald-50 text-emerald-700 border border-emerald-200">Deployed</span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-50 text-amber-800 border border-amber-200">Pulled Out</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-4">
                                            <span class="block font-bold text-slate-900"><?php echo !empty($r['item_name']) ? sanitize($r['item_name']) : '<span class="text-slate-400">Deleted item</span>'; ?></span>
                                            <?php if (!empty($r['item_code'])): ?>
                                                <span class="text-[11px] text-slate-500 font-mono"><?php echo sanitize($r['item_code']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-4 text-center font-mono font-bold <?php echo $is_to_client ? 'text-emerald-700' : 'text-amber-700'; ?>">
                                            <?php echo abs(intval($r['quantity_change'])) > 0 ? abs(intval($r['quantity_change'])) : '&mdash;'; ?>
                                        </td>
                                        <td class="py-3 px-4">
                                            <?php if (!empty($r['accountnum'])): ?>
                                                <a href="accounts.php?q=<?php echo urlencode($r['accountnum']); ?>&tab=pullouts" class="block font-bold text-slate-900 hover:text-[#EB3E0B] transition-colors">
                                                    <?php echo sanitize($client_label !== '' ? $client_label : 'Account #' . $r['accountnum']); ?>
                                                </a>
                                                <span class="text-[11px] text-slate-500 font-mono"><?php echo sanitize($r['accountnum']); ?></span>
                                            <?php else: ?>
                                                <span class="text-slate-400">&mdash;</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-4 text-slate-600"><?php echo sanitize($r['tech_name']); ?></td>
                                        <td class="py-3 px-4 text-center font-mono text-[11px] text-slate-500 whitespace-nowrap">
                                            <?php echo intval($r['previous_quantity']); ?> &rarr; <strong class="text-slate-800"><?php echo intval($r['new_quantity']); ?></strong>
                                        </td>
                                        <td class="py-3 px-4 text-slate-600 max-w-[280px]">
                                            <?php echo !empty($r['notes']) ? sanitize($r['notes']) : '<span class="text-slate-300">&mdash;</span>'; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </main>

        <?php include __DIR__ . '/includes/footer.php'; ?>
    </div>
</div>

</body>
</html>
