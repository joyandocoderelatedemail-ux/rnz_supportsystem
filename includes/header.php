<?php
// Header Navigation Component
if (!isset($page_title)) {
    $page_title = 'Dashboard';
}
$client = get_logged_client();
if ($client) {
    $pdo_hdr = get_db_connection();
    $stmt_hdr = $pdo_hdr->prepare("SELECT * FROM bucket_client WHERE accountnum = :acct LIMIT 1");
    $stmt_hdr->execute(array(':acct' => $client['accountnum']));
    $fresh_c = $stmt_hdr->fetch();
    if ($fresh_c) {
        $client = array_merge($client, $fresh_c);
    }
}
$tradename = $client ? $client['tradename'] : 'Client Portal';
$client_acct_hdr = $client ? $client['accountnum'] : '';
$hdr_has_warranty = (isset($client['warranty_status']) && $client['warranty_status'] === 'Active');

// Fetch notifications for client
$client_notifications = array();
if (!empty($client_acct_hdr)) {
    $pdo_hdr = get_db_connection();
    
    // 1. Fetch recent technician replies to client's tickets (Primary Notification)
    $stmt_c_replies = $pdo_hdr->prepare("SELECT r.id, r.ticket_id, r.sender_name, r.message, r.created_at, t.ticket_number, t.subject 
        FROM client_ticket_replies r 
        INNER JOIN client_support_tickets t ON r.ticket_id = t.id 
        WHERE t.accountnum = :acct AND r.sender_type = 'support' 
        ORDER BY r.id DESC LIMIT 5");
    $stmt_c_replies->execute(array(':acct' => $client_acct_hdr));
    $c_replies = $stmt_c_replies->fetchAll();

    foreach ($c_replies as $cr) {
        $msg_preview = !empty($cr['message']) ? mb_strimwidth(strip_tags($cr['message']), 0, 50, '...') : 'New support reply';
        $client_notifications[] = array(
            'type' => 'tech_reply',
            'key' => 'reply_' . $cr['id'],
            'title' => 'Reply from ' . $cr['sender_name'],
            'subtitle' => $cr['subject'] . ': "' . $msg_preview . '"',
            'link' => 'ticket_detail.php?id=' . $cr['ticket_id'],
            'number' => $cr['ticket_number'],
            'date' => $cr['created_at'],
            'badge' => 'Tech Reply'
        );
    }

    // 2. Fetch active tickets for this client
    $stmt_c_tickets = $pdo_hdr->prepare("SELECT id, ticket_number, subject, priority, status, created_at, updated_at 
        FROM client_support_tickets 
        WHERE accountnum = :acct 
        ORDER BY updated_at DESC LIMIT 5");
    $stmt_c_tickets->execute(array(':acct' => $client_acct_hdr));
    $c_tickets = $stmt_c_tickets->fetchAll();

    foreach ($c_tickets as $ct) {
        $client_notifications[] = array(
            'type' => 'ticket',
            'key' => 'ticket_' . $ct['id'],
            'title' => $ct['subject'],
            'subtitle' => 'Priority: ' . $ct['priority'] . ' | Status: ' . $ct['status'],
            'link' => 'ticket_detail.php?id=' . $ct['id'],
            'number' => $ct['ticket_number'],
            'date' => $ct['updated_at'],
            'badge' => $ct['status']
        );
    }

    // 3. Fetch tech service notes
    $stmt_c_notes = $pdo_hdr->prepare("SELECT id, xdate, techname, reasonoftech, status 
        FROM bucket_technotes 
        WHERE accountnum = :acct 
        ORDER BY id DESC LIMIT 3");
    $stmt_c_notes->execute(array(':acct' => $client_acct_hdr));
    $c_notes = $stmt_c_notes->fetchAll();

    foreach ($c_notes as $cn) {
        $client_notifications[] = array(
            'type' => 'technote',
            'key' => 'note_' . $cn['id'],
            'title' => 'Service Visit Log by ' . $cn['techname'],
            'subtitle' => 'Reason: ' . $cn['reasonoftech'],
            'link' => 'technotes.php',
            'number' => 'Tech Log #' . $cn['id'],
            'date' => $cn['xdate'],
            'badge' => $cn['status']
        );
    }
}
$client_notif_count = count($client_notifications);
$current_search_q = isset($_GET['q']) ? trim($_GET['q']) : '';
?>
<!-- Top Header Bar -->
<header class="bg-[#FFF5ED]/85 backdrop-blur-md sticky top-0 z-30 border-b border-[#FECDAA] px-4 sm:px-6 py-3 sm:py-4 flex items-center justify-between gap-2">
    <!-- Left Title & Mobile Menu Button -->
    <div class="flex items-center space-x-2.5 sm:space-x-4 min-w-0">
        <button id="mobile-menu-btn" onclick="openMobileSidebar()" type="button" class="md:hidden text-[#7C2112] hover:text-[#430D07] p-2 rounded-2xl hover:bg-[#FFE8D5] transition-colors focus:outline-none shrink-0" aria-label="Open Mobile Menu">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
        </button>
        <h2 class="text-base sm:text-xl font-extrabold text-[#430D07] tracking-tight truncate">
            <?php echo sanitize($page_title); ?>
        </h2>
    </div>

    <!-- Right Header Tools -->
    <div class="flex items-center space-x-2 sm:space-x-3 shrink-0">
        <?php if ($hdr_has_warranty): ?>
            <span class="hidden md:inline-flex items-center space-x-1.5 bg-emerald-100 text-emerald-800 text-[11px] font-extrabold px-3 py-1.5 rounded-full border border-emerald-300 shadow-sm">
                <svg class="w-3.5 h-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
                <span>Active Warranty</span>
            </span>
        <?php endif; ?>

        <!-- Universal Search Input (Desktop & Tablet) -->
        <div class="relative hidden sm:block w-52 md:w-64 lg:w-72">
            <form action="tickets.php" method="GET" class="relative m-0" onsubmit="return handleClientSearchSubmit(event)">
                <input type="text" id="clientHeaderSearchInput" name="q" value="<?php echo sanitize($current_search_q); ?>" 
                       placeholder="Search tickets, notes, WO..." 
                       autocomplete="off"
                       oninput="handleClientLiveSearch(this.value)"
                       onfocus="if(this.value.trim().length >= 2) handleClientLiveSearch(this.value)"
                       class="w-full bg-[#FFE8D5]/70 text-[#430D07] text-xs pl-9 pr-8 py-2 rounded-full border border-[#FECDAA] focus:border-[#FA5915] focus:bg-white focus:outline-none transition-all placeholder-[#9A2512]/60">
                <button type="submit" class="absolute left-3 top-2 text-[#9A2512] hover:text-[#EB3E0B] transition-colors focus:outline-none" title="Search">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </button>
                <button type="button" id="clientSearchClearBtn" onclick="clearClientSearchInput()" class="absolute right-2.5 top-2 text-[#9A2512]/60 hover:text-[#430D07] text-xs font-bold hidden p-0.5">
                    ✕
                </button>
            </form>

            <!-- Live Search Results Dropdown -->
            <div id="clientSearchLiveResults" class="absolute left-0 right-0 top-11 bg-white rounded-3xl shadow-2xl border border-[#FECDAA] z-50 p-4 space-y-3 hidden max-h-96 overflow-y-auto">
                <div id="clientSearchLiveContent" class="space-y-2">
                    <!-- Populated dynamically via JS -->
                </div>
                <div class="pt-2 border-t border-[#FFE8D5] flex items-center justify-between text-xs font-bold">
                    <span class="text-[#7C2112] text-[11px]">Press Enter to search all tickets</span>
                    <button type="button" onclick="submitClientSearchForm()" class="text-[#EB3E0B] hover:underline flex items-center space-x-1">
                        <span>View All &rarr;</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Mobile Search Toggle Button (Screen < sm) -->
        <button type="button" onclick="toggleMobileSearchBar()" class="sm:hidden text-[#7C2112] hover:text-[#430D07] p-2 rounded-full hover:bg-[#FFE8D5] transition-colors focus:outline-none" title="Search">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
        </button>

        <!-- Primary Action: New Ticket Pill Button -->
        <button onclick="openNewTicketModal()" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs sm:text-sm px-3 sm:px-5 py-2 sm:py-2.5 rounded-full shadow-sm shadow-[#EB3E0B]/25 flex items-center space-x-1.5 transition-all active:scale-95">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
            </svg>
            <span class="hidden xs:inline sm:inline">New Ticket</span>
        </button>

        <!-- Notification Bell Dropdown Container -->
        <div class="relative">
            <button type="button" onclick="toggleClientNotificationDropdown(event)" class="relative text-[#7C2112] hover:text-[#430D07] p-2.5 rounded-full hover:bg-[#FFE8D5] transition-colors focus:outline-none" title="Notifications">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                </svg>
                <span id="client_header_pending_badge" class="absolute -top-1 -right-1 bg-[#EB3E0B] text-white font-bold text-[10px] px-1.5 py-0.5 rounded-full ring-2 ring-white <?php echo ($client_notif_count > 0) ? '' : 'hidden'; ?>">
                    <?php echo $client_notif_count; ?>
                </span>
            </button>

            <!-- Notification Modal / Dropdown -->
            <div id="clientNotificationDropdown" class="fixed inset-x-3 top-16 sm:inset-x-auto sm:right-0 sm:top-12 sm:w-96 max-w-full sm:max-w-md bg-white rounded-3xl shadow-2xl border border-[#FECDAA] z-50 p-4 sm:p-5 space-y-3 hidden">
                <div class="flex items-center justify-between border-b border-[#FFE8D5] pb-3">
                    <div class="flex items-center space-x-2">
                        <h4 class="font-extrabold text-sm text-[#430D07]">Account Notifications</h4>
                        <span id="client_dropdown_count" class="bg-[#FFE8D5] text-[#EB3E0B] font-extrabold text-[11px] px-2.5 py-0.5 rounded-full">
                            <?php echo $client_notif_count; ?> Recent
                        </span>
                    </div>
                    <div class="flex items-center space-x-2">
                        <button type="button" onclick="markAllClientNotificationsAsRead()" class="text-[11px] font-bold text-[#9A2512] hover:text-[#430D07] transition-colors">Mark all read</button>
                        <button type="button" onclick="closeClientNotificationDropdown()" class="text-[#9A2512] hover:text-[#430D07] p-1.5 rounded-full hover:bg-[#FFE8D5] transition-colors" aria-label="Close notifications">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Notification Items List -->
                <div id="client_notification_list" class="space-y-2 max-h-[60vh] sm:max-h-72 overflow-y-auto pr-1">
                    <?php if (empty($client_notifications)): ?>
                        <div class="py-6 text-center text-xs text-[#9A2512]/70 space-y-1">
                            <p class="font-bold text-[#430D07]">No Recent Notifications</p>
                            <p>You have no pending ticket updates or service logs.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($client_notifications as $cn): ?>
                            <a href="<?php echo $cn['link']; ?>" data-client-key="<?php echo $cn['key']; ?>" onclick="markClientNotificationClicked('<?php echo $cn['key']; ?>')" class="block p-3 rounded-2xl bg-[#FFF5ED] hover:bg-[#FFE8D5] border border-[#FECDAA] transition-all space-y-1 group">
                                <div class="flex items-center justify-between gap-2 min-w-0">
                                    <span class="font-extrabold text-xs text-[#430D07] group-hover:text-[#EB3E0B] truncate flex-1 min-w-0" title="<?php echo sanitize($cn['title']); ?>">
                                        <?php echo sanitize($cn['title']); ?>
                                    </span>
                                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border shrink-0 <?php echo get_status_badge_class($cn['badge']); ?>">
                                        <?php echo sanitize($cn['badge']); ?>
                                    </span>
                                </div>
                                <p class="text-xs text-[#7C2112] font-medium line-clamp-1">
                                    <?php echo sanitize($cn['subtitle']); ?>
                                </p>
                                <div class="flex items-center justify-between pt-1 text-[10px] text-[#9A2512] font-mono">
                                    <span><?php echo sanitize($cn['number']); ?></span>
                                    <span><?php echo format_date($cn['date']); ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="pt-2 border-t border-[#FFE8D5] flex items-center justify-between text-xs">
                    <a href="tickets.php" class="font-bold text-[#EB3E0B] hover:underline flex items-center space-x-1">
                        <span>View All Support Tickets</span>
                        <span>&rarr;</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- User Profile Pill -->
        <a href="profile.php" class="flex items-center space-x-2 p-1.5 pr-3 rounded-full hover:bg-[#FFE8D5] transition-colors border border-[#FECDAA]">
            <div class="w-7 h-7 rounded-full bg-[#7C2112] text-white font-bold text-xs flex items-center justify-center">
                <?php echo strtoupper(substr($tradename, 0, 1)); ?>
            </div>
            <span class="text-xs font-semibold text-[#430D07] hidden lg:inline max-w-[120px] truncate"><?php echo sanitize($tradename); ?></span>
        </a>
    </div>
</header>

<!-- Mobile Search Bar Expandable Drawer (Screen < sm) -->
<div id="mobileSearchBarContainer" class="sm:hidden bg-[#FFF5ED] border-b border-[#FECDAA] px-4 py-3 hidden">
    <form action="tickets.php" method="GET" class="relative m-0" onsubmit="return handleMobileSearchSubmit(event)">
        <input type="text" id="clientMobileSearchInput" name="q" value="<?php echo sanitize($current_search_q); ?>" 
               placeholder="Search tickets, notes, WO, hardware..." 
               autocomplete="off"
               oninput="handleClientLiveSearch(this.value, true)"
               class="w-full bg-[#FFE8D5] text-[#430D07] text-xs pl-9 pr-9 py-2.5 rounded-full border border-[#FECDAA] focus:border-[#FA5915] focus:bg-white focus:outline-none transition-all placeholder-[#9A2512]/60">
        <button type="submit" class="absolute left-3 top-2.5 text-[#9A2512] hover:text-[#EB3E0B] focus:outline-none">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
        </button>
        <button type="button" onclick="toggleMobileSearchBar()" class="absolute right-3 top-2.5 text-[#9A2512] hover:text-[#430D07] text-xs font-bold p-0.5">
            ✕
        </button>
    </form>
</div>

<script>
// Notification LocalStorage Helpers
function getClickedClientNotifications() {
    try {
        var data = localStorage.getItem('rnz_client_clicked_notifs');
        return data ? JSON.parse(data) : {};
    } catch(e) {
        return {};
    }
}

function markClientNotificationClicked(key, updateBadgeNow) {
    if (typeof updateBadgeNow === 'undefined') updateBadgeNow = true;
    try {
        var clicked = getClickedClientNotifications();
        if (key) clicked[key] = true;
        localStorage.setItem('rnz_client_clicked_notifs', JSON.stringify(clicked));
    } catch(e) {}
    if (updateBadgeNow) {
        updateClientUnreadBadgeCount();
    }
}

function markAllClientNotificationsAsRead() {
    var dropList = document.getElementById('client_notification_list');
    if (dropList) {
        var items = dropList.querySelectorAll('a[data-client-key]');
        for (var i = 0; i < items.length; i++) {
            var key = items[i].getAttribute('data-client-key');
            markClientNotificationClicked(key, false);
        }
    }
    updateClientUnreadBadgeCount();
}

function updateClientUnreadBadgeCount() {
    var dropList = document.getElementById('client_notification_list');
    var badge = document.getElementById('client_header_pending_badge');
    var dropCount = document.getElementById('client_dropdown_count');
    var clicked = getClickedClientNotifications();
    var unread = 0;

    if (dropList) {
        var items = dropList.querySelectorAll('a[data-client-key]');
        for (var i = 0; i < items.length; i++) {
            var k = items[i].getAttribute('data-client-key');
            if (k && !clicked[k]) {
                unread++;
                items[i].classList.remove('opacity-60');
            } else {
                items[i].classList.add('opacity-60');
            }
        }
    }

    if (badge) {
        badge.innerText = unread;
        if (unread > 0) {
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
        }
    }

    if (dropCount) {
        dropCount.innerText = unread + ' Recent';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    updateClientUnreadBadgeCount();
});

function toggleClientNotificationDropdown(e) {
    e.stopPropagation();
    var dd = document.getElementById('clientNotificationDropdown');
    if (dd) {
        dd.classList.toggle('hidden');
    }
}

function closeClientNotificationDropdown() {
    var dd = document.getElementById('clientNotificationDropdown');
    if (dd) {
        dd.classList.add('hidden');
    }
}

// Mobile Search Bar Toggle
function toggleMobileSearchBar() {
    var bar = document.getElementById('mobileSearchBarContainer');
    if (bar) {
        bar.classList.toggle('hidden');
        if (!bar.classList.contains('hidden')) {
            var inp = document.getElementById('clientMobileSearchInput');
            if (inp) inp.focus();
        }
    }
}

// Live Search Handlers
var clientSearchTimer = null;

function clearClientSearchInput() {
    var inp = document.getElementById('clientHeaderSearchInput');
    if (inp) {
        inp.value = '';
        var btn = document.getElementById('clientSearchClearBtn');
        if (btn) btn.classList.add('hidden');
        hideClientLiveSearchResults();
    }
}

function hideClientLiveSearchResults() {
    var box = document.getElementById('clientSearchLiveResults');
    if (box) box.classList.add('hidden');
}

function handleClientSearchSubmit(e) {
    var inp = document.getElementById('clientHeaderSearchInput');
    if (inp && inp.value.trim() !== '') {
        window.location.href = 'tickets.php?q=' + encodeURIComponent(inp.value.trim());
        if (e) e.preventDefault();
        return false;
    }
    return true;
}

function handleMobileSearchSubmit(e) {
    var inp = document.getElementById('clientMobileSearchInput');
    if (inp && inp.value.trim() !== '') {
        window.location.href = 'tickets.php?q=' + encodeURIComponent(inp.value.trim());
        if (e) e.preventDefault();
        return false;
    }
    return true;
}

function submitClientSearchForm() {
    var inp = document.getElementById('clientHeaderSearchInput');
    if (!inp || !inp.value) {
        inp = document.getElementById('clientMobileSearchInput');
    }
    if (inp && inp.value.trim() !== '') {
        window.location.href = 'tickets.php?q=' + encodeURIComponent(inp.value.trim());
    } else {
        window.location.href = 'tickets.php';
    }
}

function handleClientLiveSearch(query, isMobile) {
    if (typeof isMobile === 'undefined') isMobile = false;
    
    var clearBtn = document.getElementById('clientSearchClearBtn');
    if (clearBtn) {
        if (query.trim().length > 0) {
            clearBtn.classList.remove('hidden');
        } else {
            clearBtn.classList.add('hidden');
        }
    }

    if (clientSearchTimer) clearTimeout(clientSearchTimer);

    if (query.trim().length < 2) {
        hideClientLiveSearchResults();
        return;
    }

    clientSearchTimer = setTimeout(function() {
        fetch('search_ajax.php?q=' + encodeURIComponent(query.trim()))
            .then(function(res) { return res.json(); })
            .then(function(data) {
                renderClientLiveSearchResults(data, query);
            })
            .catch(function(err) {
                console.error('Search error:', err);
            });
    }, 200);
}

function renderClientLiveSearchResults(data, query) {
    var resultsBox = document.getElementById('clientSearchLiveResults');
    var contentBox = document.getElementById('clientSearchLiveContent');
    if (!resultsBox || !contentBox) return;

    if (!data.success || data.count === 0) {
        contentBox.innerHTML = '<div class="py-4 text-center text-xs text-[#9A2512]/70 space-y-1">' +
            '<p class="font-bold text-[#430D07]">No results found for "' + escapeHtml(query) + '"</p>' +
            '<p>Press Enter to search all support tickets.</p>' +
        '</div>';
        resultsBox.classList.remove('hidden');
        return;
    }

    var html = '';

    // Tickets
    if (data.tickets && data.tickets.length > 0) {
        html += '<div class="space-y-1.5">';
        html += '<span class="text-[10px] font-bold text-[#9A2512] uppercase tracking-wider px-1">Support Tickets</span>';
        for (var i = 0; i < data.tickets.length; i++) {
            var item = data.tickets[i];
            html += '<a href="' + item.url + '" class="block p-2.5 rounded-2xl bg-[#FFF5ED] hover:bg-[#FFE8D5] border border-[#FECDAA] transition-all space-y-0.5 group">' +
                '<div class="flex items-center justify-between gap-2">' +
                    '<span class="font-bold text-xs text-[#430D07] group-hover:text-[#EB3E0B] truncate flex-1">' + escapeHtml(item.title) + '</span>' +
                    '<span class="text-[10px] font-bold px-2 py-0.2 rounded-full border bg-amber-100 text-amber-800 border-amber-300 shrink-0">' + escapeHtml(item.badge) + '</span>' +
                '</div>' +
                '<p class="text-[11px] text-[#7C2112] truncate">' + escapeHtml(item.subtitle) + '</p>' +
            '</a>';
        }
        html += '</div>';
    }

    // Hardware Guides
    if (data.guides && data.guides.length > 0) {
        html += '<div class="space-y-1.5 pt-1 border-t border-[#FFE8D5]">';
        html += '<span class="text-[10px] font-bold text-[#9A2512] uppercase tracking-wider px-1">Hardware Diagnostic Guides</span>';
        for (var j = 0; j < data.guides.length; j++) {
            var g = data.guides[j];
            html += '<a href="' + g.url + '" class="block p-2.5 rounded-2xl bg-[#FFF5ED] hover:bg-[#FFE8D5] border border-[#FECDAA] transition-all space-y-0.5 group">' +
                '<div class="flex items-center justify-between gap-2">' +
                    '<span class="font-bold text-xs text-[#430D07] group-hover:text-[#EB3E0B] truncate flex-1">' + escapeHtml(g.title) + '</span>' +
                    '<span class="text-[10px] font-bold px-2 py-0.2 rounded-full border bg-emerald-100 text-emerald-800 border-emerald-300 shrink-0">Guide</span>' +
                '</div>' +
                '<p class="text-[11px] text-[#7C2112] truncate">' + escapeHtml(g.subtitle) + '</p>' +
            '</a>';
        }
        html += '</div>';
    }

    // Tech Notes
    if (data.technotes && data.technotes.length > 0) {
        html += '<div class="space-y-1.5 pt-1 border-t border-[#FFE8D5]">';
        html += '<span class="text-[10px] font-bold text-[#9A2512] uppercase tracking-wider px-1">Tech Service Notes</span>';
        for (var k = 0; k < data.technotes.length; k++) {
            var n = data.technotes[k];
            html += '<a href="' + n.url + '" class="block p-2.5 rounded-2xl bg-[#FFF5ED] hover:bg-[#FFE8D5] border border-[#FECDAA] transition-all space-y-0.5 group">' +
                '<div class="flex items-center justify-between gap-2">' +
                    '<span class="font-bold text-xs text-[#430D07] group-hover:text-[#EB3E0B] truncate flex-1">' + escapeHtml(n.title) + '</span>' +
                    '<span class="text-[10px] font-mono text-[#9A2512] shrink-0">' + escapeHtml(n.date) + '</span>' +
                '</div>' +
                '<p class="text-[11px] text-[#7C2112] truncate">' + escapeHtml(n.subtitle) + '</p>' +
            '</a>';
        }
        html += '</div>';
    }

    // Work Orders
    if (data.workorders && data.workorders.length > 0) {
        html += '<div class="space-y-1.5 pt-1 border-t border-[#FFE8D5]">';
        html += '<span class="text-[10px] font-bold text-[#9A2512] uppercase tracking-wider px-1">Work Orders & Receipts</span>';
        for (var l = 0; l < data.workorders.length; l++) {
            var w = data.workorders[l];
            html += '<a href="' + w.url + '" class="block p-2.5 rounded-2xl bg-[#FFF5ED] hover:bg-[#FFE8D5] border border-[#FECDAA] transition-all space-y-0.5 group">' +
                '<div class="flex items-center justify-between gap-2">' +
                    '<span class="font-bold text-xs text-[#430D07] group-hover:text-[#EB3E0B] truncate flex-1">' + escapeHtml(w.title) + '</span>' +
                    '<span class="text-[10px] font-bold px-2 py-0.2 rounded-full border bg-emerald-100 text-emerald-800 border-emerald-300 shrink-0">' + escapeHtml(w.badge) + '</span>' +
                '</div>' +
                '<p class="text-[11px] text-[#7C2112] truncate">' + escapeHtml(w.subtitle) + '</p>' +
            '</a>';
        }
        html += '</div>';
    }

    contentBox.innerHTML = html;
    resultsBox.classList.remove('hidden');
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

// Global click handler to close dropdowns when clicking outside
document.addEventListener('click', function(e) {
    var dd = document.getElementById('clientNotificationDropdown');
    if (dd && !dd.classList.contains('hidden')) {
        if (!dd.contains(e.target) && !e.target.closest('[onclick*="toggleClientNotificationDropdown"]')) {
            dd.classList.add('hidden');
        }
    }

    var sBox = document.getElementById('clientSearchLiveResults');
    var sInput = document.getElementById('clientHeaderSearchInput');
    if (sBox && !sBox.classList.contains('hidden')) {
        if (!sBox.contains(e.target) && e.target !== sInput) {
            sBox.classList.add('hidden');
        }
    }
});
</script>
