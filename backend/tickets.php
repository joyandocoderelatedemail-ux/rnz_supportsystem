<?php
// Support Tickets Center for Tech/Admin Portal (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

require_page_access('tickets');

$pdo = get_db_connection();

// Handle quick assign or status update POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action_code = isset($_POST['action_access_code']) ? trim($_POST['action_access_code']) : '';
    $perm_check = check_tech_action_permission($action_code);

    if ($perm_check['allowed']) {
        $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
        
        if ($_POST['action'] === 'quick_update_ticket' && $ticket_id > 0) {
            $new_status = isset($_POST['status']) ? trim($_POST['status']) : '';
            $new_tech = isset($_POST['assigned_tech']) ? trim($_POST['assigned_tech']) : '';

            $sql_parts = array();
            $params = array(':id' => $ticket_id);

            if (!empty($new_status)) {
                $sql_parts[] = "status = :status";
                $params[':status'] = $new_status;
            }
            if (!empty($new_tech)) {
                $sql_parts[] = "assigned_tech = :tech";
                $params[':tech'] = $new_tech;
            }

            if (!empty($sql_parts)) {
                $stmt_up = $pdo->prepare("UPDATE client_support_tickets SET " . implode(", ", $sql_parts) . " WHERE id = :id");
                $stmt_up->execute($params);
            }
        }
    }
    header("Location: tickets.php");
    exit;
}

// Fetch technicians list from user table
$stmt_techs = $pdo->query("SELECT fname, lname, user FROM user ORDER BY fname ASC");
$tech_users = $stmt_techs->fetchAll();

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
        ORDER BY t.created_at DESC 
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

        <main class="p-6 sm:p-8 space-y-6 max-w-7xl w-full mx-auto">

            <!-- Title & Filters Bar -->
            <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div class="flex items-center space-x-2 overflow-x-auto pb-2 md:pb-0">
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

                <form action="tickets.php" method="GET" class="w-full md:w-72">
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
                                <th class="py-4 px-6 text-right">Manage</th>
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
                                    $badge_class = get_status_badge_class($t['status']);
                                ?>
                                    <tr class="hover:bg-slate-50/80 transition-colors">
                                        <td class="py-4 px-6 font-mono font-bold text-[#EB3E0B]">
                                            <?php echo sanitize($t['ticket_number']); ?>
                                        </td>
                                        <td class="py-4 px-6">
                                            <div class="font-bold text-slate-900"><?php echo sanitize($client_name); ?></div>
                                        </td>
                                        <td class="py-4 px-6 max-w-xs truncate font-semibold text-slate-800">
                                            <?php echo sanitize($t['subject']); ?>
                                        </td>
                                        <td class="py-4 px-6">
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold border <?php echo $badge_class; ?>">
                                                <?php echo sanitize($t['status']); ?>
                                            </span>
                                        </td>

                                        <td class="py-4 px-6 text-slate-500">
                                            <?php echo format_date($t['created_at']); ?>
                                        </td>

                                        <td class="py-4 px-6 text-right">
                                            <a href="ticket_detail.php?id=<?php echo $t['id']; ?>" class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-slate-100 hover:bg-[#EB3E0B] text-slate-500 hover:text-white transition-colors" title="Manage Ticket">
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

<?php include __DIR__ . '/includes/footer.php'; ?>
