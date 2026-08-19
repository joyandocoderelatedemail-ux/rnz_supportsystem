<?php
// Client Login Page
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// Redirect if already logged in
if (is_logged_in()) {
    header("Location: ./");
    exit;
}

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? $_POST['username'] : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    $res = login_client($username, $password);
    if ($res['success']) {
        header("Location: index.php");
        exit;
    } else {
        $error_message = $res['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Portal Login - RNZ Support System</title>
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
<body class="bg-[#FFF5ED] text-slate-800 antialiased min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-md">
        <!-- Logo & Header -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-[#EB3E0B] text-white font-bold text-2xl shadow-xl shadow-[#EB3E0B]/30 mb-4">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
            </div>
            <h1 class="text-2xl font-extrabold text-[#430D07] tracking-tight">RNZ Support Portal</h1>
            <p class="text-xs text-[#7C2112] font-medium mt-1.5">Sign in to manage your tickets and support requests</p>
        </div>

        <!-- Login Card -->
        <div class="bg-white/90 rounded-3xl p-8 shadow-xl shadow-[#430D07]/5 border border-[#FECDAA] backdrop-blur-xl">

            <?php if (!empty($error_message)): ?>
                <div class="mb-6 bg-rose-50 border border-rose-200 text-rose-700 text-xs rounded-2xl p-4 flex items-center space-x-3">
                    <svg class="w-5 h-5 shrink-0 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span><?php echo sanitize($error_message); ?></span>
                </div>
            <?php endif; ?>

            <form action="" method="POST" class="space-y-5">
                <div>
                    <label for="username" class="block text-xs font-bold text-[#430D07] uppercase tracking-wider mb-2">Trade Name (User)</label>
                    <div class="relative">
                        <input type="text" id="username" name="username" required 
                               value="<?php echo isset($_POST['username']) ? sanitize($_POST['username']) : ''; ?>"
                               placeholder="e.g. STARDON CONSTRUCTION SUPPLIES" 
                               class="w-full bg-[#FFF5ED] border border-[#FECDAA] text-[#430D07] text-sm rounded-xl pl-11 pr-4 py-3 focus:bg-white focus:border-[#FA5915] focus:ring-4 focus:ring-[#FA5915]/10 focus:outline-none transition-all">
                        <svg class="w-5 h-5 text-[#9A2512] absolute left-3.5 top-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                        </svg>
                    </div>
                </div>

                <div>
                    <label for="password" class="block text-xs font-bold text-[#430D07] uppercase tracking-wider mb-2">Account Number (Password)</label>
                    <div class="relative">
                        <input type="password" id="password" name="password" required 
                               placeholder="e.g. 00000002" 
                               class="w-full bg-[#FFF5ED] border border-[#FECDAA] text-[#430D07] text-sm rounded-xl pl-11 pr-4 py-3 focus:bg-white focus:border-[#FA5915] focus:ring-4 focus:ring-[#FA5915]/10 focus:outline-none transition-all">
                        <svg class="w-5 h-5 text-[#9A2512] absolute left-3.5 top-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                    </div>
                </div>

                <div class="pt-2">
                    <button type="submit" class="w-full bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-sm py-3.5 px-6 rounded-2xl shadow-lg shadow-[#EB3E0B]/30 hover:shadow-[#EB3E0B]/40 transition-all duration-200 active:scale-[0.99]">
                        Sign In to Client Portal
                    </button>
                </div>
            </form>

            <div class="mt-8 pt-6 border-t border-[#FFE8D5] text-center">
                <p class="text-xs text-[#7C2112]">
                    Need assistance logging in? Contact RNZ Support at <span class="text-[#430D07] font-bold">support@rnzsystem.com</span>
                </p>
            </div>
        </div>

        <div class="text-center mt-8">
            <p class="text-xs text-[#7C2112]/80">&copy; <?php echo date('Y'); ?> RNZ Support System. All rights reserved.</p>
        </div>
    </div>

</body>
</html>
