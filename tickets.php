<?php
// Client Support Tickets Page
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db_init.php';
require_once __DIR__ . '/includes/support_availability.php';

require_login();
$client = get_logged_client();
$accountnum = $client['accountnum'];

$pdo = get_db_connection();

$success_msg = '';
$error_msg = '';

// Handle New Ticket Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_ticket') {
    $subject = isset($_POST['subject']) ? trim($_POST['subject']) : '';
    $category = isset($_POST['category']) ? trim($_POST['category']) : 'General Support';
    $priority = isset($_POST['priority']) ? trim($_POST['priority']) : 'Medium';
    $issue_description = isset($_POST['issue_description']) ? trim($_POST['issue_description']) : '';
    $ultraviewer_user = isset($_POST['ultraviewer_user']) ? trim($_POST['ultraviewer_user']) : '';
    $ultraviewer_pass = isset($_POST['ultraviewer_pass']) ? trim($_POST['ultraviewer_pass']) : '';
    $remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';

    $is_remote_category = (stripos($category, 'Update Data') !== false || stripos($category, 'Update Item') !== false || stripos($category, 'Remote') !== false);

    if (empty($subject) || empty($issue_description)) {
        $error_msg = 'Please fill out all required fields (Subject and Description).';
    } elseif ($is_remote_category && (empty($ultraviewer_user) || empty($ultraviewer_pass) || empty($remarks))) {
        $error_msg = 'UltraViewer Username, Password, and Remarks are required for remote software update requests.';
    } else {
        // Generate unique ticket number
        $ticket_number = 'RNZ-' . date('Y') . '-' . rand(10000, 99999);
        $now = date('Y-m-d H:i:s');
        $photo_attachment = upload_ticket_photos('attachments');

        // If remote credentials provided, format a clean header in description
        $full_description = $issue_description;
        if (!empty($ultraviewer_user) || !empty($ultraviewer_pass) || !empty($remarks)) {
            $uv_block = "=== ULTRAVIEWER REMOTE ACCESS DETAILS ===\n";
            if (!empty($ultraviewer_user)) $uv_block .= "UltraViewer ID/User: " . $ultraviewer_user . "\n";
            if (!empty($ultraviewer_pass)) $uv_block .= "UltraViewer Password: " . $ultraviewer_pass . "\n";
            if (!empty($remarks))          $uv_block .= "Update Remarks: " . $remarks . "\n";
            $uv_block .= "=========================================\n\n";
            $full_description = $uv_block . $issue_description;
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO client_support_tickets 
                (ticket_number, accountnum, clientname, tradename, subject, category, ultraviewer_id, ultraviewer_pass, remarks, priority, issue_description, attachment_path, status, assigned_tech, created_at, updated_at) 
                VALUES (:num, :acct, :cname, :tname, :subj, :cat, :uv_id, :uv_pass, :remarks, :prio, :desc, :att, 'Pending', 'Unassigned', :c_at, :u_at)");
            
            $stmt->execute(array(
                ':num'      => $ticket_number,
                ':acct'     => $accountnum,
                ':cname'    => $client['clientname'],
                ':tname'    => $client['tradename'],
                ':subj'     => $subject,
                ':cat'      => $category,
                ':uv_id'    => !empty($ultraviewer_user) ? $ultraviewer_user : null,
                ':uv_pass'  => !empty($ultraviewer_pass) ? $ultraviewer_pass : null,
                ':remarks'  => !empty($remarks) ? $remarks : null,
                ':prio'     => $priority,
                ':desc'     => $full_description,
                ':att'      => $photo_attachment ? $photo_attachment : null,
                ':c_at'     => $now,
                ':u_at'     => $now
            ));

            $new_ticket_id = $pdo->lastInsertId();

            // Insert initial reply log
            $stmt2 = $pdo->prepare("INSERT INTO client_ticket_replies 
                (ticket_id, sender_type, sender_name, message, attachment_path, created_at) 
                VALUES (:tid, 'client', :sname, :msg, :att, :c_at)");
            $stmt2->execute(array(
                ':tid'   => $new_ticket_id,
                ':sname' => $client['tradename'],
                ':msg'   => $full_description,
                ':att'   => $photo_attachment ? $photo_attachment : null,
                ':c_at'  => $now
            ));

            // Nobody signed in to take the ticket? Answer it automatically with
            // the wait time and the technician roster, instead of leaving the
            // client on a silent thread.
            send_offline_autoreply_if_unattended($pdo, $new_ticket_id);

            // Back to the list with the new ticket's chat already open, so the
            // client lands in the conversation instead of a separate page
            header("Location: tickets.php?open=" . $new_ticket_id . "&submitted=1");
            exit;

        } catch (PDOException $e) {
            $error_msg = 'Failed to create ticket: ' . $e->getMessage();
        }
    }
}

