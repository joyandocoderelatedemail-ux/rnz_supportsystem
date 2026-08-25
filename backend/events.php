<?php
// Interactive Events & Staff Scheduling Console (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/events_init.php';

require_page_access('events');

// Ensure tables exist
init_events_table();

$pdo = get_db_connection();
$tech = get_logged_tech();
$tech_fullname = isset($tech['fullname']) ? $tech['fullname'] : 'Staff';
$my_tier = get_logged_tech_access_tier();

// Notification Messages
$msg = isset($_GET['msg']) ? sanitize($_GET['msg']) : '';
$msg_type = 'success';
$msg_text = '';

if ($msg === 'created') {
    $msg_text = 'Event schedule successfully booked and assigned!';
} elseif ($msg === 'updated') {
    $msg_text = 'Event schedule successfully updated.';
} elseif ($msg === 'status_updated') {
    $msg_text = 'Schedule status updated successfully.';
} elseif ($msg === 'deleted') {
    $msg_text = 'Event schedule was permanently removed.';
} elseif ($msg === 'error') {
    $msg_type = 'error';
    $msg_text = isset($_GET['err_msg']) ? sanitize($_GET['err_msg']) : 'An error occurred while processing the event.';
}

// -------------------------------------------------------------------------
// POST ACTIONS: CREATE, UPDATE, STATUS CHANGE, DELETE
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $action_code = isset($_POST['action_access_code']) ? trim($_POST['action_access_code']) : '';
    $perm_check = check_tech_action_permission($action_code);

    if (!$perm_check['allowed']) {
        header("Location: events.php?msg=error&err_msg=" . urlencode($perm_check['message']));
        exit;
    }

    // 1. CREATE EVENT
    if ($action === 'create_event') {
        $title = isset($_POST['title']) ? trim($_POST['title']) : '';
        $event_type = isset($_POST['event_type']) ? trim($_POST['event_type']) : 'Field Visit';
        $accountnum = isset($_POST['accountnum']) ? trim($_POST['accountnum']) : '';
        $client_name = isset($_POST['client_name']) ? trim($_POST['client_name']) : '';
        $assigned_tech = isset($_POST['assigned_tech']) ? trim($_POST['assigned_tech']) : '';
        $location = isset($_POST['location']) ? trim($_POST['location']) : '';
        $start_raw = isset($_POST['start_datetime']) ? trim($_POST['start_datetime']) : '';
        $end_raw = isset($_POST['end_datetime']) ? trim($_POST['end_datetime']) : '';
        $all_day = isset($_POST['all_day']) && $_POST['all_day'] == '1' ? 1 : 0;
        $priority = isset($_POST['priority']) ? trim($_POST['priority']) : 'Medium';
        $status = isset($_POST['status']) ? trim($_POST['status']) : 'Scheduled';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';

        if (empty($title)) {
            header("Location: events.php?msg=error&err_msg=" . urlencode("Event title is required."));
            exit;
        }

        if (empty($start_raw)) {
            $start_raw = date('Y-m-d H:i:s');
        } else {
            $start_raw = date('Y-m-d H:i:s', strtotime($start_raw));
        }

        if (empty($end_raw)) {
            $end_raw = date('Y-m-d H:i:s', strtotime($start_raw . ' +1 hour'));
        } else {
            $end_raw = date('Y-m-d H:i:s', strtotime($end_raw));
        }

        $stmt_ins = $pdo->prepare("INSERT INTO support_events 
            (title, event_type, accountnum, client_name, assigned_tech, location, start_datetime, end_datetime, all_day, priority, status, description, created_by, created_at, updated_at) 
            VALUES (:title, :etype, :acct, :cname, :tech, :loc, :s_dt, :e_dt, :allday, :prio, :st, :descr, :cby, :now, :now)");

        $stmt_ins->execute(array(
            ':title' => $title,
            ':etype' => $event_type,
            ':acct' => !empty($accountnum) ? $accountnum : null,
            ':cname' => !empty($client_name) ? $client_name : null,
            ':tech' => !empty($assigned_tech) ? $assigned_tech : null,
            ':loc' => !empty($location) ? $location : null,
            ':s_dt' => $start_raw,
            ':e_dt' => $end_raw,
            ':allday' => $all_day,
            ':prio' => $priority,
            ':st' => $status,
            ':descr' => $description,
            ':cby' => $tech_fullname,
            ':now' => date('Y-m-d H:i:s')
        ));

        header("Location: events.php?msg=created");
        exit;
    }

    // 2. UPDATE EVENT
    if ($action === 'update_event') {
        $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
        if ($event_id <= 0) {
            header("Location: events.php?msg=error&err_msg=" . urlencode("Invalid event ID."));
            exit;
        }

        $title = isset($_POST['title']) ? trim($_POST['title']) : '';
        $event_type = isset($_POST['event_type']) ? trim($_POST['event_type']) : 'Field Visit';
        $accountnum = isset($_POST['accountnum']) ? trim($_POST['accountnum']) : '';
        $client_name = isset($_POST['client_name']) ? trim($_POST['client_name']) : '';
        $assigned_tech = isset($_POST['assigned_tech']) ? trim($_POST['assigned_tech']) : '';
        $location = isset($_POST['location']) ? trim($_POST['location']) : '';
        $start_raw = isset($_POST['start_datetime']) ? trim($_POST['start_datetime']) : '';
        $end_raw = isset($_POST['end_datetime']) ? trim($_POST['end_datetime']) : '';
        $all_day = isset($_POST['all_day']) && $_POST['all_day'] == '1' ? 1 : 0;
        $priority = isset($_POST['priority']) ? trim($_POST['priority']) : 'Medium';
        $status = isset($_POST['status']) ? trim($_POST['status']) : 'Scheduled';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';

        if (empty($title)) {
            header("Location: events.php?msg=error&err_msg=" . urlencode("Event title cannot be empty."));
            exit;
        }

        if (!empty($start_raw)) {
            $start_raw = date('Y-m-d H:i:s', strtotime($start_raw));
        }
        if (!empty($end_raw)) {
            $end_raw = date('Y-m-d H:i:s', strtotime($end_raw));
        }

        $stmt_up = $pdo->prepare("UPDATE support_events SET 
            title = :title,
            event_type = :etype,
            accountnum = :acct,
            client_name = :cname,
            assigned_tech = :tech,
            location = :loc,
            start_datetime = :s_dt,
            end_datetime = :e_dt,
            all_day = :allday,
            priority = :prio,
            status = :st,
            description = :descr,
            updated_at = :now
            WHERE id = :id");

        $stmt_up->execute(array(
            ':title' => $title,
            ':etype' => $event_type,
            ':acct' => !empty($accountnum) ? $accountnum : null,
            ':cname' => !empty($client_name) ? $client_name : null,
            ':tech' => !empty($assigned_tech) ? $assigned_tech : null,
            ':loc' => !empty($location) ? $location : null,
            ':s_dt' => $start_raw,
            ':e_dt' => $end_raw,
            ':allday' => $all_day,
            ':prio' => $priority,
            ':st' => $status,
            ':descr' => $description,
            ':now' => date('Y-m-d H:i:s'),
            ':id' => $event_id
        ));

        header("Location: events.php?msg=updated");
        exit;
    }

    // 3. QUICK STATUS CHANGE
    if ($action === 'quick_status') {
        $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
        $new_status = isset($_POST['status']) ? trim($_POST['status']) : 'Completed';

        if ($event_id > 0) {
            $stmt_qst = $pdo->prepare("UPDATE support_events SET status = :st, updated_at = :now WHERE id = :id");
            $stmt_qst->execute(array(
                ':st' => $new_status,
                ':now' => date('Y-m-d H:i:s'),
                ':id' => $event_id
            ));
            header("Location: events.php?msg=status_updated");
            exit;
        }
    }

    // 4. DELETE EVENT
    if ($action === 'delete_event') {
        $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
        if ($event_id > 0) {
            $stmt_del = $pdo->prepare("DELETE FROM support_events WHERE id = :id");
            $stmt_del->execute(array(':id' => $event_id));
            header("Location: events.php?msg=deleted");
            exit;
        }
    }

    header("Location: events.php");
    exit;
}

// -------------------------------------------------------------------------
// DATA FETCHING: ACCOUNTS, TECHNICIANS, EVENTS LIST & METRICS
// -------------------------------------------------------------------------

// Fetch Accounts list for autocomplete
$stmt_accts = $pdo->query("SELECT accountnum, tradename, clientname, address, contactnum FROM bucket_client ORDER BY tradename ASC");
$all_accounts = $stmt_accts->fetchAll();

// Fetch Technicians list from user table
$stmt_techs = $pdo->query("SELECT id, fname, lname, user, accesslevel FROM user ORDER BY fname ASC");
$all_technicians = $stmt_techs->fetchAll();

// Filter values from GET
$filter_tech = isset($_GET['tech']) ? trim($_GET['tech']) : '';
$filter_type = isset($_GET['type']) ? trim($_GET['type']) : '';
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$filter_q = isset($_GET['q']) ? trim($_GET['q']) : '';

$where_clauses = array();
$params = array();

if (!empty($filter_tech)) {
    $where_clauses[] = "assigned_tech = :f_tech";
    $params[':f_tech'] = $filter_tech;
}
if (!empty($filter_type)) {
    $where_clauses[] = "event_type = :f_type";
    $params[':f_type'] = $filter_type;
}
if (!empty($filter_status)) {
    $where_clauses[] = "status = :f_status";
    $params[':f_status'] = $filter_status;
}
if (!empty($filter_q)) {
    $where_clauses[] = "(title LIKE :f_q OR client_name LIKE :f_q OR accountnum LIKE :f_q OR location LIKE :f_q OR description LIKE :f_q)";
    $params[':f_q'] = "%$filter_q%";
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Fetch all matching events (ordered by start_datetime)
$stmt_ev = $pdo->prepare("SELECT * FROM support_events $where_sql ORDER BY start_datetime ASC");
$stmt_ev->execute($params);
$events_records = $stmt_ev->fetchAll();

// Format events for FullCalendar JSON
$calendar_events = array();
foreach ($events_records as $e) {
    $meta = get_event_type_meta($e['event_type']);
    $cal_ev = array(
        'id' => intval($e['id']),
        'title' => $e['title'],
        'start' => $e['all_day'] ? date('Y-m-d', strtotime($e['start_datetime'])) : date('c', strtotime($e['start_datetime'])),
        'end' => $e['all_day'] ? date('Y-m-d', strtotime($e['end_datetime'] . ' +1 day')) : date('c', strtotime($e['end_datetime'])),
        'allDay' => ($e['all_day'] == 1),
        'backgroundColor' => $meta['bg_hex'],
        'borderColor' => $meta['border_hex'],
        'textColor' => $meta['text_hex'],
        'extendedProps' => array(
            'raw_id' => intval($e['id']),
            'event_type' => $e['event_type'],
            'accountnum' => $e['accountnum'],
            'client_name' => $e['client_name'],
            'assigned_tech' => $e['assigned_tech'],
            'location' => $e['location'],
            'start_raw' => date('Y-m-d\TH:i', strtotime($e['start_datetime'])),
            'end_raw' => date('Y-m-d\TH:i', strtotime($e['end_datetime'])),
            'start_formatted' => date('M d, Y h:i A', strtotime($e['start_datetime'])),
            'end_formatted' => date('M d, Y h:i A', strtotime($e['end_datetime'])),
            'priority' => $e['priority'],
            'status' => $e['status'],
            'description' => $e['description'],
            'created_by' => $e['created_by'],
            'created_at' => date('M d, Y h:i A', strtotime($e['created_at'])),
            'badge_class' => $meta['badge_class'],
            'status_badge' => get_event_status_badge($e['status'])
        )
    );
    $calendar_events[] = $cal_ev;
}

// Key Metrics Calculation
$today_str = date('Y-m-d');
$month_start = date('Y-m-01 00:00:00');
$month_end = date('Y-m-t 23:59:59');

$total_events_count = count($events_records);
$today_events_count = 0;
$in_progress_count = 0;
$completed_month_count = 0;
$today_events_list = array();
$upcoming_events_list = array();

foreach ($events_records as $ev) {
    $ev_date = date('Y-m-d', strtotime($ev['start_datetime']));
    if ($ev_date === $today_str) {
        $today_events_count++;
        if (count($today_events_list) < 8) {
            $today_events_list[] = $ev;
        }
    } elseif ($ev_date > $today_str && count($upcoming_events_list) < 8) {
        $upcoming_events_list[] = $ev;
    }

    if ($ev['status'] === 'In Progress') {
        $in_progress_count++;
    }
    if ($ev['status'] === 'Completed' && $ev['start_datetime'] >= $month_start && $ev['start_datetime'] <= $month_end) {
        $completed_month_count++;
    }
}

$active_page = 'events';
$page_title = 'Events & Staff Schedules';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Events &amp; Staff Schedules - Support Center</title>
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
    <!-- FullCalendar v6 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }

        /* FullCalendar Custom Dark/Slate Styling */
        .fc {
            --fc-border-color: #334155;
            --fc-page-bg-color: transparent;
            --fc-neutral-bg-color: #1e293b;
            --fc-list-event-hover-bg-color: #334155;
            --fc-today-bg-color: rgba(235, 62, 11, 0.08);
            font-family: inherit;
        }
        .fc .fc-toolbar-title {
            font-size: 1.15rem;
            font-weight: 800;
            color: #f8fafc;
        }
        .fc .fc-button {
            background-color: #1e293b;
            border-color: #334155;
            color: #cbd5e1;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.45rem 0.85rem;
            border-radius: 0.75rem;
            transition: all 0.15s ease;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            text-transform: capitalize;
        }
        .fc .fc-button:hover {
            background-color: #334155;
            border-color: #475569;
            color: #ffffff;
        }
        .fc .fc-button-primary:not(:disabled).fc-button-active, 
        .fc .fc-button-primary:not(:disabled):active {
            background-color: #EB3E0B !important;
            border-color: #EB3E0B !important;
            color: #ffffff !important;
            box-shadow: 0 4px 12px rgba(235, 62, 11, 0.25);
        }
        .fc .fc-button:focus {
            box-shadow: none !important;
        }
        .fc .fc-col-header-cell-cushion {
            color: #94a3b8;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.75rem 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .fc .fc-daygrid-day-number {
            color: #cbd5e1;
            font-size: 0.8rem;
            font-weight: 700;
            padding: 0.4rem 0.5rem;
        }
        .fc .fc-day-today .fc-daygrid-day-number {
            background-color: #EB3E0B;
            color: #ffffff;
            border-radius: 9999px;
            width: 1.75rem;
            height: 1.75rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin: 0.2rem;
        }
        .fc .fc-event {
            border-radius: 0.5rem;
            padding: 0.15rem 0.35rem;
            font-size: 0.75rem;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.12s ease, box-shadow 0.12s ease;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
            border-width: 1px;
        }
        .fc .fc-event:hover {
            transform: translateY(-1px) scale(1.01);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
            filter: brightness(1.08);
        }
        .fc-theme-standard td, .fc-theme-standard th {
            border-color: #334155;
        }
        .fc-theme-standard .fc-scrollgrid {
            border-color: #334155;
            border-radius: 1rem;
            overflow: hidden;
        }
        .fc .fc-list {
            border-color: #334155;
            border-radius: 1rem;
            overflow: hidden;
        }
        .fc .fc-list-day-cushion {
            background-color: #1e293b !important;
            color: #f8fafc;
            font-weight: 800;
            font-size: 0.8rem;
        }
        .fc .fc-list-event td {
            border-color: #334155;
            background-color: #0f172a;
            color: #e2e8f0;
            font-size: 0.8rem;
        }
        .fc .fc-list-event:hover td {
            background-color: #1e293b !important;
        }

        /* Printable styles */
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; color: black !important; }
            .fc .fc-toolbar-chunk:not(:first-child) { display: none !important; }
            .fc { --fc-border-color: #cbd5e1; }
        }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 antialiased min-h-screen">

