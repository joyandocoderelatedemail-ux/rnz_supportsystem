<?php
// Support Tickets Center for Tech/Admin Portal (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

require_page_access('tickets');

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

$where_clauses = array();
$params = array();

if (!empty($status_filter)) {
    $where_clauses[] = "t.status = :status";
    $params[':status'] = $status_filter;
}

if (!empty($search)) {
    $where_clauses[] = "(t.ticket_number LIKE :q OR t.subject LIKE :q OR t.issue_description LIKE :q OR c.tradename LIKE :q OR c.clientname LIKE :q)";
    $params[':q'] = "%$search%";
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

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
                        <a href="tickets.php" class="px-4 py-2 rounded-full text-xs font-bold transition-all <?php echo empty($status_filter) ? 'bg-[#EB3E0B] text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?>">
                            All Tickets
                        </a>
                        <a href="tickets.php?status=Pending" class="px-4 py-2 rounded-full text-xs font-bold transition-all <?php echo ($status_filter === 'Pending') ? 'bg-[#EB3E0B] text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?>">
                            Pending
                        </a>
                        <a href="tickets.php?status=In Progress" class="px-4 py-2 rounded-full text-xs font-bold transition-all <?php echo ($status_filter === 'In Progress') ? 'bg-[#EB3E0B] text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?>">
                            In Progress
                        </a>
                        <a href="tickets.php?status=Resolved" class="px-4 py-2 rounded-full text-xs font-bold transition-all <?php echo ($status_filter === 'Resolved') ? 'bg-[#EB3E0B] text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?>">
                            Resolved
                        </a>
                    </div>
                    
                    <div class="pl-2 border-l border-slate-200 hidden sm:block">
                        <?php echo get_tier_badge($my_tier); ?>
                    </div>
                </div>

                <form action="tickets.php" method="GET" class="w-full lg:w-72">
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
                                        <p class="font-bold text-sm">No support tickets found.</p>
                                        <p class="text-xs">Adjust your search filter or clear status selection.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($tickets as $t):
                                    $client_name = !empty($t['tradename']) ? $t['tradename'] : (!empty($t['clientname']) ? $t['clientname'] : 'Acct: ' . $t['accountnum']);
                                    // Real trade name only - the service note form wants the client record,
                                    // not the "Acct: 000..." placeholder the table falls back to.
                                    $note_client = !empty($t['tradename']) ? $t['tradename'] : (!empty($t['clientname']) ? $t['clientname'] : '');
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
                                    <tr class="ticket-row <?php echo $row_class; ?> transition-colors cursor-pointer"
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
                                        <td class="py-4 px-6 font-mono font-bold <?php echo $num_color; ?>">
                                            <?php echo sanitize($t['ticket_number']); ?>
                                        </td>
                                        <td class="py-4 px-6">
                                            <div class="font-bold <?php echo $title_color; ?>"><?php echo sanitize($client_name); ?></div>
                                        </td>
                                        <td class="py-4 px-6 max-w-xs truncate font-semibold <?php echo $subj_color; ?>">
                                            <?php echo sanitize($t['subject']); ?>
                                        </td>
                                        <td class="py-4 px-6">
                                            <span class="inline-flex items-center justify-center whitespace-nowrap px-2.5 py-1 rounded-full text-[10px] font-bold border <?php echo $badge_class; ?>"><?php echo sanitize($t['status']); ?></span>
                                        </td>

                                        <td class="py-4 px-6 <?php echo $date_color; ?>">
                                            <?php echo format_date($t['created_at']); ?>
                                        </td>

                                        <td class="py-4 px-6 text-right">
                                            <div class="flex items-center justify-end space-x-1.5">
                                                <!-- Log Technician Service Note (pre-filled from this ticket) -->
                                                <button type="button" onclick="openTicketTechNote(this)" class="inline-flex items-center justify-center w-9 h-9 rounded-full <?php echo $btn_class; ?> transition-colors shadow-xs" title="Log Technician Service Note">
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
<!-- ========================================================================= -->
<!-- TICKET CHAT POP-UP (opens at the lower right when a ticket row is clicked) -->
<!-- ========================================================================= -->
<div id="ticketChatBox" class="hidden fixed bottom-4 right-4 z-50 w-[calc(100vw-2rem)] sm:w-[390px] bg-white rounded-3xl border border-slate-200 shadow-2xl shadow-slate-900/20 overflow-hidden flex-col">

    <!-- Header -->
    <div class="shrink-0 bg-gradient-to-r from-[#EB3E0B] to-[#FA5915] text-white px-4 py-3 flex items-center gap-3">
        <button type="button" onclick="toggleTicketChatMinimize()" class="min-w-0 flex-1 text-left" title="Click to minimize / expand">
            <div class="flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-emerald-300 animate-pulse shrink-0"></span>
                <span id="ticketChatNumber" class="font-mono font-bold text-xs truncate"></span>
            </div>
            <div id="ticketChatClient" class="text-[11px] font-bold truncate opacity-95"></div>
            <div id="ticketChatSubject" class="text-[10px] truncate opacity-80"></div>
        </button>
        <div class="flex items-center gap-1 shrink-0">
            <span id="ticketChatStatus" class="px-2 py-0.5 rounded-full text-[9px] font-bold bg-white/20 border border-white/30"></span>
            <a id="ticketChatFullLink" href="#" title="Open full ticket console" class="w-7 h-7 rounded-full hover:bg-white/20 flex items-center justify-center transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
            </a>
            <button type="button" onclick="toggleTicketChatMinimize()" title="Minimize" class="w-7 h-7 rounded-full hover:bg-white/20 flex items-center justify-center transition-colors">
                <svg id="ticketChatMinIcon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 13H5"/></svg>
            </button>
            <button type="button" onclick="closeTicketChat()" title="Close chat" class="w-7 h-7 rounded-full hover:bg-white/20 flex items-center justify-center transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
    </div>

    <!-- Collapsible body: status bar + thread + composer -->
    <div id="ticketChatBody" class="flex flex-col min-h-0">

        <!-- Ticket status (changing it here also re-opens or closes the chat) -->
        <div class="shrink-0 px-3.5 py-2 bg-white border-b border-slate-200 space-y-1.5">
            <div class="flex items-center gap-2">
                <label for="ticketChatStatusSelect" class="text-[10px] font-bold uppercase tracking-wider text-slate-500 shrink-0">Status</label>
                <select id="ticketChatStatusSelect" onchange="onTicketChatStatusPicked()" <?php if ($my_tier === 1) echo 'disabled'; ?>
                        class="flex-1 min-w-0 bg-slate-50 border border-slate-200 text-slate-900 text-[11px] font-bold rounded-xl px-2.5 py-1.5 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all disabled:opacity-60 disabled:cursor-not-allowed">
                    <option value="Pending">Pending</option>
                    <option value="In Progress">In Progress</option>
                    <option value="Resolved">Resolved</option>
                    <option value="Closed">Closed</option>
                </select>
                <span id="ticketChatStatusSaved" class="hidden text-[10px] font-bold text-emerald-600 shrink-0">Saved</span>
            </div>

            <?php if ($my_tier === 1): ?>
                <p class="text-[9px] font-bold text-slate-400">Level 1 (View Only) accounts cannot change the ticket status.</p>
            <?php endif; ?>

            <!-- Level 2 accounts confirm the change with their security access code -->
            <div id="ticketChatCodeRow" class="hidden items-center gap-1.5">
                <input type="password" id="ticketChatAccessCode" placeholder="Security access code"
                       class="flex-1 min-w-0 bg-white border border-amber-300 text-slate-900 text-[10px] font-mono tracking-widest text-center rounded-xl px-2 py-1.5 focus:outline-none focus:border-[#EB3E0B] placeholder:tracking-normal placeholder:font-sans">
                <button type="button" onclick="submitTicketChatStatus()" class="px-2.5 py-1.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-[10px] font-bold shrink-0 transition-colors">Apply</button>
                <button type="button" onclick="cancelTicketChatStatus()" class="px-2 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 text-[10px] font-bold shrink-0 transition-colors">Cancel</button>
            </div>

            <p id="ticketChatStatusError" class="hidden text-[10px] font-bold text-rose-600 leading-snug"></p>
        </div>

        <!-- Conversation thread -->
        <div id="ticketChatThread" class="h-[46vh] sm:h-[320px] overflow-y-auto bg-slate-50 px-3.5 py-4 space-y-3">
            <div class="text-center text-[11px] text-slate-400 font-bold py-6">Loading conversation...</div>
        </div>

        <!-- Client typing indicator -->
        <div id="ticketChatTyping" class="hidden px-4 py-1.5 bg-slate-50 border-t border-slate-100 text-[10px] font-bold text-slate-500 italic">
            Client is typing...
        </div>

        <!-- Closed banner (shown instead of the composer on Resolved / Closed tickets) -->
        <div id="ticketChatClosed" class="hidden shrink-0 px-4 py-3.5 bg-slate-100 border-t border-slate-200 text-center space-y-1">
            <p class="text-[11px] font-bold text-slate-700">Chat closed - ticket is <span id="ticketChatClosedStatus"></span></p>
            <p class="text-[10px] text-slate-500 leading-relaxed">Set the status back to Pending or In Progress above to resume the conversation.</p>
        </div>

        <!-- Composer -->
        <div id="ticketChatComposer" class="shrink-0 border-t border-slate-200 bg-white p-3 space-y-2">
            <div id="ticketChatError" class="hidden text-[10px] font-bold text-rose-600 bg-rose-50 border border-rose-200 rounded-xl px-2.5 py-1.5"></div>

            <!-- Shown while this reply answers one specific message -->
            <div id="ticketChatReplyBar" class="hidden items-start justify-between gap-2 bg-[#FFF5ED] border border-[#FECDAA] rounded-xl px-2.5 py-1.5">
                <div class="min-w-0">
                    <p class="text-[9px] font-bold uppercase tracking-wider text-[#9A2512]">Replying to <span id="ticketChatReplyName"></span></p>
                    <p id="ticketChatReplySnippet" class="text-[10px] text-slate-600 truncate"></p>
                </div>
                <button type="button" onclick="cancelTicketChatReplyTo()" title="Cancel reply (Esc)" class="shrink-0 text-slate-400 hover:text-rose-600 transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <!-- Attached / pasted photos -->
            <div id="ticketChatPhotoBar" class="hidden bg-[#FFF5ED] border border-[#FECDAA] rounded-xl px-2.5 py-1.5 space-y-1.5">
                <div class="flex items-center justify-between gap-2 text-[10px] font-bold">
                    <span id="ticketChatPhotoCount" class="text-[#9A2512] truncate"></span>
                    <button type="button" onclick="clearTicketChatPhotos()" class="text-rose-600 hover:underline shrink-0">Remove all</button>
                </div>
                <div id="ticketChatPhotoGrid" class="flex flex-wrap gap-1.5 max-h-24 overflow-y-auto"></div>
            </div>

            <div class="flex items-end gap-2">
                <label title="Attach photos" class="w-9 h-9 shrink-0 rounded-full bg-slate-100 hover:bg-[#FFE8D5] text-slate-500 hover:text-[#EB3E0B] flex items-center justify-center cursor-pointer transition-colors">
                    <input type="file" id="ticketChatPhotoInput" accept=".png, .jpg, .jpeg, image/png, image/jpeg" multiple class="hidden" onchange="onTicketChatPhotosPicked(this)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                </label>
                <textarea id="ticketChatInput" rows="1" placeholder="Type a reply, or paste a screenshot with Ctrl + V..." class="flex-1 resize-none bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-2xl px-3.5 py-2.5 max-h-28 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all"></textarea>
                <button type="button" id="ticketChatSend" onclick="sendTicketChatReply()" title="Send reply (Enter)" class="w-9 h-9 shrink-0 rounded-full bg-[#EB3E0B] hover:bg-[#C32C0B] active:scale-95 text-white flex items-center justify-center transition-all shadow-md shadow-[#EB3E0B]/25">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                </button>
            </div>
            <p class="text-[9px] text-slate-400 font-medium px-1">Enter sends - Shift + Enter starts a new line - Ctrl + V pastes screenshots</p>
        </div>
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

// =========================================================================
// TICKET CHAT POP-UP
// Clicking a ticket row opens the conversation in a chat box at the lower
// right, so a thread can be read and answered without leaving the list.
// =========================================================================
var chatTicketId = 0;
var chatLastReplyId = 0;
var chatPollTimer = null;
var chatIsSending = false;
var chatIsMinimized = false;
var chatClientSeenId = 0;
var chatLastTypingPing = 0;
var chatReplyToId = 0;
var chatMyTier = <?php echo intval($my_tier); ?>;

var chatBox = document.getElementById('ticketChatBox');
var chatThread = document.getElementById('ticketChatThread');
var chatInput = document.getElementById('ticketChatInput');
var chatSendBtn = document.getElementById('ticketChatSend');
var chatPhotoInput = document.getElementById('ticketChatPhotoInput');
var chatStatusSelect = document.getElementById('ticketChatStatusSelect');

function escapeChatHtml(str) {
    return String(str === null || typeof str === 'undefined' ? '' : str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

// Safe to drop inside a single-quoted inline handler
function escapeChatAttr(str) {
    return escapeChatHtml(JSON.stringify(String(str === null || typeof str === 'undefined' ? '' : str)));
}

// -------------------------------------------------------------------------
// Open / close
// -------------------------------------------------------------------------
function openTicketChat(row, e) {
    // Buttons and links inside the row (Delete) keep their own behaviour
    if (e && e.target && e.target.closest) {
        var interactive = e.target.closest('a, button, input, select, label, textarea, form');
        if (interactive && interactive !== row) return;
    }

    var ticketId = parseInt(row.getAttribute('data-ticket-id'), 10);
    if (!ticketId) return;

    var reopeningSame = (ticketId === chatTicketId && !chatBox.classList.contains('hidden'));

    chatTicketId = ticketId;
    chatLastReplyId = 0;
    chatClientSeenId = 0;

    document.getElementById('ticketChatNumber').textContent = row.getAttribute('data-ticket-number') || '';
    document.getElementById('ticketChatClient').textContent = row.getAttribute('data-client') || '';
    document.getElementById('ticketChatSubject').textContent = row.getAttribute('data-subject') || '';
    document.getElementById('ticketChatFullLink').href = 'ticket_detail.php?id=' + ticketId;

    applyTicketChatStatus(row.getAttribute('data-status') || '');
    cancelTicketChatStatus();

    // Seed bubble: tickets with no replies yet still show the reported issue
    chatThread.setAttribute('data-seed-issue', row.getAttribute('data-issue') || '');
    chatThread.setAttribute('data-seed-client', row.getAttribute('data-client') || '');
    chatThread.setAttribute('data-seed-date', row.getAttribute('data-created') || '');
    chatThread.innerHTML = '<div class="text-center text-[11px] text-slate-400 font-bold py-6">Loading conversation...</div>';

    chatBox.classList.remove('hidden');
    chatBox.classList.add('flex');
    if (chatIsMinimized && !reopeningSame) {
        toggleTicketChatMinimize();
    }
    hideTicketChatError();
    clearTicketChatPhotos();
    cancelTicketChatReplyTo();

    loadTicketChatThread(true);

    if (chatPollTimer) clearInterval(chatPollTimer);
    chatPollTimer = setInterval(function() { loadTicketChatThread(false); }, 3000);
}

function closeTicketChat() {
    chatBox.classList.add('hidden');
    chatBox.classList.remove('flex');
    if (chatPollTimer) {
        clearInterval(chatPollTimer);
        chatPollTimer = null;
    }
    chatTicketId = 0;
    chatLastReplyId = 0;
    chatInput.value = '';
    chatInput.style.height = 'auto';
    clearTicketChatPhotos();
    cancelTicketChatReplyTo();
    cancelTicketChatStatus();
    hideTicketChatError();
}

function toggleTicketChatMinimize() {
    chatIsMinimized = !chatIsMinimized;
    var body = document.getElementById('ticketChatBody');
    var icon = document.getElementById('ticketChatMinIcon');
    if (chatIsMinimized) {
        body.classList.add('hidden');
        body.classList.remove('flex');
        icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/>';
    } else {
        body.classList.remove('hidden');
        body.classList.add('flex');
        icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 13H5"/>';
        scrollTicketChatToBottom();
    }
}

// -------------------------------------------------------------------------
// Ticket status
// Resolved / Closed swap the composer for a notice, exactly as the full
// console does, and the select stays in sync with whatever the poll reports.
// -------------------------------------------------------------------------
function applyTicketChatStatus(status, badgeClass) {
    document.getElementById('ticketChatStatus').textContent = status || '-';
    if (chatStatusSelect && status) {
        chatStatusSelect.value = status;
    }

    var isClosed = (status === 'Resolved' || status === 'Closed');
    document.getElementById('ticketChatComposer').classList.toggle('hidden', isClosed);
    document.getElementById('ticketChatClosed').classList.toggle('hidden', !isClosed);
    document.getElementById('ticketChatClosedStatus').textContent = status;

    // Keep the row in the table honest - replying flips Pending to In Progress
    var row = document.querySelector('.ticket-row[data-ticket-id="' + chatTicketId + '"]');
    if (row && row.getAttribute('data-status') !== status) {
        row.setAttribute('data-status', status);
        var rowBadge = row.querySelector('td:nth-child(4) span');
        if (rowBadge) {
            rowBadge.textContent = status;
            if (badgeClass) {
                rowBadge.className = 'inline-flex items-center justify-center whitespace-nowrap px-2.5 py-1 rounded-full text-[10px] font-bold border ' + badgeClass;
            }
        }
    }
}

function showTicketChatStatusError(msg) {
    var box = document.getElementById('ticketChatStatusError');
    box.textContent = msg;
    box.classList.remove('hidden');
}

// Puts the select back on the status the server last confirmed
function cancelTicketChatStatus() {
    var codeRow = document.getElementById('ticketChatCodeRow');
    codeRow.classList.add('hidden');
    codeRow.classList.remove('flex');
    document.getElementById('ticketChatAccessCode').value = '';
    document.getElementById('ticketChatStatusError').classList.add('hidden');

    var row = document.querySelector('.ticket-row[data-ticket-id="' + chatTicketId + '"]');
    if (chatStatusSelect && row && row.getAttribute('data-status')) {
        chatStatusSelect.value = row.getAttribute('data-status');
    }
}

// Level 2 accounts have to type their access code before the change is sent
function onTicketChatStatusPicked() {
    document.getElementById('ticketChatStatusError').classList.add('hidden');
    if (chatMyTier === 2) {
        var codeRow = document.getElementById('ticketChatCodeRow');
        codeRow.classList.remove('hidden');
        codeRow.classList.add('flex');
        document.getElementById('ticketChatAccessCode').focus();
        return;
    }
    submitTicketChatStatus();
}

function submitTicketChatStatus() {
    if (!chatTicketId || !chatStatusSelect) return;

    var newStatus = chatStatusSelect.value;
    var codeInput = document.getElementById('ticketChatAccessCode');

    var body = new FormData();
    body.append('action', 'update_status');
    body.append('id', chatTicketId);
    body.append('status', newStatus);
    body.append('action_access_code', codeInput ? codeInput.value : '');

    var sentToTicket = chatTicketId;

    fetch('api_ticket_replies.php?id=' + chatTicketId, { method: 'POST', body: body })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (sentToTicket !== chatTicketId) return;

            if (!data || !data.success) {
                showTicketChatStatusError((data && data.error) ? data.error : 'Could not update the status.');
                if (data && data.needs_code) {
                    var codeRow = document.getElementById('ticketChatCodeRow');
                    codeRow.classList.remove('hidden');
                    codeRow.classList.add('flex');
                } else {
                    // Roll the select back so it never shows a status that was not saved
                    var row = document.querySelector('.ticket-row[data-ticket-id="' + chatTicketId + '"]');
                    if (row && row.getAttribute('data-status')) {
                        chatStatusSelect.value = row.getAttribute('data-status');
                    }
                }
                return;
            }

            if (codeInput) codeInput.value = '';
            var codeRowOk = document.getElementById('ticketChatCodeRow');
            codeRowOk.classList.add('hidden');
            codeRowOk.classList.remove('flex');
            document.getElementById('ticketChatStatusError').classList.add('hidden');

            applyTicketChatStatus(data.status, data.status_badge_class);

            var savedTag = document.getElementById('ticketChatStatusSaved');
            savedTag.classList.remove('hidden');
            setTimeout(function() { savedTag.classList.add('hidden'); }, 1800);
        })
        .catch(function(err) {
            showTicketChatStatusError('Network error - the status was not changed.');
            console.error('Ticket status error:', err);
        });
}

// -------------------------------------------------------------------------
// Thread rendering
// -------------------------------------------------------------------------
function buildTicketChatBubble(reply) {
    var isTech = !!reply.is_tech;
    var replyId = parseInt(reply.id, 10) || 0;

    var wrap = document.createElement('div');
    wrap.className = 'chat-msg flex flex-col ' + (isTech ? 'items-end' : 'items-start');
    wrap.setAttribute('data-reply-id', replyId);
    wrap.setAttribute('data-sender-type', isTech ? 'support' : 'client');

    var bubbleClass = isTech
        ? 'max-w-[85%] px-3.5 py-2.5 rounded-2xl rounded-br-md bg-[#FFF5ED] border border-[#FECDAA] text-left'
        : 'max-w-[85%] px-3.5 py-2.5 rounded-2xl rounded-bl-md bg-white border border-slate-200 text-left';

    var body = '';
    if (reply.diagnostic_log) {
        body = '<p class="text-[11px] font-extrabold text-slate-900">This client is requesting assistance</p>' +
            '<details class="mt-1"><summary class="cursor-pointer text-[10px] font-bold text-[#EB3E0B] hover:underline">View Diagnostic Log</summary>' +
            '<pre class="mt-1.5 p-2 rounded-xl bg-slate-50 border border-slate-200 text-slate-800 font-mono text-[10px] whitespace-pre-wrap leading-relaxed max-h-40 overflow-y-auto">' + escapeChatHtml(reply.diagnostic_log) + '</pre></details>';
    } else if (reply.message) {
        body = '<p class="text-[11.5px] text-slate-800 leading-relaxed font-medium whitespace-pre-wrap break-words">' + escapeChatHtml(reply.message) + '</p>';
    }

    var atts = reply.attachments || [];
    if (atts.length > 0) {
        body += '<div class="mt-2 flex flex-wrap gap-1.5">';
        for (var i = 0; i < atts.length; i++) {
            var url = '../' + String(atts[i]).replace(/^\/+/, '');
            body += '<a href="' + escapeChatHtml(url) + '" target="_blank" rel="noopener">' +
                '<img src="' + escapeChatHtml(url) + '" alt="Attachment" loading="lazy" ' +
                'class="h-20 w-auto max-w-[120px] object-cover rounded-xl border border-slate-200 hover:opacity-90 transition-opacity"></a>';
        }
        body += '</div>';
    }

    // The message this one answers - click it to jump back up the thread
    var quote = '';
    if (reply.reply_to && reply.reply_to.id) {
        quote = '<button type="button" onclick="jumpToTicketChatMessage(' + parseInt(reply.reply_to.id, 10) + ')" ' +
            'class="w-full text-left mb-1.5 pl-2 border-l-2 ' + (reply.reply_to.is_tech ? 'border-[#EB3E0B]' : 'border-slate-400') + ' hover:opacity-75 transition-opacity">' +
            '<span class="block text-[9px] font-bold uppercase tracking-wider text-slate-500">Replying to ' + escapeChatHtml(reply.reply_to.sender_name) + '</span>' +
            '<span class="block text-[10px] text-slate-500 truncate">' + escapeChatHtml(reply.reply_to.snippet) + '</span></button>';
    }

    wrap.innerHTML =
        '<span class="text-[9px] font-bold uppercase tracking-wider mb-1 px-1 ' + (isTech ? 'text-[#EB3E0B]' : 'text-slate-500') + '">' +
            escapeChatHtml(reply.sender_name) + ' - ' + escapeChatHtml(reply.formatted_date) +
        '</span>' +
        '<div class="' + bubbleClass + '">' + quote + body + '</div>' +
        buildTicketChatActions(reply, isTech, replyId) +
        (isTech ? '<span class="chat-seen hidden text-[9px] font-bold text-slate-400 mt-0.5 px-1">Seen</span>' : '');

    return wrap;
}

// Heart + Reply row under each real message, plus the pill holder for the
// hearts already on it. The seeded issue bubble (id 0) has no row.
function buildTicketChatActions(reply, isTech, replyId) {
    if (!replyId) return '';

    var senderArg = escapeChatAttr(reply.sender_name || '');
    var snippetArg = escapeChatAttr(reply.reply_snippet || reply.message || '');

    return '<div class="flex items-center flex-wrap gap-1 mt-1 px-1 ' + (isTech ? 'justify-end' : '') + '">' +
        '<div class="chat-reactions flex items-center flex-wrap gap-1" data-reactions-for="' + replyId + '"></div>' +
        '<button type="button" onclick="sendTicketChatReaction(' + replyId + ')" title="Love this message" ' +
            'class="inline-flex items-center justify-center w-6 h-6 rounded-full border border-dashed border-slate-300 text-slate-400 hover:text-[#9A2512] hover:border-[#FECDAA] hover:bg-white transition-colors">' +
            '<svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>' +
        '</button>' +
        '<button type="button" onclick=\'startTicketChatReplyTo(' + replyId + ', ' + senderArg + ', ' + snippetArg + ')\' title="Reply to this message" ' +
            'class="inline-flex items-center justify-center w-6 h-6 rounded-full border border-slate-200 bg-white text-slate-400 hover:text-[#EB3E0B] hover:border-[#FECDAA] transition-colors">' +
            '<svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg>' +
        '</button>' +
    '</div>';
}

function renderTicketChatSeed() {
    var issue = chatThread.getAttribute('data-seed-issue');
    if (!issue) return;
    chatThread.appendChild(buildTicketChatBubble({
        id: 0,
        is_tech: false,
        sender_name: chatThread.getAttribute('data-seed-client') || 'Client',
        formatted_date: chatThread.getAttribute('data-seed-date') || '',
        message: issue,
        attachments: []
    }));
}

// -------------------------------------------------------------------------
// Heart reactions (the only reaction the ticket chat supports)
// -------------------------------------------------------------------------
function renderTicketChatReactions(replyId, list) {
    var box = chatThread.querySelector('.chat-reactions[data-reactions-for="' + parseInt(replyId, 10) + '"]');
    if (!box) return;

    var html = '';
    for (var i = 0; i < list.length; i++) {
        var rx = list[i];
        var cls = rx.mine
            ? 'bg-[#FFE8D5] border-[#FECDAA] text-[#9A2512]'
            : 'bg-white border-slate-200 text-slate-600 hover:border-slate-300';
        html += '<button type="button" onclick="sendTicketChatReaction(' + parseInt(replyId, 10) + ')" ' +
            'title="' + escapeChatHtml(rx.who || rx.label) + '" ' +
            'class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full border text-[10px] font-bold transition-colors ' + cls + '">' +
            '<span>' + rx.emoji + '</span><span>' + rx.count + '</span></button>';
    }
    box.innerHTML = html;
}

// Applies the whole-thread map the poll returns, so hearts added by the
// client show up on messages that are already on screen.
function applyTicketChatReactionMap(map) {
    var boxes = chatThread.querySelectorAll('.chat-reactions[data-reactions-for]');
    for (var i = 0; i < boxes.length; i++) {
        var rid = boxes[i].getAttribute('data-reactions-for');
        renderTicketChatReactions(rid, (map && map[rid]) ? map[rid] : []);
    }
}

function sendTicketChatReaction(replyId) {
    if (!chatTicketId || !replyId) return;

    var body = new FormData();
    body.append('action', 'toggle_reaction');
    body.append('id', chatTicketId);
    body.append('reply_id', replyId);
    body.append('reaction', 'heart');

    fetch('api_ticket_replies.php?id=' + chatTicketId, { method: 'POST', body: body })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data && data.success) {
                renderTicketChatReactions(replyId, data.reactions || []);
            } else {
                showTicketChatError((data && data.error) ? data.error : 'Could not save your reaction.');
            }
        })
        .catch(function(err) {
            console.error('Ticket chat reaction error:', err);
        });
}

