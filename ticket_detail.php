<?php
// Ticket Details & Replies Page
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db_init.php';
require_once __DIR__ . '/includes/hardware_data.php';

require_login();
$client = get_logged_client();
$accountnum = $client['accountnum'];

$pdo = get_db_connection();

$ticket_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($ticket_id <= 0) {
    header("Location: tickets.php");
    exit;
}

// Fetch ticket & verify ownership
$stmt = $pdo->prepare("SELECT * FROM client_support_tickets WHERE id = :id AND accountnum = :acct");
$stmt->execute(array(':id' => $ticket_id, ':acct' => $accountnum));
$ticket = $stmt->fetch();

if (!$ticket) {
    die("Ticket not found or permission denied.");
}

$reply_error = '';

// Handle Reply Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'post_reply') {
    $reply_message = isset($_POST['message']) ? trim($_POST['message']) : '';

    if (empty($reply_message)) {
        $reply_error = 'Please enter a message before sending.';
    } else {
        $now = date('Y-m-d H:i:s');
        $stmt_r = $pdo->prepare("INSERT INTO client_ticket_replies (ticket_id, sender_type, sender_name, message, created_at) VALUES (:tid, 'client', :sname, :msg, :c_at)");
        $stmt_r->execute(array(
            ':tid' => $ticket_id,
            ':sname' => $client['tradename'],
            ':msg' => $reply_message,
            ':c_at' => $now
        ));

        // Update ticket updated_at
        $stmt_u = $pdo->prepare("UPDATE client_support_tickets SET updated_at = :now WHERE id = :id");
        $stmt_u->execute(array(':now' => $now, ':id' => $ticket_id));

        header("Location: ticket_detail.php?id=" . $ticket_id);
        exit;
    }
}

// Fetch conversation replies
$stmt_replies = $pdo->prepare("SELECT * FROM client_ticket_replies WHERE ticket_id = :tid ORDER BY id ASC");
$stmt_replies->execute(array(':tid' => $ticket_id));
$replies = $stmt_replies->fetchAll();

if (empty($replies)) {
    $replies = array(
        array(
            'id' => 0,
            'ticket_id' => $ticket_id,
            'sender_type' => 'client',
            'sender_name' => $client['tradename'],
            'message' => $ticket['issue_description'],
            'created_at' => $ticket['created_at']
        )
    );
}

