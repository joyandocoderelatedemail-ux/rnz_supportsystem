<?php
// Support Center Executive Analytics & Business Intelligence Dashboard (PHP 5.6 Compatible)
// Strictly restricted to Super Admin (Master) accounts
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/inventory_init.php';

// Enforce login
if (!is_tech_logged_in()) {
    require_once __DIR__ . '/login.php';
    exit;
}

// Enforce Super Admin or explicit analytics permission
if (!is_super_admin() && !user_has_page_access('analytics')) {
    header("Location: index.php?msg=error&err_msg=" . urlencode("Access Denied: The Analytics tab is strictly reserved for Super Admin accounts."));
    exit;
}

// Initialize tables if needed
init_inventory_tables();

$pdo = get_db_connection();
if (!$pdo) {
    die("Database connection error.");
}

// ----------------------------------------------------
// 1. Comprehensive Date Range Filter Calculation
// ----------------------------------------------------
$range = isset($_GET['range']) ? trim($_GET['range']) : '';
$custom_start = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$custom_end = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

$start_date = '';
$end_date = '';
$active_range_label = 'All Time (Full Historical Data)';
$is_filtered = false;

// If user submitted custom start or end date directly
if (!empty($custom_start) || !empty($custom_end)) {
    $range = 'custom';
    $start_date = !empty($custom_start) ? $custom_start : '2000-01-01';
    $end_date = !empty($custom_end) ? $custom_end : date('Y-m-d');
    $is_filtered = true;
    $active_range_label = date('M d, Y', strtotime($start_date)) . ' &mdash; ' . date('M d, Y', strtotime($end_date));
} elseif ($range === 'today') {
    $start_date = date('Y-m-d');
    $end_date = date('Y-m-d');
    $is_filtered = true;
    $active_range_label = 'Today (' . date('M d, Y') . ')';
} elseif ($range === 'yesterday') {
    $start_date = date('Y-m-d', strtotime('-1 day'));
    $end_date = date('Y-m-d', strtotime('-1 day'));
    $is_filtered = true;
    $active_range_label = 'Yesterday (' . date('M d, Y', strtotime('-1 day')) . ')';
} elseif ($range === 'last_7_days') {
    $start_date = date('Y-m-d', strtotime('-6 days'));
    $end_date = date('Y-m-d');
    $is_filtered = true;
    $active_range_label = 'Last 7 Days (' . date('M d', strtotime('-6 days')) . ' - ' . date('M d, Y') . ')';
} elseif ($range === 'last_30_days') {
    $start_date = date('Y-m-d', strtotime('-29 days'));
    $end_date = date('Y-m-d');
    $is_filtered = true;
    $active_range_label = 'Last 30 Days (' . date('M d', strtotime('-29 days')) . ' - ' . date('M d, Y') . ')';
} elseif ($range === 'this_month') {
    $start_date = date('Y-m-01');
    $end_date = date('Y-m-t');
    $is_filtered = true;
    $active_range_label = 'This Month (' . date('F Y') . ')';
} elseif ($range === 'last_month') {
    $start_date = date('Y-m-01', strtotime('first day of last month'));
    $end_date = date('Y-m-t', strtotime('last day of last month'));
    $is_filtered = true;
    $active_range_label = 'Last Month (' . date('F Y', strtotime('last month')) . ')';
} elseif ($range === 'this_year') {
    $start_date = date('Y-01-01');
    $end_date = date('Y-12-31');
    $is_filtered = true;
    $active_range_label = 'This Year (' . date('Y') . ')';
} elseif ($range === 'last_year') {
    $last_yr = intval(date('Y')) - 1;
    $start_date = $last_yr . '-01-01';
    $end_date = $last_yr . '-12-31';
    $is_filtered = true;
    $active_range_label = 'Last Year (' . $last_yr . ')';
} else {
    $range = 'all';
    $start_date = '';
    $end_date = '';
    $is_filtered = false;
    $active_range_label = 'All Time (Full Historical Data)';
}

// Helper SQL clauses
$wo_date_sql = "";
$wo_params = array();
$tkt_date_sql = "";
$tkt_params = array();
$ord_date_sql = "";
$ord_params = array();
$tn_date_sql = "";
$tn_params = array();
$diag_date_sql = "";
$diag_params = array();

if ($is_filtered && !empty($start_date) && !empty($end_date)) {
    $wo_date_sql = " WHERE xdate >= :s_date AND xdate <= :e_date ";
    $wo_params = array(':s_date' => $start_date, ':e_date' => $end_date);

    $tkt_date_sql = " WHERE DATE(created_at) >= :s_date AND DATE(created_at) <= :e_date ";
    $tkt_params = array(':s_date' => $start_date, ':e_date' => $end_date);

    $ord_date_sql = " WHERE DATE(created_at) >= :s_date AND DATE(created_at) <= :e_date ";
    $ord_params = array(':s_date' => $start_date, ':e_date' => $end_date);

    $tn_date_sql = " WHERE xdate >= :s_date AND xdate <= :e_date ";
    $tn_params = array(':s_date' => $start_date, ':e_date' => $end_date);

    $diag_date_sql = " WHERE DATE(created_at) >= :s_date AND DATE(created_at) <= :e_date ";
    $diag_params = array(':s_date' => $start_date, ':e_date' => $end_date);
}

// ----------------------------------------------------
// 2. Financial Metrics (Work Orders & Hardware Sales)
// ----------------------------------------------------
$total_revenue = 0.0;
$paid_revenue = 0.0;
$unpaid_revenue = 0.0;
$total_workorders_count = 0;
$paid_workorders_count = 0;
$unpaid_workorders_count = 0;

try {
    $stmt_wo = $pdo->prepare("SELECT 
        COUNT(*) as total_count,
        COALESCE(SUM(amount), 0) as total_amt,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(status)) = 'paid' THEN amount ELSE 0 END), 0) as paid_amt,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(status)) != 'paid' THEN amount ELSE 0 END), 0) as unpaid_amt,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(status)) = 'paid' THEN 1 ELSE 0 END), 0) as paid_cnt,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(status)) != 'paid' THEN 1 ELSE 0 END), 0) as unpaid_cnt
        FROM bucket_workorder" . $wo_date_sql);
    $stmt_wo->execute($wo_params);
    $row_wo = $stmt_wo->fetch(PDO::FETCH_ASSOC);
    if ($row_wo) {
        $total_workorders_count = intval($row_wo['total_count']);
        $total_revenue = floatval($row_wo['total_amt']);
        $paid_revenue = floatval($row_wo['paid_amt']);
        $unpaid_revenue = floatval($row_wo['unpaid_amt']);
        $paid_workorders_count = intval($row_wo['paid_cnt']);
        $unpaid_workorders_count = intval($row_wo['unpaid_cnt']);
    }
} catch (PDOException $e) {
    error_log("Analytics WO Query Error: " . $e->getMessage());
}

