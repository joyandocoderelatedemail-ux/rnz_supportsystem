<?php
// Shared Ticket Chat Pop-up (PHP 5.6 Compatible)
//
// Include this at the bottom of any backend page that renders ticket rows with
// class="ticket-row" plus the data-ticket-* attributes. The tickets center and
// the dashboard queue both use it, so a ticket behaves the same in both places.
//
// Up to CHAT_MAX_OPEN conversations can be open at once, each in its own box
// docked at the lower right - clicking a new ticket row no longer replaces
// whatever is already open, so several threads can be worked side by side.
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
<!-- TICKET CHAT DOCK - individual chat boxes are created here by JS, one per  -->
<!-- open ticket, so several conversations can sit side by side at once       -->
<!-- ========================================================================= -->
<div id="ticketChatDock" class="fixed bottom-4 right-4 z-50 flex items-end gap-3 max-w-[calc(100vw-2rem)] overflow-x-auto"></div>

<script>
// =========================================================================
// TICKET CHAT POP-UP
// Clicking a ticket row opens the conversation in its own box docked at the
// lower right. Up to CHAT_MAX_OPEN stay open together - opening one more than
// that closes whichever open conversation has gone longest without being
// looked at, so the list never grows without bound.
// =========================================================================
var CHAT_MAX_OPEN = 3;

// Kept in step with get_ticket_attachment_size_limits() in includes/config.php -
// the server has the final say, this only gives instant feedback instead of a
// silent drop after the whole file has already been uploaded.
var CHAT_ATTACHMENT_MAX_SIZE = {
    png: 15 * 1024 * 1024, jpg: 15 * 1024 * 1024, jpeg: 15 * 1024 * 1024,
    pdf: 20 * 1024 * 1024, xls: 20 * 1024 * 1024, xlsx: 20 * 1024 * 1024,
    txt: 5 * 1024 * 1024,
    mp4: 50 * 1024 * 1024, mov: 50 * 1024 * 1024, webm: 50 * 1024 * 1024, avi: 50 * 1024 * 1024
};
var CHAT_ATTACHMENT_ACCEPT = '.png,.jpg,.jpeg,.pdf,.xls,.xlsx,.txt,.mp4,.mov,.webm,.avi,' +
    'image/png,image/jpeg,application/pdf,' +
    'application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,' +
    'text/plain,video/mp4,video/quicktime,video/webm,video/x-msvideo';
var chats = {};        // ticketId -> { ticketId, box, lastReplyId, pollTimer, isSending, isMinimized, clientSeenId, lastTypingPing, replyToId }
var chatOrder = [];    // open ticket ids, least recently used first
var chatMyTier = <?php echo intval($my_tier); ?>;
var chatDock = document.getElementById('ticketChatDock');

// The same colour table the rows were rendered with, so a live status change
// repaints a row exactly as a page reload would
var TICKET_ROW_PALETTE = <?php echo json_encode(array(
    'Pending'     => ticket_row_palette('Pending'),
    'Open'        => ticket_row_palette('Open'),
    'In Progress' => ticket_row_palette('In Progress'),
    'Resolved'    => ticket_row_palette('Resolved'),
    'Closed'      => ticket_row_palette('Closed')
)); ?>;