// -------------------------------------------------------------------------
// Replying to one specific message
// -------------------------------------------------------------------------
function startTicketChatReplyTo(replyId, senderName, snippet) {
    // Nothing to reply into while the ticket is Resolved / Closed
    if (document.getElementById('ticketChatComposer').classList.contains('hidden')) return;
    chatReplyToId = parseInt(replyId, 10) || 0;

    document.getElementById('ticketChatReplyName').textContent = senderName || 'this message';
    document.getElementById('ticketChatReplySnippet').textContent = snippet || '';

    var bar = document.getElementById('ticketChatReplyBar');
    bar.classList.remove('hidden');
    bar.classList.add('flex');

    chatInput.focus();
}

function cancelTicketChatReplyTo() {
    chatReplyToId = 0;
    var bar = document.getElementById('ticketChatReplyBar');
    bar.classList.add('hidden');
    bar.classList.remove('flex');
}

// Jump to a quoted message and flash it so it is easy to spot
function jumpToTicketChatMessage(replyId) {
    var msg = chatThread.querySelector('.chat-msg[data-reply-id="' + parseInt(replyId, 10) + '"]');
    if (!msg) return;

    var bubble = msg.querySelector('div');
    msg.scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (!bubble) return;
    bubble.classList.add('ring-2', 'ring-[#FA5915]');
    setTimeout(function() { bubble.classList.remove('ring-2', 'ring-[#FA5915]'); }, 1600);
}

