<?php
// Technician & Admin Login Page for Support Center (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

if (is_tech_logged_in()) {
    header("Location: index.php");
    exit;
}

$error_msg = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['user']) ? trim($_POST['user']) : '';
    $password = isset($_POST['pass']) ? trim($_POST['pass']) : '';

    $result = login_tech($username, $password);
    if ($result['success']) {
        header("Location: index.php");
        exit;
    } else {
        $error_msg = $result['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Technician Login - RNZ Support Center</title>
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
                            500: '#FA5915',
                            600: '#EB3E0B',
                            700: '#C32C0B',
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
<body class="bg-slate-950 text-slate-100 min-h-screen flex items-center justify-center p-4">

    <!-- Login Card -->
    <div class="w-full max-w-md space-y-8 bg-slate-900/90 border border-slate-800 p-8 sm:p-10 rounded-3xl shadow-2xl backdrop-blur-xl">
        <!-- Brand Logo Header -->
        <div class="text-center space-y-3">
            <div class="w-16 h-16 rounded-3xl bg-[#EB3E0B] text-white flex items-center justify-center mx-auto shadow-lg shadow-[#EB3E0B]/30">
                <svg class="w-9 h-9" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                </svg>
            </div>
            <h1 class="text-2xl font-extrabold tracking-tight text-white">RNZ Support Center</h1>
            <p class="text-xs text-slate-400">Internal Support Staff & Technician Portal</p>
        </div>

        <!-- Error Message Alert -->
        <?php if (!empty($error_msg)): ?>
            <div class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs flex items-center space-x-3">
                <svg class="w-5 h-5 text-rose-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                <span><?php echo sanitize($error_msg); ?></span>
            </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form action="login.php" method="POST" class="space-y-5">
            <div>
                <label for="user" class="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-2">Username</label>
                <div class="relative">
                    <input type="text" id="user" name="user" value="<?php echo sanitize($username); ?>" required placeholder="Enter technician username" 
                           class="w-full bg-slate-800/80 border border-slate-700 text-white text-sm rounded-2xl pl-10 pr-4 py-3.5 focus:border-[#FA5915] focus:bg-slate-800 focus:outline-none transition-all placeholder-slate-500">
                    <svg class="w-5 h-5 text-slate-400 absolute left-3.5 top-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                </div>
            </div>

            <div>
                <label for="pass" class="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-2">Password</label>
                <div class="relative">
                    <input type="password" id="pass" name="pass" required placeholder="Enter password" 
                           class="w-full bg-slate-800/80 border border-slate-700 text-white text-sm rounded-2xl pl-10 pr-4 py-3.5 focus:border-[#FA5915] focus:bg-slate-800 focus:outline-none transition-all placeholder-slate-500">
                    <svg class="w-5 h-5 text-slate-400 absolute left-3.5 top-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                </div>
            </div>

            <button type="submit" class="w-full bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-extrabold text-sm py-4 rounded-2xl shadow-lg shadow-[#EB3E0B]/30 transition-all active:scale-[0.98] flex items-center justify-center space-x-2">
                <span>Sign In to Support Center</span>
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                </svg>
            </button>
        </form>

        <div class="text-center pt-2">
            <p class="text-xs text-slate-500">Authorized RNZ Personnel Only</p>
        </div>
    </div>

</body>
</html>
