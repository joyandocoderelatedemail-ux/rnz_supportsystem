<?php
// POS Unit Maintenance Requests Page (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db_init.php';

require_login();
$client = get_logged_client();
$accountnum = $client['accountnum'];

$pdo = get_db_connection();

$error_msg = '';
$success_msg = '';

if (isset($_GET['created']) && $_GET['created'] === '1') {
    $success_msg = "Your POS maintenance request has been submitted successfully!";
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';

    if ($action === 'create_maintenance_request') {
        $preferred_date  = isset($_POST['preferred_date']) ? trim($_POST['preferred_date']) : '';
        $preferred_time  = isset($_POST['preferred_time']) ? trim($_POST['preferred_time']) : '';
        $units_count     = isset($_POST['units_count']) ? intval($_POST['units_count']) : 1;
        $location_address= isset($_POST['location_address']) ? trim($_POST['location_address']) : '';
        $contact_person  = isset($_POST['contact_person']) ? trim($_POST['contact_person']) : '';
        $contact_number  = isset($_POST['contact_number']) ? trim($_POST['contact_number']) : '';
        $additional_notes= isset($_POST['additional_notes']) ? trim($_POST['additional_notes']) : '';

        if (empty($preferred_date) || empty($preferred_time) || $units_count < 1 || empty($location_address) || empty($contact_person) || empty($contact_number)) {
            $error_msg = "Please fill in all required fields (Date, Time, Units, Location, Contact Person & Number).";
        } else {
            $req_num = 'MNT-' . date('Y') . '-' . sprintf('%05d', rand(1, 99999));
            $now = date('Y-m-d H:i:s');

            $stmt_ins = $pdo->prepare("INSERT INTO client_maintenance_requests 
                (request_number, accountnum, tradename, preferred_date, preferred_time, units_count, location_address, contact_person, contact_number, additional_notes, status, created_at, updated_at) 
                VALUES (:req_num, :acct, :trade, :pref_date, :pref_time, :units, :loc, :c_person, :c_num, :notes, 'Pending', :created, :updated)");
            
            $stmt_ins->execute(array(
                ':req_num'   => $req_num,
                ':acct'      => $accountnum,
                ':trade'     => $client['tradename'],
                ':pref_date' => $preferred_date,
                ':pref_time' => $preferred_time,
                ':units'     => $units_count,
                ':loc'       => $location_address,
                ':c_person'  => $contact_person,
                ':c_num'     => $contact_number,
                ':notes'     => $additional_notes,
                ':created'   => $now,
                ':updated'   => $now
            ));

            header("Location: maintenance.php?created=1");
            exit;
        }
    }
}

// Fetch Client Maintenance Requests
$stmt_requests = $pdo->prepare("SELECT * FROM client_maintenance_requests WHERE accountnum = :acct ORDER BY id DESC");
$stmt_requests->execute(array(':acct' => $accountnum));
$requests = $stmt_requests->fetchAll();

$active_page = 'maintenance';
$page_title = 'POS Unit Maintenance';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo sanitize($page_title); ?> - RNZ Support</title>
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
<body class="bg-[#FFF5ED]/40 text-[#430D07] min-h-screen flex">

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="flex-1 flex flex-col min-w-0">
        <?php include __DIR__ . '/includes/header.php'; ?>

        <main class="p-4 sm:p-6 lg:p-8 pb-24 md:pb-8 max-w-7xl mx-auto w-full space-y-6">

            <!-- Flash Alerts -->
            <?php if (!empty($success_msg)): ?>
                <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs sm:text-sm font-bold flex items-center justify-between">
                    <div class="flex items-center space-x-2">
                        <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        <span><?php echo sanitize($success_msg); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_msg)): ?>
                <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-xs sm:text-sm font-bold flex items-center space-x-2">
                    <svg class="w-5 h-5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span><?php echo sanitize($error_msg); ?></span>
                </div>
            <?php endif; ?>

            <!-- Page Header Card -->
            <div class="bg-white/90 rounded-3xl p-6 sm:p-8 border border-[#FECDAA] shadow-sm flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                <div class="space-y-1">
                    <div class="flex items-center space-x-2">
                        <span class="p-2 rounded-xl bg-[#FFE8D5] text-[#EB3E0B]">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                        </span>
                        <h2 class="text-2xl font-extrabold text-[#430D07]">POS Unit Maintenance & Cleaning</h2>
                    </div>
                    <p class="text-xs sm:text-sm text-[#7C2112]">Schedule on-site preventive maintenance, deep cleaning, and hardware checkup for your Point of Sale units.</p>
                </div>
                <button onclick="openMaintenanceModal()" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-extrabold text-xs sm:text-sm px-5 py-3 rounded-full shadow-md shadow-[#EB3E0B]/25 flex items-center space-x-2 transition-all shrink-0">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                    </svg>
                    <span>Request Maintenance</span>
                </button>
            </div>

            <!-- Maintenance Requests List -->
            <div class="bg-white/90 rounded-3xl p-6 sm:p-8 border border-[#FECDAA] shadow-sm space-y-4">
                <div class="flex items-center justify-between border-b border-[#FFE8D5] pb-4">
                    <h3 class="font-extrabold text-lg text-[#430D07]">Your Maintenance Requests</h3>
                    <span class="text-xs font-bold text-[#9A2512] bg-[#FFE8D5] px-3 py-1 rounded-full border border-[#FECDAA]">
                        Total: <?php echo count($requests); ?>
                    </span>
                </div>

                <?php if (empty($requests)): ?>
                    <div class="text-center py-12 space-y-3">
                        <div class="w-12 h-12 rounded-full bg-[#FFE8D5] text-[#EB3E0B] mx-auto flex items-center justify-center">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                            </svg>
                        </div>
                        <p class="font-bold text-sm text-[#430D07]">No Maintenance Requests Found</p>
                        <p class="text-xs text-[#7C2112]">You haven't requested POS unit maintenance yet. Click Request Maintenance above to schedule your cleaning service.</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead>
                                <tr class="text-[11px] font-extrabold text-[#7C2112] uppercase tracking-wider border-b border-[#FFE8D5]">
                                    <th class="pb-3 px-3">Request #</th>
                                    <th class="pb-3 px-3">Preferred Schedule</th>
                                    <th class="pb-3 px-3">Units</th>
                                    <th class="pb-3 px-3">Contact Person & Phone</th>
                                    <th class="pb-3 px-3">Location Address</th>
                                    <th class="pb-3 px-3">Status</th>
                                    <th class="pb-3 px-3 text-right">Submitted</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#FFE8D5]">
                                <?php foreach ($requests as $req): ?>
                                    <tr class="hover:bg-[#FFF5ED]/60 transition-colors">
                                        <td class="py-4 px-3 font-mono font-bold text-[#EB3E0B]">
                                            <?php echo sanitize($req['request_number']); ?>
                                        </td>
                                        <td class="py-4 px-3 font-medium">
                                            <span class="block font-bold text-[#430D07]"><?php echo format_date($req['preferred_date']); ?></span>
                                            <span class="text-[11px] text-[#7C2112] font-mono"><?php echo sanitize($req['preferred_time']); ?></span>
                                        </td>
                                        <td class="py-4 px-3">
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full font-extrabold bg-[#FFE8D5] text-[#EB3E0B] border border-[#FECDAA]">
                                                <?php echo intval($req['units_count']); ?> Unit(s)
                                            </span>
                                        </td>
                                        <td class="py-4 px-3">
                                            <span class="block font-bold text-[#430D07]"><?php echo sanitize($req['contact_person']); ?></span>
                                            <span class="text-[11px] text-[#7C2112] font-mono"><?php echo sanitize($req['contact_number']); ?></span>
                                        </td>
                                        <td class="py-4 px-3 max-w-[200px] truncate text-[#7C2112]" title="<?php echo sanitize($req['location_address']); ?>">
                                            <?php echo sanitize($req['location_address']); ?>
                                        </td>
                                        <td class="py-4 px-3">
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-[11px] font-bold border <?php echo get_status_badge_class($req['status']); ?>">
                                                <?php echo sanitize($req['status']); ?>
                                            </span>
                                        </td>
                                        <td class="py-4 px-3 text-right font-mono text-[11px] text-[#9A2512]">
                                            <?php echo format_date($req['created_at']); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </main>

        <?php include __DIR__ . '/includes/footer.php'; ?>
    </div>

    <!-- Modal 1: Request Maintenance Form Modal -->
    <div id="maintenanceModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm hidden p-4 overflow-y-auto">
        <div class="bg-white rounded-3xl max-w-xl w-full p-6 sm:p-8 border border-[#FECDAA] shadow-2xl space-y-6 relative my-8">
            <div class="flex items-center justify-between border-b border-[#FFE8D5] pb-4">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-2xl bg-[#EB3E0B] text-white flex items-center justify-center font-bold shadow-md shadow-[#EB3E0B]/20">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-extrabold text-[#430D07]">Request POS Unit Maintenance</h3>
                        <p class="text-xs text-[#7C2112]">Schedule hardware cleaning and preventive maintenance</p>
                    </div>
                </div>
                <button type="button" onclick="closeMaintenanceModal()" class="text-[#9A2512] hover:text-[#430D07] p-1.5 rounded-full hover:bg-[#FFE8D5]">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <form id="maintenanceForm" action="maintenance.php" method="POST" class="space-y-4 text-xs">
                <input type="hidden" name="action" value="create_maintenance_request">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block font-bold text-[#430D07] mb-1">Preferred Date <span class="text-[#EB3E0B]">*</span></label>
                        <input type="date" name="preferred_date" required min="<?php echo date('Y-m-d'); ?>" class="w-full bg-[#FFF5ED] border border-[#FECDAA] rounded-2xl px-4 py-2.5 text-[#430D07] font-medium focus:border-[#EB3E0B] focus:bg-white focus:outline-none">
                    </div>
                    <div>
                        <label class="block font-bold text-[#430D07] mb-1">Preferred Time <span class="text-[#EB3E0B]">*</span></label>
                        <input type="time" name="preferred_time" required class="w-full bg-[#FFF5ED] border border-[#FECDAA] rounded-2xl px-4 py-2.5 text-[#430D07] font-medium focus:border-[#EB3E0B] focus:bg-white focus:outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block font-bold text-[#430D07] mb-1">How Many POS Units? <span class="text-[#EB3E0B]">*</span></label>
                        <input type="number" name="units_count" min="1" max="100" value="1" required class="w-full bg-[#FFF5ED] border border-[#FECDAA] rounded-2xl px-4 py-2.5 text-[#430D07] font-bold focus:border-[#EB3E0B] focus:bg-white focus:outline-none">
                    </div>
                    <div>
                        <label class="block font-bold text-[#430D07] mb-1">Contact Person <span class="text-[#EB3E0B]">*</span></label>
                        <input type="text" name="contact_person" value="" placeholder="Enter Full Name of Contact Person" required class="w-full bg-[#FFF5ED] border border-[#FECDAA] rounded-2xl px-4 py-2.5 text-[#430D07] font-medium focus:border-[#EB3E0B] focus:bg-white focus:outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block font-bold text-[#430D07] mb-1">Contact Phone Number <span class="text-[#EB3E0B]">*</span></label>
                        <input type="text" name="contact_number" value="<?php echo sanitize(isset($client['contactnum']) ? $client['contactnum'] : ''); ?>" required class="w-full bg-[#FFF5ED] border border-[#FECDAA] rounded-2xl px-4 py-2.5 text-[#430D07] font-medium focus:border-[#EB3E0B] focus:bg-white focus:outline-none">
                    </div>
                    <div>
                        <label class="block font-bold text-[#430D07] mb-1">Service Location / Address <span class="text-[#EB3E0B]">*</span></label>
                        <input type="text" name="location_address" value="<?php echo sanitize(isset($client['address']) ? $client['address'] : ''); ?>" required class="w-full bg-[#FFF5ED] border border-[#FECDAA] rounded-2xl px-4 py-2.5 text-[#430D07] font-medium focus:border-[#EB3E0B] focus:bg-white focus:outline-none">
                    </div>
                </div>

                <div>
                    <label class="block font-bold text-[#430D07] mb-1">Additional Instructions or Hardware Notes</label>
                    <textarea name="additional_notes" rows="3" placeholder="Specify any specific POS models, terminal locations, or issues to clean..." class="w-full bg-[#FFF5ED] border border-[#FECDAA] rounded-2xl p-4 text-[#430D07] font-medium focus:border-[#EB3E0B] focus:bg-white focus:outline-none"></textarea>
                </div>

                <div class="pt-4 flex items-center justify-end space-x-3 border-t border-[#FFE8D5]">
                    <button type="button" onclick="closeMaintenanceModal()" class="px-5 py-2.5 rounded-full font-bold text-[#7C2112] hover:bg-[#FFE8D5]">
                        Cancel
                    </button>
                    <button type="button" onclick="triggerFeeNoticeConfirm()" class="px-6 py-2.5 rounded-full font-extrabold bg-[#EB3E0B] hover:bg-[#C32C0B] text-white shadow-md shadow-[#EB3E0B]/25">
                        Submit Maintenance Request
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal 2: Fee Notice & Confirmation Dialog Modal -->
    <div id="feeNoticeModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/70 backdrop-blur-sm hidden p-4">
        <div class="bg-white rounded-3xl max-w-md w-full p-6 sm:p-8 border border-amber-300 shadow-2xl space-y-5 text-center relative">
            <div class="w-16 h-16 rounded-full bg-amber-100 text-amber-600 mx-auto flex items-center justify-center border border-amber-200">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
            <div class="space-y-2">
                <h3 class="text-xl font-extrabold text-[#430D07]">Notice: Service Fee May Apply</h3>
                <p class="text-xs text-[#7C2112] leading-relaxed">
                    Please note that Point of Sale unit maintenance and deep cleaning services <span class="font-extrabold text-[#EB3E0B]">may incur a service fee</span> depending on your active warranty coverage and service agreement.
                </p>
            </div>
            <div class="p-4 rounded-2xl bg-amber-50 border border-amber-200 text-left text-xs space-y-1 text-amber-900 font-medium">
                <p class="font-bold">Confirmation Required:</p>
                <p>Do you wish to proceed with submitting this POS maintenance request?</p>
            </div>
            <div class="flex items-center justify-center space-x-3 pt-2">
                <button type="button" onclick="closeFeeNoticeModal()" class="px-5 py-2.5 rounded-full font-bold text-[#7C2112] hover:bg-[#FFE8D5] text-xs">
                    Cancel
                </button>
                <button type="button" onclick="confirmAndSubmitMaintenanceForm()" class="px-6 py-2.5 rounded-full font-extrabold bg-[#EB3E0B] hover:bg-[#C32C0B] text-white shadow-md shadow-[#EB3E0B]/25 text-xs">
                    Confirm & Submit Request
                </button>
            </div>
        </div>
    </div>

    <script>
    function openMaintenanceModal() {
        var m = document.getElementById('maintenanceModal');
        if (m) m.classList.remove('hidden');
    }
    function closeMaintenanceModal() {
        var m = document.getElementById('maintenanceModal');
        if (m) m.classList.add('hidden');
    }

    function triggerFeeNoticeConfirm() {
        var form = document.getElementById('maintenanceForm');
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }
        var fn = document.getElementById('feeNoticeModal');
        if (fn) fn.classList.remove('hidden');
    }

    function closeFeeNoticeModal() {
        var fn = document.getElementById('feeNoticeModal');
        if (fn) fn.classList.add('hidden');
    }

    function confirmAndSubmitMaintenanceForm() {
        var form = document.getElementById('maintenanceForm');
        if (form) {
            form.submit();
        }
    }
    </script>
</body>
</html>
