<?php
// Support Center Admin Footer Component & Service Note Modal

// Handle Service Note Form Submission if posted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_tech_note') {
    $accountnum = isset($_POST['accountnum']) ? trim($_POST['accountnum']) : '';
    $clientname = isset($_POST['clientname']) ? trim($_POST['clientname']) : '';
    $address = isset($_POST['address']) ? trim($_POST['address']) : '';
    $reasonoftech = isset($_POST['reasonoftech']) ? trim($_POST['reasonoftech']) : '';
    $causeoftheissue = isset($_POST['causeoftheissue']) ? trim($_POST['causeoftheissue']) : '';
    $resso = isset($_POST['resso']) ? trim($_POST['resso']) : '';
    $status = isset($_POST['status']) ? trim($_POST['status']) : 'Done';
    $xdate = date('m/d/Y');

    $tech = get_logged_tech();
    $techname = $tech ? $tech['fullname'] : 'RNZ Support Tech';

    if (!empty($accountnum) && !empty($reasonoftech)) {
        try {
            $pdo = get_db_connection();
            
            // Auto fetch client name & address if missing
            if (empty($clientname) || empty($address)) {
                $stmt_c = $pdo->prepare("SELECT tradename, address FROM bucket_client WHERE accountnum = :acct LIMIT 1");
                $stmt_c->execute(array(':acct' => $accountnum));
                $c_row = $stmt_c->fetch();
                if ($c_row) {
                    if (empty($clientname)) $clientname = $c_row['tradename'];
                    if (empty($address)) $address = $c_row['address'];
                }
            }

            $stmt_in = $pdo->prepare("INSERT INTO bucket_technotes 
                (accountnum, xdate, clientname, address, techname, reasonoftech, causeoftheissue, resso, status)
                VALUES (:acct, :xdate, :cname, :addr, :tname, :reason, :cause, :resso, :status)");

            $stmt_in->execute(array(
                ':acct' => $accountnum,
                ':xdate' => $xdate,
                ':cname' => $clientname,
                ':addr' => $address,
                ':tname' => $techname,
                ':reason' => $reasonoftech,
                ':cause' => $causeoftheissue,
                ':resso' => $resso,
                ':status' => $status
            ));

            echo "<script>alert('Technician Service Note recorded successfully!'); window.location.href = window.location.pathname;</script>";
            exit;
        } catch (PDOException $e) {
            error_log("Tech Note Insert Error: " . $e->getMessage());
        }
    }
}
?>
<!-- Footer -->
<footer class="bg-white border-t border-slate-200 py-4 px-6 text-center text-xs text-slate-500 mt-auto">
    <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-2">
        <p>&copy; <?php echo date('Y'); ?> RNZ Support System - Technician & Admin Portal</p>
        <div class="flex items-center space-x-4">
            <span class="text-slate-400">Internal Support Console v2.0</span>
        </div>
    </div>
</footer>

<!-- Log Technician Service Note Modal -->
<div id="newServiceNoteModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 hidden">
    <div class="bg-white rounded-3xl max-w-xl w-full p-6 sm:p-8 shadow-2xl border border-slate-200 relative animate-fadeIn max-h-[90vh] overflow-y-auto">
        <!-- Close Button -->
        <button onclick="closeNewServiceNoteModal()" class="absolute top-6 right-6 text-slate-400 hover:text-slate-600 p-2 rounded-full hover:bg-slate-100 transition-colors">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>

        <div class="flex items-center space-x-3 mb-6">
            <div class="w-10 h-10 rounded-2xl bg-[#FFE8D5] text-[#EB3E0B] flex items-center justify-center font-bold shadow-sm">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <div>
                <h3 class="text-lg font-extrabold text-slate-900">Log Technician Service Note</h3>
                <p class="text-xs text-slate-500">Record a technical visit or support service log in bucket_technotes.</p>
            </div>
        </div>

        <form action="" method="POST" class="space-y-4">
            <input type="hidden" name="action" value="save_tech_note">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Account Number *</label>
                    <input type="text" name="accountnum" id="note_accountnum" required placeholder="e.g. 00000002" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Client Trade Name</label>
                    <input type="text" name="clientname" id="note_clientname" placeholder="Client business name" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Service Address</label>
                <input type="text" name="address" id="note_address" placeholder="Location or store address" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Reason for Technical Service *</label>
                <textarea name="reasonoftech" id="note_reason" rows="2" required placeholder="Reason for tech visit or support call..." class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all"></textarea>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Root Cause of the Issue</label>
                <textarea name="causeoftheissue" id="note_cause" rows="2" placeholder="Diagnosed cause of the issue..." class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all"></textarea>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Resolution / Work Done</label>
                <textarea name="resso" id="note_resso" rows="2" placeholder="Steps performed and solution..." class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all"></textarea>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">Status</label>
                <select name="status" class="w-full bg-slate-50 border border-slate-200 text-slate-900 text-xs rounded-xl p-3 focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                    <option value="Done">Done / Resolved</option>
                    <option value="Pending Issue">Pending Issue</option>
                    <option value="Working">Working / In Progress</option>
                </select>
            </div>

            <div class="pt-4 flex items-center justify-end space-x-3 border-t border-slate-100">
                <button type="button" onclick="closeNewServiceNoteModal()" class="px-5 py-2.5 rounded-full text-xs font-bold text-slate-600 hover:bg-slate-100 transition-colors">
                    Cancel
                </button>
                <button type="submit" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs px-6 py-2.5 rounded-full shadow-md transition-all active:scale-95">
                    Save Service Note
                </button>
            </div>
        </form>
    </div>
