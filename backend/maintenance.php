<?php
// Support Center - POS Maintenance Requests Console (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

require_page_access('maintenance');

$tech = get_logged_tech();
$pdo = get_db_connection();

$error_msg = '';
$success_msg = '';

if (isset($_GET['updated']) && $_GET['updated'] === '1') {
    $success_msg = "Maintenance request status updated successfully!";
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action_code = isset($_POST['action_access_code']) ? trim($_POST['action_access_code']) : '';
    $perm_check = check_tech_action_permission($action_code);

    if (!$perm_check['allowed']) {
        $error_msg = $perm_check['message'];
    } else {
        $action = isset($_POST['action']) ? trim($_POST['action']) : '';

        if ($action === 'update_maintenance_status') {
            $req_id     = isset($_POST['request_id']) ? intval($_POST['request_id']) : 0;
            $new_status = isset($_POST['new_status']) ? trim($_POST['new_status']) : '';

            if ($req_id > 0 && !empty($new_status)) {
                $now = date('Y-m-d H:i:s');
                $stmt_u = $pdo->prepare("UPDATE client_maintenance_requests SET status = :st, updated_at = :now WHERE id = :id");
                $stmt_u->execute(array(
                    ':st' => $new_status,
                    ':now' => $now,
                    ':id' => $req_id
                ));

                header("Location: maintenance.php?updated=1");
                exit;
            }
        }
    }
}

// Filters & Search
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$search_query  = isset($_GET['q']) ? trim($_GET['q']) : '';

$where_clauses = array();
$params = array();

if (!empty($status_filter) && $status_filter !== 'All') {
    $where_clauses[] = "m.status = :st";
    $params[':st'] = $status_filter;
}

if (!empty($search_query)) {
    $where_clauses[] = "(m.request_number LIKE :q OR m.accountnum LIKE :q OR m.tradename LIKE :q OR m.contact_person LIKE :q)";
    $params[':q'] = '%' . $search_query . '%';
}

$where_sql = "";
if (!empty($where_clauses)) {
    $where_sql = "WHERE " . implode(" AND ", $where_clauses);
}

// Fetch Maintenance Requests with Client details
$sql_req = "SELECT m.*, c.clientname, c.contactnum AS client_phone, c.warranty_status, c.warranty_coverage_type 
    FROM client_maintenance_requests m 
    LEFT JOIN bucket_client c ON m.accountnum = c.accountnum 
    $where_sql 
    ORDER BY m.id DESC";

$stmt_m = $pdo->prepare($sql_req);
$stmt_m->execute($params);
$requests = $stmt_m->fetchAll();

// Counts for Stats Cards
$cnt_total     = $pdo->query("SELECT COUNT(*) FROM client_maintenance_requests")->fetchColumn();
$cnt_pending   = $pdo->query("SELECT COUNT(*) FROM client_maintenance_requests WHERE status = 'Pending'")->fetchColumn();
$cnt_scheduled = $pdo->query("SELECT COUNT(*) FROM client_maintenance_requests WHERE status = 'Scheduled'")->fetchColumn();
$cnt_completed = $pdo->query("SELECT COUNT(*) FROM client_maintenance_requests WHERE status = 'Completed'")->fetchColumn();

