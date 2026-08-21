<?php
// Client Portal Dashboard
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db_init.php';

if (!is_logged_in()) {
    // Serve RNZ Landing Website to public visitors
    if (file_exists(__DIR__ . '/index.html')) {
        readfile(__DIR__ . '/index.html');
        exit;
    }
    require_once __DIR__ . '/login.php';
    exit;
}
$client = get_logged_client();
$accountnum = (is_array($client) && isset($client['accountnum'])) ? $client['accountnum'] : '';

$pdo = get_db_connection();

$total_tickets = 0;
$pending_tickets = 0;
$in_progress_tickets = 0;
$resolved_tickets = 0;
$recent_tickets = array();
$recent_technotes = array();
$recent_workorders = array();

try {
    // 1. Fetch Client Support Tickets metrics & recent items
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total, 
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending_cnt,
        SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) AS in_progress_cnt,
        SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END) AS resolved_cnt
        FROM client_support_tickets WHERE accountnum = :acct");
    $stmt->execute(array(':acct' => $accountnum));
    $ticket_stats = $stmt->fetch();

    $total_tickets = isset($ticket_stats['total']) ? intval($ticket_stats['total']) : 0;
    $pending_tickets = isset($ticket_stats['pending_cnt']) ? intval($ticket_stats['pending_cnt']) : 0;
    $in_progress_tickets = isset($ticket_stats['in_progress_cnt']) ? intval($ticket_stats['in_progress_cnt']) : 0;
    $resolved_tickets = isset($ticket_stats['resolved_cnt']) ? intval($ticket_stats['resolved_cnt']) : 0;

    // Fetch Recent Client Tickets (Limit 5)
    $stmt = $pdo->prepare("SELECT * FROM client_support_tickets WHERE accountnum = :acct ORDER BY id DESC LIMIT 5");
    $stmt->execute(array(':acct' => $accountnum));
    $recent_tickets = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Dashboard tickets query error: " . $e->getMessage());
}

try {
    // 2. Fetch Tech Notes history from bucket_technotes
    $stmt = $pdo->prepare("SELECT * FROM bucket_technotes WHERE accountnum = :acct ORDER BY id DESC LIMIT 4");
    $stmt->execute(array(':acct' => $accountnum));
    $recent_technotes = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Dashboard technotes query error: " . $e->getMessage());
}

try {
    // 3. Fetch Work Orders history from bucket_workorder
    $stmt = $pdo->prepare("SELECT * FROM bucket_workorder WHERE accountnum = :acct ORDER BY id DESC LIMIT 4");
    $stmt->execute(array(':acct' => $accountnum));
    $recent_workorders = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Dashboard workorders query error: " . $e->getMessage());
}

try {
    // 4. Fetch fresh client profile data for live warranty status & details
    if (!empty($accountnum)) {
        $stmt_c_fresh = $pdo->prepare("SELECT * FROM bucket_client WHERE accountnum = :acct LIMIT 1");
        $stmt_c_fresh->execute(array(':acct' => $accountnum));
        $client_fresh = $stmt_c_fresh->fetch();
        if ($client_fresh && is_array($client)) {
            $client = array_merge($client, $client_fresh);
        }
    }
} catch (PDOException $e) {
    error_log("Dashboard client query error: " . $e->getMessage());
}

$has_active_warranty = (is_array($client) && isset($client['warranty_status']) && $client['warranty_status'] === 'Active');

$active_page = 'dashboard';
$page_title = 'Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - RNZ Client Support Portal</title>
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
<body class="bg-[#FFF5ED] text-slate-800 antialiased min-h-screen">

