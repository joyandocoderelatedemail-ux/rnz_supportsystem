<?php
// Hardware Devices Directory & Troubleshooting Wizard Module (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db_init.php';
require_once __DIR__ . '/includes/hardware_data.php';

require_login();
$client = get_logged_client();
$accountnum = $client['accountnum'];

$pdo = get_db_connection();

$all_hardware_list = get_hardware_devices_list();

// Get selected device (if any)
$selected_device = isset($_GET['device']) ? trim($_GET['device']) : '';
$device = null;

if (!empty($selected_device)) {
    $device = get_hardware_device($selected_device);
}

// Current Wizard Step State: if no device selected, default to 'catalog'
$wizard_step = isset($_GET['step']) ? trim($_GET['step']) : ($device ? 'overview' : 'catalog');
if (!$device && $wizard_step !== 'catalog') {
    $wizard_step = 'catalog';
}

$q_idx = isset($_GET['q_idx']) ? intval($_GET['q_idx']) : 0;
$s_idx = isset($_GET['s_idx']) ? intval($_GET['s_idx']) : 1;
$search_q = isset($_GET['q']) ? trim($_GET['q']) : '';

// Session ID for logging
if (!isset($_SESSION['troubleshoot_session_id'])) {
    $_SESSION['troubleshoot_session_id'] = 'SESS-' . uniqid() . '-' . rand(1000, 9999);
}
$session_id = $_SESSION['troubleshoot_session_id'];

