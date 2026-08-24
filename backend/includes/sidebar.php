<?php
// Support Center Admin Sidebar Component (Desktop Hover-Expand & Full Mobile Drawer + Bottom Nav)
if (!isset($active_page)) {
    $active_page = 'dashboard';
}

$tech = get_logged_tech();
$tech_name = $tech ? $tech['fullname'] : 'Support Tech';
$access_level = 'Staff';
if ($tech && !empty($tech['accesslevel'])) {
    $raw_role = strtolower(trim($tech['accesslevel']));
    if ($raw_role === 'ojt') {
        $access_level = 'OJT';
    } elseif ($raw_role === 'senior programmer' || $raw_role === 'senior_programmer') {
        $access_level = 'Senior Programmer';
    } elseif ($raw_role === 'junior programmer' || $raw_role === 'junior_programmer') {
        $access_level = 'Junior Programmer';
    } elseif ($raw_role === 'tech support' || $raw_role === 'technician') {
        $access_level = 'Tech Support';
    } elseif ($raw_role === 'admin' || $raw_role === 'administrator') {
        $access_level = 'Admin';
    } elseif ($raw_role === 'master') {
        $access_level = 'Super Admin';
    } else {
        $access_level = ucwords(trim($tech['accesslevel']));
    }
}

$pending_orders_count = 0;
$pdo_sb = get_db_connection();
if ($pdo_sb) {
    try {
        $stmt_p_ord = $pdo_sb->query("SELECT COUNT(*) FROM client_hardware_orders WHERE status = 'Pending'");
        if ($stmt_p_ord) {
            $pending_orders_count = intval($stmt_p_ord->fetchColumn());
        }
    } catch (PDOException $e) {}
}
?>

