<?php
// Sidebar Navigation Component (Desktop Hover-Expand & Full Mobile Drawer + Bottom Nav)
if (!isset($active_page)) {
    $active_page = 'dashboard';
}

$client = get_logged_client();
$tradename = $client ? $client['tradename'] : 'Client Portal';
$accountnum = $client ? $client['accountnum'] : '';
?>

<!-- ========================================================================= -->
<!-- 1. DESKTOP FLOATING AUTO-EXPAND SIDEBAR (Screens >= md) -->
<!-- ========================================================================= -->
<div class="relative w-16 hidden md:block shrink-0 z-40 min-h-screen">
    <aside class="fixed top-0 left-0 bottom-0 w-16 hover:w-64 bg-[#FFF5ED] border-r border-[#FECDAA] flex flex-col justify-between transition-[width,box-shadow] duration-200 ease-out group overflow-hidden shadow-md hover:shadow-2xl z-40 will-change-[width]">
        <div class="p-3.5 space-y-6">
            <!-- Brand Logo -->
            <div class="flex items-center space-x-3 px-1">
                <div class="w-9 h-9 rounded-2xl bg-[#EB3E0B] flex items-center justify-center text-white font-bold text-lg shadow-sm shadow-[#EB3E0B]/25 shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
                <div class="opacity-0 group-hover:opacity-100 transition-opacity duration-200 whitespace-nowrap overflow-hidden">
                    <h1 class="font-extrabold text-[#430D07] text-sm leading-tight">RNZ Support</h1>
                    <span class="text-[10px] text-[#9A2512] font-semibold uppercase tracking-wider">Client Portal</span>
                </div>
            </div>

            <!-- Navigation Links -->
            <nav class="space-y-1.5">
                <!-- Dashboard -->
                <a href="index.php" title="Dashboard" class="flex items-center px-3 py-2.5 rounded-2xl text-xs sm:text-sm font-medium transition-colors duration-150 <?php echo ($active_page === 'dashboard') ? 'bg-[#EB3E0B] text-white shadow-sm shadow-[#EB3E0B]/20 font-bold' : 'text-[#7C2112] hover:bg-[#FFE8D5] hover:text-[#430D07]'; ?>">
                    <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'dashboard') ? 'text-white' : 'text-[#FA5915]'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                    </svg>
                    <span class="ml-3 opacity-0 group-hover:opacity-100 transition-opacity duration-200 whitespace-nowrap overflow-hidden">Dashboard</span>
                </a>

                <!-- Support Tickets -->
                <a href="tickets" title="Support Tickets" class="flex items-center px-3 py-2.5 rounded-2xl text-xs sm:text-sm font-medium transition-colors duration-150 <?php echo ($active_page === 'tickets') ? 'bg-[#EB3E0B] text-white shadow-sm shadow-[#EB3E0B]/20 font-bold' : 'text-[#7C2112] hover:bg-[#FFE8D5] hover:text-[#430D07]'; ?>">
                    <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'tickets') ? 'text-white' : 'text-[#FA5915]'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 002 2 2 2 0 010 4 2 2 0 00-2 2v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 00-2-2 2 2 0 010-4 2 2 0 002-2V7a2 2 0 00-2-2H5z"/>
                    </svg>
                    <span class="ml-3 opacity-0 group-hover:opacity-100 transition-opacity duration-200 whitespace-nowrap overflow-hidden">Support Tickets</span>
                </a>

                <!-- Hardware Devices -->
                <a href="hardware" title="Hardware Devices" class="flex items-center px-3 py-2.5 rounded-2xl text-xs sm:text-sm font-medium transition-colors duration-150 <?php echo ($active_page === 'hardware') ? 'bg-[#EB3E0B] text-white shadow-sm shadow-[#EB3E0B]/20 font-bold' : 'text-[#7C2112] hover:bg-[#FFE8D5] hover:text-[#430D07]'; ?>">
                    <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'hardware') ? 'text-white' : 'text-[#FA5915]'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
                    </svg>
                    <span class="ml-3 opacity-0 group-hover:opacity-100 transition-opacity duration-200 whitespace-nowrap overflow-hidden">Hardware Devices</span>
                </a>

                <!-- Software Issues -->
                <a href="software" title="Software Issues" class="flex items-center px-3 py-2.5 rounded-2xl text-xs sm:text-sm font-medium transition-colors duration-150 <?php echo ($active_page === 'software') ? 'bg-[#EB3E0B] text-white shadow-sm shadow-[#EB3E0B]/20 font-bold' : 'text-[#7C2112] hover:bg-[#FFE8D5] hover:text-[#430D07]'; ?>">
                    <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'software') ? 'text-white' : 'text-[#FA5915]'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
                    </svg>
                    <span class="ml-3 opacity-0 group-hover:opacity-100 transition-opacity duration-200 whitespace-nowrap overflow-hidden">Software Issues</span>
                </a>

                <!-- POS Maintenance -->
                <a href="maintenance" title="POS Maintenance" class="flex items-center px-3 py-2.5 rounded-2xl text-xs sm:text-sm font-medium transition-colors duration-150 <?php echo ($active_page === 'maintenance') ? 'bg-[#EB3E0B] text-white shadow-sm shadow-[#EB3E0B]/20 font-bold' : 'text-[#7C2112] hover:bg-[#FFE8D5] hover:text-[#430D07]'; ?>">
                    <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'maintenance') ? 'text-white' : 'text-[#FA5915]'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    <span class="ml-3 opacity-0 group-hover:opacity-100 transition-opacity duration-200 whitespace-nowrap overflow-hidden">POS Maintenance</span>
                </a>

                <!-- Tech Service History -->
                <a href="technotes" title="Tech Service History" class="flex items-center px-3 py-2.5 rounded-2xl text-xs sm:text-sm font-medium transition-colors duration-150 <?php echo ($active_page === 'technotes') ? 'bg-[#EB3E0B] text-white shadow-sm shadow-[#EB3E0B]/20 font-bold' : 'text-[#7C2112] hover:bg-[#FFE8D5] hover:text-[#430D07]'; ?>">
                    <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'technotes') ? 'text-white' : 'text-[#FA5915]'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <span class="ml-3 opacity-0 group-hover:opacity-100 transition-opacity duration-200 whitespace-nowrap overflow-hidden">Tech Service History</span>
                </a>

                <!-- Work Orders -->
                <a href="workorders" title="Work Orders" class="flex items-center px-3 py-2.5 rounded-2xl text-xs sm:text-sm font-medium transition-colors duration-150 <?php echo ($active_page === 'workorders') ? 'bg-[#EB3E0B] text-white shadow-sm shadow-[#EB3E0B]/20 font-bold' : 'text-[#7C2112] hover:bg-[#FFE8D5] hover:text-[#430D07]'; ?>">
                    <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'workorders') ? 'text-white' : 'text-[#FA5915]'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                    <span class="ml-3 opacity-0 group-hover:opacity-100 transition-opacity duration-200 whitespace-nowrap overflow-hidden">Work Orders</span>
                </a>

                <!-- Account Details -->
                <a href="profile" title="Account Details" class="flex items-center px-3 py-2.5 rounded-2xl text-xs sm:text-sm font-medium transition-colors duration-150 <?php echo ($active_page === 'profile') ? 'bg-[#EB3E0B] text-white shadow-sm shadow-[#EB3E0B]/20 font-bold' : 'text-[#7C2112] hover:bg-[#FFE8D5] hover:text-[#430D07]'; ?>">
                    <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'profile') ? 'text-white' : 'text-[#FA5915]'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                    <span class="ml-3 opacity-0 group-hover:opacity-100 transition-opacity duration-200 whitespace-nowrap overflow-hidden">Account Details</span>
                </a>
            </nav>
        </div>

        <!-- Client Profile Summary at bottom of desktop sidebar -->
        <div class="p-3 m-2 bg-[#FFE8D5] border border-[#FECDAA] rounded-2xl shadow-sm">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-2.5 overflow-hidden">
                    <div class="w-8 h-8 rounded-full bg-[#EB3E0B] text-white font-bold text-xs flex items-center justify-center shrink-0 shadow-sm">
                        <?php echo strtoupper(substr($tradename, 0, 1)); ?>
                    </div>
                    <div class="truncate opacity-0 group-hover:opacity-100 transition-opacity duration-200 whitespace-nowrap overflow-hidden">
                        <p class="text-xs font-bold text-[#430D07] truncate"><?php echo sanitize($tradename); ?></p>
                        <p class="text-[10px] text-[#7C2112] font-mono">Acct: <?php echo sanitize($accountnum); ?></p>
                    </div>
                </div>
                <a href="logout.php" title="Sign Out" class="text-[#9A2512] hover:text-rose-600 transition-colors p-1 rounded-lg hover:bg-white/60 opacity-0 group-hover:opacity-100">
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
<div id="mobileSidebarBackdrop" onclick="closeMobileSidebar()" class="fixed inset-0 bg-[#430D07]/60 backdrop-blur-sm z-50 hidden transition-opacity duration-300 md:hidden"></div>