function escapeChatHtml(str) {
    return String(str === null || typeof str === 'undefined' ? '' : str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

// Safe to drop inside a single-quoted inline handler
function escapeChatAttr(str) {
    return escapeChatHtml(JSON.stringify(String(str === null || typeof str === 'undefined' ? '' : str)));
}

// One box's markup, scoped with data-role attributes instead of ids so any
// number of these can exist on the page at once without id collisions.
function buildChatBoxHtml(ticketId) {
    var statusDisabled = (chatMyTier === 1) ? 'disabled' : '';
    var tier1Notice = (chatMyTier === 1)
        ? '<p class="text-[9px] font-bold text-slate-400">Level 1 (View Only) accounts cannot change the ticket status.</p>'
        : '';

    return '' +
    '<div class="shrink-0 bg-gradient-to-r from-[#EB3E0B] to-[#FA5915] text-white px-4 py-3 flex items-center gap-3">' +
        '<button type="button" onclick="toggleTicketChatMinimize(' + ticketId + ')" class="min-w-0 flex-1 text-left" title="Click to minimize / expand">' +
            '<div class="flex items-center gap-1.5">' +
                '<span class="w-2 h-2 rounded-full bg-emerald-300 animate-pulse shrink-0"></span>' +
                '<span data-role="number" class="font-mono font-bold text-xs truncate"></span>' +
            '</div>' +
            '<div data-role="client" class="text-[11px] font-bold truncate opacity-95"></div>' +
            '<div data-role="subject" class="text-[10px] truncate opacity-80"></div>' +
        '</button>' +
        '<div class="flex items-center gap-1 shrink-0">' +
            '<span data-role="statusPill" class="px-2 py-0.5 rounded-full text-[9px] font-bold bg-white/20 border border-white/30"></span>' +
            '<a data-role="fullLink" href="#" title="Open full ticket console" class="w-7 h-7 rounded-full hover:bg-white/20 flex items-center justify-center transition-colors">' +
                '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>' +
            '</a>' +
            '<button type="button" onclick="toggleTicketChatMinimize(' + ticketId + ')" title="Minimize" class="w-7 h-7 rounded-full hover:bg-white/20 flex items-center justify-center transition-colors">' +
                '<svg data-role="minIcon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 13H5"/></svg>' +
            '</button>' +
            '<button type="button" onclick="closeTicketChat(' + ticketId + ')" title="Close chat" class="w-7 h-7 rounded-full hover:bg-white/20 flex items-center justify-center transition-colors">' +
                '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>' +
            '</button>' +
        '</div>' +
    '</div>' +

    '<div data-role="body" class="flex flex-col min-h-0">' +

        '<div class="shrink-0 px-3.5 py-2 bg-white border-b border-slate-200 space-y-1.5">' +
            '<div class="flex items-center gap-2">' +
                '<label class="text-[10px] font-bold uppercase tracking-wider text-slate-500 shrink-0">Status</label>' +
                '<select data-role="statusSelect" onchange="onTicketChatStatusPicked(' + ticketId + ')" ' + statusDisabled +
                    ' class="flex-1 min-w-0 bg-slate-50 border border-slate-200 text-slate-900 text-[11px] font-bold rounded-xl px-2.5 py-1.5 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all disabled:opacity-60 disabled:cursor-not-allowed">' +
                    '<option value="Pending">Pending</option>' +
                    '<option value="In Progress">In Progress</option>' +
                    '<option value="Resolved">Resolved</option>' +
                    '<option value="Closed">Closed</option>' +
                '</select>' +
                '<span data-role="statusSaved" class="hidden text-[10px] font-bold text-emerald-600 shrink-0">Saved</span>' +
            '</div>' +
            tier1Notice +
            '<div data-role="codeRow" class="hidden items-center gap-1.5">' +
                '<input type="password" data-role="accessCode" placeholder="Security access code" ' +
                    'class="flex-1 min-w-0 bg-white border border-amber-300 text-slate-900 text-[10px] font-mono tracking-widest text-center rounded-xl px-2 py-1.5 focus:outline-none focus:border-[#EB3E0B] placeholder:tracking-normal placeholder:font-sans">' +
                '<button type="button" onclick="submitTicketChatStatus(' + ticketId + ')" class="px-2.5 py-1.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-[10px] font-bold shrink-0 transition-colors">Apply</button>' +
                '<button type="button" onclick="cancelTicketChatStatus(' + ticketId + ')" class="px-2 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 text-[10px] font-bold shrink-0 transition-colors">Cancel</button>' +
            '</div>' +
            '<p data-role="statusError" class="hidden text-[10px] font-bold text-rose-600 leading-snug"></p>' +
            '<div data-role="pickedUp" class="hidden items-center gap-1.5 rounded-xl bg-blue-50 border border-blue-200 px-2.5 py-1.5">' +
                '<svg class="w-3.5 h-3.5 text-blue-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">' +
                    '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>' +
                '</svg>' +
                '<p class="text-[10px] font-bold text-blue-900 leading-snug min-w-0">' +
                    'Set to In Progress by <span data-role="pickedUpBy" class="font-extrabold"></span><span data-role="pickedUpAt" class="font-medium text-blue-700"></span>' +
                '</p>' +
            '</div>' +
        '</div>' +

        '<div data-role="thread" class="h-[46vh] sm:h-[320px] overflow-y-auto bg-slate-50 px-3.5 py-4 space-y-3">' +
            '<div class="text-center text-[11px] text-slate-400 font-bold py-6">Loading conversation...</div>' +
        '</div>' +

        '<div data-role="typing" class="hidden px-4 py-1.5 bg-slate-50 border-t border-slate-100 text-[10px] font-bold text-slate-500 italic">' +
            'Client is typing...' +
        '</div>' +

        '<div data-role="closedBanner" class="hidden shrink-0 px-4 py-3.5 bg-slate-100 border-t border-slate-200 text-center space-y-1">' +
            '<p class="text-[11px] font-bold text-slate-700">Chat closed - ticket is <span data-role="closedStatus"></span></p>' +
            '<p class="text-[10px] text-slate-500 leading-relaxed">Set the status back to Pending or In Progress above to resume the conversation.</p>' +
        '</div>' +

        '<div data-role="composer" class="shrink-0 border-t border-slate-200 bg-white p-3 space-y-2">' +
            '<div data-role="error" class="hidden text-[10px] font-bold text-rose-600 bg-rose-50 border border-rose-200 rounded-xl px-2.5 py-1.5"></div>' +

            '<div data-role="replyBar" class="hidden items-start justify-between gap-2 bg-[#FFF5ED] border border-[#FECDAA] rounded-xl px-2.5 py-1.5">' +
                '<div class="min-w-0">' +
                    '<p class="text-[9px] font-bold uppercase tracking-wider text-[#9A2512]">Replying to <span data-role="replyName"></span></p>' +
                    '<p data-role="replySnippet" class="text-[10px] text-slate-600 truncate"></p>' +
                '</div>' +
                '<button type="button" onclick="cancelTicketChatReplyTo(' + ticketId + ')" title="Cancel reply (Esc)" class="shrink-0 text-slate-400 hover:text-rose-600 transition-colors">' +
                    '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>' +
                '</button>' +
            '</div>' +

            '<div data-role="photoBar" class="hidden bg-[#FFF5ED] border border-[#FECDAA] rounded-xl px-2.5 py-1.5 space-y-1.5">' +
                '<div class="flex items-center justify-between gap-2 text-[10px] font-bold">' +
                    '<span data-role="photoCount" class="text-[#9A2512] truncate"></span>' +
                    '<button type="button" onclick="clearTicketChatPhotos(' + ticketId + ')" class="text-rose-600 hover:underline shrink-0">Remove all</button>' +
                '</div>' +
                '<div data-role="photoGrid" class="flex flex-wrap gap-1.5 max-h-24 overflow-y-auto"></div>' +
            '</div>' +

            '<div class="flex items-end gap-2">' +
                '<label title="Attach photos, videos, PDFs, or Excel files" class="w-9 h-9 shrink-0 rounded-full bg-slate-100 hover:bg-[#FFE8D5] text-slate-500 hover:text-[#EB3E0B] flex items-center justify-center cursor-pointer transition-colors">' +
                    '<input type="file" data-role="photoInput" accept="' + CHAT_ATTACHMENT_ACCEPT + '" multiple class="hidden" onchange="onTicketChatPhotosPicked(this, ' + ticketId + ')">' +
                    '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>' +
                '</label>' +
                '<textarea data-role="input" rows="1" placeholder="Type a reply, or paste a screenshot with Ctrl + V..." class="flex-1 resize-none bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-2xl px-3.5 py-2.5 max-h-28 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all"></textarea>' +
                '<button type="button" data-role="sendBtn" onclick="sendTicketChatReply(' + ticketId + ')" title="Send reply (Enter)" class="w-9 h-9 shrink-0 rounded-full bg-[#EB3E0B] hover:bg-[#C32C0B] active:scale-95 text-white flex items-center justify-center transition-all shadow-md shadow-[#EB3E0B]/25">' +
                    '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>' +
                '</button>' +
            '</div>' +
            '<p class="text-[9px] text-slate-400 font-medium px-1">Enter sends - Shift + Enter starts a new line - Ctrl + V pastes a screenshot or copied file</p>' +
        '</div>' +
    '</div>';
}

// Finds one element inside a specific chat box by its data-role
function chatEl(ticketId, role) {
    var chat = chats[ticketId];
    return chat ? chat.box.querySelector('[data-role="' + role + '"]') : null;
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

    // Already open - bring it back to front and un-minimize rather than
    // opening a second box for the same conversation.
    if (chats[ticketId]) {
        if (chats[ticketId].isMinimized) toggleTicketChatMinimize(ticketId);
        bringChatToFront(ticketId);
        return;
    }

    // Only a handful of conversations stay open at once - the one that has
    // gone longest without attention makes room for a newly opened ticket.
    if (chatOrder.length >= CHAT_MAX_OPEN) {
        closeTicketChat(chatOrder[0]);
    }

    createTicketChatBox(ticketId, row);
}

// Moves an already-open chat box to the front of the dock (visually the
// rightmost / most prominent position) and marks it most-recently-used.
function bringChatToFront(ticketId) {
    var chat = chats[ticketId];
    if (!chat) return;
    chatDock.appendChild(chat.box);
    var idx = chatOrder.indexOf(ticketId);
    if (idx !== -1) {
        chatOrder.splice(idx, 1);
        chatOrder.push(ticketId);
    }
}

function createTicketChatBox(ticketId, row) {
    var box = document.createElement('div');
    box.className = 'w-[calc(100vw-2rem)] sm:w-[360px] bg-white rounded-3xl border border-slate-200 shadow-2xl shadow-slate-900/20 overflow-hidden flex flex-col';
    box.setAttribute('data-ticket-id', ticketId);
    box.innerHTML = buildChatBoxHtml(ticketId);
    chatDock.appendChild(box);

    var chat = {
        ticketId: ticketId,
        box: box,
        lastReplyId: 0,
        pollTimer: null,
        isSending: false,
        isMinimized: false,
        clientSeenId: 0,
        lastTypingPing: 0,
        replyToId: 0
    };
    chats[ticketId] = chat;
    chatOrder.push(ticketId);

    var thread = chatEl(ticketId, 'thread');

    chatEl(ticketId, 'number').textContent = row.getAttribute('data-ticket-number') || '';
    chatEl(ticketId, 'client').textContent = row.getAttribute('data-client') || '';
    chatEl(ticketId, 'subject').textContent = row.getAttribute('data-subject') || '';
    chatEl(ticketId, 'fullLink').href = 'ticket_detail.php?id=' + ticketId;

    applyTicketChatStatus(ticketId, row.getAttribute('data-status') || '');
    renderTicketChatPickedUp(ticketId, '', '');  // filled in by the first poll
    cancelTicketChatStatus(ticketId);

    // Seed bubble: tickets with no replies yet still show the reported issue
    thread.setAttribute('data-seed-issue', row.getAttribute('data-issue') || '');
    thread.setAttribute('data-seed-client', row.getAttribute('data-client') || '');
    thread.setAttribute('data-seed-date', row.getAttribute('data-created') || '');

    // Opening the thread marks it read on the server, so drop the red unread
    // badge straight away instead of leaving it until the next page load.
    var listedRow = document.querySelector('.ticket-row[data-ticket-id="' + ticketId + '"]');
    if (listedRow) {
        var unreadTag = listedRow.querySelector('[data-cell="unread"]');
        if (unreadTag && unreadTag.parentNode) {
            unreadTag.parentNode.removeChild(unreadTag);
        }
    }

    bindChatBoxEvents(ticketId);

    loadTicketChatThread(ticketId, true);
    chat.pollTimer = setInterval(function() { loadTicketChatThread(ticketId, false); }, 3000);
}

// Wires the composer textarea and the access-code field for one box. Done
// once at creation time since each box gets its own fresh elements.
function bindChatBoxEvents(ticketId) {
    var chat = chats[ticketId];
    var input = chatEl(ticketId, 'input');
    if (input) {
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                cancelTicketChatReplyTo(ticketId);
                return;
            }
            if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.altKey && !e.metaKey) {
                e.preventDefault();
                sendTicketChatReply(ticketId);
            }
        });

        input.addEventListener('input', function() {
            // Grow with the text, up to the max height the class caps it at
            input.style.height = 'auto';
            input.style.height = Math.min(input.scrollHeight, 112) + 'px';

            // Let the client see the typing indicator, at most one ping every 2s
            var now = Date.now();
            if (!chat) return;
            if ((now - chat.lastTypingPing) > 2000) {
                chat.lastTypingPing = now;
                var ping = new FormData();
                ping.append('action', 'typing');
                ping.append('id', ticketId);
                fetch('api_ticket_replies.php?id=' + ticketId, { method: 'POST', body: ping }).catch(function() {});
            }
        });
    }

    var codeField = chatEl(ticketId, 'accessCode');
    if (codeField) {
        codeField.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                submitTicketChatStatus(ticketId);
            } else if (e.key === 'Escape') {
                cancelTicketChatStatus(ticketId);
            }
        });
    }
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