// Only the newest tech message carries the "Seen" tag, as in any chat app
function renderTicketChatSeen() {
    var marks = chatThread.querySelectorAll('.chat-seen');
    for (var i = 0; i < marks.length; i++) {
        marks[i].classList.add('hidden');
    }
    var techMsgs = chatThread.querySelectorAll('.chat-msg[data-sender-type="support"]');
    if (!techMsgs.length || !chatClientSeenId) return;

    var last = techMsgs[techMsgs.length - 1];
    if (parseInt(last.getAttribute('data-reply-id'), 10) <= chatClientSeenId) {
        var mark = last.querySelector('.chat-seen');
        if (mark) mark.classList.remove('hidden');
    }
}

function isTicketChatNearBottom() {
    return (chatThread.scrollHeight - chatThread.scrollTop - chatThread.clientHeight) < 80;
}

function scrollTicketChatToBottom() {
    chatThread.scrollTop = chatThread.scrollHeight;
}

// -------------------------------------------------------------------------
// Polling: the first call paints the whole thread, later ones append
// -------------------------------------------------------------------------
function loadTicketChatThread(isFirstLoad) {
    if (!chatTicketId || chatIsSending) return;
    var requestedTicket = chatTicketId;

    fetch('api_ticket_replies.php?id=' + requestedTicket + '&after_id=' + chatLastReplyId)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            // A different ticket may have been opened while this was in flight
            if (requestedTicket !== chatTicketId) return;

            if (!data || !data.success) {
                if (isFirstLoad) {
                    chatThread.innerHTML = '<div class="text-center text-[11px] text-rose-500 font-bold py-6">' +
                        escapeChatHtml((data && data.error) ? data.error : 'Failed to load conversation.') + '</div>';
                }
                return;
            }

            var stickToBottom = isFirstLoad || isTicketChatNearBottom();

            if (isFirstLoad) {
                chatThread.innerHTML = '';
                if (!data.replies || !data.replies.length) {
                    renderTicketChatSeed();
                }
            }

            if (data.replies && data.replies.length) {
                for (var i = 0; i < data.replies.length; i++) {
                    var r = data.replies[i];
                    if (chatThread.querySelector('.chat-msg[data-reply-id="' + r.id + '"]')) continue;
                    chatThread.appendChild(buildTicketChatBubble(r));
                    if (r.id > chatLastReplyId) chatLastReplyId = r.id;
                }
            }

            applyTicketChatReactionMap(data.reactions);

            chatClientSeenId = parseInt(data.client_seen_id, 10) || 0;
            renderTicketChatSeen();

            document.getElementById('ticketChatTyping').classList.toggle('hidden', !data.client_typing);

            // Do not fight a status change the user is still confirming
            if (document.getElementById('ticketChatCodeRow').classList.contains('hidden')) {
                applyTicketChatStatus(data.ticket_status, data.status_badge_class);
            }

            if (stickToBottom) scrollTicketChatToBottom();
        })
        .catch(function(err) {
            console.warn('Ticket chat poll error:', err);
        });
}

