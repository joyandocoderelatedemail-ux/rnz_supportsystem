<?php
// Support Tickets Center for Tech/Admin Portal (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ticket_chat_init.php';

require_page_access('tickets');

// Makes sure the read markers the unread badges rely on exist
init_ticket_chat_tables();

$pdo = get_db_connection();

// Handle quick assign, status update, or delete POST requests.
// Only the actions this page owns are gated here - the service note modal in
// the footer posts back to whatever page it was opened from and runs there.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], array('quick_update_ticket', 'delete_ticket'), true)) {
    $action = $_POST['action'];
    $action_code = isset($_POST['action_access_code']) ? trim($_POST['action_access_code']) : '';
    $perm_check = check_tech_action_permission($action_code);

    if (!$perm_check['allowed']) {
        header("Location: tickets.php?msg=error&err_msg=" . urlencode($perm_check['message']));
        exit;
    }

    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    
    // 1. Quick Update Ticket (Status / Technician)
    if ($action === 'quick_update_ticket' && $ticket_id > 0) {
        $new_status = isset($_POST['status']) ? trim($_POST['status']) : '';
        $new_tech = isset($_POST['assigned_tech']) ? trim($_POST['assigned_tech']) : '';

        $sql_parts = array();
        $params_up = array(':id' => $ticket_id);

        if (!empty($new_status)) {
            $sql_parts[] = "status = :status";
            $params_up[':status'] = $new_status;
        }
        if (!empty($new_tech)) {
            $sql_parts[] = "assigned_tech = :tech";
            $params_up[':tech'] = $new_tech;
        }

        if (!empty($sql_parts)) {
            $stmt_up = $pdo->prepare("UPDATE client_support_tickets SET " . implode(", ", $sql_parts) . " WHERE id = :id");
            $stmt_up->execute($params_up);
        }
        header("Location: tickets.php?msg=ticket_updated");
        exit;
    }

    // 2. Delete Support Ticket (Permanent Deletion)
    if ($action === 'delete_ticket' && $ticket_id > 0) {
        // Fetch ticket number & attachments for cleanup
        $stmt_tn = $pdo->prepare("SELECT ticket_number, attachment_path FROM client_support_tickets WHERE id = :id LIMIT 1");
        $stmt_tn->execute(array(':id' => $ticket_id));
        $t_row = $stmt_tn->fetch();

        if ($t_row) {
            $t_num = $t_row['ticket_number'];

            // Delete ticket main attachments if on disk
            if (!empty($t_row['attachment_path'])) {
                $main_att = __DIR__ . '/../' . ltrim($t_row['attachment_path'], '/\\');
                if (file_exists($main_att) && is_file($main_att)) {
                    @unlink($main_att);
                }
            }

            // Delete reply attachments if on disk
            try {
                $stmt_rep_att = $pdo->prepare("SELECT attachment_path FROM client_ticket_replies WHERE ticket_id = :id AND attachment_path IS NOT NULL AND attachment_path != ''");
                $stmt_rep_att->execute(array(':id' => $ticket_id));
                while ($r_att = $stmt_rep_att->fetch()) {
                    $rep_file = __DIR__ . '/../' . ltrim($r_att['attachment_path'], '/\\');
                    if (file_exists($rep_file) && is_file($rep_file)) {
                        @unlink($rep_file);
                    }
                }
            } catch (PDOException $e) {}

            // Delete reactions if table exists
            try {
                $stmt_del_rx = $pdo->prepare("DELETE FROM client_ticket_reactions WHERE ticket_id = :id");
                $stmt_del_rx->execute(array(':id' => $ticket_id));
            } catch (PDOException $e) {}

            // Delete replies
            try {
                $stmt_del_rep = $pdo->prepare("DELETE FROM client_ticket_replies WHERE ticket_id = :id");
                $stmt_del_rep->execute(array(':id' => $ticket_id));
            } catch (PDOException $e) {}

            // Delete the ticket record
            $stmt_del_tkt = $pdo->prepare("DELETE FROM client_support_tickets WHERE id = :id");
            $stmt_del_tkt->execute(array(':id' => $ticket_id));

            header("Location: tickets.php?msg=ticket_deleted&num=" . urlencode($t_num));
            exit;
        } else {
            header("Location: tickets.php?msg=error&err_msg=" . urlencode("Ticket not found or already deleted."));
            exit;
        }
    }

    header("Location: tickets.php");
    exit;
}

