<?php
// Document Print & PDF Generator for Support Center Backend (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

require_tech_login();

$pdo = get_db_connection();
if (!$pdo) {
    die("Database connection error.");
}

$doc_type = isset($_GET['type']) ? trim($_GET['type']) : '';
$doc_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$autoprint = isset($_GET['autoprint']) && $_GET['autoprint'] == '1';

if (empty($doc_type) || $doc_id <= 0) {
    die("Invalid document type or ID.");
}

/**
 * Helper to resolve technician / admin full name from table `user`
 */
function resolve_tech_fullname($pdo, $tech_identifier, $fallback) {
    $tech_identifier = trim($tech_identifier);
    if (empty($tech_identifier)) {
        return $fallback;
    }
    
    $lower = strtolower($tech_identifier);
    if ($lower === 'rnz support specialist' || $lower === 'rnz support staff' || $lower === 'rnz field technician' || $lower === 'rnz diagnostic system' || $lower === 'rnz inventory specialist' || $lower === 'rnz asset custodian') {
        return $fallback;
    }

    try {
        $stmt = $pdo->prepare("SELECT fname, lname FROM user WHERE LOWER(TRIM(user)) = LOWER(:u) OR LOWER(TRIM(fname)) = LOWER(:u) OR LOWER(TRIM(CONCAT(fname, ' ', lname))) = LOWER(:u) LIMIT 1");
        $stmt->execute(array(':u' => $tech_identifier));
        $row = $stmt->fetch();
        if ($row) {
            $full = trim($row['fname'] . ' ' . $row['lname']);
            if (!empty($full)) {
                return $full;
            }
        }
    } catch (PDOException $e) {}
    
    return $tech_identifier;
}

$logged_tech = get_logged_tech();
$admin_name = '';
if ($logged_tech) {
    $admin_name = !empty($logged_tech['fullname']) ? $logged_tech['fullname'] : (!empty($logged_tech['fname']) ? trim($logged_tech['fname'] . ' ' . $logged_tech['lname']) : $logged_tech['user']);
}
if (empty($admin_name)) {
    try {
        $stmt_adm = $pdo->query("SELECT fname, lname FROM user WHERE accesslevel = 'master' OR accesslevel = 'admin' ORDER BY id ASC LIMIT 1");
        $adm_row = $stmt_adm->fetch();
        if ($adm_row) {
            $admin_name = trim($adm_row['fname'] . ' ' . $adm_row['lname']);
        }
    } catch (PDOException $e) {}
}
if (empty($admin_name)) {
    $admin_name = 'Rabbi Zamora';
}

$doc_title = 'Document';
$doc_subtitle = 'Official Business & Technical Support Document';
$doc_ref = '';
$doc_date = date('Y-m-d');
$client_acct = '';
$doc_status = 'Completed';
$tech_name = $admin_name;
$data = null;
$client = null;