// -------------------------------------------------------------------------
// Photos: picked from disk or pasted straight into the box with Ctrl + V
// -------------------------------------------------------------------------
function showTicketChatError(msg) {
    var box = document.getElementById('ticketChatError');
    box.textContent = msg;
    box.classList.remove('hidden');
}

function hideTicketChatError() {
    document.getElementById('ticketChatError').classList.add('hidden');
}

function onTicketChatPhotosPicked(input) {
    var count = (input.files && input.files.length) ? input.files.length : 0;
    if (!count) {
        clearTicketChatPhotos();
        return;
    }

    var validExts = ['png', 'jpg', 'jpeg'];
    var invalid = [];
    for (var f = 0; f < input.files.length; f++) {
        var ext = input.files[f].name.split('.').pop().toLowerCase();
        if (validExts.indexOf(ext) === -1) invalid.push(input.files[f].name);
    }
    if (invalid.length > 0) {
        showTicketChatError('Only PNG, JPG and JPEG photos are allowed: ' + invalid.join(', '));
        clearTicketChatPhotos();
        return;
    }

    hideTicketChatError();
    document.getElementById('ticketChatPhotoCount').textContent = count + (count === 1 ? ' photo attached' : ' photos attached');
    document.getElementById('ticketChatPhotoBar').classList.remove('hidden');

    var grid = document.getElementById('ticketChatPhotoGrid');
    grid.innerHTML = '';
    for (var i = 0; i < count; i++) {
        (function(file) {
            var reader = new FileReader();
            reader.onload = function(ev) {
                var thumb = document.createElement('img');
                thumb.src = ev.target.result;
                thumb.alt = 'Preview';
                thumb.className = 'h-12 w-12 rounded-lg object-cover border border-[#FECDAA]';
                grid.appendChild(thumb);
            };
            reader.readAsDataURL(file);
        })(input.files[i]);
    }
}