function closeTicketChat(ticketId) {
    var chat = chats[ticketId];
    if (!chat) return;
    if (chat.pollTimer) {
        clearInterval(chat.pollTimer);
    }
    if (chat.box && chat.box.parentNode) {
        chat.box.parentNode.removeChild(chat.box);
    }
    delete chats[ticketId];
    var idx = chatOrder.indexOf(ticketId);
    if (idx !== -1) chatOrder.splice(idx, 1);
}

function toggleTicketChatMinimize(ticketId) {
    var chat = chats[ticketId];
    if (!chat) return;
    chat.isMinimized = !chat.isMinimized;
    var body = chatEl(ticketId, 'body');
    var icon = chatEl(ticketId, 'minIcon');
    if (chat.isMinimized) {
        body.classList.add('hidden');
        body.classList.remove('flex');
        icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/>';
    } else {
        body.classList.remove('hidden');
        body.classList.add('flex');
        icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 13H5"/>';
        scrollTicketChatToBottom(ticketId);
    }
}

// -------------------------------------------------------------------------
// Ticket status
// Resolved / Closed swap the composer for a notice, exactly as the full
// console does, and the select stays in sync with whatever the poll reports.
// -------------------------------------------------------------------------
function applyTicketChatStatus(ticketId, status, badgeClass) {
    var statusPill = chatEl(ticketId, 'statusPill');
    if (!statusPill) return;
    statusPill.textContent = status || '-';

    var select = chatEl(ticketId, 'statusSelect');
    if (select && status) {
        select.value = status;
    }

    var isClosed = (status === 'Resolved' || status === 'Closed');
    chatEl(ticketId, 'composer').classList.toggle('hidden', isClosed);
    chatEl(ticketId, 'closedBanner').classList.toggle('hidden', !isClosed);
    chatEl(ticketId, 'closedStatus').textContent = status;

    // Keep the row in the table honest - replying flips Pending to In Progress
    var row = document.querySelector('.ticket-row[data-ticket-id="' + ticketId + '"]');
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
function renderTicketChatPickedUp(ticketId, by, at) {
    var box = chatEl(ticketId, 'pickedUp');
    if (!box) return;
    if (!by) {
        box.classList.add('hidden');
        box.classList.remove('flex');
        return;
    }
    chatEl(ticketId, 'pickedUpBy').textContent = by;
    chatEl(ticketId, 'pickedUpAt').textContent = at ? (' - ' + at) : '';
    box.classList.remove('hidden');
    box.classList.add('flex');
}

function showTicketChatStatusError(ticketId, msg) {
    var box = chatEl(ticketId, 'statusError');
    if (!box) return;
    box.textContent = msg;
    box.classList.remove('hidden');
}

// Puts the select back on the status the server last confirmed
function cancelTicketChatStatus(ticketId) {
    var codeRow = chatEl(ticketId, 'codeRow');
    if (codeRow) {
        codeRow.classList.add('hidden');
        codeRow.classList.remove('flex');
    }
    var codeInput = chatEl(ticketId, 'accessCode');
    if (codeInput) codeInput.value = '';
    var errEl = chatEl(ticketId, 'statusError');
    if (errEl) errEl.classList.add('hidden');

    var row = document.querySelector('.ticket-row[data-ticket-id="' + ticketId + '"]');
    var select = chatEl(ticketId, 'statusSelect');
    if (select && row && row.getAttribute('data-status')) {
        select.value = row.getAttribute('data-status');
    }
}

// Level 2 accounts have to type their access code before the change is sent
function onTicketChatStatusPicked(ticketId) {
    var errEl = chatEl(ticketId, 'statusError');
    if (errEl) errEl.classList.add('hidden');
    if (chatMyTier === 2) {
        var codeRow = chatEl(ticketId, 'codeRow');
        codeRow.classList.remove('hidden');
        codeRow.classList.add('flex');
        chatEl(ticketId, 'accessCode').focus();
        return;
    }
    submitTicketChatStatus(ticketId);
}

function submitTicketChatStatus(ticketId) {
    var select = chatEl(ticketId, 'statusSelect');
    if (!chats[ticketId] || !select) return;

    var newStatus = select.value;
    var codeInput = chatEl(ticketId, 'accessCode');

    var body = new FormData();
    body.append('action', 'update_status');
    body.append('id', ticketId);
    body.append('status', newStatus);
    body.append('action_access_code', codeInput ? codeInput.value : '');

    fetch('api_ticket_replies.php?id=' + ticketId, { method: 'POST', body: body })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!chats[ticketId]) return;   // closed while this request was in flight

            if (!data || !data.success) {
                showTicketChatStatusError(ticketId, (data && data.error) ? data.error : 'Could not update the status.');
                if (data && data.needs_code) {
                    var codeRow = chatEl(ticketId, 'codeRow');
                    codeRow.classList.remove('hidden');
                    codeRow.classList.add('flex');
                } else {
                    // Roll the select back so it never shows a status that was not saved
                    var row = document.querySelector('.ticket-row[data-ticket-id="' + ticketId + '"]');
                    if (row && row.getAttribute('data-status')) {
                        select.value = row.getAttribute('data-status');
                    }
                }
                return;
            }

            if (codeInput) codeInput.value = '';
            var codeRowOk = chatEl(ticketId, 'codeRow');
            codeRowOk.classList.add('hidden');
            codeRowOk.classList.remove('flex');
            chatEl(ticketId, 'statusError').classList.add('hidden');

            applyTicketChatStatus(ticketId, data.status, data.status_badge_class);
            if (data.in_progress_by) renderTicketChatPickedUp(ticketId, data.in_progress_by, data.in_progress_at);

            var savedTag = chatEl(ticketId, 'statusSaved');
            savedTag.classList.remove('hidden');
            setTimeout(function() {
                if (chats[ticketId]) savedTag.classList.add('hidden');
            }, 1800);
        })
        .catch(function(err) {
            if (!chats[ticketId]) return;
            showTicketChatStatusError(ticketId, 'Network error - the status was not changed.');
            console.error('Ticket status error:', err);
        });
}

