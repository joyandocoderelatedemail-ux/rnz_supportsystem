<?php
// Client Profile & Account Details Page
require_once __DIR__ . '/includes/config.php';

require_login();
$client = get_logged_client();
$accountnum = $client['accountnum'];

$pdo = get_db_connection();

// Refresh latest client data from bucket_client table
$stmt = $pdo->prepare("SELECT * FROM bucket_client WHERE accountnum = :acct LIMIT 1");
$stmt->execute(array(':acct' => $accountnum));
$fresh_client = $stmt->fetch();

if ($fresh_client) {
    $client = $fresh_client;
    $_SESSION['client_data'] = $fresh_client;
}

$active_page = 'profile';
$page_title = 'Account Details';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Details - RNZ Client Portal</title>
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

        <main class="p-4 sm:p-6 md:p-8 pb-24 md:pb-8 space-y-6 max-w-4xl w-full mx-auto">

            <!-- Profile Summary Header -->
            <div class="bg-white/90 rounded-3xl p-6 sm:p-8 border border-[#FECDAA] shadow-sm flex flex-col sm:flex-row items-center space-y-4 sm:space-y-0 sm:space-x-6">
                <div class="w-20 h-20 rounded-3xl bg-[#EB3E0B] text-white font-extrabold text-3xl flex items-center justify-center shadow-lg shadow-[#EB3E0B]/30 shrink-0">
                    <?php echo strtoupper(substr($client['tradename'], 0, 1)); ?>
                </div>
                <div class="text-center sm:text-left space-y-1">
                    <h2 class="text-xl sm:text-2xl font-extrabold text-[#430D07]"><?php echo sanitize($client['tradename']); ?></h2>
                    <p class="text-xs text-[#7C2112] font-semibold">Owner / Contact: <?php echo sanitize($client['clientname']); ?></p>
                    <div class="inline-flex items-center space-x-2 bg-[#FFE8D5] px-3 py-1 rounded-full text-[#430D07] text-xs font-mono font-bold mt-1">
                        <span>Account #: <?php echo sanitize($client['accountnum']); ?></span>
                    </div>
                </div>
            </div>

            <!-- Profile Information Cards Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                <!-- Company & Contact Info -->
                <div class="bg-white/90 rounded-3xl p-6 sm:p-8 border border-[#FECDAA] shadow-sm space-y-5">
                    <h3 class="text-base font-extrabold text-[#430D07] border-b border-[#FFE8D5] pb-3 flex items-center space-x-2">
                        <svg class="w-5 h-5 text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                        </svg>
                        <span>Business & Contact Details</span>
                    </h3>

                    <div class="space-y-4 text-xs">
                        <div>
                            <span class="block text-[#7C2112] font-medium mb-0.5">Trade Name</span>
                            <span class="font-bold text-[#430D07] text-sm"><?php echo sanitize($client['tradename']); ?></span>
                        </div>
                        <div>
                            <span class="block text-[#7C2112] font-medium mb-0.5">Client Full Name</span>
                            <span class="font-bold text-[#430D07] text-sm"><?php echo sanitize($client['clientname']); ?></span>
                        </div>
                        <div>
                            <span class="block text-[#7C2112] font-medium mb-0.5">Business Address</span>
                            <span class="font-bold text-[#430D07]"><?php echo sanitize($client['address']); ?></span>
                        </div>
                        <div>
                            <span class="block text-[#7C2112] font-medium mb-0.5">Contact Phone Number</span>
                            <span class="font-bold text-[#430D07] font-mono"><?php echo sanitize($client['contactnum']); ?></span>
                        </div>
                        <div>
                            <span class="block text-[#7C2112] font-medium mb-0.5">Email Address</span>
                            <span class="font-bold text-[#430D07] font-mono"><?php echo sanitize($client['emailaddress']); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Account & Retainer Info -->
                <div class="bg-white/90 rounded-3xl p-6 sm:p-8 border border-[#FECDAA] shadow-sm space-y-5">
                    <h3 class="text-base font-extrabold text-[#430D07] border-b border-[#FFE8D5] pb-3 flex items-center space-x-2">
                        <svg class="w-5 h-5 text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>Subscription & Account Plan</span>
                    </h3>

                    <div class="space-y-4 text-xs">
                        <div>
                            <span class="block text-[#7C2112] font-medium mb-0.5">Account Type</span>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-[#FFE8D5] text-[#EB3E0B] border border-[#FECDAA]">
                                <?php echo sanitize($client['type']); ?>
                            </span>
                        </div>
                        <div>
                            <span class="block text-[#7C2112] font-medium mb-0.5">Monthly Retainer Fee</span>
                            <span class="font-extrabold text-[#430D07] text-lg">₱<?php echo number_format(floatval($client['monthlyretainersfee']), 2); ?></span>
                        </div>
                        <div>
                            <span class="block text-[#7C2112] font-medium mb-0.5">Outstanding Balance</span>
                            <span class="font-extrabold text-[#430D07] text-lg">₱<?php echo number_format(floatval($client['outstandingbalance']), 2); ?></span>
                        </div>
                        <div class="pt-4 border-t border-[#FFE8D5]">
                            <a href="logout.php" class="inline-flex items-center space-x-2 text-rose-600 hover:text-rose-700 font-bold text-xs">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                                </svg>
                                <span>Sign Out of Account</span>
                            </a>
                        </div>
                    </div>
                </div>

            </div>

        </main>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