function clearTicketChatPhotos() {
    if (chatPhotoInput) chatPhotoInput.value = '';
    document.getElementById('ticketChatPhotoBar').classList.add('hidden');
    document.getElementById('ticketChatPhotoGrid').innerHTML = '';
}

// Ctrl + V anywhere in the chat box drops the clipboard image into the
// same file input the attach button fills, so both paths send identically.
function handleTicketChatPaste(e) {
    if (!chatPhotoInput) return;

    var items = (e.clipboardData || window.clipboardData || {}).items;
    if (!items) return;

    var pasted = [];
    for (var i = 0; i < items.length; i++) {
        if (items[i].kind === 'file' && items[i].type.indexOf('image/') === 0) {
            var file = items[i].getAsFile();
            if (file) pasted.push(file);
        }
    }
    if (!pasted.length) return;

    e.preventDefault();

    var dt = new DataTransfer();
    if (chatPhotoInput.files) {
        for (var f = 0; f < chatPhotoInput.files.length; f++) {
            dt.items.add(chatPhotoInput.files[f]);
        }
    }

    var stamp = new Date().toISOString().replace(/[:.]/g, '-');
    for (var p = 0; p < pasted.length; p++) {
        var ext = (pasted[p].type === 'image/jpeg') ? 'jpg' : 'png';
        dt.items.add(new File([pasted[p]], 'pasted-image-' + stamp + '-' + p + '.' + ext, { type: pasted[p].type }));
    }

    chatPhotoInput.files = dt.files;
    onTicketChatPhotosPicked(chatPhotoInput);
}