<!-- ========================================================================= -->
<!-- 1. DESKTOP FLOATING AUTO-EXPAND SIDEBAR (Screens >= md) -->
<!-- ========================================================================= -->
<div class="relative w-16 hidden md:block shrink-0 z-40 min-h-screen">
    <aside class="fixed top-0 left-0 bottom-0 w-16 hover:w-64 bg-slate-900 border-r border-slate-800 flex flex-col justify-between text-slate-200 transition-[width,box-shadow] duration-200 ease-out group overflow-hidden shadow-xl hover:shadow-2xl z-40 will-change-[width]">
        <div class="p-3.5 space-y-6">
            <!-- Brand Logo -->
            <div class="flex items-center space-x-3 px-1">
                <div class="w-9 h-9 rounded-2xl bg-[#EB3E0B] flex items-center justify-center text-white font-bold text-lg shadow-sm shadow-[#EB3E0B]/30 shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                    </svg>
                </div>
                <div class="opacity-0 group-hover:opacity-100 transition-opacity duration-200 whitespace-nowrap overflow-hidden">
                    <h1 class="font-extrabold text-white text-sm leading-tight">RNZ Support</h1>
                    <span class="text-[10px] text-[#FEAA73] font-mono uppercase tracking-wider">Support Center</span>
                </div>
            </div>

            <!-- Navigation Links -->
            <nav class="space-y-1.5">
                <!-- Dashboard -->
                <?php if (user_has_page_access('dashboard')): ?>
                    <a href="index.php" title="Dashboard" class="flex items-center px-3 py-2.5 rounded-2xl text-xs sm:text-sm font-medium transition-colors duration-150 <?php echo ($active_page === 'dashboard') ? 'bg-[#EB3E0B] text-white shadow-sm shadow-[#EB3E0B]/20 font-bold' : 'text-slate-400 hover:bg-slate-800 hover:text-white'; ?>">
                        <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'dashboard') ? 'text-white' : 'text-slate-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                        <span class="ml-3 opacity-0 group-hover:opacity-100 transition-opacity duration-200 whitespace-nowrap overflow-hidden">Dashboard</span>
                    </a>
                <?php endif; ?>

                <!-- Support Tickets -->
                <?php if (user_has_page_access('tickets')): ?>
                    <a href="tickets.php" title="Support Tickets" class="flex items-center px-3 py-2.5 rounded-2xl text-xs sm:text-sm font-medium transition-colors duration-150 <?php echo ($active_page === 'tickets') ? 'bg-[#EB3E0B] text-white shadow-sm shadow-[#EB3E0B]/20 font-bold' : 'text-slate-400 hover:bg-slate-800 hover:text-white'; ?>">
                        <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'tickets') ? 'text-white' : 'text-slate-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 002 2 2 2 0 010 4 2 2 0 00-2 2v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 00-2-2 2 2 0 010-4 2 2 0 002-2V7a2 2 0 00-2-2H5z"/>
                        </svg>
                        <span class="ml-3 opacity-0 group-hover:opacity-100 transition-opacity duration-200 whitespace-nowrap overflow-hidden">Support Tickets</span>
                    </a>
                <?php endif; ?>

                <!-- Client Hardware Orders -->
                <?php if (user_has_page_access('orders')): ?>
                    <a href="orders.php" title="Hardware Orders" class="flex items-center justify-between px-3 py-2.5 rounded-2xl text-xs sm:text-sm font-medium transition-colors duration-150 <?php echo ($active_page === 'orders') ? 'bg-[#EB3E0B] text-white shadow-sm shadow-[#EB3E0B]/20 font-bold' : 'text-slate-400 hover:bg-slate-800 hover:text-white'; ?>">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'orders') ? 'text-white' : 'text-slate-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                            </svg>
                            <span class="ml-3 opacity-0 group-hover:opacity-100 transition-opacity duration-200 whitespace-nowrap overflow-hidden">Orders</span>
                        </div>
                        <?php if ($pending_orders_count > 0): ?>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-mono font-bold bg-amber-500 text-slate-950 opacity-0 group-hover:opacity-100 transition-opacity duration-200 shrink-0">
                                <?php echo $pending_orders_count; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>

                <!-- Manage Accounts Hub -->
                <?php if (user_has_page_access('accounts')): ?>
                    <a href="accounts.php" title="Manage Accounts" class="flex items-center px-3 py-2.5 rounded-2xl text-xs sm:text-sm font-medium transition-colors duration-150 <?php echo ($active_page === 'accounts') ? 'bg-[#EB3E0B] text-white shadow-sm shadow-[#EB3E0B]/20 font-bold' : 'text-slate-400 hover:bg-slate-800 hover:text-white'; ?>">
                        <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'accounts') ? 'text-white' : 'text-slate-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5m0 0h4m-4 0V11m0 0h4m-4 0H9m4 0V7m0 0h4m-4 0H9"/>
                        </svg>
                        <span class="ml-3 opacity-0 group-hover:opacity-100 transition-opacity duration-200 whitespace-nowrap overflow-hidden">Manage Accounts</span>
                    </a>
                <?php endif; ?>

                <!-- Hardware Inventory -->
                <?php if (user_has_page_access('inventory')): ?>
                    <a href="inventory.php" title="Hardware Inventory" class="flex items-center px-3 py-2.5 rounded-2xl text-xs sm:text-sm font-medium transition-colors duration-150 <?php echo ($active_page === 'inventory') ? 'bg-[#EB3E0B] text-white shadow-sm shadow-[#EB3E0B]/20 font-bold' : 'text-slate-400 hover:bg-slate-800 hover:text-white'; ?>">
                        <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'inventory') ? 'text-white' : 'text-slate-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                        <span class="ml-3 opacity-0 group-hover:opacity-100 transition-opacity duration-200 whitespace-nowrap overflow-hidden">Hardware Inventory</span>
                    </a>
                <?php endif; ?>

                <!-- Pull-Out Reports -->
                <?php if (user_has_page_access('inventory')): ?>
                    <a href="pullout_reports.php" title="Pull-Out Reports" class="flex items-center px-3 py-2.5 rounded-2xl text-xs sm:text-sm font-medium transition-colors duration-150 <?php echo ($active_page === 'pullout_reports') ? 'bg-[#EB3E0B] text-white shadow-sm shadow-[#EB3E0B]/20 font-bold' : 'text-slate-400 hover:bg-slate-800 hover:text-white'; ?>">
                        <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'pullout_reports') ? 'text-white' : 'text-slate-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        <span class="ml-3 opacity-0 group-hover:opacity-100 transition-opacity duration-200 whitespace-nowrap overflow-hidden">Pull-Out Reports</span>
                    </a>
                <?php endif; ?>

                <!-- POS Maintenance Requests -->
                <?php if (user_has_page_access('maintenance')): ?>
                    <a href="maintenance.php" title="POS Maintenance" class="flex items-center px-3 py-2.5 rounded-2xl text-xs sm:text-sm font-medium transition-colors duration-150 <?php echo ($active_page === 'maintenance') ? 'bg-[#EB3E0B] text-white shadow-sm shadow-[#EB3E0B]/20 font-bold' : 'text-slate-400 hover:bg-slate-800 hover:text-white'; ?>">
                        <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'maintenance') ? 'text-white' : 'text-slate-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                        </svg>
                        <span class="ml-3 opacity-0 group-hover:opacity-100 transition-opacity duration-200 whitespace-nowrap overflow-hidden">POS Maintenance</span>
                    </a>
                <?php endif; ?>

                <!-- Executive Analytics (Super Admin) -->
                <?php if (is_super_admin() || user_has_page_access('analytics')): ?>
                    <a href="analytics.php" title="Analytics" class="flex items-center px-3 py-2.5 rounded-2xl text-xs sm:text-sm font-medium transition-colors duration-150 <?php echo ($active_page === 'analytics') ? 'bg-[#EB3E0B] text-white shadow-sm shadow-[#EB3E0B]/20 font-bold' : 'text-slate-400 hover:bg-slate-800 hover:text-white'; ?>">
                        <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'analytics') ? 'text-white' : 'text-slate-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                        <span class="ml-3 opacity-0 group-hover:opacity-100 transition-opacity duration-200 whitespace-nowrap overflow-hidden">Analytics</span>
                    </a>
                <?php endif; ?>

                <!-- Admin Settings & Access Levels -->
                <?php if (user_has_page_access('settings')): ?>
                    <a href="settings.php" title="Admin Settings" class="flex items-center px-3 py-2.5 rounded-2xl text-xs sm:text-sm font-medium transition-colors duration-150 <?php echo ($active_page === 'settings') ? 'bg-[#EB3E0B] text-white shadow-sm shadow-[#EB3E0B]/20 font-bold' : 'text-slate-400 hover:bg-slate-800 hover:text-white'; ?>">
                        <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'settings') ? 'text-white' : 'text-slate-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <span class="ml-3 opacity-0 group-hover:opacity-100 transition-opacity duration-200 whitespace-nowrap overflow-hidden">Admin Settings</span>
                    </a>
                <?php endif; ?>
            </nav>
        </div>

        <!-- Tech Profile Summary at bottom of desktop sidebar -->
        <div class="p-3 m-2 bg-slate-800/80 border border-slate-700/60 rounded-2xl shadow-sm">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-2.5 overflow-hidden">
                    <div class="w-8 h-8 rounded-full bg-[#EB3E0B] text-white font-bold text-xs flex items-center justify-center shrink-0 shadow-sm">
                        <?php echo strtoupper(substr($tech_name, 0, 1)); ?>
                    </div>
                    <div class="truncate opacity-0 group-hover:opacity-100 transition-opacity duration-200 whitespace-nowrap overflow-hidden">
                        <p class="text-xs font-bold text-white truncate"><?php echo sanitize($tech_name); ?></p>
                        <p class="text-[10px] text-[#FEAA73] font-mono"><?php echo sanitize($access_level); ?></p>
                    </div>
                </div>
                <a href="logout.php" title="Sign Out" class="text-slate-400 hover:text-rose-400 transition-colors p-1 rounded-lg hover:bg-slate-700/60 opacity-0 group-hover:opacity-100">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                    </svg>
                </a>
            </div>
        </div>
    </aside>