$paid_percentage = ($total_revenue > 0) ? round(($paid_revenue / $total_revenue) * 100, 1) : 0;

// Hardware Orders Metrics
$total_hardware_orders = 0;
$total_hardware_revenue = 0.0;
$pending_hardware_orders = 0;
$fulfilled_hardware_orders = 0;

try {
    $stmt_ord = $pdo->prepare("SELECT 
        COUNT(*) as total_cnt,
        COALESCE(SUM(total_amount), 0) as total_val,
        COALESCE(SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END), 0) as pending_cnt,
        COALESCE(SUM(CASE WHEN status = 'Fulfilled' THEN 1 ELSE 0 END), 0) as fulfilled_cnt
        FROM client_hardware_orders" . $ord_date_sql);
    $stmt_ord->execute($ord_params);
    $row_ord = $stmt_ord->fetch(PDO::FETCH_ASSOC);
    if ($row_ord) {
        $total_hardware_orders = intval($row_ord['total_cnt']);
        $total_hardware_revenue = floatval($row_ord['total_val']);
        $pending_hardware_orders = intval($row_ord['pending_cnt']);
        $fulfilled_hardware_orders = intval($row_ord['fulfilled_cnt']);
    }
} catch (PDOException $e) {
    error_log("Analytics Orders Query Error: " . $e->getMessage());
}

// ----------------------------------------------------
// 3. Support Ticket KPI Metrics
// ----------------------------------------------------
$total_tickets = 0;
$pending_tickets = 0;
$in_progress_tickets = 0;
$resolved_tickets = 0;
$urgent_tickets = 0;

try {
    $stmt_tkt = $pdo->prepare("SELECT 
        COUNT(*) as total_cnt,
        COALESCE(SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END), 0) as pending_cnt,
        COALESCE(SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END), 0) as in_prog_cnt,
        COALESCE(SUM(CASE WHEN status IN ('Resolved', 'Closed') THEN 1 ELSE 0 END), 0) as resolved_cnt,
        COALESCE(SUM(CASE WHEN priority = 'Urgent' THEN 1 ELSE 0 END), 0) as urgent_cnt
        FROM client_support_tickets" . $tkt_date_sql);
    $stmt_tkt->execute($tkt_params);
    $row_tkt = $stmt_tkt->fetch(PDO::FETCH_ASSOC);
    if ($row_tkt) {
        $total_tickets = intval($row_tkt['total_cnt']);
        $pending_tickets = intval($row_tkt['pending_cnt']);
        $in_progress_tickets = intval($row_tkt['in_prog_cnt']);
        $resolved_tickets = intval($row_tkt['resolved_cnt']);
        $urgent_tickets = intval($row_tkt['urgent_cnt']);
    }
} catch (PDOException $e) {
    error_log("Analytics Tickets Query Error: " . $e->getMessage());
}

$resolution_rate = ($total_tickets > 0) ? round(($resolved_tickets / $total_tickets) * 100, 1) : 100;

// ----------------------------------------------------
// 4. Operations, Field Notes & Clients
// ----------------------------------------------------
$total_technotes = 0;
try {
    $stmt_tn = $pdo->prepare("SELECT COUNT(*) FROM bucket_technotes" . $tn_date_sql);
    $stmt_tn->execute($tn_params);
    $total_technotes = intval($stmt_tn->fetchColumn());
} catch (PDOException $e) {}

$total_diag_logs = 0;
try {
    $stmt_diag = $pdo->prepare("SELECT COUNT(*) FROM hardware_troubleshooting_logs" . $diag_date_sql);
    $stmt_diag->execute($diag_params);
    $total_diag_logs = intval($stmt_diag->fetchColumn());
} catch (PDOException $e) {}

