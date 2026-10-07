<?php
// Client Expenses Report for Support Center (PHP 5.6 Compatible)
// Every expense recorded against a client account, in one filterable list.
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/inventory_init.php';

// Expenses are internal cost data: Master accounts only, the same rule the
// expense tab in accounts.php and the expense widgets in analytics.php follow.
if (!is_tech_logged_in()) {
    require_once __DIR__ . '/login.php';
    exit;
}
if (!is_super_admin()) {
    header("Location: index.php?msg=error&err_msg=" . urlencode("Access Denied: Client expense records are restricted to Super Admin (Master) accounts."));
    exit;
}

init_inventory_tables();

$pdo = get_db_connection();
if (!$pdo) {
    die("Database connection error.");
}

// ---------------------------------------------------------------------------
// Filters. Both dates empty means every expense ever recorded, which is what
// the page opens on - narrowing is the exception, not the default.
// ---------------------------------------------------------------------------
$f_from   = isset($_GET['from']) ? trim($_GET['from']) : '';
$f_to     = isset($_GET['to']) ? trim($_GET['to']) : '';
$f_search = isset($_GET['q']) ? trim($_GET['q']) : '';

// A reversed range would silently return nothing, so swap it instead
if ($f_from !== '' && $f_to !== '' && strtotime($f_from) > strtotime($f_to)) {
    $swap = $f_from;
    $f_from = $f_to;
    $f_to = $swap;
}

$where = array();
$params = array();

if ($f_from !== '') {
    $where[] = "e.expense_date >= :from_d";
    $params[':from_d'] = $f_from;
}
if ($f_to !== '') {
    $where[] = "e.expense_date <= :to_d";
    $params[':to_d'] = $f_to;
}
if ($f_search !== '') {
    $where[] = "(e.description LIKE :q OR e.accountnum LIKE :q OR e.recorded_by LIKE :q OR c.tradename LIKE :q OR c.clientname LIKE :q)";
    $params[':q'] = '%' . $f_search . '%';
}

$where_sql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