// 1. Fetch document based on type
if ($doc_type === 'workorder') {
    $stmt = $pdo->prepare("SELECT * FROM bucket_workorder WHERE id = :id LIMIT 1");
    $stmt->execute(array(':id' => $doc_id));
    $data = $stmt->fetch();

    if (!$data) {
        die("Work Order #WO-$doc_id not found.");
    }

    $doc_title = 'WORK ORDER & BILLING INVOICE';
    $doc_subtitle = 'Official Technical Service, Maintenance & Billing Statement';
    $doc_ref = 'WO-' . str_pad($data['id'], 6, '0', STR_PAD_LEFT);
    $doc_date = !empty($data['xdate']) ? $data['xdate'] : date('Y-m-d');
    $client_acct = $data['accountnum'];
    $doc_status = !empty($data['status']) ? ucfirst($data['status']) : 'Pending';
    $tech_name = $admin_name;

} elseif ($doc_type === 'technote' || $doc_type === 'notes') {
    $stmt = $pdo->prepare("SELECT * FROM bucket_technotes WHERE id = :id LIMIT 1");
    $stmt->execute(array(':id' => $doc_id));
    $data = $stmt->fetch();

    if (!$data) {
        die("Technical Service Note #$doc_id not found.");
    }

    $doc_title = 'TECHNICAL SERVICE REPORT';
    $doc_subtitle = 'Field Visit Log, Diagnostic Findings & Solutions Rendered';
    $doc_ref = 'TSN-' . str_pad($data['id'], 6, '0', STR_PAD_LEFT);
    $doc_date = !empty($data['xdate']) ? $data['xdate'] : date('Y-m-d');
    $client_acct = $data['accountnum'];
    $doc_status = !empty($data['status']) ? ucfirst($data['status']) : 'Done';
    $tech_name = resolve_tech_fullname($pdo, !empty($data['techname']) ? $data['techname'] : '', $admin_name);

} elseif ($doc_type === 'pullout') {
    $stmt = $pdo->prepare("SELECT l.*, i.name as item_name, i.item_code, i.category, i.selling_price 
        FROM support_inventory_logs l 
        LEFT JOIN support_inventory_items i ON l.item_id = i.id 
        WHERE l.id = :id LIMIT 1");
    $stmt->execute(array(':id' => $doc_id));
    $data = $stmt->fetch();

    if (!$data) {
        die("Pull-Out / Movement Record #$doc_id not found.");
    }

    $doc_title = 'EQUIPMENT PULL-OUT & DELIVERY RECEIPT';
    $doc_subtitle = 'Official Inventory Movement, Retrieval & Custody Slip';
    $doc_ref = 'PO-' . str_pad($data['id'], 6, '0', STR_PAD_LEFT);
    $doc_date = !empty($data['created_at']) ? date('Y-m-d', strtotime($data['created_at'])) : date('Y-m-d');
    $client_acct = $data['accountnum'];
    $doc_status = !empty($data['change_type']) ? $data['change_type'] : 'Pull-Out';
    $tech_name = resolve_tech_fullname($pdo, !empty($data['tech_name']) ? $data['tech_name'] : '', $admin_name);

} elseif ($doc_type === 'log') {
    $stmt = $pdo->prepare("SELECT * FROM hardware_troubleshooting_logs WHERE id = :id LIMIT 1");
    $stmt->execute(array(':id' => $doc_id));
    $data = $stmt->fetch();

    if (!$data) {
        die("Diagnostic Log #$doc_id not found.");
    }

    $doc_title = 'HARDWARE TROUBLESHOOTING REPORT';
    $doc_subtitle = 'Diagnostic Trail, Guided Step Results & System Log';
    $doc_ref = 'DIAG-' . str_pad($data['id'], 6, '0', STR_PAD_LEFT);
    $doc_date = !empty($data['created_at']) ? date('Y-m-d', strtotime($data['created_at'])) : date('Y-m-d');
    $client_acct = $data['accountnum'];
    $doc_status = !empty($data['resolution_status']) ? ucfirst($data['resolution_status']) : 'Completed';
    $tech_name = $admin_name;

} elseif ($doc_type === 'asset') {
    $stmt = $pdo->prepare("SELECT * FROM client_assets WHERE id = :id LIMIT 1");
    $stmt->execute(array(':id' => $doc_id));
    $data = $stmt->fetch();

    if (!$data) {
        die("Client Asset Record #$doc_id not found.");
    }

    $doc_title = 'CLIENT EQUIPMENT CERTIFICATE';
    $doc_subtitle = 'Registered Hardware Asset, Warranty Scope & Maintenance Record';
    $doc_ref = 'AST-' . str_pad($data['id'], 6, '0', STR_PAD_LEFT);
    $doc_date = !empty($data['created_at']) ? date('Y-m-d', strtotime($data['created_at'])) : date('Y-m-d');
    $client_acct = $data['accountnum'];
    $doc_status = !empty($data['warranty_status']) ? $data['warranty_status'] : 'Active';
    $tech_name = resolve_tech_fullname($pdo, !empty($data['recorded_by']) ? $data['recorded_by'] : '', $admin_name);

} else {
    die("Unsupported document type.");
}

// 2. Fetch Client Info
if (!empty($client_acct)) {
    $stmt_c = $pdo->prepare("SELECT * FROM bucket_client WHERE accountnum = :acct LIMIT 1");
    $stmt_c->execute(array(':acct' => $client_acct));
    $client = $stmt_c->fetch();
}

if ($doc_type === 'workorder') {
    $client_tradename = !empty($data['clientname']) ? $data['clientname'] : (!empty($client['tradename']) ? $client['tradename'] : (!empty($client['clientname']) ? $client['clientname'] : 'N/A'));
    $client_owner = !empty($client['clientname']) ? $client['clientname'] : (!empty($data['clientname']) ? $data['clientname'] : 'N/A');
    $client_address = !empty($data['address']) ? $data['address'] : (!empty($client['address']) ? $client['address'] : (!empty($data['xaddress']) ? $data['xaddress'] : 'N/A'));
} else {
    $client_tradename = !empty($client['tradename']) ? $client['tradename'] : (!empty($data['tradename']) ? $data['tradename'] : (!empty($data['clientname']) ? $data['clientname'] : 'N/A'));
    $client_owner = !empty($client['clientname']) ? $client['clientname'] : (!empty($data['clientname']) ? $data['clientname'] : 'N/A');
    $client_address = !empty($client['address']) ? $client['address'] : (!empty($data['address']) ? $data['address'] : (!empty($data['xaddress']) ? $data['xaddress'] : 'N/A'));
}
$client_contact = (!empty($client['contactnum']) && strtoupper(trim($client['contactnum'])) !== 'NA') ? $client['contactnum'] : '—';
$client_email = (!empty($client['emailaddress']) && strtoupper(trim($client['emailaddress'])) !== 'NA') ? $client['emailaddress'] : '—';
$client_warranty_status = !empty($client['warranty_status']) ? $client['warranty_status'] : 'Inactive';

$is_paid = (strtolower(trim($doc_status)) === 'paid');
$is_unpaid = (strtolower(trim($doc_status)) === 'unpaid' || strtolower(trim($doc_status)) === 'pending');
$ornum_val = !empty($data['ornum']) ? trim($data['ornum']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo sanitize($doc_ref . ' - ' . $doc_title); ?></title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <!-- html2pdf.js for 1-click direct PDF file downloads -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        .font-mono {
            font-family: 'JetBrains Mono', monospace;
        }
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background: #ffffff !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .print-page {
                box-shadow: none !important;
                border: none !important;
                border-radius: 0 !important;
                margin: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                padding: 0 !important;
            }
            @page {
                margin: 0.3in 0.5in 0.3in 0.5in;
                size: A4 portrait;
            }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 antialiased p-3 sm:p-6 min-h-screen flex flex-col items-center justify-start">

    <!-- Top Action Bar (Hidden in Print & PDF) -->
    <div class="no-print w-full max-w-4xl mb-4 flex flex-col sm:flex-row items-center justify-between gap-3 bg-slate-900 text-white p-3.5 sm:p-4 rounded-2xl shadow-xl border border-slate-800">
        <div class="flex items-center space-x-3">
            <img src="rnzlogo.png" alt="RNZ Logo" class="w-9 h-9 rounded-full bg-black p-1 object-contain shrink-0">
            <div>
                <h2 class="text-xs sm:text-sm font-extrabold text-white flex items-center gap-2">
                    <span><?php echo sanitize($doc_ref); ?></span>
                    <span class="text-slate-500">&bull;</span>
                    <span class="text-slate-200"><?php echo sanitize($client_tradename); ?></span>
                </h2>
                <p class="text-[11px] text-slate-400">Account #<?php echo sanitize($client_acct); ?> &bull; <?php echo sanitize($doc_title); ?></p>
            </div>
        </div>

        <div class="flex items-center space-x-2 w-full sm:w-auto justify-end">
            <button type="button" onclick="downloadAsPDF()" class="bg-indigo-600 hover:bg-indigo-700 active:scale-95 text-white font-bold text-xs px-3.5 py-2 rounded-xl shadow-sm flex items-center space-x-1.5 transition-all">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                <span>Download PDF</span>
            </button>
            <button type="button" onclick="window.print()" class="bg-[#EB3E0B] hover:bg-[#C32C0B] active:scale-95 text-white font-bold text-xs px-4 py-2 rounded-xl shadow-sm flex items-center space-x-1.5 transition-all">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                </svg>
                <span>Print Document</span>
            </button>
            <button type="button" onclick="window.close()" class="bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white font-bold text-xs px-3 py-2 rounded-xl transition-all">
                Close
            </button>
        </div>
    </div>

    <!-- Printable Paper Sheet Container (0.3in Top/Bottom, 0.5in Sides) -->
    <div id="printDocumentCard" class="print-page bg-white rounded-2xl p-4 sm:py-[0.3in] sm:px-[0.5in] border border-slate-200 shadow-xl max-w-4xl w-full text-slate-800 space-y-5">
        
        <!-- Header: Company Info + Document Identification -->
        <div class="flex flex-row items-start justify-between border-b-2 border-slate-900 pb-4 gap-4">
            
            <!-- Left: RNZ Corporate Header -->
            <div class="space-y-1">
                <div class="flex items-center space-x-3">
                    <img src="rnzlogo.png" alt="RNZ Logo" class="w-12 h-12 rounded-full bg-black p-1.5 object-contain shrink-0 shadow-xs">
                    <div>
                        <h1 class="text-base sm:text-lg font-black tracking-tight text-slate-900 uppercase leading-none">
                            RNZ SOFTWARE DEVELOPMENT SERVICES
                        </h1>
                        <p class="text-[9px] sm:text-[10px] font-bold text-[#EB3E0B] tracking-wider uppercase mt-0.5">
                            POS Systems &bull; Hardware Maintenance &bull; Technical Support
                        </p>
                    </div>
                </div>
                <div class="text-[11px] text-slate-500 pt-1 leading-snug space-y-0.5">
                    <p class="font-medium">Technical Support Hotline: <strong class="text-slate-700 font-mono">09614694238</strong> &bull; Email: <strong class="text-slate-700">support@rnzsoftware.com</strong></p>
                    <p class="text-[10px] text-slate-400">Website: rnzsoftware.com &bull; Official Customer Service Document</p>
                </div>
            </div>

            <!-- Right: Document Type & Reference Code -->
            <div class="text-right shrink-0 space-y-1">
                <div class="inline-block bg-slate-900 text-white rounded-lg px-3 py-1 font-mono text-xs sm:text-sm font-bold tracking-wider shadow-xs">
                    <?php echo sanitize($doc_ref); ?>
                </div>
                <div class="text-[11px] text-slate-600 font-mono">Date: <strong class="text-slate-900"><?php echo format_date_only($doc_date); ?></strong></div>
                <div>
                    <?php if ($is_paid): ?>
                        <span class="inline-flex items-center gap-1 bg-emerald-100 text-emerald-800 text-[10px] font-bold px-2 py-0.5 rounded-md border border-emerald-300">
                            <svg class="w-3 h-3 text-emerald-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                            PAID <?php echo !empty($ornum_val) ? '&bull; OR #' . sanitize($ornum_val) : ''; ?>
                        </span>
                    <?php elseif ($is_unpaid): ?>
                        <span class="inline-flex items-center gap-1 bg-amber-100 text-amber-900 text-[10px] font-bold px-2 py-0.5 rounded-md border border-amber-300">
                            <svg class="w-3 h-3 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke-width="2"/><polyline points="12 6 12 12 16 14" stroke-width="2"/></svg>
                            PAYMENT PENDING
                        </span>
                    <?php else: ?>
                        <span class="inline-flex items-center bg-slate-100 text-slate-800 text-[10px] font-bold px-2 py-0.5 rounded-md border border-slate-300">
                            <?php echo strtoupper(sanitize($doc_status)); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Document Headline Banner -->
        <div class="flex items-center justify-between bg-[#FFF5ED] border-l-4 border-[#EB3E0B] py-2 px-4 rounded-r-xl">
            <div>
                <h2 class="text-xs sm:text-sm font-extrabold text-[#430D07] tracking-wider uppercase">
                    <?php echo sanitize($doc_title); ?>
                </h2>
                <p class="text-[10px] text-[#9A2512] font-semibold"><?php echo sanitize($doc_subtitle); ?></p>
            </div>
            <span class="text-[10px] font-mono font-bold text-[#EB3E0B] uppercase">Ref: <?php echo sanitize($doc_ref); ?></span>
        </div>

        <!-- Two-Column Information Box: Client & Service Details -->
        <div class="grid grid-cols-2 gap-4 p-4 rounded-xl bg-slate-50 border border-slate-200 text-xs">
            
            <!-- Left: Client Information -->
            <div class="space-y-1.5 pr-2 border-r border-slate-200">
                <span class="font-extrabold uppercase tracking-wider text-[10px] text-[#EB3E0B] flex items-center gap-1 border-b border-slate-200 pb-1">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                    Client &amp; Billing Particulars
                </span>
                
                <div>
                    <span class="text-slate-400 block text-[9px] font-bold uppercase">Trade / Business Name</span>
                    <span class="font-extrabold text-slate-900 text-xs sm:text-sm leading-tight block"><?php echo sanitize($client_tradename); ?></span>
                </div>

                <div class="grid grid-cols-2 gap-2 pt-0.5">
                    <div>
                        <span class="text-slate-400 block text-[9px] font-bold uppercase">Account No.</span>
                        <span class="font-mono font-bold text-slate-900 text-xs">#<?php echo sanitize($client_acct); ?></span>
                    </div>
                    <div>
                        <span class="text-slate-400 block text-[9px] font-bold uppercase">Contact Person / Owner</span>
                        <span class="font-semibold text-slate-800 text-xs truncate block"><?php echo sanitize($client_owner); ?></span>
                    </div>
                </div>

                <div class="pt-0.5">
                    <span class="text-slate-400 block text-[9px] font-bold uppercase">Service Location / Address</span>
                    <span class="font-medium text-slate-700 text-xs leading-tight block"><?php echo sanitize($client_address); ?></span>
                </div>

                <?php if ($client_contact !== '—' || $client_email !== '—'): ?>
                    <div class="grid grid-cols-2 gap-2 pt-0.5 text-[11px]">
                        <div>
                            <span class="text-slate-400 block text-[9px] font-bold uppercase">Contact No.</span>
                            <span class="font-mono text-slate-700 text-[11px]"><?php echo sanitize($client_contact); ?></span>
                        </div>
                        <div>
                            <span class="text-slate-400 block text-[9px] font-bold uppercase">Email</span>
                            <span class="text-slate-700 text-[11px] truncate block"><?php echo sanitize($client_email); ?></span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right: Document Metadata & Service Info -->
            <div class="space-y-1.5 pl-2">
                <span class="font-extrabold uppercase tracking-wider text-[10px] text-[#EB3E0B] flex items-center gap-1 border-b border-slate-200 pb-1">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Service Order Specifications
                </span>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <span class="text-slate-400 block text-[9px] font-bold uppercase">Order Reference</span>
                        <span class="font-mono font-bold text-[#EB3E0B] text-xs"><?php echo sanitize($doc_ref); ?></span>
                    </div>
                    <div>
                        <span class="text-slate-400 block text-[9px] font-bold uppercase">Issue / Record Date</span>
                        <span class="font-mono font-semibold text-slate-900 text-xs"><?php echo format_date_only($doc_date); ?></span>
                    </div>
                </div>

                <div class="pt-0.5">
                    <span class="text-slate-400 block text-[9px] font-bold uppercase">Attending Technician / Representative</span>
                    <span class="font-bold text-slate-900 text-xs block"><?php echo sanitize($tech_name); ?></span>
                </div>

                <div class="grid grid-cols-2 gap-2 pt-0.5">
                    <div>
                        <span class="text-slate-400 block text-[9px] font-bold uppercase">Payment Status</span>
                        <span class="font-bold text-xs <?php echo $is_paid ? 'text-emerald-700' : ($is_unpaid ? 'text-amber-700' : 'text-slate-800'); ?>">
                            <?php echo sanitize($doc_status); ?>
                        </span>
                    </div>
                    <div>
                        <span class="text-slate-400 block text-[9px] font-bold uppercase">Official Receipt #</span>
                        <span class="font-mono font-bold text-slate-800 text-xs">
                            <?php echo !empty($ornum_val) ? sanitize($ornum_val) : '<span class="text-slate-400 font-normal">Pending</span>'; ?>
                        </span>
                    </div>
                </div>

                <div class="pt-0.5">
                    <span class="text-slate-400 block text-[9px] font-bold uppercase">Client Maintenance Warranty</span>
                    <span class="font-semibold text-slate-700 text-xs"><?php echo sanitize($client_warranty_status); ?> Coverage</span>
                </div>
            </div>
        </div>

        <!-- SPECIFIC CONTENT SECTIONS PER DOCUMENT TYPE -->

        <!-- 1. WORK ORDER BODY -->
        <?php if ($doc_type === 'workorder'): ?>
            <div class="space-y-3">
                <div class="flex items-center justify-between border-b-2 border-slate-800 pb-1.5">
                    <h3 class="font-extrabold text-xs sm:text-sm text-slate-900 uppercase tracking-wider">
                        Itemized Scope of Work &amp; Service Statement
                    </h3>
                    <span class="text-[10px] text-slate-500 font-mono">Currency: Philippine Peso (PHP)</span>
                </div>

                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-slate-100 border-y border-slate-300 text-slate-700 font-bold uppercase text-[10px]">
                            <th class="py-2.5 px-3 w-12 text-center">Item</th>
                            <th class="py-2.5 px-3">Scope / Nature of Work &amp; Service Description</th>
                            <th class="py-2.5 px-3 text-center w-28">Receipt / Ref</th>
                            <th class="py-2.5 px-3 text-right w-36">Amount (PHP)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <tr class="bg-white">
                            <td class="py-3.5 px-3 font-mono font-bold text-slate-400 text-center align-top">01</td>
                            <td class="py-3.5 px-3 text-slate-800 leading-relaxed align-top">
                                <span class="block text-slate-900 font-bold text-xs sm:text-sm mb-1 uppercase tracking-wide">
                                    <?php echo sanitize($data['natureofwork']); ?>
                                </span>
                                <span class="text-slate-500 text-[11px] leading-tight block">
                                    Technical service, system configuration, hardware adjustment, and maintenance authorized for Account #<?php echo sanitize($client_acct); ?>.
                                </span>
                            </td>
                            <td class="py-3.5 px-3 text-center font-mono font-semibold text-slate-700 align-top">
                                <?php if (!empty($data['ornum'])): ?>
                                    <span class="inline-block bg-slate-100 text-slate-800 text-[11px] font-mono px-2 py-0.5 rounded border border-slate-300">
                                        OR #<?php echo sanitize($data['ornum']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-slate-400 text-[11px] italic">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3.5 px-3 text-right font-mono font-extrabold text-slate-900 text-sm align-top">
                                &#8369;<?php echo number_format(floatval($data['amount']), 2); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Summary Breakdown & Payment Notes Box -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-1 items-start">
                    
                    <!-- Left: Terms & Service Acknowledgment -->
                    <div class="p-3 rounded-xl bg-slate-50 border border-slate-200 text-[10px] text-slate-600 space-y-1">
                        <span class="font-bold uppercase tracking-wider text-slate-700 block text-[10px]">Service Terms &amp; Quality Guarantee:</span>
                        <p class="leading-relaxed">
                            All technical services, installations, and system maintenance conducted by RNZ Business Solutions are verified and tested operational under standard service guidelines.
                        </p>
                        <?php if ($is_paid): ?>
                            <p class="text-emerald-700 font-bold pt-0.5">
                                &bull; Payment successfully received and settled. Official Receipt: #<?php echo sanitize($ornum_val); ?>
                            </p>
                        <?php else: ?>
                            <p class="text-amber-800 font-medium pt-0.5">
                                &bull; Payment is due upon completion or delivery of services. Please request an Official Receipt upon settlement.
                            </p>
                        <?php endif; ?>
                    </div>

                    <!-- Right: Financial Grand Total Box -->
                    <div class="p-3.5 rounded-xl bg-slate-900 text-white space-y-2">
                        <div class="flex justify-between items-center text-[11px] text-slate-300 pb-1 border-b border-slate-800">
                            <span>Service Subtotal:</span>
                            <span class="font-mono font-semibold">&#8369;<?php echo number_format(floatval($data['amount']), 2); ?></span>
                        </div>
                        <div class="flex justify-between items-center text-[11px] text-slate-400 pb-1 border-b border-slate-800">
                            <span>Taxes / Adjustments:</span>
                            <span class="font-mono">&#8369;0.00</span>
                        </div>
                        <div class="flex justify-between items-center pt-0.5">
                            <div>
                                <span class="text-xs font-black uppercase tracking-wider text-white block">TOTAL BILLED AMOUNT</span>
                                <span class="text-[9px] text-slate-400">Payment Status: <?php echo sanitize($doc_status); ?></span>
                            </div>
                            <span class="font-mono text-base sm:text-lg font-black text-[#FA5915]">
                                &#8369;<?php echo number_format(floatval($data['amount']), 2); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

        <!-- 2. TECHNICAL SERVICE NOTE BODY -->
        <?php elseif ($doc_type === 'technote' || $doc_type === 'notes'): ?>
            <div class="space-y-3">
                <h3 class="font-extrabold text-xs sm:text-sm text-slate-900 uppercase tracking-wider border-b-2 border-slate-800 pb-1.5">
                    Field Service Report &amp; Diagnostic Details
                </h3>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                    <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-200 space-y-1">
                        <span class="text-[10px] font-bold text-[#EB3E0B] uppercase tracking-wider block">1. Client Concern / Reason of Visit:</span>
                        <p class="text-xs text-slate-800 font-semibold leading-relaxed whitespace-pre-wrap">
                            <?php echo !empty($data['reasonoftech']) ? sanitize($data['reasonoftech']) : 'Regular maintenance check and client service visit.'; ?>
                        </p>
                    </div>

                    <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-200 space-y-1">
                        <span class="text-[10px] font-bold text-emerald-700 uppercase tracking-wider block">2. Findings &amp; Technical Solution Rendered:</span>
                        <p class="text-xs text-slate-800 leading-relaxed font-medium whitespace-pre-wrap">
                            <?php echo !empty($data['solutionoftech']) ? sanitize($data['solutionoftech']) : 'Troubleshooting conducted, system verified operational.'; ?>
                        </p>
                    </div>
                </div>

                <?php if (!empty($data['xtime'])): ?>
                    <div class="text-[11px] text-slate-500 font-mono text-right">
                        Time of Completion: <strong><?php echo sanitize($data['xtime']); ?></strong>
                    </div>
                <?php endif; ?>
            </div>

        <!-- 3. HARDWARE & SOFTWARE PULL-OUT RECEIPT BODY -->
        <?php elseif ($doc_type === 'pullout'): ?>
            <div class="space-y-3">
                <h3 class="font-extrabold text-xs sm:text-sm text-slate-900 uppercase tracking-wider border-b-2 border-slate-800 pb-1.5">
                    Equipment Movement &amp; Pull-Out Item Breakdown
                </h3>

                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-slate-100 border-y border-slate-300 text-slate-700 font-bold uppercase text-[10px]">
                            <th class="py-2.5 px-3">Item Code</th>
                            <th class="py-2.5 px-3">Item Name / Description</th>
                            <th class="py-2.5 px-3 text-center">Movement Type</th>
                            <th class="py-2.5 px-3 text-center">Quantity</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <tr>
                            <td class="py-3 px-3 font-mono font-bold text-[#EB3E0B]">
                                <?php echo !empty($data['item_code']) ? sanitize($data['item_code']) : 'PULLOUT-ITEM'; ?>
                            </td>
                            <td class="py-3 px-3 text-slate-800 font-bold text-xs sm:text-sm">
                                <?php echo !empty($data['item_name']) ? sanitize($data['item_name']) : 'Hardware / Software Equipment Unit'; ?>
                                <?php if (!empty($data['category'])): ?>
                                    <span class="block text-[10px] font-normal text-slate-500">Category: <?php echo sanitize($data['category']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-3 text-center font-bold text-slate-800">
                                <?php echo sanitize($data['change_type']); ?>
                            </td>
                            <td class="py-3 px-3 text-center font-mono font-extrabold text-slate-900 text-sm">
                                <?php echo abs(intval($data['quantity_change'])) > 0 ? abs(intval($data['quantity_change'])) : 1; ?> unit(s)
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="p-3.5 rounded-xl bg-amber-50 border border-amber-200 space-y-1 text-xs">
                    <span class="font-bold text-amber-900 uppercase tracking-wider text-[10px] block">Pull-Out Reason, Condition &amp; Diagnostic Findings:</span>
                    <p class="text-slate-800 leading-relaxed font-medium whitespace-pre-wrap text-[11px]">
                        <?php echo !empty($data['notes']) ? sanitize($data['notes']) : 'Hardware pullout retrieved from client terminal for inspection, diagnostic testing, or warranty replacement.'; ?>
                    </p>
                </div>
            </div>

        <!-- 4. DIAGNOSTIC LOG BODY -->
        <?php elseif ($doc_type === 'log'): ?>
            <div class="space-y-3">
                <h3 class="font-extrabold text-xs sm:text-sm text-slate-900 uppercase tracking-wider border-b-2 border-slate-800 pb-1.5">
                    Hardware Diagnostic Trail &amp; Step Results
                </h3>

                <div class="grid grid-cols-2 gap-3 text-xs">
                    <div class="p-3 rounded-xl bg-slate-50 border border-slate-200 space-y-0.5">
                        <span class="text-slate-400 font-bold uppercase text-[9px] block">Hardware Device Tested</span>
                        <span class="text-slate-900 font-extrabold text-xs sm:text-sm"><?php echo sanitize($data['hardware_selected']); ?></span>
                    </div>
                    <div class="p-3 rounded-xl bg-slate-50 border border-slate-200 space-y-0.5">
                        <span class="text-slate-400 font-bold uppercase text-[9px] block">Reported Issue / Symptom</span>
                        <span class="text-slate-900 font-extrabold text-xs sm:text-sm"><?php echo !empty($data['issue_selected']) ? sanitize($data['issue_selected']) : 'Hardware Diagnostic Routine'; ?></span>
                    </div>
                </div>

                <?php if (!empty($data['custom_answer'])): ?>
                    <div class="p-3 rounded-xl bg-slate-50 border border-slate-200 text-xs space-y-0.5">
                        <span class="text-slate-400 font-bold uppercase text-[9px] block">Client Diagnostic Feedback / Inputs</span>
                        <p class="text-slate-800 font-medium whitespace-pre-wrap text-[11px]"><?php echo sanitize($data['custom_answer']); ?></p>
                    </div>
                <?php endif; ?>

                <div class="p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-xs space-y-0.5">
                    <span class="text-emerald-800 font-bold uppercase text-[9px] block">Diagnostic Summary &amp; Status</span>
                    <p class="text-slate-800 font-medium text-[11px]">
                        Completed through Step <strong><?php echo $data['step_completed']; ?></strong> with resolution outcome recorded as <strong><?php echo sanitize($data['resolution_status']); ?></strong>.
                    </p>
                </div>
            </div>

        <!-- 5. CLIENT ASSET CERTIFICATE BODY -->
        <?php elseif ($doc_type === 'asset'): ?>
            <div class="space-y-3">
                <h3 class="font-extrabold text-xs sm:text-sm text-slate-900 uppercase tracking-wider border-b-2 border-slate-800 pb-1.5">
                    Registered Asset &amp; Warranty Certificate
                </h3>

                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-slate-100 border-y border-slate-300 text-slate-700 font-bold uppercase text-[10px]">
                            <th class="py-2.5 px-3">Type</th>
                            <th class="py-2.5 px-3">Item Name / Model</th>
                            <th class="py-2.5 px-3">Serial Number</th>
                            <th class="py-2.5 px-3 text-center">Qty</th>
                            <th class="py-2.5 px-3 text-right">Unit Price</th>
                            <th class="py-2.5 px-3 text-right">Total Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <tr>
                            <td class="py-3 px-3 font-bold text-[#EB3E0B]"><?php echo sanitize($data['asset_type']); ?></td>
                            <td class="py-3 px-3 font-bold text-slate-900 text-xs">
                                <?php echo sanitize($data['name']); ?>
                                <?php if (!empty($data['item_code'])): ?>
                                    <span class="block text-[10px] font-mono text-slate-500"><?php echo sanitize($data['item_code']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-3 font-mono font-semibold text-slate-700">
                                <?php echo !empty($data['serial_number']) ? sanitize($data['serial_number']) : 'N/A'; ?>
                            </td>
                            <td class="py-3 px-3 text-center font-mono font-bold text-slate-900"><?php echo intval($data['quantity']); ?></td>
                            <td class="py-3 px-3 text-right font-mono text-slate-700">&#8369;<?php echo number_format($data['unit_price'], 2); ?></td>
                            <td class="py-3 px-3 text-right font-mono font-extrabold text-slate-900">&#8369;<?php echo number_format($data['total_amount'], 2); ?></td>
                        </tr>
                    </tbody>
                </table>

                <div class="grid grid-cols-2 gap-3 text-xs p-3.5 rounded-xl bg-slate-50 border border-slate-200">
                    <div>
                        <span class="text-slate-400 font-bold uppercase text-[9px] block">Warranty Period</span>
                        <span class="font-mono font-bold text-slate-900 text-xs">
                            <?php echo !empty($data['warranty_start']) ? format_date_only($data['warranty_start']) : 'N/A'; ?> &rarr; 
                            <?php echo !empty($data['warranty_expiry']) ? format_date_only($data['warranty_expiry']) : 'N/A'; ?>
                        </span>
                    </div>
                    <div>
                        <span class="text-slate-400 font-bold uppercase text-[9px] block">Warranty Status</span>
                        <span class="font-bold text-slate-900 text-xs"><?php echo sanitize($data['warranty_status']); ?></span>
                    </div>
                    <?php if (!empty($data['warranty_notes'])): ?>
                        <div class="col-span-2 pt-1 border-t border-slate-200">
                            <span class="text-slate-400 font-bold uppercase text-[9px] block">Warranty Terms &amp; Scope</span>
                            <p class="text-slate-800 font-medium text-[11px]"><?php echo sanitize($data['warranty_notes']); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Signatures & Conforme Block (Side by Side) -->
        <div class="pt-6 border-t-2 border-slate-900 grid grid-cols-2 gap-8 text-xs">
            
            <!-- Servicing Technician -->
            <div class="space-y-4">
                <div>
                    <span class="font-bold uppercase tracking-wider text-slate-700 block text-[10px]">Prepared &amp; Serviced By:</span>
                    <p class="text-[9px] text-slate-400">Certified accurate by attending technical representative.</p>
                </div>
                <div class="space-y-1">
                    <div class="border-b-2 border-slate-800 h-8 w-full flex items-end justify-center pb-0.5">
                        <span class="font-extrabold text-slate-900 text-xs uppercase tracking-wide"><?php echo sanitize($tech_name); ?></span>
                    </div>
                    <div class="flex justify-between text-slate-500 text-[9px] font-bold uppercase">
                        <span>Authorized Representative / Admin</span>
                        <span>Date: <?php echo format_date_only($doc_date); ?></span>
                    </div>
                </div>
            </div>

            <!-- Client Conforme -->
            <div class="space-y-4">
                <div>
                    <span class="font-bold uppercase tracking-wider text-slate-700 block text-[10px]">Client Conforme &amp; Acceptance:</span>
                    <p class="text-[9px] text-slate-400">I acknowledge that the work and services indicated have been performed satisfactorily.</p>
                </div>
                <div class="space-y-1">
                    <div class="border-b-2 border-slate-800 h-8 w-full"></div>
                    <div class="flex justify-between text-slate-500 text-[9px] font-bold uppercase">
                        <span>Authorized Client Signature / Name</span>
                        <span>Date</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer Notice -->
        <div class="text-center text-[9px] text-slate-400 border-t border-slate-200 pt-3 flex items-center justify-between">
            <span>RNZ Business Solutions &bull; POS Systems &amp; Maintenance</span>
            <span class="font-mono">Doc: <?php echo sanitize($doc_ref); ?> &bull; Acct: <?php echo sanitize($client_acct); ?></span>
            <span>Generated: <?php echo date('Y-m-d H:i'); ?></span>
        </div>

    </div>

    <script>
    function downloadAsPDF() {
        var element = document.getElementById('printDocumentCard');
        var opt = {
            margin:       [7.62, 12.7, 7.62, 12.7],
            filename:     '<?php echo sanitize($doc_ref); ?>_<?php echo sanitize($client_acct); ?>.pdf',
            image:        { type: 'jpeg', quality: 0.98 },
            html2canvas:  { scale: 2, useCORS: true },
            jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
        };
        html2pdf().set(opt).from(element).save();
    }

    <?php if ($autoprint): ?>
    window.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            window.print();
        }, 500);
    });
    <?php endif; ?>
    </script>
</body>
</html>