$active_page = 'tickets';
$page_title = 'Ticket #' . $ticket['ticket_number'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Details - <?php echo sanitize($ticket['ticket_number']); ?></title>
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

        <main class="p-4 sm:p-6 md:p-8 pb-24 md:pb-8 space-y-6 max-w-5xl w-full mx-auto">

            <!-- Back link -->
            <a href="tickets.php" class="inline-flex items-center space-x-2 text-xs font-bold text-[#7C2112] hover:text-[#430D07] transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                <span>Back to Tickets List</span>
            </a>

            <!-- Ticket Information Header Card -->
            <div class="bg-white/90 rounded-3xl p-6 sm:p-8 border border-[#FECDAA] shadow-sm space-y-6">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-[#FFE8D5] pb-6">
                    <div>
                        <div class="flex items-center space-x-3 mb-2">
                            <span class="font-mono text-xs font-bold text-[#EB3E0B] bg-[#FFE8D5] px-3 py-1 rounded-full border border-[#FECDAA]">
                                <?php echo sanitize($ticket['ticket_number']); ?>
                            </span>
                            <span class="text-xs text-[#7C2112] font-medium">Category: <?php echo sanitize($ticket['category']); ?></span>
                        </div>
                        <h2 class="text-xl sm:text-2xl font-extrabold text-[#430D07] tracking-tight">
                            <?php echo sanitize($ticket['subject']); ?>
                        </h2>
                    </div>

                    <div class="flex items-center space-x-3 shrink-0">
                        <span class="inline-flex items-center px-3.5 py-1.5 rounded-full text-xs font-semibold border <?php echo get_status_badge_class($ticket['status']); ?>">
                            <?php echo sanitize($ticket['status']); ?>
                        </span>
                    </div>
                </div>

                <!-- Ticket Meta Details -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-xs">
                    <div>
                        <span class="block text-[#7C2112] font-medium">Date Created</span>
                        <span class="font-bold text-[#430D07]"><?php echo format_date($ticket['created_at']); ?></span>
                    </div>
                    <div>
                        <span class="block text-[#7C2112] font-medium">Priority</span>
                        <span class="font-bold text-[#430D07]"><?php echo sanitize($ticket['priority']); ?></span>
                    </div>
                    <div>
                        <span class="block text-[#7C2112] font-medium">Assigned Tech</span>
                        <span class="font-bold text-[#430D07]"><?php echo sanitize($ticket['assigned_tech']); ?></span>
                    </div>
                    <div>
                        <span class="block text-[#7C2112] font-medium">Last Updated</span>
                        <span class="font-bold text-[#430D07]"><?php echo format_date($ticket['updated_at']); ?></span>
                    </div>
                </div>
            </div>

            <!-- Conversation Thread -->
            <div class="space-y-4">
                <h3 class="text-base font-extrabold text-[#430D07] px-2">Ticket Communication Thread</h3>

                <?php foreach ($replies as $r): 
                    $is_client = ($r['sender_type'] === 'client');
                ?>
                    <div class="bg-white/90 rounded-3xl p-6 border <?php echo $is_client ? 'border-[#FECDAA]' : 'border-emerald-200'; ?> shadow-sm space-y-3">
                        <div class="flex items-center justify-between border-b <?php echo $is_client ? 'border-[#FFE8D5]' : 'border-emerald-100'; ?> pb-3">
                            <div class="flex items-center space-x-2.5">
                                <div class="w-7 h-7 rounded-full <?php echo $is_client ? 'bg-[#EB3E0B] text-white' : 'bg-emerald-600 text-white'; ?> font-bold text-xs flex items-center justify-center">
                                    <?php echo strtoupper(substr($r['sender_name'], 0, 1)); ?>
                                </div>
                                <span class="text-xs font-bold text-[#430D07]"><?php echo sanitize($r['sender_name']); ?></span>
                                <span class="text-[10px] px-2 py-0.5 rounded-full font-semibold <?php echo $is_client ? 'bg-[#FFE8D5] text-[#EB3E0B]' : 'bg-emerald-50 text-emerald-700'; ?>">
                                    <?php echo $is_client ? 'Client' : 'Support Tech'; ?>
                                </span>
                            </div>
                            <span class="text-[11px] text-[#9A2512] font-mono"><?php echo format_date($r['created_at']); ?></span>
                        </div>
                        <?php if (strpos($r['message'], '=== HARDWARE DIAGNOSTIC LOG ===') !== false): ?>
                            <p class="text-xs sm:text-sm text-[#430D07] font-extrabold">This client is requesting assistance</p>
                            <details class="mt-1 text-xs">
                                <summary class="cursor-pointer font-bold text-[#EB3E0B] hover:underline">View Diagnostic Log</summary>
                                <pre class="mt-2 p-3 rounded-2xl bg-[#FFF5ED] border border-[#FECDAA] text-[#430D07] font-mono text-xs whitespace-pre-wrap leading-relaxed"><?php echo sanitize(format_diagnostic_log_text($r['message'])); ?></pre>
                            </details>
                        <?php else: ?>
                            <p class="text-xs sm:text-sm text-[#430D07] leading-relaxed whitespace-pre-wrap">
                                <?php echo sanitize($r['message']); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Reply Input Box -->
            <div class="bg-white/90 rounded-3xl p-6 border border-[#FECDAA] shadow-sm">
                <form action="ticket_detail.php?id=<?php echo $ticket_id; ?>" method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="post_reply">
                    
                    <div>
                        <label class="block text-xs font-bold text-[#430D07] uppercase tracking-wider mb-2">Send Reply or Update</label>
                        <textarea name="message" rows="3" required placeholder="Type your message here to update technical support..." class="w-full bg-[#FFF5ED] border border-[#FECDAA] text-[#430D07] text-xs sm:text-sm rounded-2xl p-4 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all"></textarea>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs sm:text-sm px-6 py-2.5 rounded-full shadow-md shadow-[#EB3E0B]/25 transition-all active:scale-95">
                            Post Reply
                        </button>
                    </div>
                </form>
            </div>

        </main>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