$expenses = array();
try {
    $stmt = $pdo->prepare("SELECT e.*, c.tradename, c.clientname
        FROM client_expenses e
        LEFT JOIN bucket_client c ON c.accountnum = e.accountnum
        " . $where_sql . "
        ORDER BY e.expense_date DESC, e.id DESC");
    $stmt->execute($params);
    $expenses = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Client Expenses Report Error: " . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Summary figures for whatever the filters currently select
// ---------------------------------------------------------------------------
$total_amount = 0;
$accounts_seen = array();
$first_date = '';
$last_date = '';

foreach ($expenses as $x) {
    $total_amount += floatval($x['amount']);
    $accounts_seen[$x['accountnum']] = true;
    if (!empty($x['expense_date'])) {
        if ($first_date === '' || $x['expense_date'] < $first_date) {
            $first_date = $x['expense_date'];
        }
        if ($last_date === '' || $x['expense_date'] > $last_date) {
            $last_date = $x['expense_date'];
        }
    }
}

$total_records = count($expenses);
$total_accounts = count($accounts_seen);
$average_amount = ($total_records > 0) ? ($total_amount / $total_records) : 0;

// Quick ranges for the preset chips. Each one is just a from/to pair, so the
// chips and the date boxes stay the same mechanism.
$today = date('Y-m-d');
$range_presets = array(
    'all'          => array('label' => 'All Time',      'from' => '',                                  'to' => ''),
    'this_month'   => array('label' => 'This Month',    'from' => date('Y-m-01'),                      'to' => date('Y-m-t')),
    'last_30_days' => array('label' => 'Last 30 Days',  'from' => date('Y-m-d', strtotime('-29 days')), 'to' => $today),
    'last_90_days' => array('label' => 'Last 90 Days',  'from' => date('Y-m-d', strtotime('-89 days')), 'to' => $today),
    'this_year'    => array('label' => 'This Year',     'from' => date('Y-01-01'),                     'to' => date('Y-12-31')),
    'last_year'    => array('label' => 'Last Year',     'from' => (intval(date('Y')) - 1) . '-01-01',  'to' => (intval(date('Y')) - 1) . '-12-31')
);

$active_range_label = 'All Time';
if ($f_from !== '' || $f_to !== '') {
    $active_range_label = ($f_from !== '' ? format_date_only($f_from) : 'Start')
        . ' to ' . ($f_to !== '' ? format_date_only($f_to) : 'Today');
    foreach ($range_presets as $pk => $pv) {
        if ($pv['from'] === $f_from && $pv['to'] === $f_to) {
            $active_range_label = $pv['label'];
            break;
        }
    }
}

$active_page = 'analytics';
$page_title = 'Client Expenses';
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
            /* Strip the app chrome so the list prints as a clean report */
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
<body class="bg-slate-50 text-slate-800 antialiased">

<div class="flex min-h-screen">

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col min-w-0">
        <!-- Top Admin Header -->
        <?php include __DIR__ . '/includes/header.php'; ?>

        <main class="p-6 sm:p-8 space-y-6 max-w-7xl w-full mx-auto">

            <!-- Print-only document heading -->
            <div class="print-only mb-4">
                <h1 style="font-size:18px;font-weight:800;margin:0;">RNZ Support Center &mdash; Client Expenses Report</h1>
                <p style="font-size:11px;margin:4px 0 0;">
                    Period: <?php echo sanitize($active_range_label); ?>
                    &nbsp;|&nbsp; Generated: <?php echo format_date(date('Y-m-d H:i:s')); ?>
                    &nbsp;|&nbsp; Records: <?php echo $total_records; ?>
                </p>
            </div>

            <!-- Page Heading -->
            <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-sm print-card">
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                    <div>
                        <div class="inline-flex items-center space-x-2 bg-rose-50 px-3 py-1 rounded-full text-rose-600 text-xs font-bold uppercase tracking-wider mb-2 no-print">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                            <span>Super Admin Only</span>
                        </div>
                        <h2 class="text-xl sm:text-2xl font-extrabold text-slate-900">Client Expenses</h2>
                        <p class="text-xs text-slate-500 mt-1">
                            Every cost recorded against a client account. These are our own servicing costs &mdash;
                            they are never billed to the client and never appear on a Statement of Account.
                        </p>
                    </div>

                    <div class="flex items-center gap-2.5 shrink-0 no-print">
                        <button type="button" onclick="window.print()" class="bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs px-5 py-2.5 rounded-full shadow-sm transition-all active:scale-95 flex items-center space-x-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                            </svg>
                            <span>Print / PDF</span>
                        </button>
                        <a href="analytics.php" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs px-5 py-2.5 rounded-full transition-all active:scale-95 flex items-center space-x-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                            </svg>
                            <span>Back to Analytics</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm no-print space-y-4">
                <form method="GET" action="expenses.php" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Date From</label>
                            <input type="date" name="from" value="<?php echo sanitize($f_from); ?>" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Date To</label>
                            <input type="date" name="to" value="<?php echo sanitize($f_to); ?>" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                        </div>

                        <div class="sm:col-span-2">
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Search</label>
                            <div class="relative">
                                <svg class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                </svg>
                                <input type="text" name="q" value="<?php echo sanitize($f_search); ?>" placeholder="Business name, account #, description or who recorded it..." class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 pl-9 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mr-1">Quick Range:</span>
                            <?php foreach ($range_presets as $pk => $pv): ?>
                                <?php $is_active = ($pv['from'] === $f_from && $pv['to'] === $f_to); ?>
                                <a href="expenses.php?<?php echo http_build_query(array('from' => $pv['from'], 'to' => $pv['to'], 'q' => $f_search)); ?>"
                                   class="px-3 py-1.5 rounded-xl text-[11px] font-bold transition-all <?php echo $is_active ? 'bg-[#EB3E0B] text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?>">
                                    <?php echo sanitize($pv['label']); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <div class="flex items-center gap-2">
                            <?php if ($f_from !== '' || $f_to !== '' || $f_search !== ''): ?>
                                <a href="expenses.php" class="px-4 py-2.5 rounded-full text-xs font-bold text-slate-600 hover:bg-slate-100 transition-colors">Clear</a>
                            <?php endif; ?>
                            <button type="submit" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs px-6 py-2.5 rounded-full shadow-sm transition-all active:scale-95">
                                Apply Filters
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Summary -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white rounded-3xl p-5 border border-slate-200 shadow-sm print-card">
                    <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total Expenses</span>
                    <p class="text-2xl font-extrabold text-rose-600 font-mono mt-1">&#8369;<?php echo number_format($total_amount, 2); ?></p>
                    <p class="text-[11px] text-slate-500 mt-0.5"><?php echo sanitize($active_range_label); ?></p>
                </div>
                <div class="bg-white rounded-3xl p-5 border border-slate-200 shadow-sm print-card">
                    <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider">Records</span>
                    <p class="text-2xl font-extrabold text-slate-900 font-mono mt-1"><?php echo number_format($total_records); ?></p>
                    <p class="text-[11px] text-slate-500 mt-0.5">expense entr<?php echo ($total_records === 1) ? 'y' : 'ies'; ?></p>
                </div>
                <div class="bg-white rounded-3xl p-5 border border-slate-200 shadow-sm print-card">
                    <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider">Accounts</span>
                    <p class="text-2xl font-extrabold text-slate-900 font-mono mt-1"><?php echo number_format($total_accounts); ?></p>
                    <p class="text-[11px] text-slate-500 mt-0.5">client<?php echo ($total_accounts === 1) ? '' : 's'; ?> with costs</p>
                </div>
                <div class="bg-white rounded-3xl p-5 border border-slate-200 shadow-sm print-card">
                    <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider">Average</span>
                    <p class="text-2xl font-extrabold text-slate-900 font-mono mt-1">&#8369;<?php echo number_format($average_amount, 2); ?></p>
                    <p class="text-[11px] text-slate-500 mt-0.5">per entry</p>
                </div>
            </div>

            <!-- Results -->
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden print-card">
                <div class="px-6 py-4 border-b border-slate-100 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-base font-extrabold text-slate-900">
                        Expense Records
                        <span class="text-slate-400 font-bold text-xs">(<?php echo number_format($total_records); ?>)</span>
                    </h3>
                    <?php if ($first_date !== ''): ?>
                        <span class="text-[11px] text-slate-500 font-mono">
                            <?php echo format_date_only($first_date); ?> &mdash; <?php echo format_date_only($last_date); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-100 text-slate-500 font-bold uppercase tracking-wider text-[11px]">
                                <th class="py-3 px-4">Date</th>
                                <th class="py-3 px-4">Account #</th>
                                <th class="py-3 px-4">Business / Trade Name</th>
                                <th class="py-3 px-4">Expense Description</th>
                                <th class="py-3 px-4">Recorded By</th>
                                <th class="py-3 px-4 text-right">Amount (PHP)</th>
                                <th class="py-3 px-4 text-center no-print">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium">
                            <?php if (empty($expenses)): ?>
                                <tr>
                                    <td colspan="7" class="py-10 text-center text-slate-400">
                                        <?php if ($f_search !== '' || $f_from !== '' || $f_to !== ''): ?>
                                            No expenses match these filters. Try a wider date range or a different search term.
                                        <?php else: ?>
                                            No client expenses have been recorded yet.
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($expenses as $x): ?>
                                    <?php
                                    $x_client = !empty($x['tradename']) ? $x['tradename'] : (!empty($x['clientname']) ? $x['clientname'] : 'Acct #' . $x['accountnum']);
                                    ?>
                                    <tr class="hover:bg-slate-50/80 transition-colors">
                                        <td class="py-3 px-4 text-slate-500 whitespace-nowrap font-mono">
                                            <?php echo format_date_only($x['expense_date']); ?>
                                        </td>
                                        <td class="py-3 px-4 font-mono font-bold text-[#EB3E0B] whitespace-nowrap">
                                            #<?php echo sanitize($x['accountnum']); ?>
                                        </td>
                                        <td class="py-3 px-4 font-bold text-slate-900">
                                            <a href="accounts.php?search=<?php echo urlencode($x['accountnum']); ?>&tab=expenses" class="hover:text-[#EB3E0B] transition-colors">
                                                <?php echo sanitize($x_client); ?>
                                            </a>
                                        </td>
                                        <td class="py-3 px-4 text-slate-700 max-w-md">
                                            <?php echo sanitize($x['description']); ?>
                                        </td>
                                        <td class="py-3 px-4 text-slate-600 whitespace-nowrap">
                                            <?php echo !empty($x['recorded_by']) ? sanitize($x['recorded_by']) : '&mdash;'; ?>
                                        </td>
                                        <td class="py-3 px-4 text-right font-mono font-extrabold text-rose-600 whitespace-nowrap">
                                            &#8369;<?php echo number_format(floatval($x['amount']), 2); ?>
                                        </td>
                                        <td class="py-3 px-4 text-center no-print">
                                            <a href="accounts.php?search=<?php echo urlencode($x['accountnum']); ?>&tab=expenses" class="bg-slate-100 hover:bg-[#EB3E0B] text-slate-600 hover:text-white px-2.5 py-1 rounded-xl font-bold text-[11px] transition-all inline-flex items-center gap-1">
                                                <span>Profile</span>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <?php if (!empty($expenses)): ?>
                            <tfoot>
                                <tr class="bg-slate-50 border-t-2 border-slate-200 text-slate-900 font-extrabold">
                                    <td class="py-3 px-4" colspan="5">Total for this view</td>
                                    <td class="py-3 px-4 text-right font-mono text-rose-600">&#8369;<?php echo number_format($total_amount, 2); ?></td>
                                    <td class="py-3 px-4 no-print"></td>
                                </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

        </main>

        <!-- Footer Component -->
        <?php include __DIR__ . '/includes/footer.php'; ?>

    </div>
</div>

</body>
</html>