</div>

<!-- ========================================================================= -->
<!-- 2. MOBILE SLIDE-OUT DRAWER OVERLAY (Screens < md) -->
<!-- ========================================================================= -->
<div id="adminMobileSidebarBackdrop" onclick="closeAdminMobileSidebar()" class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 hidden transition-opacity duration-300 md:hidden"></div>

<aside id="adminMobileSidebarDrawer" class="fixed top-0 left-0 bottom-0 w-80 max-w-[85vw] bg-slate-900 border-r border-slate-800 text-slate-200 z-50 transform -translate-x-full transition-transform duration-300 ease-in-out flex flex-col justify-between shadow-2xl p-5 overflow-y-auto md:hidden">
    <div class="space-y-6">
        <!-- Mobile Drawer Header -->
        <div class="flex items-center justify-between border-b border-slate-800 pb-4">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-2xl bg-[#EB3E0B] flex items-center justify-center text-white font-bold text-lg shadow-sm shadow-[#EB3E0B]/30 shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                    </svg>
                </div>
                <div>
                    <h2 class="font-extrabold text-white text-base leading-tight">RNZ Support</h2>
                    <span class="text-[11px] text-[#FEAA73] font-mono uppercase tracking-wider">Support Center</span>
                </div>
            </div>
            <!-- Close Button -->
            <button type="button" onclick="closeAdminMobileSidebar()" class="text-slate-400 hover:text-white p-2 rounded-2xl hover:bg-slate-800 transition-colors focus:outline-none" aria-label="Close navigation menu">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- Mobile Navigation List -->
        <nav class="space-y-1.5">
            <!-- Dashboard -->
            <?php if (user_has_page_access('dashboard')): ?>
                <a href="index.php" onclick="closeAdminMobileSidebar()" class="flex items-center space-x-3.5 px-4 py-3 rounded-2xl text-sm font-semibold transition-all <?php echo ($active_page === 'dashboard') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/25 font-bold' : 'text-slate-400 hover:bg-slate-800 hover:text-white'; ?>">
                    <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'dashboard') ? 'text-white' : 'text-slate-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                    </svg>
                    <span>Dashboard</span>
                </a>
            <?php endif; ?>

            <!-- Support Tickets -->
            <?php if (user_has_page_access('tickets')): ?>
                <a href="tickets.php" onclick="closeAdminMobileSidebar()" class="flex items-center space-x-3.5 px-4 py-3 rounded-2xl text-sm font-semibold transition-all <?php echo ($active_page === 'tickets') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/25 font-bold' : 'text-slate-400 hover:bg-slate-800 hover:text-white'; ?>">
                    <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'tickets') ? 'text-white' : 'text-slate-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 002 2 2 2 0 010 4 2 2 0 00-2 2v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 00-2-2 2 2 0 010-4 2 2 0 002-2V7a2 2 0 00-2-2H5z"/>
                    </svg>
                    <span>Support Tickets</span>
                </a>
            <?php endif; ?>

            <!-- Client Hardware Orders -->
            <?php if (user_has_page_access('orders')): ?>
                <a href="orders.php" onclick="closeAdminMobileSidebar()" class="flex items-center justify-between px-4 py-3 rounded-2xl text-sm font-semibold transition-all <?php echo ($active_page === 'orders') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/25 font-bold' : 'text-slate-400 hover:bg-slate-800 hover:text-white'; ?>">
                    <div class="flex items-center space-x-3.5">
                        <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'orders') ? 'text-white' : 'text-slate-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                        </svg>
                        <span>Hardware Orders</span>
                    </div>
                    <?php if ($pending_orders_count > 0): ?>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-mono font-bold bg-amber-500 text-slate-950">
                            <?php echo $pending_orders_count; ?>
                        </span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>

            <!-- Manage Accounts -->
            <?php if (user_has_page_access('accounts')): ?>
                <a href="accounts.php" onclick="closeAdminMobileSidebar()" class="flex items-center space-x-3.5 px-4 py-3 rounded-2xl text-sm font-semibold transition-all <?php echo ($active_page === 'accounts') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/25 font-bold' : 'text-slate-400 hover:bg-slate-800 hover:text-white'; ?>">
                    <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'accounts') ? 'text-white' : 'text-slate-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5m0 0h4m-4 0V11m0 0h4m-4 0H9m4 0V7m0 0h4m-4 0H9"/>
                    </svg>
                    <span>Manage Accounts</span>
                </a>
            <?php endif; ?>

            <!-- Hardware Inventory -->
            <?php if (user_has_page_access('inventory')): ?>
                <a href="inventory.php" onclick="closeAdminMobileSidebar()" class="flex items-center space-x-3.5 px-4 py-3 rounded-2xl text-sm font-semibold transition-all <?php echo ($active_page === 'inventory') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/25 font-bold' : 'text-slate-400 hover:bg-slate-800 hover:text-white'; ?>">
                    <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'inventory') ? 'text-white' : 'text-slate-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                    <span>Hardware Inventory</span>
                </a>
            <?php endif; ?>

            <!-- POS Maintenance -->
            <?php if (user_has_page_access('inventory')): ?>
                <a href="pullout_reports.php" onclick="closeAdminMobileSidebar()" class="flex items-center space-x-3.5 px-4 py-3 rounded-2xl text-sm font-semibold transition-all <?php echo ($active_page === 'pullout_reports') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/25 font-bold' : 'text-slate-400 hover:bg-slate-800 hover:text-white'; ?>">
                    <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'pullout_reports') ? 'text-white' : 'text-slate-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <span>Pull-Out Reports</span>
                </a>
            <?php endif; ?>

            <?php if (user_has_page_access('maintenance')): ?>
                <a href="maintenance.php" onclick="closeAdminMobileSidebar()" class="flex items-center space-x-3.5 px-4 py-3 rounded-2xl text-sm font-semibold transition-all <?php echo ($active_page === 'maintenance') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/25 font-bold' : 'text-slate-400 hover:bg-slate-800 hover:text-white'; ?>">
                    <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'maintenance') ? 'text-white' : 'text-slate-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                    </svg>
                    <span>POS Maintenance</span>
                </a>
            <?php endif; ?>

            <!-- Executive Analytics (Super Admin) -->
            <?php if (is_super_admin() || user_has_page_access('analytics')): ?>
                <a href="analytics.php" onclick="closeAdminMobileSidebar()" class="flex items-center space-x-3.5 px-4 py-3 rounded-2xl text-sm font-semibold transition-all <?php echo ($active_page === 'analytics') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/25 font-bold' : 'text-slate-400 hover:bg-slate-800 hover:text-white'; ?>">
                    <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'analytics') ? 'text-white' : 'text-slate-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                    <span>Analytics</span>
                </a>
            <?php endif; ?>

            <!-- Admin Settings & Access Levels -->
            <?php if (user_has_page_access('settings')): ?>
                <a href="settings.php" onclick="closeAdminMobileSidebar()" class="flex items-center space-x-3.5 px-4 py-3 rounded-2xl text-sm font-semibold transition-all <?php echo ($active_page === 'settings') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/25 font-bold' : 'text-slate-400 hover:bg-slate-800 hover:text-white'; ?>">
                    <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'settings') ? 'text-white' : 'text-slate-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    <span>Admin Settings</span>
                </a>
            <?php endif; ?>
        </nav>
    </div>

    <!-- Mobile Drawer Footer / Tech Profile Card -->
    <div class="mt-6 pt-4 border-t border-slate-800 space-y-3">
        <div class="p-3 bg-slate-800/90 border border-slate-700/60 rounded-2xl flex items-center justify-between shadow-sm">
            <div class="flex items-center space-x-3 overflow-hidden">
                <div class="w-9 h-9 rounded-2xl bg-[#EB3E0B] text-white font-bold text-sm flex items-center justify-center shrink-0 shadow-sm">
                    <?php echo strtoupper(substr($tech_name, 0, 1)); ?>
                </div>
                <div class="truncate">
                    <p class="text-xs font-bold text-white truncate"><?php echo sanitize($tech_name); ?></p>
                    <p class="text-[10px] text-[#FEAA73] font-mono"><?php echo sanitize($access_level); ?></p>
                </div>
            </div>
            <a href="logout.php" title="Sign Out" class="text-slate-400 hover:text-rose-400 p-2 rounded-xl hover:bg-slate-700/60 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                </svg>
            </a>
        </div>
    </div>