// Fetch technicians list from user table
$stmt_techs = $pdo->query("SELECT fname, lname, user FROM user ORDER BY fname ASC");
$tech_users = $stmt_techs->fetchAll();

// Access Level Tier for currently logged-in technician
$my_tier = get_logged_tech_access_tier();

// Notification Messages
$msg = isset($_GET['msg']) ? sanitize($_GET['msg']) : '';
$msg_type = 'success';
$msg_text = '';

if ($msg === 'ticket_deleted') {
    $num = isset($_GET['num']) ? sanitize($_GET['num']) : '';
    $msg_text = 'Support ticket ' . (!empty($num) ? '<strong>' . $num . '</strong> ' : '') . 'and its conversation thread were permanently deleted.';
} elseif ($msg === 'ticket_updated') {
    $msg_text = 'Support ticket status was updated successfully.';
} elseif ($msg === 'error') {
    $msg_type = 'error';
    $msg_text = isset($_GET['err_msg']) ? sanitize($_GET['err_msg']) : 'An error occurred during the requested operation.';
}

// Filters & Search
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$search = isset($_GET['q']) ? trim($_GET['q']) : '';

// "My Tickets": everything assigned to this technician plus everything they
// picked up themselves by moving it to In Progress from the chat.
$mine_only = (isset($_GET['mine']) && $_GET['mine'] === '1');
$me = get_logged_tech();
$my_name = ($me && isset($me['fullname'])) ? strtolower(trim($me['fullname'])) : '';
$mine_sql = "(LOWER(TRIM(IFNULL(t.assigned_tech, ''))) = :myname OR LOWER(TRIM(IFNULL(t.in_progress_by, ''))) = :myname2)";

$where_clauses = array();
$params = array();

if ($mine_only && $my_name !== '') {
    // My Tickets is a work queue, not a status filter: it lists only the
    // In Progress tickets this technician owns, so the status chips - which
    // belong to the all-tickets view - are not applied on top of it.
    $where_clauses[] = $mine_sql;
    $where_clauses[] = "t.status = 'In Progress'";
    $params[':myname'] = $my_name;
    $params[':myname2'] = $my_name;
} elseif (!empty($status_filter)) {
    $where_clauses[] = "t.status = :status";
    $params[':status'] = $status_filter;
}