// -------------------------------------------------------------------------
// Thread rendering
// -------------------------------------------------------------------------
// One attachment rendered per its actual kind: a real thumbnail for photos,
// an inline player for video, and a labelled download chip for anything the
// browser cannot preview by itself (PDF, Excel). The server does not keep the
// original filename, so documents are labelled by type rather than by name.
function buildTicketChatAttachmentHtml(url) {
    var ext = (url.split('.').pop() || '').toLowerCase().split(/[?#]/)[0];

    if (CHAT_IMAGE_EXTS.indexOf(ext) !== -1) {
        return '<a href="' + escapeChatHtml(url) + '" target="_blank" rel="noopener">' +
            '<img src="' + escapeChatHtml(url) + '" alt="Attachment" loading="lazy" ' +
            'class="h-20 w-auto max-w-[120px] object-cover rounded-xl border border-slate-200 hover:opacity-90 transition-opacity"></a>';
    }

    if (['mp4', 'mov', 'webm', 'avi'].indexOf(ext) !== -1) {
        return '<video controls preload="metadata" class="max-h-48 max-w-[220px] rounded-xl border border-slate-200 bg-black">' +
            '<source src="' + escapeChatHtml(url) + '">' +
            'Your browser cannot play this video. <a href="' + escapeChatHtml(url) + '" target="_blank" rel="noopener" class="underline">Download it instead</a>.' +
            '</video>';
    }

    var typeLabel = 'File';
    var iconBg = 'bg-slate-100 text-slate-500';
    if (ext === 'pdf') {
        typeLabel = 'PDF Document';
        iconBg = 'bg-rose-50 text-rose-600';
    } else if (ext === 'xls' || ext === 'xlsx') {
        typeLabel = 'Excel Spreadsheet';
        iconBg = 'bg-emerald-50 text-emerald-600';
    } else if (ext === 'txt') {
        typeLabel = 'Text File';
        iconBg = 'bg-sky-50 text-sky-600';
    }

    return '<a href="' + escapeChatHtml(url) + '" target="_blank" rel="noopener" ' +
        'class="flex items-center gap-2.5 bg-white border border-slate-200 rounded-xl px-3 py-2.5 hover:border-[#FECDAA] hover:bg-[#FFF5ED]/50 transition-colors max-w-[220px]">' +
        '<span class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0 ' + iconBg + '">' +
            '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>' +
        '</span>' +
        '<span class="min-w-0">' +
            '<span class="block text-[11px] font-bold text-slate-800 truncate">' + typeLabel + '</span>' +
            '<span class="block text-[9px] font-bold text-[#EB3E0B] uppercase tracking-wider">Tap to open / download</span>' +
        '</span>' +
    '</a>';
}

function buildTicketChatBubble(ticketId, reply) {
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
    if (reply.unsent) {
        body = '<p class="chat-unsent text-[11.5px] italic text-slate-400">This message was unsent.</p>';
    } else if (reply.diagnostic_log) {
        body = '<p class="text-[11px] font-extrabold text-slate-900">This client is requesting assistance</p>' +
            '<details class="mt-1"><summary class="cursor-pointer text-[10px] font-bold text-[#EB3E0B] hover:underline">View Diagnostic Log</summary>' +
            '<pre class="mt-1.5 p-2 rounded-xl bg-slate-50 border border-slate-200 text-slate-800 font-mono text-[10px] whitespace-pre-wrap leading-relaxed max-h-40 overflow-y-auto">' + escapeChatHtml(reply.diagnostic_log) + '</pre></details>';
    } else if (reply.message) {
        body = '<p class="chat-text text-[11.5px] text-slate-800 leading-relaxed font-medium whitespace-pre-wrap break-words">' + escapeChatHtml(reply.message) + '</p>';
    }

    var atts = reply.unsent ? [] : (reply.attachments || []);
    if (atts.length > 0) {
        body += '<div class="mt-2 flex flex-wrap gap-1.5">';
        for (var i = 0; i < atts.length; i++) {
            var url = '../' + String(atts[i]).replace(/^\/+/, '');
            body += buildTicketChatAttachmentHtml(url);
        }
        body += '</div>';
    }

    // The message this one answers - click it to jump back up the thread
    var quote = '';
    if (reply.reply_to && reply.reply_to.id) {
        quote = '<button type="button" onclick="jumpToTicketChatMessage(' + ticketId + ', ' + parseInt(reply.reply_to.id, 10) + ')" ' +
            'class="w-full text-left mb-1.5 pl-2 border-l-2 ' + (reply.reply_to.is_tech ? 'border-[#EB3E0B]' : 'border-slate-400') + ' hover:opacity-75 transition-opacity">' +
            '<span class="block text-[9px] font-bold uppercase tracking-wider text-slate-500">Replying to ' + escapeChatHtml(reply.reply_to.sender_name) + '</span>' +
            '<span class="block text-[10px] text-slate-500 truncate">' + escapeChatHtml(reply.reply_to.snippet) + '</span></button>';
    }

    wrap.innerHTML =
        '<span class="text-[9px] font-bold uppercase tracking-wider mb-1 px-1 ' + (isTech ? 'text-[#EB3E0B]' : 'text-slate-500') + '">' +
            escapeChatHtml(reply.sender_name) + ' - ' + escapeChatHtml(reply.formatted_date) +
            '<span class="chat-edited-tag text-slate-400 normal-case tracking-normal font-medium' + (reply.edited ? '' : ' hidden') + '"' +
                (reply.edited_at ? ' title="Edited ' + escapeChatHtml(reply.edited_at) + '"' : '') + '> (edited)</span>' +
        '</span>' +
        '<div class="chat-bubble ' + bubbleClass + '">' + quote + body + '</div>' +
        buildTicketChatActions(ticketId, reply, isTech, replyId) +
        (isTech ? '<span class="chat-seen hidden text-[9px] font-bold text-slate-400 mt-0.5 px-1">Seen</span>' : '');

    return wrap;
}

// Heart + Reply row under each real message, plus the pill holder for the
// hearts already on it. The seeded issue bubble (id 0) has no row.
function buildTicketChatActions(ticketId, reply, isTech, replyId) {
    if (!replyId) return '';
    if (reply.unsent) return '';   // nothing left to react to, quote or change

    var senderArg = escapeChatAttr(reply.sender_name || '');
    var snippetArg = escapeChatAttr(reply.reply_snippet || reply.message || '');

    return '<div class="flex items-center flex-wrap gap-1 mt-1 px-1 ' + (isTech ? 'justify-end' : '') + '">' +
        '<div class="chat-reactions flex items-center flex-wrap gap-1" data-reactions-for="' + replyId + '"></div>' +
        '<button type="button" onclick="sendTicketChatReaction(' + ticketId + ', ' + replyId + ')" title="Love this message" ' +
            'class="inline-flex items-center justify-center w-6 h-6 rounded-full border border-dashed border-slate-300 text-slate-400 hover:text-[#9A2512] hover:border-[#FECDAA] hover:bg-white transition-colors">' +
            '<svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>' +
        '</button>' +
        '<button type="button" onclick=\'startTicketChatReplyTo(' + ticketId + ', ' + replyId + ', ' + senderArg + ', ' + snippetArg + ')\' title="Reply to this message" ' +
            'class="inline-flex items-center justify-center w-6 h-6 rounded-full border border-slate-200 bg-white text-slate-400 hover:text-[#EB3E0B] hover:border-[#FECDAA] transition-colors">' +
            '<svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg>' +
        '</button>' +
        // Own support messages can be corrected or taken back
        (reply.can_edit
            ? '<button type="button" onclick="startTicketChatEdit(' + ticketId + ', ' + replyId + ')" title="Edit this message" ' +
                'class="inline-flex items-center justify-center w-6 h-6 rounded-full border border-slate-200 bg-white text-slate-400 hover:text-[#EB3E0B] hover:border-[#FECDAA] transition-colors">' +
                '<svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>' +
              '</button>' +
              '<button type="button" onclick="unsendTicketChatMessage(' + ticketId + ', ' + replyId + ')" title="Unsend this message" ' +
                'class="inline-flex items-center justify-center w-6 h-6 rounded-full border border-slate-200 bg-white text-slate-400 hover:text-rose-600 hover:border-rose-200 transition-colors">' +
                '<svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>' +
              '</button>'
            : '') +
    '</div>';
}

function renderTicketChatSeed(ticketId) {
    var thread = chatEl(ticketId, 'thread');
    if (!thread) return;
    var issue = thread.getAttribute('data-seed-issue');
    if (!issue) return;
    thread.appendChild(buildTicketChatBubble(ticketId, {
        id: 0,
        is_tech: false,
        sender_name: thread.getAttribute('data-seed-client') || 'Client',
        formatted_date: thread.getAttribute('data-seed-date') || '',
        message: issue,
        attachments: []
    }));
}

// -------------------------------------------------------------------------
// Heart reactions (the only reaction the ticket chat supports)
// -------------------------------------------------------------------------
function renderTicketChatReactions(ticketId, replyId, list) {
    var thread = chatEl(ticketId, 'thread');
    if (!thread) return;
    var box = thread.querySelector('.chat-reactions[data-reactions-for="' + parseInt(replyId, 10) + '"]');
    if (!box) return;

    var html = '';
    for (var i = 0; i < list.length; i++) {
        var rx = list[i];
        var cls = rx.mine
            ? 'bg-[#FFE8D5] border-[#FECDAA] text-[#9A2512]'
            : 'bg-white border-slate-200 text-slate-600 hover:border-slate-300';
        html += '<button type="button" onclick="sendTicketChatReaction(' + ticketId + ', ' + parseInt(replyId, 10) + ')" ' +
            'title="' + escapeChatHtml(rx.who || rx.label) + '" ' +
            'class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full border text-[10px] font-bold transition-colors ' + cls + '">' +
            '<span>' + rx.emoji + '</span><span>' + rx.count + '</span></button>';
    }
    box.innerHTML = html;
}

// Applies the whole-thread map the poll returns, so hearts added by the
// client show up on messages that are already on screen.
function applyTicketChatReactionMap(ticketId, map) {
    var thread = chatEl(ticketId, 'thread');
    if (!thread) return;
    var boxes = thread.querySelectorAll('.chat-reactions[data-reactions-for]');
    for (var i = 0; i < boxes.length; i++) {
        var rid = boxes[i].getAttribute('data-reactions-for');
        renderTicketChatReactions(ticketId, rid, (map && map[rid]) ? map[rid] : []);
    }
}

function sendTicketChatReaction(ticketId, replyId) {
    if (!ticketId || !replyId) return;

    var body = new FormData();
    body.append('action', 'toggle_reaction');
    body.append('id', ticketId);
    body.append('reply_id', replyId);
    body.append('reaction', 'heart');

    fetch('api_ticket_replies.php?id=' + ticketId, { method: 'POST', body: body })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!chats[ticketId]) return;
            if (data && data.success) {
                renderTicketChatReactions(ticketId, replyId, data.reactions || []);
            } else {
                showTicketChatError(ticketId, (data && data.error) ? data.error : 'Could not save your reaction.');
            }
        })
        .catch(function(err) {
            console.error('Ticket chat reaction error:', err);
        });
}