</div>

<?php
$pdo_footer = get_db_connection();
$stmt_max_t = $pdo_footer->query("SELECT MAX(id) FROM client_support_tickets");
$init_max_ticket_id = intval($stmt_max_t->fetchColumn());

$stmt_max_m = $pdo_footer->query("SELECT MAX(id) FROM client_maintenance_requests");
$init_max_maint_id = intval($stmt_max_m->fetchColumn());

$init_combined_id = ($init_max_ticket_id * 100000) + $init_max_maint_id;
?>
<script>
function getClickedNotifications() {
    try {
        var data = localStorage.getItem('rnz_clicked_notifications');
        return data ? JSON.parse(data) : {};
    } catch(e) {
        return {};
    }
}

function markNotificationClicked(notifKey, notifId, updateBadgeNow) {
    if (typeof updateBadgeNow === 'undefined') {
        updateBadgeNow = true;
    }
    try {
        var clicked = getClickedNotifications();
        if (notifKey) clicked[notifKey] = true;
        if (notifId) clicked['id_' + notifId] = true;
        localStorage.setItem('rnz_clicked_notifications', JSON.stringify(clicked));
        
        var maxId = parseInt(localStorage.getItem('rnz_last_clicked_notif_id') || '0', 10);
        if (notifId && notifId > maxId) {
            localStorage.setItem('rnz_last_clicked_notif_id', notifId.toString());
        }
        if (notifId) {
            lastKnownTicketId = Math.max(lastKnownTicketId, notifId);
        }
    } catch(e) {}

    if (updateBadgeNow) {
        updateUnreadBadgeCount();
    }
}

function markAllNotificationsAsRead() {
    var dropList = document.getElementById('notification_tickets_list');
    if (dropList) {
        var items = dropList.querySelectorAll('a[data-notif-key]');
        for (var i = 0; i < items.length; i++) {
            var key = items[i].getAttribute('data-notif-key');
            var id = items[i].getAttribute('data-notif-id');
            markNotificationClicked(key, id, false);
        }
    }
    updateUnreadBadgeCount();
}

function isNotificationClicked(notifKey, notifId) {
    try {
        var clicked = getClickedNotifications();
        if (notifKey && clicked[notifKey]) return true;
        if (notifId && clicked['id_' + notifId]) return true;
        var maxId = parseInt(localStorage.getItem('rnz_last_clicked_notif_id') || '0', 10);
        if (notifId && notifId <= maxId) return true;
        return false;
    } catch(e) {
        return false;
    }
}

function updateUnreadBadgeCount() {
    var dropList = document.getElementById('notification_tickets_list');
    var badge = document.getElementById('header_pending_badge');
    var dropCount = document.getElementById('dropdown_pending_count');
    
    var unreadCount = 0;
    
    if (dropList) {
        var items = dropList.querySelectorAll('a[data-notif-key]');
        for (var i = 0; i < items.length; i++) {
            var key = items[i].getAttribute('data-notif-key');
            var id = items[i].getAttribute('data-notif-id');
            if (!isNotificationClicked(key, id)) {
                unreadCount++;
                items[i].classList.add('bg-amber-50/90', 'border-amber-200');
                items[i].classList.remove('opacity-60', 'bg-slate-50');
            } else {
                items[i].classList.remove('bg-amber-50/90', 'border-amber-200', 'animate-pulse');
                items[i].classList.add('opacity-60', 'bg-slate-50');
            }
        }
    }
    
    if (badge) {
        badge.innerText = unreadCount;
        if (unreadCount > 0) {
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
        }
    }
    
    if (dropCount) {
        dropCount.innerText = unreadCount + ' Pending';
    }
}