if (!empty($search)) {
    $where_clauses[] = "(t.ticket_number LIKE :q OR t.subject LIKE :q OR t.issue_description LIKE :q OR c.tradename LIKE :q OR c.clientname LIKE :q)";
    $params[':q'] = "%$search%";
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Badge count for the My Tickets chip - my open workload, ignoring the search
// box so the number does not move around while browsing.
$my_ticket_count = 0;
if ($my_name !== '') {
    $stmt_mine_cnt = $pdo->prepare("SELECT COUNT(*) FROM client_support_tickets t WHERE " . $mine_sql . " AND t.status = 'In Progress'");
    $stmt_mine_cnt->execute(array(':myname' => $my_name, ':myname2' => $my_name));
    $my_ticket_count = intval($stmt_mine_cnt->fetchColumn());
}

/**
 * URL for one filter chip, carrying the other active filters along.
 *
 * @param string $status  status to filter by ('' for all)
 * @param bool   $mine    limit to the logged-in technician
 * @param string $search  current search term
 * @return string
 */
function tickets_filter_url($status, $mine, $search) {
    $p = array();
    if ($status !== '') {
        $p['status'] = $status;
    }
    if ($mine) {
        $p['mine'] = '1';
    }
    if ($search !== '') {
        $p['q'] = $search;
    }
    return 'tickets.php' . (empty($p) ? '' : '?' . http_build_query($p));
}

// 10 items per page pagination
$per_page = 10;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

// Get total count
$count_sql = "SELECT COUNT(*) 
              FROM client_support_tickets t 
              LEFT JOIN bucket_client c ON t.accountnum = c.accountnum 
              $where_sql";
$stmt_count = $pdo->prepare($count_sql);
$stmt_count->execute($params);
$total_tickets = intval($stmt_count->fetchColumn());
$total_pages = max(1, ceil($total_tickets / $per_page));

if ($current_page > $total_pages) {
    $current_page = $total_pages;
}
$offset = ($current_page - 1) * $per_page;

$sql = "SELECT t.*, c.tradename, c.clientname, c.contactnum, c.address 
        FROM client_support_tickets t 
        LEFT JOIN bucket_client c ON t.accountnum = c.accountnum 
        $where_sql 
        ORDER BY 
            CASE 
                WHEN t.status = 'Pending' THEN 1 
                WHEN t.status = 'In Progress' THEN 2 
                WHEN t.status = 'Open' THEN 3 
                WHEN t.status IN ('Resolved', 'Closed') THEN 4 
                ELSE 3 
            END ASC, 
            t.created_at DESC 
        LIMIT " . intval($offset) . ", " . intval($per_page);

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();

$start_item = $total_tickets > 0 ? $offset + 1 : 0;
$end_item = min($offset + $per_page, $total_tickets);

// Client messages nobody on the support side has opened yet, for the red badge
$page_ticket_ids = array();
foreach ($tickets as $t_row) {
    $page_ticket_ids[] = intval($t_row['id']);
}
$unread_counts = get_support_unread_counts($pdo, $page_ticket_ids);

// Ticket notifications point here with ?open_ticket=ID instead of opening the
// full console, so the thread opens in the chat pop-up over this list. The
// ticket is loaded on its own as well - a filter or a later page would
// otherwise leave nothing for the pop-up to read.
$open_ticket_id = isset($_GET['open_ticket']) ? intval($_GET['open_ticket']) : 0;
$chat_autoload_ticket = null;
// Dropped here so the pagination links below (built from $_GET) do not carry
// it along and re-open the chat on every page the tech browses to.
unset($_GET['open_ticket']);

if ($open_ticket_id > 0) {
    $stmt_open = $pdo->prepare("SELECT t.*, c.tradename, c.clientname, c.address
        FROM client_support_tickets t
        LEFT JOIN bucket_client c ON t.accountnum = c.accountnum
        WHERE t.id = :id LIMIT 1");
    $stmt_open->execute(array(':id' => $open_ticket_id));
    $open_row = $stmt_open->fetch();

    if ($open_row) {
        $open_client = !empty($open_row['tradename'])
            ? $open_row['tradename']
            : (!empty($open_row['clientname']) ? $open_row['clientname'] : 'Acct: ' . $open_row['accountnum']);

        $chat_autoload_ticket = array(
            'id' => intval($open_row['id']),
            'ticket_number' => $open_row['ticket_number'],
            'client' => $open_client,
            'subject' => $open_row['subject'],
            'status' => $open_row['status'],
            'issue' => $open_row['issue_description'],
            'created' => format_date($open_row['created_at']),
            'account' => $open_row['accountnum'],
            'tradename' => !empty($open_row['tradename']) ? $open_row['tradename'] : (!empty($open_row['clientname']) ? $open_row['clientname'] : ''),
            'address' => isset($open_row['address']) ? $open_row['address'] : ''
        );
    }
}

$active_page = 'tickets';
$page_title = 'Support Tickets Center';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Tickets Center - RNZ Admin</title>
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
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
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

            <!-- Notification Banner -->
            <?php if (!empty($msg_text)): ?>
                <div class="p-4 rounded-2xl flex items-center justify-between shadow-sm border <?php echo ($msg_type === 'success') ? 'bg-emerald-50 border-emerald-200 text-emerald-900' : 'bg-rose-50 border-rose-200 text-rose-900'; ?>">
                    <div class="flex items-center space-x-3">
                        <div class="w-8 h-8 rounded-xl flex items-center justify-center shrink-0 <?php echo ($msg_type === 'success') ? 'bg-emerald-500 text-white' : 'bg-rose-500 text-white'; ?>">
                            <?php if ($msg_type === 'success'): ?>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <?php else: ?>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs sm:text-sm font-medium"><?php echo $msg_text; ?></p>
                    </div>
                    <a href="tickets.php" class="text-xs font-bold opacity-70 hover:opacity-100 transition-opacity">Dismiss</a>
                </div>
            <?php endif; ?>

            <!-- Title & Filters Bar -->
            <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                
                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex items-center space-x-1.5 overflow-x-auto pb-1 lg:pb-0">
                        <a href="<?php echo tickets_filter_url('', false, $search); ?>" class="px-4 py-2 rounded-full text-xs font-bold transition-all whitespace-nowrap <?php echo (empty($status_filter) && !$mine_only) ? 'bg-[#EB3E0B] text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?>">
                            All Tickets
                        </a>
                        <a href="<?php echo tickets_filter_url('Pending', false, $search); ?>" class="px-4 py-2 rounded-full text-xs font-bold transition-all whitespace-nowrap <?php echo ($status_filter === 'Pending' && !$mine_only) ? 'bg-[#EB3E0B] text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?>">
                            Pending
                        </a>
                        <a href="<?php echo tickets_filter_url('In Progress', false, $search); ?>" class="px-4 py-2 rounded-full text-xs font-bold transition-all whitespace-nowrap <?php echo ($status_filter === 'In Progress' && !$mine_only) ? 'bg-[#EB3E0B] text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?>">
                            In Progress
                        </a>
                        <a href="<?php echo tickets_filter_url('Resolved', false, $search); ?>" class="px-4 py-2 rounded-full text-xs font-bold transition-all whitespace-nowrap <?php echo ($status_filter === 'Resolved' && !$mine_only) ? 'bg-[#EB3E0B] text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?>">
                            Resolved
                        </a>

                        <!-- My open workload: In Progress tickets assigned to me,
                             or that I picked up myself -->
                        <a href="<?php echo tickets_filter_url('', !$mine_only, $search); ?>"
                           title="<?php echo $mine_only ? 'Show tickets from the whole team' : 'Only In Progress tickets assigned to you or that you set to In Progress'; ?>"
                           class="px-4 py-2 rounded-full text-xs font-bold transition-all whitespace-nowrap flex items-center gap-1.5 ml-1 border <?php echo $mine_only ? 'bg-slate-900 text-white border-slate-900 shadow-sm' : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-100'; ?>">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                            </svg>
                            <span>My Tickets</span>
                            <span class="px-1.5 py-0.5 rounded-full text-[10px] font-extrabold <?php echo $mine_only ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600'; ?>"><?php echo $my_ticket_count; ?></span>
                        </a>
                    </div>
                    
                    <div class="pl-2 border-l border-slate-200 hidden sm:block">
                        <?php echo get_tier_badge($my_tier); ?>
                    </div>
                </div>

                <form action="tickets.php" method="GET" class="w-full lg:w-72">
                    <!-- Searching stays inside whichever view is open -->
                    <?php if ($mine_only): ?>
                        <input type="hidden" name="mine" value="1">
                    <?php elseif (!empty($status_filter)): ?>
                        <input type="hidden" name="status" value="<?php echo sanitize($status_filter); ?>">
                    <?php endif; ?>
                    <div class="relative">
                        <input type="text" name="q" value="<?php echo sanitize($search); ?>" placeholder="Search ticket #, client, problem..." class="w-full bg-slate-50 text-slate-800 text-xs pl-9 pr-4 py-2.5 rounded-full border border-slate-200 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                        <svg class="w-4 h-4 text-slate-400 absolute left-3 top-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </div>
                </form>
            </div>

            <!-- Support Tickets Table -->
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-100 text-slate-500 font-bold uppercase tracking-wider text-[11px]">
                                <th class="py-4 px-6">Ticket #</th>
                                <th class="py-4 px-6">Trade Name</th>
                                <th class="py-4 px-6">Subject / Summary</th>
                                <th class="py-4 px-6">Status</th>
                                <th class="py-4 px-6">Created Date</th>
                                <th class="py-4 px-6 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium">
                            <?php if (empty($tickets)): ?>
                                <tr>
                                    <td colspan="6" class="py-12 text-center text-slate-400 space-y-2">
                                        <?php if ($mine_only): ?>
                                            <p class="font-bold text-sm">You have no tickets in progress.</p>
                                            <p class="text-xs">A ticket lands here once it is assigned to you, or once you set it to In Progress.</p>
                                        <?php else: ?>
                                            <p class="font-bold text-sm">No support tickets found.</p>
                                            <p class="text-xs">Adjust your search filter or clear status selection.</p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($tickets as $t):
                                    $client_name = !empty($t['tradename']) ? $t['tradename'] : (!empty($t['clientname']) ? $t['clientname'] : 'Acct: ' . $t['accountnum']);
                                    // Real trade name only - the service note form wants the client record,
                                    // not the "Acct: 000..." placeholder the table falls back to.
                                    $note_client = !empty($t['tradename']) ? $t['tradename'] : (!empty($t['clientname']) ? $t['clientname'] : '');
                                    $st = $t['status'];

                                    $pal = ticket_row_palette($st);
                                ?>
                                    <tr class="ticket-row <?php echo $pal['row']; ?> transition-colors cursor-pointer"
                                        onclick="openTicketChat(this, event)"
                                        title="Open chat thread"
                                        data-ticket-id="<?php echo intval($t['id']); ?>"
                                        data-ticket-number="<?php echo sanitize($t['ticket_number']); ?>"
                                        data-client="<?php echo sanitize($client_name); ?>"
                                        data-subject="<?php echo sanitize($t['subject']); ?>"
                                        data-status="<?php echo sanitize($t['status']); ?>"
                                        data-issue="<?php echo sanitize($t['issue_description']); ?>"
                                        data-created="<?php echo sanitize(format_date($t['created_at'])); ?>"
                                        data-account="<?php echo sanitize($t['accountnum']); ?>"
                                        data-tradename="<?php echo sanitize($note_client); ?>"
                                        data-address="<?php echo sanitize(isset($t['address']) ? $t['address'] : ''); ?>">
                                        <td data-cell="num" class="py-4 px-6 font-mono font-bold <?php echo $pal['num']; ?>">
                                            <div class="flex items-center gap-1.5">
                                                <span><?php echo sanitize($t['ticket_number']); ?></span>
                                                <?php $t_unread = isset($unread_counts[intval($t['id'])]) ? $unread_counts[intval($t['id'])] : 0; ?>
                                                <?php if ($t_unread > 0): ?>
                                                    <span data-cell="unread" data-unread-count="<?php echo $t_unread; ?>" title="<?php echo $t_unread; ?> unread client message<?php echo ($t_unread > 1) ? 's' : ''; ?>"
                                                          class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full bg-rose-600 text-white text-[9px] font-extrabold shadow-sm shadow-rose-600/30 shrink-0">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-white animate-pulse"></span>
                                                        <?php echo $t_unread; ?> new
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="py-4 px-6">
                                            <div data-cell="title" class="font-bold <?php echo $pal['title']; ?>"><?php echo sanitize($client_name); ?></div>
                                        </td>
                                        <td data-cell="subject" class="py-4 px-6 max-w-xs truncate font-semibold <?php echo $pal['subj']; ?>">
                                            <?php echo sanitize($t['subject']); ?>
                                        </td>
                                        <td class="py-4 px-6">
                                            <span data-cell="badge" class="inline-flex items-center justify-center whitespace-nowrap px-2.5 py-1 rounded-full text-[10px] font-bold border <?php echo $pal['badge']; ?>"><?php echo sanitize($t['status']); ?></span>
                                        </td>

                                        <td data-cell="date" class="py-4 px-6 <?php echo $pal['date']; ?>">
                                            <?php echo format_date($t['created_at']); ?>
                                        </td>

                                        <td class="py-4 px-6 text-right">
                                            <div class="flex items-center justify-end space-x-1.5">
                                                <!-- Log Technician Service Note (pre-filled from this ticket) -->
                                                <button type="button" onclick="openTicketTechNote(this)" data-cell="note" class="inline-flex items-center justify-center w-9 h-9 rounded-full <?php echo $pal['btn']; ?> transition-colors shadow-xs" title="Log Technician Service Note">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                                    </svg>
                                                </button>

                                                <!-- Delete Ticket Button (Opens Modal) -->
                                                <button type="button" onclick="openDeleteTicketModal(<?php echo $t['id']; ?>, '<?php echo addslashes($t['ticket_number']); ?>', '<?php echo addslashes($client_name); ?>', '<?php echo addslashes($t['subject']); ?>')" class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-rose-50 hover:bg-rose-600 text-rose-600 hover:text-white transition-all shadow-xs" title="Delete Ticket">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Controls -->
                <?php if ($total_pages > 1): ?>
                    <div class="p-4 sm:p-6 border-t border-slate-100 bg-slate-50/50 flex flex-col sm:flex-row items-center justify-between gap-4">
                        <p class="text-xs text-slate-500 font-medium">
                            Showing <strong class="text-slate-900 font-bold"><?php echo $start_item; ?></strong> to <strong class="text-slate-900 font-bold"><?php echo $end_item; ?></strong> of <strong class="text-slate-900 font-bold"><?php echo $total_tickets; ?></strong> tickets
                        </p>
                        <div class="flex items-center space-x-1.5">
                            <!-- Prev Button -->
                            <?php if ($current_page > 1): ?>
                                <a href="tickets.php?<?php echo http_build_query(array_merge($_GET, array('page' => $current_page - 1))); ?>" class="px-3 py-2 rounded-xl border border-slate-200 bg-white text-slate-700 hover:bg-slate-100 text-xs font-bold transition-all flex items-center space-x-1 shadow-xs">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                                    <span>Prev</span>
                                </a>
                            <?php else: ?>
                                <span class="px-3 py-2 rounded-xl border border-slate-100 bg-slate-100 text-slate-400 text-xs font-bold cursor-not-allowed flex items-center space-x-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                                    <span>Prev</span>
                                </span>
                            <?php endif; ?>

                            <!-- Page Number Links -->
                            <?php 
                            $start_p = max(1, $current_page - 2);
                            $end_p = min($total_pages, $current_page + 2);
                            if ($start_p > 1): ?>
                                <a href="tickets.php?<?php echo http_build_query(array_merge($_GET, array('page' => 1))); ?>" class="w-8 h-8 rounded-xl border border-slate-200 bg-white text-slate-700 hover:bg-slate-100 text-xs font-bold flex items-center justify-center transition-all shadow-xs">1</a>
                                <?php if ($start_p > 2): ?><span class="text-slate-400 text-xs px-1">...</span><?php endif; ?>
                            <?php endif; ?>

                            <?php for ($p = $start_p; $p <= $end_p; $p++): ?>
                                <?php if ($p == $current_page): ?>
                                    <span class="w-8 h-8 rounded-xl bg-[#EB3E0B] text-white text-xs font-bold flex items-center justify-center shadow-sm shadow-[#EB3E0B]/25"><?php echo $p; ?></span>
                                <?php else: ?>
                                    <a href="tickets.php?<?php echo http_build_query(array_merge($_GET, array('page' => $p))); ?>" class="w-8 h-8 rounded-xl border border-slate-200 bg-white text-slate-700 hover:bg-slate-100 text-xs font-bold flex items-center justify-center transition-all shadow-xs"><?php echo $p; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ($end_p < $total_pages): ?>
                                <?php if ($end_p < $total_pages - 1): ?><span class="text-slate-400 text-xs px-1">...</span><?php endif; ?>
                                <a href="tickets.php?<?php echo http_build_query(array_merge($_GET, array('page' => $total_pages))); ?>" class="w-8 h-8 rounded-xl border border-slate-200 bg-white text-slate-700 hover:bg-slate-100 text-xs font-bold flex items-center justify-center transition-all shadow-xs"><?php echo $total_pages; ?></a>
                            <?php endif; ?>

                            <!-- Next Button -->
                            <?php if ($current_page < $total_pages): ?>
                                <a href="tickets.php?<?php echo http_build_query(array_merge($_GET, array('page' => $current_page + 1))); ?>" class="px-3 py-2 rounded-xl border border-slate-200 bg-white text-slate-700 hover:bg-slate-100 text-xs font-bold transition-all flex items-center space-x-1 shadow-xs">
                                    <span>Next</span>
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                </a>
                            <?php else: ?>
                                <span class="px-3 py-2 rounded-xl border border-slate-100 bg-slate-100 text-slate-400 text-xs font-bold cursor-not-allowed flex items-center space-x-1">
                                    <span>Next</span>
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php elseif ($total_tickets > 0): ?>
                    <div class="p-4 sm:p-6 border-t border-slate-100 bg-slate-50/50 text-xs text-slate-500 font-medium">
                        Showing all <strong class="text-slate-900 font-bold"><?php echo $total_tickets; ?></strong> ticket(s)
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>
</div>

<!-- ========================================================================= -->
<!-- DELETE TICKET CONFIRMATION MODAL (TIER-PROTECTED) -->
<!-- ========================================================================= -->
<div id="deleteTicketModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/70 backdrop-blur-sm p-4">
    <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-slate-200 transform transition-all space-y-5">
        
        <div class="flex items-center space-x-3.5">
            <div class="w-12 h-12 rounded-2xl bg-rose-100 text-rose-600 flex items-center justify-center font-bold shrink-0">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
            </div>
            <div>
                <h3 class="text-base font-extrabold text-slate-900">Delete Support Ticket</h3>
                <p class="text-xs text-slate-500">Permanent record deletion</p>
            </div>
        </div>

        <!-- Ticket Particulars Box -->
        <div class="p-3.5 bg-slate-50 rounded-2xl border border-slate-200 space-y-1.5 text-xs">
            <div class="flex justify-between items-center">
                <span class="text-slate-400 font-bold uppercase text-[10px]">Ticket No.</span>
                <span id="delete_ticket_number" class="font-mono font-bold text-rose-600 text-xs"></span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-slate-400 font-bold uppercase text-[10px]">Client</span>
                <span id="delete_ticket_client" class="font-bold text-slate-800 text-xs truncate max-w-[200px]"></span>
            </div>
            <div class="pt-1 border-t border-slate-200">
                <span class="text-slate-400 font-bold uppercase text-[10px] block">Subject</span>
                <span id="delete_ticket_subject" class="font-semibold text-slate-700 text-xs truncate block"></span>
            </div>
        </div>

        <p class="text-xs text-slate-600 leading-relaxed">
            Are you sure you want to delete this support ticket? This will permanently remove the ticket thread, client replies, reactions, and attached files.
        </p>

        <!-- Access Tier Condition Form -->
        <form method="POST" action="tickets.php" class="space-y-4">
            <input type="hidden" name="action" value="delete_ticket">
            <input type="hidden" name="ticket_id" id="delete_ticket_id" value="">

            <?php if ($my_tier === 1): ?>
                <div class="p-3.5 bg-rose-50 border border-rose-200 rounded-2xl text-xs text-rose-900 leading-relaxed flex items-start space-x-2">
                    <svg class="w-4 h-4 text-rose-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    <span><strong>Action Disabled:</strong> Level 1 (View Only) accounts are not permitted to delete ticket records.</span>
                </div>
            <?php elseif ($my_tier === 2): ?>
                <div class="p-3.5 bg-amber-50 border border-amber-200 rounded-2xl text-xs text-amber-900 space-y-2">
                    <div class="flex items-center space-x-1.5 font-bold text-[11px] text-amber-800">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        <span>Security Level 2 Access Verification Required</span>
                    </div>
                    <p class="text-[11px] text-amber-800/90 leading-snug">
                        Please enter your security access code to authorize this deletion.
                    </p>
                    <div>
                        <input type="password" name="action_access_code" id="delete_ticket_access_code" placeholder="Enter security access code" required class="w-full bg-white border border-amber-300 text-slate-900 text-xs px-3 py-2 rounded-xl focus:outline-none focus:border-[#EB3E0B] font-mono tracking-widest text-center placeholder:tracking-normal">
                    </div>
                </div>
            <?php else: ?>
                <div class="text-[11px] text-slate-500 font-mono flex items-center gap-1.5 p-2 bg-slate-50 rounded-xl border border-slate-200">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                    <span>Authorized for Direct Deletion (Level 3 Tier)</span>
                </div>
            <?php endif; ?>

            <div class="flex items-center justify-end space-x-2 pt-2">
                <button type="button" onclick="closeDeleteTicketModal()" class="px-4 py-2.5 rounded-2xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs transition-colors">
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


<script>
function openDeleteTicketModal(id, ticketNumber, clientName, subject) {
    document.getElementById('delete_ticket_id').value = id;
    document.getElementById('delete_ticket_number').textContent = ticketNumber;
    document.getElementById('delete_ticket_client').textContent = clientName;
    document.getElementById('delete_ticket_subject').textContent = subject;

    var codeInput = document.getElementById('delete_ticket_access_code');
    if (codeInput) {
        codeInput.value = '';
    }

    var modal = document.getElementById('deleteTicketModal');
    if (modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
}

function closeDeleteTicketModal() {
    var modal = document.getElementById('deleteTicketModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
}

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDeleteTicketModal();
    }
});

// Close modal when clicking on backdrop
document.getElementById('deleteTicketModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeDeleteTicketModal();
    }
});

// =========================================================================
// LOG TECHNICIAN SERVICE NOTE
// Opens the shared footer modal already filled in with this ticket's client,
// so a visit can be logged without retyping the account details.
// =========================================================================
function openTicketTechNote(btn) {
    var row = btn.closest('.ticket-row');
    if (!row) return;

    var ticketNumber = row.getAttribute('data-ticket-number') || '';
    var subject = row.getAttribute('data-subject') || '';
    var reason = ticketNumber ? ('Ticket ' + ticketNumber + ' - ' + subject) : subject;

    // The ticket id goes along so saving the note as Done resolves this ticket
    openNewServiceNoteModal(
        row.getAttribute('data-account') || '',
        row.getAttribute('data-tradename') || '',
        row.getAttribute('data-address') || '',
        reason,
        row.getAttribute('data-ticket-id') || ''
    );
}
</script>

<?php include __DIR__ . '/includes/ticket_chat_popup.php'; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