// -------------------------------------------------------------------------
// Sending
// -------------------------------------------------------------------------
function sendTicketChatReply() {
    if (!chatTicketId || chatIsSending) return;

    var msg = chatInput.value.trim();
    var hasPhotos = chatPhotoInput && chatPhotoInput.files && chatPhotoInput.files.length > 0;
    if (!msg && !hasPhotos) return;

    hideTicketChatError();
    chatIsSending = true;
    chatSendBtn.disabled = true;
    chatSendBtn.classList.add('opacity-50', 'cursor-not-allowed');

    var body = new FormData();
    body.append('action', 'send_tech_reply');
    body.append('id', chatTicketId);
    body.append('reply_message', msg);
    body.append('reply_to_id', chatReplyToId);
    if (hasPhotos) {
        for (var i = 0; i < chatPhotoInput.files.length; i++) {
            body.append('attachments[]', chatPhotoInput.files[i]);
        }
    }

    var sentToTicket = chatTicketId;

    fetch('api_ticket_replies.php?id=' + chatTicketId, { method: 'POST', body: body })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            chatIsSending = false;
            chatSendBtn.disabled = false;
            chatSendBtn.classList.remove('opacity-50', 'cursor-not-allowed');

            if (!data || !data.success) {
                showTicketChatError((data && data.error) ? data.error : 'Failed to send reply.');
                return;
            }

            chatInput.value = '';
            chatInput.style.height = 'auto';
            clearTicketChatPhotos();
            cancelTicketChatReplyTo();

            if (sentToTicket !== chatTicketId) return;
            if (!chatThread.querySelector('.chat-msg[data-reply-id="' + data.reply.id + '"]')) {
                chatThread.appendChild(buildTicketChatBubble(data.reply));
                if (data.reply.id > chatLastReplyId) chatLastReplyId = data.reply.id;
                renderTicketChatReactions(data.reply.id, data.reply.reactions || []);
                renderTicketChatSeen();
                scrollTicketChatToBottom();
            }
        })
        .catch(function(err) {
            chatIsSending = false;
            chatSendBtn.disabled = false;
            chatSendBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            showTicketChatError('Network error - reply was not sent.');
            console.error('Ticket chat send error:', err);
        });
}