var serverMaxId = <?php echo $init_combined_id; ?>;
var storedMaxClickedId = parseInt(localStorage.getItem('rnz_last_clicked_notif_id') || '0', 10);
var lastKnownTicketId = Math.max(serverMaxId, storedMaxClickedId);

document.addEventListener('DOMContentLoaded', function() {
    updateUnreadBadgeCount();
});

function openNewServiceNoteModal(acct, name, addr, reason) {
    if (acct) document.getElementById('note_accountnum').value = acct;
    if (name) document.getElementById('note_clientname').value = name;
    if (addr) document.getElementById('note_address').value = addr;
    if (reason) document.getElementById('note_reason').value = reason;
    document.getElementById('newServiceNoteModal').classList.remove('hidden');
}

function closeNewServiceNoteModal() {
    document.getElementById('newServiceNoteModal').classList.add('hidden');
}

// Web Audio API Chime Synthesizer (No external sound file needed)
function playNewTicketChime() {
    try {
        var AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;
        var ctx = new AudioCtx();
        if (ctx.state === 'suspended') {
            ctx.resume();
        }

        // Tone 1: D5 (587.33 Hz)
        var osc1 = ctx.createOscillator();
        var gain1 = ctx.createGain();
        osc1.type = 'sine';
        osc1.frequency.setValueAtTime(587.33, ctx.currentTime);
        gain1.gain.setValueAtTime(0.3, ctx.currentTime);
        gain1.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.4);
        osc1.connect(gain1);
        gain1.connect(ctx.destination);
        osc1.start(ctx.currentTime);
        osc1.stop(ctx.currentTime + 0.4);

        // Tone 2: A5 (880.00 Hz)
        var osc2 = ctx.createOscillator();
        var gain2 = ctx.createGain();
        osc2.type = 'sine';
        osc2.frequency.setValueAtTime(880.00, ctx.currentTime + 0.15);
        gain2.gain.setValueAtTime(0.4, ctx.currentTime + 0.15);
        gain2.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.7);
        osc2.connect(gain2);
        gain2.connect(ctx.destination);
        osc2.start(ctx.currentTime + 0.15);
        osc2.stop(ctx.currentTime + 0.7);

        // Tone 3: D6 (1174.66 Hz)
        var osc3 = ctx.createOscillator();
        var gain3 = ctx.createGain();
        osc3.type = 'sine';
        osc3.frequency.setValueAtTime(1174.66, ctx.currentTime + 0.3);
        gain3.gain.setValueAtTime(0.35, ctx.currentTime + 0.3);
        gain3.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.9);
        osc3.connect(gain3);
        gain3.connect(ctx.destination);
        osc3.start(ctx.currentTime + 0.3);
        osc3.stop(ctx.currentTime + 0.9);
    } catch (e) {
        console.log('Audio chime exception:', e);
    }
}

// Real-Time Ticket Poller (Checks every 6 seconds)
function pollForNewTickets() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'api_check_tickets.php', true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success && res.latest_id > 0) {
                    var notifKey = res.latest_type + '_' + (res.latest_ticket ? res.latest_ticket.id : 0);
                    var alreadyClicked = isNotificationClicked(notifKey, res.latest_id);

                    if (res.latest_id > lastKnownTicketId) {
                        lastKnownTicketId = res.latest_id;
                        
                        if (!alreadyClicked) {
                            // Play Sound
                            playNewTicketChime();
                            
                            // Show Visual Toast
                            showNewTicketToastPopup(res.latest_ticket, res.pending_count, res.latest_type, res.latest_id, notifKey);
                        }

                        // Update Dropdown Counter & List
                        var dropList = document.getElementById('notification_tickets_list');
                        if (dropList && res.latest_ticket) {
                            var t = res.latest_ticket;
                            var isMaint = (res.latest_type === 'maintenance');
                            var itemLink = isMaint ? 'maintenance.php' : ('ticket_detail.php?id=' + t.id);
                            var itemBadge = isMaint ? 'Maintenance' : escapeHtml(t.priority);
                            var badgeClass = isMaint ? 'bg-[#FFE8D5] text-[#EB3E0B] border-[#FECDAA]' : 'bg-amber-100 text-amber-800 border-amber-300';

                            var itemHtml = '<a href="' + itemLink + '" data-notif-key="' + notifKey + '" data-notif-id="' + res.latest_id + '" onclick="markNotificationClicked(\'' + notifKey + '\', ' + res.latest_id + ')" class="block p-3 rounded-2xl bg-amber-50 hover:bg-[#FFE8D5] border border-amber-200 transition-all space-y-1 group animate-pulse">'
                                + '<div class="flex items-center justify-between gap-2 min-w-0">'
                                + '  <span class="font-extrabold text-xs text-slate-900 group-hover:text-[#EB3E0B] truncate flex-1 min-w-0" title="' + escapeHtml(t.tradename) + '">' + escapeHtml(t.tradename) + '</span>'
                                + '  <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border shrink-0 ' + badgeClass + '">' + itemBadge + '</span>'
                                + '</div>'
                                + '<p class="text-xs text-slate-600 font-medium line-clamp-1">' + escapeHtml(t.subject) + '</p>'
                                + '<div class="flex items-center justify-between pt-1 text-[10px] text-slate-400 font-mono">'
                                + '  <span>' + escapeHtml(t.ticket_number) + '</span>'
                                + '  <span>Just now</span>'
                                + '</div>'
                                + '</a>';
                            dropList.insertAdjacentHTML('afterbegin', itemHtml);
                        }

                        updateUnreadBadgeCount();
                    }
                }
            } catch (e) {
                console.error(e);
            }
        }
    };
    xhr.send();
}