// Filtering & Search
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : 'All';
$search_query = isset($_GET['q']) ? trim($_GET['q']) : '';

$where_clause = "WHERE accountnum = :acct";
$params = array(':acct' => $accountnum);

if ($status_filter !== 'All') {
    $where_clause .= " AND status = :status";
    $params[':status'] = $status_filter;
}

if (!empty($search_query)) {
    $where_clause .= " AND (ticket_number LIKE :q OR subject LIKE :q OR category LIKE :q)";
    $params[':q'] = '%' . $search_query . '%';
}

// 10 items per page pagination
$per_page = 10;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

// Get total count
$stmt_count = $pdo->prepare("SELECT COUNT(*) FROM client_support_tickets {$where_clause}");
$stmt_count->execute($params);
$total_tickets = intval($stmt_count->fetchColumn());
$total_pages = max(1, ceil($total_tickets / $per_page));

if ($current_page > $total_pages) {
    $current_page = $total_pages;
}
$offset = ($current_page - 1) * $per_page;

$stmt = $pdo->prepare("SELECT * FROM client_support_tickets {$where_clause} ORDER BY id DESC LIMIT " . intval($offset) . ", " . intval($per_page));
$stmt->execute($params);
$tickets = $stmt->fetchAll();

$start_item = $total_tickets > 0 ? $offset + 1 : 0;
$end_item = min($offset + $per_page, $total_tickets);