<div class="flex min-h-screen">
    <!-- Sidebar Navigation -->
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col min-w-0">
        <!-- Header -->
        <?php include __DIR__ . '/includes/header.php'; ?>

        <!-- Dashboard Canvas -->
        <main class="p-4 sm:p-6 md:p-8 pb-24 md:pb-8 space-y-6 sm:space-y-8 max-w-7xl w-full mx-auto">

            <!-- Welcome Header Card -->
            <div class="bg-gradient-to-r from-[#430D07] via-[#7C2112] to-[#9A2512] rounded-3xl p-6 sm:p-8 text-white shadow-xl shadow-[#430D07]/15 flex flex-col md:flex-row items-start md:items-center justify-between gap-6 relative overflow-hidden">
                <div class="space-y-2 relative z-10">
                    <div class="flex items-center flex-wrap gap-2">
                        <div class="inline-flex items-center space-x-2 bg-[#FFE8D5]/20 backdrop-blur-md px-3 py-1 rounded-full text-[#FEAA73] text-xs font-semibold border border-[#FEAA73]/20">
                            <span>Account Number: <?php echo sanitize($client['accountnum']); ?></span>
                        </div>
                        <?php if ($has_active_warranty): ?>
                            <div class="inline-flex items-center space-x-1.5 bg-emerald-500/20 backdrop-blur-md px-3 py-1 rounded-full text-emerald-300 text-xs font-bold border border-emerald-400/30">
                                <svg class="w-3.5 h-3.5 text-emerald-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                </svg>
                                <span>Active Warranty</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <h2 class="text-2xl sm:text-3xl font-extrabold tracking-tight text-white">
                        Welcome back, <?php echo sanitize($client['tradename']); ?>!
                    </h2>
                </div>
                <div class="relative z-10 flex flex-wrap gap-2.5">
                    <a href="hardware.php" class="bg-[#EB3E0B] hover:bg-[#FC884D] text-white font-semibold text-xs sm:text-sm px-4 py-2.5 rounded-full shadow-lg shadow-[#EB3E0B]/30 transition-all active:scale-95 flex items-center space-x-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
                        </svg>
                        <span>Hardware Devices</span>
                    </a>
                    <a href="software.php" class="bg-white/15 hover:bg-white/25 text-white font-semibold text-xs sm:text-sm px-4 py-2.5 rounded-full backdrop-blur-md border border-white/20 transition-all active:scale-95 flex items-center space-x-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
                        </svg>
                        <span>Software Issues</span>
                    </a>
                </div>
            </div>

            <!-- WARRANTY NOTIFICATION CARD -->
            <?php if ($has_active_warranty): 
                $cov_type = isset($client['warranty_coverage_type']) ? $client['warranty_coverage_type'] : 'Both';
                if ($cov_type === 'Software') {
                    $warranty_headline = 'Your Account has a Software Warranty!';
                    $warranty_badge_text = 'ACTIVE SOFTWARE WARRANTY';
                } elseif ($cov_type === 'Hardware') {
                    $warranty_headline = 'Your Account has a Hardware Warranty!';
                    $warranty_badge_text = 'ACTIVE HARDWARE WARRANTY';
                } else {
                    $warranty_headline = 'Your Account has a Software & Hardware Warranty!';
                    $warranty_badge_text = 'ACTIVE SOFTWARE & HARDWARE WARRANTY';
                }
            ?>
                <div class="bg-gradient-to-r from-emerald-950 via-teal-900 to-slate-900 rounded-3xl p-6 sm:p-8 text-white shadow-xl shadow-emerald-950/20 border border-emerald-500/30 flex flex-col md:flex-row items-start md:items-center justify-between gap-6 relative overflow-hidden">
                    <div class="flex items-start space-x-4 relative z-10">
                        <div class="w-12 h-12 rounded-2xl bg-emerald-500/20 text-emerald-400 border border-emerald-400/30 flex items-center justify-center font-bold shrink-0 shadow-inner">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                            </svg>
                        </div>
                        <div class="space-y-1">
                            <div class="inline-flex items-center space-x-2 bg-emerald-500/20 backdrop-blur-md px-3 py-0.5 rounded-full text-emerald-300 text-[11px] font-extrabold uppercase tracking-wider border border-emerald-400/20">
                                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                                <span><?php echo sanitize($warranty_badge_text); ?></span>
                            </div>
                            <h3 class="text-lg sm:text-xl font-extrabold text-white tracking-tight"><?php echo sanitize($warranty_headline); ?></h3>
                            <p class="text-xs text-emerald-200/90 max-w-2xl leading-relaxed">
                                <?php echo !empty($client['warranty_notes']) ? sanitize($client['warranty_notes']) : 'Your account is covered under active warranty protection.'; ?>
                            </p>
                        </div>
                    </div>

                    <div class="relative z-10 flex flex-col sm:flex-row items-start sm:items-center gap-4 shrink-0 bg-white/10 backdrop-blur-md p-4 rounded-2xl border border-white/10 text-xs">
                        <div>
                            <span class="block text-emerald-300 text-[10px] uppercase font-bold">Warranty Expiry</span>
                            <span class="font-mono font-bold text-white text-xs">
                                <?php echo !empty($client['warranty_expiry']) ? format_date($client['warranty_expiry']) : 'Active Retainer / No Expiry'; ?>
                            </span>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- INACTIVE / NO WARRANTY NOTIFICATION CARD -->
                <div class="bg-gradient-to-r from-amber-950 via-slate-900 to-amber-900 rounded-3xl p-6 sm:p-8 text-white shadow-xl shadow-amber-950/20 border border-amber-500/30 flex flex-col md:flex-row items-start md:items-center justify-between gap-6 relative overflow-hidden">
                    <div class="flex items-start space-x-4 relative z-10">
                        <div class="w-12 h-12 rounded-2xl bg-amber-500/20 text-amber-400 border border-amber-400/30 flex items-center justify-center font-bold shrink-0 shadow-inner">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                        </div>
                        <div class="space-y-1">
                            <div class="inline-flex items-center space-x-2 bg-amber-500/20 backdrop-blur-md px-3 py-0.5 rounded-full text-amber-300 text-[11px] font-extrabold uppercase tracking-wider border border-amber-400/20">
                                <span class="w-2 h-2 rounded-full bg-amber-400"></span>
                                <span>NO ACTIVE WARRANTY</span>
                            </div>
                            <h3 class="text-lg sm:text-xl font-extrabold text-white tracking-tight">Your account does not have an active warranty!</h3>
                            <p class="text-xs text-amber-200/90 max-w-2xl leading-relaxed">
                                Support services, technical assistance, and hardware repairs may require payment or service fee billing.
                            </p>
                        </div>
                    </div>

                    <div class="relative z-10 flex flex-col sm:flex-row items-start sm:items-center gap-4 shrink-0 bg-white/10 backdrop-blur-md p-4 rounded-2xl border border-white/10 text-xs">
                        <div>
                            <span class="block text-amber-300 text-[10px] uppercase font-bold">Warranty Status</span>
                            <span class="font-mono font-bold text-white text-xs">Inactive / Service Payment Required</span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>



            <!-- Stats Grid Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                <!-- Pending Tickets -->
                <div class="bg-white/90 rounded-3xl p-6 border border-[#FECDAA] shadow-sm hover:shadow-md transition-shadow">
                    <div class="flex items-center justify-between mb-4">
                        <span class="text-xs font-bold text-[#7C2112] uppercase tracking-wider">Pending Issues</span>
                        <div class="w-10 h-10 rounded-2xl bg-[#FFE8D5] text-[#EB3E0B] flex items-center justify-center font-bold">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                    </div>
                    <div class="flex items-baseline space-x-2">
                        <span class="text-3xl font-extrabold text-[#430D07]"><?php echo $pending_tickets; ?></span>
                        <span class="text-xs text-[#EB3E0B] font-semibold">Awaiting Tech</span>
                    </div>
                </div>

                <!-- In Progress Tickets -->
                <div class="bg-white/90 rounded-3xl p-6 border border-[#FECDAA] shadow-sm hover:shadow-md transition-shadow">
                    <div class="flex items-center justify-between mb-4">
                        <span class="text-xs font-bold text-[#7C2112] uppercase tracking-wider">In Progress</span>
                        <div class="w-10 h-10 rounded-2xl bg-[#FFE8D5] text-[#FA5915] flex items-center justify-center font-bold">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                            </svg>
                        </div>
                    </div>
                    <div class="flex items-baseline space-x-2">
                        <span class="text-3xl font-extrabold text-[#430D07]"><?php echo $in_progress_tickets; ?></span>
                        <span class="text-xs text-[#FA5915] font-semibold">Active Work</span>
                    </div>
                </div>

                <!-- Resolved Tickets -->
                <div class="bg-white/90 rounded-3xl p-6 border border-[#FECDAA] shadow-sm hover:shadow-md transition-shadow">
                    <div class="flex items-center justify-between mb-4">
                        <span class="text-xs font-bold text-[#7C2112] uppercase tracking-wider">Resolved Tickets</span>
                        <div class="w-10 h-10 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                    </div>
                    <div class="flex items-baseline space-x-2">
                        <span class="text-3xl font-extrabold text-[#430D07]"><?php echo $resolved_tickets; ?></span>
                        <span class="text-xs text-emerald-600 font-semibold">Completed</span>
                    </div>
                </div>

                <!-- Monthly Retainer & Balance -->
                <div class="bg-white/90 rounded-3xl p-6 border border-[#FECDAA] shadow-sm hover:shadow-md transition-shadow">
                    <div class="flex items-center justify-between mb-4">
                        <span class="text-xs font-bold text-[#7C2112] uppercase tracking-wider">Retainer Fee</span>
                        <div class="w-10 h-10 rounded-2xl bg-[#FFE8D5] text-[#7C2112] flex items-center justify-center font-bold">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                    </div>
                    <div class="flex items-baseline space-x-2">
                        <span class="text-2xl font-extrabold text-[#430D07]">₱<?php echo number_format(floatval($client['monthlyretainersfee']), 2); ?></span>
                        <span class="text-xs text-[#7C2112]">/ mo</span>
                    </div>
                </div>
            </div>

            <!-- Dashboard Content Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

                <!-- Left Column: Support Tickets List -->
                <div class="lg:col-span-2 space-y-6">
                    <div class="bg-white/90 rounded-3xl p-6 sm:p-8 border border-[#FECDAA] shadow-sm">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h3 class="text-lg font-extrabold text-[#430D07]">Recent Support Tickets</h3>
                                <p class="text-xs text-[#7C2112]">Tickets submitted through your portal</p>
                            </div>
                            <a href="tickets.php" class="text-xs font-bold text-[#EB3E0B] hover:text-[#C32C0B] flex items-center space-x-1">
                                <span>View All</span>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                            </a>
                        </div>

                        <?php if (empty($recent_tickets)): ?>
                            <div class="text-center py-12 bg-[#FFF5ED] rounded-2xl border border-dashed border-[#FECDAA]">
                                <svg class="w-12 h-12 text-[#FEAA73] mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 002 2 2 2 0 010 4 2 2 0 00-2 2v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 00-2-2 2 2 0 010-4 2 2 0 002-2V7a2 2 0 00-2-2H5z"/>
                                </svg>
                                <p class="text-sm font-bold text-[#430D07]">No support tickets yet</p>
                                <p class="text-xs text-[#7C2112] mt-1 mb-4">Select your issue category to troubleshoot or request assistance:</p>
                                <div class="flex items-center justify-center gap-2">
                                    <a href="hardware.php" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-semibold text-xs px-4 py-2 rounded-full transition-all">
                                        Hardware Support
                                    </a>
                                    <a href="software.php" class="bg-[#FFE8D5] hover:bg-[#FECDAA] text-[#7C2112] font-semibold text-xs px-4 py-2 rounded-full transition-all">
                                        Software Issues
                                    </a>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left border-collapse">
                                    <thead>
                                        <tr class="border-b border-[#FFE8D5] text-[11px] font-bold text-[#7C2112] uppercase tracking-wider">
                                            <th class="pb-3">Ticket #</th>
                                            <th class="pb-3">Subject</th>
                                            <th class="pb-3">Category</th>
                                            <th class="pb-3">Date</th>
                                            <th class="pb-3 text-right">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-[#FFE8D5] text-xs">
                                        <?php foreach ($recent_tickets as $ticket): ?>
                                            <tr class="hover:bg-[#FFF5ED] transition-colors group cursor-pointer" onclick="window.location='ticket_detail.php?id=<?php echo $ticket['id']; ?>'">
                                                <td class="py-3.5 font-mono font-bold text-[#EB3E0B] group-hover:underline">
                                                    <?php echo sanitize($ticket['ticket_number']); ?>
                                                </td>
                                                <td class="py-3.5 font-bold text-[#430D07]">
                                                    <?php echo sanitize($ticket['subject']); ?>
                                                </td>
                                                <td class="py-3.5 text-[#7C2112]">
                                                    <?php echo sanitize($ticket['category']); ?>
                                                </td>
                                                <td class="py-3.5 text-[#9A2512]">
                                                    <?php echo format_date($ticket['created_at']); ?>
                                                </td>
                                                <td class="py-3.5 text-right">
                                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-semibold border <?php echo get_status_badge_class($ticket['status']); ?>">
                                                        <?php echo sanitize($ticket['status']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right Column: Tech Service History Feed -->
                <div class="space-y-6">
                    <div class="bg-white/90 rounded-3xl p-6 border border-[#FECDAA] shadow-sm">
                        <div class="flex items-center justify-between mb-5">
                            <div>
                                <h3 class="text-base font-extrabold text-[#430D07]">Tech Service Logs</h3>
                                <p class="text-xs text-[#7C2112]">Technician notes for your account</p>
                            </div>
                            <a href="technotes.php" class="text-xs font-bold text-[#EB3E0B] hover:text-[#C32C0B]">View All</a>
                        </div>

                        <?php if (empty($recent_technotes)): ?>
                            <p class="text-xs text-[#7C2112] italic py-4 text-center">No technician logs recorded yet.</p>
                        <?php else: ?>
                            <div class="space-y-4">
                                <?php foreach ($recent_technotes as $note): ?>
                                    <div class="p-4 rounded-2xl bg-[#FFF5ED] border border-[#FECDAA] hover:border-[#FEAA73] transition-all space-y-2">
                                        <div class="flex items-center justify-between text-xs">
                                            <span class="font-bold text-[#430D07] flex items-center space-x-1.5">
                                                <svg class="w-3.5 h-3.5 text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                                </svg>
                                                <span>Tech: <?php echo sanitize($note['techname']); ?></span>
                                            </span>
                                            <span class="text-[#9A2512] font-mono text-[11px]"><?php echo format_date($note['xdate']); ?></span>
                                        </div>
                                        <p class="text-xs font-semibold text-[#430D07] line-clamp-1">
                                            Issue: <?php echo sanitize($note['reasonoftech']); ?>
                                        </p>
                                        <div class="flex items-center justify-between pt-1">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold border <?php echo get_status_badge_class($note['status']); ?>">
                                                <?php echo sanitize($note['status']); ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

        </main>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