// Log User Interaction to DB
function log_hardware_activity($pdo, $accountnum, $session_id, $device_name, $issue = null, $q_id = null, $answer = null, $custom_ans = null, $step_v = 0, $step_c = 0, $status = 'In Progress') {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Unknown';
    $now = date('Y-m-d H:i:s');

    // Detect simple OS & Browser
    $os = 'Windows';
    if (strpos($user_agent, 'Macintosh') !== false) { $os = 'macOS'; }
    elseif (strpos($user_agent, 'Linux') !== false) { $os = 'Linux'; }

    $browser = 'Browser';
    if (strpos($user_agent, 'Chrome') !== false) { $browser = 'Chrome'; }
    elseif (strpos($user_agent, 'Firefox') !== false) { $browser = 'Firefox'; }
    elseif (strpos($user_agent, 'Safari') !== false) { $browser = 'Safari'; }

    try {
        $stmt = $pdo->prepare("INSERT INTO hardware_troubleshooting_logs 
            (accountnum, session_id, ip_address, hardware_selected, issue_selected, question_id, selected_answer, custom_answer, step_viewed, step_completed, time_started, time_completed, resolution_status, browser, operating_system, device_type, created_at)
            VALUES (:acct, :sess, :ip, :hw, :issue, :qid, :ans, :custom, :sv, :sc, :t_start, :t_comp, :status, :br, :os, 'Desktop', :c_at)");

        $time_comp = ($status === 'Resolved' || $status === 'Unresolved') ? $now : null;

        $stmt->execute(array(
            ':acct' => $accountnum,
            ':sess' => $session_id,
            ':ip' => $ip,
            ':hw' => $device_name,
            ':issue' => $issue,
            ':qid' => $q_id,
            ':ans' => $answer,
            ':custom' => $custom_ans,
            ':sv' => $step_v,
            ':sc' => $step_c,
            ':t_start' => $now,
            ':t_comp' => $time_comp,
            ':status' => $status,
            ':br' => $browser,
            ':os' => $os,
            ':c_at' => $now
        ));
    } catch (PDOException $e) {
        error_log("Logging error: " . $e->getMessage());
    }
}

// Handle Form Submissions & State Transitions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $device) {
    $post_action = isset($_POST['action']) ? trim($_POST['action']) : '';

    if ($post_action === 'start_troubleshoot') {
        log_hardware_activity($pdo, $accountnum, $session_id, $device['name'], null, null, null, null, 0, 0, 'In Progress');
        header("Location: hardware.php?device=" . $selected_device . "&step=question&q_idx=0");
        exit;

    } elseif ($post_action === 'answer_question') {
        $q_id = isset($_POST['question_id']) ? trim($_POST['question_id']) : '';
        $answer = isset($_POST['selected_answer']) ? trim($_POST['selected_answer']) : '';
        $custom_ans = isset($_POST['custom_answer']) ? trim($_POST['custom_answer']) : '';

        // Save selected issue in session
        if (!empty($answer)) {
            $_SESSION['selected_issue'] = $answer;
        }
        if ($answer === 'Other' && !empty($custom_ans)) {
            $_SESSION['selected_issue'] = 'Other: ' . $custom_ans;
        }

        $current_issue = isset($_SESSION['selected_issue']) ? $_SESSION['selected_issue'] : 'General Hardware Issue';
        log_hardware_activity($pdo, $accountnum, $session_id, $device['name'], $current_issue, $q_id, $answer, $custom_ans, 0, 0, 'In Progress');

        $total_q = count($device['questions']);
        $next_q = $q_idx + 1;

        if ($next_q < $total_q) {
            header("Location: hardware.php?device=" . $selected_device . "&step=question&q_idx=" . $next_q);
            exit;
        } else {
            // Questions complete -> Move to Troubleshooting Steps
            header("Location: hardware.php?device=" . $selected_device . "&step=resolution&s_idx=1");
            exit;
        }

    } elseif ($post_action === 'step_feedback') {
        $feedback = isset($_POST['solved']) ? trim($_POST['solved']) : 'no';
        $curr_step = isset($_POST['current_step']) ? intval($_POST['current_step']) : 1;
        $current_issue = isset($_SESSION['selected_issue']) ? $_SESSION['selected_issue'] : 'General Hardware Issue';

        if ($feedback === 'yes') {
            log_hardware_activity($pdo, $accountnum, $session_id, $device['name'], $current_issue, null, 'Solved on Step ' . $curr_step, null, $curr_step, $curr_step, 'Resolved');
            header("Location: hardware.php?device=" . $selected_device . "&step=resolved");
            exit;
        } else {
            log_hardware_activity($pdo, $accountnum, $session_id, $device['name'], $current_issue, null, 'Unsolved on Step ' . $curr_step, null, $curr_step, $curr_step, 'In Progress');
            $next_step = $curr_step + 1;
            $total_steps = count($device['steps']);

            if ($next_step <= $total_steps) {
                header("Location: hardware.php?device=" . $selected_device . "&step=resolution&s_idx=" . $next_step);
                exit;
            } else {
                log_hardware_activity($pdo, $accountnum, $session_id, $device['name'], $current_issue, null, 'All steps exhausted', null, $total_steps, $total_steps, 'Unresolved');
                header("Location: hardware.php?device=" . $selected_device . "&step=unresolved");
                exit;
            }
        }
    }
}

