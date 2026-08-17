<?php
// Support Technician Ticket Console (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/hardware_data.php';

require_tech_login();

$tech = get_logged_tech();
$tech_fullname = $tech['fullname'];

$pdo = get_db_connection();

$ticket_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($ticket_id <= 0) {
    header("Location: tickets.php");
    exit;
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = isset($_POST['action']) ? trim($_POST['action']) : '';

    if ($post_action === 'send_tech_reply') {
        $reply_msg = isset($_POST['reply_message']) ? trim($_POST['reply_message']) : '';

        if (!empty($reply_msg)) {
            $stmt_rep = $pdo->prepare("INSERT INTO client_ticket_replies 
                (ticket_id, sender_type, sender_name, message, created_at) 
                VALUES (:tid, 'support', :sname, :msg, :c_at)");

            $stmt_rep->execute(array(
                ':tid' => $ticket_id,
                ':sname' => $tech_fullname,
                ':msg' => $reply_msg,
                ':c_at' => date('Y-m-d H:i:s')
            ));

            // Auto update status to In Progress if currently Pending
            $pdo->prepare("UPDATE client_support_tickets SET status = 'In Progress' WHERE id = :tid AND status = 'Pending'")
                ->execute(array(':tid' => $ticket_id));

            header("Location: ticket_detail.php?id=" . $ticket_id);
            exit;
        }

    } elseif ($post_action === 'update_ticket_status') {
        $new_status = isset($_POST['status']) ? trim($_POST['status']) : '';
        $new_tech = isset($_POST['assigned_tech']) ? trim($_POST['assigned_tech']) : '';

        if (!empty($new_status)) {
            $stmt_up = $pdo->prepare("UPDATE client_support_tickets SET status = :status, assigned_tech = :tech WHERE id = :tid");
            $stmt_up->execute(array(
                ':status' => $new_status,
                ':tech' => $new_tech,
                ':tid' => $ticket_id
            ));
        }

        header("Location: ticket_detail.php?id=" . $ticket_id . "&saved=1");
        exit;
    }
}

// Fetch Ticket with Client info
$stmt_t = $pdo->prepare("SELECT t.*, c.tradename, c.clientname, c.contactnum, c.address 
    FROM client_support_tickets t 
    LEFT JOIN bucket_client c ON t.accountnum = c.accountnum 
    WHERE t.id = :tid LIMIT 1");
$stmt_t->execute(array(':tid' => $ticket_id));
$ticket = $stmt_t->fetch();

if (!$ticket) {
    header("Location: tickets.php");
    exit;
}

$client_display = !empty($ticket['tradename']) ? $ticket['tradename'] : (!empty($ticket['clientname']) ? $ticket['clientname'] : 'Acct: ' . $ticket['accountnum']);

// Fetch Replies
$stmt_replies = $pdo->prepare("SELECT * FROM client_ticket_replies WHERE ticket_id = :tid ORDER BY id ASC");
$stmt_replies->execute(array(':tid' => $ticket_id));
$replies = $stmt_replies->fetchAll();

if (empty($replies)) {
    $replies = array(
        array(
            'id' => 0,
            'ticket_id' => $ticket_id,
            'sender_type' => 'client',
            'sender_name' => $client_display,
            'message' => $ticket['issue_description'],
            'created_at' => $ticket['created_at']
        )
    );
}

// Fetch Technicians list
$stmt_techs = $pdo->query("SELECT fname, lname, user FROM user ORDER BY fname ASC");
$tech_users = $stmt_techs->fetchAll();

$active_page = 'tickets';
$page_title = 'Ticket #' . $ticket['ticket_number'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket #<?php echo sanitize($ticket['ticket_number']); ?> - Support Center Console</title>
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
        @keyframes slideDown { from { opacity: 0; transform: translateY(-12px); } to { opacity: 1; transform: translateY(0); } }
        .animate-slideDown { animation: slideDown 0.35s ease-out; }
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

            <?php if (isset($_GET['saved']) && $_GET['saved'] == '1'): ?>
            <div id="saveToast" class="bg-emerald-50 border border-emerald-200 rounded-2xl px-5 py-3.5 flex items-center justify-between shadow-sm animate-slideDown">
                <div class="flex items-center space-x-3">
                    <div class="w-8 h-8 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                    </div>
                    <p class="text-xs font-bold text-emerald-800">Ticket settings saved successfully!</p>
                </div>
                <button onclick="document.getElementById('saveToast').remove()" class="text-emerald-400 hover:text-emerald-700 transition-colors p-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <script>setTimeout(function(){ var t = document.getElementById('saveToast'); if(t) t.style.display='none'; }, 4000);</script>
            <?php endif; ?>

            <!-- Back Link -->
            <a href="tickets.php" class="inline-flex items-center space-x-2 text-xs font-bold text-slate-600 hover:text-slate-900 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                <span>Back to Support Tickets Queue</span>
            </a>

            <!-- Grid Layout: Ticket Console & Quick Controls Sidebar -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- Left 2-Columns: Main Ticket Info & Thread -->
                <div class="lg:col-span-2 space-y-6">

                    <!-- Ticket Header Summary Card -->
                    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-sm space-y-4">
                        <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                            <div>
                                <span class="text-xs font-mono font-bold text-[#EB3E0B] bg-[#FFE8D5] px-3 py-1 rounded-full border border-[#FECDAA]">
                                    Ticket #<?php echo sanitize($ticket['ticket_number']); ?>
                                </span>
                                <h2 class="text-xl font-extrabold text-slate-900 mt-2">
                                    <?php echo sanitize($ticket['subject']); ?>
                                </h2>
                            </div>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold border <?php echo get_status_badge_class($ticket['status']); ?>">
                                <?php echo sanitize($ticket['status']); ?>
                            </span>
                        </div>

                        <!-- Ticket Metadata Pills -->
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-xs">
                            <div>
                                <span class="block text-slate-400 font-bold uppercase tracking-wider text-[10px]">Client</span>
                                <span class="font-extrabold text-slate-900"><?php echo sanitize($client_display); ?></span>
                            </div>
                            <div>
                                <span class="block text-slate-400 font-bold uppercase tracking-wider text-[10px]">Account #</span>
                                <span class="font-mono font-bold text-slate-700"><?php echo sanitize($ticket['accountnum']); ?></span>
                            </div>
                            <div>
                                <span class="block text-slate-400 font-bold uppercase tracking-wider text-[10px]">Category</span>
                                <span class="font-bold text-slate-800"><?php echo sanitize($ticket['category']); ?></span>
                            </div>
                            <div>
                                <span class="block text-slate-400 font-bold uppercase tracking-wider text-[10px]">Priority</span>
                                <span class="font-mono font-bold text-slate-800 uppercase"><?php echo sanitize($ticket['priority']); ?></span>
                            </div>
                        </div>

                        <!-- Original Ticket Description Card -->
                        <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200/80 space-y-2">
                            <span class="block text-xs font-bold text-slate-500 uppercase tracking-wider">Client Issue Description</span>
                            <?php if (strpos($ticket['issue_description'], '=== HARDWARE DIAGNOSTIC LOG ===') !== false): ?>
                                <p class="font-extrabold text-slate-900 text-xs sm:text-sm">This client is requesting assistance</p>
                                <details class="mt-1 text-xs">
                                    <summary class="cursor-pointer font-bold text-[#EB3E0B] hover:underline">View Diagnostic Log</summary>
                                    <pre class="mt-2 p-3 rounded-2xl bg-white border border-slate-200 text-slate-800 font-mono text-xs whitespace-pre-wrap leading-relaxed"><?php echo sanitize(format_diagnostic_log_text($ticket['issue_description'])); ?></pre>
                                </details>
                            <?php else: ?>
                                <p class="text-xs sm:text-sm text-slate-800 leading-relaxed whitespace-pre-wrap font-medium">
                                    <?php echo sanitize($ticket['issue_description']); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Conversation Thread -->
                    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-sm space-y-6">
                        <h3 class="text-base font-extrabold text-slate-900 border-b border-slate-100 pb-4">
                            Support Conversation & Reply Thread
                        </h3>

                        <div class="space-y-4">
                            <!-- Replies -->
                            <?php foreach ($replies as $rep): 
                                $is_tech = ($rep['sender_type'] === 'support');
                            ?>
                                <div class="p-4 sm:p-5 rounded-2xl text-xs space-y-2 <?php echo $is_tech ? 'bg-[#FFF5ED] border border-[#FECDAA] ml-4 sm:ml-8' : 'bg-slate-50 border border-slate-200 mr-4 sm:mr-8'; ?>">
                                    <div class="flex items-center justify-between">
                                        <span class="font-bold flex items-center gap-1.5 <?php echo $is_tech ? 'text-[#EB3E0B]' : 'text-slate-900'; ?>">
                                            <?php if ($is_tech): ?>
                                                <svg class="w-4 h-4 text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                                                </svg>
                                            <?php endif; ?>
                                            <?php echo sanitize($rep['sender_name']); ?> <?php echo $is_tech ? '(RNZ Support Tech)' : '(Client)'; ?>
                                        </span>
                                        <span class="text-slate-400"><?php echo format_date($rep['created_at']); ?></span>
                                    </div>
                                    <?php if (strpos($rep['message'], '=== HARDWARE DIAGNOSTIC LOG ===') !== false): ?>
                                        <p class="font-extrabold text-slate-900 text-xs sm:text-sm">This client is requesting assistance</p>
                                        <details class="mt-1 text-xs">
                                            <summary class="cursor-pointer font-bold text-[#EB3E0B] hover:underline">View Diagnostic Log</summary>
                                            <pre class="mt-2 p-3 rounded-2xl bg-white border border-slate-200 text-slate-800 font-mono text-xs whitespace-pre-wrap leading-relaxed"><?php echo sanitize(format_diagnostic_log_text($rep['message'])); ?></pre>
                                        </details>
                                    <?php else: ?>
                                        <p class="text-slate-800 leading-relaxed font-medium whitespace-pre-wrap"><?php echo sanitize($rep['message']); ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Reply Box -->
                        <div class="pt-6 border-t border-slate-100">
                            <form action="ticket_detail.php?id=<?php echo $ticket_id; ?>" method="POST" class="space-y-4">
                                <input type="hidden" name="action" value="send_tech_reply">

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">Send Support Reply to Client</label>
                                    <textarea name="reply_message" rows="4" required placeholder="Type your technician response or instructions here..." class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs sm:text-sm rounded-2xl p-4 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all"></textarea>
                                </div>

                                <div class="flex items-center justify-between">
                                    <span class="text-[11px] text-slate-400">Replying as: <strong class="text-slate-700"><?php echo sanitize($tech_fullname); ?></strong></span>
                                    <button type="submit" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs px-6 py-3 rounded-full shadow-md shadow-[#EB3E0B]/25 transition-all active:scale-95 flex items-center space-x-2">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                                        </svg>
                                        <span>Post Support Reply</span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                </div>

                <!-- Right Column: Ticket Controls Sidebar -->
                <div class="space-y-6">

                    <!-- Update Ticket Status & Tech Assignment Card -->
                    <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm space-y-5">
                        <h3 class="text-sm font-extrabold text-slate-900 border-b border-slate-100 pb-3">
                            Management & Actions
                        </h3>

                        <form action="ticket_detail.php?id=<?php echo $ticket_id; ?>" method="POST" class="space-y-4">
                            <input type="hidden" name="action" value="update_ticket_status">

                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Ticket Status</label>
                                <select name="status" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all font-semibold">
                                    <option value="Pending" <?php echo ($ticket['status'] === 'Pending') ? 'selected' : ''; ?>>Pending</option>
                                    <option value="In Progress" <?php echo ($ticket['status'] === 'In Progress') ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="Resolved" <?php echo ($ticket['status'] === 'Resolved') ? 'selected' : ''; ?>>Resolved</option>
                                    <option value="Closed" <?php echo ($ticket['status'] === 'Closed') ? 'selected' : ''; ?>>Closed</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Assigned Technician</label>
                                <select name="assigned_tech" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all font-semibold">
                                    <option value="">-- Unassigned --</option>
                                    <?php foreach ($tech_users as $tu): 
                                        $t_fname = trim($tu['fname'] . ' ' . $tu['lname']);
                                        $sel = ($ticket['assigned_tech'] === $t_fname) ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo sanitize($t_fname); ?>" <?php echo $sel; ?>>
                                            <?php echo sanitize($t_fname); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <button type="submit" class="w-full bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs py-3 rounded-xl shadow-sm transition-all">
                                Save Ticket Settings
                            </button>
                        </form>

                        <div class="pt-4 border-t border-slate-100 space-y-3">
                            <button onclick="openNewServiceNoteModal('<?php echo addslashes($ticket['accountnum']); ?>', '<?php echo addslashes($client_display); ?>', '<?php echo addslashes(isset($ticket['address']) ? $ticket['address'] : ''); ?>', '<?php echo addslashes($ticket['subject']); ?>')" 
                                    class="w-full bg-[#FFE8D5] hover:bg-[#EB3E0B] text-[#430D07] hover:text-white font-bold text-xs py-3 rounded-xl flex items-center justify-center space-x-2 transition-all">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                                <span>Record Service Note</span>
                            </button>
                        </div>
                    </div>

                    <!-- Client Profile Information Card -->
                    <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm space-y-3">
                        <h3 class="text-sm font-extrabold text-slate-900 border-b border-slate-100 pb-2">
                            Client Details
                        </h3>

                        <div class="space-y-2 text-xs">
                            <div>
                                <span class="text-slate-400 font-bold uppercase text-[10px]">Trade Name</span>
                                <p class="font-bold text-slate-900"><?php echo sanitize($client_display); ?></p>
                            </div>
                            <div>
                                <span class="text-slate-400 font-bold uppercase text-[10px]">Contact Number</span>
                                <p class="font-mono text-slate-700"><?php echo !empty($ticket['contactnum']) ? sanitize($ticket['contactnum']) : 'N/A'; ?></p>
                            </div>
                            <div>
                                <span class="text-slate-400 font-bold uppercase text-[10px]">Address</span>
                                <p class="text-slate-700"><?php echo !empty($ticket['address']) ? sanitize($ticket['address']) : 'N/A'; ?></p>
                            </div>
                        </div>
                    </div>

                </div>

            </div>

        </main>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
