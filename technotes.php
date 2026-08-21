<?php
// Client Tech Service History (Strictly Scoped to Logged Client Account)
require_once __DIR__ . '/includes/config.php';

require_login();
$client = get_logged_client();
$accountnum = $client['accountnum'];
$tradename = $client['tradename'];

$pdo = get_db_connection();

$search_q = isset($_GET['q']) ? trim($_GET['q']) : '';

// STRICT ACCOUNT LOCK: Query only matching notes for logged client's accountnum
$sql = "SELECT * FROM bucket_technotes WHERE accountnum = :acct";
$params = array(':acct' => $accountnum);

if (!empty($search_q)) {
    $sql .= " AND (techname LIKE :q OR reasonoftech LIKE :q OR causeoftheissue LIKE :q OR resso LIKE :q OR status LIKE :q)";
    $params[':q'] = '%' . $search_q . '%';
}

$sql .= " ORDER BY id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$technotes = $stmt->fetchAll();

$active_page = 'technotes';
$page_title = 'Tech Service History';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tech Service History - RNZ Client Portal</title>
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

        <main class="p-4 sm:p-6 md:p-8 pb-24 md:pb-8 space-y-6 max-w-7xl w-full mx-auto">

            <!-- Client Account Header Card -->
            <div class="bg-white/90 rounded-3xl p-6 sm:p-8 border border-[#FECDAA] shadow-sm flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                <div>
                    <div class="inline-flex items-center space-x-2 bg-[#FFE8D5] px-3 py-1 rounded-full text-[#EB3E0B] text-xs font-bold font-mono mb-2">
                        <span>Account #<?php echo sanitize($accountnum); ?></span>
                    </div>
                    <h2 class="text-xl sm:text-2xl font-extrabold text-[#430D07]"><?php echo sanitize($tradename); ?></h2>
                    <p class="text-xs text-[#7C2112] mt-1">Technician visit history, hardware diagnostic notes, and issue resolutions.</p>
                </div>

                <!-- Search Within Client Notes Form -->
                <form action="technotes.php" method="GET" class="w-full sm:w-80">
                    <div class="relative">
                        <input type="text" name="q" value="<?php echo sanitize($search_q); ?>" placeholder="Search within your visit notes..." class="w-full bg-[#FFF5ED] text-[#430D07] text-xs pl-10 pr-10 py-3 rounded-2xl border border-[#FECDAA] focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all placeholder-[#9A2512]/60 font-medium">
                        <svg class="w-4 h-4 text-[#9A2512] absolute left-3.5 top-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        <?php if (!empty($search_q)): ?>
                            <a href="technotes.php" class="absolute right-3 top-3 text-[#9A2512] hover:text-[#430D07] text-xs font-bold">
                                &times;
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Notes Table Card -->
            <div class="bg-white/90 rounded-3xl p-6 sm:p-8 border border-[#FECDAA] shadow-sm overflow-hidden space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-base font-extrabold text-[#430D07]">Technical Service Notes History</h3>
                    <span class="text-xs font-bold text-[#7C2112]">
                        (<?php echo count($technotes); ?> record<?php echo count($technotes) == 1 ? '' : 's'; ?> found)
                    </span>
                </div>

                <?php if (empty($technotes)): ?>
                    <div class="p-12 text-center space-y-3">
                        <div class="w-16 h-16 rounded-full bg-[#FFE8D5] text-[#EB3E0B] flex items-center justify-center mx-auto">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                        </div>
                        <h4 class="text-base font-extrabold text-[#430D07]">No Service History Records</h4>
                        <p class="text-xs text-[#7C2112] max-w-sm mx-auto">
                            <?php if (!empty($search_q)): ?>
                                No service notes matched your search term "<?php echo sanitize($search_q); ?>".
                            <?php else: ?>
                                There are currently no recorded technician service visit notes for your account.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs sm:text-sm">
                            <thead>
                                <tr class="bg-[#FFF5ED] border-b border-[#FECDAA] text-[#7C2112] font-bold uppercase tracking-wider text-[11px]">
                                    <th class="py-3.5 px-4">ID / Date</th>
                                    <th class="py-3.5 px-4">Technician</th>
                                    <th class="py-3.5 px-4">Status</th>
                                    <th class="py-3.5 px-4 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#FFE8D5] font-medium text-[#430D07]">
                                <?php foreach ($technotes as $note): 
                                    $status_class = ($note['status'] === 'Done' || $note['status'] === 'Resolved' || $note['status'] === 'Closed') ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-amber-50 text-amber-700 border-amber-200';
                                ?>
                                    <tr class="hover:bg-[#FFF5ED]/80 transition-colors">
                                        <td class="py-3.5 px-4 space-y-0.5">
                                            <div class="font-mono text-[#9A2512]">#<?php echo $note['id']; ?></div>
                                            <div class="font-bold text-[#430D07] text-xs"><?php echo sanitize($note['xdate']); ?></div>
                                        </td>
                                        <td class="py-3.5 px-4 font-bold text-[#EB3E0B]"><?php echo sanitize($note['techname']); ?></td>
                                        <td class="py-3.5 px-4">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold border <?php echo $status_class; ?>">
                                                <?php echo sanitize($note['status']); ?>
                                            </span>
                                        </td>
                                        <td class="py-3.5 px-4 text-right">
                                            <div class="flex items-center justify-end space-x-1.5">
                                                <button data-note="<?php echo htmlspecialchars(json_encode($note), ENT_QUOTES, 'UTF-8'); ?>"
                                                        onclick="openClientNoteModal(this)" 
                                                        class="bg-[#FFE8D5] hover:bg-[#EB3E0B] text-[#430D07] hover:text-white font-bold text-xs px-3.5 py-1.5 rounded-full inline-flex items-center space-x-1.5 transition-all shadow-sm">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                                    </svg>
                                                    <span>View</span>
                                                </button>
                                                <a href="print_document.php?type=technote&id=<?php echo $note['id']; ?>&autoprint=1" target="_blank" 
                                                   class="p-1.5 rounded-full bg-white hover:bg-[#FFE8D5] text-[#7C2112] hover:text-[#EB3E0B] border border-[#FECDAA] inline-flex items-center space-x-1 transition-colors shadow-xs" title="Print / Download Service Note PDF">
                                                    <svg class="w-3.5 h-3.5 text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                                                    </svg>
                                                    <span class="text-[11px] font-bold">Print</span>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>