<div class="flex min-h-screen">
    <!-- Admin Sidebar Navigation -->
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col min-w-0">
        <!-- Top Header -->
        <?php include __DIR__ . '/includes/header.php'; ?>

        <main class="p-4 sm:p-6 lg:p-8 space-y-6 max-w-7xl w-full mx-auto">

            <!-- Notification Banner -->
            <?php if (!empty($msg_text)): ?>
                <div class="p-4 rounded-2xl flex items-center justify-between shadow-sm border <?php echo ($msg_type === 'success') ? 'bg-emerald-950/80 border-emerald-700 text-emerald-200' : 'bg-rose-950/80 border-rose-700 text-rose-200'; ?> no-print">
                    <div class="flex items-center space-x-3">
                        <div class="w-8 h-8 rounded-xl flex items-center justify-center shrink-0 <?php echo ($msg_type === 'success') ? 'bg-emerald-600 text-white' : 'bg-rose-600 text-white'; ?>">
                            <?php if ($msg_type === 'success'): ?>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <?php else: ?>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs sm:text-sm font-medium"><?php echo $msg_text; ?></p>
                    </div>
                    <a href="events.php" class="text-xs font-bold opacity-70 hover:opacity-100 transition-opacity">Dismiss</a>
                </div>
            <?php endif; ?>

            <!-- Page Title & Top Actions Bar -->
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 bg-slate-900/90 border border-slate-800 rounded-3xl p-6 shadow-xl no-print">
                <div class="flex items-center space-x-4">
                    <div class="w-14 h-14 rounded-2xl bg-[#EB3E0B]/20 text-[#EB3E0B] border border-[#EB3E0B]/30 flex items-center justify-center font-bold shrink-0 shadow-lg shadow-[#EB3E0B]/10">
                        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                    </div>
                    <div>
                        <div class="flex items-center space-x-2">
                            <h1 class="text-xl font-extrabold text-white tracking-tight">Events &amp; Staff Schedules</h1>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-mono font-bold bg-[#EB3E0B] text-white">Live Calendar</span>
                        </div>
                        <p class="text-xs text-slate-400 mt-0.5">Interactive dispatch scheduler, field service routing, and maintenance calendar</p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2.5">
                    <?php echo get_tier_badge($my_tier); ?>

                    <button type="button" onclick="window.print()" class="px-3.5 py-2 rounded-2xl bg-slate-800 hover:bg-slate-700 text-slate-200 hover:text-white text-xs font-bold transition-all border border-slate-700 flex items-center space-x-1.5 shadow-sm">
                        <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                        <span>Print Schedule</span>
                    </button>

                    <?php if ($my_tier !== 1): ?>
                        <button type="button" onclick="openNewEventModal()" class="px-4 py-2 rounded-2xl bg-[#EB3E0B] hover:bg-[#C32C0B] text-white text-xs font-bold transition-all shadow-md shadow-[#EB3E0B]/25 flex items-center space-x-1.5 active:scale-95">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            <span>Schedule New Event</span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 4 Quick KPI Summary Cards -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 no-print">
                <!-- Total Events -->
                <div class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4 sm:p-5 flex items-center justify-between">
                    <div>
                        <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">Total Schedules</span>
                        <span class="text-xl sm:text-2xl font-extrabold text-white font-mono"><?php echo $total_events_count; ?></span>
                    </div>
                    <div class="w-10 h-10 rounded-2xl bg-orange-500/10 text-orange-400 border border-orange-500/20 flex items-center justify-center font-bold">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    </div>
                </div>

                <!-- Today's Visits -->
                <div class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4 sm:p-5 flex items-center justify-between">
                    <div>
                        <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">Today's Visits</span>
                        <span class="text-xl sm:text-2xl font-extrabold text-[#EB3E0B] font-mono"><?php echo $today_events_count; ?></span>
                    </div>
                    <div class="w-10 h-10 rounded-2xl bg-[#EB3E0B]/10 text-[#EB3E0B] border border-[#EB3E0B]/20 flex items-center justify-center font-bold">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                </div>

                <!-- In Progress -->
                <div class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4 sm:p-5 flex items-center justify-between">
                    <div>
                        <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">In Progress</span>
                        <span class="text-xl sm:text-2xl font-extrabold text-blue-400 font-mono"><?php echo $in_progress_count; ?></span>
                    </div>
                    <div class="w-10 h-10 rounded-2xl bg-blue-500/10 text-blue-400 border border-blue-500/20 flex items-center justify-center font-bold">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                </div>

                <!-- Completed Month -->
                <div class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4 sm:p-5 flex items-center justify-between">
                    <div>
                        <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">Completed (This Month)</span>
                        <span class="text-xl sm:text-2xl font-extrabold text-emerald-400 font-mono"><?php echo $completed_month_count; ?></span>
                    </div>
                    <div class="w-10 h-10 rounded-2xl bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 flex items-center justify-center font-bold">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                </div>
            </div>

            <!-- Interactive Filters Toolbar -->
            <div class="bg-slate-900/80 border border-slate-800 rounded-3xl p-4 sm:p-5 shadow-lg no-print">
                <form action="events.php" method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                    
                    <!-- Filter: Technician -->
                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Technician / Staff</label>
                        <select name="tech" onchange="this.form.submit()" class="w-full bg-slate-800 border border-slate-700 text-slate-200 text-xs rounded-xl p-2.5 focus:outline-none focus:border-[#EB3E0B] font-medium">
                            <option value="">All Staff</option>
                            <?php foreach ($all_technicians as $t): 
                                $t_name = trim($t['fname'] . ' ' . $t['lname']);
                                $sel = ($filter_tech === $t_name) ? 'selected' : '';
                            ?>
                                <option value="<?php echo sanitize($t_name); ?>" <?php echo $sel; ?>><?php echo sanitize($t_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Filter: Event Type -->
                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Category / Type</label>
                        <select name="type" onchange="this.form.submit()" class="w-full bg-slate-800 border border-slate-700 text-slate-200 text-xs rounded-xl p-2.5 focus:outline-none focus:border-[#EB3E0B] font-medium">
                            <option value="">All Categories</option>
                            <option value="Field Visit" <?php echo ($filter_type === 'Field Visit') ? 'selected' : ''; ?>>Field Visit</option>
                            <option value="POS Installation" <?php echo ($filter_type === 'POS Installation') ? 'selected' : ''; ?>>POS Installation</option>
                            <option value="POS Maintenance" <?php echo ($filter_type === 'POS Maintenance') ? 'selected' : ''; ?>>POS Maintenance</option>
                            <option value="Hardware Delivery" <?php echo ($filter_type === 'Hardware Delivery') ? 'selected' : ''; ?>>Hardware Delivery</option>
                            <option value="Urgent Troubleshooting" <?php echo ($filter_type === 'Urgent Troubleshooting') ? 'selected' : ''; ?>>Urgent Troubleshooting</option>
                            <option value="System Upgrade" <?php echo ($filter_type === 'System Upgrade') ? 'selected' : ''; ?>>System Upgrade</option>
                            <option value="Meeting / Conference" <?php echo ($filter_type === 'Meeting / Conference') ? 'selected' : ''; ?>>Meeting / Conference</option>
                            <option value="General Reminder" <?php echo ($filter_type === 'General Reminder') ? 'selected' : ''; ?>>General Reminder</option>
                        </select>
                    </div>

                    <!-- Filter: Status -->
                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Status</label>
                        <select name="status" onchange="this.form.submit()" class="w-full bg-slate-800 border border-slate-700 text-slate-200 text-xs rounded-xl p-2.5 focus:outline-none focus:border-[#EB3E0B] font-medium">
                            <option value="">All Statuses</option>
                            <option value="Scheduled" <?php echo ($filter_status === 'Scheduled') ? 'selected' : ''; ?>>Scheduled</option>
                            <option value="In Progress" <?php echo ($filter_status === 'In Progress') ? 'selected' : ''; ?>>In Progress</option>
                            <option value="Completed" <?php echo ($filter_status === 'Completed') ? 'selected' : ''; ?>>Completed</option>
                            <option value="Cancelled" <?php echo ($filter_status === 'Cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>

                    <!-- Search Box -->
                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Search Keywords</label>
                        <input type="text" name="q" value="<?php echo sanitize($filter_q); ?>" placeholder="Search title, client, place..." class="w-full bg-slate-800 border border-slate-700 text-slate-200 text-xs rounded-xl p-2.5 focus:outline-none focus:border-[#EB3E0B] font-medium">
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex items-end space-x-2">
                        <button type="submit" class="w-full bg-slate-800 hover:bg-[#EB3E0B] text-white text-xs font-bold py-2.5 rounded-xl border border-slate-700 transition-all flex items-center justify-center space-x-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            <span>Filter</span>
                        </button>
                        <?php if (!empty($filter_tech) || !empty($filter_type) || !empty($filter_status) || !empty($filter_q)): ?>
                            <a href="events.php" class="bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white text-xs font-bold p-2.5 rounded-xl border border-slate-700 transition-all" title="Reset Filters">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Calendar & Agenda Grid Layout -->
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">

                <!-- Main Interactive Calendar (3 Cols) -->
                <div class="lg:col-span-3 bg-slate-900 border border-slate-800 rounded-3xl p-5 sm:p-6 shadow-2xl space-y-4">
                    <div id="calendar" class="min-h-[640px]"></div>
                </div>

                <!-- Right Sidebar: Today's Agenda & Legend (1 Col) -->
                <div class="space-y-6">

                    <!-- Category Legend Card -->
                    <div class="bg-slate-900/90 border border-slate-800 rounded-3xl p-5 shadow-xl space-y-3 no-print">
                        <h3 class="text-xs font-extrabold text-white uppercase tracking-wider border-b border-slate-800 pb-2">
                            Event Categories
                        </h3>
                        <div class="space-y-2 text-xs">
                            <div class="flex items-center space-x-2">
                                <span class="w-3 h-3 rounded-full bg-[#EB3E0B] shrink-0"></span>
                                <span class="text-slate-300 font-medium">Field Visit</span>
                            </div>
                            <div class="flex items-center space-x-2">
                                <span class="w-3 h-3 rounded-full bg-[#059669] shrink-0"></span>
                                <span class="text-slate-300 font-medium">POS Installation</span>
                            </div>
                            <div class="flex items-center space-x-2">
                                <span class="w-3 h-3 rounded-full bg-[#2563EB] shrink-0"></span>
                                <span class="text-slate-300 font-medium">POS Maintenance</span>
                            </div>
                            <div class="flex items-center space-x-2">
                                <span class="w-3 h-3 rounded-full bg-[#7C3AED] shrink-0"></span>
                                <span class="text-slate-300 font-medium">Hardware Delivery</span>
                            </div>
                            <div class="flex items-center space-x-2">
                                <span class="w-3 h-3 rounded-full bg-[#DC2626] shrink-0"></span>
                                <span class="text-slate-300 font-medium">Urgent Troubleshooting</span>
                            </div>
                            <div class="flex items-center space-x-2">
                                <span class="w-3 h-3 rounded-full bg-[#0891B2] shrink-0"></span>
                                <span class="text-slate-300 font-medium">System Upgrade</span>
                            </div>
                            <div class="flex items-center space-x-2">
                                <span class="w-3 h-3 rounded-full bg-[#D97706] shrink-0"></span>
                                <span class="text-slate-300 font-medium">Meeting / Conference</span>
                            </div>
                        </div>
                    </div>

                    <!-- Today's Schedule Card -->
                    <div class="bg-slate-900/90 border border-slate-800 rounded-3xl p-5 shadow-xl space-y-4">
                        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                            <div>
                                <h3 class="text-sm font-extrabold text-white">Today's Schedule</h3>
                                <p class="text-[10px] text-slate-400 font-mono"><?php echo date('l, M d, Y'); ?></p>
                            </div>
                            <span class="w-6 h-6 rounded-full bg-[#EB3E0B]/20 text-[#EB3E0B] font-bold text-xs flex items-center justify-center">
                                <?php echo count($today_events_list); ?>
                            </span>
                        </div>

                        <?php if (empty($today_events_list)): ?>
                            <div class="p-6 text-center text-slate-500 space-y-1">
                                <svg class="w-8 h-8 mx-auto text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <p class="text-xs font-bold">No appointments for today.</p>
                                <p class="text-[11px]">Click on any day in the calendar to schedule a new visit.</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-2.5 max-h-96 overflow-y-auto pr-1">
                                <?php foreach ($today_events_list as $tev): 
                                    $tmeta = get_event_type_meta($tev['event_type']);
                                ?>
                                    <div class="p-3 bg-slate-800/80 hover:bg-slate-800 rounded-2xl border border-slate-700/80 transition-all cursor-pointer space-y-1.5" onclick="viewEventDetails(<?php echo $tev['id']; ?>)">
                                        <div class="flex items-center justify-between">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold border <?php echo $tmeta['badge_class']; ?>">
                                                <?php echo sanitize($tev['event_type']); ?>
                                            </span>
                                            <span class="text-[10px] font-mono text-slate-400">
                                                <?php echo date('h:i A', strtotime($tev['start_datetime'])); ?>
                                            </span>
                                        </div>
                                        <h4 class="text-xs font-bold text-white leading-snug truncate" title="<?php echo sanitize($tev['title']); ?>">
                                            <?php echo sanitize($tev['title']); ?>
                                        </h4>
                                        <div class="flex items-center justify-between text-[11px] text-slate-400">
                                            <span class="truncate max-w-[120px] text-slate-300"><?php echo sanitize(!empty($tev['client_name']) ? $tev['client_name'] : 'General'); ?></span>
                                            <span class="text-[10px] font-mono text-orange-400"><?php echo sanitize(!empty($tev['assigned_tech']) ? $tev['assigned_tech'] : 'Unassigned'); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Upcoming Schedules Card -->
                    <?php if (!empty($upcoming_events_list)): ?>
                        <div class="bg-slate-900/90 border border-slate-800 rounded-3xl p-5 shadow-xl space-y-3 no-print">
                            <h3 class="text-xs font-extrabold text-white uppercase tracking-wider border-b border-slate-800 pb-2">
                                Upcoming Next
                            </h3>
                            <div class="space-y-2 max-h-72 overflow-y-auto pr-1">
                                <?php foreach ($upcoming_events_list as $uev): ?>
                                    <div class="p-2.5 bg-slate-800/50 hover:bg-slate-800 rounded-xl border border-slate-700/50 transition-all cursor-pointer flex items-center justify-between text-xs" onclick="viewEventDetails(<?php echo $uev['id']; ?>)">
                                        <div class="space-y-0.5 truncate">
                                            <p class="font-bold text-white truncate"><?php echo sanitize($uev['title']); ?></p>
                                            <p class="text-[10px] text-slate-400 font-mono"><?php echo date('M d, D \a\t h:i A', strtotime($uev['start_datetime'])); ?></p>
                                        </div>
                                        <span class="text-[10px] font-mono text-slate-300 font-bold bg-slate-700 px-2 py-0.5 rounded-lg shrink-0">
                                            <?php echo sanitize($uev['assigned_tech']); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>

            </div>

        </main>
    </div>
</div>

<!-- ========================================================================= -->
<!-- 1. CREATE NEW EVENT / SCHEDULE MODAL -->
<!-- ========================================================================= -->
<div id="newEventModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/75 backdrop-blur-sm p-4 overflow-y-auto">
    <div class="bg-slate-900 rounded-3xl max-w-xl w-full p-6 sm:p-8 shadow-2xl border border-slate-800 transform transition-all space-y-5 my-8">
        
        <div class="flex items-center justify-between border-b border-slate-800 pb-4">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-2xl bg-[#EB3E0B]/20 text-[#EB3E0B] border border-[#EB3E0B]/30 flex items-center justify-center font-bold shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                </div>
                <div>
                    <h3 class="text-base font-extrabold text-white">Schedule New Event</h3>
                    <p class="text-xs text-slate-400">Book staff visit, maintenance, or appointment</p>
                </div>
            </div>
            <button type="button" onclick="closeNewEventModal()" class="text-slate-400 hover:text-white p-1 rounded-xl hover:bg-slate-800 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form method="POST" action="events.php" class="space-y-4 text-xs">
            <input type="hidden" name="action" value="create_event">

            <!-- Title -->
            <div>
                <label class="block text-slate-300 font-bold uppercase tracking-wider text-[10px] mb-1">Event Title *</label>
                <input type="text" name="title" id="new_event_title" required placeholder="e.g. POS Terminal Setup & Training" class="w-full bg-slate-800 border border-slate-700 text-white rounded-xl p-3 focus:outline-none focus:border-[#EB3E0B] font-semibold">
            </div>

            <!-- Event Category & Priority -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-slate-300 font-bold uppercase tracking-wider text-[10px] mb-1">Event Category *</label>
                    <select name="event_type" required class="w-full bg-slate-800 border border-slate-700 text-white rounded-xl p-3 focus:outline-none focus:border-[#EB3E0B] font-medium">
                        <option value="Field Visit">Field Visit</option>
                        <option value="POS Installation">POS Installation</option>
                        <option value="POS Maintenance">POS Maintenance</option>
                        <option value="Hardware Delivery">Hardware Delivery</option>
                        <option value="Urgent Troubleshooting">Urgent Troubleshooting</option>
                        <option value="System Upgrade">System Upgrade</option>
                        <option value="Meeting / Conference">Meeting / Conference</option>
                        <option value="General Reminder">General Reminder</option>
                    </select>
                </div>
                <div>
                    <label class="block text-slate-300 font-bold uppercase tracking-wider text-[10px] mb-1">Priority Level</label>
                    <select name="priority" class="w-full bg-slate-800 border border-slate-700 text-white rounded-xl p-3 focus:outline-none focus:border-[#EB3E0B] font-medium">
                        <option value="Low">Low Priority</option>
                        <option value="Medium" selected>Medium Priority</option>
                        <option value="High">High Priority</option>
                        <option value="Urgent">Urgent Priority</option>
                    </select>
                </div>
            </div>

            <!-- Client Account Link -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-slate-300 font-bold uppercase tracking-wider text-[10px] mb-1">Client Account (Optional)</label>
                    <select name="accountnum" id="new_event_accountnum" onchange="autoFillClientDetails(this.value, 'new')" class="w-full bg-slate-800 border border-slate-700 text-white rounded-xl p-3 focus:outline-none focus:border-[#EB3E0B] font-medium">
                        <option value="">-- No Specific Client --</option>
                        <?php foreach ($all_accounts as $ca): 
                            $c_disp = !empty($ca['tradename']) ? $ca['tradename'] : $ca['clientname'];
                        ?>
                            <option value="<?php echo sanitize($ca['accountnum']); ?>" data-name="<?php echo sanitize($c_disp); ?>" data-address="<?php echo sanitize($ca['address']); ?>">
                                <?php echo sanitize($c_disp); ?> (#<?php echo sanitize($ca['accountnum']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="client_name" id="new_event_client_name" value="">
                </div>

                <div>
                    <label class="block text-slate-300 font-bold uppercase tracking-wider text-[10px] mb-1">Assigned Technician *</label>
                    <select name="assigned_tech" required class="w-full bg-slate-800 border border-slate-700 text-white rounded-xl p-3 focus:outline-none focus:border-[#EB3E0B] font-medium">
                        <option value="">-- Select Staff --</option>
                        <?php foreach ($all_technicians as $t): 
                            $t_name = trim($t['fname'] . ' ' . $t['lname']);
                        ?>
                            <option value="<?php echo sanitize($t_name); ?>" <?php echo ($t_name === $tech_fullname) ? 'selected' : ''; ?>>
                                <?php echo sanitize($t_name); ?> (<?php echo sanitize($t['accesslevel']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Location / Address -->
            <div>
                <label class="block text-slate-300 font-bold uppercase tracking-wider text-[10px] mb-1">Service Location / Address</label>
                <input type="text" name="location" id="new_event_location" placeholder="e.g. Branch 2, SM Mall POS Counter" class="w-full bg-slate-800 border border-slate-700 text-white rounded-xl p-3 focus:outline-none focus:border-[#EB3E0B] font-medium">
            </div>

            <!-- Start & End Date Time -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-slate-300 font-bold uppercase tracking-wider text-[10px] mb-1">Start Date &amp; Time *</label>
                    <input type="datetime-local" name="start_datetime" id="new_event_start" required class="w-full bg-slate-800 border border-slate-700 text-white rounded-xl p-3 focus:outline-none focus:border-[#EB3E0B] font-mono text-xs">
                </div>
                <div>
                    <label class="block text-slate-300 font-bold uppercase tracking-wider text-[10px] mb-1">End Date &amp; Time</label>
                    <input type="datetime-local" name="end_datetime" id="new_event_end" class="w-full bg-slate-800 border border-slate-700 text-white rounded-xl p-3 focus:outline-none focus:border-[#EB3E0B] font-mono text-xs">
                </div>
            </div>

            <div class="flex items-center space-x-2">
                <input type="checkbox" name="all_day" id="new_event_allday" value="1" class="w-4 h-4 rounded text-[#EB3E0B] bg-slate-800 border-slate-700 focus:ring-[#EB3E0B]">
                <label for="new_event_allday" class="text-xs text-slate-300 font-medium">All-Day Event (No specific hours)</label>
            </div>

            <!-- Description / Instructions -->
            <div>
                <label class="block text-slate-300 font-bold uppercase tracking-wider text-[10px] mb-1">Notes / Service Instructions</label>
                <textarea name="description" rows="3" placeholder="Provide details, scope of work, or customer special requests..." class="w-full bg-slate-800 border border-slate-700 text-white rounded-xl p-3 focus:outline-none focus:border-[#EB3E0B] font-medium text-xs leading-relaxed"></textarea>
            </div>

            <!-- Access Tier Security Check for Level 2 -->
            <?php if ($my_tier === 2): ?>
                <div class="p-3.5 bg-amber-950/40 border border-amber-800/80 rounded-2xl text-xs text-amber-200 space-y-2">
                    <div class="flex items-center space-x-1.5 font-bold text-[11px] text-amber-400">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        <span>Security Level 2 Verification</span>
                    </div>
                    <p class="text-[11px] text-amber-300/80 leading-snug">Enter your security access code to schedule this event.</p>
                    <input type="password" name="action_access_code" placeholder="Enter security access code" required class="w-full bg-slate-900 border border-amber-700 text-white text-xs px-3 py-2 rounded-xl focus:outline-none focus:border-[#EB3E0B] font-mono tracking-widest text-center">
                </div>
            <?php endif; ?>

            <div class="flex items-center justify-end space-x-2.5 pt-3 border-t border-slate-800">
                <button type="button" onclick="closeNewEventModal()" class="px-4 py-2.5 rounded-2xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold text-xs transition-colors">
                    Cancel
                </button>
                <button type="submit" class="px-6 py-2.5 rounded-2xl bg-[#EB3E0B] hover:bg-[#C32C0B] active:scale-95 text-white font-bold text-xs transition-all shadow-md shadow-[#EB3E0B]/25 flex items-center space-x-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    <span>Save Schedule</span>
                </button>
            </div>
        </form>

    </div>
</div>

<!-- ========================================================================= -->
<!-- 2. VIEW / EDIT EVENT DETAILS MODAL -->
<!-- ========================================================================= -->
<div id="viewEventModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/75 backdrop-blur-sm p-4 overflow-y-auto">
    <div class="bg-slate-900 rounded-3xl max-w-xl w-full p-6 sm:p-8 shadow-2xl border border-slate-800 transform transition-all space-y-5 my-8">
        
        <div class="flex items-center justify-between border-b border-slate-800 pb-4">
            <div class="flex items-center space-x-3">
                <div id="view_event_icon" class="w-10 h-10 rounded-2xl bg-orange-500/20 text-orange-400 border border-orange-500/30 flex items-center justify-center font-bold shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                </div>
                <div>
                    <h3 id="view_event_title_display" class="text-base font-extrabold text-white">Event Particulars</h3>
                    <p id="view_event_time_display" class="text-xs text-slate-400 font-mono"></p>
                </div>
            </div>
            <button type="button" onclick="closeViewEventModal()" class="text-slate-400 hover:text-white p-1 rounded-xl hover:bg-slate-800 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form method="POST" action="events.php" class="space-y-4 text-xs">
            <input type="hidden" name="action" value="update_event">
            <input type="hidden" name="event_id" id="edit_event_id" value="">

            <!-- Title -->
            <div>
                <label class="block text-slate-300 font-bold uppercase tracking-wider text-[10px] mb-1">Title *</label>
                <input type="text" name="title" id="edit_event_title" required class="w-full bg-slate-800 border border-slate-700 text-white rounded-xl p-3 focus:outline-none focus:border-[#EB3E0B] font-semibold">
            </div>

            <!-- Category & Status -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-slate-300 font-bold uppercase tracking-wider text-[10px] mb-1">Category *</label>
                    <select name="event_type" id="edit_event_type" required class="w-full bg-slate-800 border border-slate-700 text-white rounded-xl p-3 focus:outline-none focus:border-[#EB3E0B] font-medium">
                        <option value="Field Visit">Field Visit</option>
                        <option value="POS Installation">POS Installation</option>
                        <option value="POS Maintenance">POS Maintenance</option>
                        <option value="Hardware Delivery">Hardware Delivery</option>
                        <option value="Urgent Troubleshooting">Urgent Troubleshooting</option>
                        <option value="System Upgrade">System Upgrade</option>
                        <option value="Meeting / Conference">Meeting / Conference</option>
                        <option value="General Reminder">General Reminder</option>
                    </select>
                </div>
                <div>
                    <label class="block text-slate-300 font-bold uppercase tracking-wider text-[10px] mb-1">Status</label>
                    <select name="status" id="edit_event_status" class="w-full bg-slate-800 border border-slate-700 text-white rounded-xl p-3 focus:outline-none focus:border-[#EB3E0B] font-medium">
                        <option value="Scheduled">Scheduled</option>
                        <option value="In Progress">In Progress</option>
                        <option value="Completed">Completed</option>
                        <option value="Cancelled">Cancelled</option>
                        <option value="Rescheduled">Rescheduled</option>
                    </select>
                </div>
            </div>

            <!-- Client & Tech -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-slate-300 font-bold uppercase tracking-wider text-[10px] mb-1">Client Account</label>
                    <select name="accountnum" id="edit_event_accountnum" onchange="autoFillClientDetails(this.value, 'edit')" class="w-full bg-slate-800 border border-slate-700 text-white rounded-xl p-3 focus:outline-none focus:border-[#EB3E0B] font-medium">
                        <option value="">-- No Specific Client --</option>
                        <?php foreach ($all_accounts as $ca): 
                            $c_disp = !empty($ca['tradename']) ? $ca['tradename'] : $ca['clientname'];
                        ?>
                            <option value="<?php echo sanitize($ca['accountnum']); ?>" data-name="<?php echo sanitize($c_disp); ?>" data-address="<?php echo sanitize($ca['address']); ?>">
                                <?php echo sanitize($c_disp); ?> (#<?php echo sanitize($ca['accountnum']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="client_name" id="edit_event_client_name" value="">
                </div>

                <div>
                    <label class="block text-slate-300 font-bold uppercase tracking-wider text-[10px] mb-1">Assigned Technician</label>
                    <select name="assigned_tech" id="edit_event_assigned_tech" class="w-full bg-slate-800 border border-slate-700 text-white rounded-xl p-3 focus:outline-none focus:border-[#EB3E0B] font-medium">
                        <option value="">-- Select Staff --</option>
                        <?php foreach ($all_technicians as $t): 
                            $t_name = trim($t['fname'] . ' ' . $t['lname']);
                        ?>
                            <option value="<?php echo sanitize($t_name); ?>">
                                <?php echo sanitize($t_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Location -->
            <div>
                <label class="block text-slate-300 font-bold uppercase tracking-wider text-[10px] mb-1">Location / Address</label>
                <input type="text" name="location" id="edit_event_location" placeholder="e.g. Main Branch" class="w-full bg-slate-800 border border-slate-700 text-white rounded-xl p-3 focus:outline-none focus:border-[#EB3E0B] font-medium">
            </div>

            <!-- Start & End Date Time -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-slate-300 font-bold uppercase tracking-wider text-[10px] mb-1">Start Date &amp; Time *</label>
                    <input type="datetime-local" name="start_datetime" id="edit_event_start" required class="w-full bg-slate-800 border border-slate-700 text-white rounded-xl p-3 focus:outline-none focus:border-[#EB3E0B] font-mono text-xs">
                </div>
                <div>
                    <label class="block text-slate-300 font-bold uppercase tracking-wider text-[10px] mb-1">End Date &amp; Time</label>
                    <input type="datetime-local" name="end_datetime" id="edit_event_end" class="w-full bg-slate-800 border border-slate-700 text-white rounded-xl p-3 focus:outline-none focus:border-[#EB3E0B] font-mono text-xs">
                </div>
            </div>

            <!-- Priority -->
            <div>
                <label class="block text-slate-300 font-bold uppercase tracking-wider text-[10px] mb-1">Priority</label>
                <select name="priority" id="edit_event_priority" class="w-full bg-slate-800 border border-slate-700 text-white rounded-xl p-3 focus:outline-none focus:border-[#EB3E0B] font-medium">
                    <option value="Low">Low</option>
                    <option value="Medium">Medium</option>
                    <option value="High">High</option>
                    <option value="Urgent">Urgent</option>
                </select>
            </div>

            <!-- Notes -->
            <div>
                <label class="block text-slate-300 font-bold uppercase tracking-wider text-[10px] mb-1">Service Notes &amp; Dispatch Instructions</label>
                <textarea name="description" id="edit_event_description" rows="3" class="w-full bg-slate-800 border border-slate-700 text-white rounded-xl p-3 focus:outline-none focus:border-[#EB3E0B] font-medium text-xs leading-relaxed"></textarea>
            </div>

            <!-- Meta created info -->
            <div class="p-3 bg-slate-950/50 rounded-xl border border-slate-800 text-[11px] text-slate-400 flex items-center justify-between">
                <span>Created by: <strong id="view_event_creator" class="text-slate-200"></strong></span>
                <span id="view_event_created_at" class="font-mono text-[10px]"></span>
            </div>

            <!-- Level 2 Code Input -->
            <?php if ($my_tier === 2): ?>
                <div class="p-3.5 bg-amber-950/40 border border-amber-800/80 rounded-2xl text-xs text-amber-200 space-y-2">
                    <div class="flex items-center space-x-1.5 font-bold text-[11px] text-amber-400">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        <span>Security Level 2 Verification</span>
                    </div>
                    <p class="text-[11px] text-amber-300/80 leading-snug">Enter your security access code to save changes.</p>
                    <input type="password" name="action_access_code" placeholder="Enter security access code" required class="w-full bg-slate-900 border border-amber-700 text-white text-xs px-3 py-2 rounded-xl focus:outline-none focus:border-[#EB3E0B] font-mono tracking-widest text-center">
                </div>
            <?php endif; ?>

            <div class="flex items-center justify-between pt-3 border-t border-slate-800">
                <?php if ($my_tier !== 1): ?>
                    <button type="button" onclick="triggerDeleteFromEditModal()" class="px-4 py-2.5 rounded-2xl bg-rose-950 hover:bg-rose-900 text-rose-300 hover:text-white font-bold text-xs transition-colors border border-rose-800 flex items-center space-x-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        <span>Delete</span>
                    </button>
                <?php else: ?>
                    <div></div>
                <?php endif; ?>

                <div class="flex items-center space-x-2">
                    <button type="button" onclick="closeViewEventModal()" class="px-4 py-2.5 rounded-2xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold text-xs transition-colors">
                        Close
                    </button>
                    <?php if ($my_tier !== 1): ?>
                        <button type="submit" class="px-5 py-2.5 rounded-2xl bg-[#EB3E0B] hover:bg-[#C32C0B] active:scale-95 text-white font-bold text-xs transition-all shadow-md shadow-[#EB3E0B]/25 flex items-center space-x-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <span>Save Changes</span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </form>

    </div>
</div>

<!-- ========================================================================= -->
<!-- 3. TIER-PROTECTED DELETE EVENT MODAL -->
<!-- ========================================================================= -->
<div id="deleteEventModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/80 backdrop-blur-sm p-4">
    <div class="bg-slate-900 rounded-3xl max-w-md w-full p-6 shadow-2xl border border-slate-800 transform transition-all space-y-5">
        
        <div class="flex items-center space-x-3.5">
            <div class="w-12 h-12 rounded-2xl bg-rose-950/80 text-rose-400 border border-rose-800 flex items-center justify-center font-bold shrink-0">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            </div>
            <div>
                <h3 class="text-base font-extrabold text-white">Delete Scheduled Event</h3>
                <p class="text-xs text-slate-400">Permanent schedule cancellation</p>
            </div>
        </div>

        <div class="p-3.5 bg-slate-950 rounded-2xl border border-slate-800 space-y-1.5 text-xs">
            <span class="text-slate-500 font-bold uppercase text-[10px]">Event Title</span>
            <p id="del_event_title" class="font-bold text-white text-xs truncate"></p>
        </div>

        <p class="text-xs text-slate-300 leading-relaxed">
            Are you sure you want to delete this event schedule? This will permanently remove it from the interactive calendar and dispatch agenda.
        </p>

        <form method="POST" action="events.php" class="space-y-4">
            <input type="hidden" name="action" value="delete_event">
            <input type="hidden" name="event_id" id="del_event_id" value="">

            <?php if ($my_tier === 1): ?>
                <div class="p-3.5 bg-rose-950/50 border border-rose-800 rounded-2xl text-xs text-rose-300 leading-relaxed flex items-start space-x-2">
                    <svg class="w-4 h-4 text-rose-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    <span><strong>Action Disabled:</strong> Level 1 (View Only) accounts are not permitted to delete event schedules.</span>
                </div>
            <?php elseif ($my_tier === 2): ?>
                <div class="p-3.5 bg-amber-950/40 border border-amber-800/80 rounded-2xl text-xs text-amber-200 space-y-2">
                    <div class="flex items-center space-x-1.5 font-bold text-[11px] text-amber-400">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        <span>Security Level 2 Verification</span>
                    </div>
                    <p class="text-[11px] text-amber-300/80 leading-snug">Enter your security access code to authorize this deletion.</p>
                    <input type="password" name="action_access_code" id="del_access_code" placeholder="Enter security access code" required class="w-full bg-slate-950 border border-amber-700 text-white text-xs px-3 py-2 rounded-xl focus:outline-none focus:border-[#EB3E0B] font-mono tracking-widest text-center">
                </div>
            <?php else: ?>
                <div class="text-[11px] text-slate-400 font-mono flex items-center gap-1.5 p-2 bg-slate-950 rounded-xl border border-slate-800">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                    <span>Authorized for Direct Deletion (Level 3 Tier)</span>
                </div>
            <?php endif; ?>

            <div class="flex items-center justify-end space-x-2 pt-2">
                <button type="button" onclick="closeDeleteEventModal()" class="px-4 py-2.5 rounded-2xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold text-xs transition-colors">
                    Cancel
                </button>
                <?php if ($my_tier !== 1): ?>
                    <button type="submit" class="px-5 py-2.5 rounded-2xl bg-rose-600 hover:bg-rose-700 active:scale-95 text-white font-bold text-xs transition-all shadow-md shadow-rose-600/25 flex items-center space-x-1.5">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        <span>Confirm Deletion</span>
                    </button>
                <?php endif; ?>
            </div>
        </form>

    </div>
</div>

<!-- ========================================================================= -->
<!-- JAVASCRIPT INITIALIZATION & CALENDAR LOGIC -->
<!-- ========================================================================= -->
<script>
var rawEventsData = <?php echo json_encode($calendar_events); ?>;
var myAccessTier = <?php echo intval($my_tier); ?>;
var calendarInstance = null;

document.addEventListener('DOMContentLoaded', function() {
    var calendarEl = document.getElementById('calendar');
    if (!calendarEl) return;

    calendarInstance = new FullCalendar.Calendar(calendarEl, {
        initialView: window.innerWidth < 768 ? 'listMonth' : 'dayGridMonth',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay,listMonth'
        },
        buttonText: {
            today: 'Today',
            month: 'Month',
            week: 'Week',
            day: 'Day',
            list: 'Schedule List'
        },
        events: rawEventsData,
        navLinks: true,
        editable: false,
        selectable: true,
        selectMirror: true,
        dayMaxEvents: 3,
        nowIndicator: true,
        eventTimeFormat: {
            hour: '2-digit',
            minute: '2-digit',
            meridiem: 'short'
        },
        dateClick: function(info) {
            if (myAccessTier === 1) return;
            openNewEventModal(info.dateStr);
        },
        eventClick: function(info) {
            info.jsEvent.preventDefault();
            var ev = info.event;
            viewEventDetails(ev.id);
        }
    });

    calendarInstance.render();
});

// Auto-fill client name and address when account selected
function autoFillClientDetails(accountnum, mode) {
    var sel = document.getElementById(mode + '_event_accountnum');
    if (!sel) return;
    var opt = sel.options[sel.selectedIndex];
    var cname = opt ? opt.getAttribute('data-name') : '';
    var caddr = opt ? opt.getAttribute('data-address') : '';

    var nameInput = document.getElementById(mode + '_event_client_name');
    var locInput = document.getElementById(mode + '_event_location');

    if (nameInput) nameInput.value = cname || '';
    if (locInput && (!locInput.value || mode === 'new')) {
        locInput.value = caddr || '';
    }
}

// Modal open/close handlers
function openNewEventModal(dateStr) {
    if (myAccessTier === 1) return;
    var titleEl = document.getElementById('new_event_title');
    if (titleEl) titleEl.value = '';

    var startEl = document.getElementById('new_event_start');
    var endEl = document.getElementById('new_event_end');

    if (dateStr) {
        // e.g. "2026-08-25" or "2026-08-25T14:30:00"
        var sDate = dateStr.indexOf('T') !== -1 ? dateStr.substring(0, 16) : dateStr + 'T09:00';
        var eDate = dateStr.indexOf('T') !== -1 ? dateStr.substring(0, 16) : dateStr + 'T11:00';
        if (startEl) startEl.value = sDate;
        if (endEl) endEl.value = eDate;
    } else {
        var now = new Date();
        var nowStr = now.toISOString().substring(0, 11) + '09:00';
        var endStr = now.toISOString().substring(0, 11) + '11:00';
        if (startEl) startEl.value = nowStr;
        if (endEl) endEl.value = endStr;
    }

    var modal = document.getElementById('newEventModal');
    if (modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
}

function closeNewEventModal() {
    var modal = document.getElementById('newEventModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
}

function viewEventDetails(eventId) {
    var ev = rawEventsData.find(function(item) { return item.id == eventId; });
    if (!ev) return;

    var props = ev.extendedProps || {};
    
    document.getElementById('edit_event_id').value = ev.id;
    document.getElementById('edit_event_title').value = ev.title || '';
    document.getElementById('view_event_title_display').textContent = ev.title || 'Event Particulars';
    document.getElementById('view_event_time_display').textContent = (props.start_formatted || '') + ' - ' + (props.end_formatted || '');
    
    document.getElementById('edit_event_type').value = props.event_type || 'Field Visit';
    document.getElementById('edit_event_status').value = props.status || 'Scheduled';
    document.getElementById('edit_event_priority').value = props.priority || 'Medium';
    document.getElementById('edit_event_accountnum').value = props.accountnum || '';
    document.getElementById('edit_event_client_name').value = props.client_name || '';
    document.getElementById('edit_event_assigned_tech').value = props.assigned_tech || '';
    document.getElementById('edit_event_location').value = props.location || '';
    document.getElementById('edit_event_start').value = props.start_raw || '';
    document.getElementById('edit_event_end').value = props.end_raw || '';
    document.getElementById('edit_event_description').value = props.description || '';
    
    document.getElementById('view_event_creator').textContent = props.created_by || 'Staff';
    document.getElementById('view_event_created_at').textContent = props.created_at || '';

    var modal = document.getElementById('viewEventModal');
    if (modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
}

function closeViewEventModal() {
    var modal = document.getElementById('viewEventModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
}

function triggerDeleteFromEditModal() {
    var eventId = document.getElementById('edit_event_id').value;
    var title = document.getElementById('edit_event_title').value;
    closeViewEventModal();
    openDeleteEventModal(eventId, title);
}

function openDeleteEventModal(eventId, title) {
    document.getElementById('del_event_id').value = eventId;
    document.getElementById('del_event_title').textContent = title || 'Event Schedule';
    var codeInput = document.getElementById('del_access_code');
    if (codeInput) codeInput.value = '';

    var modal = document.getElementById('deleteEventModal');
    if (modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
}

function closeDeleteEventModal() {
    var modal = document.getElementById('deleteEventModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
}

// Escape key to close modals
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeNewEventModal();
        closeViewEventModal();
        closeDeleteEventModal();
    }
});

<?php if (isset($_GET['new']) || (isset($_GET['action']) && $_GET['action'] === 'new')): ?>
document.addEventListener('DOMContentLoaded', function() {
    openNewEventModal();
});
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