// Enter sends; Shift + Enter starts a new line
if (chatInput) {
    chatInput.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            cancelTicketChatReplyTo();
            return;
        }
        if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.altKey && !e.metaKey) {
            e.preventDefault();
            sendTicketChatReply();
        }
    });

    chatInput.addEventListener('input', function() {
        // Grow with the text, up to the max height the class caps it at
        chatInput.style.height = 'auto';
        chatInput.style.height = Math.min(chatInput.scrollHeight, 112) + 'px';

        // Let the client see the typing indicator, at most one ping every 2s
        var now = Date.now();
        if (chatTicketId && (now - chatLastTypingPing) > 2000) {
            chatLastTypingPing = now;
            var ping = new FormData();
            ping.append('action', 'typing');
            ping.append('id', chatTicketId);
            fetch('api_ticket_replies.php?id=' + chatTicketId, { method: 'POST', body: ping }).catch(function() {});
        }
    });
}

// Ctrl + V works anywhere while the chat is open, not just inside the
// textarea - but a paste aimed at another field on the page is left alone.
document.addEventListener('paste', function(e) {
    if (!chatTicketId || chatBox.classList.contains('hidden') || chatIsMinimized) return;
    if (document.getElementById('ticketChatComposer').classList.contains('hidden')) return;

    var el = document.activeElement;
    if (el && el !== document.body && !chatBox.contains(el)) return;

    handleTicketChatPaste(e);
});

// The access code field submits on Enter like the rest of the pop-up
var chatCodeField = document.getElementById('ticketChatAccessCode');
if (chatCodeField) {
    chatCodeField.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            submitTicketChatStatus();
        } else if (e.key === 'Escape') {
            cancelTicketChatStatus();
        }
    });
}

// Polling is pointless while the tab is hidden
document.addEventListener('visibilitychange', function() {
    if (!chatTicketId) return;
    if (document.hidden) {
        if (chatPollTimer) {
            clearInterval(chatPollTimer);
            chatPollTimer = null;
        }
    } else if (!chatPollTimer) {
        loadTicketChatThread(false);
        chatPollTimer = setInterval(function() { loadTicketChatThread(false); }, 3000);
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
