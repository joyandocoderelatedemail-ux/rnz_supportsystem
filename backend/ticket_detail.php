<?php
// Support Technician Ticket Console (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/hardware_data.php';

require_page_access('tickets');

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
    $action_code = isset($_POST['action_access_code']) ? trim($_POST['action_access_code']) : '';
    $perm_check = check_tech_action_permission($action_code);
    if (!$perm_check['allowed']) {
        header("Location: ticket_detail.php?id=" . $ticket_id . "&err=" . urlencode($perm_check['message']));
        exit;
    }

    $post_action = isset($_POST['action']) ? trim($_POST['action']) : '';

    if ($post_action === 'send_tech_reply') {
        $reply_msg = isset($_POST['reply_message']) ? trim($_POST['reply_message']) : '';
        $photo_attachments = upload_ticket_photos('attachments');

        if (!empty($reply_msg) || !empty($photo_attachments)) {
            $stmt_rep = $pdo->prepare("INSERT INTO client_ticket_replies 
                (ticket_id, sender_type, sender_name, message, attachment_path, created_at) 
                VALUES (:tid, 'support', :sname, :msg, :att, :c_at)");

            $stmt_rep->execute(array(
                ':tid' => $ticket_id,
                ':sname' => $tech_fullname,
                ':msg' => $reply_msg,
                ':att' => $photo_attachments ? $photo_attachments : null,
                ':c_at' => date('Y-m-d H:i:s')
            ));

            // Auto update status to In Progress if currently Pending
            $pdo->prepare("UPDATE client_support_tickets SET status = 'In Progress', updated_at = :now WHERE id = :tid AND status = 'Pending'")
                ->execute(array(':now' => date('Y-m-d H:i:s'), ':tid' => $ticket_id));

            header("Location: ticket_detail.php?id=" . $ticket_id);
            exit;
        }

    } elseif ($post_action === 'update_ticket_status') {
        $new_status = isset($_POST['status']) ? trim($_POST['status']) : '';
        $new_tech = isset($_POST['assigned_tech']) ? trim($_POST['assigned_tech']) : '';

        if (!empty($new_status)) {
            $stmt_up = $pdo->prepare("UPDATE client_support_tickets SET status = :status, assigned_tech = :tech, updated_at = :now WHERE id = :tid");
            $stmt_up->execute(array(
                ':status' => $new_status,
                ':tech' => $new_tech,
                ':now' => date('Y-m-d H:i:s'),
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

$max_reply_id = 0;
foreach ($replies as $r) {
    if (isset($r['id']) && intval($r['id']) > $max_reply_id) {
        $max_reply_id = intval($r['id']);
    }
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
                        <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                            <h3 class="text-base font-extrabold text-slate-900">
                                Support Conversation & Reply Thread
                            </h3>
                            <div class="flex items-center space-x-2 text-[11px] font-bold text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-full border border-emerald-200">
                                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                <span>Live Chat Sync</span>
                            </div>
                        </div>

                        <div id="backendRepliesContainer" class="space-y-4">
                            <!-- Replies -->
                            <?php foreach ($replies as $rep): 
                                $is_tech = ($rep['sender_type'] === 'support');
                                $r_id = isset($rep['id']) ? intval($rep['id']) : 0;
                            ?>
                                <div class="tech-reply-card p-4 sm:p-5 rounded-2xl text-xs space-y-2 <?php echo $is_tech ? 'bg-[#FFF5ED] border border-[#FECDAA] ml-4 sm:ml-8' : 'bg-slate-50 border border-slate-200 mr-4 sm:mr-8'; ?>" data-reply-id="<?php echo $r_id; ?>">
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
                                    <?php elseif (!empty($rep['message'])): ?>
                                        <p class="text-slate-800 leading-relaxed font-medium whitespace-pre-wrap"><?php echo sanitize($rep['message']); ?></p>
                                    <?php endif; ?>

                                    <?php 
                                    $att_list = parse_ticket_attachments($rep['attachment_path']);
                                    if (!empty($att_list)): 
                                    ?>
                                        <div class="mt-2.5 pt-2 border-t <?php echo $is_tech ? 'border-[#FFE8D5]' : 'border-slate-200'; ?>">
                                            <div class="flex flex-wrap gap-2.5">
                                                <?php foreach ($att_list as $img_rel): 
                                                    $img_url = '../' . ltrim($img_rel, '/');
                                                ?>
                                                    <a href="<?php echo sanitize($img_url); ?>" target="_blank" class="group relative inline-block">
                                                        <img src="<?php echo sanitize($img_url); ?>" alt="Attachment" class="h-28 w-auto max-w-[200px] object-cover rounded-2xl border <?php echo $is_tech ? 'border-[#FECDAA]' : 'border-slate-200'; ?> shadow-xs group-hover:opacity-90 group-hover:scale-[1.02] transition-all">
                                                        <span class="absolute bottom-1 right-1 bg-black/60 text-white text-[9px] font-bold px-1.5 py-0.5 rounded-md backdrop-blur-xs flex items-center gap-0.5 opacity-90 group-hover:opacity-100">
                                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                                            <span>View</span>
                                                        </span>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Reply Box -->
                        <div class="pt-6 border-t border-slate-100">
                            <form id="techReplyForm" action="ticket_detail.php?id=<?php echo $ticket_id; ?>" method="POST" enctype="multipart/form-data" class="space-y-4">
                                <input type="hidden" name="action" value="send_tech_reply">
                                <input type="hidden" name="id" value="<?php echo $ticket_id; ?>">

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">Send Support Reply to Client</label>
                                    <textarea id="techReplyTextarea" name="reply_message" rows="3" placeholder="Type your technician response or instructions here..." class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs sm:text-sm rounded-2xl p-4 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all"></textarea>
                                </div>

                                <!-- Photo Attachment Input -->
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">Attach Photos / Screenshots (Optional, Multiple Allowed)</label>
                                    <div class="flex items-center space-x-3">
                                        <label class="cursor-pointer bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-300 text-xs font-bold px-4 py-2.5 rounded-xl flex items-center space-x-2 transition-all shrink-0">
                                            <svg class="w-4 h-4 text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                            </svg>
                                            <span>Choose Photos</span>
                                            <input type="file" name="attachments[]" id="techChatPhotoInput" accept=".png, .jpg, .jpeg, image/png, image/jpeg" multiple class="hidden" onchange="previewTechChatPhotos(this)">
                                        </label>
                                        <span id="techChatPhotoName" class="text-xs text-slate-500 truncate max-w-[220px]">No photos chosen</span>
                                    </div>
                                    <p class="text-[11px] text-slate-500 mt-1.5 font-medium flex items-center gap-1">
                                        <svg class="w-3.5 h-3.5 text-[#EB3E0B] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        <span><strong class="text-[#EB3E0B]">Allowed formats:</strong> <strong class="text-slate-800">PNG, JPG, JPEG only</strong> (Max 15MB each)</span>
                                    </p>
                                    <div id="techChatPhotoPreviewBox" class="hidden mt-3 space-y-2">
                                        <div class="flex items-center justify-between">
                                            <span id="techChatPhotoCount" class="text-[11px] font-bold text-[#EB3E0B]">0 photos selected</span>
                                            <button type="button" onclick="clearTechChatPhotos()" class="text-[11px] font-bold text-rose-600 hover:underline">Clear All</button>
                                        </div>
                                        <div id="techChatPhotoGrid" class="flex flex-wrap gap-2.5 max-h-36 overflow-y-auto p-2 bg-slate-50 border border-slate-200 rounded-2xl">
                                            <!-- Previews injected via JS -->
                                        </div>
                                    </div>
                                </div>

                                <div class="flex items-center justify-between pt-2">
                                    <span class="text-[11px] text-slate-400">Replying as: <strong class="text-slate-700"><?php echo sanitize($tech_fullname); ?></strong></span>
                                    <button type="submit" id="techReplySubmitBtn" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs px-6 py-3 rounded-full shadow-md shadow-[#EB3E0B]/25 transition-all active:scale-95 flex items-center space-x-2">
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

<script>
var currentTicketId = <?php echo intval($ticket_id); ?>;
var lastReplyId = <?php echo intval($max_reply_id); ?>;
var isTechSubmitting = false;

function escapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function previewTechChatPhotos(input) {
    var previewBox = document.getElementById('techChatPhotoPreviewBox');
    var grid = document.getElementById('techChatPhotoGrid');
    var nameEl = document.getElementById('techChatPhotoName');
    var countEl = document.getElementById('techChatPhotoCount');

    if (!input.files || input.files.length === 0) {
        clearTechChatPhotos();
        return;
    }

    var validExts = ['png', 'jpg', 'jpeg'];
    var invalidFiles = [];
    for (var f = 0; f < input.files.length; f++) {
        var file = input.files[f];
        var ext = file.name.split('.').pop().toLowerCase();
        if (validExts.indexOf(ext) === -1) {
            invalidFiles.push(file.name);
        }
    }

    if (invalidFiles.length > 0) {
        alert('Invalid photo format: ' + invalidFiles.join(', ') + '\n\nOnly PNG, JPG, and JPEG photos are allowed. Please convert or choose supported image files.');
        clearTechChatPhotos();
        return;
    }

    grid.innerHTML = '';
    var total = input.files.length;
    nameEl.textContent = total + (total === 1 ? ' photo selected' : ' photos selected');
    if (countEl) countEl.textContent = total + (total === 1 ? ' photo selected' : ' photos selected');
    previewBox.classList.remove('hidden');

    for (var i = 0; i < total; i++) {
        (function(file) {
            var reader = new FileReader();
            reader.onload = function(e) {
                var thumb = document.createElement('div');
                thumb.className = 'relative inline-block';
                thumb.innerHTML = '<img src="' + e.target.result + '" alt="Preview" class="h-16 w-16 rounded-xl object-cover border border-slate-300 shadow-xs">';
                grid.appendChild(thumb);
            };
            reader.readAsDataURL(file);
        })(input.files[i]);
    }
}

function clearTechChatPhotos() {
    var input = document.getElementById('techChatPhotoInput');
    if (input) input.value = '';
    document.getElementById('techChatPhotoName').textContent = 'No photos chosen';
    document.getElementById('techChatPhotoPreviewBox').classList.add('hidden');
    document.getElementById('techChatPhotoGrid').innerHTML = '';
}

function buildTechReplyCard(reply) {
    var isTech = reply.is_tech;
    var containerClass = isTech 
        ? 'tech-reply-card p-4 sm:p-5 rounded-2xl text-xs space-y-2 bg-[#FFF5ED] border border-[#FECDAA] ml-4 sm:ml-8 animate-in fade-in zoom-in-95 duration-200'
        : 'tech-reply-card p-4 sm:p-5 rounded-2xl text-xs space-y-2 bg-slate-50 border border-slate-200 mr-4 sm:mr-8 animate-in fade-in zoom-in-95 duration-200';
    
    var senderNameColor = isTech ? 'text-[#EB3E0B]' : 'text-slate-900';
    var senderLabel = isTech ? '(RNZ Support Tech)' : '(Client)';
    var techIcon = isTech 
        ? '<svg class="w-4 h-4 text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>'
        : '';

    var contentHtml = '';
    if (reply.diagnostic_log) {
        contentHtml = '<p class="font-extrabold text-slate-900 text-xs sm:text-sm">This client is requesting assistance</p>' +
            '<details class="mt-1 text-xs">' +
            '<summary class="cursor-pointer font-bold text-[#EB3E0B] hover:underline">View Diagnostic Log</summary>' +
            '<pre class="mt-2 p-3 rounded-2xl bg-white border border-slate-200 text-slate-800 font-mono text-xs whitespace-pre-wrap leading-relaxed">' + escapeHtml(reply.diagnostic_log) + '</pre>' +
            '</details>';
    } else if (reply.message) {
        contentHtml = '<p class="text-slate-800 leading-relaxed font-medium whitespace-pre-wrap">' + escapeHtml(reply.message) + '</p>';
    }

    // Attachments
    var atts = reply.attachments || [];
    if (!atts.length && reply.attachment_path) {
        try {
            var parsed = JSON.parse(reply.attachment_path);
            if (Array.isArray(parsed)) atts = parsed;
            else atts = [reply.attachment_path];
        } catch(e) {
            atts = [reply.attachment_path];
        }
    }

    if (atts.length > 0) {
        var borderClr = isTech ? 'border-[#FECDAA]' : 'border-slate-200';
        contentHtml += '<div class="mt-2.5 pt-2 border-t ' + (isTech ? 'border-[#FFE8D5]' : 'border-slate-200') + '">' +
            '<div class="flex flex-wrap gap-2.5">';
        for (var i = 0; i < atts.length; i++) {
            var rawPath = atts[i].replace(/^\/+/, '');
            var imgUrl = '../' + rawPath;
            contentHtml += '<a href="' + escapeHtml(imgUrl) + '" target="_blank" class="group relative inline-block">' +
                '<img src="' + escapeHtml(imgUrl) + '" alt="Attachment" class="h-28 w-auto max-w-[200px] object-cover rounded-2xl border ' + borderClr + ' shadow-xs group-hover:opacity-90 group-hover:scale-[1.02] transition-all">' +
                '<span class="absolute bottom-1 right-1 bg-black/60 text-white text-[9px] font-bold px-1.5 py-0.5 rounded-md backdrop-blur-xs flex items-center gap-0.5 opacity-90 group-hover:opacity-100">' +
                    '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>' +
                    '<span>View</span>' +
                '</span>' +
            '</a>';
        }
        contentHtml += '</div></div>';
    }

    var card = document.createElement('div');
    card.className = containerClass;
    card.setAttribute('data-reply-id', reply.id);
    card.innerHTML = 
        '<div class="flex items-center justify-between">' +
            '<span class="font-bold flex items-center gap-1.5 ' + senderNameColor + '">' +
                techIcon + escapeHtml(reply.sender_name) + ' ' + senderLabel +
            '</span>' +
            '<span class="text-slate-400">' + escapeHtml(reply.formatted_date) + '</span>' +
        '</div>' + contentHtml;

    return card;
}

function pollTechReplies() {
    if (isTechSubmitting) return;

    fetch('api_ticket_replies.php?id=' + currentTicketId + '&after_id=' + lastReplyId)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data && data.success && data.replies && data.replies.length > 0) {
                var container = document.getElementById('backendRepliesContainer');
                data.replies.forEach(function(r) {
                    if (!document.querySelector('.tech-reply-card[data-reply-id="' + r.id + '"]')) {
                        var card = buildTechReplyCard(r);
                        container.appendChild(card);
                        if (r.id > lastReplyId) {
                            lastReplyId = r.id;
                        }
                    }
                });
            }
        })
        .catch(function(err) {
            console.warn('Tech chat poll error:', err);
        });
}

// Start polling every 3 seconds
var techChatPollInterval = setInterval(pollTechReplies, 3000);

// AJAX Form Submit for Support Tech
var techReplyForm = document.getElementById('techReplyForm');
var techReplyTextarea = document.getElementById('techReplyTextarea');
var techReplySubmitBtn = document.getElementById('techReplySubmitBtn');

if (techReplyForm && techReplyTextarea) {
    techReplyForm.addEventListener('submit', function(e) {
        e.preventDefault();
        var msg = techReplyTextarea.value.trim();
        var fileInput = document.getElementById('techChatPhotoInput');
        var hasFile = fileInput && fileInput.files && fileInput.files.length > 0;

        if (!msg && !hasFile) return;

        isTechSubmitting = true;
        techReplySubmitBtn.disabled = true;
        techReplySubmitBtn.classList.add('opacity-50', 'cursor-not-allowed');

        var formData = new FormData(techReplyForm);

        fetch('api_ticket_replies.php', {
            method: 'POST',
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            isTechSubmitting = false;
            techReplySubmitBtn.disabled = false;
            techReplySubmitBtn.classList.remove('opacity-50', 'cursor-not-allowed');

            if (data && data.success && data.reply) {
                techReplyTextarea.value = '';
                clearTechChatPhotos();
                var container = document.getElementById('backendRepliesContainer');
                if (!document.querySelector('.tech-reply-card[data-reply-id="' + data.reply.id + '"]')) {
                    var card = buildTechReplyCard(data.reply);
                    container.appendChild(card);
                    if (data.reply.id > lastReplyId) {
                        lastReplyId = data.reply.id;
                    }
                    card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            } else {
                alert((data && data.error) ? data.error : 'Failed to send response.');
            }
        })
        .catch(function(err) {
            isTechSubmitting = false;
            techReplySubmitBtn.disabled = false;
            techReplySubmitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            console.error('Submit error:', err);
            techReplyForm.submit();
        });
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
