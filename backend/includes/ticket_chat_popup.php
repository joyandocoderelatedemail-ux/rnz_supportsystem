<?php
// Shared Ticket Chat Pop-up (PHP 5.6 Compatible)
//
// Include this at the bottom of any backend page that renders ticket rows with
// class="ticket-row" plus the data-ticket-* attributes. The tickets center and
// the dashboard queue both use it, so a ticket behaves the same in both places.
if (!isset($my_tier)) {
    $my_tier = get_logged_tech_access_tier();
}
// A host page can hand over one ticket to open on load (see the open_ticket
// parameter the notification links carry). Null means open nothing.
if (!isset($chat_autoload_ticket)) {
    $chat_autoload_ticket = null;
}
?>
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

            <!-- Who moved this ticket to In Progress -->
            <div id="ticketChatPickedUp" class="hidden items-center gap-1.5 rounded-xl bg-blue-50 border border-blue-200 px-2.5 py-1.5">
                <svg class="w-3.5 h-3.5 text-blue-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
                <p class="text-[10px] font-bold text-blue-900 leading-snug min-w-0">
                    Set to In Progress by <span id="ticketChatPickedUpBy" class="font-extrabold"></span><span id="ticketChatPickedUpAt" class="font-medium text-blue-700"></span>
                </p>
            </div>
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

// The same colour table the rows were rendered with, so a live status change
// repaints a row exactly as a page reload would
var TICKET_ROW_PALETTE = <?php echo json_encode(array(
    'Pending'     => ticket_row_palette('Pending'),
    'Open'        => ticket_row_palette('Open'),
    'In Progress' => ticket_row_palette('In Progress'),
    'Resolved'    => ticket_row_palette('Resolved'),
    'Closed'      => ticket_row_palette('Closed')
)); ?>;

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
    renderTicketChatPickedUp('', '');  // filled in by the first poll
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

// Opens a ticket the page was asked to show on load - a notification click
// lands here. The row is used when the ticket is on screen so it highlights
// and repaints with the thread; otherwise the chat opens from the ticket data
// the server sent, so a filtered or paginated-away ticket still opens.
function openTicketChatById(meta) {
    if (!meta) return;
    var id = parseInt(meta.id, 10) || 0;
    if (!id) return;

    var row = document.querySelector('.ticket-row[data-ticket-id="' + id + '"]');
    if (row) {
        openTicketChat(row, null);
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        row.classList.add('ring-2', 'ring-inset', 'ring-[#FA5915]');
        setTimeout(function() {
            row.classList.remove('ring-2', 'ring-inset', 'ring-[#FA5915]');
        }, 2200);
        return;
    }

    var holder = document.createElement('div');
    holder.className = 'ticket-row';
    holder.setAttribute('data-ticket-id', id);
    holder.setAttribute('data-ticket-number', meta.ticket_number || '');
    holder.setAttribute('data-client', meta.client || '');
    holder.setAttribute('data-subject', meta.subject || '');
    holder.setAttribute('data-status', meta.status || '');
    holder.setAttribute('data-issue', meta.issue || '');
    holder.setAttribute('data-created', meta.created || '');
    holder.setAttribute('data-account', meta.account || '');
    holder.setAttribute('data-tradename', meta.tradename || '');
    holder.setAttribute('data-address', meta.address || '');
    openTicketChat(holder, null);
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
        applyTicketRowPalette(row, status, badgeClass);
    }
}

// Repaints a whole row for a new status. The colours are baked in by PHP when
// the page renders, so changing only the badge left a ticket that moved to
// In Progress still wearing its red Pending row until the next reload.
function applyTicketRowPalette(row, status, badgeClass) {
    var pal = TICKET_ROW_PALETTE[status];

    var badge = row.querySelector('[data-cell="badge"]');
    if (badge) {
        badge.textContent = status;
        var badgeCls = pal ? pal.badge : badgeClass;
        if (badgeCls) {
            badge.className = 'inline-flex items-center justify-center whitespace-nowrap px-2.5 py-1 rounded-full text-[10px] font-bold border ' + badgeCls;
        }
    }

    // An unlisted status keeps the row it was rendered with
    if (!pal) return;

    row.className = 'ticket-row ' + pal.row + ' transition-colors cursor-pointer';

    var paint = function(selector, classes) {
        var el = row.querySelector(selector);
        if (el) el.className = classes;
    };
    paint('[data-cell="num"]', 'py-4 px-6 font-mono font-bold ' + pal.num);
    paint('[data-cell="title"]', 'font-bold ' + pal.title);
    paint('[data-cell="subject"]', 'py-4 px-6 max-w-xs truncate font-semibold ' + pal.subj);
    paint('[data-cell="date"]', 'py-4 px-6 ' + pal.date);
    paint('[data-cell="note"]', 'inline-flex items-center justify-center w-9 h-9 rounded-full ' + pal.btn + ' transition-colors shadow-xs');
}

// Names the technician who moved the ticket to In Progress. Stays visible
// after the ticket moves on, since it is a record of who picked it up.
function renderTicketChatPickedUp(by, at) {
    var box = document.getElementById('ticketChatPickedUp');
    if (!by) {
        box.classList.add('hidden');
        box.classList.remove('flex');
        return;
    }
    document.getElementById('ticketChatPickedUpBy').textContent = by;
    document.getElementById('ticketChatPickedUpAt').textContent = at ? (' - ' + at) : '';
    box.classList.remove('hidden');
    box.classList.add('flex');
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
            if (data.in_progress_by) renderTicketChatPickedUp(data.in_progress_by, data.in_progress_at);

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
            renderTicketChatPickedUp(data.in_progress_by, data.in_progress_at);

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

// A notification click asked for one ticket - open its thread once the rows
// are on the page.
var TICKET_CHAT_AUTOLOAD = <?php echo json_encode($chat_autoload_ticket); ?>;
if (TICKET_CHAT_AUTOLOAD) {
    document.addEventListener('DOMContentLoaded', function() {
        openTicketChatById(TICKET_CHAT_AUTOLOAD);
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