function showNewTicketToastPopup(ticket, pendingCount, latestType, latestId, notifKey) {
    var toast = document.createElement('div');
    toast.className = 'fixed bottom-6 right-6 z-50 bg-slate-900 text-white rounded-3xl p-5 shadow-2xl border border-[#FA5915] max-w-sm w-full space-y-3 animate-bounce';
    
    var isMaint = (latestType === 'maintenance');
    var targetLink = isMaint ? 'maintenance.php' : ('ticket_detail.php?id=' + (ticket ? ticket.id : 0));
    var labelText = isMaint ? 'NEW POS MAINTENANCE REQUEST' : 'NEW SUPPORT TICKET';
    var iconSymbol = isMaint ? '🛠️' : '🔔';
    var btnText = isMaint ? 'View Requests &rarr;' : 'View Ticket &rarr;';

    var html = '';
    html += '<div class="flex items-start justify-between gap-3 min-w-0">';
    html += '  <div class="flex items-center space-x-3 min-w-0 flex-1 overflow-hidden">';
    html += '    <div class="w-10 h-10 rounded-2xl bg-[#EB3E0B] text-white flex items-center justify-center font-bold text-lg shrink-0 shadow-md">' + iconSymbol + '</div>';
    html += '    <div class="min-w-0 flex-1 overflow-hidden">';
    html += '      <span class="text-[10px] font-extrabold uppercase tracking-wider text-[#FEAA73] block truncate">' + labelText + '</span>';
    html += '      <h4 class="text-sm font-extrabold text-white truncate max-w-full" title="' + (ticket ? escapeHtml(ticket.tradename) : '') + '">' + (ticket ? escapeHtml(ticket.tradename) : 'Client Request') + '</h4>';
    html += '    </div>';
    html += '  </div>';
    html += '  <button onclick="markNotificationClicked(\'' + (notifKey || '') + '\', ' + (latestId || 0) + '); this.parentElement.parentElement.remove()" class="text-slate-400 hover:text-white p-1 text-lg font-bold shrink-0">&times;</button>';
    html += '</div>';
    
    if (ticket) {
        html += '<p class="text-xs text-slate-300 line-clamp-2 bg-slate-800/80 p-2.5 rounded-xl border border-slate-700 font-medium">' + escapeHtml(ticket.subject) + '</p>';
        html += '<div class="flex items-center justify-between pt-1">';
        html += '  <span class="text-[11px] font-mono text-slate-400">' + escapeHtml(ticket.ticket_number) + '</span>';
        html += '  <a href="' + targetLink + '" onclick="markNotificationClicked(\'' + (notifKey || '') + '\', ' + (latestId || 0) + ')" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs px-4 py-1.5 rounded-full transition-all shadow-sm">' + btnText + '</a>';
        html += '</div>';
    }
    
    toast.innerHTML = html;
    document.body.appendChild(toast);
    
    setTimeout(function() {
        if (toast && toast.parentElement) {
            toast.remove();
        }
    }, 12000);
}

function escapeHtml(str) {
    return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// Start polling every 6 seconds
setInterval(pollForNewTickets, 6000);
</script>
</body>
</html>