</aside>

<!-- ========================================================================= -->
<!-- 3. MOBILE STICKY BOTTOM NAVIGATION BAR (Screens < md) -->
<!-- ========================================================================= -->
<nav class="fixed bottom-0 left-0 right-0 bg-slate-900/95 backdrop-blur-lg border-t border-slate-800 px-2 py-1.5 flex items-center justify-around z-40 shadow-[0_-4px_20px_rgba(0,0,0,0.3)] md:hidden">
    <!-- Home / Dashboard -->
    <?php if (user_has_page_access('dashboard')): ?>
        <a href="index.php" class="flex flex-col items-center justify-center py-1 px-2.5 rounded-2xl text-[10px] font-bold transition-all <?php echo ($active_page === 'dashboard') ? 'text-[#EB3E0B]' : 'text-slate-400 hover:text-white'; ?>">
            <svg class="w-5 h-5 mb-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="<?php echo ($active_page === 'dashboard') ? '2.5' : '1.8'; ?>" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
            </svg>
            <span>Home</span>
        </a>
    <?php endif; ?>

    <!-- Tickets -->
    <?php if (user_has_page_access('tickets')): ?>
        <a href="tickets.php" class="flex flex-col items-center justify-center py-1 px-2.5 rounded-2xl text-[10px] font-bold transition-all <?php echo ($active_page === 'tickets') ? 'text-[#EB3E0B]' : 'text-slate-400 hover:text-white'; ?>">
            <svg class="w-5 h-5 mb-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="<?php echo ($active_page === 'tickets') ? '2.5' : '1.8'; ?>" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 002 2 2 2 0 010 4 2 2 0 00-2 2v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 00-2-2 2 2 0 010-4 2 2 0 002-2V7a2 2 0 00-2-2H5z"/>
            </svg>
            <span>Tickets</span>
        </a>
    <?php endif; ?>

    <!-- Accounts -->
    <?php if (user_has_page_access('accounts')): ?>
        <a href="accounts.php" class="flex flex-col items-center justify-center py-1 px-2.5 rounded-2xl text-[10px] font-bold transition-all <?php echo ($active_page === 'accounts') ? 'text-[#EB3E0B]' : 'text-slate-400 hover:text-white'; ?>">
            <svg class="w-5 h-5 mb-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="<?php echo ($active_page === 'accounts') ? '2.5' : '1.8'; ?>" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5m0 0h4m-4 0V11m0 0h4m-4 0H9m4 0V7m0 0h4m-4 0H9"/>
            </svg>
            <span>Accounts</span>
        </a>
    <?php endif; ?>

    <!-- Inventory -->
    <?php if (user_has_page_access('inventory')): ?>
        <a href="inventory.php" class="flex flex-col items-center justify-center py-1 px-2.5 rounded-2xl text-[10px] font-bold transition-all <?php echo ($active_page === 'inventory') ? 'text-[#EB3E0B]' : 'text-slate-400 hover:text-white'; ?>">
            <svg class="w-5 h-5 mb-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="<?php echo ($active_page === 'inventory') ? '2.5' : '1.8'; ?>" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
            </svg>
            <span>Stock</span>
        </a>
    <?php endif; ?>

    <!-- Menu Trigger -->
    <button type="button" onclick="openAdminMobileSidebar()" class="flex flex-col items-center justify-center py-1 px-2.5 rounded-2xl text-[10px] font-bold text-slate-400 hover:text-white transition-all focus:outline-none">
        <svg class="w-5 h-5 mb-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
        <span>Menu</span>
    </button>
</nav>

<!-- ========================================================================= -->
<!-- 4. SIDEBAR JAVASCRIPT HANDLERS -->
<!-- ========================================================================= -->
<script>
function openAdminMobileSidebar() {
    var drawer = document.getElementById('adminMobileSidebarDrawer');
    var backdrop = document.getElementById('adminMobileSidebarBackdrop');
    if (drawer && backdrop) {
        backdrop.classList.remove('hidden');
        drawer.classList.remove('-translate-x-full');
        drawer.classList.add('translate-x-0');
        document.body.classList.add('overflow-hidden');
    }
}

function closeAdminMobileSidebar() {
    var drawer = document.getElementById('adminMobileSidebarDrawer');
    var backdrop = document.getElementById('adminMobileSidebarBackdrop');
    if (drawer && backdrop) {
        drawer.classList.remove('translate-x-0');
        drawer.classList.add('-translate-x-full');
        backdrop.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }
}

// Auto attach to header button with id 'mobile-menu-btn'
document.addEventListener('DOMContentLoaded', function() {
    var btn = document.getElementById('mobile-menu-btn');
    if (btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            openAdminMobileSidebar();
        });
    }
});

// Close mobile sidebar on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAdminMobileSidebar();
    }
});
</script>
