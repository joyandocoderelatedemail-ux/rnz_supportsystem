<?php
// Support Center Admin Footer Component & Service Note Modal
require_once __DIR__ . '/technote_init.php';

init_technote_tables();

// Handle Service Note Form Submission if posted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_tech_note') {
    $accountnum = isset($_POST['accountnum']) ? trim($_POST['accountnum']) : '';
    $clientname = isset($_POST['clientname']) ? trim($_POST['clientname']) : '';
    $address = isset($_POST['address']) ? trim($_POST['address']) : '';
    $reasonoftech = isset($_POST['reasonoftech']) ? trim($_POST['reasonoftech']) : '';
    $causeoftheissue = isset($_POST['causeoftheissue']) ? trim($_POST['causeoftheissue']) : '';
    $resso = isset($_POST['resso']) ? trim($_POST['resso']) : '';
    $status = isset($_POST['status']) ? trim($_POST['status']) : 'Done';
    $note_ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    $note_id = isset($_POST['note_id']) ? intval($_POST['note_id']) : 0;
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

            // Reopening a ticket's note loads it back into this form, so saving
            // updates that note rather than stacking a second one on the ticket.
            if ($note_id > 0 && $note_ticket_id > 0) {
                $stmt_up = $pdo->prepare("UPDATE bucket_technotes SET
                    accountnum = :acct, xdate = :xdate, clientname = :cname, address = :addr,
                    techname = :tname, reasonoftech = :reason, causeoftheissue = :cause,
                    resso = :resso, status = :status
                    WHERE id = :nid AND ticket_id = :tid");

                $stmt_up->execute(array(
                    ':acct' => $accountnum,
                    ':xdate' => $xdate,
                    ':cname' => $clientname,
                    ':addr' => $address,
                    ':tname' => $techname,
                    ':reason' => $reasonoftech,
                    ':cause' => $causeoftheissue,
                    ':resso' => $resso,
                    ':status' => $status,
                    ':nid' => $note_id,
                    ':tid' => $note_ticket_id
                ));
            } else {
                $stmt_in = $pdo->prepare("INSERT INTO bucket_technotes
                    (accountnum, xdate, clientname, address, techname, reasonoftech, causeoftheissue, resso, status, ticket_id)
                    VALUES (:acct, :xdate, :cname, :addr, :tname, :reason, :cause, :resso, :status, :tid)");

                $stmt_in->execute(array(
                    ':acct' => $accountnum,
                    ':xdate' => $xdate,
                    ':cname' => $clientname,
                    ':addr' => $address,
                    ':tname' => $techname,
                    ':reason' => $reasonoftech,
                    ':cause' => $causeoftheissue,
                    ':resso' => $resso,
                    ':status' => $status,
                    ':tid' => $note_ticket_id
                ));
            }

            // A note logged against a ticket and saved as Done closes that
            // ticket out, so the visit and the ticket never disagree.
            $ticket_resolved = false;
            if ($note_ticket_id > 0 && $status === 'Done') {
                $stmt_tk = $pdo->prepare("UPDATE client_support_tickets SET status = 'Resolved', updated_at = :now WHERE id = :tid");
                $stmt_tk->execute(array(':now' => date('Y-m-d H:i:s'), ':tid' => $note_ticket_id));
                // rowCount() is 0 for a ticket that was already Resolved, and it
                // is still Resolved either way, so the message does not use it.
                $ticket_resolved = true;
            }

            $saved_word = ($note_id > 0 && $note_ticket_id > 0) ? 'updated' : 'recorded';
            $done_msg = $ticket_resolved
                ? 'Technician Service Note ' . $saved_word . '. The support ticket is now marked as Resolved.'
                : 'Technician Service Note ' . $saved_word . ' successfully!';

            echo "<script>alert(" . json_encode($done_msg) . "); window.location.href = window.location.pathname;</script>";
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

        <!-- Shown when this ticket already has a service note loaded into the form -->
        <div id="note_existing_banner" class="hidden mb-5 rounded-2xl border border-[#FECDAA] bg-[#FFF5ED] px-4 py-3 flex items-start gap-2.5">
            <svg class="w-4 h-4 text-[#EB3E0B] shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div class="min-w-0">
                <p class="text-xs font-extrabold text-[#9A2512]">Service note already logged for this ticket</p>
                <p id="note_existing_meta" class="text-[11px] text-slate-600 leading-snug"></p>
            </div>
        </div>

        <form action="" method="POST" class="space-y-4">
            <input type="hidden" name="action" value="save_tech_note">
            <!-- Set when the note is logged from a support ticket, so saving it
                 as Done can close that ticket out too -->
            <input type="hidden" name="ticket_id" id="note_ticket_id" value="">
            <!-- Set when this ticket already has a note, so saving edits it
                 instead of stacking a second note on the same ticket -->
            <input type="hidden" name="note_id" id="note_id" value="">

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

            <!-- Only notes opened from a ticket have a conversation to summarise -->
            <div id="note_ai_row" class="hidden items-center justify-between gap-3 rounded-2xl border border-dashed border-[#FECDAA] bg-[#FFF9F5] px-3.5 py-2.5">
                <p class="text-[10px] text-slate-500 font-medium leading-snug min-w-0">
                    Draft this note from the ticket conversation. <span class="font-bold text-slate-700">Always review before saving.</span>
                </p>
                <button type="button" id="note_ai_btn" onclick="summarizeTicketNote()" class="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-slate-900 hover:bg-slate-800 text-white text-[10px] font-bold transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                    <svg id="note_ai_icon" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/>
                    </svg>
                    <span id="note_ai_label">Summarize with AI</span>
                </button>
            </div>
            <p id="note_ai_error" class="hidden text-[10px] font-bold text-rose-600 leading-snug"></p>

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

// Newest client message at page load, so the poller only sounds for messages
// that arrive from here on rather than for the whole backlog.
$stmt_max_r = $pdo_footer->query("SELECT MAX(id) FROM client_ticket_replies WHERE sender_type = 'client'");
$init_max_client_reply_id = intval($stmt_max_r->fetchColumn());
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
var lastKnownClientReplyId = <?php echo $init_max_client_reply_id; ?>;

document.addEventListener('DOMContentLoaded', function() {
    updateUnreadBadgeCount();
});

function openNewServiceNoteModal(acct, name, addr, reason, ticketId) {
    // Start from a blank form every time, otherwise the details of whatever
    // was opened before stay behind in the fields this call does not fill.
    var fields = ['note_accountnum', 'note_clientname', 'note_address', 'note_reason', 'note_cause', 'note_resso', 'note_ticket_id', 'note_id'];
    for (var i = 0; i < fields.length; i++) {
        var el = document.getElementById(fields[i]);
        if (el) el.value = '';
    }

    // Only notes opened from a ticket carry one, and only those can close a ticket
    if (ticketId) document.getElementById('note_ticket_id').value = ticketId;
    if (acct) document.getElementById('note_accountnum').value = acct;
    if (name) document.getElementById('note_clientname').value = name;
    if (addr) document.getElementById('note_address').value = addr;
    if (reason) document.getElementById('note_reason').value = reason;
    document.getElementById('newServiceNoteModal').classList.remove('hidden');

    // The AI draft needs a conversation, so it only appears for ticket notes
    var aiRow = document.getElementById('note_ai_row');
    document.getElementById('note_ai_error').classList.add('hidden');
    if (ticketId) {
        aiRow.classList.remove('hidden');
        aiRow.classList.add('flex');
    } else {
        aiRow.classList.add('hidden');
        aiRow.classList.remove('flex');
    }

    loadTicketServiceNote(ticketId);
}

// Drafts the note fields from the ticket's chat thread. This only fills the
// form in - the technician still reviews and presses Save themselves.
function summarizeTicketNote() {
    var ticketId = document.getElementById('note_ticket_id').value;
    if (!ticketId) return;

    var btn = document.getElementById('note_ai_btn');
    var label = document.getElementById('note_ai_label');
    var icon = document.getElementById('note_ai_icon');
    var errorBox = document.getElementById('note_ai_error');

    errorBox.classList.add('hidden');
    btn.disabled = true;
    label.textContent = 'Reading the conversation...';
    icon.classList.add('animate-spin');

    var done = function() {
        btn.disabled = false;
        label.textContent = 'Summarize with AI';
        icon.classList.remove('animate-spin');
    };

    fetch('api_summarize_ticket.php?ticket_id=' + encodeURIComponent(ticketId))
        .then(function(res) {
            // Read as text first: if PHP dies it returns an HTML error page, and
            // res.json() would throw, hiding the real reason behind "network error"
            return res.text().then(function(body) {
                try {
                    return JSON.parse(body);
                } catch (e) {
                    return {
                        success: false,
                        error: 'The server did not return a summary (HTTP ' + res.status + '). ' +
                               'It may have timed out - check backend/includes/ai_config.php.'
                    };
                }
            });
        })
        .then(function(data) {
            done();

            // The modal may have been closed or moved to another ticket by now
            if (document.getElementById('note_ticket_id').value !== String(ticketId)) return;

            if (!data || !data.success) {
                errorBox.textContent = (data && data.error) ? data.error : 'Could not draft the note.';
                errorBox.classList.remove('hidden');
                return;
            }

            var note = data.note;
            document.getElementById('note_reason').value = note.reasonoftech || '';
            document.getElementById('note_cause').value = note.causeoftheissue || '';
            document.getElementById('note_resso').value = note.resso || '';

            var statusSelect = document.querySelector('#newServiceNoteModal select[name="status"]');
            if (statusSelect && note.status) statusSelect.value = note.status;

            label.textContent = 'Redraft with AI';
        })
        .catch(function(err) {
            done();
            errorBox.textContent = 'Network error - the summary could not be generated.';
            errorBox.classList.remove('hidden');
            console.error('Note summary error:', err);
        });
}

// Loads the note already logged for this ticket - and only for this ticket -
// straight into the form, so reopening it shows what was saved rather than a
// blank sheet. Saving then edits that note instead of adding a second one.
function loadTicketServiceNote(ticketId) {
    var banner = document.getElementById('note_existing_banner');
    banner.classList.add('hidden');
    document.getElementById('note_id').value = '';

    if (!ticketId) return;

    fetch('api_ticket_notes.php?ticket_id=' + encodeURIComponent(ticketId))
        .then(function(res) { return res.json(); })
        .then(function(data) {
            // The modal may have been closed or reopened on another ticket by now
            if (document.getElementById('note_ticket_id').value !== String(ticketId)) return;
            if (!data || !data.success || !data.note) return;

            var note = data.note;
            document.getElementById('note_id').value = note.id;
            document.getElementById('note_reason').value = note.reasonoftech || '';
            document.getElementById('note_cause').value = note.causeoftheissue || '';
            document.getElementById('note_resso').value = note.resso || '';

            var statusSelect = document.querySelector('#newServiceNoteModal select[name="status"]');
            if (statusSelect && note.status) statusSelect.value = note.status;

            document.getElementById('note_existing_meta').textContent =
                'Logged ' + (note.xdate || '') + ' by ' + (note.techname || 'a technician') +
                '. Editing it here updates that same note.';
            banner.classList.remove('hidden');
        })
        .catch(function(err) {
            console.error('Service note load error:', err);
        });
}

function closeNewServiceNoteModal() {
    document.getElementById('newServiceNoteModal').classList.add('hidden');
}

// Notification Alarm - plays the submarine warning tone recording
var TICKET_ALARM_FILE = 'ElevenLabs_Loud_warning_tone_in_a_submarine_for_a_critical_system_malfunction.mp3';
var TICKET_ALARM_VOLUME = 0.85;
var TICKET_ALARM_MAX_SECONDS = 4;    // the clip runs ~10s; 0 plays all of it
var TICKET_ALARM_FADE_SECONDS = 0.5;

var ticketAlarmAudio = null;
var ticketAlarmStopTimer = null;
var ticketAlarmFadeTimer = null;
var ticketAlarmUnlocked = false;

// One element reused for every alarm, so the clip is fetched and decoded once
function getTicketAlarmAudio() {
    if (!ticketAlarmAudio) {
        ticketAlarmAudio = new Audio(TICKET_ALARM_FILE);
        ticketAlarmAudio.preload = 'auto';
        ticketAlarmAudio.volume = TICKET_ALARM_VOLUME;
    }
    return ticketAlarmAudio;
}

// Browsers refuse to play audio until the page has been interacted with, which
// is why an alarm arriving on its own can stay silent while the test button
// works. Priming the clip muted on the first click clears that for the session.
function unlockTicketAudio() {
    if (ticketAlarmUnlocked) return;
    ticketAlarmUnlocked = true;
    document.removeEventListener('click', unlockTicketAudio);
    document.removeEventListener('keydown', unlockTicketAudio);

    var audio = getTicketAlarmAudio();
    audio.muted = true;

    var reset = function() {
        audio.pause();
        audio.currentTime = 0;
        audio.muted = false;
    };

    try {
        var primed = audio.play();
        if (primed && primed.then) {
            primed.then(reset)['catch'](reset);
        } else {
            reset();
        }
    } catch (e) {
        reset();
    }
}
document.addEventListener('click', unlockTicketAudio);
document.addEventListener('keydown', unlockTicketAudio);

function stopTicketAlarm() {
    if (ticketAlarmStopTimer) {
        clearTimeout(ticketAlarmStopTimer);
        ticketAlarmStopTimer = null;
    }
    if (ticketAlarmFadeTimer) {
        clearInterval(ticketAlarmFadeTimer);
        ticketAlarmFadeTimer = null;
    }
    if (!ticketAlarmAudio) return;
    ticketAlarmAudio.pause();
    ticketAlarmAudio.currentTime = 0;
    ticketAlarmAudio.volume = TICKET_ALARM_VOLUME;
}

function playNewTicketChime() {
    try {
        var audio = getTicketAlarmAudio();

        // A second ticket landing mid-alarm restarts it rather than overlapping
        stopTicketAlarm();
        audio.volume = TICKET_ALARM_VOLUME;
        audio.currentTime = 0;

        var played = audio.play();
        if (played && played['catch']) {
            played['catch'](function(err) {
                console.log('Notification alarm blocked by the browser:', err);
            });
        }

        // Cut the clip short so one ticket does not hold the room for 10 seconds
        if (TICKET_ALARM_MAX_SECONDS > 0) {
            var fadeAt = Math.max(0, TICKET_ALARM_MAX_SECONDS - TICKET_ALARM_FADE_SECONDS);
            ticketAlarmStopTimer = setTimeout(function() {
                var steps = 10;
                var step = 0;
                ticketAlarmFadeTimer = setInterval(function() {
                    step++;
                    var level = TICKET_ALARM_VOLUME * (1 - (step / steps));
                    audio.volume = (level > 0) ? level : 0;
                    if (step >= steps) {
                        stopTicketAlarm();
                    }
                }, (TICKET_ALARM_FADE_SECONDS * 1000) / steps);
            }, fadeAt * 1000);
        }
    } catch (e) {
        console.log('Audio alarm exception:', e);
    }
}

// The Test Sound button plays the alarm exactly as a new ticket does. The
// confirmation waits for it to finish, because a modal dialog blocks the timer
// that fades the clip out and the alarm would run on until it was dismissed.
function testNotificationSound() {
    playNewTicketChime();
    var waitMs = ((TICKET_ALARM_MAX_SECONDS > 0) ? TICKET_ALARM_MAX_SECONDS : 10) * 1000 + 300;
    setTimeout(function() {
        alert('🔔 Notification Sound Test: this is exactly what a new ticket sounds like.');
    }, waitMs);
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
                            // Tickets open in the chat pop-up on the list page
                            var itemLink = isMaint ? 'maintenance.php' : ('tickets.php?open_ticket=' + t.id);
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

                // A client answering an existing ticket deserves the same
                // attention as a brand new one.
                if (res.success && res.latest_client_reply_id > lastKnownClientReplyId) {
                    lastKnownClientReplyId = res.latest_client_reply_id;
                    if (res.latest_client_reply) {
                        handleNewClientReply(res.latest_client_reply);
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
    var targetLink = isMaint ? 'maintenance.php' : ('tickets.php?open_ticket=' + (ticket ? ticket.id : 0));
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

// =========================================================================
// CLIENT REPLIED TO AN EXISTING TICKET
// Same alarm as a new ticket, plus the red unread badge on the listed row so
// the table agrees with what was just heard.
// =========================================================================
function handleNewClientReply(reply) {
    var ticketId = parseInt(reply.ticket_id, 10) || 0;
    if (!ticketId) return;

    // The chat pop-up sounds for the thread it has open, so skip that one here
    var chatIsOpenForThis = (typeof chatTicketId !== 'undefined' && chatTicketId === ticketId &&
        typeof chatBox !== 'undefined' && chatBox && !chatBox.classList.contains('hidden'));
    if (chatIsOpenForThis) return;

    playNewTicketChime();
    bumpTicketUnreadBadge(ticketId);
    showClientReplyToast(reply);
}

// Adds or increments the red badge on a ticket row that is on this page
function bumpTicketUnreadBadge(ticketId) {
    var row = document.querySelector('.ticket-row[data-ticket-id="' + ticketId + '"]');
    if (!row) return;

    var badge = row.querySelector('[data-cell="unread"]');
    var count = badge ? (parseInt(badge.getAttribute('data-unread-count'), 10) || 0) + 1 : 1;

    if (!badge) {
        var numCell = row.querySelector('[data-cell="num"] div') || row.querySelector('[data-cell="num"]');
        if (!numCell) return;
        badge = document.createElement('span');
        badge.setAttribute('data-cell', 'unread');
        badge.className = 'inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full bg-rose-600 text-white text-[9px] font-extrabold shadow-sm shadow-rose-600/30 shrink-0';
        numCell.appendChild(badge);
    }

    badge.setAttribute('data-unread-count', count);
    badge.setAttribute('title', count + ' unread client message' + (count > 1 ? 's' : ''));
    badge.innerHTML = '<span class="w-1.5 h-1.5 rounded-full bg-white animate-pulse"></span>' + count + ' new';
}

function showClientReplyToast(reply) {
    var toast = document.createElement('div');
    toast.className = 'fixed bottom-6 right-6 z-50 bg-slate-900 text-white rounded-3xl p-5 shadow-2xl border border-[#FA5915] max-w-sm w-full space-y-3';

    var link = 'tickets.php?open_ticket=' + parseInt(reply.ticket_id, 10);
    var html = '';
    html += '<div class="flex items-start justify-between gap-3 min-w-0">';
    html += '  <div class="flex items-center space-x-3 min-w-0 flex-1 overflow-hidden">';
    html += '    <div class="w-10 h-10 rounded-2xl bg-[#EB3E0B] text-white flex items-center justify-center font-bold text-lg shrink-0 shadow-md">💬</div>';
    html += '    <div class="min-w-0 flex-1 overflow-hidden">';
    html += '      <span class="text-[10px] font-extrabold uppercase tracking-wider text-[#FEAA73] block truncate">NEW CLIENT MESSAGE</span>';
    html += '      <h4 class="text-sm font-extrabold text-white truncate max-w-full" title="' + escapeHtml(reply.tradename) + '">' + escapeHtml(reply.tradename) + '</h4>';
    html += '    </div>';
    html += '  </div>';
    html += '  <button onclick="this.parentElement.parentElement.remove()" class="text-slate-400 hover:text-white p-1 text-lg font-bold shrink-0">&times;</button>';
    html += '</div>';
    html += '<p class="text-xs text-slate-300 line-clamp-2 bg-slate-800/80 p-2.5 rounded-xl border border-slate-700 font-medium">' + escapeHtml(reply.message) + '</p>';
    html += '<div class="flex items-center justify-between pt-1">';
    html += '  <span class="text-[11px] font-mono text-slate-400">' + escapeHtml(reply.ticket_number) + '</span>';
    html += '  <a href="' + link + '" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs px-4 py-1.5 rounded-full transition-all shadow-sm">Open Chat &rarr;</a>';
    html += '</div>';

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