// -------------------------------------------------------------------------
// Editing a message already in the thread
// The bubble turns into a small editor in place, so the conversation keeps
// its position instead of the text jumping down to the composer.
// -------------------------------------------------------------------------
function startTicketChatEdit(ticketId, replyId) {
    var thread = chatEl(ticketId, 'thread');
    if (!thread) return;
    var msg = thread.querySelector('.chat-msg[data-reply-id="' + parseInt(replyId, 10) + '"]');
    if (!msg || msg.querySelector('.chat-edit-box')) return;

    var textEl = msg.querySelector('.chat-text');
    if (!textEl) return;   // diagnostic reports and photo-only messages have no text to edit

    var bubble = msg.querySelector('.chat-bubble');
    var original = textEl.textContent;

    var box = document.createElement('div');
    box.className = 'chat-edit-box mt-1.5 space-y-1.5';
    box.innerHTML =
        '<textarea class="chat-edit-input w-full resize-none bg-white border border-[#FECDAA] text-slate-900 text-[11.5px] rounded-xl px-2.5 py-2 max-h-40 focus:outline-none focus:border-[#FA5915]" rows="3"></textarea>' +
        (chatMyTier === 2
            ? '<input type="password" class="chat-edit-code w-full bg-white border border-amber-300 text-slate-900 text-[10px] font-mono tracking-widest text-center rounded-xl px-2 py-1.5 focus:outline-none focus:border-[#EB3E0B] placeholder:tracking-normal placeholder:font-sans" placeholder="Security access code">'
            : '') +
        '<p class="chat-edit-error hidden text-[10px] font-bold text-rose-600"></p>' +
        '<div class="flex items-center justify-end gap-1.5">' +
            '<button type="button" onclick="cancelTicketChatEdit(' + ticketId + ', ' + replyId + ')" class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 text-[10px] font-bold transition-colors">Cancel</button>' +
            '<button type="button" onclick="saveTicketChatEdit(' + ticketId + ', ' + replyId + ')" class="px-2.5 py-1 rounded-lg bg-[#EB3E0B] hover:bg-[#C32C0B] text-white text-[10px] font-bold transition-colors">Save</button>' +
        '</div>';

    textEl.classList.add('hidden');
    bubble.appendChild(box);

    var input = box.querySelector('.chat-edit-input');
    input.value = original;
    input.focus();
    input.setSelectionRange(input.value.length, input.value.length);

    // Enter saves, Shift + Enter adds a line, Escape backs out
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            cancelTicketChatEdit(ticketId, replyId);
        } else if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.altKey && !e.metaKey) {
            e.preventDefault();
            saveTicketChatEdit(ticketId, replyId);
        }
    });
}

