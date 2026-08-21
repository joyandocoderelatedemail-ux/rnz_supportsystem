<?php
// Client Portal - Software Issues & POS Troubleshooting Module (PHP 5.6 Compatible)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db_init.php';
require_once __DIR__ . '/includes/software_data.php';

require_login();
$client = get_logged_client();
$accountnum = $client['accountnum'];

$pdo = get_db_connection();

$all_software_issues = get_software_issues_list();

// Get selected issue (if any)
$selected_issue_id = isset($_GET['issue']) ? trim($_GET['issue']) : '';
$issue_item = null;

if (!empty($selected_issue_id)) {
    $issue_item = get_software_issue($selected_issue_id);
}

// Current Wizard Step State: catalog, overview, question, resolution, resolved, unresolved
$wizard_step = isset($_GET['step']) ? trim($_GET['step']) : ($issue_item ? 'overview' : 'catalog');
if (!$issue_item && $wizard_step !== 'catalog') {
    $wizard_step = 'catalog';
}

$q_idx = isset($_GET['q_idx']) ? intval($_GET['q_idx']) : 0;
$s_idx = isset($_GET['s_idx']) ? intval($_GET['s_idx']) : 1;
$search_q = isset($_GET['q']) ? trim($_GET['q']) : '';

// Session ID for tracking
if (!isset($_SESSION['software_troubleshoot_session_id'])) {
    $_SESSION['software_troubleshoot_session_id'] = 'SOFT-' . uniqid() . '-' . rand(1000, 9999);
}
$session_id = $_SESSION['software_troubleshoot_session_id'];

// Log User Interaction into database
function log_software_activity($pdo, $accountnum, $session_id, $issue_name, $issue_desc = null, $q_id = null, $answer = null, $custom_ans = null, $step_v = 0, $step_c = 0, $status = 'In Progress') {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Unknown';
    $now = date('Y-m-d H:i:s');

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
            VALUES (:acct, :sess, :ip, :hw, :issue, :qid, :ans, :custom, :sv, :sc, :t_start, :t_comp, :status, :br, :os, 'Software POS', :c_at)");

        $time_comp = ($status === 'Resolved' || $status === 'Unresolved') ? $now : null;

        $stmt->execute(array(
            ':acct' => $accountnum,
            ':sess' => $session_id,
            ':ip' => $ip,
            ':hw' => 'Software: ' . $issue_name,
            ':issue' => $issue_desc ? $issue_desc : $issue_name,
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
        error_log("Software logging error: " . $e->getMessage());
    }
}

// Handle Form Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $issue_item) {
    $post_action = isset($_POST['action']) ? trim($_POST['action']) : '';

    if ($post_action === 'start_troubleshoot') {
        log_software_activity($pdo, $accountnum, $session_id, $issue_item['name'], null, null, null, null, 0, 0, 'In Progress');
        
        if (!empty($issue_item['questions'])) {
            header("Location: software.php?issue=" . $selected_issue_id . "&step=question&q_idx=0");
        } else {
            header("Location: software.php?issue=" . $selected_issue_id . "&step=resolution&s_idx=1");
        }
        exit;

    } elseif ($post_action === 'answer_question') {
        $q_id = isset($_POST['question_id']) ? trim($_POST['question_id']) : '';
        $answer = isset($_POST['selected_answer']) ? trim($_POST['selected_answer']) : '';
        $custom_ans = isset($_POST['custom_answer']) ? trim($_POST['custom_answer']) : '';

        if (!empty($answer)) {
            $_SESSION['selected_software_issue'] = $answer;
        }

        $current_issue = isset($_SESSION['selected_software_issue']) ? $_SESSION['selected_software_issue'] : $issue_item['name'];
        log_software_activity($pdo, $accountnum, $session_id, $issue_item['name'], $current_issue, $q_id, $answer, $custom_ans, 0, 0, 'In Progress');

        $total_q = count($issue_item['questions']);
        $next_q = $q_idx + 1;

        if ($next_q < $total_q) {
            header("Location: software.php?issue=" . $selected_issue_id . "&step=question&q_idx=" . $next_q);
            exit;
        } else {
            header("Location: software.php?issue=" . $selected_issue_id . "&step=resolution&s_idx=1");
            exit;
        }

    } elseif ($post_action === 'step_feedback') {
        $feedback = isset($_POST['solved']) ? trim($_POST['solved']) : 'no';
        $curr_step = isset($_POST['current_step']) ? intval($_POST['current_step']) : 1;
        $current_issue = isset($_SESSION['selected_software_issue']) ? $_SESSION['selected_software_issue'] : $issue_item['name'];

        if ($feedback === 'yes') {
            log_software_activity($pdo, $accountnum, $session_id, $issue_item['name'], $current_issue, null, 'Solved on Step ' . $curr_step, null, $curr_step, $curr_step, 'Resolved');
            header("Location: software.php?issue=" . $selected_issue_id . "&step=resolved");
            exit;
        } else {
            log_software_activity($pdo, $accountnum, $session_id, $issue_item['name'], $current_issue, null, 'Unsolved on Step ' . $curr_step, null, $curr_step, $curr_step, 'In Progress');
            $next_step = $curr_step + 1;
            $total_steps = count($issue_item['steps']);

            if ($next_step <= $total_steps) {
                header("Location: software.php?issue=" . $selected_issue_id . "&step=resolution&s_idx=" . $next_step);
                exit;
            } else {
                log_software_activity($pdo, $accountnum, $session_id, $issue_item['name'], $current_issue, null, 'All steps exhausted', null, $total_steps, $total_steps, 'Unresolved');
                header("Location: software.php?issue=" . $selected_issue_id . "&step=unresolved");
                exit;
            }
        }
    }
}

