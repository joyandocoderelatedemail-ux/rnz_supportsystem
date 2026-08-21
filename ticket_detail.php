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
    $photo_attachments = upload_ticket_photos('attachments');

    if (empty($reply_message) && empty($photo_attachments)) {
        $reply_error = 'Please enter a message or attach photo(s) before sending.';
    } else {
        $now = date('Y-m-d H:i:s');
        $stmt_r = $pdo->prepare("INSERT INTO client_ticket_replies (ticket_id, sender_type, sender_name, message, attachment_path, created_at) VALUES (:tid, 'client', :sname, :msg, :att, :c_at)");
        $stmt_r->execute(array(
            ':tid' => $ticket_id,
            ':sname' => $client['tradename'],
            ':msg' => $reply_message,
            ':att' => $photo_attachments ? $photo_attachments : null,
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

$max_reply_id = 0;
foreach ($replies as $r) {
    if (isset($r['id']) && intval($r['id']) > $max_reply_id) {
        $max_reply_id = intval($r['id']);
    }
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
                        <span id="ticketStatusBadge" class="inline-flex items-center px-3.5 py-1.5 rounded-full text-xs font-semibold border <?php echo get_status_badge_class($ticket['status']); ?>">
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
                        <span id="ticketAssignedTech" class="font-bold text-[#430D07]"><?php echo sanitize($ticket['assigned_tech']); ?></span>
                    </div>
                    <div>
                        <span class="block text-[#7C2112] font-medium">Last Updated</span>
                        <span id="ticketLastUpdated" class="font-bold text-[#430D07]"><?php echo format_date($ticket['updated_at']); ?></span>
                    </div>
                </div>
            </div>

            <!-- Conversation Thread -->
            <div class="space-y-4">
                <div class="flex items-center justify-between px-2">
                    <h3 class="text-base font-extrabold text-[#430D07]">Ticket Communication Thread</h3>
                    <div class="flex items-center space-x-2 text-[11px] font-bold text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-full border border-emerald-200">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span>Live Sync Active</span>
                    </div>
                </div>

                <div id="repliesThreadContainer" class="space-y-4">
                    <?php foreach ($replies as $r): 
                        $is_client = ($r['sender_type'] === 'client');
                        $r_id = isset($r['id']) ? intval($r['id']) : 0;
                    ?>
                        <div class="reply-card bg-white/90 rounded-3xl p-6 border <?php echo $is_client ? 'border-[#FECDAA]' : 'border-emerald-200'; ?> shadow-sm space-y-3" data-reply-id="<?php echo $r_id; ?>">
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
                                <p class="text-xs sm:text-sm text-[#430D07] leading-relaxed whitespace-pre-wrap"><?php echo sanitize($r['message']); ?></p>
                            <?php endif; ?>

                            <?php 
                            $att_list = parse_ticket_attachments($r['attachment_path']);
                            if (!empty($att_list)): 
                            ?>
                                <div class="mt-3 pt-2 border-t <?php echo $is_client ? 'border-[#FFE8D5]' : 'border-emerald-100'; ?>">
                                    <div class="flex flex-wrap gap-2.5">
                                        <?php foreach ($att_list as $img_path): ?>
                                            <a href="<?php echo sanitize($img_path); ?>" target="_blank" class="group relative inline-block">
                                                <img src="<?php echo sanitize($img_path); ?>" alt="Attachment" class="h-28 w-auto max-w-[200px] object-cover rounded-2xl border <?php echo $is_client ? 'border-[#FECDAA]' : 'border-emerald-200'; ?> shadow-xs group-hover:opacity-90 group-hover:scale-[1.02] transition-all">
                                                <span class="absolute bottom-1 right-1 bg-black/60 text-white text-[9px] font-bold px-1.5 py-0.5 rounded-md backdrop-blur-xs flex items-center gap-0.5 opacity-90 group-hover:opacity-100">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
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
            </div>

            <!-- Reply Input Box -->
            <div class="bg-white/90 rounded-3xl p-6 border border-[#FECDAA] shadow-sm">
                <form id="replyForm" action="ticket_detail.php?id=<?php echo $ticket_id; ?>" method="POST" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="action" value="post_reply">
                    <input type="hidden" name="id" value="<?php echo $ticket_id; ?>">
                    
                    <div>
                        <label class="block text-xs font-bold text-[#430D07] uppercase tracking-wider mb-2">Send Reply or Update</label>
                        <textarea id="replyTextarea" name="message" rows="3" placeholder="Type your message here to update technical support..." class="w-full bg-[#FFF5ED] border border-[#FECDAA] text-[#430D07] text-xs sm:text-sm rounded-2xl p-4 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all"></textarea>
                    </div>

                    <!-- Photo Attachment Input -->
                    <div>
                        <label class="block text-xs font-bold text-[#430D07] uppercase tracking-wider mb-1.5">Attach Photos / Screenshots (Optional, Multiple Allowed)</label>
                        <div class="flex items-center space-x-3">
                            <label class="cursor-pointer bg-[#FFE8D5] hover:bg-[#FECDAA] text-[#430D07] border border-[#FECDAA] text-xs font-bold px-4 py-2.5 rounded-xl flex items-center space-x-2 transition-all shrink-0">
                                <svg class="w-4 h-4 text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                                <span>Choose Photos</span>
                                <input type="file" name="attachments[]" id="chatPhotoInput" accept=".png, .jpg, .jpeg, image/png, image/jpeg" multiple class="hidden" onchange="previewChatPhotos(this)">
                            </label>
                            <span id="chatPhotoName" class="text-xs text-[#7C2112] truncate max-w-[220px]">No photos chosen</span>
                        </div>
                        <p class="text-[11px] text-[#7C2112] mt-1.5 font-medium flex items-center gap-1">
                            <svg class="w-3.5 h-3.5 text-[#EB3E0B] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <span><strong class="text-[#EB3E0B]">Allowed formats:</strong> <strong class="text-[#430D07]">PNG, JPG, JPEG only</strong> (Max 15MB each)</span>
                        </p>
                        <div id="chatPhotoPreviewBox" class="hidden mt-3 space-y-2">
                            <div class="flex items-center justify-between">
                                <span id="chatPhotoCount" class="text-[11px] font-bold text-[#EB3E0B]">0 photos selected</span>
                                <button type="button" onclick="clearChatPhotos()" class="text-[11px] font-bold text-rose-600 hover:underline">Clear All</button>
                            </div>
                            <div id="chatPhotoGrid" class="flex flex-wrap gap-2.5 max-h-36 overflow-y-auto p-2 bg-[#FFF5ED] border border-[#FECDAA] rounded-2xl">
                                <!-- Previews injected via JS -->
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-2">
                        <span id="replySendingStatus" class="text-xs text-[#7C2112] font-semibold hidden">Sending message...</span>
                        <button type="submit" id="replySubmitBtn" class="ml-auto bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs sm:text-sm px-6 py-2.5 rounded-full shadow-md shadow-[#EB3E0B]/25 transition-all active:scale-95 flex items-center space-x-2">
                            <span>Post Reply</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                        </button>
                    </div>
                </form>
            </div>

        </main>
    </div>
</div>

<script>
var currentTicketId = <?php echo intval($ticket_id); ?>;
var lastReplyId = <?php echo intval($max_reply_id); ?>;
var isSubmitting = false;

// Function to escape HTML
function escapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function previewChatPhotos(input) {
    var previewBox = document.getElementById('chatPhotoPreviewBox');
    var grid = document.getElementById('chatPhotoGrid');
    var nameEl = document.getElementById('chatPhotoName');
    var countEl = document.getElementById('chatPhotoCount');

    if (!input.files || input.files.length === 0) {
        clearChatPhotos();
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
        clearChatPhotos();
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
                thumb.innerHTML = '<img src="' + e.target.result + '" alt="Preview" class="h-16 w-16 rounded-xl object-cover border border-[#FECDAA] shadow-xs">';
                grid.appendChild(thumb);
            };
            reader.readAsDataURL(file);
        })(input.files[i]);
    }
}

function clearChatPhotos() {
    var input = document.getElementById('chatPhotoInput');
    if (input) input.value = '';
    document.getElementById('chatPhotoName').textContent = 'No photos chosen';
    document.getElementById('chatPhotoPreviewBox').classList.add('hidden');
    document.getElementById('chatPhotoGrid').innerHTML = '';
}

// Function to create HTML card for a new reply
function buildReplyCard(reply) {
    var isClient = reply.is_client;
    var borderColor = isClient ? 'border-[#FECDAA]' : 'border-emerald-200';
    var headerBorder = isClient ? 'border-[#FFE8D5]' : 'border-emerald-100';
    var avatarBg = isClient ? 'bg-[#EB3E0B] text-white' : 'bg-emerald-600 text-white';
    var roleBadge = isClient 
        ? '<span class="text-[10px] px-2 py-0.5 rounded-full font-semibold bg-[#FFE8D5] text-[#EB3E0B]">Client</span>'
        : '<span class="text-[10px] px-2 py-0.5 rounded-full font-semibold bg-emerald-50 text-emerald-700">Support Tech</span>';
    var firstLetter = (reply.sender_name || 'U').charAt(0).toUpperCase();

    var contentHtml = '';
    if (reply.diagnostic_log) {
        contentHtml = '<p class="text-xs sm:text-sm text-[#430D07] font-extrabold">This client is requesting assistance</p>' +
            '<details class="mt-1 text-xs">' +
            '<summary class="cursor-pointer font-bold text-[#EB3E0B] hover:underline">View Diagnostic Log</summary>' +
            '<pre class="mt-2 p-3 rounded-2xl bg-[#FFF5ED] border border-[#FECDAA] text-[#430D07] font-mono text-xs whitespace-pre-wrap leading-relaxed">' + escapeHtml(reply.diagnostic_log) + '</pre>' +
            '</details>';
    } else if (reply.message) {
        contentHtml = '<p class="text-xs sm:text-sm text-[#430D07] leading-relaxed whitespace-pre-wrap">' + escapeHtml(reply.message) + '</p>';
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
        contentHtml += '<div class="mt-3 pt-2 border-t ' + headerBorder + '">' +
            '<div class="flex flex-wrap gap-2.5">';
        for (var i = 0; i < atts.length; i++) {
            var path = escapeHtml(atts[i]);
            contentHtml += '<a href="' + path + '" target="_blank" class="group relative inline-block">' +
                '<img src="' + path + '" alt="Attachment" class="h-28 w-auto max-w-[200px] object-cover rounded-2xl border ' + borderColor + ' shadow-xs group-hover:opacity-90 group-hover:scale-[1.02] transition-all">' +
                '<span class="absolute bottom-1 right-1 bg-black/60 text-white text-[9px] font-bold px-1.5 py-0.5 rounded-md backdrop-blur-xs flex items-center gap-0.5 opacity-90 group-hover:opacity-100">' +
                    '<svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>' +
                    '<span>View</span>' +
                '</span>' +
            '</a>';
        }
        contentHtml += '</div></div>';
    }

    var card = document.createElement('div');
    card.className = 'reply-card bg-white/90 rounded-3xl p-6 border ' + borderColor + ' shadow-sm space-y-3 animate-in fade-in zoom-in-95 duration-200';
    card.setAttribute('data-reply-id', reply.id);
    card.innerHTML = 
        '<div class="flex items-center justify-between border-b ' + headerBorder + ' pb-3">' +
            '<div class="flex items-center space-x-2.5">' +
                '<div class="w-7 h-7 rounded-full ' + avatarBg + ' font-bold text-xs flex items-center justify-center">' + firstLetter + '</div>' +
                '<span class="text-xs font-bold text-[#430D07]">' + escapeHtml(reply.sender_name) + '</span>' +
                roleBadge +
            '</div>' +
            '<span class="text-[11px] text-[#9A2512] font-mono">' + escapeHtml(reply.formatted_date) + '</span>' +
        '</div>' + contentHtml;
    
    return card;
}

// Function to poll new replies
function pollReplies() {
    if (isSubmitting) return;

    fetch('api_ticket_replies.php?id=' + currentTicketId + '&after_id=' + lastReplyId)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data && data.success) {
                if (data.replies && data.replies.length > 0) {
                    var container = document.getElementById('repliesThreadContainer');
                    data.replies.forEach(function(r) {
                        if (!document.querySelector('.reply-card[data-reply-id="' + r.id + '"]')) {
                            var card = buildReplyCard(r);
                            container.appendChild(card);
                            if (r.id > lastReplyId) {
                                lastReplyId = r.id;
                            }
                        }
                    });
                }
                // Live update status badge if changed
                if (data.ticket_status) {
                    var badge = document.getElementById('ticketStatusBadge');
                    if (badge) {
                        badge.textContent = data.ticket_status;
                        if (data.status_badge_class) {
                            badge.className = 'inline-flex items-center px-3.5 py-1.5 rounded-full text-xs font-semibold border ' + data.status_badge_class;
                        }
                    }
                }
                if (data.assigned_tech) {
                    var techEl = document.getElementById('ticketAssignedTech');
                    if (techEl) techEl.textContent = data.assigned_tech;
                }
                if (data.last_updated) {
                    var upEl = document.getElementById('ticketLastUpdated');
                    if (upEl) upEl.textContent = data.last_updated;
                }
            }
        })
        .catch(function(err) {
            console.warn('Chat poll error:', err);
        });
}