<aside id="mobileSidebarDrawer" class="fixed top-0 left-0 bottom-0 w-80 max-w-[85vw] bg-[#FFF5ED] border-r border-[#FECDAA] z-50 transform -translate-x-full transition-transform duration-300 ease-in-out flex flex-col justify-between shadow-2xl p-5 overflow-y-auto md:hidden">
    <div class="space-y-6">
        <!-- Mobile Drawer Header -->
        <div class="flex items-center justify-between border-b border-[#FFE8D5] pb-4">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-2xl bg-[#EB3E0B] flex items-center justify-center text-white font-bold text-lg shadow-sm shadow-[#EB3E0B]/30 shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="font-extrabold text-[#430D07] text-base leading-tight">RNZ Support</h2>
                    <span class="text-[11px] text-[#9A2512] font-semibold uppercase tracking-wider">Client Portal</span>
                </div>
            </div>
            <!-- Close Button -->
            <button type="button" onclick="closeMobileSidebar()" class="text-[#9A2512] hover:text-[#430D07] p-2 rounded-2xl hover:bg-[#FFE8D5] transition-colors focus:outline-none" aria-label="Close navigation menu">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- Mobile Navigation List -->
        <nav class="space-y-1.5">
            <!-- Dashboard -->
            <a href="index.php" onclick="closeMobileSidebar()" class="flex items-center space-x-3.5 px-4 py-3 rounded-2xl text-sm font-semibold transition-all <?php echo ($active_page === 'dashboard') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/25 font-bold' : 'text-[#7C2112] hover:bg-[#FFE8D5] hover:text-[#430D07]'; ?>">
                <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'dashboard') ? 'text-white' : 'text-[#FA5915]'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                <span>Dashboard</span>
            </a>

            <!-- Support Tickets -->
            <a href="tickets" onclick="closeMobileSidebar()" class="flex items-center space-x-3.5 px-4 py-3 rounded-2xl text-sm font-semibold transition-all <?php echo ($active_page === 'tickets') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/25 font-bold' : 'text-[#7C2112] hover:bg-[#FFE8D5] hover:text-[#430D07]'; ?>">
                <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'tickets') ? 'text-white' : 'text-[#FA5915]'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 002 2 2 2 0 010 4 2 2 0 00-2 2v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 00-2-2 2 2 0 010-4 2 2 0 002-2V7a2 2 0 00-2-2H5z"/>
                </svg>
                <span>Support Tickets</span>
            </a>

            <!-- Hardware Devices -->
            <a href="hardware" onclick="closeMobileSidebar()" class="flex items-center space-x-3.5 px-4 py-3 rounded-2xl text-sm font-semibold transition-all <?php echo ($active_page === 'hardware') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/25 font-bold' : 'text-[#7C2112] hover:bg-[#FFE8D5] hover:text-[#430D07]'; ?>">
                <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'hardware') ? 'text-white' : 'text-[#FA5915]'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
                </svg>
                <span>Hardware Devices</span>
            </a>

            <!-- Software Issues -->
            <a href="software" onclick="closeMobileSidebar()" class="flex items-center space-x-3.5 px-4 py-3 rounded-2xl text-sm font-semibold transition-all <?php echo ($active_page === 'software') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/25 font-bold' : 'text-[#7C2112] hover:bg-[#FFE8D5] hover:text-[#430D07]'; ?>">
                <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'software') ? 'text-white' : 'text-[#FA5915]'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
                </svg>
                <span>Software Issues</span>
            </a>

            <!-- POS Maintenance -->
            <a href="maintenance" onclick="closeMobileSidebar()" class="flex items-center space-x-3.5 px-4 py-3 rounded-2xl text-sm font-semibold transition-all <?php echo ($active_page === 'maintenance') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/25 font-bold' : 'text-[#7C2112] hover:bg-[#FFE8D5] hover:text-[#430D07]'; ?>">
                <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'maintenance') ? 'text-white' : 'text-[#FA5915]'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <span>POS Maintenance</span>
            </a>

            <!-- Tech Service History -->
            <a href="technotes" onclick="closeMobileSidebar()" class="flex items-center space-x-3.5 px-4 py-3 rounded-2xl text-sm font-semibold transition-all <?php echo ($active_page === 'technotes') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/25 font-bold' : 'text-[#7C2112] hover:bg-[#FFE8D5] hover:text-[#430D07]'; ?>">
                <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'technotes') ? 'text-white' : 'text-[#FA5915]'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <span>Tech Service History</span>
            </a>

            <!-- Work Orders -->
            <a href="workorders" onclick="closeMobileSidebar()" class="flex items-center space-x-3.5 px-4 py-3 rounded-2xl text-sm font-semibold transition-all <?php echo ($active_page === 'workorders') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/25 font-bold' : 'text-[#7C2112] hover:bg-[#FFE8D5] hover:text-[#430D07]'; ?>">
                <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'workorders') ? 'text-white' : 'text-[#FA5915]'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
                <span>Work Orders</span>
            </a>

            <!-- Account Details -->
            <a href="profile" onclick="closeMobileSidebar()" class="flex items-center space-x-3.5 px-4 py-3 rounded-2xl text-sm font-semibold transition-all <?php echo ($active_page === 'profile') ? 'bg-[#EB3E0B] text-white shadow-md shadow-[#EB3E0B]/25 font-bold' : 'text-[#7C2112] hover:bg-[#FFE8D5] hover:text-[#430D07]'; ?>">
                <svg class="w-5 h-5 shrink-0 <?php echo ($active_page === 'profile') ? 'text-white' : 'text-[#FA5915]'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
                <span>Account Details</span>
            </a>
        </nav>
    </div>

    <!-- Mobile Drawer Footer / Client Profile Card -->
    <div class="mt-6 pt-4 border-t border-[#FFE8D5] space-y-3">
        <div class="p-3 bg-[#FFE8D5] border border-[#FECDAA] rounded-2xl flex items-center justify-between shadow-sm">
            <div class="flex items-center space-x-3 overflow-hidden">
                <div class="w-9 h-9 rounded-2xl bg-[#EB3E0B] text-white font-bold text-sm flex items-center justify-center shrink-0 shadow-sm">
                    <?php echo strtoupper(substr($tradename, 0, 1)); ?>
                </div>
                <div class="truncate">
                    <p class="text-xs font-bold text-[#430D07] truncate"><?php echo sanitize($tradename); ?></p>
                    <p class="text-[10px] text-[#7C2112] font-mono">Acct: <?php echo sanitize($accountnum); ?></p>
                </div>
            </div>
            <a href="logout" title="Sign Out" class="text-[#9A2512] hover:text-rose-600 p-2 rounded-xl hover:bg-white/80 transition-colors">
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
<nav class="fixed bottom-0 left-0 right-0 bg-[#FFF5ED]/95 backdrop-blur-lg border-t border-[#FECDAA] px-2 py-1.5 flex items-center justify-around z-40 shadow-[0_-4px_20px_rgba(67,13,7,0.08)] md:hidden">
    <!-- Home / Dashboard -->
    <a href="index.php" class="flex flex-col items-center justify-center py-1 px-2.5 rounded-2xl text-[10px] font-bold transition-all <?php echo ($active_page === 'dashboard') ? 'text-[#EB3E0B]' : 'text-[#7C2112] hover:text-[#430D07]'; ?>">
        <svg class="w-5 h-5 mb-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="<?php echo ($active_page === 'dashboard') ? '2.5' : '1.8'; ?>" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
        </svg>
        <span>Home</span>
    </a>

    <!-- Tickets -->
    <a href="tickets" class="flex flex-col items-center justify-center py-1 px-2.5 rounded-2xl text-[10px] font-bold transition-all <?php echo ($active_page === 'tickets') ? 'text-[#EB3E0B]' : 'text-[#7C2112] hover:text-[#430D07]'; ?>">
        <svg class="w-5 h-5 mb-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="<?php echo ($active_page === 'tickets') ? '2.5' : '1.8'; ?>" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 002 2 2 2 0 010 4 2 2 0 00-2 2v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 00-2-2 2 2 0 010-4 2 2 0 002-2V7a2 2 0 00-2-2H5z"/>
        </svg>
        <span>Tickets</span>
    </a>

    <!-- Hardware -->
    <a href="hardware" class="flex flex-col items-center justify-center py-1 px-2.5 rounded-2xl text-[10px] font-bold transition-all <?php echo ($active_page === 'hardware') ? 'text-[#EB3E0B]' : 'text-[#7C2112] hover:text-[#430D07]'; ?>">
        <svg class="w-5 h-5 mb-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="<?php echo ($active_page === 'hardware') ? '2.5' : '1.8'; ?>" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
        </svg>
        <span>Hardware</span>
    </a>

    <!-- Maintenance -->
    <a href="maintenance" class="flex flex-col items-center justify-center py-1 px-2.5 rounded-2xl text-[10px] font-bold transition-all <?php echo ($active_page === 'maintenance') ? 'text-[#EB3E0B]' : 'text-[#7C2112] hover:text-[#430D07]'; ?>">
        <svg class="w-5 h-5 mb-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="<?php echo ($active_page === 'maintenance') ? '2.5' : '1.8'; ?>" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
        </svg>
        <span>Service</span>
    </a>

    <!-- Menu Trigger -->
    <button type="button" onclick="openMobileSidebar()" class="flex flex-col items-center justify-center py-1 px-2.5 rounded-2xl text-[10px] font-bold text-[#7C2112] hover:text-[#430D07] transition-all focus:outline-none">
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
function openMobileSidebar() {
    var drawer = document.getElementById('mobileSidebarDrawer');
    var backdrop = document.getElementById('mobileSidebarBackdrop');
    if (drawer && backdrop) {
        backdrop.classList.remove('hidden');
        drawer.classList.remove('-translate-x-full');
        drawer.classList.add('translate-x-0');
        document.body.classList.add('overflow-hidden');
    }
}

function closeMobileSidebar() {
    var drawer = document.getElementById('mobileSidebarDrawer');
    var backdrop = document.getElementById('mobileSidebarBackdrop');
    if (drawer && backdrop) {
        drawer.classList.remove('translate-x-0');
        drawer.classList.add('-translate-x-full');
        backdrop.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }
}

function toggleMobileSidebar() {
    var drawer = document.getElementById('mobileSidebarDrawer');
    if (drawer && drawer.classList.contains('-translate-x-full')) {
        openMobileSidebar();
    } else {
        closeMobileSidebar();
    }
}

// Auto attach to any header button with id 'mobile-menu-btn'
document.addEventListener('DOMContentLoaded', function() {
    var btn = document.getElementById('mobile-menu-btn');
    if (btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            openMobileSidebar();
        });
    }
});

// Close mobile sidebar on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeMobileSidebar();
    }
});
</script>