$active_page = 'hardware';
$page_title = $device ? $device['name'] . ' Support' : 'Hardware Devices';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo sanitize($page_title); ?> - RNZ Client Portal</title>
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

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col min-w-0">
        <!-- Header -->
        <?php include __DIR__ . '/includes/header.php'; ?>

        <main class="p-4 sm:p-6 md:p-8 pb-24 md:pb-8 space-y-6 max-w-7xl w-full mx-auto">

            <!-- CATALOG VIEW: 15 HARDWARE DEVICES GRID -->
            <?php if ($wizard_step === 'catalog' || !$device): ?>
                
                <!-- Catalog Header Bar -->
                <div class="bg-white/90 rounded-3xl p-6 sm:p-8 border border-[#FECDAA] shadow-sm flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                    <div>
                        <div class="inline-flex items-center space-x-2 bg-[#FFE8D5] px-3 py-1 rounded-full text-[#EB3E0B] text-xs font-bold uppercase tracking-wider mb-2">
                            <span>Diagnostic Knowledge Base</span>
                        </div>
                        <h2 class="text-2xl font-extrabold text-[#430D07]">Hardware Devices Support</h2>
                        <p class="text-xs text-[#7C2112] mt-1">Select a device below to view common issues and launch the step-by-step diagnostic wizard.</p>
                    </div>

                    <!-- Search Filter -->
                    <form action="hardware.php" method="GET" class="w-full sm:w-72">
                        <div class="relative">
                            <input type="text" name="q" value="<?php echo sanitize($search_q); ?>" placeholder="Search hardware devices..." class="w-full bg-[#FFF5ED] text-[#430D07] text-xs pl-9 pr-4 py-2.5 rounded-full border border-[#FECDAA] focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all">
                            <svg class="w-4 h-4 text-[#9A2512] absolute left-3 top-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                        </div>
                    </form>
                </div>

                <!-- 15 Hardware Devices Catalog Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php 
                    foreach ($all_hardware_list as $h_key => $dev_info):
                        if (!empty($search_q)) {
                            if (stripos($dev_info['name'], $search_q) === false && stripos($dev_info['description'], $search_q) === false) {
                                continue;
                            }
                        }
                    ?>
                        <div class="bg-white/90 rounded-3xl p-6 border border-[#FECDAA] shadow-sm hover:shadow-md hover:border-[#FEAA73] transition-all flex flex-col justify-between group overflow-hidden">
                            <div class="space-y-4">
                                <!-- Hardware Photo Image Container -->
                                <div class="w-full h-44 rounded-2xl bg-[#FFF5ED] border border-[#FECDAA]/60 overflow-hidden flex items-center justify-center p-3 relative">
                                    <img src="<?php echo sanitize($dev_info['image']); ?>" alt="<?php echo sanitize($dev_info['name']); ?>" class="max-h-full max-w-full object-contain group-hover:scale-105 transition-transform duration-300">
                                    <span class="absolute top-3 right-3 text-[10px] font-mono font-bold bg-white/90 backdrop-blur-sm text-[#7C2112] px-2.5 py-1 rounded-full border border-[#FECDAA]">
                                        <?php echo count($dev_info['common_issues']); ?> Issues
                                    </span>
                                </div>

                                <!-- Device Title & Description -->
                                <div>
                                    <h3 class="text-base font-extrabold text-[#430D07] group-hover:text-[#EB3E0B] transition-colors">
                                        <?php echo sanitize($dev_info['name']); ?>
                                    </h3>
                                    <p class="text-xs text-[#7C2112] mt-1 line-clamp-2 leading-relaxed">
                                        <?php echo sanitize($dev_info['description']); ?>
                                    </p>
                                </div>

                                <!-- Preview Issues Badges -->
                                <div class="flex flex-wrap gap-1.5 pt-1">
                                    <?php 
                                    $preview_issues = array_slice($dev_info['common_issues'], 0, 3);
                                    foreach ($preview_issues as $p_issue): 
                                    ?>
                                        <span class="text-[10px] font-semibold bg-[#FFF5ED] text-[#9A2512] px-2 py-0.5 rounded-md border border-[#FECDAA]/60">
                                            <?php echo sanitize($p_issue); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Bottom Action Button -->
                            <div class="pt-6 border-t border-[#FFE8D5] mt-6">
                                <a href="hardware.php?device=<?php echo $h_key; ?>&step=overview" 
                                   class="w-full bg-[#FFE8D5] hover:bg-[#EB3E0B] text-[#430D07] hover:text-white font-bold text-xs py-3 px-4 rounded-full flex items-center justify-center space-x-2 transition-all shadow-sm">
                                    <span>Start Troubleshooting</span>
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                                    </svg>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>

                <!-- Back to Hardware List Button -->
                <a href="hardware.php" class="inline-flex items-center space-x-2 text-xs font-bold text-[#7C2112] hover:text-[#430D07] transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    <span>Back to All Hardware Devices</span>
                </a>

                <!-- Device Header Summary Card with Photo -->
                <div class="bg-white/90 rounded-3xl p-6 sm:p-8 border border-[#FECDAA] shadow-sm flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
                    <div class="flex items-center space-x-5">
                        <div class="w-24 h-24 rounded-2xl bg-[#FFF5ED] border border-[#FECDAA] flex items-center justify-center p-2 shrink-0 overflow-hidden shadow-sm">
                            <img src="<?php echo sanitize($device['image']); ?>" alt="<?php echo sanitize($device['name']); ?>" class="max-h-full max-w-full object-contain">
                        </div>
                        <div>
                            <div class="inline-flex items-center space-x-2 bg-[#FFE8D5] px-3 py-0.5 rounded-full text-[#EB3E0B] text-[11px] font-bold uppercase tracking-wider mb-1">
                                <span>Hardware Device</span>
                            </div>
                            <h2 class="text-2xl font-extrabold text-[#430D07]"><?php echo sanitize($device['name']); ?></h2>
                            <p class="text-xs text-[#7C2112] max-w-xl"><?php echo sanitize($device['description']); ?></p>
                        </div>
                    </div>

                    <a href="hardware.php?device=<?php echo $selected_device; ?>&step=overview" class="text-xs font-bold text-[#EB3E0B] hover:text-[#C32C0B] flex items-center space-x-1 shrink-0">
                        <span>Restart Diagnostics</span>
                    </a>
                </div>

                <!-- DEVICE OVERVIEW -->
                <?php if ($wizard_step === 'overview'): ?>
                    <div class="bg-white/90 rounded-3xl p-6 sm:p-8 border border-[#FECDAA] shadow-sm space-y-6">
                        <h3 class="text-lg font-extrabold text-[#430D07] border-b border-[#FFE8D5] pb-4">
                            Common Issues & Diagnostics
                        </h3>

                        <p class="text-xs text-[#7C2112]">Select a common issue below or launch our interactive step-by-step diagnostic wizard to isolate and resolve your hardware problem.</p>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <?php foreach ($device['common_issues'] as $issue): ?>
                                <div class="p-4 rounded-2xl bg-[#FFF5ED] border border-[#FECDAA] flex items-center space-x-3">
                                    <div class="w-2 h-2 rounded-full bg-[#EB3E0B] shrink-0"></div>
                                    <span class="text-xs font-bold text-[#430D07]"><?php echo sanitize($issue); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="pt-4 border-t border-[#FFE8D5] flex items-center justify-between">
                            <span class="text-xs text-[#9A2512] font-semibold">Ready to diagnose your device?</span>
                            <form action="hardware.php?device=<?php echo $selected_device; ?>" method="POST">
                                <input type="hidden" name="action" value="start_troubleshoot">
                                <button type="submit" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs sm:text-sm px-6 py-3 rounded-full shadow-lg shadow-[#EB3E0B]/25 transition-all active:scale-95 flex items-center space-x-2">
                                    <span>Start Troubleshooting</span>
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                                    </svg>
                                </button>
                            </form>
                        </div>
                    </div>

                <!-- QUESTION WIZARD -->
                <?php elseif ($wizard_step === 'question'): 
                    $questions = $device['questions'];
                    $total_q = count($questions);
                    $curr_q = isset($questions[$q_idx]) ? $questions[$q_idx] : $questions[0];
                    $progress_pct = intval(round((($q_idx + 1) / $total_q) * 100));
                ?>
                    <div class="bg-white/90 rounded-3xl p-6 sm:p-8 border border-[#FECDAA] shadow-sm space-y-6">
                        
                        <!-- Progress Bar Header -->
                        <div class="space-y-2">
                            <div class="flex items-center justify-between text-xs font-bold text-[#430D07]">
                                <span>Question <?php echo ($q_idx + 1); ?> of <?php echo $total_q; ?></span>
                                <span class="text-[#EB3E0B] font-mono"><?php echo $progress_pct; ?>% Complete</span>
                            </div>
                            <div class="w-full bg-[#FFE8D5] rounded-full h-2.5 overflow-hidden">
                                <div class="bg-[#EB3E0B] h-2.5 rounded-full transition-all duration-300" style="width: <?php echo $progress_pct; ?>%"></div>
                            </div>
                        </div>

                        <!-- Question Card -->
                        <div class="p-6 rounded-3xl bg-[#FFF5ED] border border-[#FECDAA] space-y-4">
                            <h3 class="text-base sm:text-lg font-extrabold text-[#430D07] leading-snug">
                                <?php echo sanitize($curr_q['text']); ?>
                            </h3>

                            <form action="hardware.php?device=<?php echo $selected_device; ?>&q_idx=<?php echo $q_idx; ?>" method="POST" id="questionForm" class="space-y-4">
                                <input type="hidden" name="action" value="answer_question">
                                <input type="hidden" name="question_id" value="<?php echo sanitize($curr_q['id']); ?>">
                                <input type="hidden" name="selected_answer" id="selected_answer_input" value="">

                                <?php if ($curr_q['type'] === 'choice'): ?>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
                                        <?php foreach ($curr_q['options'] as $opt): ?>
                                            <button type="button" onclick="submitChoice('<?php echo addslashes($opt); ?>')" 
                                                    class="w-full text-left p-4 rounded-2xl bg-white border border-[#FECDAA] hover:border-[#EB3E0B] hover:bg-[#FFE8D5]/40 text-xs font-bold text-[#430D07] transition-all flex items-center justify-between group">
                                                <span><?php echo sanitize($opt); ?></span>
                                                <svg class="w-4 h-4 text-[#FA5915] group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                                </svg>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>

                                    <!-- Custom "Other" Input field -->
                                    <div id="custom_other_container" class="hidden space-y-3 pt-3">
                                        <label class="block text-xs font-bold text-[#430D07] uppercase tracking-wider">Please describe your problem:</label>
                                        <textarea name="custom_answer" rows="3" placeholder="Explain what is happening with the device..." class="w-full bg-white border border-[#FECDAA] text-xs sm:text-sm text-[#430D07] rounded-2xl p-4 focus:border-[#FA5915] focus:outline-none transition-all"></textarea>
                                        <button type="submit" onclick="document.getElementById('selected_answer_input').value='Other'" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs px-6 py-2.5 rounded-full shadow-md">
                                            Submit Problem Description
                                        </button>
                                    </div>

                                <?php elseif ($curr_q['type'] === 'yesno'): ?>
                                    <div class="flex items-center space-x-4 pt-4">
                                        <button type="button" onclick="submitChoice('Yes')" class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold text-sm py-4 rounded-2xl shadow-md transition-all active:scale-95 flex items-center justify-center space-x-2">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                            </svg>
                                            <span>Yes</span>
                                        </button>
                                        <button type="button" onclick="submitChoice('No')" class="flex-1 bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-extrabold text-sm py-4 rounded-2xl shadow-md transition-all active:scale-95 flex items-center justify-center space-x-2">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                            <span>No</span>
                                        </button>
                                    </div>

                                    <?php if (isset($curr_q['no_solution'])): ?>
                                        <div class="mt-4 p-4 rounded-2xl bg-amber-50 border border-amber-200 text-amber-800 text-xs flex items-start space-x-3">
                                            <svg class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                            <div>
                                                <span class="font-bold">Quick Check if "No":</span>
                                                <p class="mt-0.5"><?php echo sanitize($curr_q['no_solution']); ?></p>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>

                    <script>
                    function submitChoice(val) {
                        if (val === 'Other') {
                            document.getElementById('custom_other_container').classList.remove('hidden');
                            return;
                        }
                        document.getElementById('selected_answer_input').value = val;
                        document.getElementById('questionForm').submit();
                    }
                    </script>

                <!-- TROUBLESHOOTING STEPS -->
                <?php elseif ($wizard_step === 'resolution'): 
                    $steps = $device['steps'];
                    $total_steps = count($steps);
                    $step_data = isset($steps[$s_idx - 1]) ? $steps[$s_idx - 1] : $steps[0];
                ?>
                    <div class="bg-white/90 rounded-3xl p-6 sm:p-8 border border-[#FECDAA] shadow-sm space-y-6">
                        <div class="flex items-center justify-between border-b border-[#FFE8D5] pb-4">
                            <div>
                                <span class="text-xs font-mono font-bold text-[#EB3E0B] bg-[#FFE8D5] px-3 py-1 rounded-full border border-[#FECDAA]">
                                    Step <?php echo $s_idx; ?> of <?php echo $total_steps; ?>
                                </span>
                                <h3 class="text-xl font-extrabold text-[#430D07] mt-2">
                                    <?php echo sanitize($step_data['title']); ?>
                                </h3>
                            </div>
                        </div>

                        <div class="p-6 rounded-3xl bg-[#FFF5ED] border border-[#FECDAA] space-y-4">
                            <div class="space-y-2">
                                <span class="block text-xs font-bold text-[#7C2112] uppercase tracking-wider">Instruction</span>
                                <p class="text-sm font-semibold text-[#430D07] leading-relaxed">
                                    <?php echo sanitize($step_data['instruction']); ?>
                                </p>
                            </div>

                            <div class="p-4 rounded-2xl bg-white border border-[#FECDAA] space-y-1">
                                <span class="block text-xs font-bold text-[#EB3E0B] uppercase tracking-wider">Expected Result</span>
                                <p class="text-xs text-[#7C2112] font-medium">
                                    <?php echo sanitize($step_data['expected']); ?>
                                </p>
                            </div>
                        </div>

                        <!-- Step Feedback Prompt -->
                        <div class="p-6 rounded-3xl bg-amber-50/70 border border-amber-200 text-center space-y-4">
                            <h4 class="text-sm font-extrabold text-[#430D07]">Did this step solve your issue?</h4>
                            <form action="hardware.php?device=<?php echo $selected_device; ?>" method="POST" class="flex items-center justify-center space-x-4">
                                <input type="hidden" name="action" value="step_feedback">
                                <input type="hidden" name="current_step" value="<?php echo $s_idx; ?>">
                                
                                <button type="submit" name="solved" value="yes" class="bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold text-xs sm:text-sm px-8 py-3 rounded-full shadow-md transition-all active:scale-95 flex items-center space-x-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                    </svg>
                                    <span>Yes, Issue Solved</span>
                                </button>

                                <button type="submit" name="solved" value="no" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-extrabold text-xs sm:text-sm px-8 py-3 rounded-full shadow-md transition-all active:scale-95 flex items-center space-x-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                    <span>No, Next Step</span>
                                </button>
                            </form>
                        </div>
                    </div>

                <!-- RESOLVED SUCCESS SCREEN -->
                <?php elseif ($wizard_step === 'resolved'): ?>
                    <div class="bg-white/90 rounded-3xl p-8 sm:p-12 border border-emerald-200 shadow-sm text-center space-y-6">
                        <div class="w-20 h-20 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center mx-auto shadow-inner">
                            <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                            </svg>
                        </div>
                        <div class="space-y-2">
                            <h3 class="text-2xl font-extrabold text-emerald-900">Great! Your issue has been resolved.</h3>
                            <p class="text-xs text-emerald-700 max-w-md mx-auto">We have logged this session resolution status as <strong>Resolved</strong>. If you encounter any further problems, feel free to start a new check.</p>
                        </div>
                        <div class="pt-4 flex items-center justify-center space-x-4">
                            <a href="index.php" class="bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs px-6 py-3 rounded-full shadow-sm">
                                Return to Dashboard
                            </a>
                            <a href="hardware.php" class="bg-[#FFE8D5] hover:bg-[#FECDAA] text-[#430D07] font-bold text-xs px-6 py-3 rounded-full">
                                Check Another Device
                            </a>
                        </div>
                    </div>

                <!-- UNRESOLVED FALLBACK SCREEN -->
                <?php elseif ($wizard_step === 'unresolved'): 
                    $isolated_issue = isset($_SESSION['selected_issue']) ? $_SESSION['selected_issue'] : 'Hardware Support Issue';

                    // Build diagnostic trail from session logs
                    $diag_stmt = $pdo->prepare("SELECT question_id, selected_answer, custom_answer, step_viewed, step_completed, resolution_status, created_at FROM hardware_troubleshooting_logs WHERE session_id = :sess AND accountnum = :acct ORDER BY id ASC");
                    $diag_stmt->execute(array(':sess' => $session_id, ':acct' => $accountnum));
                    $diag_rows = $diag_stmt->fetchAll();

                    $q_map = array();
                    if (isset($device['questions'])) {
                        foreach ($device['questions'] as $q_item) {
                            $q_map[$q_item['id']] = $q_item['text'];
                        }
                    }

                    $diag_lines = array();
                    $diag_lines[] = "=== HARDWARE DIAGNOSTIC LOG ===";
                    $diag_lines[] = "Device: " . $device['name'];
                    $diag_lines[] = "Reported Issue: " . $isolated_issue;
                    $diag_lines[] = "Session ID: " . $session_id;
                    $diag_lines[] = "";

                    $q_count = 0;
                    $s_count = 0;
                    foreach ($diag_rows as $dl) {
                        if (!empty($dl['question_id'])) {
                            $q_count++;
                            $ans_text = $dl['selected_answer'];
                            if (!empty($dl['custom_answer'])) {
                                $ans_text .= ' - ' . $dl['custom_answer'];
                            }
                            $q_text = isset($q_map[$dl['question_id']]) ? $q_map[$dl['question_id']] : ("Question " . $q_count);
                            $diag_lines[] = "Question: " . $q_text;
                            $diag_lines[] = "Answer: " . $ans_text;
                            $diag_lines[] = "";
                        }
                        if ($dl['step_completed'] > 0 && strpos($dl['selected_answer'], 'Unsolved on Step') !== false) {
                            $s_count++;
                            $diag_lines[] = "Step " . $dl['step_completed'] . ": Tried - NOT resolved";
                        }
                        if ($dl['step_completed'] > 0 && strpos($dl['selected_answer'], 'All steps exhausted') !== false) {
                            $diag_lines[] = ">> All troubleshooting steps exhausted - UNRESOLVED";
                        }
                    }

                    $diag_lines[] = "";
                    $diag_lines[] = "Status: UNRESOLVED - Requesting technician assistance.";

                    $diag_trail = implode("\n", $diag_lines);
                    $diag_trail_js = json_encode($diag_trail);
                ?>
                    <div class="bg-white/90 rounded-3xl p-8 sm:p-12 border border-rose-200 shadow-sm text-center space-y-6">
                        <div class="w-20 h-20 rounded-full bg-rose-100 text-rose-600 flex items-center justify-center mx-auto shadow-inner">
                            <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                        </div>
                        <div class="space-y-2">
                            <h3 class="text-2xl font-extrabold text-rose-900">We couldn't resolve your issue.</h3>
                            <p class="text-xs text-rose-700 max-w-md mx-auto">Please contact RNZ Technical Support. Clicking the button below will automatically create a support ticket with your diagnostic logs attached.</p>
                        </div>

                        <div class="pt-4 flex items-center justify-center space-x-4">
                            <button onclick="openNewTicketModalWithPrefill()" 
                                    class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-sm px-8 py-3.5 rounded-full shadow-lg shadow-[#EB3E0B]/30 transition-all active:scale-95 flex items-center space-x-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                </svg>
                                <span>Submit Support Ticket</span>
                            </button>
                        </div>
                    </div>

                    <script>
                    function openNewTicketModalWithPrefill() {
                        var devName = <?php echo json_encode($device['name']); ?>;
                        var issueText = <?php echo json_encode($isolated_issue); ?>;
                        var diagTrail = <?php echo $diag_trail_js; ?>;

                        if (typeof openNewTicketModal === 'function') {
                            openNewTicketModal();
                        } else {
                            var modal = document.getElementById('newTicketModal');
                            if (modal) modal.classList.remove('hidden');
                        }
                        var subjInput = document.querySelector('#newTicketModal input[name="subject"]');
                        var descInput = document.querySelector('#newTicketModal textarea[name="issue_description"]');
                        if (subjInput) subjInput.value = devName + " Issue: " + issueText;
                        if (descInput) descInput.value = diagTrail;
                    }
                    </script>
                <?php endif; ?>

            <?php endif; ?>

        </main>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