// Start polling every 3 seconds
var chatPollInterval = setInterval(pollReplies, 3000);

// AJAX Form Submit for instant message sending without refresh
var replyForm = document.getElementById('replyForm');
var replyTextarea = document.getElementById('replyTextarea');
var replySubmitBtn = document.getElementById('replySubmitBtn');
var replySendingStatus = document.getElementById('replySendingStatus');

if (replyForm && replyTextarea) {
    replyForm.addEventListener('submit', function(e) {
        e.preventDefault();
        var msg = replyTextarea.value.trim();
        var fileInput = document.getElementById('chatPhotoInput');
        var hasFile = fileInput && fileInput.files && fileInput.files.length > 0;

        if (!msg && !hasFile) return;

        isSubmitting = true;
        replySubmitBtn.disabled = true;
        replySubmitBtn.classList.add('opacity-50', 'cursor-not-allowed');
        if (replySendingStatus) replySendingStatus.classList.remove('hidden');

        var formData = new FormData(replyForm);

        fetch('api_ticket_replies.php', {
            method: 'POST',
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            isSubmitting = false;
            replySubmitBtn.disabled = false;
            replySubmitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            if (replySendingStatus) replySendingStatus.classList.add('hidden');

            if (data && data.success && data.reply) {
                replyTextarea.value = '';
                clearChatPhotos();
                var container = document.getElementById('repliesThreadContainer');
                if (!document.querySelector('.reply-card[data-reply-id="' + data.reply.id + '"]')) {
                    var card = buildReplyCard(data.reply);
                    container.appendChild(card);
                    if (data.reply.id > lastReplyId) {
                        lastReplyId = data.reply.id;
                    }
                    card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            } else {
                alert((data && data.error) ? data.error : 'Failed to send reply. Please try again.');
            }
        })
        .catch(function(err) {
            isSubmitting = false;
            replySubmitBtn.disabled = false;
            replySubmitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            if (replySendingStatus) replySendingStatus.classList.add('hidden');
            console.error('Submit error:', err);
            replyForm.submit();
        });
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