$active_page = 'tickets';
$page_title = 'Support Tickets';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Tickets - RNZ Client Portal</title>
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

    <!-- Main Content -->
    <div class="flex-1 flex flex-col min-w-0">
        <!-- Header -->
        <?php include __DIR__ . '/includes/header.php'; ?>

        <main class="p-4 sm:p-6 md:p-8 pb-24 md:pb-8 space-y-6 max-w-7xl w-full mx-auto">

            <?php if (!empty($error_msg)): ?>
                <div class="bg-rose-50 border border-rose-200 text-rose-700 text-xs rounded-2xl p-4 flex items-center space-x-3">
                    <span><?php echo sanitize($error_msg); ?></span>
                </div>
            <?php endif; ?>

            <!-- Action Bar & Filter Header -->
            <div class="bg-white/90 rounded-3xl p-6 border border-[#FECDAA] shadow-sm flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                <!-- Status Filter Tabs -->
                <div class="flex items-center space-x-1.5 overflow-x-auto w-full sm:w-auto">
                    <?php 
                    $filters = array('All', 'Pending', 'In Progress', 'Resolved');
                    foreach ($filters as $f): 
                        $is_active = ($status_filter === $f);
                    ?>
                        <a href="tickets.php?status=<?php echo urlencode($f); ?>&q=<?php echo urlencode($search_query); ?>" 
                           class="px-4 py-2 rounded-full text-xs font-bold transition-all <?php echo $is_active ? 'bg-[#EB3E0B] text-white shadow-sm' : 'text-[#7C2112] hover:bg-[#FFE8D5]'; ?>">
                            <?php echo $f; ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Search & Filter -->
                <form action="tickets.php" method="GET" class="flex items-center space-x-3 w-full sm:w-auto">
                    <input type="hidden" name="status" value="<?php echo sanitize($status_filter); ?>">
                    <div class="relative w-full sm:w-64">
                        <input type="text" name="q" value="<?php echo sanitize($search_query); ?>" placeholder="Search by ticket # or subject..." class="w-full bg-[#FFF5ED] text-[#430D07] text-xs pl-9 pr-4 py-2.5 rounded-full border border-[#FECDAA] focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                        <svg class="w-4 h-4 text-[#9A2512] absolute left-3 top-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </div>
                    <button type="submit" class="bg-[#FFE8D5] hover:bg-[#FECDAA] text-[#430D07] text-xs font-bold px-4 py-2.5 rounded-full transition-colors">
                        Filter
                    </button>
                </form>
            </div>

            <!-- Tickets Table Card -->
            <div class="bg-white/90 rounded-3xl p-6 sm:p-8 border border-[#FECDAA] shadow-sm">
                <?php if (empty($tickets)): ?>
                    <div class="text-center py-16">
                        <svg class="w-12 h-12 text-[#FEAA73] mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        <p class="text-sm font-bold text-[#430D07]">No tickets found</p>
                        <p class="text-xs text-[#7C2112] mt-1 mb-4">Select your issue category to troubleshoot or request assistance:</p>
                        <div class="flex items-center justify-center gap-2">
                            <a href="hardware.php" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-semibold text-xs px-5 py-2.5 rounded-full shadow-sm">
                                Hardware Devices
                            </a>
                            <a href="software.php" class="bg-[#FFE8D5] hover:bg-[#FECDAA] text-[#7C2112] font-semibold text-xs px-5 py-2.5 rounded-full shadow-sm">
                                Software Issues
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="border-b border-[#FFE8D5] text-[11px] font-bold text-[#7C2112] uppercase tracking-wider">
                                    <th class="pb-3.5 pl-2">Ticket #</th>
                                    <th class="pb-3.5">Subject</th>
                                    <th class="pb-3.5">Category</th>
                                    <th class="pb-3.5">Priority</th>
                                    <th class="pb-3.5">Date Created</th>
                                    <th class="pb-3.5 text-right pr-2">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#FFE8D5] text-xs">
                                <?php foreach ($tickets as $t): ?>
                                    <tr class="ticket-row hover:bg-[#FFF5ED] transition-colors group cursor-pointer"
                                        onclick="openTicketChat(this, event)"
                                        title="Open support chat"
                                        data-ticket-id="<?php echo intval($t['id']); ?>"
                                        data-ticket-number="<?php echo sanitize($t['ticket_number']); ?>"
                                        data-subject="<?php echo sanitize($t['subject']); ?>"
                                        data-status="<?php echo sanitize($t['status']); ?>"
                                        data-tech="<?php echo sanitize(isset($t['assigned_tech']) ? $t['assigned_tech'] : ''); ?>"
                                        data-issue="<?php echo sanitize($t['issue_description']); ?>"
                                        data-created="<?php echo sanitize(format_date($t['created_at'])); ?>">
                                        <td class="py-4 pl-2 font-mono font-bold text-[#EB3E0B] group-hover:underline">
                                            <?php echo sanitize($t['ticket_number']); ?>
                                        </td>
                                        <td class="py-4 font-bold text-[#430D07] max-w-xs truncate">
                                            <?php echo sanitize($t['subject']); ?>
                                        </td>
                                        <td class="py-4 text-[#7C2112] font-medium">
                                            <?php echo sanitize($t['category']); ?>
                                        </td>
                                        <td class="py-4">
                                            <span class="font-semibold text-[11px] <?php echo ($t['priority'] === 'Urgent' || $t['priority'] === 'High') ? 'text-[#EB3E0B]' : 'text-[#7C2112]'; ?>">
                                                <?php echo sanitize($t['priority']); ?>
                                            </span>
                                        </td>
                                        <td class="py-4 text-[#9A2512] font-mono text-[11px]">
                                            <?php echo format_date($t['created_at']); ?>
                                        </td>
                                        <td class="py-4 text-right pr-2">
                                            <span class="inline-flex items-center justify-center whitespace-nowrap px-3 py-1 rounded-full text-[11px] font-semibold border <?php echo get_status_badge_class($t['status']); ?>"><?php echo sanitize($t['status']); ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination Controls -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pt-6 mt-4 border-t border-[#FFE8D5] flex flex-col sm:flex-row items-center justify-between gap-4">
                            <p class="text-xs text-[#7C2112] font-medium">
                                Showing <strong class="text-[#430D07] font-bold"><?php echo $start_item; ?></strong> to <strong class="text-[#430D07] font-bold"><?php echo $end_item; ?></strong> of <strong class="text-[#430D07] font-bold"><?php echo $total_tickets; ?></strong> tickets
                            </p>
                            <div class="flex items-center space-x-1.5">
                                <!-- Prev Button -->
                                <?php if ($current_page > 1): ?>
                                    <a href="tickets.php?<?php echo http_build_query(array_merge($_GET, array('page' => $current_page - 1))); ?>" class="px-3 py-2 rounded-xl border border-[#FECDAA] bg-white text-[#7C2112] hover:bg-[#FFE8D5] text-xs font-bold transition-all flex items-center space-x-1 shadow-xs">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                                        <span>Prev</span>
                                    </a>
                                <?php else: ?>
                                    <span class="px-3 py-2 rounded-xl border border-[#FFE8D5] bg-[#FFF5ED] text-[#7C2112]/40 text-xs font-bold cursor-not-allowed flex items-center space-x-1">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                                        <span>Prev</span>
                                    </span>
                                <?php endif; ?>

                                <!-- Page Number Links -->
                                <?php 
                                $start_p = max(1, $current_page - 2);
                                $end_p = min($total_pages, $current_page + 2);
                                if ($start_p > 1): ?>
                                    <a href="tickets.php?<?php echo http_build_query(array_merge($_GET, array('page' => 1))); ?>" class="w-8 h-8 rounded-xl border border-[#FECDAA] bg-white text-[#7C2112] hover:bg-[#FFE8D5] text-xs font-bold flex items-center justify-center transition-all">1</a>
                                    <?php if ($start_p > 2): ?><span class="text-[#7C2112]/50 text-xs px-1">...</span><?php endif; ?>
                                <?php endif; ?>

                                <?php for ($p = $start_p; $p <= $end_p; $p++): ?>
                                    <?php if ($p == $current_page): ?>
                                        <span class="w-8 h-8 rounded-xl bg-[#EB3E0B] text-white text-xs font-bold flex items-center justify-center shadow-sm shadow-[#EB3E0B]/25"><?php echo $p; ?></span>
                                    <?php else: ?>
                                        <a href="tickets.php?<?php echo http_build_query(array_merge($_GET, array('page' => $p))); ?>" class="w-8 h-8 rounded-xl border border-[#FECDAA] bg-white text-[#7C2112] hover:bg-[#FFE8D5] text-xs font-bold flex items-center justify-center transition-all"><?php echo $p; ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>

                                <?php if ($end_p < $total_pages): ?>
                                    <?php if ($end_p < $total_pages - 1): ?><span class="text-[#7C2112]/50 text-xs px-1">...</span><?php endif; ?>
                                    <a href="tickets.php?<?php echo http_build_query(array_merge($_GET, array('page' => $total_pages))); ?>" class="w-8 h-8 rounded-xl border border-[#FECDAA] bg-white text-[#7C2112] hover:bg-[#FFE8D5] text-xs font-bold flex items-center justify-center transition-all"><?php echo $total_pages; ?></a>
                                <?php endif; ?>

                                <!-- Next Button -->
                                <?php if ($current_page < $total_pages): ?>
                                    <a href="tickets.php?<?php echo http_build_query(array_merge($_GET, array('page' => $current_page + 1))); ?>" class="px-3 py-2 rounded-xl border border-[#FECDAA] bg-white text-[#7C2112] hover:bg-[#FFE8D5] text-xs font-bold transition-all flex items-center space-x-1 shadow-xs">
                                        <span>Next</span>
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                    </a>
                                <?php else: ?>
                                    <span class="px-3 py-2 rounded-xl border border-[#FFE8D5] bg-[#FFF5ED] text-[#7C2112]/40 text-xs font-bold cursor-not-allowed flex items-center space-x-1">
                                        <span>Next</span>
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php elseif ($total_tickets > 0): ?>
                        <div class="pt-4 mt-4 border-t border-[#FFE8D5] text-xs text-[#7C2112] font-medium">
                            Showing all <strong class="text-[#430D07] font-bold"><?php echo $total_tickets; ?></strong> ticket(s)
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('action') === 'create' || urlParams.has('subject') || urlParams.has('description')) {
        if (typeof openNewTicketModal === 'function') {
            openNewTicketModal();
        } else {
            var modal = document.getElementById('newTicketModal');
            if (modal) modal.classList.remove('hidden');
        }
        var subj = urlParams.get('subject');
        var cat = urlParams.get('category');
        var desc = urlParams.get('description');
        if (subj) {
            var el = document.querySelector('#newTicketModal input[name="subject"]');
            if (el) el.value = subj;
        }
        if (cat) {
            var el = document.querySelector('#newTicketModal select[name="category"]');
            if (el) el.value = cat;
        }
        if (desc) {
            var el = document.querySelector('#newTicketModal textarea[name="issue_description"]');
            if (el) el.value = desc;
        }
    }
});
</script>
<!-- ========================================================================= -->
<!-- SUPPORT CHAT POP-UP (opens at the lower right when a ticket row is clicked) -->
<!-- ========================================================================= -->
<div id="ticketChatBox" class="hidden fixed bottom-4 right-4 z-50 w-[calc(100vw-2rem)] sm:w-[380px] bg-white rounded-3xl border border-[#FECDAA] shadow-2xl shadow-[#430D07]/20 overflow-hidden flex-col">

    <!-- Header -->
    <div class="shrink-0 bg-gradient-to-r from-[#EB3E0B] to-[#FA5915] text-white px-4 py-3 flex items-center gap-3">
        <button type="button" onclick="toggleTicketChatMinimize()" class="min-w-0 flex-1 text-left" title="Click to minimize / expand">
            <div class="flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-emerald-300 animate-pulse shrink-0"></span>
                <span id="ticketChatNumber" class="font-mono font-bold text-xs truncate"></span>
            </div>
            <div id="ticketChatSubject" class="text-[11px] font-semibold truncate opacity-95"></div>
            <div id="ticketChatTech" class="text-[10px] truncate opacity-80"></div>
        </button>
        <div class="flex items-center gap-1 shrink-0">
            <!-- Read only - only the support team changes a ticket's status -->
            <span id="ticketChatStatus" class="px-2 py-0.5 rounded-full whitespace-nowrap text-[9px] font-bold bg-white/20 border border-white/30"></span>
            <a id="ticketChatFullLink" href="#" title="Open full ticket page" class="w-7 h-7 rounded-full hover:bg-white/20 flex items-center justify-center transition-colors">
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

    <!-- Collapsible body: thread + composer -->
    <div id="ticketChatBody" class="flex flex-col min-h-0">

        <!-- Conversation thread -->
        <div id="ticketChatThread" class="h-[46vh] sm:h-[340px] overflow-y-auto bg-[#FFF9F5] px-3.5 py-4 space-y-3">
            <div class="text-center text-[11px] text-[#B4785F] font-bold py-6">Loading conversation...</div>
        </div>

        <!-- Support typing indicator -->
        <div id="ticketChatTyping" class="hidden px-4 py-1.5 bg-[#FFF9F5] border-t border-[#FFE8D5] text-[10px] font-bold text-[#7C2112] italic">
            Support is typing...
        </div>

        <!-- Closed banner (shown instead of the composer on Resolved / Closed tickets) -->
        <div id="ticketChatClosed" class="hidden shrink-0 px-4 py-3.5 bg-[#FFF5ED] border-t border-[#FFE8D5] text-center space-y-1">
            <p class="text-[11px] font-bold text-[#7C2112]">This ticket is <span id="ticketChatClosedStatus"></span> - the chat is closed.</p>
            <p class="text-[10px] text-[#9A2512]/80 leading-relaxed">Still need help? Submit a new support ticket and our team will pick it up.</p>
        </div>

        <!-- Composer -->
        <div id="ticketChatComposer" class="shrink-0 border-t border-[#FFE8D5] bg-white p-3 space-y-2">
            <div id="ticketChatError" class="hidden text-[10px] font-bold text-rose-600 bg-rose-50 border border-rose-200 rounded-xl px-2.5 py-1.5"></div>

            <!-- Attached / pasted photos -->
            <div id="ticketChatPhotoBar" class="hidden bg-[#FFF5ED] border border-[#FECDAA] rounded-xl px-2.5 py-1.5 space-y-1.5">
                <div class="flex items-center justify-between gap-2 text-[10px] font-bold">
                    <span id="ticketChatPhotoCount" class="text-[#9A2512] truncate"></span>
                    <button type="button" onclick="clearTicketChatPhotos()" class="text-rose-600 hover:underline shrink-0">Remove all</button>
                </div>
                <div id="ticketChatPhotoGrid" class="flex flex-wrap gap-1.5 max-h-24 overflow-y-auto"></div>
            </div>

            <div class="flex items-end gap-2">
                <label title="Attach photos" class="w-9 h-9 shrink-0 rounded-full bg-[#FFF5ED] hover:bg-[#FFE8D5] text-[#B4785F] hover:text-[#EB3E0B] flex items-center justify-center cursor-pointer transition-colors">
                    <input type="file" id="ticketChatPhotoInput" accept=".png, .jpg, .jpeg, image/png, image/jpeg" multiple class="hidden" onchange="onTicketChatPhotosPicked(this)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                </label>
                <textarea id="ticketChatInput" rows="1" placeholder="Message support, or paste a screenshot with Ctrl + V..." class="flex-1 resize-none bg-[#FFF9F5] border border-[#FECDAA] text-[#430D07] text-xs rounded-2xl px-3.5 py-2.5 max-h-28 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all"></textarea>
                <button type="button" id="ticketChatSend" onclick="sendTicketChatReply()" title="Send message (Enter)" class="w-9 h-9 shrink-0 rounded-full bg-[#EB3E0B] hover:bg-[#C32C0B] active:scale-95 text-white flex items-center justify-center transition-all shadow-md shadow-[#EB3E0B]/25">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                </button>
            </div>
            <p class="text-[9px] text-[#B4785F] font-medium px-1">Enter sends - Shift + Enter starts a new line - Ctrl + V pastes screenshots</p>
        </div>
    </div>