</div>

<!-- CLIENT VIEW SERVICE NOTE DETAILS MODAL -->
<div id="clientNoteModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 hidden">
    <div class="bg-white rounded-3xl max-w-xl w-full p-6 sm:p-8 shadow-2xl border border-[#FECDAA] relative animate-fadeIn max-h-[90vh] overflow-y-auto space-y-6">
        <!-- Close Button -->
        <button onclick="closeClientNoteModal()" class="absolute top-6 right-6 text-[#9A2512] hover:text-[#430D07] p-2 rounded-full hover:bg-[#FFE8D5] transition-colors">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>

        <div class="flex items-center space-x-3 border-b border-[#FFE8D5] pb-4">
            <div class="w-12 h-12 rounded-2xl bg-[#FFE8D5] text-[#EB3E0B] flex items-center justify-center font-bold shadow-sm shrink-0">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <div>
                <div class="flex items-center space-x-2">
                    <span id="c_note_id" class="text-xs font-mono font-bold text-[#EB3E0B]">#000</span>
                    <span id="c_note_date" class="text-xs text-[#7C2112] font-mono">Date</span>
                </div>
                <h3 class="text-lg font-extrabold text-[#430D07]">Technical Service Visit Details</h3>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4 text-xs">
            <div class="p-3.5 rounded-2xl bg-[#FFF5ED] border border-[#FECDAA]">
                <span class="block text-[#7C2112] font-bold uppercase text-[10px]">Assigned Technician</span>
                <p id="c_note_tech" class="font-extrabold text-[#EB3E0B] text-sm mt-0.5">-</p>
            </div>
            <div class="p-3.5 rounded-2xl bg-[#FFF5ED] border border-[#FECDAA]">
                <span class="block text-[#7C2112] font-bold uppercase text-[10px]">Visit Status</span>
                <div id="c_note_status" class="font-bold text-[#430D07] text-sm mt-0.5">-</div>
            </div>
        </div>

        <div class="space-y-4 text-xs">
            <div class="p-4 rounded-2xl bg-[#FFF5ED] border border-[#FECDAA] space-y-1">
                <span class="block text-[#EB3E0B] font-bold uppercase text-[10px] tracking-wider">Reason for Visit / Reported Issue</span>
                <p id="c_note_reason" class="font-semibold text-[#430D07] leading-relaxed whitespace-pre-wrap text-xs sm:text-sm">-</p>
            </div>

            <div class="p-4 rounded-2xl bg-[#FFF5ED] border border-[#FECDAA] space-y-1">
                <span class="block text-amber-800 font-bold uppercase text-[10px] tracking-wider">Cause of the Issue</span>
                <p id="c_note_cause" class="font-medium text-[#430D07] leading-relaxed whitespace-pre-wrap text-xs sm:text-sm">-</p>
            </div>

            <div class="p-4 rounded-2xl bg-emerald-50/80 border border-emerald-200 space-y-1">
                <span class="block text-emerald-800 font-bold uppercase text-[10px] tracking-wider">Resolution / Technician Solution</span>
                <p id="c_note_resso" class="font-semibold text-emerald-950 leading-relaxed whitespace-pre-wrap text-xs sm:text-sm">-</p>
            </div>
        </div>

        <div class="pt-3 flex items-center justify-between border-t border-[#FFE8D5]">
            <a id="c_note_print_btn" href="#" target="_blank" class="bg-white hover:bg-[#FFE8D5] text-[#7C2112] hover:text-[#EB3E0B] border border-[#FECDAA] font-bold text-xs px-4 py-2.5 rounded-full shadow-xs flex items-center space-x-1.5 transition-all">
                <svg class="w-4 h-4 text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                </svg>
                <span>Print PDF</span>
            </a>
            <button onclick="closeClientNoteModal()" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs px-6 py-2.5 rounded-full shadow-md">
                Close Details
            </button>
        </div>
    </div>
</div>

<script>
function openClientNoteModal(btn) {
    var note = JSON.parse(btn.getAttribute('data-note'));
    document.getElementById('c_note_id').innerText = '#' + note.id;
    document.getElementById('c_note_date').innerText = note.xdate;
    document.getElementById('c_note_tech').innerText = note.techname;
    document.getElementById('c_note_status').innerText = note.status;
    document.getElementById('c_note_reason').innerText = note.reasonoftech ? note.reasonoftech : 'N/A';
    document.getElementById('c_note_cause').innerText = note.causeoftheissue ? note.causeoftheissue : 'N/A';
    document.getElementById('c_note_resso').innerText = note.resso ? note.resso : 'N/A';
    
    var printBtn = document.getElementById('c_note_print_btn');
    if (printBtn && note.id) {
        printBtn.href = 'print_document.php?type=technote&id=' + encodeURIComponent(note.id) + '&autoprint=1';
    }
    
    document.getElementById('clientNoteModal').classList.remove('hidden');
}

function closeClientNoteModal() {
    document.getElementById('clientNoteModal').classList.add('hidden');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
