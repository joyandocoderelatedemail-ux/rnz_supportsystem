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

$doc_title = 'Document';
$doc_ref = '';
$doc_date = date('Y-m-d');
$client_acct = '';
$doc_status = 'Completed';
$tech_name = 'RNZ Support Staff';
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

    $doc_title = 'OFFICIAL WORK ORDER & BILLING STATEMENT';
    $doc_ref = 'WO-' . str_pad($data['id'], 6, '0', STR_PAD_LEFT);
    $doc_date = !empty($data['xdate']) ? $data['xdate'] : date('Y-m-d');
    $client_acct = $data['accountnum'];
    $doc_status = !empty($data['status']) ? ucfirst($data['status']) : 'Pending';
    $tech_name = !empty($data['xuser']) ? $data['xuser'] : 'RNZ Support Specialist';

} elseif ($doc_type === 'technote' || $doc_type === 'notes') {
    $stmt = $pdo->prepare("SELECT * FROM bucket_technotes WHERE id = :id LIMIT 1");
    $stmt->execute(array(':id' => $doc_id));
    $data = $stmt->fetch();

    if (!$data) {
        die("Technical Service Note #$doc_id not found.");
    }

    $doc_title = 'TECHNICAL SERVICE REPORT & VISIT LOG';
    $doc_ref = 'TSN-' . str_pad($data['id'], 6, '0', STR_PAD_LEFT);
    $doc_date = !empty($data['xdate']) ? $data['xdate'] : date('Y-m-d');
    $client_acct = $data['accountnum'];
    $doc_status = !empty($data['status']) ? ucfirst($data['status']) : 'Done';
    $tech_name = !empty($data['techname']) ? $data['techname'] : 'RNZ Field Technician';

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
    $doc_ref = 'PO-' . str_pad($data['id'], 6, '0', STR_PAD_LEFT);
    $doc_date = !empty($data['created_at']) ? date('Y-m-d', strtotime($data['created_at'])) : date('Y-m-d');
    $client_acct = $data['accountnum'];
    $doc_status = !empty($data['change_type']) ? $data['change_type'] : 'Pull-Out';
    $tech_name = !empty($data['tech_name']) ? $data['tech_name'] : 'RNZ Inventory Specialist';

} elseif ($doc_type === 'log') {
    $stmt = $pdo->prepare("SELECT * FROM hardware_troubleshooting_logs WHERE id = :id LIMIT 1");
    $stmt->execute(array(':id' => $doc_id));
    $data = $stmt->fetch();

    if (!$data) {
        die("Diagnostic Log #$doc_id not found.");
    }

    $doc_title = 'HARDWARE TROUBLESHOOTING & DIAGNOSTIC REPORT';
    $doc_ref = 'DIAG-' . str_pad($data['id'], 6, '0', STR_PAD_LEFT);
    $doc_date = !empty($data['created_at']) ? date('Y-m-d', strtotime($data['created_at'])) : date('Y-m-d');
    $client_acct = $data['accountnum'];
    $doc_status = !empty($data['resolution_status']) ? ucfirst($data['resolution_status']) : 'Completed';
    $tech_name = 'RNZ Diagnostic System';

} elseif ($doc_type === 'asset') {
    $stmt = $pdo->prepare("SELECT * FROM client_assets WHERE id = :id LIMIT 1");
    $stmt->execute(array(':id' => $doc_id));
    $data = $stmt->fetch();

    if (!$data) {
        die("Client Asset Record #$doc_id not found.");
    }

    $doc_title = 'CLIENT EQUIPMENT & WARRANTY CERTIFICATE';
    $doc_ref = 'AST-' . str_pad($data['id'], 6, '0', STR_PAD_LEFT);
    $doc_date = !empty($data['created_at']) ? date('Y-m-d', strtotime($data['created_at'])) : date('Y-m-d');
    $client_acct = $data['accountnum'];
    $doc_status = !empty($data['warranty_status']) ? $data['warranty_status'] : 'Active';
    $tech_name = !empty($data['recorded_by']) ? $data['recorded_by'] : 'RNZ Asset Custodian';

} else {
    die("Unsupported document type.");
}