function cancelTicketChatEdit(ticketId, replyId) {
    var thread = chatEl(ticketId, 'thread');
    if (!thread) return;
    var msg = thread.querySelector('.chat-msg[data-reply-id="' + parseInt(replyId, 10) + '"]');
    if (!msg) return;
    var box = msg.querySelector('.chat-edit-box');
    if (box && box.parentNode) box.parentNode.removeChild(box);
    var textEl = msg.querySelector('.chat-text');
    if (textEl) textEl.classList.remove('hidden');
}

function saveTicketChatEdit(ticketId, replyId) {
    var thread = chatEl(ticketId, 'thread');
    if (!thread) return;
    var msg = thread.querySelector('.chat-msg[data-reply-id="' + parseInt(replyId, 10) + '"]');
    if (!msg) return;
    var box = msg.querySelector('.chat-edit-box');
    if (!box) return;

    var input = box.querySelector('.chat-edit-input');
    var codeEl = box.querySelector('.chat-edit-code');
    var errEl = box.querySelector('.chat-edit-error');
    var newText = input.value.trim();

    if (newText === '') {
        errEl.textContent = 'The message cannot be left empty.';
        errEl.classList.remove('hidden');
        return;
    }

    var body = new FormData();
    body.append('action', 'edit_reply');
    body.append('id', ticketId);
    body.append('reply_id', replyId);
    body.append('reply_message', newText);
    body.append('action_access_code', codeEl ? codeEl.value : '');

    fetch('api_ticket_replies.php?id=' + ticketId, { method: 'POST', body: body })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!chats[ticketId]) return;
            if (!data || !data.success) {
                errEl.textContent = (data && data.error) ? data.error : 'The message could not be saved.';
                errEl.classList.remove('hidden');
                return;
            }
            applyTicketChatEdit(ticketId, replyId, data.message, data.edited_at);
            cancelTicketChatEdit(ticketId, replyId);
        })
        .catch(function(err) {
            errEl.textContent = 'Network error - the message was not saved.';
            errEl.classList.remove('hidden');
            console.error('Ticket chat edit error:', err);
        });
}

// -------------------------------------------------------------------------
// Unsending a message
// The bubble stays in place as a plain notice, so replies quoting it still
// make sense, but the text and any photos are gone for the client too.
// -------------------------------------------------------------------------
function unsendTicketChatMessage(ticketId, replyId) {
    if (!ticketId) return;
    if (!confirm('Unsend this message?\n\nThe text and any photos are removed for the client as well. This cannot be undone.')) {
        return;
    }

    var code = '';
    if (chatMyTier === 2) {
        code = prompt('Enter your security access code to unsend this message:') || '';
        if (code === '') return;
    }

    var body = new FormData();
    body.append('action', 'unsend_reply');
    body.append('id', ticketId);
    body.append('reply_id', replyId);
    body.append('action_access_code', code);

    fetch('api_ticket_replies.php?id=' + ticketId, { method: 'POST', body: body })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!chats[ticketId]) return;
            if (!data || !data.success) {
                showTicketChatError(ticketId, (data && data.error) ? data.error : 'The message could not be unsent.');
                return;
            }
            applyTicketChatUnsent(ticketId, replyId);
        })
        .catch(function(err) {
            showTicketChatError(ticketId, 'Network error - the message was not unsent.');
            console.error('Ticket chat unsend error:', err);
        });
}

// Turns a bubble already on screen into the unsent notice
function applyTicketChatUnsent(ticketId, replyId) {
    var thread = chatEl(ticketId, 'thread');
    if (!thread) return;
    var msg = thread.querySelector('.chat-msg[data-reply-id="' + parseInt(replyId, 10) + '"]');
    if (!msg) return;

    cancelTicketChatEdit(ticketId, replyId);

    var bubble = msg.querySelector('.chat-bubble');
    if (bubble) {
        bubble.innerHTML = '<p class="chat-unsent text-[11.5px] italic text-slate-400">This message was unsent.</p>';
    }

    // The heart / reply / edit row and the edited tag no longer apply
    var actions = msg.querySelector('.chat-reactions');
    if (actions && actions.parentNode && actions.parentNode.parentNode) {
        actions.parentNode.parentNode.removeChild(actions.parentNode);
    }
    var tag = msg.querySelector('.chat-edited-tag');
    if (tag) tag.classList.add('hidden');

    // A quote of this message elsewhere in the thread now reads as unsent
    var quotes = thread.querySelectorAll('button[onclick="jumpToTicketChatMessage(' + ticketId + ', ' + parseInt(replyId, 10) + ')"] span:last-child');
    for (var i = 0; i < quotes.length; i++) {
        quotes[i].textContent = 'Message unsent';
    }
}

// Writes new text into a bubble already on screen and flags it as edited
function applyTicketChatEdit(ticketId, replyId, message, editedAt) {
    var thread = chatEl(ticketId, 'thread');
    if (!thread) return;
    var msg = thread.querySelector('.chat-msg[data-reply-id="' + parseInt(replyId, 10) + '"]');
    if (!msg) return;

    var textEl = msg.querySelector('.chat-text');
    if (textEl) textEl.textContent = message;

    var tag = msg.querySelector('.chat-edited-tag');
    if (tag) {
        tag.classList.remove('hidden');
        if (editedAt) tag.setAttribute('title', 'Edited ' + editedAt);
    }
}

// Applies the whole-thread edit map the poll returns, so a correction made
// elsewhere reaches messages that are already drawn.
function applyTicketChatEditMap(ticketId, map) {
    if (!map) return;
    var thread = chatEl(ticketId, 'thread');
    if (!thread) return;
    for (var id in map) {
        if (!map.hasOwnProperty(id)) continue;
        var msg = thread.querySelector('.chat-msg[data-reply-id="' + parseInt(id, 10) + '"]');
        if (!msg) continue;

        if (map[id].unsent) {
            if (!msg.querySelector('.chat-unsent')) {
                applyTicketChatUnsent(ticketId, id);
            }
            continue;
        }

        if (msg.querySelector('.chat-edit-box')) continue;   // never overwrite an open editor
        var textEl = msg.querySelector('.chat-text');
        if (textEl && textEl.textContent !== map[id].message) {
            applyTicketChatEdit(ticketId, id, map[id].message, map[id].edited_at);
        } else {
            var tag = msg.querySelector('.chat-edited-tag');
            if (tag) tag.classList.remove('hidden');
        }
    }
}

// -------------------------------------------------------------------------
// Replying to one specific message
// -------------------------------------------------------------------------
function startTicketChatReplyTo(ticketId, replyId, senderName, snippet) {
    var chat = chats[ticketId];
    if (!chat) return;
    // Nothing to reply into while the ticket is Resolved / Closed
    if (chatEl(ticketId, 'composer').classList.contains('hidden')) return;
    chat.replyToId = parseInt(replyId, 10) || 0;

    chatEl(ticketId, 'replyName').textContent = senderName || 'this message';
    chatEl(ticketId, 'replySnippet').textContent = snippet || '';

    var bar = chatEl(ticketId, 'replyBar');
    bar.classList.remove('hidden');
    bar.classList.add('flex');

    chatEl(ticketId, 'input').focus();
}

