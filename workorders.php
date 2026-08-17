<?php
// Work Orders & Billing History (bucket_workorder)
require_once __DIR__ . '/includes/config.php';

require_login();
$client = get_logged_client();
$accountnum = $client['accountnum'];

$pdo = get_db_connection();

$search_q = isset($_GET['q']) ? trim($_GET['q']) : '';

$sql = "SELECT * FROM bucket_workorder WHERE accountnum = :acct";
$params = array(':acct' => $accountnum);

if (!empty($search_q)) {
    $sql .= " AND (natureofwork LIKE :q OR ornum LIKE :q OR status LIKE :q)";
    $params[':q'] = '%' . $search_q . '%';
}

$sql .= " ORDER BY id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$workorders = $stmt->fetchAll();

$active_page = 'workorders';
$page_title = 'Work Orders';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Work Orders - RNZ Client Portal</title>
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

            <!-- Header Bar -->
            <div class="bg-white/90 rounded-3xl p-6 border border-[#FECDAA] shadow-sm flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                <div>
                    <h3 class="text-lg font-extrabold text-[#430D07]">Work Orders & Billing History</h3>
                    <p class="text-xs text-[#7C2112]">Service work orders, billing statements, and official receipts</p>
                </div>

                <form action="workorders.php" method="GET" class="w-full sm:w-72">
                    <div class="relative">
                        <input type="text" name="q" value="<?php echo sanitize($search_q); ?>" placeholder="Search work orders or OR #..." class="w-full bg-[#FFF5ED] text-[#430D07] text-xs pl-9 pr-4 py-2.5 rounded-full border border-[#FECDAA] focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                        <svg class="w-4 h-4 text-[#9A2512] absolute left-3 top-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </div>
                </form>
            </div>

            <!-- Work Orders Table Card -->
            <div class="bg-white/90 rounded-3xl p-6 sm:p-8 border border-[#FECDAA] shadow-sm">
                <?php if (empty($workorders)): ?>
                    <div class="text-center py-16">
                        <svg class="w-12 h-12 text-[#FEAA73] mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                        </svg>
                        <p class="text-sm font-bold text-[#430D07]">No work orders recorded</p>
                        <p class="text-xs text-[#7C2112] mt-1">There are no work order statements found for your account.</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="border-b border-[#FFE8D5] text-[11px] font-bold text-[#7C2112] uppercase tracking-wider">
                                    <th class="pb-3.5 pl-2">Date</th>
                                    <th class="pb-3.5">Nature of Work / Particulars</th>
                                    <th class="pb-3.5">O.R. Number</th>
                                    <th class="pb-3.5">Amount</th>
                                    <th class="pb-3.5 text-right pr-2">Payment Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#FFE8D5] text-xs">
                                <?php foreach ($workorders as $wo): ?>
                                    <tr class="hover:bg-[#FFF5ED] transition-colors">
                                        <td class="py-4 pl-2 text-[#9A2512] font-mono text-[11px]">
                                            <?php echo format_date($wo['xdate']); ?>
                                        </td>
                                        <td class="py-4 font-bold text-[#430D07] max-w-md">
                                            <?php echo sanitize($wo['natureofwork']); ?>
                                        </td>
                                        <td class="py-4 font-mono text-[#7C2112]">
                                            <?php echo !empty($wo['ornum']) ? 'OR #' . sanitize($wo['ornum']) : 'N/A'; ?>
                                        </td>
                                        <td class="py-4 font-extrabold text-[#430D07]">
                                            ₱<?php echo number_format(floatval($wo['amount']), 2); ?>
                                        </td>
                                        <td class="py-4 text-right pr-2">
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-[11px] font-semibold border <?php echo get_status_badge_class($wo['status']); ?>">
                                                <?php echo sanitize(ucfirst($wo['status'])); ?>
                                            </span>
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

<?php include __DIR__ . '/includes/footer.php'; ?>