$total_clients = 0;
$active_warranty_clients = 0;
try {
    $stmt_cl = $pdo->query("SELECT 
        COUNT(*) as total_cnt,
        COALESCE(SUM(CASE WHEN LOWER(TRIM(warranty_status)) = 'active' THEN 1 ELSE 0 END), 0) as active_war
        FROM bucket_client");
    if ($stmt_cl) {
        $row_cl = $stmt_cl->fetch(PDO::FETCH_ASSOC);
        $total_clients = intval($row_cl['total_cnt']);
        $active_warranty_clients = intval($row_cl['active_war']);
    }
} catch (PDOException $e) {}

// Inventory Health
$total_inventory_items = 0;
$total_stock_units = 0;
$low_stock_count = 0;
$out_of_stock_count = 0;
try {
    $stmt_inv = $pdo->query("SELECT 
        COUNT(*) as total_items,
        COALESCE(SUM(quantity), 0) as total_units,
        COALESCE(SUM(CASE WHEN quantity <= 0 THEN 1 ELSE 0 END), 0) as out_of_stock,
        COALESCE(SUM(CASE WHEN quantity > 0 AND quantity <= min_threshold THEN 1 ELSE 0 END), 0) as low_stock
        FROM support_inventory_items");
    if ($stmt_inv) {
        $row_inv = $stmt_inv->fetch(PDO::FETCH_ASSOC);
        $total_inventory_items = intval($row_inv['total_items']);
        $total_stock_units = intval($row_inv['total_units']);
        $out_of_stock_count = intval($row_inv['out_of_stock']);
        $low_stock_count = intval($row_inv['low_stock']);
    }
} catch (PDOException $e) {}

// ----------------------------------------------------
// 5. Chart Data Generation (Monthly Breakdown)
// ----------------------------------------------------
$monthly_rev_labels = array();
$monthly_rev_data = array();
$monthly_wo_count = array();

try {
    $chart_where = " WHERE xdate IS NOT NULL AND xdate != '' AND xdate != '0000-00-00' ";
    $chart_params = array();
    if ($is_filtered && !empty($start_date) && !empty($end_date)) {
        $chart_where .= " AND xdate >= :c_sdate AND xdate <= :c_edate ";
        $chart_params = array(':c_sdate' => $start_date, ':c_edate' => $end_date);
    }

    $stmt_mrev = $pdo->prepare("SELECT 
        DATE_FORMAT(xdate, '%Y-%m') as ym,
        DATE_FORMAT(xdate, '%b %Y') as m_label,
        COUNT(*) as wo_cnt,
        COALESCE(SUM(amount), 0) as rev_sum
        FROM bucket_workorder 
        " . $chart_where . "
        GROUP BY ym 
        ORDER BY ym DESC 
        LIMIT 12");
    $stmt_mrev->execute($chart_params);
    $raw_mrev = $stmt_mrev ? $stmt_mrev->fetchAll(PDO::FETCH_ASSOC) : array();
    $raw_mrev = array_reverse($raw_mrev);
    foreach ($raw_mrev as $item) {
        $monthly_rev_labels[] = $item['m_label'];
        $monthly_rev_data[] = floatval($item['rev_sum']);
        $monthly_wo_count[] = intval($item['wo_cnt']);
    }
} catch (PDOException $e) {}

if (empty($monthly_rev_labels)) {
    $monthly_rev_labels = array(!empty($start_date) ? date('M Y', strtotime($start_date)) : date('M Y'));
    $monthly_rev_data = array(0);
    $monthly_wo_count = array(0);
}

// Tickets by Category Distribution
$tkt_categories = array();
$tkt_category_counts = array();
try {
    $tcat_where = ($is_filtered && !empty($start_date) && !empty($end_date)) ? " WHERE DATE(created_at) >= :s_date AND DATE(created_at) <= :e_date " : "";
    $stmt_tcat = $pdo->prepare("SELECT 
        COALESCE(category, 'General Support') as cat_name, 
        COUNT(*) as cat_count 
        FROM client_support_tickets 
        " . $tcat_where . "
        GROUP BY cat_name 
        ORDER BY cat_count DESC 
        LIMIT 6");
    $stmt_tcat->execute($tkt_params);
    $raw_tcat = $stmt_tcat ? $stmt_tcat->fetchAll(PDO::FETCH_ASSOC) : array();
    foreach ($raw_tcat as $tc) {
        $tkt_categories[] = !empty($tc['cat_name']) ? $tc['cat_name'] : 'General Support';
        $tkt_category_counts[] = intval($tc['cat_count']);
    }
} catch (PDOException $e) {}

if (empty($tkt_categories)) {
    $tkt_categories = array('Hardware Issue', 'Software Support', 'POS Maintenance');
    $tkt_category_counts = array(0, 0, 0);
}

// Top 5 Technicians by Field Visits (Within Filter Period)
$top_technicians = array();
try {
    $tech_where = " WHERE techname IS NOT NULL AND TRIM(techname) != '' ";
    $tech_p = array();
    if ($is_filtered && !empty($start_date) && !empty($end_date)) {
        $tech_where .= " AND xdate >= :s_date AND xdate <= :e_date ";
        $tech_p = array(':s_date' => $start_date, ':e_date' => $end_date);
    }
    $stmt_top_tech = $pdo->prepare("SELECT 
        TRIM(techname) as tech, 
        COUNT(*) as visits_count 
        FROM bucket_technotes 
        " . $tech_where . "
        GROUP BY tech 
        ORDER BY visits_count DESC 
        LIMIT 6");
    $stmt_top_tech->execute($tech_p);
    $top_technicians = $stmt_top_tech ? $stmt_top_tech->fetchAll(PDO::FETCH_ASSOC) : array();
} catch (PDOException $e) {}

// Top Hardware Devices Diagnosed
$device_labels = array();
$device_counts = array();
try {
    $dev_where = " WHERE hardware_selected IS NOT NULL AND TRIM(hardware_selected) != '' ";
    $dev_p = array();
    if ($is_filtered && !empty($start_date) && !empty($end_date)) {
        $dev_where .= " AND DATE(created_at) >= :s_date AND DATE(created_at) <= :e_date ";
        $dev_p = array(':s_date' => $start_date, ':e_date' => $end_date);
    }
    $stmt_dev = $pdo->prepare("SELECT 
        hardware_selected, 
        COUNT(*) as diag_count 
        FROM hardware_troubleshooting_logs 
        " . $dev_where . "
        GROUP BY hardware_selected 
        ORDER BY diag_count DESC 
        LIMIT 5");
    $stmt_dev->execute($dev_p);
    $raw_dev = $stmt_dev ? $stmt_dev->fetchAll(PDO::FETCH_ASSOC) : array();
    foreach ($raw_dev as $d) {
        $device_labels[] = $d['hardware_selected'];
        $device_counts[] = intval($d['diag_count']);
    }
} catch (PDOException $e) {}

if (empty($device_labels)) {
    $device_labels = array('Thermal Printer', 'POS Terminal', 'Barcode Scanner');
    $device_counts = array(0, 0, 0);
}

// ----------------------------------------------------
// 6. High-Value Client Accounts Leaderboard
// ----------------------------------------------------
$top_clients = array();
try {
    $cl_where = ($is_filtered && !empty($start_date) && !empty($end_date)) ? " WHERE w.xdate >= :s_date AND w.xdate <= :e_date " : "";
    $stmt_top_cl = $pdo->prepare("SELECT 
        c.accountnum,
        c.tradename,
        c.clientname,
        c.warranty_status,
        COUNT(w.id) as wo_count,
        COALESCE(SUM(w.amount), 0) as total_billed
        FROM bucket_client c
        INNER JOIN bucket_workorder w ON c.accountnum = w.accountnum
        " . $cl_where . "
        GROUP BY c.accountnum, c.tradename, c.clientname, c.warranty_status
        ORDER BY total_billed DESC
        LIMIT 8");
    $stmt_top_cl->execute($wo_params);
    $top_clients = $stmt_top_cl ? $stmt_top_cl->fetchAll(PDO::FETCH_ASSOC) : array();
} catch (PDOException $e) {}

// ----------------------------------------------------
// 7. Recent Financial Work Orders
// ----------------------------------------------------
$recent_workorders = array();
try {
    $stmt_rwo = $pdo->prepare("SELECT w.*, c.tradename, c.clientname as cl_owner 
        FROM bucket_workorder w 
        LEFT JOIN bucket_client c ON w.accountnum = c.accountnum " . $wo_date_sql . "
        ORDER BY w.xdate DESC, w.id DESC 
        LIMIT 10");
    $stmt_rwo->execute($wo_params);
    $recent_workorders = $stmt_rwo ? $stmt_rwo->fetchAll(PDO::FETCH_ASSOC) : array();
} catch (PDOException $e) {}

$active_page = 'analytics';
$page_title = 'Executive Analytics & BI';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Executive Analytics &amp; BI - RNZ Support Center</title>
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
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <!-- Chart.js CDN for Interactive High-Def Visualizations -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        .font-mono {
            font-family: 'JetBrains Mono', monospace;
        }
        input[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(1);
            cursor: pointer;
        }
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background: #ffffff !important;
                color: #000000 !important;
            }
            .print-card {
                box-shadow: none !important;
                border: 1px solid #e2e8f0 !important;
                break-inside: avoid;
            }
        }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 flex min-h-screen antialiased">

    <!-- Sidebar Component -->
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- Main Content Wrapper -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
        
        <!-- Header Component -->
        <?php include __DIR__ . '/includes/header.php'; ?>

        <!-- Main Analytics View -->
        <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 space-y-6 pb-24 md:pb-12 max-w-7xl mx-auto w-full">
            
            <!-- ========================================================================= -->
            <!-- 1. EXECUTIVE HEADER BANNER -->
            <!-- ========================================================================= -->
            <div class="bg-slate-900 border border-slate-800 rounded-3xl p-5 sm:p-6 shadow-xl relative overflow-hidden">
                <div class="absolute top-0 right-0 -mt-8 -mr-8 w-64 h-64 bg-[#EB3E0B]/10 rounded-full blur-3xl pointer-events-none"></div>
                <div class="absolute bottom-0 left-1/3 -mb-12 w-64 h-64 bg-indigo-500/10 rounded-full blur-3xl pointer-events-none"></div>

                <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-5 relative z-10">
                    
                    <!-- Title & Super Admin Badge -->
                    <div class="space-y-1.5">
                        <div class="flex items-center space-x-2.5">
                            <span class="px-2.5 py-1 rounded-full text-[10px] font-mono font-bold bg-[#EB3E0B]/20 text-[#FEAA73] border border-[#EB3E0B]/40 flex items-center gap-1.5 uppercase tracking-wider">
                                <svg class="w-3.5 h-3.5 text-[#EB3E0B]" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 2a1 1 0 011 1v1.323l3.954 1.582 1.599-.8a1 1 0 011.334 1.335l-.8 1.6 1.582 3.953H18a1 1 0 110 2h-1.323l-1.582 3.954.8 1.599a1 1 0 01-1.335 1.334l-1.6-.8-3.953 1.582V18a1 1 0 11-2 0v-1.323l-3.954-1.582-1.599.8a1 1 0 01-1.334-1.335l.8-1.6-1.582-3.953H2a1 1 0 110-2h1.323l1.582-3.954-.8-1.599a1 1 0 011.335-1.334l1.6.8 3.953-1.582V2a1 1 0 011-1zm0 5a3 3 0 100 6 3 3 0 000-6z" clip-rule="evenodd"/>
                                </svg>
                                Super Admin Intelligence
                            </span>
                            <span class="text-xs text-slate-400 font-mono">Executive Metrics &amp; Audit Logs</span>
                        </div>
                        <h1 class="text-xl sm:text-2xl lg:text-3xl font-extrabold text-white tracking-tight flex items-center gap-3">
                            <span>Executive Business Analytics</span>
                        </h1>
                        <p class="text-xs sm:text-sm text-slate-400 max-w-2xl leading-relaxed">
                            Financial performance, work order billing, staff field productivity, customer ticket resolution velocity, and asset diagnostics.
                        </p>
                    </div>

                    <!-- Print / Export Action -->
                    <div class="flex items-center space-x-2 shrink-0">
                        <button type="button" onclick="window.print()" class="no-print bg-slate-800 hover:bg-slate-700 active:scale-95 text-slate-200 hover:text-white px-4 py-2.5 rounded-2xl border border-slate-700 text-xs font-bold transition-all flex items-center space-x-2 shadow-sm" title="Print Executive Summary">
                            <svg class="w-4 h-4 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                            </svg>
                            <span>Print Report</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- ========================================================================= -->
            <!-- 2. INTERACTIVE DATE RANGE PICKER & PRESET FILTER BAR -->
            <!-- ========================================================================= -->
            <div class="bg-slate-900/90 border border-slate-800 rounded-3xl p-4 sm:p-5 shadow-lg space-y-4">
                
                <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                    
                    <!-- Quick Presets -->
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-mono font-bold uppercase tracking-wider text-slate-400 block">
                            Quick Range Presets:
                        </label>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <a href="analytics.php?range=all" class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all <?php echo ($range === 'all') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/30' : 'bg-slate-950 text-slate-400 hover:text-white hover:bg-slate-800 border border-slate-800'; ?>">
                                All Time
                            </a>
                            <a href="analytics.php?range=today" class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all <?php echo ($range === 'today') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/30' : 'bg-slate-950 text-slate-400 hover:text-white hover:bg-slate-800 border border-slate-800'; ?>">
                                Today
                            </a>
                            <a href="analytics.php?range=yesterday" class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all <?php echo ($range === 'yesterday') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/30' : 'bg-slate-950 text-slate-400 hover:text-white hover:bg-slate-800 border border-slate-800'; ?>">
                                Yesterday
                            </a>
                            <a href="analytics.php?range=last_7_days" class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all <?php echo ($range === 'last_7_days') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/30' : 'bg-slate-950 text-slate-400 hover:text-white hover:bg-slate-800 border border-slate-800'; ?>">
                                Last 7 Days
                            </a>
                            <a href="analytics.php?range=last_30_days" class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all <?php echo ($range === 'last_30_days') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/30' : 'bg-slate-950 text-slate-400 hover:text-white hover:bg-slate-800 border border-slate-800'; ?>">
                                Last 30 Days
                            </a>
                            <a href="analytics.php?range=this_month" class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all <?php echo ($range === 'this_month') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/30' : 'bg-slate-950 text-slate-400 hover:text-white hover:bg-slate-800 border border-slate-800'; ?>">
                                This Month
                            </a>
                            <a href="analytics.php?range=last_month" class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all <?php echo ($range === 'last_month') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/30' : 'bg-slate-950 text-slate-400 hover:text-white hover:bg-slate-800 border border-slate-800'; ?>">
                                Last Month
                            </a>
                            <a href="analytics.php?range=this_year" class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all <?php echo ($range === 'this_year') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/30' : 'bg-slate-950 text-slate-400 hover:text-white hover:bg-slate-800 border border-slate-800'; ?>">
                                This Year
                            </a>
                            <a href="analytics.php?range=last_year" class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all <?php echo ($range === 'last_year') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/30' : 'bg-slate-950 text-slate-400 hover:text-white hover:bg-slate-800 border border-slate-800'; ?>">
                                Last Year
                            </a>
                        </div>
                    </div>

                    <!-- Custom Date Picker Form -->
                    <form method="GET" action="analytics.php" class="bg-slate-950 p-2.5 rounded-2xl border border-slate-800 flex flex-wrap items-center gap-2">
                        <input type="hidden" name="range" value="custom">
                        
                        <div class="flex items-center space-x-2">
                            <div>
                                <label class="text-[9px] font-bold text-slate-400 uppercase block pl-1">From Date</label>
                                <input type="date" name="start_date" value="<?php echo !empty($start_date) ? htmlspecialchars($start_date) : ''; ?>" required class="bg-slate-900 border border-slate-700 text-white text-xs rounded-xl px-2.5 py-1.5 focus:outline-none focus:border-[#EB3E0B] font-mono">
                            </div>
                            <span class="text-slate-500 text-xs self-end pb-2 font-bold">&rarr;</span>
                            <div>
                                <label class="text-[9px] font-bold text-slate-400 uppercase block pl-1">To Date</label>
                                <input type="date" name="end_date" value="<?php echo !empty($end_date) ? htmlspecialchars($end_date) : date('Y-m-d'); ?>" required class="bg-slate-900 border border-slate-700 text-white text-xs rounded-xl px-2.5 py-1.5 focus:outline-none focus:border-[#EB3E0B] font-mono">
                            </div>
                        </div>

                        <div class="flex items-center space-x-1.5 self-end">
                            <button type="submit" class="bg-[#EB3E0B] hover:bg-[#C32C0B] active:scale-95 text-white font-bold text-xs px-3.5 py-2 rounded-xl transition-all shadow-sm flex items-center space-x-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                                </svg>
                                <span>Apply Filter</span>
                            </button>
                            <?php if ($is_filtered): ?>
                                <a href="analytics.php?range=all" class="bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white font-bold text-xs px-2.5 py-2 rounded-xl transition-all" title="Clear Date Filter">
                                    Reset
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>

                </div>

                <!-- Active Range Indicator -->
                <div class="pt-3 border-t border-slate-800/80 flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs">
                    <div class="flex items-center space-x-2 text-slate-300">
                        <span class="w-2 h-2 rounded-full <?php echo $is_filtered ? 'bg-emerald-400 animate-pulse' : 'bg-slate-500'; ?>"></span>
                        <span class="text-slate-400">Current Scope:</span>
                        <strong class="text-white font-semibold font-mono"><?php echo $active_range_label; ?></strong>
                    </div>
                    <?php if ($is_filtered): ?>
                        <div class="text-slate-400 text-[11px] flex items-center gap-1.5">
                            <span>Showing results matching selected date window.</span>
                            <a href="analytics.php?range=all" class="text-[#FEAA73] hover:underline font-bold">Clear filter &rarr;</a>
                        </div>
                    <?php else: ?>
                        <span class="text-slate-500 text-[11px]">All historical work orders, tickets, and field visits included.</span>
                    <?php endif; ?>
                </div>

            </div>

            <!-- ========================================================================= -->
            <!-- 3. TOP TIER EXECUTIVE KPI STAT CARDS (6 Key Pillars) -->
            <!-- ========================================================================= -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
                
                <!-- Card 1: Total Billed Revenue -->
                <div class="bg-slate-900 border border-slate-800 rounded-3xl p-4 sm:p-5 shadow-lg flex flex-col justify-between space-y-3 relative overflow-hidden group hover:border-[#EB3E0B]/50 transition-colors">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Total Billed</span>
                        <div class="w-8 h-8 rounded-xl bg-emerald-500/10 text-emerald-400 flex items-center justify-center">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                    </div>
                    <div>
                        <div class="font-mono text-xl sm:text-2xl font-black text-white tracking-tight">
                            &#8369;<?php echo number_format($total_revenue, 2); ?>
                        </div>
                        <div class="flex items-center justify-between text-[11px] text-slate-400 mt-1">
                            <span><?php echo $total_workorders_count; ?> work orders</span>
                            <span class="text-emerald-400 font-bold"><?php echo $paid_percentage; ?>% Paid</span>
                        </div>
                    </div>
                    <div class="w-full bg-slate-800 h-1.5 rounded-full overflow-hidden">
                        <div class="bg-emerald-500 h-full rounded-full" style="width: <?php echo min(100, $paid_percentage); ?>%"></div>
                    </div>
                </div>

                <!-- Card 2: Hardware Orders Volume -->
                <div class="bg-slate-900 border border-slate-800 rounded-3xl p-4 sm:p-5 shadow-lg flex flex-col justify-between space-y-3 relative overflow-hidden group hover:border-[#EB3E0B]/50 transition-colors">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Hardware Sales</span>
                        <div class="w-8 h-8 rounded-xl bg-indigo-500/10 text-indigo-400 flex items-center justify-center">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                            </svg>
                        </div>
                    </div>
                    <div>
                        <div class="font-mono text-xl sm:text-2xl font-black text-white tracking-tight">
                            &#8369;<?php echo number_format($total_hardware_revenue, 2); ?>
                        </div>
                        <div class="flex items-center justify-between text-[11px] text-slate-400 mt-1">
                            <span><?php echo $total_hardware_orders; ?> client orders</span>
                            <span class="text-indigo-400 font-bold"><?php echo $pending_hardware_orders; ?> pending</span>
                        </div>
                    </div>
                    <div class="w-full bg-slate-800 h-1.5 rounded-full overflow-hidden">
                        <div class="bg-indigo-500 h-full rounded-full" style="width: <?php echo ($total_hardware_orders > 0) ? round(($fulfilled_hardware_orders / $total_hardware_orders) * 100) : 0; ?>%"></div>
                    </div>
                </div>

                <!-- Card 3: Ticket Resolution Rate -->
                <div class="bg-slate-900 border border-slate-800 rounded-3xl p-4 sm:p-5 shadow-lg flex flex-col justify-between space-y-3 relative overflow-hidden group hover:border-[#EB3E0B]/50 transition-colors">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Ticket Resolution</span>
                        <div class="w-8 h-8 rounded-xl bg-[#EB3E0B]/10 text-[#FEAA73] flex items-center justify-center">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                    </div>
                    <div>
                        <div class="font-mono text-xl sm:text-2xl font-black text-white tracking-tight">
                            <?php echo $resolution_rate; ?>%
                        </div>
                        <div class="flex items-center justify-between text-[11px] text-slate-400 mt-1">
                            <span><?php echo $total_tickets; ?> total tickets</span>
                            <span class="text-amber-400 font-bold"><?php echo $pending_tickets + $in_progress_tickets; ?> active</span>
                        </div>
                    </div>
                    <div class="w-full bg-slate-800 h-1.5 rounded-full overflow-hidden">
                        <div class="bg-[#EB3E0B] h-full rounded-full" style="width: <?php echo $resolution_rate; ?>%"></div>
                    </div>
                </div>

                <!-- Card 4: Field Operations / Tech Notes -->
                <div class="bg-slate-900 border border-slate-800 rounded-3xl p-4 sm:p-5 shadow-lg flex flex-col justify-between space-y-3 relative overflow-hidden group hover:border-[#EB3E0B]/50 transition-colors">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Field Visits</span>
                        <div class="w-8 h-8 rounded-xl bg-cyan-500/10 text-cyan-400 flex items-center justify-center">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5m0 0h4m-4 0V11m0 0h4m-4 0H9m4 0V7m0 0h4m-4 0H9"/>
                            </svg>
                        </div>
                    </div>
                    <div>
                        <div class="font-mono text-xl sm:text-2xl font-black text-white tracking-tight">
                            <?php echo number_format($total_technotes); ?>
                        </div>
                        <div class="flex items-center justify-between text-[11px] text-slate-400 mt-1">
                            <span>Service reports logged</span>
                            <span class="text-cyan-400 font-bold"><?php echo $total_diag_logs; ?> diags</span>
                        </div>
                    </div>
                    <div class="w-full bg-slate-800 h-1.5 rounded-full overflow-hidden">
                        <div class="bg-cyan-500 h-full rounded-full w-full"></div>
                    </div>
                </div>

                <!-- Card 5: Registered Clients & Warranties -->
                <div class="bg-slate-900 border border-slate-800 rounded-3xl p-4 sm:p-5 shadow-lg flex flex-col justify-between space-y-3 relative overflow-hidden group hover:border-[#EB3E0B]/50 transition-colors">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Client Coverage</span>
                        <div class="w-8 h-8 rounded-xl bg-purple-500/10 text-purple-400 flex items-center justify-center">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                            </svg>
                        </div>
                    </div>
                    <div>
                        <div class="font-mono text-xl sm:text-2xl font-black text-white tracking-tight">
                            <?php echo number_format($total_clients); ?>
                        </div>
                        <div class="flex items-center justify-between text-[11px] text-slate-400 mt-1">
                            <span>Total accounts</span>
                            <span class="text-purple-400 font-bold"><?php echo $active_warranty_clients; ?> warranty</span>
                        </div>
                    </div>
                    <div class="w-full bg-slate-800 h-1.5 rounded-full overflow-hidden">
                        <div class="bg-purple-500 h-full rounded-full" style="width: <?php echo ($total_clients > 0) ? round(($active_warranty_clients / $total_clients) * 100) : 0; ?>%"></div>
                    </div>
                </div>

                <!-- Card 6: Inventory Stock Health -->
                <div class="bg-slate-900 border border-slate-800 rounded-3xl p-4 sm:p-5 shadow-lg flex flex-col justify-between space-y-3 relative overflow-hidden group hover:border-[#EB3E0B]/50 transition-colors">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Inventory Health</span>
                        <div class="w-8 h-8 rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                            </svg>
                        </div>
                    </div>
                    <div>
                        <div class="font-mono text-xl sm:text-2xl font-black text-white tracking-tight">
                            <?php echo number_format($total_stock_units); ?>
                        </div>
                        <div class="flex items-center justify-between text-[11px] text-slate-400 mt-1">
                            <span><?php echo $total_inventory_items; ?> SKU items</span>
                            <span class="text-amber-400 font-bold"><?php echo $low_stock_count + $out_of_stock_count; ?> alerts</span>
                        </div>
                    </div>
                    <div class="w-full bg-slate-800 h-1.5 rounded-full overflow-hidden">
                        <div class="bg-amber-500 h-full rounded-full" style="width: <?php echo ($low_stock_count > 0) ? '65' : '100'; ?>%"></div>
                    </div>
                </div>

            </div>

            <!-- ========================================================================= -->
            <!-- 4. PRIMARY FINANCIAL & REVENUE CHARTS (Row 1) -->
            <!-- ========================================================================= -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <!-- Left: Revenue & Work Order Trend (Area / Line Chart) -->
                <div class="lg:col-span-2 bg-slate-900 border border-slate-800 rounded-3xl p-5 sm:p-6 shadow-xl space-y-4 print-card">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-800 pb-3">
                        <div>
                            <h2 class="text-base font-extrabold text-white flex items-center gap-2">
                                <span class="w-2.5 h-2.5 rounded-full bg-[#EB3E0B]"></span>
                                <span>Monthly Revenue &amp; Work Order Billing Trend</span>
                            </h2>
                            <p class="text-xs text-slate-400">Track billed service fees and client maintenance totals over time</p>
                        </div>
                        <div class="flex items-center space-x-3 text-xs font-mono">
                            <span class="flex items-center gap-1.5 text-slate-300">
                                <span class="w-3 h-3 rounded-md bg-[#EB3E0B]"></span> Revenue (PHP)
                            </span>
                        </div>
                    </div>

                    <div class="relative h-72 sm:h-80 w-full">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>

                <!-- Right: Payment & Collection Status Ratio (Doughnut Chart) -->
                <div class="bg-slate-900 border border-slate-800 rounded-3xl p-5 sm:p-6 shadow-xl space-y-4 flex flex-col justify-between print-card">
                    <div class="border-b border-slate-800 pb-3">
                        <h2 class="text-base font-extrabold text-white flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                            <span>Payment &amp; Collection Health</span>
                        </h2>
                        <p class="text-xs text-slate-400">Paid settlements vs pending receivables</p>
                    </div>

                    <div class="relative h-52 flex items-center justify-center my-auto">
                        <canvas id="paymentStatusChart"></canvas>
                    </div>

                    <div class="grid grid-cols-2 gap-3 pt-3 border-t border-slate-800 text-xs">
                        <div class="p-3 bg-slate-950/60 rounded-2xl border border-slate-800">
                            <span class="text-slate-400 block text-[10px] uppercase font-bold">Settled Paid</span>
                            <span class="font-mono font-black text-emerald-400 text-sm">&#8369;<?php echo number_format($paid_revenue, 2); ?></span>
                            <span class="block text-[10px] text-slate-500 mt-0.5"><?php echo $paid_workorders_count; ?> work orders</span>
                        </div>
                        <div class="p-3 bg-slate-950/60 rounded-2xl border border-slate-800">
                            <span class="text-slate-400 block text-[10px] uppercase font-bold">Pending / Unpaid</span>
                            <span class="font-mono font-black text-amber-400 text-sm">&#8369;<?php echo number_format($unpaid_revenue, 2); ?></span>
                            <span class="block text-[10px] text-slate-500 mt-0.5"><?php echo $unpaid_workorders_count; ?> work orders</span>
                        </div>
                    </div>
                </div>

            </div>

            <!-- ========================================================================= -->
            <!-- 5. SUPPORT & OPERATIONS BREAKDOWN (Row 2) -->
            <!-- ========================================================================= -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                
                <!-- Chart 3: Support Tickets by Category -->
                <div class="bg-slate-900 border border-slate-800 rounded-3xl p-5 sm:p-6 shadow-xl space-y-4 print-card">
                    <div class="border-b border-slate-800 pb-3">
                        <h2 class="text-base font-extrabold text-white flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-indigo-500"></span>
                            <span>Tickets by Category</span>
                        </h2>
                        <p class="text-xs text-slate-400">Distribution of customer issue classifications</p>
                    </div>

                    <div class="relative h-56 flex items-center justify-center">
                        <canvas id="ticketCategoryChart"></canvas>
                    </div>
                </div>

                <!-- Chart 4: Hardware Devices Diagnosed -->
                <div class="bg-slate-900 border border-slate-800 rounded-3xl p-5 sm:p-6 shadow-xl space-y-4 print-card">
                    <div class="border-b border-slate-800 pb-3">
                        <h2 class="text-base font-extrabold text-white flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-cyan-500"></span>
                            <span>Hardware Issues by Device</span>
                        </h2>
                        <p class="text-xs text-slate-400">Most commonly diagnosed POS peripheral units</p>
                    </div>

                    <div class="relative h-56 flex items-center justify-center">
                        <canvas id="hardwareDeviceChart"></canvas>
                    </div>
                </div>

                <!-- Leaderboard: Top Field Technicians -->
                <div class="bg-slate-900 border border-slate-800 rounded-3xl p-5 sm:p-6 shadow-xl space-y-4 flex flex-col justify-between print-card">
                    <div class="border-b border-slate-800 pb-3">
                        <h2 class="text-base font-extrabold text-white flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                            <span>Top Field Technicians</span>
                        </h2>
                        <p class="text-xs text-slate-400">Ranked by total completed service visit logs</p>
                    </div>

                    <div class="space-y-2.5 overflow-y-auto max-h-60 pr-1">
                        <?php if (!empty($top_technicians)): ?>
                            <?php 
                            $max_visits = isset($top_technicians[0]['visits_count']) ? max(1, intval($top_technicians[0]['visits_count'])) : 1;
                            $rank = 1;
                            ?>
                            <?php foreach ($top_technicians as $t): ?>
                                <?php 
                                $pct = round((intval($t['visits_count']) / $max_visits) * 100);
                                ?>
                                <div class="p-2.5 bg-slate-950/60 rounded-2xl border border-slate-800 space-y-1.5">
                                    <div class="flex items-center justify-between text-xs">
                                        <div class="flex items-center space-x-2 truncate">
                                            <span class="w-5 h-5 rounded-full bg-slate-800 font-mono text-[10px] font-bold text-slate-300 flex items-center justify-center shrink-0">
                                                #<?php echo $rank++; ?>
                                            </span>
                                            <span class="font-bold text-white truncate"><?php echo sanitize($t['tech']); ?></span>
                                        </div>
                                        <span class="font-mono font-bold text-[#FEAA73] shrink-0"><?php echo number_format($t['visits_count']); ?> visits</span>
                                    </div>
                                    <div class="w-full bg-slate-800 h-1 rounded-full overflow-hidden">
                                        <div class="bg-amber-500 h-full rounded-full" style="width: <?php echo $pct; ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-slate-500 text-xs text-center py-6">No technician service visits found for this date range.</p>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <!-- ========================================================================= -->
            <!-- 6. TOP BILLED CLIENT ACCOUNTS LEADERBOARD -->
            <!-- ========================================================================= -->
            <div class="bg-slate-900 border border-slate-800 rounded-3xl p-5 sm:p-6 shadow-xl space-y-4 print-card">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-800 pb-3">
                    <div>
                        <h2 class="text-base font-extrabold text-white flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-purple-500"></span>
                            <span>Highest Billed Client Accounts</span>
                        </h2>
                        <p class="text-xs text-slate-400">Top commercial partners by total cumulative service work orders</p>
                    </div>
                    <a href="accounts.php" class="no-print text-xs font-bold text-[#EB3E0B] hover:text-[#FEAA73] flex items-center gap-1 transition-colors">
                        <span>View All Client Accounts</span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </a>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="bg-slate-950/80 text-slate-400 font-bold uppercase text-[10px] border-b border-slate-800">
                                <th class="py-3 px-4">Account #</th>
                                <th class="py-3 px-4">Business / Trade Name</th>
                                <th class="py-3 px-4">Owner / Contact</th>
                                <th class="py-3 px-4 text-center">Work Orders</th>
                                <th class="py-3 px-4 text-center">Warranty Status</th>
                                <th class="py-3 px-4 text-right">Total Billed (PHP)</th>
                                <th class="py-3 px-4 text-center no-print">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60">
                            <?php if (!empty($top_clients)): ?>
                                <?php foreach ($top_clients as $cl): ?>
                                    <tr class="hover:bg-slate-800/40 transition-colors">
                                        <td class="py-3.5 px-4 font-mono font-bold text-[#FEAA73]">
                                            #<?php echo sanitize($cl['accountnum']); ?>
                                        </td>
                                        <td class="py-3.5 px-4 font-bold text-white">
                                            <?php echo sanitize($cl['tradename']); ?>
                                        </td>
                                        <td class="py-3.5 px-4 text-slate-300">
                                            <?php echo sanitize($cl['clientname']); ?>
                                        </td>
                                        <td class="py-3.5 px-4 text-center font-mono font-semibold text-slate-300">
                                            <?php echo intval($cl['wo_count']); ?>
                                        </td>
                                        <td class="py-3.5 px-4 text-center">
                                            <?php if (strtolower(trim($cl['warranty_status'])) === 'active'): ?>
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">Active</span>
                                            <?php else: ?>
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-800 text-slate-400 border border-slate-700">Standard</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3.5 px-4 text-right font-mono font-extrabold text-white text-sm">
                                            &#8369;<?php echo number_format($cl['total_billed'], 2); ?>
                                        </td>
                                        <td class="py-3.5 px-4 text-center no-print">
                                            <a href="accounts.php?search=<?php echo urlencode($cl['accountnum']); ?>" class="bg-slate-800 hover:bg-[#EB3E0B] text-slate-200 hover:text-white px-2.5 py-1 rounded-xl font-bold text-[11px] transition-all inline-flex items-center gap-1">
                                                <span>Profile</span>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="py-8 text-center text-slate-500">No client work order records found for this date range.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ========================================================================= -->
            <!-- 7. RECENT FINANCIAL WORK ORDERS LEDGER -->
            <!-- ========================================================================= -->
            <div class="bg-slate-900 border border-slate-800 rounded-3xl p-5 sm:p-6 shadow-xl space-y-4 print-card">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-800 pb-3">
                    <div>
                        <h2 class="text-base font-extrabold text-white flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                            <span>Work Orders &amp; Service Invoices in Scope</span>
                        </h2>
                        <p class="text-xs text-slate-400">Detailed financial entries with direct print &amp; billing statement generator</p>
                    </div>
                    <a href="accounts.php?tab=orders" class="no-print text-xs font-bold text-[#EB3E0B] hover:text-[#FEAA73] flex items-center gap-1 transition-colors">
                        <span>All Work Orders</span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </a>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="bg-slate-950/80 text-slate-400 font-bold uppercase text-[10px] border-b border-slate-800">
                                <th class="py-3 px-4">WO Ref</th>
                                <th class="py-3 px-4">Date</th>
                                <th class="py-3 px-4">Client Business</th>
                                <th class="py-3 px-4">Scope of Work</th>
                                <th class="py-3 px-4 text-center">Status</th>
                                <th class="py-3 px-4 text-right">Amount (PHP)</th>
                                <th class="py-3 px-4 text-center no-print">Document</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60">
                            <?php if (!empty($recent_workorders)): ?>
                                <?php foreach ($recent_workorders as $wo): ?>
                                    <?php 
                                    $is_wo_paid = (strtolower(trim($wo['status'])) === 'paid');
                                    $cl_name = !empty($wo['tradename']) ? $wo['tradename'] : (!empty($wo['clientname']) ? $wo['clientname'] : 'Acct #' . $wo['accountnum']);
                                    ?>
                                    <tr class="hover:bg-slate-800/40 transition-colors">
                                        <td class="py-3 px-4 font-mono font-bold text-slate-300">
                                            WO-<?php echo str_pad($wo['id'], 6, '0', STR_PAD_LEFT); ?>
                                        </td>
                                        <td class="py-3 px-4 font-mono text-slate-400">
                                            <?php echo format_date_only($wo['xdate']); ?>
                                        </td>
                                        <td class="py-3 px-4 font-bold text-white">
                                            <a href="accounts.php?search=<?php echo urlencode($wo['accountnum']); ?>&tab=orders" class="hover:text-[#EB3E0B] transition-colors">
                                                <?php echo sanitize($cl_name); ?>
                                            </a>
                                        </td>
                                        <td class="py-3 px-4 text-slate-300 max-w-xs truncate">
                                            <?php echo sanitize($wo['natureofwork']); ?>
                                        </td>
                                        <td class="py-3 px-4 text-center">
                                            <?php if ($is_wo_paid): ?>
                                                <span class="inline-flex items-center gap-1 bg-emerald-500/10 text-emerald-400 text-[10px] font-bold px-2 py-0.5 rounded-full border border-emerald-500/30">
                                                    Paid <?php echo !empty($wo['ornum']) ? '&bull; OR #' . sanitize($wo['ornum']) : ''; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1 bg-amber-500/10 text-amber-400 text-[10px] font-bold px-2 py-0.5 rounded-full border border-amber-500/30">
                                                    Pending
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-4 text-right font-mono font-extrabold text-white text-sm">
                                            &#8369;<?php echo number_format(floatval($wo['amount']), 2); ?>
                                        </td>
                                        <td class="py-3 px-4 text-center no-print">
                                            <a href="print_document.php?type=workorder&id=<?php echo $wo['id']; ?>" target="_blank" class="inline-flex items-center space-x-1 bg-slate-800 hover:bg-[#EB3E0B] text-slate-200 hover:text-white px-2.5 py-1 rounded-xl text-[11px] font-bold transition-all shadow-xs" title="Print Work Order Statement">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                                                </svg>
                                                <span>Print</span>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="py-8 text-center text-slate-500">No work orders found for the selected date range.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>

        <!-- Footer Component -->
        <?php include __DIR__ . '/includes/footer.php'; ?>

    </div>

    <!-- ========================================================================= -->
    <!-- 8. CHART.JS INITIALIZATION SCRIPTS -->
    <!-- ========================================================================= -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        Chart.defaults.color = '#94a3b8';
        Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";

        // 1. Revenue & Billing Trend Chart
        var ctxRev = document.getElementById('revenueChart');
        if (ctxRev) {
            new Chart(ctxRev.getContext('2d'), {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($monthly_rev_labels); ?>,
                    datasets: [
                        {
                            label: 'Monthly Revenue (PHP)',
                            data: <?php echo json_encode($monthly_rev_data); ?>,
                            borderColor: '#EB3E0B',
                            backgroundColor: 'rgba(235, 62, 11, 0.12)',
                            fill: true,
                            tension: 0.35,
                            borderWidth: 3,
                            pointBackgroundColor: '#EB3E0B',
                            pointRadius: 4,
                            pointHoverRadius: 7
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return ' Revenue: PHP ' + Number(context.raw).toLocaleString('en-US', { minimumFractionDigits: 2 });
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { color: 'rgba(51, 65, 85, 0.3)' },
                            ticks: { color: '#94a3b8', font: { size: 11 } }
                        },
                        y: {
                            grid: { color: 'rgba(51, 65, 85, 0.3)' },
                            ticks: {
                                color: '#94a3b8',
                                font: { size: 11 },
                                callback: function(value) {
                                    return 'PHP ' + (value >= 1000 ? (value / 1000) + 'k' : value);
                                }
                            }
                        }
                    }
                }
            });
        }

        // 2. Payment & Collections Ratio Doughnut Chart
        var ctxPay = document.getElementById('paymentStatusChart');
        if (ctxPay) {
            new Chart(ctxPay.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['Paid Collections', 'Unpaid / Pending'],
                    datasets: [{
                        data: [<?php echo $paid_revenue; ?>, <?php echo $unpaid_revenue; ?>],
                        backgroundColor: ['#10b981', '#f59e0b'],
                        borderColor: '#0f172a',
                        borderWidth: 4,
                        hoverOffset: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { color: '#cbd5e1', font: { size: 11, weight: 'bold' } }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return ' ' + context.label + ': PHP ' + Number(context.raw).toLocaleString('en-US', { minimumFractionDigits: 2 });
                                }
                            }
                        }
                    },
                    cutout: '72%'
                }
            });
        }

        // 3. Tickets by Category Doughnut Chart
        var ctxTCat = document.getElementById('ticketCategoryChart');
        if (ctxTCat) {
            new Chart(ctxTCat.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode($tkt_categories); ?>,
                    datasets: [{
                        data: <?php echo json_encode($tkt_category_counts); ?>,
                        backgroundColor: ['#6366f1', '#06b6d4', '#f59e0b', '#ec4899', '#10b981', '#8b5cf6'],
                        borderColor: '#0f172a',
                        borderWidth: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { color: '#cbd5e1', font: { size: 10 }, boxWidth: 12 }
                        }
                    },
                    cutout: '65%'
                }
            });
        }

        // 4. Hardware Devices Diagnosed Bar Chart
        var ctxDev = document.getElementById('hardwareDeviceChart');
        if (ctxDev) {
            new Chart(ctxDev.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($device_labels); ?>,
                    datasets: [{
                        label: 'Diagnostic Sessions',
                        data: <?php echo json_encode($device_counts); ?>,
                        backgroundColor: '#06b6d4',
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { color: '#94a3b8', font: { size: 10 } }
                        },
                        y: {
                            grid: { color: 'rgba(51, 65, 85, 0.3)' },
                            ticks: { color: '#94a3b8', font: { size: 10 }, precision: 0 }
                        }
                    }
                }
            });
        }
    });
    </script>
</body>
</html>