function cancelTicketChatReplyTo(ticketId) {
    var chat = chats[ticketId];
    if (chat) chat.replyToId = 0;
    var bar = chatEl(ticketId, 'replyBar');
    if (!bar) return;
    bar.classList.add('hidden');
    bar.classList.remove('flex');
}

// Jump to a quoted message and flash it so it is easy to spot
function jumpToTicketChatMessage(ticketId, replyId) {
    var thread = chatEl(ticketId, 'thread');
    if (!thread) return;
    var msg = thread.querySelector('.chat-msg[data-reply-id="' + parseInt(replyId, 10) + '"]');
    if (!msg) return;

    var bubble = msg.querySelector('div');
    msg.scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (!bubble) return;
    bubble.classList.add('ring-2', 'ring-[#FA5915]');
    setTimeout(function() { bubble.classList.remove('ring-2', 'ring-[#FA5915]'); }, 1600);
}

// Only the newest tech message carries the "Seen" tag, as in any chat app
function renderTicketChatSeen(ticketId) {
    var chat = chats[ticketId];
    var thread = chatEl(ticketId, 'thread');
    if (!chat || !thread) return;

    var marks = thread.querySelectorAll('.chat-seen');
    for (var i = 0; i < marks.length; i++) {
        marks[i].classList.add('hidden');
    }
    var techMsgs = thread.querySelectorAll('.chat-msg[data-sender-type="support"]');
    if (!techMsgs.length || !chat.clientSeenId) return;

    var last = techMsgs[techMsgs.length - 1];
    if (parseInt(last.getAttribute('data-reply-id'), 10) <= chat.clientSeenId) {
        var mark = last.querySelector('.chat-seen');
        if (mark) mark.classList.remove('hidden');
    }
}

function isTicketChatNearBottom(ticketId) {
    var thread = chatEl(ticketId, 'thread');
    if (!thread) return true;
    return (thread.scrollHeight - thread.scrollTop - thread.clientHeight) < 80;
}

function scrollTicketChatToBottom(ticketId) {
    var thread = chatEl(ticketId, 'thread');
    if (thread) thread.scrollTop = thread.scrollHeight;
}

// -------------------------------------------------------------------------
// Polling: the first call paints the whole thread, later ones append
// -------------------------------------------------------------------------
function loadTicketChatThread(ticketId, isFirstLoad) {
    var chat = chats[ticketId];
    if (!chat || chat.isSending) return;
    var thread = chatEl(ticketId, 'thread');

    fetch('api_ticket_replies.php?id=' + ticketId + '&after_id=' + chat.lastReplyId)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            // The box may have been closed while this request was in flight
            if (!chats[ticketId]) return;

            if (!data || !data.success) {
                if (isFirstLoad) {
                    thread.innerHTML = '<div class="text-center text-[11px] text-rose-500 font-bold py-6">' +
                        escapeChatHtml((data && data.error) ? data.error : 'Failed to load conversation.') + '</div>';
                }
                return;
            }

            var stickToBottom = isFirstLoad || isTicketChatNearBottom(ticketId);

            if (isFirstLoad) {
                thread.innerHTML = '';
                if (!data.replies || !data.replies.length) {
                    renderTicketChatSeed(ticketId);
                }
            }

            var clientReplyArrived = false;

            if (data.replies && data.replies.length) {
                for (var i = 0; i < data.replies.length; i++) {
                    var r = data.replies[i];
                    if (thread.querySelector('.chat-msg[data-reply-id="' + r.id + '"]')) continue;
                    thread.appendChild(buildTicketChatBubble(ticketId, r));
                    if (r.id > chat.lastReplyId) chat.lastReplyId = r.id;
                    if (!isFirstLoad && !r.is_tech) {
                        clientReplyArrived = true;
                    }
                }
            }

            // A message landing in an open thread sounds the same alarm as one
            // arriving on any other ticket. The footer poller skips a ticket
            // that already has a box open, so it only ever sounds once.
            if (clientReplyArrived && typeof playNewTicketChime === 'function') {
                playNewTicketChime();
            }

            applyTicketChatReactionMap(ticketId, data.reactions);
            applyTicketChatEditMap(ticketId, data.edits);

            chat.clientSeenId = parseInt(data.client_seen_id, 10) || 0;
            renderTicketChatSeen(ticketId);

            chatEl(ticketId, 'typing').classList.toggle('hidden', !data.client_typing);

            // Do not fight a status change the user is still confirming
            if (chatEl(ticketId, 'codeRow').classList.contains('hidden')) {
                applyTicketChatStatus(ticketId, data.ticket_status, data.status_badge_class);
            }
            renderTicketChatPickedUp(ticketId, data.in_progress_by, data.in_progress_at);

            if (stickToBottom) scrollTicketChatToBottom(ticketId);
        })
        .catch(function(err) {
            console.warn('Ticket chat poll error:', err);
        });
}

// -------------------------------------------------------------------------
// Photos: picked from disk or pasted straight into the box with Ctrl + V
// -------------------------------------------------------------------------
function showTicketChatError(ticketId, msg) {
    var box = chatEl(ticketId, 'error');
    if (!box) return;
    box.textContent = msg;
    box.classList.remove('hidden');
}

function hideTicketChatError(ticketId) {
    var box = chatEl(ticketId, 'error');
    if (box) box.classList.add('hidden');
}

var CHAT_IMAGE_EXTS = ['png', 'jpg', 'jpeg'];

function onTicketChatPhotosPicked(input, ticketId) {
    var count = (input.files && input.files.length) ? input.files.length : 0;
    if (!count) {
        clearTicketChatPhotos(ticketId);
        return;
    }

    var invalid = [];
    var oversized = [];
    for (var f = 0; f < input.files.length; f++) {
        var file = input.files[f];
        var ext = file.name.split('.').pop().toLowerCase();
        var maxSize = CHAT_ATTACHMENT_MAX_SIZE[ext];
        if (!maxSize) {
            invalid.push(file.name);
        } else if (file.size > maxSize) {
            oversized.push(file.name + ' (max ' + Math.floor(maxSize / (1024 * 1024)) + 'MB)');
        }
    }
    if (invalid.length > 0) {
        showTicketChatError(ticketId, 'Unsupported file type: ' + invalid.join(', ') +
            '. Allowed: images (PNG/JPG), videos (MP4/MOV/WEBM/AVI), PDF, Excel (XLS/XLSX), and text (TXT).');
        clearTicketChatPhotos(ticketId);
        return;
    }
    if (oversized.length > 0) {
        showTicketChatError(ticketId, 'Too large to attach: ' + oversized.join(', '));
        clearTicketChatPhotos(ticketId);
        return;
    }

    hideTicketChatError(ticketId);
    chatEl(ticketId, 'photoCount').textContent = count + (count === 1 ? ' file attached' : ' files attached');
    chatEl(ticketId, 'photoBar').classList.remove('hidden');

    var grid = chatEl(ticketId, 'photoGrid');
    grid.innerHTML = '';
    for (var i = 0; i < count; i++) {
        (function(file) {
            var ext = file.name.split('.').pop().toLowerCase();
            if (CHAT_IMAGE_EXTS.indexOf(ext) !== -1) {
                var reader = new FileReader();
                reader.onload = function(ev) {
                    var thumb = document.createElement('img');
                    thumb.src = ev.target.result;
                    thumb.alt = 'Preview';
                    thumb.className = 'h-12 w-12 rounded-lg object-cover border border-[#FECDAA]';
                    grid.appendChild(thumb);
                };
                reader.readAsDataURL(file);
            } else {
                // Non-image picks show a text chip with the real filename instead
                // of a thumbnail - the server does not keep the original name
                // after upload, so this is the only place it is ever shown.
                var chip = document.createElement('span');
                chip.className = 'inline-flex items-center h-12 px-2.5 rounded-lg border border-[#FECDAA] bg-white text-[9px] font-bold text-[#9A2512] max-w-[150px] truncate';
                chip.title = file.name;
                chip.textContent = ext.toUpperCase() + ': ' + file.name;
                grid.appendChild(chip);
            }
        })(input.files[i]);
    }
}