$active_page = 'maintenance';
$page_title = 'POS Maintenance Requests';
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

            <!-- Flash Alerts -->
            <?php if (!empty($success_msg)): ?>
                <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs sm:text-sm font-bold flex items-center justify-between shadow-sm">
                    <span class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        <?php echo sanitize($success_msg); ?>
                    </span>
                    <button onclick="this.parentElement.remove()" class="text-emerald-500 hover:text-emerald-800">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Page Header Card -->
            <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-sm flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                <div class="space-y-1">
                    <div class="flex items-center space-x-3">
                        <span class="p-2.5 rounded-2xl bg-[#FFE8D5] text-[#EB3E0B]">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                        </span>
                        <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight">POS Maintenance Requests</h2>
                    </div>
                    <p class="text-xs sm:text-sm text-slate-500">Manage client requests for Point of Sale unit cleaning, hardware checkups, and scheduled maintenance visits.</p>
                </div>
            </div>

            <!-- Stats Overview Grid -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <a href="maintenance.php" class="p-5 rounded-3xl bg-white border border-slate-200 shadow-sm hover:border-slate-300 transition-all space-y-1">
                    <span class="text-[11px] font-extrabold text-slate-400 uppercase tracking-wider">Total Requests</span>
                    <p class="text-2xl sm:text-3xl font-extrabold text-slate-900 font-mono"><?php echo intval($cnt_total); ?></p>
                </a>
                <a href="maintenance.php?status=Pending" class="p-5 rounded-3xl bg-amber-50/80 border border-amber-200 shadow-sm hover:border-amber-300 transition-all space-y-1">
                    <span class="text-[11px] font-extrabold text-amber-700 uppercase tracking-wider">Pending Action</span>
                    <p class="text-2xl sm:text-3xl font-extrabold text-amber-800 font-mono"><?php echo intval($cnt_pending); ?></p>
                </a>
                <a href="maintenance.php?status=Scheduled" class="p-5 rounded-3xl bg-blue-50/80 border border-blue-200 shadow-sm hover:border-blue-300 transition-all space-y-1">
                    <span class="text-[11px] font-extrabold text-blue-700 uppercase tracking-wider">Scheduled</span>
                    <p class="text-2xl sm:text-3xl font-extrabold text-blue-800 font-mono"><?php echo intval($cnt_scheduled); ?></p>
                </a>
                <a href="maintenance.php?status=Completed" class="p-5 rounded-3xl bg-emerald-50/80 border border-emerald-200 shadow-sm hover:border-emerald-300 transition-all space-y-1">
                    <span class="text-[11px] font-extrabold text-emerald-700 uppercase tracking-wider">Completed</span>
                    <p class="text-2xl sm:text-3xl font-extrabold text-emerald-800 font-mono"><?php echo intval($cnt_completed); ?></p>
                </a>
            </div>

            <!-- Filter & Search Bar -->
            <div class="bg-white rounded-3xl p-5 border border-slate-200 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-4 text-xs">
                <form action="maintenance.php" method="GET" class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
                    <span class="font-bold text-slate-500">Status:</span>
                    <?php 
                    $statuses = array('All', 'Pending', 'Scheduled', 'Completed', 'Cancelled');
                    foreach ($statuses as $st): 
                        $is_act = ($status_filter === $st || (empty($status_filter) && $st === 'All'));
                    ?>
                        <a href="maintenance.php?status=<?php echo urlencode($st); ?>&q=<?php echo urlencode($search_query); ?>" class="px-3.5 py-1.5 rounded-full font-bold transition-all <?php echo $is_act ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/20' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?>">
                            <?php echo $st; ?>
                        </a>
                    <?php endforeach; ?>
                </form>

                <form action="maintenance.php" method="GET" class="relative w-full sm:w-72">
                    <?php if (!empty($status_filter)): ?>
                        <input type="hidden" name="status" value="<?php echo sanitize($status_filter); ?>">
                    <?php endif; ?>
                    <input type="text" name="q" value="<?php echo sanitize($search_query); ?>" placeholder="Search account, request #, contact..." class="w-full bg-slate-50 border border-slate-200 text-slate-800 rounded-full px-4 py-2 pl-9 focus:border-[#EB3E0B] focus:bg-white focus:outline-none transition-all">
                    <svg class="w-4 h-4 text-slate-400 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </form>
            </div>

            <!-- Maintenance Requests Console Table -->
            <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm space-y-4">
                <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                    <h3 class="font-extrabold text-base text-slate-900">Client Requests List</h3>
                    <span class="text-xs font-bold text-slate-500 bg-slate-100 px-3 py-1 rounded-full">
                        Total Records: <?php echo count($requests); ?>
                    </span>
                </div>

                <?php if (empty($requests)): ?>
                    <div class="text-center py-12 space-y-3">
                        <p class="font-bold text-sm text-slate-600">No POS maintenance requests found</p>
                        <p class="text-xs text-slate-400">Try clearing your filters or search query.</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead>
                                <tr class="text-[11px] font-extrabold text-slate-500 uppercase tracking-wider border-b border-slate-100">
                                    <th class="pb-3 px-3">Request #</th>
                                    <th class="pb-3 px-3">Client Account</th>
                                    <th class="pb-3 px-3">Preferred Date / Time</th>
                                    <th class="pb-3 px-3">Units</th>
                                    <th class="pb-3 px-3">Contact Person & Phone</th>
                                    <th class="pb-3 px-3">Location Address</th>
                                    <th class="pb-3 px-3">Warranty</th>
                                    <th class="pb-3 px-3">Status</th>
                                    <th class="pb-3 px-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($requests as $r): ?>
                                    <tr class="hover:bg-slate-50/80 transition-colors">
                                        <td class="py-4 px-3 font-mono font-bold text-[#EB3E0B]">
                                            <?php echo sanitize($r['request_number']); ?>
                                        </td>
                                        <td class="py-4 px-3">
                                            <span class="block font-bold text-slate-900"><?php echo sanitize($r['tradename']); ?></span>
                                            <span class="text-[11px] text-slate-500 font-mono">Acct: <?php echo sanitize($r['accountnum']); ?></span>
                                        </td>
                                        <td class="py-4 px-3 font-medium">
                                            <span class="block font-bold text-slate-800"><?php echo format_date($r['preferred_date']); ?></span>
                                            <span class="text-[11px] text-slate-500 font-mono"><?php echo sanitize($r['preferred_time']); ?></span>
                                        </td>
                                        <td class="py-4 px-3">
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full font-extrabold bg-[#FFE8D5] text-[#EB3E0B] border border-[#FECDAA]">
                                                <?php echo intval($r['units_count']); ?> Unit(s)
                                            </span>
                                        </td>
                                        <td class="py-4 px-3">
                                            <span class="block font-bold text-slate-900"><?php echo sanitize($r['contact_person']); ?></span>
                                            <span class="text-[11px] text-slate-500 font-mono"><?php echo sanitize($r['contact_number']); ?></span>
                                        </td>
                                        <td class="py-4 px-3 max-w-[180px] truncate text-slate-600" title="<?php echo sanitize($r['location_address']); ?>">
                                            <?php echo sanitize($r['location_address']); ?>
                                        </td>
                                        <td class="py-4 px-3">
                                            <?php if (isset($r['warranty_status']) && $r['warranty_status'] === 'Active'): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                    Active Warranty
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-rose-50 text-rose-700 border border-rose-200">
                                                    No Warranty
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-4 px-3">
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-[11px] font-bold border <?php echo get_status_badge_class($r['status']); ?>">
                                                <?php echo sanitize($r['status']); ?>
                                            </span>
                                        </td>
                                        <td class="py-4 px-3 text-right">
                                            <button onclick="openStatusModal(<?php echo $r['id']; ?>, '<?php echo sanitize($r['request_number']); ?>', '<?php echo sanitize($r['status']); ?>')" class="px-3.5 py-1.5 rounded-full font-bold bg-[#EB3E0B] hover:bg-[#C32C0B] text-white text-[11px] shadow-sm transition-all">
                                                Update Status
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </main>

        <?php include __DIR__ . '/includes/footer.php'; ?>
    </div>
</div>

<!-- Update Status Modal -->
<div id="statusModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm hidden p-4">
    <div class="bg-white rounded-3xl max-w-md w-full p-6 sm:p-8 border border-slate-200 shadow-2xl space-y-5 text-slate-800 relative">
        <div class="flex items-center justify-between border-b border-slate-100 pb-4">
            <div>
                <h3 class="text-base font-extrabold text-slate-900">Update Request Status</h3>
                <p id="modalReqNum" class="text-xs font-mono text-[#EB3E0B] font-bold"></p>
            </div>
            <button type="button" onclick="closeStatusModal()" class="text-slate-400 hover:text-slate-700 p-1.5 rounded-full hover:bg-slate-100">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <form action="maintenance.php" method="POST" class="space-y-4 text-xs">
            <input type="hidden" name="action" value="update_maintenance_status">
            <input type="hidden" name="request_id" id="modalReqId" value="0">

            <div>
                <label class="block font-bold text-slate-700 mb-1.5">Select New Status</label>
                <select name="new_status" id="modalStatusSelect" required class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-4 py-2.5 text-slate-800 font-bold focus:border-[#EB3E0B] focus:bg-white focus:outline-none transition-all">
                    <option value="Pending">Pending</option>
                    <option value="Scheduled">Scheduled</option>
                    <option value="Completed">Completed</option>
                    <option value="Cancelled">Cancelled</option>
                </select>
            </div>

            <div class="pt-3 flex items-center justify-end space-x-3 border-t border-slate-100">
                <button type="button" onclick="closeStatusModal()" class="px-4 py-2 rounded-full font-bold text-slate-500 hover:bg-slate-100">
                    Cancel
                </button>
                <button type="submit" class="px-5 py-2 rounded-full font-extrabold bg-[#EB3E0B] hover:bg-[#C32C0B] text-white shadow-md shadow-[#EB3E0B]/25">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openStatusModal(id, reqNum, currentStatus) {
    document.getElementById('modalReqId').value = id;
    document.getElementById('modalReqNum').innerText = reqNum;
    document.getElementById('modalStatusSelect').value = currentStatus;
    var m = document.getElementById('statusModal');
    if (m) m.classList.remove('hidden');
}
function closeStatusModal() {
    var m = document.getElementById('statusModal');
    if (m) m.classList.add('hidden');
}
</script>
</body>
</html>
