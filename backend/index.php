<?php
// Support Center Admin Dashboard (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/events_init.php';

if (!is_tech_logged_in()) {
    require_once __DIR__ . '/login.php';
    exit;
}

// Check permission for dashboard or redirect to first accessible page
require_page_access('dashboard');

// Auto initialize tables
init_inventory_tables();
init_events_table();

$total_tickets = 0;
$pending_tickets = 0;
$pending_orders = 0;
$in_progress_tickets = 0;
$resolved_tickets = 0;
$today_events_count = 0;
$upcoming_events_count = 0;
$dash_events = array();
$recent_tickets = array();

try {
    $pdo = get_db_connection();

    // KPI Stats Queries
    $total_tickets = intval($pdo->query("SELECT COUNT(*) FROM client_support_tickets")->fetchColumn());
    $pending_tickets = intval($pdo->query("SELECT COUNT(*) FROM client_support_tickets WHERE status = 'Pending'")->fetchColumn());
    $pending_orders = intval($pdo->query("SELECT COUNT(*) FROM client_hardware_orders WHERE status = 'Pending'")->fetchColumn());
    $in_progress_tickets = intval($pdo->query("SELECT COUNT(*) FROM client_support_tickets WHERE status = 'In Progress'")->fetchColumn());
    $resolved_tickets = intval($pdo->query("SELECT COUNT(*) FROM client_support_tickets WHERE status IN ('Resolved', 'Closed')")->fetchColumn());

    // Events Stats Queries (Completed & Cancelled items don't belong on the dashboard)
    $today_events_count = intval($pdo->query("SELECT COUNT(*) FROM support_events WHERE DATE(start_datetime) = CURDATE() AND status NOT IN ('Cancelled', 'Completed')")->fetchColumn());
    $upcoming_events_count = intval($pdo->query("SELECT COUNT(*) FROM support_events WHERE DATE(start_datetime) >= CURDATE() AND status IN ('Scheduled', 'In Progress', 'Rescheduled')")->fetchColumn());

    // Fetch Today's Events only - this is a same-day reminder, not a lookahead
    $stmt_dash_events = $pdo->query("SELECT * FROM support_events
        WHERE DATE(start_datetime) = CURDATE()
          AND status NOT IN ('Cancelled', 'Completed')
        ORDER BY start_datetime ASC
        LIMIT 6");
    $dash_events = $stmt_dash_events ? $stmt_dash_events->fetchAll() : array();

    // Recent Incoming Tickets
    $stmt_tickets = $pdo->query("SELECT t.*, c.tradename, c.clientname 
        FROM client_support_tickets t 
        LEFT JOIN bucket_client c ON t.accountnum = c.accountnum 
        ORDER BY 
            CASE 
                WHEN t.status = 'Pending' THEN 1 
                WHEN t.status = 'In Progress' THEN 2 
                WHEN t.status = 'Open' THEN 3 
                WHEN t.status IN ('Resolved', 'Closed') THEN 4 
                ELSE 3 
            END ASC, 
            t.created_at DESC 
        LIMIT 8");
    $recent_tickets = $stmt_tickets ? $stmt_tickets->fetchAll() : array();
} catch (PDOException $e) {
    error_log("Backend dashboard query error: " . $e->getMessage());
}

$active_page = 'dashboard';
$page_title = 'Support Center Overview';

$tech = get_logged_tech();
$auto_open_popup = true;
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

            <!-- Today's Schedule Quick Action Banner -->
            <div class="bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 rounded-3xl p-5 sm:p-6 text-white flex flex-col sm:flex-row sm:items-center justify-between gap-4 shadow-xl border border-slate-800">
                <div class="flex items-center space-x-3.5">
                    <div class="w-12 h-12 rounded-2xl bg-[#EB3E0B] text-white flex items-center justify-center font-bold shadow-lg shadow-[#EB3E0B]/30 shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                    </div>
                    <div>
                        <div class="flex items-center space-x-2">
                            <h2 class="text-base sm:text-lg font-extrabold text-white">Today's Schedule &amp; Field Appointments</h2>
                            <?php if ($today_events_count > 0): ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-[#EB3E0B] text-white">
                                    <?php echo $today_events_count; ?> Scheduled Today
                                </span>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs text-slate-400 mt-0.5">
                            <?php echo date('l, F j, Y'); ?> &bull; <?php echo $upcoming_events_count; ?> upcoming schedule item(s) this week
                        </p>
                    </div>
                </div>

                <div class="flex items-center space-x-2 shrink-0">
                    <button type="button" onclick="openSchedulePopup()" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white text-xs font-bold px-4 py-2.5 rounded-2xl transition-all shadow-md shadow-[#EB3E0B]/20 flex items-center space-x-1.5 active:scale-95">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        <span>View Schedule Pop-up</span>
                    </button>
                    <a href="events.php" class="bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-bold px-3.5 py-2.5 rounded-2xl transition-colors border border-slate-700 flex items-center space-x-1">
                        <span>Full Calendar &rarr;</span>
                    </a>
                </div>
            </div>

            <!-- KPI Metric Stats Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-5">
                
                <!-- Pending Tickets Card -->
                <a href="tickets.php?status=Pending" class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm flex items-center justify-between hover:border-amber-500/50 transition-all group">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-amber-600 uppercase tracking-wider">Pending Tickets</span>
                        <h3 class="text-3xl font-extrabold text-slate-900 font-mono"><?php echo number_format($pending_tickets); ?></h3>
                        <p class="text-[11px] text-slate-500 group-hover:text-amber-600 font-medium">Customer tickets</p>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center font-bold">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                </a>

                <!-- Pending Orders Card -->
                <a href="orders.php?status=Pending" class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm flex items-center justify-between hover:border-[#EB3E0B]/50 transition-all group">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-[#EB3E0B] uppercase tracking-wider">Pending Orders</span>
                        <h3 class="text-3xl font-extrabold text-slate-900 font-mono"><?php echo number_format($pending_orders); ?></h3>
                        <p class="text-[11px] text-slate-500 group-hover:text-[#EB3E0B] font-medium">Material requests</p>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-[#FFE8D5] text-[#EB3E0B] flex items-center justify-center font-bold">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                        </svg>
                    </div>
                </a>

                <!-- In Progress Card -->
                <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm flex items-center justify-between">
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-blue-600 uppercase tracking-wider">In Progress</span>
                        <h3 class="text-3xl font-extrabold text-slate-900 font-mono"><?php echo number_format($in_progress_tickets); ?></h3>
                        <p class="text-[11px] text-slate-500">Under investigation</p>
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
                        <p class="text-[11px] text-slate-500">Completed support</p>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                    </div>
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
                                    $st = $t['status'];

                                    if ($st === 'Resolved' || $st === 'Closed') {
                                        // Resolved -> Green Row
                                        $row_class   = 'bg-emerald-50/70 hover:bg-emerald-100/80 border-b border-emerald-100 text-emerald-950';
                                        $num_color   = 'text-emerald-700';
                                        $title_color = 'text-emerald-950';
                                        $subj_color  = 'text-emerald-900';
                                        $date_color  = 'text-emerald-700 font-mono';
                                        $badge_class = 'bg-emerald-100 text-emerald-800 border border-emerald-300';
                                        $btn_class   = 'bg-emerald-100/90 hover:bg-emerald-600 text-emerald-700 hover:text-white';
                                    } elseif ($st === 'Pending' || $st === 'Open') {
                                        // Pending -> Red Row
                                        $row_class   = 'bg-rose-50/70 hover:bg-rose-100/80 border-b border-rose-100 text-rose-950';
                                        $num_color   = 'text-rose-700';
                                        $title_color = 'text-rose-950';
                                        $subj_color  = 'text-rose-900';
                                        $date_color  = 'text-rose-700 font-mono';
                                        $badge_class = 'bg-rose-100 text-rose-800 border border-rose-300';
                                        $btn_class   = 'bg-rose-100/90 hover:bg-rose-600 text-rose-700 hover:text-white';
                                    } elseif ($st === 'In Progress') {
                                        // In Progress -> Blue Row
                                        $row_class   = 'bg-blue-50/70 hover:bg-blue-100/80 border-b border-blue-100 text-blue-950';
                                        $num_color   = 'text-blue-700';
                                        $title_color = 'text-blue-950';
                                        $subj_color  = 'text-blue-900';
                                        $date_color  = 'text-blue-700 font-mono';
                                        $badge_class = 'bg-blue-100 text-blue-800 border border-blue-300';
                                        $btn_class   = 'bg-blue-100/90 hover:bg-blue-600 text-blue-700 hover:text-white';
                                    } else {
                                        $row_class   = 'hover:bg-slate-50/80 text-slate-800';
                                        $num_color   = 'text-[#EB3E0B]';
                                        $title_color = 'text-slate-900';
                                        $subj_color  = 'text-slate-800';
                                        $date_color  = 'text-slate-500 font-mono';
                                        $badge_class = get_status_badge_class($st);
                                        $btn_class   = 'bg-slate-100 hover:bg-[#EB3E0B] text-slate-500 hover:text-white';
                                    }
                                ?>
                                    <tr class="<?php echo $row_class; ?> transition-colors">
                                        <td class="py-4 px-6 font-mono font-bold <?php echo $num_color; ?>">
                                            <?php echo sanitize($t['ticket_number']); ?>
                                        </td>
                                        <td class="py-4 px-6">
                                            <div class="font-bold <?php echo $title_color; ?>"><?php echo sanitize($client_display); ?></div>
                                        </td>
                                        <td class="py-4 px-6 max-w-xs truncate font-semibold <?php echo $subj_color; ?>">
                                            <?php echo sanitize($t['subject']); ?>
                                        </td>
                                        <td class="py-4 px-6">
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold border <?php echo $badge_class; ?>">
                                                <?php echo sanitize($t['status']); ?>
                                            </span>
                                        </td>
                                        <td class="py-4 px-6 <?php echo $date_color; ?>">
                                            <?php echo format_date($t['created_at']); ?>
                                        </td>
                                        <td class="py-4 px-6 text-right">
                                            <a href="ticket_detail.php?id=<?php echo $t['id']; ?>" onclick="markNotificationClicked('ticket_<?php echo $t['id']; ?>', <?php echo (intval($t['id']) * 100000); ?>)" class="inline-flex items-center justify-center w-9 h-9 rounded-full <?php echo $btn_class; ?> transition-colors shadow-xs" title="Manage Ticket">
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

<!-- ========================================================================= -->
<!-- SIMPLIFIED EVENT & SCHEDULE POP-UP MODAL (Auto-shown on login & on-demand) -->
<!-- ========================================================================= -->
<div id="schedulePopupModal" class="fixed inset-0 z-50 <?php echo $auto_open_popup ? 'flex' : 'hidden'; ?> items-center justify-center bg-slate-950/75 backdrop-blur-md p-4 overflow-y-auto" onclick="handlePopupBackdropClick(event)">
    <div class="bg-white rounded-3xl max-w-2xl w-full shadow-2xl border border-slate-200 transform transition-all overflow-hidden my-8 max-h-[90vh] flex flex-col" onclick="event.stopPropagation()">
        
        <!-- Modal Header -->
        <div class="p-6 border-b border-slate-100 bg-gradient-to-r from-orange-50/70 via-amber-50/40 to-white flex items-center justify-between">
            <div class="flex items-center space-x-3.5">
                <div class="w-12 h-12 rounded-2xl bg-[#EB3E0B] text-white flex items-center justify-center font-bold shadow-md shadow-[#EB3E0B]/25 shrink-0">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </div>
                <div>
                    <div class="flex items-center space-x-2">
                        <h3 class="text-lg sm:text-xl font-extrabold text-slate-900">Today's Schedule &amp; Events</h3>
                        <?php if ($today_events_count > 0): ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-[#EB3E0B] text-white shadow-xs">
                                <?php echo $today_events_count; ?> Today
                            </span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs text-slate-500 font-medium mt-0.5">
                        <?php echo date('l, F j, Y'); ?> &bull; Logged in as <strong class="text-slate-800"><?php echo isset($tech['fullname']) ? sanitize($tech['fullname']) : 'Staff'; ?></strong>
                    </p>
                </div>
            </div>

            <button type="button" onclick="closeSchedulePopup()" class="w-9 h-9 rounded-2xl bg-white/80 hover:bg-slate-100 text-slate-400 hover:text-slate-700 flex items-center justify-center border border-slate-200/80 transition-colors shadow-xs" title="Close">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- Modal Body (Scrollable Schedule List) -->
        <div class="p-6 overflow-y-auto space-y-4 flex-1">
            <?php if (empty($dash_events)): ?>
                <div class="text-center py-12 px-4 bg-slate-50 rounded-2xl border border-dashed border-slate-200">
                    <div class="w-14 h-14 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center mx-auto mb-3.5">
                        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <h4 class="text-base font-bold text-slate-800">No Events Scheduled For Today</h4>
                    <p class="text-xs text-slate-500 mt-1 max-w-sm mx-auto">You're all caught up! There are no field visits or maintenance appointments currently assigned for today.</p>
                    <div class="mt-5 flex items-center justify-center space-x-3">
                        <a href="events.php?new=1" class="text-xs font-bold text-white bg-[#EB3E0B] hover:bg-[#C32C0B] px-4 py-2.5 rounded-xl transition-all shadow-sm">
                            + Book New Schedule
                        </a>
                        <a href="events.php" class="text-xs font-bold text-slate-700 bg-slate-200/70 hover:bg-slate-300 px-4 py-2.5 rounded-xl transition-all">
                            View Full Calendar
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($dash_events as $ev): 
                        $type_meta = get_event_type_meta($ev['event_type']);
                        $st_badge = get_event_status_badge($ev['status']);
                        $is_today = (date('Y-m-d', strtotime($ev['start_datetime'])) === date('Y-m-d'));
                        $is_tomorrow = (date('Y-m-d', strtotime($ev['start_datetime'])) === date('Y-m-d', strtotime('+1 day')));
                        
                        if ($ev['all_day']) {
                            $time_label = 'All Day';
                        } else {
                            $time_label = date('h:i A', strtotime($ev['start_datetime'])) . ' - ' . date('h:i A', strtotime($ev['end_datetime']));
                        }
                        
                        if ($is_today) {
                            $badge_day = '<span class="bg-[#EB3E0B] text-white px-2.5 py-0.5 rounded-md font-extrabold text-[10px] uppercase tracking-wider">Today</span>';
                        } elseif ($is_tomorrow) {
                            $badge_day = '<span class="bg-amber-500 text-white px-2.5 py-0.5 rounded-md font-bold text-[10px] uppercase tracking-wider">Tomorrow</span>';
                        } else {
                            $badge_day = '<span class="bg-slate-200 text-slate-700 px-2.5 py-0.5 rounded-md font-bold text-[10px] uppercase font-mono">' . date('D, M j', strtotime($ev['start_datetime'])) . '</span>';
                        }
                    ?>
                        <div class="p-4 rounded-2xl border <?php echo $is_today ? 'bg-orange-50/50 border-orange-200 ring-1 ring-orange-200/60 shadow-xs' : 'bg-slate-50 border-slate-200 hover:bg-white hover:border-slate-300'; ?> transition-all space-y-3 group">
                            
                            <!-- Header: Day, Time & Category -->
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center space-x-2">
                                    <?php echo $badge_day; ?>
                                    <span class="text-xs font-mono font-bold text-slate-700"><?php echo $time_label; ?></span>
                                </div>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold border <?php echo $type_meta['badge_class']; ?>">
                                    <span class="w-1.5 h-1.5 rounded-full mr-1.5 <?php echo $type_meta['dot_class']; ?>"></span>
                                    <?php echo sanitize($ev['event_type']); ?>
                                </span>
                            </div>

                            <!-- Title -->
                            <h4 class="font-extrabold text-sm text-slate-900 leading-snug">
                                <?php echo sanitize($ev['title']); ?>
                            </h4>

                            <!-- Client & Location Info -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs text-slate-600 bg-white/70 p-2.5 rounded-xl border border-slate-100">
                                <?php if (!empty($ev['client_name'])): ?>
                                    <div class="flex items-center space-x-1.5 font-bold text-slate-800 truncate">
                                        <svg class="w-4 h-4 text-[#EB3E0B] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                                        <span class="truncate"><?php echo sanitize($ev['client_name']); ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($ev['location'])): ?>
                                    <div class="flex items-center space-x-1.5 text-slate-500 truncate">
                                        <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                        <span class="truncate"><?php echo sanitize($ev['location']); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Footer: Assigned Tech, Status & View Link -->
                            <div class="flex items-center justify-between text-xs pt-1">
                                <div class="flex items-center space-x-1.5 text-slate-700 font-semibold">
                                    <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                    <span>Assigned: <strong><?php echo !empty($ev['assigned_tech']) ? sanitize($ev['assigned_tech']) : 'Unassigned'; ?></strong></span>
                                </div>

                                <div class="flex items-center space-x-2">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold <?php echo $st_badge; ?>">
                                        <?php echo sanitize($ev['status']); ?>
                                    </span>
                                    <a href="events.php?q=<?php echo urlencode($ev['title']); ?>" class="text-[11px] font-bold text-[#EB3E0B] hover:text-[#C32C0B] hover:underline flex items-center space-x-0.5">
                                        <span>Details</span>
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Modal Footer -->
        <div class="p-4 sm:p-5 bg-slate-50 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-3">
            <div class="text-xs text-slate-500 font-medium text-center sm:text-left">
                <?php echo $today_events_count; ?> schedule item(s) today &bull; <a href="events.php" class="text-[#EB3E0B] hover:underline font-bold">see upcoming</a>
            </div>
            <div class="flex items-center space-x-2.5 w-full sm:w-auto justify-end">
                <a href="events.php" class="flex-1 sm:flex-initial text-center bg-slate-200/80 hover:bg-slate-300 text-slate-800 text-xs font-bold px-4 py-2.5 rounded-xl transition-all">
                    Full Calendar
                </a>
                <button type="button" onclick="closeSchedulePopup()" class="flex-1 sm:flex-initial text-center bg-[#EB3E0B] hover:bg-[#C32C0B] text-white text-xs font-bold px-5 py-2.5 rounded-xl transition-all shadow-sm">
                    Got it
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function openSchedulePopup() {
    var modal = document.getElementById('schedulePopupModal');
    if (modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
}

function closeSchedulePopup() {
    var modal = document.getElementById('schedulePopupModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
}

function handlePopupBackdropClick(e) {
    if (e.target && e.target.id === 'schedulePopupModal') {
        closeSchedulePopup();
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeSchedulePopup();
    }
});

<?php if ($auto_open_popup): ?>
document.addEventListener('DOMContentLoaded', function() {
    openSchedulePopup();
});
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