function clearTicketChatPhotos(ticketId) {
    var input = chatEl(ticketId, 'photoInput');
    if (input) input.value = '';
    var bar = chatEl(ticketId, 'photoBar');
    if (bar) bar.classList.add('hidden');
    var grid = chatEl(ticketId, 'photoGrid');
    if (grid) grid.innerHTML = '';
}

// Ctrl + V drops the clipboard image into the same file input the attach
// button fills, so both paths send identically.
function handleTicketChatPaste(ticketId, e) {
    var photoInput = chatEl(ticketId, 'photoInput');
    if (!photoInput) return;

    var items = (e.clipboardData || window.clipboardData || {}).items;
    if (!items) return;

    // Raw clipboard image data (an actual screenshot) has no real filename and
    // always needs one synthesized; a file copied from Explorer/Finder already
    // has its own name and extension, so it is kept as-is and just checked
    // against the allowed types.
    var pastedScreenshots = [];
    var pastedFiles = [];
    for (var i = 0; i < items.length; i++) {
        if (items[i].kind !== 'file') continue;
        var file = items[i].getAsFile();
        if (!file) continue;

        if (items[i].type.indexOf('image/') === 0) {
            pastedScreenshots.push(file);
        } else {
            var ext = (file.name.split('.').pop() || '').toLowerCase();
            if (CHAT_ATTACHMENT_MAX_SIZE[ext]) pastedFiles.push(file);
        }
    }
    if (!pastedScreenshots.length && !pastedFiles.length) return;

    e.preventDefault();

    var dt = new DataTransfer();
    if (photoInput.files) {
        for (var f = 0; f < photoInput.files.length; f++) {
            dt.items.add(photoInput.files[f]);
        }
    }

    var stamp = new Date().toISOString().replace(/[:.]/g, '-');
    for (var p = 0; p < pastedScreenshots.length; p++) {
        var ext = (pastedScreenshots[p].type === 'image/jpeg') ? 'jpg' : 'png';
        dt.items.add(new File([pastedScreenshots[p]], 'pasted-image-' + stamp + '-' + p + '.' + ext, { type: pastedScreenshots[p].type }));
    }
    for (var q = 0; q < pastedFiles.length; q++) {
        dt.items.add(pastedFiles[q]);
    }

    photoInput.files = dt.files;
    onTicketChatPhotosPicked(photoInput, ticketId);
}

// -------------------------------------------------------------------------
// Sending
// -------------------------------------------------------------------------
function sendTicketChatReply(ticketId) {
    var chat = chats[ticketId];
    if (!chat || chat.isSending) return;

    var input = chatEl(ticketId, 'input');
    var photoInput = chatEl(ticketId, 'photoInput');
    var sendBtn = chatEl(ticketId, 'sendBtn');
    var thread = chatEl(ticketId, 'thread');

    var msg = input.value.trim();
    var hasPhotos = photoInput && photoInput.files && photoInput.files.length > 0;
    if (!msg && !hasPhotos) return;

    hideTicketChatError(ticketId);
    chat.isSending = true;
    sendBtn.disabled = true;
    sendBtn.classList.add('opacity-50', 'cursor-not-allowed');

    var body = new FormData();
    body.append('action', 'send_tech_reply');
    body.append('id', ticketId);
    body.append('reply_message', msg);
    body.append('reply_to_id', chat.replyToId);
    if (hasPhotos) {
        for (var i = 0; i < photoInput.files.length; i++) {
            body.append('attachments[]', photoInput.files[i]);
        }
    }

    fetch('api_ticket_replies.php?id=' + ticketId, { method: 'POST', body: body })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!chats[ticketId]) return;   // closed while the reply was in flight
            chat.isSending = false;
            sendBtn.disabled = false;
            sendBtn.classList.remove('opacity-50', 'cursor-not-allowed');

            if (!data || !data.success) {
                showTicketChatError(ticketId, (data && data.error) ? data.error : 'Failed to send reply.');
                return;
            }

            input.value = '';
            input.style.height = 'auto';
            clearTicketChatPhotos(ticketId);
            cancelTicketChatReplyTo(ticketId);

            if (!thread.querySelector('.chat-msg[data-reply-id="' + data.reply.id + '"]')) {
                thread.appendChild(buildTicketChatBubble(ticketId, data.reply));
                if (data.reply.id > chat.lastReplyId) chat.lastReplyId = data.reply.id;
                renderTicketChatReactions(ticketId, data.reply.id, data.reply.reactions || []);
                renderTicketChatSeen(ticketId);
                scrollTicketChatToBottom(ticketId);
            }
        })
        .catch(function(err) {
            if (chats[ticketId]) {
                chat.isSending = false;
                sendBtn.disabled = false;
                sendBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                showTicketChatError(ticketId, 'Network error - reply was not sent.');
            }
            console.error('Ticket chat send error:', err);
        });
}

// Ctrl + V works anywhere while a chat box is open, not just inside its own
// textarea. Focus inside a specific box targets that box; otherwise it falls
// back to whichever open box was most recently used.
document.addEventListener('paste', function(e) {
    if (!chatOrder.length) return;

    var active = document.activeElement;
    var targetId = null;

    for (var i = 0; i < chatOrder.length; i++) {
        var tid = chatOrder[i];
        var chat = chats[tid];
        if (chat && active && active !== document.body && chat.box.contains(active)) {
            targetId = tid;
            break;
        }
    }
    if (targetId === null) {
        targetId = chatOrder[chatOrder.length - 1];
    }

    var target = chats[targetId];
    if (!target || target.isMinimized) return;
    if (chatEl(targetId, 'composer').classList.contains('hidden')) return;

    var el = document.activeElement;
    if (el && el !== document.body && !target.box.contains(el)) return;

    handleTicketChatPaste(targetId, e);
});

// A notification click asked for one ticket - open its thread once the rows
// are on the page.
var TICKET_CHAT_AUTOLOAD = <?php echo json_encode($chat_autoload_ticket); ?>;
if (TICKET_CHAT_AUTOLOAD) {
    document.addEventListener('DOMContentLoaded', function() {
        openTicketChatById(TICKET_CHAT_AUTOLOAD);
    });
}

// Polling is pointless while the tab is hidden - every open box pauses and
// resumes together.
document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        for (var i = 0; i < chatOrder.length; i++) {
            var chat = chats[chatOrder[i]];
            if (chat && chat.pollTimer) {
                clearInterval(chat.pollTimer);
                chat.pollTimer = null;
            }
        }
        return;
    }
    var openNow = chatOrder.slice();
    for (var j = 0; j < openNow.length; j++) {
        (function(tid) {
            var chat = chats[tid];
            if (chat && !chat.pollTimer) {
                loadTicketChatThread(tid, false);
                chat.pollTimer = setInterval(function() { loadTicketChatThread(tid, false); }, 3000);
            }
        })(openNow[j]);
    }
});
</script>
