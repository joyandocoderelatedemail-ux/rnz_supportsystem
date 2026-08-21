<?php
// Client Support Tickets Page
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db_init.php';

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

            header("Location: ticket_detail.php?id=" . $new_ticket_id . "&submitted=1");
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
                                    <tr class="hover:bg-[#FFF5ED] transition-colors group cursor-pointer" onclick="window.location='ticket_detail.php?id=<?php echo $t['id']; ?>'">
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
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-[11px] font-semibold border <?php echo get_status_badge_class($t['status']); ?>">
                                                <?php echo sanitize($t['status']); ?>
                                            </span>
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

<?php include __DIR__ . '/includes/footer.php'; ?>