// 2. Fetch Client Info
if (!empty($client_acct)) {
    $stmt_c = $pdo->prepare("SELECT * FROM bucket_client WHERE accountnum = :acct LIMIT 1");
    $stmt_c->execute(array(':acct' => $client_acct));
    $client = $stmt_c->fetch();
}

$client_tradename = !empty($client['tradename']) ? $client['tradename'] : (!empty($data['tradename']) ? $data['tradename'] : (!empty($data['clientname']) ? $data['clientname'] : 'N/A'));
$client_owner = !empty($client['clientname']) ? $client['clientname'] : (!empty($data['clientname']) ? $data['clientname'] : 'N/A');
$client_address = !empty($client['address']) ? $client['address'] : (!empty($data['address']) ? $data['address'] : (!empty($data['xaddress']) ? $data['xaddress'] : 'N/A'));
$client_contact = !empty($client['contactnum']) ? $client['contactnum'] : 'N/A';
$client_email = !empty($client['emailaddress']) ? $client['emailaddress'] : 'N/A';
$client_warranty_status = !empty($client['warranty_status']) ? $client['warranty_status'] : 'Inactive';
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
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <!-- html2pdf.js for 1-click direct PDF file downloads -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        @media print {
            .no-print { display: none !important; }
            body { background: #ffffff !important; padding: 0 !important; }
            .print-page { box-shadow: none !important; border: none !important; margin: 0 !important; width: 100% !important; max-width: 100% !important; padding: 0 !important; }
            @page { margin: 12mm 15mm; size: A4 portrait; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 antialiased p-4 sm:p-8 min-h-screen flex flex-col items-center">

    <!-- Top Action Bar (Hidden when printed) -->
    <div class="no-print w-full max-w-4xl mb-6 flex flex-col sm:flex-row items-center justify-between gap-4 bg-slate-900 text-white p-4 rounded-2xl shadow-xl border border-slate-800">
        <div class="flex items-center space-x-3">
            <div class="w-10 h-10 rounded-xl bg-[#EB3E0B] text-white flex items-center justify-center font-extrabold shadow-md">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                </svg>
            </div>
            <div>
                <h2 class="text-sm font-extrabold text-white"><?php echo sanitize($doc_ref); ?> &bull; <?php echo sanitize($doc_title); ?></h2>
                <p class="text-xs text-slate-400">Official printable document for Account #<?php echo sanitize($client_acct); ?></p>
            </div>
        </div>

        <div class="flex items-center space-x-2.5 w-full sm:w-auto justify-end">
            <button onclick="downloadAsPDF()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs px-4 py-2.5 rounded-xl shadow-md flex items-center space-x-2 transition-all active:scale-95">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                <span>Download PDF</span>
            </button>
            <button onclick="window.print()" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs px-5 py-2.5 rounded-xl shadow-md flex items-center space-x-2 transition-all active:scale-95">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                </svg>
                <span>Print Document</span>
            </button>
            <button onclick="window.close()" class="bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white font-bold text-xs px-3.5 py-2.5 rounded-xl transition-all">
                Close
            </button>
        </div>
    </div>

    <!-- Printable Paper Page Container -->
    <div id="printDocumentCard" class="print-page bg-white rounded-3xl p-8 sm:p-12 border border-slate-200 shadow-2xl max-w-4xl w-full text-slate-800 space-y-8">
        
        <!-- Header: Company & Reference info -->
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between border-b-2 border-slate-900 pb-6 gap-6">
            <div class="space-y-1">
                <div class="flex items-center space-x-2.5">
                    <div class="w-9 h-9 rounded-xl bg-[#EB3E0B] text-white flex items-center justify-center font-extrabold text-lg shadow-sm">
                        R
                    </div>
                    <div>
                        <h1 class="text-xl font-extrabold tracking-tight text-slate-900 uppercase">RNZ BUSINESS SOLUTIONS</h1>
                        <p class="text-[10px] font-bold text-[#EB3E0B] tracking-wider uppercase">Point of Sale &bull; Hardware Maintenance &bull; Technical Support</p>
                    </div>
                </div>
                <p class="text-xs text-slate-500 pt-1 leading-tight">
                    Official Technical Support &amp; Customer Relations Document<br>
                    Website: rnzpos.com &bull; Support Hotline: (02) 8000-RNZ
                </p>
            </div>

            <div class="text-left sm:text-right space-y-1 sm:self-end">
                <div class="inline-block bg-slate-100 border border-slate-300 rounded-xl px-4 py-1.5 font-mono text-sm font-bold text-slate-900">
                    <?php echo sanitize($doc_ref); ?>
                </div>
                <div class="text-xs text-slate-500 font-mono">Date: <strong><?php echo format_date($doc_date); ?></strong></div>
                <div class="text-xs text-slate-500">Status: <span class="font-bold text-slate-800"><?php echo sanitize($doc_status); ?></span></div>
            </div>
        </div>

        <!-- Document Headline Banner -->
        <div class="text-center bg-[#FFF5ED] border border-[#FECDAA] py-3 px-4 rounded-2xl">
            <h2 class="text-sm sm:text-base font-extrabold text-[#430D07] tracking-wider uppercase">
                <?php echo sanitize($doc_title); ?>
            </h2>
        </div>

        <!-- Two-Column Information Box: Client & Service Details -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 p-6 rounded-2xl bg-slate-50 border border-slate-200 text-xs">
            <!-- Left: Client Information -->
            <div class="space-y-2">
                <span class="font-bold uppercase tracking-wider text-[11px] text-[#EB3E0B] block border-b border-slate-200 pb-1">
                    Client Account Details
                </span>
                <div>
                    <span class="text-slate-500 block text-[10px] font-bold uppercase">Trade / Business Name</span>
                    <span class="font-extrabold text-slate-900 text-sm"><?php echo sanitize($client_tradename); ?></span>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <span class="text-slate-500 block text-[10px] font-bold uppercase">Account Number</span>
                        <span class="font-mono font-bold text-slate-900"><?php echo sanitize($client_acct); ?></span>
                    </div>
                    <div>
                        <span class="text-slate-500 block text-[10px] font-bold uppercase">Contact Number</span>
                        <span class="font-semibold text-slate-800"><?php echo sanitize($client_contact); ?></span>
                    </div>
                </div>
                <div>
                    <span class="text-slate-500 block text-[10px] font-bold uppercase">Client / Owner Name</span>
                    <span class="font-medium text-slate-800"><?php echo sanitize($client_owner); ?></span>
                </div>
                <div>
                    <span class="text-slate-500 block text-[10px] font-bold uppercase">Store / Business Address</span>
                    <span class="font-medium text-slate-800"><?php echo sanitize($client_address); ?></span>
                </div>
            </div>

            <!-- Right: Document Metadata & Service Info -->
            <div class="space-y-2">
                <span class="font-bold uppercase tracking-wider text-[11px] text-[#EB3E0B] block border-b border-slate-200 pb-1">
                    Service Record Details
                </span>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <span class="text-slate-500 block text-[10px] font-bold uppercase">Document Ref</span>
                        <span class="font-mono font-bold text-[#EB3E0B]"><?php echo sanitize($doc_ref); ?></span>
                    </div>
                    <div>
                        <span class="text-slate-500 block text-[10px] font-bold uppercase">Record Date</span>
                        <span class="font-mono font-semibold text-slate-900"><?php echo sanitize($doc_date); ?></span>
                    </div>
                </div>
                <div>
                    <span class="text-slate-500 block text-[10px] font-bold uppercase">Attending Technician / Representative</span>
                    <span class="font-bold text-slate-900"><?php echo sanitize($tech_name); ?></span>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <span class="text-slate-500 block text-[10px] font-bold uppercase">Document Status</span>
                        <span class="font-bold text-slate-800"><?php echo sanitize($doc_status); ?></span>
                    </div>
                    <div>
                        <span class="text-slate-500 block text-[10px] font-bold uppercase">Warranty Coverage</span>
                        <span class="font-semibold text-slate-800"><?php echo sanitize($client_warranty_status); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- SPECIFIC CONTENT SECTIONS PER DOCUMENT TYPE -->

        <!-- 1. WORK ORDER BODY -->
        <?php if ($doc_type === 'workorder'): ?>
            <div class="space-y-4">
                <h3 class="font-extrabold text-sm text-slate-900 uppercase tracking-wider border-b border-slate-200 pb-2">
                    Service Particulars &amp; Billing Statement
                </h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="bg-slate-100 border-b border-slate-300 text-slate-700 font-bold uppercase text-[11px]">
                                <th class="py-3 px-4">Item #</th>
                                <th class="py-3 px-4">Description of Work / Service Performed</th>
                                <th class="py-3 px-4 text-center">Receipt #</th>
                                <th class="py-3 px-4 text-right">Amount (PHP)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <tr>
                                <td class="py-4 px-4 font-mono font-bold text-slate-500">01</td>
                                <td class="py-4 px-4 text-slate-800 leading-relaxed font-medium">
                                    <strong class="block text-slate-900 text-sm mb-1"><?php echo sanitize($data['natureofwork']); ?></strong>
                                    <span>Work Order reference for technical servicing, maintenance, and client billables.</span>
                                </td>
                                <td class="py-4 px-4 text-center font-mono font-semibold text-slate-700">
                                    <?php echo !empty($data['ornum']) ? 'OR #' . sanitize($data['ornum']) : 'N/A'; ?>
                                </td>
                                <td class="py-4 px-4 text-right font-mono font-extrabold text-slate-900 text-sm">
                                    &#8369;<?php echo number_format(floatval($data['amount']), 2); ?>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="bg-slate-50 font-bold border-t-2 border-slate-300">
                                <td colspan="3" class="py-3 px-4 text-right uppercase text-slate-700">Total Billed Amount:</td>
                                <td class="py-3 px-4 text-right font-mono text-base font-extrabold text-[#EB3E0B]">
                                    &#8369;<?php echo number_format(floatval($data['amount']), 2); ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

        <!-- 2. TECHNICAL SERVICE NOTE BODY -->
        <?php elseif ($doc_type === 'technote' || $doc_type === 'notes'): ?>
            <div class="space-y-5">
                <h3 class="font-extrabold text-sm text-slate-900 uppercase tracking-wider border-b border-slate-200 pb-2">
                    Field Service Report &amp; Diagnostic Details
                </h3>

                <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200 space-y-2">
                    <span class="text-[11px] font-bold text-[#EB3E0B] uppercase tracking-wider block">1. Client Concern / Reason of Visit:</span>
                    <p class="text-xs text-slate-800 font-semibold leading-relaxed whitespace-pre-wrap">
                        <?php echo !empty($data['reasonoftech']) ? sanitize($data['reasonoftech']) : 'Regular maintenance check and client service visit.'; ?>
                    </p>
                </div>

                <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200 space-y-2">
                    <span class="text-[11px] font-bold text-emerald-700 uppercase tracking-wider block">2. Findings &amp; Technical Solution Rendered:</span>
                    <p class="text-xs text-slate-800 leading-relaxed font-medium whitespace-pre-wrap">
                        <?php echo !empty($data['solutionoftech']) ? sanitize($data['solutionoftech']) : 'Troubleshooting conducted, system verified operational.'; ?>
                    </p>
                </div>

                <?php if (!empty($data['xtime'])): ?>
                    <div class="text-xs text-slate-500 font-mono">
                        Time of Visit / Completion: <strong><?php echo sanitize($data['xtime']); ?></strong>
                    </div>
                <?php endif; ?>
            </div>

        <!-- 3. HARDWARE & SOFTWARE PULL-OUT RECEIPT BODY -->
        <?php elseif ($doc_type === 'pullout'): ?>
            <div class="space-y-4">
                <h3 class="font-extrabold text-sm text-slate-900 uppercase tracking-wider border-b border-slate-200 pb-2">
                    Equipment Movement &amp; Pull-Out Item Breakdown
                </h3>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="bg-slate-100 border-b border-slate-300 text-slate-700 font-bold uppercase text-[11px]">
                                <th class="py-3 px-4">Item Code</th>
                                <th class="py-3 px-4">Item Name / Description</th>
                                <th class="py-3 px-4 text-center">Movement Type</th>
                                <th class="py-3 px-4 text-center">Quantity</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <tr>
                                <td class="py-4 px-4 font-mono font-bold text-[#EB3E0B]">
                                    <?php echo !empty($data['item_code']) ? sanitize($data['item_code']) : 'PULLOUT-ITEM'; ?>
                                </td>
                                <td class="py-4 px-4 text-slate-800 font-bold text-sm">
                                    <?php echo !empty($data['item_name']) ? sanitize($data['item_name']) : 'Hardware / Software Equipment Unit'; ?>
                                    <?php if (!empty($data['category'])): ?>
                                        <span class="block text-xs font-normal text-slate-500">Category: <?php echo sanitize($data['category']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-4 px-4 text-center font-bold text-slate-800">
                                    <?php echo sanitize($data['change_type']); ?>
                                </td>
                                <td class="py-4 px-4 text-center font-mono font-extrabold text-slate-900 text-sm">
                                    <?php echo abs(intval($data['quantity_change'])) > 0 ? abs(intval($data['quantity_change'])) : 1; ?> unit(s)
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="p-5 rounded-2xl bg-amber-50 border border-amber-200 space-y-2 text-xs">
                    <span class="font-bold text-amber-900 uppercase tracking-wider text-[11px] block">Pull-Out Reason, Condition &amp; Diagnostic Findings:</span>
                    <p class="text-slate-800 leading-relaxed font-medium whitespace-pre-wrap">
                        <?php echo !empty($data['notes']) ? sanitize($data['notes']) : 'Hardware pullout retrieved from client terminal for inspection, diagnostic testing, or warranty replacement.'; ?>
                    </p>
                </div>
            </div>

        <!-- 4. DIAGNOSTIC LOG BODY -->
        <?php elseif ($doc_type === 'log'): ?>
            <div class="space-y-4">
                <h3 class="font-extrabold text-sm text-slate-900 uppercase tracking-wider border-b border-slate-200 pb-2">
                    Hardware Diagnostic Trail &amp; Step Results
                </h3>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                    <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 space-y-1">
                        <span class="text-slate-500 font-bold uppercase text-[10px] block">Hardware Device Tested</span>
                        <span class="text-slate-900 font-extrabold text-sm"><?php echo sanitize($data['hardware_selected']); ?></span>
                    </div>
                    <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 space-y-1">
                        <span class="text-slate-500 font-bold uppercase text-[10px] block">Reported Issue / Symptom</span>
                        <span class="text-slate-900 font-extrabold text-sm"><?php echo !empty($data['issue_selected']) ? sanitize($data['issue_selected']) : 'Hardware Diagnostic Routine'; ?></span>
                    </div>
                </div>

                <?php if (!empty($data['custom_answer'])): ?>
                    <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-xs space-y-1">
                        <span class="text-slate-500 font-bold uppercase text-[10px] block">Client Diagnostic Feedback / Inputs</span>
                        <p class="text-slate-800 font-medium whitespace-pre-wrap"><?php echo sanitize($data['custom_answer']); ?></p>
                    </div>
                <?php endif; ?>

                <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-xs space-y-1">
                    <span class="text-emerald-800 font-bold uppercase text-[10px] block">Diagnostic Summary &amp; Status</span>
                    <p class="text-slate-800 font-medium">
                        Completed through Step <strong><?php echo $data['step_completed']; ?></strong> with resolution outcome recorded as <strong><?php echo sanitize($data['resolution_status']); ?></strong>.
                    </p>
                </div>
            </div>

        <!-- 5. CLIENT ASSET CERTIFICATE BODY -->
        <?php elseif ($doc_type === 'asset'): ?>
            <div class="space-y-4">
                <h3 class="font-extrabold text-sm text-slate-900 uppercase tracking-wider border-b border-slate-200 pb-2">
                    Registered Asset &amp; Warranty Certificate
                </h3>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="bg-slate-100 border-b border-slate-300 text-slate-700 font-bold uppercase text-[11px]">
                                <th class="py-3 px-4">Type</th>
                                <th class="py-3 px-4">Item Name / Model</th>
                                <th class="py-3 px-4">Serial Number</th>
                                <th class="py-3 px-4 text-center">Qty</th>
                                <th class="py-3 px-4 text-right">Unit Price</th>
                                <th class="py-3 px-4 text-right">Total Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <tr>
                                <td class="py-4 px-4 font-bold text-[#EB3E0B]"><?php echo sanitize($data['asset_type']); ?></td>
                                <td class="py-4 px-4 font-bold text-slate-900 text-sm">
                                    <?php echo sanitize($data['name']); ?>
                                    <?php if (!empty($data['item_code'])): ?>
                                        <span class="block text-xs font-mono text-slate-500"><?php echo sanitize($data['item_code']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-4 px-4 font-mono font-semibold text-slate-700">
                                    <?php echo !empty($data['serial_number']) ? sanitize($data['serial_number']) : 'N/A'; ?>
                                </td>
                                <td class="py-4 px-4 text-center font-mono font-bold text-slate-900"><?php echo intval($data['quantity']); ?></td>
                                <td class="py-4 px-4 text-right font-mono text-slate-700">&#8369;<?php echo number_format($data['unit_price'], 2); ?></td>
                                <td class="py-4 px-4 text-right font-mono font-extrabold text-slate-900">&#8369;<?php echo number_format($data['total_amount'], 2); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs p-5 rounded-2xl bg-slate-50 border border-slate-200">
                    <div>
                        <span class="text-slate-500 font-bold uppercase text-[10px] block">Warranty Period</span>
                        <span class="font-mono font-bold text-slate-900">
                            <?php echo !empty($data['warranty_start']) ? format_date_only($data['warranty_start']) : 'N/A'; ?> &rarr; 
                            <?php echo !empty($data['warranty_expiry']) ? format_date_only($data['warranty_expiry']) : 'N/A'; ?>
                        </span>
                    </div>
                    <div>
                        <span class="text-slate-500 font-bold uppercase text-[10px] block">Warranty Status</span>
                        <span class="font-bold text-slate-900"><?php echo sanitize($data['warranty_status']); ?></span>
                    </div>
                    <?php if (!empty($data['warranty_notes'])): ?>
                        <div class="sm:col-span-2">
                            <span class="text-slate-500 font-bold uppercase text-[10px] block">Warranty Terms &amp; Scope</span>
                            <p class="text-slate-800 font-medium"><?php echo sanitize($data['warranty_notes']); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Signatures & Conforme Block -->
        <div class="pt-10 border-t-2 border-slate-900 grid grid-cols-1 sm:grid-cols-2 gap-12 text-xs">
            <!-- Client Conforme -->
            <div class="space-y-8">
                <div>
                    <span class="font-bold uppercase tracking-wider text-slate-700 block text-[11px]">Client Conforme &amp; Acceptance:</span>
                    <p class="text-[10px] text-slate-500">I hereby acknowledge the work, services rendered, or equipment described herein.</p>
                </div>
                <div class="space-y-1">
                    <div class="border-b-2 border-slate-800 h-10 w-full"></div>
                    <div class="flex justify-between text-slate-500 text-[10px] font-bold uppercase">
                        <span>Authorized Client Signature / Printed Name</span>
                        <span>Date</span>
                    </div>
                </div>
            </div>

            <!-- RNZ Representative Signature -->
            <div class="space-y-8">
                <div>
                    <span class="font-bold uppercase tracking-wider text-slate-700 block text-[11px]">Authorized RNZ Representative:</span>
                    <p class="text-[10px] text-slate-500">Certified accurate and authorized by technical personnel.</p>
                </div>
                <div class="space-y-1">
                    <div class="border-b-2 border-slate-800 h-10 w-full flex items-end justify-center pb-1">
                        <span class="font-bold text-slate-900"><?php echo sanitize($tech_name); ?></span>
                    </div>
                    <div class="flex justify-between text-slate-500 text-[10px] font-bold uppercase">
                        <span>Servicing Technician / Specialist</span>
                        <span>Date</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer Notice -->
        <div class="text-center text-[10px] text-slate-400 border-t border-slate-200 pt-4">
            RNZ Business Solutions &bull; POS Systems &amp; Maintenance &bull; Generated on <?php echo date('Y-m-d H:i:s'); ?>
        </div>

    </div>

    <script>
    function downloadAsPDF() {
        var element = document.getElementById('printDocumentCard');
        var opt = {
            margin:       [10, 10, 10, 10],
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
        }, 600);
    });
    <?php endif; ?>
    </script>
</body>
</html>