</div>

<script>
// =========================================================================
// SUPPORT CHAT POP-UP
// Clicking a ticket opens its conversation in a chat box at the lower right.
// Clients read and reply here - only the support team changes ticket status,
// so nothing in this pop-up edits the ticket itself.
// =========================================================================
var chatTicketId = 0;
var chatLastReplyId = 0;
var chatPollTimer = null;
var chatIsSending = false;
var chatIsMinimized = false;
var chatLastTypingPing = 0;

var chatBox = document.getElementById('ticketChatBox');
var chatThread = document.getElementById('ticketChatThread');
var chatInput = document.getElementById('ticketChatInput');
var chatSendBtn = document.getElementById('ticketChatSend');
var chatPhotoInput = document.getElementById('ticketChatPhotoInput');

function escapeChatHtml(str) {
    return String(str === null || typeof str === 'undefined' ? '' : str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

// -------------------------------------------------------------------------
// Open / close
// -------------------------------------------------------------------------
function openTicketChat(row, e) {
    // Links and buttons inside the row keep their own behaviour
    if (e && e.target && e.target.closest) {
        var interactive = e.target.closest('a, button, input, select, label, textarea, form');
        if (interactive && interactive !== row) return;
    }

    var ticketId = parseInt(row.getAttribute('data-ticket-id'), 10);
    if (!ticketId) return;

    var reopeningSame = (ticketId === chatTicketId && !chatBox.classList.contains('hidden'));

    chatTicketId = ticketId;
    chatLastReplyId = 0;

    document.getElementById('ticketChatNumber').textContent = row.getAttribute('data-ticket-number') || '';
    document.getElementById('ticketChatSubject').textContent = row.getAttribute('data-subject') || '';
    document.getElementById('ticketChatFullLink').href = 'ticket_detail.php?id=' + ticketId;

    var tech = row.getAttribute('data-tech') || '';
    document.getElementById('ticketChatTech').textContent = tech ? ('Handled by ' + tech) : 'Waiting for a technician';

    applyTicketChatStatus(row.getAttribute('data-status') || '');

    // Seed bubble: tickets with no replies yet still show the issue as reported
    chatThread.setAttribute('data-seed-issue', row.getAttribute('data-issue') || '');
    chatThread.setAttribute('data-seed-date', row.getAttribute('data-created') || '');
    chatThread.innerHTML = '<div class="text-center text-[11px] text-[#B4785F] font-bold py-6">Loading conversation...</div>';

    chatBox.classList.remove('hidden');
    chatBox.classList.add('flex');
    if (chatIsMinimized && !reopeningSame) {
        toggleTicketChatMinimize();
    }
    hideTicketChatError();
    clearTicketChatPhotos();

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
// Status is display only here. Support closes a ticket; the client just sees
// the chat swap to a notice when that happens.
// -------------------------------------------------------------------------
function applyTicketChatStatus(status) {
    document.getElementById('ticketChatStatus').textContent = status || '-';

    var isClosed = (status === 'Resolved' || status === 'Closed');
    document.getElementById('ticketChatComposer').classList.toggle('hidden', isClosed);
    document.getElementById('ticketChatClosed').classList.toggle('hidden', !isClosed);
    document.getElementById('ticketChatClosedStatus').textContent = status;

    // Keep the row in the table honest if support changed the status mid-chat
    var row = document.querySelector('.ticket-row[data-ticket-id="' + chatTicketId + '"]');
    if (row && row.getAttribute('data-status') !== status) {
        row.setAttribute('data-status', status);
        var rowBadge = row.querySelector('td:last-child span');
        if (rowBadge) rowBadge.textContent = status;
    }
}

// -------------------------------------------------------------------------
// Thread rendering
// -------------------------------------------------------------------------
function buildTicketChatBubble(reply) {
    var isMine = !!reply.is_client;

    var wrap = document.createElement('div');
    wrap.className = 'chat-msg flex flex-col ' + (isMine ? 'items-end' : 'items-start');
    wrap.setAttribute('data-reply-id', parseInt(reply.id, 10) || 0);

    var bubbleClass = isMine
        ? 'max-w-[85%] px-3.5 py-2.5 rounded-2xl rounded-br-md bg-[#FFF5ED] border border-[#FECDAA] text-left'
        : 'max-w-[85%] px-3.5 py-2.5 rounded-2xl rounded-bl-md bg-white border border-[#FFE8D5] text-left';

    var body = '';
    if (reply.unsent) {
        body = '<p class="chat-unsent text-[11.5px] italic text-[#B07A6A]">This message was unsent.</p>';
    } else if (reply.diagnostic_log) {
        body = '<p class="text-[11px] font-extrabold text-[#430D07]">Hardware diagnostic report</p>' +
            '<details class="mt-1"><summary class="cursor-pointer text-[10px] font-bold text-[#EB3E0B] hover:underline">View Diagnostic Log</summary>' +
            '<pre class="mt-1.5 p-2 rounded-xl bg-[#FFF9F5] border border-[#FFE8D5] text-[#430D07] font-mono text-[10px] whitespace-pre-wrap leading-relaxed max-h-40 overflow-y-auto">' + escapeChatHtml(reply.diagnostic_log) + '</pre></details>';
    } else if (reply.message) {
        body = '<p class="chat-text text-[11.5px] text-[#430D07] leading-relaxed font-medium whitespace-pre-wrap break-words">' + escapeChatHtml(reply.message) + '</p>';
    }

    var atts = reply.unsent ? [] : (reply.attachments || []);
    if (atts.length > 0) {
        body += '<div class="mt-2 flex flex-wrap gap-1.5">';
        for (var i = 0; i < atts.length; i++) {
            var url = String(atts[i]);
            body += '<a href="' + escapeChatHtml(url) + '" target="_blank" rel="noopener">' +
                '<img src="' + escapeChatHtml(url) + '" alt="Attachment" loading="lazy" ' +
                'class="h-20 w-auto max-w-[120px] object-cover rounded-xl border border-[#FECDAA] hover:opacity-90 transition-opacity"></a>';
        }
        body += '</div>';
    }

    wrap.innerHTML =
        '<span class="text-[9px] font-bold uppercase tracking-wider mb-1 px-1 ' + (isMine ? 'text-[#EB3E0B]' : 'text-[#7C2112]') + '">' +
            escapeChatHtml(reply.sender_name) + (isMine ? '' : ' (Support)') + ' - ' + escapeChatHtml(reply.formatted_date) +
            '<span class="chat-edited-tag text-[#B07A6A] normal-case tracking-normal font-medium' + (reply.edited ? '' : ' hidden') + '"' +
                (reply.edited_at ? ' title="Edited ' + escapeChatHtml(reply.edited_at) + '"' : '') + '> (edited)</span>' +
        '</span>' +
        '<div class="' + bubbleClass + '">' + body + '</div>';

    return wrap;
}

// Support can correct a message after sending it - refresh anything already
// on screen so the client is never left reading the old wording.
function applyTicketChatEditMap(map) {
    if (!map) return;
    for (var id in map) {
        if (!map.hasOwnProperty(id)) continue;
        var msg = chatThread.querySelector('.chat-msg[data-reply-id="' + parseInt(id, 10) + '"]');
        if (!msg) continue;

        // Support took the message back - replace it with the plain notice
        if (map[id].unsent) {
            if (!msg.querySelector('.chat-unsent')) {
                var bubble = msg.querySelector('div');
                if (bubble) {
                    bubble.innerHTML = '<p class="chat-unsent text-[11.5px] italic text-[#B07A6A]">This message was unsent.</p>';
                }
                var utag = msg.querySelector('.chat-edited-tag');
                if (utag) utag.classList.add('hidden');
            }
            continue;
        }

        var textEl = msg.querySelector('.chat-text');
        if (textEl && textEl.textContent !== map[id].message) {
            textEl.textContent = map[id].message;
        }
        var tag = msg.querySelector('.chat-edited-tag');
        if (tag) {
            tag.classList.remove('hidden');
            if (map[id].edited_at) tag.setAttribute('title', 'Edited ' + map[id].edited_at);
        }
    }
}

function renderTicketChatSeed() {
    var issue = chatThread.getAttribute('data-seed-issue');
    if (!issue) return;
    chatThread.appendChild(buildTicketChatBubble({
        id: 0,
        is_client: true,
        sender_name: 'You',
        formatted_date: chatThread.getAttribute('data-seed-date') || '',
        message: issue,
        attachments: []
    }));
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

            applyTicketChatEditMap(data.edits);

            document.getElementById('ticketChatTyping').classList.toggle('hidden', !data.support_typing);

            if (data.assigned_tech) {
                document.getElementById('ticketChatTech').textContent = 'Handled by ' + data.assigned_tech;
            }
            applyTicketChatStatus(data.ticket_status);

            if (stickToBottom) scrollTicketChatToBottom();
        })
        .catch(function(err) {
            console.warn('Support chat poll error:', err);
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

// Drops the clipboard image into the same file input the attach button fills,
// so pasted and picked photos travel the identical path
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
    body.append('action', 'post_reply');
    body.append('id', chatTicketId);
    // The client API reads "message" - the backend one uses "reply_message"
    body.append('message', msg);
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
                showTicketChatError((data && data.error) ? data.error : 'Failed to send message.');
                return;
            }

            chatInput.value = '';
            chatInput.style.height = 'auto';
            clearTicketChatPhotos();

            if (sentToTicket !== chatTicketId) return;
            if (!chatThread.querySelector('.chat-msg[data-reply-id="' + data.reply.id + '"]')) {
                chatThread.appendChild(buildTicketChatBubble(data.reply));
                if (data.reply.id > chatLastReplyId) chatLastReplyId = data.reply.id;
                scrollTicketChatToBottom();
            }
        })
        .catch(function(err) {
            chatIsSending = false;
            chatSendBtn.disabled = false;
            chatSendBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            showTicketChatError('Network error - your message was not sent.');
            console.error('Support chat send error:', err);
        });
}