$active_page = 'software';
$page_title = $issue_item ? $issue_item['name'] . ' Support' : 'Software & POS Issues';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo sanitize($page_title); ?> - RNZ Support</title>
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
<body class="bg-[#FFF5ED] text-[#430D07] antialiased min-h-screen">

<div class="flex min-h-screen">
    <!-- Sidebar Navigation -->
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col min-w-0">
        <!-- Header -->
        <?php include __DIR__ . '/includes/header.php'; ?>

        <main class="p-4 sm:p-6 md:p-8 pb-24 md:pb-8 space-y-6 sm:space-y-8 max-w-7xl w-full mx-auto">

            <!-- STEP 1: SOFTWARE ISSUES CATALOG VIEW -->
            <?php if ($wizard_step === 'catalog'): ?>
                <div class="space-y-6">
                    <!-- Page Banner -->
                    <div class="bg-gradient-to-r from-[#430D07] to-[#7C2112] text-white p-6 sm:p-8 rounded-3xl shadow-xl space-y-3 relative overflow-hidden">
                        <div class="relative z-10 max-w-3xl space-y-2">
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-[#EB3E0B] text-white uppercase tracking-wider">
                                POS Software Diagnostics
                            </span>
                            <h2 class="text-2xl sm:text-3xl font-extrabold tracking-tight">Software & POS System Self-Troubleshooting</h2>
                            <p class="text-sm text-[#FFE8D5] font-medium leading-relaxed">
                                Select a software issue below (e.g. Slow POS performance or "Account Already In Use") to generate step-by-step resolution guides and instant fixes.
                            </p>
                        </div>
                    </div>

                    <!-- Search Filter Box -->
                    <div class="bg-white p-4 rounded-3xl border border-[#FECDAA] shadow-sm flex items-center justify-between gap-4">
                        <div class="relative flex-1">
                            <input type="text" id="softwareSearchInput" onkeyup="filterSoftwareIssues()" placeholder="Search software issues (e.g., Slow POS, Account locked, Database...)" 
                                   class="w-full bg-[#FFF5ED] text-slate-800 text-xs sm:text-sm pl-10 pr-4 py-3 rounded-2xl border border-[#FECDAA] focus:bg-white focus:border-[#FA5915] focus:outline-none transition-all placeholder-[#9A2512]/60">
                            <svg class="w-5 h-5 text-[#FA5915] absolute left-3.5 top-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                        </div>
                    </div>

                    <!-- Software Issues Grid -->
                    <div id="softwareCardsGrid" class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <?php foreach ($all_software_issues as $s_key => $s_info): ?>
                            <?php if (!empty($s_info['is_remote'])): ?>
                                <div onclick="openNewTicketModal('<?php echo sanitize($s_info['name']); ?>', 'Request <?php echo sanitize($s_info['name']); ?> via UltraViewer')" 
                                     class="software-card bg-white p-6 rounded-3xl border border-amber-300/80 shadow-sm hover:shadow-xl hover:border-[#EB3E0B] transition-all space-y-4 group flex flex-col justify-between cursor-pointer">
                                    <div class="space-y-3">
                                        <div class="flex items-center justify-between">
                                            <div class="w-12 h-12 rounded-2xl bg-amber-100 group-hover:bg-[#EB3E0B] text-[#EB3E0B] group-hover:text-white flex items-center justify-center transition-colors shadow-sm">
                                                <?php echo $s_info['icon']; ?>
                                            </div>
                                            <span class="text-[11px] font-bold px-3 py-1 rounded-full bg-amber-100 text-amber-900 border border-amber-300">
                                                ● UltraViewer Remote
                                            </span>
                                        </div>
                                        <h3 class="text-lg font-extrabold text-[#430D07] group-hover:text-[#EB3E0B] transition-colors">
                                            <?php echo sanitize($s_info['name']); ?>
                                        </h3>
                                        <p class="text-xs text-[#7C2112] font-medium leading-relaxed">
                                            <?php echo sanitize($s_info['description']); ?>
                                        </p>

                                        <!-- UltraViewer Callout -->
                                        <div class="p-3 rounded-2xl bg-amber-50 border border-amber-200 space-y-1">
                                            <span class="text-[10px] font-extrabold uppercase tracking-wider text-[#EB3E0B] block">Remote Session Required:</span>
                                            <p class="text-[11px] text-amber-900 font-semibold">Requires UltraViewer Username & Password + Remarks of what to update.</p>
                                        </div>
                                    </div>

                                    <div class="pt-4 border-t border-[#FFE8D5] flex items-center justify-between text-xs font-bold text-[#EB3E0B]">
                                        <span>Submit Remote Request &rarr;</span>
                                        <span class="w-8 h-8 rounded-full bg-[#FFE8D5] group-hover:bg-[#EB3E0B] group-hover:text-white flex items-center justify-center transition-colors">&rarr;</span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <a href="software.php?issue=<?php echo $s_key; ?>&step=overview" 
                                   class="software-card bg-white p-6 rounded-3xl border border-[#FECDAA] shadow-sm hover:shadow-xl hover:border-[#FA5915] transition-all space-y-4 group flex flex-col justify-between">
                                    <div class="space-y-3">
                                        <div class="flex items-center justify-between">
                                            <div class="w-12 h-12 rounded-2xl bg-[#FFE8D5] group-hover:bg-[#EB3E0B] text-[#EB3E0B] group-hover:text-white flex items-center justify-center transition-colors shadow-sm">
                                                <?php echo $s_info['icon']; ?>
                                            </div>
                                            <span class="text-[11px] font-bold px-3 py-1 rounded-full bg-[#FFF5ED] text-[#EB3E0B] border border-[#FECDAA]">
                                                <?php echo sanitize($s_info['category']); ?>
                                            </span>
                                        </div>
                                        <h3 class="text-lg font-extrabold text-[#430D07] group-hover:text-[#EB3E0B] transition-colors">
                                            <?php echo sanitize($s_info['name']); ?>
                                        </h3>
                                        <p class="text-xs text-[#7C2112] font-medium leading-relaxed">
                                            <?php echo sanitize($s_info['description']); ?>
                                        </p>

                                        <!-- Main Cause Callout if available -->
                                        <?php if (!empty($s_info['main_cause'])): ?>
                                            <div class="p-3 rounded-2xl bg-[#FFF5ED] border border-[#FECDAA] space-y-1">
                                                <span class="text-[10px] font-extrabold uppercase tracking-wider text-[#EB3E0B] block">Probable Cause:</span>
                                                <p class="text-[11px] text-slate-700 font-semibold line-clamp-2"><?php echo sanitize($s_info['main_cause']); ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="pt-4 border-t border-[#FFE8D5] flex items-center justify-between text-xs font-bold text-[#EB3E0B]">
                                        <span>Start Diagnostics & Resolution &rarr;</span>
                                        <span class="w-8 h-8 rounded-full bg-[#FFE8D5] group-hover:bg-[#EB3E0B] group-hover:text-white flex items-center justify-center transition-colors">&rarr;</span>
                                    </div>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <script>
                function filterSoftwareIssues() {
                    var input = document.getElementById('softwareSearchInput').value.toLowerCase();
                    var cards = document.getElementsByClassName('software-card');
                    for (var i = 0; i < cards.length; i++) {
                        var text = cards[i].innerText.toLowerCase();
                        if (text.indexOf(input) > -1) {
                            cards[i].style.display = 'flex';
                        } else {
                            cards[i].style.display = 'none';
                        }
                    }
                }
                </script>

            <!-- STEP 2: ISSUE OVERVIEW & CAUSE EXPLANATION -->
            <?php elseif ($wizard_step === 'overview' && $issue_item): ?>
                <div class="max-w-4xl mx-auto space-y-6">
                    <a href="software.php" class="inline-flex items-center text-xs font-bold text-[#EB3E0B] hover:underline space-x-1">
                        <span>&larr; Back to Software Issues Catalog</span>
                    </a>

                    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-[#FECDAA] shadow-lg space-y-6">
                        <div class="flex items-start space-x-4 border-b border-[#FFE8D5] pb-6">
                            <div class="w-14 h-14 rounded-2xl bg-[#FFE8D5] text-[#EB3E0B] flex items-center justify-center shrink-0 shadow-sm">
                                <?php echo $issue_item['icon']; ?>
                            </div>
                            <div class="space-y-1">
                                <span class="text-xs font-bold uppercase tracking-wider text-[#EB3E0B]">
                                    <?php echo sanitize($issue_item['category']); ?> Diagnostic
                                </span>
                                <h2 class="text-2xl font-extrabold text-[#430D07]"><?php echo sanitize($issue_item['name']); ?></h2>
                                <p class="text-xs text-[#7C2112] font-medium leading-relaxed"><?php echo sanitize($issue_item['description']); ?></p>
                            </div>
                        </div>

                        <!-- Special Highlight for Remote UltraViewer Categories -->
                        <?php if (!empty($issue_item['is_remote'])): ?>
                            <div class="p-5 rounded-3xl bg-amber-50 border border-amber-200 space-y-4">
                                <div class="flex items-center space-x-2 text-amber-900 font-extrabold text-sm">
                                    <svg class="w-5 h-5 text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                    </svg>
                                    <span>UltraViewer Remote Session Required</span>
                                </div>
                                <p class="text-xs text-slate-700 leading-relaxed font-medium">
                                    To <?php echo strtolower(sanitize($issue_item['name'])); ?>, our technical team needs to connect remotely to your POS computer terminal. Please make sure UltraViewer is launched and submit your ticket with your credentials and specific update remarks.
                                </p>
                                <div>
                                    <button type="button" onclick="openNewTicketModal('<?php echo sanitize($issue_item['name']); ?>', 'Request <?php echo sanitize($issue_item['name']); ?> via UltraViewer')" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-extrabold text-xs px-6 py-3 rounded-full shadow-md shadow-[#EB3E0B]/25 transition-all active:scale-95 inline-flex items-center space-x-2">
                                        <span>Open Ticket Form with UltraViewer Credentials &rarr;</span>
                                    </button>
                                </div>
                            </div>
                        <?php elseif ($issue_item['id'] === 'account-in-use'): ?>
                            <div class="p-5 rounded-3xl bg-amber-50 border border-amber-200 space-y-3">
                                <div class="flex items-center space-x-2 text-amber-800 font-extrabold text-sm">
                                    <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    <span>Main Cause & Quick Resolution Overview</span>
                                </div>
                                <p class="text-xs text-slate-700 leading-relaxed font-medium">
                                    <strong class="text-slate-900">Why does this happen?</strong> The cashier or user did not log out properly from their previous terminal session (e.g. computer was turned off directly or POS application force closed).
                                </p>
                                <div class="p-4 rounded-2xl bg-white border border-amber-200/80 space-y-1.5 text-xs text-slate-800 font-medium">
                                    <p class="font-bold text-[#EB3E0B]">Step-by-step Solution Summary:</p>
                                    <ol class="list-decimal list-inside space-y-1 text-slate-700">
                                        <li>Log in using an <strong>Admin or Manager</strong> supervisor account.</li>
                                        <li>Navigate to <strong>Admin -> Accounts</strong> (or Active Cashiers / User Sessions).</li>
                                        <li>Locate the locked cashier account in the active list and click <strong>"Log Out"</strong>.</li>
                                        <li>The cashier can now log in normally without any error!</li>
                                    </ol>
                                </div>
                            </div>
                        <?php elseif ($issue_item['id'] === 'slow-pos'): ?>
                            <div class="p-5 rounded-3xl bg-orange-50 border border-orange-200 space-y-3">
                                <div class="flex items-center space-x-2 text-orange-900 font-extrabold text-sm">
                                    <svg class="w-5 h-5 text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                    </svg>
                                    <span>Slow POS Troubleshooting Plan</span>
                                </div>
                                <p class="text-xs text-slate-700 leading-relaxed font-medium">
                                    Our self-troubleshooting wizard will guide you through clearing background RAM consumption, flushing print spooler queues, and optimizing local database indexes to restore peak POS speed.
                                </p>
                            </div>
                        <?php endif; ?>

                        <!-- Common Causes List -->
                        <?php if (!empty($issue_item['common_causes'])): ?>
                            <div class="space-y-3">
                                <h4 class="text-xs font-bold text-[#430D07] uppercase tracking-wider">Common Causes:</h4>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                                    <?php foreach ($issue_item['common_causes'] as $cc): ?>
                                        <div class="p-3 rounded-2xl bg-[#FFF5ED] border border-[#FECDAA] text-xs font-semibold text-[#7C2112] flex items-center space-x-2">
                                            <span class="w-2 h-2 rounded-full bg-[#EB3E0B] shrink-0"></span>
                                            <span><?php echo sanitize($cc); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Start Button -->
                        <form action="software.php?issue=<?php echo $selected_issue_id; ?>" method="POST" class="pt-4 border-t border-[#FFE8D5] flex items-center justify-between">
                            <input type="hidden" name="action" value="start_troubleshoot">
                            <span class="text-xs text-[#9A2512] font-semibold">Interactive Resolution Wizard</span>
                            <button type="submit" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs sm:text-sm px-6 py-3 rounded-full shadow-md shadow-[#EB3E0B]/25 transition-all active:scale-95">
                                Start Troubleshooting &rarr;
                            </button>
                        </form>
                    </div>
                </div>

            <!-- STEP 3: DIAGNOSTIC QUESTIONS -->
            <?php elseif ($wizard_step === 'question' && $issue_item): ?>
                <?php
                $questions = $issue_item['questions'];
                $curr_q = isset($questions[$q_idx]) ? $questions[$q_idx] : null;
                ?>
                <?php if ($curr_q): ?>
                    <div class="max-w-2xl mx-auto space-y-6">
                        <div class="bg-white rounded-3xl p-6 sm:p-8 border border-[#FECDAA] shadow-xl space-y-6">
                            <div class="flex items-center justify-between border-b border-[#FFE8D5] pb-4">
                                <span class="text-xs font-bold text-[#EB3E0B] uppercase tracking-wider">
                                    Diagnostic Question <?php echo ($q_idx + 1); ?> of <?php echo count($questions); ?>
                                </span>
                                <span class="text-xs font-mono font-bold text-slate-400"><?php echo sanitize($issue_item['name']); ?></span>
                            </div>

                            <h3 class="text-lg sm:text-xl font-extrabold text-[#430D07]">
                                <?php echo sanitize($curr_q['text']); ?>
                            </h3>

                            <form action="software.php?issue=<?php echo $selected_issue_id; ?>&q_idx=<?php echo $q_idx; ?>" method="POST" class="space-y-4">
                                <input type="hidden" name="action" value="answer_question">
                                <input type="hidden" name="question_id" value="<?php echo sanitize($curr_q['id']); ?>">

                                <?php if ($curr_q['type'] === 'choice'): ?>
                                    <div class="space-y-2.5">
                                        <?php foreach ($curr_q['options'] as $opt): ?>
                                            <label class="flex items-center p-4 rounded-2xl bg-[#FFF5ED] hover:bg-[#FFE8D5] border border-[#FECDAA] cursor-pointer transition-all space-x-3 group">
                                                <input type="radio" name="selected_answer" value="<?php echo sanitize($opt); ?>" required class="w-4 h-4 text-[#EB3E0B] focus:ring-[#FA5915]">
                                                <span class="text-xs sm:text-sm font-semibold text-[#430D07] group-hover:text-[#EB3E0B]"><?php echo sanitize($opt); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>

                                <?php elseif ($curr_q['type'] === 'yesno'): ?>
                                    <div class="grid grid-cols-2 gap-4">
                                        <button type="submit" name="selected_answer" value="Yes" class="p-5 rounded-2xl bg-emerald-50 hover:bg-emerald-100 border border-emerald-300 text-emerald-900 font-extrabold text-sm transition-all text-center">
                                            Yes
                                        </button>
                                        <button type="submit" name="selected_answer" value="No" class="p-5 rounded-2xl bg-amber-50 hover:bg-amber-100 border border-amber-300 text-amber-900 font-extrabold text-sm transition-all text-center">
                                            No
                                        </button>
                                    </div>
                                <?php endif; ?>

                                <?php if ($curr_q['type'] === 'choice'): ?>
                                    <div class="pt-4 flex justify-end">
                                        <button type="submit" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs px-6 py-3 rounded-full shadow-md">
                                            Next Diagnostic Step &rarr;
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

            <!-- STEP 4: STEP-BY-STEP RESOLUTION WALKTHROUGH -->
            <?php elseif ($wizard_step === 'resolution' && $issue_item): ?>
                <?php
                $steps = $issue_item['steps'];
                $total_steps = count($steps);
                $step_data = isset($steps[$s_idx - 1]) ? $steps[$s_idx - 1] : $steps[0];
                ?>
                <div class="max-w-3xl mx-auto space-y-6">
                    <!-- Progress Bar -->
                    <div class="bg-white p-4 rounded-3xl border border-[#FECDAA] shadow-sm flex items-center justify-between gap-4">
                        <span class="text-xs font-extrabold text-[#430D07]">Troubleshooting Step <?php echo $s_idx; ?> of <?php echo $total_steps; ?></span>
                        <div class="flex-1 max-w-xs bg-[#FFF5ED] h-2.5 rounded-full overflow-hidden border border-[#FECDAA]">
                            <div class="bg-[#EB3E0B] h-full transition-all duration-300" style="width: <?php echo ($s_idx / $total_steps * 100); ?>%;"></div>
                        </div>
                    </div>

                    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-[#FECDAA] shadow-xl space-y-6">
                        <div class="flex items-center space-x-3 border-b border-[#FFE8D5] pb-4">
                            <div class="w-10 h-10 rounded-2xl bg-[#EB3E0B] text-white flex items-center justify-center font-bold text-sm shadow-md">
                                <?php echo $s_idx; ?>
                            </div>
                            <div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-[#EB3E0B]">Resolution Instruction</span>
                                <h3 class="text-lg font-extrabold text-[#430D07]"><?php echo sanitize($step_data['title']); ?></h3>
                            </div>
                        </div>

                        <!-- Step Action Card -->
                        <div class="p-5 rounded-3xl bg-[#FFF5ED] border border-[#FECDAA] space-y-3">
                            <span class="text-[10px] font-extrabold uppercase tracking-wider text-[#9A2512] block">Action Required:</span>
                            <p class="text-xs sm:text-sm text-slate-800 font-semibold leading-relaxed">
                                <?php echo sanitize($step_data['instruction']); ?>
                            </p>
                        </div>

                        <!-- Expected Outcome -->
                        <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-xs text-emerald-900 space-y-1">
                            <span class="font-extrabold uppercase text-[10px] text-emerald-700 block">Expected Result:</span>
                            <p class="font-medium"><?php echo sanitize($step_data['expected']); ?></p>
                        </div>

                        <!-- Feedback Form -->
                        <form action="software.php?issue=<?php echo $selected_issue_id; ?>" method="POST" class="pt-4 border-t border-[#FFE8D5] space-y-4">
                            <input type="hidden" name="action" value="step_feedback">
                            <input type="hidden" name="current_step" value="<?php echo $s_idx; ?>">

                            <p class="text-xs font-bold text-[#430D07] text-center">Did this step solve your software issue?</p>

                            <div class="grid grid-cols-2 gap-4">
                                <button type="submit" name="solved" value="yes" class="p-4 rounded-2xl bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold text-xs transition-all flex items-center justify-center space-x-2 shadow-sm">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                    <span>Yes, Solved!</span>
                                </button>
                                <button type="submit" name="solved" value="no" class="p-4 rounded-2xl bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-extrabold text-xs transition-all flex items-center justify-center space-x-2 shadow-sm">
                                    <span>No, Next Step &rarr;</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

            <!-- STEP 5: ISSUE RESOLVED -->
            <?php elseif ($wizard_step === 'resolved'): ?>
                <div class="max-w-xl mx-auto text-center space-y-6">
                    <div class="bg-white rounded-3xl p-8 border border-emerald-200 shadow-2xl space-y-6">
                        <div class="w-16 h-16 rounded-full bg-emerald-100 text-emerald-600 mx-auto flex items-center justify-center font-bold shadow-sm">
                            <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <div class="space-y-2">
                            <h2 class="text-2xl font-extrabold text-slate-900">Software Issue Resolved!</h2>
                            <p class="text-xs text-slate-600 font-medium">Great! Your troubleshooting session has been logged as successfully resolved.</p>
                        </div>

                        <div class="pt-4 flex items-center justify-center space-x-3">
                            <a href="index.php" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs px-6 py-3 rounded-full shadow-md">
                                Return to Dashboard
                            </a>
                            <a href="software.php" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs px-6 py-3 rounded-full">
                                Software Catalog
                            </a>
                        </div>
                    </div>
                </div>

            <!-- STEP 6: UNRESOLVED - CREATE SUPPORT TICKET -->
            <?php elseif ($wizard_step === 'unresolved' && $issue_item): 
                $isolated_issue = isset($_SESSION['selected_software_issue']) ? $_SESSION['selected_software_issue'] : $issue_item['name'];

                // Build diagnostic trail from session logs
                $diag_stmt = $pdo->prepare("SELECT question_id, selected_answer, custom_answer, step_viewed, step_completed, resolution_status, created_at 
                    FROM hardware_troubleshooting_logs 
                    WHERE session_id = :sess AND accountnum = :acct 
                    ORDER BY id ASC");
                $diag_stmt->execute(array(':sess' => $session_id, ':acct' => $accountnum));
                $diag_rows = $diag_stmt->fetchAll();

                $q_map = array();
                if (isset($issue_item['questions'])) {
                    foreach ($issue_item['questions'] as $q_item) {
                        $q_map[$q_item['id']] = $q_item['text'];
                    }
                }

                $diag_lines = array();
                $diag_lines[] = "=== SOFTWARE DIAGNOSTIC LOG ===";
                $diag_lines[] = "Module / Issue: " . $issue_item['name'];
                $diag_lines[] = "Category: " . $issue_item['category'];
                $diag_lines[] = "Reported Symptom: " . $isolated_issue;
                $diag_lines[] = "Session ID: " . $session_id;
                $diag_lines[] = "";

                $q_count = 0;
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
                }

                $diag_lines[] = "[Attempted Troubleshooting Steps by Client]";
                if (isset($issue_item['steps'])) {
                    foreach ($issue_item['steps'] as $st) {
                        $diag_lines[] = "Step " . $st['step_num'] . " (" . $st['title'] . "): Tried - NOT resolved";
                    }
                }

                $diag_lines[] = "";
                $diag_lines[] = "Status: UNRESOLVED - Client completed troubleshooting steps without resolution. Requesting technician assistance.";

                $diag_trail = implode("\n", $diag_lines);
                $diag_trail_js = json_encode($diag_trail);
            ?>
                <div class="max-w-xl mx-auto text-center space-y-6">
                    <div class="bg-white rounded-3xl p-8 border border-amber-200 shadow-2xl space-y-6">
                        <div class="w-16 h-16 rounded-full bg-amber-100 text-amber-600 mx-auto flex items-center justify-center font-bold shadow-sm">
                            <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        </div>
                        <div class="space-y-2">
                            <h2 class="text-2xl font-extrabold text-slate-900">Troubleshooting Incomplete</h2>
                            <p class="text-xs text-slate-600 font-medium">
                                We could not resolve your software issue using self-diagnostic steps. Submit a support ticket to alert an RNZ Support Technician immediately with your diagnostic trail attached.
                            </p>
                        </div>

                        <div class="pt-4 flex items-center justify-center space-x-3">
                            <button type="button" onclick="openTeamViewerCheckModal()" 
                                    class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-bold text-xs sm:text-sm px-8 py-3.5 rounded-full shadow-lg shadow-[#EB3E0B]/25 transition-all active:scale-95 flex items-center space-x-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                </svg>
                                <span>Submit Support Ticket With Diagnostic Steps &rarr;</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- TEAMVIEWER CHECK MODAL -->
                <div id="teamViewerCheckModal" class="fixed inset-0 z-50 bg-[#430D07]/60 backdrop-blur-sm hidden items-center justify-center p-4 overflow-y-auto">
                    <div class="bg-white rounded-3xl shadow-2xl border border-[#FECDAA] max-w-lg w-full p-6 sm:p-8 space-y-6 my-auto relative animate-in fade-in zoom-in duration-150 text-[#430D07]">
                        <!-- Close Button -->
                        <button type="button" onclick="closeTeamViewerModal()" class="absolute top-5 right-5 text-[#9A2512] hover:text-[#430D07] p-2 rounded-full hover:bg-[#FFE8D5] transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>

                        <!-- VIEW 1: TeamViewer Question -->
                        <div id="tvQuestionView" class="space-y-6 text-center">
                            <div class="w-16 h-16 rounded-2xl bg-[#FFE8D5] text-[#EB3E0B] mx-auto flex items-center justify-center font-bold shadow-sm">
                                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                </svg>
                            </div>
                            
                            <div class="space-y-2">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-[11px] font-bold bg-[#FFF5ED] text-[#EB3E0B] border border-[#FECDAA]">
                                    Remote Assistance Readiness
                                </span>
                                <h3 class="text-xl sm:text-2xl font-extrabold text-[#430D07]">Do you have TeamViewer installed on this computer?</h3>
                                <p class="text-xs text-[#7C2112] leading-relaxed">
                                    Our support technician will need to remotely access your POS terminal to inspect logs, repair configuration, and resolve this software issue.
                                </p>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
                                <button type="button" onclick="handleTeamViewerAnswer(true)" class="p-4 rounded-2xl bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold text-xs sm:text-sm shadow-md transition-all active:scale-95 flex items-center justify-center space-x-2">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                    <span>Yes, I Have TeamViewer</span>
                                </button>
                                <button type="button" onclick="handleTeamViewerAnswer(false)" class="p-4 rounded-2xl bg-[#FFE8D5] hover:bg-[#FECDAA] text-[#430D07] font-extrabold text-xs sm:text-sm border border-[#FECDAA] transition-all active:scale-95 flex items-center justify-center space-x-2">
                                    <svg class="w-5 h-5 text-[#EB3E0B]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                    <span>No, Need to Download</span>
                                </button>
                            </div>
                        </div>

                        <!-- VIEW 2: TeamViewer Download Guide (Shown when answered No) -->
                        <div id="tvDownloadView" class="space-y-5 hidden text-left">
                            <div class="flex items-center space-x-3 border-b border-[#FFE8D5] pb-4">
                                <div class="w-12 h-12 rounded-2xl bg-blue-50 text-[#0066cc] flex items-center justify-center font-bold shrink-0">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                </div>
                                <div>
                                    <h3 class="text-lg font-extrabold text-[#430D07]">Download TeamViewer for Windows</h3>
                                    <p class="text-xs text-[#7C2112]">Required for remote technical assistance.</p>
                                </div>
                            </div>

                            <div class="p-4 rounded-2xl bg-[#FFF5ED] border border-[#FECDAA] space-y-3">
                                <p class="text-xs text-slate-700 leading-relaxed font-medium">
                                    Please download and install TeamViewer so our technical team can connect directly to your POS terminal:
                                </p>
                                <div class="space-y-1.5">
                                    <a href="https://www.teamviewer.com/apac/download/windows/" target="_blank" rel="noopener noreferrer" 
                                       class="w-full bg-[#0066cc] hover:bg-[#004f9f] text-white font-extrabold text-xs sm:text-sm py-3.5 px-5 rounded-2xl flex items-center justify-center space-x-2 shadow-md transition-all">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                        <span>Download TeamViewer for Windows &rarr;</span>
                                    </a>
                                    <span class="block text-center text-[10px] text-slate-500 font-mono">
                                        Link: https://www.teamviewer.com/apac/download/windows/
                                    </span>
                                </div>
                            </div>

                            <div class="p-4 rounded-2xl bg-amber-50 border border-amber-200 text-xs text-slate-700 space-y-1.5 font-medium">
                                <span class="font-extrabold text-[#EB3E0B] uppercase text-[10px] block">Quick Next Steps:</span>
                                <ol class="list-decimal list-inside space-y-1 text-[11px] text-slate-600">
                                    <li>Click the button above to open the TeamViewer Windows download page.</li>
                                    <li>Run the downloaded installer on your POS terminal.</li>
                                    <li>Once installed or running, click below to submit your support ticket.</li>
                                </ol>
                            </div>

                            <div class="pt-2 flex items-center justify-between border-t border-[#FFE8D5]">
                                <button type="button" onclick="handleTeamViewerAnswer('back')" class="text-xs font-bold text-[#7C2112] hover:text-[#430D07]">
                                    &larr; Back
                                </button>
                                <button type="button" onclick="proceedToTicketAfterTV()" class="bg-[#EB3E0B] hover:bg-[#C32C0B] text-white font-extrabold text-xs sm:text-sm px-6 py-2.5 rounded-full shadow-md shadow-[#EB3E0B]/25 transition-all active:scale-95">
                                    Proceed to Submit Ticket &rarr;
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <script>
                function openTeamViewerCheckModal() {
                    var m = document.getElementById('teamViewerCheckModal');
                    var qView = document.getElementById('tvQuestionView');
                    var dlView = document.getElementById('tvDownloadView');
                    if (m) {
                        if (qView) qView.classList.remove('hidden');
                        if (dlView) dlView.classList.add('hidden');
                        m.classList.remove('hidden');
                        m.classList.add('flex');
                    }
                }

                function closeTeamViewerModal() {
                    var m = document.getElementById('teamViewerCheckModal');
                    if (m) {
                        m.classList.add('hidden');
                        m.classList.remove('flex');
                    }
                }

                function handleTeamViewerAnswer(hasTV) {
                    if (hasTV === 'back') {
                        document.getElementById('tvQuestionView').classList.remove('hidden');
                        document.getElementById('tvDownloadView').classList.add('hidden');
                        return;
                    }

                    if (hasTV === true) {
                        closeTeamViewerModal();
                        openNewSoftwareTicketModalWithPrefill();
                    } else {
                        document.getElementById('tvQuestionView').classList.add('hidden');
                        document.getElementById('tvDownloadView').classList.remove('hidden');
                    }
                }

                function proceedToTicketAfterTV() {
                    closeTeamViewerModal();
                    openNewSoftwareTicketModalWithPrefill();
                }

                function openNewSoftwareTicketModalWithPrefill() {
                    var issueName = <?php echo json_encode($issue_item['name']); ?>;
                    var diagTrail = <?php echo $diag_trail_js; ?>;
                    var subj = "Software Issue: " + issueName;

                    if (typeof openNewTicketModal === 'function') {
                        openNewTicketModal(issueName, subj, diagTrail);
                    } else {
                        var modal = document.getElementById('newTicketModal');
                        if (modal) {
                            modal.classList.remove('hidden');
                            modal.classList.add('flex');
                        }
                        var subjInput = document.querySelector('#newTicketModal input[name="subject"]');
                        var descInput = document.querySelector('#newTicketModal textarea[name="issue_description"]');
                        if (subjInput) subjInput.value = subj;
                        if (descInput) descInput.value = diagTrail;
                    }
                }
                </script>
            <?php endif; ?>

        </main>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
