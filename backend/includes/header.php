<?php
// Support Center Admin Header Component
if (!isset($page_title)) {
    $page_title = 'Dashboard';
}
$tech = get_logged_tech();
$tech_name = $tech ? $tech['fullname'] : 'Support Tech';

$pdo = get_db_connection();
$stmt_pending_t = $pdo->query("SELECT COUNT(*) FROM client_support_tickets WHERE status = 'Pending'");
$pending_tickets_cnt = intval($stmt_pending_t->fetchColumn());

$stmt_pending_m = $pdo->query("SELECT COUNT(*) FROM client_maintenance_requests WHERE status = 'Pending'");
$pending_maint_cnt = intval($stmt_pending_m->fetchColumn());

$pending_count = $pending_tickets_cnt + $pending_maint_cnt;

$admin_notifications = array();

// 1. Fetch pending POS Maintenance Requests
$stmt_maint_reqs = $pdo->query("SELECT id, request_number, tradename, preferred_date, preferred_time, units_count, status, created_at 
    FROM client_maintenance_requests 
    WHERE status = 'Pending' 
    ORDER BY id DESC LIMIT 5");
$maint_list = $stmt_maint_reqs->fetchAll();

foreach ($maint_list as $mr) {
    $m_comb_id = intval($mr['id']);
    $admin_notifications[] = array(
        'type' => 'maintenance',
        'key' => 'maintenance_' . $mr['id'],
        'combined_id' => $m_comb_id,
        'title' => 'POS Maintenance: ' . $mr['tradename'],
        'subtitle' => $mr['units_count'] . ' Unit(s) | Date: ' . format_date_only($mr['preferred_date']) . ' (' . format_time($mr['preferred_time']) . ')',
        'link' => 'maintenance.php',
        'number' => $mr['request_number'],
        'date' => $mr['created_at'],
        'badge' => 'Maintenance'
    );
}

// 2. Fetch pending support tickets
$stmt_pending_tickets = $pdo->query("SELECT id, ticket_number, tradename, subject, category, priority, status, created_at 
    FROM client_support_tickets 
    WHERE status = 'Pending' 
    ORDER BY id DESC LIMIT 5");
$header_pending_list = $stmt_pending_tickets->fetchAll();

foreach ($header_pending_list as $pt) {
    $t_comb_id = intval($pt['id']) * 100000;
    $admin_notifications[] = array(
        'type' => 'ticket',
        'key' => 'ticket_' . $pt['id'],
        'combined_id' => $t_comb_id,
        'title' => 'Ticket: ' . $pt['tradename'],
        'subtitle' => $pt['subject'] . ' (' . $pt['priority'] . ' Priority)',
        'link' => 'ticket_detail.php?id=' . $pt['id'],
        'number' => $pt['ticket_number'],
        'date' => $pt['created_at'],
        'badge' => $pt['priority']
    );
}

$current_admin_search_q = isset($_GET['q']) ? trim($_GET['q']) : '';
?>
<!-- Top Admin Header Bar -->
<header class="bg-white/90 backdrop-blur-md sticky top-0 z-30 border-b border-slate-200/80 px-4 sm:px-6 py-3 sm:py-4 flex items-center justify-between gap-2">
    <!-- Left Title & Mobile Menu Button -->
    <div class="flex items-center space-x-2.5 sm:space-x-4 min-w-0">
        <button id="mobile-menu-btn" onclick="openAdminMobileSidebar()" type="button" class="md:hidden text-slate-500 hover:text-slate-800 p-2 rounded-2xl hover:bg-slate-100 transition-colors focus:outline-none shrink-0" aria-label="Open Mobile Menu">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
        </button>
        <h2 class="text-base sm:text-xl font-extrabold text-slate-900 tracking-tight truncate">
            <?php echo sanitize($page_title); ?>
        </h2>
    </div>

    <!-- Right Header Tools -->
    <div class="flex items-center space-x-2 sm:space-x-3 shrink-0">
        <!-- Universal Search Input (Desktop & Tablet) -->
        <div class="relative hidden sm:block w-52 md:w-64 lg:w-72">
            <form action="tickets.php" method="GET" class="relative m-0" onsubmit="return handleAdminSearchSubmit(event)">
                <input type="text" id="adminHeaderSearchInput" name="q" value="<?php echo sanitize($current_admin_search_q); ?>" 
                       placeholder="Search tickets, clients, stock..." 
                       autocomplete="off"
                       oninput="handleAdminLiveSearch(this.value)"
                       onfocus="if(this.value.trim().length >= 2) handleAdminLiveSearch(this.value)"
                       class="w-full bg-slate-100/90 text-slate-800 text-xs pl-9 pr-8 py-2 rounded-full border border-transparent focus:border-[#FA5915] focus:bg-white focus:outline-none transition-all placeholder-slate-400">
                <button type="submit" class="absolute left-3 top-2 text-slate-400 hover:text-[#EB3E0B] transition-colors focus:outline-none" title="Search">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </button>
                <button type="button" id="adminSearchClearBtn" onclick="clearAdminSearchInput()" class="absolute right-2.5 top-2 text-slate-400 hover:text-slate-700 text-xs font-bold hidden p-0.5">
                    ✕
                </button>
            </form>

            <!-- Live Search Results Dropdown -->
            <div id="adminSearchLiveResults" class="absolute left-0 right-0 top-11 bg-white rounded-3xl shadow-2xl border border-slate-200 z-50 p-4 space-y-3 hidden max-h-96 overflow-y-auto">
                <div id="adminSearchLiveContent" class="space-y-2">
                    <!-- Populated dynamically via JS -->
                </div>
                <div class="pt-2 border-t border-slate-100 flex items-center justify-between text-xs font-bold">
                    <span class="text-slate-500 text-[11px]">Press Enter to search tickets</span>
                    <button type="button" onclick="submitAdminSearchForm()" class="text-[#EB3E0B] hover:underline flex items-center space-x-1">
                        <span>View All &rarr;</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Mobile Search Toggle Button (Screen < sm) -->
        <button type="button" onclick="toggleAdminMobileSearchBar()" class="sm:hidden text-slate-600 hover:text-slate-900 p-2 rounded-full hover:bg-slate-100 transition-colors focus:outline-none" title="Search">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
        </button>

        <!-- Add Service Note Button -->
        <!-- The tickets list logs notes per ticket instead, from the row action button -->
        <?php if (basename($_SERVER['SCRIPT_NAME']) !== 'tickets.php'): ?>
            <button onclick="openNewServiceNoteModal()" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-medium text-xs sm:text-sm px-3 sm:px-5 py-2 sm:py-2.5 rounded-full shadow-sm shadow-[#EB3E0B]/25 flex items-center space-x-1.5 transition-all active:scale-95">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                </svg>
                <span class="hidden xs:inline sm:inline">Log Service Note</span>
            </button>
        <?php endif; ?>

        <!-- Audio Test Button -->
        <button type="button" onclick="testNotificationSound()" class="text-slate-500 hover:text-[#EB3E0B] p-2 rounded-full hover:bg-slate-100 transition-colors flex items-center space-x-1" title="Test Notification Sound">
            <svg class="w-4 h-4 text-slate-500 hover:text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.536 8.464a5 5 0 010 7.072m2.828-9.9a9 9 0 010 12.728M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z"/>
            </svg>
            <span class="text-[11px] font-bold text-slate-600 hidden xl:inline">Test Sound</span>
        </button>

        <!-- Notification Bell Dropdown Container -->
        <div class="relative">
            <button type="button" onclick="toggleNotificationDropdown(event)" class="relative text-slate-600 hover:text-slate-900 p-2.5 rounded-full hover:bg-slate-100 transition-colors focus:outline-none" title="Pending Support Notifications">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                </svg>
                <span id="header_pending_badge" class="absolute -top-1 -right-1 bg-[#EB3E0B] text-white font-bold text-[10px] px-1.5 py-0.5 rounded-full ring-2 ring-white <?php echo ($pending_count > 0) ? '' : 'hidden'; ?>">
                    <?php echo $pending_count; ?>
                </span>
            </button>

            <!-- Notification Modal / Dropdown -->
            <div id="notificationDropdown" class="fixed inset-x-3 top-16 sm:inset-x-auto sm:right-0 sm:top-12 sm:w-96 max-w-full sm:max-w-md bg-white rounded-3xl shadow-2xl border border-slate-200 z-50 p-4 sm:p-5 space-y-3 hidden">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <div class="flex items-center space-x-2">
                        <h4 class="font-extrabold text-sm text-slate-900">Notifications</h4>
                        <span id="dropdown_pending_count" class="bg-[#FFE8D5] text-[#EB3E0B] font-extrabold text-[11px] px-2.5 py-0.5 rounded-full">
                            <?php echo $pending_count; ?> Pending
                        </span>
                    </div>
                    <div class="flex items-center space-x-2">
                        <button type="button" onclick="markAllNotificationsAsRead()" class="text-[11px] font-bold text-slate-500 hover:text-[#EB3E0B] transition-colors">Mark all read</button>
                        <button type="button" onclick="closeNotificationDropdown()" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-full hover:bg-slate-100 transition-colors" aria-label="Close notifications">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Notification Items List -->
                <div id="notification_tickets_list" class="space-y-2 max-h-[60vh] sm:max-h-72 overflow-y-auto pr-1">
                    <?php if (empty($admin_notifications)): ?>
                        <div class="py-6 text-center text-xs text-slate-400 space-y-1">
                            <p class="font-bold text-slate-600">No Pending Notifications</p>
                            <p>All client support tickets and maintenance requests are handled.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($admin_notifications as $an): ?>
                            <a href="<?php echo $an['link']; ?>" data-notif-key="<?php echo $an['key']; ?>" data-notif-id="<?php echo isset($an['combined_id']) ? $an['combined_id'] : 0; ?>" onclick="markNotificationClicked('<?php echo $an['key']; ?>', <?php echo isset($an['combined_id']) ? $an['combined_id'] : 0; ?>)" class="block p-3 rounded-2xl bg-slate-50 hover:bg-[#FFE8D5]/50 border border-slate-200/80 hover:border-[#FECDAA] transition-all space-y-1 group">
                                <div class="flex items-center justify-between gap-2 min-w-0">
                                    <span class="font-extrabold text-xs text-slate-900 group-hover:text-[#EB3E0B] truncate flex-1 min-w-0" title="<?php echo sanitize($an['title']); ?>">
                                        <?php echo sanitize($an['title']); ?>
                                    </span>
                                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border shrink-0 <?php echo ($an['type'] === 'maintenance') ? 'bg-[#FFE8D5] text-[#EB3E0B] border-[#FECDAA]' : 'bg-amber-100 text-amber-800 border-amber-300'; ?>">
                                        <?php echo sanitize($an['badge']); ?>
                                    </span>
                                </div>
                                <p class="text-xs text-slate-600 font-medium line-clamp-1">
                                    <?php echo sanitize($an['subtitle']); ?>
                                </p>
                                <div class="flex items-center justify-between pt-1 text-[10px] text-slate-400 font-mono">
                                    <span><?php echo sanitize($an['number']); ?></span>
                                    <span><?php echo format_date($an['date']); ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="pt-2 border-t border-slate-100 flex items-center justify-between text-xs">
                    <a href="tickets.php?status=Pending" class="font-bold text-[#EB3E0B] hover:underline flex items-center space-x-1">
                        <span>View All Pending Tickets</span>
                        <span>&rarr;</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Tech User Profile Pill -->
        <div class="flex items-center space-x-2 p-1.5 pr-3 rounded-full bg-slate-100 border border-slate-200/60">
            <div class="w-7 h-7 rounded-full bg-slate-900 text-white font-bold text-xs flex items-center justify-center">
                <?php echo strtoupper(substr($tech_name, 0, 1)); ?>
            </div>
            <span class="text-xs font-semibold text-slate-800 hidden lg:inline max-w-[140px] truncate"><?php echo sanitize($tech_name); ?></span>
        </div>
    </div>
</header>

<!-- Mobile Search Bar Expandable Drawer (Screen < sm) -->
<div id="adminMobileSearchBarContainer" class="sm:hidden bg-white border-b border-slate-200 px-4 py-3 hidden">
    <form action="tickets.php" method="GET" class="relative m-0" onsubmit="return handleAdminMobileSearchSubmit(event)">
        <input type="text" id="adminMobileSearchInput" name="q" value="<?php echo sanitize($current_admin_search_q); ?>" 
               placeholder="Search tickets, clients, stock..." 
               autocomplete="off"
               oninput="handleAdminLiveSearch(this.value, true)"
               class="w-full bg-slate-100 text-slate-800 text-xs pl-9 pr-9 py-2.5 rounded-full border border-slate-200 focus:border-[#FA5915] focus:bg-white focus:outline-none transition-all placeholder-slate-400">
        <button type="submit" class="absolute left-3 top-2.5 text-slate-400 hover:text-[#EB3E0B] focus:outline-none">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
        </button>
        <button type="button" onclick="toggleAdminMobileSearchBar()" class="absolute right-3 top-2.5 text-slate-400 hover:text-slate-700 text-xs font-bold p-0.5">
            ✕
        </button>
    </form>
</div>

<script>
function toggleNotificationDropdown(e) {
    e.stopPropagation();
    var dd = document.getElementById('notificationDropdown');
    if (dd) {
        dd.classList.toggle('hidden');
    }
}

function closeNotificationDropdown() {
    var dd = document.getElementById('notificationDropdown');
    if (dd) {
        dd.classList.add('hidden');
    }
}

// Mobile Search Bar Toggle
function toggleAdminMobileSearchBar() {
    var bar = document.getElementById('adminMobileSearchBarContainer');
    if (bar) {
        bar.classList.toggle('hidden');
        if (!bar.classList.contains('hidden')) {
            var inp = document.getElementById('adminMobileSearchInput');
            if (inp) inp.focus();
        }
    }
}

// Live Search Handlers
var adminSearchTimer = null;

function clearAdminSearchInput() {
    var inp = document.getElementById('adminHeaderSearchInput');
    if (inp) {
        inp.value = '';
        var btn = document.getElementById('adminSearchClearBtn');
        if (btn) btn.classList.add('hidden');
        hideAdminLiveSearchResults();
    }
}

function hideAdminLiveSearchResults() {
    var box = document.getElementById('adminSearchLiveResults');
    if (box) box.classList.add('hidden');
}

function handleAdminSearchSubmit(e) {
    var inp = document.getElementById('adminHeaderSearchInput');
    if (inp && inp.value.trim() !== '') {
        window.location.href = 'tickets.php?q=' + encodeURIComponent(inp.value.trim());
        if (e) e.preventDefault();
        return false;
    }
    return true;
}

function handleAdminMobileSearchSubmit(e) {
    var inp = document.getElementById('adminMobileSearchInput');
    if (inp && inp.value.trim() !== '') {
        window.location.href = 'tickets.php?q=' + encodeURIComponent(inp.value.trim());
        if (e) e.preventDefault();
        return false;
    }
    return true;
}

function submitAdminSearchForm() {
    var inp = document.getElementById('adminHeaderSearchInput');
    if (!inp || !inp.value) {
        inp = document.getElementById('adminMobileSearchInput');
    }
    if (inp && inp.value.trim() !== '') {
        window.location.href = 'tickets.php?q=' + encodeURIComponent(inp.value.trim());
    } else {
        window.location.href = 'tickets.php';
    }
}

function handleAdminLiveSearch(query, isMobile) {
    if (typeof isMobile === 'undefined') isMobile = false;
    
    var clearBtn = document.getElementById('adminSearchClearBtn');
    if (clearBtn) {
        if (query.trim().length > 0) {
            clearBtn.classList.remove('hidden');
        } else {
            clearBtn.classList.add('hidden');
        }
    }

    if (adminSearchTimer) clearTimeout(adminSearchTimer);

    if (query.trim().length < 2) {
        hideAdminLiveSearchResults();
        return;
    }

    adminSearchTimer = setTimeout(function() {
        fetch('search_ajax.php?q=' + encodeURIComponent(query.trim()))
            .then(function(res) { return res.json(); })
            .then(function(data) {
                renderAdminLiveSearchResults(data, query);
            })
            .catch(function(err) {
                console.error('Search error:', err);
            });
    }, 200);
}

function renderAdminLiveSearchResults(data, query) {
    var resultsBox = document.getElementById('adminSearchLiveResults');
    var contentBox = document.getElementById('adminSearchLiveContent');
    if (!resultsBox || !contentBox) return;

    if (!data.success || data.count === 0) {
        contentBox.innerHTML = '<div class="py-4 text-center text-xs text-slate-400 space-y-1">' +
            '<p class="font-bold text-slate-700">No results found for "' + escapeAdminHtml(query) + '"</p>' +
            '<p>Press Enter to search all support tickets.</p>' +
        '</div>';
        resultsBox.classList.remove('hidden');
        return;
    }

    var html = '';

    // Accounts
    if (data.accounts && data.accounts.length > 0) {
        html += '<div class="space-y-1.5">';
        html += '<span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">Client Accounts</span>';
        for (var a = 0; a < data.accounts.length; a++) {
            var acct = data.accounts[a];
            html += '<a href="' + acct.url + '" class="block p-2.5 rounded-2xl bg-slate-50 hover:bg-[#FFE8D5]/50 border border-slate-200 transition-all space-y-0.5 group">' +
                '<div class="flex items-center justify-between gap-2">' +
                    '<span class="font-bold text-xs text-slate-900 group-hover:text-[#EB3E0B] truncate flex-1">' + escapeAdminHtml(acct.title) + '</span>' +
                    '<span class="text-[10px] font-bold font-mono px-2 py-0.2 rounded-full border bg-slate-100 text-slate-700 border-slate-300 shrink-0">#' + escapeAdminHtml(acct.date) + '</span>' +
                '</div>' +
                '<p class="text-[11px] text-slate-500 truncate">' + escapeAdminHtml(acct.subtitle) + '</p>' +
            '</a>';
        }
        html += '</div>';
    }

    // Tickets
    if (data.tickets && data.tickets.length > 0) {
        html += '<div class="space-y-1.5 pt-1 border-t border-slate-100">';
        html += '<span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">Support Tickets</span>';
        for (var i = 0; i < data.tickets.length; i++) {
            var item = data.tickets[i];
            html += '<a href="' + item.url + '" class="block p-2.5 rounded-2xl bg-slate-50 hover:bg-[#FFE8D5]/50 border border-slate-200 transition-all space-y-0.5 group">' +
                '<div class="flex items-center justify-between gap-2">' +
                    '<span class="font-bold text-xs text-slate-900 group-hover:text-[#EB3E0B] truncate flex-1">' + escapeAdminHtml(item.title) + '</span>' +
                    '<span class="text-[10px] font-bold px-2 py-0.2 rounded-full border bg-amber-100 text-amber-800 border-amber-300 shrink-0">' + escapeAdminHtml(item.badge) + '</span>' +
                '</div>' +
                '<p class="text-[11px] text-slate-500 truncate">' + escapeAdminHtml(item.subtitle) + '</p>' +
            '</a>';
        }
        html += '</div>';
    }

    // Inventory
    if (data.inventory && data.inventory.length > 0) {
        html += '<div class="space-y-1.5 pt-1 border-t border-slate-100">';
        html += '<span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">Hardware Inventory</span>';
        for (var j = 0; j < data.inventory.length; j++) {
            var inv = data.inventory[j];
            html += '<a href="' + inv.url + '" class="block p-2.5 rounded-2xl bg-slate-50 hover:bg-[#FFE8D5]/50 border border-slate-200 transition-all space-y-0.5 group">' +
                '<div class="flex items-center justify-between gap-2">' +
                    '<span class="font-bold text-xs text-slate-900 group-hover:text-[#EB3E0B] truncate flex-1">' + escapeAdminHtml(inv.title) + '</span>' +
                    '<span class="text-[10px] font-bold px-2 py-0.2 rounded-full border bg-emerald-100 text-emerald-800 border-emerald-300 shrink-0">' + escapeAdminHtml(inv.badge) + '</span>' +
                '</div>' +
                '<p class="text-[11px] text-slate-500 truncate">' + escapeAdminHtml(inv.subtitle) + '</p>' +
            '</a>';
        }
        html += '</div>';
    }

    contentBox.innerHTML = html;
    resultsBox.classList.remove('hidden');
}

function escapeAdminHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

// Global click handler to close dropdowns
document.addEventListener('click', function(e) {
    var dd = document.getElementById('notificationDropdown');
    if (dd && !dd.classList.contains('hidden')) {
        if (!dd.contains(e.target) && !e.target.closest('[onclick*="toggleNotificationDropdown"]')) {
            dd.classList.add('hidden');
        }
    }

    var sBox = document.getElementById('adminSearchLiveResults');
    var sInput = document.getElementById('adminHeaderSearchInput');
    if (sBox && !sBox.classList.contains('hidden')) {
        if (!sBox.contains(e.target) && e.target !== sInput) {
            sBox.classList.add('hidden');
        }
    }
});
</script>