// Enter sends; Shift + Enter starts a new line
if (chatInput) {
    chatInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.altKey && !e.metaKey) {
            e.preventDefault();
            sendTicketChatReply();
        }
    });

    chatInput.addEventListener('input', function() {
        // Grow with the text, up to the max height the class caps it at
        chatInput.style.height = 'auto';
        chatInput.style.height = Math.min(chatInput.scrollHeight, 112) + 'px';

        // Let support see the typing indicator, at most one ping every 2s
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

// ?open=<id> lands here straight after submitting a ticket - open that chat so
// the client carries on in the conversation instead of hunting for the row.
(function() {
    var params = new URLSearchParams(window.location.search);
    var openId = params.get('open');
    if (!openId) return;

    var row = document.querySelector('.ticket-row[data-ticket-id="' + parseInt(openId, 10) + '"]');
    if (!row) return;

    openTicketChat(row, null);
    row.scrollIntoView({ behavior: 'smooth', block: 'center' });

    // Drop the parameter so a refresh does not keep reopening the chat
    if (window.history && window.history.replaceState) {
        params['delete']('open');
        params['delete']('submitted');
        var rest = params.toString();
        window.history.replaceState({}, '', window.location.pathname + (rest ? '?' + rest : ''));
    }
})();
</script>


<?php include __DIR__ . '/includes/footer.php'; ?>
